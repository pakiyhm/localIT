<?php
// db/seed_dummy.php — Clear real data and insert dummy data for Git repository
require_once __DIR__ . '/../includes/db_connect.php';

echo "Cleaning existing data...\n";

// Disable foreign keys temporarily
$pdo->exec("PRAGMA foreign_keys = OFF;");

// Clear all tables
$tables = [
    'alert_notifications_sent', 'map_pin_positions', 'map_zones', 'maps',
    'asset_attachments', 'handover_slips',
    'software_license_logs', 'software_licenses', 'software_batches', 'software',
    'consumable_logs', 'incoming_orders',
    'logs', 'asset_relations', 'accessories', 'assets',
    'users', 'locations'
];

foreach ($tables as $t) {
    try {
        $pdo->exec("DELETE FROM $t");
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
    } catch (Exception $e) { /* ignore */ }
}
$pdo->exec("PRAGMA foreign_keys = ON;");

// Reset system_settings to defaults (keep structure, reset branding)
try {
    $pdo->exec("UPDATE system_settings SET setting_value = '123456' WHERE setting_key = 'edit_pin'");
    $pdo->exec("UPDATE system_settings SET setting_value = '60' WHERE setting_key = 'session_minutes'");
    $pdo->exec("UPDATE system_settings SET setting_value = 'IT Asset Management' WHERE setting_key = 'company_name'");
    $pdo->exec("UPDATE system_settings SET setting_value = 'IT Department' WHERE setting_key = 'company_dept'");
    $pdo->exec("UPDATE system_settings SET setting_value = 'IT ASSET TAG' WHERE setting_key = 'label_owner_text'");
    $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'company_logo'");
} catch (Exception $e) { /* ignore */ }

echo "Inserting dummy data...\n";

// ── Locations ──
$locs = ['Head Office - Floor 1', 'Head Office - Floor 2', 'Branch Office A', 'Server Room', 'Warehouse'];
foreach ($locs as $l) {
    $pdo->exec("INSERT INTO locations (location_name) VALUES ('$l')");
}

// ── Users ──
$users = [
    ['IT001', 'John Doe',      'IT',        'john.doe@example.com',      1],
    ['HR001', 'Jane Smith',    'HR',        'jane.smith@example.com',    2],
    ['FN001', 'Alice Johnson', 'Finance',   'alice.johnson@example.com', 1],
    ['MK001', 'Bob Brown',     'Marketing', 'bob.brown@example.com',     3],
    ['IT002', 'Charlie Davis', 'IT Support','charlie.davis@example.com', 1],
    ['EG001', 'David Wilson',  'Engineering','david.wilson@example.com', 2],
];
$stmtU = $pdo->prepare("INSERT INTO users (emp_id, full_name, department, email, location_id) VALUES (?,?,?,?,?)");
foreach ($users as $u) { $stmtU->execute($u); }

// ── Assets ──
$assets = [
    ['Laptop',  'LPT-IT001',   'SN-A0001', 'deployed', 'Buy',  'Dell Latitude 5540',  '2024', 'SVCTAG01', 1, 1, null, '2027-03-15'],
    ['Laptop',  'LPT-HR001',   'SN-A0002', 'deployed', 'Rent', 'HP EliteBook 840 G10','2024', 'SVCTAG02', 2, 2, date('Y-m-d', strtotime('+25 days')), '2026-12-31'],
    ['Desktop', 'DSK-FN001',   'SN-A0003', 'deployed', 'Buy',  'Lenovo ThinkCentre',  '2023', null,       3, 1, null, '2026-08-31'],
    ['Laptop',  'LPT-MK001',   'SN-A0004', 'deployed', 'Buy',  'Dell Latitude 7440',  '2024', 'SVCTAG04', 4, 3, null, '2027-06-30'],
    ['Switch',  'SW-CORE-01',  'SN-A0005', 'deployed', 'Buy',  'Cisco Catalyst 9200', '2022', null,       null, 4, null, '2025-12-31'],
    ['Laptop',  'LPT-SPARE01', 'SN-A0006', 'in_stock', 'Rent', 'HP ProBook 450 G10',  '2024', 'SVCTAG06', null, 5, date('Y-m-d', strtotime('+60 days')), null],
    ['Laptop',  'LPT-SPARE02', 'SN-A0007', 'in_stock', 'Buy',  'Dell Latitude 5550',  '2025', 'SVCTAG07', null, 5, null, null],
    ['Printer', 'PRT-F1-01',   'SN-A0008', 'deployed', 'Buy',  'Brother HL-L5200DW',  '2023', null,       null, 1, null, null],
    ['Server',  'SRV-DC-01',   'SN-A0009', 'deployed', 'Buy',  'Dell PowerEdge R750', '2022', 'SVCTAG09', null, 4, null, '2027-12-31'],
    ['Laptop',  'LPT-RPR01',   'SN-A0010', 'repair',   'Buy',  'Lenovo ThinkPad T14', '2023', null,       null, 1, null, null],
];
$stmtA = $pdo->prepare("INSERT INTO assets (category, hostname, serial_number, status, ownership_type, model, purchase_year, service_tag, user_id, location_id, lease_expiry, end_of_life_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
foreach ($assets as $a) { $stmtA->execute($a); }

// Set repair details for LPT-RPR01
$pdo->exec("UPDATE assets SET repair_shop='ABC Service Center', repair_sent_at='".date('Y-m-d', strtotime('-10 days'))."', repair_eta='".date('Y-m-d', strtotime('+5 days'))."', repair_note='Screen cracked' WHERE hostname='LPT-RPR01'");

// ── Accessories ──
$accs = [
    ['Monitor',        'Dell U2422H 24"',       'SN-ACC001', 'deployed', 'Buy', 'Dell U2422H',         1, 1, 1],
    ['Monitor',        'HP E24 G5',             'SN-ACC002', 'deployed', 'Buy', 'HP E24 G5',           2, 2, 2],
    ['Keyboard',       'Logitech MX Keys',      'SN-ACC003', 'deployed', 'Buy', 'MX Keys',             1, 1, null],
    ['Mouse',          'Logitech MX Master 3S', 'SN-ACC004', 'deployed', 'Buy', 'MX Master 3S',        1, 1, null],
    ['Docking Station','Dell WD19S',            'SN-ACC005', 'deployed', 'Buy', 'WD19S Thunderbolt',   3, 1, null],
    ['Headset',        'Jabra Evolve2 75',      'SN-ACC006', 'in_stock', 'Buy', 'Evolve2 75',          null, 5, null],
    ['Webcam',         'Logitech Brio 500',     'SN-ACC007', 'in_stock', 'Buy', 'Brio 500',            null, 5, null],
    ['Monitor',        'Samsung 27" FHD',       'SN-ACC008', 'in_stock', 'Buy', 'Samsung LS27',        null, 5, null],
];
$stmtAcc = $pdo->prepare("INSERT INTO accessories (category, name, serial_number, status, ownership_type, model, user_id, location_id, parent_asset_id) VALUES (?,?,?,?,?,?,?,?,?)");
foreach ($accs as $ac) { $stmtAcc->execute($ac); }

// ── Software & Licenses ──
$pdo->exec("INSERT INTO software (name, vendor, category) VALUES ('Microsoft 365 Business', 'Microsoft', 'Productivity')");
$pdo->exec("INSERT INTO software (name, vendor, category) VALUES ('AutoCAD LT', 'Autodesk', 'Design')");
$pdo->exec("INSERT INTO software (name, vendor, category) VALUES ('Adobe Creative Cloud', 'Adobe', 'Design')");

$pdo->exec("INSERT INTO software_batches (software_id, batch_name, license_type, purchase_order, purchase_date) VALUES (1, 'M365 Annual 2025', 'Per User', 'PO-2025-001', '2025-01-15')");
$pdo->exec("INSERT INTO software_batches (software_id, batch_name, license_type, purchase_order, purchase_date) VALUES (2, 'AutoCAD Q1 2025', 'Per User', 'PO-2025-002', '2025-03-01')");

// M365 licenses
$stmtLic = $pdo->prepare("INSERT INTO software_licenses (batch_id, license_key, status, start_date, expiry_date, user_id) VALUES (?,?,?,?,?,?)");
$stmtLic->execute([1, 'M365-KEY-001', 'assigned', '2025-01-15', '2026-01-14', 1]);
$stmtLic->execute([1, 'M365-KEY-002', 'assigned', '2025-01-15', '2026-01-14', 2]);
$stmtLic->execute([1, 'M365-KEY-003', 'assigned', '2025-01-15', '2026-01-14', 3]);
$stmtLic->execute([1, 'M365-KEY-004', 'active',   '2025-01-15', '2026-01-14', null]);

// AutoCAD licenses
$stmtLic->execute([2, 'ACAD-KEY-001', 'assigned', '2025-03-01', '2026-02-28', 6]);
$stmtLic->execute([2, 'ACAD-KEY-002', 'active',   '2025-03-01', '2026-02-28', null]);

// ── Consumable Logs ──
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('HDMI Cable 2m', 'in', 50, 1, 'Initial Stock')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('Toner TN-2480', 'in', 10, 1, 'Initial Stock')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('LAN Cable Cat6 3m', 'in', 100, 1, 'Initial Stock')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('HDMI Cable 2m', 'out', 5, 2, 'For HR department')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('Toner TN-2480', 'out', 2, 1, 'Replace printer Floor 1')");

// ── Sample Logs ──
$pdo->exec("INSERT INTO logs (asset_id, action_type, to_user_id, note, created_at) VALUES (1, 'assign', 1, 'Initial deployment', datetime('now', '-30 days'))");
$pdo->exec("INSERT INTO logs (asset_id, action_type, to_user_id, note, created_at) VALUES (2, 'assign', 2, 'New hire equipment', datetime('now', '-25 days'))");
$pdo->exec("INSERT INTO logs (asset_id, action_type, to_user_id, note, created_at) VALUES (3, 'assign', 3, 'Workstation setup', datetime('now', '-20 days'))");
$pdo->exec("INSERT INTO logs (asset_id, action_type, to_user_id, note, created_at) VALUES (4, 'assign', 4, 'Marketing laptop', datetime('now', '-15 days'))");
$pdo->exec("INSERT INTO logs (asset_id, action_type, note, created_at) VALUES (10, 'repair', 'Screen cracked - sent to ABC Service', datetime('now', '-10 days'))");
$pdo->exec("INSERT INTO logs (accessory_id, action_type, to_user_id, note, created_at) VALUES (1, 'assign', 1, 'Monitor for IT admin', datetime('now', '-30 days'))");
$pdo->exec("INSERT INTO logs (accessory_id, action_type, to_user_id, note, created_at) VALUES (2, 'assign', 2, 'Monitor for HR', datetime('now', '-25 days'))");

// ── Incoming Order ──
$pdo->exec("INSERT INTO incoming_orders (category, model, qty_expected, qty_received, vendor, purchase_order, ownership_type, ordered_date, eta_date, status, note)
            VALUES ('Laptop', 'Dell Latitude 5560', 3, 0, 'Dell Technologies', 'PO-2026-010', 'Buy', '".date('Y-m-d', strtotime('-5 days'))."', '".date('Y-m-d', strtotime('+10 days'))."', 'ordered', 'New hire batch Q3')");

$pdo->exec("VACUUM;");\n\necho "\n✅ Dummy data seeded successfully!\n";
echo "   - 5 Locations\n";
echo "   - 6 Users (with emails)\n";
echo "   - 10 Assets (2 spare, 1 repair)\n";
echo "   - 8 Accessories (3 spare)\n";
echo "   - 3 Software, 6 Licenses\n";
echo "   - 5 Consumable transactions\n";
echo "   - 7 Audit logs\n";
echo "   - 1 Incoming order\n";
echo "\n";
?>
