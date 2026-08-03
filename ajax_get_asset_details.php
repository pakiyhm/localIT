<?php
// ajax_get_asset_details.php — API endpoint for QR Scanner
require_once 'includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$identifier = trim($_GET['id'] ?? '');
if (!$identifier) {
    echo json_encode(['success' => false, 'error' => 'Invalid Identifier']);
    exit;
}

try {
    // Fetch asset details by ID, SN, Hostname, Service Tag, or MAC Address
    $stmt = $pdo->prepare("
        SELECT a.*, l.location_name 
        FROM assets a
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE a.asset_id = :id OR a.serial_number = :id OR a.hostname = :id OR a.service_tag = :id OR a.mac_address = :id
    ");
    $stmt->execute(['id' => $identifier]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_accessory = false;

    if (!$asset) {
        $stmtAcc = $pdo->prepare("
            SELECT a.*, l.location_name 
            FROM accessories a
            LEFT JOIN locations l ON a.location_id = l.location_id
            WHERE a.accessory_id = :id OR a.serial_number = :id OR a.name = :id OR a.service_tag = :id OR a.mac_address = :id
        ");
        $stmtAcc->execute(['id' => $identifier]);
        $asset = $stmtAcc->fetch(PDO::FETCH_ASSOC);
        if ($asset) {
            $is_accessory = true;
        }
    }

    if (!$asset) {
        echo json_encode(['success' => false, 'error' => 'Asset not found']);
        exit;
    }

    $data = [
        'asset_id' => $is_accessory ? $asset['accessory_id'] : $asset['asset_id'],
        'category' => $asset['category'],
        'hostname' => $is_accessory ? $asset['name'] : $asset['hostname'],
        'model' => $asset['model'],
        'serial_number' => $asset['serial_number'],
        'service_tag' => $asset['service_tag'],
        'ownership_type' => $asset['ownership_type'],
        'status' => $asset['status'],
        'end_of_life_date' => $asset['end_of_life_date'] ? date('d M Y', strtotime($asset['end_of_life_date'])) : null,
        'is_expired' => $asset['end_of_life_date'] ? (strtotime($asset['end_of_life_date']) < time()) : false,
        'is_accessory' => $is_accessory,
        'parent_asset_id' => $is_accessory ? ($asset['parent_asset_id'] ?? null) : null,
        'owner' => null
    ];

    // Fetch owner details if assigned
    if ($asset['user_id']) {
        $uStmt = $pdo->prepare("
            SELECT u.*, loc.location_name as user_location
            FROM users u
            LEFT JOIN locations loc ON u.location_id = loc.location_id
            WHERE u.user_id = :uid
        ");
        $uStmt->execute(['uid' => $asset['user_id']]);
        $owner = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($owner) {
            // Fetch when this was assigned to them
            if ($is_accessory) {
                $logStmt = $pdo->prepare("
                    SELECT created_at 
                    FROM logs 
                    WHERE accessory_id = :aid AND action_type = 'assign' AND to_user_id = :uid 
                    ORDER BY created_at DESC LIMIT 1
                ");
                $logStmt->execute(['aid' => $asset['accessory_id'], 'uid' => $asset['user_id']]);
            } else {
                $logStmt = $pdo->prepare("
                    SELECT created_at 
                    FROM logs 
                    WHERE asset_id = :aid AND action_type = 'assign' AND to_user_id = :uid 
                    ORDER BY created_at DESC LIMIT 1
                ");
                $logStmt->execute(['aid' => $asset['asset_id'], 'uid' => $asset['user_id']]);
            }
            $start_using = $logStmt->fetchColumn();

            $data['owner'] = [
                'full_name' => $owner['full_name'],
                'emp_id' => $owner['emp_id'] ?? null,
                'department' => $owner['department'],
                'location' => $owner['user_location'],
                'start_using' => $start_using ? date('d M Y', strtotime($start_using)) : null
            ];
        }
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
