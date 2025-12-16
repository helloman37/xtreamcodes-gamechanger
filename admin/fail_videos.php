<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Settings keys
// URLs can point to any player-reachable media: .mp4, .m3u8 (HLS), or .ts.
// For best compatibility, most IPTV apps prefer HLS (.m3u8) for Live.
$fields = [
  // LIVE
  'fail_video_live_invalid_login' => ['Live: Invalid login', 'Video URL (.mp4/.m3u8/.ts) played when username/password/token is invalid.'],
  'fail_video_live_expired'       => ['Live: Expired subscription', 'Video URL (.mp4/.m3u8/.ts) played when subscription is expired / inactive.'],
  'fail_video_live_banned'        => ['Live: Blocked/banned', 'Video URL (.mp4/.m3u8/.ts) played when the request is blocked (ban / IP deny).'],
  'fail_video_live_limit'         => ['Live: Limit reached', 'Video URL (.mp4/.m3u8/.ts) played when max connections/devices is reached.'],
  'fail_video_live_offline'       => ['Live: Channel offline', 'Video URL (.mp4/.m3u8/.ts) played when the channel upstream is offline/unreachable (dead link, 4xx/5xx, timeout).'],

  // VOD / Series
  'fail_video_vod_invalid_login'  => ['VOD/Series: Invalid login', 'Video URL (.mp4/.m3u8/.ts) played when username/password/token is invalid.'],
  'fail_video_vod_expired'        => ['VOD/Series: Expired subscription', 'Video URL (.mp4/.m3u8/.ts) played when subscription is expired / inactive.'],
  'fail_video_vod_banned'         => ['VOD/Series: Blocked/banned', 'Video URL (.mp4/.m3u8/.ts) played when the request is blocked (ban / IP deny).'],
  'fail_video_vod_limit'          => ['VOD/Series: Limit reached', 'Video URL (.mp4/.m3u8/.ts) played when max connections/devices is reached.'],
  'fail_video_vod_offline'        => ['VOD/Series: Source offline', 'Video URL (.mp4/.m3u8/.ts) played when the VOD/episode source is offline/unreachable (dead link, 4xx/5xx, timeout).'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  foreach ($fields as $key => $meta) {
    $val = trim((string)($_POST[$key] ?? ''));
    // Empty => remove setting
    system_setting_set($pdo, $key, $val);
  }

  audit_log('system_fail_videos_update', null, []);
  flash_set('Fail videos saved.', 'success');
  header('Location: fail_videos.php');
  exit;
}

// Load current values
$values = [];
foreach ($fields as $key => $meta) {
  $values[$key] = (string)system_setting_get($pdo, $key, '');
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Fail Videos</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
    .hint{opacity:.75;font-size:12px;margin-top:6px}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-size:12px}
    .muted{opacity:.8}
    @media(max-width:900px){.grid2{grid-template-columns:1fr}}
    input[type=text]{width:100%}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .row a.btn{display:inline-block}
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Custom Fail Videos</h2>
  <?php flash_show(); ?>

  <p class="muted">
    When a stream request fails (invalid login, expired subscription, ban, connection/device limit, or source offline),
    the server can <span class="pill">redirect</span> the player to a video URL instead of returning a text error.
  </p>

  <div class="card" style="margin:12px 0;">
    <div class="muted">
      <div><span class="pill">Formats</span> You can use <b>.mp4</b>, <b>.m3u8</b> (HLS), or <b>.ts</b> URLs.</div>
      <div style="margin-top:6px"><span class="pill">Tip</span> For Live, most IPTV apps work best with <b>HLS (.m3u8)</b>, but .ts or .mp4 may work depending on the player.</div>
      <div style="margin-top:6px">Leave a field blank to disable that redirect and keep the default error behavior.</div>
    </div>
  </div>

  <form method="post">
    <?= csrf_input() ?>

    <div class="grid2">
      <div>
        <h3>Live</h3>

        <?php foreach ($fields as $key => $meta): ?>
          <?php if (str_starts_with($key, 'fail_video_live_')): ?>
            <label><?= e($meta[0]) ?></label>
            <input type="text" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>" placeholder="https://.../fail.m3u8 (or .ts / .mp4)">
            <div class="hint"><?= e($meta[1]) ?></div>
            <?php if ($values[$key] !== ''): ?>
              <div class="row" style="margin:8px 0 16px 0">
                <a class="btn" target="_blank" rel="noopener" href="<?= e($values[$key]) ?>">Open</a>
              </div>
            <?php else: ?>
              <div style="height:10px"></div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>

      </div>

      <div>
        <h3>VOD / Series</h3>

        <?php foreach ($fields as $key => $meta): ?>
          <?php if (str_starts_with($key, 'fail_video_vod_')): ?>
            <label><?= e($meta[0]) ?></label>
            <input type="text" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>" placeholder="https://.../fail.mp4 (or .m3u8 / .ts)">
            <div class="hint"><?= e($meta[1]) ?></div>
            <?php if ($values[$key] !== ''): ?>
              <div class="row" style="margin:8px 0 16px 0">
                <a class="btn" target="_blank" rel="noopener" href="<?= e($values[$key]) ?>">Open</a>
              </div>
            <?php else: ?>
              <div style="height:10px"></div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>

      </div>
    </div>

    <button class="btn" type="submit" style="margin-top:12px">Save</button>
  </form>
</div>

</div></main></div>
</body>
</html>
