<?php
/**
 * 邀请系统数据库迁移脚本
 * 将旧的邀请系统升级为永久邀请码系统
 */

require_once 'database.php';

function migrateInvitationSystem() {
    $db = Database::getInstance()->getConnection();
    
    echo "开始迁移邀请系统数据库...\n";
    
    try {
        // 开始事务
        $db->exec('BEGIN TRANSACTION');
        
        // 1. 检查是否已经迁移过
        $columns = [];
        $result = $db->query("PRAGMA table_info(invitations)");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        
        if (in_array('is_active', $columns)) {
            echo "数据库已经是最新版本，无需迁移。\n";
            $db->exec('ROLLBACK');
            return true;
        }
        
        echo "检测到旧版本数据库，开始迁移...\n";
        
        // 2. 备份旧的邀请表
        echo "备份原始数据...\n";
        $db->exec("CREATE TABLE invitations_backup AS SELECT * FROM invitations");
        
        // 3. 创建新的邀请表结构
        echo "创建新的邀请表结构...\n";
        $db->exec("DROP TABLE IF EXISTS invitations_new");
        $db->exec("CREATE TABLE invitations_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inviter_id INTEGER NOT NULL,
            invitation_code TEXT NOT NULL UNIQUE,
            reward_points INTEGER DEFAULT 0,
            use_count INTEGER DEFAULT 0,
            total_rewards INTEGER DEFAULT 0,
            is_active INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP DEFAULT NULL,
            FOREIGN KEY (inviter_id) REFERENCES users(id)
        )");
        
        // 4. 创建邀请使用记录表
        echo "创建邀请使用记录表...\n";
        $db->exec("CREATE TABLE IF NOT EXISTS invitation_uses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invitation_id INTEGER NOT NULL,
            invitee_id INTEGER NOT NULL,
            reward_points INTEGER DEFAULT 0,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invitation_id) REFERENCES invitations(id),
            FOREIGN KEY (invitee_id) REFERENCES users(id)
        )");
        
        // 5. 迁移数据
        echo "迁移现有数据...\n";
        $oldInvitations = [];
        $result = $db->query("SELECT * FROM invitations_backup");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $oldInvitations[] = $row;
        }
        
        foreach ($oldInvitations as $old) {
            // 计算新字段的值
            $use_count = isset($old['status']) && $old['status'] == 1 ? 1 : 0;
            $total_rewards = isset($old['reward_given']) && $old['reward_given'] == 1 ? $old['reward_points'] : 0;
            $last_used_at = isset($old['used_at']) ? $old['used_at'] : null;
            
            // 插入到新表
            $stmt = $db->prepare("INSERT INTO invitations_new 
                (id, inviter_id, invitation_code, reward_points, use_count, total_rewards, is_active, created_at, last_used_at) 
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmt->bindValue(1, $old['id'], SQLITE3_INTEGER);
            $stmt->bindValue(2, $old['inviter_id'], SQLITE3_INTEGER);
            $stmt->bindValue(3, $old['invitation_code'], SQLITE3_TEXT);
            $stmt->bindValue(4, $old['reward_points'], SQLITE3_INTEGER);
            $stmt->bindValue(5, $use_count, SQLITE3_INTEGER);
            $stmt->bindValue(6, $total_rewards, SQLITE3_INTEGER);
            $stmt->bindValue(7, $old['created_at'], SQLITE3_TEXT);
            $stmt->bindValue(8, $last_used_at, SQLITE3_TEXT);
            $stmt->execute();
            
            // 如果有使用记录，添加到使用记录表
            if ($use_count > 0 && isset($old['invitee_id']) && $old['invitee_id']) {
                $stmt = $db->prepare("INSERT INTO invitation_uses 
                    (invitation_id, invitee_id, reward_points, used_at) 
                    VALUES (?, ?, ?, ?)");
                $stmt->bindValue(1, $old['id'], SQLITE3_INTEGER);
                $stmt->bindValue(2, $old['invitee_id'], SQLITE3_INTEGER);
                $stmt->bindValue(3, $old['reward_points'], SQLITE3_INTEGER);
                $stmt->bindValue(4, $old['used_at'] ?: $old['created_at'], SQLITE3_TEXT);
                $stmt->execute();
            }
        }
        
        // 6. 替换旧表
        echo "替换旧表结构...\n";
        $db->exec("DROP TABLE invitations");
        $db->exec("ALTER TABLE invitations_new RENAME TO invitations");
        
        // 7. 提交事务
        $db->exec('COMMIT');
        
        echo "邀请系统迁移完成！\n";
        echo "迁移统计：\n";
        echo "- 迁移邀请码数量：" . count($oldInvitations) . "\n";
        echo "- 活跃邀请码：" . $db->querySingle("SELECT COUNT(*) FROM invitations WHERE is_active = 1") . "\n";
        echo "- 使用记录：" . $db->querySingle("SELECT COUNT(*) FROM invitation_uses") . "\n";
        
        return true;
        
    } catch (Exception $e) {
        // 回滚事务
        $db->exec('ROLLBACK');
        echo "迁移失败：" . $e->getMessage() . "\n";
        return false;
    }
}

// 如果直接运行此脚本
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== 邀请系统数据库迁移工具 ===\n";
    echo "警告：此操作将修改数据库结构，请确保已备份数据库！\n";
    echo "按 Enter 继续，或 Ctrl+C 取消...\n";
    
    if (php_sapi_name() === 'cli') {
        fgets(STDIN);
    }
    
    if (migrateInvitationSystem()) {
        echo "\n✅ 迁移成功完成！\n";
        echo "现在可以使用新的永久邀请码功能了。\n";
    } else {
        echo "\n❌ 迁移失败！\n";
        echo "请检查错误信息并重试。\n";
    }
}
?>