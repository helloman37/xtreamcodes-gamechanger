<?php
// config.php
// -----------------------------------------------------------------------------
// DO NOT hard-edit this file in production.
// Use the built-in installer at /install to set DB creds + base_url safely.
// The installer writes config.local.php which overrides these defaults.
// -----------------------------------------------------------------------------

// PayPal REST API (storefront)
if (!defined('PAYPAL_CLIENT_ID')) define('PAYPAL_CLIENT_ID', '');
if (!defined('PAYPAL_SECRET')) define('PAYPAL_SECRET', '');
if (!defined('PAYPAL_SANDBOX')) define('PAYPAL_SANDBOX', true);

// CashApp storefront (owner cashtag)
if (!defined('CASHAPP_CASHTAG')) define('CASHAPP_CASHTAG', '$');

$defaults = [
  'db' => [
    'host' => 'localhost',
    'name' => '',
    'user' => '',
    'pass' => '',
    'charset' => 'utf8mb4'
  ],

  'session_name' => 'iptv_admin_session',

  // your real domain root (NO trailing slash)
  'base_url' => 'http://',

  // CHANGE THIS to a long random string
  'secret_key' => 'h!}[.;RZP,4|Y(wNfdtb6fVx*.W[I[g2XKek8hu>BRNNr06JTlaNk=@YL,4~#f)I',

  // token expiry in seconds (used when subscription has no ends_at)
  'token_ttl' => 604800,

  // Active subscription cache TTL (seconds). Used at stream start to reduce DB load.
  // Keep small (30-120). Short TTL still allows quick bans/cancels.
  'sub_cache_ttl' => 60,

  // If true, requires the client to send a stable device_id (querystring or X-Device-ID).
  // Recommended for your Android app; keep false for generic IPTV apps.
  'strict_device_id' => false,

  // Optional: Discord/Telegram/Slack webhook for security/ops alerts (expects JSON body)
  'webhook_url' => '',

  // device/connection window in seconds
  'device_window' => 300,


  // segment session window in seconds (HLS segment auth grace; keep >= device_window)
  'segment_window' => 1800,
  // anti-restream: max unique IPs in window
  'max_ip_changes' => 3,
  'max_ip_window'  => 600,

  // TMDB API key used by the subscriber portal for Movies/Series browse + search.
  // You can override this in config.local.php, system_settings('tmdb_api_key'), or per-user.
  'tmdb_api_key' => '59a35acc07b72f0e91aa657cdb259beb',
  'tmdb_region'  => 'US',
  'tmdb_language'=> 'en-US',

  // Default region for TMDB requests (used for some endpoints like discover).
  'tmdb_region' => 'US'
];

// Optional local overrides written by /install
$local_path = __DIR__ . '/config.local.php';
$local = [];
if (is_file($local_path)) {
  $tmp = require $local_path;
  if (is_array($tmp)) $local = $tmp;
}

// Deep-ish merge: local values override defaults.
$merged = array_replace_recursive($defaults, $local);
return $merged;