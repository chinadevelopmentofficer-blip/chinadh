<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理更新请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rewards'])) {
    try {
        $current_reward_points = (int)getSetting('invitation_reward_points', '10');
        
        // 更新所有邀请码的奖励积分为当前设置值
        $stmt = $db->prepare("UPDATE invitations SET reward_points = ? WHERE is_active = 1");
        $stmt->bindValue(1, $current_reward_points, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        if ($result) {
            $updated_count = $db->changes();
            logAction('admin', $_SESSION['admin_id'], 'update_invitation_rewards', "批量更新邀请码奖励积分为 $current_reward_points，影响 $updated_count 条记录");
            showSuccess("成功更新了 $updated_count 个邀请码的奖励积分为 $current_reward_points");
        } else {
            showError('更新失败，请重试');
        }
    } catch (Exception $e) {
        showError('更新失败：' . $e->getMessage());
    }
    
    redirect('update_invitation_rewards.php');
}

// 获取当前设置和统计信息
$current_reward_points = (int)getSetting('invitation_reward_points', '10');
$stats = [
    'total_invitations' => $db->querySingle("SELECT COUNT(*) FROM invitations WHERE is_active = 1"),
    'outdated_invitations' => $db->querySingle("SELECT COUNT(*) FROM invitations WHERE is_active = 1 AND reward_points != $current_reward_points"),
    'current_setting' => $current_reward_points
];

// 获取不同奖励积分的分布
$reward_distribution = [];
$result = $db->query("SELECT reward_points, COUNT(*) as count FROM invitations WHERE is_active = 1 GROUP BY reward_points ORDER BY reward_points");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $reward_distribution[] = $row;
}

$page_title = '更新邀请奖励积分';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-sync-alt me-2"></i>更新邀请奖励积分</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="invitations.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>返回邀请管理
                    </a>
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
            
            <div class="row">
                <!-- 统计信息 -->
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-chart-pie me-2"></i>奖励积分统计
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card bg-primary text-white">
                                        <div class="card-body text-center">
                                            <div class="h4 mb-0"><?php echo $stats['total_invitations']; ?></div>
                                            <small>总邀请码数</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-warning text-white">
                                        <div class="card-body text-center">
                                            <div class="h4 mb-0"><?php echo $stats['outdated_invitations']; ?></div>
                                            <small>需要更新的邀请码</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-success text-white">
                                        <div class="card-body text-center">
                                            <div class="h4 mb-0"><?php echo $stats['current_setting']; ?></div>
                                            <small>当前设置积分</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($reward_distribution)): ?>
                            <h6 class="text-muted mb-3">奖励积分分布</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>奖励积分</th>
                                            <th>邀请码数量</th>
                                            <th>状态</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reward_distribution as $dist): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-info"><?php echo $dist['reward_points']; ?> 积分</span>
                                            </td>
                                            <td><?php echo $dist['count']; ?> 个</td>
                                            <td>
                                                <?php if ($dist['reward_points'] == $current_reward_points): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i>已是最新
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>需要更新
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 操作面板 -->
                <div class="col-lg-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-warning">
                                <i class="fas fa-tools me-2"></i>批量更新操作
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($stats['outdated_invitations'] > 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>发现问题</strong><br>
                                    有 <strong><?php echo $stats['outdated_invitations']; ?></strong> 个邀请码的奖励积分与当前设置不一致。
                                </div>
                                
                                <div class="mb-3">
                                    <h6 class="text-primary">更新说明：</h6>
                                    <ul class="small text-muted">
                                        <li>将所有活跃邀请码的奖励积分更新为当前设置值</li>
                                        <li>当前设置：<strong><?php echo $current_reward_points; ?></strong> 积分</li>
                                        <li>影响邀请码：<strong><?php echo $stats['outdated_invitations']; ?></strong> 个</li>
                                        <li>此操作不可撤销</li>
                                    </ul>
                                </div>
                                
                                <form method="POST" onsubmit="return confirmUpdate()">
                                    <div class="d-grid">
                                        <button type="submit" name="update_rewards" class="btn btn-warning">
                                            <i class="fas fa-sync-alt me-2"></i>
                                            批量更新奖励积分
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>一切正常</strong><br>
                                    所有邀请码的奖励积分都与当前设置一致。
                                </div>
                                
                                <div class="text-center">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-muted">无需更新</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card shadow mt-4">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">
                                <i class="fas fa-info-circle me-2"></i>使用说明
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="small text-muted">
                                <h6 class="text-primary">什么时候需要更新？</h6>
                                <ul>
                                    <li>修改了系统的邀请奖励积分设置</li>
                                    <li>发现邀请码奖励与设置不一致</li>
                                    <li>系统升级后的数据同步</li>
                                </ul>
                                
                                <h6 class="text-primary mt-3">更新影响：</h6>
                                <ul>
                                    <li>只影响未来的邀请奖励</li>
                                    <li>不影响已发放的奖励</li>
                                    <li>确保系统设置的一致性</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
function confirmUpdate() {
    return confirm('确定要批量更新所有邀请码的奖励积分吗？\n\n此操作将把所有活跃邀请码的奖励积分更新为当前系统设置值（<?php echo $current_reward_points; ?> 积分）。\n\n此操作不可撤销，请确认继续。');
}
</script>

<style>
.card {
    transition: transform 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
}

.badge {
    font-size: 0.875rem;
}
</style>

<?php include 'includes/footer.php'; ?>