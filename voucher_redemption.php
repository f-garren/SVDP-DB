<?php
$pageTitle = 'Voucher Redemption';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();
$error = '';
$voucher = null;
$searchCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_voucher'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $voucherCode = sanitizeString($_POST['voucher_code'] ?? '', 50);
        $searchCode = $voucherCode;
        
        if (empty($voucherCode)) {
            $error = 'Please enter a voucher code.';
        } else {
        $stmt = $db->query(
            "SELECT v.*, c.name as customer_name, c.customer_id
             FROM vouchers v
             INNER JOIN customers c ON v.customer_id = c.id
             WHERE v.voucher_code = ?",
            [$voucherCode]
        );
        
        $voucher = $stmt->fetch();
        
        if (!$voucher) {
            $error = 'Voucher code not found.';
        } elseif ($voucher['is_redeemed']) {
            $error = 'This voucher has already been redeemed on ' . date('M j, Y g:i A', strtotime($voucher['redeemed_at']));
        } elseif ($voucher['expiration_date'] && strtotime($voucher['expiration_date']) < time()) {
            $error = 'This voucher has expired on ' . date('M j, Y', strtotime($voucher['expiration_date']));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_voucher'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $voucherId = sanitizeInt($_POST['voucher_id'] ?? 0, 1);
        
        if ($voucherId > 0) {
        $db->query(
            "UPDATE vouchers SET is_redeemed = TRUE, redeemed_at = NOW() WHERE id = ?",
            [$voucherId]
        );
        header('Location: /voucher_redemption.php?success=voucher_redeemed');
        exit;
    }
}

// Get active vouchers
$stmt = $db->query(
    "SELECT v.*, c.name as customer_name, c.customer_id
     FROM vouchers v
     INNER JOIN customers c ON v.customer_id = c.id
     WHERE v.is_redeemed = FALSE
     AND (v.expiration_date IS NULL OR v.expiration_date >= CURDATE())
     ORDER BY v.created_at DESC
     LIMIT 50"
);
$activeVouchers = $stmt->fetchAll();
?>
<div class="page-header">
    <h2>Voucher Redemption</h2>
</div>

<div class="voucher-redemption">
    <div class="voucher-check-section">
        <h3>Check Voucher</h3>
        <form method="POST" class="form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="form-group">
                <label for="voucher_code">Voucher Code</label>
                <input type="text" id="voucher_code" name="voucher_code" 
                       value="<?php echo h($searchCode); ?>" required autofocus 
                       placeholder="Enter voucher code">
            </div>
            <button type="submit" name="check_voucher" class="btn btn-primary">Check Voucher</button>
        </form>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo h($error); ?></div>
        <?php endif; ?>
        
        <?php if ($voucher && empty($error)): ?>
            <div class="voucher-details">
                <h4>Voucher Details</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Voucher Code:</strong> <?php echo h($voucher['voucher_code']); ?>
                    </div>
                    <div class="detail-item">
                        <strong>Customer:</strong> <?php echo h($voucher['customer_name']); ?> (<?php echo h($voucher['customer_id']); ?>)
                    </div>
                    <div class="detail-item">
                        <strong>Amount:</strong> $<?php echo number_format($voucher['amount'], 2); ?>
                    </div>
                    <?php if ($voucher['expiration_date']): ?>
                        <div class="detail-item">
                            <strong>Expiration Date:</strong> <?php echo h(date('M j, Y', strtotime($voucher['expiration_date']))); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($voucher['notes']): ?>
                        <div class="detail-item full-width">
                            <strong>Notes:</strong> <?php echo h($voucher['notes']); ?>
                        </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <strong>Created:</strong> <?php echo h(date('M j, Y g:i A', strtotime($voucher['created_at']))); ?>
                    </div>
                </div>
                
                <form method="POST" style="margin-top: 20px;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="voucher_id" value="<?php echo $voucher['id']; ?>">
                    <button type="submit" name="redeem_voucher" class="btn btn-success">Redeem Voucher</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="active-vouchers-section">
        <h3>Active Vouchers</h3>
        <?php if (empty($activeVouchers)): ?>
            <p>No active vouchers found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Voucher Code</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Expiration</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeVouchers as $v): ?>
                        <tr>
                            <td><?php echo h($v['voucher_code']); ?></td>
                            <td><?php echo h($v['customer_name']); ?><br><small><?php echo h($v['customer_id']); ?></small></td>
                            <td>$<?php echo number_format($v['amount'], 2); ?></td>
                            <td><?php echo $v['expiration_date'] ? h(date('M j, Y', strtotime($v['expiration_date']))) : 'No expiration'; ?></td>
                            <td><?php echo h(date('M j, Y', strtotime($v['created_at']))); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="voucher_code" value="<?php echo h($v['voucher_code']); ?>">
                                    <button type="submit" name="check_voucher" class="btn btn-sm">Check</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

