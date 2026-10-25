-- Bitcoin dashboard schema. MySQL 8.0+ / MariaDB 10.5+.
-- All observation dates are UTC. No synthetic weekend values or interpolation.
-- This migration creates tables only. It does not import or claim historical data.

CREATE TABLE IF NOT EXISTS bitcoin_data_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(96) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    provider_type VARCHAR(64) NOT NULL,
    base_url VARCHAR(1024) NULL,
    license_note TEXT NULL,
    attribution_text TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bitcoin_source_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_metrics (
    metric_id VARCHAR(96) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    category VARCHAR(32) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    unit VARCHAR(64) NOT NULL,
    source_hint VARCHAR(255) NOT NULL,
    source_series VARCHAR(512) NULL,
    description TEXT NOT NULL,
    methodology_note TEXT NULL,
    native_frequency ENUM('daily','weekly','monthly','event') NOT NULL,
    observation_kind ENUM('daily_close','period_sum','period_average','period_end','point_in_time','derived') NULL,
    calendar_type ENUM('calendar_daily','market_weekdays','native_periods') NULL,
    timezone_name VARCHAR(64) NOT NULL DEFAULT 'UTC',
    expected_start DATE NULL,
    coverage_start DATE NULL,
    coverage_end DATE NULL,
    observation_count INT UNSIGNED NOT NULL DEFAULT 0,
    availability_status ENUM('planned','ready','partial','unavailable') NOT NULL DEFAULT 'planned',
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_overview TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (metric_id),
    KEY ix_bitcoin_metrics_category (category, display_order),
    KEY ix_bitcoin_metrics_source (source_id),
    KEY ix_bitcoin_metrics_status (availability_status),
    CONSTRAINT fk_bitcoin_metric_source FOREIGN KEY (source_id) REFERENCES bitcoin_data_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_metric_dependencies (
    metric_id VARCHAR(96) NOT NULL,
    input_metric_id VARCHAR(96) NOT NULL,
    calculation_note TEXT NOT NULL,
    PRIMARY KEY (metric_id, input_metric_id),
    CONSTRAINT fk_bitcoin_dependency_result FOREIGN KEY (metric_id) REFERENCES bitcoin_metrics(metric_id),
    CONSTRAINT fk_bitcoin_dependency_input FOREIGN KEY (input_metric_id) REFERENCES bitcoin_metrics(metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_metric_series (
    metric_id VARCHAR(96) NOT NULL,
    series_key VARCHAR(96) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    native_frequency ENUM('daily','weekly','monthly','event') NULL,
    calendar_type ENUM('calendar_daily','market_weekdays','native_periods') NULL,
    expected_start DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (metric_id, series_key),
    CONSTRAINT fk_bitcoin_series_metric FOREIGN KEY (metric_id) REFERENCES bitcoin_metrics(metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    metric_id VARCHAR(96) NOT NULL,
    series_key VARCHAR(96) NOT NULL DEFAULT 'main',
    source_id BIGINT UNSIGNED NULL,
    observation_date DATE NOT NULL,
    value DECIMAL(36,12) NOT NULL,
    source_timestamp DATETIME NULL,
    fetched_at DATETIME NOT NULL,
    quality_status ENUM('valid','provisional','revised','flagged') NOT NULL DEFAULT 'provisional',
    source_revision VARCHAR(128) NULL,
    raw_payload_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bitcoin_metric_series_date (metric_id, series_key, observation_date),
    KEY ix_bitcoin_observations_date (observation_date),
    KEY ix_bitcoin_observations_source (source_id),
    CONSTRAINT fk_bitcoin_observation_series FOREIGN KEY (metric_id, series_key) REFERENCES bitcoin_metric_series(metric_id, series_key),
    CONSTRAINT fk_bitcoin_observation_source FOREIGN KEY (source_id) REFERENCES bitcoin_data_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_sync_state (
    metric_id VARCHAR(96) NOT NULL,
    latest_observation_date DATE NULL,
    backfill_cursor_date DATE NULL,
    last_checked_at DATETIME NULL,
    last_successful_sync_at DATETIME NULL,
    next_check_at DATETIME NULL,
    lock_token CHAR(36) NULL,
    lock_until DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_at DATETIME NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (metric_id),
    CONSTRAINT fk_bitcoin_sync_metric FOREIGN KEY (metric_id) REFERENCES bitcoin_metrics(metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_sync_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trigger_type ENUM('cron','manual','initial_backfill','gap_repair') NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    status ENUM('running','success','partial','failed') NOT NULL,
    metrics_checked INT UNSIGNED NOT NULL DEFAULT 0,
    metrics_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
    metrics_failed INT UNSIGNED NOT NULL DEFAULT 0,
    rows_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    rows_updated INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_bitcoin_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitcoin_data_quality (
    metric_id VARCHAR(96) NOT NULL,
    series_key VARCHAR(96) NOT NULL DEFAULT 'main',
    expected_start DATE NULL,
    expected_end DATE NULL,
    observation_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    missing_period_count INT UNSIGNED NOT NULL DEFAULT 0,
    flagged_count INT UNSIGNED NOT NULL DEFAULT 0,
    coverage_start DATE NULL,
    coverage_end DATE NULL,
    notes TEXT NULL,
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (metric_id, series_key),
    CONSTRAINT fk_bitcoin_quality_series FOREIGN KEY (metric_id, series_key) REFERENCES bitcoin_metric_series(metric_id, series_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
