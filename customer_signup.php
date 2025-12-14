<?php
$pageTitle = 'Customer Sign Up';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$auth->requirePermission('customer_creation');

$db = Database::getInstance();
$error = '';
$duplicates = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Validate and sanitize form data
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
        
        $signupDate = sanitizeString($_POST['signup_date'] ?? date('Y-m-d'), 10);
        $signupTime = sanitizeString($_POST['signup_time'] ?? date('H:i:s'), 8);
        
        if (!validateDate($signupDate)) {
            $error = 'Invalid signup date format.';
        }
        
        $signupDateTime = $signupDate . ' ' . $signupTime;
        if (!validateDateTime($signupDateTime, 'Y-m-d H:i:s')) {
            $signupDateTime = $signupDate . ' ' . date('H:i:s');
        }
    
        // Parse phone number
        $phoneData = parsePhoneNumber($phone);
        
        // Get household members
        $householdMembers = [];
        if (isset($_POST['household_member_name']) && is_array($_POST['household_member_name'])) {
            $memberNames = $_POST['household_member_name'];
            $memberBirthdates = $_POST['household_member_birthdate'] ?? [];
            $memberRelationships = $_POST['household_member_relationship'] ?? [];
            
            foreach ($memberNames as $index => $memberName) {
                $memberName = sanitizeString($memberName, 255);
                if (!empty($memberName) && strlen($memberName) >= 2) {
                    $birthdate = isset($memberBirthdates[$index]) ? sanitizeString($memberBirthdates[$index], 10) : null;
                    if ($birthdate && !validateDate($birthdate)) {
                        $birthdate = null;
                    }
                    $relationship = sanitizeString($memberRelationships[$index] ?? '', 100);
                    
                    $householdMembers[] = [
                        'name' => $memberName,
                        'birthdate' => $birthdate,
                        'relationship' => $relationship
                    ];
                }
            }
        }
        
        // Get household income
        $householdIncome = [];
        $allowedIncomeTypes = ['Child Support', 'Pension', 'Wages', 'SS/SSD/SSI', 'Unemployment', 'Food Stamps', 'Other'];
        if (isset($_POST['income_type']) && is_array($_POST['income_type'])) {
            $incomeTypes = $_POST['income_type'];
            $incomeAmounts = $_POST['income_amount'] ?? [];
            $incomeDescriptions = $_POST['income_description'] ?? [];
            
            foreach ($incomeTypes as $index => $incomeType) {
                if (in_array($incomeType, $allowedIncomeTypes)) {
                    $amount = sanitizeFloat($incomeAmounts[$index] ?? 0, 0, 999999.99);
                    $description = sanitizeString($incomeDescriptions[$index] ?? '', 500);
                    
                    if ($amount !== null && $amount >= 0) {
                        $householdIncome[] = [
                            'type' => $incomeType,
                            'amount' => $amount,
                            'description' => $description
                        ];
                    }
                }
            }
        }
        
        // Validate required fields
        if (empty($name) || empty($address) || empty($city) || empty($state) || empty($zip) || empty($phoneData['local_number'])) {
            $error = 'Please fill in all required fields.';
        } else {
        // Check for duplicates
        $duplicates = findDuplicateCustomers($name, $address, $phoneData['local_number'], $householdMembers);
        
            // If no duplicates or user confirms, create customer
            if (empty($duplicates) || (isset($_POST['confirm_no_duplicate']) && $_POST['confirm_no_duplicate'] == '1')) {
            try {
                $db->getConnection()->beginTransaction();
                
                // Generate customer ID
                $customerId = generateCustomerID();
                
                // Insert customer
                $db->query(
                    "INSERT INTO customers (customer_id, name, address, city, state, zip_code, 
                     phone_country_code, phone_local_number, description, previous_application, 
                     subsidized_housing, signup_date) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$customerId, $name, $address, $city, $state, $zip, 
                     $phoneData['country_code'], $phoneData['local_number'], $description, 
                     $previousApplication, $subsidizedHousing, $signupDateTime]
                );
                
                $newCustomerId = $db->getConnection()->lastInsertId();
                
                // Insert household members (including initial customer) - batch insert
                $householdRows = [
                    ['customer_id' => $newCustomerId, 'name' => $name, 'birthdate' => null, 'relationship' => 'Self']
                ];
                foreach ($householdMembers as $member) {
                    $householdRows[] = [
                        'customer_id' => $newCustomerId,
                        'name' => $member['name'],
                        'birthdate' => $member['birthdate'] ?: null,
                        'relationship' => $member['relationship']
                    ];
                }
                $db->batchInsert('household_members', ['customer_id', 'name', 'birthdate', 'relationship'], $householdRows);
                
                // Insert household income - batch insert
                if (!empty($householdIncome)) {
                    $incomeRows = [];
                    foreach ($householdIncome as $income) {
                        $incomeRows[] = [
                            'customer_id' => $newCustomerId,
                            'income_type' => $income['type'],
                            'amount' => $income['amount'],
                            'description' => $income['description']
                        ];
                    }
                    $db->batchInsert('household_income', ['customer_id', 'income_type', 'amount', 'description'], $incomeRows);
                }
                
                $db->getConnection()->commit();
                    header('Location: /customer_view.php?id=' . $newCustomerId . '&success=customer_created');
                    exit;
                } catch (Exception $e) {
                    $db->getConnection()->rollBack();
                    error_log('Customer creation error: ' . $e->getMessage());
                    $error = 'Error creating customer. Please try again.';
                }
            }
        }
    }
}
?>
<div class="page-header">
    <h2>Customer Sign Up</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo h($error); ?></div>
<?php endif; ?>

<?php if (!empty($duplicates) && !isset($_POST['confirm_no_duplicate'])): ?>
    <div class="alert alert-warning">
        <h3>Potential Duplicate Customers Found</h3>
        <p>The following customers may be duplicates:</p>
        <ul>
            <?php foreach ($duplicates as $dup): ?>
                <li>
                    <a href="/customer_view.php?id=<?php echo $dup['id']; ?>">
                        <?php echo h($dup['name']); ?> - <?php echo h($dup['address']); ?> 
                        (<?php echo h($dup['customer_id']); ?>)
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="POST" style="margin-top: 20px;">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <?php foreach ($_POST as $key => $value): ?>
                <?php if (is_array($value)): ?>
                    <?php foreach ($value as $v): ?>
                        <input type="hidden" name="<?php echo h($key); ?>[]" value="<?php echo h($v); ?>">
                    <?php endforeach; ?>
                <?php else: ?>
                    <input type="hidden" name="<?php echo h($key); ?>" value="<?php echo h($value); ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="confirm_no_duplicate" value="1">
            <button type="submit" class="btn btn-warning">Continue Anyway (No Duplicate)</button>
        </form>
    </div>
<?php else: ?>
    <form method="POST" class="form">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <fieldset>
            <legend>Customer Information</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required value="<?php echo h($_POST['name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="text" id="phone" name="phone" required value="<?php echo h($_POST['phone'] ?? ''); ?>" 
                           placeholder="(123) 456-7890">
                </div>
            </div>
            <div class="form-group">
                <label for="address">Address *</label>
                <input type="text" id="address" name="address" required value="<?php echo h($_POST['address'] ?? ''); ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="city">City *</label>
                    <input type="text" id="city" name="city" required value="<?php echo h($_POST['city'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="state">State *</label>
                    <input type="text" id="state" name="state" required value="<?php echo h($_POST['state'] ?? ''); ?>" maxlength="2" placeholder="XX">
                </div>
                <div class="form-group">
                    <label for="zip_code">ZIP Code *</label>
                    <input type="text" id="zip_code" name="zip_code" required value="<?php echo h($_POST['zip_code'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="description">Description of Need and Situation</label>
                <textarea id="description" name="description" rows="4"><?php echo h($_POST['description'] ?? ''); ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="previous_application" value="1" 
                               <?php echo (isset($_POST['previous_application']) && $_POST['previous_application']) ? 'checked' : ''; ?>>
                        Previous Application
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="subsidized_housing" value="1" 
                               <?php echo (isset($_POST['subsidized_housing']) && $_POST['subsidized_housing']) ? 'checked' : ''; ?>>
                        Living in Subsidized Housing
                    </label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="signup_date">Sign Up Date</label>
                    <input type="date" id="signup_date" name="signup_date" 
                           value="<?php echo h($_POST['signup_date'] ?? date('Y-m-d')); ?>" required>
                </div>
                <div class="form-group">
                    <label for="signup_time">Sign Up Time</label>
                    <input type="time" id="signup_time" name="signup_time" 
                           value="<?php echo h($_POST['signup_time'] ?? date('H:i')); ?>" required>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Household Members</legend>
            <p><em>The initial customer is automatically included as the first household member.</em></p>
            <div id="household-members">
                <div class="household-member">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="household_member_name[]" placeholder="Additional household member">
                        </div>
                        <div class="form-group">
                            <label>Birthdate</label>
                            <input type="date" name="household_member_birthdate[]">
                        </div>
                        <div class="form-group">
                            <label>Relationship</label>
                            <input type="text" name="household_member_relationship[]" placeholder="e.g., Spouse, Child">
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-secondary" onclick="addHouseholdMember()">Add Household Member</button>
        </fieldset>

        <fieldset>
            <legend>Household Income</legend>
            <div id="household-income">
                <div class="income-entry">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Income Type</label>
                            <select name="income_type[]">
                                <option value="">Select...</option>
                                <option value="Child Support">Child Support</option>
                                <option value="Pension">Pension</option>
                                <option value="Wages">Wages</option>
                                <option value="SS/SSD/SSI">SS/SSD/SSI</option>
                                <option value="Unemployment">Unemployment</option>
                                <option value="Food Stamps">Food Stamps</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount</label>
                            <input type="number" name="income_amount[]" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" name="income_description[]" placeholder="Optional description">
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-secondary" onclick="addIncomeEntry()">Add Income Entry</button>
        </fieldset>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create Customer</button>
            <a href="/dashboard.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

    <script>
    function addHouseholdMember() {
        const container = document.getElementById('household-members');
        const newMember = document.createElement('div');
        newMember.className = 'household-member';
        newMember.innerHTML = `
            <div class="form-row">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="household_member_name[]" placeholder="Additional household member">
                </div>
                <div class="form-group">
                    <label>Birthdate</label>
                    <input type="date" name="household_member_birthdate[]">
                </div>
                <div class="form-group">
                    <label>Relationship</label>
                    <input type="text" name="household_member_relationship[]" placeholder="e.g., Spouse, Child">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-danger" onclick="this.closest('.household-member').remove()">Remove</button>
                </div>
            </div>
        `;
        container.appendChild(newMember);
    }

    function addIncomeEntry() {
        const container = document.getElementById('household-income');
        const newEntry = document.createElement('div');
        newEntry.className = 'income-entry';
        newEntry.innerHTML = `
            <div class="form-row">
                <div class="form-group">
                    <label>Income Type</label>
                    <select name="income_type[]">
                        <option value="">Select...</option>
                        <option value="Child Support">Child Support</option>
                        <option value="Pension">Pension</option>
                        <option value="Wages">Wages</option>
                        <option value="SS/SSD/SSI">SS/SSD/SSI</option>
                        <option value="Unemployment">Unemployment</option>
                        <option value="Food Stamps">Food Stamps</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="income_amount[]" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="income_description[]" placeholder="Optional description">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-danger" onclick="this.closest('.income-entry').remove()">Remove</button>
                </div>
            </div>
        `;
        container.appendChild(newEntry);
    }
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

