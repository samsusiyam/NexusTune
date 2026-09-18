<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$token = trim($_GET['token'] ?? '');
$is_pending = isset($_GET['pending']) || isset($_GET['resend']);
$prefill_email = trim($_GET['email'] ?? '');
$status = 'pending_lock';
$message = '';
$user = null;

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE verification_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $expires = strtotime($user['verification_expires'] ?? '');
        if ($expires && time() > $expires) {
            $status = 'expired';
            $message = 'Your verification link has expired (valid for 24 hours). Please request a new verification email below.';
            $prefill_email = $user['email'];
        } else {
            // Success: verify user
            $upd = $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = '', verification_expires = NULL WHERE id = ?");
            $upd->execute([$user['id']]);

            regenerateUserSession();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            $status = 'success';
            $message = 'Your email address has been successfully verified! Welcome to your Nexus Tune Artist Workspace.';
        }
    } else {
        $status = 'invalid';
        $message = 'This verification link is invalid or has already been used.';
    }
} elseif ($is_pending) {
    $status = 'pending_lock';
    $message = 'Email verification is mandatory to unlock and access your Nexus Tune artist portal.';
} else {
    $status = 'pending_lock';
    $message = 'Please enter your registered email address below to receive an activation link.';
}

// Handle resend request
$resend_success = '';
$resend_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $resend_error = 'Security session expired. Please refresh the page.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $u_stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND email_verified = 0");
        $u_stmt->execute([$email]);
        $u = $u_stmt->fetch();

        if ($u) {
            $new_token = bin2hex(random_bytes(32));
            $exp = date('Y-m-d H:i:s', time() + 86400);
            $u_upd = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
            $u_upd->execute([$new_token, $exp, $u['id']]);

            sendSignupVerificationEmail($u['email'], $u['name'], $new_token);
            $resend_success = 'A fresh verification link has been sent to ' . htmlspecialchars($email) . '. Please check your inbox!';
            $prefill_email = $email;
        } else {
            $check_active = $pdo->prepare("SELECT id FROM users WHERE email = ? AND email_verified = 1");
            $check_active->execute([$email]);
            if ($check_active->fetch()) {
                $resend_success = 'This account is already verified. You can sign in now!';
            } else {
                $resend_error = 'No account found with this email address. Please check your spelling or sign up.';
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
    <title>Email Verification | Nexus Tune Artist Portal</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://portal.nexustune.com/verify-email">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/portal.css">
</head>
<body class="auth-page">

<div class="auth-card" style="max-width: 480px; text-align: center;">
    <div class="auth-header">
        <div class="auth-logo" style="justify-content: center; margin-bottom: 20px;">
            <a href="../">
                <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 48px; max-width: 240px; object-fit: contain;">
            </a>
        </div>
        
        <?php if ($status === 'success'): ?>
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(87, 255, 82, 0.15); border: 2px solid var(--color-primary); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 16px; box-shadow: 0 0 25px rgba(87, 255, 82, 0.25);">
                <i class="fa-solid fa-check"></i>
            </div>
            <h1 class="auth-title" style="color: #fff;">Account Verified!</h1>
            <p class="auth-subtitle"><?= htmlspecialchars($message) ?></p>

            <div style="margin-top: 24px;">
                <a href="dashboard" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 15px;">
                    <i class="fa-solid fa-gauge-high"></i> Go to Artist Dashboard &rarr;
                </a>
            </div>

        <?php elseif ($status === 'pending_lock'): ?>
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(251, 191, 36, 0.12); border: 2px solid #fbbf24; color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px; box-shadow: 0 0 25px rgba(251, 191, 36, 0.2);">
                <i class="fa-solid fa-envelope-circle-check"></i>
            </div>
            <h1 class="auth-title" style="color: #fff;">Email Verification Required</h1>
            <p class="auth-subtitle" style="color: #d1d5db;"><?= htmlspecialchars($message) ?></p>

            <?php if ($resend_success): ?>
                <div class="alert alert-success" style="margin-top: 20px; text-align: left;">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?= htmlspecialchars($resend_success) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($resend_error): ?>
                <div class="alert alert-error" style="margin-top: 20px; text-align: left;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?= htmlspecialchars($resend_error) ?></div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 24px; text-align: left; background: rgba(255,255,255,0.03); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <div style="font-weight: 700; color: #fff; font-size: 13.5px; margin-bottom: 6px;">
                    <i class="fa-solid fa-paper-plane" style="color: var(--color-primary);"></i> Resend Activation Email
                </div>
                <p style="font-size: 12px; color: var(--text-dim); margin-bottom: 12px;">
                    Enter your email to receive a fresh verification link:
                </p>
                <form method="POST" action="verify-email">
                    <?= csrfInput() ?>
                    <input type="hidden" name="resend_email" value="1">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <input type="email" name="email" class="form-control" placeholder="artist@nexustune.com" required value="<?= htmlspecialchars($prefill_email) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; padding: 11px;">
                        <i class="fa-solid fa-paper-plane"></i> Send Verification Link
                    </button>
                </form>
            </div>

            <div style="margin-top: 20px; font-size: 13px;">
                <a href="login" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left"></i> Return to Sign In</a>
            </div>

        <?php elseif ($status === 'expired' || $status === 'invalid'): ?>
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); border: 2px solid #ef4444; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 28px; margin: 0 auto 16px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h1 class="auth-title" style="color: #fff;">Verification <?= $status === 'expired' ? 'Expired' : 'Invalid' ?></h1>
            <p class="auth-subtitle" style="color: #fca5a5;"><?= htmlspecialchars($message) ?></p>

            <?php if ($resend_success): ?>
                <div class="alert alert-success" style="margin-top: 20px; text-align: left;">
                    <i class="fa-solid fa-circle-check"></i>
                    <div><?= htmlspecialchars($resend_success) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($resend_error): ?>
                <div class="alert alert-error" style="margin-top: 20px; text-align: left;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?= htmlspecialchars($resend_error) ?></div>
                </div>
            <?php endif; ?>

            <div style="margin-top: 24px; text-align: left; background: rgba(255,255,255,0.02); padding: 18px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <div style="font-weight: 600; color: #fff; font-size: 13.5px; margin-bottom: 8px;"><i class="fa-solid fa-paper-plane" style="color: var(--color-primary);"></i> Request New Verification Link</div>
                <form method="POST" action="verify-email">
                    <?= csrfInput() ?>
                    <input type="hidden" name="resend_email" value="1">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <input type="email" name="email" class="form-control" placeholder="Enter your registered email" required value="<?= htmlspecialchars($prefill_email) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" style="width: 100%; padding: 11px;">Send Verification Link</button>
                </form>
            </div>

            <div style="margin-top: 20px; font-size: 13px;">
                <a href="login" style="color: var(--text-muted);"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
