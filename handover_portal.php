<?php
// handover_portal.php — Handover & Resignation Clearance Forms Tracking & Batch Printing Portal
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

// Handle Action Submissions
$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';
$filterStatus = $_GET['status'] ?? 'all';

// Fetch all users for dropdown
$users = $pdo->query("SELECT user_id, full_name, emp_id, department FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Auto-Sync: Ensure every active employee holding assets/accessories/software is registered in Pending Signature tracker
try {
    $usersWithAssets = $pdo->query("
        SELECT DISTINCT u.user_id 
        FROM users u 
        WHERE u.resigned_at IS NULL AND (
            EXISTS (SELECT 1 FROM assets a WHERE a.user_id = u.user_id) OR
            EXISTS (SELECT 1 FROM accessories ac WHERE ac.user_id = u.user_id) OR
            EXISTS (SELECT 1 FROM software_licenses sl WHERE sl.user_id = u.user_id)
        )
    ")->fetchAll(PDO::FETCH_COLUMN);

    $insStmt = $pdo->prepare("INSERT OR IGNORE INTO handover_slips (doc_no, user_id, form_type, status) VALUES (:d, :u, 'handover', 'pending_signature')");
    foreach ($usersWithAssets as $uid) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM handover_slips WHERE user_id = :u");
        $check->execute(['u' => $uid]);
        if ((int)$check->fetchColumn() === 0) {
            $docNo = "IT-ACT-" . date('Ymd') . "-" . str_pad($uid, 4, '0', STR_PAD_LEFT);
            $insStmt->execute(['d' => $docNo, 'u' => $uid]);
        }
    }
} catch (Exception $e) {}

// Query Slips
$whereClause = [];
$params = [];
if ($filterStatus !== 'all') {
    $whereClause[] = "hs.status = :st";
    $params['st']  = $filterStatus;
}

$whereSql = $whereClause ? "WHERE " . implode(" AND ", $whereClause) : "";

$slips = $pdo->prepare("
    SELECT hs.*, u.full_name, u.emp_id, u.department, att.filename as signed_filename, att.attachment_id as signed_att_id
    FROM handover_slips hs
    LEFT JOIN users u ON hs.user_id = u.user_id
    LEFT JOIN asset_attachments att ON hs.signed_file_id = att.attachment_id
    $whereSql
    ORDER BY hs.created_at DESC
");
$slips->execute($params);
$slips = $slips->fetchAll(PDO::FETCH_ASSOC);

// Counts
$totalCount   = (int)$pdo->query("SELECT COUNT(*) FROM handover_slips")->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM handover_slips WHERE status = 'pending_signature'")->fetchColumn();
$signedCount  = (int)$pdo->query("SELECT COUNT(*) FROM handover_slips WHERE status = 'signed'")->fetchColumn();

$pageTitle = 'Handover & Clearance Portal';
$activeNav = 'handover_portal';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'created'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> New Handover Slip created and registered in Pending Signature tracker.</div>
<?php endif; ?>
<?php if ($msg === 'signed'): ?>
    <div class="alert alert-success"><i class="bi bi-patch-check-fill"></i> Handover Slip marked as SIGNED and archived successfully.</div>
<?php endif; ?>
<?php if ($msg === 'deleted'): ?>
    <div class="alert alert-warning"><i class="bi bi-trash-fill"></i> Handover Slip record deleted.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h2 style="margin:0; font-size:1.4rem;"><i class="bi bi-file-earmark-check-fill" style="color:var(--color-accent); margin-right:8px;"></i> Handover & Clearance Portal</h2>
        <p style="margin:4px 0 0; color:var(--color-text-muted); font-size:.9rem;">Issue, track, manage, and batch-print signed IT Handover and Resignation Clearance Certificates.</p>
    </div>
    <div class="edit-only" style="display:flex; gap:8px;">
        <a href="settings.php?tab=branding" class="btn btn-ghost" style="border:1px solid var(--color-border); font-weight:600;">
            <i class="bi bi-image" style="color:var(--color-accent);"></i> Upload Logo & Branding
        </a>
        <button onclick="document.getElementById('batchPrintModal').style.display='flex'" class="btn btn-ghost" style="border:1px solid var(--color-border); font-weight:600; color:#2563eb;">
            <i class="bi bi-printer"></i> Batch Reprint All Users
        </button>
        <button onclick="document.getElementById('issueSlipModal').style.display='flex'" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Issue New Form
        </button>
    </div>
</div>

<!-- KPI Cards -->
<div class="stat-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:24px;">
    <a href="handover_portal.php?status=all" class="stat-card blue" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-file-earmark-text"></i></div>
        <div class="stat-body">
            <div class="stat-value"><?= $totalCount ?></div>
            <div class="stat-label">Total Slips Issued</div>
        </div>
    </a>
    <a href="handover_portal.php?status=pending_signature" class="stat-card warning" style="text-decoration:none; color:inherit; <?= $pendingCount > 0 ? 'border:2px solid #f59e0b;' : '' ?>">
        <div class="stat-icon"><i class="bi bi-hourglass-split" style="color:#d97706;"></i></div>
        <div class="stat-body">
            <div class="stat-value" style="color:#d97706;"><?= $pendingCount ?></div>
            <div class="stat-label">Pending Signature (ค้างเซ็นชื่อ)</div>
        </div>
    </a>
    <a href="handover_portal.php?status=signed" class="stat-card green" style="text-decoration:none; color:inherit;">
        <div class="stat-icon"><i class="bi bi-patch-check"></i></div>
        <div class="stat-body">
            <div class="stat-value" style="color:#16a34a;"><?= $signedCount ?></div>
            <div class="stat-label">Signed & Complete (เซ็นเรียบร้อย)</div>
        </div>
    </a>
</div>

<!-- Sticky Floating Batch Action Bar -->
<div id="batchActionBar" style="display:none; position:sticky; top:12px; z-index:100; background:#0f172a; color:#fff; padding:12px 20px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.3); margin-bottom:16px; align-items:center; justify-content:space-between;">
    <div style="font-size:.88rem; font-weight:600;">
        <span id="selectedCount" style="color:#38bdf8; font-weight:800;">0</span> slips selected for Batch Actions
    </div>
    <div style="display:flex; gap:10px;">
        <button type="button" onclick="submitBatchPrint()" class="btn" style="background:#2563eb; color:#fff; font-size:12.5px; padding:6px 14px;">
            <i class="bi bi-printer"></i> Batch Print Selected Slips
        </button>
        <button type="button" onclick="unselectAll()" class="btn btn-ghost" style="font-size:12.5px; padding:6px 14px;">
            Deselect All
        </button>
    </div>
</div>

<!-- Forms Table -->
<form id="batchForm" action="print_batch_handover.php" method="POST" target="_blank">
<div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
    <div class="card-header" style="justify-content:space-between; align-items:center; padding:16px 24px; background:var(--color-surface);">
        <div style="display:flex; align-items:center; gap:12px;">
            <h3 style="font-size:1.05rem; margin:0;"><i class="bi bi-list-task"></i> Handover Slips Registry</h3>
            <span class="text-muted" style="font-size:.8rem;"><?= count($slips) ?> records</span>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="handover_portal.php?status=all" class="btn btn-sm <?= $filterStatus === 'all' ? 'btn-primary' : 'btn-ghost' ?>">All</a>
            <a href="handover_portal.php?status=pending_signature" class="btn btn-sm <?= $filterStatus === 'pending_signature' ? 'btn-primary' : 'btn-ghost' ?>" style="color:<?= $filterStatus !== 'pending_signature' ? '#b45309' : '#fff' ?>;">
                <i class="bi bi-clock-history"></i> Pending Signature (<?= $pendingCount ?>)
            </a>
            <a href="handover_portal.php?status=signed" class="btn btn-sm <?= $filterStatus === 'signed' ? 'btn-primary' : 'btn-ghost' ?>" style="color:<?= $filterStatus !== 'signed' ? '#15803d' : '#fff' ?>;">
                <i class="bi bi-check2-circle"></i> Signed (<?= $signedCount ?>)
            </a>
        </div>
    </div>

    <div class="card-body tbl-wrap" style="padding:0;">
        <table>
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;">
                        <input type="checkbox" id="selectAllSlips" onclick="toggleSelectAll(this)" title="Select All / Deselect All">
                    </th>
                    <th>Doc Reference</th>
                    <th>Date Issued</th>
                    <th>Employee Name</th>
                    <th>Department</th>
                    <th>Form Type</th>
                    <th>Signature Status</th>
                    <th style="text-align:center;">Signed File</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($slips)): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="bi bi-file-earmark-x"></i><p>No handover slips found in this view.</p></div></td></tr>
                <?php else: foreach ($slips as $s): 
                    $isPending = ($s['status'] === 'pending_signature');
                    $printUrl  = "print_handover.php?user_id=" . (int)$s['user_id'] . "&type=" . ($s['form_type'] === 'handover' ? 'assign' : 'return') . ($s['form_type'] === 'resignation' ? '&reason=resignation' : '');
                ?>
                <tr>
                    <td style="text-align:center;">
                        <input type="checkbox" name="user_ids[]" value="<?= (int)$s['user_id'] ?>" class="slip-chk" onclick="updateBatchBar()">
                    </td>
                    <td style="font-family:monospace; font-weight:700; color:var(--color-text);"><?= htmlspecialchars($s['doc_no']) ?></td>
                    <td class="text-mono"><?= htmlspecialchars(substr($s['created_at'], 0, 10)) ?></td>
                    <td><strong><?= htmlspecialchars($s['full_name'] ?? '—') ?></strong></td>
                    <td><?= htmlspecialchars($s['department'] ?? '—') ?></td>
                    <td>
                        <?php if ($s['form_type'] === 'handover'): ?>
                            <span class="badge" style="background:#dbeafe; color:#1d4ed8;">Handover (รับมอบ)</span>
                        <?php elseif ($s['form_type'] === 'resignation'): ?>
                            <span class="badge" style="background:#ffe4e6; color:#be123c;">Resignation Clearance</span>
                        <?php else: ?>
                            <span class="badge" style="background:#fef3c7; color:#b45309;">Return (ส่งคืน)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isPending): ?>
                            <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a;">
                                ⏳ Pending Signature
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;">
                                ✅ Signed & Complete
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($s['signed_filename']): ?>
                            <a href="attachments.php?action=download&id=<?= $s['signed_att_id'] ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:2px 8px; font-size:.78rem; border:1px solid #bbf7d0; color:#15803d;" title="View Signed File">
                                <i class="bi bi-file-earmark-pdf-fill"></i> <?= htmlspecialchars($s['signed_filename']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.78rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:inline-flex; gap:6px;">
                            <a href="<?= $printUrl ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:4px 8px;" title="Print / View Certificate">
                                <i class="bi bi-printer"></i> Print
                            </a>
                            <?php if ($isPending): ?>
                            <button type="button" onclick="openMarkSignedModal(<?= $s['slip_id'] ?>, '<?= htmlspecialchars(addslashes($s['doc_no'])) ?>', <?= (int)$s['user_id'] ?>)" class="btn btn-sm edit-only" style="background:#16a34a; color:#fff; padding:4px 8px;" title="Mark as Signed">
                                <i class="bi bi-patch-check"></i> Attach Signed Slip
                            </button>
                            <?php endif; ?>
                            <form action="actions.php" method="POST" class="edit-only" style="display:inline; margin:0;" data-confirm="Delete slip record <?= htmlspecialchars($s['doc_no']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_handover_slip">
                                <input type="hidden" name="slip_id" value="<?= $s['slip_id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:#dc2626; padding:4px 6px;" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</form>

<!-- Modal: Batch Reprint All Users Picker -->
<div id="batchPrintModal" class="modal-backdrop" style="display:none;">
    <div class="modal-dialog" style="max-width:600px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="bi bi-printer-fill"></i> Batch Reprint Handover Certificates</h3>
            <button onclick="document.getElementById('batchPrintModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <form action="print_batch_handover.php" method="POST" target="_blank">
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem; margin-bottom:12px;">
                    Select employees to generate a continuous multi-page printable document for annual audit or batch signing.
                </p>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; background:#f8fafc; padding:8px 12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <label style="font-weight:700; font-size:.85rem; cursor:pointer;">
                        <input type="checkbox" id="modalMasterChk" onclick="toggleModalAllUsers(this)"> Select All / Deselect All Employees (<?= count($users) ?>)
                    </label>
                    <span style="font-size:.75rem; color:#64748b;">(Checked: <strong id="modalCheckCount">0</strong>)</span>
                </div>
                <div style="max-height:280px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; padding:10px; background:#fff;">
                    <?php foreach ($users as $u): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:6px 4px; border-bottom:1px solid #f1f5f9; font-size:.85rem; cursor:pointer;">
                        <input type="checkbox" name="user_ids[]" value="<?= $u['user_id'] ?>" class="modal-user-chk" onclick="updateModalCount()">
                        <span><strong><?= htmlspecialchars($u['full_name']) ?></strong> <?= $u['emp_id'] ? '<small style="color:#64748b;">('.$u['emp_id'].')</small>' : '' ?> &bull; <small style="color:#64748b;"><?= htmlspecialchars($u['department'] ?: 'No Dept') ?></small></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('batchPrintModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" onclick="document.getElementById('batchPrintModal').style.display='none'" class="btn btn-primary"><i class="bi bi-printer"></i> Generate & Print Batch (ต่อเนื่อง)</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Issue New Form -->
<div id="issueSlipModal" class="modal-backdrop" style="display:none;">
    <div class="modal-dialog" style="max-width:500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="bi bi-file-earmark-plus"></i> Issue Handover / Clearance Form</h3>
            <button onclick="document.getElementById('issueSlipModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <form action="actions.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_handover_slip">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Employee</label>
                    <select name="user_id" class="form-control form-select" required>
                        <option value="">Select Employee...</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?><?= $u['emp_id'] ? ' ('.$u['emp_id'].')' : '' ?><?= $u['department'] ? ' — '.$u['department'] : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Form Type</label>
                    <select name="form_type" class="form-control form-select" required>
                        <option value="handover">IT Asset Handover Certificate (ใบรับมอบอุปกรณ์)</option>
                        <option value="return">IT Asset Return Certificate (ใบส่งคืนอุปกรณ์)</option>
                        <option value="resignation">IT Resignation Clearance Certificate (ใบคืนพนักงานลาออก)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Ticket / Ref No. (Optional)</label>
                    <input type="text" name="ticket_no" class="form-control" placeholder="e.g. TK-20260803">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('issueSlipModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Register Form</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Mark Signed & Upload File -->
<div id="markSignedModal" class="modal-backdrop" style="display:none;">
    <div class="modal-dialog" style="max-width:500px;">
        <div class="modal-header">
            <h3 class="modal-title"><i class="bi bi-patch-check"></i> Attach Signed Slip & Complete</h3>
            <button onclick="document.getElementById('markSignedModal').style.display='none'" class="modal-close">&times;</button>
        </div>
        <form action="actions.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_slip_signed">
            <input type="hidden" id="signedSlipId" name="slip_id">
            <input type="hidden" id="signedUserId" name="user_id">
            <div class="modal-body">
                <p class="text-muted" style="font-size:.85rem; margin-bottom:14px;">
                    Upload scanned PDF or photo of the signed form for <strong id="signedDocNo"></strong>.
                </p>
                <div class="form-group">
                    <label class="form-label">Signed Document File (PDF / Image)</label>
                    <input type="file" name="file" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.docx" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Note / Remark</label>
                    <input type="text" name="note" class="form-control" placeholder="e.g. Signed by employee and HOD on 03 Aug 2026">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="document.getElementById('markSignedModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:#16a34a;"><i class="bi bi-check-lg"></i> Mark Signed & Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const chks = document.querySelectorAll('.slip-chk');
    chks.forEach(c => c.checked = master.checked);
    updateBatchBar();
}

function updateBatchBar() {
    const chks = document.querySelectorAll('.slip-chk:checked');
    const bar = document.getElementById('batchActionBar');
    const countSpan = document.getElementById('selectedCount');
    countSpan.innerText = chks.length;
    if (chks.length > 0) {
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
        document.getElementById('selectAllSlips').checked = false;
    }
}

function unselectAll() {
    const chks = document.querySelectorAll('.slip-chk');
    chks.forEach(c => c.checked = false);
    document.getElementById('selectAllSlips').checked = false;
    updateBatchBar();
}

function submitBatchPrint() {
    document.getElementById('batchForm').submit();
}

function toggleModalAllUsers(master) {
    const chks = document.querySelectorAll('.modal-user-chk');
    chks.forEach(c => c.checked = master.checked);
    updateModalCount();
}

function updateModalCount() {
    const chks = document.querySelectorAll('.modal-user-chk:checked');
    document.getElementById('modalCheckCount').innerText = chks.length;
}

function openMarkSignedModal(slipId, docNo, userId) {
    document.getElementById('signedSlipId').value = slipId;
    document.getElementById('signedUserId').value = userId;
    document.getElementById('signedDocNo').innerText = docNo;
    document.getElementById('markSignedModal').style.display = 'flex';
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
