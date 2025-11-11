<?php
/**
 * 临时工具：不验证API直接添加彩虹DNS渠道
 * 仅用于调试！
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $channel_name = trim($_POST['channel_name']);
    $api_key = trim($_POST['api_key']);
    $api_base_url = trim($_POST['api_base_url']);
    $provider_uid = trim($_POST['provider_uid']);
    $description = trim($_POST['description'] ?? '');
    
    try {
        $stmt = $db->prepare("
            INSERT INTO rainbow_accounts (name, account_name, api_key, email, api_base_url, provider_uid, description, status, created_at) 
            VALUES (?, ?, ?, '', ?, ?, ?, 1, datetime('now'))
        ");
        $stmt->bindValue(1, $channel_name, SQLITE3_TEXT);
        $stmt->bindValue(2, $channel_name, SQLITE3_TEXT);
        $stmt->bindValue(3, $api_key, SQLITE3_TEXT);
        $stmt->bindValue(4, $api_base_url, SQLITE3_TEXT);
        $stmt->bindValue(5, $provider_uid, SQLITE3_TEXT);
        $stmt->bindValue(6, $description, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            $channel_id = $db->lastInsertRowID();
            showSuccess("彩虹DNS渠道添加成功！ID: {$channel_id}（未验证API）");
            header("Location: channels_management.php");
            exit;
        } else {
            showError('数据库插入失败: ' . $db->lastErrorMsg());
        }
    } catch (Exception $e) {
        showError('添加失败：' . $e->getMessage());
    }
}

$page_title = '临时添加彩虹DNS（无验证）';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>临时添加彩虹DNS（跳过API验证）
                </h1>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>警告：</strong>此页面会跳过API验证直接添加渠道！仅用于测试调试！
            </div>
            
            <?php
            $messages = getMessages();
            if ($messages):
                foreach ($messages as $type => $content):
            ?>
                <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($content); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php
                endforeach;
            endif;
            ?>
            
            <div class="card shadow">
                <div class="card-header">
                    <h5 class="card-title mb-0">添加彩虹DNS渠道（无API验证）</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="channel_name" class="form-label">渠道名称 *</label>
                            <input type="text" class="form-control" id="channel_name" name="channel_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="api_key" class="form-label">API密钥 *</label>
                            <input type="text" class="form-control" id="api_key" name="api_key" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="api_base_url" class="form-label">API基础URL *</label>
                            <input type="url" class="form-control" id="api_base_url" name="api_base_url" 
                                   placeholder="https://caihong.6qu.cc" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="provider_uid" class="form-label">用户ID *</label>
                            <input type="text" class="form-control" id="provider_uid" name="provider_uid" 
                                   placeholder="1000" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">描述</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-plus me-1"></i>直接添加（跳过验证）
                            </button>
                            <a href="channels_management.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>返回
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card shadow mt-3">
                <div class="card-header bg-info text-white">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i>说明
                    </h5>
                </div>
                <div class="card-body">
                    <p><strong>此页面的作用：</strong></p>
                    <ul>
                        <li>跳过API验证</li>
                        <li>直接将数据写入数据库</li>
                        <li>用于测试数据库插入是否正常</li>
                    </ul>
                    
                    <p class="mb-0"><strong>使用后请：</strong></p>
                    <ul class="mb-0">
                        <li>查看渠道列表是否显示</li>
                        <li>如果显示了，说明问题在于API验证</li>
                        <li>如果仍然没有，说明问题在于数据库或session</li>
                    </ul>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

