<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';

if (isAdminLoggedIn()) {
    header('Location: /admin/index.php');
    exit;
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeText($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_full_name'] = $admin['full_name'];
        header('Location: /admin/index.php');
        exit;
    }

    $errorMessage = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Login | Pickle Trip</title>
    <link rel="stylesheet" href="/assets/css/style.css" />
</head>
<body class="admin-shell">
    <div class="admin-login">
        <div class="login-card">
            <div class="brand" style="margin-bottom: 18px;">
                <span class="brand-mark">P</span>
                <span class="brand-copy">
                    <span>Pickle Trip</span>
                    <span>Admin Portal</span>
                </span>
            </div>
            <h1>Welcome back</h1>
            <p class="login-subtitle">Login to access the booking dashboard and court management tools.</p>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-grid" style="grid-template-columns: 1fr;">
                    <div class="form-field">
                        <label for="username">Username</label>
                        <input id="username" type="text" name="username" value="admin" required />
                    </div>
                    <div class="form-field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" value="admin123" required />
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button-primary" style="width: 100%;">Login</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
