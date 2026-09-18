<?php
require_once __DIR__ . '/includes/auth.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error = '';
$valid = false;
$email = '';

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT email, created_at FROM password_resets WHERE token = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        // Check if token is older than 2 hours (7200 seconds)
        $token_time = strtotime($row['created_at']);
        if ($token_time && (time() - $token_time > 7200)) {
            $error = 'This password reset link has expired. Please request a new one.';
            $del = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
            $del->execute([$token]);
        } else {
            $valid = true;
            $email = $row['email'];
        }
    } else {
        $error = 'This password reset link is invalid or has already been used.';
    }
} else {
    $error = 'No reset token provided.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $new_pass = $_POST['password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        if (empty($new_pass) || strlen($new_pass) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($new_pass !== $confirm_pass) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
            $upd->execute([$hash, $email]);

            // Invalidate token
            $del = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
            $del->execute([$token]);

            header('Location: login?msg=pass_reset');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - Nexus Tune Artist Portal</title>
    <meta name="description" content="Set a new secure password for your Nexus Tune artist portal account.">
    <link rel="canonical" href="https://portal.nexustune.com/reset-password">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Set New Password - Nexus Tune Artist Portal">
    <meta property="og:description" content="Set a new secure password for your Nexus Tune artist portal account.">
    <meta property="og:image" content="https://nexustune.com/logo.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="https://nexustune.com/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/portal.css">
</head>
<body class="auth-page">

<div class="auth-card">
    <div class="auth-header">
        <div class="auth-logo" style="justify-content: center; margin-bottom: 20px;">
            <a href="https://www.nexustune.com/">
                <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 48px; max-width: 240px; object-fit: contain;">
            </a>
        </div>
        <h1 class="auth-title">Set New Password</h1>
        <p class="auth-subtitle"><?= $valid ? 'Account: <strong>' . htmlspecialchars($email) . '</strong>' : 'Password Recovery' ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($valid): ?>
        <form method="POST" action="reset-password">
            <?= csrfInput() ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fa-solid fa-check"></i> Update Password & Sign In
            </button>
        </form>
    <?php else: ?>
        <div style="text-align: center; margin-top: 20px;">
            <a href="forgot-password" class="btn btn-secondary">Request New Reset Link</a>
        </div>
    <?php endif; ?>

    <div style="margin-top: 24px; text-align: center; font-size: 13px;">
        <a href="login" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
    </div>
</div>

</body>
</html>
