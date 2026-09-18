<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard');
    exit;
}

$error = '';
$success = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $success = 'You have been successfully logged out.';
}
if (isset($_GET['msg']) && $_GET['msg'] === 'pass_reset') {
    $success = 'Password reset successfully! Please sign in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'suspended') {
                    $error = 'Your account has been suspended. Please contact support.';
                } elseif ($user['role'] !== 'admin' && intval($user['email_verified'] ?? 0) === 0) {
                    require_once __DIR__ . '/includes/mailer.php';
                    $code = sprintf("%06d", mt_rand(100000, 999999));
                    $expires = date('Y-m-d H:i:s', time() + 900); // 15 minutes

                    $upd = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
                    $upd->execute([$code, $expires, $user['id']]);

                    sendSignupOtpEmail($user['email'], $user['name'], $code);

                    $_SESSION['pending_verify_email'] = $user['email'];
                    header('Location: verify-email?email=' . urlencode($user['email']) . '&msg=pending');
                    exit;
                } else {
                    regenerateUserSession();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    
                    if ($user['role'] === 'admin') {
                        header('Location: admin');
                    } else {
                        header('Location: dashboard');
                    }
                    exit;
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Nexus Tune Artist Portal | Manage Music & Royalties</title>
    <meta name="description" content="Sign in to your Nexus Tune Artist Portal to distribute songs, view streaming analytics across Spotify and Apple Music, and request instant royalty withdrawals.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://portal.nexustune.com/login">
    
    <!-- Open Graph -->
    <meta property="og:site_name" content="Nexus Tune Portal">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://portal.nexustune.com/login">
    <meta property="og:title" content="Sign In - Nexus Tune Artist Portal">
    <meta property="og:description" content="Manage your music distribution, royalties, and streaming analytics with Nexus Tune.">
    <meta property="og:image" content="https://nexustune.com/logo.png">
    
    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Sign In - Nexus Tune Artist Portal">
    <meta name="twitter:description" content="Manage your music distribution, royalties, and streaming analytics.">
    <meta name="twitter:image" content="https://nexustune.com/logo.png">

    <!-- Favicon & Touch Icons -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
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
        <h1 class="auth-title">Welcome Back</h1>
        <p class="auth-subtitle">Sign in to manage your music catalog & royalties</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><?= $error ?></div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            <div><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="login">
        <?= csrfInput() ?>

        <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" id="loginEmail" class="form-control" placeholder="artist@nexustune.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <label class="form-label" style="margin-bottom: 0;">Password</label>
                <a href="forgot-password" style="font-size: 12px; color: var(--color-primary);">Forgot password?</a>
            </div>
            <input type="password" name="password" id="loginPass" class="form-control" placeholder="••••••••" required>
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
        </button>
    </form>

    <div style="margin-top: 20px; text-align: center; font-size: 13px; color: var(--text-muted);">
        Don't have an artist account? <a href="signup" style="color: var(--color-primary); font-weight: 600;">Sign Up Free</a>
    </div>
</div>

</body>
</html>
