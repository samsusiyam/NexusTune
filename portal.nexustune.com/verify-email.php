<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

$error = '';
$success = '';
$info = '';

$email = trim($_GET['email'] ?? ($_SESSION['pending_verify_email'] ?? ''));
$is_sent = isset($_GET['sent']);
$is_pending = isset($_GET['pending']) || isset($_GET['msg']);

if ($is_sent) {
    $info = "A 6-digit activation code has been dispatched to your email address.";
} elseif ($is_pending) {
    $info = "Email verification is required. A fresh 6-digit activation code was sent to your email.";
}

// Handle Direct 6-Digit Code Verification Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify_code'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['email'] ?? $email);
        $code = trim($_POST['otp_code'] ?? '');
        
        // If 6 individual box inputs were sent
        if (empty($code) && isset($_POST['otp_1'])) {
            $code = trim($_POST['otp_1'] . $_POST['otp_2'] . $_POST['otp_3'] . $_POST['otp_4'] . $_POST['otp_5'] . $_POST['otp_6']);
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
            $error = 'Please enter the complete 6-digit verification code.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with this email address. Please check your spelling or sign up.';
            } elseif (intval($user['email_verified']) === 1) {
                $success = 'Your account is already verified! Redirecting to login...';
                header('Refresh: 2; url=login');
            } else {
                $saved_code = trim($user['verification_token'] ?? '');
                $expires_at = strtotime($user['verification_expires'] ?? '');

                if ($expires_at && time() > $expires_at) {
                    $error = 'This verification code has expired (valid for 15 minutes). Please click <strong>Resend Code</strong> to get a new code.';
                } elseif (empty($saved_code) || $saved_code !== $code) {
                    $error = 'Incorrect verification code. Please check your email inbox and enter the 6-digit code correctly.';
                } else {
                    // Success: Activate user account
                    $upd = $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = '', verification_expires = NULL, status = 'active' WHERE id = ?");
                    $upd->execute([$user['id']]);

                    // Auto-login verified user
                    regenerateUserSession();
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    unset($_SESSION['pending_verify_email']);

                    $success = 'Email verified successfully! Welcome to your Nexus Tune Artist Workspace.';
                    header('Refresh: 1; url=dashboard');
                }
            }
        }
    }
}

// Handle Resending 6-Digit OTP Code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_resend_code'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page.';
    } else {
        $email = trim($_POST['email'] ?? $email);

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid registered email address.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'No account found with this email address.';
            } elseif (intval($user['email_verified']) === 1) {
                $success = 'This account is already verified. You can sign in directly!';
                header('Refresh: 2; url=login');
            } else {
                $new_code = sprintf("%06d", mt_rand(100000, 999999));
                $new_expires = date('Y-m-d H:i:s', time() + 900); // 15 mins

                $upd = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires = ? WHERE id = ?");
                $upd->execute([$new_code, $new_expires, $user['id']]);

                sendSignupOtpEmail($user['email'], $user['name'], $new_code);

                $info = "A fresh 6-digit verification code has been dispatched to <strong>" . htmlspecialchars($email) . "</strong>.";
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
    <title>Enter Verification Code | Nexus Tune Artist Portal</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://portal.nexustune.com/verify-email">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/portal.css">

    <!-- Prevent Duplicate Form Resubmission on Page Refresh -->
    <script>
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    </script>

    <style>
    .otp-container {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin: 24px 0 20px;
    }
    .otp-input {
        width: 52px;
        height: 60px;
        background: rgba(255, 255, 255, 0.04);
        border: 2px solid rgba(255, 255, 255, 0.12);
        border-radius: 12px;
        font-family: 'SFMono-Regular', Consolas, Monaco, monospace;
        font-size: 26px;
        font-weight: 800;
        color: #ffffff;
        text-align: center;
        transition: var(--transition);
        outline: none;
    }
    .otp-input:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 20px rgba(87, 255, 82, 0.35);
        background: rgba(87, 255, 82, 0.05);
        transform: translateY(-2px);
    }
    .email-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 13px;
        color: #e5e7eb;
        margin-top: 6px;
    }
    @media (max-width: 480px) {
        .otp-container {
            gap: 6px;
        }
        .otp-input {
            width: 42px;
            height: 52px;
            font-size: 22px;
            border-radius: 8px;
        }
    }
    </style>
</head>
<body class="auth-page">

<div class="auth-card" style="max-width: 480px; text-align: center;">
    <div class="auth-header">
        <div class="auth-logo" style="justify-content: center; margin-bottom: 20px;">
            <a href="../">
                <img src="assets/images/logo.png" alt="Nexus Tune" style="height: 48px; max-width: 240px; object-fit: contain;">
            </a>
        </div>
        
        <?php if ($success): ?>
            <div style="width: 68px; height: 68px; border-radius: 50%; background: rgba(87, 255, 82, 0.15); border: 2px solid var(--color-primary); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 16px; box-shadow: 0 0 30px rgba(87, 255, 82, 0.35);">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h1 class="auth-title" style="color: #fff;">Verified & Activated!</h1>
            <p class="auth-subtitle" style="color: #9ca3af; margin-bottom: 20px;"><?= $success ?></p>
            <a href="dashboard" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 15px;">
                <i class="fa-solid fa-gauge-high"></i> Entering Dashboard...
            </a>
        <?php else: ?>
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(87, 255, 82, 0.12); border: 2px solid rgba(87, 255, 82, 0.3); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px; box-shadow: 0 0 25px rgba(87, 255, 82, 0.2);">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1 class="auth-title" style="color: #fff;">Enter Verification Code</h1>
            <p class="auth-subtitle" style="color: #9ca3af; margin-bottom: 6px;">
                Please enter the 6-digit code sent to:
            </p>
            <?php if ($email): ?>
                <div class="email-pill">
                    <i class="fa-solid fa-envelope" style="color: var(--color-primary);"></i>
                    <strong><?= htmlspecialchars($email) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" style="text-align: left; margin-top: 16px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <?php if ($info): ?>
            <div class="alert alert-success" style="text-align: left; margin-top: 16px;">
                <i class="fa-solid fa-circle-info"></i>
                <div><?= $info ?></div>
            </div>
        <?php endif; ?>

        <!-- 6-Digit Code Verification Form -->
        <form method="POST" action="verify-email" id="otpForm" style="margin-top: 10px;">
            <?= csrfInput() ?>
            <input type="hidden" name="action_verify_code" value="1">
            <input type="hidden" name="email" id="hiddenEmail" value="<?= htmlspecialchars($email) ?>">
            <input type="hidden" name="otp_code" id="hiddenOtpCode" value="">

            <?php if (!$email): ?>
                <div class="form-group" style="text-align: left; margin-top: 16px;">
                    <label class="form-label">Registered Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="artist@example.com" required value="<?= htmlspecialchars($email) ?>" style="font-size: 14px;">
                </div>
            <?php endif; ?>

            <div class="otp-container" id="otpBoxContainer">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_1" autofocus autocomplete="one-time-code">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_2">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_3">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_4">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_5">
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" class="otp-input" id="otp_6">
            </div>

            <button type="submit" class="btn btn-primary" id="verifyBtn" style="width: 100%; padding: 14px; font-size: 15px; font-weight: 700; margin-top: 10px;">
                <i class="fa-solid fa-key"></i> Verify & Activate Account
            </button>
        </form>

        <!-- Resend Code Form -->
        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; gap: 12px; align-items: center;">
            <form method="POST" action="verify-email" style="display: inline-block;">
                <?= csrfInput() ?>
                <input type="hidden" name="action_resend_code" value="1">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <span style="font-size: 13px; color: var(--text-dim);">Didn't receive the code?</span>
                <button type="submit" id="resendBtn" class="btn btn-secondary btn-sm" style="margin-left: 6px; padding: 6px 14px;">
                    <i class="fa-solid fa-rotate-right"></i> Resend Code
                </button>
            </form>

            <div style="font-size: 12px; color: var(--text-dim);">
                Wrong email address? <a href="signup" style="color: var(--color-primary); text-decoration: underline; font-weight: 600;">Sign up with another email</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="auth-footer" style="margin-top: 24px;">
        <p style="font-size: 13px; color: var(--text-dim);">
            Already verified? <a href="login" style="color: var(--color-primary); font-weight: 600;">Sign in here</a>
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputs = [
        document.getElementById('otp_1'),
        document.getElementById('otp_2'),
        document.getElementById('otp_3'),
        document.getElementById('otp_4'),
        document.getElementById('otp_5'),
        document.getElementById('otp_6')
    ].filter(Boolean);

    const hiddenOtp = document.getElementById('hiddenOtpCode');
    const form = document.getElementById('otpForm');

    function syncCode() {
        const code = inputs.map(i => i.value).join('');
        if (hiddenOtp) hiddenOtp.value = code;
        return code;
    }

    inputs.forEach((input, index) => {
        input.addEventListener('input', function(e) {
            const val = this.value.replace(/[^0-9]/g, '');
            this.value = val ? val.charAt(val.length - 1) : '';
            
            syncCode();

            if (this.value && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }

            // Auto-submit if all 6 digits entered
            if (syncCode().length === 6) {
                form.submit();
            }
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && index > 0) {
                inputs[index - 1].focus();
            }
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').trim();
            const digits = pasted.replace(/[^0-9]/g, '').slice(0, 6);
            
            if (digits) {
                for (let i = 0; i < inputs.length; i++) {
                    inputs[i].value = digits[i] || '';
                }
                const code = syncCode();
                if (code.length === 6) {
                    inputs[5].focus();
                    form.submit();
                } else if (inputs[digits.length]) {
                    inputs[digits.length].focus();
                }
            }
        });
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            const code = syncCode();
            if (code.length !== 6) {
                e.preventDefault();
                alert('Please enter all 6 digits of the verification code.');
                const emptyIdx = inputs.findIndex(i => !i.value);
                if (emptyIdx !== -1) inputs[emptyIdx].focus();
            }
        });
    }
});
</script>

</body>
</html>
