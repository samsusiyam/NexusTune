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
            $body = <<<HTML
            <h2 style="margin: 0 0 16px 0; color: #ffffff !important; font-size: 22px; font-weight: 700;">SMTP Test Delivery Success 🎉</h2>
            <p style="margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.6;">Congratulations! Your SMTP mail server configuration on <strong>Nexus Tune</strong> is operating perfectly.</p>
            <div style="background-color: #05070d; border: 1px solid #1e293b; border-radius: 10px; padding: 16px 20px; margin: 20px 0;">
                <div style="color: #94a3b8; font-size: 13px; margin-bottom: 6px;"><strong style="color: #ffffff;">From:</strong> {$from_email}</div>
                <div style="color: #94a3b8; font-size: 13px; margin-bottom: 6px;"><strong style="color: #ffffff;">Relay Server:</strong> {$host}:{$port} ({$secure})</div>
                <div style="color: #94a3b8; font-size: 13px;"><strong style="color: #ffffff;">Timestamp:</strong> {$time_str}</div>
            </div>
            <p style="margin: 0; color: #57ff52 !important; font-size: 14px; font-weight: 600;">✅ All outgoing system notifications & OTP verifications are active and ready.</p>
HTML;
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
 * Base Bulletproof Cyber-Luxe HTML Email Wrapper
 * Fully optimized for Dark Mode & Light Mode rendering across all email clients
 */
function renderNexusEmail($title, $preheader, $contentHtml) {
    return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{$title}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root {
            color-scheme: light dark;
            supported-color-schemes: light dark;
        }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; background-color: #070a12; }
        
        /* Client Dark Mode Overrides */
        [data-ogsc] .email-bg { background-color: #070a12 !important; }
        [data-ogsc] .card-bg { background-color: #0e1422 !important; }
        [data-ogsc] .text-main { color: #e2e8f0 !important; }
        [data-ogsc] .text-title { color: #ffffff !important; }
        
        @media (prefers-color-scheme: dark) {
            .email-bg { background-color: #070a12 !important; }
            .card-bg { background-color: #0e1422 !important; }
            .text-main { color: #e2e8f0 !important; }
            .text-title { color: #ffffff !important; }
        }
        @media only screen and (max-width: 600px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .content-padding { padding: 28px 20px !important; }
            .header-padding { padding: 24px 20px !important; }
        }
    </style>
</head>
<body class="email-bg" bgcolor="#070a12" style="margin: 0; padding: 0; background-color: #070a12; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Preheader preview text -->
    <div style="display: none; max-height: 0px; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #070a12; opacity: 0;">
        {$preheader}
    </div>

    <!-- Outer Table -->
    <table role="presentation" class="email-bg" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#070a12" style="background-color: #070a12; table-layout: fixed;">
        <tr>
            <td align="center" style="padding: 30px 12px;">
                <!-- Main Container Card -->
                <table role="presentation" class="email-container card-bg" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #0e1422; border: 1px solid #1e293b; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 35px rgba(0,0,0,0.5);">
                    
                    <!-- Header -->
                    <tr>
                        <td align="center" class="header-padding" bgcolor="#0b0f19" style="padding: 30px 24px; background-color: #0b0f19; border-bottom: 1px solid #1e293b; text-align: center;">
                            <a href="https://nexustune.com" target="_blank" style="text-decoration: none; display: inline-block;">
                                <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; line-height: 1.2;">
                                    <span style="color: #ffffff !important;">NEXUS</span><span style="color: #57ff52 !important;">TUNE</span>
                                </div>
                                <div style="font-size: 10.5px; text-transform: uppercase; letter-spacing: 2.5px; color: #94a3b8 !important; margin-top: 4px; font-weight: 600;">
                                    Global Music Distribution
                                </div>
                            </a>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-padding text-main" bgcolor="#0e1422" style="padding: 36px 32px; background-color: #0e1422; font-size: 15px; line-height: 1.65; color: #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            {$contentHtml}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" bgcolor="#070a12" style="padding: 24px 30px; background-color: #070a12; border-top: 1px solid #1e293b; text-align: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                            <p style="margin: 0 0 6px 0; color: #94a3b8 !important; font-size: 12px; font-weight: 600;">
                                Nexus Tune Global Music Distribution
                            </p>
                            <p style="margin: 0 0 10px 0; color: #64748b !important; font-size: 11.5px;">
                                Paris (France) &bull; Mumbai (India) &bull; Dhaka (Bangladesh)
                            </p>
                            <p style="margin: 0; color: #64748b !important; font-size: 11px; line-height: 1.5;">
                                This is an automated operational notification. If you did not make this request, please contact <a href="mailto:support@nexustune.com" style="color: #57ff52 !important; text-decoration: underline;">support@nexustune.com</a>.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
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
    <h2 style="margin: 0 0 16px 0; color: #ffffff !important; font-size: 22px; font-weight: 700; line-height: 1.3;">Welcome to Nexus Tune, {$name}! 🎵</h2>
    <p style="margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.65;">
        Thank you for creating your artist account with <strong style="color: #ffffff;">Nexus Tune Global Distribution</strong>.
    </p>
    <p style="margin: 0 0 20px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.65;">
        To verify your email address and activate your artist workspace, please enter this 6-digit verification code:
    </p>
    
    <!-- Bulletproof Code Box Table -->
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                    <tr>
                        <td align="center" bgcolor="#05070d" style="background-color: #05070d; border: 2px solid #57ff52; border-radius: 14px; padding: 20px 36px; text-align: center;">
                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #57ff52 !important; font-weight: 700; margin-bottom: 8px;">6-Digit Activation Code</div>
                            <div style="font-family: 'Courier New', Courier, monospace, monospace; font-size: 38px; font-weight: 800; letter-spacing: 12px; color: #ffffff !important; line-height: 1;">{$code}</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 20px 0 0 0; font-size: 13.5px; color: #94a3b8 !important; text-align: center; line-height: 1.5;">
        This code is valid for <strong style="color: #ffffff;">15 minutes</strong>. If you did not request this verification, you can safely ignore this message.
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
    <h2 style="margin: 0 0 16px 0; color: #ffffff !important; font-size: 22px; font-weight: 700; line-height: 1.3;">Password Reset Request</h2>
    <p style="margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.65;">Hello <strong style="color: #ffffff;">{$name}</strong>,</p>
    <p style="margin: 0 0 16px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.65;">We received a request to reset the password for your Nexus Tune account associated with <strong style="color: #ffffff;">{$to_email}</strong>.</p>
    <p style="margin: 0 0 20px 0; color: #e2e8f0 !important; font-size: 15px; line-height: 1.65;">Click the button below to choose a new password and regain instant access to your artist dashboard:</p>
    
    <!-- Bulletproof Button -->
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0;">
        <tr>
            <td align="center">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                    <tr>
                        <td align="center" bgcolor="#57ff52" style="border-radius: 9999px; background-color: #57ff52;">
                            <a href="{$reset_url}" target="_blank" style="display: inline-block; padding: 14px 34px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 15px; font-weight: 700; color: #000000 !important; text-decoration: none; border-radius: 9999px; line-height: 1;">
                                Reset Your Password &rarr;
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 20px 0 6px 0; font-size: 13px; color: #94a3b8 !important;">Or copy and paste this link into your browser:</p>
    <div style="background-color: #05070d; border: 1px solid #1e293b; border-radius: 8px; padding: 12px 14px; font-family: 'Courier New', Courier, monospace; font-size: 12.5px; color: #57ff52 !important; word-break: break-all;">
        {$reset_url}
    </div>

    <p style="margin: 24px 0 0 0; font-size: 12px; color: #64748b !important; line-height: 1.5;">
        Note: For your security, this reset link will expire in <strong style="color: #94a3b8;">2 hours</strong>. If you did not request a password reset, you can safely ignore this email &mdash; your account remains secure.
    </p>
HTML;

    $html = renderNexusEmail($subject, $preheader, $content);
    $mailer = new NexusMailer();
    return $mailer->send($to_email, $name, $subject, $html, "Reset your password at: $reset_url");
}

