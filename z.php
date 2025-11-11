<?php
/**
 * 子域名重复检测工具
 * 用于检测和显示存在多条记录的子域名
 * 独立运行，使用完毕后可删除
 */

// 引入数据库配置
require_once 'config/database.php';

// 设置页面编码
header('Content-Type: text/html; charset=UTF-8');

// 获取数据库连接
$db = Database::getInstance()->getConnection();

// 查询所有子域名及其记录数
$duplicate_query = "
    SELECT 
        dr.subdomain,
        COUNT(*) as record_count
    FROM dns_records dr
    WHERE dr.status = 1
    GROUP BY dr.subdomain
    HAVING COUNT(*) > 1
    ORDER BY record_count DESC, dr.subdomain ASC
";

$duplicate_result = $db->query($duplicate_query);

$duplicate_subdomains = [];
while ($row = $duplicate_result->fetchArray(SQLITE3_ASSOC)) {
    $duplicate_subdomains[] = $row;
}

// 如果有重复的子域名，获取详细信息
$detailed_records = [];
if (!empty($duplicate_subdomains)) {
    foreach ($duplicate_subdomains as $dup) {
        $subdomain = $dup['subdomain'];
        
        $detail_query = "
            SELECT 
                dr.id,
                dr.subdomain,
                dr.type,
                dr.content,
                dr.proxied,
                dr.remark,
                dr.created_at,
                d.domain_name,
                CASE 
                    WHEN dr.user_id IS NULL OR dr.is_system = 1 THEN '系统所属'
                    ELSE COALESCE(u.username, '未知用户')
                END as username,
                COALESCE(u.email, '-') as user_email,
                CASE 
                    WHEN dr.user_id IS NULL OR dr.is_system = 1 THEN 1
                    ELSE 0
                END as is_system_record
            FROM dns_records dr
            LEFT JOIN users u ON dr.user_id = u.id AND dr.user_id IS NOT NULL AND (dr.is_system = 0 OR dr.is_system IS NULL)
            JOIN domains d ON dr.domain_id = d.id
            WHERE dr.subdomain = :subdomain AND dr.status = 1
            ORDER BY dr.created_at DESC
        ";
        
        $stmt = $db->prepare($detail_query);
        $stmt->bindValue(':subdomain', $subdomain, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $records = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $full_domain = $row['subdomain'] === '@' 
                ? $row['domain_name'] 
                : $row['subdomain'] . '.' . $row['domain_name'];
            
            $records[] = array_merge($row, ['full_domain' => $full_domain]);
        }
        
        if (!empty($records)) {
            $detailed_records[$subdomain] = $records;
        }
    }
}

// 统计信息
$total_duplicates = count($duplicate_subdomains);
$total_duplicate_records = 0;
foreach ($duplicate_subdomains as $dup) {
    $total_duplicate_records += $dup['record_count'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>子域名重复检测工具</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 30px 0;
        }
        .container {
            max-width: 1400px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px;
        }
        .table {
            margin-bottom: 0;
        }
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            white-space: nowrap;
        }
        .subdomain-group {
            background: #fff;
            border-radius: 10px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .subdomain-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .stat-item {
            text-align: center;
            padding: 15px;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 5px;
        }
        .badge-custom {
            padding: 5px 10px;
            border-radius: 5px;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .no-duplicates {
            text-align: center;
            padding: 60px 20px;
            color: white;
        }
        .no-duplicates i {
            font-size: 5rem;
            margin-bottom: 20px;
            opacity: 0.8;
        }
        .no-duplicates h3 {
            font-size: 2rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- 页面标题 -->
        <div class="text-center mb-4">
            <h1 class="text-white mb-2">
                <i class="fas fa-search-plus me-2"></i>子域名重复检测工具
            </h1>
            <p class="text-white-50">检测系统中存在多条记录的子域名</p>
        </div>

        <?php if ($total_duplicates > 0): ?>
            <!-- 统计信息 -->
            <div class="stats-card">
                <div class="row">
                    <div class="col-md-6">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $total_duplicates; ?></div>
                            <div class="stat-label">
                                <i class="fas fa-exclamation-triangle me-1"></i>重复的子域名数量
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $total_duplicate_records; ?></div>
                            <div class="stat-label">
                                <i class="fas fa-database me-1"></i>涉及的记录总数
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 重复子域名详情 -->
            <?php foreach ($detailed_records as $subdomain => $records): ?>
                <div class="subdomain-group">
                    <div class="subdomain-header">
                        <i class="fas fa-layer-group me-2"></i>
                        子域名: <code style="background: rgba(255,255,255,0.2); color: white; padding: 5px 10px; border-radius: 5px;"><?php echo htmlspecialchars($subdomain); ?></code>
                        <span class="badge bg-light text-dark ms-2"><?php echo count($records); ?> 条记录</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>完整域名</th>
                                    <th>邮箱</th>
                                    <th>用户名</th>
                                    <th>类型</th>
                                    <th>内容</th>
                                    <th>代理</th>
                                    <th>备注</th>
                                    <th>创建时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td><?php echo $record['id']; ?></td>
                                        <td><code><?php echo htmlspecialchars($record['full_domain']); ?></code></td>
                                        <td>
                                            <?php if ($record['is_system_record']): ?>
                                                <span class="text-muted">-</span>
                                            <?php else: ?>
                                                <code><?php echo htmlspecialchars($record['user_email']); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($record['is_system_record']): ?>
                                                <span class="badge bg-info badge-custom">
                                                    <i class="fas fa-server me-1"></i>系统所属
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success badge-custom">
                                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($record['username']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary badge-custom"><?php echo htmlspecialchars($record['type']); ?></span>
                                        </td>
                                        <td>
                                            <code class="text-break"><?php echo htmlspecialchars($record['content']); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($record['proxied'] == 1): ?>
                                                <span class="badge bg-success badge-custom">
                                                    <i class="fas fa-check"></i> 是
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary badge-custom">
                                                    <i class="fas fa-times"></i> 否
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($record['remark'])): ?>
                                                <?php echo htmlspecialchars($record['remark']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($record['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- 底部提示 -->
            <div class="alert alert-warning mt-4" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <strong>注意：</strong>此工具为临时检测脚本，使用完毕后请删除此文件（check_duplicate_subdomains.php）以确保安全。
            </div>

        <?php else: ?>
            <!-- 没有重复记录 -->
            <div class="no-duplicates">
                <i class="fas fa-check-circle"></i>
                <h3>太棒了！</h3>
                <p>系统中没有发现重复的子域名记录</p>
                <div class="mt-4">
                    <a href="admin/dns_records.php" class="btn btn-light btn-lg">
                        <i class="fas fa-list me-2"></i>查看所有DNS记录
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- 页脚 -->
        <div class="text-center mt-4">
            <p class="text-white-50 mb-0">
                <i class="fas fa-clock me-1"></i>生成时间: <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
