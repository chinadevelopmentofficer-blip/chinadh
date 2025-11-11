<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理导出请求
if (getGet('export') === '1') {
    $invitation_id = getGet('id');
    if ($invitation_id && is_numeric($invitation_id)) {
        // 获取邀请码信息
        $invitation = $db->querySingle("
            SELECT i.*, u.username as inviter_username
            FROM invitations i 
            LEFT JOIN users u ON i.inviter_id = u.id 
            WHERE i.id = $invitation_id
        ", true);
        
        if ($invitation) {
            // 获取使用历史
            $usage_history = [];
            $result = $db->query("
                SELECT iu.*, u.username as invitee_username, u.email as invitee_email
                FROM invitation_uses iu
                JOIN users u ON iu.invitee_id = u.id
                WHERE iu.invitation_id = $invitation_id
                ORDER BY iu.used_at DESC
            ");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $usage_history[] = $row;
            }
            
            // 设置CSV头部
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="invitation_history_' . $invitation['invitation_code'] . '_' . date('Y-m-d') . '.csv"');
            
            // 输出CSV内容
            $output = fopen('php://output', 'w');
            
            // 添加BOM以支持中文
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV头部
            fputcsv($output, [
                '邀请码',
                '邀请人',
                '被邀请用户',
                '用户邮箱',
                '奖励积分',
                '使用时间'
            ]);
            
            // CSV数据
            foreach ($usage_history as $usage) {
                fputcsv($output, [
                    $invitation['invitation_code'],
                    $invitation['inviter_username'],
                    $usage['invitee_username'],
                    $usage['invitee_email'],
                    $usage['reward_points'],
                    $usage['used_at']
                ]);
            }
            
            fclose($output);
            exit;
        }
    }
    
    // 导出失败，重定向回去
    showError('导出失败：无效的邀请码');
    redirect('invitations.php');
}

// 获取邀请码ID
$invitation_id = getGet('id');
if (!$invitation_id || !is_numeric($invitation_id)) {
    showError('无效的邀请码ID');
    redirect('invitations.php');
}

// 获取邀请码基本信息
$invitation = $db->querySingle("
    SELECT i.*, u.username as inviter_username, u.email as inviter_email
    FROM invitations i 
    LEFT JOIN users u ON i.inviter_id = u.id 
    WHERE i.id = $invitation_id
", true);

if (!$invitation) {
    showError('邀请码不存在');
    redirect('invitations.php');
}

// 获取使用历史
$usage_history = [];
$result = $db->query("
    SELECT iu.*, u.username as invitee_username, u.email as invitee_email, u.points as invitee_points
    FROM invitation_uses iu
    JOIN users u ON iu.invitee_id = u.id
    WHERE iu.invitation_id = $invitation_id
    ORDER BY iu.used_at DESC
");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $usage_history[] = $row;
}

// 获取统计信息
$stats = [
    'total_uses' => count($usage_history),
    'total_rewards' => array_sum(array_column($usage_history, 'reward_points')),
    'first_use' => !empty($usage_history) ? end($usage_history)['used_at'] : null,
    'last_use' => !empty($usage_history) ? $usage_history[0]['used_at'] : null,
    'unique_users' => count($usage_history),
    'avg_reward' => !empty($usage_history) ? round(array_sum(array_column($usage_history, 'reward_points')) / count($usage_history), 1) : 0
];

$page_title = '邀请历史 - ' . $invitation['invitation_code'];
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-history me-2"></i>邀请历史
                    <small class="text-muted">- <?php echo htmlspecialchars($invitation['invitation_code']); ?></small>
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="invitations.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>返回列表
                        </a>
                        <button type="button" class="btn btn-outline-primary" onclick="exportHistory()">
                            <i class="fas fa-download me-2"></i>导出历史
                        </button>
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
                <!-- 邀请码信息 -->
                <div class="col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-ticket-alt me-2"></i>邀请码信息
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">邀请码</label>
                                <div class="d-flex align-items-center">
                                    <code class="invitation-code me-2"><?php echo htmlspecialchars($invitation['invitation_code']); ?></code>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('<?php echo $invitation['invitation_code']; ?>', '邀请码已复制')" title="复制邀请码">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">邀请人</label>
                                <div>
                                    <a href="users.php?search=<?php echo urlencode($invitation['inviter_username']); ?>" class="text-decoration-none">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($invitation['inviter_username']); ?>
                                    </a>
                                </div>
                                <small class="text-muted"><?php echo htmlspecialchars($invitation['inviter_email']); ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">状态</label>
                                <div>
                                    <?php if ($invitation['is_active']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>活跃
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="fas fa-times-circle me-1"></i>禁用
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold text-muted">单次奖励</label>
                                <div>
                                    <span class="badge bg-info">
                                        <i class="fas fa-coins me-1"></i>
                                        <?php echo $invitation['reward_points']; ?> 积分
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-0">
                                <label class="form-label fw-bold text-muted">创建时间</label>
                                <div class="text-muted small">
                                    <?php echo formatTime($invitation['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 统计信息 -->
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-success">
                                <i class="fas fa-chart-bar me-2"></i>使用统计
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6 mb-3">
                                    <div class="border-end">
                                        <h4 class="text-primary mb-1"><?php echo $stats['total_uses']; ?></h4>
                                        <small class="text-muted">使用次数</small>
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <h4 class="text-success mb-1"><?php echo $stats['total_rewards']; ?></h4>
                                    <small class="text-muted">总奖励</small>
                                </div>
                            </div>
                            
                            <?php if ($stats['first_use']): ?>
                            <div class="mb-2">
                                <small class="text-muted">首次使用：</small>
                                <div class="small"><?php echo formatTime($stats['first_use']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['last_use']): ?>
                            <div class="mb-2">
                                <small class="text-muted">最后使用：</small>
                                <div class="small"><?php echo formatTime($stats['last_use']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($stats['avg_reward'] > 0): ?>
                            <div class="mb-0">
                                <small class="text-muted">平均奖励：</small>
                                <div class="small"><?php echo $stats['avg_reward']; ?> 积分/次</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- 使用历史 -->
                <div class="col-lg-8">
                    <div class="card shadow">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-list me-2"></i>使用历史
                                <span class="badge bg-primary ms-2"><?php echo count($usage_history); ?></span>
                            </h6>
                            <?php if (!empty($usage_history)): ?>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-filter me-1"></i>筛选
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="filterHistory('all')">
                                        <i class="fas fa-list me-2"></i>显示全部
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="filterHistory('recent')">
                                        <i class="fas fa-clock me-2"></i>最近7天
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="filterHistory('month')">
                                        <i class="fas fa-calendar me-2"></i>最近30天
                                    </a></li>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($usage_history)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">暂无使用记录</h5>
                                    <p class="text-muted">此邀请码还没有被任何用户使用过</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="historyTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th class="border-0">用户信息</th>
                                                <th class="border-0">奖励积分</th>
                                                <th class="border-0">使用时间</th>
                                                <th class="border-0">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($usage_history as $index => $usage): ?>
                                            <tr data-timestamp="<?php echo strtotime($usage['used_at']); ?>">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm bg-success rounded-circle d-flex align-items-center justify-content-center me-2">
                                                            <i class="fas fa-user text-white"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold">
                                                                <a href="users.php?search=<?php echo urlencode($usage['invitee_username']); ?>" 
                                                                   class="text-decoration-none">
                                                                    <?php echo htmlspecialchars($usage['invitee_username']); ?>
                                                                </a>
                                                            </div>
                                                            <small class="text-muted">
                                                                <?php echo htmlspecialchars($usage['invitee_email']); ?>
                                                            </small>
                                                            <div class="small text-info">
                                                                <i class="fas fa-coins me-1"></i>
                                                                当前积分: <?php echo $usage['invitee_points']; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success fs-6">
                                                        <i class="fas fa-plus me-1"></i>
                                                        <?php echo $usage['reward_points']; ?> 积分
                                                    </span>
                                                </td>
                                                <td>
                                                    <div><?php echo formatTime($usage['used_at']); ?></div>
                                                    <small class="text-muted">
                                                        第 <?php echo count($usage_history) - $index; ?> 次使用
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-sm btn-outline-info" 
                                                                onclick="viewUserDetails('<?php echo $usage['invitee_username']; ?>')" 
                                                                title="查看用户详情">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="copyUserInfo('<?php echo htmlspecialchars($usage['invitee_username']); ?>', '<?php echo htmlspecialchars($usage['invitee_email']); ?>')" 
                                                                title="复制用户信息">
                                                            <i class="fas fa-copy"></i>
                                                        </button>
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
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// 复制功能
function copyToClipboard(text, successMessage) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            showToast(successMessage, 'success');
        }).catch(function(err) {
            fallbackCopy(text, successMessage);
        });
    } else {
        fallbackCopy(text, successMessage);
    }
}

function fallbackCopy(text, successMessage) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showToast(successMessage, 'success');
    } catch (err) {
        showToast('复制失败，请手动复制', 'error');
    }
    
    document.body.removeChild(textArea);
}

// 复制用户信息
function copyUserInfo(username, email) {
    const userInfo = `用户名: ${username}\n邮箱: ${email}`;
    copyToClipboard(userInfo, '用户信息已复制');
}

// 查看用户详情
function viewUserDetails(username) {
    window.open(`users.php?search=${encodeURIComponent(username)}`, '_blank');
}

// 导出历史
function exportHistory() {
    const invitationCode = '<?php echo $invitation['invitation_code']; ?>';
    const currentUrl = new URL(window.location);
    currentUrl.searchParams.set('export', '1');
    window.open(currentUrl.toString(), '_blank');
    showToast('历史数据导出已开始', 'info');
}

// 筛选历史记录
function filterHistory(type) {
    const table = document.getElementById('historyTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    const now = Date.now() / 1000;
    
    for (let i = 0; i < rows.length; i++) {
        const timestamp = parseInt(rows[i].getAttribute('data-timestamp'));
        let show = true;
        
        switch (type) {
            case 'recent':
                show = (now - timestamp) <= (7 * 24 * 60 * 60); // 7天
                break;
            case 'month':
                show = (now - timestamp) <= (30 * 24 * 60 * 60); // 30天
                break;
            case 'all':
            default:
                show = true;
                break;
        }
        
        rows[i].style.display = show ? '' : 'none';
    }
    
    // 更新筛选提示
    const filterText = {
        'all': '显示全部',
        'recent': '最近7天',
        'month': '最近30天'
    };
    showToast(`已筛选: ${filterText[type]}`, 'info');
}

// 显示提示
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0 position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : 'info'} me-2"></i>${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    document.body.appendChild(toast);
    
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 5000);
}
</script>

<style>
.invitation-code {
    background-color: #f8f9fa;
    padding: 4px 8px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-weight: bold;
    color: #495057;
    border: 1px solid #dee2e6;
    font-size: 0.875rem;
}

.avatar-sm {
    width: 32px;
    height: 32px;
    font-size: 0.875rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,123,255,0.05);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.btn-group .btn {
    transition: all 0.2s ease;
}

.btn-group .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
    transition: all 0.3s ease;
}

.card.shadow:hover {
    box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.2) !important;
    transform: translateY(-2px);
}
</style>

<?php include 'includes/footer.php'; ?>