<?php
// export_handler.php — Handles CSV generation
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_edit(); // Prevent anonymous CSV download

$type = $_GET['type'] ?? '';
if (!$type) {
    die("Invalid export type.");
}

// Note: schema migration (service_tag, model columns) is handled by db_connect.php self-healing.

// Function to output clean CSV
function outputCSV($filename, $headers, $rows) {
    if (ob_get_length()) ob_clean(); // Discard any stray HTML or whitespace
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output BOM for Excel UTF-8 support
    echo "\xEF\xBB\xBF";
    
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ',', '"', "\\");
    foreach ($rows as $row) {
        fputcsv($out, $row, ',', '"', "\\");
    }
    fclose($out);
    exit;
}

$standardHeaders = ['Record Type', 'Category', 'Identifier (Hostname/Name)', 'Model', 'Serial No.', 'Service Tag', 'MAC Address', 'Purchase Year', 'Purchase Date', 'End of Life', 'Batch / PO Number', 'Status', 'Ownership Type', 'Assigned To', 'Location', 'Note'];

if ($type === 'full_inventory') {
    // Both Assets and Accessories
    $assets = $pdo->query("
        SELECT 'Asset' as RecordType, a.category as Category, a.hostname as Identifier, a.model as Model, a.serial_number as SerialNumber, a.service_tag as ServiceTag, a.mac_address as MacAddress, a.purchase_year as PurchaseYear, a.purchase_date as PurchaseDate, a.end_of_life_date as EndOfLife, a.po_number as PoNumber,
               a.status as Status, a.ownership_type as Ownership, u.full_name as AssignedTo, l.location_name as Location, a.note as Note
        FROM assets a
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN locations l ON a.location_id = l.location_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $accessories = $pdo->query("
        SELECT 'Accessory' as RecordType, ac.category as Category, ac.name as Identifier, ac.model as Model, ac.serial_number as SerialNumber, ac.service_tag as ServiceTag, ac.mac_address as MacAddress, ac.purchase_year as PurchaseYear, ac.purchase_date as PurchaseDate, ac.end_of_life_date as EndOfLife, ac.po_number as PoNumber,
               ac.status as Status, ac.ownership_type as Ownership, u.full_name as AssignedTo, l.location_name as Location, ac.note as Note
        FROM accessories ac
        LEFT JOIN users u ON ac.user_id = u.user_id
        LEFT JOIN locations l ON ac.location_id = l.location_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $data = array_merge($assets, $accessories);
    // Sort by RecordType then Category
    usort($data, function($a, $b) {
        if ($a['RecordType'] === $b['RecordType']) {
            return strcmp($a['Category'], $b['Category']);
        }
        return strcmp($a['RecordType'], $b['RecordType']);
    });

    outputCSV("Inventory_Export_" . date('Ymd') . ".csv", $standardHeaders, $data);

} elseif ($type === 'by_category') {
    $cat = $_GET['category'] ?? '';
    if (!$cat) die("Category required.");
    
    $data = [];
    if ($cat === 'ALL_ASSETS') {
        $data = $pdo->query("
            SELECT 'Asset', a.category, a.hostname, a.model, a.serial_number, a.service_tag, a.mac_address, a.purchase_year, a.purchase_date, a.end_of_life_date, a.po_number, a.status, a.ownership_type, u.full_name, l.location_name, a.note
            FROM assets a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN locations l ON a.location_id = l.location_id
        ")->fetchAll(PDO::FETCH_NUM);
    } elseif ($cat === 'ALL_ACCESSORIES') {
        $data = $pdo->query("
            SELECT 'Accessory', ac.category, ac.name, ac.model, ac.serial_number, ac.service_tag, ac.mac_address, ac.purchase_year, ac.purchase_date, ac.end_of_life_date, ac.po_number, ac.status, ac.ownership_type, u.full_name, l.location_name, ac.note
            FROM accessories ac LEFT JOIN users u ON ac.user_id = u.user_id LEFT JOIN locations l ON ac.location_id = l.location_id
        ")->fetchAll(PDO::FETCH_NUM);
    } else {
        $stmtA = $pdo->prepare("SELECT 'Asset', a.category, a.hostname, a.model, a.serial_number, a.service_tag, a.mac_address, a.purchase_year, a.purchase_date, a.end_of_life_date, a.po_number, a.status, a.ownership_type, u.full_name, l.location_name, a.note FROM assets a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN locations l ON a.location_id = l.location_id WHERE a.category = :c");
        $stmtA->execute(['c' => $cat]);
        $dataA = $stmtA->fetchAll(PDO::FETCH_NUM);

        $stmtB = $pdo->prepare("SELECT 'Accessory', ac.category, ac.name, ac.model, ac.serial_number, ac.service_tag, ac.mac_address, ac.purchase_year, ac.purchase_date, ac.end_of_life_date, ac.po_number, ac.status, ac.ownership_type, u.full_name, l.location_name, ac.note FROM accessories ac LEFT JOIN users u ON ac.user_id = u.user_id LEFT JOIN locations l ON ac.location_id = l.location_id WHERE ac.category = :c");
        $stmtB->execute(['c' => $cat]);
        $dataB = $stmtB->fetchAll(PDO::FETCH_NUM);

        $data = array_merge($dataA, $dataB);
    }

    $safeCat = preg_replace('/[^A-Za-z0-9_]/', '_', $cat);
    outputCSV("Category_Export_{$safeCat}_" . date('Ymd') . ".csv", $standardHeaders, $data);

} elseif ($type === 'custom') {
    $status = $_GET['status'] ?? 'all';
    $ownership = $_GET['ownership'] ?? 'all';
    $loc_id = $_GET['location_id'] ?? 'all';
    $cat = $_GET['category'] ?? 'all';
    $search = trim($_GET['search'] ?? '');

    $whereA = []; $whereB = [];
    $paramsA = []; $paramsB = [];

    if ($status !== 'all') {
        $whereA[] = "a.status = :status"; $whereB[] = "ac.status = :status";
        $paramsA['status'] = $status; $paramsB['status'] = $status;
    }
    if ($ownership !== 'all') {
        $whereA[] = "a.ownership_type = :own"; $whereB[] = "ac.ownership_type = :own";
        $paramsA['own'] = $ownership; $paramsB['own'] = $ownership;
    }
    if ($loc_id !== 'all') {
        $whereA[] = "a.location_id = :loc"; $whereB[] = "ac.location_id = :loc";
        $paramsA['loc'] = (int)$loc_id; $paramsB['loc'] = (int)$loc_id;
    }
    if ($cat !== 'all') {
        if ($cat === 'ALL_ASSETS') {
            $whereB[] = "1=0"; 
        } elseif ($cat === 'ALL_ACCESSORIES') {
            $whereA[] = "1=0"; 
        } else {
            $whereA[] = "a.category = :cat"; $whereB[] = "ac.category = :cat";
            $paramsA['cat'] = $cat; $paramsB['cat'] = $cat;
        }
    }
    if ($search !== '') {
        $whereA[] = "(a.hostname LIKE :search OR a.serial_number LIKE :search OR a.service_tag LIKE :search)";
        $whereB[] = "(ac.name LIKE :search OR ac.serial_number LIKE :search)";
        $paramsA['search'] = "%{$search}%"; $paramsB['search'] = "%{$search}%";
    }

    $whereSqlA = $whereA ? "WHERE " . implode(" AND ", $whereA) : "";
    $whereSqlB = $whereB ? "WHERE " . implode(" AND ", $whereB) : "";

    $stmtA = $pdo->prepare("SELECT 'Asset', a.category, a.hostname, a.model, a.serial_number, a.service_tag, a.mac_address, a.purchase_year, a.purchase_date, a.end_of_life_date, a.po_number, a.status, a.ownership_type, u.full_name, l.location_name, a.note FROM assets a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN locations l ON a.location_id = l.location_id $whereSqlA");
    $stmtA->execute($paramsA);
    
    $stmtB = $pdo->prepare("SELECT 'Accessory', ac.category, ac.name, ac.model, ac.serial_number, ac.service_tag, ac.mac_address, ac.purchase_year, ac.purchase_date, ac.end_of_life_date, ac.po_number, ac.status, ac.ownership_type, u.full_name, l.location_name, ac.note FROM accessories ac LEFT JOIN users u ON ac.user_id = u.user_id LEFT JOIN locations l ON ac.location_id = l.location_id $whereSqlB");
    $stmtB->execute($paramsB);

    $data = array_merge($stmtA->fetchAll(PDO::FETCH_NUM), $stmtB->fetchAll(PDO::FETCH_NUM));
    outputCSV("Custom_Filter_Export_" . date('Ymd') . ".csv", $standardHeaders, $data);

} elseif ($type === 'user_centric') {
    $users = $pdo->query("SELECT user_id, full_name, emp_id, department FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    
    $assets = $pdo->query("SELECT user_id, category, hostname, serial_number FROM assets WHERE status='deployed' AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $accessories = $pdo->query("SELECT user_id, category, name as hostname, serial_number FROM accessories WHERE status='deployed' AND user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    
    $userHoldings = [];
    foreach ($assets as $a) {
        $userHoldings[$a['user_id']][] = "Asset: {$a['category']} ({$a['hostname']} | SN:{$a['serial_number']})";
    }
    foreach ($accessories as $a) {
        $userHoldings[$a['user_id']][] = "Acc: {$a['category']} ({$a['hostname']} | SN:{$a['serial_number']})";
    }

    $data = [];
    foreach ($users as $u) {
        $holdings = $userHoldings[$u['user_id']] ?? [];
        $data[] = [
            $u['emp_id'],
            $u['full_name'],
            $u['department'],
            count($holdings),
            implode("\n", $holdings)
        ];
    }

    $headers = ['Employee ID', 'Full Name', 'Department', 'Total Items Deployed', 'Item Details'];
    outputCSV("User_Centric_Export_" . date('Ymd') . ".csv", $headers, $data);

} elseif ($type === 'bulk') {
    $ids = $_GET['ids'] ?? '';
    if (!$ids) die("No IDs provided.");
    
    $idArray = array_filter(array_map('intval', explode(',', $ids)));
    if (empty($idArray)) die("Invalid IDs.");
    
    $in = str_repeat('?,', count($idArray) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT 'Asset', a.category, a.hostname, a.model, a.serial_number, a.service_tag, a.mac_address, a.purchase_year, a.purchase_date, a.end_of_life_date, a.po_number, a.status, a.ownership_type, u.full_name, l.location_name, a.note
        FROM assets a
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE a.asset_id IN ($in)
    ");
    $stmt->execute($idArray);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
    outputCSV("Bulk_Selected_Export_" . date('Ymd') . ".csv", $standardHeaders, $data);

} elseif ($type === 'bulk_accessories') {
    $ids = $_GET['ids'] ?? '';
    if (!$ids) die("No IDs provided.");
    
    $idArray = array_filter(array_map('intval', explode(',', $ids)));
    if (empty($idArray)) die("Invalid IDs.");
    
    $in = str_repeat('?,', count($idArray) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT 'Accessory', ac.category, ac.name, ac.model, ac.serial_number, ac.service_tag, ac.mac_address, ac.purchase_year, ac.purchase_date, ac.end_of_life_date, ac.po_number, ac.status, ac.ownership_type, u.full_name, l.location_name, ac.note
        FROM accessories ac
        LEFT JOIN users u ON ac.user_id = u.user_id
        LEFT JOIN locations l ON ac.location_id = l.location_id
        WHERE ac.accessory_id IN ($in)
    ");
    $stmt->execute($idArray);
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
    outputCSV("Bulk_Selected_Accessories_" . date('Ymd') . ".csv", $standardHeaders, $data);

} elseif ($type === 'employees') {
    // Export all users/employees
    $stmt = $pdo->prepare("
        SELECT u.emp_id, u.full_name, u.department, u.email, l.location_name
        FROM users u
        LEFT JOIN locations l ON u.location_id = l.location_id
        ORDER BY u.full_name ASC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
    
    $headers = ['Employee ID', 'Full Name', 'Department', 'Email', 'Location'];
    outputCSV("Employees_Export_" . date('Ymd') . ".csv", $headers, $data);

} elseif ($type === 'logs') {
    $stmt = $pdo->prepare("
        SELECT l.log_id, l.created_at, l.action_type,
               COALESCE(a.hostname, ac.name, '—') AS item_name,
               COALESCE(a.serial_number, ac.serial_number, '—') AS serial_number,
               uf.full_name AS from_user,
               ut.full_name AS to_user,
               l.note
        FROM logs l
        LEFT JOIN assets a ON l.asset_id = a.asset_id
        LEFT JOIN accessories ac ON l.accessory_id = ac.accessory_id
        LEFT JOIN users uf ON l.from_user_id = uf.user_id
        LEFT JOIN users ut ON l.to_user_id = ut.user_id
        ORDER BY l.created_at DESC
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_NUM);
    
    $headers = ['Log ID', 'Timestamp', 'Action', 'Item Name', 'Serial Number', 'From User', 'To User', 'Note'];
    outputCSV("Audit_Logs_Export_" . date('Ymd') . ".csv", $headers, $data);

} elseif ($type === 'backup_db') {
    $dbPath = __DIR__ . '/db/it_assets.db';
    if (!file_exists($dbPath)) {
        die("Database file not found.");
    }
    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="it_assets_backup_' . date('Ymd_His') . '.db"');
    header('Content-Length: ' . filesize($dbPath));
    readfile($dbPath);
    exit;

} else {
    die("Export type not supported.");
}
