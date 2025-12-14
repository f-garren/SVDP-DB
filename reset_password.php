<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/config.php';

$auth->requireLogin();

if (!$auth->isPasswordResetRequired()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
        $db = Database::getInstance();
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        
        $db->query(
            "UPDATE employees SET password_hash = ?, password_reset_required = FALSE WHERE id = ?",
            [$hash, $auth->getUserId()]
        );
        
        $_SESSION['password_reset_required'] = false;
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo h(getSetting('company_name', COMPANY_NAME)); ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h1>Reset Password</h1>
        <?php if ($success): ?>
            <div class="alert alert-success">Password reset successfully. Redirecting...</div>
            <script>
                setTimeout(function() {
                    window.location.href = '/dashboard.php';
                }, 2000);
            </script>
        <?php else: ?>
            <form method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo h($error); ?></div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required minlength="8" autofocus>
                    <small>Must be at least 8 characters long</small>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

