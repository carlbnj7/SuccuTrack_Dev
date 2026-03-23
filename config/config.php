<?php
/**
 * config/config.php
 *
 * ── IMPORTANT: GMAIL SETUP (read before deploying) ───────────────────────────
 *
 *  Shared hosts (Hostinger, cPanel, etc.) block outbound TCP to external
 *  SMTP servers on port 587/465. A raw socket to smtp.gmail.com will time-out
 *  or be refused before PHP can even attempt authentication — which is exactly
 *  why you see the "Dev / Test Mode" banner even after the other fixes.
 *
 *  This file solves that by using the Gmail REST API (HTTP POST to
 *  https://gmail.googleapis.com) instead of a raw SMTP socket.
 *  HTTP/HTTPS on port 443 is almost never blocked by shared hosts.
 *
 *  ONE-TIME GMAIL SETUP (takes ~5 minutes):
 *  ─────────────────────────────────────────
 *  1. Go to https://console.cloud.google.com/
 *  2. Create a project (or reuse one).
 *  3. Enable "Gmail API" for the project.
 *  4. Create credentials → OAuth 2.0 Client ID → Desktop app.
 *  5. Download the JSON, then run the one-time OAuth token script at the
 *     bottom of this file (or separately) to get a refresh token.
 *  6. Paste GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET, GMAIL_REFRESH_TOKEN below.
 *
 *  ─── ALTERNATIVELY: use a Gmail App Password (simpler, but needs SMTP) ─────
 *  If your host does NOT block port 587, the smtp_send() function below still
 *  works with a Gmail App Password. Enable 2-Step Verification on the Google
 *  account, generate an App Password at https://myaccount.google.com/apppasswords,
 *  and set SMTP_PASS to the 16-char code WITHOUT spaces.
 *  Set GMAIL_USE_API to false to use that path instead.
 */

// ── Base path ─────────────────────────────────────────────────────────────────
define('APP_ROOT', dirname(__DIR__));

// ── URL helpers ───────────────────────────────────────────────────────────────
if (!defined('APP_BASE')) {
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot  = str_replace('\\', '/', APP_ROOT);
    $relative = ltrim(str_replace($docRoot, '', $appRoot), '/');
    define('APP_BASE', '/' . $relative);
}

function url_to(string $path): string {
    return rtrim(APP_BASE, '/') . '/' . ltrim($path, '/');
}

function redirect_to(string $path, int $code = 302): void {
    http_response_code($code);
    header('Location: ' . url_to($path));
    exit;
}

// ── Timezone ──────────────────────────────────────────────────────────────────
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ── Database ──────────────────────────────────────────────────────────────────
$host = 'localhost';
$db   = 'u442411629_succulent';
$user = 'u442411629_dev_succulent';
$pass = '%oV0p(24rNz7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log("DB Connection failed: " . $e->getMessage());
    die("Database unavailable. Please try again later.");
}

// ── Schema migrations ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verifications (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        username   VARCHAR(80)  NOT NULL,
        password   VARCHAR(255) NOT NULL,
        otp_code   CHAR(6)      NOT NULL,
        attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email   (email),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE users
        ADD COLUMN IF NOT EXISTS status
        ENUM('pending','recommended','active') NOT NULL DEFAULT 'active'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        notif_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        for_role    ENUM('admin','manager') NOT NULL,
        type        VARCHAR(40)  NOT NULL,
        title       VARCHAR(160) NOT NULL,
        body        TEXT NOT NULL,
        ref_user_id INT UNSIGNED NULL,
        is_read     TINYINT(1) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role_unread (for_role, is_read),
        INDEX idx_ref (ref_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Non-fatal – column/table may already exist
}

// ══════════════════════════════════════════════════════════════════════════════
// OTP CONSTANTS
// ══════════════════════════════════════════════════════════════════════════════
define('OTP_LENGTH',          6);   // digits in every verification code
define('OTP_EXPIRY_MINUTES', 10);   // minutes until the code expires
define('OTP_MAX_ATTEMPTS',    5);   // wrong guesses before the code is locked

// ══════════════════════════════════════════════════════════════════════════════
// GMAIL CONFIGURATION
// ══════════════════════════════════════════════════════════════════════════════

// ── Sender identity (always required) ────────────────────────────────────────
define('MAIL_FROM_ADDRESS', 'succutrack@gmail.com');
define('MAIL_FROM_NAME',    'SuccuTrack');

// ── Toggle: true = Gmail REST API (works on Hostinger), false = raw SMTP ─────
// Start with true. Switch to false only if you confirm port 587 is open.
define('GMAIL_USE_API', true);

// ── Option A: Gmail REST API via OAuth 2.0 ───────────────────────────────────
// Fill these in after completing the one-time OAuth setup described at the top.
// Leave as empty strings until you have the values — the mailer will detect
// that and fall back to the dev banner with a clear log message.
define('GMAIL_CLIENT_ID',      '');   // e.g. '1234567890-abc.apps.googleusercontent.com'
define('GMAIL_CLIENT_SECRET',  '');   // e.g. 'GOCSPX-xxxxxxxxxxxxxxxxxxxxxxxx'
define('GMAIL_REFRESH_TOKEN',  '');   // e.g. '1//0gxxxxxxxx...'

// ── Option B: Gmail App Password over SMTP (only if host allows port 587) ────
// Set GMAIL_USE_API to false above, then fill in the 16-char App Password
// WITHOUT spaces. Get one at https://myaccount.google.com/apppasswords
// (requires 2-Step Verification to be enabled on the account first).
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'succutrack@gmail.com');
define('SMTP_PASS', 'oypvvdxsrpzcrwqk');              // paste 16-char App Password here, no spaces
define('SMTP_FROM', MAIL_FROM_ADDRESS);
define('SMTP_NAME', MAIL_FROM_NAME);

// true  → OTP stored in $_SESSION['dev_otp'] and shown on screen
// false → real delivery only (API or SMTP)
define('OTP_DEV_MODE', false);

// ══════════════════════════════════════════════════════════════════════════════
// GMAIL REST API SENDER
// Uses OAuth 2.0 refresh token to obtain a short-lived access token, then
// posts the message to https://gmail.googleapis.com/gmail/v1/users/me/messages/send
// This goes out over HTTPS port 443 — never blocked by shared hosts.
// ══════════════════════════════════════════════════════════════════════════════
function gmail_api_send(
    string $to,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    $clientId     = GMAIL_CLIENT_ID;
    $clientSecret = GMAIL_CLIENT_SECRET;
    $refreshToken = GMAIL_REFRESH_TOKEN;
    $from         = MAIL_FROM_ADDRESS;
    $fromName     = MAIL_FROM_NAME;

    // Guard: detect unconfigured credentials
    if (!$clientId || !$clientSecret || !$refreshToken) {
        return ['ok' => false, 'error' => 'gmail_api_not_configured'];
    }

    // ── Step 1: exchange refresh token for access token ───────────────────────
    $tokenPayload = http_build_query([
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $tokenPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $tokenResponse = curl_exec($ch);
    $tokenErr      = curl_error($ch);
    curl_close($ch);

    if ($tokenErr || !$tokenResponse) {
        error_log("[SuccuTrack Gmail API] Token fetch failed: " . $tokenErr);
        return ['ok' => false, 'error' => 'token_fetch_failed: ' . $tokenErr];
    }

    $tokenData   = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';

    if (!$accessToken) {
        $apiError = $tokenData['error_description'] ?? $tokenData['error'] ?? 'unknown';
        error_log("[SuccuTrack Gmail API] No access token: " . $apiError);
        return ['ok' => false, 'error' => 'no_access_token: ' . $apiError];
    }

    // ── Step 2: build RFC 2822 MIME message ───────────────────────────────────
    $boundary = '=_' . md5(uniqid('', true));
    $msgId    = '<' . uniqid('st', true) . '@succutrack.app>';

    $raw  = "From: {$fromName} <{$from}>\r\n";
    $raw .= "To: {$toName} <{$to}>\r\n";
    $raw .= "Subject: {$subject}\r\n";
    $raw .= "Message-ID: {$msgId}\r\n";
    $raw .= "Date: " . date('r') . "\r\n";
    $raw .= "MIME-Version: 1.0\r\n";
    $raw .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $raw .= "\r\n";
    $raw .= "--{$boundary}\r\n";
    $raw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $raw .= quoted_printable_encode($textBody) . "\r\n";
    $raw .= "--{$boundary}\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $raw .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $raw .= quoted_printable_encode($htmlBody) . "\r\n";
    $raw .= "--{$boundary}--\r\n";

    // Gmail API requires URL-safe base64 (no +, /, or = padding)
    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    // ── Step 3: POST to Gmail API ─────────────────────────────────────────────
    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['raw' => $encoded]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $sendResponse = curl_exec($ch);
    $sendErr      = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sendErr) {
        error_log("[SuccuTrack Gmail API] Send cURL error: " . $sendErr);
        return ['ok' => false, 'error' => 'curl_send_failed: ' . $sendErr];
    }

    $sendData = json_decode($sendResponse, true);

    if ($httpCode === 200 && !empty($sendData['id'])) {
        return ['ok' => true];
    }

    $apiError = $sendData['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log("[SuccuTrack Gmail API] Send failed ({$httpCode}): " . $apiError);
    return ['ok' => false, 'error' => 'gmail_api_error: ' . $apiError];
}

// ══════════════════════════════════════════════════════════════════════════════
// RAW SMTP SENDER (Option B — used when GMAIL_USE_API is false)
// ══════════════════════════════════════════════════════════════════════════════
function smtp_send(
    string $to,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = SMTP_FROM;
    $name    = SMTP_NAME;
    $timeout = 15;

    if (!$user || !$pass || stripos($pass, 'xxxx') !== false) {
        return ['ok' => false, 'error' => 'smtp_not_configured'];
    }

    try {
        $ctx  = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $sock = stream_socket_client(
            "tcp://{$host}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$sock) {
            return ['ok' => false, 'error' => "Cannot connect to {$host}:{$port} — {$errstr} ({$errno})"];
        }
        stream_set_timeout($sock, $timeout);

        $cmd = function (string $c) use ($sock): string {
            if ($c !== '') fwrite($sock, $c . "\r\n");
            $r = '';
            while ($line = fgets($sock, 512)) {
                $r .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $r;
        };
        $expect = function (string $r, string $code): void {
            if (substr(trim($r), 0, 3) !== $code) {
                throw new RuntimeException("SMTP error (expected {$code}): " . trim($r));
            }
        };

        $expect($cmd(''), '220');
        $expect($cmd("EHLO " . gethostname()), '250');
        $expect($cmd("STARTTLS"), '220');
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException("TLS handshake failed");
        }
        $expect($cmd("EHLO " . gethostname()), '250');
        $expect($cmd("AUTH LOGIN"), '334');
        $expect($cmd(base64_encode($user)), '334');
        $expect($cmd(base64_encode($pass)), '235');
        $expect($cmd("MAIL FROM:<{$from}>"), '250');
        $expect($cmd("RCPT TO:<{$to}>"), '250');

        $b     = '=_' . md5(uniqid('', true));
        $h     = "Date: " . date('r') . "\r\n";
        $h    .= "Message-ID: <" . uniqid('st', true) . "@succutrack.app>\r\n";
        $h    .= "From: {$name} <{$from}>\r\nTo: {$toName} <{$to}>\r\nSubject: {$subject}\r\n";
        $h    .= "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
        $h    .= "X-Mailer: SuccuTrack/PHP\r\n";
        $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($textBody) . "\r\n";
        $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($htmlBody) . "\r\n";
        $body .= "--{$b}--\r\n";

        $expect($cmd("DATA"), '354');
        $msg = str_replace("\n.", "\n..", $h . "\r\n" . $body);
        $expect($cmd($msg . "\r\n."), '250');
        $cmd("QUIT");
        fclose($sock);
        return ['ok' => true];

    } catch (RuntimeException $e) {
        if (isset($sock) && is_resource($sock)) fclose($sock);
        error_log("[SuccuTrack SMTP] " . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// UNIFIED MAILER — calls API or SMTP based on GMAIL_USE_API constant
// ══════════════════════════════════════════════════════════════════════════════
function send_mail(
    string $to,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): array {
    if (GMAIL_USE_API) {
        return gmail_api_send($to, $toName, $subject, $htmlBody, $textBody);
    }
    return smtp_send($to, $toName, $subject, $htmlBody, $textBody);
}

// ══════════════════════════════════════════════════════════════════════════════
// send_otp_email()
// Called by register.php and verify_email.php (resend action).
// Return shape consumed by verify_email.php:
//   ['success'=>true,  'otp'=>'######']                      real send OK
//   ['success'=>true,  'otp'=>'######', 'dev_mode'=>true]     OTP_DEV_MODE on
//   ['success'=>true,  'otp'=>'######', 'dev_mode'=>true,
//                      'smtp_error'=>string]                  delivery failed, fallback
// ══════════════════════════════════════════════════════════════════════════════
function send_otp_email(
    PDO    $pdo,
    string $email,
    string $username,
    string $hashed_password
): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'invalid_email'];
    }

    // Remove prior pending row for this address and all stale expired rows
    $pdo->prepare("DELETE FROM email_verifications WHERE email=? OR expires_at < NOW()")
        ->execute([$email]);

    // Generate OTP
    $otp = '';
    for ($i = 0; $i < OTP_LENGTH; $i++) {
        $otp .= (string) random_int(0, 9);
    }

    $expires = date('Y-m-d H:i:s', time() + OTP_EXPIRY_MINUTES * 60);

    $pdo->prepare(
        "INSERT INTO email_verifications
             (email, username, password, otp_code, attempts, expires_at)
         VALUES (?, ?, ?, ?, 0, ?)"
    )->execute([$email, $username, $hashed_password, $otp, $expires]);

    // Dev mode — skip real sending
    if (OTP_DEV_MODE) {
        error_log("[SuccuTrack DEV] OTP for {$email}: {$otp}");
        return ['success' => true, 'otp' => $otp, 'dev_mode' => true];
    }

    // Build email content
    $year   = date('Y');
    $expMin = OTP_EXPIRY_MINUTES;
    $subj   = "[SuccuTrack] Your verification code: {$otp}";
    $safe   = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f3f7;font-family:Arial,sans-serif;">
  <div style="max-width:480px;margin:32px auto;background:#fff;border-radius:10px;
              overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0d1f14,#1a5235);padding:26px 30px 22px;">
      <div style="font-size:1.2rem;font-weight:700;color:#fff;">🌵 SuccuTrack</div>
      <div style="font-size:.8rem;color:rgba(255,255,255,.5);margin-top:3px;">Email Verification</div>
    </div>
    <div style="padding:26px 30px;">
      <h2 style="font-size:1rem;color:#0f172a;margin:0 0 8px;">Hi {$safe},</h2>
      <p style="font-size:.85rem;color:#64748b;line-height:1.65;margin:0 0 20px;">
        Enter the code below to verify your email and activate your SuccuTrack account.
      </p>
      <div style="text-align:center;margin:0 0 20px;">
        <div style="display:inline-block;background:#f0f5f2;border:2px solid #8fceaa;
                    border-radius:10px;padding:14px 40px;">
          <div style="font-size:.65rem;font-weight:700;color:#1a6e3c;letter-spacing:.12em;
                      text-transform:uppercase;margin-bottom:8px;">Your Verification Code</div>
          <div style="font-size:2.6rem;font-weight:700;color:#1a6e3c;letter-spacing:.28em;
                      font-family:monospace;">{$otp}</div>
        </div>
      </div>
      <p style="font-size:.79rem;color:#94a3b8;margin:0 0 6px;">
        ⏱ Expires in <strong style="color:#0f172a;">{$expMin} minutes</strong>.
      </p>
      <p style="font-size:.79rem;color:#94a3b8;margin:0;">
        If you did not request this, you can safely ignore this email.
      </p>
    </div>
    <div style="padding:13px 30px;border-top:1px solid #e2e8f0;background:#f6f7fa;">
      <p style="font-size:.7rem;color:#94a3b8;margin:0;">
        &copy; {$year} SuccuTrack &middot; Manolo Fortich, Bukidnon
      </p>
    </div>
  </div>
</body></html>
HTML;

    $text = "Hi {$username},\n\n"
          . "Your SuccuTrack verification code is:\n\n  {$otp}\n\n"
          . "Expires in {$expMin} minutes.\n\n"
          . "If you did not request this, ignore this email.\n\n"
          . "© {$year} SuccuTrack";

    $result = send_mail($email, $username, $subj, $html, $text);

    if ($result['ok']) {
        return ['success' => true, 'otp' => $otp];
    }

    // Delivery failed — log it and surface the on-screen fallback banner
    // so the user can still complete registration
    error_log("[SuccuTrack] Mail delivery failed for {$email} — " . ($result['error'] ?? 'unknown'));
    return [
        'success'    => true,
        'otp'        => $otp,
        'dev_mode'   => true,
        'smtp_error' => $result['error'] ?? 'unknown',
    ];
}

// ── Notification helpers ──────────────────────────────────────────────────────
function notify_managers_new_user(PDO $pdo, int $newUserId, string $username): void
{
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('manager', 'new_user', ?, ?, ?)"
    )->execute([
        "New user registered: @{$username}",
        "User @{$username} just registered and is waiting for review.",
        $newUserId,
    ]);
}

function notify_admins_recommended(PDO $pdo, int $userId, string $username, string $managerName): void
{
    $pdo->prepare(
        "INSERT INTO notifications (for_role, type, title, body, ref_user_id)
         VALUES ('admin', 'recommended', ?, ?, ?)"
    )->execute([
        "User ready for plant assignment: @{$username}",
        "Manager @{$managerName} reviewed and recommended @{$username}. Please assign plants.",
        $userId,
    ]);
}

function get_unread_count(PDO $pdo, string $role): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE for_role=? AND is_read=0");
    $s->execute([$role]);
    return (int) $s->fetchColumn();
}

function count_pending_users(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='user' AND status='pending'"
    )->fetchColumn();
}

function count_actionable_users(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='user' AND status IN('pending','recommended')"
    )->fetchColumn();
}


// ══════════════════════════════════════════════════════════════════════════════
// ONE-TIME OAUTH TOKEN GENERATOR
// ──────────────────────────────────────────────────────────────────────────────
// Run this script ONCE from a browser (not in production) to get your refresh
// token. After you have the token, delete or disable this block.
//
// HOW TO USE:
//   1. Fill in your CLIENT_ID and CLIENT_SECRET from Google Cloud Console.
//   2. Add http://localhost/succutrackv3/config/config.php to your OAuth
//      redirect URIs in Google Cloud Console → Credentials → your OAuth client.
//   3. Visit this file directly in your browser once.
//   4. Follow the Google consent screen.
//   5. Copy the refresh_token that prints on screen into GMAIL_REFRESH_TOKEN above.
//   6. Comment out this entire block.
// ══════════════════════════════════════════════════════════════════════════════
if (
    php_sapi_name() !== 'cli'
    && isset($_GET['oauth_setup'])
    && GMAIL_CLIENT_ID !== ''
    && GMAIL_CLIENT_SECRET !== ''
    && GMAIL_REFRESH_TOKEN === ''   // only runs when token not yet configured
) {
    $redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . strtok($_SERVER['REQUEST_URI'], '?');

    // Step 2: Google redirected back with ?code=...
    if (!empty($_GET['code'])) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'code'          => $_GET['code'],
                'client_id'     => GMAIL_CLIENT_ID,
                'client_secret' => GMAIL_CLIENT_SECRET,
                'redirect_uri'  => $redirectUri . '?oauth_setup=1',
                'grant_type'    => 'authorization_code',
            ]),
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        echo '<pre style="font-family:monospace;padding:20px;">';
        echo "<strong>✅ OAuth complete. Copy the refresh_token below into config.php:</strong>\n\n";
        echo "GMAIL_REFRESH_TOKEN = '" . ($resp['refresh_token'] ?? '— not returned, check scopes —') . "'\n\n";
        echo "Full response:\n" . json_encode($resp, JSON_PRETTY_PRINT);
        echo '</pre>';
        exit;
    }

    // Step 1: redirect to Google consent screen
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'             => GMAIL_CLIENT_ID,
        'redirect_uri'          => $redirectUri . '?oauth_setup=1',
        'response_type'         => 'code',
        'scope'                 => 'https://www.googleapis.com/auth/gmail.send',
        'access_type'           => 'offline',
        'prompt'                => 'consent',
    ]);
    header('Location: ' . $authUrl);
    exit;
}