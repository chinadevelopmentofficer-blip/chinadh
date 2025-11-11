<?php
/**
 * 邀请详情API接口
 */
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// 检查管理员登录
checkAdminLogin();

// 设置JSON响应头
header('Content-Type: application/json');

// 获取参数
$invitation_id = (int)getGet('id');
$invitation_code = getGet('code');

if (!$invitation_id && !$invitation_code) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // 构建查询条件
    $where_clause = '';
    $bind_value = '';
    if ($invitation_id) {
        $where_clause = 'i.id = ?';
        $bind_value = $invitation_id;
    } else {
        $where_clause = 'i.invitation_code = ?';
        $bind_value = $invitation_code;
    }
    
    // 获取邀请详情
    $sql = "SELECT i.*, u.username as inviter_username, u.points as inviter_points
            FROM invitations i
            LEFT JOIN users u ON i.user_id = u.id
            WHERE $where_clause";
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $bind_value);
    $result = $stmt->execute();
    $invitation = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$invitation) {
        http_response_code(404);
        echo json_encode(['error' => '邀请记录不存在']);
        exit;
    }
    
    // 获取使用历史
    $history_sql = "SELECT ih.*, u.username as invitee_username
                    FROM invitation_history ih
                    LEFT JOIN users u ON ih.invitee_id = u.id
                    WHERE ih.invitation_id = ?
                    ORDER BY ih.used_at DESC
                    LIMIT 20";
    
    $history_stmt = $db->prepare($history_sql);
    $history_stmt->bindValue(1, $invitation['id']);
    $history_result = $history_stmt->execute();
    
    $usage_history = [];
    while ($row = $history_result->fetchArray(SQLITE3_ASSOC)) {
        $usage_history[] = [
            'invitee_username' => $row['invitee_username'],
            'used_at' => $row['used_at'],
            'reward_points' => $row['reward_points'],
            'ip_address' => $row['ip_address'] ?? '未知'
        ];
    }
    
    // 获取统计信息
    $stats_sql = "SELECT 
                    COUNT(*) as total_uses,
                    COUNT(DISTINCT invitee_id) as unique_users,
                    SUM(reward_points) as total_rewards,
                    MAX(used_at) as last_used_at,
                    MIN(used_at) as first_used_at
                  FROM invitation_history 
                  WHERE invitation_id = ?";
    
    $stats_stmt = $db->prepare($stats_sql);
    $stats_stmt->bindValue(1, $invitation['id']);
    $stats_result = $stats_stmt->execute();
    $stats = $stats_result->fetchArray(SQLITE3_ASSOC);
    
    // 获取最近30天的使用情况
    $recent_sql = "SELECT DATE(used_at) as date, COUNT(*) as count
                   FROM invitation_history 
                   WHERE invitation_id = ? AND used_at >= datetime('now', '-30 days')
                   GROUP BY DATE(used_at)
                   ORDER BY date DESC";
    
    $recent_stmt = $db->prepare($recent_sql);
    $recent_stmt->bindValue(1, $invitation['id']);
    $recent_result = $recent_stmt->execute();
    
    $recent_usage = [];
    while ($row = $recent_result->fetchArray(SQLITE3_ASSOC)) {
        $recent_usage[] = $row;
    }
    
    // 返回详细信息
    $response = [
        'invitation' => [
            'id' => $invitation['id'],
            'invitation_code' => $invitation['invitation_code'],
            'inviter_username' => $invitation['inviter_username'],
            'inviter_points' => $invitation['inviter_points'],
            'is_active' => (bool)$invitation['is_active'],
            'reward_points' => $invitation['reward_points'],
            'created_at' => $invitation['created_at'],
            'updated_at' => $invitation['updated_at']
        ],
        'statistics' => [
            'total_uses' => (int)$stats['total_uses'],
            'unique_users' => (int)$stats['unique_users'],
            'total_rewards' => (int)$stats['total_rewards'],
            'last_used_at' => $stats['last_used_at'],
            'first_used_at' => $stats['first_used_at']
        ],
        'usage_history' => $usage_history,
        'recent_usage' => $recent_usage
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误: ' . $e->getMessage()]);
}
?>