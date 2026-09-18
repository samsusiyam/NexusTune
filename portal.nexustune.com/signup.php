<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    header('Location: dashboard');
    exit;
}

$error = '';
$success = '';
$registered_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $account_type = $_POST['account_type'] ?? 'Individual Artist';
        $country = $_POST['country'] ?? 'Bangladesh';
        $terms = isset($_POST['terms']);

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$terms) {
            $error = 'You must agree to the Nexus Tune Terms & Conditions.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'An account with this email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 86400); // 24 hours

                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, account_type, country, email_verified, verification_token, verification_expires) VALUES (?, ?, ?, 'artist', ?, ?, 0, ?, ?)");
                $stmt->execute([$name, $email, $hash, $account_type, $country, $token, $expires]);
                $user_id = $pdo->lastInsertId();

                // Dispatch verification email
                $mail_sent = sendSignupVerificationEmail($email, $name, $token);

                $registered_email = $email;
                $success = "Account created successfully! We've sent an activation link to <strong>" . htmlspecialchars($email) . "</strong>. Please verify your email to access your account.";
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
    <title>Create Free Artist Account - Unlimited Music Distribution | Nexus Tune</title>
    <meta name="description" content="Sign up free for Nexus Tune. Release unlimited music to Spotify, Apple Music, TikTok, YouTube Music, and 250+ platforms worldwide while keeping 100% of your royalties.">
    <meta name="keywords" content="Sign up music distribution, free music distribution, sell music online, artist sign up, independent record label distribution">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://portal.nexustune.com/signup">
    
    <!-- Open Graph -->
    <meta property="og:site_name" content="Nexus Tune Portal">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://portal.nexustune.com/signup">
    <meta property="og:title" content="Create Artist Account - Unlimited Music Distribution | Nexus Tune">
    <meta property="og:description" content="Release unlimited music to 250+ streaming platforms globally. Keep 100% of your earnings.">
    <meta property="og:image" content="https://nexustune.com/logo.png">
    
    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Create Artist Account - Nexus Tune">
    <meta name="twitter:description" content="Release unlimited music worldwide. Keep 100% of your rights and royalties.">
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

<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="justify-content: center; margin-bottom: 20px;">
            <a href="../">
                <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 48px; max-width: 240px; object-fit: contain;">
            </a>
        </div>
        <h1 class="auth-title">Create Account</h1>
        <p class="auth-subtitle">Join 50,000+ artists releasing music globally</p>
    </div>

    <?php if ($success): ?>
        <div style="background: rgba(87, 255, 82, 0.08); border: 1px solid rgba(87, 255, 82, 0.25); border-radius: var(--radius-lg); padding: 24px; text-align: center; margin-bottom: 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(87, 255, 82, 0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 26px; color: var(--color-primary); box-shadow: 0 0 20px rgba(87, 255, 82, 0.25);">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
            <h2 style="font-size: 19px; font-weight: 800; color: #ffffff; margin-bottom: 10px;">Check Your Email</h2>
            <p style="font-size: 13.5px; color: #a1a1aa; line-height: 1.6; margin-bottom: 20px;">
                <?= $success ?>
            </p>
            <div style="padding: 12px; background: rgba(0, 0, 0, 0.4); border-radius: 8px; font-size: 12px; color: var(--text-dim); margin-bottom: 20px;">
                <i class="fa-solid fa-clock"></i> The verification link will expire in <strong>24 hours</strong>. If you don't see it, check your spam or promotions folder.
            </div>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="login" class="btn btn-primary" style="padding: 10px 20px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Go to Sign In
                </a>
                <a href="verify-email?resend=1&email=<?= urlencode($registered_email) ?>" class="btn btn-secondary" style="padding: 10px 18px;">
                    <i class="fa-solid fa-rotate-right"></i> Resend Email
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="signup">
            <?= csrfInput() ?>

            <div class="form-group">
                <label class="form-label">Artist or Record Label Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Nexus Records or John Doe" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="artist@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Account Type</label>
                    <select name="account_type" class="form-control">
                        <option value="Individual Artist">Individual Artist</option>
                        <option value="Band / Music Duo">Band / Music Duo</option>
                        <option value="Record Label">Record Label</option>
                        <option value="Music Producer">Music Producer</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <select name="country" class="form-control">
                        <option value="Bangladesh">Bangladesh</option>
                        <option value="India">India</option>
                        <option value="France">France</option>
                        <option value="United States">United States</option>
                        <option value="United Kingdom">United Kingdom</option>
                        <option value="Canada">Canada</option>
                        <option value="Other">Other Worldwide</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Min. 6 characters" required>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 15px;">
                <input type="checkbox" name="terms" id="termsCheck" required style="width: 18px; height: 18px; accent-color: var(--color-primary); cursor: pointer;">
                <label for="termsCheck" style="font-size: 12.5px; color: var(--text-muted); cursor: pointer;">
                    I agree to the <a href="../legal.nexustune.com/terms-conditions/" target="_blank" style="color: var(--color-primary);">Terms & Conditions</a> and <a href="../legal.nexustune.com/distribution-agreement/" target="_blank" style="color: var(--color-primary);">Distribution Agreement</a>.
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                <i class="fa-solid fa-user-plus"></i> Create Artist Account
            </button>
        </form>

        <div style="margin-top: 20px; text-align: center; font-size: 13px; color: var(--text-muted);">
            Already have an account? <a href="login" style="color: var(--color-primary); font-weight: 600;">Sign In</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
