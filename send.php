<?php
/**
 * Contact form handler.
 *  1. Sends the lead to the owner's WhatsApp via the Meta WhatsApp Cloud API (template message).
 *  2. Sends an automatic "thank you" to the visitor on WhatsApp (if a phone was given) and by email.
 *  3. Emails the lead to the owner as a backup so no lead is lost if WhatsApp fails.
 *
 * Secrets live in config.php (copy config.sample.php). Never expose them in the browser.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false, 'error' => 'Method not allowed']);

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) out(500, ['ok' => false, 'error' => 'Server not configured']);
$cfg = require $cfgFile;

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'Invalid request']);

// Honeypot: real users never fill this hidden field.
if (!empty($in['website'])) out(200, ['ok' => true]);

// Simple per-IP rate limit: 5 submissions / 10 min.
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl   = sys_get_temp_dir() . '/pk_rl_' . md5($ip);
$hits = is_file($rl) ? array_filter(array_map('intval', file($rl, FILE_IGNORE_NEW_LINES)), fn($t) => $t > time() - 600) : [];
if (count($hits) >= 5) out(429, ['ok' => false, 'error' => 'Too many requests. Please try later.']);
$hits[] = time();
file_put_contents($rl, implode("\n", $hits));

// Clean + validate
$clean = fn($v, $max) => mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string)($v ?? ''))), 0, $max);
$name  = $clean($in['name'] ?? '', 100);
$email = $clean($in['email'] ?? '', 255);
$phone = $clean($in['phone'] ?? '', 20);
$msg   = mb_substr(trim((string)($in['message'] ?? '')), 0, 1000);
$subject = $clean($in['subject'] ?? '', 150);
if ($subject !== '') $msg = mb_substr('[' . $subject . '] ' . $msg, 0, 1000);
$msgOneLine = trim(preg_replace('/\s+/', ' ', $msg));

if ($name === '' || $msg === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    out(422, ['ok' => false, 'error' => 'Please fill name, a valid email and message.']);
}

// Normalise phone to E.164 digits (10-digit numbers assumed India +91)
$digits = preg_replace('/\D+/', '', $phone);
if (strlen($digits) === 10) $digits = '91' . $digits;
$visitorWa = (strlen($digits) >= 11 && strlen($digits) <= 15) ? $digits : '';

function wa_template(array $cfg, string $to, string $template, array $params): array {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'   => $to,
        'type' => 'template',
        'template' => [
            'name'     => $template,
            'language' => ['code' => $cfg['wa_lang'] ?? 'en'],
            'components' => [[
                'type' => 'body',
                'parameters' => array_map(fn($t) => ['type' => 'text', 'text' => ($t === '' ? '-' : $t)], $params),
            ]],
        ],
    ];
    $ch = curl_init("https://graph.facebook.com/{$cfg['wa_api_version']}/{$cfg['wa_phone_number_id']}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['wa_token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) error_log("WhatsApp send failed ($template -> $to): $code $res");
    return ['ok' => $code >= 200 && $code < 300];
}

function send_mail(array $cfg, string $to, string $subject, string $body, string $replyTo = ''): bool {
    $headers = "From: {$cfg['from_name']} <{$cfg['from_email']}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if ($replyTo !== '') $headers .= "Reply-To: $replyTo\r\n";
    return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

// 1) Lead -> owner's WhatsApp
$ownerWa = wa_template($cfg, $cfg['owner_whatsapp'], $cfg['tpl_lead'], [$name, $email, $visitorWa ?: 'not given', $msgOneLine]);

// 2) Backup email to owner
$ownerMail = send_mail($cfg, $cfg['owner_email'], "New portfolio lead: $name",
    "Name: $name\nEmail: $email\nPhone: " . ($visitorWa ?: 'not given') . "\n\nMessage:\n$msg\n", $email);

// 3) Automatic thank-you to the visitor
$thanksWa = $visitorWa ? wa_template($cfg, $visitorWa, $cfg['tpl_thanks'], [$name]) : ['ok' => false];
$thanksMail = send_mail($cfg, $email, 'Thanks for contacting Pavan Kumar Jangili',
    "Hi $name,\n\nThanks for contacting me! I've received your message and will get back to you shortly.\n\nBest regards,\nPavan Kumar Jangili\nFull-Stack Web Developer\n+91 72072 70296\n");

// Success if the owner got the lead by at least one channel.
if ($ownerWa['ok'] || $ownerMail) out(200, ['ok' => true]);
out(502, ['ok' => false, 'error' => 'Could not deliver your message. Please email jpk56339@gmail.com.']);
