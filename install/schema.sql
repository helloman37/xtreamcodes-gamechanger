CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  password_enc TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(190) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','suspended') DEFAULT 'active',
  allow_adult TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_email (email)
);

CREATE TABLE plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  duration_days INT NOT NULL DEFAULT 30,
  max_streams INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  plan_id INT NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status ENUM('active','expired','cancelled') DEFAULT 'active',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);

CREATE TABLE channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  group_title VARCHAR(255) DEFAULT NULL,
  tvg_id VARCHAR(255) DEFAULT NULL,
  tvg_name VARCHAR(255) DEFAULT NULL,
  tvg_logo VARCHAR(1024) DEFAULT NULL,
  stream_url TEXT NOT NULL,
  epg_url TEXT DEFAULT NULL,
  direct_play TINYINT(1) DEFAULT 0,
  container_ext VARCHAR(10) DEFAULT NULL,
  is_adult TINYINT(1) DEFAULT 0,
  last_checked_at DATETIME DEFAULT NULL,
  last_status_code INT DEFAULT NULL,
  works TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE epg_sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  xmltv_url TEXT NOT NULL,
  enabled TINYINT(1) DEFAULT 1,
  region_rules TEXT NULL,
  cache_ttl INT NOT NULL DEFAULT 21600,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- device/restream tracking
CREATE TABLE IF NOT EXISTS stream_sessions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  channel_id INT NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  last_seen DATETIME NOT NULL,
  INDEX(user_id),
  INDEX(last_seen)
);

-- Default admin (username: admin, password: admin123)
INSERT INTO admins (username, password_hash)
VALUES ('admin', '$2y$10$rJCms01vVFTESX7pLdB20.x0Y24XVO7N0uutIPw3hQVOkQpHP1xfS');


/* ---------- Resellers / Credits ---------- */
CREATE TABLE IF NOT EXISTS resellers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  credits INT NOT NULL DEFAULT 0,
  status ENUM('active','suspended') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS reseller_id INT DEFAULT NULL;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_reseller_id (reseller_id);

-- Optional FK (safe if engine supports it)
-- ALTER TABLE users
--   ADD CONSTRAINT fk_users_reseller
--   FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL;



CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  email VARCHAR(190) NOT NULL,
  plan_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
  provider ENUM('paypal','cashapp','stripe') NOT NULL,
  provider_txn VARCHAR(190) NOT NULL,
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  paid_at TIMESTAMP NULL,
  UNIQUE KEY uniq_provider_txn (provider, provider_txn)
);


ALTER TABLE subscriptions ADD COLUMN order_id INT NULL;
ALTER TABLE subscriptions ADD COLUMN source ENUM('admin','reseller','storefront') NOT NULL DEFAULT 'admin';

-- One-time IP-locked trial support
CREATE TABLE IF NOT EXISTS trial_claims (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  user_id INT NULL,
  claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ip (ip)
);

-- Optional plan flag:
ALTER TABLE plans ADD COLUMN is_trial TINYINT(1) DEFAULT 0;

-- Example 7-day trial plan (price 0)
INSERT INTO plans (name, price, duration_days, max_streams, is_trial)
VALUES ('Trial (7 Days)', 0.00, 7, 1, 1);
