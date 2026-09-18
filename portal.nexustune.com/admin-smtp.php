<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
requireAdmin();

$user = currentUser();
$page_title = "SMTP & Mail Server Settings";
$success = '';
$error = '';
$test_output = null;

// Handle saving settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_smtp'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $fields = [
            'smtp_enabled'    => isset($_POST['smtp_enabled']) ? '1' : '0',
            'smtp_host'       => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'       => trim($_POST['smtp_port'] ?? '587'),
            'smtp_user'       => trim($_POST['smtp_user'] ?? ''),
            'smtp_secure'     => trim($_POST['smtp_secure'] ?? 'tls'),
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_from_name'  => trim($_POST['smtp_from_name'] ?? 'Nexus Tune Distribution'),
            'site_url'        => trim($_POST['site_url'] ?? 'https://portal.nexustune.com')
        ];

        // Only update password if a new one was typed
        if (!empty($_POST['smtp_pass'])) {
            $fields['smtp_pass'] = $_POST['smtp_pass'];
        }

        foreach ($fields as $key => $val) {
            $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
            $stmt->execute([$key, $val]);
        }

        $success = "SMTP mail server configuration saved successfully.";
    }
}

// Handle sending test email (Saved configuration or Custom configuration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action_test_smtp']) || isset($_POST['action_test_custom_smtp']))) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $test_email = trim($_POST['test_email'] ?? '');
        if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid recipient email address for testing.';
        } else {
            $mailer = new NexusMailer();

            if (isset($_POST['action_test_custom_smtp'])) {
                // Test with custom on-the-fly parameters without altering database
                $custom_cfg = [
                    'host'       => trim($_POST['custom_host'] ?? ''),
                    'port'       => intval($_POST['custom_port'] ?? 587),
                    'secure'     => trim($_POST['custom_secure'] ?? 'tls'),
                    'user'       => trim($_POST['custom_user'] ?? ''),
                    'pass'       => $_POST['custom_pass'] ?? '',
                    'from_email' => trim($_POST['custom_from_email'] ?? 'no-reply@nexustune.com'),
                    'from_name'  => trim($_POST['custom_from_name'] ?? 'Nexus Tune Distribution')
                ];
                $test_output = $mailer->testConnectionAndSend($test_email, $custom_cfg);
            } else {
                // Test with saved database configuration
                $test_output = $mailer->testConnectionAndSend($test_email);
            }

            if ($test_output['success']) {
                $success = $test_output['message'];
            } else {
                $error = $test_output['message'];
            }
        }
    }
}

// Fetch current settings
$current_settings = [];
try {
    $stmt = $pdo->query("SELECT key, value FROM settings");
    while ($row = $stmt->fetch()) {
        $current_settings[$row['key']] = $row['value'];
    }
} catch (Exception $e) {}

$smtp_enabled    = ($current_settings['smtp_enabled'] ?? '1') === '1';
$smtp_host       = $current_settings['smtp_host'] ?? 'smtp.gmail.com';
$smtp_port       = $current_settings['smtp_port'] ?? '587';
$smtp_user       = $current_settings['smtp_user'] ?? 'support@nexustune.com';
$smtp_pass       = $current_settings['smtp_pass'] ?? '';
$smtp_secure     = $current_settings['smtp_secure'] ?? 'tls';
$smtp_from_email = $current_settings['smtp_from_email'] ?? 'support@nexustune.com';
$smtp_from_name  = $current_settings['smtp_from_name'] ?? 'Nexus Tune Distribution';
$site_url        = $current_settings['site_url'] ?? 'https://portal.nexustune.com';

// Read recent mail log entries
$mail_log_file = __DIR__ . '/data/mail_log.txt';
$recent_logs = '';
if (file_exists($mail_log_file)) {
    $lines = file($mail_log_file);
    $recent_lines = array_slice($lines, -40);
    $recent_logs = implode('', $recent_lines);
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="app-main">
    <header class="app-header">
        <div class="header-left">
            <button class="mobile-toggle" id="mobileToggleBtn"><i class="fa-solid fa-bars"></i></button>
            <h1 class="page-title"><i class="fa-solid fa-envelope-circle-check" style="color: var(--color-primary);"></i> SMTP & Mail Server Configuration</h1>
        </div>
        <div class="header-right">
            <a href="admin" class="btn btn-secondary btn-sm">
                <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </header>

    <main class="page-body">
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

<style>
.smtp-grid-container {
    display: grid;
    grid-template-columns: 1.25fr 1fr;
    align-items: start;
    gap: 24px;
    width: 100%;
}
.smtp-presets-bar {
    margin-bottom: 20px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}
.smtp-presets-label {
    font-size: 12px;
    color: var(--text-dim);
    margin-right: 4px;
}
.smtp-log-terminal {
    background: #05070a;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-sm);
    padding: 12px;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 11.5px;
    color: #a1a1aa;
    max-height: 240px;
    overflow-y: auto;
    overflow-x: hidden;
    word-break: break-word;
    line-height: 1.6;
}
.smtp-toggle-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 20px;
    background: rgba(255, 255, 255, 0.03);
    padding: 14px 16px;
    border-radius: var(--radius-md);
    border: 1px solid rgba(255, 255, 255, 0.06);
}
.test-tab-btn {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: var(--text-muted);
    padding: 7px 14px;
    border-radius: var(--radius-sm);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}
.test-tab-btn.active {
    background: rgba(87, 255, 82, 0.12);
    border-color: var(--color-primary);
    color: var(--color-primary);
}
@media (max-width: 992px) {
    .smtp-grid-container {
        grid-template-columns: 1fr;
        gap: 20px;
    }
}
@media (max-width: 576px) {
    .smtp-presets-bar {
        gap: 6px;
    }
    .smtp-presets-bar .btn {
        padding: 6px 10px;
        font-size: 11.5px;
    }
    .smtp-toggle-card {
        padding: 12px;
    }
}
</style>

        <div class="smtp-grid-container">
            <!-- Left Column: SMTP Server Configuration Form -->
            <div class="glass-card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fa-solid fa-server" style="color: var(--color-primary);"></i> Mail Delivery Connection Settings
                    </div>
                </div>

                <div class="smtp-presets-bar">
                    <span class="smtp-presets-label">Quick Presets:</span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('gmail')"><i class="fa-brands fa-google"></i> Gmail</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('zoho')"><i class="fa-solid fa-envelope"></i> Zoho</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('sendgrid')"><i class="fa-solid fa-paper-plane"></i> SendGrid</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="setPreset('mailgun')"><i class="fa-solid fa-bolt"></i> Mailgun</button>
                </div>

                <form method="POST" action="admin-smtp">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action_save_smtp" value="1">

                    <div class="form-group smtp-toggle-card">
                        <input type="checkbox" name="smtp_enabled" id="smtp_enabled" <?= $smtp_enabled ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: var(--color-primary); cursor: pointer; margin-top: 2px; flex-shrink: 0;">
                        <label for="smtp_enabled" style="font-size: 13.5px; font-weight: 600; color: #fff; cursor: pointer; flex: 1;">
                            Enable Live SMTP Email Dispatch
                            <div style="font-size: 12px; color: var(--text-dim); font-weight: 400; margin-top: 2px; line-height: 1.4;">
                                When enabled, activation links and password resets are sent directly via the SMTP server below.
                            </div>
                        </label>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">SMTP Host / Server</label>
                            <input type="text" id="smtp_host" name="smtp_host" class="form-control" placeholder="smtp.gmail.com" required value="<?= htmlspecialchars($smtp_host) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">SMTP Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" class="form-control" placeholder="587" required value="<?= htmlspecialchars($smtp_port) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Encryption Protocol</label>
                            <select id="smtp_secure" name="smtp_secure" class="form-control">
                                <option value="tls" <?= $smtp_secure === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587 - Recommended)</option>
                                <option value="ssl" <?= $smtp_secure === 'ssl' ? 'selected' : '' ?>>SSL / TLS Direct (Port 465)</option>
                                <option value="none" <?= $smtp_secure === 'none' ? 'selected' : '' ?>>None / Plain (Port 25)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">SMTP Username</label>
                            <input type="text" id="smtp_user" name="smtp_user" class="form-control" placeholder="your_email@gmail.com" value="<?= htmlspecialchars($smtp_user) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            SMTP Password / App Password
                            <?php if (!empty($smtp_pass)): ?>
                                <span style="color: var(--color-primary); font-size: 11px; margin-left: 8px;"><i class="fa-solid fa-lock"></i> Configured (Leave blank to keep existing)</span>
                            <?php endif; ?>
                        </label>
                        <div style="position: relative;">
                            <input type="password" id="smtp_pass" name="smtp_pass" class="form-control" placeholder="<?= !empty($smtp_pass) ? '••••••••••••••••' : 'Enter SMTP password or App Password' ?>">
                            <button type="button" onclick="togglePass('smtp_pass', 'eyeIcon')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-dim); cursor: pointer;">
                                <i id="eyeIcon" class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                        <small style="font-size: 11.5px; color: var(--text-dim); margin-top: 4px; display: block;">
                            For Gmail accounts with 2FA, generate an <strong>App Password</strong> in Google Account Security.
                        </small>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">From Sender Email</label>
                            <input type="email" id="smtp_from_email" name="smtp_from_email" class="form-control" placeholder="noreply@nexustune.com" required value="<?= htmlspecialchars($smtp_from_email) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">From Sender Name</label>
                            <input type="text" id="smtp_from_name" name="smtp_from_name" class="form-control" placeholder="Nexus Tune Distribution" required value="<?= htmlspecialchars($smtp_from_name) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Portal Base URL (For Links in Emails)</label>
                        <input type="url" name="site_url" class="form-control" placeholder="https://portal.nexustune.com" required value="<?= htmlspecialchars($site_url) ?>">
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" class="btn btn-primary" style="padding: 12px 28px; width: auto;">
                            <i class="fa-solid fa-floppy-disk"></i> Save SMTP Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Live Testing & Real-Time Connection Diagnostic -->
            <div style="display: flex; flex-direction: column; gap: 24px; min-width: 0;">
                <!-- Test Email Form with Dual Mode -->
                <div class="glass-card">
                    <div class="card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div class="card-title">
                            <i class="fa-solid fa-paper-plane" style="color: var(--color-primary);"></i> Send Live Test Email
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="test-tab-btn active" id="tabBtnSaved" onclick="switchTestTab('saved')">Saved Server</button>
                            <button type="button" class="test-tab-btn" id="tabBtnCustom" onclick="switchTestTab('custom')">Custom Server</button>
                        </div>
                    </div>

                    <!-- Mode 1: Test with Saved Settings -->
                    <div id="testTabSaved">
                        <p style="font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-bottom: 16px;">
                            Send a real test email through your saved active SMTP configuration to verify connection and inbox delivery.
                        </p>

                        <form method="POST" action="admin-smtp">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action_test_smtp" value="1">

                            <div class="form-group">
                                <label class="form-label">Test Recipient Email</label>
                                <input type="email" name="test_email" class="form-control" placeholder="your_personal_email@example.com" required value="<?= htmlspecialchars($_POST['test_email'] ?? $user['email']) ?>">
                            </div>

                            <button type="submit" class="btn btn-secondary" style="width: 100%;">
                                <i class="fa-solid fa-vial-circle-check"></i> Send Test via Saved SMTP
                            </button>
                        </form>
                    </div>

                    <!-- Mode 2: Test with Custom Parameters on the Fly -->
                    <div id="testTabCustom" style="display: none;">
                        <p style="font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-bottom: 16px;">
                            Test any SMTP server or temporary credentials directly on-the-fly without changing the database settings.
                        </p>

                        <form method="POST" action="admin-smtp">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action_test_custom_smtp" value="1">

                            <div class="form-row" style="margin-bottom: 10px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="font-size: 12px;">Host</label>
                                    <input type="text" name="custom_host" class="form-control" style="padding: 8px 12px; font-size: 13px;" placeholder="mail.nexustune.com" required value="<?= htmlspecialchars($_POST['custom_host'] ?? $smtp_host) ?>">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="font-size: 12px;">Port</label>
                                    <input type="number" name="custom_port" class="form-control" style="padding: 8px 12px; font-size: 13px;" placeholder="587" required value="<?= htmlspecialchars($_POST['custom_port'] ?? $smtp_port) ?>">
                                </div>
                            </div>

                            <div class="form-row" style="margin-bottom: 10px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="font-size: 12px;">Security</label>
                                    <select name="custom_secure" class="form-control" style="padding: 8px 12px; font-size: 13px;">
                                        <option value="tls" <?= ($_POST['custom_secure'] ?? $smtp_secure) === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                                        <option value="ssl" <?= ($_POST['custom_secure'] ?? $smtp_secure) === 'ssl' ? 'selected' : '' ?>>SSL / TLS (465)</option>
                                        <option value="none" <?= ($_POST['custom_secure'] ?? $smtp_secure) === 'none' ? 'selected' : '' ?>>None (25)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label class="form-label" style="font-size: 12px;">Username</label>
                                    <input type="text" name="custom_user" class="form-control" style="padding: 8px 12px; font-size: 13px;" placeholder="no-reply@nexustune.com" value="<?= htmlspecialchars($_POST['custom_user'] ?? $smtp_user) ?>">
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 10px;">
                                <label class="form-label" style="font-size: 12px;">Password / App Password</label>
                                <div style="position: relative;">
                                    <input type="password" id="custom_pass" name="custom_pass" class="form-control" style="padding: 8px 12px; font-size: 13px;" placeholder="Enter password to test" value="<?= htmlspecialchars($_POST['custom_pass'] ?? $smtp_pass) ?>">
                                    <button type="button" onclick="togglePass('custom_pass', 'customEyeIcon')" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--text-dim); cursor: pointer;">
                                        <i id="customEyeIcon" class="fa-solid fa-eye" style="font-size: 12px;"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" style="font-size: 12px;">Test Recipient Email</label>
                                <input type="email" name="test_email" class="form-control" placeholder="your_personal_email@example.com" required value="<?= htmlspecialchars($_POST['test_email'] ?? $user['email']) ?>">
                            </div>

                            <button type="submit" class="btn btn-secondary" style="width: 100%;">
                                <i class="fa-solid fa-flask"></i> Run Custom Configuration Test
                            </button>
                        </form>
                    </div>

                    <?php if ($test_output): ?>
                        <div style="margin-top: 20px;">
                            <div style="font-size: 12.5px; font-weight: 700; color: #fff; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">
                                <span>Diagnostic Handshake Log:</span>
                                <span style="font-size: 11px; color: var(--text-dim);">Live Socket Output</span>
                            </div>
                            <div class="smtp-log-terminal">
                                <?php foreach ($test_output['logs'] as $log_line): ?>
                                    <div style="<?= strpos($log_line, '✅') !== false ? 'color: #57ff52;' : (strpos($log_line, '❌') !== false ? 'color: #ef4444;' : (strpos($log_line, '⚠️') !== false ? 'color: #f59e0b;' : '')) ?>">
                                        <?= htmlspecialchars($log_line) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 10px; font-size: 12px; color: var(--text-dim); background: rgba(255,255,255,0.02); padding: 8px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.06);">
                                <i class="fa-solid fa-circle-info" style="color: var(--color-primary); margin-right: 4px;"></i>
                                <strong>Inbox Delivery Note:</strong> When sending through a custom/shared domain for the first time, check your Gmail <strong>Spam / Junk / Promotions</strong> folder.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Dispatch Logs -->
                <div class="glass-card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-clipboard-list" style="color: var(--color-primary);"></i> System Mail Activity Log
                        </div>
                    </div>
                    <div class="smtp-log-terminal">
                        <?= htmlspecialchars($recent_logs ?: 'No outgoing mail activity logged yet.') ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
function togglePass(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!input || !icon) return;
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function switchTestTab(tab) {
    const tabSaved = document.getElementById('testTabSaved');
    const tabCustom = document.getElementById('testTabCustom');
    const btnSaved = document.getElementById('tabBtnSaved');
    const btnCustom = document.getElementById('tabBtnCustom');

    if (tab === 'custom') {
        tabSaved.style.display = 'none';
        tabCustom.style.display = 'block';
        btnSaved.classList.remove('active');
        btnCustom.classList.add('active');
    } else {
        tabSaved.style.display = 'block';
        tabCustom.style.display = 'none';
        btnCustom.classList.remove('active');
        btnSaved.classList.add('active');
    }
}

function setPreset(provider) {
    const host = document.getElementById('smtp_host');
    const port = document.getElementById('smtp_port');
    const secure = document.getElementById('smtp_secure');

    if (provider === 'gmail') {
        host.value = 'smtp.gmail.com';
        port.value = '587';
        secure.value = 'tls';
    } else if (provider === 'zoho') {
        host.value = 'smtppro.zoho.com';
        port.value = '587';
        secure.value = 'tls';
    } else if (provider === 'sendgrid') {
        host.value = 'smtp.sendgrid.net';
        port.value = '587';
        secure.value = 'tls';
    } else if (provider === 'mailgun') {
        host.value = 'smtp.mailgun.org';
        port.value = '587';
        secure.value = 'tls';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

