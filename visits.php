<?php
$pageTitle = 'Record Visit';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();
$visitType = $_GET['type'] ?? 'food';
$error = '';
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $visitType = sanitizeString($_POST['visit_type'] ?? 'food', 20);
        if (!in_array($visitType, ['Food', 'Money', 'Voucher'])) {
            $visitType = 'Food';
        }
        
        $customerId = sanitizeInt($_POST['customer_id'] ?? 0, 1);
        $visitDate = sanitizeString($_POST['visit_date'] ?? date('Y-m-d'), 10);
        $visitTime = sanitizeString($_POST['visit_time'] ?? date('H:i:s'), 8);
        
        if (!validateDate($visitDate)) {
            $visitDate = date('Y-m-d');
        }
        
        $visitDateTime = $visitDate . ' ' . $visitTime;
        if (!validateDateTime($visitDateTime, 'Y-m-d H:i:s')) {
            $visitDateTime = $visitDate . ' ' . date('H:i:s');
        }
        
        $notes = sanitizeString($_POST['notes'] ?? '', 5000);
    
    if ($customerId <= 0) {
        $error = 'Please select a customer.';
    } else {
        // Validate visit limits
        if ($visitType === 'Food') {
            $auth->requirePermission('food_visit_entry');
            $limits = checkFoodVisitLimits($customerId, $visitDateTime);
            if (!$limits['valid']) {
                $warnings = $limits['errors'];
            }
        } elseif ($visitType === 'Money') {
            $auth->requirePermission('money_visit_entry');
            $limits = checkMoneyVisitLimits($customerId, $visitDateTime);
            if (!$limits['valid']) {
                $warnings = $limits['errors'];
            }
        } elseif ($visitType === 'Voucher') {
            $auth->requirePermission('voucher_creation');
            $voucherAmount = sanitizeFloat($_POST['voucher_amount'] ?? 0, 0.01, 99999.99);
            $expirationDate = sanitizeString($_POST['expiration_date'] ?? '', 10);
            
            if ($voucherAmount === null || $voucherAmount <= 0) {
                $error = 'Voucher amount must be greater than 0.';
            }
            
            if (!empty($expirationDate) && !validateDate($expirationDate)) {
                $expirationDate = null;
            }
        }
        
        // If no errors and no warnings (or user confirmed), create visit
        if (empty($error) && (empty($warnings) || isset($_POST['confirm_override']))) {
            try {
                $db->getConnection()->beginTransaction();
                
                // Create visit
                $db->query(
                    "INSERT INTO visits (customer_id, visit_type, visit_date, notes, created_by) 
                     VALUES (?, ?, ?, ?, ?)",
                    [$customerId, $visitType, $visitDateTime, $notes, $auth->getUserId()]
                );
                
                $visitId = $db->getConnection()->lastInsertId();
                
                // If voucher visit, create voucher
                if ($visitType === 'Voucher') {
                    $voucherCode = generateVoucherCode();
                    $db->query(
                        "INSERT INTO vouchers (visit_id, customer_id, voucher_code, amount, expiration_date, notes) 
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$visitId, $customerId, $voucherCode, $voucherAmount, $expirationDate ?: null, $notes]
                    );
                }
                
                $db->getConnection()->commit();
                
                // Redirect to receipt or success page
                    header('Location: /visit_receipt.php?id=' . $visitId . '&success=visit_recorded');
                    exit;
                } catch (Exception $e) {
                    $db->getConnection()->rollBack();
                    error_log('Visit recording error: ' . $e->getMessage());
                    $error = 'Error recording visit. Please try again.';
                }
            }
        }
    }
}
?>
<div class="page-header">
    <h2>Record Visit</h2>
    <div class="visit-type-tabs">
        <a href="?type=food" class="<?php echo $visitType === 'food' ? 'active' : ''; ?>">Food</a>
        <a href="?type=money" class="<?php echo $visitType === 'money' ? 'active' : ''; ?>">Money</a>
        <a href="?type=voucher" class="<?php echo $visitType === 'voucher' ? 'active' : ''; ?>">Voucher</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo h($error); ?></div>
<?php endif; ?>

<?php if (!empty($warnings) && !isset($_POST['confirm_override'])): ?>
    <div class="alert alert-warning">
        <h3>Visit Limit Warnings</h3>
        <ul>
            <?php foreach ($warnings as $warning): ?>
                <li><?php echo h($warning); ?></li>
            <?php endforeach; ?>
        </ul>
        <form method="POST" style="margin-top: 20px;">
            <?php foreach ($_POST as $key => $value): ?>
                <input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="confirm_override" value="1">
            <button type="submit" class="btn btn-warning">Continue Anyway</button>
        </form>
    </div>
<?php else: ?>
    <form method="POST" class="form" id="visit-form">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="visit_type" value="<?php echo ucfirst($visitType); ?>">
        
        <div class="form-group">
            <label for="customer_search">Search Customer *</label>
            <input type="text" id="customer_search" name="customer_search" 
                   placeholder="Search by name, address, phone, or customer ID" autocomplete="off">
            <input type="hidden" id="customer_id" name="customer_id" required>
            <div id="customer_results" class="search-results"></div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="visit_date">Visit Date</label>
                <input type="date" id="visit_date" name="visit_date" 
                       value="<?php echo h($_POST['visit_date'] ?? date('Y-m-d')); ?>" required>
            </div>
            <div class="form-group">
                <label for="visit_time">Visit Time</label>
                <input type="time" id="visit_time" name="visit_time" 
                       value="<?php echo h($_POST['visit_time'] ?? date('H:i')); ?>" required>
            </div>
        </div>
        
        <?php if ($visitType === 'voucher'): ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="voucher_amount">Voucher Amount *</label>
                    <input type="number" id="voucher_amount" name="voucher_amount" 
                           step="0.01" min="0.01" required value="<?php echo h($_POST['voucher_amount'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="expiration_date">Expiration Date (Optional)</label>
                    <input type="date" id="expiration_date" name="expiration_date" 
                           value="<?php echo h($_POST['expiration_date'] ?? ''); ?>">
                </div>
            </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label for="notes">Notes (Optional)</label>
            <textarea id="notes" name="notes" rows="3"><?php echo h($_POST['notes'] ?? ''); ?></textarea>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Record Visit</button>
            <a href="/dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<script>
let searchTimeout;
document.getElementById('customer_search').addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    const query = e.target.value.trim();
    
    if (query.length < 2) {
        document.getElementById('customer_results').innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(function() {
        fetch('/api/search_customers.php?q=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                const resultsDiv = document.getElementById('customer_results');
                if (data.length === 0) {
                    resultsDiv.innerHTML = '<div class="search-result">No customers found</div>';
                    return;
                }
                
                resultsDiv.innerHTML = data.map(customer => `
                    <div class="search-result" onclick="selectCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")} - ${customer.customer_id}')">
                        ${customer.name} - ${customer.customer_id}<br>
                        <small>${customer.address}, ${customer.city}, ${customer.state}</small>
                    </div>
                `).join('');
            })
            .catch(error => {
                console.error('Search error:', error);
            });
    }, 300);
});

function selectCustomer(id, display) {
    document.getElementById('customer_id').value = id;
    document.getElementById('customer_search').value = display;
    document.getElementById('customer_results').innerHTML = '';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

