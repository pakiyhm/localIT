<?php
// index.php — Dashboard (Action-First layout)
require_once 'includes/db_connect.php';

// ─── KPI Stats ───────────────────────────────────────────────
$totalAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status NOT IN ('retired','lost')")->fetchColumn();
$deployed    = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='deployed'")->fetchColumn();
$inStock     = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='in_stock'")->fetchColumn();
$inRepair    = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='repair'")->fetchColumn();

// ─── Unified "Needs Attention" feed ─────────────────────────
require_once 'includes/attention_helper.php';
$attention      = getAttentionList($pdo);
$bellItems      = $attention;  // cache for layout.php bell notification (avoids re-query)
$attentionTotal = count($attention);
$attentionShow  = array_slice($attention, 0, 10);

// ─── Status distribution + category breakdown ───────────────
$statusBreakdown = $pdo->query("
    SELECT status, COUNT(*) as n FROM assets
    WHERE  status NOT IN ('retired','lost')
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$topCategories = $pdo->query("
    SELECT category, COUNT(*) as n FROM assets
    WHERE  status NOT IN ('retired','lost')
    GROUP BY category ORDER BY n DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Recent Activity (last 7 days from logs) ────────────────
$recentActivity = $pdo->query("
    SELECT l.action_type, l.created_at, l.note,
           COALESCE(a.hostname, ac.name)              AS item_name,
           COALESCE(a.serial_number, ac.serial_number) AS item_sn,
           COALESCE(a.category, ac.category)          AS item_cat,
           a.asset_id, ac.accessory_id,
           u_to.full_name   AS to_user,
           u_from.full_name AS from_user
    FROM   logs l
    LEFT JOIN assets      a     ON l.asset_id      = a.asset_id
    LEFT JOIN accessories ac    ON l.accessory_id  = ac.accessory_id
    LEFT JOIN users       u_to  ON l.to_user_id    = u_to.user_id
    LEFT JOIN users       u_from ON l.from_user_id = u_from.user_id
    WHERE  l.created_at >= date('now','-7 days')
    ORDER BY l.created_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);


// ─── Search redirect (topbar scanner uses ?q=) ───────────────
$q = trim($_GET['q'] ?? '');
$searchResults = [];
if ($q !== '') {
    $s = $pdo->prepare("
        SELECT 'asset' as item_kind, a.asset_id as id, a.category, a.hostname as name, a.serial_number, a.service_tag,
               a.status, a.ownership_type, u.full_name, u.user_id, l.location_name
        FROM   assets a
        LEFT JOIN users     u ON a.user_id     = u.user_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE  a.hostname LIKE :q OR a.serial_number LIKE :q OR a.service_tag LIKE :q OR a.mac_address LIKE :q OR u.full_name LIKE :q OR u.emp_id LIKE :q
        UNION ALL
        SELECT 'accessory' as item_kind, ac.accessory_id as id, ac.category, ac.name as name, ac.serial_number, ac.service_tag,
               ac.status, ac.ownership_type, u.full_name, u.user_id, l.location_name
        FROM   accessories ac
        LEFT JOIN users     u ON ac.user_id     = u.user_id
        LEFT JOIN locations l ON ac.location_id = l.location_id
        WHERE  ac.name LIKE :q OR ac.serial_number LIKE :q OR ac.service_tag LIKE :q OR ac.mac_address LIKE :q OR u.full_name LIKE :q OR u.emp_id LIKE :q
        LIMIT 50
    ");
    $s->execute(['q' => "%$q%"]);
    $searchResults = $s->fetchAll(PDO::FETCH_ASSOC);
}

function dashBadge($status) {
    $safe  = htmlspecialchars($status);
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class='badge badge-{$safe}'>{$label}</span>";
}

function renderTooltip($pdo, $sql, $title, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return '';
    $html  = '<div class="stat-card-tooltip">';
    $html .= '<div class="tooltip-title">' . htmlspecialchars($title) . '</div>';
    $html .= '<ul class="tooltip-list">';
    foreach ($rows as $r) {
        $html .= '<li class="tooltip-item">';
        $html .= '<span>' . htmlspecialchars($r['category'] ?? '—') . '</span>';
        $html .= '<span class="count">' . $r['n'] . '</span>';
        $html .= '</li>';
    }
    $html .= '</ul></div>';
    return $html;
}

// Recent activity icon map
function actIcon($action) {
    return match($action) {
        'assign' => ['bi-person-check-fill', 'assign', 'assigned to'],
        'return' => ['bi-arrow-return-left', 'return', 'returned'],
        'repair' => ['bi-tools',              'repair', 'sent for repair'],
        'retire' => ['bi-archive-fill',       'retire', 'retired'],
        'update' => ['bi-pencil-square',      'update', 'updated'],
        'import' => ['bi-download',           'import', 'imported'],
        default  => ['bi-circle',             'default', $action],
    };
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once 'includes/layout.php';
?>

<style>
    /* Stat grid — 4 tiles only on dashboard */
    .dash-stats { grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 1100px) { .dash-stats { grid-template-columns: repeat(2, 1fr); } }

    /* Needs-Attention panel */
    .att-panel {
        background: #fff; border: 1px solid var(--color-border);
        border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
        margin-bottom: 22px;
    }
    .att-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 22px; border-bottom: 1px solid var(--color-border-soft);
    }
    .att-head h3 {
        display: flex; align-items: center; gap: 8px;
        font-size: .98rem; font-weight: 600;
    }
    .att-head .att-count {
        font-size: .72rem; font-weight: 700;
        color: var(--color-text-muted);
        background: var(--color-bg); padding: 3px 10px; border-radius: 99px;
    }
    .att-empty {
        padding: 36px 20px; text-align: center; color: var(--color-text-muted);
    }
    .att-row-wrapper {
        position: relative; display: flex; align-items: center; 
        border-bottom: 1px solid var(--color-border-soft); transition: background .12s;
    }
    .att-row-wrapper:last-child { border-bottom: none; }
    .att-row-wrapper:hover { background: var(--color-bg); }

    /* Custom Dropdown for Dashboard */
    .dropdown { position: relative; }
    .dropdown-menu {
        display: none; position: absolute; right: 0; top: 100%; margin-top: 6px;
        background: #fff; border: 1px solid var(--color-border-soft); border-radius: 8px;
        box-shadow: 0 4px 16px rgba(0,0,0,.12); z-index: 100; min-width: 180px; padding: 6px 0;
    }
    .dropdown-menu.show { display: block; }
    .dropdown-item {
        display: flex; align-items: center; padding: 8px 16px; font-size: 0.85rem;
        color: var(--color-text); text-decoration: none; transition: background .12s;
        cursor: pointer;
    }
    .dropdown-item:hover { background: var(--color-bg); }
    .dropdown-divider { border-top: 1px solid var(--color-border-soft); margin: 6px 0; }

    .att-row {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 22px; flex: 1;
        text-decoration: none; color: inherit;
    }

    .att-ico {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; font-size: 14px;
    }
    .att-body { flex: 1; min-width: 0; }
    .att-title { font-weight: 600; font-size: .9rem; color: var(--color-text); }
    .att-sub   { font-size: .75rem; color: var(--color-text-muted); margin-top: 2px; }
    .att-meta  { text-align: right; flex-shrink: 0; }
    .att-days {
        display: inline-block; font-size: .72rem; font-weight: 700;
        padding: 3px 10px; border-radius: 99px;
        font-family: 'SFMono-Regular', ui-monospace, monospace;
    }
    .att-days.overdue { background: #fee2e2; color: #991b1b; }
    .att-days.soon    { background: #fef3c7; color: #92400e; }
    .att-days.later   { background: var(--color-bg); color: var(--color-text-muted); }
    .att-date { font-size: .7rem; color: var(--color-text-muted); margin-top: 2px; }
    .att-foot {
        padding: 10px 22px; text-align: center;
        background: var(--color-bg); border-top: 1px solid var(--color-border-soft);
    }

    /* 2-column block: status + activity */
    .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 1100px) { .dash-grid { grid-template-columns: 1fr; } }

    /* Status bar */
    .stat-bar-track {
        display: flex; height: 12px; border-radius: 99px; overflow: hidden;
        background: var(--color-border-soft); margin: 14px 0;
    }
    .stat-bar-seg { transition: width .3s; }
    .stat-bar-seg.deployed { background: #2563eb; }
    .stat-bar-seg.in_stock { background: #16a34a; }
    .stat-bar-seg.repair   { background: #d97706; }
    .stat-bar-legend { display: flex; flex-wrap: wrap; gap: 16px; font-size: .82rem; }
    .stat-bar-legend .leg {
        display: flex; align-items: center; gap: 6px;
    }
    .stat-bar-legend .leg .dot {
        width: 10px; height: 10px; border-radius: 3px;
    }

    /* Category bars */
    .cat-row { display: grid; grid-template-columns: 90px 1fr 30px; gap: 10px; align-items: center; margin-bottom: 8px; }
    .cat-row .name { font-size: .82rem; color: var(--color-text-sub); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cat-row .track { height: 8px; background: var(--color-border-soft); border-radius: 99px; overflow: hidden; }
    .cat-row .fill  { height: 100%; background: var(--color-accent); border-radius: 99px; }
    .cat-row .num   { font-size: .78rem; font-weight: 700; text-align: right; color: var(--color-text-sub); }

    /* Activity timeline */
    .act-list { list-style: none; padding: 0; margin: 0; }
    .act-list li {
        display: flex; gap: 12px; padding: 10px 22px;
        border-bottom: 1px solid var(--color-border-soft);
        font-size: .85rem;
    }
    .act-list li:last-child { border-bottom: none; }
    .act-ico {
        width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 12px; flex-shrink: 0;
    }
    .act-ico.assign  { background: var(--color-accent-lite);  color: var(--color-accent); }
    .act-ico.return  { background: var(--color-success-bg);   color: var(--color-success); }
    .act-ico.repair  { background: var(--color-warning-bg);   color: var(--color-warning); }
    .act-ico.retire  { background: var(--color-danger-bg);    color: var(--color-danger); }
    .act-ico.update  { background: #f3e8ff;                   color: var(--color-purple); }
    .act-ico.import  { background: var(--color-info-bg);      color: var(--color-info); }
    .act-ico.default { background: var(--color-border);       color: var(--color-text-muted); }
    .act-body { flex: 1; }
    .act-title { color: var(--color-text); }
    .act-meta  { font-size: .72rem; color: var(--color-text-muted); margin-top: 2px; }
</style>

<!-- ─── KPI Cards (4 only) ──────────────────────────────────── -->
<div class="stat-grid dash-stats">
    <a href="assets.php" class="stat-card blue" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-layers"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $totalAssets ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Total Assets')) ?></div>
        </div>
        <?= renderTooltip($pdo, "SELECT category, COUNT(*) as n FROM assets WHERE status NOT IN ('retired','lost') GROUP BY category ORDER BY n DESC", __('Total by Category')) ?>
    </a>
    <a href="assets.php?status=deployed" class="stat-card green" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-person-check"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $deployed ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Deployed')) ?></div>
        </div>
        <?= renderTooltip($pdo, "SELECT category, COUNT(*) as n FROM assets WHERE status='deployed' GROUP BY category ORDER BY n DESC", __('Deployed by Category')) ?>
    </a>
    <a href="assets.php?status=in_stock" class="stat-card info" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-archive"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $inStock ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('In Stock')) ?></div>
        </div>
        <?= renderTooltip($pdo, "SELECT category, COUNT(*) as n FROM assets WHERE status='in_stock' GROUP BY category ORDER BY n DESC", __('In Stock by Category')) ?>
    </a>
    <a href="assets.php?status=repair" class="stat-card warn" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-tools"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $inRepair ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('In Repair')) ?></div>
        </div>
        <?= renderTooltip($pdo, "SELECT category, COUNT(*) as n FROM assets WHERE status='repair' GROUP BY category ORDER BY n DESC", __('Repair by Category')) ?>
    </a>
</div>

<!-- ─── Search results (if query) ───────────────────────────── -->
<?php if ($q !== ''): ?>
<div class="card" style="margin-bottom:22px;">
    <div class="card-header">
        <h3><i class="bi bi-search"></i> <?= htmlspecialchars(__('Search results for')) ?> "<?= htmlspecialchars($q) ?>"
            <span class="text-muted" style="font-weight:400; font-size:.8rem;"><?= count($searchResults) ?> <?= htmlspecialchars(__('matches')) ?></span>
        </h3>
        <a href="index.php" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> Clear</a>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead><tr>
                <th><?= htmlspecialchars(__('Category')) ?></th><th><?= htmlspecialchars(__('Hostname')) ?></th><th><?= htmlspecialchars(__('Serial')) ?></th>
                <th><?= htmlspecialchars(__('Assigned To')) ?></th><th><?= htmlspecialchars(__('Location')) ?></th><th><?= htmlspecialchars(__('Status')) ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($searchResults)): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-search"></i><p><?= htmlspecialchars(__('No assets found matching')) ?> "<?= htmlspecialchars($q) ?>"</p></div></td></tr>
            <?php else: foreach ($searchResults as $r): ?>
                <tr>
                    <td class="fw-600"><?= htmlspecialchars($r['category']) ?></td>
                    <td>
                        <?php if ($r['item_kind'] === 'asset'): ?>
                            <a href="history.php?asset_id=<?= $r['id'] ?>" class="text-primary-link"><?= htmlspecialchars($r['name'] ?? '—') ?></a>
                        <?php else: ?>
                            <a href="accessories.php" class="text-primary-link"><?= htmlspecialchars($r['name'] ?? '—') ?></a> <span class="badge" style="background:#f0fdf4;color:#166534;font-size:0.7rem;">Accessory</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-mono"><?= htmlspecialchars($r['serial_number'] ?: ($r['service_tag'] ?: '—')) ?></td>
                    <td><?= $r['full_name'] ? htmlspecialchars($r['full_name']) : '<span class="text-muted">'.htmlspecialchars(__('Unassigned')).'</span>' ?></td>
                    <td><?= htmlspecialchars($r['location_name'] ?? '—') ?></td>
                    <td><?= dashBadge($r['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ─── Needs Attention (unified) ───────────────────────────── -->
<div class="att-panel">
    <div class="att-head">
        <h3>
            <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;"></i>
            <?= htmlspecialchars(__('Needs Attention')) ?>
        </h3>
        <span class="att-count"><?= $attentionTotal ?> <?= htmlspecialchars(__('items')) ?></span>
    </div>

    <?php if ($attentionTotal === 0): ?>
        <div class="att-empty">
            <i class="bi bi-check-circle"></i>
            <div style="font-size:.9rem;"><?= htmlspecialchars(__('Everything is fine. No items need attention right now.')) ?></div>
        </div>
    <?php else: ?>
        <?php foreach ($attentionShow as $it):
            [$cls, $txt] = attDaysLabel((int)$it['days']);
            $ex = $it['extra'] ?? [];
            $itemId = $ex['item_id'] ?? 0;
            $type = $it['type'];
        ?>
        <div class="att-row-wrapper">
            <a class="att-row" href="<?= htmlspecialchars($it['url']) ?>">
                <div class="att-ico" style="background: <?= $it['bg'] ?>; color: <?= $it['color'] ?>;">
                    <i class="bi <?= $it['icon'] ?>"></i>
                </div>
                <div class="att-body">
                    <div class="att-title"><?= htmlspecialchars($it['title']) ?></div>
                    <div class="att-sub"><?= htmlspecialchars($it['sub']) ?></div>
                </div>
                <div class="att-meta">
                    <span class="att-days <?= $cls ?>"><?= $txt ?></span>
                    <div class="att-date"><?= htmlspecialchars($it['date']) ?></div>
                </div>
            </a>
            <div class="att-actions" style="padding-right:22px;">
                <div class="dropdown">
                    <button class="btn btn-ghost btn-sm" type="button" onclick="this.nextElementSibling.classList.toggle('show')" style="padding:4px 8px; border:1px solid var(--color-border-soft); border-radius:6px;"><i class="bi bi-three-dots-vertical"></i></button>
                    <div class="dropdown-menu shadow-sm">
                        <?php if ($type === 'lease'): ?>
                            <a class="dropdown-item" href="#" onclick="openRenewLease(<?= $itemId ?>, '<?= htmlspecialchars($ex['lease_start'] ?? '') ?>', '<?= htmlspecialchars($ex['lease_expiry'] ?? '') ?>'); return false;"><i class="bi bi-arrow-repeat text-primary me-2"></i> Renew Lease</a>
                            <?php if (($ex['status'] ?? '') !== 'retired'): ?>
                                <a class="dropdown-item" href="#" onclick="if(confirm('Return this leased item?')) { document.getElementById('ret_<?= $itemId ?>').submit(); } return false;"><i class="bi bi-truck text-danger me-2"></i> Return to Vendor</a>
                                <form id="ret_<?= $itemId ?>" method="POST" action="actions.php" style="display:none;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_retired">
                                    <input type="hidden" name="asset_id" value="<?= $itemId ?>">
                                    <input type="hidden" name="vendor_return_method" value="Returned at end of lease">
                                </form>
                            <?php endif; ?>
                        <?php elseif ($type === 'warranty'): ?>
                            <a class="dropdown-item" href="#" onclick="openExtendWarranty(<?= $itemId ?>, '<?= htmlspecialchars($ex['end_of_life_date'] ?? '') ?>'); return false;"><i class="bi bi-shield-plus text-success me-2"></i> Extend Warranty</a>
                            <?php if (($ex['status'] ?? '') !== 'retired'): ?>
                                <a class="dropdown-item" href="#" onclick="if(confirm('Retire this asset?')) { document.getElementById('retire_<?= $itemId ?>').submit(); } return false;"><i class="bi bi-archive text-danger me-2"></i> Retire</a>
                                <form id="retire_<?= $itemId ?>" method="POST" action="actions.php" style="display:none;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="set_retired">
                                    <input type="hidden" name="asset_id" value="<?= $itemId ?>">
                                    <input type="hidden" name="vendor_return_method" value="End of life">
                                </form>
                            <?php endif; ?>
                        <?php elseif ($type === 'borrow'): ?>
                            <a class="dropdown-item" href="#" onclick="if(confirm('Return this borrowed item?')) { document.getElementById('return_<?= $itemId ?>').submit(); } return false;"><i class="bi bi-arrow-return-left text-success me-2"></i> Return to Stock</a>
                            <form id="return_<?= $itemId ?>" method="POST" action="actions.php" style="display:none;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="return">
                                <input type="hidden" name="asset_id" value="<?= $itemId ?>">
                                <input type="hidden" name="location_id" value="">
                            </form>
                        <?php elseif ($type === 'repair'): ?>
                            <a class="dropdown-item" href="#" onclick="if(confirm('Mark this item as fixed and return to stock?')) { document.getElementById('fix_<?= $itemId ?>').submit(); } return false;"><i class="bi bi-check-circle text-success me-2"></i> Mark Fixed</a>
                            <form id="fix_<?= $itemId ?>" method="POST" action="actions.php" style="display:none;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_fixed">
                                <input type="hidden" name="asset_id" value="<?= $itemId ?>">
                                <input type="hidden" name="location_id" value="">
                            </form>
                        <?php elseif ($type === 'software'): ?>
                            <a class="dropdown-item" href="software.php"><i class="bi bi-arrow-repeat text-primary me-2"></i> Renew Batch</a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?= htmlspecialchars($it['url']) ?>"><i class="bi bi-info-circle text-muted me-2"></i> View Detail</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($attentionTotal > count($attentionShow)): ?>
        <a class="att-foot text-primary-link" href="audit.php" style="font-weight:600; font-size:.85rem;">
            <?= htmlspecialchars(__('View all')) ?> <?= $attentionTotal ?> <?= htmlspecialchars(__('items')) ?> →
        </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ─── 2-Column: Status + Recent Activity ──────────────────── -->
<div class="dash-grid">

    <!-- Status Distribution -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <h3><i class="bi bi-pie-chart"></i> <?= htmlspecialchars(__('Status & Categories')) ?></h3>
            <span class="text-muted" style="font-size:.78rem;"><?= ($deployed + $inStock + $inRepair) ?> <?= htmlspecialchars(__('active')) ?></span>
        </div>
        <div style="padding:18px 22px;">
            <?php
                $total = max(1, $deployed + $inStock + $inRepair);
                $pDep   = round($deployed / $total * 100);
                $pStock = round($inStock  / $total * 100);
                $pRep   = max(0, 100 - $pDep - $pStock);
            ?>
            <div class="stat-bar-track">
                <div class="stat-bar-seg deployed" style="width: <?= $pDep ?>%;"   title="Deployed <?= $deployed ?>"></div>
                <div class="stat-bar-seg in_stock" style="width: <?= $pStock ?>%;" title="In Stock <?= $inStock ?>"></div>
                <div class="stat-bar-seg repair"   style="width: <?= $pRep ?>%;"   title="Repair <?= $inRepair ?>"></div>
            </div>
            <div class="stat-bar-legend">
                <div class="leg"><span class="dot" style="background:#2563eb;"></span> <?= htmlspecialchars(__('Deployed')) ?> <strong><?= $deployed ?></strong> <span class="text-muted">(<?= $pDep ?>%)</span></div>
                <div class="leg"><span class="dot" style="background:#16a34a;"></span> <?= htmlspecialchars(__('In Stock')) ?> <strong><?= $inStock ?></strong> <span class="text-muted">(<?= $pStock ?>%)</span></div>
                <div class="leg"><span class="dot" style="background:#d97706;"></span> <?= htmlspecialchars(__('In Repair')) ?> <strong><?= $inRepair ?></strong> <span class="text-muted">(<?= $pRep ?>%)</span></div>
            </div>

            <div style="margin-top:22px;">
                <div style="font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--color-text-muted); margin-bottom:10px;">Top categories</div>
                <?php
                    $maxN = 1;
                    foreach ($topCategories as $c) { $maxN = max($maxN, (int)$c['n']); }
                    foreach ($topCategories as $c):
                        $w = round($c['n'] / $maxN * 100);
                ?>
                <div class="cat-row">
                    <div class="name" title="<?= htmlspecialchars($c['category']) ?>"><?= htmlspecialchars($c['category']) ?></div>
                    <div class="track"><div class="fill" style="width: <?= $w ?>%;"></div></div>
                    <div class="num"><?= $c['n'] ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($topCategories)): ?>
                    <div class="text-muted" style="font-size:.82rem;"><?= __('No assets in system') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Activity (7 days) -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <h3><i class="bi bi-clock-history"></i> Recent activity</h3>
            <a href="audit.php" class="text-primary-link" style="font-size:.8rem;">audit log →</a>
        </div>
        <?php if (empty($recentActivity)): ?>
            <div class="att-empty">
                <i class="bi bi-clock" style="color:var(--color-text-muted);"></i>
                <div style="font-size:.85rem;"><?= __('No activity in the last 7 days') ?></div>
            </div>
        <?php else: ?>
            <ul class="act-list">
            <?php foreach ($recentActivity as $a):
                [$icon, $cls, $verb] = actIcon($a['action_type']);
                $name = $a['item_name'] ?: $a['item_sn'] ?: '—';
                $href = $a['asset_id'] ? ('history.php?asset_id=' . (int)$a['asset_id']) : null;
            ?>
                <li>
                    <div class="act-ico <?= $cls ?>"><i class="bi <?= $icon ?>"></i></div>
                    <div class="act-body">
                        <div class="act-title">
                            <?php if ($href): ?>
                                <a href="<?= $href ?>" class="text-primary-link fw-600"><?= htmlspecialchars($name) ?></a>
                            <?php else: ?>
                                <span class="fw-600"><?= htmlspecialchars($name) ?></span>
                            <?php endif; ?>
                            <span class="text-muted"><?= htmlspecialchars($verb) ?></span>
                            <?php if ($a['to_user']): ?>
                                → <strong><?= htmlspecialchars($a['to_user']) ?></strong>
                            <?php elseif ($a['from_user']): ?>
                                <span class="text-muted">from</span> <?= htmlspecialchars($a['from_user']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="act-meta">
                            <?= htmlspecialchars(date('M d · H:i', strtotime($a['created_at']))) ?>
                            <?= $a['item_cat'] ? ' · ' . htmlspecialchars($a['item_cat']) : '' ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Modals for Quick Actions ────────────────────────────── -->
<div id="renewLeaseModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff; width:100%; max-width:400px; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,.15); overflow:hidden;">
        <form method="POST" action="actions.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="renew_lease">
            <input type="hidden" name="asset_id" id="rl_asset_id">
            <div style="padding:16px 24px; border-bottom:1px solid var(--color-border-soft); display:flex; justify-content:space-between; align-items:center;">
                <h5 style="margin:0; font-size:1.1rem;"><i class="bi bi-arrow-repeat text-primary" style="margin-right:6px;"></i> Renew Lease</h5>
                <button type="button" onclick="document.getElementById('renewLeaseModal').style.display='none'" style="background:none; border:none; font-size:18px; color:#9ca3af; cursor:pointer;">&times;</button>
            </div>
            <div style="padding:24px;">
                <div class="form-group mb-3">
                    <label>New Lease Start</label>
                    <input type="date" name="lease_start" id="rl_start" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>New Lease Expiry</label>
                    <input type="date" name="lease_expiry" id="rl_expiry" class="form-control" required>
                </div>
            </div>
            <div style="padding:16px 24px; background:var(--color-bg); text-align:right;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('renewLeaseModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Renew Lease</button>
            </div>
        </form>
    </div>
</div>

<div id="extendWarrantyModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff; width:100%; max-width:400px; border-radius:12px; box-shadow:0 20px 40px rgba(0,0,0,.15); overflow:hidden;">
        <form method="POST" action="actions.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="renew_warranty">
            <input type="hidden" name="asset_id" id="rw_asset_id">
            <div style="padding:16px 24px; border-bottom:1px solid var(--color-border-soft); display:flex; justify-content:space-between; align-items:center;">
                <h5 style="margin:0; font-size:1.1rem;"><i class="bi bi-shield-plus text-success" style="margin-right:6px;"></i> Extend Warranty</h5>
                <button type="button" onclick="document.getElementById('extendWarrantyModal').style.display='none'" style="background:none; border:none; font-size:18px; color:#9ca3af; cursor:pointer;">&times;</button>
            </div>
            <div style="padding:24px;">
                <div class="form-group">
                    <label>New End of Life Date</label>
                    <input type="date" name="end_of_life_date" id="rw_date" class="form-control" required>
                </div>
            </div>
            <div style="padding:16px 24px; background:var(--color-bg); text-align:right;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('extendWarrantyModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Extend Warranty</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRenewLease(id, start, expiry) {
    document.getElementById('rl_asset_id').value = id;
    document.getElementById('rl_start').value = start;
    document.getElementById('rl_expiry').value = expiry;
    document.getElementById('renewLeaseModal').style.display = 'flex';
}
function openExtendWarranty(id, eol) {
    document.getElementById('rw_asset_id').value = id;
    document.getElementById('rw_date').value = eol;
    document.getElementById('extendWarrantyModal').style.display = 'flex';
}

// Close modals on backdrop click or escape key
['renewLeaseModal', 'extendWarrantyModal'].forEach(function(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.addEventListener('click', function(e) {
        if (e.target === m) m.style.display = 'none';
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('renewLeaseModal').style.display = 'none';
        document.getElementById('extendWarrantyModal').style.display = 'none';
    }
});
</script>

<?php require_once 'includes/layout_end.php'; ?>

