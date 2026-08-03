<?php
// scan.php — Mobile-optimized view when scanning an asset QR code
require_once 'includes/db_connect.php';

$asset_id = (int)($_GET['id'] ?? 0);
if (!$asset_id) {
    die("Invalid Asset ID.");
}

$type = $_GET['type'] ?? '';
$isAccessory = ($type === 'accessory');

if (!$isAccessory) {
    $stmt = $pdo->prepare("
        SELECT a.*, l.location_name 
        FROM assets a
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE a.asset_id = :id
    ");
    $stmt->execute(['id' => $asset_id]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $asset = null;
}

if (!$asset) {
    $stmtAcc = $pdo->prepare("
        SELECT ac.*, ac.name as hostname, l.location_name 
        FROM accessories ac
        LEFT JOIN locations l ON ac.location_id = l.location_id
        WHERE ac.accessory_id = :id
    ");
    $stmtAcc->execute(['id' => $asset_id]);
    $asset = $stmtAcc->fetch(PDO::FETCH_ASSOC);
}

if (!$asset) {
    die("Asset or Accessory not found.");
}

// Fetch owner details if assigned
$owner = null;
$start_using = null;

if ($asset['user_id']) {
    $uStmt = $pdo->prepare("
        SELECT u.*, loc.location_name as user_location
        FROM users u
        LEFT JOIN locations loc ON u.location_id = loc.location_id
        WHERE u.user_id = :uid
    ");
    $uStmt->execute(['uid' => $asset['user_id']]);
    $owner = $uStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch when this was assigned to them
    $logStmt = $pdo->prepare("
        SELECT created_at 
        FROM logs 
        WHERE asset_id = :aid AND action_type = 'assign' AND to_user_id = :uid 
        ORDER BY created_at DESC LIMIT 1
    ");
    $logStmt->execute(['aid' => $asset_id, 'uid' => $asset['user_id']]);
    $start_using = $logStmt->fetchColumn();
}

$pageTitle = 'Asset Details';
// Don't include standard layout as we want a mobile-app-like experience without the sidebar
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Asset Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
            padding-bottom: 30px;
        }
        .header-bar {
            background-color: #2563eb;
            color: white;
            padding: 16px 20px;
            text-align: center;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px 16px;
        }
        .asset-icon-box {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            aspect-ratio: 4/3;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
            font-size: 5rem;
            color: #94a3b8;
        }
        .section-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            padding: 16px;
            margin-bottom: 24px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .info-row:first-child {
            padding-top: 0;
        }
        .info-label {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .info-value {
            font-weight: 600;
            font-size: 0.95rem;
            text-align: right;
            word-break: break-word;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-in_stock { background: #dcfce7; color: #166534; }
        .status-deployed { background: #dbeafe; color: #1e40af; }
        .status-repair { background: #fef9c3; color: #854d0e; }
        .status-retired { background: #f1f5f9; color: #475569; }
        .status-lost { background: #fee2e2; color: #991b1b; }

        .btn-history {
            display: block;
            width: 100%;
            background: #2563eb;
            color: white;
            text-align: center;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            margin-top: 10px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
            transition: background 0.2s;
        }
        .btn-history:hover {
            background: #1d4ed8;
            color: white;
        }
        .btn-outline {
            display: block;
            width: 100%;
            background: transparent;
            color: #475569;
            border: 1px solid #cbd5e1;
            text-align: center;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            margin-top: 12px;
        }
        .owner-avatar {
            width: 48px;
            height: 48px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .owner-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
    </style>
</head>
<body>

<div class="header-bar">
    <?= htmlspecialchars($asset['hostname'] ?: $asset['category']) ?>
</div>

<div class="container">
    
    <div class="asset-icon-box">
        <?php
            $cat = strtolower($asset['category']);
            if (strpos($cat, 'laptop') !== false) echo '<i class="bi bi-laptop"></i>';
            elseif (strpos($cat, 'desktop') !== false) echo '<i class="bi bi-pc-display"></i>';
            elseif (strpos($cat, 'printer') !== false) echo '<i class="bi bi-printer"></i>';
            elseif (strpos($cat, 'phone') !== false || strpos($cat, 'mobile') !== false) echo '<i class="bi bi-phone"></i>';
            else echo '<i class="bi bi-box-seam"></i>';
        ?>
    </div>

    <!-- OWNER DETAILS -->
    <div class="section-title">Owner Details</div>
    <?php if ($owner): ?>
        <div class="info-card" style="border: 2px solid #3b82f6;">
            <div class="owner-header">
                <div class="owner-avatar">
                    <?= strtoupper(substr($owner['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight: 700; font-size: 1.1rem; color: #1e293b;"><?= htmlspecialchars($owner['full_name']) ?></div>
                    <div style="color: #64748b; font-size: 0.85rem;"><?= htmlspecialchars($owner['emp_id']) ?></div>
                </div>
            </div>
            
            <div class="info-row">
                <span class="info-label">Department</span>
                <span class="info-value"><?= htmlspecialchars($owner['department'] ?: '-') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Location</span>
                <span class="info-value"><?= htmlspecialchars($owner['user_location'] ?: '-') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Start Using</span>
                <span class="info-value"><?= $start_using ? date('d M Y', strtotime($start_using)) : '-' ?></span>
            </div>
        </div>
    <?php else: ?>
        <div class="info-card" style="text-align: center; padding: 30px 16px;">
            <i class="bi bi-person-x" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom:10px; display:inline-block;"></i>
            <h5 style="color: #475569; font-weight: 600; margin-bottom: 5px;">Not Assigned</h5>
            <div class="status-badge status-<?= $asset['status'] ?>">
                <?= strtoupper(str_replace('_', ' ', $asset['status'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ASSET DETAILS -->
    <div class="section-title">Asset Details</div>
    <div class="info-card">
        <div class="info-row">
            <span class="info-label">Category</span>
            <span class="info-value"><?= htmlspecialchars($asset['category']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Model</span>
            <span class="info-value"><?= htmlspecialchars($asset['model'] ?: '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Serial No.</span>
            <span class="info-value" style="font-family: monospace; font-size: 1.05rem;"><?= htmlspecialchars($asset['serial_number'] ?: '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Service Tag</span>
            <span class="info-value" style="font-family: monospace; font-size: 1.05rem;"><?= htmlspecialchars($asset['service_tag'] ?: '-') ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Warranty EOL</span>
            <span class="info-value">
                <?php 
                    if ($asset['end_of_life_date']) {
                        $isExp = strtotime($asset['end_of_life_date']) < time();
                        echo $isExp ? "<span style='color:#dc2626;'><i class='bi bi-exclamation-circle'></i> Expired</span>" : date('d M Y', strtotime($asset['end_of_life_date']));
                    } else {
                        echo '-';
                    }
                ?>
            </span>
        </div>
    </div>

    <!-- ACTIONS -->
    <a href="history.php?asset_id=<?= $asset['asset_id'] ?>" class="btn-history">
        <i class="bi bi-clock-history"></i> View Full History
    </a>
    
    <!-- Link back to admin portal if they need to edit -->
    <a href="index.php" class="btn-outline">
        <i class="bi bi-house"></i> Go to Admin Portal
    </a>

</div>

</body>
</html>
