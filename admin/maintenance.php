<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

$default_tpl = <<<'HTML'
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance</title>
  <style>
    :root{color-scheme:dark}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0b0f17;color:#e9eef7}
    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px}
    .card{max-width:860px;width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.45);overflow:hidden}
    .top{padding:28px 28px 10px}
    h1{margin:0 0 10px;font-size:30px;letter-spacing:.2px}
    p{margin:0;color:rgba(233,238,247,.82);line-height:1.55;font-size:16px}
    .bar{height:1px;background:rgba(255,255,255,.10)}
    .media{padding:18px 28px 28px}
    video{width:100%;max-height:420px;border-radius:14px;background:#000}
    .note{margin-top:12px;font-size:13px;color:rgba(233,238,247,.55)}
    .pill{display:inline-block;margin-top:14px;padding:8px 12px;border-radius:999px;background:rgba(108,162,255,.18);border:1px solid rgba(108,162,255,.30);color:#cfe1ff;font-size:13px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="top">
        <div class="pill">Maintenance Mode</div>
        <h1>We’ll be right back</h1>
        <p>{{message}}</p>
      </div>
      <div class="bar"></div>
      <div class="media">
        <video src="{{video_url_raw}}" controls autoplay muted playsinline></video>
        <div class="note">If the video is blank, your Maintenance Video URL is not set.</div>
      </div>
    </div>
  </div>
</body>
</html>
HTML;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  $enabled = isset($_POST['enabled']) ? '1' : '0';
  $message = trim((string)($_POST['message'] ?? ''));
  $video_url = trim((string)($_POST['video_url'] ?? ''));

  $use_custom = isset($_POST['use_custom_html']);
  $custom_html = (string)($_POST['custom_html'] ?? '');
  $custom_html = trim($custom_html);

  system_setting_set($pdo, 'maintenance_mode', $enabled);
  system_setting_set($pdo, 'maintenance_message', $message);
  system_setting_set($pdo, 'maintenance_video_url', $video_url);

  if ($use_custom && $custom_html !== '') {
    system_setting_set($pdo, 'maintenance_custom_html', $custom_html);
  } else {
    // Clear custom page => fall back to built-in template
    system_setting_set($pdo, 'maintenance_custom_html', null);
  }

  flash_set('Saved maintenance settings.', 'success');
  header('Location: maintenance.php');
  exit;
}

$enabled = (system_setting_get($pdo, 'maintenance_mode', '0') === '1');
$message = (string)system_setting_get($pdo, 'maintenance_message', 'Service is temporarily under maintenance. Please try again later.');
$video_url = trim((string)system_setting_get($pdo, 'maintenance_video_url', ''));
$custom_html = (string)system_setting_get($pdo, 'maintenance_custom_html', '');
$use_custom = (trim($custom_html) !== '');

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Admin Panel - Maintenance</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    textarea.code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 12.5px;
      line-height: 1.45;
      min-height: 340px;
      resize: vertical;
    }
    .row2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    @media (max-width: 900px) {
      .row2 { grid-template-columns: 1fr; }
    }
    .muted2 {
      opacity: .78;
      font-size: 12.5px;
    }
    .btnline {
      display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    }
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card">
  <div class="dash-head">
    <div class="dash-head-left">
      <h1 class="dash-title">Maintenance Mode</h1>
      <div class="dash-sub muted">When enabled, storefront + portal users will only see the maintenance page.</div>
    </div>
  </div>

  <?php flash_show(); ?>

  <form method="post">
    <?= csrf_input() ?>

    <div style="margin-bottom:12px;">
      <label style="display:flex;gap:8px;align-items:center;">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <span>Enable maintenance mode</span>
      </label>
    </div>

    <div class="row2" style="margin-bottom:12px;">
      <div>
        <label>Maintenance message (used for API / fallback)</label>
        <textarea name="message" rows="4" style="width:100%;"><?= e($message) ?></textarea>
      </div>
      <div>
        <label>Maintenance video URL (optional)</label>
        <input type="text" name="video_url" value="<?= e($video_url) ?>" placeholder="https://.../maintenance.mp4 or .m3u8" style="width:100%;">
        <div class="muted2" style="margin-top:6px;">If set, the default page can show this video and /get.php can redirect streams to it.</div>
        <?php if ($video_url !== ''): ?>
          <div style="margin-top:8px;"><a class="btn" target="_blank" rel="noopener" href="<?= e($video_url) ?>">Open</a></div>
        <?php endif; ?>
      </div>
    </div>

    <div style="margin-bottom:12px;">
      <label style="display:flex;gap:8px;align-items:center;">
        <input type="checkbox" id="use_custom_html" name="use_custom_html" value="1" <?= $use_custom ? 'checked' : '' ?>>
        <span>Use custom maintenance HTML page</span>
      </label>
      <div class="muted2" style="margin-top:6px;">
        Placeholders you can use: <b>{{message}}</b> (escaped), <b>{{video_url}}</b> (escaped), <b>{{message_raw}}</b> (raw), <b>{{video_url_raw}}</b> (raw)
      </div>
    </div>

    <div style="margin-bottom:12px;">
      <label>Custom maintenance HTML</label>
      <?php
        $prefill = $use_custom ? $custom_html : $default_tpl;
      ?>
      <textarea id="custom_html" class="code" name="custom_html" style="width:100%;" <?= $use_custom ? '' : 'disabled' ?>><?= e($prefill) ?></textarea>

      <div class="btnline" style="margin-top:10px;">
        <button type="button" class="btn" id="btnUseDefault">Load default template</button>
        <div class="muted2">Tip: enable the checkbox first, then edit.</div>
      </div>
    </div>

    <div class="btnline">
      <button type="submit" class="btn green">Save</button>
      <?php if ($enabled): ?>
        <span class="dash-chip">Currently: ON</span>
      <?php else: ?>
        <span class="dash-chip">Currently: OFF</span>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
(function() {
  var cb = document.getElementById('use_custom_html');
  var ta = document.getElementById('custom_html');
  var btn = document.getElementById('btnUseDefault');
  var defaultTpl = "<!doctype html>\n<html>\n<head>\n  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n  <title>Maintenance</title>\n  <style>\n    :root{color-scheme:dark}\n    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0b0f17;color:#e9eef7}\n    .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px}\n    .card{max-width:860px;width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);border-radius:18px;box-shadow:0 18px 60px rgba(0,0,0,.45);overflow:hidden}\n    .top{padding:28px 28px 10px}\n    h1{margin:0 0 10px;font-size:30px;letter-spacing:.2px}\n    p{margin:0;color:rgba(233,238,247,.82);line-height:1.55;font-size:16px}\n    .bar{height:1px;background:rgba(255,255,255,.10)}\n    .media{padding:18px 28px 28px}\n    video{width:100%;max-height:420px;border-radius:14px;background:#000}\n    .note{margin-top:12px;font-size:13px;color:rgba(233,238,247,.55)}\n    .pill{display:inline-block;margin-top:14px;padding:8px 12px;border-radius:999px;background:rgba(108,162,255,.18);border:1px solid rgba(108,162,255,.30);color:#cfe1ff;font-size:13px}\n  </style>\n</head>\n<body>\n  <div class=\"wrap\">\n    <div class=\"card\">\n      <div class=\"top\">\n        <div class=\"pill\">Maintenance Mode</div>\n        <h1>We\u2019ll be right back</h1>\n        <p>{{message}}</p>\n      </div>\n      <div class=\"bar\"></div>\n      <div class=\"media\">\n        <video src=\"{{video_url_raw}}\" controls autoplay muted playsinline></video>\n        <div class=\"note\">If the video is blank, your Maintenance Video URL is not set.</div>\n      </div>\n    </div>\n  </div>\n</body>\n</html>\n";
  function sync() {
    var on = cb && cb.checked;
    if (!ta) return;
    ta.disabled = !on;
    if (on && ta.value.trim() === '') {
      ta.value = defaultTpl;
    }
  }
  if (cb) cb.addEventListener('change', sync);
  if (btn) btn.addEventListener('click', function() {
    if (!cb || !ta) return;
    cb.checked = true;
    ta.disabled = false;
    ta.value = defaultTpl;
    ta.focus();
  });
  sync();
})();
</script>

</body>
</html>
