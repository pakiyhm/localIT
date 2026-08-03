<?php
// accessories.php — Accessories Portal (trackable items linked to device assets)
require_once 'includes/db_connect.php';

// All unique categories from accessories
$categories = $pdo->query("SELECT DISTINCT category FROM accessories ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// All users for OUT/Borrow assign dropdown
$users = $pdo->query("SELECT user_id, full_name, department FROM users WHERE resigned_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// All locations
$locations = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// All device assets for parent linking
$deviceAssets = $pdo->query("SELECT asset_id, category, hostname, serial_number FROM assets ORDER BY category, hostname ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filters from GET
$filterCat    = $_GET['cat']    ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterOwn    = $_GET['own']    ?? 'all';

// Build query
$where = [];
$params = [];
if ($filterCat    !== 'all') { $where[] = "ac.category = :cat";           $params['cat']    = $filterCat; }
if ($filterStatus !== 'all') { $where[] = "ac.status = :status";          $params['status'] = $filterStatus; }
if ($filterOwn    !== 'all') { $where[] = "ac.ownership_type = :own";     $params['own']    = $filterOwn; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$accessories = $pdo->prepare("
    SELECT ac.*, u.full_name, l.location_name,
           pa.hostname as parent_hostname, pa.category as parent_category
    FROM   accessories ac
    LEFT JOIN users     u  ON ac.user_id          = u.user_id
    LEFT JOIN locations l  ON ac.location_id       = l.location_id
    LEFT JOIN assets    pa ON ac.parent_asset_id   = pa.asset_id
    $whereSql
    ORDER BY ac.category ASC, ac.name ASC
");
$accessories->execute($params);
$accessories = $accessories->fetchAll(PDO::FETCH_ASSOC);

// Counts for filter badges
$statusCounts = $pdo->query("SELECT status, COUNT(*) as n FROM accessories GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$catCounts    = $pdo->query("SELECT category, COUNT(*) as n FROM accessories GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
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

$pageTitle = 'Accessories';
$activeNav = 'accessories';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'assign_success'): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Accessory assigned (OUT) successfully.</div><?php endif; ?>
<?php if ($msg === 'return_success'): ?><div class="alert alert-info"><i class="bi bi-arrow-return-left"></i> Accessory returned to stock (IN).</div><?php endif; ?>
<?php if ($msg === 'success'): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Accessory saved successfully.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-warning"><i class="bi bi-trash3-fill"></i> <?= __('Accessory permanently deleted') ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ─── Accessories Table & Toolbar ──────────────────── -->
<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="flex-direction:column; align-items:stretch; gap:16px; padding:20px 24px; background:var(--color-surface);">
        
        <!-- Top Row: Title & Add Button -->
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:1.1rem; margin:0;"><i class="bi bi-plug" style="color:var(--color-accent); margin-right:8px;"></i>
                <?= htmlspecialchars(__('Accessories')) ?>
                <span style="font-size:.8rem;font-weight:400;color:var(--color-text-muted);margin-left:6px;"><?= count($accessories) ?> <?= htmlspecialchars(__('records')) ?></span>
            </h3>
            <button onclick="document.getElementById('addAccModal').style.display='flex'" class="btn btn-primary btn-sm edit-only" style="padding:7px 16px; border-radius:6px;">
                <i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Add Accessory')) ?>
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
                        <option value="all" <?= $filterOwn==='all'?'selected':'' ?>>All Ownership</option>
                        <?php foreach (['Buy','Rent','Borrow'] as $o): ?>
                            <option value="<?= $o ?>" <?= $filterOwn===$o?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($filterCat!=='all'||$filterStatus!=='all'||$filterOwn!=='all'): ?>
                    <a href="accessories.php" class="btn btn-ghost btn-sm" style="padding:6px 10px;color:var(--color-danger); border-radius:6px;" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Bulk Toolbar -->
    <div id="bulkToolbar" style="display:none; padding:10px 20px; background:linear-gradient(135deg,#1e3a8a,#2563eb); border-bottom:2px solid #1d4ed8; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div style="font-weight:700; color:#fff; font-size:.9rem; display:flex; align-items:center; gap:8px;">
            <span style="background:rgba(255,255,255,.2); padding:3px 10px; border-radius:20px; font-size:.8rem;">
                <i class="bi bi-check2-square"></i> <span id="bulkCount">0</span> selected
            </span>
        </div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
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
                    <th><?= htmlspecialchars(__('Category')) ?></th>
                    <th><?= htmlspecialchars(__('Name')) ?></th>
                    <th><?= htmlspecialchars(__('Serial Number')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Model')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Purchase Yr')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Service Tag')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('MAC Address')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Purchase Date')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('End Date')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Return Date')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Return To')) ?></th>
                    <th class="adv-col adv-col-header"><?= htmlspecialchars(__('Return Method')) ?></th>
                    <th><?= htmlspecialchars(__('Ownership')) ?></th>
                    <th><?= htmlspecialchars(__('Parent Device')) ?></th>
                    <th><?= htmlspecialchars(__('Assigned To')) ?></th>
                    <th><?= htmlspecialchars(__('Location')) ?></th>
                    <th><?= htmlspecialchars(__('Status')) ?></th>
                    <th style="text-align:center;"><?= htmlspecialchars(__('Controls')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($accessories)): ?>
                <tr><td colspan="9">
                    <div class="empty-state">
                        <i class="bi bi-plug"></i>
                        <p><?= htmlspecialchars(__('No accessories found. Try adjusting your filters or add a new accessory.')) ?></p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($accessories as $a): ?>
                <tr data-accessory-id="<?= $a['accessory_id'] ?>" data-row-id="<?= $a['accessory_id'] ?>">
                    <td style="text-align:center;">
                        <input type="checkbox" class="row-checkbox" value="<?= $a['accessory_id'] ?>" style="cursor:pointer;">
                    </td>
                    <td class="fw-600"><span class="text-truncate-custom" style="max-width:120px;" title="<?= htmlspecialchars($a['category']) ?>"><?= htmlspecialchars($a['category']) ?></span></td>
                    <td class="fw-600"><span class="text-truncate-custom" title="<?= htmlspecialchars($a['name']) ?>"><?= htmlspecialchars($a['name']) ?></span></td>
                    <td class="text-mono"><?= htmlspecialchars($a['serial_number'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell"><span class="text-truncate-custom" title="<?= htmlspecialchars($a['model'] ?? '') ?>"><?= htmlspecialchars($a['model'] ?? '—') ?></span></td>
                    <td class="adv-col adv-col-cell"><?= htmlspecialchars($a['purchase_year'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-mono"><?= htmlspecialchars($a['service_tag'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-mono"><?= htmlspecialchars($a['mac_address'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-muted"><?= htmlspecialchars($a['purchase_date'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-muted"><?= htmlspecialchars($a['end_of_life_date'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-muted"><?= htmlspecialchars($a['vendor_return_date'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-muted"><?= htmlspecialchars($a['vendor_return_to'] ?? '—') ?></td>
                    <td class="adv-col adv-col-cell text-muted"><?= htmlspecialchars($a['vendor_return_method'] ?? '—') ?></td>
                    <td><?= ownBadge($a['ownership_type']) ?></td>
                    <td>
                        <?php if ($a['parent_hostname']): ?>
                            <a href="history.php?asset_id=<?= $a['parent_asset_id'] ?>" class="text-primary-link" style="font-size:.82rem;">
                                <i class="bi bi-laptop" style="font-size:11px;"></i> <?= htmlspecialchars($a['parent_hostname']) ?>
                            </a>
                            <div style="font-size:.7rem;color:var(--color-text-muted);"><?= htmlspecialchars($a['parent_category']) ?></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['full_name']): ?>
                            <a href="users.php#user-<?= $a['user_id'] ?>" class="text-primary-link text-truncate-custom" style="font-size:.82rem; max-width:130px;" title="<?= htmlspecialchars($a['full_name']) ?>">
                                <i class="bi bi-person" style="font-size:11px;"></i> <?= htmlspecialchars($a['full_name']) ?>
                            </a>
                            <?php if (!empty($a['borrow_due_date'])):
                                $today = new DateTime('today');
                                $due = new DateTime($a['borrow_due_date']);
                                $diff = (int)$today->diff($due)->format('%r%a');
                                if ($diff < 0) {
                                    echo '<div><span class="badge badge-overdue"><i class="bi bi-exclamation-triangle"></i> ' . __('Overdue') . ' '.abs($diff).' ' . __('days') . '</span></div>';
                                } elseif ($diff <= 7) {
                                    echo '<div><span class="badge badge-soon"><i class="bi bi-clock"></i> ' . __('Remaining') . ' '.$diff.' ' . __('days') . '</span></div>';
                                } else {
                                    echo '<div class="text-muted" style="font-size:.7rem;margin-top:2px;">Due '.htmlspecialchars($a['borrow_due_date']).'</div>';
                                }
                            endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="text-truncate-custom" title="<?= htmlspecialchars($a['location_name'] ?? '') ?>"><?= htmlspecialchars($a['location_name'] ?? '—') ?></span></td>
                    <td>
                        <?= badge($a['status'], $a['vendor_return_date'] ?? null) ?>
                        <?php if ($a['status'] === 'repair' && (!empty($a['repair_shop']) || !empty($a['repair_eta']))): ?>
                            <div class="text-muted" style="font-size:.7rem; margin-top:3px; line-height:1.3;">
                                <?php if (!empty($a['repair_shop'])): ?><i class="bi bi-shop"></i> <?= htmlspecialchars($a['repair_shop']) ?><?php endif; ?>
                                <?php if (!empty($a['repair_eta'])):
                                    $today = new DateTime('today'); $eta = new DateTime($a['repair_eta']);
                                    $d = (int)$today->diff($eta)->format('%r%a');
                                    $eClass = $d < 0 ? 'badge-overdue' : ($d <= 3 ? 'badge-soon' : '');
                                ?>
                                    <?php if ($eClass): ?>
                                        <span class="badge <?= $eClass ?>" style="font-size:.65rem; padding:1px 6px;"><i class="bi bi-clock"></i> ETA <?= htmlspecialchars($a['repair_eta']) ?></span>
                                    <?php else: ?>
                                        <div><i class="bi bi-clock"></i> ETA <?= htmlspecialchars($a['repair_eta']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-dropdown" style="display:flex; justify-content:center;">
                            <button type="button" onclick="toggleActionDropdown(this)" class="btn btn-sm btn-ghost edit-only" style="padding:4px 8px;" title="Actions">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <div class="action-dropdown-menu edit-only">
                                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($a)) ?>)" class="action-dropdown-item text-primary" title="Edit Accessory Details">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>

                                <?php if ($a['status'] === 'deployed'): ?>
                                    <form action="actions.php" method="POST" style="margin:0;" data-confirm="Return «<?= htmlspecialchars(addslashes($a['name'])) ?>» to stock?" data-confirm-title="Return Accessory" data-confirm-kind="info">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="return_accessory">
                                        <input type="hidden" name="accessory_id" value="<?= $a['accessory_id'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $a['user_id'] ?>">
                                        <button type="submit" class="action-dropdown-item" title="Return item from user back to IT stock">
                                            <i class="bi bi-box-arrow-in-down-left"></i> Return IN
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (in_array($a['status'], ['in_stock', 'repair', 'deployed'])): ?>
                                    <button type="button" onclick="openAccRmaModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')" class="action-dropdown-item text-warning" title="RMA/Replace with new device">
                                        <i class="bi bi-arrow-repeat"></i> RMA
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] === 'in_stock'): ?>
                                    <button type="button" onclick="openAccReturnVendorModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')" class="action-dropdown-item" title="Ship back to Vendor/Lessor">
                                        <i class="bi bi-truck"></i> Vendor
                                    </button>
                                <?php endif; ?>

                                <?php if (in_array($a['status'], ['in_stock','repair'])): ?>
                                    <button type="button" onclick="openAccOutModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>', 'assign')" class="action-dropdown-item text-primary" title="Deploy item to a user permanently">
                                        <i class="bi bi-person-up"></i> Deploy
                                    </button>
                                    <button type="button" onclick="openAccOutModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>', 'borrow')" class="action-dropdown-item" style="color:#9333ea;" title="Loan item to a user temporarily">
                                        <i class="bi bi-clock"></i> Loan
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] !== 'repair' && $a['status'] !== 'retired' && $a['status'] !== 'lost'): ?>
                                    <button type="button" onclick="openAccRepairSendModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')" class="action-dropdown-item text-warning" title="Send to Repair">
                                        <i class="bi bi-wrench"></i> Repair
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] === 'repair'): ?>
                                    <button type="button" onclick="openAccRepairReturnModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'])) ?>')" class="action-dropdown-item text-success" title="Mark as Repaired & Ready">
                                        <i class="bi bi-check-circle"></i> Fixed
                                    </button>
                                <?php endif; ?>

                                <?php if ($a['status'] !== 'retired' && $a['status'] !== 'lost'): ?>
                                    <form action="actions.php" method="POST" style="margin:0;" data-confirm="<?= __('Retire this item?') ?> (จะถูกเก็บออกจากกองหลัก)" data-confirm-title="Retire Accessory">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_accessory_retired">
                                        <input type="hidden" name="accessory_id" value="<?= $a['accessory_id'] ?>">
                                        <button type="submit" class="action-dropdown-item" title="Mark as Retired">
                                            <i class="bi bi-archive"></i> Retire
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <!-- Permanent Delete -->
                                <button type="button" onclick="openDeleteAccModal(<?= $a['accessory_id'] ?>, '<?= htmlspecialchars(addslashes($a['name'] ?? 'Accessory #'.$a['accessory_id'])) ?>')" class="action-dropdown-item text-danger" title="<?= __('Permanently Delete') ?>">
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

<!-- ─── Permanent Delete Accessory Modal ───────────── -->
<div id="deleteAccModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:1050;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:14px;width:460px;max-width:94vw;box-shadow:0 24px 70px rgba(220,38,38,.18);overflow:hidden;border:2px solid #fecaca;">
        <div style="padding:18px 24px;border-bottom:1px solid #fecaca;display:flex;justify-content:space-between;align-items:center;background:#fff1f2;">
            <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin:0;">
                <i class="bi bi-trash3-fill"></i> <?= __('Permanently delete accessory') ?>
            </h3>
            <button onclick="closeDeleteAccModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:20px 24px;">
            <div style="padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:18px;font-size:.85rem;color:#991b1b;line-height:1.6;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong><?= __('Warning:') ?></strong> การลบนี้ <strong><?= __('Cannot be undone.') ?></strong> <?= __('System will delete') ?>
                <span id="deleteAccName" style="font-weight:700;"></span>
                <?= __('permanently, but keep') ?><strong><?= __('audit logs.') ?></strong>
            </div>
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_accessory">
                <input type="hidden" name="accessory_id" id="deleteAccId" value="">
                <div class="form-group" style="margin-bottom:18px;">
                    <label class="form-label" style="font-weight:600;color:#374151;"><?= __('Reason for deletion') ?> <span style="color:#dc2626;">*</span></label>
                    <textarea name="delete_reason" id="deleteAccReason" class="form-control" rows="3"
                              placeholder="<?= __('e.g., Duplicate, incorrect import, testing, etc.') ?>" required
                              style="border-color:#fca5a5; resize:vertical;"></textarea>
                    <small style="color:#6b7280;font-size:.72rem;"><?= __('This reason will be permanently recorded in the audit log.') ?></small>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" onclick="closeDeleteAccModal()" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn" style="background:#dc2626;color:#fff;border-color:#dc2626;font-weight:600;"
                            onclick="return validateDeleteAccReason()">
                        <i class="bi bi-trash3-fill"></i> <?= __('Confirm Permanent Deletion') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDeleteAccModal(accId, accName) {
    document.getElementById('deleteAccId').value = accId;
    document.getElementById('deleteAccName').textContent = ' "' + accName + '" ';
    document.getElementById('deleteAccReason').value = '';
    document.getElementById('deleteAccModal').style.display = 'flex';
    setTimeout(() => document.getElementById('deleteAccReason').focus(), 100);
}
function closeDeleteAccModal() {
    document.getElementById('deleteAccModal').style.display = 'none';
}
function validateDeleteAccReason() {
    const r = document.getElementById('deleteAccReason').value.trim();
    if (!r) {
        document.getElementById('deleteAccReason').style.borderColor = '#dc2626';
        document.getElementById('deleteAccReason').focus();
        return false;
    }
    return true;
}
document.getElementById('deleteAccModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteAccModal();
});
</script>

<!-- ─── OUT / Borrow Modal ──────────────────────────── -->
<div id="accOutModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="accOutTitle" style="font-size:1rem;font-weight:600;"></h3>
            <button onclick="closeAccOutModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"       id="accOutAction"  value="assign_accessory">
            <input type="hidden" name="accessory_id" id="accOutId"      value="">

            <div class="form-group">
                <label class="form-label">Assign To User</label>
                <select name="user_id_direct" id="accOutUserId" class="form-control form-select" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>">
                            <?= htmlspecialchars($u['full_name']) ?>
                            <?= $u['department'] ? ' ('.$u['department'].')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="accBorrowNote" style="display:none;padding:10px 12px;background:#ede9fe;border-radius:8px;margin-bottom:14px;font-size:.82rem;color:#5b21b6;">
                <i class="bi bi-info-circle"></i> Ownership = <strong>Borrow</strong>, status = <strong>Deployed</strong>
            </div>

            <div id="accBorrowDueGroup" class="form-group" style="display:none;">
                <label class="form-label"><?= __('Due Date') ?> <span style="color:#9ca3af;font-weight:400;"><?= __('— optional') ?></span></label>
                <input type="date" name="borrow_due_date" id="accBorrowDueDate" class="form-control" min="<?= date('Y-m-d') ?>">
                <small class="text-muted" style="font-size:.72rem;"><?= __('System will warn when approaching or overdue') ?></small>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeAccOutModal()" class="btn btn-ghost">Cancel</button>
                <button type="submit" id="accOutBtn" class="btn btn-primary"><i class="bi bi-arrow-up-circle"></i> Confirm OUT</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Add Accessory Modal ─────────────────────────── -->
<div id="addAccModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:500px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-plus-circle" style="color:#2563eb;"></i> Add New Accessory</h3>
            <button onclick="document.getElementById('addAccModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_accessory">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category <span style="color:#dc2626;">*</span></label>
                    <input list="accCatList" name="category" class="form-control" placeholder="USB-C Dock, Charger…" required>
                    <datalist id="accCatList">
                        <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
                        <option value="USB-C Dock"><option value="Charger"><option value="Webcam">
                        <option value="Cable"><option value="Adapter"><option value="Carrying Bag">
                        <option value="Mouse"><option value="Keyboard"><option value="Headset">
                    </datalist>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="Dell WD19S Dock" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" placeholder="ACC-SN-…">
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
                        <input type="text" name="model" class="form-control" placeholder="Model info...">
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

            <div class="form-group">
                <label class="form-label">Link to Parent Device Asset</label>
                <select name="parent_asset_id" class="form-control form-select">
                    <option value="">— None (standalone) —</option>
                    <?php foreach ($deviceAssets as $da): ?>
                        <option value="<?= $da['asset_id'] ?>">
                            [<?= htmlspecialchars($da['category']) ?>] <?= htmlspecialchars($da['hostname'] ?? $da['serial_number'] ?? 'ID:'.$da['asset_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Note</label>
                <input type="text" name="note" class="form-control" placeholder="Optional note…">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
                <button type="button" onclick="document.getElementById('addAccModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Accessory</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Edit Accessory Modal ──────────────────────────── -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:500px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-pencil" style="color:#2563eb;"></i> Edit Accessory</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_accessory">
            <input type="hidden" name="accessory_id" id="editAccId">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category <span style="color:#dc2626;">*</span></label>
                    <input list="catList" name="category" id="editCategory" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Name <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="name" id="editName" class="form-control" required>
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
                    <label class="form-label">Location (For unassigned items)</label>
                    <select name="location_id" id="editLocation" class="form-control form-select">
                        <option value="">— None —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Parent Device (Optional)</label>
                <select name="parent_asset_id" id="editParentId" class="form-control form-select">
                    <option value="0">— No Parent Device —</option>
                    <?php foreach ($deviceAssets as $da): ?>
                        <option value="<?= $da['asset_id'] ?>">
                            <?= htmlspecialchars($da['category']) ?>: <?= htmlspecialchars($da['hostname'] ?? $da['serial_number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Edit Note (Optional)</label>
                <input type="text" name="note" class="form-control" placeholder="Reason for change...">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update Accessory</button>
            </div>
        </form>

        <!-- ─ Attachments Section ─ -->
        <div style="border-top:1px solid var(--color-border-soft); padding:18px 24px; background:var(--color-bg);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <h4 style="font-size:.88rem; font-weight:600; margin:0;"><i class="bi bi-paperclip"></i> <?= __('Attachments') ?></h4>
            </div>
            <div id="accAttachList" style="margin-bottom:12px;">
                <div class="text-muted" style="font-size:.82rem;"><?= __('Loading...') ?></div>
            </div>
            <form action="attachments.php" method="POST" enctype="multipart/form-data" class="edit-only"
                  style="display:flex; gap:8px; align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="action"       value="upload">
                <input type="hidden" name="accessory_id" id="accAttachId" value="">
                <input type="file" name="file" required style="font-size:.82rem; flex:1;">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Upload</button>
            </form>
            <small class="text-muted" style="font-size:.7rem;"><?= __('Max size 20MB') ?></small>
        </div>
    </div>
</div>

<!-- ─── Return to Vendor Modal ────────────────────────── -->
<div id="accVendorModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="accVendorModalTitle" style="font-size:1rem;font-weight:600;">Return to Vendor</h3>
            <button onclick="document.getElementById('accVendorModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="return_accessory_to_vendor">
            <input type="hidden" name="accessory_ids" id="accVendorId" value="">

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
                <button type="button" onclick="document.getElementById('accVendorModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#be123c;border-color:#be123c;"><i class="bi bi-box-arrow-right"></i> Submit Return</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Repair Send / Return Modals ──────────────────── -->
<div id="accRepairSendModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:440px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="accRepairSendTitle" style="font-size:1rem;font-weight:600;"><i class="bi bi-wrench" style="color:#d97706;"></i> <?= __('Send for Repair') ?></h3>
            <button onclick="document.getElementById('accRepairSendModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"       value="set_accessory_repair">
            <input type="hidden" name="accessory_id" id="accRepairSendId" value="">
            <div class="form-group">
                <label class="form-label"><?= __('Repair Shop / Vendor') ?></label>
                <input type="text" name="repair_shop" class="form-control" placeholder="<?= __('Name of repair shop or responsible person') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">ETA</label>
                <input type="date" name="repair_eta" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= __('Symptoms / Notes') ?></label>
                <textarea name="repair_note" class="form-control" rows="3"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('accRepairSendModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-wrench"></i> <?= __('Send for Repair') ?></button>
            </div>
        </form>
    </div>
</div>

<div id="accRepairReturnModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="accRepairReturnTitle" style="font-size:1rem;font-weight:600;"><i class="bi bi-check-circle" style="color:#16a34a;"></i> <?= __('Return from Repair') ?></h3>
            <button onclick="document.getElementById('accRepairReturnModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"       value="set_accessory_fixed">
            <input type="hidden" name="accessory_id" id="accRepairReturnId" value="">
            <div class="form-group">
                <label class="form-label"><?= __('Notes (What was repaired / Costs etc.)') ?></label>
                <textarea name="completion_note" class="form-control" rows="3"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('accRepairReturnModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> <?= __('Confirm Return') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Bulk Update Modal (Location/User/Status) ─────── -->
<div id="bulkAccModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:440px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="bulkAccModalTitle" style="font-size:1rem;font-weight:600;">Bulk Update</h3>
            <button onclick="document.getElementById('bulkAccModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="bulkAccAction" value="">
            <input type="hidden" name="accessory_ids" id="bulkAccIds" value="">

            <div id="bulkLocGroup" class="form-group" style="display:none;">
                <label class="form-label">New Location</label>
                <select name="location_id" class="form-control form-select">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="bulkUserGroup" class="form-group" style="display:none;">
                <label class="form-label">Assign to User</label>
                <select name="user_id" class="form-control form-select">
                    <option value="">— Select —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?><?= $u['department'] ? ' ('.$u['department'].')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="bulkStatGroup" class="form-group" style="display:none;">
                <label class="form-label">New Status</label>
                <select name="status" class="form-control form-select">
                    <option value="in_stock">In Stock</option>
                    <option value="deployed">Deployed</option>
                    <option value="repair">Repair</option>
                    <option value="retired">Retired</option>
                    <option value="lost">Lost</option>
                </select>
            </div>

            <!-- Batch Edit -->
            <div id="bulkBatchGroup" style="display:none;">
                <div class="form-group">
                    <label class="form-label">Batch / PO Number</label>
                    <input type="text" name="po_number" class="form-control" placeholder="Leave blank to keep unchanged">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <input list="catListBulkAcc" name="category" class="form-control" placeholder="Leave blank to keep unchanged">
                    <datalist id="catListBulkAcc">
                        <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
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
                    <strong> Warning:</strong> You are about to delete these accessories permanently. This action cannot be undone.
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for deletion <span style="color:#dc2626;">*</span></label>
                    <textarea name="delete_reason" class="form-control" rows="3" placeholder="Why are you deleting these accessories?" style="border-color:#fca5a5;"></textarea>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
                <button type="button" onclick="document.getElementById('bulkAccModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Apply</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── RMA Replace Modal ────────────────────────────── -->
<div id="accRmaModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="accRmaModalTitle" style="font-size:1rem;font-weight:600;">RMA Replacement</h3>
            <button onclick="document.getElementById('accRmaModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:20px 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rma_accessory_replace">
            <input type="hidden" name="old_accessory_id" id="accRmaOldId" value="">

            <div style="margin-bottom:15px; padding:10px; background:#fff7ed; border:1px solid #ffedd5; border-radius:6px; font-size:0.85rem; color:#ea580c;">
                <i class="bi bi-info-circle"></i> This will retire the old accessory and create a new identical one. If the old accessory is deployed, the new one will automatically be assigned to the same user.
            </div>

            <div class="form-group">
                <label class="form-label">New Serial Number</label>
                <input type="text" name="serial_number" class="form-control" placeholder="Optional. SN of the new replacement">
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
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('accRmaModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-sm" style="background:#ea580c;color:#fff;border:none;font-weight:600;"><i class="bi bi-arrow-repeat"></i> Process RMA</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(a) {
    document.getElementById('editAccId').value        = a.accessory_id;
    document.getElementById('editCategory').value     = a.category;
    document.getElementById('editName').value         = a.name;
    document.getElementById('editSerialNumber').value = a.serial_number || '';
    document.getElementById('editOwnership').value    = a.ownership_type;
    document.getElementById('editStatus').value       = a.status;
    document.getElementById('editLocation').value     = a.location_id   || '';
    document.getElementById('editParentId').value     = a.parent_asset_id || 0;
    
    document.getElementById('editModel').value        = a.model        || '';
    document.getElementById('editPurchaseYear').value = a.purchase_year || '';
    document.getElementById('editServiceTag').value   = a.service_tag   || '';
    document.getElementById('editMacAddress').value   = a.mac_address   || '';
    document.getElementById('editPoNumber').value     = a.po_number     || '';
    document.getElementById('editPurchaseDate').value = a.purchase_date  || '';
    document.getElementById('editEolDate').value      = a.end_of_life_date || '';

    // Wire attachments
    document.getElementById('accAttachId').value = a.accessory_id;
    const list = document.getElementById('accAttachList');
    list.innerHTML = '<div class="text-muted" style="font-size:.82rem;"><?= __('Loading...') ?></div>';
    fetch('attachments.php?action=list&accessory_id=' + a.accessory_id)
        .then(r => r.text())
        .then(html => { list.innerHTML = html; });

    document.getElementById('editModal').style.display = 'flex';
}
function openAccOutModal(accId, label, mode) {
    document.getElementById('accOutId').value = accId;
    document.getElementById('accOutAction').value  = mode === 'borrow' ? 'borrow_accessory' : 'assign_accessory';
    document.getElementById('accOutTitle').textContent = (mode === 'borrow' ? 'Loan Out: ' : 'Deploy: ') + label;
    document.getElementById('accBorrowNote').style.display  = mode === 'borrow' ? 'block' : 'none';
    document.getElementById('accBorrowDueGroup').style.display = mode === 'borrow' ? 'block' : 'none';
    document.getElementById('accBorrowDueDate').value = '';
    document.getElementById('accOutBtn').textContent  = mode === 'borrow' ? '↔ Confirm Loan' : '↑ Confirm Deploy';
    document.getElementById('accOutModal').style.display    = 'flex';
    document.getElementById('accOutUserId').value = '';
}
function closeAccOutModal() {
    document.getElementById('accOutModal').style.display = 'none';
}
// Close modals on backdrop click
['accOutModal','addAccModal','accVendorModal','editModal','accRmaModal'].forEach(function(id) {
    const el = document.getElementById(id);
    if(el) {
        el.addEventListener('click', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    }
});
function openAccReturnVendorModal(accId, label) {
    document.getElementById('accVendorId').value = accId;
    document.getElementById('accVendorModalTitle').textContent = 'Return to Vendor: ' + label;
    document.getElementById('accVendorModal').style.display = 'flex';
}

function openAccRmaModal(accId, label) {
    document.getElementById('accRmaOldId').value = accId;
    document.getElementById('accRmaModalTitle').textContent = 'RMA Replace: ' + label;
    document.getElementById('accRmaModal').style.display = 'flex';
}
function openAccRepairSendModal(id, label) {
    document.getElementById('accRepairSendId').value = id;
    document.getElementById('accRepairSendTitle').innerHTML = '<i class="bi bi-wrench" style="color:#d97706;"></i> <?= __('Send for Repair:') ?> ' + label;
    document.getElementById('accRepairSendModal').style.display = 'flex';
}
function openAccRepairReturnModal(id, label) {
    document.getElementById('accRepairReturnId').value = id;
    document.getElementById('accRepairReturnTitle').innerHTML = '<i class="bi bi-check-circle" style="color:#16a34a;"></i> <?= __('Return from Repair:') ?> ' + label;
    document.getElementById('accRepairReturnModal').style.display = 'flex';
}
['accRepairSendModal','accRepairReturnModal'].forEach(id => {
    const m = document.getElementById(id);
    if (m) m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});

// ─── QR Deep-link focus (accessories.php?focus=ID) ────────────
(function focusFromQR() {
    const m = location.search.match(/[?&]focus=(\d+)/);
    if (!m) return;
    const id = m[1];
    const row = document.querySelector('tr[data-accessory-id="' + id + '"]');
    if (!row) return;
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.style.transition = 'background .4s';
    const orig = row.style.background;
    row.style.background = '#fef3c7';
    setTimeout(() => { row.style.background = orig; }, 2500);
})();

// ─── Bulk Actions Logic ───────────────────────────────────────
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.row-checkbox');
const bulkToolbar = document.getElementById('bulkToolbar');
const bulkCount = document.getElementById('bulkCount');

function getCheckedIds() {
    return [...document.querySelectorAll('.row-checkbox:checked')].map(cb => cb.value);
}

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkToolbar();
}

function bulkExport() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    const url = 'export_handler.php?type=bulk_accessories&ids=' + encodeURIComponent(ids.join(','));
    window.location.href = url;
}

function bulkPrintLabels() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    window.open('print_labels.php?type=accessories&ids=' + encodeURIComponent(ids.join(',')), '_blank');
}

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
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkToolbar();
    });
}
checkboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkToolbar);
});

function openBulkReturnVendorModal() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (checked.length === 0) return;

    let ids = [];
    checked.forEach(cb => ids.push(cb.value));

    document.getElementById('accVendorId').value = ids.join(',');
    document.getElementById('accVendorModalTitle').textContent = 'Bulk Return ' + ids.length + ' Accessories to Vendor';
    document.getElementById('accVendorModal').style.display = 'flex';
}

// ─── Bulk Update Modal (Location / User / Status) ─────────────
function openBulkModal(type) {
    const ids = getCheckedIds();
    if (!ids.length) { askConfirm({title:'<?= __('Select accessory first') ?>', message:'<?= __('Please select at least 1 item') ?>', confirmText:'OK', danger:false}); return; }
    document.getElementById('bulkAccIds').value = ids.join(',');
    document.getElementById('bulkLocGroup').style.display  = type === 'location' ? 'block' : 'none';
    document.getElementById('bulkUserGroup').style.display = type === 'user'     ? 'block' : 'none';
    document.getElementById('bulkStatGroup').style.display = type === 'status'   ? 'block' : 'none';
    document.getElementById('bulkBatchGroup').style.display = type === 'batch'   ? 'block' : 'none';
    document.getElementById('bulkDeleteGroup').style.display = type === 'delete' ? 'block' : 'none';
    document.getElementById('bulkAccModalTitle').textContent =
        type === 'location' ? 'Set Location for ' + ids.length + ' Accessories' :
        type === 'user'     ? 'Assign User to ' + ids.length + ' Accessories' :
        type === 'batch'    ? 'Batch Update ' + ids.length + ' Accessories' :
        type === 'delete'   ? 'Bulk Delete ' + ids.length + ' Accessories' :
                              'Change Status of ' + ids.length + ' Accessories';
    const actionMap = {
        location:'bulk_update_accessory_location',
        user:    'bulk_assign_accessory_user',
        status:  'bulk_update_accessory_status',
        batch:   'bulk_edit_accessories',
        delete:  'bulk_delete_accessories'
    };
    document.getElementById('bulkAccAction').value = actionMap[type];
    document.getElementById('bulkAccModal').style.display = 'flex';
}
// Backdrop click closes bulk modal
(function(){
    const m = document.getElementById('bulkAccModal');
    if (m) m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
})();
// Escape key closes modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['accOutModal','addAccModal','accVendorModal','editModal','accRmaModal'].forEach(id => {
            const el = document.getElementById(id);
            if(el) el.style.display = 'none';
        });
    }
});
</script>

<?php require_once 'includes/layout_end.php'; ?>
