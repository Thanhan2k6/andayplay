<?php
/**
 * ANPLAY STORE - Idempotent Migration v5 Runner (--up / --down)
 * CLI-only. Defaults to local `anplay_staging` and refuses production or `game_library_db` unless explicitly overridden.
 *
 * Usage:
 *   php scripts/migrate_v5.php --up
 *   php scripts/migrate_v5.php --down
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$options = getopt('', ['up', 'down', 'db::', 'host::', 'user::', 'pass::']);
$isUp = isset($options['up']);
$isDown = isset($options['down']);

if ($isUp === $isDown) {
    fwrite(STDERR, "Usage: php scripts/migrate_v5.php [--up | --down] [--db=anplay_staging]\n");
    exit(1);
}

$host = $options['host'] ?? '127.0.0.1';
$user = $options['user'] ?? 'root';
$pass = $options['pass'] ?? '';
$dbName = $options['db'] ?? (getenv('ANPLAY_DB_NAME') ?: 'anplay_staging');

if (stripos($host, 'infinityfree') !== false || $dbName === 'if0_42898364_gamelib' || $dbName === 'game_library_db') {
    fwrite(STDERR, "[ABORT] Refusing to run against protected database '$dbName' on '$host'.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, $dbName);
$conn->set_charset('utf8mb4');
$conn->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

function columnExists(mysqli $conn, string $db, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $stmt->bind_param("sss", $db, $table, $column);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function indexExists(mysqli $conn, string $db, string $table, string $index): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
    $stmt->bind_param("sss", $db, $table, $index);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function fkExists(mysqli $conn, string $db, string $table, string $fk): bool {
    $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY' LIMIT 1");
    $stmt->bind_param("sss", $db, $table, $fk);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

if ($isUp) {
    echo "[UP] Running 002_schema_integrity_and_security.sql on '$dbName' (STRICT_ALL_TABLES)...\n";
    $sqlFile = __DIR__ . '/../includes/migrations/002_schema_integrity_and_security.sql';
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        fwrite(STDERR, "Failed to read $sqlFile\n");
        exit(3);
    }

    if ($conn->multi_query($sql)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
    if ($conn->errno) {
        fwrite(STDERR, "[ERROR] multi_query failed: " . $conn->error . "\n");
        exit(4);
    }

    // Upgrade any legacy plaintext passwords in staging users table
    $upgraded = 0;
    $resUsers = $conn->query("SELECT user_id, password FROM users WHERE password NOT LIKE '\$2y\$%' AND password NOT LIKE '\$2a\$%' AND password NOT LIKE '\$2b\$%' AND password NOT LIKE '\$argon2%'");
    while ($u = $resUsers->fetch_assoc()) {
        $hashed = password_hash($u['password'], PASSWORD_BCRYPT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $upd->bind_param("si", $hashed, $u['user_id']);
        $upd->execute();
        $upd->close();
        $upgraded++;
    }
    echo "[UP] Legacy plaintext passwords upgraded: $upgraded\n";
    echo "[UP] Completed successfully.\n";
} else {
    echo "[DOWN] Rolling back 002_schema_integrity_and_security on '$dbName' (STRICT_ALL_TABLES)...\n";

    $fks = [
        ['rental_packages', 'fk_rp_game'],
        ['game_accounts', 'fk_ga_game'],
        ['borrow_records', 'fk_br_user'],
        ['borrow_records', 'fk_br_game'],
        ['borrow_records', 'fk_br_account'],
        ['borrow_records', 'fk_br_package'],
        ['borrow_records', 'fk_br_prev'],
        ['comments', 'fk_comments_game'],
        ['comments', 'fk_comments_user'],
        ['notifications', 'fk_notif_user'],
        ['support_tickets', 'fk_st_user'],
        ['support_messages', 'fk_sm_ticket'],
        ['support_attachments', 'fk_sa_ticket'],
        ['user_permissions', 'fk_up_user'],
        ['user_permissions', 'fk_up_perm'],
        ['password_resets', 'fk_pr_user'],
    ];
    foreach ($fks as [$tbl, $fk]) {
        if (fkExists($conn, $dbName, $tbl, $fk)) {
            $conn->query("ALTER TABLE `$tbl` DROP FOREIGN KEY `$fk`");
        }
    }

    $indexes = [
        ['borrow_records', 'uq_borrow_payment_tx'],
        ['borrow_records', 'idx_borrow_status_due'],
        ['borrow_records', 'idx_borrow_user_game_status'],
        ['borrow_records', 'idx_borrow_prev'],
        ['borrow_records', 'idx_borrow_account'],
        ['borrow_records', 'idx_borrow_order_code'],
        ['borrow_records', 'idx_borrow_game'],
        ['borrow_records', 'idx_borrow_package'],
        ['comments', 'idx_comments_game'],
        ['comments', 'idx_comments_user'],
        ['site_visits', 'idx_visit_date'],
        ['games', 'idx_games_price_type'],
        ['games', 'idx_games_title'],
    ];
    foreach ($indexes as [$tbl, $idx]) {
        if (indexExists($conn, $dbName, $tbl, $idx)) {
            $conn->query("ALTER TABLE `$tbl` DROP INDEX `$idx`");
        }
    }

    $conn->query("DROP TABLE IF EXISTS `auth_rate_limits`");
    $conn->query("DROP TABLE IF EXISTS `password_resets`");

    if (columnExists($conn, $dbName, 'games', 'quantity')) {
        $conn->query("ALTER TABLE `games` DROP COLUMN `quantity`");
    }
    foreach (['two_factor_backup_codes', 'two_factor_secret', 'two_factor_enabled'] as $col) {
        if (columnExists($conn, $dbName, 'users', $col)) {
            $conn->query("ALTER TABLE `users` DROP COLUMN `$col`");
        }
    }
    foreach (['payment_tx_id', 'return_date'] as $col) {
        if (columnExists($conn, $dbName, 'borrow_records', $col)) {
            $conn->query("ALTER TABLE `borrow_records` DROP COLUMN `$col`");
        }
    }
    $conn->query("UPDATE `borrow_records` SET `due_date` = COALESCE(`due_date`, NOW()) WHERE `due_date` IS NULL");
    $conn->query("ALTER TABLE `borrow_records` MODIFY COLUMN `due_date` DATETIME NOT NULL");

    echo "[DOWN] Rollback completed successfully.\n";
}

$conn->close();
