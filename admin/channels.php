<?php
session_start();
require_once '../config/database.php';
require_once '../config/dns_manager.php';
require_once '../includes/functions.php';

checkAdminLogin();

$db = Database::getInstance()->getConnection();
$messages = getMessages();

// 获取统计信息
$stats = [
    'cloudflare_accounts' => $db->querySingle("SELECT COUNT(*) FROM cloudflare_accounts WHERE status = 1"),
    'rainbow_accounts' => $db->querySingle("SELECT COUNT(*) FROM domains WHERE provider_type = 'rainbow' AND status = 1"),
    'total_domains' => $db->querySingle("SELECT COUNT(*) FROM domains WHERE status = 1"),
    'cloudflare_domains' => $db->querySingle("SELECT COUNT(*) FROM domains WHERE (provider_type = 'cloudflare' OR provider_type IS NULL) AND status = 1"),
    'rainbow_domains' => $db->querySingle("SELECT COUNT(*) FROM domains WHERE provider_type = 'rainbow' AND status = 1")
];

// 获取最近的渠道活动
$recent_activities = [];
$result = $db->query("
    SELECT 
        'cloudflare' as provider_type,
        domain_name,
        created_at,
        'domain_added' as action_type
    FROM domains 
    WHERE (provider_type = 'cloudflare' OR provider_type IS NULL)
    
    UNION ALL
    
    SELECT 
        'rainbow' as provider_type,
        domain_name,
        created_at,
        'domain_added' as action_type
    FROM domains 
    WHERE provider_type = 'rainbow'
    
    ORDER BY created_at DESC 
    LIMIT 10
");

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recent_activities[] = $row;
}

$page_title = '渠道管理';
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">API渠道管理</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="cloudflare_accounts.php" class="btn btn-info">
                            <i class="fab fa-cloudflare me-1"></i>Cloudflare账户
                        </a>
                        <a href="rainbow_accounts.php" class="btn btn-warning">
                            <i class="fas fa-rainbow me-1"></i>彩虹DNS账户
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if ($messages): ?>
                <?php foreach ($messages as $type => $content): ?>
                    <div class="alert alert-<?php echo $type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($content); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- 渠道统计概览 -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Cloudflare账户
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['cloudflare_accounts']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fab fa-cloudflare fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        彩虹DNS账户
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['rainbow_accounts']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-rainbow fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Cloudflare域名
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['cloudflare_domains']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-globe fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        彩虹DNS域名
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo $stats['rainbow_domains']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-cloud fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 渠道状态 -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">渠道状态概览</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card border-left-primary mb-3">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <i class="fab fa-cloudflare fa-3x text-primary"></i>
                                                </div>
                                                <div>
                                                    <h5 class="card-title">Cloudflare</h5>
                                                    <p class="card-text">
                                                        <strong><?php echo $stats['cloudflare_accounts']; ?></strong> 个账户<br>
                                                        <strong><?php echo $stats['cloudflare_domains']; ?></strong> 个域名
                                                    </p>
                                                    <div class="d-flex gap-2">
                                                        <span class="badge bg-success">支持代理</span>
                                                        <span class="badge bg-info">全球CDN</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card border-left-warning mb-3">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <i class="fas fa-rainbow fa-3x text-warning"></i>
                                                </div>
                                                <div>
                                                    <h5 class="card-title">彩虹聚合DNS</h5>
                                                    <p class="card-text">
                                                        <strong><?php echo $stats['rainbow_accounts']; ?></strong> 个账户<br>
                                                        <strong><?php echo $stats['rainbow_domains']; ?></strong> 个域名
                                                    </p>
                                                    <div class="d-flex gap-2">
                                                        <span class="badge bg-warning">支持线路</span>
                                                        <span class="badge bg-secondary">聚合平台</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="cloudflare_accounts.php" class="btn btn-primary me-2">
                                    <i class="fab fa-cloudflare me-1"></i>管理Cloudflare
                                </a>
                                <a href="rainbow_accounts.php" class="btn btn-warning">
                                    <i class="fas fa-rainbow me-1"></i>管理彩虹DNS
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">最近活动</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activities)): ?>
                                <div class="text-center text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <p>暂无活动记录</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_activities as $activity): ?>
                                    <div class="list-group-item border-0 px-0">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <?php if ($activity['provider_type'] === 'cloudflare'): ?>
                                                    <i class="fab fa-cloudflare text-primary"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-rainbow text-warning"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="small">
                                                    添加域名: <strong><?php echo htmlspecialchars($activity['domain_name']); ?></strong>
                                                </div>
                                                <div class="text-muted small">
                                                    <?php echo formatTime($activity['created_at']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 快速操作 -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">快速操作</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="cloudflare_accounts.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column justify-content-center align-items-center">
                                <i class="fab fa-cloudflare fa-2x mb-2"></i>
                                <span>添加Cloudflare账户</span>
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="rainbow_accounts.php" class="btn btn-outline-warning w-100 h-100 d-flex flex-column justify-content-center align-items-center">
                                <i class="fas fa-rainbow fa-2x mb-2"></i>
                                <span>添加彩虹DNS账户</span>
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="domains.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column justify-content-center align-items-center">
                                <i class="fas fa-globe fa-2x mb-2"></i>
                                <span>管理域名</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
</style>

<?php include 'includes/footer.php'; ?>