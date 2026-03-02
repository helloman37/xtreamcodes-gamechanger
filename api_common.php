<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Create categories from channels.group_title (if categories empty),
 * and backfill channels.category_id. Stable IDs going forward.
 */
function ensure_categories(PDO $pdo): void {
  // Ensure expected columns exist on categories (older installs may lack these).
  // Safe no-op if they already exist.
  try {
    $hasAdult = (bool)$pdo->query("SHOW COLUMNS FROM categories LIKE 'is_adult'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasAdult) {
      $pdo->exec("ALTER TABLE categories ADD COLUMN is_adult TINYINT(1) NOT NULL DEFAULT 0");
    }
  } catch (Throwable $e) {
    // ignore (restricted DB perms / non-MySQL)
  }

  try {
    $hasSort = (bool)$pdo->query("SHOW COLUMNS FROM categories LIKE 'sort_order'")->fetch(PDO::FETCH_ASSOC);
    if (!$hasSort) {
      $pdo->exec("ALTER TABLE categories ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
    }
  } catch (Throwable $e) {
    // ignore
  }

  // Keep categories in sync with channels.group_title values. This is idempotent.
  // Treat NULL/empty/whitespace group titles as "Uncategorized".
  $groups = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(group_title),''),'Uncategorized') AS grp FROM channels ORDER BY grp")
    ->fetchAll(PDO::FETCH_ASSOC);

  $ins = $pdo->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
  foreach ($groups as $g) {
    $ins->execute([$g['grp']]);
  }

  // Ensure we always have an Uncategorized bucket.
  $ins->execute(['Uncategorized']);

  // Build name -> id map
  $map = [];
  $rows = $pdo->query("SELECT id,name FROM categories")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) $map[$r['name']] = (int)$r['id'];

  // Backfill channels.category_id where NULL/0 and normalize blank group_title to "Uncategorized"
  $st = $pdo->query("SELECT id, COALESCE(NULLIF(TRIM(group_title),''),'Uncategorized') AS grp FROM channels WHERE category_id IS NULL OR category_id=0");
  $upd = $pdo->prepare("UPDATE channels SET category_id=?, group_title=? WHERE id=?");

  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $grp = $row['grp'] ?? 'Uncategorized';
    $cid = $map[$grp] ?? null;

    // If a category name slipped in after the initial map build, insert it and refresh just-in-time.
    if (!$cid && $grp !== '') {
      try {
        $ins->execute([$grp]);
        $cid = (int)($pdo->query("SELECT id FROM categories WHERE name=" . $pdo->quote($grp) . " LIMIT 1")->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
        if ($cid) $map[$grp] = $cid;
      } catch (Throwable $e) {}
    }

    if ($cid) {
      $upd->execute([$cid, $grp, (int)$row['id']]);
    }
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
