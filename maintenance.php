<?php
// maintenance.php — Repair dashboard: current + history + stats
require_once 'includes/db_connect.php';

// ─── Currently in repair (assets + accessories) ────────────
$current = [];

$rows = $pdo->query("
    SELECT a.asset_id AS id, 'asset' AS kind, a.hostname AS name, a.serial_number AS sn,
           a.category, a.repair_shop, a.repair_sent_at, a.repair_eta, a.repair_note
    FROM   assets a
    WHERE  a.status='repair'
    ORDER BY a.repair_eta ASC, a.repair_sent_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) $current[] = $r;

$rows = $pdo->query("
    SELECT ac.accessory_id AS id, 'accessory' AS kind, ac.name AS name, ac.serial_number AS sn,
           ac.category, ac.repair_shop, ac.repair_sent_at, ac.repair_eta, ac.repair_note
    FROM   accessories ac
    WHERE  ac.status='repair'
    ORDER BY ac.repair_eta ASC, ac.repair_sent_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) $current[] = $r;

// Sort current — overdue first, then no-ETA last
usort($current, function ($a, $b) {
    $ea = $a['repair_eta'] ? strtotime($a['repair_eta']) : PHP_INT_MAX;
    $eb = $b['repair_eta'] ? strtotime($b['repair_eta']) : PHP_INT_MAX;
    return $ea <=> $eb;
});

// ─── Repair history (90 days from logs) ────────────────────
$repairLogs = $pdo->query("
    SELECT l.asset_id, l.accessory_id, l.action_type, l.created_at, l.note,
           COALESCE(a.hostname, ac.name)              AS name,
           COALESCE(a.serial_number, ac.serial_number) AS sn,
           COALESCE(a.category, ac.category)          AS category
    FROM   logs l
    LEFT JOIN assets      a  ON l.asset_id     = a.asset_id
    LEFT JOIN accessories ac ON l.accessory_id = ac.accessory_id
    WHERE  l.action_type IN ('repair','update')
      AND  l.created_at >= date('now','-90 days')
      AND  (
            (l.action_type='repair') OR
            (l.action_type='update' AND l.note LIKE 'Repair completed%')
           )
    ORDER BY l.created_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// Build pair: send → fixed cycle per (asset/accessory)
$sentMap = [];   // key => [sent_at, note]
$cycles  = [];   // [name, category, sent_at, fixed_at, days, note_fixed]
foreach (array_reverse($repairLogs) as $r) {
    $key = ($r['asset_id'] ? 'a:'.$r['asset_id'] : 'c:'.$r['accessory_id']);
    if ($r['action_type'] === 'repair') {
        $sentMap[$key] = [
            'sent_at'  => $r['created_at'],
            'name'     => $r['name'],
            'category' => $r['category'],
            'note'     => $r['note'],
        ];
    } elseif ($r['action_type'] === 'update' && isset($sentMap[$key])) {
        $sent = $sentMap[$key];
        $days = round((strtotime($r['created_at']) - strtotime($sent['sent_at'])) / 86400, 1);
        $cycles[] = [
            'name'      => $sent['name'],
            'category'  => $sent['category'],
            'sent_at'   => $sent['sent_at'],
            'fixed_at'  => $r['created_at'],
            'days'      => $days,
            'sent_note' => $sent['note'],
            'fix_note'  => $r['note'],
        ];
        unset($sentMap[$key]);
    }
}
// Most recent first
usort($cycles, fn($a,$b) => strcmp($b['fixed_at'], $a['fixed_at']));
$cyclesShow = array_slice($cycles, 0, 20);

// ─── Stats (90 days) ────────────────────────────────────────
$totalCycles  = count($cycles);
$avgDays      = $totalCycles ? round(array_sum(array_column($cycles, 'days')) / $totalCycles, 1) : 0;

// Top shops + categories from CURRENT + recent cycles
$shopCount = [];
$catCount  = [];
foreach ($current as $r) {
    if (!empty($r['repair_shop'])) $shopCount[$r['repair_shop']] = ($shopCount[$r['repair_shop']] ?? 0) + 1;
    $cat = $r['category'] ?: 'Unknown';
    $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
}
foreach ($cyclesShow as $r) {
    $cat = $r['category'] ?: 'Unknown';
    $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
}
arsort($shopCount); arsort($catCount);
$topShops = array_slice($shopCount, 0, 5, true);
$topCats  = array_slice($catCount,  0, 5, true);

$pageTitle = 'Maintenance';
$activeNav = 'maintenance';
require_once 'includes/layout.php';
?>

<style>

.mt-current-row {
    display: grid;
    grid-template-columns: 1fr 1.2fr 100px 110px 100px;
    gap: 12px; align-items: center;
    padding: 12px 22px;
    border-bottom: 1px solid var(--color-border-soft);
}
.mt-current-row:last-child { border-bottom: none; }
.mt-current-row:hover { background: var(--color-bg); }
</style>

<div class="stat-grid dash-stats" style="margin-bottom:18px;">
    <div class="stat-card warn">
        <div class="stat-icon"><i class="bi bi-wrench"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= count($current) ?></div>
            <div class="stat-label"><?= __('In Repair') ?></div>
        </div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $totalCycles ?></div>
            <div class="stat-label"><?= __('Fixed (90 days)') ?></div>
        </div>
    </div>
    <div class="stat-card blue">
        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $avgDays ?: '—' ?> <span style="font-size:.85rem;font-weight:500;color:var(--color-text-muted);"><?= __('days') ?></span></div>
            <div class="stat-label"><?= __('Avg. per cycle (90 days)') ?></div>
        </div>
    </div>
    <div class="stat-card red">
        <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
        <div class="stat-body">
            <div class="stat-value">
                <?php
                $overdue = 0;
                foreach ($current as $r) {
                    if ($r['repair_eta'] && strtotime($r['repair_eta']) < strtotime('today')) $overdue++;
                }
                echo $overdue;
                ?>
            </div>
            <div class="stat-label"><?= __('Overdue ETA') ?></div>
        </div>
    </div>
</div>

<!-- ─── Currently in repair ─── -->
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">
        <h3><i class="bi bi-wrench"></i> <?= __('Currently in Repair') ?></h3>
        <span class="text-muted" style="font-size:.78rem;"><?= count($current) ?> <?= __('items') ?></span>
    </div>
    <div style="overflow-x:auto;">
        <?php if (empty($current)): ?>
            <div class="empty-state" style="padding:36px;">
                <i class="bi bi-check-circle" style="color:var(--color-success);"></i>
                <p><?= __('No items in repair') ?></p>
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: 1fr 1.2fr 100px 110px 100px; gap:12px; padding:10px 22px; background:var(--color-bg); font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--color-text-muted);">
                <div><?= __('Asset') ?></div>
                <div><?= __('Shop / Note') ?></div>
                <div><?= __('Sent') ?></div>
                <div><?= __('ETA') ?></div>
                <div style="text-align:right;"><?= __('Action') ?></div>
            </div>
            <?php foreach ($current as $r):
                $etaDays = $r['repair_eta'] ? (int)floor((strtotime($r['repair_eta']) - strtotime('today')) / 86400) : null;
                $sentDays = $r['repair_sent_at'] ? (int)floor((strtotime('today') - strtotime($r['repair_sent_at'])) / 86400) : null;
                $etaColor = $etaDays === null ? 'var(--color-text-muted)' :
                            ($etaDays < 0 ? '#dc2626' : ($etaDays <= 3 ? '#d97706' : 'var(--color-text)'));
                $href = $r['kind']==='asset'
                      ? 'history.php?asset_id=' . (int)$r['id']
                      : 'accessories.php?focus=' . (int)$r['id'];
            ?>
            <div class="mt-current-row">
                <div>
                    <a href="<?= $href ?>" class="text-primary-link fw-600"><?= htmlspecialchars($r['name'] ?: $r['sn'] ?: '—') ?></a>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?= htmlspecialchars($r['category']) ?>
                        <?= $r['sn'] ? ' · SN: ' . htmlspecialchars($r['sn']) : '' ?>
                        <?php if ($r['kind']==='accessory'): ?> · <span style="color:#059669;">accessory</span><?php endif; ?>
                    </div>
                </div>
                <div style="font-size:.85rem;">
                    <?php if ($r['repair_shop']): ?>
                        <i class="bi bi-shop text-muted"></i> <?= htmlspecialchars($r['repair_shop']) ?>
                    <?php else: ?>
                        <span class="text-muted"><?= __('No shop specified') ?></span>
                    <?php endif; ?>
                    <?php if ($r['repair_note']): ?>
                        <div class="text-muted" style="font-size:.72rem; margin-top:2px;"><?= htmlspecialchars($r['repair_note']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-mono" style="font-size:.78rem;">
                    <?= htmlspecialchars($r['repair_sent_at'] ?: '—') ?>
                    <?php if ($sentDays !== null && $sentDays > 0): ?>
                        <div class="text-muted" style="font-size:.7rem;"><?= $sentDays ?>d ago</div>
                    <?php endif; ?>
                </div>
                <div class="text-mono" style="font-size:.78rem; color:<?= $etaColor ?>;">
                    <?= $r['repair_eta'] ? htmlspecialchars($r['repair_eta']) : '<span class="text-muted">—</span>' ?>
                    <?php if ($etaDays !== null): ?>
                        <div style="font-size:.7rem;">
                            <?= $etaDays < 0 ? __('Overdue') . ' ' . abs($etaDays) . 'd' : ($etaDays === 0 ? __('Today') : "in {$etaDays}d") ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;">
                    <a href="<?= $href ?>" class="btn btn-sm btn-ghost" title="<?= __('View / Receive back') ?>"><i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Stats sub-row ─── -->
<?php if (!empty($topShops) || !empty($topCats)): ?>
<div style="display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-bottom:18px;">
    <?php if (!empty($topShops)): ?>
    <div class="card" style="margin:0;">
        <div class="card-header"><h3><i class="bi bi-shop"></i> <?= __('Top Shops (Current)') ?></h3></div>
        <div style="padding:14px 22px;">
            <?php $maxN = max($topShops); foreach ($topShops as $shop => $n):
                $w = round($n / $maxN * 100); ?>
            <div style="display:grid; grid-template-columns: 130px 1fr 30px; gap:10px; align-items:center; margin-bottom:8px;">
                <div style="font-size:.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($shop) ?></div>
                <div style="height:8px; background:var(--color-border-soft); border-radius:99px; overflow:hidden;">
                    <div style="height:100%; width:<?= $w ?>%; background:#d97706; border-radius:99px;"></div>
                </div>
                <div style="font-size:.78rem; font-weight:700; text-align:right;"><?= $n ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($topCats)): ?>
    <div class="card" style="margin:0;">
        <div class="card-header"><h3><i class="bi bi-tag"></i> <?= __('Top Categories') ?></h3></div>
        <div style="padding:14px 22px;">
            <?php $maxN = max($topCats); foreach ($topCats as $cat => $n):
                $w = round($n / $maxN * 100); ?>
            <div style="display:grid; grid-template-columns: 130px 1fr 30px; gap:10px; align-items:center; margin-bottom:8px;">
                <div style="font-size:.82rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($cat) ?></div>
                <div style="height:8px; background:var(--color-border-soft); border-radius:99px; overflow:hidden;">
                    <div style="height:100%; width:<?= $w ?>%; background:#2563eb; border-radius:99px;"></div>
                </div>
                <div style="font-size:.78rem; font-weight:700; text-align:right;"><?= $n ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ─── Recent finished cycles ─── -->
<div class="card">
    <div class="card-header">
        <h3><i class="bi bi-check-circle"></i> <?= __('Repair History (90 days)') ?></h3>
        <span class="text-muted" style="font-size:.78rem;"><?= count($cyclesShow) ?> <?= __('latest items') ?> · <?= __('TOTAL') ?> <?= $totalCycles ?></span>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead><tr>
                <th><?= __('Asset') ?></th>
                <th><?= __('Category') ?></th>
                <th><?= __('Sent') ?></th>
                <th><?= __('Fixed') ?></th>
                <th><?= __('Total Time') ?></th>
                <th><?= __('Detail') ?></th>
            </tr></thead>
            <tbody>
            <?php if (empty($cyclesShow)): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-clock-history"></i><p><?= __('No finished repairs in the last 90 days') ?></p></div></td></tr>
            <?php else: foreach ($cyclesShow as $c): ?>
                <tr>
                    <td class="fw-600"><?= htmlspecialchars($c['name'] ?: '—') ?></td>
                    <td class="text-muted"><?= htmlspecialchars($c['category'] ?: '—') ?></td>
                    <td class="text-mono" style="font-size:.8rem;"><?= htmlspecialchars(substr($c['sent_at'], 0, 10)) ?></td>
                    <td class="text-mono" style="font-size:.8rem;"><?= htmlspecialchars(substr($c['fixed_at'], 0, 10)) ?></td>
                    <td><strong><?= $c['days'] ?></strong> <span class="text-muted" style="font-size:.78rem;"><?= __('days') ?></span></td>
                    <td class="text-muted" style="font-size:.82rem;">
                        <?= htmlspecialchars($c['fix_note'] ?: '—') ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/layout_end.php'; ?>
