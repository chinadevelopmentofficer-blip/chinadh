<?php
session_start();
require_once '../config/database.php';
require_once '../config/cloudflare.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$domain_id = (int)getGet('domain_id');
$action = getGet('action', 'list');
$messages = getMessages();

// 获取域名信息
$domain = $db->querySingle("SELECT * FROM domains WHERE id = $domain_id", true);
if (!$domain) {
    showError('域名不存在！');
    redirect('domains.php');
}

// 初始化Cloudflare API
$cf = new CloudflareAPI($domain['api_key'], $domain['email']);

// 处理添加DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    $subdomain = getPost('subdomain');
    $type = getPost('type');
    $content = getPost('content');
    $proxied = getPost('proxied', 0);
    $remark = getPost('remark', '');
    
    if ($subdomain && $type && $content) {
        try {
            // 构建完整的记录名称
            $full_name = $subdomain === '@' ? $domain['domain_name'] : $subdomain . '.' . $domain['domain_name'];
            
            // 检查是否已存在冲突的DNS记录
            $existing_records = $cf->getDNSRecords($domain['zone_id']);
            $conflict_found = false;
            $existing_record = null;
            
            foreach ($existing_records as $record) {
                if (strtolower($record['name']) === strtolower($full_name)) {
                    $record_type = strtoupper($record['type']);
                    $target_type = strtoupper($type);
                    
                    // A、AAAA、CNAME记录之间会冲突
                    $conflicting_types = ['A', 'AAAA', 'CNAME'];
                    
                    if (in_array($record_type, $conflicting_types) && in_array($target_type, $conflicting_types)) {
                        $conflict_found = true;
                        $existing_record = $record;
                        break;
                    }
                    
                    // 完全相同的记录类型和内容
                    if ($record_type === $target_type) {
                        if ($record['content'] === $content) {
                            throw new Exception("相同的DNS记录已存在！记录名称: {$full_name}, 类型: {$type}, 内容: {$content}");
                        } else {
                            $conflict_found = true;
                            $existing_record = $record;
                            break;
                        }
                    }
                }
            }
            
            if ($conflict_found) {
                $conflict_msg = "DNS记录冲突：域名 '{$full_name}' 已存在 {$existing_record['type']} 记录";
                $conflict_msg .= "（内容: {$existing_record['content']}）";
                $conflict_msg .= "。无法添加 {$type} 记录到相同名称。";
                $conflict_msg .= "建议：1) 使用不同的子域名前缀；2) 删除现有记录后重新添加；3) 使用编辑功能修改现有记录。";
                throw new Exception($conflict_msg);
            }
            
            // 某些记录类型不能启用代理
            $non_proxiable_types = ['NS', 'MX', 'TXT', 'SRV', 'CAA'];
            $final_proxied = in_array(strtoupper($type), $non_proxiable_types) ? false : (bool)$proxied;
            
            // 通过Cloudflare API添加记录
            $result = $cf->addDNSRecord($domain['zone_id'], $type, $full_name, $content, $final_proxied);
            
            // 保存到本地数据库
            $stmt = $db->prepare("INSERT INTO dns_records (user_id, domain_id, subdomain, type, content, proxied, cloudflare_id, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bindValue(1, 0, SQLITE3_INTEGER); // 管理员添加的记录，user_id为0
            $stmt->bindValue(2, $domain_id, SQLITE3_INTEGER);
            $stmt->bindValue(3, $subdomain, SQLITE3_TEXT);
            $stmt->bindValue(4, $type, SQLITE3_TEXT);
            $stmt->bindValue(5, $content, SQLITE3_TEXT);
            $stmt->bindValue(6, $final_proxied ? 1 : 0, SQLITE3_INTEGER);
            $stmt->bindValue(7, $result['id'], SQLITE3_TEXT);
            $stmt->bindValue(8, $remark, SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'add_dns_record', "为域名 {$domain['domain_name']} 添加DNS记录: $subdomain.$type");
                showSuccess('DNS记录添加成功！');
            } else {
                showError('本地数据库保存失败！');
            }
        } catch (Exception $e) {
            showError('添加DNS记录失败: ' . $e->getMessage());
        }
    } else {
        showError('请填写完整信息！');
    }
    redirect("domain_dns.php?domain_id=$domain_id");
}

// 处理更新DNS记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record'])) {
    $record_id = (int)getPost('record_id');
    $subdomain = getPost('subdomain');
    $type = getPost('type');
    $content = getPost('content');
    $proxied = getPost('proxied', 0);
    
    // 获取本地记录
    $local_record = $db->querySingle("SELECT * FROM dns_records WHERE id = $record_id", true);
    if ($local_record && $subdomain && $type && $content) {
        try {
            // 构建完整的记录名称
            $full_name = $subdomain === '@' ? $domain['domain_name'] : $subdomain . '.' . $domain['domain_name'];
            
            // 检查是否为SSL for SaaS回退源记录
            if ($cf->isSSLForSaaSFallbackRecord($domain['zone_id'], $local_record['cloudflare_id'])) {
                // 对于SSL for SaaS回退源记录，强制启用代理
                $proxied = 1;
                showWarning('检测到此记录为SSL for SaaS回退源，已自动启用代理状态。');
            }
            
            // 通过Cloudflare API更新记录
            $result = $cf->updateDNSRecord($domain['zone_id'], $local_record['cloudflare_id'], $type, $full_name, $content, (bool)$proxied);
            
            // 更新本地数据库
            $stmt = $db->prepare("UPDATE dns_records SET subdomain = ?, type = ?, content = ?, proxied = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bindValue(1, $subdomain, SQLITE3_TEXT);
            $stmt->bindValue(2, $type, SQLITE3_TEXT);
            $stmt->bindValue(3, $content, SQLITE3_TEXT);
            $stmt->bindValue(4, $proxied, SQLITE3_INTEGER);
            $stmt->bindValue(5, $record_id, SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'update_dns_record', "更新域名 {$domain['domain_name']} 的DNS记录: $subdomain.$type");
                showSuccess('DNS记录更新成功！');
            } else {
                showError('本地数据库更新失败！');
            }
        } catch (Exception $e) {
            showError('更新DNS记录失败: ' . $e->getMessage());
        }
    } else {
        showError('记录不存在或信息不完整！');
    }
    redirect("domain_dns.php?domain_id=$domain_id");
}

// 处理删除DNS记录
if ($action === 'delete' && getGet('record_id')) {
    $record_id = (int)getGet('record_id');
    $local_record = $db->querySingle("SELECT * FROM dns_records WHERE id = $record_id", true);
    
    if ($local_record) {
        try {
            // 通过Cloudflare API删除记录
            $cf->deleteDNSRecord($domain['zone_id'], $local_record['cloudflare_id']);
            
            // 删除本地记录
            $db->exec("DELETE FROM dns_records WHERE id = $record_id");
            
            logAction('admin', $_SESSION['admin_id'], 'delete_dns_record', "删除域名 {$domain['domain_name']} 的DNS记录: {$local_record['subdomain']}.{$local_record['type']}");
            showSuccess('DNS记录删除成功！');
        } catch (Exception $e) {
            showError('删除DNS记录失败: ' . $e->getMessage());
        }
    } else {
        showError('记录不存在！');
    }
    redirect("domain_dns.php?domain_id=$domain_id");
}

// 同步DNS功能已移除

// 获取DNS记录列表
$dns_records = [];
$result = $db->query("SELECT * FROM dns_records WHERE domain_id = $domain_id ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dns_records[] = $row;
}

$page_title = $domain['domain_name'] . ' - DNS记录管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <a href="domains.php" class="text-decoration-none">
                        <i class="fas fa-arrow-left me-2"></i>
                    </a>
                    <?php echo htmlspecialchars($domain['domain_name']); ?> - DNS记录管理
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRecordModal">
                        <i class="fas fa-plus me-1"></i>添加记录
                    </button>
                </div>
            </div>
            
            <!-- 消息提示 -->
            <?php if (isset($messages['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($messages['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($messages['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($messages['warning'])): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($messages['warning']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- 域名信息卡片 -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">域名信息</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>域名:</strong> <?php echo htmlspecialchars($domain['domain_name']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Zone ID:</strong> <code><?php echo htmlspecialchars($domain['zone_id']); ?></code>
                        </div>
                        <div class="col-md-3">
                            <strong>邮箱:</strong> <?php echo htmlspecialchars($domain['email']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>默认代理:</strong> 
                            <?php if ($domain['proxied_default']): ?>
                                <span class="badge bg-success">是</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">否</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- DNS记录列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">DNS记录列表 (<?php echo count($dns_records); ?> 条记录)</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="recordsTable">
                            <thead>
                                <tr>
                                    <th>完整域名</th>
                                    <th>类型</th>
                                    <th>内容</th>
                                    <th>代理状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dns_records as $record): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $full_domain = $record['subdomain'] === '@' ? $domain['domain_name'] : $record['subdomain'] . '.' . $domain['domain_name'];
                                        // 根据记录类型和代理状态选择协议
                                        $protocol = ($record['type'] === 'A' || $record['type'] === 'AAAA' || $record['type'] === 'CNAME') ? 
                                                   ($record['proxied'] ? 'https' : 'http') : '';
                                        ?>
                                        <?php if ($protocol): ?>
                                            <a href="<?php echo $protocol; ?>://<?php echo htmlspecialchars($full_domain); ?>" 
                                               target="_blank" 
                                               class="text-decoration-none domain-link"
                                               title="点击访问 <?php echo htmlspecialchars($full_domain); ?>">
                                                <code class="text-primary"><?php echo htmlspecialchars($full_domain); ?></code>
                                                <i class="fas fa-external-link-alt ms-1 text-muted" style="font-size: 0.75em;"></i>
                                            </a>
                                        <?php else: ?>
                                            <code class="text-dark"><?php echo htmlspecialchars($full_domain); ?></code>
                                        <?php endif; ?>
                                        
                                        <?php if ($record['subdomain'] === '@'): ?>
                                            <br><small class="text-muted">(根域名)</small>
                                        <?php else: ?>
                                            <br><small class="text-muted">子域名: <code class="text-secondary"><?php echo htmlspecialchars($record['subdomain']); ?></code></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-info"><?php echo $record['type']; ?></span></td>
                                    <td><?php echo htmlspecialchars($record['content']); ?></td>
                                    <td>
                                        <?php if ($record['proxied']): ?>
                                            <span class="badge bg-warning">已代理</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">仅DNS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatTime($record['created_at']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-warning me-1" 
                                                onclick="editRecord(<?php echo htmlspecialchars(json_encode($record)); ?>)"
                                                title="编辑记录">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="?domain_id=<?php echo $domain_id; ?>&action=delete&record_id=<?php echo $record['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirmDelete('确定要删除这条DNS记录吗？')"
                                           title="删除记录">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 添加DNS记录模态框 -->
<div class="modal fade" id="addRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加DNS记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="subdomain" class="form-label">子域名</label>
                        <input type="text" class="form-control" id="subdomain" name="subdomain" placeholder="www 或 @ (根域名)" required>
                        <div class="form-text">输入 @ 表示根域名，输入 www 表示 www.<?php echo $domain['domain_name']; ?></div>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="form-label">记录类型</label>
                        <select class="form-control" id="type" name="type" required>
                            <option value="A">A - IPv4地址</option>
                            <option value="AAAA">AAAA - IPv6地址</option>
                            <option value="CNAME">CNAME - 别名</option>
                            <option value="MX">MX - 邮件交换</option>
                            <option value="TXT">TXT - 文本记录</option>
                            <option value="NS">NS - 名称服务器</option>
                            <option value="SRV">SRV - 服务记录</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="content" class="form-label">记录内容</label>
                        <input type="text" class="form-control" id="content" name="content" placeholder="记录值" required>
                        <div class="form-text">A记录填写IP地址，CNAME记录填写目标域名</div>
                    </div>
                    <div class="mb-3">
                        <label for="remark" class="form-label">备注 <span class="text-muted">(可选)</span></label>
                        <input type="text" class="form-control" id="remark" name="remark" placeholder="例如：网站主页、API接口、邮件服务器等" maxlength="100">
                        <div class="form-text">添加备注可以帮助您区分不同解析记录的用途</div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="proxied" name="proxied" value="1" 
                                   <?php echo $domain['proxied_default'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="proxied">
                                启用Cloudflare代理 (仅适用于A、AAAA、CNAME记录)
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_record" class="btn btn-primary">添加记录</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑DNS记录模态框 -->
<div class="modal fade" id="editRecordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑DNS记录</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" id="edit_record_id" name="record_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_subdomain" class="form-label">子域名</label>
                        <input type="text" class="form-control" id="edit_subdomain" name="subdomain" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_type" class="form-label">记录类型</label>
                        <select class="form-control" id="edit_type" name="type" required>
                            <option value="A">A - IPv4地址</option>
                            <option value="AAAA">AAAA - IPv6地址</option>
                            <option value="CNAME">CNAME - 别名</option>
                            <option value="MX">MX - 邮件交换</option>
                            <option value="TXT">TXT - 文本记录</option>
                            <option value="NS">NS - 名称服务器</option>
                            <option value="SRV">SRV - 服务记录</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_content" class="form-label">记录内容</label>
                        <input type="text" class="form-control" id="edit_content" name="content" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_proxied" name="proxied" value="1">
                            <label class="form-check-label" for="edit_proxied">
                                启用Cloudflare代理
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="update_record" class="btn btn-warning">更新记录</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.domain-link:hover code {
    text-decoration: underline !important;
}

.domain-link:hover .fa-external-link-alt {
    color: #0d6efd !important;
}

.table td {
    vertical-align: middle;
}
</style>

<script>
function confirmDelete(message) {
    return confirm(message);
}

function editRecord(record) {
    document.getElementById('edit_record_id').value = record.id;
    document.getElementById('edit_subdomain').value = record.subdomain;
    document.getElementById('edit_type').value = record.type;
    document.getElementById('edit_content').value = record.content;
    document.getElementById('edit_proxied').checked = record.proxied == 1;
    
    var editModal = new bootstrap.Modal(document.getElementById('editRecordModal'));
    editModal.show();
}

// 根据记录类型自动调整代理选项
document.getElementById('type').addEventListener('change', function() {
    const proxiedCheckbox = document.getElementById('proxied');
    const proxyableTypes = ['A', 'AAAA', 'CNAME'];
    
    if (proxyableTypes.includes(this.value)) {
        proxiedCheckbox.disabled = false;
        proxiedCheckbox.parentElement.style.opacity = '1';
    } else {
        proxiedCheckbox.disabled = true;
        proxiedCheckbox.checked = false;
        proxiedCheckbox.parentElement.style.opacity = '0.5';
    }
});

document.getElementById('edit_type').addEventListener('change', function() {
    const proxiedCheckbox = document.getElementById('edit_proxied');
    const proxyableTypes = ['A', 'AAAA', 'CNAME'];
    
    if (proxyableTypes.includes(this.value)) {
        proxiedCheckbox.disabled = false;
        proxiedCheckbox.parentElement.style.opacity = '1';
    } else {
        proxiedCheckbox.disabled = true;
        proxiedCheckbox.checked = false;
        proxiedCheckbox.parentElement.style.opacity = '0.5';
    }
});
</script>

<?php include 'includes/footer.php'; ?>