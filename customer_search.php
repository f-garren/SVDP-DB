<?php
$pageTitle = 'Customer Search';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$db = Database::getInstance();

$search = $_GET['search'] ?? '';
$city = $_GET['city'] ?? '';
$state = $_GET['state'] ?? '';
$visitType = $_GET['visit_type'] ?? '';
$visitDateFrom = $_GET['visit_date_from'] ?? '';
$visitDateTo = $_GET['visit_date_to'] ?? '';

$customers = [];

if (!empty($search) || !empty($city) || !empty($state) || !empty($visitType) || !empty($visitDateFrom) || !empty($visitDateTo)) {
    $conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $conditions[] = "(c.name LIKE ? OR c.customer_id LIKE ? OR c.address LIKE ? 
                         OR c.phone_local_number LIKE ? OR hm.name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }
    
    if (!empty($city)) {
        $conditions[] = "c.city = ?";
        $params[] = $city;
    }
    
    if (!empty($state)) {
        $conditions[] = "c.state = ?";
        $params[] = $state;
    }
    
    if (!empty($visitType)) {
        $conditions[] = "v.visit_type = ?";
        $params[] = $visitType;
    }
    
    if (!empty($visitDateFrom)) {
        $conditions[] = "v.visit_date >= ?";
        $params[] = $visitDateFrom . ' 00:00:00';
    }
    
    if (!empty($visitDateTo)) {
        $conditions[] = "v.visit_date <= ?";
        $params[] = $visitDateTo . ' 23:59:59';
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    $sql = "SELECT DISTINCT c.id, c.customer_id, c.name, c.address, c.city, c.state, 
                   c.signup_date, COUNT(DISTINCT v.id) as visit_count
            FROM customers c
            LEFT JOIN visits v ON c.id = v.customer_id
            LEFT JOIN household_members hm ON c.id = hm.customer_id
            $whereClause
            GROUP BY c.id
            ORDER BY c.name
            LIMIT 100";
    
    $stmt = $db->query($sql, $params);
    $customers = $stmt->fetchAll();
}

// Get unique cities and states for filters
$stmt = $db->query("SELECT DISTINCT city FROM customers ORDER BY city");
$citiesResult = $stmt->fetchAll();
$cities = array_column($citiesResult, 'city');

$stmt = $db->query("SELECT DISTINCT state FROM customers ORDER BY state");
$statesResult = $stmt->fetchAll();
$states = array_column($statesResult, 'state');
?>
<div class="page-header">
    <h2>Customer Search</h2>
</div>

<form method="GET" class="form search-form">
    <div class="form-group">
        <label for="search">Search (Name, ID, Address, Phone, Household Members)</label>
        <input type="text" id="search" name="search" value="<?php echo h($search); ?>" 
               placeholder="Enter search terms...">
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label for="city">City</label>
            <select id="city" name="city">
                <option value="">All Cities</option>
                <?php foreach ($cities as $c): ?>
                    <option value="<?php echo h($c); ?>" <?php echo $city === $c ? 'selected' : ''; ?>>
                        <?php echo h($c); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="state">State</label>
            <select id="state" name="state">
                <option value="">All States</option>
                <?php foreach ($states as $s): ?>
                    <option value="<?php echo h($s); ?>" <?php echo $state === $s ? 'selected' : ''; ?>>
                        <?php echo h($s); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label for="visit_type">Visit Type</label>
            <select id="visit_type" name="visit_type">
                <option value="">All Types</option>
                <option value="Food" <?php echo $visitType === 'Food' ? 'selected' : ''; ?>>Food</option>
                <option value="Money" <?php echo $visitType === 'Money' ? 'selected' : ''; ?>>Money</option>
                <option value="Voucher" <?php echo $visitType === 'Voucher' ? 'selected' : ''; ?>>Voucher</option>
            </select>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label for="visit_date_from">Visit Date From</label>
            <input type="date" id="visit_date_from" name="visit_date_from" value="<?php echo h($visitDateFrom); ?>">
        </div>
        
        <div class="form-group">
            <label for="visit_date_to">Visit Date To</label>
            <input type="date" id="visit_date_to" name="visit_date_to" value="<?php echo h($visitDateTo); ?>">
        </div>
    </div>
    
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="/customer_search.php" class="btn btn-secondary">Clear</a>
    </div>
</form>

<?php if (!empty($customers)): ?>
    <div class="search-results-table">
        <h3>Search Results (<?php echo count($customers); ?>)</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>City</th>
                    <th>State</th>
                    <th>Sign Up Date</th>
                    <th>Visits</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?php echo h($customer['customer_id']); ?></td>
                        <td><?php echo h($customer['name']); ?></td>
                        <td><?php echo h($customer['address']); ?></td>
                        <td><?php echo h($customer['city']); ?></td>
                        <td><?php echo h($customer['state']); ?></td>
                        <td><?php echo h(date('M j, Y', strtotime($customer['signup_date']))); ?></td>
                        <td><?php echo $customer['visit_count']; ?></td>
                        <td>
                            <a href="/customer_view.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php elseif (!empty($search) || !empty($city) || !empty($state) || !empty($visitType) || !empty($visitDateFrom) || !empty($visitDateTo)): ?>
    <div class="alert alert-info">No customers found matching your criteria.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

