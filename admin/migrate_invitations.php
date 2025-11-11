<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 检查是否需要迁移
function needsMigration($db) {
    $columns = [];
    $result = $db->query("PRAGMA table_info(invitations)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    return !in_array('is_active', $columns);
}

// 执行迁移
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    try {
        // 开始事务
        $db->exec('BEGIN TRANSACTION');
        
        // 1. 备份旧的邀请表
        $db->exec("CREATE TABLE IF NOT EXISTS invitations_backup AS SELECT * FROM invitations");
        
        // 2. 创建新的邀请表结构
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
        
        // 3. 创建邀请使用记录表
        $db->exec("CREATE TABLE IF NOT EXISTS invitation_uses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invitation_id INTEGER NOT NULL,
            invitee_id INTEGER NOT NULL,
            reward_points INTEGER DEFAULT 0,
            used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invitation_id) REFERENCES invitations(id),
            FOREIGN KEY (invitee_id) REFERENCES users(id)
        )");
        
        // 4. 迁移数据
        $oldInvitations = [];
        $result = $db->query("SELECT * FROM invitations");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $oldInvitations[] = $row;
        }
        
        $migratedCount = 0;
        $useRecordsCount = 0;
        
        foreach ($oldInvitations as $old) {
            // 计算新字段的值
            $use_count = (isset($old['status']) && $old['status'] == 1) ? 1 : 0;
            $total_rewards = (isset($old['reward_given']) && $old['reward_given'] == 1) ? $old['reward_points'] : 0;
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
            $migratedCount++;
            
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
                $useRecordsCount++;
            }
        }
        
        // 5. 替换旧表
        $db->exec("DROP TABLE invitations");
        $db->exec("ALTER TABLE invitations_new RENAME TO invitations");
        
        // 6. 提交事务
        $db->exec('COMMIT');
        
        logAction('admin', $_SESSION['admin_id'], 'migrate_invitations', "邀请系统迁移完成，迁移 $migratedCount 个邀请码，$useRecordsCount 个使用记录");
        showSuccess("邀请系统迁移成功！迁移了 $migratedCount 个邀请码，$useRecordsCount 个使用记录。");
        redirect('invitations.php');
        
    } catch (Exception $e) {
        // 回滚事务
        $db->exec('ROLLBACK');
        showError('迁移失败：' . $e->getMessage());
    }
}

$needs_migration = needsMigration($db);
$page_title = '邀请系统迁移';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><i class="fas fa-database me-2"></i>邀请系统迁移</h1>
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
                <div class="col-lg-8">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">
                                <i class="fas fa-arrow-up me-2"></i>升级到永久邀请码系统
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if ($needs_migration): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>检测到旧版本邀请系统</strong><br>
                                    需要升级数据库结构以支持永久邀请码功能。
                                </div>
                                
                                <h6 class="text-primary mb-3">升级内容：</h6>
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item d-flex align-items-center">
                                        <i class="fas fa-check-circle text-success me-3"></i>
                                        <div>
                                            <strong>永久邀请码</strong><br>
                                            <small class="text-muted">邀请码永不过期，可重复使用</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center">
                                        <i class="fas fa-chart-line text-info me-3"></i>
                                        <div>
                                            <strong>使用统计</strong><br>
                                            <small class="text-muted">详细记录每个邀请码的使用次数和奖励</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center">
                                        <i class="fas fa-users text-warning me-3"></i>
                                        <div>
                                            <strong>用户记录</strong><br>
                                            <small class="text-muted">记录所有使用邀请码的用户信息</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex align-items-center">
                                        <i class="fas fa-coins text-success me-3"></i>
                                        <div>
                                            <strong>持续奖励</strong><br>
                                            <small class="text-muted">每次有新用户使用邀请码都可获得奖励</small>
                                        </div>
                                    </li>
                                </ul>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>迁移说明：</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>系统会自动备份现有数据</li>
                                        <li>已使用的邀请码将转换为永久有效</li>
                                        <li>现有的使用记录和奖励将被保留</li>
                                        <li>迁移过程中系统可能暂时不可用</li>
                                    </ul>
                                </div>
                                
                                <form method="POST" onsubmit="return confirmMigration()">
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="migrate" class="btn btn-primary btn-lg">
                                            <i class="fas fa-rocket me-2"></i>开始升级
                                        </button>
                                        <a href="invitations.php" class="btn btn-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>稍后升级
                                        </a>
                                    </div>
                                </form>
                                
                            <?php else: ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>系统已是最新版本</strong><br>
                                    您的邀请系统已经支持永久邀请码功能。
                                </div>
                                
                                <div class="text-center">
                                    <i class="fas fa-check-circle fa-5x text-success mb-3"></i>
                                    <h5 class="text-success">升级完成</h5>
                                    <p class="text-muted">您现在可以使用所有永久邀请码功能了。</p>
                                    <a href="invitations.php" class="btn btn-primary">
                                        <i class="fas fa-user-friends me-2"></i>管理邀请系统
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-info">
                                <i class="fas fa-question-circle me-2"></i>常见问题
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="accordion" id="faqAccordion">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq1">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                            升级是否安全？
                                        </button>
                                    </h2>
                                    <div id="collapse1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <small>系统会自动备份原始数据，升级失败时会自动回滚，确保数据安全。</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq2">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                            升级需要多长时间？
                                        </button>
                                    </h2>
                                    <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <small>通常只需要几秒钟，具体时间取决于现有邀请记录的数量。</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="faq3">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                            现有数据会丢失吗？
                                        </button>
                                    </h2>
                                    <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                        <div class="accordion-body">
                                            <small>不会。所有现有的邀请码、用户记录和奖励信息都会被完整保留。</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
function confirmMigration() {
    return confirm('确定要开始升级邀请系统吗？\n\n升级过程中请不要关闭浏览器或刷新页面。');
}
</script>

<?php include 'includes/footer.php'; ?>