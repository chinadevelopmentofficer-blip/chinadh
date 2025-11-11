<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 处理密码修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = getPost('current_password');
    $new_password = getPost('new_password');
    $confirm_password = getPost('confirm_password');
    
    if (!$current_password || !$new_password || !$confirm_password) {
        showError('请填写完整信息');
    } elseif (strlen($new_password) < 6) {
        showError('新密码至少需要6个字符');
    } elseif ($new_password !== $confirm_password) {
        showError('两次输入的新密码不一致');
    } else {
        // 验证当前密码
        $admin = $db->querySingle("SELECT password FROM admins WHERE id = {$_SESSION['admin_id']}", true);
        if (password_verify($current_password, $admin['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $stmt->bindValue(1, $hashed_password, SQLITE3_TEXT);
            $stmt->bindValue(2, $_SESSION['admin_id'], SQLITE3_INTEGER);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'change_password', '管理员修改密码');
                showSuccess('密码修改成功！');
            } else {
                showError('密码修改失败！');
            }
        } else {
            showError('当前密码错误！');
        }
    }
    redirect('profile.php');
}

// 处理邮箱修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email'])) {
    $email = getPost('email');
    
    if (!$email) {
        showError('请输入邮箱地址');
    } elseif (!isValidEmail($email)) {
        showError('请输入有效的邮箱地址');
    } else {
        $stmt = $db->prepare("UPDATE admins SET email = ? WHERE id = ?");
        $stmt->bindValue(1, $email, SQLITE3_TEXT);
        $stmt->bindValue(2, $_SESSION['admin_id'], SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            logAction('admin', $_SESSION['admin_id'], 'update_email', '管理员更新邮箱');
            showSuccess('邮箱更新成功！');
        } else {
            showError('邮箱更新失败！');
        }
    }
    redirect('profile.php');
}

// 处理添加新管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $username = getPost('username');
    $password = getPost('password');
    $email = getPost('email');
    
    if (!$username || !$password) {
        showError('请填写用户名和密码');
    } elseif (strlen($password) < 6) {
        showError('密码至少需要6个字符');
    } else {
        // 检查用户名是否已存在
        $exists = $db->querySingle("SELECT COUNT(*) FROM admins WHERE username = '$username'");
        if ($exists) {
            showError('用户名已存在');
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO admins (username, password, email) VALUES (?, ?, ?)");
            $stmt->bindValue(1, $username, SQLITE3_TEXT);
            $stmt->bindValue(2, $hashed_password, SQLITE3_TEXT);
            $stmt->bindValue(3, $email, SQLITE3_TEXT);
            
            if ($stmt->execute()) {
                logAction('admin', $_SESSION['admin_id'], 'add_admin', "添加新管理员: $username");
                showSuccess('新管理员添加成功！');
            } else {
                showError('添加管理员失败！');
            }
        }
    }
    redirect('profile.php');
}

// 处理删除管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    $admin_id = (int)getPost('admin_id');
    
    if ($admin_id == $_SESSION['admin_id']) {
        showError('不能删除自己的账户！');
    } elseif ($admin_id <= 0) {
        showError('无效的管理员ID！');
    } else {
        // 检查是否是最后一个管理员
        $admin_count = $db->querySingle("SELECT COUNT(*) FROM admins");
        if ($admin_count <= 1) {
            showError('不能删除最后一个管理员账户！');
        } else {
            $admin = $db->querySingle("SELECT username FROM admins WHERE id = $admin_id", true);
            if ($admin) {
                $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
                $stmt->bindValue(1, $admin_id, SQLITE3_INTEGER);
                
                if ($stmt->execute() && $db->changes() > 0) {
                    logAction('admin', $_SESSION['admin_id'], 'delete_admin', "删除管理员: {$admin['username']}");
                    showSuccess('管理员删除成功！');
                } else {
                    showError('删除失败，管理员不存在！');
                }
            } else {
                showError('管理员不存在！');
            }
        }
    }
    redirect('profile.php');
}

// 处理编辑管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_admin'])) {
    $admin_id = (int)getPost('edit_admin_id');
    $username = getPost('edit_username');
    $email = getPost('edit_email');
    $status = (int)getPost('edit_status', 1);
    $new_password = getPost('edit_password');
    
    if ($admin_id <= 0) {
        showError('无效的管理员ID！');
    } elseif (!$username) {
        showError('用户名不能为空！');
    } else {
        // 检查用户名是否被其他管理员使用
        $exists = $db->querySingle("SELECT COUNT(*) FROM admins WHERE username = '$username' AND id != $admin_id");
        if ($exists) {
            showError('用户名已被其他管理员使用！');
        } else {
            $admin = $db->querySingle("SELECT username FROM admins WHERE id = $admin_id", true);
            if ($admin) {
                // 构建更新SQL
                if ($new_password) {
                    if (strlen($new_password) < 6) {
                        showError('密码至少需要6个字符');
                        redirect('profile.php');
                        exit;
                    }
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE admins SET username = ?, email = ?, password = ? WHERE id = ?");
                    $stmt->bindValue(1, $username, SQLITE3_TEXT);
                    $stmt->bindValue(2, $email, SQLITE3_TEXT);
                    $stmt->bindValue(3, $hashed_password, SQLITE3_TEXT);
                    $stmt->bindValue(4, $admin_id, SQLITE3_INTEGER);
                } else {
                    $stmt = $db->prepare("UPDATE admins SET username = ?, email = ? WHERE id = ?");
                    $stmt->bindValue(1, $username, SQLITE3_TEXT);
                    $stmt->bindValue(2, $email, SQLITE3_TEXT);
                    $stmt->bindValue(3, $admin_id, SQLITE3_INTEGER);
                }
                
                if ($stmt->execute()) {
                    $details = "编辑管理员: {$admin['username']} -> $username";
                    if ($new_password) {
                        $details .= " (包含密码重置)";
                    }
                    logAction('admin', $_SESSION['admin_id'], 'edit_admin', $details);
                    showSuccess('管理员信息更新成功！');
                } else {
                    showError('更新失败！');
                }
            } else {
                showError('管理员不存在！');
            }
        }
    }
    redirect('profile.php');
}

// 获取当前管理员信息
$current_admin = $db->querySingle("SELECT * FROM admins WHERE id = {$_SESSION['admin_id']}", true);

// 获取所有管理员
$admins = [];
$result = $db->query("SELECT * FROM admins ORDER BY created_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $admins[] = $row;
}

$page_title = '管理员设置';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">管理员设置</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="fas fa-user-plus me-1"></i>添加管理员
                    </button>
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
                <!-- 个人信息 -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">个人信息</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="username" class="form-label">用户名</label>
                                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($current_admin['username']); ?>" readonly>
                                    <div class="form-text">用户名不可修改</div>
                                </div>
                                <div class="mb-3">
                                    <label for="email" class="form-label">邮箱地址</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($current_admin['email'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">创建时间</label>
                                    <input type="text" class="form-control" value="<?php echo formatTime($current_admin['created_at']); ?>" readonly>
                                </div>
                                <button type="submit" name="update_email" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>更新邮箱
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- 密码修改 -->
                <div class="col-lg-6 mb-4">
                    <div class="card shadow">
                        <div class="card-header">
                            <h6 class="m-0 font-weight-bold text-primary">修改密码</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">当前密码</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">新密码</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <div class="form-text">密码至少6个字符</div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">确认新密码</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-warning">
                                    <i class="fas fa-key me-1"></i>修改密码
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 管理员列表 -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">管理员列表</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>用户名</th>
                                    <th>邮箱</th>
                                    <th>创建时间</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><?php echo $admin['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                        <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                            <span class="badge bg-primary ms-1">当前</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($admin['email'] ?? '未设置'); ?></td>
                                    <td><?php echo formatTime($admin['created_at']); ?></td>
                                    <td>
                                        <span class="badge bg-success">正常</span>
                                    </td>
                                    <td>
                                        <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                        <button type="button" class="btn btn-sm btn-primary me-1" 
                                                onclick="editAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>', '<?php echo htmlspecialchars($admin['email'] ?? ''); ?>')">
                                            <i class="fas fa-edit"></i> 编辑
                                        </button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirmDelete('确定要删除管理员 <?php echo htmlspecialchars($admin['username']); ?> 吗？这个操作不可恢复！')">
                                            <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                            <button type="submit" name="delete_admin" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i> 删除
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted">当前账户</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- 添加管理员模态框 -->
<div class="modal fade" id="addAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加新管理员</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new_username" class="form-label">用户名</label>
                        <input type="text" class="form-control" id="new_username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_email" class="form-label">邮箱地址</label>
                        <input type="email" class="form-control" id="new_email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">密码</label>
                        <input type="password" class="form-control" id="new_password" name="password" required>
                        <div class="form-text">密码至少6个字符</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_admin" class="btn btn-primary">添加管理员</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑管理员模态框 -->
<div class="modal fade" id="editAdminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑管理员</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" id="edit_admin_id" name="edit_admin_id">
                    <div class="mb-3">
                        <label for="edit_username" class="form-label">用户名</label>
                        <input type="text" class="form-control" id="edit_username" name="edit_username" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">邮箱地址</label>
                        <input type="email" class="form-control" id="edit_email" name="edit_email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_password" class="form-label">新密码</label>
                        <input type="password" class="form-control" id="edit_password" name="edit_password">
                        <div class="form-text">留空则不修改密码，如填写则密码至少6个字符</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_admin" class="btn btn-primary">保存修改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(message) {
    return confirm(message);
}

function editAdmin(id, username, email) {
    document.getElementById('edit_admin_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_password').value = '';
    
    var editModal = new bootstrap.Modal(document.getElementById('editAdminModal'));
    editModal.show();
}
</script>

<?php include 'includes/footer.php'; ?>