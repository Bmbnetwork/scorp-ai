<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\DatabaseException;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

fwrite(STDOUT, "SCORP AI Phase 6 database migration\n");
fwrite(STDOUT, str_repeat('=', 38) . "\n\n");

try {
    $database = Database::fromEnv($env);
} catch (DatabaseException $exception) {
    fwrite(STDERR, "Database not configured. Set DB_* values in .env.\n");
    fwrite(STDERR, "Current issue: " . $exception->getMessage() . "\n");
    exit(2);
}

fwrite(STDOUT, "DSN host/db: " . $database->dsn() . "\n\n");

$statements = [
    'investigations' => <<<SQL
CREATE TABLE IF NOT EXISTS investigations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(64) NOT NULL,
    environment VARCHAR(32) NULL,
    endpoint VARCHAR(191) NULL,
    order_status VARCHAR(64) NULL,
    intent VARCHAR(64) NULL,
    amount_value VARCHAR(32) NULL,
    amount_currency VARCHAR(8) NULL,
    created_at VARCHAR(40) NULL,
    updated_at VARCHAR(40) NULL,
    retrieved_at VARCHAR(40) NULL,
    investigated_at VARCHAR(40) NULL,
    report_generated_at VARCHAR(40) NULL,
    purchase_unit_count INT UNSIGNED NOT NULL DEFAULT 0,
    capture_count INT UNSIGNED NOT NULL DEFAULT 0,
    captures_json MEDIUMTEXT NULL,
    report_state VARCHAR(32) NULL,
    ai_origin VARCHAR(40) NULL,
    ai_available TINYINT(1) NOT NULL DEFAULT 0,
    ai_model VARCHAR(80) NULL,
    ai_response_mode VARCHAR(20) NULL,
    ai_generated_at VARCHAR(40) NULL,
    ai_summary MEDIUMTEXT NULL,
    ai_facts_json MEDIUMTEXT NULL,
    ai_uncertainties_json MEDIUMTEXT NULL,
    ai_next_steps_json MEDIUMTEXT NULL,
    ai_limitations_json MEDIUMTEXT NULL,
    ai_error_code VARCHAR(80) NULL,
    ai_error_message MEDIUMTEXT NULL,
    rule_next_steps_json MEDIUMTEXT NULL,
    created_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reference (reference),
    KEY idx_created_timestamp (created_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
    'investigation_findings' => <<<SQL
CREATE TABLE IF NOT EXISTS investigation_findings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    investigation_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(80) NULL,
    severity VARCHAR(20) NULL,
    title VARCHAR(255) NULL,
    detail MEDIUMTEXT NULL,
    origin VARCHAR(40) NULL,
    evidence_json MEDIUMTEXT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_investigation_id (investigation_id),
    CONSTRAINT fk_findings_investigation
        FOREIGN KEY (investigation_id) REFERENCES investigations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL,
];

try {
    $pdo = $database->pdo();

    foreach ($statements as $name => $sql) {
        $pdo->exec($sql);
        fwrite(STDOUT, "OK: ensured table {$name}\n");
    }

    fwrite(STDOUT, "\nMigration complete. History is now available.\n");
} catch (DatabaseException $exception) {
    fwrite(STDERR, "\nMigration failed: " . $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "\nMigration failed due to a database error.\n");
    fwrite(STDERR, "Check that MySQL/MariaDB is running in XAMPP and DB_* values are correct.\n");
    exit(1);
}