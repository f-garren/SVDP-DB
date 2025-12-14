<?php
$pageTitle = 'Employee Management';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/functions.php';

$auth->requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';

// Handle employee creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_employee'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = sanitizeString($_POST['username'] ?? '', 100);
        $password = $_POST['password'] ?? '';
        $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
        
        // Validate username
        if (empty($username) || strlen($username) < 3 || strlen($username) > 100) {
            $error = 'Username must be between 3 and 100 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $error = 'Username can only contain letters, numbers, and underscores.';
        } elseif (empty($password) || strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } else {
        // Check if username exists
        $stmt = $db->query("SELECT COUNT(*) as count FROM employees WHERE username = ?", [$username]);
        if ($stmt->fetch()['count'] > 0) {
            $error = 'Username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            
            $db->getConnection()->beginTransaction();
            try {
                $db->query(
                    "INSERT INTO employees (username, password_hash, password_reset_required) VALUES (?, ?, TRUE)",
                    [$username, $hash]
                );
                
                $employeeId = $db->getConnection()->lastInsertId();
                
                // Add permissions
                foreach ($permissions as $permission) {
                    $db->query(
                        "INSERT INTO employee_permissions (employee_id, permission) VALUES (?, ?)",
                        [$employeeId, $permission]
                    );
                }
                
                    $db->getConnection()->commit();
                    header('Location: /employee_management.php?success=employee_created');
                    exit;
                } catch (Exception $e) {
                    $db->getConnection()->rollBack();
                    error_log('Employee creation error: ' . $e->getMessage());
                    $error = 'Error creating employee. Please try again.';
                }
            }
        }
    }
}

// Handle employee deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_employee'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $employeeId = sanitizeInt($_POST['employee_id'] ?? 0, 1);
        
        if ($employeeId > 0 && $employeeId != $auth->getUserId()) {
        $db->query("DELETE FROM employees WHERE id = ?", [$employeeId]);
            header('Location: /employee_management.php?success=employee_deleted');
            exit;
        } else {
            $error = 'Cannot delete your own account or invalid employee ID.';
        }
    }
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $employeeId = sanitizeInt($_POST['employee_id'] ?? 0, 1);
        $newPassword = $_POST['new_password'] ?? '';
        
        if (empty($newPassword) || strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } else {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $db->query(
            "UPDATE employees SET password_hash = ?, password_reset_required = TRUE WHERE id = ?",
            [$hash, $employeeId]
        );
            header('Location: /employee_management.php?success=password_reset');
            exit;
        }
    }
}

// Handle permission update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_permissions'])) {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $employeeId = sanitizeInt($_POST['employee_id'] ?? 0, 1);
        $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
        
        // Validate permissions
        $allowedPermissions = ['customer_creation', 'food_visit_entry', 'money_visit_entry', 
                              'voucher_creation', 'settings_access', 'report_access'];
        $permissions = array_intersect($permissions, $allowedPermissions);
        
        if ($employeeId > 0) {
        $db->getConnection()->beginTransaction();
        try {
            // Delete existing permissions
            $db->query("DELETE FROM employee_permissions WHERE employee_id = ?", [$employeeId]);
            
            // Add new permissions
            foreach ($permissions as $permission) {
                $db->query(
                    "INSERT INTO employee_permissions (employee_id, permission) VALUES (?, ?)",
                    [$employeeId, $permission]
                );
            }
            
            $db->getConnection()->commit();
                header('Location: /employee_management.php?success=permissions_updated');
                exit;
            } catch (Exception $e) {
                $db->getConnection()->rollBack();
                error_log('Permission update error: ' . $e->getMessage());
                $error = 'Error updating permissions. Please try again.';
            }
        }
    }
}

// Get all employees with permissions (optimized single query)
$stmt = $db->query(
    "SELECT e.*, GROUP_CONCAT(ep.permission ORDER BY ep.permission SEPARATOR ',') as permissions
     FROM employees e
     LEFT JOIN employee_permissions ep ON e.id = ep.employee_id
     GROUP BY e.id, e.username, e.password_hash, e.password_reset_required, e.created_at, e.updated_at
     ORDER BY e.username"
);
$employees = $stmt->fetchAll();

$allPermissions = [
    'customer_creation',
    'food_visit_entry',
    'money_visit_entry',
    'voucher_creation',
    'settings_access',
    'report_access'
];
?>
<div class="page-header">
    <h2>Employee Management</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo h($error); ?></div>
<?php endif; ?>

<div class="employee-management">
    <div class="section">
        <h3>Create New Employee</h3>
        <form method="POST" class="form">
            <div class="form-row">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small>Must be at least 8 characters</small>
                </div>
            </div>
            
            <div class="form-group">
                <label>Permissions</label>
                <div class="permissions-grid">
                    <?php foreach ($allPermissions as $perm): ?>
                        <label>
                            <input type="checkbox" name="permissions[]" value="<?php echo h($perm); ?>">
                            <?php echo h(str_replace('_', ' ', ucwords($perm, '_'))); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <button type="submit" name="create_employee" class="btn btn-primary">Create Employee</button>
        </form>
    </div>
    
    <div class="section">
        <h3>Existing Employees</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Permissions</th>
                    <th>Password Reset Required</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td>
                            <?php echo h($emp['username']); ?>
                            <?php
                            $adminAccounts = explode(',', ADMIN_ACCOUNTS);
                            if (in_array($emp['username'], $adminAccounts)):
                            ?>
                                <span class="badge badge-info">Admin</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $perms = $emp['permissions'] ? explode(',', $emp['permissions']) : [];
                            if (in_array($emp['username'], explode(',', ADMIN_ACCOUNTS))) {
                                echo '<span class="badge badge-info">All Permissions</span>';
                            } elseif (empty($perms)) {
                                echo '<span class="badge badge-warning">No Permissions</span>';
                            } else {
                                echo implode(', ', array_map(function($p) {
                                    return '<span class="badge">' . h(str_replace('_', ' ', ucwords($p, '_'))) . '</span>';
                                }, $perms));
                            }
                            ?>
                        </td>
                        <td><?php echo $emp['password_reset_required'] ? '<span class="badge badge-warning">Yes</span>' : '<span class="badge badge-success">No</span>'; ?></td>
                        <td><?php echo h(date('M j, Y', strtotime($emp['created_at']))); ?></td>
                        <td>
                            <button onclick="showResetPassword(<?php echo $emp['id']; ?>, '<?php echo h($emp['username']); ?>')" 
                                    class="btn btn-sm">Reset Password</button>
                            <button onclick="showEditPermissions(<?php echo $emp['id']; ?>, '<?php echo h($emp['username']); ?>', [<?php echo implode(',', array_map(function($p) { return "'" . h($p) . "'"; }, $perms)); ?>])" 
                                    class="btn btn-sm">Edit Permissions</button>
                            <?php if ($emp['id'] != $auth->getUserId() && !in_array($emp['username'], explode(',', ADMIN_ACCOUNTS))): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this employee?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                    <button type="submit" name="delete_employee" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Reset Password</h3>
        <form method="POST">
            <input type="hidden" id="reset_employee_id" name="employee_id" value="">
            <div class="form-group">
                <label for="new_password">New Password *</label>
                <input type="password" id="new_password" name="new_password" required minlength="8">
                <small>Must be at least 8 characters. Employee will be required to reset on next login.</small>
            </div>
            <div class="form-actions">
                <button type="submit" name="reset_password" class="btn btn-primary">Reset Password</button>
                <button type="button" onclick="closeResetPassword()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Permissions Modal -->
<div id="editPermissionsModal" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Edit Permissions</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" id="perm_employee_id" name="employee_id" value="">
            <div class="form-group">
                <label>Permissions</label>
                <div class="permissions-grid">
                    <?php foreach ($allPermissions as $perm): ?>
                        <label>
                            <input type="checkbox" name="permissions[]" value="<?php echo h($perm); ?>" 
                                   class="perm-checkbox">
                            <?php echo h(str_replace('_', ' ', ucwords($perm, '_'))); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="update_permissions" class="btn btn-primary">Update Permissions</button>
                <button type="button" onclick="closeEditPermissions()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function showResetPassword(employeeId, username) {
    document.getElementById('reset_employee_id').value = employeeId;
    document.getElementById('resetPasswordModal').style.display = 'block';
}

function closeResetPassword() {
    document.getElementById('resetPasswordModal').style.display = 'none';
    document.getElementById('new_password').value = '';
}

function showEditPermissions(employeeId, username, currentPermissions) {
    document.getElementById('perm_employee_id').value = employeeId;
    const checkboxes = document.querySelectorAll('.perm-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = currentPermissions.includes(cb.value);
    });
    document.getElementById('editPermissionsModal').style.display = 'block';
}

function closeEditPermissions() {
    document.getElementById('editPermissionsModal').style.display = 'none';
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

