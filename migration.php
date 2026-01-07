<?php
// migration.php
// Lightweight runtime migrations (safe to call on each request).

function _db_name(PDO $pdo): ?string {
  try {
    $row = $pdo->query('SELECT DATABASE() AS d')->fetch(PDO::FETCH_ASSOC);
    return $row['d'] ?? null;
  } catch (Throwable $e) {
    return null;
  }
}

function _table_exists(PDO $pdo, string $table): bool {
  $db = _db_name($pdo);
  if (!$db) return false;
  $st = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
  $st->execute([$db, $table]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['c'] ?? 0) > 0;
}

function _col_exists(PDO $pdo, string $table, string $col): bool {
  $db = _db_name($pdo);
  if (!$db) return false;
  $st = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
  $st->execute([$db, $table, $col]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['c'] ?? 0) > 0;
}

function _idx_exists(PDO $pdo, string $table, string $indexName): bool {
  $db = _db_name($pdo);
  if (!$db) return false;
  $st = $pdo->prepare('SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?');
  $st->execute([$db, $table, $indexName]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return (int)($row['c'] ?? 0) > 0;
}

function _ensure_col(PDO $pdo, string $table, string $col, string $ddl): void {
  if (!_col_exists($pdo, $table, $col)) {
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
  }
}

function _ensure_index(PDO $pdo, string $table, string $indexName, string $ddl): void {
  if (!_idx_exists($pdo, $table, $indexName)) {
    $pdo->exec("ALTER TABLE `$table` ADD $ddl");
  }
}

/**
 * Run runtime migrations.
 */
function db_migrate(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  // --- New tables ---
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_categories_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS packages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_packages_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS package_channels (
      package_id INT NOT NULL,
      channel_id INT NOT NULL,
      PRIMARY KEY (package_id, channel_id),
      INDEX idx_pc_channel (channel_id),
      FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
      FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_packages (
      user_id INT NOT NULL,
      package_id INT NOT NULL,
      PRIMARY KEY (user_id, package_id),
      INDEX idx_up_package (package_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_devices (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      fingerprint VARCHAR(128) NOT NULL,
      first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      last_ip VARCHAR(45) DEFAULT NULL,
      UNIQUE KEY uniq_user_device (user_id, fingerprint),
      INDEX idx_user_devices_user (user_id),
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      user_id INT NULL,
      reseller_id INT NULL,
      ip VARCHAR(45) NULL,
      event VARCHAR(80) NOT NULL,
      meta_json TEXT NULL,
      INDEX idx_audit_created (created_at),
      INDEX idx_audit_user (user_id),
      INDEX idx_audit_event (event)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // High-volume request telemetry (API hits + stream starts). Keep this separate from audit_logs.
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS request_logs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      endpoint VARCHAR(64) NOT NULL,
      action VARCHAR(64) NULL,
      user_id INT NULL,
      reseller_id INT NULL,
      username VARCHAR(64) NULL,
      ip VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      device_fp VARCHAR(128) NULL,
      status_code SMALLINT NULL,
      duration_ms INT NULL,
      reason VARCHAR(64) NULL,
      meta_json TEXT NULL,
      INDEX idx_req_created (created_at),
      INDEX idx_req_ip (ip),
      INDEX idx_req_user (user_id),
      INDEX idx_req_endpoint (endpoint),
      INDEX idx_req_reason (reason)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Manual abuse bans (IP and/or user). Enforced by API + stream endpoints.
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS abuse_bans (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      ban_type ENUM('ip','user') NOT NULL,
      ip VARCHAR(45) NULL,
      user_id INT NULL,
      reason VARCHAR(255) NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NULL,
      INDEX idx_abuse_ip (ip),
      INDEX idx_abuse_user (user_id),
      INDEX idx_abuse_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // System settings key/value store (used for failover videos, etc.)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(190) PRIMARY KEY,
      setting_value TEXT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Outbound email log (dedupe reminders / prevent repeated sends)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS email_logs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL,
      email VARCHAR(190) NULL,
      type VARCHAR(64) NOT NULL,
      uniq_key VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_email_logs (uniq_key),
      INDEX idx_email_user (user_id),
      INDEX idx_email_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // XMLTV sources (upstream EPG providers). Used by xmltv.php proxy mode and importer.
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS epg_sources (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      xmltv_url TEXT NOT NULL,
      enabled TINYINT(1) DEFAULT 1,
      region_rules TEXT NULL,
      cache_ttl INT NOT NULL DEFAULT 21600,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");



  $pdo->exec("
    CREATE TABLE IF NOT EXISTS epg_programs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      channel_xmltv_id VARCHAR(255) NOT NULL,
      start_utc DATETIME NOT NULL,
      stop_utc DATETIME NOT NULL,
      title VARCHAR(255) NOT NULL,
      descr TEXT NULL,
      INDEX idx_epg_chan_time (channel_xmltv_id, start_utc, stop_utc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // --- EPG source extra fields (safe on older installs) ---
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS stream_health (
      channel_id INT PRIMARY KEY,
      last_ok TIMESTAMP NULL,
      last_fail TIMESTAMP NULL,
      fail_count INT NOT NULL DEFAULT 0,
      last_http INT NULL,
      last_error VARCHAR(255) NULL,
      FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // --- Columns ---
  _ensure_col($pdo, 'users', 'device_lock', 'device_lock TINYINT(1) NOT NULL DEFAULT 0');
  _ensure_col($pdo, 'users', 'ip_allowlist', 'ip_allowlist TEXT NULL');
  _ensure_col($pdo, 'users', 'ip_denylist', 'ip_denylist TEXT NULL');
  _ensure_col($pdo, 'users', 'max_ip_changes', 'max_ip_changes INT NULL');
  _ensure_col($pdo, 'users', 'max_ip_window',  'max_ip_window INT NULL');
  _ensure_col($pdo, 'users', 'tmdb_api_key',   'tmdb_api_key VARCHAR(128) NULL');
  _ensure_col($pdo, 'users', 'app_logo_url',   'app_logo_url VARCHAR(1024) NULL');
  _ensure_col($pdo, 'users', 'tmdb_region',    'tmdb_region VARCHAR(10) NULL');

  // User profile fields (optional)
  _ensure_col($pdo, 'users', 'name',  'name VARCHAR(190) NULL');
  _ensure_col($pdo, 'users', 'email', 'email VARCHAR(190) NULL');
  // Email verification (optional but can be enforced by settings)
  _ensure_col($pdo, 'users', 'email_verified_at', 'email_verified_at DATETIME NULL');
  _ensure_col($pdo, 'users', 'email_verify_token', 'email_verify_token VARCHAR(128) NULL');
  _ensure_col($pdo, 'users', 'email_verify_sent_at', 'email_verify_sent_at DATETIME NULL');
  _ensure_col($pdo, 'users', 'password_enc', 'password_enc TEXT NULL');
  // Reseller attribution (used for reseller dashboards + admin reporting)
  _ensure_col($pdo, 'users', 'reseller_id', 'reseller_id INT NULL');
  _ensure_index($pdo, 'users', 'idx_users_email', 'INDEX idx_users_email (email)');
  _ensure_index($pdo, 'users', 'idx_users_email_verify_token', 'INDEX idx_users_email_verify_token (email_verify_token)');
  _ensure_index($pdo, 'users', 'idx_users_reseller_id', 'INDEX idx_users_reseller_id (reseller_id)');

  /* ---------- EPG source options ---------- */
  _ensure_col($pdo, 'epg_sources', 'region_rules', 'region_rules TEXT NULL');
  _ensure_col($pdo, 'epg_sources', 'cache_ttl', 'cache_ttl INT NOT NULL DEFAULT 21600');

  /* ---------- Ordering (admin-defined sort) ---------- */
  _ensure_col($pdo, 'categories', 'sort_order', 'sort_order INT NOT NULL DEFAULT 0');
  _ensure_col($pdo, 'categories', 'is_adult', 'is_adult TINYINT(1) NOT NULL DEFAULT 0');

  _ensure_col($pdo, 'channels', 'category_id', 'category_id INT NULL');
  _ensure_col($pdo, 'channels', 'sort_order', 'sort_order INT NOT NULL DEFAULT 0');
  _ensure_col($pdo, 'channels', 'sources_json', 'sources_json TEXT NULL');

  _ensure_index($pdo, 'categories', 'idx_categories_sort', 'INDEX idx_categories_sort (sort_order, id)');
  _ensure_index($pdo, 'channels', 'idx_channels_cat_sort', 'INDEX idx_channels_cat_sort (category_id, sort_order, id)');

  // Backfill sort_order for existing rows (idempotent).
  $pdo->exec("UPDATE categories SET sort_order=id WHERE sort_order=0 OR sort_order IS NULL");
  $pdo->exec("UPDATE channels SET sort_order=id WHERE sort_order=0 OR sort_order IS NULL");

  _ensure_col($pdo, 'stream_sessions', 'device_fp', 'device_fp VARCHAR(128) NULL');
  _ensure_index($pdo, 'stream_sessions', 'idx_ss_user_chan', 'INDEX idx_ss_user_chan (user_id, channel_id)');
  _ensure_index($pdo, 'stream_sessions', 'idx_ss_user_last', 'INDEX idx_ss_user_last (user_id, last_seen)');
  /* ---------- VOD / SERIES (Xtream compatibility) ---------- */
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS vod_categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_vod_categories_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS movies (
      id INT AUTO_INCREMENT PRIMARY KEY,
      category_id INT NULL,
      name VARCHAR(255) NOT NULL,
      stream_url TEXT NOT NULL,
      poster_url VARCHAR(1024) NULL,
      backdrop_url VARCHAR(1024) NULL,
      plot TEXT NULL,
      release_date VARCHAR(32) NULL,
      rating DECIMAL(4,2) NULL,
      tmdb_id INT NULL,
      is_adult TINYINT(1) DEFAULT 0,
      container_ext VARCHAR(10) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_movies_cat (category_id),
      INDEX idx_movies_tmdb (tmdb_id),
      FOREIGN KEY (category_id) REFERENCES vod_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS series_categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_series_categories_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS series (
      id INT AUTO_INCREMENT PRIMARY KEY,
      category_id INT NULL,
      name VARCHAR(255) NOT NULL,
      cover_url VARCHAR(1024) NULL,
      backdrop_url VARCHAR(1024) NULL,
      plot TEXT NULL,
      release_date VARCHAR(32) NULL,
      rating DECIMAL(4,2) NULL,
      tmdb_id INT NULL,
      is_adult TINYINT(1) DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_series_cat (category_id),
      INDEX idx_series_tmdb (tmdb_id),
      FOREIGN KEY (category_id) REFERENCES series_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS series_episodes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      series_id INT NOT NULL,
      season_num INT NOT NULL DEFAULT 1,
      episode_num INT NOT NULL DEFAULT 1,
      title VARCHAR(255) NOT NULL,
      stream_url TEXT NOT NULL,
      container_ext VARCHAR(10) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ep_series (series_id, season_num, episode_num),
      FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  /* ---------- Package restrictions for VOD / Series ---------- */
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS package_movies (
      package_id INT NOT NULL,
      movie_id INT NOT NULL,
      PRIMARY KEY (package_id, movie_id),
      INDEX idx_pm_movie (movie_id),
      FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
      FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS package_series (
      package_id INT NOT NULL,
      series_id INT NOT NULL,
      PRIMARY KEY (package_id, series_id),
      INDEX idx_ps_series (series_id),
      FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
      FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  /* ---------- Plan / Reseller enforcement ---------- */
  _ensure_col($pdo, 'plans', 'reseller_credits_cost', 'reseller_credits_cost INT NOT NULL DEFAULT 1');
  _ensure_col($pdo, 'plans', 'max_devices', 'max_devices INT NOT NULL DEFAULT 2');

  _ensure_col($pdo, 'resellers', 'max_users', 'max_users INT NULL');
  _ensure_col($pdo, 'resellers', 'max_active_users', 'max_active_users INT NULL');
  _ensure_col($pdo, 'resellers', 'max_days_per_sub', 'max_days_per_sub INT NULL');

  /* ---------- Session kill + token rotation ---------- */
  _ensure_col($pdo, 'stream_sessions', 'killed_at', 'killed_at DATETIME NULL');
  _ensure_col($pdo, 'stream_sessions', 'session_token', 'session_token VARCHAR(64) NULL');
  _ensure_col($pdo, 'stream_sessions', 'stream_type', "stream_type VARCHAR(20) NOT NULL DEFAULT 'live'");
  _ensure_col($pdo, 'stream_sessions', 'item_id', 'item_id INT NULL');
  _ensure_index($pdo, 'stream_sessions', 'idx_ss_token', 'INDEX idx_ss_token (session_token)');
  _ensure_index($pdo, 'stream_sessions', 'idx_ss_type_item', 'INDEX idx_ss_type_item (stream_type, item_id)');

  // Per-user notes + tags
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_notes (
      user_id INT PRIMARY KEY,
      notes TEXT NULL,
      tags VARCHAR(255) NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_user_notes_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");


// Notifications (portal bell / support replies / expiring subscriptions)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    uniq_key VARCHAR(120) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_user_read (user_id, is_read, created_at),
    UNIQUE KEY uniq_notif_user_key (user_id, uniq_key),
    CONSTRAINT fk_notifications_user
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Admin Notifications (admin bell / admin alerts)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT NULL,
    link VARCHAR(255) NULL,
    uniq_key VARCHAR(120) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    INDEX idx_admin_notif_admin (admin_id),
    INDEX idx_admin_notif_admin_read (admin_id, is_read, created_at),
    UNIQUE KEY uniq_admin_notif_key (admin_id, uniq_key),
    CONSTRAINT fk_admin_notifications_admin
      FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
}
