<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();
?>
<div class="dashboard">
    <h2>Dashboard</h2>
    <div class="dashboard-grid">
        <a href="/customer_signup.php" class="dashboard-card">
            <h3>Customer Sign Up</h3>
            <p>Register new customers</p>
        </a>
        <a href="/visits.php" class="dashboard-card">
            <h3>Record Visit</h3>
            <p>Record food, money, or voucher visits</p>
        </a>
        <a href="/voucher_redemption.php" class="dashboard-card">
            <h3>Voucher Redemption</h3>
            <p>Redeem customer vouchers</p>
        </a>
        <a href="/customer_search.php" class="dashboard-card">
            <h3>Customer Search</h3>
            <p>Search and view customer information</p>
        </a>
        <a href="/reports.php" class="dashboard-card">
            <h3>Reports</h3>
            <p>View statistics and export data</p>
        </a>
        <?php if ($auth->isAdmin()): ?>
        <a href="/settings.php" class="dashboard-card">
            <h3>Settings</h3>
            <p>Configure system settings</p>
        </a>
        <a href="/employee_management.php" class="dashboard-card">
            <h3>Employee Management</h3>
            <p>Manage employee accounts</p>
        </a>
        <?php endif; ?>
    </div>
    
    <div class="dashboard-stats">
        <h3>Quick Stats</h3>
        <div class="stats-grid">
            <?php
            // Optimized: Single query to get all stats
            $stmt = $db->query(
                "SELECT 
                    (SELECT COUNT(*) FROM customers) as total_customers,
                    (SELECT COUNT(*) FROM visits 
                     WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_invalid = FALSE) as visits_7days,
                    (SELECT COUNT(*) FROM visits 
                     WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_invalid = FALSE) as visits_30days"
            );
            $stats = $stmt->fetch();
            $totalCustomers = $stats['total_customers'];
            $visits7Days = $stats['visits_7days'];
            $visits30Days = $stats['visits_30days'];
            ?>
            <div class="stat-card">
                <div class="stat-value"><?php echo $totalCustomers; ?></div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $visits7Days; ?></div>
                <div class="stat-label">Visits (Last 7 Days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $visits30Days; ?></div>
                <div class="stat-label">Visits (Last 30 Days)</div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

