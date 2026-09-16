<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$domain = trim((string)(getenv('WEBHOOK_URL') ?: ''));
if ($domain === '') {
    $domain = trim((string)(getenv('RAILWAY_PUBLIC_DOMAIN') ?: ''));
    if ($domain !== '') $domain = 'https://' . preg_replace('~^https?://~', '', $domain);
}
if ($domain === '') {
    fwrite(STDOUT, "WEBHOOK_URL/RAILWAY_PUBLIC_DOMAIN is not set; webhook setup skipped.\n");
    exit(0);
}
$domain = rtrim($domain, '/');
$url = $domain . '/';

$payload = ['url' => $url, 'drop_pending_updates' => false];
$secret = trim((string)(getenv('WEBHOOK_SECRET') ?: ''));
if ($secret !== '') $payload['secret_token'] = $secret;

$ch = curl_init('https://api.telegram.org/bot' . BOT_TOKEN . '/setWebhook');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 20,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false) {
    fwrite(STDERR, "Webhook setup failed: {$err}\n");
    exit(1);
}
$data = json_decode($raw, true) ?: [];
if (empty($data['ok'])) {
    fwrite(STDERR, "Telegram rejected webhook (HTTP {$code}): " . ($data['description'] ?? $raw) . "\n");
    exit(1);
}
fwrite(STDOUT, "Webhook set: {$url}\n");
