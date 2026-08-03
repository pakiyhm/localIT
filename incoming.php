<?php
// incoming.php — Track pending hardware orders before they arrive
require_once 'includes/db_connect.php';

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

// Pending = ordered or partial
$pending = $pdo->query("
    SELECT * FROM incoming_orders
    WHERE  status IN ('ordered','partial')
    ORDER BY eta_date ASC, created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Recently received (last 30 days)
$recent = $pdo->query("
    SELECT * FROM incoming_orders
    WHERE  status='received' AND created_at >= date('now','-30 days')
    ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Cancelled (small history)
$cancelled = $pdo->query("
    SELECT * FROM incoming_orders WHERE status='cancelled'
    ORDER BY created_at DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Incoming Orders';
$activeNav = 'incoming';
require_once 'includes/layout.php';
?>

<style>
.io-status-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 99px;
    font-size: .72rem; font-weight: 700;
}
.io-status-ordered   { background:#fef3c7; color:#92400e; }
.io-status-partial   { background:#fef3c7; color:#b45309; }
.io-status-received  { background:#dcfce7; color:#15803d; }
.io-status-cancelled { background:#f3f4f6; color:#6b7280; }

.io-proceed-row {
    display: grid;
    grid-template-columns: 30px 1fr 1fr;
    gap: 8px; padding: 6px 0;
}
.io-proceed-row .idx { color: var(--color-text-muted); font-size: .82rem; text-align:right; }
.io-proceed-row input { font-size: .82rem; padding: 6px 10px; }
</style>

<?php if ($msg === 'incoming_added'): ?>
<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> เพิ่ม order ใหม่แล้ว</div>
<?php elseif (str_starts_with($msg, 'received_')): ?>
<div class="alert alert-success"><i class="bi bi-box-arrow-in-down"></i> รับเข้า stock <?= (int)substr($msg, 9) ?> รายการแล้ว</div>
<?php elseif ($msg === 'success'): ?>
<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> บันทึกแล้ว</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
    <div>
        <h2 style="font-size:1.1rem; font-weight:600; margin:0;">
            <i class="bi bi-truck" style="color:var(--color-accent);"></i> Incoming Orders
            <span class="text-muted" style="font-size:.82rem; font-weight:400; margin-left:6px;">
                <?= count($pending) ?> รอ + <?= count($recent) ?> เข้าแล้ว 30 วัน
            </span>
        </h2>
    </div>
    <button onclick="document.getElementById('addIncomingModal').style.display='flex'" class="btn btn-primary btn-sm edit-only">
        <i class="bi bi-plus-lg"></i> Add Order
    </button>
</div>

<!-- ─── Pending arrivals ─── -->
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">
        <h3><i class="bi bi-hourglass-split"></i> <?= __('Pending Arrivals') ?></h3>
        <span class="text-muted" style="font-size:.78rem;"><?= count($pending) ?> <?= __('orders') ?></span>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead><tr>
                <th>Order</th>
                <th>Category / Model</th>
                <th>Qty</th>
                <th>Vendor / PO</th>
                <th>Ordered</th>
                <th>ETA</th>
                <th>Status</th>
                <th style="text-align:right; min-width:200px;">Actions</th>
            </tr></thead>
            <tbody>
            <?php if (empty($pending)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-box"></i><p><?= __('No pending orders — add a new order to track incoming shipments.') ?></p></div></td></tr>
            <?php else: foreach ($pending as $o):
                $etaDays = null;
                if ($o['eta_date']) {
                    $etaDays = (int)floor((strtotime($o['eta_date']) - strtotime('today')) / 86400);
                }
                $remaining = (int)$o['qty_expected'] - (int)$o['qty_received'];
            ?>
                <tr>
                    <td class="text-mono text-muted">#<?= $o['order_id'] ?></td>
                    <td>
                        <div class="fw-600"><?= htmlspecialchars($o['category']) ?></div>
                        <?php if ($o['model']): ?><div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($o['model']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <strong><?= $o['qty_received'] ?></strong> / <?= $o['qty_expected'] ?>
                        <?php if ($remaining > 0): ?>
                            <div class="text-muted" style="font-size:.7rem;"><?= __('Remaining') ?> <?= $remaining ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $o['vendor'] ? '<div>'.htmlspecialchars($o['vendor']).'</div>' : '<span class="text-muted">—</span>' ?>
                        <?= $o['purchase_order'] ? '<div class="text-muted" style="font-size:.7rem;">PO: '.htmlspecialchars($o['purchase_order']).'</div>' : '' ?>
                    </td>
                    <td class="text-mono" style="font-size:.78rem;"><?= htmlspecialchars($o['ordered_date'] ?: '—') ?></td>
                    <td class="text-mono" style="font-size:.78rem;">
                        <?php if ($o['eta_date']): ?>
                            <?= htmlspecialchars($o['eta_date']) ?>
                            <?php if ($etaDays !== null): ?>
                                <div style="font-size:.7rem; color:<?= $etaDays < 0 ? '#dc2626' : ($etaDays <= 3 ? '#d97706' : 'var(--color-text-muted)') ?>;">
                                    <?= $etaDays < 0 ? 'เลย ' . abs($etaDays) . ' วัน' : ($etaDays === 0 ? 'วันนี้' : "in $etaDays d") ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="io-status-badge io-status-<?= $o['status'] ?>"><?= ucfirst($o['status']) ?></span></td>
                    <td style="text-align:right;">
                        <button onclick='openProceed(<?= json_encode($o) ?>)' class="btn btn-sm edit-only" style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">
                            <i class="bi bi-box-arrow-in-down"></i> Proceed
                        </button>
                        <button onclick='openEdit(<?= json_encode($o) ?>)' class="btn btn-sm edit-only" style="background:#f8fafc;color:#2563eb;border:1px solid #cbd5e1;">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form action="actions.php" method="POST" class="edit-only" style="display:inline-block;margin:0;"
                              data-confirm="ยกเลิก order #<?= $o['order_id'] ?>?" data-confirm-title="Cancel Order">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="incoming_cancel">
                            <input type="hidden" name="order_id" value="<?= $o['order_id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:#fff1f2;color:#dc2626;border:1px solid #fecdd3;"><i class="bi bi-x-circle"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ─── Recently received ─── -->
<?php if (!empty($recent)): ?>
<div class="card" style="margin-bottom:18px;">
    <div class="card-header">
        <h3><i class="bi bi-check2-square"></i> เพิ่งเข้า stock (30 วัน)</h3>
    </div>
    <div class="card-body tbl-wrap">
        <table>
            <thead><tr><th>Order</th><th>Category / Model</th><th>Qty</th><th>Vendor</th><th>Received</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $o): ?>
                <tr>
                    <td class="text-mono text-muted">#<?= $o['order_id'] ?></td>
                    <td>
                        <div class="fw-600"><?= htmlspecialchars($o['category']) ?></div>
                        <?php if ($o['model']): ?><div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($o['model']) ?></div><?php endif; ?>
                    </td>
                    <td><?= $o['qty_received'] ?> / <?= $o['qty_expected'] ?></td>
                    <td><?= $o['vendor'] ? htmlspecialchars($o['vendor']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-mono" style="font-size:.78rem;"><?= htmlspecialchars(substr($o['created_at'],0,10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ─── ADD MODAL ─── -->
<div id="addIncomingModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:520px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-plus-circle" style="color:#2563eb;"></i> New Incoming Order</h3>
            <button onclick="document.getElementById('addIncomingModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_incoming">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category *</label>
                    <input type="text" name="category" class="form-control" placeholder="Laptop, Monitor, ..." required>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity *</label>
                    <input type="number" name="qty_expected" class="form-control" value="1" min="1" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control" placeholder="ThinkPad T14">
                </div>
                <div class="form-group">
                    <label class="form-label">Ownership</label>
                    <select name="ownership_type" class="form-control form-select">
                        <option value="Buy">Buy</option>
                        <option value="Rent">Rent</option>
                        <option value="Borrow">Borrow</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Vendor</label>
                    <input type="text" name="vendor" class="form-control" placeholder="ชื่อ supplier">
                </div>
                <div class="form-group">
                    <label class="form-label">PO Number</label>
                    <input type="text" name="purchase_order" class="form-control" placeholder="PO-2026-001">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ordered Date</label>
                    <input type="date" name="ordered_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">ETA</label>
                    <input type="date" name="eta_date" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Note</label>
                <textarea name="note" class="form-control" rows="2"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('addIncomingModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> เพิ่ม Order</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── EDIT MODAL ─── -->
<div id="editIncomingModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:520px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-pencil" style="color:#2563eb;"></i> Edit Order</h3>
            <button onclick="document.getElementById('editIncomingModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="edit_incoming">
            <input type="hidden" name="order_id" id="edit_oid" value="">
            <div class="form-row">
                <div class="form-group"><label class="form-label">Category *</label><input type="text" name="category" id="edit_cat" class="form-control" required></div>
                <div class="form-group"><label class="form-label">Quantity *</label><input type="number" name="qty_expected" id="edit_qty" class="form-control" min="1" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Model</label><input type="text" name="model" id="edit_model" class="form-control"></div>
                <div class="form-group">
                    <label class="form-label">Ownership</label>
                    <select name="ownership_type" id="edit_own" class="form-control form-select">
                        <option value="Buy">Buy</option><option value="Rent">Rent</option><option value="Borrow">Borrow</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Vendor</label><input type="text" name="vendor" id="edit_vendor" class="form-control"></div>
                <div class="form-group"><label class="form-label">PO Number</label><input type="text" name="purchase_order" id="edit_po" class="form-control"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Ordered Date</label><input type="date" name="ordered_date" id="edit_od" class="form-control"></div>
                <div class="form-group"><label class="form-label">ETA</label><input type="date" name="eta_date" id="edit_eta" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">Note</label><textarea name="note" id="edit_note" class="form-control" rows="2"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('editIncomingModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── PROCEED MODAL ─── -->
<div id="proceedModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:560px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-box-arrow-in-down" style="color:#15803d;"></i> Receive & Push to Stock</h3>
            <button onclick="document.getElementById('proceedModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="incoming_proceed">
            <input type="hidden" name="order_id" id="proc_oid" value="">
            <div id="procSummary" style="background:var(--color-bg); padding:10px 14px; border-radius:8px; font-size:.85rem; margin-bottom:14px;"></div>
            <div style="font-size:.82rem; color:var(--color-text-muted); margin-bottom:10px;">
                <?= __('Enter the Serial / Hostname of the received items. The system will automatically create assets in stock. Only enter what was actually received (partial receiving is OK).') ?>
            </div>
            <div id="procRows"></div>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                <button type="button" onclick="addProcRow()" class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i> <?= __('Add Row') ?></button>
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="document.getElementById('proceedModal').style.display='none'" class="btn btn-ghost"><?= __('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> <?= __('Receive into Stock') ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(o) {
    document.getElementById('edit_oid').value    = o.order_id;
    document.getElementById('edit_cat').value    = o.category || '';
    document.getElementById('edit_qty').value    = o.qty_expected;
    document.getElementById('edit_model').value  = o.model || '';
    document.getElementById('edit_own').value    = o.ownership_type || 'Buy';
    document.getElementById('edit_vendor').value = o.vendor || '';
    document.getElementById('edit_po').value     = o.purchase_order || '';
    document.getElementById('edit_od').value     = o.ordered_date || '';
    document.getElementById('edit_eta').value    = o.eta_date || '';
    document.getElementById('edit_note').value   = o.note || '';
    document.getElementById('editIncomingModal').style.display = 'flex';
}

let procRowIdx = 0;
function addProcRow() {
    procRowIdx++;
    const wrap = document.createElement('div');
    wrap.className = 'io-proceed-row';
    wrap.innerHTML = `<div class="idx">${procRowIdx}.</div>
        <input type="text" name="serial[]"   class="form-control" placeholder="Serial Number">
        <input type="text" name="hostname[]" class="form-control" placeholder="Hostname (optional)">`;
    document.getElementById('procRows').appendChild(wrap);
}

function openProceed(o) {
    document.getElementById('proc_oid').value = o.order_id;
    document.getElementById('procSummary').innerHTML =
        `<strong>${o.category}</strong>${o.model ? ' · ' + o.model : ''}` +
        ` <span class="text-muted">· รอ ${o.qty_expected - o.qty_received} จาก ${o.qty_expected}` +
        (o.vendor ? ' · vendor ' + o.vendor : '') + '</span>';
    // reset rows
    procRowIdx = 0;
    document.getElementById('procRows').innerHTML = '';
    const remaining = Math.max(1, parseInt(o.qty_expected) - parseInt(o.qty_received));
    for (let i = 0; i < remaining; i++) addProcRow();
    document.getElementById('proceedModal').style.display = 'flex';
}

['addIncomingModal','editIncomingModal','proceedModal'].forEach(id => {
    const m = document.getElementById(id);
    if (m) m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
</script>

<?php require_once 'includes/layout_end.php'; ?>
