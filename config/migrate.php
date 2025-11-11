<?php
/**
 * 数据库迁移脚本
 * 用于将旧数据库结构升级到新版本
 */

require_once 'database.php';

function migrateDatabase() {
    $db = Database::getInstance()->getConnection();
    
    echo "开始数据库迁移...\n";
    
    // 检查并添加缺失的列
    try {
        // 检查users表是否有status列
        $result = $db->query("PRAGMA table_info(users)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (!in_array('status', $columns)) {
            $db->exec("ALTER TABLE users ADD COLUMN status INTEGER DEFAULT 1");
            echo "✓ 添加users.status列\n";
        }
        
        if (!in_array('email', $columns)) {
            $db->exec("ALTER TABLE users ADD COLUMN email TEXT");
            echo "✓ 添加users.email列\n";
        }
        
        if (!in_array('created_at', $columns)) {
            $db->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "✓ 添加users.created_at列\n";
        }
        
        if (!in_array('updated_at', $columns)) {
            $db->exec("ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "✓ 添加users.updated_at列\n";
        }
        
        // 检查domains表
        $result = $db->query("PRAGMA table_info(domains)");
        $domain_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $domain_columns[] = $row['name'];
        }
        
        if (!in_array('status', $domain_columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN status INTEGER DEFAULT 1");
            echo "✓ 添加domains.status列\n";
        }
        
        if (!in_array('created_at', $domain_columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "✓ 添加domains.created_at列\n";
        }
        
        if (!in_array('updated_at', $domain_columns)) {
            $db->exec("ALTER TABLE domains ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "✓ 添加domains.updated_at列\n";
        }
        
        // 检查dns_records表
        $result = $db->query("PRAGMA table_info(dns_records)");
        $record_columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $record_columns[] = $row['name'];
        }
        
        if (!in_array('status', $record_columns)) {
            $db->exec("ALTER TABLE dns_records ADD COLUMN status INTEGER DEFAULT 1");
            echo "✓ 添加dns_records.status列\n";
        }
        
        if (!in_array('updated_at', $record_columns)) {
            $db->exec("ALTER TABLE dns_records ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "✓ 添加dns_records.updated_at列\n";
        }
        
        if (!in_array('remark', $record_columns)) {
            $db->exec("ALTER TABLE dns_records ADD COLUMN remark TEXT DEFAULT ''");
            echo "✓ 添加dns_records.remark列\n";
        }
        
        // 创建管理员表（如果不存在）
        $db->exec("CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        
        // 创建系统设置表
        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // 插入默认设置
        $default_settings = [
            ['points_per_record', '1', '每条DNS记录消耗积分'],
            ['default_user_points', '100', '新用户默认积分'],
            ['site_name', 'Cloudflare DNS管理系统', '网站名称'],
            ['allow_registration', '1', '是否允许用户注册']
        ];
        
        foreach ($default_settings as $setting) {
            $exists = $db->querySingle("SELECT COUNT(*) FROM settings WHERE setting_key = '{$setting[0]}'");
            if (!$exists) {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $setting[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $setting[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $setting[2], SQLITE3_TEXT);
                $stmt->execute();
                echo "✓ 添加设置: {$setting[0]}\n";
            }
        }
        
        // 创建操作日志表
        $db->exec("CREATE TABLE IF NOT EXISTS action_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_type TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        echo "✓ 创建操作日志表\n";
        
        // 创建Cloudflare账户表
        $db->exec("CREATE TABLE IF NOT EXISTS cloudflare_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            api_key TEXT NOT NULL,
            status INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        echo "✓ 创建Cloudflare账户表\n";
        
        // 创建DNS记录类型表
        $db->exec("CREATE TABLE IF NOT EXISTS dns_record_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type_name TEXT NOT NULL UNIQUE,
            description TEXT,
            enabled INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        echo "✓ 创建DNS记录类型表\n";
        
        // 插入默认DNS记录类型
        $default_types = [
            ['A', 'IPv4地址记录', 1],
            ['AAAA', 'IPv6地址记录', 1],
            ['CNAME', '别名记录', 1],
            ['MX', '邮件交换记录', 1],
            ['TXT', '文本记录', 1],
            ['NS', '名称服务器记录', 0],
            ['PTR', '反向解析记录', 0],
            ['SRV', '服务记录', 0],
            ['CAA', '证书颁发机构授权记录', 0]
        ];
        
        foreach ($default_types as $type) {
            $exists = $db->querySingle("SELECT COUNT(*) FROM dns_record_types WHERE type_name = '{$type[0]}'");
            if (!$exists) {
                $stmt = $db->prepare("INSERT INTO dns_record_types (type_name, description, enabled) VALUES (?, ?, ?)");
                $stmt->bindValue(1, $type[0], SQLITE3_TEXT);
                $stmt->bindValue(2, $type[1], SQLITE3_TEXT);
                $stmt->bindValue(3, $type[2], SQLITE3_INTEGER);
                $stmt->execute();
                echo "✓ 添加DNS记录类型: {$type[0]}\n";
            }
        }
        
        echo "\n数据库迁移完成！\n";
        
    } catch (Exception $e) {
        echo "迁移过程中出现错误: " . $e->getMessage() . "\n";
        return false;
    }
    
    return true;
}

// 如果直接访问此文件，执行迁移
if (basename($_SERVER['PHP_SELF']) === 'migrate.php') {
    migrateDatabase();
}
?>
