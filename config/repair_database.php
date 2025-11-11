<?php
/**
 * 数据库修复脚本
 * 用于检查和修复数据库结构，确保所有必要的表和字段都存在
 */

require_once 'database.php';

function repairDatabase() {
    echo "开始检查和修复数据库结构...\n";
    
    try {
        // 强制重新初始化数据库
        $db = Database::getInstance()->getConnection();
        
        echo "✅ 数据库连接成功\n";
        
        // 检查所有表是否存在
        $requiredTables = [
            'users', 'admins', 'domains', 'dns_records', 'settings',
            'card_keys', 'card_key_usage', 'action_logs', 'dns_record_types',
            'invitations', 'invitation_uses', 'announcements', 'user_announcement_views',
            'blocked_prefixes', 'login_attempts', 'cloudflare_accounts'
        ];
        
        $existingTables = [];
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $existingTables[] = $row['name'];
        }
        
        echo "现有表: " . implode(', ', $existingTables) . "\n";
        
        $missingTables = array_diff($requiredTables, $existingTables);
        if (!empty($missingTables)) {
            echo "缺失的表: " . implode(', ', $missingTables) . "\n";
        } else {
            echo "✅ 所有必需的表都存在\n";
        }
        
        // 检查关键字段
        $criticalFields = [
            'invitations' => ['last_used_at', 'is_active', 'use_count'],
            'card_keys' => ['used_count', 'status'],
            'users' => ['status', 'points'],
            'announcements' => ['is_active', 'type'],
            'blocked_prefixes' => ['is_active'],
            'login_attempts' => ['success', 'attempt_time']
        ];
        
        foreach ($criticalFields as $table => $fields) {
            if (in_array($table, $existingTables)) {
                $tableColumns = [];
                $result = $db->query("PRAGMA table_info($table)");
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $tableColumns[] = $row['name'];
                }
                
                $missingFields = array_diff($fields, $tableColumns);
                if (!empty($missingFields)) {
                    echo "⚠️  表 $table 缺失字段: " . implode(', ', $missingFields) . "\n";
                } else {
                    echo "✅ 表 $table 的关键字段完整\n";
                }
            }
        }
        
        echo "数据库结构检查完成！\n";
        return true;
        
    } catch (Exception $e) {
        echo "❌ 数据库修复失败: " . $e->getMessage() . "\n";
        return false;
    }
}

// 如果直接运行此脚本
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== 数据库修复工具 ===\n";
    
    if (repairDatabase()) {
        echo "\n✅ 数据库检查修复完成！\n";
    } else {
        echo "\n❌ 数据库修复失败！\n";
    }
}
?>