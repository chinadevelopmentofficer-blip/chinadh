<?php
session_start();
require_once '../config/database.php';
require_once '../config/cloudflare.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 确保Cloudflare账户表存在
$db->exec("CREATE TABLE IF NOT EXISTS cloudflare_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    api_key TEXT NOT NULL,
    status INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 处理添加Cloudflare账户
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $name = getPost('name');
    $email = getPost('email');
    $api_key = getPost('api_key');
    
    if ($name && $email && $api_key) {
        try {
            // 验证API密钥
            $cf = new CloudflareAPI($api_key, $email);
            if ($cf->verifyCredentials()) {
                $stmt = $db->prepare("INSERT INTO cloudflare_accounts (name, email, api_key) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $name, SQLITE3_TEXT);
                $stmt->bindValue(2, $email, SQLITE3_TEXT);
                $stmt->bindValue(3, $api_key, SQLITE3_TEXT);
                
                if ($stmt->execute()) {
                    logAction('admin', $_SESSION['admin_id'], 'add_cloudflare_account', "添加Cloudflare账户: $name ($email)");
                    showSuccess('Cloudflare账户添加成功！');
                } else {
                    showError('账户添加失败！');
                }
            } else {
                showError('Cloudflare API验证失败，请检查邮箱和API密钥！');
            }
        } catch (Exception $e) {
            showError('Cloudflare API错误: ' . $e->getMessage());
        }
    } else {
        showError('请填写完整信息！');
    }
    redirect('cloudflare_accounts.php');
}

// 处理删除账户
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $account = $db->querySingle("SELECT name, email FROM cloudflare_accounts WHERE id = $id", true);
    
    if ($account) {
        // 检查是否有关联的域名
        $domain_count = $db->querySingle("SELECT COUNT(*) FROM domains WHERE api_key = (SELECT api_key FROM cloudflare_accounts WHERE id = $id)");
        
        if ($domain_count > 0) {
            showError("无法删除账户，还有 $domain_count 个域名使用此账户！");
        } else {
            $db->exec("DELETE FROM cloudflare_accounts WHERE id = $id");
            logAction('admin', $_SESSION['admin_id'], 'delete_cloudflare_account', "删除Cloudflare账户: {$account['name']} ({$account['email']})");
            showSuccess('Cloudflare账户删除成功！');
        }
    } else {
        showError('账户不存在！');
    }
    redirect('cloudflare_accounts.php');
}

// 处理状态切换
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $account = $db->querySingle("SELECT name, email, status FROM cloudflare_accounts WHERE id = $id", true);
    
    if ($account) {
        $new_status = $account['status'] ? 0 : 1;
        $db->exec("UPDATE cloudflare_accounts SET status = $new_status WHERE id = $id");
        
        $status_text = $new_status ? '启用' : '禁用';
        logAction('admin', $_SESSION['admin_id'], 'toggle_cloudflare_account', "{$status_text}Cloudflare账户: {$account['name']}");
        showSuccess("Cloudflare账户已{$status_text}！");
    } else {
        showError('账户不存在！');
    }
    redirect('cloudflare_accounts.php');
}

// 处理测试连接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $id = (int)getPost('account_id');
    $account = $db->querySingle("SELECT * FROM cloudflare_accounts WHERE id = $id", true);
    
    if ($account) {
        try {
            $cf = new CloudflareAPI($account['api_key'], $account['email']);
            $details = $cf->getVerificationDetails();
            
            if ($details['api_token_valid'] || $details['global_key_valid']) {
                showSuccess('API连接测试成功！');
                if (isset($details['user_info'])) {
                    showSuccess('用户信息: ' . $details['user_info']['email']);
                }
            } else {
                showError('API连接测试失败: ' . $details['error_message']);
            }
        } catch (Exception $e) {
            showError('连接测试失败: ' . $e->getMessage());
        }
    } else {
        showError('账户不存在！');
    }
    redirect('cloudflare_accounts.php');
}

// 获取Cloudflare账户列表
$cf_accounts = [];
$result = $db->query("SELECT * FROM cloudflare_accounts ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // 获取关联的域名数量
    $domain_count = $db->querySingle("SELECT COUNT(*) FROM domains WHERE api_key = '{$row['api_key']}'");
    $row['domain_count'] = $domain_count;
    $cf_accounts[] = $row;
}

$page_title = 'Cloudflare账户管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fab fa-cloudflare me-2"></i>Cloudflare账户管理
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
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
                    <h5 class="card-title mb-0">Cloudflare账户列表</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($cf_accounts)): ?>
                        <div class="text-center py-5">
                            <i class="fab fa-cloudflare fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">暂无Cloudflare账户</h5>
                            <p class="text-muted">点击上方"添加账户"按钮来添加您的第一个Cloudflare账户</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
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
                                        <th>邮箱</th>
                                        <th>API密钥</th>
                                        <th>关联域名</th>
                                        <th>状态</th>
                                        <th>创建时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cf_accounts as $account): ?>
                                    <tr>
                                        <td><?php echo $account['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($account['name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($account['email']); ?></td>
                                        <td>
                                            <code class="small">
                                                <?php echo substr($account['api_key'], 0, 8) . '...' . substr($account['api_key'], -8); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $account['domain_count']; ?> 个</span>
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
                <h5 class="modal-title">添加Cloudflare账户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        请确保您的Cloudflare API密钥具有Zone:Read和Zone:Edit权限。
                    </div>
                    <div class="mb-3">
                        <label for="account_name" class="form-label">账户名称</label>
                        <input type="text" class="form-control" id="account_name" name="name" placeholder="例如：主账户、备用账户" required>
                        <div class="form-text">用于区分不同的Cloudflare账户</div>
                    </div>
                    <div class="mb-3">
                        <label for="account_email" class="form-label">Cloudflare邮箱</label>
                        <input type="email" class="form-control" id="account_email" name="email" required>
                        <div class="form-text">您的Cloudflare账户邮箱</div>
                    </div>
                    <div class="mb-3">
                        <label for="account_api_key" class="form-label">API密钥</label>
                        <input type="text" class="form-control" id="account_api_key" name="api_key" required>
                        <div class="form-text">
                            Global API Key 或 Zone API Token<br>
                            <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" class="text-decoration-none">
                                <i class="fas fa-external-link-alt me-1"></i>获取API密钥
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_account" class="btn btn-primary">添加账户</button>
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

<script>
function confirmDelete(message) {
    return confirm(message);
}

function testConnection(accountId) {
    document.getElementById('test_account_id').value = accountId;
    document.getElementById('testConnectionForm').submit();
}
</script>

<?php include 'includes/footer.php'; ?>