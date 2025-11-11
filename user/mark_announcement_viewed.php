<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查用户登录状态
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => '方法不允许']);
    exit;
}

// 获取POST数据
$input = json_decode(file_get_contents('php://input'), true);
$announcement_id = isset($input['announcement_id']) ? (int)$input['announcement_id'] : 0;

if ($announcement_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => '无效的公告ID']);
    exit;
}

try {
    // 记录用户查看公告
    markAnnouncementViewed($_SESSION['user_id'], $announcement_id);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => '服务器错误']);
}
?>