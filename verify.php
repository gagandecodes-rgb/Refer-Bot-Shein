<?php
/**
 * ✅ verify.php (Separate Web Verify File)
 *
 * URL:
 *   /verify.php?uid=TG_ID&token=VERIFY_TOKEN
 *
 * REQUIRED ENV:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 * BOT_USERNAME (without @)
 *
 * REQUIRED DB:
 * users(tg_id, verified, verify_token, verify_token_expires, verified_at)
 * device_links(device_token, tg_id)
 */

error_reporting(E_ALL);
ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", "php://stderr");

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");
$BOT_USERNAME = getenv("BOT_USERNAME"); // without @

function botUsername() {
  global $BOT_USERNAME;
  $u = ltrim((string)$BOT_USERNAME, "@");
  return $u ?: "";
}

function htmlUI($title, $msg, $doUrl) {
  $btn = $doUrl ? '<a class="btn" href="'.htmlspecialchars($doUrl).'">✅ Verify Now</a>' : '';
  return '<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'.htmlspecialchars($title).'</title>
<style>
  body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;font-family:system-ui;color:#fff;}
  .card{width:min(560px,92vw);background:#111827;border-radius:18px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.45);}
  .h{font-size:26px;font-weight:800;margin:0 0 10px}
  .p{opacity:.85;line-height:1.4;margin:0 0 16px;font-size:16px}
  .btn{display:block;text-align:center;background:#22c55e;color:#000;padding:14px 16px;border-radius:12px;text-decoration:none;font-weight:800;font-size:18px}
</style>
</head><body><div class="card">
<div class="h">🔐 '.htmlspecialchars($title).'</div>
<div class="p">'.htmlspecialchars($msg).'</div>
'.$btn.'
</div></body></html>';
}

function setDeviceCookie($name, $value, $days = 365) {
  $expires = time() + 86400 * $days;
  $secure = (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"]) && $_SERVER["HTTP_X_FORWARDED_PROTO"] === "https")
            || (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
  $cookie = "{$name}={$value}; Expires=" . gmdate("D, d M Y H:i:s", $expires) . " GMT; Path=/; HttpOnly; SameSite=Lax";
  if ($secure) $cookie .= "; Secure";
  header("Set-Cookie: ".$cookie, false);
}

/* ================= DB CONNECT ================= */
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require;connect_timeout=5",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Exception $e) {
  echo htmlUI("DB Error", "Database not connected.", null);
  exit;
}

/* ================= INPUT ================= */
$uid   = (int)($_GET["uid"] ?? 0);
$token = trim($_GET["token"] ?? "");
$step  = $_GET["step"] ?? "";

if (!$uid || !$token) {
  echo htmlUI("Invalid", "Invalid verification link.", null);
  exit;
}

/* ================= STEP 1: UI ================= */
if ($step !== "do") {
  $doUrl = "verify.php?uid=".$uid."&token=".urlencode($token)."&step=do";
  echo htmlUI("Verification", "Tap below to verify.", $doUrl);
  exit;
}

/* ================= STEP 2: VERIFY ================= */

// device token cookie
$cookieName = "device_token";
if (empty($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 20) {
  $dt = bin2hex(random_bytes(16));
  setDeviceCookie($cookieName, $dt, 365);
  $_COOKIE[$cookieName] = $dt;
}
$deviceToken = $_COOKIE[$cookieName];

// Ensure user exists
$pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
    ->execute([":tg" => $uid]);

// Validate token + expiry
$st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
$st->execute([":tg" => $uid]);
$u = $st->fetch();

if (!$u) {
  echo htmlUI("Error", "User not found.", null);
  exit;
}

if (!empty($u["verified"])) {
  $bot = botUsername();
  if ($bot) { header("Location: https://t.me/".$bot); exit; }
  echo htmlUI("Verified", "Verified already.", null);
  exit;
}

if (($u["verify_token"] ?? "") !== $token) {
  echo htmlUI("Invalid", "This link is invalid. Press Check Verification again.", null);
  exit;
}

$exp = $u["verify_token_expires"] ?? "";
if (!$exp || strtotime($exp) < time()) {
  echo htmlUI("Expired", "Link expired. Press Check Verification again.", null);
  exit;
}

// device lock
$st = $pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=:dt LIMIT 1");
$st->execute([":dt" => $deviceToken]);
$existing = $st->fetch();

if ($existing && (int)$existing["tg_id"] !== $uid) {
  echo htmlUI("Blocked", "❌ This device is already linked to another Telegram ID.", null);
  exit;
}

// link device
$pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
               ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
    ->execute([":dt" => $deviceToken, ":tg" => $uid]);

// mark verified
$pdo->prepare("UPDATE users
               SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL
               WHERE tg_id=:tg")
    ->execute([":tg" => $uid]);

// redirect to bot
$bot = botUsername();
if ($bot) {
  header("Location: https://t.me/".$bot);
  exit;
}

echo htmlUI("Done", "Verified successfully. Return to Telegram.", null);
exit;
