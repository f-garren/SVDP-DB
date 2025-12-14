<?php
$pageTitle = 'Customer View';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();
$customerId = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($customerId <= 0) {
    header('Location: /customer_search.php?error=not_found');
    exit;
}

// Handle visit invalidation
if ($action === 'invalidate_visit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $visitId = sanitizeInt($_POST['visit_id'] ?? 0, 1);
        $reason = sanitizeString($_POST['reason'] ?? '', 500);
        
        if ($visitId > 0 && !empty($reason)) {
        $db->query(
            "UPDATE visits SET is_invalid = TRUE, invalid_reason = ? WHERE id = ?",
            [$reason, $visitId]
        );
            header('Location: /customer_view.php?id=' . $customerId . '&success=visit_invalidated');
            exit;
        } else {
            $error = 'Invalid visit ID or reason required.';
        }
    }
}

// Handle customer update
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->requirePermission('customer_creation');
    
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $nameResult = validateCustomerName($_POST['name'] ?? '');
        if (!$nameResult['valid']) {
            $error = $nameResult['error'];
        } else {
            $name = $nameResult['value'];
        }
        
        $addressResult = validateAddress($_POST['address'] ?? '');
        if (!$addressResult['valid']) {
            $error = $addressResult['error'];
        } else {
            $address = $addressResult['value'];
        }
        
        $cityResult = validateCity($_POST['city'] ?? '');
        if (!$cityResult['valid']) {
            $error = $cityResult['error'];
        } else {
            $city = $cityResult['value'];
        }
        
        $state = strtoupper(sanitizeString($_POST['state'] ?? '', 2));
        if (!validateStateCode($state)) {
            $error = 'Invalid state code.';
        }
        
        $zip = sanitizeString($_POST['zip_code'] ?? '', 10);
        if (!validateZipCode($zip)) {
            $error = 'Invalid ZIP code format.';
        }
        
        $phone = sanitizeString($_POST['phone'] ?? '');
        if (!validatePhoneNumber($phone)) {
            $error = 'Invalid phone number format.';
        }
        
        $description = sanitizeString($_POST['description'] ?? '', 5000);
        $previousApplication = isset($_POST['previous_application']) && $_POST['previous_application'] == '1' ? 1 : 0;
        $subsidizedHousing = isset($_POST['subsidized_housing']) && $_POST['subsidized_housing'] == '1' ? 1 : 0;
        
        $phoneData = parsePhoneNumber($phone);
    
    // Get current values for audit trail
    $stmt = $db->query("SELECT * FROM customers WHERE id = ?", [$customerId]);
    $oldCustomer = $stmt->fetch();
    
    try {
        $db->getConnection()->beginTransaction();
        
        // Update customer
        $db->query(
            "UPDATE customers SET name = ?, address = ?, city = ?, state = ?, zip_code = ?,
             phone_country_code = ?, phone_local_number = ?, description = ?,
             previous_application = ?, subsidized_housing = ?
             WHERE id = ?",
            [$name, $address, $city, $state, $zip, $phoneData['country_code'], 
             $phoneData['local_number'], $description, $previousApplication, 
             $subsidizedHousing, $customerId]
        );
        
        // Create audit trail entries (batch insert for efficiency)
        $fieldMap = [
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip_code' => $zip,
            'phone_country_code' => $phoneData['country_code'],
            'phone_local_number' => $phoneData['local_number'],
            'description' => $description,
            'previous_application' => $previousApplication,
            'subsidized_housing' => $subsidizedHousing
        ];
        
        $auditRows = [];
        $userId = $auth->getUserId();
        foreach ($fieldMap as $field => $newValue) {
            $oldValue = $oldCustomer[$field] ?? null;
            if ($oldValue != $newValue) {
                $auditRows[] = [
                    'customer_id' => $customerId,
                    'field_name' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'changed_by' => $userId
                ];
            }
        }
        
        if (!empty($auditRows)) {
            $db->batchInsert('customer_audit', ['customer_id', 'field_name', 'old_value', 'new_value', 'changed_by'], $auditRows);
        }
        
        $db->getConnection()->commit();
            header('Location: /customer_view.php?id=' . $customerId . '&success=customer_updated');
            exit;
        } catch (Exception $e) {
            $db->getConnection()->rollBack();
            error_log('Customer update error: ' . $e->getMessage());
            $error = 'Error updating customer. Please try again.';
        }
    }
}

// Get customer
$stmt = $db->query("SELECT * FROM customers WHERE id = ?", [$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    header('Location: /customer_search.php?error=not_found');
    exit;
}

// Get household members
$stmt = $db->query("SELECT * FROM household_members WHERE customer_id = ? ORDER BY id", [$customerId]);
$householdMembers = $stmt->fetchAll();

// Get household income and calculate total in single query
$stmt = $db->query(
    "SELECT *, (SELECT COALESCE(SUM(amount), 0) FROM household_income WHERE customer_id = ?) as total_income
     FROM household_income WHERE customer_id = ?",
    [$customerId, $customerId]
);
$householdIncome = $stmt->fetchAll();
$totalIncome = !empty($householdIncome) ? (float)$householdIncome[0]['total_income'] : 0.00;

// Get visits with employee info
$stmt = $db->query(
    "SELECT v.*, e.username as created_by_username
     FROM visits v
     LEFT JOIN employees e ON v.created_by = e.id
     WHERE v.customer_id = ?
     ORDER BY v.visit_date DESC",
    [$customerId]
);
$visits = $stmt->fetchAll();

// Get audit trail with employee info
$stmt = $db->query(
    "SELECT ca.*, e.username as changed_by_username
     FROM customer_audit ca
     LEFT JOIN employees e ON ca.changed_by = e.id
     WHERE ca.customer_id = ?
     ORDER BY ca.changed_at DESC",
    [$customerId]
);
$auditTrail = $stmt->fetchAll();
?>
<div class="page-header">
    <h2>Customer Details</h2>
    <div class="page-actions">
        <a href="/customer_search.php" class="btn btn-secondary">Back to Search</a>
        <?php if ($auth->hasPermission('customer_creation')): ?>
            <a href="?id=<?php echo $customerId; ?>&action=edit" class="btn btn-primary">Edit Customer</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'edit'): ?>
            <form method="POST" class="form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <fieldset>
                    <legend>Edit Customer Information</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required value="<?php echo h($customer['name']); ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="text" id="phone" name="phone" required 
                           value="<?php echo h(formatPhoneNumber($customer['phone_country_code'], $customer['phone_local_number'])); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="address">Address *</label>
                <input type="text" id="address" name="address" required value="<?php echo h($customer['address']); ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="city">City *</label>
                    <input type="text" id="city" name="city" required value="<?php echo h($customer['city']); ?>">
                </div>
                <div class="form-group">
                    <label for="state">State *</label>
                    <input type="text" id="state" name="state" required value="<?php echo h($customer['state']); ?>" maxlength="2">
                </div>
                <div class="form-group">
                    <label for="zip_code">ZIP Code *</label>
                    <input type="text" id="zip_code" name="zip_code" required value="<?php echo h($customer['zip_code']); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4"><?php echo h($customer['description']); ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="previous_application" value="1" 
                               <?php echo $customer['previous_application'] ? 'checked' : ''; ?>>
                        Previous Application
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="subsidized_housing" value="1" 
                               <?php echo $customer['subsidized_housing'] ? 'checked' : ''; ?>>
                        Living in Subsidized Housing
                    </label>
                </div>
            </div>
        </fieldset>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="/customer_view.php?id=<?php echo $customerId; ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
<?php else: ?>
    <div class="customer-details">
        <div class="detail-section">
            <h3>Customer Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <strong>Customer ID:</strong> <?php echo h($customer['customer_id']); ?>
                </div>
                <div class="detail-item">
                    <strong>Name:</strong> <?php echo h($customer['name']); ?>
                </div>
                <div class="detail-item">
                    <strong>Address:</strong> <?php echo h($customer['address']); ?>
                </div>
                <div class="detail-item">
                    <strong>City:</strong> <?php echo h($customer['city']); ?>
                </div>
                <div class="detail-item">
                    <strong>State:</strong> <?php echo h($customer['state']); ?>
                </div>
                <div class="detail-item">
                    <strong>ZIP Code:</strong> <?php echo h($customer['zip_code']); ?>
                </div>
                <div class="detail-item">
                    <strong>Phone:</strong> <?php echo h(formatPhoneNumber($customer['phone_country_code'], $customer['phone_local_number'])); ?>
                </div>
                <div class="detail-item">
                    <strong>Sign Up Date:</strong> <?php echo h(date('F j, Y g:i A', strtotime($customer['signup_date']))); ?>
                </div>
                <?php if ($customer['description']): ?>
                    <div class="detail-item full-width">
                        <strong>Description:</strong> <?php echo nl2br(h($customer['description'])); ?>
                    </div>
                <?php endif; ?>
                <div class="detail-item">
                    <strong>Previous Application:</strong> <?php echo $customer['previous_application'] ? 'Yes' : 'No'; ?>
                </div>
                <div class="detail-item">
                    <strong>Subsidized Housing:</strong> <?php echo $customer['subsidized_housing'] ? 'Yes' : 'No'; ?>
                </div>
            </div>
        </div>
        
        <div class="detail-section">
            <h3>Household Members</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Birthdate</th>
                        <th>Relationship</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($householdMembers as $member): ?>
                        <tr>
                            <td><?php echo h($member['name']); ?></td>
                            <td><?php echo $member['birthdate'] ? h(date('M j, Y', strtotime($member['birthdate']))) : 'N/A'; ?></td>
                            <td><?php echo h($member['relationship']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="detail-section">
            <h3>Household Income</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($householdIncome as $income): ?>
                        <tr>
                            <td><?php echo h($income['income_type']); ?></td>
                            <td>$<?php echo number_format($income['amount'], 2); ?></td>
                            <td><?php echo h($income['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td><strong>Total</strong></td>
                        <td><strong>$<?php echo number_format($totalIncome, 2); ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="detail-section">
            <h3>Visit History</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Notes</th>
                        <th>Created By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits as $visit): ?>
                        <tr class="<?php echo $visit['is_invalid'] ? 'invalid-visit' : ''; ?>">
                            <td><?php echo h(date('M j, Y g:i A', strtotime($visit['visit_date']))); ?></td>
                            <td><?php echo h($visit['visit_type']); ?></td>
                            <td><?php echo h($visit['notes']); ?></td>
                            <td><?php echo h($visit['created_by_username'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($visit['is_invalid']): ?>
                                    <span class="badge badge-error">Invalid</span>
                                    <br><small><?php echo h($visit['invalid_reason']); ?></small>
                                <?php else: ?>
                                    <span class="badge badge-success">Valid</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/visit_receipt.php?id=<?php echo $visit['id']; ?>" class="btn btn-sm">Receipt</a>
                                <?php if (!$visit['is_invalid']): ?>
                                    <button onclick="showInvalidateForm(<?php echo $visit['id']; ?>)" class="btn btn-sm btn-danger">Invalidate</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($auditTrail)): ?>
            <div class="detail-section">
                <h3>Change History</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Field</th>
                            <th>Old Value</th>
                            <th>New Value</th>
                            <th>Changed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditTrail as $audit): ?>
                            <tr>
                                <td><?php echo h(date('M j, Y g:i A', strtotime($audit['changed_at']))); ?></td>
                                <td><?php echo h($audit['field_name']); ?></td>
                                <td><?php echo h($audit['old_value'] ?? 'N/A'); ?></td>
                                <td><?php echo h($audit['new_value'] ?? 'N/A'); ?></td>
                                <td><?php echo h($audit['changed_by_username'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Invalidate Visit Modal -->
    <div id="invalidateModal" class="modal" style="display: none;">
        <div class="modal-content">
            <h3>Invalidate Visit</h3>
            <form method="POST" action="?id=<?php echo $customerId; ?>&action=invalidate_visit">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" id="visit_id" name="visit_id" value="">
                <div class="form-group">
                    <label for="reason">Reason for Invalidation *</label>
                    <textarea id="reason" name="reason" rows="4" required placeholder="e.g., Mistakenly submitted"></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">Mark as Invalid</button>
                    <button type="button" onclick="closeInvalidateForm()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    function showInvalidateForm(visitId) {
        document.getElementById('visit_id').value = visitId;
        document.getElementById('invalidateModal').style.display = 'block';
    }
    
    function closeInvalidateForm() {
        document.getElementById('invalidateModal').style.display = 'none';
        document.getElementById('reason').value = '';
    }
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

