<?php
// users.php — User List with expandable asset rows
require_once 'includes/db_connect.php';

// Filter from GET
$filterDept = $_GET['dept'] ?? 'all';
$showTab    = $_GET['tab']  ?? 'active'; // active | resigned

$where  = ($showTab === 'resigned') ? "WHERE u.resigned_at IS NOT NULL" : "WHERE u.resigned_at IS NULL";
$params = [];
if ($filterDept !== 'all') {
    $where  .= " AND u.department = :dept";
    $params['dept'] = $filterDept;
}

// Fetch users with their asset and accessory counts combined, and join location
$stmt = $pdo->prepare("
    SELECT u.*, l.location_name,
           (SELECT COUNT(*) FROM assets WHERE user_id = u.user_id) +
           (SELECT COUNT(*) FROM accessories WHERE user_id = u.user_id) as asset_count,

           (SELECT COUNT(*) FROM assets WHERE user_id = u.user_id AND status = 'deployed') +
           (SELECT COUNT(*) FROM accessories WHERE user_id = u.user_id AND status = 'deployed') as deployed_count,

           (SELECT COUNT(*) FROM software_licenses WHERE user_id = u.user_id AND status != 'revoked') as software_count
    FROM   users u
    LEFT JOIN locations l ON u.location_id = l.location_id
    $where
    GROUP BY u.user_id
    ORDER BY u.full_name ASC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts for tab badges
$activeCount   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE resigned_at IS NULL")->fetchColumn();
$resignedCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE resigned_at IS NOT NULL")->fetchColumn();

// All unique departments (active only)
$departments = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' AND resigned_at IS NULL ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch all assigned assets grouped by user_id for expandable sub-rows
if ($showTab === 'active') {
    $allAssets = $pdo->query("
        SELECT a.asset_id, a.user_id, a.category, a.hostname, a.serial_number,
               a.status, a.ownership_type, l.location_name
        FROM   assets a
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE  a.user_id IS NOT NULL
        ORDER BY a.category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $allAssets = $pdo->query("
        SELECT a.asset_id, l.from_user_id as user_id, a.category, a.hostname, a.serial_number,
               a.status, a.ownership_type, loc.location_name
        FROM   logs l
        JOIN   assets a ON l.asset_id = a.asset_id
        LEFT JOIN locations loc ON a.location_id = loc.location_id
        WHERE  l.from_user_id IS NOT NULL AND l.note LIKE '%resignation%'
        GROUP BY a.asset_id, l.from_user_id
        ORDER BY a.category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Group assets by user_id
$assetsByUser = [];
foreach ($allAssets as $asset) {
    $assetsByUser[$asset['user_id']][] = $asset;
}

// Fetch all assigned accessories grouped by user_id
if ($showTab === 'active') {
    $allAccessories = $pdo->query("
        SELECT ac.accessory_id, ac.user_id, ac.category, ac.name, ac.serial_number,
               ac.status, ac.ownership_type, l.location_name,
               pa.hostname as parent_hostname
        FROM   accessories ac
        LEFT JOIN locations l  ON ac.location_id = l.location_id
        LEFT JOIN assets    pa ON ac.parent_asset_id = pa.asset_id
        WHERE  ac.user_id IS NOT NULL
        ORDER BY ac.category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $allAccessories = $pdo->query("
        SELECT ac.accessory_id, l.from_user_id as user_id, ac.category, ac.name, ac.serial_number,
               ac.status, ac.ownership_type, loc.location_name,
               pa.hostname as parent_hostname
        FROM   logs l
        JOIN   accessories ac ON l.accessory_id = ac.accessory_id
        LEFT JOIN locations loc ON ac.location_id = loc.location_id
        LEFT JOIN assets pa ON ac.parent_asset_id = pa.asset_id
        WHERE  l.from_user_id IS NOT NULL AND l.note LIKE '%resignation%'
        GROUP BY ac.accessory_id, l.from_user_id
        ORDER BY ac.category ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Group accessories by user_id
$accByUser = [];
foreach ($allAccessories as $acc) {
    $accByUser[$acc['user_id']][] = $acc;
}

// Fetch all assigned software licenses grouped by user_id
if ($showTab === 'active') {
    $allSoftware = $pdo->query("
        SELECT l.license_id, l.user_id, l.license_key, l.status, l.expiry_date, 
               s.name as software_name, s.category
        FROM software_licenses l
        JOIN software_batches b ON l.batch_id = b.batch_id
        JOIN software s ON b.software_id = s.software_id
        WHERE l.user_id IS NOT NULL AND l.status != 'revoked'
        ORDER BY s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    try {
        $allSoftware = $pdo->query("
            SELECT l.license_id, CAST(lg.old_value AS INTEGER) as user_id, l.license_key, l.status, l.expiry_date, 
                   s.name as software_name, s.category
            FROM software_license_logs lg
            JOIN software_licenses l ON lg.license_id = l.license_id
            JOIN software_batches b ON l.batch_id = b.batch_id
            JOIN software s ON b.software_id = s.software_id
            WHERE lg.action IN ('revoke', 'unassign') AND lg.note LIKE '%resignation%'
            GROUP BY l.license_id, lg.old_value
            ORDER BY s.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $allSoftware = []; }
}

// Group software by user_id
$swByUser = [];
foreach ($allSoftware as $sw) {
    if ($sw['user_id']) $swByUser[$sw['user_id']][] = $sw;
}

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

function badge($status) {
    $safe  = htmlspecialchars($status);
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class='badge badge-{$safe}'>{$label}</span>";
}

$pageTitle = 'Users & Assets';
$activeNav = 'users';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'assign_success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Asset assigned successfully.</div>
<?php elseif ($msg === 'return_success'): ?>
    <div class="alert alert-info"><i class="bi bi-arrow-return-left"></i> Asset returned to stock.</div>
<?php elseif ($msg === 'success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Action completed successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; background:var(--color-surface);">
        <div style="display:flex; justify-content:space-between; align-items:center; width:100%; flex-wrap:wrap; gap:16px;">
            <div>
                <h3 style="font-size:1.1rem; margin:0;"><i class="bi bi-people" style="color:var(--color-accent); margin-right:8px;"></i> <?= htmlspecialchars(__('Users & Assigned Assets')) ?></h3>
                <span class="text-muted" style="font-size:.8rem; margin-top:4px; display:block;"><?= count($users) ?> <?= htmlspecialchars(__('users')) ?> <?= $filterDept !== 'all' ? htmlspecialchars(__('in')) . " $filterDept" : "" ?> — <?= htmlspecialchars(__('click a row to expand')) ?></span>
            </div>

            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <!-- Active / Resigned tabs -->
                <div style="display:flex; gap:4px; background:var(--color-bg); padding:3px; border-radius:8px;">
                    <a href="users.php?tab=active" style="padding:6px 14px; font-size:.82rem; font-weight:600; border-radius:6px; text-decoration:none;
                        background:<?= $showTab==='active' ? '#fff' : 'transparent' ?>;
                        color:<?= $showTab==='active' ? 'var(--color-accent-dark)' : 'var(--color-text-muted)' ?>;
                        box-shadow:<?= $showTab==='active' ? '0 1px 3px rgba(0,0,0,.08)' : 'none' ?>;">
                        <i class="bi bi-people"></i> <?= htmlspecialchars(__('Active')) ?>
                        <span style="font-size:.7rem; opacity:.7; margin-left:4px;"><?= $activeCount ?></span>
                    </a>
                    <a href="users.php?tab=resigned" style="padding:6px 14px; font-size:.82rem; font-weight:600; border-radius:6px; text-decoration:none;
                        background:<?= $showTab==='resigned' ? '#fff' : 'transparent' ?>;
                        color:<?= $showTab==='resigned' ? '#991b1b' : 'var(--color-text-muted)' ?>;
                        box-shadow:<?= $showTab==='resigned' ? '0 1px 3px rgba(0,0,0,.08)' : 'none' ?>;">
                        <i class="bi bi-person-dash"></i> <?= htmlspecialchars(__('Resigned')) ?>
                        <span style="font-size:.7rem; opacity:.7; margin-left:4px;"><?= $resignedCount ?></span>
                    </a>
                </div>

                <form method="GET" style="display:flex; gap:10px; align-items:center; margin:0;">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($showTab) ?>">
                    <div style="position:relative;">
                        <i class="bi bi-building" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--color-text-muted);font-size:12px;"></i>
                        <select name="dept" class="form-control form-select" style="width:180px;padding:6px 24px 6px 30px;font-size:.8rem; border-radius:6px;" onchange="this.form.submit()">
                            <option value="all" <?= $filterDept === 'all' ? 'selected' : '' ?>><?= htmlspecialchars(__('All Departments')) ?></option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= htmlspecialchars($d) ?>" <?= $filterDept === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($filterDept !== 'all'): ?>
                        <a href="users.php?tab=<?= htmlspecialchars($showTab) ?>" class="btn btn-ghost btn-sm" style="padding:6px 10px;color:var(--color-danger); border-radius:6px;" title="Clear Filter"><i class="bi bi-x-circle"></i></a>
                    <?php endif; ?>
                </form>

                <?php if ($showTab === 'active'): ?>
                <button onclick="document.getElementById('addUserModal').style.display='flex'" class="btn btn-primary btn-sm edit-only" style="padding:7px 16px; border-radius:6px;">
                    <i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Add User')) ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:32px;"></th>
                    <th><?= htmlspecialchars(__('Employee ID')) ?></th>
                    <th><?= htmlspecialchars(__('Full Name')) ?></th>
                    <th><?= htmlspecialchars(__('Email')) ?></th>
                    <th><?= htmlspecialchars(__('Department')) ?> / <?= htmlspecialchars(__('Location')) ?></th>
                    <th><?= htmlspecialchars(__('Total Assigned')) ?></th>
                    <th><?= htmlspecialchars(__('Deployment Status')) ?></th>
                    <th style="width:40px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="8">
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <p><?= htmlspecialchars(__('No users found in the system.')) ?></p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <?php
                        $uid   = $user['user_id'];
                        $uAssets = $assetsByUser[$uid] ?? [];
                        $uAccessories = $accByUser[$uid] ?? [];
                        $uSoftware = $swByUser[$uid] ?? [];
                        $hasAssets = count($uAssets) > 0 || count($uAccessories) > 0 || count($uSoftware) > 0;
                        $displayAssetCount = count($uAssets) + count($uAccessories);
                        $displaySoftwareCount = count($uSoftware);
                    ?>

                    <!-- ─── User Row ─── -->
                    <tr class="user-row"
                        data-uid="<?= $uid ?>"
                        id="user-<?= $uid ?>">
                        <td style="text-align:center; padding-left:20px; color:#9ca3af;">
                            <?php if ($hasAssets): ?>
                                <i class="bi bi-chevron-right chevron" style="font-size:11px;"></i>
                            <?php else: ?>
                                <i class="bi bi-dash" style="font-size:11px; opacity:.3;"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-mono"><?= htmlspecialchars($user['emp_id'] ?? '—') ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:30px;height:30px;border-radius:50%;background:<?= $user['resigned_at'] ? '#f3f4f6' : '#dbeafe' ?>;color:<?= $user['resigned_at'] ? '#9ca3af' : '#2563eb' ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0;">
                                    <?= strtoupper(substr($user['full_name'],0,1)) ?>
                                </div>
                                <div>
                                    <span class="fw-600" style="<?= $user['resigned_at'] ? 'color:#6b7280;text-decoration:line-through;' : '' ?>"><?= htmlspecialchars($user['full_name']) ?></span>
                                    <?php if ($user['resigned_at']): ?>
                                        <div style="font-size:.7rem; color:#991b1b; margin-top:1px;">
                                            <i class="bi bi-person-dash"></i> <?= htmlspecialchars(__('Resigned')) ?> <?= htmlspecialchars($user['resigned_at']) ?>
                                            <?php if (!empty($user['resign_note'])): ?>
                                                <span class="text-muted"> · <?= htmlspecialchars($user['resign_note']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($user['email'])): ?>
                                <a href="mailto:<?= htmlspecialchars($user['email']) ?>" class="text-primary-link" style="font-size:.82rem;">
                                    <i class="bi bi-envelope" style="font-size:11px;"></i> <?= htmlspecialchars($user['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size:.85rem; font-weight:600;"><?= htmlspecialchars($user['department'] ?? '—') ?></div>
                            <div style="font-size:.75rem; color:#6b7280; margin-top:2px;">
                                <i class="bi bi-geo-alt" style="font-size:10px;"></i> <?= htmlspecialchars($user['location_name'] ?? __('No Location')) ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($displayAssetCount > 0): ?>
                                <span class="badge" style="background:#dbeafe;color:#1d4ed8;">
                                    <?= $displayAssetCount ?> <?= htmlspecialchars(__('item'.($displayAssetCount > 1 ? 's' : ''))) ?>
                                </span>
                            <?php elseif ($displaySoftwareCount == 0): ?>
                                <span class="text-muted"><?= htmlspecialchars(__('None')) ?></span>
                            <?php endif; ?>
                            
                            <?php if ($displaySoftwareCount > 0): ?>
                                <span class="badge" style="background:#fdf4ff;color:#c026d3; <?= $displayAssetCount > 0 ? 'margin-left:4px;' : '' ?>">
                                    <?= $displaySoftwareCount ?> <?= htmlspecialchars(__('lic'.($displaySoftwareCount > 1 ? 's' : ''))) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['deployed_count'] > 0): ?>
                                <span class="badge badge-deployed"><?= $user['deployed_count'] ?> <?= htmlspecialchars(__('deployed')) ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <button onclick="openEditUserModal(event, <?= $uid ?>, '<?= htmlspecialchars(addslashes($user['emp_id'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($user['full_name'])) ?>', '<?= htmlspecialchars(addslashes($user['department'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($user['email'] ?? '')) ?>', '<?= $user['location_id'] ?: 0 ?>')" class="btn btn-ghost btn-sm edit-only" style="padding:5px 8px; border-radius:6px;" title="<?= htmlspecialchars(__('Edit User')) ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>

                    <!-- ─── Expandable Asset Sub-row ─── -->
                    <tr class="asset-sub" data-uid="<?= $uid ?>">
                        <td colspan="8">
                            <div class="asset-sub-inner">

                                <?php
                                $latestSlip = $pdo->prepare("SELECT * FROM handover_slips WHERE user_id = :u ORDER BY created_at DESC LIMIT 1");
                                $latestSlip->execute(['u' => $uid]);
                                $slip = $latestSlip->fetch(PDO::FETCH_ASSOC);

                                $needsDocUpdate = false;
                                $updateReason = '';
                                if (!$slip) {
                                    $needsDocUpdate = true;
                                    $updateReason = 'No signed handover certificate on file for current assets.';
                                } else {
                                    $compareTime = $slip['signed_at'] ?? $slip['created_at'];
                                    $changeStmt = $pdo->prepare("
                                        SELECT COUNT(*) 
                                        FROM logs 
                                        WHERE (to_user_id = :u OR from_user_id = :u) 
                                          AND created_at > :t
                                    ");
                                    $changeStmt->execute(['u' => $uid, 't' => $compareTime]);
                                    if ((int)$changeStmt->fetchColumn() > 0) {
                                        $needsDocUpdate = true;
                                        $updateReason = 'Inventory has changed (new items assigned/returned) since the last certificate (' . $slip['doc_no'] . ').';
                                    }
                                }
                                ?>

                                <?php if ($needsDocUpdate && $showTab !== 'resigned'): ?>
                                <div style="background:#fffbeb; border:1px solid #fef3c7; color:#b45309; padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:.82rem; display:flex; align-items:center; gap:8px;">
                                    <i class="bi bi-exclamation-triangle-fill" style="font-size:1.1rem; color:#d97706;"></i>
                                    <div>
                                        <strong>Document Action Required:</strong> <?= htmlspecialchars($updateReason) ?> 
                                        Please print a new slip or have the employee write the changes on the existing sheet.
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Quick Assign Form: hidden for resigned users -->
                                <?php if ($showTab !== 'resigned'): ?>
                                <div class="assign-panel edit-only">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <div style="font-size:.8rem;font-weight:600;color:#374151;">
                                            <i class="bi bi-upc-scan" style="color:#2563eb;"></i>
                                            <?= htmlspecialchars(__('Quick Assign to')) ?> <?= htmlspecialchars($user['full_name']) ?>
                                        </div>
                                        <div style="display:flex; gap:6px;">
                                            <a href="print_handover.php?user_id=<?= $uid ?>&type=assign" target="_blank" class="btn btn-ghost btn-sm" style="padding:3px 10px; font-size:.78rem; border:1px solid #cbd5e1; font-weight:600;" title="Print Handover Slip">
                                                <i class="bi bi-printer" style="color:#2563eb;"></i> Handover Slip
                                            </a>
                                            <a href="print_handover.php?user_id=<?= $uid ?>&type=exchange" target="_blank" class="btn btn-ghost btn-sm" style="padding:3px 10px; font-size:.78rem; border:1px solid #cbd5e1; font-weight:600;" title="Print Exchange & Replacement Slip">
                                                <i class="bi bi-arrow-repeat" style="color:#d97706;"></i> Exchange Slip
                                            </a>
                                            <a href="print_handover.php?user_id=<?= $uid ?>&type=return" target="_blank" class="btn btn-ghost btn-sm" style="padding:3px 10px; font-size:.78rem; border:1px solid #cbd5e1; font-weight:600;" title="Print Return Slip">
                                                <i class="bi bi-arrow-return-left" style="color:#dc2626;"></i> Return Slip
                                            </a>
                                        </div>
                                    </div>
                                    <form action="actions.php" method="POST" class="assign-row">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="assign">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="text"
                                               name="asset_identifier"
                                               class="form-control"
                                               placeholder="<?= htmlspecialchars(__('Scan or type Hostname / Serial Number…')) ?>"
                                               autocomplete="off"
                                               required>
                                        <button type="submit" class="btn btn-primary btn-sm">
                                            <i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Assign')) ?>
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Asset Table for this User -->
                                <?php if ($hasAssets): ?>
                                    <?php if ($showTab === 'resigned'): ?>
                                        <div style="font-size:.75rem; color:#991b1b; margin-bottom:8px;"><i class="bi bi-clock-history"></i> Historical items held before resignation</div>
                                    <?php endif; ?>
                                <table class="asset-sub-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th>Hostname</th>
                                            <th>Serial Number</th>
                                            <th>Location</th>
                                            <th>Ownership</th>
                                            <th>Status</th>
                                            <th style="text-align:right;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($uAssets as $a): ?>
                                        <tr>
                                            <td class="fw-600"><?= htmlspecialchars($a['category']) ?></td>
                                            <td>
                                                <a href="history.php?asset_id=<?= $a['asset_id'] ?>"
                                                   class="text-primary-link">
                                                    <?= htmlspecialchars($a['hostname'] ?? '—') ?>
                                                </a>
                                            </td>
                                            <td class="text-mono"><?= htmlspecialchars($a['serial_number'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($a['location_name'] ?? '—') ?></td>
                                            <td><?= htmlspecialchars($a['ownership_type'] ?? '—') ?></td>
                                            <td><?= badge($a['status']) ?></td>
                                            <td style="text-align:right;">
                                                <form action="actions.php" method="POST" class="edit-only"
                                                      data-confirm="คืน asset นี้กลับเข้า stock?" data-confirm-title="Return Asset" data-confirm-kind="info">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action"   value="return">
                                                    <input type="hidden" name="asset_id" value="<?= $a['asset_id'] ?>">
                                                    <input type="hidden" name="user_id"  value="<?= $uid ?>">
                                                    <button type="submit" class="btn btn-danger-ghost btn-sm">
                                                        <i class="bi bi-arrow-return-left"></i> Return
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php else: ?>
                                <p style="font-size:.82rem;color:#9ca3af;margin-top:8px;">
                                    No device assets currently assigned.
                                </p>
                                <?php endif; ?>

                                 <!-- Accessories Quick Assign -->
                                <?php if ($showTab !== 'resigned'): ?>
                                <div class="assign-panel edit-only" style="margin-top:14px;">
                                    <div style="font-size:.8rem;font-weight:600;color:#374151;margin-bottom:8px;">
                                        <i class="bi bi-plug" style="color:#059669;"></i>
                                        Quick Assign Accessory to <?= htmlspecialchars($user['full_name']) ?>
                                    </div>
                                    <form action="actions.php" method="POST" class="assign-row">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="assign_accessory_quick">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <input type="text"
                                               name="accessory_identifier"
                                               class="form-control"
                                               placeholder="Scan or type Accessory Name / Serial Number…"
                                               autocomplete="off"
                                               required>
                                        <button type="submit" class="btn btn-sm" style="background:#059669;color:#fff;border:none;border-radius:6px;">
                                            <i class="bi bi-plus-lg"></i> Assign
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>

                                <!-- Accessories Table for this User -->
                                <?php if (count($uAccessories) > 0): ?>
                                <table class="asset-sub-table" style="margin-top:10px;">
                                    <thead>
                                        <tr style="background:#f0fdf4 !important;">
                                            <th style="color:#059669;">Category</th>
                                            <th style="color:#059669;">Name</th>
                                            <th style="color:#059669;">Serial Number</th>
                                            <th style="color:#059669;">Parent Device</th>
                                            <th style="color:#059669;">Status</th>
                                            <th style="text-align:right;color:#059669;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($uAccessories as $ac): ?>
                                        <tr>
                                            <td class="fw-600"><?= htmlspecialchars($ac['category']) ?></td>
                                            <td class="fw-600"><?= htmlspecialchars($ac['name']) ?></td>
                                            <td class="text-mono"><?= htmlspecialchars($ac['serial_number'] ?? '—') ?></td>
                                            <td>
                                                <?php if (!empty($ac['parent_hostname'])): ?>
                                                    <i class="bi bi-laptop" style="font-size:11px;color:var(--color-accent);"></i>
                                                    <?= htmlspecialchars($ac['parent_hostname']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= badge($ac['status']) ?></td>
                                            <td style="text-align:right;">
                                                <form action="actions.php" method="POST" class="edit-only"
                                                      data-confirm="คืน accessory นี้กลับเข้า stock?" data-confirm-title="Return Accessory" data-confirm-kind="info">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action"       value="return_accessory">
                                                    <input type="hidden" name="accessory_id" value="<?= $ac['accessory_id'] ?>">
                                                    <input type="hidden" name="user_id"      value="<?= $uid ?>">
                                                    <button type="submit" class="btn btn-danger-ghost btn-sm">
                                                        <i class="bi bi-arrow-return-left"></i> Return
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php elseif (count($uAssets) === 0 && count($uSoftware) === 0): ?>
                                <p style="font-size:.82rem;color:#9ca3af;margin-top:4px;">
                                    No accessories assigned either — scan above to assign.
                                </p>
                                <?php endif; ?>

                                <!-- Software Table for this User -->
                                <?php if (count($uSoftware) > 0): ?>
                                <table class="asset-sub-table" style="margin-top:14px;">
                                    <thead>
                                        <tr style="background:#fdf4ff !important;">
                                            <th style="color:#c026d3;">Software Name</th>
                                            <th style="color:#c026d3;">Category</th>
                                            <th style="color:#c026d3;">License Key</th>
                                            <th style="color:#c026d3;">Expiry Date</th>
                                            <th style="color:#c026d3;">Status</th>
                                            <th style="text-align:right;color:#c026d3;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($uSoftware as $sw): ?>
                                        <tr>
                                            <td class="fw-600"><?= htmlspecialchars($sw['software_name']) ?></td>
                                            <td><?= htmlspecialchars($sw['category']) ?></td>
                                            <td class="text-mono">
                                                <?php if ($sw['license_key']): ?>
                                                    <?= strlen($sw['license_key']) > 15 ? htmlspecialchars(substr($sw['license_key'], 0, 10)).'...' : htmlspecialchars($sw['license_key']) ?>
                                                <?php else: ?>
                                                    <span class="text-muted" style="font-size:0.8rem;"><i>No Key</i></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-mono"><?= $sw['expiry_date'] ?: '—' ?></td>
                                            <td><?= badge($sw['status']) ?></td>
                                            <td style="text-align:right;">
                                                <form action="actions.php" method="POST" class="edit-only"
                                                      data-confirm="เพิกถอน license นี้จากผู้ใช้?" data-confirm-title="Revoke License">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action"     value="return_license">
                                                    <input type="hidden" name="license_id" value="<?= $sw['license_id'] ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:transparent; color:#c026d3; border:1px solid #fbcfe8; border-radius:6px;">
                                                        <i class="bi bi-arrow-return-left"></i> Revoke
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ─── Add User Modal ─── -->
<div id="addUserModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-person-plus" style="color:#2563eb;"></i> Add New User</h3>
            <button onclick="document.getElementById('addUserModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_user">

            <div class="form-group">
                <label class="form-label">Employee ID</label>
                <input type="text" name="emp_id" class="form-control" placeholder="EMP-123">
            </div>
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:#dc2626;">*</span></label>
                <input type="text" name="full_name" class="form-control" placeholder="Jane Doe" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="jane.doe@company.com">
            </div>
            <div class="form-group">
                <label class="form-label">Department</label>
                <input list="deptList" name="department" class="form-control" placeholder="Sales, IT, HR...">
                <datalist id="deptList">
                    <?php foreach ($departments as $d): ?><option value="<?= htmlspecialchars($d) ?>"><?php endforeach; ?>
                </datalist>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <button type="button" onclick="document.getElementById('addUserModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Edit User Modal ─── -->
<div id="editUserModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-pencil" style="color:#2563eb;"></i> Edit User</h3>
                <button type="button" onclick="handleDeleteUser()" class="btn btn-ghost btn-sm" style="color:#94a3b8; padding:2px 6px;" title="Delete User (Mistake)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
            <button onclick="closeEditUserModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div class="form-group">
                <label class="form-label">Employee ID</label>
                <input type="text" name="emp_id" id="edit_emp_id" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:#dc2626;">*</span></label>
                <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" id="edit_email" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Department</label>
                <input list="deptList" name="department" id="edit_department" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label">Location (All assigned assets will follow this)</label>
                <select name="location_id" id="edit_user_location" class="form-control">
                    <option value="0">-- No Location --</option>
                    <?php 
                    $locs = $pdo->query("SELECT * FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($locs as $l): ?>
                        <option value="<?= $l['location_id'] ?>"><?= htmlspecialchars($l['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:space-between;margin-top:20px; padding-top:15px; border-top:1px solid #f1f5f9;">
                <div>
                    <button type="button" onclick="handleResignation()" class="btn btn-danger-ghost" style="color:#dc2626;">
                        <i class="bi bi-person-x"></i> Resign Employee
                    </button>
                </div>
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="closeEditUserModal()" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update User</button>
                </div>
            </div>
        </form>
        <!-- Hidden delete form -->
        <form id="deleteUserForm" action="actions.php" method="POST" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="delete_user_id">
        </form>
    </div>
</div>

<script>
function handleDeleteUser() {
    const name = document.getElementById('edit_full_name').value;
    const uid = document.getElementById('edit_user_id').value;
    askConfirm({
        title: 'ลบผู้ใช้',
        message: `ลบผู้ใช้ "${name}"? ใช้เฉพาะกรณีเพิ่มผิด — asset ของผู้ใช้นี้จะยังคงอยู่ ไม่คืนเข้า stock`,
        confirmText: 'ลบเลย'
    }).then(ok => {
        if (!ok) return;
        document.getElementById('delete_user_id').value = uid;
        const f = document.getElementById('deleteUserForm');
        f.dataset.confirmed = '1'; f.submit();
    });
}
function handleResignation() {
    // Open the per-item disposition review page instead of one-click resign.
    const uid = document.getElementById('edit_user_id').value;
    if (!uid) return;
    window.location.href = 'resign_review.php?user_id=' + uid;
}
function openEditUserModal(event, id, emp_id, name, dept, email, loc_id) {
    event.stopPropagation();
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_emp_id').value = emp_id;
    document.getElementById('edit_full_name').value = name;
    document.getElementById('edit_department').value = dept;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_user_location').value = loc_id;
    document.getElementById('editUserModal').style.display = 'flex';
}
function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
}
// Close modals on backdrop click
['addUserModal','editUserModal'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
// Escape key closes modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('addUserModal').style.display = 'none';
        document.getElementById('editUserModal').style.display = 'none';
    }
});
</script>

<?php require_once 'includes/layout_end.php'; ?>
