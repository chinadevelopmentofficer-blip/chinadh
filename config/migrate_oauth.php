<?php
/**
 * 数据库迁移 - 添加OAuth支持
 */

require_once 'database.php';

function migrateOAuthSupport() {
    $db = Database::getInstance()->getConnection();
    
    // 检查users表是否已有OAuth字段
    $columns = [];
    $result = $db->query("PRAGMA table_info(users)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    // 添加OAuth相关字段
    if (!in_array('github_id', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN github_id TEXT");
    }
    
    if (!in_array('github_username', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN github_username TEXT");
    }
    
    if (!in_array('avatar_url', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN avatar_url TEXT");
    }
    
    if (!in_array('oauth_provider', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN oauth_provider TEXT");
    }
    
    if (!in_array('github_bonus_received', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN github_bonus_received INTEGER DEFAULT 0");
    }
    
    // 创建OAuth用户索引
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_github_id ON users(github_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_oauth_provider ON users(oauth_provider)");
    
    // 添加GitHub OAuth设置到系统设置表
    $oauth_settings = [
        ['github_oauth_enabled', '0', '是否启用GitHub OAuth登录'],
        ['github_client_id', '', 'GitHub OAuth Client ID'],
        ['github_client_secret', '', 'GitHub OAuth Client Secret'],
        ['github_auto_register', '1', '是否允许GitHub用户自动注册'],
        ['github_bonus_points', '200', 'GitHub用户奖励积分']
    ];
    
    foreach ($oauth_settings as $setting) {
        $exists = $db->querySingle("SELECT COUNT(*) FROM settings WHERE setting_key = '{$setting[0]}'");
        if (!$exists) {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $setting[0], SQLITE3_TEXT);
            $stmt->bindValue(2, $setting[1], SQLITE3_TEXT);
            $stmt->bindValue(3, $setting[2], SQLITE3_TEXT);
            $stmt->execute();
        }
    }
    
    return true;
}

// 如果直接运行此文件，执行迁移
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        migrateOAuthSupport();
        echo "OAuth数据库迁移完成！\n";
    } catch (Exception $e) {
        echo "迁移失败: " . $e->getMessage() . "\n";
    }
}