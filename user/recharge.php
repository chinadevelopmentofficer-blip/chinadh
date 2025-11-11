<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkUserLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理卡密充值
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recharge'])) {
    $card_key = strtoupper(trim(getPost('card_key')));
    
    if (!$card_key) {
        showError('请输入卡密！');
    } else {
        // 查找卡密
        $card = $db->querySingle("SELECT * FROM card_keys WHERE card_key = '$card_key'", true);
        
        if (!$card) {
            showError('卡密不存在！');
        } elseif ($card['status'] != 1) {
            showError('卡密已被禁用！');
        } elseif ($card['used_count'] >= $card['max_uses']) {
            showError('卡密已用完！');
        } else {
            // 检查用户是否已经使用过这张卡密
            $used_by_user = $db->querySingle("SELECT COUNT(*) FROM card_key_usage WHERE card_key_id = {$card['id']} AND user_id = {$_SESSION['user_id']}");
            
            if ($used_by_user > 0) {
                showError('您已经使用过这张卡密！');
            } else {
                // 开始事务
                $db->exec('BEGIN TRANSACTION');
                
                try {
                    // 更新用户积分
                    $stmt = $db->prepare("UPDATE users SET points = points + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bindValue(1, $card['points'], SQLITE3_INTEGER);
                    $stmt->bindValue(2, $_SESSION['user_id'], SQLITE3_INTEGER);
                    $stmt->execute();
                    
                    // 更新卡密使用次数
                    $stmt = $db->prepare("UPDATE card_keys SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->bindValue(1, $card['id'], SQLITE3_INTEGER);
                    $stmt->execute();
                    
                    // 记录使用记录
                    $stmt = $db->prepare("INSERT INTO card_key_usage (card_key_id, user_id, points_added) VALUES (?, ?, ?)");
                    $stmt->bindValue(1, $card['id'], SQLITE3_INTEGER);
                    $stmt->bindValue(2, $_SESSION['user_id'], SQLITE3_INTEGER);
                    $stmt->bindValue(3, $card['points'], SQLITE3_INTEGER);
                    $stmt->execute();
                    
                    // 记录日志
                    logAction('user', $_SESSION['user_id'], 'recharge', "使用卡密充值 {$card['points']} 积分");
                    
                    $db->exec('COMMIT');
                    showSuccess("充值成功！获得 {$card['points']} 积分");
                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    showError('充值失败，请重试！');
                }
            }
        }
    }
    redirect('recharge.php');
}

// 获取用户当前积分
$user = $db->querySingle("SELECT points FROM users WHERE id = {$_SESSION['user_id']}", true);

// 获取用户充值记录
$recharge_records = [];
$result = $db->query("SELECT cku.*, ck.card_key, ck.points 
                     FROM card_key_usage cku 
                     JOIN card_keys ck ON cku.card_key_id = ck.id 
                     WHERE cku.user_id = {$_SESSION['user_id']} 
                     ORDER BY cku.used_at DESC 
                     LIMIT 20");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recharge_records[] = $row;
}

$page_title = '积分充值';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="main-content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3" style="border-bottom: 1px solid rgba(255, 255, 255, 0.2);">
                <h1 class="h2">积分充值</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <span class="btn btn-outline-primary">
                            <i class="fas fa-coins me-1"></i>当前积分: <strong><?php echo $user['points']; ?></strong>
                        </span>
                    </div>
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
                <!-- 卡密充值 -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-credit-card me-2"></i>卡密充值
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="card_key" class="form-label">卡密</label>
                                    <input type="text" class="form-control" id="card_key" name="card_key" 
                                           placeholder="请输入16位卡密" maxlength="16" required 
                                           style="font-family: monospace; letter-spacing: 2px;">
                                    <div class="form-text">请输入管理员提供的16位卡密</div>
                                </div>
                                <button type="submit" name="recharge" class="btn btn-primary btn-lg w-100">
                                    <i class="fas fa-plus-circle me-1"></i>立即充值
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- 充值说明 -->
                    <div class="card shadow mt-4">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">
                                <i class="fas fa-info-circle me-2"></i>充值说明
                            </h6>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <li>每张卡密只能使用一次</li>
                                <li>卡密不区分大小写</li>
                                <li>充值成功后积分立即到账</li>
                                <li>如有问题请联系管理员</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- 充值记录 -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-history me-2"></i>充值记录
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recharge_records)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                <p>暂无充值记录</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>卡密</th>
                                            <th>积分</th>
                                            <th>时间</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recharge_records as $record): ?>
                                        <tr>
                                            <td>
                                                <code><?php echo substr($record['card_key'], 0, 4) . '****' . substr($record['card_key'], -4); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">+<?php echo $record['points_added']; ?></span>
                                            </td>
                                            <td>
                                                <small><?php echo formatTime($record['used_at']); ?></small>
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
            </div>
            
            <!-- 积分使用说明 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-success">
                        <i class="fas fa-question-circle me-2"></i>积分使用说明
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>积分用途：</h6>
                            <ul>
                                <li>添加DNS记录消耗 <?php echo getSetting('points_per_record', 1); ?> 积分</li>
                                <li>修改DNS记录不消耗积分</li>
                                <li>删除DNS记录不消耗积分</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>获取积分：</h6>
                            <ul>
                                <li>新用户注册赠送 <?php echo getSetting('default_user_points', 100); ?> 积分</li>
                                <li>使用卡密充值获得积分</li>
                                <li>联系管理员人工充值</li>
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
// 自动转换卡密为大写
document.getElementById('card_key').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase();
});

// 自动格式化卡密输入（每4位加一个空格）
document.getElementById('card_key').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\s/g, '');
    let formattedValue = value.replace(/(.{4})/g, '$1 ').trim();
    if (formattedValue !== e.target.value) {
        e.target.value = formattedValue;
    }
});

// 提交前移除空格
document.querySelector('form').addEventListener('submit', function(e) {
    let cardKeyInput = document.getElementById('card_key');
    cardKeyInput.value = cardKeyInput.value.replace(/\s/g, '');
});
</script>

<?php include 'includes/footer.php'; ?>