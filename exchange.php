<?php
// exchange.php — Dedicated Checkout / Checkin Portal
require_once 'includes/db_connect.php';

// Fetch all users for assignment
$users = $pdo->query("SELECT user_id, emp_id, full_name, department FROM users WHERE resigned_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all locations
$locations = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch in-stock assets for checkout
$inStock = $pdo->query("SELECT asset_id, category, hostname, serial_number FROM assets WHERE status = 'in_stock' ORDER BY category, serial_number ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch deployed assets for checkin
$deployed = $pdo->query("
    SELECT a.asset_id, a.category, a.hostname, a.serial_number, u.full_name as assigned_to
    FROM assets a
    LEFT JOIN users u ON a.user_id = u.user_id
    WHERE a.status IN ('deployed') AND a.user_id IS NOT NULL
    ORDER BY a.category, a.serial_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch in-stock accessories for checkout
$inStockAcc = $pdo->query("SELECT accessory_id, category, name, serial_number FROM accessories WHERE status = 'in_stock' ORDER BY category, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch deployed accessories for checkin
$deployedAcc = $pdo->query("
    SELECT ac.accessory_id, ac.category, ac.name, ac.serial_number, u.full_name as assigned_to
    FROM accessories ac
    LEFT JOIN users u ON ac.user_id = u.user_id
    WHERE ac.status = 'deployed' AND ac.user_id IS NOT NULL
    ORDER BY ac.category, ac.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Recent exchange activity (assets + accessories)
$recentLogs = $pdo->query("
    SELECT l.action_type, l.created_at,
           COALESCE(a.hostname, ac.name) as item_name,
           COALESCE(a.serial_number, ac.serial_number) as serial_number,
           COALESCE(a.category, ac.category) as category,
           CASE WHEN l.accessory_id IS NOT NULL THEN 'accessory' ELSE 'asset' END as item_type,
           u_to.full_name as to_user, u_from.full_name as from_user
    FROM logs l
    LEFT JOIN assets a ON l.asset_id = a.asset_id
    LEFT JOIN accessories ac ON l.accessory_id = ac.accessory_id
    LEFT JOIN users u_to ON l.to_user_id = u_to.user_id
    LEFT JOIN users u_from ON l.from_user_id = u_from.user_id
    WHERE l.action_type IN ('assign', 'return')
    ORDER BY l.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Counts for summary
$deployedAssetCount = count($deployed);
$inStockAssetCount  = count($inStock);
$deployedAccCount   = count($deployedAcc);
$inStockAccCount    = count($inStockAcc);

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

$pageTitle = 'Exchange Portal';
$activeNav = 'exchange';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'assign_success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Item checked out successfully.</div>
<?php elseif ($msg === 'return_success'): ?>
    <div class="alert alert-info"><i class="bi bi-arrow-return-left"></i> Item returned to stock.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ── Quick Stats Row ── -->
<div class="stat-grid" style="grid-template-columns:repeat(4, 1fr);">
    <div class="stat-card blue">
        <div class="stat-icon"><i class="bi bi-box-arrow-right"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $inStockAssetCount ?></div>
            <div class="stat-label">Devices Available</div>
        </div>
    </div>
    <div class="stat-card warn" style="--color-accent-lite:#fef3c7;--color-accent:#d97706;">
        <div class="stat-icon"><i class="bi bi-person-check"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $deployedAssetCount ?></div>
            <div class="stat-label">Devices Deployed</div>
        </div>
    </div>
    <div class="stat-card green" style="--color-accent-lite:#f0fdf4;--color-accent:#059669;">
        <div class="stat-icon"><i class="bi bi-plug"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $inStockAccCount ?></div>
            <div class="stat-label">Accessories Available</div>
        </div>
    </div>
    <div class="stat-card purple" style="--color-accent-lite:#ede9fe;--color-accent:#7c3aed;">
        <div class="stat-icon"><i class="bi bi-plug-fill"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $deployedAccCount ?></div>
            <div class="stat-label">Accessories Deployed</div>
        </div>
    </div>
</div>

<!-- ── Main Exchange Layout ── -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">

    <!-- ══ DEVICE ASSETS COLUMN ══ -->
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); margin:0; overflow:visible;">
        <div class="card-header" style="padding:18px 24px; background:var(--color-surface); border-bottom:1px solid var(--color-border-soft);">
            <h3 style="font-size:1.05rem; margin:0; color:var(--color-text);"><i class="bi bi-laptop" style="color:var(--color-accent); margin-right:8px;"></i> Device Assets</h3>
        </div>
        <div class="card-body" style="padding:0;">

            <!-- Device Check-Out -->
            <form action="actions.php" method="POST" style="padding:20px 24px; border-bottom:1px solid var(--color-border-soft);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                    <i class="bi bi-box-arrow-right" style="color:var(--color-accent);font-size:16px;"></i>
                    <span style="font-weight:600;font-size:.88rem;color:var(--color-text);">Check-Out (Assign)</span>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Employee</label>
                    <select name="user_id" class="form-control form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?> <?= $u['emp_id'] ? "({$u['emp_id']})" : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Asset (In Stock)</label>
                    <input list="inStockAssets" name="asset_identifier" class="form-control" placeholder="Search by SN or Hostname..." required autocomplete="off">
                    <datalist id="inStockAssets">
                        <?php foreach ($inStock as $a): ?>
                            <option value="<?= htmlspecialchars($a['serial_number'] ?? $a['hostname']) ?>">
                                [<?= htmlspecialchars($a['category']) ?>] <?= htmlspecialchars($a['hostname'] ?? 'No Hostname') ?>
                            </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Destination Location (Optional)</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— Use Employee's Location —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px;">
                    <i class="bi bi-box-arrow-right"></i> Check Out Device
                </button>
            </form>

            <!-- Device Check-In -->
            <form action="actions.php" method="POST" style="padding:20px 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                    <i class="bi bi-box-arrow-in-left" style="color:var(--color-warning);font-size:16px;"></i>
                    <span style="font-weight:600;font-size:.88rem;color:var(--color-text);">Check-In (Return)</span>
                    <?php if ($deployedAssetCount > 0): ?>
                        <span class="badge" style="background:#fef3c7;color:#b45309;font-size:.65rem;"><?= $deployedAssetCount ?> out</span>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Deployed Asset</label>
                    <select name="asset_id" class="form-control form-select" required>
                        <option value="">— Select asset to return —</option>
                        <?php foreach ($deployed as $a): ?>
                            <option value="<?= $a['asset_id'] ?>">
                                <?= htmlspecialchars($a['serial_number'] ?? $a['hostname']) ?>
                                (<?= htmlspecialchars($a['category']) ?>)
                                — <?= htmlspecialchars($a['assigned_to']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Return to Location (Optional)</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— Unassigned Location —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn" style="width:100%;justify-content:center;padding:10px;background:var(--color-warning);color:#fff;border:none;border-radius:8px;font-weight:500;font-size:.88rem;">
                    <i class="bi bi-box-arrow-in-left"></i> Return Device
                </button>
            </form>
        </div>
    </div>

    <!-- ══ ACCESSORIES COLUMN ══ -->
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); margin:0; overflow:visible;">
        <div class="card-header" style="padding:18px 24px; background:var(--color-surface); border-bottom:1px solid var(--color-border-soft);">
            <h3 style="font-size:1.05rem; margin:0; color:var(--color-text);"><i class="bi bi-plug" style="color:#059669; margin-right:8px;"></i> Accessories</h3>
        </div>
        <div class="card-body" style="padding:0;">

            <!-- Accessory Check-Out -->
            <form action="actions.php" method="POST" style="padding:20px 24px; border-bottom:1px solid var(--color-border-soft);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_accessory">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                    <i class="bi bi-box-arrow-right" style="color:#059669;font-size:16px;"></i>
                    <span style="font-weight:600;font-size:.88rem;color:var(--color-text);">Check-Out (Assign)</span>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Employee</label>
                    <select name="user_id_direct" class="form-control form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?> <?= $u['emp_id'] ? "({$u['emp_id']})" : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Accessory (In Stock)</label>
                    <select name="accessory_id" class="form-control form-select" required>
                        <option value="">— Select —</option>
                        <?php foreach ($inStockAcc as $ac): ?>
                            <option value="<?= $ac['accessory_id'] ?>">
                                [<?= htmlspecialchars($ac['category']) ?>] <?= htmlspecialchars($ac['name']) ?>
                                <?= $ac['serial_number'] ? '('.$ac['serial_number'].')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Destination Location (Optional)</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— Use Employee's Location —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn" style="width:100%;justify-content:center;padding:10px;background:#059669;color:#fff;border:none;border-radius:8px;font-weight:500;font-size:.88rem;">
                    <i class="bi bi-box-arrow-right"></i> Check Out Accessory
                </button>
            </form>

            <!-- Accessory Check-In -->
            <form action="actions.php" method="POST" style="padding:20px 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="return_accessory">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                    <i class="bi bi-box-arrow-in-left" style="color:#059669;font-size:16px;"></i>
                    <span style="font-weight:600;font-size:.88rem;color:var(--color-text);">Check-In (Return)</span>
                    <?php if ($deployedAccCount > 0): ?>
                        <span class="badge" style="background:#f0fdf4;color:#059669;font-size:.65rem;"><?= $deployedAccCount ?> out</span>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Deployed Accessory</label>
                    <select name="accessory_id" class="form-control form-select" required>
                        <option value="">— Select accessory to return —</option>
                        <?php foreach ($deployedAcc as $ac): ?>
                            <option value="<?= $ac['accessory_id'] ?>">
                                <?= htmlspecialchars($ac['name']) ?>
                                <?= $ac['serial_number'] ? '('.$ac['serial_number'].')' : '' ?>
                                [<?= htmlspecialchars($ac['category']) ?>]
                                — <?= htmlspecialchars($ac['assigned_to']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Return to Location (Optional)</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— Unassigned Location —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn" style="width:100%;justify-content:center;padding:10px;background:#059669;color:#fff;border:none;border-radius:8px;font-weight:500;font-size:.88rem;">
                    <i class="bi bi-box-arrow-in-left"></i> Return Accessory
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ── Recent Activity Feed ── -->
<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="padding:16px 24px; background:var(--color-surface); border-bottom:1px solid var(--color-border-soft);">
        <h3 style="font-size:1.05rem; margin:0;"><i class="bi bi-activity" style="color:var(--color-accent); margin-right:8px;"></i> Recent Exchange Activity</h3>
        <span class="text-muted" style="font-size:.78rem;"><?= count($recentLogs) ?> events</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($recentLogs)): ?>
            <div style="padding:40px; text-align:center; color:var(--color-text-muted); font-size:.9rem;">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
                No recent checkouts or returns.
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0;">
                <?php foreach ($recentLogs as $i => $log): ?>
                    <div style="padding:14px 20px; border-bottom:1px solid var(--color-border-soft); <?= $i % 2 === 0 ? 'border-right:1px solid var(--color-border-soft);' : '' ?> display:flex; gap:12px; align-items:flex-start;">
                        <div style="margin-top:2px;">
                            <?php if ($log['action_type'] === 'assign'): ?>
                                <div style="width:28px;height:28px;border-radius:6px;background:<?= ($log['item_type'] ?? '') === 'accessory' ? '#f0fdf4' : '#dbeafe' ?>;color:<?= ($log['item_type'] ?? '') === 'accessory' ? '#059669' : '#2563eb' ?>;display:flex;align-items:center;justify-content:center;font-size:13px;">
                                    <i class="bi bi-arrow-up-right"></i>
                                </div>
                            <?php else: ?>
                                <div style="width:28px;height:28px;border-radius:6px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:13px;">
                                    <i class="bi bi-arrow-down-left"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-weight:600; font-size:.82rem; color:var(--color-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars($log['serial_number'] ?? $log['item_name'] ?? 'Item') ?>
                                <?php if (($log['item_type'] ?? '') === 'accessory'): ?>
                                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#059669;margin-left:4px;vertical-align:middle;" title="Accessory"></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:.78rem; color:var(--color-text-muted); margin-top:2px;">
                                <?php if ($log['action_type'] === 'assign'): ?>
                                    → <?= htmlspecialchars($log['to_user'] ?? '') ?>
                                <?php else: ?>
                                    ← <?= htmlspecialchars($log['from_user'] ?? 'User') ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:.7rem; color:var(--color-text-muted); margin-top:2px; opacity:.7;">
                                <?= date('M d, H:i', strtotime($log['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
