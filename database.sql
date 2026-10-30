-- ============================================================
-- Clovers' 动漫协会 数据库结构（完整版）
--
-- 用法：在宝塔「数据库」里建好库（例如 clovers，全小写），
--       在 phpMyAdmin 里【选中这个库】后导入本文件。
--       本文件不包含 CREATE DATABASE / USE。
--
-- 已经有数据的老站不用手动导入：访问一次
--   /api/migrate.php?key=你的DIAG_KEY
-- 会自动补字段、建新表（不会删数据）。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 用户
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nickname` VARCHAR(32) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password` VARCHAR(255) NOT NULL COMMENT 'password_hash 加密',
  `role` VARCHAR(16) NOT NULL DEFAULT 'user' COMMENT 'user / admin',
  `heat` DECIMAL(14,2) NOT NULL DEFAULT 1000.00 COMMENT '可用热力值',
  `total_earned` DECIMAL(14,2) NOT NULL DEFAULT 1000.00 COMMENT '累计获得（含初始）',
  `last_checkin` DATE NULL,
  `checkin_streak` INT NOT NULL DEFAULT 0,
  `signature` VARCHAR(30) NULL,
  `avatar` VARCHAR(255) NULL,
  `avatar_mime` VARCHAR(50) NULL,
  `departments` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 邮箱验证码
CREATE TABLE IF NOT EXISTS `email_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(120) NOT NULL,
  `code` VARCHAR(10) NOT NULL,
  `purpose` VARCHAR(20) NOT NULL DEFAULT 'register',
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email_purpose` (`email`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 留言板
CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `nickname` VARCHAR(32) NOT NULL,
  `content` VARCHAR(300) NOT NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_deleted` (`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 公告
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(120) NOT NULL,
  `content` TEXT NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `nickname` VARCHAR(32) NOT NULL DEFAULT '管理员',
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 投票
CREATE TABLE IF NOT EXISTS `polls` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(160) NOT NULL,
  `description` VARCHAR(500) NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_poll` (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id` INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_poll_user` (`poll_id`, `user_id`),
  KEY `idx_option` (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 社刊投稿
CREATE TABLE IF NOT EXISTS `submissions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `category` VARCHAR(30) NOT NULL DEFAULT '其他',
  `description` VARCHAR(1000) NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
  `mime` VARCHAR(100) NULL,
  `status` ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 行情
CREATE TABLE IF NOT EXISTS `market` (
  `symbol` VARCHAR(24) NOT NULL,
  `name` VARCHAR(40) NOT NULL,
  `icon` VARCHAR(8) NOT NULL DEFAULT '🍀',
  `base_price` DECIMAL(14,4) NOT NULL DEFAULT 1000.0000,
  `price` DECIMAL(14,4) NOT NULL DEFAULT 1000.0000,
  `anchor` DECIMAL(14,4) NULL COMMENT '慢速基本面锚',
  `vol` DECIMAL(20,12) NOT NULL DEFAULT 0.000000336100 COMMENT '每分钟方差',
  `last_shock` DECIMAL(20,12) NOT NULL DEFAULT 0,
  `spread_bps` DECIMAL(8,2) NOT NULL DEFAULT 6.00 COMMENT '点差（基点）',
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `market_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `symbol` VARCHAR(24) NOT NULL,
  `price` DECIMAL(14,4) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_symbol_id` (`symbol`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 持仓
CREATE TABLE IF NOT EXISTS `positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `symbol` VARCHAR(24) NOT NULL,
  `direction` ENUM('long','short') NOT NULL,
  `margin` DECIMAL(14,2) NOT NULL,
  `leverage` TINYINT UNSIGNED NOT NULL,
  `entry_price` DECIMAL(14,4) NOT NULL,
  `close_after` DATETIME NOT NULL,
  `status` ENUM('open','closed','liquidated') NOT NULL DEFAULT 'open',
  `exit_price` DECIMAL(14,4) NULL,
  `pnl` DECIMAL(14,2) NULL,
  `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME NULL,
  `close_reason` VARCHAR(32) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_symbol` (`status`, `symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 动态流
CREATE TABLE IF NOT EXISTS `activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `nickname` VARCHAR(32) NOT NULL,
  `type` VARCHAR(24) NOT NULL,
  `text` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(14,2) NULL,
  `symbol` VARCHAR(24) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 小游戏记录
CREATE TABLE IF NOT EXISTS `game_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `game` VARCHAR(20) NOT NULL DEFAULT 'clover',
  `score` INT NOT NULL,
  `heat` DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_game_user_time` (`game`, `user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 像素农场
CREATE TABLE IF NOT EXISTS `farm_plots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `plot_index` TINYINT UNSIGNED NOT NULL,
  `crop` VARCHAR(20) NOT NULL,
  `planted_at` DATETIME NOT NULL,
  `ready_at` DATETIME NOT NULL,
  `stolen` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_plot` (`user_id`, `plot_index`),
  KEY `idx_ready` (`ready_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `farm_steals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `thief_id` INT UNSIGNED NOT NULL,
  `owner_id` INT UNSIGNED NOT NULL,
  `plot_id` INT UNSIGNED NOT NULL,
  `crop` VARCHAR(20) NOT NULL,
  `heat` DECIMAL(14,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_thief_day` (`thief_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 番剧
CREATE TABLE IF NOT EXISTS `anime` (
  `id` INT UNSIGNED NOT NULL,
  `season` VARCHAR(8) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `title_cn` VARCHAR(160) NULL,
  `air_date` DATE NULL,
  `image` VARCHAR(255) NULL,
  `summary` TEXT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_season` (`season`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anime_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `nickname` VARCHAR(32) NOT NULL,
  `content` VARCHAR(500) NOT NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subject` (`subject_id`, `id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anime_ratings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_subject` (`user_id`, `subject_id`),
  KEY `idx_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 缓存 / 设置
CREATE TABLE IF NOT EXISTS `cache` (
  `k` VARCHAR(64) NOT NULL,
  `v` MEDIUMTEXT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始行情：每个部门基础热力值 1000
INSERT IGNORE INTO `market` (`symbol`, `name`, `icon`, `base_price`, `price`, `anchor`, `vol`, `updated_at`) VALUES
('xuanbian', '宣编部', '📝', 1000, 1000, 1000, 0.000000336100, NOW()),
('zhaiwu',   '宅舞部', '💃', 1000, 1000, 1000, 0.000000336100, NOW()),
('wota',     'WOTA艺部', '🎤', 1000, 1000, 1000, 0.000000336100, NOW()),
('zhuoyou',  '桌游部', '🎲', 1000, 1000, 1000, 0.000000336100, NOW());

SET FOREIGN_KEY_CHECKS = 1;
