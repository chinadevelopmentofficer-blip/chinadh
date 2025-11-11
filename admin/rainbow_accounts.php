<?php
session_start();
require_once '../config/database.php';
require_once '../config/rainbow_dns.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 创建彩虹DNS账户表
$db->exec("CREATE TABLE IF NOT EXISTS rainbow_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    provider_uid TEXT NOT NULL,
    api_key TEXT NOT NULL,
    api_base_url TEXT NOT NULL,
    status INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 处理添加彩虹DNS账户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $name = getPost('name');
    $provider_uid = getPost('provider_uid');
    $api_key = getPost('api_key');
    $api_base_url = getPost('api_base_url');
    
    if ($name && $provider_uid && $api_key && $api_base_url) {
        try {
            // 验证API
            $rainbow_api = new RainbowDNSAPI($provider_uid, $api_key, $api_base_url);
            if ($rainbow_api->verifyCredentials()) {
                $stmt = $db->prepare("INSERT INTO rainbow_accounts (name, provider_uid, api_key, api_base_url) VALUES (?, ?, ?, ?)");
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $stmt->bindValue(2, $provider_uid, SQLITE3_TEXT);
                $stmt->bindValue(3, $api_key, SQLITE3_TEXT);
                $stmt->bindValue(4, $api_base_url, SQLITE3_TEXT);
                
                if ($stmt->execute()) {
                    logAction('admin', $_SESSION['admin_id'], 'add_rainbow_account', "添加彩虹DNS账户: $name");
                    showSuccess('彩虹DNS账户添加成功！');
                } else {
                    showError('账户添加失败！');
                }
            } else {
                showError('彩虹DNS API验证失败，请检查配置！');
            }
        } catch (Exception $e) {
            showError('彩虹DNS API错误: ' . $e->getMessage());
        }
    } else {
        showError('请填写完整信息！');
    }
    redirect('rainbow_accounts.php');
}

// 处理删除账户
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $account = $db->querySingle("SELECT name FROM rainbow_accounts WHERE id = $id", true);
    
    if ($account) {
        // 检查是否有关联的域名
        $domain_count = $db->querySingle("SELECT COUNT(*) FROM domains WHERE provider_type = 'rainbow' AND provider_uid = (SELECT provider_uid FROM rainbow_accounts WHERE id = $id)");
        
        if ($domain_count > 0) {
            showError("无法删除账户，还有 $domain_count 个域名使用此账户！");
        } else {
            $db->exec("DELETE FROM rainbow_accounts WHERE id = $id");
            logAction('admin', $_SESSION['admin_id'], 'delete_rainbow_account', "删除彩虹DNS账户: {$account['name']}");
            showSuccess('彩虹DNS账户删除成功！');
        }
    } else {
        showError('账户不存在！');
    }
    redirect('rainbow_accounts.php');
}

// 处理状态切换
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $account = $db->querySingle("SELECT name, status FROM rainbow_accounts WHERE id = $id", true);
    
    if ($account) {
        $new_status = $account['status'] ? 0 : 1;
        $db->exec("UPDATE rainbow_accounts SET status = $new_status WHERE id = $id");
        
        $status_text = $new_status ? '启用' : '禁用';
        logAction('admin', $_SESSION['admin_id'], 'toggle_rainbow_account', "{$status_text}彩虹DNS账户: {$account['name']}");
        showSuccess("彩虹DNS账户已{$status_text}！");
    } else {
        showError('账户不存在！');
    }
    redirect('rainbow_accounts.php');
}

// 处理测试连接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $id = (int)getPost('account_id');
    $account = $db->querySingle("SELECT * FROM rainbow_accounts WHERE id = $id", true);
    
    if ($account) {
        try {
            $rainbow_api = new RainbowDNSAPI($account['provider_uid'], $account['api_key'], $account['api_base_url']);
            $details = $rainbow_api->getVerificationDetails();
            
            if ($details['api_valid']) {
                showSuccess("API连接测试成功！可访问 {$details['domain_count']} 个域名。");
            } else {
                showError('API连接测试失败: ' . $details['error_message']);
            }
        } catch (Exception $e) {
            showError('连接测试失败: ' . $e->getMessage());
        }
    } else {
        showError('账户不存在！');
    }
    redirect('rainbow_accounts.php');
}

// 处理获取域名列表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_domains'])) {
    $id = (int)getPost('account_id');
    $account = $db->querySingle("SELECT * FROM rainbow_accounts WHERE id = $id", true);
    
    if ($account) {
        try {
            $rainbow_api = new RainbowDNSAPI($account['provider_uid'], $account['api_key'], $account['api_base_url']);
            $domains_response = $rainbow_api->getDomains(0, 100);
            
            if (isset($domains_response['rows'])) {
                $_SESSION['fetched_rainbow_domains'] = $domains_response['rows'];
                $_SESSION['rainbow_config'] = [
                    'api_key' => $account['api_key'],
                    'provider_uid' => $account['provider_uid'],
                    'api_base_url' => $account['api_base_url']
                ];
                
                showSuccess("成功获取到 " . count($domains_response['rows']) . " 个彩虹DNS域名！");
                redirect('domains.php?action=select_rainbow_domains');
            } else {
                showError('未获取到域名列表！');
            }
        } catch (Exception $e) {
            showError('获取域名列表失败: ' . $e->getMessage());
        }
    } else {
        showError('账户不存在！');
    }
    redirect('rainbow_accounts.php');
}

// 获取彩虹DNS账户列表
$rainbow_accounts = [];
$result = $db->query("SELECT * FROM rainbow_accounts ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // 获取关联的域名数量
    $domain_count = $db->querySingle("SELECT COUNT(*) FROM domains WHERE provider_type = 'rainbow' AND provider_uid = '{$row['provider_uid']}'");
    $row['domain_count'] = $domain_count;
    $rainbow_accounts[] = $row;
}

$page_title = '彩虹DNS账户管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-rainbow me-2"></i>彩虹DNS账户管理
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                        <i class="fas fa-plus me-1"></i>添加账户
                    </button>
                </div>
            </div>
            
            <?php if ($messages): ?>
                <?php foreach ($messages as $type => $content): ?>
                    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($content); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- 账户列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="card-title mb-0">彩虹DNS账户列表</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($rainbow_accounts)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-rainbow fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">暂无彩虹DNS账户</h5>
                            <p class="text-muted">点击上方"添加账户"按钮来添加您的第一个彩虹DNS账户</p>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                <i class="fas fa-plus me-1"></i>添加账户
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>账户名称</th>
                                        <th>用户ID</th>
                                        <th>API基础URL</th>
                                        <th>API密钥</th>
                                        <th>关联域名</th>
                                        <th>状态</th>
                                        <th>创建时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rainbow_accounts as $account): ?>
                                    <tr>
                                        <td><?php echo $account['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($account['name']); ?></strong>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($account['provider_uid']); ?></code>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($account['api_base_url']); ?></small>
                                        </td>
                                        <td>
                                            <code class="small">
                                                <?php echo substr($account['api_key'], 0, 8) . '...' . substr($account['api_key'], -8); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?php echo $account['domain_count']; ?> 个</span>
                                        </td>
                                        <td>
                                            <?php if ($account['status']): ?>
                                                <span class="badge bg-success">正常</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">禁用</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatTime($account['created_at']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="testConnection(<?php echo $account['id']; ?>)" 
                                                        title="测试连接">
                                                    <i class="fas fa-plug"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-success" 
                                                        onclick="fetchDomains(<?php echo $account['id']; ?>)" 
                                                        title="获取域名">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <a href="?action=toggle&id=<?php echo $account['id']; ?>" 
                                                   class="btn btn-outline-<?php echo $account['status'] ? 'warning' : 'success'; ?>" 
                                                   title="<?php echo $account['status'] ? '禁用' : '启用'; ?>">
                                                    <i class="fas fa-<?php echo $account['status'] ? 'pause' : 'play'; ?>"></i>
                                                </a>
                                                <?php if ($account['domain_count'] == 0): ?>
                                                <a href="?action=delete&id=<?php echo $account['id']; ?>" 
                                                   class="btn btn-outline-danger" 
                                                   onclick="return confirmDelete('确定要删除账户 <?php echo htmlspecialchars($account['name']); ?> 吗？')"
                                                   title="删除">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-outline-secondary" disabled title="有关联域名，无法删除">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 添加账户模态框 -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加彩虹DNS账户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        请确保您已经在彩虹聚合DNS平台开通API接口并获取相关密钥。
                    </div>
                    <div class="mb-3">
                        <label for="account_name" class="form-label">账户名称</label>
                        <input type="text" class="form-control" id="account_name" name="name" placeholder="例如：主账户、备用账户" required>
                        <div class="form-text">用于区分不同的彩虹DNS账户</div>
                    </div>
                    <div class="mb-3">
                        <label for="provider_uid" class="form-label">用户ID</label>
                        <input type="text" class="form-control" id="provider_uid" name="provider_uid" required>
                        <div class="form-text">彩虹聚合DNS的用户ID</div>
                    </div>
                    <div class="mb-3">
                        <label for="api_key" class="form-label">API密钥</label>
                        <input type="text" class="form-control" id="api_key" name="api_key" required>
                        <div class="form-text">彩虹聚合DNS的API密钥</div>
                    </div>
                    <div class="mb-3">
                        <label for="api_base_url" class="form-label">API基础URL</label>
                        <input type="url" class="form-control" id="api_base_url" name="api_base_url" placeholder="https://api.example.com" required>
                        <div class="form-text">彩虹聚合DNS的API基础地址</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_account" class="btn btn-warning">添加账户</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 测试连接表单 -->
<form id="testConnectionForm" method="POST" style="display: none;">
    <input type="hidden" name="test_connection" value="1">
    <input type="hidden" name="account_id" id="test_account_id">
</form>

<!-- 获取域名表单 -->
<form id="fetchDomainsForm" method="POST" style="display: none;">
    <input type="hidden" name="fetch_domains" value="1">
    <input type="hidden" name="account_id" id="fetch_account_id">
</form>

<script>
function confirmDelete(message) {
    return confirm(message);
}

function testConnection(accountId) {
    document.getElementById('test_account_id').value = accountId;
    document.getElementById('testConnectionForm').submit();
}

function fetchDomains(accountId) {
    if (confirm('确定要获取此账户的域名列表吗？获取后将跳转到域名选择页面。')) {
        document.getElementById('fetch_account_id').value = accountId;
        document.getElementById('fetchDomainsForm').submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>