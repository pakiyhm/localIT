<?php
// software.php — Software License Tracking
require_once 'includes/db_connect.php';

// Ensure tables exist (Self-Healing from db_connect should handle this, but checking to be safe)
try {
    $pdo->query("SELECT 1 FROM software LIMIT 1");
} catch (Exception $e) {
    die("<div style='padding:20px'>Software tables not found. Please reload the page to trigger auto-migration, or check db_connect.php.</div>");
}

$pageTitle = 'Software Licenses';
$activeNav = 'software';

// ─── Fetch Stats ─────────────────────────────────────────────
$stats = [
    'total_software' => 0,
    'total_licenses' => 0,
    'assigned'       => 0,
    'expiring_30'    => 0,
    'expiring_60'    => 0,
    'expiring_90'    => 0
];

$stats['total_software'] = (int)$pdo->query("SELECT COUNT(*) FROM software")->fetchColumn();
$stats['total_licenses'] = (int)$pdo->query("SELECT COUNT(*) FROM software_licenses WHERE status != 'revoked'")->fetchColumn();
$stats['assigned']       = (int)$pdo->query("SELECT COUNT(*) FROM software_licenses WHERE status = 'assigned'")->fetchColumn();

$stats['expiring_30'] = (int)$pdo->query("SELECT COUNT(*) FROM software_licenses WHERE status != 'revoked' AND expiry_date IS NOT NULL AND expiry_date <= date('now','+30 days') AND expiry_date >= date('now')")->fetchColumn();
$stats['expiring_60'] = (int)$pdo->query("SELECT COUNT(*) FROM software_licenses WHERE status != 'revoked' AND expiry_date IS NOT NULL AND expiry_date <= date('now','+60 days') AND expiry_date > date('now','+30 days')")->fetchColumn();
$stats['expiring_90'] = (int)$pdo->query("SELECT COUNT(*) FROM software_licenses WHERE status != 'revoked' AND expiry_date IS NOT NULL AND expiry_date <= date('now','+90 days') AND expiry_date > date('now','+60 days')")->fetchColumn();

// ─── Fetch All Software ──────────────────────────────────────
$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT s.*, 
           (SELECT COUNT(*) FROM software_batches b WHERE b.software_id = s.software_id) as batch_count,
           (SELECT COUNT(*) FROM software_licenses l JOIN software_batches b ON l.batch_id = b.batch_id WHERE b.software_id = s.software_id AND l.status != 'revoked') as total_lic,
           (SELECT COUNT(*) FROM software_licenses l JOIN software_batches b ON l.batch_id = b.batch_id WHERE b.software_id = s.software_id AND l.status = 'assigned') as assigned_lic
    FROM software s
";
if ($q) {
    $sql .= " WHERE s.name LIKE :q OR s.vendor LIKE :q OR s.category LIKE :q ";
}
$sql .= " ORDER BY s.name ASC";

$stmt = $pdo->prepare($sql);
if ($q) {
    $stmt->execute(['q' => "%$q%"]);
} else {
    $stmt->execute();
}
$softwareList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to fetch batches and licenses for a software
function getBatches($pdo, $software_id) {
    $stmt = $pdo->prepare("SELECT * FROM software_batches WHERE software_id = :s ORDER BY purchase_date DESC, created_at DESC");
    $stmt->execute(['s' => $software_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($batches as &$b) {
        $lStmt = $pdo->prepare("
            SELECT l.*, u.full_name as user_name 
            FROM software_licenses l 
            LEFT JOIN users u ON l.user_id = u.user_id 
            WHERE l.batch_id = :b 
            ORDER BY l.status, l.license_key
        ");
        $lStmt->execute(['b' => $b['batch_id']]);
        $b['licenses'] = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $batches;
}

// Fetch all users for dropdowns
$users = $pdo->query("SELECT user_id, full_name, department FROM users WHERE resigned_at IS NULL ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper for status badges
function licBadge($status, $expiry = null) {
    if ($expiry && $expiry < date('Y-m-d') && $status != 'revoked') {
        return "<span class='badge badge-lost'>Expired</span>";
    }
    $map = [
        'active'   => 'badge-in_stock',
        'assigned' => 'badge-deployed',
        'revoked'  => 'badge-retired',
        'expired'  => 'badge-lost'
    ];
    $cls = $map[$status] ?? 'badge-in_stock';
    return "<span class='badge {$cls}'>" . ucfirst($status) . "</span>";
}

// Check for messages
$msg = $_GET['msg'] ?? '';

require_once 'includes/layout.php';
?>

<style>
.sw-card { margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; overflow: hidden; }
.sw-header { padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
.sw-header:hover { background: #f1f5f9; }
.sw-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin-bottom: 4px; }
.sw-meta { font-size: 0.85rem; color: #64748b; }
.sw-stats { display: flex; gap: 15px; }
.sw-stat-pill { background: #e2e8f0; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; color: #334155; }
.sw-stat-pill.active { background: #dcfce7; color: #166534; }
.sw-body { padding: 0; display: none; }
.sw-body.open { display: block; }
.batch-section { padding: 20px 24px; border-bottom: 1px solid #f1f5f9; }
.batch-section:last-child { border-bottom: none; }
.batch-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.batch-title { font-weight: 600; color: #334155; font-size: 0.95rem; }
.lic-table th, .lic-table td { padding: 8px 12px; font-size: 0.85rem; }
.lic-row-expiring { background-color: #fffbeb !important; }
.lic-row-expired { background-color: #fef2f2 !important; }
.key-mask { font-family: monospace; letter-spacing: 1px; color: #475569; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; }

/* ─── Modal CSS ─── */
.modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 999; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
.modal.show { display: flex; }
.modal-content { background: #fff; border-radius: 8px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1); }
.modal-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; position: sticky; top: 0; z-index: 10; border-radius: 8px 8px 0 0; }
.modal-header h3 { margin: 0; font-size: 1.1rem; color: #0f172a; }
.modal-close { cursor: pointer; color: #94a3b8; font-size: 1.2rem; transition: color 0.2s; }
.modal-close:hover { color: #dc2626; }
.modal-body { padding: 20px; }
</style>

<?php if ($msg === 'success'): ?>
    <div class="alert alert-success">Operation completed successfully.</div>
<?php endif; ?>

<!-- ─── Stat Cards ─────────────────────────────────────────── -->
<div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card blue">
        <div class="stat-icon"><i class="bi bi-box"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $stats['total_software'] ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Software Products')) ?></div>
        </div>
    </div>
    <div class="stat-card blue" style="--color-accent-lite:#ede9fe;--color-accent:#7c3aed;">
        <div class="stat-icon"><i class="bi bi-key"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $stats['total_licenses'] ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Total Licenses')) ?></div>
        </div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon"><i class="bi bi-person-check"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $stats['assigned'] ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Assigned Licenses')) ?></div>
        </div>
    </div>
    <div class="stat-card warn" style="--color-accent-lite:#fef3c7;--color-accent:#d97706;">
        <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $stats['total_licenses'] - $stats['assigned'] ?></div>
            <div class="stat-label"><?= htmlspecialchars(__('Available Licenses')) ?></div>
        </div>
    </div>
</div>

<!-- ─── Expiry Alerts ─────────────────────────────────────── -->
<?php if ($stats['expiring_30'] + $stats['expiring_60'] + $stats['expiring_90'] > 0): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h3><i class="bi bi-calendar-event"></i> <?= htmlspecialchars(__('License Expiry Alerts')) ?></h3>
    </div>
    <div class="lease-bands">
        <div class="lease-band">
            <div class="lb-dot" style="background:#dc2626;"></div>
            <div>
                <div class="lb-count"><?= $stats['expiring_30'] ?></div>
                <div class="lb-label"><?= htmlspecialchars(__('Expiring in ≤ 30 days')) ?></div>
            </div>
        </div>
        <div class="lease-band">
            <div class="lb-dot" style="background:#d97706;"></div>
            <div>
                <div class="lb-count"><?= $stats['expiring_60'] ?></div>
                <div class="lb-label"><?= htmlspecialchars(__('Expiring in 31–60 days')) ?></div>
            </div>
        </div>
        <div class="lease-band">
            <div class="lb-dot" style="background:#0891b2;"></div>
            <div>
                <div class="lb-count"><?= $stats['expiring_90'] ?></div>
                <div class="lb-label"><?= htmlspecialchars(__('Expiring in 61–90 days')) ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ─── Main Content ──────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; padding:20px 24px;">
        <h3><i class="bi bi-disc"></i> <?= htmlspecialchars(__('Software Directory')) ?> <?= $q ? ' ('.htmlspecialchars(__('Search')).': "'.htmlspecialchars($q).'")' : '' ?></h3>
        <div>
            <?php if ($q): ?>
                <a href="software.php" class="btn btn-ghost btn-sm"><i class="bi bi-x"></i> <?= htmlspecialchars(__('Clear')) ?></a>
            <?php endif; ?>
            <button class="btn btn-primary btn-sm edit-only" onclick="openModal('addSoftwareModal')"><i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Add Software')) ?></button>
        </div>
    </div>
    
    <div class="card-body" style="padding: 20px; background: #f8fafc;">
        <?php if (empty($softwareList)): ?>
            <div class="empty-state">
                <i class="bi bi-box-seam"></i>
                <p><?= htmlspecialchars(__('No software found.')) ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($softwareList as $sw): ?>
                <?php $batches = getBatches($pdo, $sw['software_id']); ?>
                <div class="sw-card">
                    <div class="sw-header" onclick="toggleSw(this)">
                        <div>
                            <div class="sw-title"><?= htmlspecialchars($sw['name']) ?></div>
                            <div class="sw-meta">
                                <?= $sw['vendor'] ? htmlspecialchars($sw['vendor']) . ' &bull; ' : '' ?>
                                <?= htmlspecialchars($sw['category'] ?: __('Uncategorized')) ?> &bull; 
                                <?= $sw['batch_count'] ?> <?= htmlspecialchars(__('Batches')) ?>
                            </div>
                        </div>
                        <div class="sw-stats">
                            <div class="sw-stat-pill active" title="Assigned / Total">
                                <i class="bi bi-person-fill"></i> <?= $sw['assigned_lic'] ?> / <?= $sw['total_lic'] ?>
                            </div>
                            <i class="bi bi-chevron-down" style="color:#94a3b8; transition: transform 0.2s;"></i>
                        </div>
                    </div>
                    <div class="sw-body">
                        <?php if (empty($batches)): ?>
                            <div style="padding: 20px; text-align: center; color: #64748b;"><?= htmlspecialchars(__('No batches found.')) ?></div>
                        <?php else: ?>
                            <?php foreach ($batches as $batch):
                                $licList = $batch['licenses'] ?? [];
                                $bTotal    = 0;
                                $bAssigned = 0;
                                foreach ($licList as $_l) {
                                    if (($_l['status'] ?? '') === 'revoked') continue;
                                    $bTotal++;
                                    if (($_l['status'] ?? '') === 'assigned') $bAssigned++;
                                }
                                $bPct = $bTotal > 0 ? round($bAssigned / $bTotal * 100) : 0;
                                $bClass = $bPct >= 100 ? 'full' : ($bPct >= 80 ? 'high' : ($bPct >= 40 ? 'medium' : 'low'));
                            ?>
                                <div class="batch-section">
                                    <div class="batch-header">
                                        <div class="batch-title">
                                            <i class="bi bi-collection"></i> <?= htmlspecialchars($batch['batch_name']) ?>
                                            <span style="font-weight:normal; font-size:0.85rem; color:#64748b; margin-left:10px;">
                                                <?= htmlspecialchars($batch['license_type']) ?>
                                                <?= $batch['purchase_date'] ? '&bull; '.htmlspecialchars(__('Purchased')).': '.$batch['purchase_date'] : '' ?>
                                            </span>
                                        </div>
                                        <div style="display:flex; gap:14px; align-items:center;">
                                            <div class="cap-bar" title="ใช้แล้ว <?= $bAssigned ?> จาก <?= $bTotal ?> license">
                                                <div class="cap-bar-row">
                                                    <span><strong><?= $bAssigned ?></strong> / <?= $bTotal ?> <?= htmlspecialchars(__('Assigned')) ?></span>
                                                    <span class="text-muted"><?= $bPct ?>%</span>
                                                </div>
                                                <div class="cap-bar-track">
                                                    <div class="cap-bar-fill <?= $bClass ?>" style="width:<?= $bPct ?>%;"></div>
                                                </div>
                                            </div>
                                            <button class="btn btn-ghost btn-sm edit-only" onclick='openEditBatchModal(<?= json_encode($batch) ?>)' title="<?= htmlspecialchars(__('Edit Batch')) ?>"><i class="bi bi-pencil"></i></button>
                                            <button class="btn btn-ghost btn-sm edit-only" onclick="openAddLicensesModal(<?= $batch['batch_id'] ?>, '<?= htmlspecialchars(addslashes($batch['batch_name'])) ?>')"><i class="bi bi-plus-circle"></i> <?= htmlspecialchars(__('Add Licenses')) ?></button>
                                            <button class="btn btn-ghost btn-sm edit-only" onclick="openRenewBatchModal(<?= $batch['batch_id'] ?>, '<?= htmlspecialchars(addslashes($batch['batch_name'])) ?>')"><i class="bi bi-arrow-repeat"></i> <?= htmlspecialchars(__('Renew All')) ?></button>
                                        </div>
                                    </div>
                                    
                                    <?php if (empty($batch['licenses'])): ?>
                                        <div style="font-size:0.85rem; color:#94a3b8; margin-bottom:10px;"><?= htmlspecialchars(__('No licenses in this batch.')) ?></div>
                                    <?php else: ?>
                                        <div class="table-wrap" style="margin-top: 10px;">
                                            <table class="lic-table">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 50px;">ID</th>
                                                        <th><?= htmlspecialchars(__('License Key')) ?></th>
                                                        <th><?= htmlspecialchars(__('Assigned To')) ?></th>
                                                        <th><?= htmlspecialchars(__('Start Date')) ?></th>
                                                        <th><?= htmlspecialchars(__('Expiry Date')) ?></th>
                                                        <th><?= htmlspecialchars(__('Status')) ?></th>
                                                        <th style="text-align:right;"><?= htmlspecialchars(__('Actions')) ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($batch['licenses'] as $lic): 
                                                        $isExpired = ($lic['expiry_date'] && $lic['expiry_date'] < date('Y-m-d') && $lic['status'] != 'revoked');
                                                        $isExpiring = (!$isExpired && $lic['expiry_date'] && $lic['expiry_date'] <= date('Y-m-d', strtotime('+30 days')) && $lic['status'] != 'revoked');
                                                        $rowClass = $isExpired ? 'lic-row-expired' : ($isExpiring ? 'lic-row-expiring' : '');
                                                    ?>
                                                    <tr class="<?= $rowClass ?>">
                                                        <td class="text-muted">#<?= $lic['license_id'] ?></td>
                                                        <td>
                                                            <?php if ($lic['license_key']): ?>
                                                                <span class="key-mask" title="<?= htmlspecialchars($lic['license_key']) ?>">
                                                                    <?= strlen($lic['license_key']) > 15 ? htmlspecialchars(substr($lic['license_key'], 0, 10)).'...' : htmlspecialchars($lic['license_key']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="text-muted" style="font-size:0.8rem;"><i><?= htmlspecialchars(__('No Key')) ?></i></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($lic['user_id']): ?>
                                                                <a href="users.php#user-<?= $lic['user_id'] ?>" class="text-primary-link" style="font-size:0.85rem;">
                                                                    <i class="bi bi-person"></i> <?= htmlspecialchars($lic['user_name']) ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted" style="font-size:0.8rem;">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-mono"><?= $lic['start_date'] ?: '—' ?></td>
                                                        <td class="text-mono <?= $isExpired ? 'text-danger fw-600' : ($isExpiring ? 'text-warning fw-600' : '') ?>">
                                                            <?= $lic['expiry_date'] ?: '—' ?>
                                                        </td>
                                                        <td><?= licBadge($lic['status'], $lic['expiry_date']) ?></td>
                                                        <td style="text-align:right;">
                                                            <button class="btn btn-ghost btn-sm edit-only" onclick='openEditLicenseModal(<?= json_encode($lic) ?>)' title="<?= htmlspecialchars(__('Edit License')) ?>"><i class="bi bi-pencil"></i></button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <div style="padding: 10px 20px 15px; background: #f8fafc; border-top: 1px solid #e5e7eb; display:flex; justify-content:space-between;">
                            <div>
                                <button class="btn btn-ghost btn-sm text-danger edit-only" onclick="deleteSoftware(<?= $sw['software_id'] ?>)"><i class="bi bi-trash"></i> <?= htmlspecialchars(__('Delete Software')) ?></button>
                                <button class="btn btn-ghost btn-sm edit-only" onclick='openEditSoftwareModal(<?= json_encode($sw) ?>)'><i class="bi bi-pencil"></i> <?= htmlspecialchars(__('Edit Software Info')) ?></button>
                            </div>
                            <button class="btn btn-outline btn-sm edit-only" onclick="openAddBatchModal(<?= $sw['software_id'] ?>, '<?= htmlspecialchars(addslashes($sw['name'])) ?>')"><i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('Add Batch')) ?></button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ─── MODALS ────────────────────────────────────────────── -->

<!-- Add Software Modal -->
<div id="addSoftwareModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Add New Software</h3>
            <span class="modal-close" onclick="closeModal('addSoftwareModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_software">
                
                <h4 style="margin-top:0; font-size:1rem; color:#334155; border-bottom:1px solid #e2e8f0; padding-bottom:5px; margin-bottom:15px;">Software Details</h4>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>Software Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Microsoft 365">
                    </div>
                    <div>
                        <label>Vendor</label>
                        <input type="text" name="vendor" class="form-control" placeholder="e.g. Microsoft">
                    </div>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" placeholder="e.g. Productivity, Security, Design">
                </div>
                
                <h4 style="margin-top:20px; font-size:1rem; color:#334155; border-bottom:1px solid #e2e8f0; padding-bottom:5px; margin-bottom:15px;">Initial Batch & Licenses</h4>
                <div class="form-group" style="display:grid; grid-template-columns: 2fr 1fr; gap:15px;">
                    <div>
                        <label>Batch Name <span class="text-danger">*</span></label>
                        <input type="text" name="batch_name" class="form-control" required placeholder="e.g. Q1 2024 Purchase">
                    </div>
                    <div>
                        <label>License Type</label>
                        <select name="license_type" class="form-control">
                            <option value="Per User">Per User</option>
                            <option value="Per Device">Per Device</option>
                            <option value="Site">Site</option>
                            <option value="Volume">Volume</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px;">
                    <div>
                        <label>Quantity to Create</label>
                        <input type="number" name="qty" class="form-control" value="1" min="1" required>
                    </div>
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>
                    <div>
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>License Keys (Optional, one per line)</label>
                    <textarea name="license_keys" class="form-control" rows="3" placeholder="XXXX-XXXX-XXXX-XXXX&#10;YYYY-YYYY-YYYY-YYYY"></textarea>
                    <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">If you provide fewer keys than Quantity, the rest will be created blank.</div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addSoftwareModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Software</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Batch Modal -->
<div id="addBatchModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Add Batch to <span id="addBatchSwName" class="text-primary"></span></h3>
            <span class="modal-close" onclick="closeModal('addBatchModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_batch">
                <input type="hidden" name="software_id" id="addBatchSwId" value="">
                
                <div class="form-group">
                    <label>Batch Name <span class="text-danger">*</span></label>
                    <input type="text" name="batch_name" class="form-control" required placeholder="e.g. Additional 20 Seats">
                </div>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>License Type</label>
                        <select name="license_type" class="form-control">
                            <option value="Per User">Per User</option>
                            <option value="Per Device">Per Device</option>
                            <option value="Site">Site</option>
                            <option value="Volume">Volume</option>
                        </select>
                    </div>
                    <div>
                        <label>Quantity</label>
                        <input type="number" name="qty" class="form-control" value="1" min="1" required>
                    </div>
                </div>
                
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>
                    <div>
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>License Keys (Optional, one per line)</label>
                    <textarea name="license_keys" class="form-control" rows="3"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addBatchModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Licenses Modal -->
<div id="addLicensesModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Add Licenses to <span id="addLicBatchName" class="text-primary"></span></h3>
            <span class="modal-close" onclick="closeModal('addLicensesModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_licenses">
                <input type="hidden" name="batch_id" id="addLicBatchId" value="">
                
                <div class="form-group">
                    <label>Quantity to Add <span class="text-danger">*</span></label>
                    <input type="number" name="qty" class="form-control" value="1" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>License Keys (Optional, one per line)</label>
                    <textarea name="license_keys" class="form-control" rows="3"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addLicensesModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Licenses</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Renew Batch Modal -->
<div id="renewBatchModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Renew Batch <span id="renewBatchName" class="text-primary"></span></h3>
            <span class="modal-close" onclick="closeModal('renewBatchModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <p style="font-size:0.9rem; color:#64748b; margin-bottom:15px;">
                This will update the expiry date for ALL licenses in this batch.
            </p>
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="renew_batch">
                <input type="hidden" name="batch_id" id="renewBatchId" value="">
                
                <div class="form-group">
                    <label>New Expiry Date <span class="text-danger">*</span></label>
                    <input type="date" name="new_expiry_date" class="form-control" required>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('renewBatchModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Renewal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit License Modal -->
<div id="editLicenseModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Edit License #<span id="editLicIdSpan"></span></h3>
            <span class="modal-close" onclick="closeModal('editLicenseModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <div style="background:#f1f5f9; padding:10px; border-radius:6px; margin-bottom:15px; font-size:0.85rem; color:#64748b;">
                <i class="bi bi-info-circle"></i> Any changes saved here will be recorded in the audit log.
            </div>
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_license">
                <input type="hidden" name="license_id" id="editLicId" value="">
                
                <div class="form-group">
                    <label>License Key</label>
                    <input type="text" name="license_key" id="editLicKey" class="form-control text-mono">
                </div>
                
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 2fr; gap:15px;">
                    <div>
                        <label>Status</label>
                        <select name="status" id="editLicStatus" class="form-control">
                            <option value="active">Active (Available)</option>
                            <option value="assigned">Assigned</option>
                            <option value="expired">Expired</option>
                            <option value="revoked">Revoked / Disabled</option>
                        </select>
                    </div>
                    <div>
                        <label>Assign to User</label>
                        <select name="user_id" id="editLicUser" class="form-control">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?> <?= $u['department'] ? '('.htmlspecialchars($u['department']).')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>Start Date</label>
                        <input type="date" name="start_date" id="editLicStart" class="form-control">
                    </div>
                    <div>
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="editLicExpiry" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="note" id="editLicNote" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editLicenseModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Software Modal -->
<div id="editSoftwareModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3>Edit Software Info</h3>
            <span class="modal-close" onclick="closeModal('editSoftwareModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_software">
                <input type="hidden" name="software_id" id="editSwId" value="">
                
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>Software Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editSwName" class="form-control" required>
                    </div>
                    <div>
                        <label>Vendor</label>
                        <input type="text" name="vendor" id="editSwVendor" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" id="editSwCat" class="form-control">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="note" id="editSwNote" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editSoftwareModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Batch Modal -->
<div id="editBatchModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3>Edit Batch</h3>
            <span class="modal-close" onclick="closeModal('editBatchModal')"><i class="bi bi-x-lg"></i></span>
        </div>
        <div class="modal-body">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit_batch">
                <input type="hidden" name="batch_id" id="editBatchId" value="">
                
                <div class="form-group">
                    <label>Batch Name <span class="text-danger">*</span></label>
                    <input type="text" name="batch_name" id="editBatchName" class="form-control" required>
                </div>
                <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div>
                        <label>License Type</label>
                        <select name="license_type" id="editBatchType" class="form-control">
                            <option value="Per User">Per User</option>
                            <option value="Per Device">Per Device</option>
                            <option value="Site">Site</option>
                            <option value="Volume">Volume</option>
                        </select>
                    </div>
                    <div>
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" id="editBatchDate" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Purchase Order #</label>
                    <input type="text" name="purchase_order" id="editBatchPO" class="form-control">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="note" id="editBatchNote" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editBatchModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteSoftwareForm" action="actions.php" method="POST" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_software">
    <input type="hidden" name="software_id" id="deleteSwId" value="">
</form>

<script>
function toggleSw(headerEl) {
    const body = headerEl.nextElementSibling;
    const icon = headerEl.querySelector('.bi-chevron-down');
    body.classList.toggle('open');
    if (body.classList.contains('open')) {
        icon.style.transform = 'rotate(180deg)';
    } else {
        icon.style.transform = 'rotate(0deg)';
    }
}

function openModal(id) {
    document.getElementById(id).classList.add('show');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}

function openAddBatchModal(swId, swName) {
    document.getElementById('addBatchSwId').value = swId;
    document.getElementById('addBatchSwName').textContent = swName;
    openModal('addBatchModal');
}

function openAddLicensesModal(batchId, batchName) {
    document.getElementById('addLicBatchId').value = batchId;
    document.getElementById('addLicBatchName').textContent = batchName;
    openModal('addLicensesModal');
}

function openRenewBatchModal(batchId, batchName) {
    document.getElementById('renewBatchId').value = batchId;
    document.getElementById('renewBatchName').textContent = batchName;
    openModal('renewBatchModal');
}

function openEditSoftwareModal(swData) {
    document.getElementById('editSwId').value = swData.software_id;
    document.getElementById('editSwName').value = swData.name || '';
    document.getElementById('editSwVendor').value = swData.vendor || '';
    document.getElementById('editSwCat').value = swData.category || '';
    document.getElementById('editSwNote').value = swData.note || '';
    openModal('editSoftwareModal');
}

function openEditBatchModal(batchData) {
    document.getElementById('editBatchId').value = batchData.batch_id;
    document.getElementById('editBatchName').value = batchData.batch_name || '';
    document.getElementById('editBatchType').value = batchData.license_type || 'Per User';
    document.getElementById('editBatchDate').value = batchData.purchase_date || '';
    document.getElementById('editBatchPO').value = batchData.purchase_order || '';
    document.getElementById('editBatchNote').value = batchData.note || '';
    openModal('editBatchModal');
}

function openEditLicenseModal(licData) {
    document.getElementById('editLicId').value = licData.license_id;
    document.getElementById('editLicIdSpan').textContent = licData.license_id;
    document.getElementById('editLicKey').value = licData.license_key || '';
    document.getElementById('editLicStatus').value = licData.status || 'active';
    document.getElementById('editLicUser').value = licData.user_id || '';
    document.getElementById('editLicStart').value = licData.start_date || '';
    document.getElementById('editLicExpiry').value = licData.expiry_date || '';
    document.getElementById('editLicNote').value = licData.note || '';
    
    // Auto-update status when user is selected
    const userSelect = document.getElementById('editLicUser');
    const statusSelect = document.getElementById('editLicStatus');
    userSelect.onchange = function() {
        if (this.value && statusSelect.value === 'active') {
            statusSelect.value = 'assigned';
        } else if (!this.value && statusSelect.value === 'assigned') {
            statusSelect.value = 'active';
        }
    };
    
    openModal('editLicenseModal');
}

function deleteSoftware(id) {
    askConfirm({
        title: 'ลบ Software',
        message: 'ลบ software นี้? ALL batch + license + log จะหายถาวร ย้อนกลับไม่ได้',
        confirmText: 'ลบทั้งหมด'
    }).then(ok => {
        if (!ok) return;
        document.getElementById('deleteSwId').value = id;
        const f = document.getElementById('deleteSoftwareForm');
        f.dataset.confirmed = '1'; f.submit();
    });
}

// Open the first software card automatically if there is only one
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.sw-card');
    if (cards.length === 1) {
        toggleSw(cards[0].querySelector('.sw-header'));
    }
});
</script>

<?php require_once 'includes/layout_end.php'; ?>
