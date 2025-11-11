<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// 记录退出日志
if (isset($_SESSION['user_id'])) {
    logAction('user', $_SESSION['user_id'], 'logout', '用户退出登录');
}

// 清除会话
session_destroy();

// 重定向到登录页面
redirect('login.php');
?>