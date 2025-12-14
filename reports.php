<?php
$pageTitle = 'Reports';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$auth->requirePermission('report_access');

$db = Database::getInstance();

// Get total customers
$stmt = $db->query("SELECT COUNT(*) as count FROM customers");
$totalCustomers = $stmt->fetch()['count'];

// Get visits for last 7 days
$stmt = $db->query(
    "SELECT COUNT(*) as count FROM visits 
     WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND is_invalid = FALSE"
);
$visits7Days = $stmt->fetch()['count'];

// Get visits for last 30 days
$stmt = $db->query(
    "SELECT COUNT(*) as count FROM visits 
     WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_invalid = FALSE"
);
$visits30Days = $stmt->fetch()['count'];

// Get monthly trends for past 12 months
$stmt = $db->query(
    "SELECT DATE_FORMAT(visit_date, '%Y-%m') as month, 
            COUNT(*) as count,
            SUM(CASE WHEN visit_type = 'Food' THEN 1 ELSE 0 END) as food_count,
            SUM(CASE WHEN visit_type = 'Money' THEN 1 ELSE 0 END) as money_count,
            SUM(CASE WHEN visit_type = 'Voucher' THEN 1 ELSE 0 END) as voucher_count
     FROM visits
     WHERE visit_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH) AND is_invalid = FALSE
     GROUP BY DATE_FORMAT(visit_date, '%Y-%m')
     ORDER BY month DESC"
);
$monthlyTrends = $stmt->fetchAll();

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'money_visits') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="money_visits_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    fputcsv($output, ['Visit Date', 'Customer ID', 'Customer Name', 'Address', 'City', 'State', 'Notes', 'Created By']);
    
    // Data rows
    $stmt = $db->query(
        "SELECT v.visit_date, c.customer_id, c.name, c.address, c.city, c.state, v.notes, e.username
         FROM visits v
         INNER JOIN customers c ON v.customer_id = c.id
         LEFT JOIN employees e ON v.created_by = e.id
         WHERE v.visit_type = 'Money' AND v.is_invalid = FALSE
         ORDER BY v.visit_date DESC"
    );
    
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['visit_date'],
            $row['customer_id'],
            $row['name'],
            $row['address'],
            $row['city'],
            $row['state'],
            $row['notes'],
            $row['username'] ?? 'N/A'
        ]);
    }
    
    fclose($output);
    exit;
}
?>
<div class="page-header">
    <h2>Reports</h2>
</div>

<div class="reports">
    <div class="report-section">
        <h3>Summary Statistics</h3>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalCustomers); ?></div>
                <div class="stat-label">Total Customers</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($visits7Days); ?></div>
                <div class="stat-label">Visits (Last 7 Days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($visits30Days); ?></div>
                <div class="stat-label">Visits (Last 30 Days)</div>
            </div>
        </div>
    </div>
    
    <div class="report-section">
        <h3>Monthly Trends (Last 12 Months)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Total Visits</th>
                    <th>Food Visits</th>
                    <th>Money Visits</th>
                    <th>Voucher Visits</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyTrends as $trend): ?>
                    <tr>
                        <td><?php echo h(date('F Y', strtotime($trend['month'] . '-01'))); ?></td>
                        <td><?php echo $trend['count']; ?></td>
                        <td><?php echo $trend['food_count']; ?></td>
                        <td><?php echo $trend['money_count']; ?></td>
                        <td><?php echo $trend['voucher_count']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="report-section">
        <h3>Data Export</h3>
        <div class="export-options">
            <a href="?export=money_visits" class="btn btn-primary">Export Money Visits (CSV)</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

