<?php
require_once __DIR__ . '/_init.php';
$PORTAL_PAGE = 'watchlist';
require_once __DIR__ . '/_layout_top.php';

$plugin = __DIR__ . '/../plugins/watchlist/portal_watchlist.php';
if (!is_file($plugin)) {
  echo '<div class="card notice">Watchlist plugin is not installed.</div>';
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}
require $plugin;

require_once __DIR__ . '/_layout_bottom.php';
