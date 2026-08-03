<?php
// assets.php — Device Assets Portal (hardware only, no consumables)
require_once 'includes/db_connect.php';
require_once 'includes/column_helper.php';

// Ensure column_settings is seeded for existing installs
seedColumnSettings($pdo);

// Load column configuration
$visibleColumns  = getVisibleColumns($pdo);
$allColSettings  = getColumnSettings($pdo);
$customColumns   = array_values(array_filter($allColSettings, fn($c) => !(int)$c['is_system']));
$visibleColCount = count($visibleColumns) + 2; // +2 for checkbox + controls

// All unique categories from the database
$categories = $pdo->query("SELECT DISTINCT category FROM assets ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// All users for OUT/Borrow assign dropdown
$users = $pdo->query("SELECT user_id, full_name, department FROM users WHERE resigned_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// All locations
$locations = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters from GET
$filterCat    = $_GET['cat']    ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterOwn    = $_GET['own']    ?? 'all';

// Build query
$where = [];
$params = [];
if ($filterCat    !== 'all') { $where[] = "a.category = :cat";           $params['cat']    = $filterCat; }
if ($filterStatus !== 'all') { $where[] = "a.status = :status";          $params['status'] = $filterStatus; }
if ($filterOwn    !== 'all') { $where[] = "a.ownership_type = :own";     $params['own']    = $filterOwn; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$assets = $pdo->prepare("
    SELECT a.*, u.full_name, l.location_name
    FROM   assets a
    LEFT JOIN users     u ON a.user_id     = u.user_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    $whereSql
    ORDER BY a.category ASC, a.hostname ASC
");
$assets->execute($params);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter badges
$statusCounts = $pdo->query("SELECT status, COUNT(*) as n FROM assets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$catCounts    = $pdo->query("SELECT category, COUNT(*) as n FROM assets GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll     = array_sum($statusCounts);

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

function badge($status, $vendor_return_date = null) {
    if ($status === 'retired' && $vendor_return_date) {
        return "<span class='badge' style='background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;'>Returned</span>";
    }
    $safe  = htmlspecialchars($status);
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class='badge badge-{$safe}'>{$label}</span>";
}

function ownBadge($own) {
    $map = [
        'Buy'    => ['#dbeafe','#1d4ed8'],
        'Rent'   => ['#fef3c7','#b45309'],
        'Borrow' => ['#ede9fe','#6d28d9'],
    ];
    [$bg,$fg] = $map[$own] ?? ['#f3f4f6','#6b7280'];
    $safe = htmlspecialchars($own ?? '—');
    return "<span class='badge' style='background:{$bg};color:{$fg};'>{$safe}</span>";
}

$pageTitle = 'Device Assets';
$activeNav = 'assets';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'assign_success'): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Asset assigned (OUT) successfully.</div><?php endif; ?>
<?php if ($msg === 'return_success'): ?><div class="alert alert-info"><i class="bi bi-arrow-return-left"></i> Asset returned to stock (IN).</div><?php endif; ?>
<?php if ($msg === 'success'): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Asset saved successfully.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-warning"><i class="bi bi-trash3-fill"></i> <?= __('Asset permanently deleted') ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ─── Assets Table & Toolbar ─────────────────────────── -->
<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="flex-direction:column; align-items:stretch; gap:16px; padding:20px 24px; background:var(--color-surface);">
        
        <!-- Top Row: Title & Add Button -->
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:1.1rem; margin:0;"><i class="bi bi-laptop" style="color:var(--color-accent); margin-right:8px;"></i>
                <?= htmlspecialchars(__('Device Assets')) ?>
                <span style="font-size:.8rem;font-weight:400;color:var(--color-text-muted);margin-left:6px;"><?= count($assets) ?> <?= htmlspecialchars(__('records')) ?></span>
            </h3>
            <button onclick="document.getElementById('addModal').style.display='flex'" class="btn btn-primary btn-sm edit-only" style="padding:7px 16px; border-radius:6px;">
                <i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Add Asset')) ?>
            </button>
        </div>

        <!-- Bottom Row: Filters -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
            <!-- Category Tabs -->
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button type="button" onclick="document.querySelector('.tbl-wrap table').classList.toggle('show-advanced'); this.classList.toggle('btn-primary'); this.classList.toggle('btn-ghost');" class="btn btn-ghost btn-sm" style="padding:5px 10px; border-radius:6px; margin-right:8px;" title="Toggle Advanced Columns">
                    <i class="bi bi-layout-three-columns"></i> <?= htmlspecialchars(__('Advanced')) ?>
                </button>
                <?php
                $cats = array_merge(['all' => $totalAll], $catCounts);
                foreach ($cats as $cat => $cnt):
                    $active = ($filterCat === $cat);
                    $label  = $cat === 'all' ? htmlspecialchars(__('All')) : htmlspecialchars($cat);
                ?>
                <a href="?cat=<?= urlencode($cat) ?>&status=<?= urlencode($filterStatus) ?>&own=<?= urlencode($filterOwn) ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:6px;font-size:.8rem;font-weight:600;background:<?= $active ? 'var(--color-accent-lite)' : '#f8fafc' ?>;color:<?= $active ? 'var(--color-accent-dark)' : 'var(--color-text-sub)' ?>;border:1px solid <?= $active ? 'var(--color-accent)' : 'var(--color-border)' ?>;text-decoration:none;transition:.15s;">
                    <?= $label ?>
                    <span style="background:<?= $active ? 'var(--color-accent)' : '#e2e8f0' ?>;color:<?= $active ? '#fff' : 'var(--color-text-muted)' ?>;padding:2px 6px;border-radius:4px;font-size:.7rem; line-height:1;"><?= (int)$cnt ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Status + Ownership Dropdowns -->
            <form method="GET" style="display:flex; gap:10px; align-items:center; margin:0;">
                <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCat) ?>">
                
                <div style="position:relative;">
                    <i class="bi bi-funnel" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--color-text-muted);font-size:12px;"></i>
                    <select name="status" class="form-control form-select" style="width:130px;padding:6px 24px 6px 30px;font-size:.8rem; border-radius:6px;" onchange="this.form.submit()">
                        <option value="all" <?= $filterStatus==='all'?'selected':'' ?>><?= htmlspecialchars(__('All Status')) ?></option>
                        <?php foreach (['in_stock','deployed','repair','retired','lost'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= htmlspecialchars(__(ucfirst(str_replace('_',' ',$s)))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="position:relative;">
                    <i class="bi bi-tag" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--color-text-muted);font-size:12px;"></i>
                    <select name="own" class="form-control form-select" style="width:140px;padding:6px 24px 6px 30px;font-size:.8rem; border-radius:6px;" onchange="this.form.submit()">
                        <option value="all" <?= $filterOwn==='all'?'selected':'' ?>><?= htmlspecialchars(__('All Ownership')) ?></option>
                        <?php foreach (['Buy','Rent','Borrow'] as $o): ?>
                            <option value="<?= $o ?>" <?= $filterOwn===$o?'selected':'' ?>><?= htmlspecialchars(__($o)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($filterCat!=='all'||$filterStatus!=='all'||$filterOwn!=='all'): ?>
                    <a href="assets.php" class="btn btn-ghost btn-sm" style="padding:6px 10px;color:var(--color-danger); border-radius:6px;" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Bulk Toolbar -->
    <div id="bulkToolbar" style="display:none; padding:10px 20px; background:linear-gradient(135deg,#1e3a8a,#2563eb); border-bottom:2px solid #1d4ed8; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div style="font-weight:700; color:#fff; font-size:.9rem; display:flex; align-items:center; gap:8px;">
            <span style="background:rgba(255,255,255,.2); padding:3px 10px; border-radius:20px; font-size:.8rem;">
                <i class="bi bi-check2-square"></i> <span id="bulkCount">0</span> <?= htmlspecialchars(__('selected')) ?>
            </span>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <!-- Color Tags -->
            <div style="display:flex; gap:4px; align-items:center; background:rgba(255,255,255,.1); border-radius:8px; padding:4px 8px;">
                <span style="font-size:.72rem; color:rgba(255,255,255,.7); margin-right:2px;">Tag:</span>
                <?php foreach(['#ef4444'=>'red','#f59e0b'=>'amber','#10b981'=>'green','#8b5cf6'=>'purple','#64748b'=>'gray'] as $hex=>$name): ?>
                <button type="button" onclick="applyTag('<?=$hex?>')" title="Tag <?=$name?>" style="width:20px;height:20px;border-radius:50%;background:<?=$hex?>;border:2px solid rgba(255,255,255,.4);cursor:pointer;"></button>
                <?php endforeach; ?>
                <button type="button" onclick="applyTag('')" title="Clear tags" style="width:20px;height:20px;border-radius:50%;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4);cursor:pointer;font-size:10px;color:#fff;">✕</button>
            </div>
            <!-- Bulk Actions -->
            <button onclick="openBulkModal('batch')" class="btn btn-sm edit-only" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-collection"></i> <?= htmlspecialchars(__('Batch Edit')) ?>
            </button>
            <button onclick="openBulkModal('location')" class="btn btn-sm edit-only" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-geo-alt"></i> <?= htmlspecialchars(__('Set Location')) ?>
            </button>
            <button onclick="openBulkModal('user')" class="btn btn-sm edit-only" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-person-up"></i> <?= htmlspecialchars(__('Assign User')) ?>
            </button>
            <button onclick="openBulkModal('status')" class="btn btn-sm edit-only" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-arrow-repeat"></i> <?= htmlspecialchars(__('Change Status')) ?>
            </button>
            <button onclick="openBulkReturnVendorModal()" class="btn btn-sm edit-only" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-truck"></i> <?= htmlspecialchars(__('Return Vendor')) ?>
            </button>
            <button onclick="openBulkModal('delete')" class="btn btn-sm edit-only" style="background:#be123c;color:#fff;border:none;border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-trash3"></i> <?= htmlspecialchars(__('Delete')) ?>
            </button>
            <div style="width:1px; height:24px; background:rgba(255,255,255,.2); margin:0 4px;"></div>
            <button onclick="bulkExport()" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Export')) ?>
            </button>
            <button onclick="bulkPrintLabels()" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;font-size:.8rem;font-weight:600;">
                <i class="bi bi-printer"></i> <?= htmlspecialchars(__('Labels')) ?>
            </button>
            <div style="width:1px; height:24px; background:rgba(255,255,255,.2); margin:0 4px;"></div>
            <button onclick="clearSelection()" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.2);border-radius:6px;font-size:.8rem;">
                <i class="bi bi-x"></i> <?= htmlspecialchars(__('Clear')) ?>
            </button>
        </div>
    </div>

    <div class="card-body tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;">
                        <input type="checkbox" id="selectAll" style="cursor:pointer;">
                    </th>
                    <?php foreach ($visibleColumns as $col):
                        $advClass = ($col['is_advanced'] && !$col['col_visible']) ? 'adv-col adv-col-header' : ''; ?>
                    <th class="<?= $advClass ?>"><?= htmlspecialchars(__($col['col_label'])) ?></th>
                    <?php endforeach; ?>
                    <th style="text-align:center;"><?= htmlspecialchars(__('Controls')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($assets)): ?>
                <tr><td colspan="<?= $visibleColCount ?>">
                    <div class="empty-state">
                        <i class="bi bi-laptop"></i>
                        <p><?= htmlspecialchars(__('No assets found. Try adjusting your filters or add a new asset.')) ?></p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($assets as $a): ?>
                <tr data-asset-id="<?= $a['asset_id'] ?>" data-row-id="<?= $a['asset_id'] ?>" style="transition:background .2s;">
                    <td style="text-align:center;">
                        <input type="checkbox" class="row-checkbox" value="<?= $a['asset_id'] ?>" style="cursor:pointer;">
                    </td>
                    <?php foreach ($visibleColumns as $col):
                        $key = $col['col_key'];
                        $val = htmlspecialchars($a[$key] ?? ''); 
                        $advClass = ($col['is_advanced'] && !$col['col_visible']) ? 'adv-col adv-col-cell' : ''; ?>
                    <td class="<?= $advClass ?>">
                    <?php if ($key === 'category'): ?>
                        <span class="fw-600 text-truncate-custom" title="<?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars($a['category']) ?></span>
                    <?php elseif ($key === 'hostname'): ?>
                        <a href="history.php?asset_id=<?= $a['asset_id'] ?>" class="text-primary-link">
                            <?= htmlspecialchars($a['hostname'] ?? '—') ?>
                        </a>
                    <?php elseif ($key === 'serial_number'): ?>
                        <span class="text-mono"><?= htmlspecialchars($a['serial_number'] ?? '—') ?></span>
                    <?php elseif ($key === 'ownership_type'): ?>
                        <?= ownBadge($a['ownership_type']) ?>
                    <?php elseif ($key === 'status'): ?>
                        <?= badge($a['status'], $a['vendor_return_date'] ?? null) ?>
                        <?php if ($a['status'] === 'repair' && (!empty($a['repair_shop']) || !empty($a['repair_eta']))): ?>
                            <div class="text-muted" style="font-size:.7rem; margin-top:3px; line-height:1.3;">
                                <?php if (!empty($a['repair_shop'])): ?>
                                    <i class="bi bi-shop"></i> <?= htmlspecialchars($a['repair_shop']) ?>
                                <?php endif; ?>
                                <?php if (!empty($a['repair_eta'])):
                                    $today = new DateTime('today');
                                    $eta = new DateTime($a['repair_eta']);
                                    $d = (int)$today->diff($eta)->format('%r%a');
                                    $eClass = $d < 0 ? 'badge-overdue' : ($d <= 3 ? 'badge-soon' : '');
                                ?>
                                    <?php if ($eClass): ?>
                                        <span class="badge <?= $eClass ?>" style="font-size:.65rem; padding:1px 6px;">
                                            <i class="bi bi-clock"></i> ETA <?= htmlspecialchars($a['repair_eta']) ?>
                                        </span>
                                    <?php else: ?>
                                        <div><i class="bi bi-clock"></i> ETA <?= htmlspecialchars($a['repair_eta']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($key === 'full_name'): ?>
                        <?php if ($a['full_name']): ?>
                            <a href="users.php#user-<?= $a['user_id'] ?>" class="text-primary-link text-truncate-custom" style="font-size:.82rem; max-width:130px;" title="<?= htmlspecialchars($a['full_name']) ?>">
                                <i class="bi bi-person" style="font-size:11px;"></i> <?= htmlspecialchars($a['full_name']) ?>
                            </a>
                            <?php if (!empty($a['borrow_due_date'])):
                                $today = new DateTime('today');
                                $due = new DateTime($a['borrow_due_date']);
                                $diff = (int)$today->diff($due)->format('%r%a');
                                if ($diff < 0) {
                                    echo '<div><span class="badge badge-overdue" title="' . __('Overdue') . '"><i class="bi bi-exclamation-triangle"></i> ' . __('Overdue') . ' ' . abs($diff) . ' ' . __('days') . '</span></div>';
                                } elseif ($diff <= 7) {
                                    echo '<div><span class="badge badge-soon" title="' . __('Due soon') . ' '.$a['borrow_due_date'].'"><i class="bi bi-clock"></i> ' . __('Remaining') . ' '.$diff.' ' . __('days') . '</span></div>';
                                } else {
                                    echo '<div class="text-muted" style="font-size:.7rem;margin-top:2px;"><i class="bi bi-calendar-event"></i> Due '.htmlspecialchars($a['borrow_due_date']).'</div>';
                                }
                            endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    <?php elseif ($key === 'location_name'): ?>
                        <span class="text-truncate-custom" title="<?= htmlspecialchars($a['location_name'] ?? '') ?>"><?= htmlspecialchars($a['location_name'] ?? '—') ?></span>
                    <?php elseif ($col['col_type'] === 'date'): ?>
                        <span class="text-muted"><?= $val ?: '—' ?></span>
                    <?php elseif ($col['col_type'] === 'number'): ?>
                        <span class="text-mono"><?= $val ?: '—' ?></span>
                    <?php elseif ($key === 'service_tag'): ?>
                        <span class="text-mono"><?= $val ?: '—' ?></span>
                    <?php else: ?>
                        <span class="text-truncate-custom" title="<?= $val ?>"><?= $val ?: '—' ?></span>
                    <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <td>
                        <div class="action-dropdown" style="display:flex; justify-content:center;">
                            <button type="button" onclick="toggleActionDropdown(this)" class="btn btn-sm btn-ghost edit-only" style="padding:4px 8px;" title="Actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <div class="action-dropdown-menu edit-only">
                                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($a)) ?>)" class="action-dropdown-item text-primary" title="Edit Asset Details">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <a href="history.php?asset_id=<?= $a['asset_id'] ?>" class="action-dropdown-item" title="View Audit History">
                                    <i class="bi bi-journal-text"></i> History
                                </a>

                                <?php if ($a['status'] === 'deployed'): ?>
                                    <form action="actions.php" method="POST" style="margin:0;" data-confirm="Return «<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>» to stock?" data-confirm-title="Return Asset" data-confirm-kind="info">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="return">
                                        <input type="hidden" name="asset_id" value="<?= $a['asset_id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
                                        <button type="submit" class="action-dropdown-item" title="Return item from user back to IT stock">
                                            <i class="bi bi-box-arrow-in-down-left"></i> Return IN
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($a['status'], ['in_stock', 'repair', 'deployed'])): ?>
                                    <button type="button" onclick="openRmaModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>')" class="action-dropdown-item text-warning" title="RMA/Replace with new device">
                                        <i class="bi bi-arrow-repeat"></i> RMA
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] === 'in_stock'): ?>
                                    <button type="button" onclick="openReturnVendorModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>')" class="action-dropdown-item" title="Ship back to Vendor/Lessor">
                                        <i class="bi bi-truck"></i> Vendor
                                    </button>
                                <?php endif; ?>

                                <?php if (in_array($a['status'], ['in_stock','repair'])): ?>
                                    <button type="button" onclick="openOutModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>', 'assign')" class="action-dropdown-item text-primary" title="Deploy item to a user permanently">
                                        <i class="bi bi-person-up"></i> Deploy
                                    </button>
                                    <button type="button" onclick="openOutModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>', 'borrow')" class="action-dropdown-item" style="color:#9333ea;" title="Loan item to a user temporarily">
                                        <i class="bi bi-clock"></i> Loan
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] !== 'repair' && $a['status'] !== 'retired' && $a['status'] !== 'lost'): ?>
                                    <button type="button" onclick="openRepairSendModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>')" class="action-dropdown-item text-warning" title="Send device to Repair">
                                        <i class="bi bi-wrench"></i> Repair
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] === 'repair'): ?>
                                    <button type="button" onclick="openRepairReturnModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'])) ?>')" class="action-dropdown-item text-success" title="Mark as Repaired & Ready">
                                        <i class="bi bi-check-circle"></i> Fixed
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] !== 'retired' && $a['status'] !== 'lost'): ?>
                                    <form action="actions.php" method="POST" style="margin:0;" data-confirm="<?= __('Retire this item?') ?>" data-confirm-title="Retire Asset">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_retired">
                                        <input type="hidden" name="asset_id" value="<?= $a['asset_id'] ?>">
                                        <button type="submit" class="action-dropdown-item" title="Mark as Retired">
                                            <i class="bi bi-archive"></i> Retire
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Permanent Delete -->
                                <button type="button" onclick="openDeleteAssetModal(<?= $a['asset_id'] ?>, '<?= htmlspecialchars(addslashes($a['hostname'] ?? $a['serial_number'] ?? 'Asset #'.$a['asset_id'])) ?>')" class="action-dropdown-item text-danger" title="<?= __('Permanently Delete') ?>">
                                    <i class="bi bi-trash3"></i> Delete
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ─── Permanent Delete Asset Modal ────────────────── -->
<div id="deleteAssetModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1050;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:14px;width:460px;max-width:94vw;box-shadow:0 24px 70px rgba(220,38,38,.18);overflow:hidden;border:2px solid #fecaca;">
        <div style="padding:18px 24px;border-bottom:1px solid #fecaca;display:flex;justify-content:space-between;align-items:center;background:#fff1f2;">
            <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin:0;">
                <i class="bi bi-trash3-fill"></i> <?= __('Permanently delete asset') ?>
            </h3>
            <button onclick="closeDeleteAssetModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:20px 24px;">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:18px;font-size:.85rem;color:#991b1b;line-height:1.6;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong><?= __('Warning:') ?></strong> การลบนี้ <strong><?= __('Cannot be undone.') ?></strong><?= __('System will delete asset data') ?>
                <span id="deleteAssetName" style="font-weight:700;"></span>
                <?= __('permanently, but keep') ?><strong><?= __('audit logs.') ?></strong>
            </div>
            <form action="actions.php" method="POST" id="deleteAssetForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_asset">
                <input type="hidden" name="asset_id" id="deleteAssetId" value="">
                <div class="form-group" style="margin-bottom:18px;">
                    <label class="form-label" style="font-weight:600;color:#374151;"><?= __('Reason for deletion') ?> <span style="color:#dc2626;">*</span></label>
                    <textarea name="delete_reason" id="deleteAssetReason" class="form-control" rows="3"
                              placeholder="<?= __('e.g., Duplicate, incorrect import, testing, etc.') ?>" required
                              style="border-color:#fca5a5; resize:vertical;"></textarea>
                    <small style="color:#6b7280;font-size:.72rem;"><?= __('This reason will be permanently recorded in the audit log.') ?></small>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeDeleteAssetModal()" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn" style="background:#dc2626;color:#fff;border-color:#dc2626;font-weight:600;"
                            onclick="return validateDeleteReason()">
                        <i class="bi bi-trash3-fill"></i> <?= __('Confirm Permanent Deletion') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDeleteAssetModal(assetId, assetName) {
    document.getElementById('deleteAssetId').value   = assetId;
    document.getElementById('deleteAssetName').textContent = ' "' + assetName + '" ';
    document.getElementById('deleteAssetReason').value = '';
    document.getElementById('deleteAssetModal').style.display = 'flex';
    setTimeout(() => document.getElementById('deleteAssetReason').focus(), 100);
}
function closeDeleteAssetModal() {
    document.getElementById('deleteAssetModal').style.display = 'none';
}
function validateDeleteReason() {
    const reason = document.getElementById('deleteAssetReason').value.trim();
    if (!reason) {
        document.getElementById('deleteAssetReason').style.borderColor = '#dc2626';
        document.getElementById('deleteAssetReason').focus();
        return false;
    }
    return true;
}
document.getElementById('deleteAssetModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteAssetModal();
});
</script>

<!-- ─── Repair Send Modal ─────────────────────────────── -->
<div id="repairSendModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:440px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="repairSendTitle" style="font-size:1rem;font-weight:600;"><i class="bi bi-wrench" style="color:#d97706;"></i> <?= __('Send for Repair') ?></h3>
            <button onclick="document.getElementById('repairSendModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="set_repair">
            <input type="hidden" name="asset_id" id="repairSendAssetId" value="">
            <div class="form-group">
                <label class="form-label"><?= __('Repair Shop / Vendor') ?></label>
                <input type="text" name="repair_shop" class="form-control" placeholder="<?= __('Name of repair shop or responsible person') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('ETA (Expected return date)') ?></label>
                <input type="date" name="repair_eta" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('Symptoms / Notes') ?></label>
                <textarea name="repair_note" class="form-control" rows="3" placeholder="<?= __('e.g., Broken screen, won\'t turn on, etc.') ?>"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('repairSendModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-wrench"></i> <?= __('Send for Repair') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Repair Return (Mark as Fixed) Modal ──────────── -->
<div id="repairReturnModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="repairReturnTitle" style="font-size:1rem;font-weight:600;"><i class="bi bi-check-circle" style="color:#16a34a;"></i> <?= __('Return from Repair') ?></h3>
            <button onclick="document.getElementById('repairReturnModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="set_fixed">
            <input type="hidden" name="asset_id" id="repairReturnAssetId" value="">
            <div class="form-group">
                <label class="form-label"><?= __('Notes (What was repaired / Costs etc.)') ?></label>
                <textarea name="completion_note" class="form-control" rows="3" placeholder="<?= __('What was done, status after repair, costs, etc.') ?>"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('repairReturnModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> <?= __('Confirm Return') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ─── OUT / Borrow Modal ────────────────────────────── -->
<div id="outModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="outModalTitle" style="font-size:1rem;font-weight:600;"></h3>
            <button onclick="closeOutModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   id="outAction"  value="assign">
            <input type="hidden" name="asset_id" id="outAssetId" value="">
            <input type="hidden" name="out_mode" id="outMode"    value="assign">

            <div class="form-group">
                <label class="form-label">Assign To User</label>
                <select name="user_id_direct" id="outUserId" class="form-control form-select" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>">
                            <?= htmlspecialchars($u['full_name']) ?>
                            <?= $u['department'] ? ' ('.$u['department'].')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="borrowNote" style="display:none;padding:10px 12px;background:#ede9fe;border-radius:8px;margin-bottom:14px;font-size:.82rem;color:#5b21b6;">
                <i class="bi bi-info-circle"></i> Ownership = <strong>Borrow</strong>, status = <strong>Deployed</strong>
            </div>

            <div id="borrowDueGroup" class="form-group" style="display:none;">
                <label class="form-label"><?= __('Due Date') ?> <span style="color:#9ca3af;font-weight:400;"><?= __('— optional') ?></span></label>
                <input type="date" name="borrow_due_date" id="borrowDueDate" class="form-control" min="<?= date('Y-m-d') ?>">
                <small class="text-muted" style="font-size:.72rem;"><?= __('System will warn when approaching or overdue') ?></small>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeOutModal()" class="btn btn-ghost">Cancel</button>
                <button type="submit" id="outSubmitBtn" class="btn btn-primary"><i class="bi bi-arrow-up-circle"></i> Confirm OUT</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Add Asset Modal ───────────────────────────────── -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:500px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-plus-circle" style="color:#2563eb;"></i> Add New Device Asset</h3>
            <button onclick="document.getElementById('addModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_asset">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category <span style="color:#dc2626;">*</span></label>
                    <input list="catList" name="category" class="form-control" placeholder="Laptop, Monitor…" required>
                    <datalist id="catList">
                        <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
                        <option value="Laptop"><option value="Desktop"><option value="Monitor">
                        <option value="Printer"><option value="Scanner"><option value="Headset"><option value="Tablet">
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Hostname</label>
                    <input type="text" name="hostname" class="form-control" placeholder="LT-ENG-01">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" placeholder="SN…">
                </div>
                <div class="form-group">
                    <label class="form-label">Ownership <span style="color:#dc2626;">*</span></label>
                    <select name="ownership_type" class="form-control form-select" required>
                        <option value="Buy">Buy</option>
                        <option value="Rent">Rent</option>
                        <option value="Borrow">Borrow</option>
                    </select>
                </div>
            </div>

            <!-- Advanced Tracking Section -->
            <div style="margin:20px 0 16px 0; border-top:1px dashed #e8eaed; padding-top:16px;">
                <div style="font-size:.8rem;font-weight:600;color:var(--color-text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;">Advanced Tracking (Optional)</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input type="text" name="model" class="form-control" placeholder="ThinkPad T14">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Year</label>
                        <input type="number" name="purchase_year" class="form-control" placeholder="2024" min="1990" max="2050">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Tag</label>
                        <input type="text" name="service_tag" class="form-control" placeholder="TAG...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">MAC Address</label>
                        <input type="text" name="mac_address" class="form-control" placeholder="00:1A:2B...">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Batch / PO Number</label>
                        <input type="text" name="po_number" class="form-control" placeholder="Lot No, PO No...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">End of Life (Warranty)</label>
                        <input type="date" name="end_of_life_date" class="form-control">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control form-select">
                        <option value="in_stock">In Stock</option>
                        <option value="deployed">Deployed</option>
                        <option value="repair">Repair</option>
                        <option value="retired">Retired</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— None —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row" id="leaseFields" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Lease Start</label>
                    <input type="date" name="lease_start" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Lease Expiry</label>
                    <input type="date" name="lease_expiry" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Note</label>
                <input type="text" name="note" class="form-control" placeholder="Optional asset note...">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
                <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Edit Asset Modal ──────────────────────────────── -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:500px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-pencil" style="color:#2563eb;"></i> Edit Device Asset</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_asset">
            <input type="hidden" name="asset_id" id="editAssetId">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category <span style="color:#dc2626;">*</span></label>
                    <input list="catList" name="category" id="editCategory" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Hostname</label>
                    <input type="text" name="hostname" id="editHostname" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" id="editSerialNumber" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Ownership <span style="color:#dc2626;">*</span></label>
                    <select name="ownership_type" id="editOwnership" class="form-control form-select" required>
                        <option value="Buy">Buy</option>
                        <option value="Rent">Rent</option>
                        <option value="Borrow">Borrow</option>
                    </select>
                </div>
            </div>

            <div style="margin:20px 0 16px 0; border-top:1px dashed #e8eaed; padding-top:16px;">
                <div style="font-size:.8rem;font-weight:600;color:var(--color-text-muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;">Advanced Tracking</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Model</label>
                        <input type="text" name="model" id="editModel" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Year</label>
                        <input type="number" name="purchase_year" id="editPurchaseYear" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Service Tag</label>
                        <input type="text" name="service_tag" id="editServiceTag" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">MAC Address</label>
                        <input type="text" name="mac_address" id="editMacAddress" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Batch / PO Number</label>
                        <input type="text" name="po_number" id="editPoNumber" class="form-control" placeholder="Lot No, PO No...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date" id="editPurchaseDate" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">End of Life (Warranty)</label>
                        <input type="date" name="end_of_life_date" id="editEolDate" class="form-control">
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="editStatus" class="form-control form-select">
                        <option value="in_stock">In Stock</option>
                        <option value="deployed">Deployed</option>
                        <option value="repair">Repair</option>
                        <option value="retired">Retired</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Location (For unassigned/fixed items)</label>
                    <select name="location_id" id="editLocation" class="form-control form-select">
                        <option value="">— None —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Note</label>
                <input type="text" name="note" id="editNote" class="form-control" placeholder="Persistent asset note...">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Asset</button>
            </div>
        </form>

        <!-- ─ Attachments Section (in Edit modal) ─ -->
        <div style="border-top:1px solid var(--color-border-soft); padding:18px 24px; background:var(--color-bg);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="font-size:.88rem; font-weight:600; margin:0;"><i class="bi bi-paperclip"></i> <?= __('Attachments') ?> <span class="text-muted" style="font-weight:400;"><?= __('(invoice, manual, pictures)') ?></span></h4>
            </div>

            <div id="attachList" style="margin-bottom:12px;">
                <div class="text-muted" style="font-size:.82rem;"><?= __('Loading...') ?></div>
            </div>

            <form action="attachments.php" method="POST" enctype="multipart/form-data" class="edit-only"
                  style="display:flex; gap:8px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action"   value="upload">
                <input type="hidden" name="asset_id" id="attachAssetId" value="">
                <input type="file" name="file" required style="font-size:.82rem; flex:1;">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Upload</button>
            </form>
            <small class="text-muted" style="font-size:.7rem;"><?= __('Max size 20MB · invoice/picture/PDF/Word/Excel/zip') ?></small>
        </div>
    </div>
</div>

<!-- ─── Return to Vendor Modal ────────────────────────── -->
<div id="vendorModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="vendorModalTitle" style="font-size:1rem;font-weight:600;">Return to Vendor</h3>
            <button onclick="document.getElementById('vendorModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="return_to_vendor">
            <input type="hidden" name="asset_ids" id="vendorAssetId" value="">

            <div class="form-group">
                <label class="form-label">Return Date <span style="color:#dc2626;">*</span></label>
                <input type="date" name="vendor_return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Vendor / Recipient Name</label>
                <input type="text" name="vendor_return_to" class="form-control" placeholder="e.g. Dell Financial Services">
            </div>
            <div class="form-group">
                <label class="form-label">Return Method / Tracking</label>
                <input type="text" name="vendor_return_method" class="form-control" placeholder="e.g. FedEx 123456789">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="note" class="form-control" placeholder="Optional notes...">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="document.getElementById('vendorModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#be123c;border-color:#be123c;"><i class="bi bi-box-arrow-right"></i> Submit Return</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── RMA Replace Modal ────────────────────────────── -->
<div id="rmaModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="rmaModalTitle" style="font-size:1rem;font-weight:600;">RMA Replacement</h3>
            <button onclick="document.getElementById('rmaModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rma_replace">
            <input type="hidden" name="old_asset_id" id="rmaOldAssetId" value="">

            <div style="margin-bottom:15px; padding:10px; background:#fff7ed; border:1px solid #ffedd5; border-radius:6px; font-size:0.85rem; color:#ea580c;">
                <i class="bi bi-info-circle"></i> This will retire the old device and create a new identical one. If the old device is deployed, the new one will automatically be assigned to the same user.
            </div>

            <div class="form-group">
                <label class="form-label">New Serial Number <span style="color:#dc2626;">*</span></label>
                <input type="text" name="serial_number" class="form-control" required placeholder="SN of the new replacement">
            </div>
            
            <div class="form-group">
                <label class="form-label">New Hostname</label>
                <input type="text" name="hostname" id="rmaHostname" class="form-control" placeholder="Optional. Can use old hostname.">
            </div>

            <div class="form-group">
                <label class="form-label">New Service Tag</label>
                <input type="text" name="service_tag" class="form-control" placeholder="Optional">
            </div>

            <div class="form-group">
                <label class="form-label">New MAC Address</label>
                <input type="text" name="mac_address" class="form-control" placeholder="Optional">
            </div>

            <div class="form-group">
                <label class="form-label">RMA Notes / Ticket #</label>
                <input type="text" name="note" class="form-control" placeholder="e.g. Warranty Claim #12345">
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('rmaModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-sm" style="background:#ea580c;color:#fff;border:none;font-weight:600;"><i class="bi bi-arrow-repeat"></i> Process RMA</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(a) {
    document.getElementById('editAssetId').value      = a.asset_id;
    document.getElementById('editCategory').value     = a.category;
    document.getElementById('editHostname').value     = a.hostname     || '';
    document.getElementById('editSerialNumber').value = a.serial_number || '';
    document.getElementById('editOwnership').value    = a.ownership_type;
    document.getElementById('editStatus').value       = a.status;
    document.getElementById('editLocation').value     = a.location_id   || '';
    
    document.getElementById('editModel').value        = a.model        || '';
    document.getElementById('editPurchaseYear').value = a.purchase_year || '';
    document.getElementById('editServiceTag').value   = a.service_tag   || '';
    document.getElementById('editMacAddress').value   = a.mac_address   || '';
    document.getElementById('editPoNumber').value     = a.po_number     || '';
    document.getElementById('editPurchaseDate').value = a.purchase_date  || '';
    document.getElementById('editEolDate').value      = a.end_of_life_date || '';
    if (document.getElementById('editNote')) {
        document.getElementById('editNote').value = a.note || '';
    }

    // Wire attachments section
    document.getElementById('attachAssetId').value = a.asset_id;
    const list = document.getElementById('attachList');
    list.innerHTML = '<div class="text-muted" style="font-size:.82rem;"><?= __('Loading...') ?></div>';
    fetch('attachments.php?action=list&asset_id=' + a.asset_id)
        .then(r => r.text())
        .then(html => { list.innerHTML = html; });

    document.getElementById('editModal').style.display = 'flex';
}
function openOutModal(assetId, label, mode) {
    document.getElementById('outAssetId').value = assetId;
    document.getElementById('outMode').value    = mode;
    document.getElementById('outAction').value  = mode === 'borrow' ? 'assign_borrow' : 'assign_direct';
    document.getElementById('outModalTitle').textContent = (mode === 'borrow' ? 'Loan Out: ' : 'Deploy: ') + label;
    document.getElementById('borrowNote').style.display  = mode === 'borrow' ? 'block' : 'none';
    document.getElementById('borrowDueGroup').style.display = mode === 'borrow' ? 'block' : 'none';
    document.getElementById('outSubmitBtn').textContent  = mode === 'borrow' ? '↔ Confirm Loan' : '↑ Confirm Deploy';
    document.getElementById('outModal').style.display    = 'flex';
    document.getElementById('outUserId').value = '';
    document.getElementById('borrowDueDate').value = '';
}
function closeOutModal() {
    document.getElementById('outModal').style.display = 'none';
}
// Close modals on backdrop click
['outModal','addModal','vendorModal','editModal','rmaModal'].forEach(function(id) {
    const el = document.getElementById(id);
    if(el) {
        el.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }
});
function openReturnVendorModal(assetId, label) {
    document.getElementById('vendorAssetId').value = assetId;
    document.getElementById('vendorModalTitle').textContent = 'Return to Vendor: ' + label;
    document.getElementById('vendorModal').style.display = 'flex';
}

function openRmaModal(assetId, oldHostname) {
    document.getElementById('rmaOldAssetId').value = assetId;
    document.getElementById('rmaModalTitle').textContent = 'RMA Replace: ' + oldHostname;
    document.getElementById('rmaHostname').value = oldHostname; // Prepops old hostname so they can tweak or keep it
    document.getElementById('rmaModal').style.display = 'flex';
}

function openRepairSendModal(assetId, label) {
    document.getElementById('repairSendAssetId').value = assetId;
    document.getElementById('repairSendTitle').innerHTML = '<i class="bi bi-wrench" style="color:#d97706;"></i> <?= __('Send for Repair:') ?> ' + label;
    document.getElementById('repairSendModal').style.display = 'flex';
}
function openRepairReturnModal(assetId, label) {
    document.getElementById('repairReturnAssetId').value = assetId;
    document.getElementById('repairReturnTitle').innerHTML = '<i class="bi bi-check-circle" style="color:#16a34a;"></i> <?= __('Return from Repair:') ?> ' + label;
    document.getElementById('repairReturnModal').style.display = 'flex';
}
// Backdrop close
['repairSendModal','repairReturnModal'].forEach(id => {
    const m = document.getElementById(id);
    if (m) m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});

// ─── Bulk Actions Logic ───────────────────────────────────────
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.row-checkbox');
const bulkToolbar = document.getElementById('bulkToolbar');
const bulkCount = document.getElementById('bulkCount');

function updateBulkToolbar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length > 0) {
        bulkCount.textContent = checked.length;
        bulkToolbar.style.display = 'flex';
    } else {
        bulkToolbar.style.display = 'none';
        if (selectAll) selectAll.checked = false;
    }
}

if (selectAll) {
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => {
            cb.checked = this.checked;
            const row = cb.closest('tr');
            if (row) {
                row.style.background = this.checked ? '#eff6ff' : '';
                row.style.outline    = this.checked ? '2px solid #93c5fd' : '';
            }
        });
        updateBulkToolbar();
    });
}

function openBulkReturnVendorModal() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    document.getElementById('vendorAssetId').value = ids.join(',');
    document.getElementById('vendorModalTitle').textContent = 'Bulk Return ' + ids.length + ' Items to Vendor';
    document.getElementById('vendorModal').style.display = 'flex';
}
// Show lease fields when Rent selected
document.querySelectorAll('[name="ownership_type"]').forEach(function(sel) {
    sel.addEventListener('change', function() {
        const modal = this.closest('form');
        if (modal) {
            const leaseFields = modal.querySelector('#leaseFields, .lease-fields');
            if (leaseFields) leaseFields.style.display = this.value === 'Rent' ? 'grid' : 'none';
        }
    });
});

// ─── Bulk Selection Helpers ──────────────────────────────────
function getCheckedIds() {
    return [...document.querySelectorAll('.row-checkbox:checked')].map(cb => cb.value);
}
function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkToolbar();
    // Remove highlights
    document.querySelectorAll('tr[data-row-id]').forEach(r => r.style.outline = '');
}

function bulkExport() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    
    // We can export directly via a small form or window location, but since our export handler currently supports type=full, by_category, custom, user_centric, we need a small tweak to export_handler to accept 'ids'.
    // Let's redirect to a small script or update export_handler.
    const url = 'export_handler.php?type=bulk&ids=' + encodeURIComponent(ids.join(','));
    window.location.href = url;
}

function bulkPrintLabels() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    window.open('print_labels.php?ids=' + encodeURIComponent(ids.join(',')), '_blank');
}

// ─── Row Highlight on Check ──────────────────────────────────
document.querySelectorAll('.row-checkbox').forEach(cb => {
    cb.addEventListener('change', function() {
        const row = this.closest('tr');
        if (this.checked) {
            row.style.background = '#eff6ff';
            row.style.outline = '2px solid #93c5fd';
        } else {
            row.style.background = '';
            row.style.outline = '';
        }
        updateBulkToolbar();
    });
});

// ─── Color Tagging via localStorage ──────────────────────────
const TAG_KEY = 'asset_tags_v1';
function getTags() {
    try { return JSON.parse(localStorage.getItem(TAG_KEY) || '{}'); } catch(e) { return {}; }
}
function saveTags(tags) { localStorage.setItem(TAG_KEY, JSON.stringify(tags)); }
function applyTag(color) {
    const ids = getCheckedIds();
    if (!ids.length) return;
    const tags = getTags();
    ids.forEach(id => {
        if (color) tags[id] = color;
        else delete tags[id];
    });
    saveTags(tags);
    renderTags();
}
function renderTags() {
    const tags = getTags();
    document.querySelectorAll('tr[data-row-id]').forEach(row => {
        const id = row.dataset.rowId;
        const color = tags[id];
        if (color) {
            row.style.borderLeft = '4px solid ' + color;
            row.style.backgroundColor = color + '18'; // 10% opacity
        } else {
            row.style.borderLeft = '';
            if (!row.querySelector('.row-checkbox:checked')) row.style.backgroundColor = '';
        }
    });
}
renderTags(); // Apply on page load

// ─── Bulk Update Modal ───────────────────────────────────────
function openBulkModal(type) {
    const ids = getCheckedIds();
    if (!ids.length) { alert('Please select at least one asset.'); return; }
    document.getElementById('bulkAssetIds').value = ids.join(',');
    const modal = document.getElementById('bulkModal');
    document.getElementById('bulkLocGroup').style.display  = type === 'location' ? 'block' : 'none';
    document.getElementById('bulkUserGroup').style.display = type === 'user'     ? 'block' : 'none';
    document.getElementById('bulkStatGroup').style.display = type === 'status'   ? 'block' : 'none';
    document.getElementById('bulkBatchGroup').style.display = type === 'batch'   ? 'block' : 'none';
    document.getElementById('bulkDeleteGroup').style.display = type === 'delete' ? 'block' : 'none';
    document.getElementById('bulkModalTitle').textContent =
        type === 'location' ? '\uD83C\uDFE2 Set Location for ' + ids.length + ' Assets' :
        type === 'user'     ? '\uD83D\uDC64 Assign User to ' + ids.length + ' Assets' :
        type === 'batch'    ? '\uD83D\uDCCB Batch Update ' + ids.length + ' Assets' :
        type === 'delete'   ? '\uD83D\uDDD1\uFE0F Bulk Delete ' + ids.length + ' Assets' :
                             '\uD83D\uDCE6 Change Status of ' + ids.length + ' Assets';
    const actionMap = {location:'bulk_update_location', user:'bulk_assign_user', status:'bulk_update_status', batch:'bulk_edit_assets', delete:'bulk_delete_assets'};
    document.getElementById('bulkAction').value = actionMap[type];
    modal.style.display = 'flex';
}

// Escape key closes modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['outModal','addModal','vendorModal','editModal','rmaModal','bulkModal'].forEach(id => {
            const el = document.getElementById(id);
            if(el) el.style.display = 'none';
        });
    }
});
document.getElementById('bulkModal')?.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<!-- ─── Bulk Update Modal ─────────────────────────────────── -->
<div id="bulkModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#1e3a8a,#2563eb);">
            <h3 id="bulkModalTitle" style="font-size:.95rem;font-weight:700;margin:0;color:#fff;"></h3>
            <button onclick="document.getElementById('bulkModal').style.display='none'" style="background:rgba(255,255,255,.2);border:none;border-radius:6px;color:#fff;cursor:pointer;padding:4px 8px;font-size:14px;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="bulkAction" value="">
            <input type="hidden" name="asset_ids" id="bulkAssetIds" value="">

            <!-- Location -->
            <div id="bulkLocGroup">
                <div class="form-group">
                    <label class="form-label">Assign to Location</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— Remove Location —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- User -->
            <div id="bulkUserGroup">
                <div class="form-group">
                    <label class="form-label">Assign to User <span style="color:#dc2626;">*</span></label>
                    <select name="user_id" class="form-control form-select">
                        <option value="">— Select user —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?><?= $u['department'] ? ' ('.$u['department'].')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="font-size:.78rem;color:var(--color-text-muted);padding:8px 10px;background:#eff6ff;border-radius:6px;margin-top:-4px;">
                    <i class="bi bi-info-circle"></i> All selected assets will be set to <strong>Deployed</strong> and assigned to this user's location.
                </div>
            </div>

            <!-- Status -->
            <div id="bulkStatGroup">
                <div class="form-group">
                    <label class="form-label">New Status <span style="color:#dc2626;">*</span></label>
                    <select name="status" class="form-control form-select">
                        <?php foreach (['in_stock','deployed','repair','retired','lost'] as $s): ?>
                            <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Batch Edit -->
            <div id="bulkBatchGroup" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Batch / PO Number</label>
                    <input type="text" name="po_number" class="form-control" placeholder="Leave blank to keep unchanged">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <input list="catListBulk" name="category" class="form-control" placeholder="Leave blank to keep unchanged">
                    <datalist id="catListBulk">
                        <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
                        <option value="Laptop"><option value="Desktop"><option value="Monitor">
                        <option value="Printer"><option value="Scanner"><option value="Headset"><option value="Tablet">
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">End of Life Date</label>
                    <input type="date" name="end_of_life_date" class="form-control">
                </div>
                <div style="font-size:.78rem;color:var(--color-text-muted);padding:8px 10px;background:#eff6ff;border-radius:6px;">
                    <i class="bi bi-info-circle"></i> Fields left empty will NOT be changed.
                </div>
            </div>

            <!-- Bulk Delete -->
            <div id="bulkDeleteGroup" style="display:none;">
                <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:18px;font-size:.85rem;color:#991b1b;line-height:1.6;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong> Warning:</strong> You are about to delete these assets permanently. This action cannot be undone.
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for deletion <span style="color:#dc2626;">*</span></label>
                    <textarea name="delete_reason" id="bulkDeleteReason" class="form-control" rows="3" placeholder="Why are you deleting these assets?" style="border-color:#fca5a5;"></textarea>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                <button type="button" onclick="document.getElementById('bulkModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Apply to All Selected</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>

