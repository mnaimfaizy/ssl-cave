-- SSL Cave v1 schema (MySQL/MariaDB)
-- Do not store certificate private keys here.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL,
    `value` TEXT NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS domains (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    cpanel_expiry DATE NULL,
    acme_expiry DATE NULL,
    deploy_hook TINYINT(1) NOT NULL DEFAULT 0,
    webroot VARCHAR(512) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    notes TEXT NULL,
    in_cpanel TINYINT(1) NOT NULL DEFAULT 0,
    in_acme TINYINT(1) NOT NULL DEFAULT 0,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_domains_domain (domain),
    KEY idx_domains_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    action VARCHAR(16) NOT NULL,
    payload JSON NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    stdout MEDIUMTEXT NULL,
    stderr MEDIUMTEXT NULL,
    exit_code INT NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_jobs_status (status),
    KEY idx_jobs_domain (domain),
    KEY idx_jobs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alert_sent (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    domain VARCHAR(255) NOT NULL,
    alert_type VARCHAR(64) NOT NULL,
    ref_key VARCHAR(128) NOT NULL,
    sent_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert (domain, alert_type, ref_key),
    KEY idx_alert_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
