<?php
$pageTitle = 'Visit Receipt';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();
$visitId = intval($_GET['id'] ?? 0);

if ($visitId <= 0) {
    header('Location: /dashboard.php?error=not_found');
    exit;
}

$stmt = $db->query(
    "SELECT v.*, c.id as customer_db_id, c.customer_id, c.name as customer_name, c.address, c.city, c.state, c.zip_code,
            c.phone_country_code, c.phone_local_number
     FROM visits v
     INNER JOIN customers c ON v.customer_id = c.id
     WHERE v.id = ?",
    [$visitId]
);

$visit = $stmt->fetch();

if (!$visit) {
    header('Location: /dashboard.php?error=not_found');
    exit;
}

// Get voucher if this is a voucher visit
$voucher = null;
if ($visit['visit_type'] === 'Voucher') {
    $stmt = $db->query("SELECT * FROM vouchers WHERE visit_id = ?", [$visitId]);
    $voucher = $stmt->fetch();
}
?>
<div class="receipt-container">
    <div class="receipt" id="receipt">
        <div class="receipt-header">
            <h2><?php echo h(getSetting('company_name', COMPANY_NAME)); ?></h2>
            <p>Visit Receipt</p>
        </div>
        
        <div class="receipt-info">
            <div class="receipt-row">
                <strong>Customer:</strong> <?php echo h($visit['customer_name']); ?>
            </div>
            <div class="receipt-row">
                <strong>Customer ID:</strong> <?php echo h($visit['customer_id']); ?>
            </div>
            <div class="receipt-row">
                <strong>Address:</strong> <?php echo h($visit['address'] . ', ' . $visit['city'] . ', ' . $visit['state'] . ' ' . $visit['zip_code']); ?>
            </div>
            <div class="receipt-row">
                <strong>Phone:</strong> <?php echo h(formatPhoneNumber($visit['phone_country_code'], $visit['phone_local_number'])); ?>
            </div>
            <div class="receipt-row">
                <strong>Visit Type:</strong> <?php echo h($visit['visit_type']); ?>
            </div>
            <div class="receipt-row">
                <strong>Visit Date:</strong> <?php echo h(date('F j, Y g:i A', strtotime($visit['visit_date']))); ?>
            </div>
            
            <?php if ($voucher): ?>
                <div class="receipt-section">
                    <h3>Voucher Information</h3>
                    <div class="receipt-row">
                        <strong>Voucher Code:</strong> <?php echo h($voucher['voucher_code']); ?>
                    </div>
                    <div class="receipt-row">
                        <strong>Amount:</strong> $<?php echo number_format($voucher['amount'], 2); ?>
                    </div>
                    <?php if ($voucher['expiration_date']): ?>
                        <div class="receipt-row">
                            <strong>Expiration Date:</strong> <?php echo h(date('F j, Y', strtotime($voucher['expiration_date']))); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($voucher['notes']): ?>
                        <div class="receipt-row">
                            <strong>Notes:</strong> <?php echo h($voucher['notes']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($visit['notes']): ?>
                <div class="receipt-row">
                    <strong>Notes:</strong> <?php echo h($visit['notes']); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="receipt-footer">
            <p>Generated on <?php echo date('F j, Y g:i A'); ?></p>
        </div>
    </div>
    
    <div class="receipt-actions">
        <button onclick="window.print()" class="btn btn-primary">Print Receipt</button>
        <a href="/dashboard.php" class="btn btn-secondary">Return to Dashboard</a>
        <a href="/customer_view.php?id=<?php echo $visit['customer_db_id']; ?>" class="btn btn-secondary">View Customer</a>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    .receipt, .receipt * {
        visibility: visible;
    }
    .receipt {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
    }
    .receipt-actions {
        display: none;
    }
}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

