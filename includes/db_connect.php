<?php
// includes/db_connect.php
require_once __DIR__ . '/lang.php';
date_default_timezone_set('Asia/Bangkok');

$db_dir = __DIR__ . '/../db';
$db_file = $db_dir . '/it_assets.db';

// Ensure the db directory exists
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0755, true);
}

try {
    // Connect to local SQLite file
    $pdo = new PDO("sqlite:" . $db_file);
    
    // Set Exception error mode for security and debugging
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Strict Data Integrity: Enforce foreign keys
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Self-Healing Migration: Ensure 'note' column exists in critical tables
    // This fixes "no such column: note" errors during bulk actions or edits
    try {
        $tablesToFix = ['logs', 'assets', 'accessories'];
        foreach ($tablesToFix as $tbl) {
            $cols = $pdo->query("PRAGMA table_info($tbl)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($cols) && !in_array('note', $cols)) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN note TEXT");
            }
        }
    } catch (Exception $e) { /* Silent fail */ }

    // Self-Healing Migration: Create software license tables if missing
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('software', $tables)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS software (software_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, vendor TEXT, category TEXT, note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        }
        if (!in_array('software_batches', $tables)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS software_batches (batch_id INTEGER PRIMARY KEY AUTOINCREMENT, software_id INTEGER NOT NULL, batch_name TEXT NOT NULL, license_type TEXT CHECK(license_type IN ('Per User','Per Device','Site','Volume')) DEFAULT 'Per User', purchase_order TEXT, purchase_date DATE, note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (software_id) REFERENCES software(software_id))");
        }
        if (!in_array('software_licenses', $tables)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS software_licenses (license_id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER NOT NULL, license_key TEXT, status TEXT CHECK(status IN ('active','assigned','expired','revoked')) DEFAULT 'active', start_date DATE, expiry_date DATE, user_id INTEGER, note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (batch_id) REFERENCES software_batches(batch_id), FOREIGN KEY (user_id) REFERENCES users(user_id))");
        }
        if (!in_array('software_license_logs', $tables)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS software_license_logs (log_id INTEGER PRIMARY KEY AUTOINCREMENT, license_id INTEGER NOT NULL, action TEXT CHECK(action IN ('create','assign','unassign','renew','edit','revoke')), field_name TEXT, old_value TEXT, new_value TEXT, note TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (license_id) REFERENCES software_licenses(license_id))");
        }
    } catch (Exception $e) { /* Silent fail */ }

    // Self-Healing Migration: Create system_settings table if missing
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)");
        // Seed defaults if empty
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
        if ($cnt === 0) {
            $defaults = [
                ['lease_alert_days', '30'],
                ['warranty_alert_days', '30'],
                ['software_alert_days', '30'],
            ];
            $ins = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)");
            foreach ($defaults as [$k, $v]) {
                $ins->execute(['k' => $k, 'v' => $v]);
            }
        }
    } catch (Exception $e) { /* Silent fail */ }
    
    // Table for tracking sent alerts to prevent duplicates
    $pdo->exec("CREATE TABLE IF NOT EXISTS alert_notifications_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_type TEXT NOT NULL,
        item_id INTEGER NOT NULL,
        round_days INTEGER NOT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(item_type, item_id, round_days)
    )");

    // Self-Healing Migration: soft-delete (resignation) columns on users
    try {
        $userCols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!empty($userCols) && !in_array('resigned_at', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN resigned_at DATE");
        }
        if (!empty($userCols) && !in_array('resign_note', $userCols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN resign_note TEXT");
        }
    } catch (Exception $e) { /* silent */ }

    // Self-Healing Migration: borrow due date columns on assets & accessories
    try {
        foreach (['assets', 'accessories'] as $tbl) {
            $cols = $pdo->query("PRAGMA table_info($tbl)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($cols) && !in_array('borrow_due_date', $cols)) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN borrow_due_date DATE");
            }
            if (!empty($cols) && !in_array('borrowed_at', $cols)) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN borrowed_at DATETIME");
            }
        }
    } catch (Exception $e) { /* silent */ }

    // Self-Healing Migration: repair tracking columns on assets & accessories
    try {
        foreach (['assets', 'accessories'] as $tbl) {
            $cols = $pdo->query("PRAGMA table_info($tbl)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!empty($cols) && !in_array('repair_shop', $cols)) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN repair_shop TEXT");
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN repair_sent_at DATE");
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN repair_eta DATE");
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN repair_note TEXT");
            }
        }
    } catch (Exception $e) { /* silent */ }

    // Self-Healing Migration: incoming orders table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS incoming_orders (
            order_id        INTEGER PRIMARY KEY AUTOINCREMENT,
            category        TEXT NOT NULL,
            model           TEXT,
            qty_expected    INTEGER NOT NULL DEFAULT 1,
            qty_received    INTEGER NOT NULL DEFAULT 0,
            vendor          TEXT,
            purchase_order  TEXT,
            ownership_type  TEXT CHECK(ownership_type IN ('Buy','Rent','Borrow')) DEFAULT 'Buy',
            ordered_date    DATE,
            eta_date        DATE,
            status          TEXT CHECK(status IN ('ordered','partial','received','cancelled')) DEFAULT 'ordered',
            note            TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) { /* silent */ }

    // Self-Healing Migration: asset attachments table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_attachments (
            attachment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            asset_id      INTEGER,
            accessory_id  INTEGER,
            filename      TEXT NOT NULL,
            stored_name   TEXT NOT NULL,
            mime_type     TEXT,
            size_bytes    INTEGER,
            note          TEXT,
            uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
            FOREIGN KEY (accessory_id) REFERENCES accessories(accessory_id)
        )");
    } catch (Exception $e) { /* silent */ }

    // Self-Healing Migration: seed PIN + base URL system_settings if missing
    try {
        $defaults = [
            ['edit_pin', '123456'],
            ['session_minutes', '60'],
            ['qr_base_url', ''],
        ];
        $ins = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)");
        foreach ($defaults as [$k, $v]) {
            $ins->execute(['k' => $k, 'v' => $v]);
        }
    } catch (Exception $e) { /* silent */ }

    // Start session for auth (must be before any output)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
