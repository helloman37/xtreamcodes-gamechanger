<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Create categories from channels.group_title (if categories empty),
 * and backfill channels.category_id. Stable IDs going forward.
 */
function ensure_categories(PDO $pdo): void {
  // Keep categories in sync with group_title values. This is idempotent.
  $groups = $pdo->query("SELECT DISTINCT IFNULL(group_title,'Uncategorized') AS grp FROM channels ORDER BY grp")
    ->fetchAll(PDO::FETCH_ASSOC);
  $ins = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
  foreach ($groups as $g) {
    $ins->execute([$g['grp']]);
  }

  // Ensure we always have an Uncategorized bucket.
  $ins->execute(['Uncategorized']);

  // Backfill channels.category_id where NULL
  $map = [];
  $rows = $pdo->query("SELECT id,name FROM categories")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) $map[$r['name']] = (int)$r['id'];

  $st = $pdo->query("SELECT id, IFNULL(group_title,'Uncategorized') AS grp FROM channels WHERE category_id IS NULL");
  $upd = $pdo->prepare("UPDATE channels SET category_id=? WHERE id=?");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $cid = $map[$row['grp']] ?? null;
    if ($cid) $upd->execute([$cid, (int)$row['id']]);
  }
}

/**
 * Returns user package IDs (empty array => no restriction).
 */
function user_package_ids(PDO $pdo, int $user_id): array {
  $st = $pdo->prepare("SELECT package_id FROM user_packages WHERE user_id=?");
  $st->execute([$user_id]);
  $ids = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ids[] = (int)$r['package_id'];
  return $ids;
}

/**
 * Builds SQL filter clause for package restrictions.
 */
function package_filter_sql(array $package_ids, string $channels_alias='c'): array {
  if (!$package_ids) return ['', []];
  $in = implode(',', array_fill(0, count($package_ids), '?'));
  $sql = " AND EXISTS (SELECT 1 FROM package_channels pc WHERE pc.channel_id={$channels_alias}.id AND pc.package_id IN ($in)) ";
  return [$sql, $package_ids];
}

/**
 * Builds SQL filter clause for VOD/movie restrictions.
 */
function package_filter_sql_movies(array $package_ids, string $movies_alias='m'): array {
  if (!$package_ids) return ['', []];
  $in = implode(',', array_fill(0, count($package_ids), '?'));
  $sql = " AND EXISTS (SELECT 1 FROM package_movies pm WHERE pm.movie_id={$movies_alias}.id AND pm.package_id IN ($in)) ";
  return [$sql, $package_ids];
}

/**
 * Builds SQL filter clause for Series restrictions.
 */
function package_filter_sql_series(array $package_ids, string $series_alias='s'): array {
  if (!$package_ids) return ['', []];
  $in = implode(',', array_fill(0, count($package_ids), '?'));
  $sql = " AND EXISTS (SELECT 1 FROM package_series ps WHERE ps.series_id={$series_alias}.id AND ps.package_id IN ($in)) ";
  return [$sql, $package_ids];
}
