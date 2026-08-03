<?php
// print_batch_handover.php — Continuous Batch Handover Certificate Printer for Multiple Employees
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';

$comp_name = _auth_get_setting($pdo, 'company_name', 'COMPANY NAME');
$comp_dept = _auth_get_setting($pdo, 'company_dept', 'Information Technology Department');
$comp_logo = _auth_get_setting($pdo, 'company_logo', '');

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

// Parse User IDs
$userIdsRaw = $_POST['user_ids'] ?? $_GET['user_ids'] ?? [];
if (is_string($userIdsRaw)) {
    $userIdsRaw = explode(',', $userIdsRaw);
}
$userIds = array_values(array_filter(array_map('intval', (array)$userIdsRaw)));

$type   = $_GET['type'] ?? $_POST['type'] ?? 'assign'; // 'assign' or 'return'
$reason = $_GET['reason'] ?? $_POST['reason'] ?? ($type === 'return' ? 'resignation' : 'handover');

if (empty($userIds)) {
    $userIds = $pdo->query("SELECT DISTINCT user_id FROM assets WHERE user_id IS NOT NULL UNION SELECT DISTINCT user_id FROM accessories WHERE user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($userIds)) {
    die("<div style='font-family:sans-serif; padding:40px; text-align:center;'>No employees selected for Batch Handover Printing. <br><br><a href='javascript:window.close()'>Close Window</a></div>");
}

// Fetch all selected users & data
$inSql = implode(',', array_fill(0, count($userIds), '?'));

$uStmt = $pdo->prepare("
    SELECT u.*, l.location_name 
    FROM users u 
    LEFT JOIN locations l ON u.location_id = l.location_id 
    WHERE u.user_id IN ($inSql)
    ORDER BY u.full_name ASC
");
$uStmt->execute($userIds);
$userList = $uStmt->fetchAll(PDO::FETCH_ASSOC);

$docTitleEn = ($type === 'return') 
            ? ($reason === 'resignation' ? "IT ASSET RETURN & RESIGNATION CLEARANCE CERTIFICATE" : "IT ASSET RETURN CERTIFICATE")
            : "IT ASSET HANDOVER CERTIFICATE";
$purposeText = ($type === 'return')
            ? ($reason === 'resignation' ? "Equipment Return & IT Account Clearance due to Employee Resignation" : "Hardware Return to IT Inventory")
            : "Official IT Equipment & Account Provisioning";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Batch IT Certificates (<?= count($userList) ?> Employees)</title>
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
        margin: 0 auto 16px;
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
    .batch-chk-group {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .batch-chk-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #0f172a;
        border: 1px solid #334155;
        padding: 7px 12px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 700;
        color: #38bdf8;
        cursor: pointer;
        user-select: none;
        transition: all 0.15s ease;
    }
    .batch-chk-item:hover {
        border-color: #2563eb;
        background: #1e293b;
    }
    .batch-chk-item input[type="checkbox"] {
        accent-color: #2563eb;
        width: 15px;
        height: 15px;
        cursor: pointer;
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

    /* Printable Page Container */
    .certificate-paper {
        max-width: 860px;
        margin: 0 auto 40px;
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
        .certificate-paper { box-shadow: none; padding: 0; border-top: none; margin-bottom: 0; page-break-after: always; page-break-inside: avoid; }
        @page { margin: 1.5cm; }
    }
</style>
</head>
<body>

<!-- Screen Management Toolbar -->
<div class="screen-toolbar">
    <div class="toolbar-info">
        <strong style="font-size:15px; color:#fff;">Continuous Batch Printing (<?= count($userList) ?> Employees)</strong>
        <span class="doc-tag">Batch Print Mode</span>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-ghost" onclick="window.close()">Close</button>
        <button class="btn" onclick="window.print()">Print All <?= count($userList) ?> Certificates</button>
    </div>
</div>

<!-- Non-Inventory Custom Items Batch Builder -->
<div class="builder-card">
    <div class="builder-label">Include Standard Digital Accounts & Permissions (Toggle to Add to ALL Certificates):</div>
    <div class="batch-chk-group">
        <label class="batch-chk-item">
            <input type="checkbox" onchange="toggleBatchPreset('Software / Access', 'Microsoft 365 &amp; Corporate Email Account', this.checked)">
            <span>+ MS365 &amp; Email Account</span>
        </label>
        <label class="batch-chk-item">
            <input type="checkbox" onchange="toggleBatchPreset('Software / Access', 'Corporate VPN &amp; Remote Network Access', this.checked)">
            <span>+ VPN Access</span>
        </label>
        <label class="batch-chk-item">
            <input type="checkbox" onchange="toggleBatchPreset('Software / Access', 'Dynamics AX System Privileges &amp; Account', this.checked)">
            <span>+ Dynamic AX Access</span>
        </label>
    </div>
    <div class="builder-label" style="font-size:10px; color:#64748b;">Or type custom non-inventory account to include for all employees:</div>
    <div class="builder-row">
        <input type="text" id="customBatchCat" placeholder="Category (e.g. Software / Access)" style="width:220px;">
        <input type="text" id="customBatchName" placeholder="Item Description (e.g. AutoCAD License / Shared Drive)" style="flex:1;">
        <button type="button" onclick="addCustomBatchItem()" class="btn-add">+ Add to All Certificates</button>
    </div>
</div>

<?php 
foreach ($userList as $u): 
    $uid = (int)$u['user_id'];

    $aStmt = $pdo->prepare("SELECT a.*, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id WHERE a.user_id = :u");
    $aStmt->execute(['u' => $uid]);
    $uAssets = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    $acStmt = $pdo->prepare("SELECT ac.*, l.location_name FROM accessories ac LEFT JOIN locations l ON ac.location_id = l.location_id WHERE ac.user_id = :u");
    $acStmt->execute(['u' => $uid]);
    $uAccessories = $acStmt->fetchAll(PDO::FETCH_ASSOC);

    $swList = [];
    try {
        $swStmt = $pdo->prepare("
            SELECT sl.*, s.software_name, sb.batch_name
            FROM software_licenses sl
            JOIN software s ON sl.software_id = s.software_id
            LEFT JOIN software_batches sb ON sl.batch_id = sb.batch_id
            WHERE sl.user_id = :u AND sl.license_key IS NOT NULL AND TRIM(sl.license_key) != ''
        ");
        $swStmt->execute(['u' => $uid]);
        $swList = $swStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $docNo = "IT-" . ($type === 'return' ? "RET" : "ACT") . "-" . date('Ymd') . "-" . str_pad($uid, 4, '0', STR_PAD_LEFT);
?>

<!-- Individual Printable Certificate Paper -->
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
                <div class="cert-title-en"><?= htmlspecialchars($docTitleEn) ?></div>
                <div class="cert-meta">Document Reference: <strong style="color:#0f172a; font-family:monospace;"><?= $docNo ?></strong></div>
                <div class="cert-meta">Issuance Date: <strong><?= date('d M Y') ?></strong></div>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <table class="grid-table">
        <tr>
            <td class="grid-label">Employee Name</td>
            <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
            <td class="grid-label">Employee ID</td>
            <td><?= htmlspecialchars($u['emp_id'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="grid-label">Department</td>
            <td><?= htmlspecialchars($u['department'] ?? '—') ?></td>
            <td class="grid-label">Location</td>
            <td><?= htmlspecialchars($u['location_name'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="grid-label">Action Purpose</td>
            <td><strong><?= htmlspecialchars($purposeText) ?></strong></td>
            <td class="grid-label">Ticket / Ref No.</td>
            <td>TK-BATCH-<?= date('Ymd') ?></td>
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
        <tbody class="cert-table-body">
            <?php 
            $i = 1;
            $allRows = array_merge($uAssets, $uAccessories);
            if (empty($allRows) && empty($swList)): 
            ?>
            <tr class="empty-batch-row">
                <td colspan="5" style="text-align:center; color:#94a3b8; padding:24px;">No hardware items assigned. Use toolbar above to include digital accounts.</td>
            </tr>
            <?php else: 
                foreach ($allRows as $item): 
                    $itemIdentifier = getItemDisplayIdentifier($item);
                    $sn  = $item['serial_number'] ?: ($item['service_tag'] ?: 'NO-SERIAL');
                    $cat = $item['category'] ?? 'Hardware';
            ?>
            <tr>
                <td style="text-align:center; font-weight:700;" class="row-index"><?= $i++ ?></td>
                <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                <td><div style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($itemIdentifier) ?></div></td>
                <td style="font-family:monospace; font-weight:700; color:#0f172a;"><?= htmlspecialchars($sn) ?></td>
                <td><span style="font-weight:700; color:<?= $type === 'return' ? '#dc2626' : '#059669' ?>;"><?= $type === 'return' ? 'RETURNED' : 'HANDED OVER' ?></span></td>
            </tr>
            <?php endforeach; ?>

            <!-- Software Licenses in DB -->
            <?php foreach ($swList as $sw): ?>
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

<?php endforeach; ?>

<script>
    function toggleBatchPreset(cat, name, isChecked) {
        const slug = 'preset-' + name.replace(/[^a-z0-9]/gi, '_');
        
        document.querySelectorAll('.cert-table-body').forEach(tbody => {
            const empty = tbody.querySelector('.empty-batch-row');
            if (isChecked) {
                if (tbody.querySelector('.' + slug)) return;
                if (empty) empty.style.display = 'none';

                const tr = document.createElement('tr');
                tr.className = 'batch-preset-row ' + slug;
                const stText = '<?= $type === 'return' ? 'REVOKED' : 'ACTIVE' ?>';
                const stColor = '<?= $type === 'return' ? '#dc2626' : '#059669' ?>';

                tr.innerHTML = `
                    <td style="text-align:center; font-weight:700;" class="row-index">#</td>
                    <td><strong>${escapeHtml(cat)}</strong></td>
                    <td><div style="font-weight:600; color:#0f172a;">${escapeHtml(name)}</div></td>
                    <td style="font-family:monospace; font-weight:700; color:#0f172a;">Digital Account</td>
                    <td><span style="font-weight:700; color:${stColor};">${stText}</span></td>
                `;
                tbody.appendChild(tr);
            } else {
                const tr = tbody.querySelector('.' + slug);
                if (tr) tr.remove();
            }
            reindexTbody(tbody);
        });
    }

    function addCustomBatchItem() {
        const catInput = document.getElementById('customBatchCat');
        const nameInput = document.getElementById('customBatchName');
        const cat = catInput.value.trim() || 'Software / Access';
        const name = nameInput.value.trim();

        if (!name) {
            alert('Please enter Custom Account Description');
            return;
        }

        const slug = 'custom-' + name.replace(/[^a-z0-9]/gi, '_');

        document.querySelectorAll('.cert-table-body').forEach(tbody => {
            const empty = tbody.querySelector('.empty-batch-row');
            if (empty) empty.style.display = 'none';

            const tr = document.createElement('tr');
            tr.className = 'batch-preset-row ' + slug;
            const stText = '<?= $type === 'return' ? 'REVOKED' : 'ACTIVE' ?>';
            const stColor = '<?= $type === 'return' ? '#dc2626' : '#059669' ?>';

            tr.innerHTML = `
                <td style="text-align:center; font-weight:700;" class="row-index">#</td>
                <td><strong>${escapeHtml(cat)}</strong></td>
                <td><div style="font-weight:600; color:#0f172a;">${escapeHtml(name)}</div></td>
                <td style="font-family:monospace; font-weight:700; color:#0f172a;">Digital Account</td>
                <td>
                    <span style="font-weight:700; color:${stColor};">${stText}</span>
                    <button type="button" onclick="removeBatchRow('${slug}', this)" class="btn-del-item" title="Remove">&times;</button>
                </td>
            `;
            tbody.appendChild(tr);
            reindexTbody(tbody);
        });

        nameInput.value = '';
    }

    function removeBatchRow(slug, btnEl) {
        document.querySelectorAll('.' + slug).forEach(tr => {
            const tbody = tr.closest('.cert-table-body');
            tr.remove();
            if (tbody) reindexTbody(tbody);
        });
    }

    function reindexTbody(tbody) {
        let idx = 1;
        tbody.querySelectorAll('tr').forEach(tr => {
            if (tr.style.display === 'none') return;
            const el = tr.querySelector('.row-index');
            if (el) el.innerText = idx++;
        });
    }

    function escapeHtml(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
</script>

</body>
</html>
