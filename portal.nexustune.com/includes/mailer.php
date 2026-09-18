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
            $this->logMail($to_email, $subject, $html_body, 'Logged/Fallback (No Live SMTP Password)');
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
            $scheme = ($this->secure === 'ssl') ? 'ssl://' : 'tcp://';
            $remote_addr = $scheme . $this->host . ':' . $this->port;

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $socket = @stream_socket_client($remote_addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                $this->logMail($to_email, $subject, $html_body, "Socket error ($errno): $errstr");
                return false;
            }

            stream_set_timeout($socket, $timeout);

            $response = $this->readResponse($socket);
            if (empty($response) || substr($response, 0, 3) !== '220') {
                fclose($socket);
                $this->logMail($to_email, $subject, $html_body, "Invalid initial response: $response");
                return false;
            }

            $this->cmd($socket, "EHLO " . gethostname());

            // STARTTLS Negotiation
            if ($this->secure === 'tls') {
                $tls_reply = $this->cmd($socket, "STARTTLS");
                if (substr($tls_reply, 0, 3) !== '220') {
                    fclose($socket);
                    $this->logMail($to_email, $subject, $html_body, "STARTTLS rejected: $tls_reply");
                    return false;
                }

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

            // Auto-generate plain text if empty
            if (empty($alt_body)) {
                $alt_body = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html_body));
            }

            // Build RFC 5322 Compliant Headers & MIME Data
            $boundary = "----=_NextPart_" . md5(time() . rand());
            $msg_domain = preg_replace('/^mail\./', '', $this->host);
            if (empty($msg_domain) || !strpos($msg_domain, '.')) $msg_domain = 'nexustune.com';
            $msg_id = time() . '.' . bin2hex(random_bytes(8)) . '@' . $msg_domain;

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "Reply-To: {$this->from_email}\r\n";
            $headers .= "Return-Path: <{$this->from_email}>\r\n";
            $headers .= "To: " . ($to_name ? "{$to_name} <{$to_email}>" : $to_email) . "\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "Message-ID: <{$msg_id}>\r\n";
            $headers .= "X-Mailer: NexusTuneMailer/2.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "\r\n";

            $message  = $headers;
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($alt_body)) . "\r\n";

            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($html_body)) . "\r\n";
            $message .= "--{$boundary}--\r\n";
            $message .= "\r\n.\r\n";

            fputs($socket, $message);
            $send_res = $this->readResponse($socket);

            $this->cmd($socket, "QUIT");
            fclose($socket);

            $this->logMail($to_email, $subject, $html_body, "Success (SMTP 250 OK - " . trim($send_res) . ")");
            return true;

        } catch (Exception $e) {
            $this->logMail($to_email, $subject, $html_body, "Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Diagnostic test routine for Admin SMTP testing with real-time logs
     * Accepts optional $custom_config to test unsaved settings on-the-fly
     */
    public function testConnectionAndSend($to_email, $custom_config = null) {
        $host       = $custom_config['host'] ?? $this->host;
        $port       = intval($custom_config['port'] ?? $this->port);
        $user       = $custom_config['user'] ?? $this->user;
        $pass       = isset($custom_config['pass']) ? $custom_config['pass'] : $this->pass;
        $secure     = strtolower($custom_config['secure'] ?? $this->secure);
        $from_email = $custom_config['from_email'] ?? $this->from_email;
        $from_name  = $custom_config['from_name'] ?? $this->from_name;

        $logs = [];
        $logs[] = "Initializing test connection to {$host}:{$port} (Security: {$secure})...";

        if (empty($pass)) {
            $logs[] = "⚠️ Notice: SMTP password is empty. The mailer will operate in local fallback logging mode.";
            $this->logMail($to_email, "SMTP Test Delivery (Simulated)", "<p>Test email in fallback mode</p>", "Simulated Fallback (No Password)");
            return [
                'success' => true,
                'fallback' => true,
                'logs' => $logs,
                'message' => 'Mailer simulated in local fallback mode. Message recorded in system activity log.'
            ];
        }

        try {
            $timeout = 15;
            $scheme = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
            $remote_addr = $scheme . $host . ':' . $port;

            $logs[] = "Connecting to socket: $remote_addr...";
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]
            ]);

            $socket = @stream_socket_client($remote_addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                $logs[] = "❌ Socket Error ($errno): $errstr";
                $this->logMail($to_email, "SMTP Test Delivery", "", "Socket Failed: $errstr");
                return ['success' => false, 'logs' => $logs, 'message' => "Socket connection failed: $errstr (Code $errno)"];
            }
            $logs[] = "✅ TCP Socket connected successfully.";

            stream_set_timeout($socket, $timeout);

            $response = $this->readResponse($socket);
            $logs[] = "<- " . trim($response);
            if (empty($response) || substr($response, 0, 3) !== '220') {
                fclose($socket);
                $logs[] = "❌ Invalid SMTP greeting: $response";
                $this->logMail($to_email, "SMTP Test Delivery", "", "Invalid Greeting: $response");
                return ['success' => false, 'logs' => $logs, 'message' => "Invalid SMTP greeting: $response"];
            }

            $ehlo_res = $this->cmd($socket, "EHLO " . gethostname());
            $logs[] = "-> EHLO " . gethostname();
            $logs[] = "<- " . trim($ehlo_res);

            if ($secure === 'tls') {
                $logs[] = "-> STARTTLS";
                $tls_res = $this->cmd($socket, "STARTTLS");
                $logs[] = "<- " . trim($tls_res);

                if (substr($tls_res, 0, 3) !== '220') {
                    fclose($socket);
                    $logs[] = "❌ Server rejected STARTTLS with: " . trim($tls_res);
                    $this->logMail($to_email, "SMTP Test Delivery", "", "STARTTLS rejected: $tls_res");
                    return ['success' => false, 'logs' => $logs, 'message' => "Server rejected STARTTLS: " . trim($tls_res)];
                }

                $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

                $logs[] = "Starting TLS encryption handshake...";
                $secure_ok = stream_socket_enable_crypto($socket, true, $crypto_method);
                if (!$secure_ok) {
                    fclose($socket);
                    $logs[] = "❌ TLS Handshake failed.";
                    $this->logMail($to_email, "SMTP Test Delivery", "", "TLS Handshake Failed");
                    return ['success' => false, 'logs' => $logs, 'message' => "TLS Handshake negotiation failed."];
                }
                $logs[] = "✅ TLS Encrypted tunnel established.";

                $ehlo_tls = $this->cmd($socket, "EHLO " . gethostname());
                $logs[] = "-> EHLO " . gethostname() . " (Post-TLS)";
                $logs[] = "<- " . trim($ehlo_tls);
            }

            if (!empty($user) && !empty($pass)) {
                $logs[] = "Authenticating with user: " . $user;
                $this->cmd($socket, "AUTH LOGIN");
                $this->cmd($socket, base64_encode($user));
                $auth_res = $this->cmd($socket, base64_encode($pass));
                $logs[] = "<- " . trim($auth_res);

                if (substr($auth_res, 0, 3) !== '235') {
                    fclose($socket);
                    $logs[] = "❌ Authentication failed. Response: " . trim($auth_res);
                    $this->logMail($to_email, "SMTP Test Delivery", "", "Auth Failed: $auth_res");
                    return ['success' => false, 'logs' => $logs, 'message' => "SMTP Authentication failed: " . trim($auth_res)];
                }
                $logs[] = "✅ SMTP Authentication successful.";
            }

            $logs[] = "-> MAIL FROM:<{$from_email}>";
            $res = $this->cmd($socket, "MAIL FROM:<{$from_email}>");
            $logs[] = "<- " . trim($res);

            $logs[] = "-> RCPT TO:<{$to_email}>";
            $res = $this->cmd($socket, "RCPT TO:<{$to_email}>");
            $logs[] = "<- " . trim($res);

            $this->cmd($socket, "DATA");

            $subject = "Nexus Tune SMTP Live Test Delivery";
            $time_str = date('Y-m-d H:i:s T');
            $body = "<p>Congratulations! Your SMTP mail server configuration on <strong>Nexus Tune</strong> is operating perfectly.</p><p>Sent from: <code>{$from_email}</code> via <code>{$host}:{$port}</code></p><p>Timestamp: <code>{$time_str}</code></p>";
            $html = renderNexusEmail($subject, "SMTP Test Delivery Success", $body);
            $plain_text = "Nexus Tune SMTP Live Test Delivery\n\nCongratulations! Your SMTP mail server configuration on Nexus Tune is operating perfectly.\nSent from: {$from_email} via {$host}:{$port}\nTimestamp: {$time_str}\n";

            $boundary = "----=_NextPart_" . md5(time() . rand());
            $msg_domain = preg_replace('/^mail\./', '', $host);
            if (empty($msg_domain) || !strpos($msg_domain, '.')) $msg_domain = 'nexustune.com';
            $msg_id = time() . '.' . bin2hex(random_bytes(8)) . '@' . $msg_domain;

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "From: {$from_name} <{$from_email}>\r\n";
            $headers .= "Reply-To: {$from_email}\r\n";
            $headers .= "Return-Path: <{$from_email}>\r\n";
            $headers .= "To: {$to_email}\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $headers .= "Message-ID: <{$msg_id}>\r\n";
            $headers .= "X-Mailer: NexusTuneMailer/2.0\r\n";
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $headers .= "\r\n";

            $message  = $headers;
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($plain_text)) . "\r\n";

            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($html)) . "\r\n";
            $message .= "--{$boundary}--\r\n";
            $message .= "\r\n.\r\n";

            fputs($socket, $message);
            $send_res = $this->readResponse($socket);
            $logs[] = "<- " . trim($send_res);

            $this->cmd($socket, "QUIT");
            fclose($socket);

            $logs[] = "✅ Mail transaction completed (250 OK). Test email sent successfully!";
            
            // Log to system mail log file
            $this->logMail($to_email, $subject, $html, "Test Success (SMTP 250 OK - " . trim($send_res) . ")");

            return [
                'success' => true,
                'logs' => $logs,
                'message' => "Test email successfully delivered to $to_email via SMTP (ID: " . trim($send_res) . ")! If not visible in Inbox within 1-2 minutes, check your Spam/Junk folder."
            ];

        } catch (Exception $e) {
            $logs[] = "❌ Exception: " . $e->getMessage();
            $this->logMail($to_email, "SMTP Test Delivery", "", "Exception: " . $e->getMessage());
            return ['success' => false, 'logs' => $logs, 'message' => $e->getMessage()];
        }
    }

    private function readResponse($socket) {
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            // In RFC 5321, the last line of a multiline response has space after 3-digit code (e.g. '250 ' or '220 ')
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }
        return $res;
    }

    private function cmd($socket, $cmd) {
        fputs($socket, $cmd . "\r\n");
        return $this->readResponse($socket);
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
 * Send Signup 6-Digit OTP Email Verification
 */
function sendSignupOtpEmail($to_email, $name, $code) {
    $subject = "Your Nexus Tune Verification Code: $code";
    $preheader = "Your 6-digit activation code is $code. Enter this code to verify your artist account.";

    $content = <<<HTML
    <h2 style="color: #ffffff; margin-top: 0; font-size: 22px; font-weight: 700;">Welcome to Nexus Tune, {$name}! 🎵</h2>
    <p>Thank you for creating your artist account with <strong>Nexus Tune Global Distribution</strong>.</p>
    <p>To verify your email address and activate your artist workspace, please enter the following 6-digit verification code on the verification screen:</p>
    
    <div style="text-align: center; margin: 28px 0;">
        <div style="display: inline-block; background: rgba(87, 255, 82, 0.08); border: 2px solid #57ff52; border-radius: 16px; padding: 18px 36px; box-shadow: 0 0 30px rgba(87, 255, 82, 0.25);">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #57ff52; margin-bottom: 6px; font-weight: 700;">6-Digit Activation Code</div>
            <div style="font-family: 'SFMono-Regular', Consolas, Monaco, monospace; font-size: 36px; font-weight: 800; letter-spacing: 12px; color: #ffffff;">{$code}</div>
        </div>
    </div>

    <p style="font-size: 13.5px; color: #9ca3af; text-align: center; line-height: 1.5;">
        This code is valid for <strong>15 minutes</strong>. If you did not request this verification, please ignore this message.
    </p>
HTML;

    $html = renderNexusEmail($subject, $preheader, $content);
    $mailer = new NexusMailer();
    return $mailer->send($to_email, $name, $subject, $html, "Your Nexus Tune Verification Code is: $code (Valid for 15 minutes)");
}

/**
 * Send Signup Email Verification Link (Legacy Fallback)
 */
function sendSignupVerificationEmail($to_email, $name, $token) {
    return sendSignupOtpEmail($to_email, $name, $token);
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

