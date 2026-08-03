<?php
// db/seed_dummy.php — Clear real data and insert dummy data for portfolio
require_once __DIR__ . '/../includes/db_connect.php';

echo "Cleaning existing data...\n";

// Disable foreign keys temporarily
$pdo->exec("PRAGMA foreign_keys = OFF;");

// Clear all tables
$tables = [
    'users', 'locations', 'assets', 'asset_relations', 'logs', 
    'consumable_logs', 'accessories', 'maps', 'map_zones', 'map_pin_positions',
    'software', 'software_batches', 'software_licenses', 'software_license_logs',
    'incoming_orders', 'asset_attachments'
];

foreach ($tables as $t) {
    try {
        $pdo->exec("DELETE FROM $t");
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name='$t'"); // reset autoincrement
    } catch (Exception $e) {
        // ignore if table doesn't exist
    }
}
$pdo->exec("PRAGMA foreign_keys = ON;");

echo "Inserting dummy data...\n";

// Dummy locations
$pdo->exec("INSERT INTO locations (location_name) VALUES ('Head Office - Floor 1')");
$pdo->exec("INSERT INTO locations (location_name) VALUES ('Head Office - Floor 2')");
$pdo->exec("INSERT INTO locations (location_name) VALUES ('Branch A')");
$pdo->exec("INSERT INTO locations (location_name) VALUES ('Server Room')");

// Dummy users
$users = [
    ['emp_id' => 'IT001', 'full_name' => 'John Doe (IT Admin)', 'department' => 'IT', 'location_id' => 1],
    ['emp_id' => 'HR001', 'full_name' => 'Jane Smith', 'department' => 'HR', 'location_id' => 2],
    ['emp_id' => 'FN001', 'full_name' => 'Alice Johnson', 'department' => 'Finance', 'location_id' => 1],
    ['emp_id' => 'MK001', 'full_name' => 'Bob Brown', 'department' => 'Marketing', 'location_id' => 3],
    ['emp_id' => 'IT002', 'full_name' => 'Charlie Davis', 'department' => 'IT Support', 'location_id' => 1],
];
$stmtUser = $pdo->prepare("INSERT INTO users (emp_id, full_name, department, location_id) VALUES (?, ?, ?, ?)");
foreach ($users as $u) {
    $stmtUser->execute([$u['emp_id'], $u['full_name'], $u['department'], $u['location_id']]);
}

// Dummy assets
$assets = [
    ['category' => 'Laptop', 'hostname' => 'LPT-IT001', 'serial_number' => 'SN-LPT001', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => 1, 'location_id' => 1],
    ['category' => 'Laptop', 'hostname' => 'LPT-HR001', 'serial_number' => 'SN-LPT002', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => 2, 'location_id' => 2],
    ['category' => 'Desktop', 'hostname' => 'DSK-FN001', 'serial_number' => 'SN-DSK001', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => 3, 'location_id' => 1],
    ['category' => 'Switch', 'hostname' => 'SW-CORE-01', 'serial_number' => 'SN-SW001', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => null, 'location_id' => 4],
    ['category' => 'Laptop', 'hostname' => 'LPT-SPARE01', 'serial_number' => 'SN-LPT003', 'status' => 'in_stock', 'ownership_type' => 'Rent', 'user_id' => null, 'location_id' => 1],
];
$stmtAsset = $pdo->prepare("INSERT INTO assets (category, hostname, serial_number, status, ownership_type, user_id, location_id, lease_expiry) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($assets as $a) {
    $lease_expiry = ($a['ownership_type'] === 'Rent') ? date('Y-m-d', strtotime('+30 days')) : null;
    $stmtAsset->execute([$a['category'], $a['hostname'], $a['serial_number'], $a['status'], $a['ownership_type'], $a['user_id'], $a['location_id'], $lease_expiry]);
}

// Dummy accessories
$accessories = [
    ['category' => 'Monitor', 'name' => 'Dell 24"', 'serial_number' => 'SN-MON001', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => 1, 'parent_asset_id' => 1],
    ['category' => 'Monitor', 'name' => 'HP 27"', 'serial_number' => 'SN-MON002', 'status' => 'in_stock', 'ownership_type' => 'Buy', 'user_id' => null, 'parent_asset_id' => null],
    ['category' => 'Keyboard', 'name' => 'Logitech MX Keys', 'serial_number' => 'SN-KB001', 'status' => 'deployed', 'ownership_type' => 'Buy', 'user_id' => 2, 'parent_asset_id' => 2],
];
$stmtAcc = $pdo->prepare("INSERT INTO accessories (category, name, serial_number, status, ownership_type, user_id, parent_asset_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ($accessories as $ac) {
    $stmtAcc->execute([$ac['category'], $ac['name'], $ac['serial_number'], $ac['status'], $ac['ownership_type'], $ac['user_id'], $ac['parent_asset_id']]);
}

// Dummy consumable logs
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('HDMI Cable', 'in', 50, 1, 'Initial Stock')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('Toner TN-2480', 'in', 10, 1, 'Initial Stock')");
$pdo->exec("INSERT INTO consumable_logs (item_name, action, quantity, user_id, note) VALUES ('HDMI Cable', 'out', 2, 2, 'For HR Dept')");

echo "Dummy data seeded successfully!\n";
?>
