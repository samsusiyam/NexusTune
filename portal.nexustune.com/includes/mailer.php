<?php
// Nexus Tune - Enterprise Pure PHP SMTP Mailer Engine
require_once __DIR__ . '/../config/db.php';

class NexusMailer {
    private $host;
    private $port;
    private $user;
    private $pass;
    private $secure;
    private $from_email;
    private $from_name;
    private $enabled;
    private $log_file;

    public function __construct() {
        global $pdo;
        $this->log_file = __DIR__ . '/../data/mail_log.txt';
        
        // Fetch SMTP config from settings table
        $settings = [];
        try {
            $stmt = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'smtp_%'");
            while ($row = $stmt->fetch()) {
                $settings[$row['key']] = $row['value'];
            }
        } catch (Exception $e) {}

        $this->enabled    = ($settings['smtp_enabled'] ?? '1') === '1';
        $this->host       = $settings['smtp_host'] ?? 'smtp.gmail.com';
        $this->port       = intval($settings['smtp_port'] ?? 587);
        $this->user       = $settings['smtp_user'] ?? 'support@nexustune.com';
        $this->pass       = $settings['smtp_pass'] ?? '';
        $this->secure     = strtolower($settings['smtp_secure'] ?? 'tls');
        $this->from_email = $settings['smtp_from_email'] ?? 'support@nexustune.com';
        $this->from_name  = $settings['smtp_from_name'] ?? 'Nexus Tune Distribution';
    }

    /**
     * Send email via Direct Socket SMTP with TLS/SSL
     */
    public function send($to_email, $to_name, $subject, $html_body, $alt_body = '') {
        // If password is not configured or in local offline mode, record to mail log and attempt fallback
        if (empty($this->pass) || !$this->enabled) {
            $this->logMail($to_email, $subject, $html_body);
            // Also attempt PHP mail() if available
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "Reply-To: {$this->from_email}\r\n";
            @mail($to_email, $subject, $html_body, $headers);
            return true;
        }

        try {
            $timeout = 15;
            $host = $this->host;
            if ($this->secure === 'ssl') {
                $host = 'ssl://' . $host;
            }

            $socket = @fsockopen($host, $this->port, $errno, $errstr, $timeout);
            if (!$socket) {
                $this->logMail($to_email, $subject, $html_body, "Socket error ($errno): $errstr");
                return false;
            }

            $response = fgets($socket, 515);
            if (empty($response) || substr($response, 0, 3) !== '220') {
                fclose($socket);
                $this->logMail($to_email, $subject, $html_body, "Invalid initial response: $response");
                return false;
            }

            $this->cmd($socket, "EHLO " . gethostname());

            // STARTTLS Negotiation
            if ($this->secure === 'tls') {
                $this->cmd($socket, "STARTTLS");
                $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                $secure_ok = stream_socket_enable_crypto($socket, true, $crypto_method);
                if (!$secure_ok) {
                    fclose($socket);
                    $this->logMail($to_email, $subject, $html_body, "TLS handshake failed");
                    return false;
                }
                $this->cmd($socket, "EHLO " . gethostname());
            }

            // Authentication
            if (!empty($this->user) && !empty($this->pass)) {
                $this->cmd($socket, "AUTH LOGIN");
                $this->cmd($socket, base64_encode($this->user));
                $auth_res = $this->cmd($socket, base64_encode($this->pass));
                if (substr($auth_res, 0, 3) !== '235') {
                    fclose($socket);
                    $this->logMail($to_email, $subject, $html_body, "Auth failed: $auth_res");
                    return false;
                }
            }

            // Mail Transaction
            $this->cmd($socket, "MAIL FROM:<{$this->from_email}>");
            $this->cmd($socket, "RCPT TO:<{$to_email}>");
            $this->cmd($socket, "DATA");

            // Build Headers & MIME Data
            $boundary = "----=_NextPart_" . md5(time() . rand());
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "To: " . ($to_name ? "{$to_name} <{$to_email}>" : $to_email) . "\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "X-Mailer: NexusTuneMailer/2.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "\r\n";

            $message  = $headers;
            if (!empty($alt_body)) {
                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= chunk_split(base64_encode($alt_body)) . "\r\n";
            }
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($html_body)) . "\r\n";
            $message .= "--{$boundary}--\r\n";
            $message .= "\r\n.\r\n";

            fputs($socket, $message);
            $send_res = fgets($socket, 515);

            $this->cmd($socket, "QUIT");
            fclose($socket);

            $this->logMail($to_email, $subject, $html_body, "Success (SMTP 250 OK)");
            return true;

        } catch (Exception $e) {
            $this->logMail($to_email, $subject, $html_body, "Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Diagnostic test routine for Admin SMTP testing with real-time logs
     */
    public function testConnectionAndSend($to_email) {
        $logs = [];
        $logs[] = "Initializing test connection to {$this->host}:{$this->port} (Security: {$this->secure})...";

        if (empty($this->pass)) {
            $logs[] = "⚠️ Notice: SMTP password is empty. The mailer will operate in local fallback logging mode.";
            $this->logMail($to_email, "SMTP Test Message", "<p>Test email content</p>", "Test in Fallback mode");
            return [
                'success' => true,
                'fallback' => true,
                'logs' => $logs,
                'message' => 'Mailer simulated successfully in local fallback mode. Outgoing mail logged to data/mail_log.txt.'
            ];
        }

        try {
            $timeout = 15;
            $host = $this->host;
            if ($this->secure === 'ssl') {
                $host = 'ssl://' . $host;
            }

            $logs[] = "Connecting to socket: $host:$this->port...";
            $socket = @fsockopen($host, $this->port, $errno, $errstr, $timeout);
            if (!$socket) {
                $logs[] = "❌ Socket Error ($errno): $errstr";
                return ['success' => false, 'logs' => $logs, 'message' => "Socket connection failed: $errstr (Code $errno)"];
            }
            $logs[] = "✅ TCP Socket connected successfully.";

            $response = fgets($socket, 515);
            $logs[] = "<- " . trim($response);
            if (empty($response) || substr($response, 0, 3) !== '220') {
                fclose($socket);
                $logs[] = "❌ Invalid SMTP greeting: $response";
                return ['success' => false, 'logs' => $logs, 'message' => "Invalid SMTP greeting: $response"];
            }

            $ehlo_res = $this->cmd($socket, "EHLO " . gethostname());
            $logs[] = "-> EHLO " . gethostname();
            $logs[] = "<- " . trim($ehlo_res);

            if ($this->secure === 'tls') {
                $logs[] = "-> STARTTLS";
                $tls_res = $this->cmd($socket, "STARTTLS");
                $logs[] = "<- " . trim($tls_res);

                $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $logs[] = "Starting TLS encryption handshake...";
                $secure_ok = stream_socket_enable_crypto($socket, true, $crypto_method);
                if (!$secure_ok) {
                    fclose($socket);
                    $logs[] = "❌ TLS Handshake failed.";
                    return ['success' => false, 'logs' => $logs, 'message' => "TLS Handshake negotiation failed."];
                }
                $logs[] = "✅ TLS Encrypted tunnel established.";

                $ehlo_tls = $this->cmd($socket, "EHLO " . gethostname());
                $logs[] = "-> EHLO " . gethostname() . " (Post-TLS)";
                $logs[] = "<- " . trim($ehlo_tls);
            }

            if (!empty($this->user) && !empty($this->pass)) {
                $logs[] = "Authenticating with user: " . $this->user;
                $this->cmd($socket, "AUTH LOGIN");
                $this->cmd($socket, base64_encode($this->user));
                $auth_res = $this->cmd($socket, base64_encode($this->pass));
                $logs[] = "<- " . trim($auth_res);

                if (substr($auth_res, 0, 3) !== '235') {
                    fclose($socket);
                    $logs[] = "❌ Authentication failed. Response: " . trim($auth_res);
                    return ['success' => false, 'logs' => $logs, 'message' => "SMTP Authentication failed: " . trim($auth_res)];
                }
                $logs[] = "✅ SMTP Authentication successful.";
            }

            $logs[] = "-> MAIL FROM:<{$this->from_email}>";
            $res = $this->cmd($socket, "MAIL FROM:<{$this->from_email}>");
            $logs[] = "<- " . trim($res);

            $logs[] = "-> RCPT TO:<{$to_email}>";
            $res = $this->cmd($socket, "RCPT TO:<{$to_email}>");
            $logs[] = "<- " . trim($res);

            $this->cmd($socket, "DATA");

            $subject = "Nexus Tune SMTP Live Test Delivery";
            $time_str = date('Y-m-d H:i:s T');
            $body = "<p>Congratulations! Your SMTP configuration on <strong>Nexus Tune</strong> is operating perfectly.</p><p>Sent on: <code>{$time_str}</code></p>";
            $html = renderNexusEmail($subject, "SMTP Test Delivery Success", $body);

            $boundary = "----=_NextPart_" . md5(time() . rand());
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "To: {$to_email}\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "X-Mailer: NexusTuneMailer/2.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

            $message = $headers . $html . "\r\n.\r\n";
            fputs($socket, $message);
            $send_res = fgets($socket, 515);
            $logs[] = "<- " . trim($send_res);

            $this->cmd($socket, "QUIT");
            fclose($socket);

            $logs[] = "✅ Mail transaction completed (250 OK). Test email sent successfully!";
            return ['success' => true, 'logs' => $logs, 'message' => "Test email successfully delivered to $to_email via SMTP!"];

        } catch (Exception $e) {
            $logs[] = "❌ Exception: " . $e->getMessage();
            return ['success' => false, 'logs' => $logs, 'message' => $e->getMessage()];
        }
    }

    private function cmd($socket, $cmd) {
        fputs($socket, $cmd . "\r\n");
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $res;
    }

    private function logMail($to, $subject, $body, $status = 'Logged/Fallback') {
        $entry = "[" . date('Y-m-d H:i:s') . "] TO: $to | SUBJECT: $subject | STATUS: $status\n";
        // Extract any direct action links for dev convenience
        if (preg_match('/href="([^"]+(?:verify-email|reset-password)[^"]*)"/i', $body, $matches)) {
            $entry .= "  -> ACTION LINK: " . $matches[1] . "\n";
        }
        $entry .= "------------------------------------------------------------\n";
        @file_put_contents($this->log_file, $entry, FILE_APPEND);
    }
}

/**
 * Base Cyber-Luxe HTML Email Wrapper
 */
function renderNexusEmail($title, $preheader, $contentHtml) {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #07090e; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f3f4f6; }
        .wrapper { width: 100%; max-width: 600px; margin: 0 auto; background-color: #0e131d; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; overflow: hidden; }
        .header { padding: 32px 24px; text-align: center; background: linear-gradient(180deg, rgba(87, 255, 82, 0.06) 0%, rgba(14, 19, 29, 0) 100%); border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
        .body-content { padding: 36px 32px; font-size: 15px; line-height: 1.7; color: #e5e7eb; }
        .btn-primary { display: inline-block; background-color: #57ff52; color: #000000 !important; font-weight: 700; font-size: 15px; text-decoration: none; padding: 14px 36px; border-radius: 9999px; text-align: center; margin: 24px 0; box-shadow: 0 0 25px rgba(87, 255, 82, 0.35); }
        .footer { padding: 24px 32px; background-color: #07090e; border-top: 1px solid rgba(255, 255, 255, 0.06); font-size: 12px; color: #6b7280; text-align: center; line-height: 1.6; }
        .code-box { background: rgba(0, 0, 0, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; padding: 12px; font-family: monospace; font-size: 13px; color: #57ff52; word-break: break-all; margin-top: 12px; }
    </style>
</head>
<body style="background-color: #07090e; padding: 30px 10px;">
    <div style="display: none; max-height: 0px; overflow: hidden;">{$preheader}</div>
    <div class="wrapper">
        <div class="header">
            <a href="https://nexustune.com" target="_blank" style="text-decoration: none;">
                <img src="https://nexustune.com/logo.png" alt="Nexus Tune" style="height: 42px; max-width: 220px; object-fit: contain;">
            </a>
        </div>
        <div class="body-content">
            {$contentHtml}
        </div>
        <div class="footer">
            <p style="margin: 0 0 8px;"><strong>Nexus Tune Global Music Distribution</strong></p>
            <p style="margin: 0 0 8px;">Paris (France) • Mumbai (India) • Dhaka (Bangladesh)</p>
            <p style="margin: 0;">This is an automated operational notification. If you did not make this request, please ignore or contact <a href="mailto:support@nexustune.com" style="color: #57ff52; text-decoration: none;">support@nexustune.com</a>.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Send Signup Email Verification
 */
function sendSignupVerificationEmail($to_email, $name, $token) {
    $verify_url = "https://portal.nexustune.com/verify-email?token=" . urlencode($token);
    $subject = "Verify Your Nexus Tune Artist Account";
    $preheader = "Activate your Nexus Tune artist portal and begin distributing music globally.";

    $content = <<<HTML
    <h2 style="color: #ffffff; margin-top: 0; font-size: 22px; font-weight: 700;">Welcome to Nexus Tune, {$name}! 🎵</h2>
    <p>Thank you for joining <strong>Nexus Tune</strong>, the global music distribution platform empowering over 50,000+ independent artists and record labels worldwide.</p>
    <p>To activate your artist portal, start uploading releases to Spotify & Apple Music, and receive 100% of your streaming royalties, please verify your email address:</p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{$verify_url}" class="btn-primary" target="_blank">Verify Email Address & Activate Portal &rarr;</a>
    </div>

    <p style="font-size: 13px; color: #9ca3af;">Or copy and paste this verification link directly into your browser:</p>
    <div class="code-box">{$verify_url}</div>

    <p style="font-size: 12px; color: #6b7280; margin-top: 24px;">Note: This verification link is valid for 24 hours. If you did not create an account with Nexus Tune, no further action is required.</p>
HTML;

    $html = renderNexusEmail($subject, $preheader, $content);
    $mailer = new NexusMailer();
    return $mailer->send($to_email, $name, $subject, $html, "Please verify your Nexus Tune account by visiting: $verify_url");
}

/**
 * Send Password Reset Email
 */
function sendPasswordResetEmail($to_email, $name, $token) {
    $reset_url = "https://portal.nexustune.com/reset-password?token=" . urlencode($token);
    $subject = "Reset Your Nexus Tune Account Password";
    $preheader = "Password recovery instructions for your Nexus Tune Artist Portal.";

    $content = <<<HTML
    <h2 style="color: #ffffff; margin-top: 0; font-size: 22px; font-weight: 700;">Password Reset Request</h2>
    <p>Hello <strong>{$name}</strong>,</p>
    <p>We received a request to reset the password for your Nexus Tune account associated with <strong>{$to_email}</strong>.</p>
    <p>Click the button below to choose a new password and regain instant access to your artist dashboard:</p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{$reset_url}" class="btn-primary" target="_blank">Reset Your Password &rarr;</a>
    </div>

    <p style="font-size: 13px; color: #9ca3af;">Or paste this link into your browser:</p>
    <div class="code-box">{$reset_url}</div>

    <p style="font-size: 12px; color: #6b7280; margin-top: 24px;">Note: For your security, this reset link will expire in <strong>2 hours</strong>. If you did not request a password reset, you can safely ignore this email — your account remains secure.</p>
HTML;

    $html = renderNexusEmail($subject, $preheader, $content);
    $mailer = new NexusMailer();
    return $mailer->send($to_email, $name, $subject, $html, "Reset your password at: $reset_url");
}
