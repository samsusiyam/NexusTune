<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$message = '';
$error = '';
$reset_sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $u = $stmt->fetch();

            if ($u) {
                // Delete previous tokens for this email
                $del = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
                $del->execute([$email]);

                $token = bin2hex(random_bytes(32));
                $ins = $pdo->prepare("INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
                $ins->execute([$email, $token]);
                
                // Dispatch password recovery email via SMTP
                sendPasswordResetEmail($email, $u['name'], $token);
            }

            // Generic message for security (don't reveal user existence)
            $reset_sent = true;
            $message = "If an active account exists for <strong>" . htmlspecialchars($email) . "</strong>, we've sent password reset instructions to your inbox.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Nexus Tune Artist Portal</title>
    <meta name="description" content="Recover and reset your Nexus Tune artist portal password.">
    <link rel="canonical" href="https://portal.nexustune.com/forgot-password">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Reset Password - Nexus Tune Artist Portal">
    <meta property="og:description" content="Recover and reset your Nexus Tune artist portal password.">
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
            <a href="../">
                <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 48px; max-width: 240px; object-fit: contain;">
            </a>
        </div>
        <h1 class="auth-title">Reset Password</h1>
        <p class="auth-subtitle">Enter your registered email address to receive reset instructions</p>
    </div>

    <?php if ($reset_sent): ?>
        <div style="background: rgba(87, 255, 82, 0.08); border: 1px solid rgba(87, 255, 82, 0.25); border-radius: var(--radius-lg); padding: 24px; text-align: center; margin-bottom: 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(87, 255, 82, 0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px; color: var(--color-primary); box-shadow: 0 0 20px rgba(87, 255, 82, 0.25);">
                <i class="fa-solid fa-envelope-circle-check"></i>
            </div>
            <h2 style="font-size: 19px; font-weight: 800; color: #ffffff; margin-bottom: 10px;">Check Your Inbox</h2>
            <p style="font-size: 13.5px; color: #a1a1aa; line-height: 1.6; margin-bottom: 20px;">
                <?= $message ?>
            </p>
            <div style="padding: 12px; background: rgba(0, 0, 0, 0.4); border-radius: 8px; font-size: 12px; color: var(--text-dim); margin-bottom: 20px;">
                <i class="fa-solid fa-clock"></i> Password reset links are valid for <strong>1 hour</strong>. Don't forget to check your spam/junk folder.
            </div>
            <a href="login" class="btn btn-primary" style="width: 100%;">
                <i class="fa-solid fa-arrow-left"></i> Return to Sign In
            </a>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="forgot-password">
            <?= csrfInput() ?>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="artist@nexustune.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fa-solid fa-paper-plane"></i> Send Reset Link
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center; font-size: 13px;">
            <a href="login" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
