<?php
/**
 * 彩虹DNS API测试工具
 * 用于调试和诊断彩虹DNS API连接问题
 */

session_start();
require_once '../config/database.php';
require_once '../config/rainbow_dns.php';
require_once '../includes/functions.php';

checkAdminLogin();

$test_result = null;
$test_error = null;

// 处理测试请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provider_uid = $_POST['provider_uid'] ?? '';
    $api_key = $_POST['api_key'] ?? '';
    $api_base_url = $_POST['api_base_url'] ?? '';
    
    if ($provider_uid && $api_key && $api_base_url) {
        try {
            $test_result = [
                'step1' => '✓ 初始化彩虹DNS API类',
                'config' => [
                    'UID' => $provider_uid,
                    'API Key' => substr($api_key, 0, 8) . '...' . substr($api_key, -8),
                    'Base URL' => $api_base_url
                ]
            ];
            
            // 创建API实例
            $rainbow_api = new RainbowDNSAPI($provider_uid, $api_key, $api_base_url);
            $test_result['step2'] = '✓ API实例创建成功';
            
            // 测试连接
            $test_result['step3'] = '正在测试API连接...';
            $details = $rainbow_api->getVerificationDetails();
            
            if ($details['api_valid']) {
                $test_result['step3'] = '✓ API连接测试成功';
                $test_result['domains'] = [
                    'count' => $details['domain_count'],
                    'message' => "成功获取到 {$details['domain_count']} 个域名"
                ];
                
                // 尝试获取域名列表
                try {
                    $domains_response = $rainbow_api->getDomains(0, 5);
                    if (isset($domains_response['rows']) && !empty($domains_response['rows'])) {
                        $test_result['sample_domains'] = [];
                        foreach ($domains_response['rows'] as $domain) {
                            $test_result['sample_domains'][] = $domain['Domain'] ?? $domain['name'] ?? '未知';
                        }
                    }
                } catch (Exception $e) {
                    $test_result['sample_domains_error'] = $e->getMessage();
                }
                
            } else {
                $test_error = '✗ API验证失败: ' . $details['error_message'];
            }
            
        } catch (Exception $e) {
            $test_error = '✗ 测试失败: ' . $e->getMessage();
        }
    } else {
        $test_error = '请填写完整的测试信息！';
    }
}

$page_title = '彩虹DNS API测试工具';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-flask me-2"></i>彩虹DNS API测试工具
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="rainbow_accounts.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>返回账户管理
                    </a>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card shadow mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-vial me-2"></i>API测试配置
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    此工具用于测试彩虹DNS API连接，帮助您排查配置问题。
                                </div>
                                
                                <div class="mb-3">
                                    <label for="provider_uid" class="form-label">用户ID (UID)</label>
                                    <input type="text" class="form-control" id="provider_uid" name="provider_uid" 
                                           value="<?php echo htmlspecialchars($_POST['provider_uid'] ?? ''); ?>" required>
                                    <div class="form-text">彩虹聚合DNS的用户ID</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="api_key" class="form-label">API密钥</label>
                                    <input type="text" class="form-control" id="api_key" name="api_key" 
                                           value="<?php echo htmlspecialchars($_POST['api_key'] ?? ''); ?>" required>
                                    <div class="form-text">彩虹聚合DNS的API密钥</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="api_base_url" class="form-label">API基础URL</label>
                                    <input type="url" class="form-control" id="api_base_url" name="api_base_url" 
                                           value="<?php echo htmlspecialchars($_POST['api_base_url'] ?? ''); ?>" 
                                           placeholder="https://api.example.com" required>
                                    <div class="form-text">彩虹聚合DNS的API基础地址（不要包含末尾斜杠）</div>
                                </div>
                                
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="fas fa-play me-1"></i>开始测试
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- 常见问题 -->
                    <div class="card shadow">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-question-circle me-2"></i>常见问题
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="accordion" id="faqAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                            网络请求失败怎么办？
                                        </button>
                                    </h2>
                                    <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>检查API基础URL是否正确</li>
                                                <li>确认服务器可以访问该URL（防火墙/网络限制）</li>
                                                <li>如果是HTTPS，检查SSL证书是否有效</li>
                                                <li>检查PHP的curl扩展是否已启用</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                            API验证失败怎么办？
                                        </button>
                                    </h2>
                                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>检查用户ID和API密钥是否正确</li>
                                                <li>确认API密钥未过期</li>
                                                <li>检查API是否已启用</li>
                                                <li>查看详细错误信息了解具体原因</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                            签名验证失败怎么办？
                                        </button>
                                    </h2>
                                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <ul>
                                                <li>确认签名算法：MD5(uid + timestamp + api_key)</li>
                                                <li>检查服务器时间是否准确</li>
                                                <li>API密钥不要包含空格或特殊字符</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <?php if ($test_result): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-check-circle me-2"></i>测试结果
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($test_result as $key => $value): ?>
                                <?php if ($key === 'config'): ?>
                                    <div class="mb-3">
                                        <strong>配置信息：</strong>
                                        <ul class="list-unstyled ms-3 mt-2">
                                            <?php foreach ($value as $k => $v): ?>
                                                <li><code><?php echo $k; ?>:</code> <?php echo htmlspecialchars($v); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php elseif ($key === 'domains'): ?>
                                    <div class="alert alert-success mb-3">
                                        <i class="fas fa-globe me-2"></i>
                                        <strong><?php echo $value['message']; ?></strong>
                                    </div>
                                <?php elseif ($key === 'sample_domains'): ?>
                                    <div class="mb-3">
                                        <strong>示例域名列表：</strong>
                                        <ul class="list-unstyled ms-3 mt-2">
                                            <?php foreach ($value as $domain): ?>
                                                <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars($domain); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php elseif ($key === 'sample_domains_error'): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        获取域名列表详情失败：<?php echo htmlspecialchars($value); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-2">
                                        <?php echo htmlspecialchars($value); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            
                            <div class="alert alert-success mt-3 mb-0">
                                <i class="fas fa-thumbs-up me-2"></i>
                                <strong>恭喜！</strong>API配置正确，可以正常使用。
                            </div>
                        </div>
                    </div>
                    <?php elseif ($test_error): ?>
                    <div class="card shadow mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-times-circle me-2"></i>测试失败
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-danger">
                                <strong>错误信息：</strong><br>
                                <?php echo htmlspecialchars($test_error); ?>
                            </div>
                            
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-lightbulb me-2"></i>
                                <strong>建议：</strong>
                                <ul class="mb-0 mt-2">
                                    <li>检查左侧常见问题解答</li>
                                    <li>确认所有配置信息准确无误</li>
                                    <li>联系彩虹DNS服务商确认API状态</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-flask fa-4x text-muted mb-3"></i>
                            <h5 class="text-muted">填写左侧配置信息开始测试</h5>
                            <p class="text-muted">测试结果将显示在这里</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 技术信息 -->
                    <div class="card shadow">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle me-2"></i>技术信息
                            </h5>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>PHP版本：</strong></td>
                                    <td><?php echo PHP_VERSION; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>cURL扩展：</strong></td>
                                    <td>
                                        <?php if (function_exists('curl_version')): ?>
                                            <span class="badge bg-success">已启用</span>
                                            <?php $curl_version = curl_version(); ?>
                                            (<?php echo $curl_version['version']; ?>)
                                        <?php else: ?>
                                            <span class="badge bg-danger">未启用</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>OpenSSL：</strong></td>
                                    <td>
                                        <?php if (extension_loaded('openssl')): ?>
                                            <span class="badge bg-success">已启用</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">未启用</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>签名算法：</strong></td>
                                    <td><code>MD5(uid + timestamp + api_key)</code></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

