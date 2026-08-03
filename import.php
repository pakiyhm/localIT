<?php
// import.php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_edit(); // Must be logged in

$pageTitle = __('Import & Bulk Update');
$activeNav = 'import';

$entity = $_GET['entity'] ?? $_POST['entity'] ?? 'assets';

// Allowed columns mapping (CSV Header => DB Column)
$column_mapping_assets = [
    'Category' => 'category',
    'Hostname' => 'hostname',
    'Identifier (Hostname/Name)' => 'hostname',
    'Serial Number' => 'serial_number',
    'Serial No.' => 'serial_number',
    'Model' => 'model',
    'Purchase Year' => 'purchase_year',
    'Service Tag' => 'service_tag',
    'MAC Address' => 'mac_address',
    'Purchase Date' => 'purchase_date',
    'End of Life' => 'end_of_life_date',
    'Ownership' => 'ownership_type',
    'Ownership Type' => 'ownership_type',
    'Batch / PO Number' => 'po_number',
    'Note' => 'note',
    'Assigned To' => 'emp_id', // Virtual
    'Assigned To (Name or ID)' => 'emp_id',
    'Location' => 'location_name', // Virtual
    'Status' => 'status'
];

$column_mapping_users = [
    'Employee ID' => 'emp_id',
    'Full Name' => 'full_name',
    'Email' => 'email',
    'Department' => 'department',
    'Location' => 'location_name' // Virtual
];

$column_mapping = ($entity === 'users') ? $column_mapping_users : $column_mapping_assets;


// Handle CSV Template Download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $entity . '_import_template.csv');
    $output = fopen('php://output', 'w');
    // Add BOM for Excel UTF-8 support
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $template_headers_assets = ['Category', 'Hostname', 'Serial Number', 'Model', 'Purchase Year', 'Service Tag', 'MAC Address', 'Purchase Date', 'End of Life', 'Ownership', 'Batch / PO Number', 'Note', 'Assigned To (Name or ID)', 'Location', 'Status'];
    $template_headers_users = ['Employee ID', 'Full Name', 'Email', 'Department', 'Location'];
    $template_headers = ($entity === 'users') ? $template_headers_users : $template_headers_assets;
    
    fputcsv($output, $template_headers, ',', '"', "\\");
    if ($entity === 'assets') {
        fputcsv($output, ['Laptop', 'LAP-001', 'SN12345', 'Dell XPS 15', '2023', '', '', '', '', 'Buy', 'PO-1001', '', 'EMP001', 'HQ', 'in_stock'], ',', '"', "\\");
    } else {
        fputcsv($output, ['EMP001', 'Jane Doe', 'jane@company.com', 'IT', 'HQ'], ',', '"', "\\");
    }
    fclose($output);
    exit;
}

$errorMsg = '';
$successMsg = '';
$diffData = null; // Will hold the parsed differences
$stats = ['new' => 0, 'update' => 0, 'error' => 0, 'unchanged' => 0];

// Handle CSV Upload and Parse
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!csrf_verify()) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Error uploading file.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                $errorMsg = 'Only .csv files are allowed.';
            } else {
                $handle = fopen($file['tmp_name'], 'r');
                if ($handle !== FALSE) {
                    // Remove BOM if present
                    $bom = fread($handle, 3);
                    if ($bom !== b"\xEF\xBB\xBF") {
                        rewind($handle);
                    }
                    
                    $headers = fputcsv_get_headers($handle);
                    
                    $req = $entity === 'users' ? 'Employee ID' : 'Category';
                    if (!$headers || !in_array($req, $headers)) {
                        $errorMsg = "Invalid CSV format. '{$req}' column is required.";
                    } else {
                        $diffData = process_csv_diff($pdo, $handle, $headers, $column_mapping, $stats, $entity);
                    }
                    fclose($handle);
                } else {
                    $errorMsg = 'Could not read the uploaded file.';
                }
            }
        }
    }
}

// Handle Final Commit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commit') {
    if (!csrf_verify()) {
        $errorMsg = 'Invalid CSRF token.';
    } else {
        $commitData = json_decode($_POST['commit_data'], true);
        if ($commitData && is_array($commitData)) {
            $result = commit_import_data($pdo, $commitData, $entity);
            if ($result['success']) {
                $successMsg = "Successfully processed {$result['count']} items.";
                $diffData = null; // Clear view
            } else {
                $errorMsg = "Database error: " . $result['error'];
            }
        } else {
            $errorMsg = "Invalid data payload.";
        }
    }
}

// --- Helper Functions ---

function fputcsv_get_headers($handle) {
    $data = fgetcsv($handle, 10000, ',', '"', "\\");
    if ($data !== FALSE) {
        return array_map('trim', $data);
    }
    return false;
}

function process_csv_diff($pdo, $handle, $headers, $column_mapping, &$stats, $entity) {
    if ($entity === 'users') return process_user_csv_diff($pdo, $handle, $headers, $column_mapping, $stats);
    return process_asset_csv_diff($pdo, $handle, $headers, $column_mapping, $stats);
}

function process_user_csv_diff($pdo, $handle, $headers, $column_mapping, &$stats) {
    $rows = [];
    $rowIndex = 1;
    
    $stmt = $pdo->query("SELECT * FROM users");
    $existingUsers = [];
    $existingUsersByName = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!empty($u['emp_id'])) $existingUsers[$u['emp_id']] = $u;
        $existingUsersByName[strtolower(trim($u['full_name']))] = $u;
    }
    
    $locationsByName = [];
    foreach ($pdo->query("SELECT location_id, location_name FROM locations")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $locationsByName[strtolower($l['location_name'])] = $l['location_id'];
    }
    
    while (($data = fgetcsv($handle, 10000, ',', '"', "\\")) !== FALSE) {
        $rowIndex++;
        $row = [];
        foreach ($headers as $index => $header) {
            if (isset($column_mapping[$header]) && isset($data[$index])) {
                $row[$column_mapping[$header]] = trim($data[$index]);
            }
        }
        
        $isEmpty = true;
        foreach ($row as $val) { if ($val !== '') { $isEmpty = false; break; } }
        if ($isEmpty) continue;
        
        $empId = $row['emp_id'] ?? null;
        $fullName = $row['full_name'] ?? null;
        
        if (empty($empId) && empty($fullName)) {
            $stats['error']++;
            $rows[] = ['row_index' => $rowIndex, 'data' => $row, 'status' => 'error', 'msg' => 'Employee ID or Full Name is required.'];
            continue;
        }
        
        $row['location_id'] = null;
        if (!empty($row['location_name'])) {
            $loc = strtolower($row['location_name']);
            if (isset($locationsByName[$loc])) {
                $row['location_id'] = $locationsByName[$loc];
            } else {
                $stats['error']++;
                $rows[] = ['row_index' => $rowIndex, 'data' => $row, 'status' => 'error', 'msg' => "Location '{$row['location_name']}' not found."];
                continue;
            }
        }
        
        $existing = null;
        if (!empty($empId) && isset($existingUsers[$empId])) {
            $existing = $existingUsers[$empId];
        } elseif (!empty($fullName) && isset($existingUsersByName[strtolower(trim($fullName))])) {
            $existing = $existingUsersByName[strtolower(trim($fullName))];
        }
        
        if ($existing) {
            $changes = [];
            $fieldsToCompare = ['emp_id', 'full_name', 'email', 'department', 'location_id'];
            foreach ($fieldsToCompare as $field) {
                if (isset($row[$field]) && $row[$field] !== '' && (string)$row[$field] !== (string)$existing[$field]) {
                    $changes[$field] = ['old' => $existing[$field], 'new' => $row[$field]];
                }
            }
            if (!empty($changes)) {
                $stats['update']++;
                $rows[] = ['row_index' => $rowIndex, 'data' => $row, 'status' => 'update', 'changes' => $changes, 'target_id' => $existing['user_id']];
            } else {
                $stats['unchanged']++;
                $rows[] = ['row_index' => $rowIndex, 'data' => $row, 'status' => 'unchanged'];
            }
        } else {
            $stats['new']++;
            $rows[] = ['row_index' => $rowIndex, 'data' => $row, 'status' => 'new'];
        }
    }
    return $rows;
}

function process_asset_csv_diff($pdo, $handle, $headers, $column_mapping, &$stats) {
    $rows = [];
    $rowIndex = 1;
    
    // Fetch all current assets for quick lookup
    $stmt = $pdo->query("SELECT * FROM assets");
    $existingAssets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $assetsBySN = [];
    $assetsByHostname = [];
    foreach ($existingAssets as $asset) {
        if (!empty($asset['serial_number'])) $assetsBySN[$asset['serial_number']] = $asset;
        if (!empty($asset['hostname'])) $assetsByHostname[$asset['hostname']] = $asset;
    }
    
    // Fetch relationships
    $usersByEmpId = [];
    $usersByName = [];
    foreach ($pdo->query("SELECT user_id, emp_id, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!empty($u['emp_id'])) {
            $usersByEmpId[$u['emp_id']] = $u['user_id'];
        }
        if (!empty($u['full_name'])) {
            $usersByName[strtolower(trim($u['full_name']))] = $u['user_id'];
        }
    }
    
    $locationsByName = [];
    foreach ($pdo->query("SELECT location_id, location_name FROM locations")->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $locationsByName[strtolower($l['location_name'])] = $l['location_id'];
    }
    
    while (($data = fgetcsv($handle, 10000, ',', '"', "\\")) !== FALSE) {
        $rowIndex++;
        // Map row to associative array based on headers
        $row = [];
        foreach ($headers as $index => $header) {
            if (isset($column_mapping[$header]) && isset($data[$index])) {
                $row[$column_mapping[$header]] = trim($data[$index]);
            }
        }
        
        // Validation
        if (empty($row['category'])) {
            $stats['error']++;
            $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Missing category', 'data' => $row];
            continue;
        }
        
        $sn = $row['serial_number'] ?? '';
        $hn = $row['hostname'] ?? '';
        
        if (empty($sn) && empty($hn)) {
            $stats['error']++;
            $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Must provide serial_number or hostname', 'data' => $row];
            continue;
        }
        
        $match = null;
        if (!empty($sn) && isset($assetsBySN[$sn])) {
            $match = $assetsBySN[$sn];
        } elseif (!empty($hn) && isset($assetsByHostname[$hn])) {
            $match = $assetsByHostname[$hn];
        }
        
        // Relationship resolution
        if (isset($row['emp_id']) && $row['emp_id'] !== '') {
            $assigned = $row['emp_id'];
            $assignedLower = strtolower(trim($assigned));
            if (isset($usersByEmpId[$assigned])) {
                $row['user_id'] = $usersByEmpId[$assigned];
            } elseif (isset($usersByName[$assignedLower])) {
                $row['user_id'] = $usersByName[$assignedLower];
            } else {
                $stats['error']++;
                $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Assigned To (emp_id or Name) not found', 'data' => $row];
                continue;
            }
        } elseif (isset($row['emp_id']) && $row['emp_id'] === '') {
            $row['user_id'] = null; // Clear field
        }
        
        if (isset($row['location_name']) && $row['location_name'] !== '') {
            $locLower = strtolower($row['location_name']);
            if (isset($locationsByName[$locLower])) {
                $row['location_id'] = $locationsByName[$locLower];
            } else {
                $stats['error']++;
                $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Location not found', 'data' => $row];
                continue;
            }
        } elseif (isset($row['location_name']) && $row['location_name'] === '') {
            $row['location_id'] = null; // Clear field
        }
        
        // Check for conflicts (SN matches one, HN matches another)
        if (!empty($sn) && !empty($hn)) {
            $matchSN = $assetsBySN[$sn] ?? null;
            $matchHN = $assetsByHostname[$hn] ?? null;
            if ($matchSN && $matchHN && $matchSN['asset_id'] !== $matchHN['asset_id']) {
                $stats['error']++;
                $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Conflict: SN and Hostname belong to different assets in DB', 'data' => $row];
                continue;
            }
        }
        
        if ($match) {
            // It's an UPDATE
            $changes = [];
            
            // Critical Rule: If asset is deployed, do not allow changing status or user_id via CSV
            $isDeployed = ($match['status'] === 'deployed' || !empty($match['user_id']));
            
            foreach ($row as $key => $val) {
                // Skip virtual fields that are not in the DB
                if (in_array($key, ['emp_id', 'location_name'])) continue;
                
                // Skip empty values from CSV if they are not intentionally clearing
                if ($val === '') continue; 
                
                // Block status change if deployed
                if ($isDeployed && $key === 'status' && $val !== $match[$key]) {
                     $changes[$key] = ['old' => $match[$key], 'new' => $val, 'blocked' => true, 'msg' => 'Asset is deployed'];
                     continue;
                }
                
                if (isset($match[$key]) && $match[$key] !== $val) {
                    $changes[$key] = ['old' => $match[$key], 'new' => $val];
                } elseif (!isset($match[$key]) && $val !== '') {
                    $changes[$key] = ['old' => '', 'new' => $val];
                }
            }
            
            // Check if any actual valid changes
            $hasValidChanges = false;
            foreach ($changes as $k => $c) {
                if (!isset($c['blocked'])) {
                    $hasValidChanges = true; break;
                }
            }
            
            if (empty($changes)) {
                $stats['unchanged']++;
                $rows[] = ['status' => 'unchanged', 'row_index' => $rowIndex, 'data' => $row, 'asset_id' => $match['asset_id']];
            } else {
                if ($hasValidChanges) {
                    $stats['update']++;
                    $rows[] = ['status' => 'update', 'row_index' => $rowIndex, 'changes' => $changes, 'data' => $row, 'asset_id' => $match['asset_id']];
                } else {
                    $stats['error']++;
                    $rows[] = ['status' => 'error', 'row_index' => $rowIndex, 'msg' => 'Changes blocked (Asset deployed)', 'changes' => $changes, 'data' => $row];
                }
            }
            
        } else {
            // It's NEW
            $stats['new']++;
            $rows[] = ['status' => 'new', 'row_index' => $rowIndex, 'data' => $row];
        }
    }
    
    return $rows;
}

function commit_import_data($pdo, $commitData, $entity) {
    try {
        $pdo->beginTransaction();
        
        $count = 0;
        
        if ($entity === 'users') {
            $insertUserStmt = $pdo->prepare("INSERT INTO users (emp_id, full_name, email, department, location_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($commitData as $row) {
                if ($row['status'] === 'new') {
                    $data = $row['data'];
                    $insertUserStmt->execute([
                        ($data['emp_id'] ?? '') === '' ? null : $data['emp_id'],
                        ($data['full_name'] ?? '') === '' ? null : $data['full_name'],
                        ($data['email'] ?? '') === '' ? null : $data['email'],
                        ($data['department'] ?? '') === '' ? null : $data['department'],
                        ($data['location_id'] ?? '') === '' ? null : $data['location_id']
                    ]);
                    $count++;
                } elseif ($row['status'] === 'update') {
                    $changes = $row['changes'];
                    $userId = $row['target_id'];
                    $updateFields = [];
                    $params = [];
                    foreach ($changes as $key => $change) {
                        if (!isset($change['blocked'])) {
                            $updateFields[] = "$key = ?";
                            $params[] = $change['new'] === '' ? null : $change['new'];
                        }
                    }
                    if (!empty($updateFields)) {
                        $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
                        $params[] = $userId;
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $count++;
                    }
                }
            }
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO assets (category, hostname, serial_number, status, ownership_type, model, purchase_year, service_tag, mac_address, purchase_date, end_of_life_date, vendor_return_date, vendor_return_to, vendor_return_method, po_number, user_id, location_id, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($commitData as $row) {
                if ($row['status'] === 'new') {
                    $data = $row['data'];
                    $insertStmt->execute([
                        ($data['category'] ?? '') === '' ? null : $data['category'],
                        ($data['hostname'] ?? '') === '' ? null : $data['hostname'],
                        ($data['serial_number'] ?? '') === '' ? null : $data['serial_number'],
                        !empty($data['user_id']) ? 'deployed' : (empty($data['status']) ? 'in_stock' : $data['status']),
                        ($data['ownership_type'] ?? '') === '' ? null : $data['ownership_type'],
                        ($data['model'] ?? '') === '' ? null : $data['model'],
                        ($data['purchase_year'] ?? '') === '' ? null : $data['purchase_year'],
                        ($data['service_tag'] ?? '') === '' ? null : $data['service_tag'],
                        ($data['mac_address'] ?? '') === '' ? null : $data['mac_address'],
                        ($data['purchase_date'] ?? '') === '' ? null : $data['purchase_date'],
                        ($data['end_of_life_date'] ?? '') === '' ? null : $data['end_of_life_date'],
                        ($data['vendor_return_date'] ?? '') === '' ? null : $data['vendor_return_date'],
                        ($data['vendor_return_to'] ?? '') === '' ? null : $data['vendor_return_to'],
                        ($data['vendor_return_method'] ?? '') === '' ? null : $data['vendor_return_method'],
                        ($data['po_number'] ?? '') === '' ? null : $data['po_number'],
                        ($data['user_id'] ?? '') === '' ? null : $data['user_id'],
                        ($data['location_id'] ?? '') === '' ? null : $data['location_id'],
                        ($data['note'] ?? '') === '' ? null : $data['note']
                    ]);
                    
                    $assetId = $pdo->lastInsertId();
                    
                    $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (?, 'import', 'Imported via CSV bulk tool')");
                    $logStmt->execute([$assetId]);
                    
                    $count++;
                } elseif ($row['status'] === 'update') {
                    $data = $row['data'];
                    $changes = $row['changes'];
                    $assetId = $row['asset_id'];
                    
                    $updateFields = [];
                    $params = [];
                    $logNotes = [];
                    
                    foreach ($changes as $key => $change) {
                        if (!isset($change['blocked'])) {
                            $updateFields[] = "$key = ?";
                            $params[] = $change['new'] === '' ? null : $change['new'];
                            $logNotes[] = "$key: {$change['old']} -> {$change['new']}";
                        }
                    }
                    
                    // Auto-deploy status if user_id was set in this update
                    if (isset($changes['user_id']) && !empty($changes['user_id']['new']) && !isset($changes['user_id']['blocked'])) {
                        $updateFields[] = "status = ?";
                        $params[] = 'deployed';
                    }

                    if (!empty($updateFields)) {
                        $sql = "UPDATE assets SET " . implode(', ', $updateFields) . " WHERE asset_id = ?";
                        $params[] = $assetId;
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        
                        $logNote = "Bulk Updated: " . implode(', ', $logNotes);
                        $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (?, 'update', ?)");
                        $logStmt->execute([$assetId, $logNote]);
                        
                        $count++;
                    }
                }
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'count' => $count];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

require_once 'includes/layout.php';
?>

<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="flex-direction:column; align-items:stretch; gap:10px; padding:20px 24px; background:var(--color-surface);">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:1.1rem; margin:0;"><i class="bi bi-cloud-arrow-up" style="color:var(--color-accent); margin-right:8px;"></i>
                <?= htmlspecialchars(__('Bulk Import & Update')) ?>
            </h3>
        </div>
        
        <div style="display:flex; gap:10px; margin-top: 15px; border-bottom: 1px solid var(--color-border-soft); padding-bottom: 15px;">
            <a href="import.php?entity=assets" style="text-decoration:none; padding:8px 16px; border-radius:6px; font-weight:600; transition:all 0.2s; <?= $entity === 'assets' ? 'background:var(--color-accent); color:#fff; box-shadow:0 2px 4px rgba(37,99,235,0.2);' : 'background:#f1f5f9; color:var(--color-text-muted);' ?>">
                <i class="bi bi-laptop"></i> <?= __('Import Assets') ?>
            </a>
            <a href="import.php?entity=users" style="text-decoration:none; padding:8px 16px; border-radius:6px; font-weight:600; transition:all 0.2s; <?= $entity === 'users' ? 'background:var(--color-accent); color:#fff; box-shadow:0 2px 4px rgba(37,99,235,0.2);' : 'background:#f1f5f9; color:var(--color-text-muted);' ?>">
                <i class="bi bi-people"></i> <?= __('Import Users') ?>
            </a>
        </div>
        
        <div class="text-muted" style="font-size:.85rem; margin-top:10px;">
            <?php if ($entity === 'assets'): ?>
                <?= __('Upload a CSV file to bulk insert new assets or update existing ones. The system will detect duplicates based on <strong>serial_number</strong> and <strong>hostname</strong>.') ?>
            <?php else: ?>
                <?= __('Upload a CSV file to bulk insert new users or update existing ones. The system will detect duplicates based on <strong>Employee ID</strong>.') ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding: 24px;">
        
        <?php if ($errorMsg): ?>
            <div class="alert alert-danger" style="margin-bottom:20px;"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>
        <?php if ($successMsg): ?>
            <div class="alert alert-success" style="margin-bottom:20px;"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <?php if ($diffData === null): ?>
            
            <div style="display:flex; gap: 24px; align-items:stretch;">
                <div style="flex:1; border:2px dashed var(--color-border-soft); padding:30px; border-radius:12px; text-align:center; background:#f8fafc; display:flex; flex-direction:column; justify-content:space-between; align-items:center;">
                    <div>
                        <i class="bi bi-file-earmark-spreadsheet" style="font-size:3rem; color:var(--color-primary); margin-bottom:10px; display:block;"></i>
                        <h6 style="margin-bottom:10px; font-weight:700;"><?= __('1. Download Template') ?></h6>
                        <p style="font-size:.9rem; color:var(--color-text-muted);"><?= __('Start with our perfectly formatted CSV template.') ?></p>
                    </div>
                    <a href="?action=template&entity=<?= htmlspecialchars($entity) ?>" class="btn btn-ghost" style="margin-top:20px;"><i class="bi bi-download"></i> <?= __('Download Example CSV') ?></a>
                </div>
                
                <div style="flex:1; border:2px dashed var(--color-border-soft); padding:30px; border-radius:12px; text-align:center; background:#f8fafc; display:flex; flex-direction:column; justify-content:space-between; align-items:center;">
                    <div>
                        <i class="bi bi-cloud-upload" style="font-size:3rem; color:var(--color-primary); margin-bottom:10px; display:block;"></i>
                        <h6 style="margin-bottom:10px; font-weight:700;"><?= __('2. Upload Data') ?></h6>
                        <p style="font-size:.9rem; color:var(--color-text-muted);"><?= __('Upload your modified CSV file for review.') ?></p>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; align-items:center; gap:10px; margin-top:20px;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="entity" value="<?= htmlspecialchars($entity) ?>">
                        <input type="file" name="csv_file" accept=".csv" required class="form-control" style="max-width:250px;">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> <?= __('Review Data') ?></button>
                    </form>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Phase 2: Diff Review -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <h5 class="m-0"><?= __('Review Changes') ?></h5>
                <div style="display:flex; gap:10px;">
                    <span class="badge badge-in_stock" style="font-size:.9rem; padding:6px 12px;"><?= $stats['new'] ?> <?= __('New') ?></span>
                    <span class="badge badge-repair" style="font-size:.9rem; padding:6px 12px;"><?= $stats['update'] ?> <?= __('Updates') ?></span>
                    <span class="badge badge-retired" style="font-size:.9rem; padding:6px 12px;"><?= $stats['unchanged'] ?> <?= __('Unchanged') ?></span>
                    <?php if ($stats['error'] > 0): ?>
                    <span class="badge badge-lost" style="font-size:.9rem; padding:6px 12px;"><?= $stats['error'] ?> <?= __('Errors') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($stats['error'] > 0): ?>
                <div class="alert alert-danger" style="font-size:.9rem;">
                    <strong><i class="bi bi-exclamation-octagon"></i> <?= __('Warning:') ?></strong> <?= sprintf(__('There are %d rows with errors. These rows will be skipped during the update.'), $stats['error']) ?>
                </div>
            <?php endif; ?>
            
            <div class="tbl-wrap" style="max-height: 500px; overflow-y:auto; border:1px solid var(--color-border-soft); border-radius:8px;">
                <table style="margin:0; font-size:.85rem;">
                    <thead style="position:sticky; top:0; background:#f8fafc; z-index:1; box-shadow:0 1px 0 var(--color-border-soft);">
                        <tr>
                            <th><?= __('Row') ?></th>
                            <th><?= __('Status') ?></th>
                            <?php if ($entity === 'users'): ?>
                                <th><?= __('Employee ID') ?></th>
                                <th><?= __('Full Name') ?></th>
                                <th><?= __('Email') ?></th>
                                <th><?= __('Department / Location') ?></th>
                            <?php else: ?>
                                <th><?= __('Category') ?></th>
                                <th><?= __('Hostname') ?></th>
                                <th><?= __('Serial Number') ?></th>
                                <th><?= __('Model') ?></th>
                                <th><?= __('Service Tag') ?></th>
                                <th><?= __('Ownership') ?></th>
                                <th><?= __('Assigned To') ?></th>
                                <th><?= __('Location') ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($diffData as $row): 
                            if ($entity === 'users') {
                                $ident = "EMP: " . htmlspecialchars($row['data']['emp_id'] ?? '-');
                            } else {
                                $sn = $row['data']['serial_number'] ?? '-';
                                $hn = $row['data']['hostname'] ?? '-';
                                $ident = "SN: $sn<br>HN: $hn";
                            }
                        ?>
                            <tr>
                                <td style="width:5%; font-weight:bold; color:var(--color-text-muted);">#<?= $row['row_index'] ?></td>
                                <td style="width:10%;">
                                    <?php if ($row['status'] === 'new'): ?>
                                        <span class="badge badge-in_stock"><?= __('New') ?></span>
                                    <?php elseif ($row['status'] === 'update'): ?>
                                        <span class="badge badge-repair"><?= __('Updates') ?></span>
                                    <?php elseif ($row['status'] === 'unchanged'): ?>
                                        <span class="badge badge-retired"><?= __('Unchanged') ?></span>
                                    <?php elseif ($row['status'] === 'error'): ?>
                                        <span class="badge badge-lost"><?= __('Errors') ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <?php if ($entity === 'users'): ?>
                                    <?php if ($row['status'] === 'error'): ?>
                                        <td colspan="4">
                                            <div style="color:#dc2626; font-weight:bold;"><?= htmlspecialchars(__($row['msg'])) ?></div>
                                        </td>
                                    <?php else: ?>
                                        <?php
                                        $renderCell = function($field, $isDeptLoc = false) use ($row) {
                                            if ($isDeptLoc) {
                                                $dept = htmlspecialchars($row['data']['department'] ?? '-');
                                                $loc = htmlspecialchars($row['data']['location_name'] ?? '-');
                                                if ($row['status'] === 'update') {
                                                    if (isset($row['changes']['department'])) {
                                                        $dept = '<span style="text-decoration:line-through; color:#dc2626;">' . htmlspecialchars($row['changes']['department']['old'] ?: '-') . '</span> &rarr; <span style="color:#16a34a;">' . htmlspecialchars($row['changes']['department']['new'] ?: '-') . '</span>';
                                                    }
                                                    if (isset($row['changes']['location_id'])) {
                                                        $loc = '<span style="color:#16a34a;">' . __('Updated') . '</span>';
                                                    }
                                                }
                                                return "<div>$dept</div><div style='font-size:0.8rem; color:var(--color-text-muted);'>$loc</div>";
                                            } else {
                                                if ($row['status'] === 'update' && isset($row['changes'][$field])) {
                                                    $c = $row['changes'][$field];
                                                    return '<span style="text-decoration:line-through; color:#dc2626;">' . htmlspecialchars($c['old'] ?: '-') . '</span> &rarr; <span style="color:#16a34a;">' . htmlspecialchars($c['new'] ?: '-') . '</span>';
                                                }
                                                return htmlspecialchars($row['data'][$field] ?? '-');
                                            }
                                        };
                                        ?>
                                        <td><strong><?= $renderCell('emp_id') ?></strong></td>
                                        <td><?= $renderCell('full_name') ?></td>
                                        <td><?= $renderCell('email') ?></td>
                                        <td><?= $renderCell('', true) ?></td>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($row['status'] === 'error'): ?>
                                        <td colspan="8">
                                            <div style="color:#dc2626; font-weight:bold;"><?= htmlspecialchars(__($row['msg'])) ?></div>
                                            <?php if(isset($row['changes'])): ?>
                                                <div style="margin-top:5px; font-size:.8rem;">
                                                    <?php foreach ($row['changes'] as $k => $c): ?>
                                                        <?php if(isset($c['blocked'])): ?>
                                                            <div style="color:#dc2626; text-decoration:line-through;"><?= $k ?>: <?= $c['old'] ?> &rarr; <?= $c['new'] ?> (Blocked)</div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <?php
                                        $renderAssetCell = function($field) use ($row) {
                                            if ($row['status'] === 'update' && isset($row['changes'][$field])) {
                                                $c = $row['changes'][$field];
                                                return '<span style="text-decoration:line-through; color:#dc2626;">' . htmlspecialchars($c['old'] ?: '-') . '</span> &rarr; <span style="color:#16a34a;">' . htmlspecialchars($c['new'] ?: '-') . '</span>';
                                            }
                                            $val = $row['data'][$field] ?? '';
                                            return htmlspecialchars($val === '' ? '-' : $val);
                                        };
                                        ?>
                                        <td><?= $renderAssetCell('category') ?></td>
                                        <td><?= $renderAssetCell('hostname') ?></td>
                                        <td><?= $renderAssetCell('serial_number') ?></td>
                                        <td><?= $renderAssetCell('model') ?></td>
                                        <td><?= $renderAssetCell('service_tag') ?></td>
                                        <td><?= $renderAssetCell('ownership_type') ?></td>
                                        <td><?= $renderAssetCell('emp_id') ?></td>
                                        <td><?= $renderAssetCell('location_name') ?></td>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center;">
                <a href="import.php?entity=<?= htmlspecialchars($entity) ?>" class="btn btn-ghost"><i class="bi bi-x-circle"></i> <?= __('Cancel') ?></a>
                
                <?php if ($stats['new'] > 0 || $stats['update'] > 0): ?>
                    <form method="POST" id="commitForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="commit">
                        <input type="hidden" name="entity" value="<?= htmlspecialchars($entity) ?>">
                        <?php
                        // Filter out unchanged and errors to save payload size
                        $commitPayload = array_filter($diffData, function($r) {
                            return in_array($r['status'], ['new', 'update']);
                        });
                        ?>
                        <input type="hidden" name="commit_data" value="<?= htmlspecialchars(json_encode(array_values($commitPayload))) ?>">
                        <button type="submit" class="btn btn-success" style="font-weight:600;"><i class="bi bi-check2-circle"></i> <?= __('Confirm & Update') ?></button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-success" disabled><?= __('No valid changes to commit') ?></button>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
