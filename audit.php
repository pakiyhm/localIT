<?php
// audit.php — Global System Audit Log (with filters)
require_once 'includes/db_connect.php';

// ── Filter inputs ──────────────────────────────────────────────
$fDateFrom = $_GET['date_from'] ?? '';
$fDateTo   = $_GET['date_to']   ?? '';
$fAction   = $_GET['action']    ?? '';
$fUserId   = (int)($_GET['user_id'] ?? 0);
$fItemType = $_GET['item_type'] ?? ''; // asset | accessory | software

// Validate dates (silent fallback)
if ($fDateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateFrom)) $fDateFrom = '';
if ($fDateTo   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateTo))   $fDateTo   = '';

// Build WHERE clauses for outer query
$where  = [];
$params = [];
if ($fDateFrom) { $where[] = "created_at >= :df"; $params['df'] = $fDateFrom . ' 00:00:00'; }
if ($fDateTo)   { $where[] = "created_at <= :dt"; $params['dt'] = $fDateTo   . ' 23:59:59'; }
if ($fAction)   { $where[] = "action_type = :act"; $params['act'] = $fAction; }
if ($fItemType) { $where[] = "item_type = :it";   $params['it']  = $fItemType; }
if ($fUserId)   { $where[] = "(from_user_id = :u OR to_user_id = :u)"; $params['u'] = $fUserId; }
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

$logs = $pdo->prepare("
    SELECT * FROM (
        SELECT l.action_type, l.created_at, l.note,
               COALESCE(a.hostname, ac.name) as item_name,
               COALESCE(a.serial_number, ac.serial_number) as item_serial,
               COALESCE(a.category, ac.category) as item_category,
               CASE WHEN l.accessory_id IS NOT NULL THEN 'accessory' ELSE 'asset' END as item_type,
               u_from.full_name as from_user, u_to.full_name as to_user,
               l.from_user_id, l.to_user_id,
               l.asset_id, l.accessory_id, NULL as license_id
        FROM logs l
        LEFT JOIN assets a ON l.asset_id = a.asset_id
        LEFT JOIN accessories ac ON l.accessory_id = ac.accessory_id
        LEFT JOIN users u_from ON l.from_user_id = u_from.user_id
        LEFT JOIN users u_to ON l.to_user_id = u_to.user_id

        UNION ALL

        SELECT sl.action as action_type, sl.created_at,
               CASE
                   WHEN sl.field_name = 'user_id' THEN 'Reassigned from ' || COALESCE(u_from_sl.full_name, 'Stock') || ' to ' || COALESCE(u_to_sl.full_name, 'Stock')
                   ELSE COALESCE(sl.note, 'Changed ' || sl.field_name || ' from ' || sl.old_value || ' to ' || sl.new_value)
               END as note,
               sw.name as item_name,
               s.license_key as item_serial,
               sw.category as item_category,
               'software' as item_type,
               u_from_sl.full_name as from_user,
               u_to_sl.full_name as to_user,
               CASE WHEN sl.field_name='user_id' THEN CAST(sl.old_value AS INTEGER) ELSE NULL END as from_user_id,
               CASE WHEN sl.field_name='user_id' THEN CAST(sl.new_value AS INTEGER) ELSE NULL END as to_user_id,
               NULL as asset_id, NULL as accessory_id, sl.license_id
        FROM software_license_logs sl
        JOIN software_licenses s ON sl.license_id = s.license_id
        JOIN software_batches sb ON s.batch_id = sb.batch_id
        JOIN software sw ON sb.software_id = sw.software_id
        LEFT JOIN users u_from_sl ON sl.field_name = 'user_id' AND sl.old_value = CAST(u_from_sl.user_id AS TEXT)
        LEFT JOIN users u_to_sl ON sl.field_name = 'user_id' AND sl.new_value = CAST(u_to_sl.user_id AS TEXT)

        UNION ALL

        SELECT cl.action as action_type, cl.created_at,
               COALESCE(cl.note, 'Qty: ' || cl.quantity) as note,
               cl.item_name as item_name,
               NULL as item_serial,
               'Consumables' as item_category,
               'consumable' as item_type,
               NULL as from_user,
               u_cl.full_name as to_user,
               NULL as from_user_id,
               cl.user_id as to_user_id,
               NULL as asset_id, NULL as accessory_id, NULL as license_id
        FROM consumable_logs cl
        LEFT JOIN users u_cl ON cl.user_id = u_cl.user_id

        UNION ALL

        SELECT 'ordered' as action_type, io.created_at,
               'PO: ' || COALESCE(io.purchase_order, 'N/A') || ' (Expected: ' || io.qty_expected || ')' as note,
               io.category as item_name,
               NULL as item_serial,
               'Incoming Order' as item_category,
               'order' as item_type,
               NULL as from_user,
               NULL as to_user,
               NULL as from_user_id,
               NULL as to_user_id,
               NULL as asset_id, NULL as accessory_id, NULL as license_id
        FROM incoming_orders io
    ) combined_logs
    $whereSql
    ORDER BY created_at DESC
    LIMIT 500
");
$logs->execute($params);
$logs = $logs->fetchAll(PDO::FETCH_ASSOC);

// User dropdown
$allUsers = $pdo->query("SELECT user_id, full_name FROM users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

function formatActionIcon($action) {
    $map = [
        'assign'   => ['bi-person-check-fill', 'assign'],
        'unassign' => ['bi-arrow-return-left', 'return'],
        'return'   => ['bi-arrow-return-left', 'return'],
        'repair'   => ['bi-tools', 'repair'],
        'retire'   => ['bi-archive-fill', 'retire'],
        'revoke'   => ['bi-slash-circle', 'retire'],
        'update'   => ['bi-pencil-square', 'update'],
        'edit'     => ['bi-pencil-square', 'update'],
        'import'   => ['bi-cloud-arrow-up-fill', 'import'],
        'create'   => ['bi-plus-circle', 'import'],
        'in'       => ['bi-box-arrow-in-right', 'import'],
        'out'      => ['bi-box-arrow-right', 'assign'],
        'audit'    => ['bi-clipboard-check', 'update'],
        'ordered'  => ['bi-cart-plus', 'repair'],
    ];
    [$icon, $cls] = $map[$action] ?? ['bi-record-circle', 'default'];
    return "<div class='tl-icon {$cls}'><i class='bi {$icon}'></i></div>";
}

$hasFilter = ($fDateFrom || $fDateTo || $fAction || $fUserId || $fItemType);

$pageTitle = 'Audit Log';
$activeNav = 'audit';
require_once 'includes/layout.php';
?>

<div class="card" style="margin-bottom:18px;">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3><i class="bi bi-funnel"></i> Filter</h3>
        <?php if ($hasFilter): ?>
            <a href="audit.php" class="btn btn-ghost btn-sm"><i class="bi bi-x-circle"></i> ล้าง filter</a>
        <?php endif; ?>
    </div>
    <div style="padding:16px 22px;">
        <form method="GET" action="audit.php" data-no-block="1">
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto; gap:12px; align-items:end;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($fDateFrom) ?>" class="form-control">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($fDateTo) ?>" class="form-control">
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Action</label>
                    <select name="action" class="form-control form-select">
                        <option value="">— All —</option>
                        <?php foreach (['assign','return','unassign','repair','retire','revoke','update','edit','create','import'] as $a): ?>
                            <option value="<?= $a ?>" <?= $fAction === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">Type</label>
                    <select name="item_type" class="form-control form-select">
                        <option value="">— All —</option>
                        <option value="asset"     <?= $fItemType==='asset'     ? 'selected':'' ?>>Asset</option>
                        <option value="accessory" <?= $fItemType==='accessory' ? 'selected':'' ?>>Accessory</option>
                        <option value="software"  <?= $fItemType==='software'  ? 'selected':'' ?>>Software</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label">User</label>
                    <select name="user_id" class="form-control form-select">
                        <option value="0">— Anyone —</option>
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $fUserId === (int)$u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> ค้นหา</button>
            </div>
        </form>
    </div>
</div>

<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 24px; background:var(--color-surface);">
        <div>
            <h3 style="font-size:1.1rem; margin:0;"><i class="bi bi-clock-history" style="color:var(--color-accent); margin-right:8px;"></i> Global Audit Log</h3>
            <span class="text-muted" style="font-size:.8rem; margin-top:4px; display:block;">
                <?= count($logs) ?> event<?= count($logs)!==1?'s':'' ?>
                <?= $hasFilter ? '(filtered)' : '(latest 500)' ?>
            </span>
        </div>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">Action</th>
                    <th>Timestamp</th>
                    <th>Asset Details</th>
                    <th>Event Details</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="5">
                    <div class="empty-state">
                        <i class="bi bi-journal-x"></i>
                        <p><?= $hasFilter ? 'ไม่พบ event ที่ตรงกับเงื่อนไข — ลองล้าง filter' : 'No audit logs found in the system yet.' ?></p>
                    </div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="text-align:center;">
                        <?= formatActionIcon($log['action_type']) ?>
                    </td>
                    <td class="text-muted text-mono" style="font-size:.8rem;">
                        <?= htmlspecialchars(date('M d, Y H:i', strtotime($log['created_at'] . ' UTC'))) ?>
                    </td>
                    <td>
                        <?php if ($log['item_type'] === 'accessory'): ?>
                            <span class="fw-600" style="color:#059669;">
                                <i class="bi bi-plug" style="font-size:11px;"></i>
                                <?= htmlspecialchars($log['item_name'] ?? $log['item_serial'] ?? 'Accessory #'.$log['accessory_id']) ?>
                            </span>
                        <?php elseif ($log['item_type'] === 'software'): ?>
                            <span class="fw-600" style="color:#c026d3;">
                                <i class="bi bi-disc" style="font-size:11px;"></i>
                                <?= htmlspecialchars($log['item_name'] ?? 'Software #'.$log['license_id']) ?>
                            </span>
                        <?php else: ?>
                            <a href="history.php?asset_id=<?= $log['asset_id'] ?>" class="text-primary-link fw-600">
                                <?= htmlspecialchars($log['item_name'] ?? $log['item_serial'] ?? 'Asset #'.$log['asset_id']) ?>
                            </a>
                        <?php endif; ?>
                        <div style="font-size:.75rem; color:var(--color-text-muted); margin-top:2px;">
                            <?= htmlspecialchars($log['item_category'] ?? '') ?>
                            <?php if ($log['item_type'] === 'accessory'): ?>
                                <span class="badge" style="background:#f0fdf4;color:#059669;font-size:.6rem;padding:1px 5px;margin-left:4px;">Accessory</span>
                            <?php elseif ($log['item_type'] === 'software'): ?>
                                <span class="badge" style="background:#fdf4ff;color:#c026d3;border:1px solid #fbcfe8;font-size:.6rem;padding:1px 5px;margin-left:4px;">Software</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="font-size:.85rem;">
                        <?php if ($log['action_type'] === 'assign'): ?>
                            Assigned to <span class="fw-600" style="color:var(--color-text);"><?= htmlspecialchars($log['to_user'] ?? 'Unknown User') ?></span>
                        <?php elseif ($log['action_type'] === 'return' || $log['action_type'] === 'unassign'): ?>
                            Returned to stock
                            <?php if ($log['from_user']): ?>
                                from <span class="text-muted"><?= htmlspecialchars($log['from_user']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($log['action_type'] === 'edit'): ?>
                            <span class="fw-600">Record Updated</span>
                        <?php elseif ($log['action_type'] === 'create'): ?>
                            <span class="fw-600">Added to System</span>
                        <?php elseif ($log['action_type'] === 'revoke'): ?>
                            <span class="fw-600">License Revoked</span>
                        <?php else: ?>
                            Status changed to <span class="fw-600"><?= htmlspecialchars(ucfirst($log['action_type'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:.85rem;">
                        <?= htmlspecialchars($log['note'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
