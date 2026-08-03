<?php
// init_db.php
require_once 'includes/db_connect.php';
require_once 'includes/column_helper.php';

$schema = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    emp_id TEXT UNIQUE,
    full_name TEXT NOT NULL,
    department TEXT,
    email TEXT,
    location_id INTEGER,
    FOREIGN KEY (location_id) REFERENCES locations(location_id)
);

CREATE TABLE IF NOT EXISTS locations (
    location_id INTEGER PRIMARY KEY AUTOINCREMENT,
    location_name TEXT UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS assets (
    asset_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL, 
    hostname TEXT UNIQUE, 
    serial_number TEXT UNIQUE, 
    status TEXT CHECK(status IN ('in_stock', 'deployed', 'repair', 'retired', 'lost')) DEFAULT 'in_stock',
    ownership_type TEXT CHECK(ownership_type IN ('Buy', 'Rent', 'Borrow')),
    lease_start DATE,
    lease_expiry DATE,
    model TEXT,
    purchase_year INTEGER,
    service_tag TEXT,
    mac_address TEXT,
    purchase_date DATE,
    end_of_life_date DATE,
    vendor_return_date DATE,
    vendor_return_to TEXT,
    vendor_return_method TEXT,
    note TEXT,
    po_number TEXT,
    user_id INTEGER,
    location_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id)
);

CREATE TABLE IF NOT EXISTS asset_relations (
    relation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_asset_id INTEGER NOT NULL,
    child_asset_id INTEGER UNIQUE NOT NULL, 
    FOREIGN KEY (parent_asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (child_asset_id) REFERENCES assets(asset_id)
);

CREATE TABLE IF NOT EXISTS logs (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER,
    accessory_id INTEGER,
    action_type TEXT CHECK(action_type IN ('assign', 'return', 'repair', 'retire', 'update', 'import')),
    from_user_id INTEGER,
    to_user_id INTEGER,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id),
    FOREIGN KEY (accessory_id) REFERENCES accessories(accessory_id)
);

CREATE TABLE IF NOT EXISTS maps (
    map_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    image_path TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS map_zones (
    zone_id INTEGER PRIMARY KEY AUTOINCREMENT,
    map_id INTEGER NOT NULL,
    location_id INTEGER NOT NULL,
    polygon_json TEXT NOT NULL,
    FOREIGN KEY (map_id) REFERENCES maps(map_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id)
);

CREATE TABLE IF NOT EXISTS consumable_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    item_name TEXT NOT NULL,
    action TEXT CHECK(action IN ('in', 'out', 'audit')),
    quantity INTEGER NOT NULL,
    user_id INTEGER,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS accessories (
    accessory_id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    name TEXT NOT NULL,
    serial_number TEXT UNIQUE,
    status TEXT CHECK(status IN ('in_stock', 'deployed', 'repair', 'retired', 'lost')) DEFAULT 'in_stock',
    ownership_type TEXT CHECK(ownership_type IN ('Buy', 'Rent', 'Borrow')),
    model TEXT,
    purchase_year INTEGER,
    service_tag TEXT,
    mac_address TEXT,
    purchase_date DATE,
    end_of_life_date DATE,
    vendor_return_date DATE,
    vendor_return_to TEXT,
    vendor_return_method TEXT,
    user_id INTEGER,
    location_id INTEGER,
    parent_asset_id INTEGER,
    note TEXT,
    po_number TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id),
    FOREIGN KEY (parent_asset_id) REFERENCES assets(asset_id)
);

CREATE TABLE IF NOT EXISTS column_settings (
    col_key     TEXT PRIMARY KEY,
    col_label   TEXT NOT NULL,
    col_visible INTEGER NOT NULL DEFAULT 1,
    col_order   INTEGER NOT NULL DEFAULT 999,
    is_system   INTEGER NOT NULL DEFAULT 1,
    col_type    TEXT NOT NULL DEFAULT 'text',
    is_advanced INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS map_pin_positions (
    pin_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    map_id      INTEGER NOT NULL,
    group_key   TEXT NOT NULL,
    location_id INTEGER NOT NULL,
    pin_x       REAL NOT NULL,
    pin_y       REAL NOT NULL,
    UNIQUE(map_id, group_key),
    FOREIGN KEY (map_id) REFERENCES maps(map_id),
    FOREIGN KEY (location_id) REFERENCES locations(location_id)
);

CREATE TABLE IF NOT EXISTS software (
    software_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    vendor      TEXT,
    category    TEXT,
    note        TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS software_batches (
    batch_id     INTEGER PRIMARY KEY AUTOINCREMENT,
    software_id  INTEGER NOT NULL,
    batch_name   TEXT NOT NULL,
    license_type TEXT CHECK(license_type IN ('Per User','Per Device','Site','Volume')) DEFAULT 'Per User',
    purchase_order TEXT,
    purchase_date  DATE,
    note         TEXT,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (software_id) REFERENCES software(software_id)
);

CREATE TABLE IF NOT EXISTS software_licenses (
    license_id  INTEGER PRIMARY KEY AUTOINCREMENT,
    batch_id    INTEGER NOT NULL,
    license_key TEXT,
    status      TEXT CHECK(status IN ('active','assigned','expired','revoked')) DEFAULT 'active',
    start_date  DATE,
    expiry_date DATE,
    user_id     INTEGER,
    note        TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES software_batches(batch_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS software_license_logs (
    log_id      INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id  INTEGER NOT NULL,
    action      TEXT CHECK(action IN ('create','assign','unassign','renew','edit','revoke')),
    field_name  TEXT,
    old_value   TEXT,
    new_value   TEXT,
    note        TEXT,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (license_id) REFERENCES software_licenses(license_id)
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL
);
SQL;

try {
    // Create Tables
    $pdo->exec($schema);

    // ── Migrations for existing databases ──
    // Add email column to users if it doesn't exist
    $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('email', $colNames)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT");
    }

    // Add accessory_id column to logs if it doesn't exist
    $cols = $pdo->query("PRAGMA table_info(logs)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('accessory_id', $colNames)) {
        $pdo->exec("ALTER TABLE logs ADD COLUMN accessory_id INTEGER REFERENCES accessories(accessory_id)");
    }

    // Add advanced tracking columns to assets if they don't exist
    $cols = $pdo->query("PRAGMA table_info(assets)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('model', $colNames)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN model TEXT");
        $pdo->exec("ALTER TABLE assets ADD COLUMN purchase_year INTEGER");
        $pdo->exec("ALTER TABLE assets ADD COLUMN service_tag TEXT");
        $pdo->exec("ALTER TABLE assets ADD COLUMN mac_address TEXT");
        $pdo->exec("ALTER TABLE assets ADD COLUMN purchase_date DATE");
        $pdo->exec("ALTER TABLE assets ADD COLUMN end_of_life_date DATE");
    }
    if (!in_array('vendor_return_date', $colNames)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN vendor_return_date DATE");
        $pdo->exec("ALTER TABLE assets ADD COLUMN vendor_return_to TEXT");
        $pdo->exec("ALTER TABLE assets ADD COLUMN vendor_return_method TEXT");
    }
    if (!in_array('note', $colNames)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN note TEXT");
    }

    // Add advanced tracking columns to accessories if they don't exist
    $cols = $pdo->query("PRAGMA table_info(accessories)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('model', $colNames)) {
        $pdo->exec("ALTER TABLE accessories ADD COLUMN model TEXT");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN purchase_year INTEGER");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN service_tag TEXT");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN mac_address TEXT");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN purchase_date DATE");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN end_of_life_date DATE");
    }
    if (!in_array('vendor_return_date', $colNames)) {
        $pdo->exec("ALTER TABLE accessories ADD COLUMN vendor_return_date DATE");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN vendor_return_to TEXT");
        $pdo->exec("ALTER TABLE accessories ADD COLUMN vendor_return_method TEXT");
    }

    // Add po_number to assets and accessories
    $cols = $pdo->query("PRAGMA table_info(assets)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('po_number', $colNames)) {
        $pdo->exec("ALTER TABLE assets ADD COLUMN po_number TEXT");
    }
    
    $cols = $pdo->query("PRAGMA table_info(accessories)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    if (!in_array('po_number', $colNames)) {
        $pdo->exec("ALTER TABLE accessories ADD COLUMN po_number TEXT");
    }

    // Make asset_id nullable in logs (for accessory-only logs) — SQLite can't alter columns,
    // but new rows can insert NULL if the column allows it (no NOT NULL constraint after migration)

    // Add po_number to column_settings if missing
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM column_settings WHERE col_key = 'po_number'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO column_settings (col_key, col_label, col_visible, col_order, is_system, col_type, is_advanced) VALUES ('po_number', 'Batch / PO Number', 0, 131, 1, 'text', 1)");
    }

    // ── column_settings: seed default column definitions ──
    seedColumnSettings($pdo);

    // Check if we need to insert mock data (only run once)
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    if ($stmt->fetchColumn() > 0) {
        echo "<div style='font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #d1e7dd; color: #0f5132; border-radius: 8px; border: 1px solid #badbcc;'>
                <h3 style='margin-top: 0;'>✅ Initialization Successful</h3>
                <p>The database schema has been successfully generated. The system is now ready for production data.</p>
                <a href='index.php' style='color: #0f5132; font-weight: bold;'>Go to Dashboard &rarr;</a>
              </div>";
    }

} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}
?>
