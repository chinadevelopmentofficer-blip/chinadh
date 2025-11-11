<?php
/**
 * 渠道添加调试页面
 * 用于调试提交的表单数据
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/rainbow_dns.php';
require_once __DIR__ . '/../includes/functions.php';

checkAdminLogin();

$debug_info = [];
$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debug_info['提交方法'] = 'POST';
    $debug_info['POST数据'] = $_POST;
    
    // 提取表单数据
    $channel_type = trim($_POST['channel_type'] ?? '');
    $channel_name = trim($_POST['channel_name'] ?? '');
    $api_key = trim($_POST['api_key'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $api_base_url = trim($_POST['api_base_url'] ?? '');
    $provider_uid = trim($_POST['provider_uid'] ?? '');
    
    $debug_info['处理后的数据'] = [
        'channel_type' => $channel_type,
        'channel_name' => $channel_name,
        'api_key' => $api_key ? (substr($api_key, 0, 8) . '...') : '(空)',
        'email' => $email ?: '(空)',
        'api_base_url' => $api_base_url ?: '(空)',
        'provider_uid' => $provider_uid ?: '(空)',
    ];
    
    // 如果是彩虹DNS，测试API
    if ($channel_type === 'rainbow') {
        $debug_info['彩虹DNS验证'] = [];
        
        if (empty($api_base_url)) {
            $debug_info['彩虹DNS验证']['错误'] = 'API基础URL为空';
        } elseif (empty($provider_uid)) {
            $debug_info['彩虹DNS验证']['错误'] = '用户ID为空';
        } elseif (empty($api_key)) {
            $debug_info['彩虹DNS验证']['错误'] = 'API密钥为空';
        } else {
            try {
                $rainbow_api = new RainbowDNSAPI($provider_uid, $api_key, $api_base_url);
                $debug_info['彩虹DNS验证']['API对象创建'] = '成功';
                
                $verification = $rainbow_api->getVerificationDetails();
                $debug_info['彩虹DNS验证']['验证结果'] = $verification;
                
                if ($verification['api_valid']) {
                    $test_result = [
                        'status' => 'success',
                        'message' => "API验证成功！可以访问 {$verification['domain_count']} 个域名。"
                    ];
                } else {
                    $test_result = [
                        'status' => 'error',
                        'message' => 'API验证失败: ' . $verification['error_message']
                    ];
                }
            } catch (Exception $e) {
                $debug_info['彩虹DNS验证']['异常'] = $e->getMessage();
                $test_result = [
                    'status' => 'error',
                    'message' => 'API异常: ' . $e->getMessage()
                ];
            }
        }
    }
}

$page_title = '渠道添加调试';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-bug me-2"></i>渠道添加调试
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="channels_management.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>返回渠道管理
                    </a>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card shadow mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-wpforms me-2"></i>测试表单
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    填写表单提交后，系统会显示详细的调试信息
                                </div>
                                
                                <div class="mb-3">
                                    <label for="channel_type" class="form-label">渠道类型</label>
                                    <select class="form-select" id="channel_type" name="channel_type" required>
                                        <option value="">请选择</option>
                                        <option value="rainbow" selected>彩虹DNS</option>
                                        <option value="cloudflare">Cloudflare</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="channel_name" class="form-label">渠道名称</label>
                                    <input type="text" class="form-control" id="channel_name" name="channel_name" value="测试渠道" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="api_key" class="form-label">API密钥</label>
                                    <input type="text" class="form-control" id="api_key" name="api_key" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">邮箱（可选）</label>
                                    <input type="email" class="form-control" id="email" name="email">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="api_base_url" class="form-label">API基础URL</label>
                                    <input type="url" class="form-control" id="api_base_url" name="api_base_url" placeholder="https://api.example.com">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="provider_uid" class="form-label">用户ID</label>
                                    <input type="text" class="form-control" id="provider_uid" name="provider_uid">
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-play me-1"></i>提交测试
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <?php if (!empty($debug_info)): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header <?php echo $test_result && $test_result['status'] === 'success' ? 'bg-success' : 'bg-warning'; ?> text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-clipboard-list me-2"></i>调试信息
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($test_result): ?>
                            <div class="alert alert-<?php echo $test_result['status'] === 'success' ? 'success' : 'danger'; ?>">
                                <i class="fas fa-<?php echo $test_result['status'] === 'success' ? 'check-circle' : 'times-circle'; ?> me-2"></i>
                                <strong><?php echo htmlspecialchars($test_result['message']); ?></strong>
                            </div>
                            <?php endif; ?>
                            
                            <?php foreach ($debug_info as $key => $value): ?>
                            <div class="mb-3">
                                <h6 class="fw-bold"><?php echo htmlspecialchars($key); ?>:</h6>
                                <pre class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;"><code><?php echo htmlspecialchars(print_r($value, true)); ?></code></pre>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-bug fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">提交左侧表单查看调试信息</h5>
                            <p class="text-muted">调试信息将显示在这里</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 使用说明 -->
                    <div class="card shadow">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-question-circle me-2"></i>使用说明
                            </h5>
                        </div>
                        <div class="card-body">
                            <ol>
                                <li>填写左侧表单的所有必填项</li>
                                <li>点击"提交测试"按钮</li>
                                <li>查看右侧显示的调试信息</li>
                                <li>根据调试信息排查问题</li>
                            </ol>
                            
                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>注意：</strong>此页面仅用于调试，不会实际保存数据到数据库。
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

