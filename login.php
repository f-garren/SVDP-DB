<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config.php';

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    if ($auth->isPasswordResetRequired()) {
        header('Location: /reset_password.php');
    } else {
        header('Location: /dashboard.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting
    $rateLimit = checkRateLimit('login', 5, 900); // 5 attempts per 15 minutes
    if (!$rateLimit['allowed']) {
        $error = 'Too many login attempts. Please try again later.';
    } else {
        $username = sanitizeString($_POST['username'] ?? '', 100);
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif ($auth->login($username, $password)) {
        if ($auth->isPasswordResetRequired()) {
            header('Location: /reset_password.php');
        } else {
            header('Location: /dashboard.php');
        }
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo h(getSetting('company_name', COMPANY_NAME)); ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h1><?php echo h(getSetting('company_name', COMPANY_NAME)); ?></h1>
        <form method="POST" class="login-form">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo h($error); ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
    </div>
</body>
</html>

