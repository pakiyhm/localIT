<?php
// print_handover.php — 100% Pure English Corporate IT Asset Handover, Return & Exchange Certificate
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';

$comp_name = _auth_get_setting($pdo, 'company_name', 'COMPANY NAME');
$comp_dept = _auth_get_setting($pdo, 'company_dept', 'Information Technology Department');
$comp_logo = _auth_get_setting($pdo, 'company_logo', '');

$user_id      = (int)($_GET['user_id'] ?? 0);
$asset_id     = (int)($_GET['asset_id'] ?? 0);
$old_asset_id = (int)($_GET['old_asset_id'] ?? 0);
$type         = $_GET['type'] ?? 'assign'; // 'assign', 'return', or 'exchange'
$reason       = $_GET['reason'] ?? ($type === 'return' ? 'resignation' : 'handover');

$user = null;
$assets = [];
$accessories = [];
$software = [];
$returnedHistory = [];

if ($user_id > 0) {
    $uStmt = $pdo->prepare("
        SELECT u.*, l.location_name 
        FROM users u 
        LEFT JOIN locations l ON u.location_id = l.location_id 
        WHERE u.user_id = :u
    ");
    $uStmt->execute(['u' => $user_id]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $aStmt = $pdo->prepare("
            SELECT a.*, l.location_name 
            FROM assets a 
            LEFT JOIN locations l ON a.location_id = l.location_id 
            WHERE a.user_id = :u
        ");
        $aStmt->execute(['u' => $user_id]);
        $assets = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        $acStmt = $pdo->prepare("
            SELECT ac.*, l.location_name 
            FROM accessories ac 
            LEFT JOIN locations l ON ac.location_id = l.location_id 
            WHERE ac.user_id = :u
        ");
        $acStmt->execute(['u' => $user_id]);
        $accessories = $acStmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $swStmt = $pdo->prepare("
                SELECT sl.*, s.software_name, sb.batch_name
                FROM software_licenses sl
                JOIN software s ON sl.software_id = s.software_id
                LEFT JOIN software_batches sb ON sl.batch_id = sb.batch_id
                WHERE sl.user_id = :u AND sl.license_key IS NOT NULL AND TRIM(sl.license_key) != ''
            ");
            $swStmt->execute(['u' => $user_id]);
            $software = $swStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

        // Fetch historical returned items (e.g., previously exchanged machines) for Return Certificate
        if ($type === 'return') {
            try {
                $hStmt = $pdo->prepare("
                    SELECT DISTINCT a.*, l.created_at as return_date
                    FROM logs l
                    JOIN assets a ON l.asset_id = a.asset_id
                    WHERE l.from_user_id = :u AND l.action_type = 'return' AND (a.user_id IS NULL OR a.user_id != :u)
                    ORDER BY l.created_at DESC
                ");
                $hStmt->execute(['u' => $user_id]);
                $returnedHistory = $hStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }
    }
} elseif ($asset_id > 0) {
    $aStmt = $pdo->prepare("
        SELECT a.*, l.location_name, u.full_name as user_name, u.emp_id, u.department
        FROM assets a 
        LEFT JOIN locations l ON a.location_id = l.location_id 
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE a.asset_id = :a
    ");
    $aStmt->execute(['a' => $asset_id]);
    $assetRow = $aStmt->fetch(PDO::FETCH_ASSOC);
    if ($assetRow) {
        $assets = [$assetRow];
        if ($assetRow['user_id']) {
            $user = [
                'full_name' => $assetRow['user_name'],
                'emp_id'    => $assetRow['emp_id'],
                'department'=> $assetRow['department'],
                'location_name' => $assetRow['location_name']
            ];
        }
    }
}

// Append specified old asset being returned/exchanged
if ($old_asset_id > 0) {
    $oldStmt = $pdo->prepare("SELECT a.*, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id WHERE a.asset_id = :a");
    $oldStmt->execute(['a' => $old_asset_id]);
    $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
    if ($oldRow) {
        $oldRow['is_old_returned'] = true;
        array_unshift($assets, $oldRow);
    }
}

if (!$user && empty($assets)) {
    die("<div style='font-family:sans-serif; padding:40px; text-align:center;'>No valid employee or equipment record selected for Handover Certificate. <br><br><a href='javascript:window.close()'>Close Window</a></div>");
}

// Single Identifier Priority: Hostname > Service Tag > Serial Number > Name > Model > Category
function getItemDisplayIdentifier($item) {
    $hostname   = trim($item['hostname'] ?? '');
    $serviceTag = trim($item['service_tag'] ?? '');
    $sn         = trim($item['serial_number'] ?? '');
    $name       = trim($item['name'] ?? '');
    $model      = trim($item['model'] ?? '');
    $cat        = trim($item['category'] ?? 'Equipment');

    if ($hostname !== '' && $hostname !== '—') {
        return $hostname;
    } elseif ($serviceTag !== '' && $serviceTag !== '—') {
        return $serviceTag;
    } elseif ($sn !== '' && $sn !== '—' && $sn !== 'NO-SERIAL') {
        return $sn;
    } elseif ($name !== '' && $name !== '—') {
        return $name;
    } elseif ($model !== '' && $model !== '—') {
        return $model;
    } else {
        return $cat;
    }
}

$docPrefix = ($type === 'exchange') ? "EXC" : (($type === 'return') ? "RET" : "ACT");
$docNo = "IT-" . $docPrefix . "-" . date('Ymd') . "-" . str_pad($user_id ?: $asset_id, 4, '0', STR_PAD_LEFT);

$docTitle = ($type === 'exchange') 
            ? "IT ASSET EXCHANGE & REPLACEMENT CERTIFICATE" 
            : (($type === 'return') 
                ? ($reason === 'resignation' ? "IT ASSET RETURN & RESIGNATION CLEARANCE CERTIFICATE" : "IT ASSET RETURN CERTIFICATE")
                : "IT ASSET HANDOVER CERTIFICATE");

$purposeText = ($type === 'exchange')
            ? "Hardware Replacement & Equipment Refresh (Machine Exchange)"
            : (($type === 'return')
                ? ($reason === 'resignation' ? "Equipment Return & IT Account Clearance due to Employee Resignation" : "Hardware Return to IT Inventory")
                : "Official IT Equipment & Account Provisioning");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($docTitle) ?> — <?= htmlspecialchars($docNo) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: #090d16;
        margin: 0;
        padding: 24px;
        color: #0f172a;
        -webkit-font-smoothing: antialiased;
    }

    /* Screen Management Toolbar */
    .screen-toolbar {
        max-width: 860px;
        margin: 0 auto 24px;
        background: #1e293b;
        color: #ffffff;
        border-radius: 12px;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
        border: 1px solid #334155;
    }
    .toolbar-info { display: flex; align-items: center; gap: 12px; }
    .doc-tag {
        background: #334155;
        color: #38bdf8;
        font-family: monospace;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
    }
    .btn {
        padding: 9px 18px;
        border-radius: 8px;
        border: none;
        background: #2563eb;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.15s ease;
    }
    .btn:hover { background: #1d4ed8; }
    .btn-ghost { background: #334155; color: #e2e8f0; }
    .btn-ghost:hover { background: #475569; color: #fff; }

    /* Builder Card */
    .builder-card {
        max-width: 860px;
        margin: 0 auto 24px;
        background: #1e293b;
        border: 1px solid #334155;
        border-radius: 12px;
        padding: 16px 22px;
        color: #fff;
    }
    .builder-label {
        font-size: 11px;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 10px;
    }
    .preset-btn-group {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }
    .btn-preset {
        background: #0f172a;
        border: 1px solid #334155;
        color: #38bdf8;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-preset:hover {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    .builder-row { display: flex; gap: 10px; }
    .builder-row input {
        background: #0f172a;
        border: 1px solid #334155;
        color: #fff;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 12px;
    }
    .builder-row input::placeholder { color: #64748b; }
    .btn-add { background: #10b981; color: #fff; font-weight: 700; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 12px; }
    .btn-add:hover { background: #059669; }

    /* Pure Corporate Printable Certificate */
    .certificate-paper {
        max-width: 860px;
        margin: 0 auto;
        background: #ffffff;
        padding: 52px;
        border-radius: 2px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        box-sizing: border-box;
        border-top: 6px solid #1e293b;
        position: relative;
    }

    .cert-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #0f172a;
        padding-bottom: 16px;
        margin-bottom: 24px;
    }
    .brand-title {
        font-size: 26px;
        font-weight: 900;
        color: #0f172a;
        letter-spacing: -0.03em;
        text-transform: uppercase;
        line-height: 1;
    }
    .brand-subtitle {
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-top: 6px;
    }

    .cert-title-block { text-align: right; }
    .cert-title-en {
        font-size: 16px;
        font-weight: 900;
        color: #0f172a;
        letter-spacing: -0.01em;
        text-transform: uppercase;
    }
    .cert-meta {
        font-size: 11px;
        color: #64748b;
        margin-top: 4px;
    }

    /* Grid Details */
    .grid-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 28px;
        border: 1px solid #cbd5e1;
    }
    .grid-table td {
        padding: 9px 14px;
        border: 1px solid #cbd5e1;
        font-size: 12px;
    }
    .grid-label {
        background: #f8fafc;
        font-weight: 700;
        color: #334155;
        width: 18%;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.02em;
    }

    /* Tables */
    .table-section-head {
        font-size: 11.5px;
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 8px;
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 24px;
    }
    .data-table th {
        background: #0f172a;
        color: #ffffff;
        padding: 10px 14px;
        font-size: 10.5px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        text-align: left;
        border: 1px solid #0f172a;
    }
    .data-table td {
        padding: 11px 14px;
        font-size: 12px;
        border: 1px solid #cbd5e1;
        vertical-align: middle;
    }
    .data-table tr:nth-child(even) { background: #f8fafc; }

    /* Clearance Checklist */
    .clearance-box {
        background: #fafafa;
        border: 1px solid #0f172a;
        padding: 14px 18px;
        margin-bottom: 28px;
        font-size: 11.5px;
    }
    .clearance-title {
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        font-size: 11px;
        margin-bottom: 10px;
    }
    .clearance-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        font-weight: 600;
        color: #334155;
    }
    .clearance-chk { display: flex; align-items: center; gap: 8px; }
    .box-icon {
        width: 15px; height: 15px; border: 1.5px solid #0f172a; border-radius: 2px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 900; background: #fff;
    }

    /* Terms */
    .terms-card {
        border-top: 1px solid #e2e8f0;
        padding-top: 14px;
        font-size: 10.5px;
        color: #64748b;
        line-height: 1.6;
        margin-bottom: 32px;
    }

    /* Signatures */
    .sig-grid {
        width: 100%;
        border-collapse: collapse;
        margin-top: 40px;
    }
    .sig-cell {
        width: 25%;
        text-align: center;
        padding: 8px;
        vertical-align: bottom;
    }
    .sig-line-box {
        border-bottom: 1.5px dashed #64748b;
        height: 60px;
        margin-bottom: 8px;
    }
    .sig-title {
        font-size: 11px;
        font-weight: 800;
        color: #0f172a;
        text-transform: uppercase;
    }
    .sig-sub {
        font-size: 10px;
        color: #64748b;
        margin-top: 2px;
    }

    .btn-del-item {
        color: #dc2626; cursor: pointer; font-weight: bold; border: none; background: none; font-size: 16px; margin-left: 6px;
    }

    @media print {
        body { background: #ffffff; padding: 0; }
        .screen-toolbar, .builder-card, .btn-del-item { display: none !important; }
        .certificate-paper { box-shadow: none; padding: 0; border-top: none; }
        @page { margin: 1.5cm; }
    }
</style>
</head>
<body>

<!-- Screen Management Toolbar -->
<div class="screen-toolbar">
    <div class="toolbar-info">
        <strong style="font-size:15px; color:#fff;"><?= htmlspecialchars($docTitle) ?></strong>
        <span class="doc-tag"><?= $docNo ?></span>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-ghost" onclick="window.close()">Close</button>
        <button class="btn" onclick="window.print()">Print Certificate</button>
    </div>
</div>

<!-- Non-Inventory Custom Items Builder -->
<div class="builder-card">
    <div class="builder-label">Quick Add Standard Digital Accounts & Services (Click to Include):</div>
    <div class="preset-btn-group">
        <button type="button" class="btn-preset" onclick="addPresetItem('Software / Access', 'Microsoft 365 &amp; Corporate Email Account')">+ MS365 &amp; Email Account</button>
        <button type="button" class="btn-preset" onclick="addPresetItem('Software / Access', 'Corporate VPN &amp; Remote Network Access')">+ VPN Access</button>
        <button type="button" class="btn-preset" onclick="addPresetItem('Software / Access', 'Dynamics AX System Privileges &amp; Account')">+ Dynamic AX Access</button>
    </div>
    <div class="builder-label" style="font-size:10px; color:#64748b;">Or type custom non-inventory account below:</div>
    <div class="builder-row">
        <input type="text" id="customCat" placeholder="Category (e.g. Software / Access)" style="width:220px;">
        <input type="text" id="customName" placeholder="Item Description (e.g. AutoCAD License / Shared Drive)" style="flex:1;">
        <button type="button" onclick="addCustomItem()" class="btn-add">+ Add Row</button>
    </div>
</div>

<!-- Pure Corporate Printable Certificate Paper -->
<div class="certificate-paper">
    <!-- Header -->
    <table style="width:100%; border-collapse:collapse; margin-bottom:24px;">
        <tr>
            <td style="vertical-align:top;">
                <?php if ($comp_logo && file_exists(__DIR__ . '/' . $comp_logo)): ?>
                    <img src="<?= htmlspecialchars($comp_logo) ?>" alt="Company Logo" style="max-height:55px; max-width:220px; object-fit:contain; margin-bottom:4px; display:block;">
                    <div class="brand-subtitle"><?= htmlspecialchars($comp_dept) ?></div>
                <?php else: ?>
                    <div class="brand-title"><?= htmlspecialchars($comp_name) ?></div>
                    <div class="brand-subtitle"><?= htmlspecialchars($comp_dept) ?> &bull; Asset Management</div>
                <?php endif; ?>
            </td>
            <td class="cert-title-block" style="vertical-align:top;">
                <div class="cert-title-en"><?= htmlspecialchars($docTitle) ?></div>
                <div class="cert-meta">Document Reference: <strong style="color:#0f172a; font-family:monospace;"><?= $docNo ?></strong></div>
                <div class="cert-meta">Issuance Date: <strong><?= date('d M Y') ?></strong></div>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <table class="grid-table">
        <tr>
            <td class="grid-label">Employee Name</td>
            <td><strong><?= htmlspecialchars($user['full_name'] ?? 'Unassigned / IT Stock') ?></strong></td>
            <td class="grid-label">Employee ID</td>
            <td><?= htmlspecialchars($user['emp_id'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="grid-label">Department</td>
            <td><?= htmlspecialchars($user['department'] ?? '—') ?></td>
            <td class="grid-label">Location</td>
            <td><?= htmlspecialchars($user['location_name'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="grid-label">Action Purpose</td>
            <td><strong><?= htmlspecialchars($purposeText) ?></strong></td>
            <td class="grid-label">Ticket / Ref No.</td>
            <td><?= htmlspecialchars($_GET['ticket'] ?? ('TK-' . date('Ymd'))) ?></td>
        </tr>
    </table>

    <!-- Equipment List Table -->
    <div class="table-section-head">Authorized IT Equipment & Account Provisioning List</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:5%; text-align:center;">#</th>
                <th style="width:20%;">Category</th>
                <th style="width:40%;">Device / Account Description</th>
                <th style="width:20%;">Serial No. / Tag</th>
                <th style="width:15%;">Status</th>
            </tr>
        </thead>
        <tbody id="certTableBody">
            <?php 
            $i = 1;
            $allRows = array_merge($assets, $accessories);
            if (empty($allRows) && empty($software)): 
            ?>
            <tr id="emptyCertRow">
                <td colspan="5" style="text-align:center; color:#94a3b8; padding:24px;">No equipment records found. Click preset buttons above to include digital accounts.</td>
            </tr>
            <?php else: 
                foreach ($allRows as $item): 
                    $itemIdentifier = getItemDisplayIdentifier($item);
                    $sn  = $item['serial_number'] ?: ($item['service_tag'] ?: 'NO-SERIAL');
                    $cat = $item['category'] ?? 'Hardware';

                    if (!empty($item['is_old_returned'])) {
                        $stText  = 'RETURNED (OLD)';
                        $stColor = '#dc2626';
                    } elseif ($type === 'exchange') {
                        $stText  = 'HANDED OVER (NEW)';
                        $stColor = '#059669';
                    } elseif ($type === 'return') {
                        $stText  = 'RETURNED';
                        $stColor = '#dc2626';
                    } else {
                        $stText  = 'HANDED OVER';
                        $stColor = '#059669';
                    }
            ?>
            <tr>
                <td style="text-align:center; font-weight:700;" class="row-index"><?= $i++ ?></td>
                <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                <td><div style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($itemIdentifier) ?></div></td>
                <td style="font-family:monospace; font-weight:700; color:#0f172a;"><?= htmlspecialchars($sn) ?></td>
                <td><span style="font-weight:700; color:<?= $stColor ?>;"><?= $stText ?></span></td>
            </tr>
            <?php endforeach; ?>

            <!-- Software Licenses in DB -->
            <?php foreach ($software as $sw): ?>
            <tr>
                <td style="text-align:center; font-weight:700;" class="row-index"><?= $i++ ?></td>
                <td><strong>Software</strong></td>
                <td>
                    <div style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($sw['software_name']) ?></div>
                </td>
                <td style="font-family:monospace; font-weight:700; color:#0f172a;"><?= htmlspecialchars($sw['license_key'] ?: 'Digital Account') ?></td>
                <td><span style="font-weight:700; color:<?= $type === 'return' ? '#dc2626' : '#059669' ?>;"><?= $type === 'return' ? 'REVOKED' : 'ACTIVE' ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- Resignation Clearance Checklist (Visible on Return / Resignation) -->
    <?php if ($type === 'return' || $reason === 'resignation'): ?>
    <div class="clearance-box">
        <div class="clearance-title">Official IT Resignation Clearance Checklist:</div>
        <div class="clearance-grid">
            <div class="clearance-chk"><span class="box-icon">[✓]</span> Hardware &amp; Accessories Returned to IT Inventory</div>
            <div class="clearance-chk"><span class="box-icon">[✓]</span> Microsoft 365 &amp; Corporate Email Disabled</div>
            <div class="clearance-chk"><span class="box-icon">[✓]</span> VPN &amp; Remote Network Credentials Revoked</div>
            <div class="clearance-chk"><span class="box-icon">[✓]</span> Dynamics AX System Access Privileges Removed</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Terms -->
    <div class="terms-card">
        <strong>Terms & Conditions of IT Assets Provisioning:</strong><br>
        1. The undersigned employee acknowledges receipt/return of all listed corporate IT assets and accounts in verified condition.<br>
        2. All assigned assets are corporate property and must be utilized strictly for authorized business operations in compliance with Security Policies.<br>
        3. Upon employment termination, transfer, or request, the employee agrees to immediately return all assigned hardware and access tokens to IT.
    </div>

    <!-- Signatures Grid -->
    <table class="sig-grid">
        <tr>
            <td class="sig-cell">
                <div class="sig-line-box"></div>
                <div class="sig-title">Employee Signature</div>
                <div class="sig-sub">Date: ____ / ____ / ________</div>
            </td>
            <td class="sig-cell">
                <div class="sig-line-box"></div>
                <div class="sig-title">Head of Department</div>
                <div class="sig-sub">Date: ____ / ____ / ________</div>
            </td>
            <td class="sig-cell">
                <div class="sig-line-box"></div>
                <div class="sig-title">Head of IT</div>
                <div class="sig-sub">Date: ____ / ____ / ________</div>
            </td>
            <td class="sig-cell">
                <div class="sig-line-box"></div>
                <div class="sig-title">IT Administrator</div>
                <div class="sig-sub">Date: ____ / ____ / ________</div>
            </td>
        </tr>
    </table>
</div>

<script>
    let certCount = <?= count($assets) + count($accessories) + count($software) ?>;

    function addPresetItem(cat, name) {
        document.getElementById('customCat').value = cat;
        document.getElementById('customName').value = name;
        addCustomItem();
    }

    function addCustomItem() {
        const catInput = document.getElementById('customCat');
        const nameInput = document.getElementById('customName');
        const cat = catInput.value.trim() || 'Software / Access';
        const name = nameInput.value.trim();

        if (!name) {
            alert('Please enter Item Description');
            return;
        }

        const empty = document.getElementById('emptyCertRow');
        if (empty) empty.remove();

        certCount++;
        const tbody = document.getElementById('certTableBody');
        const tr = document.createElement('tr');
        
        tr.innerHTML = `
            <td style="text-align:center; font-weight:700;" class="row-index">${certCount}</td>
            <td><strong>${escapeHtml(cat)}</strong></td>
            <td><div style="font-weight:600; color:#0f172a;">${escapeHtml(name)}</div></td>
            <td style="font-family:monospace; font-weight:700; color:#0f172a;">Digital Account</td>
            <td>
                <span style="font-weight:700; color:<?= $type === 'return' ? '#dc2626' : '#059669' ?>;"><?= $type === 'return' ? 'REVOKED' : 'ACTIVE' ?></span>
                <button type="button" onclick="this.closest('tr').remove(); reindexCert();" class="btn-del-item" title="Remove">&times;</button>
            </td>
        `;

        tbody.appendChild(tr);
        nameInput.value = '';
    }

    function reindexCert() {
        let idx = 1;
        document.querySelectorAll('#certTableBody tr').forEach(tr => {
            const el = tr.querySelector('.row-index');
            if (el) el.innerText = idx++;
        });
        certCount = idx - 1;
    }

    function escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
</script>

</body>
</html>
