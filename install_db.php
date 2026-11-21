<?php
// Automatic first-run install: if the database is empty, create all tables and the starter data.
// Runs by itself from db() in lib.php, so no manual setup step is needed.
function ensure_installed(): void {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) return;

    // Only one visitor installs; others wait, then see it is already done.
    $pdo->query("SELECT GET_LOCK('ecoconnect_install', 60)")->fetchColumn();
    try {
        if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) return;
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->exec(ECO_SCHEMA_SQL);

        $bid = (int)$pdo->query("SELECT id FROM barangays WHERE name = 'Tiniguiban'")->fetchColumn();
        $cat = (int)$pdo->query("SELECT id FROM event_categories WHERE name = 'General Cleanup'")->fetchColumn();
        $add = $pdo->prepare("INSERT INTO users (username,password,first_name,last_name,phone_number,role,barangay_id,is_verified) VALUES (?,?,?,?,?,?,?,1)");
        $add->execute(['official', password_hash('Official123!', PASSWORD_DEFAULT), 'Official', 'Account', '09000000001', 'admin', $bid]);
        $officialId = (int)$pdo->lastInsertId();
        $add->execute(['resident', password_hash('Resident123!', PASSWORD_DEFAULT), 'Resident', 'Account', '09000000002', 'resident', $bid]);
        $pdo->prepare("INSERT INTO events (barangay_id, organizer_id, category_id, title, description, location, date, time, points_reward) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$bid, $officialId, $cat, 'Barangay Clean-up Drive', 'Bring gloves and a sack. Meet at the barangay hall.', 'Barangay Hall', date('Y-m-d', strtotime('+7 days')), '07:00:00', 20]);
        $pdo->prepare("INSERT INTO rewards (barangay_id, name, points_cost, stock, max_per_claim, icon) VALUES (?,?,?,?,?,?)")
            ->execute([$bid, 'Reusable tote bag', 30, 20, 2, "\u{1F45C}"]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ecoconnect_install')")->fetchColumn();
    }
}

const ECO_SCHEMA_SQL = <<<'SQL'
-- EcoConnect (PHP + MySQL edition)
-- Import with phpMyAdmin (Import tab) or:  mysql -u root < ecoconnect.sql
-- Table design follows the EcoConnect Django models (barangays, users, events, ...).



SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `otp_codes`, `transfer_requests`, `point_awards`, `reward_claims`, `rewards`,
  `event_participants`, `events`, `event_categories`, `users`, `barangays`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `barangays` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `city` VARCHAR(60) NOT NULL DEFAULT 'Puerto Princesa City',
  `is_participating` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barangay_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(80) NOT NULL DEFAULT '',
  `last_name` VARCHAR(80) NOT NULL DEFAULT '',
  `email` VARCHAR(254) NULL,
  `phone_number` VARCHAR(20) NOT NULL,
  `role` ENUM('resident','admin') NOT NULL DEFAULT 'resident',   -- admin = Barangay Official
  `barangay_id` INT NULL,
  `points` INT NOT NULL DEFAULT 0,
  `peak_points` INT NOT NULL DEFAULT 0,
  `title` VARCHAR(50) NULL,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `id_image` VARCHAR(500) NULL,
  `profile_picture` VARCHAR(500) NULL,
  `date_joined` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username` (`username`),
  UNIQUE KEY `uq_user_phone` (`phone_number`),
  CONSTRAINT `fk_users_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `event_categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE `events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `barangay_id` INT NOT NULL,
  `organizer_id` INT NULL,
  `category_id` INT NOT NULL,
  `title` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `location` VARCHAR(200) NOT NULL,
  `date` DATE NOT NULL,
  `time` TIME NOT NULL,
  `points_reward` INT UNSIGNED NOT NULL DEFAULT 10,
  `max_participants` INT UNSIGNED NULL,
  `status` ENUM('upcoming','completed','cancelled') NOT NULL DEFAULT 'upcoming',
  `completed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_events_date` (`date`, `time`),
  CONSTRAINT `fk_events_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_events_organizer` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_events_category` FOREIGN KEY (`category_id`) REFERENCES `event_categories` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `event_participants` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `event_id` INT NOT NULL,
  `checked_in_by` INT NULL,
  `status` ENUM('joined','attended') NOT NULL DEFAULT 'joined',
  `ticket_code` CHAR(8) NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `attended_at` DATETIME NULL,
  `checkin_method` ENUM('qr','code','manual') NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `one_ticket_per_resident_per_event` (`user_id`, `event_id`),
  UNIQUE KEY `uq_ticket_code` (`ticket_code`),
  CONSTRAINT `fk_part_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_part_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_part_checker` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `rewards` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `barangay_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `points_cost` INT UNSIGNED NOT NULL DEFAULT 50,
  `stock` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_per_claim` INT UNSIGNED NOT NULL DEFAULT 1,
  `icon` VARCHAR(10) NOT NULL DEFAULT '🎁',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rewards_barangay` FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `reward_claims` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `reward_id` INT NULL,
  `claimed_by` INT NULL,
  `item_name` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `points_spent` INT UNSIGNED NOT NULL,
  `status` ENUM('Pending','Claimed','Rejected') NOT NULL DEFAULT 'Pending',  -- Pending = reserved
  `claim_code` CHAR(8) NULL,
  `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_claim_code` (`claim_code`),
  CONSTRAINT `fk_claims_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_claims_reward` FOREIGN KEY (`reward_id`) REFERENCES `rewards` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_claims_official` FOREIGN KEY (`claimed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `point_awards` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `awarded_by` INT NULL,
  `points` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL DEFAULT 'Direct award by official',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_awards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_awards_by` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `transfer_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `source_barangay_id` INT NOT NULL,
  `target_barangay_id` INT NOT NULL,
  `decided_by` INT NULL,
  `reason` TEXT NULL,
  `status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_tr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tr_source` FOREIGN KEY (`source_barangay_id`) REFERENCES `barangays` (`id`),
  CONSTRAINT `fk_tr_target` FOREIGN KEY (`target_barangay_id`) REFERENCES `barangays` (`id`),
  CONSTRAINT `fk_tr_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `otp_codes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `phone_number` VARCHAR(20) NULL,
  `email` VARCHAR(120) NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `purpose` ENUM('signup','reset') NOT NULL DEFAULT 'reset',
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Reference data (same as the Django seed migration)
INSERT INTO `barangays` (`name`) VALUES ('Santa Monica'), ('Tiniguiban'), ('Tagburos');
INSERT INTO `event_categories` (`name`) VALUES
  ('General Cleanup'), ('Coastal Cleanup'), ('Tree Planting'), ('River Cleanup'),
  ('Waste Segregation'), ('Mangrove Planting'), ('Urban Gardening');
SQL;
