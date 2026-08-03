<?php
// consumables.php
require_once 'includes/db_connect.php';

// Current stock balances per item
$balances = $pdo->query("
    SELECT item_name,
           SUM(CASE WHEN action='in'  THEN quantity ELSE 0 END) as total_in,
           SUM(CASE WHEN action='out' THEN quantity ELSE 0 END) as total_out,
           SUM(CASE WHEN action='in'  THEN quantity WHEN action='out' THEN -quantity ELSE 0 END) as balance
    FROM   consumable_logs
    GROUP  BY item_name
    ORDER  BY item_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Distinct item names for the datalist
$itemNames = $pdo->query("SELECT DISTINCT item_name FROM consumable_logs ORDER BY item_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Recent logs
$logs = $pdo->query("
    SELECT cl.*, u.full_name
    FROM   consumable_logs cl
    LEFT JOIN users u ON cl.user_id = u.user_id
    ORDER  BY cl.created_at DESC
    LIMIT  60
")->fetchAll(PDO::FETCH_ASSOC);

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

$pageTitle = 'Consumables';
$activeNav = 'consumables';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Record saved successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start;">

    <!-- ─── Record Form (edit mode only) ──────── -->
    <div class="card edit-only" style="margin-bottom:0;">
        <div class="card-header" style="padding:20px 24px;">
            <h3><i class="bi bi-plus-circle"></i> <?= htmlspecialchars(__('Record In / Out')) ?></h3>
        </div>
        <div style="padding:20px;">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="consumable_log">

                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('Item Name')) ?></label>
                    <input list="consumableItems" name="item_name" class="form-control"
                           placeholder="e.g. HDMI Cable, Toner TN-2480"
                           required autofocus>
                    <datalist id="consumableItems">
                        <?php foreach ($itemNames as $name): ?>
                            <option value="<?= htmlspecialchars($name) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('Action')) ?></label>
                        <select name="log_action" class="form-control form-select" required>
                            <option value="in">📥 Restock (IN)</option>
                            <option value="out">📤 Distribute (OUT)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('Quantity')) ?></label>
                        <input type="number" name="quantity" class="form-control"
                               min="1" placeholder="1" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('Note')) ?> <span style="color:#9ca3af;">(<?= htmlspecialchars(__('optional')) ?>)</span></label>
                    <input type="text" name="note" class="form-control"
                           placeholder="Reason, vendor, or recipient…">
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                    <i class="bi bi-save"></i> <?= htmlspecialchars(__('Save Record')) ?>
                </button>
            </form>
        </div>
    </div>

    <div>
        <!-- ─── Stock Balances ──────────────────── -->
        <div class="card">
            <div class="card-header" style="padding:20px 24px;">
                <h3><i class="bi bi-bar-chart-line"></i> <?= htmlspecialchars(__('Current Stock')) ?></h3>
            </div>
            <div class="card-body tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(__('Item')) ?></th>
                            <th style="text-align:right;"><?= htmlspecialchars(__('Total IN')) ?></th>
                            <th style="text-align:right;"><?= htmlspecialchars(__('Total OUT')) ?></th>
                            <th style="text-align:right;"><?= htmlspecialchars(__('Balance')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($balances)): ?>
                        <tr><td colspan="4">
                            <div class="empty-state">
                                <i class="bi bi-box"></i>
                                <p><?= htmlspecialchars(__('No consumable records yet. Use the form to add your first item.')) ?></p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($balances as $b): ?>
                        <tr>
                            <td class="fw-600"><?= htmlspecialchars($b['item_name']) ?></td>
                            <td style="text-align:right;color:#16a34a;font-weight:600;">+<?= (int)$b['total_in'] ?></td>
                            <td style="text-align:right;color:#d97706;font-weight:600;">−<?= (int)$b['total_out'] ?></td>
                            <td style="text-align:right;">
                                <?php $bal = (int)$b['balance']; ?>
                                <span class="balance-pill <?= $bal <= 0 ? 'zero' : '' ?>">
                                    <?= $bal <= 0 ? htmlspecialchars(__('Out of Stock')) : $bal . ' ' . htmlspecialchars(__('pcs')) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ─── Transaction Log ─────────────────── -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header" style="padding:20px 24px;">
                <h3><i class="bi bi-clock-history"></i> <?= htmlspecialchars(__('Recent Transactions')) ?> (<?= htmlspecialchars(__('Last 60 records')) ?>)</h3>
            </div>
            <div class="card-body tbl-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(__('Date')) ?></th>
                            <th><?= htmlspecialchars(__('Item')) ?></th>
                            <th style="text-align:center;"><?= htmlspecialchars(__('Action')) ?></th>
                            <th style="text-align:right;"><?= htmlspecialchars(__('Qty')) ?></th>
                            <th><?= htmlspecialchars(__('By / To')) ?></th>
                            <th><?= htmlspecialchars(__('Note')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px;"><?= htmlspecialchars(__('No transactions yet.')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-muted text-mono"><?= substr($log['created_at'], 0, 16) ?></td>
                            <td class="fw-600"><?= htmlspecialchars($log['item_name']) ?></td>
                            <td>
                                <?php if ($log['action'] === 'in'): ?>
                                    <span class="badge" style="background:#dcfce7;color:#15803d;">IN</span>
                                <?php elseif ($log['action'] === 'out'): ?>
                                    <span class="badge" style="background:#fef3c7;color:#b45309;">OUT</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f3f4f6;color:#6b7280;">AUDIT</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-weight:600;"><?= (int)$log['quantity'] ?></td>
                            <td class="text-muted"><?= htmlspecialchars($log['note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once 'includes/layout_end.php'; ?>
