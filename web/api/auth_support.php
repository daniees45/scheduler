<?php

require_once __DIR__ . '/db.php';

function auth_support_get_columns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM {$table}");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }

    return $columns;
}

function auth_support_ensure_user_auth_columns(mysqli $conn): void
{
    $columns = auth_support_get_columns($conn, 'users');

    if (!isset($columns['email'])) {
        $conn->query("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER full_name");
    }
    if (!isset($columns['email_verified'])) {
        $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER email");
    }
    if (!isset($columns['verification_code_hash'])) {
        $conn->query("ALTER TABLE users ADD COLUMN verification_code_hash VARCHAR(255) NULL AFTER email_verified");
    }
    if (!isset($columns['verification_code_expires_at'])) {
        $conn->query("ALTER TABLE users ADD COLUMN verification_code_expires_at DATETIME NULL AFTER verification_code_hash");
    }
    if (!isset($columns['password_reset_code_hash'])) {
        $conn->query("ALTER TABLE users ADD COLUMN password_reset_code_hash VARCHAR(255) NULL AFTER verification_code_expires_at");
    }
    if (!isset($columns['password_reset_expires_at'])) {
        $conn->query("ALTER TABLE users ADD COLUMN password_reset_expires_at DATETIME NULL AFTER password_reset_code_hash");
    }
}

function auth_support_generate_code(int $length = 6): string
{
    $length = max(4, min(10, $length));
    $min = (int)pow(10, $length - 1);
    $max = (int)pow(10, $length) - 1;
    return (string)random_int($min, $max);
}

function auth_support_hash_code(string $code): string
{
    return hash('sha256', trim($code));
}

function auth_support_get_login_url(): string
{
    $baseUrl = function_exists('scheduler_public_base_url') ? scheduler_public_base_url() : null;

    if (is_string($baseUrl) && trim($baseUrl) !== '') {
        return scheduler_url_join($baseUrl, 'login.php');
    }

    return 'login.php';
}

function auth_support_build_code_email(string $variant, string $toName, string $code, int $minutes): array
{
    $variants = [
        'confirmation' => [
            'subject' => 'Confirm your VVU Scheduler account',
            'eyebrow' => 'Account verification',
            'headline' => 'Confirm your account',
            'accent' => '#4f46e5',
            'icon' => 'fa-envelope-open-text',
            'description' => 'Use this confirmation code to finish creating your account.',
            'footer' => 'If you did not create this account, you can ignore this email safely.',
            'button' => 'Open Login',
        ],
        'password_reset' => [
            'subject' => 'Reset your VVU Scheduler password',
            'eyebrow' => 'Password recovery',
            'headline' => 'Reset your password',
            'accent' => '#f59e0b',
            'icon' => 'fa-key',
            'description' => 'Use this reset code to set a new password for your account.',
            'footer' => 'If you did not request a password reset, no action is needed.',
            'button' => 'Go to Login',
        ],
    ];

    $theme = $variants[$variant] ?? $variants['confirmation'];
    $safeName = htmlspecialchars($toName ?: 'User', ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeLoginUrl = htmlspecialchars(auth_support_get_login_url(), ENT_QUOTES, 'UTF-8');
    $minutes = max(1, $minutes);

    $htmlBody = '
        <div style="background:#0f172a;padding:32px 0;font-family:Inter,Arial,sans-serif;">
            <div style="max-width:640px;margin:0 auto;padding:0 18px;">
                <div style="background:linear-gradient(135deg,' . $theme['accent'] . ',#0ea5e9);border-radius:24px;padding:28px;color:#fff;box-shadow:0 24px 70px rgba(0,0,0,.28);overflow:hidden;">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;opacity:.95;">
                        <div style="width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;">
                            <i class="fa-solid ' . $theme['icon'] . '" style="font-size:18px;"></i>
                        </div>
                        <div style="font-size:12px;letter-spacing:.18em;text-transform:uppercase;font-weight:700;">' . htmlspecialchars($theme['eyebrow'], ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                    <h1 style="margin:0 0 10px 0;font-size:30px;line-height:1.2;">' . htmlspecialchars($theme['headline'], ENT_QUOTES, 'UTF-8') . '</h1>
                    <p style="margin:0;color:rgba(255,255,255,.86);font-size:15px;line-height:1.7;max-width:520px;">Hello ' . $safeName . ', ' . htmlspecialchars($theme['description'], ENT_QUOTES, 'UTF-8') . '</p>
                </div>

                <div style="margin-top:-18px;background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:22px;padding:24px;color:#e5e7eb;box-shadow:0 18px 40px rgba(0,0,0,.22);">
                    <div style="font-size:13px;color:#9ca3af;text-transform:uppercase;letter-spacing:.14em;margin-bottom:8px;">Your code</div>
                    <div style="display:inline-block;padding:16px 24px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:34px;letter-spacing:8px;font-weight:800;color:#fff;">
                        ' . $safeCode . '
                    </div>
                    <p style="margin:18px 0 0 0;color:#cbd5e1;line-height:1.7;">This code expires in <strong>' . $minutes . ' minutes</strong>. Use it on the login page to complete the request.</p>
                    <div style="margin-top:22px;">
                        <a href="' . $safeLoginUrl . '" style="display:inline-block;background:' . $theme['accent'] . ';color:#fff;text-decoration:none;padding:12px 20px;border-radius:12px;font-weight:700;">' . htmlspecialchars($theme['button'], ENT_QUOTES, 'UTF-8') . '</a>
                    </div>
                </div>

                <div style="padding:18px 6px 0 6px;color:#94a3b8;font-size:13px;line-height:1.7;text-align:center;">
                    ' . htmlspecialchars($theme['footer'], ENT_QUOTES, 'UTF-8') . '
                </div>
            </div>
        </div>
    ';

    $textBody = "Hello {$toName},\n\n{$theme['description']}\n\nYour code: {$code}\nExpires in {$minutes} minutes.\n\n{$theme['footer']}";

    return [$theme['subject'], $htmlBody, $textBody];
}

function auth_support_send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): array
{
    // Load centralised email configuration (config/app_config.php → bootstrap)
    $emailCfg = function_exists('scheduler_email_config') ? scheduler_email_config() : [];

    $driver = strtolower((string)($emailCfg['driver'] ?? 'auto'));
    $fromEmail = (string)($emailCfg['from_address'] ?? 'noreply@vvuscheduler.local');
    $fromName  = (string)($emailCfg['from_name'] ?? 'VVU Scheduler');

    $gmailEmail = (string)($emailCfg['gmail']['app_email'] ?? '');
    $gmailPassword = (string)($emailCfg['gmail']['app_password'] ?? '');
    $hasGmail = ($gmailEmail !== '' && $gmailPassword !== '');

    $sgApiKey = (string)($emailCfg['sendgrid']['api_key'] ?? '');
    $sgFromEmail = (string)($emailCfg['sendgrid']['from_email'] ?? '');
    $sgFromName = (string)($emailCfg['sendgrid']['from_name'] ?? $fromName);
    $hasSendGrid = ($sgApiKey !== '');

    // Gmail-first policy:
    // 1) driver=gmail  -> use direct Gmail SMTP (no PHPMailer).
    // 2) driver=auto   -> try Gmail SMTP first, then SendGrid, then generic native fallback.
    if ($driver === 'gmail' || ($driver === 'auto' && $hasGmail)) {
        $gmailResult = auth_support_send_via_gmail_smtp(
            $gmailEmail,
            $gmailPassword,
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $textBody
        );

        if ($gmailResult['success']) {
            return $gmailResult;
        }

        // If explicitly set to gmail, surface that failure and do not switch provider silently.
        if ($driver === 'gmail') {
            return $gmailResult;
        }
    }

    // Secondary transport: SendGrid HTTP API.
    if ($driver === 'sendgrid' || ($driver === 'auto' && $hasSendGrid)) {
        $sgFrom = $sgFromEmail !== '' ? $sgFromEmail : $fromEmail;
        $sendgridResult = auth_support_send_via_sendgrid_api(
            $sgApiKey,
            $sgFrom,
            $sgFromName,
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $textBody
        );

        if ($sendgridResult['success']) {
            return $sendgridResult;
        }

        // If explicitly using SendGrid, return the API error immediately.
        if ($driver === 'sendgrid') {
            return $sendgridResult;
        }
    }

    // Final fallback transport: native mail() using local MTA.
    return auth_support_send_via_native_mail(
        $fromEmail,
        $fromName,
        $toEmail,
        $toName,
        $subject,
        $htmlBody,
        $textBody
    );
}

function auth_support_send_via_gmail_smtp(
    string $gmailEmail,
    string $gmailAppPassword,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    if ($gmailEmail === '' || $gmailAppPassword === '') {
        return ['success' => false, 'error' => 'Gmail app email/password not configured.'];
    }

    $ctx = stream_context_create([
        'ssl' => [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $socket = @stream_socket_client('tcp://smtp.gmail.com:587', $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        return ['success' => false, 'error' => 'Unable to connect to Gmail SMTP: ' . $errstr . ' (' . $errno . ')'];
    }

    stream_set_timeout($socket, 20);

    $readResponse = static function ($stream) {
        $response = '';
        while (($line = fgets($stream, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };

    $sendCommand = static function ($stream, string $command, array $expectCodes) use ($readResponse) {
        if (@fwrite($stream, $command . "\r\n") === false) {
            return ['ok' => false, 'response' => 'Write failed'];
        }
        $response = $readResponse($stream);
        $code = (int)substr($response, 0, 3);
        return ['ok' => in_array($code, $expectCodes, true), 'response' => $response];
    };

    $openBanner = $readResponse($socket);
    if ((int)substr($openBanner, 0, 3) !== 220) {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP banner error: ' . trim($openBanner)];
    }

    $steps = [
        ['EHLO localhost', [250]],
        ['STARTTLS', [220]],
    ];

    foreach ($steps as [$cmd, $codes]) {
        $res = $sendCommand($socket, $cmd, $codes);
        if (!$res['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => 'SMTP command failed (' . $cmd . '): ' . trim($res['response'])];
        }
    }

    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($socket);
        return ['success' => false, 'error' => 'Unable to enable TLS for Gmail SMTP.'];
    }

    $afterTls = [
        ['EHLO localhost', [250]],
        ['AUTH LOGIN', [334]],
        [base64_encode($gmailEmail), [334]],
        [base64_encode($gmailAppPassword), [235]],
        ['MAIL FROM:<' . $gmailEmail . '>', [250]],
        ['RCPT TO:<' . $toEmail . '>', [250, 251]],
        ['DATA', [354]],
    ];

    foreach ($afterTls as [$cmd, $codes]) {
        $res = $sendCommand($socket, $cmd, $codes);
        if (!$res['ok']) {
            fclose($socket);
            return ['success' => false, 'error' => 'SMTP command failed (' . (strpos($cmd, 'AUTH') === 0 ? 'AUTH' : $cmd) . '): ' . trim($res['response'])];
        }
    }

    $toDisplay = $toName !== '' ? ('"' . addslashes($toName) . '" <' . $toEmail . '>') : $toEmail;
    $boundary = '=_vvu_' . md5(uniqid((string)mt_rand(), true));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    $headers[] = 'From: "VVU Scheduler" <' . $gmailEmail . '>';
    $headers[] = 'To: ' . $toDisplay;
    $headers[] = 'Subject: ' . $encodedSubject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $textBody . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $htmlBody . "\r\n\r\n";
    $message .= '--' . $boundary . "--\r\n.\r\n";

    if (@fwrite($socket, $message) === false) {
        fclose($socket);
        return ['success' => false, 'error' => 'Failed to write email body to SMTP stream.'];
    }

    $dataResp = $readResponse($socket);
    if ((int)substr($dataResp, 0, 3) !== 250) {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP DATA failed: ' . trim($dataResp)];
    }

    $sendCommand($socket, 'QUIT', [221]);
    fclose($socket);

    return ['success' => true, 'error' => null];
}

function auth_support_send_via_sendgrid_api(
    string $apiKey,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    if ($apiKey === '') {
        return ['success' => false, 'error' => 'SendGrid API key is missing.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL extension is required for SendGrid API transport.'];
    }

    $payload = [
        'personalizations' => [[
            'to' => [[
                'email' => $toEmail,
                'name' => ($toName !== '' ? $toName : $toEmail),
            ]],
            'subject' => $subject,
        ]],
        'from' => [
            'email' => $fromEmail,
            'name' => ($fromName !== '' ? $fromName : 'VVU Scheduler'),
        ],
        'content' => [
            ['type' => 'text/plain', 'value' => $textBody],
            ['type' => 'text/html', 'value' => $htmlBody],
        ],
    ];

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    if ($ch === false) {
        return ['success' => false, 'error' => 'Unable to initialize SendGrid request.'];
    }

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'SendGrid request failed: ' . $curlErr];
    }

    // SendGrid returns 202 Accepted on success.
    if ($status >= 200 && $status < 300) {
        return ['success' => true, 'error' => null];
    }

    $msg = 'SendGrid API error (HTTP ' . $status . ')';
    $decoded = json_decode((string)$response, true);
    if (is_array($decoded) && isset($decoded['errors'][0]['message'])) {
        $msg .= ': ' . (string)$decoded['errors'][0]['message'];
    }

    return ['success' => false, 'error' => $msg];
}

function auth_support_send_via_native_mail(
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    $toDisplay = $toName !== '' ? ('"' . addslashes($toName) . '" <' . $toEmail . '>') : $toEmail;

    $boundary = '=_vvu_' . md5(uniqid((string)mt_rand(), true));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $safeFromName = str_replace(['\r', '\n'], '', $fromName !== '' ? $fromName : 'VVU Scheduler');
    $safeFromEmail = str_replace(['\r', '\n'], '', $fromEmail);

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: "' . addslashes($safeFromName) . '" <' . $safeFromEmail . '>';
    $headers[] = 'Reply-To: ' . $safeFromEmail;
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $ok = @mail($toDisplay, $encodedSubject, $body, implode("\r\n", $headers));
    if ($ok) {
        return ['success' => true, 'error' => null];
    }

    return [
        'success' => false,
        'error' => 'Native mail() transport failed. Configure SendGrid API (recommended) or local sendmail/postfix.',
    ];
}

function auth_support_send_confirmation_code(string $toEmail, string $toName, string $code, int $minutes = 15): array
{
    [$subject, $htmlBody, $textBody] = auth_support_build_code_email('confirmation', $toName, $code, $minutes);

    return auth_support_send_email($toEmail, $toName, $subject, $htmlBody, $textBody);
}

function auth_support_send_password_reset_code(string $toEmail, string $toName, string $code, int $minutes = 15): array
{
    [$subject, $htmlBody, $textBody] = auth_support_build_code_email('password_reset', $toName, $code, $minutes);

    return auth_support_send_email($toEmail, $toName, $subject, $htmlBody, $textBody);
}
