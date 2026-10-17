<?php
declare(strict_types=1);

/**
 * Senternet early-access API.
 * POST JSON: {"name":"...","email":"...","consent":true}
 * GET: {"status":"ok","service":"senternet-early-access-api"}
 *
 * Configure SENTERNET_DATA_DIR to a writable directory OUTSIDE the public web root.
 * Configure SENTERNET_ALLOWED_ORIGINS as a comma-separated allowlist, e.g.
 * https://senternettechnologies.github.io
 */

const MAX_BODY_BYTES = 8192;
const MAX_NAME_LENGTH = 100;
const MAX_EMAIL_LENGTH = 254;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function env_value(string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : trim($value);
}

$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', env_value('SENTERNET_ALLOWED_ORIGINS')))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
        respond(403, ['error' => 'Origin not allowed.']);
    }
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    respond(200, ['status' => 'ok', 'service' => 'senternet-early-access-api']);
}
if ($method !== 'POST') {
    header('Allow: GET, POST, OPTIONS');
    respond(405, ['error' => 'Method not allowed.']);
}
if ($origin !== '' && !in_array($origin, $allowedOrigins, true)) {
    respond(403, ['error' => 'Origin not allowed.']);
}
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    respond(415, ['error' => 'Content-Type must be application/json.']);
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > MAX_BODY_BYTES) {
    respond(413, ['error' => 'Request is too large.']);
}
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    respond(413, ['error' => 'Request is too large.']);
}
try {
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    respond(400, ['error' => 'Invalid JSON.']);
}
if (!is_array($input)) {
    respond(400, ['error' => 'Invalid request.']);
}

$name = trim((string)($input['name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$consent = ($input['consent'] ?? false) === true;

if ($name === '' || mb_strlen($name) > MAX_NAME_LENGTH) {
    respond(422, ['error' => 'Enter a name under 100 characters.']);
}
if (strlen($email) > MAX_EMAIL_LENGTH || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['error' => 'Enter a valid email address.']);
}
if (!$consent) {
    respond(422, ['error' => 'Consent is required to join the early-access list.']);
}

// Never store visitor submissions in the GitHub repository.
$dataDir = env_value('SENTERNET_DATA_DIR');
if ($dataDir === '') {
    error_log('SENTERNET_DATA_DIR is not configured; refusing to store submissions.');
    respond(503, ['error' => 'Submissions are temporarily unavailable.']);
}
if (!is_dir($dataDir) || !is_writable($dataDir)) {
    error_log('SENTERNET_DATA_DIR is not a writable directory.');
    respond(503, ['error' => 'Submissions are temporarily unavailable.']);
}

// A lightweight per-IP throttle. For higher traffic, use a gateway/WAF rate limit.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateKey = hash_hmac('sha256', $ip, env_value('SENTERNET_RATE_LIMIT_SECRET', 'unset-secret-change-this'));
$ratePath = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'rate_' . $rateKey . '.json';
$rateHandle = fopen($ratePath, 'c+');
if ($rateHandle === false) respond(503, ['error' => 'Submissions are temporarily unavailable.']);
flock($rateHandle, LOCK_EX);
$rateRaw = stream_get_contents($rateHandle);
$rateData = json_decode($rateRaw ?: '{}', true);
$now = time();
$windowStart = is_array($rateData) ? (int)($rateData['window_start'] ?? 0) : 0;
$count = is_array($rateData) ? (int)($rateData['count'] ?? 0) : 0;
if ($now - $windowStart >= 3600 || $windowStart > $now) {
    $windowStart = $now;
    $count = 0;
}
if ($count >= 10) {
    flock($rateHandle, LOCK_UN);
    fclose($rateHandle);
    respond(429, ['error' => 'Too many requests. Please try again later.']);
}
$count++;
rewind($rateHandle);
ftruncate($rateHandle, 0);
fwrite($rateHandle, json_encode(['window_start' => $windowStart, 'count' => $count]));
fflush($rateHandle);
flock($rateHandle, LOCK_UN);
fclose($rateHandle);

$dataPath = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'early_access.json';
$handle = fopen($dataPath, 'c+');
if ($handle === false) respond(503, ['error' => 'Submissions are temporarily unavailable.']);
flock($handle, LOCK_EX);
$storedRaw = stream_get_contents($handle);
$records = json_decode($storedRaw ?: '[]', true);
if (!is_array($records) || ($storedRaw !== '' && $storedRaw !== false && trim($storedRaw) !== '' && json_last_error() !== JSON_ERROR_NONE)) {
    flock($handle, LOCK_UN);
    fclose($handle);
    error_log('Early-access JSON data is invalid; write rejected to avoid data loss.');
    respond(500, ['error' => 'Storage is unavailable.']);
}
foreach ($records as $record) {
    if (is_array($record) && strtolower((string)($record['email'] ?? '')) === $email) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(409, ['error' => 'This email is already on the early-access list.']);
    }
}
$records[] = [
    'id' => bin2hex(random_bytes(12)),
    'name' => $name,
    'email' => $email,
    'consent' => true,
    'created_at' => gmdate('c')
];
$encoded = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false) {
    flock($handle, LOCK_UN);
    fclose($handle);
    respond(500, ['error' => 'Unable to save submission.']);
}
rewind($handle);
ftruncate($handle, 0);
$written = fwrite($handle, $encoded . PHP_EOL);
fflush($handle);
flock($handle, LOCK_UN);
fclose($handle);
if ($written === false) respond(500, ['error' => 'Unable to save submission.']);

respond(201, ['status' => 'created', 'message' => 'Early-access request received.']);
