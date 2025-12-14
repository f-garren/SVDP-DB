<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/security.php';

$auth->requireLogin();

// Redirect to password reset if required
if ($auth->isPasswordResetRequired() && basename($_SERVER['PHP_SELF']) != 'reset_password.php') {
    header('Location: /reset_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h(getSetting('company_name', COMPANY_NAME)); ?> - <?php echo h($pageTitle ?? 'Dashboard'); ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <h1 class="nav-title"><?php echo h(getSetting('company_name', COMPANY_NAME)); ?></h1>
            <div class="nav-links">
                <a href="/dashboard.php">Dashboard</a>
                <span class="nav-user"><?php echo h($auth->getUsername()); ?></span>
                <a href="/logout.php">Logout</a>
            </div>
        </div>
    </nav>
    <main class="container">
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-error">
                <?php
                $errors = [
                    'permission_denied' => 'You do not have permission to access this page.',
                    'admin_required' => 'Admin access required.',
                    'not_found' => 'Resource not found.',
                ];
                echo h($errors[$_GET['error']] ?? 'An error occurred.');
                ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <?php
                $messages = [
                    'customer_created' => 'Customer created successfully.',
                    'visit_recorded' => 'Visit recorded successfully.',
                    'voucher_created' => 'Voucher created successfully.',
                    'voucher_redeemed' => 'Voucher redeemed successfully.',
                    'customer_updated' => 'Customer updated successfully.',
                    'visit_invalidated' => 'Visit marked as invalid.',
                    'employee_created' => 'Employee created successfully.',
                    'employee_deleted' => 'Employee deleted successfully.',
                    'settings_updated' => 'Settings updated successfully.',
                ];
                echo h($messages[$_GET['success']] ?? 'Operation completed successfully.');
                ?>
            </div>
        <?php endif; ?>

