<?php
declare(strict_types=1);

// email_lib.php
// Outbound email notifications (phpmail / SMTP) + optional IMAP config storage/test.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function gc_email_bool(string $v): bool {
  $v = strtolower(trim($v));
  return in_array($v, ['1','true','yes','on'], true);
}

function gc_email_settings(PDO $pdo): array {
  // Defaults
  $base = iptv_base_url();
  $host = parse_url($base, PHP_URL_HOST) ?: 'localhost';
  $defaultFrom = 'no-reply@' . $host;

  $driver = (string)system_setting_get($pdo, 'mail_driver', 'phpmail');
  $driver = strtolower(trim($driver));
  if (!in_array($driver, ['phpmail','smtp'], true)) $driver = 'phpmail';

  $from_email = trim((string)system_setting_get($pdo, 'mail_from_email', $defaultFrom));
  $from_name  = trim((string)system_setting_get($pdo, 'mail_from_name', 'Service'));

  $reply_to = trim((string)system_setting_get($pdo, 'mail_reply_to', ''));
  if ($reply_to !== '' && !filter_var($reply_to, FILTER_VALIDATE_EMAIL)) $reply_to = '';

  $smtp = [
    'host' => trim((string)system_setting_get($pdo, 'smtp_host', '')),
    'port' => (int)system_setting_get($pdo, 'smtp_port', '587'),
    'user' => trim((string)system_setting_get($pdo, 'smtp_user', '')),
    'pass' => iptv_decrypt(system_setting_get($pdo, 'smtp_pass_enc', '') ?? ''),
    'security' => strtolower(trim((string)system_setting_get($pdo, 'smtp_security', 'tls'))), // none|tls|ssl
    'auth' => gc_email_bool((string)system_setting_get($pdo, 'smtp_auth', '1')),
    'timeout' => (int)system_setting_get($pdo, 'smtp_timeout', '15'),
  ];
  if (!in_array($smtp['security'], ['none','tls','ssl'], true)) $smtp['security'] = 'tls';
  if ($smtp['port'] < 1) $smtp['port'] = ($smtp['security'] === 'ssl') ? 465 : 587;
  if ($smtp['timeout'] < 3) $smtp['timeout'] = 15;

  $imap = [
    'host' => trim((string)system_setting_get($pdo, 'imap_host', '')),
    'port' => (int)system_setting_get($pdo, 'imap_port', '993'),
    'user' => trim((string)system_setting_get($pdo, 'imap_user', '')),
    'pass' => iptv_decrypt(system_setting_get($pdo, 'imap_pass_enc', '') ?? ''),
    'security' => strtolower(trim((string)system_setting_get($pdo, 'imap_security', 'ssl'))), // none|tls|ssl
    'mailbox' => trim((string)system_setting_get($pdo, 'imap_mailbox', 'INBOX')),
    'timeout' => (int)system_setting_get($pdo, 'imap_timeout', '15'),
  ];
  if (!in_array($imap['security'], ['none','tls','ssl'], true)) $imap['security'] = 'ssl';
  if ($imap['port'] < 1) $imap['port'] = ($imap['security'] === 'ssl') ? 993 : 143;
  if ($imap['timeout'] < 3) $imap['timeout'] = 15;

  $toggles = [
    'require_verification' => gc_email_bool((string)system_setting_get($pdo, 'require_email_verification', '1')),
    'send_welcome' => gc_email_bool((string)system_setting_get($pdo, 'email_welcome_enabled', '1')),
    'send_verify' => gc_email_bool((string)system_setting_get($pdo, 'email_verify_enabled', '1')),
    'send_subscription' => gc_email_bool((string)system_setting_get($pdo, 'email_subscription_enabled', '1')),
    'send_expiry' => gc_email_bool((string)system_setting_get($pdo, 'email_expiry_enabled', '1')),
    'expiry_days' => (int)system_setting_get($pdo, 'email_expiry_days', '3'),
  ];
  if ($toggles['expiry_days'] < 1) $toggles['expiry_days'] = 3;

  return [
    'driver' => $driver,
    'from_email' => $from_email,
    'from_name' => $from_name,
    'reply_to' => $reply_to,
    'smtp' => $smtp,
    'imap' => $imap,
    'toggles' => $toggles,
    'base_url' => $base,
  ];
}

function gc_email_wrap_html(string $title, string $bodyHtml): string {
  $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>' . $titleEsc . '</title>'
    . '</head><body style="margin:0;padding:0;background:#0b0f14;font-family:Arial,Helvetica,sans-serif;">'
    . '<div style="max-width:720px;margin:0 auto;padding:22px;">'
    . '<div style="background:#121a23;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:22px;color:#e9eef7;">'
    . $bodyHtml
    . '</div>'
    . '<div style="color:rgba(233,238,247,.6);font-size:12px;line-height:1.4;margin-top:12px;padding:0 6px;">'
    . 'If you did not request this email, you can ignore it.'
    . '</div>'
    . '</div></body></html>';
}

function gc_email_log_once(PDO $pdo, string $uniqKey, ?int $userId, ?string $email, string $type): bool {
  try {
    $st = $pdo->prepare("INSERT IGNORE INTO email_logs (user_id, email, type, uniq_key) VALUES (?,?,?,?)");
    $st->execute([$userId, $email, $type, $uniqKey]);
    return ($st->rowCount() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

// ---------------- SMTP client (minimal) ----------------
function _smtp_read_line($fp, int $timeout = 15): string {
  stream_set_timeout($fp, $timeout);
  $line = '';
  while (!feof($fp)) {
    $l = fgets($fp, 515);
    if ($l === false) break;
    $line .= $l;
    // Multi-line reply ends with space after code
    if (preg_match('/^\d{3} /', $l)) break;
  }
  return $line;
}

function _smtp_expect($fp, array $okCodes, int $timeout = 15): string {
  $resp = _smtp_read_line($fp, $timeout);
  if ($resp === '') throw new RuntimeException('SMTP: empty response');
  $code = (int)substr($resp, 0, 3);
  if (!in_array($code, $okCodes, true)) {
    throw new RuntimeException('SMTP: unexpected code ' . $code . ' resp=' . trim($resp));
  }
  return $resp;
}

function _smtp_cmd($fp, string $cmd, array $okCodes, int $timeout = 15): void {
  fwrite($fp, $cmd . "\r\n");
  _smtp_expect($fp, $okCodes, $timeout);
}

function gc_email_send_smtp(array $cfg, string $fromEmail, string $fromName, string $to, string $subject, string $html, string $text = '', string $replyTo = ''): bool {
  $host = $cfg['host'] ?? '';
  $port = (int)($cfg['port'] ?? 587);
  if ($host === '' || $port < 1) return false;

  $timeout = (int)($cfg['timeout'] ?? 15);
  if ($timeout < 3) $timeout = 15;

  $security = $cfg['security'] ?? 'tls';
  $security = strtolower((string)$security);
  $transport = ($security === 'ssl') ? 'ssl://' : '';
  $fp = @fsockopen($transport . $host, $port, $errno, $errstr, $timeout);
  if (!$fp) return false;

  try {
    _smtp_expect($fp, [220], $timeout);
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    fwrite($fp, $ehlo . "\r\n");
    $resp = _smtp_read_line($fp, $timeout);
    if (!preg_match('/^250/', $resp)) {
      // fallback HELO
      _smtp_cmd($fp, 'HELO ' . (gethostname() ?: 'localhost'), [250], $timeout);
    }

    // STARTTLS
    if ($security === 'tls') {
      // Some servers require STARTTLS; try it if advertised or just attempt.
      fwrite($fp, "STARTTLS\r\n");
      $r = _smtp_read_line($fp, $timeout);
      if (preg_match('/^220/', $r)) {
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
          throw new RuntimeException('SMTP: STARTTLS failed');
        }
        // Re-EHLO after TLS
        _smtp_cmd($fp, $ehlo, [250], $timeout);
      }
    }

    // AUTH
    $auth = !empty($cfg['auth']);
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');
    if ($auth && $user !== '') {
      _smtp_cmd($fp, 'AUTH LOGIN', [334], $timeout);
      _smtp_cmd($fp, base64_encode($user), [334], $timeout);
      _smtp_cmd($fp, base64_encode($pass), [235], $timeout);
    }

    $fromPath = '<' . $fromEmail . '>';
    $toPath = '<' . $to . '>';
    _smtp_cmd($fp, 'MAIL FROM:' . $fromPath, [250], $timeout);
    _smtp_cmd($fp, 'RCPT TO:' . $toPath, [250, 251], $timeout);
    _smtp_cmd($fp, 'DATA', [354], $timeout);

    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = [];
    $headers[] = 'From: ' . ($fromName !== '' ? ('"' . addslashes($fromName) . '" ') : '') . '<' . $fromEmail . '>';
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8');
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary=' . $boundary;
    $headers[] = 'Date: ' . date('r');

    if ($text === '') {
      $text = trim(strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html)));
    }

    $msg = implode("\r\n", $headers) . "\r\n\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= $text . "\r\n\r\n";
    $msg .= '--' . $boundary . "\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= $html . "\r\n\r\n";
    $msg .= '--' . $boundary . "--\r\n";
    $msg .= "\r\n.\r\n";

    fwrite($fp, $msg);
    _smtp_expect($fp, [250], $timeout);
    _smtp_cmd($fp, 'QUIT', [221, 250], $timeout);
    fclose($fp);
    return true;
  } catch (Throwable $e) {
    try { fwrite($fp, "QUIT\r\n"); } catch (Throwable $t) {}
    try { fclose($fp); } catch (Throwable $t) {}
    return false;
  }
}

function gc_email_send_mail(PDO $pdo, string $to, string $subject, string $html, string $text = ''): bool {
  $to = trim($to);
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

  $s = gc_email_settings($pdo);
  $fromEmail = $s['from_email'];
  $fromName  = $s['from_name'];
  $replyTo   = $s['reply_to'];

  if ($s['driver'] === 'smtp') {
    return gc_email_send_smtp($s['smtp'], $fromEmail, $fromName, $to, $subject, $html, $text, $replyTo);
  }

  // phpmail (mail())
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/html; charset=UTF-8';
  $headers[] = 'From: ' . ($fromName !== '' ? ('"' . addslashes($fromName) . '" ') : '') . '<' . $fromEmail . '>';
  if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;

  $ok = @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $html, implode("\r\n", $headers));
  return (bool)$ok;
}

function gc_email_user_row(PDO $pdo, int $userId): ?array {
  $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  return $u ?: null;
}

function gc_email_user_is_verified(array $user): bool {
  return !empty($user['email_verified_at']);
}

function gc_email_verification_required(PDO $pdo): bool {
  $s = gc_email_settings($pdo);
  return (bool)$s['toggles']['require_verification'];
}

function gc_email_make_verify_token(): string {
  return bin2hex(random_bytes(32));
}

function gc_email_send_welcome(PDO $pdo, int $userId): bool {
  $s = gc_email_settings($pdo);
  if (empty($s['toggles']['send_welcome'])) return false;
  $u = gc_email_user_row($pdo, $userId);
  if (!$u) return false;
  $email = trim((string)($u['email'] ?? ''));
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

  $name = trim((string)($u['name'] ?? ''));
  $hello = $name !== '' ? ('Hi ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',') : 'Welcome!';

  $body = '<h2 style="margin:0 0 10px;">' . $hello . '</h2>'
    . '<p style="margin:0 0 10px;color:rgba(233,238,247,.88);">Your account has been created.</p>'
    . '<p style="margin:0;color:rgba(233,238,247,.88);">Username: <b>' . htmlspecialchars((string)$u['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b></p>';

  $html = gc_email_wrap_html('Welcome', $body);
  return gc_email_send_mail($pdo, $email, 'Welcome to ' . ($s['from_name'] ?: 'our service'), $html);
}

function gc_email_send_verification(PDO $pdo, int $userId, bool $force = false): bool {
  $s = gc_email_settings($pdo);
  if (empty($s['toggles']['send_verify'])) return false;

  $u = gc_email_user_row($pdo, $userId);
  if (!$u) return false;
  $email = trim((string)($u['email'] ?? ''));
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

  if (!$force && gc_email_user_is_verified($u)) return true;

  // basic rate limit: 2 minutes between sends unless forced
  if (!$force && !empty($u['email_verify_sent_at'])) {
    $last = strtotime((string)$u['email_verify_sent_at']);
    if ($last && $last > (time() - 120)) return true;
  }

  $token = gc_email_make_verify_token();
  $st = $pdo->prepare("UPDATE users SET email_verify_token=?, email_verify_sent_at=NOW() WHERE id=?");
  $st->execute([$token, $userId]);

  $link = rtrim($s['base_url'], '/') . '/verify_email.php?token=' . urlencode($token);
  $uniq = 'verify:' . $userId . ':' . date('YmdHi');
  // Deduping: if mail is sent repeatedly due to retries, allow once per minute.
  gc_email_log_once($pdo, $uniq, $userId, $email, 'verify');

  $body = '<h2 style="margin:0 0 10px;">Verify your email</h2>'
    . '<p style="margin:0 0 12px;color:rgba(233,238,247,.88);">Please verify your email to activate your account.</p>'
    . '<p style="margin:0 0 14px;"><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" '
    . 'style="display:inline-block;background:#4f8cff;color:#fff;text-decoration:none;padding:12px 16px;border-radius:10px;">Verify Email</a></p>'
    . '<p style="margin:0;color:rgba(233,238,247,.75);font-size:12px;">Or paste this link into your browser:<br>'
    . '<span style="word-break:break-all;">' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></p>';

  $html = gc_email_wrap_html('Verify your email', $body);
  return gc_email_send_mail($pdo, $email, 'Verify your email', $html);
}

function gc_email_send_subscription(PDO $pdo, int $userId, string $planName, ?string $endsAt): bool {
  $s = gc_email_settings($pdo);
  if (empty($s['toggles']['send_subscription'])) return false;
  $u = gc_email_user_row($pdo, $userId);
  if (!$u) return false;
  $email = trim((string)($u['email'] ?? ''));
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

  $endsNice = 'Unlimited';
  if ($endsAt) {
    $ts = strtotime($endsAt);
    if ($ts) $endsNice = date('M j, Y g:ia', $ts);
  }

  $body = '<h2 style="margin:0 0 10px;">Subscription activated</h2>'
    . '<p style="margin:0 0 10px;color:rgba(233,238,247,.88);">Your subscription is now active.</p>'
    . '<p style="margin:0;color:rgba(233,238,247,.88);">Plan: <b>' . htmlspecialchars($planName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b></p>'
    . '<p style="margin:6px 0 0;color:rgba(233,238,247,.88);">Expires: <b>' . htmlspecialchars($endsNice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b></p>'
    . '<p style="margin:14px 0 0;color:rgba(233,238,247,.75);">Login: ' . htmlspecialchars(rtrim($s['base_url'], '/') . '/login.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

  $html = gc_email_wrap_html('Subscription activated', $body);
  return gc_email_send_mail($pdo, $email, 'Your subscription is active', $html);
}

function gc_email_send_expiry_reminder(PDO $pdo, int $userId, int $subId, string $planName, string $endsAt): bool {
  $s = gc_email_settings($pdo);
  if (empty($s['toggles']['send_expiry'])) return false;

  $u = gc_email_user_row($pdo, $userId);
  if (!$u) return false;
  $email = trim((string)($u['email'] ?? ''));
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

  $ts = strtotime($endsAt);
  if (!$ts) return false;
  $endsNice = date('M j, Y g:ia', $ts);

  $uniq = 'exprem:' . $subId . ':' . date('Ymd', $ts);
  if (!gc_email_log_once($pdo, $uniq, $userId, $email, 'expiry_reminder')) return false;

  $body = '<h2 style="margin:0 0 10px;">Subscription expiring soon</h2>'
    . '<p style="margin:0 0 10px;color:rgba(233,238,247,.88);">Your subscription will expire on <b>' . htmlspecialchars($endsNice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>.</p>'
    . '<p style="margin:0;color:rgba(233,238,247,.88);">Plan: <b>' . htmlspecialchars($planName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b></p>'
    . '<p style="margin:14px 0 0;color:rgba(233,238,247,.75);">Renew here: ' . htmlspecialchars(rtrim($s['base_url'], '/') . '/dashboard.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

  $html = gc_email_wrap_html('Subscription expiring', $body);
  return gc_email_send_mail($pdo, $email, 'Your subscription expires soon', $html);
}

function gc_email_imap_test(PDO $pdo): array {
  $s = gc_email_settings($pdo);
  $cfg = $s['imap'];
  $host = $cfg['host'];
  $user = $cfg['user'];
  $pass = $cfg['pass'];
  if ($host === '' || $user === '') {
    return ['ok'=>false,'error'=>'IMAP settings incomplete (host/username missing)'];
  }
  if ($pass === '') {
    // If a value exists in DB but cannot be decrypted (key changed), be explicit.
    $raw = (string)system_setting_get($pdo, 'imap_pass_enc', '');
    if ($raw !== '') {
      return ['ok'=>false,'error'=>'IMAP password cannot be decrypted. Re-enter and save the password in Settings.'];
    }
    return ['ok'=>false,'error'=>'IMAP settings incomplete (password missing)'];
  }
  if (!function_exists('imap_open')) {
    return ['ok'=>false,'error'=>'PHP IMAP extension not installed'];
  }

  $sec = $cfg['security'];
  $port = (int)$cfg['port'];
  $mailbox = $cfg['mailbox'] ?: 'INBOX';
  $flags = '';
  if ($sec === 'ssl') $flags = '/imap/ssl/novalidate-cert';
  elseif ($sec === 'tls') $flags = '/imap/tls/novalidate-cert';
  else $flags = '/imap/notls';

  $mbox = '{' . $host . ':' . $port . $flags . '}' . $mailbox;
  $timeout = (int)($cfg['timeout'] ?? 15);
  if (function_exists('imap_timeout')) {
    @imap_timeout(IMAP_OPENTIMEOUT, $timeout);
    @imap_timeout(IMAP_READTIMEOUT, $timeout);
    @imap_timeout(IMAP_WRITETIMEOUT, $timeout);
  }

  $inbox = @imap_open($mbox, $user, $pass);
  if ($inbox === false) {
    $err = function_exists('imap_last_error') ? (string)imap_last_error() : 'IMAP connect failed';
    return ['ok'=>false,'error'=>$err];
  }
  @imap_close($inbox);
  return ['ok'=>true,'error'=>''];
}
