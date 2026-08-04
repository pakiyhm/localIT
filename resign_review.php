<?php
// resign_review.php — Per-item disposition before processing a resignation
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';

$user_id = (int)($_GET['user_id'] ?? 0);
if (!$user_id) { header('Location: users.php'); exit; }

$u = $pdo->prepare("SELECT * FROM users WHERE user_id=:u");
$u->execute(['u' => $user_id]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: users.php'); exit; }
if (!empty($user['resigned_at'])) { header('Location: users.php?tab=resigned'); exit; }

// Items held
$assets = $pdo->prepare("
    SELECT a.asset_id, a.category, a.hostname, a.serial_number, a.status, a.ownership_type
    FROM   assets a WHERE a.user_id = :u ORDER BY a.category, a.hostname
");
$assets->execute(['u' => $user_id]);
$assets = $assets->fetchAll(PDO::FETCH_ASSOC);

$accessories = $pdo->prepare("
    SELECT accessory_id, category, name, serial_number, status
    FROM   accessories WHERE user_id = :u ORDER BY category, name
");
$accessories->execute(['u' => $user_id]);
$accessories = $accessories->fetchAll(PDO::FETCH_ASSOC);

$userEmail = !empty($user['email']) ? trim($user['email']) : (
    !empty($user['full_name']) ? strtolower(str_replace(' ', '.', trim($user['full_name']))) . '@example.com' : ''
);

$licenses = [];
try {
    $licenses = $pdo->prepare("
        SELECT s.license_id, s.license_key, s.expiry_date, sw.name AS sw_name, sw.category
        FROM   software_licenses s
        JOIN   software_batches sb ON s.batch_id    = sb.batch_id
        JOIN   software         sw ON sb.software_id = sw.software_id
        WHERE  s.user_id = :u AND s.status = 'assigned'
        ORDER BY sw.name
    ");
    $licenses->execute(['u' => $user_id]);
    $licenses = $licenses->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $licenses = []; }

$digitalAccounts = [];
if ($userEmail !== '') {
    $digitalAccounts[] = [
        'id'    => 'ms365',
        'name'  => 'Microsoft 365 & Corporate Email Account',
        'meta'  => 'Corporate Email Account',
        'badge' => $userEmail,
        'type'  => 'email'
    ];
}
$digitalAccounts[] = [
    'id'    => 'vpn',
    'name'  => 'Corporate VPN & Remote Network Access',
    'meta'  => 'Remote Access Privilege',
    'badge' => 'VPN Credentials',
    'type'  => 'vpn'
];

foreach ($licenses as $lic) {
    $digitalAccounts[] = [
        'id'    => 'lic_' . $lic['license_id'],
        'name'  => $lic['sw_name'],
        'meta'  => ($lic['category'] ?? 'Software') . ($lic['license_key'] ? ' · Key: ' . $lic['license_key'] : ''),
        'badge' => $lic['license_key'] ?: 'Assigned License',
        'type'  => 'license',
        'license_id' => $lic['license_id']
    ];
}

$totalItems = count($assets) + count($accessories) + count($digitalAccounts);

$pageTitle = 'Resignation Review';
$activeNav = 'users';
require_once 'includes/layout.php';
?>

<style>
.rr-item-row {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 14px;
    padding: 12px 22px;
    border-bottom: 1px solid var(--color-border-soft);
}
.rr-item-row:last-child { border-bottom: none; }
.rr-item-name { font-weight: 600; font-size: .9rem; color: var(--color-text); }
.rr-item-meta { font-size: .72rem; color: var(--color-text-muted); margin-top: 2px; }

.rr-radio-group {
    display: flex; gap: 4px;
    background: var(--color-bg);
    padding: 3px;
    border-radius: 8px;
}
.rr-radio-group input[type="radio"] { display: none; }
.rr-radio-group label {
    padding: 6px 12px;
    font-size: .78rem;
    font-weight: 600;
    color: var(--color-text-sub);
    border-radius: 6px;
    cursor: pointer;
    transition: .15s;
}
.rr-radio-group label:hover { color: var(--color-text); }
.rr-radio-group input[type="radio"]:checked + label { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.rr-radio-group input[type="radio"][value="returned"]:checked + label,
.rr-radio-group input[type="radio"][value="revoke"]:checked + label { color: #15803d; }
.rr-radio-group input[type="radio"][value="lost"]:checked + label { color: #b91c1c; }
.rr-radio-group input[type="radio"][value="broken"]:checked + label { color: #b45309; }
.rr-radio-group input[type="radio"][value="keep"]:checked + label { color: #2563eb; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
    <a href="users.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-left"></i> Back to Users</a>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="background:#fff5f5; border-bottom:1px solid #fecaca; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h3 style="color:#991b1b; margin:0;">
                <i class="bi bi-person-dash"></i>
                Resignation Review: <?= htmlspecialchars($user['full_name']) ?>
            </h3>
            <span class="text-muted" style="font-size:.82rem;">
                <?= $user['emp_id'] ? 'Emp #' . htmlspecialchars($user['emp_id']) . ' · ' : '' ?>
                <?= $user['department'] ? htmlspecialchars($user['department']) : '' ?>
            </span>
        </div>
        <a href="print_handover.php?user_id=<?= $user_id ?>&type=return&reason=resignation" target="_blank" class="btn btn-ghost btn-sm" style="border:1px solid #fca5a5; background:#fff; color:#dc2626; font-weight:700; padding:6px 14px;" title="Print Clearance Form">
            <i class="bi bi-printer"></i> Print Clearance Form (ใบคืนอุปกรณ์)
        </a>
    </div>
    <div style="padding:16px 22px; font-size:.88rem;">
        ตรวจสอบของที่อยู่ในความดูแลของพนักงานคนนี้ก่อนกดประมวลผลการลาออก
        <strong><?= $totalItems ?></strong> รายการ
        (<?= count($assets) ?> assets · <?= count($accessories) ?> accessories · <?= count($digitalAccounts) ?> digital accounts &amp; email)
    </div>
</div>

<form action="actions.php" method="POST" id="resignForm"
      data-confirm="ยืนยันประมวลผลการลาออก <?= htmlspecialchars(addslashes($user['full_name'])) ?>?"
      data-confirm-title="Confirm Resignation"
      data-confirm-btn="ดำเนินการลาออก">
    <?= csrf_field() ?>
    <input type="hidden" name="action"  value="resign_user">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">

    <div class="card" style="margin-bottom:14px;">
        <div class="card-header"><h3><i class="bi bi-calendar"></i> วันที่ลาออก</h3></div>
        <div style="padding:16px 22px; display:flex; gap:14px; align-items:end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Resignation Date</label>
                <input type="date" name="resignation_date" class="form-control"
                       value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required style="width:200px;">
            </div>
            <div class="form-group" style="margin:0; flex:1;">
                <label class="form-label">หมายเหตุ (Optional)</label>
                <input type="text" name="note" class="form-control" placeholder="เช่น เหตุผล / สาขา / handover ไปยัง...">
            </div>
        </div>
    </div>

    <!-- Assets -->
    <?php if (!empty($assets)): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header">
            <h3><i class="bi bi-laptop"></i> Device Assets (<?= count($assets) ?>)</h3>
            <button type="button" onclick="setAll('asset','returned')" class="btn btn-ghost btn-sm" style="font-size:.75rem;">
                เลือกทั้งหมด: คืนครบ
            </button>
        </div>
        <?php foreach ($assets as $a): ?>
        <div class="rr-item-row">
            <div>
                <div class="rr-item-name">
                    <a href="history.php?asset_id=<?= $a['asset_id'] ?>" class="text-primary-link" target="_blank">
                        <?= htmlspecialchars($a['hostname'] ?: $a['serial_number'] ?: '—') ?>
                    </a>
                </div>
                <div class="rr-item-meta">
                    <?= htmlspecialchars($a['category']) ?>
                    <?= $a['serial_number'] ? ' · SN: ' . htmlspecialchars($a['serial_number']) : '' ?>
                    <?= $a['ownership_type'] ? ' · ' . htmlspecialchars($a['ownership_type']) : '' ?>
                </div>
            </div>
            <div class="rr-radio-group">
                <input type="radio" name="asset_disposition[<?= $a['asset_id'] ?>]" id="ast_<?= $a['asset_id'] ?>_returned" value="returned" checked>
                <label for="ast_<?= $a['asset_id'] ?>_returned"><i class="bi bi-check2"></i> คืนครบ</label>

                <input type="radio" name="asset_disposition[<?= $a['asset_id'] ?>]" id="ast_<?= $a['asset_id'] ?>_lost" value="lost">
                <label for="ast_<?= $a['asset_id'] ?>_lost"><i class="bi bi-x-circle"></i> หาย</label>

                <input type="radio" name="asset_disposition[<?= $a['asset_id'] ?>]" id="ast_<?= $a['asset_id'] ?>_broken" value="broken">
                <label for="ast_<?= $a['asset_id'] ?>_broken"><i class="bi bi-wrench"></i> ชำรุด</label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Accessories -->
    <?php if (!empty($accessories)): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header">
            <h3><i class="bi bi-plug"></i> Accessories (<?= count($accessories) ?>)</h3>
            <button type="button" onclick="setAll('acc','returned')" class="btn btn-ghost btn-sm" style="font-size:.75rem;">
                เลือกทั้งหมด: คืนครบ
            </button>
        </div>
        <?php foreach ($accessories as $ac): ?>
        <div class="rr-item-row">
            <div>
                <div class="rr-item-name"><?= htmlspecialchars($ac['name']) ?></div>
                <div class="rr-item-meta">
                    <?= htmlspecialchars($ac['category']) ?>
                    <?= $ac['serial_number'] ? ' · SN: ' . htmlspecialchars($ac['serial_number']) : '' ?>
                </div>
            </div>
            <div class="rr-radio-group">
                <input type="radio" name="acc_disposition[<?= $ac['accessory_id'] ?>]" id="acc_<?= $ac['accessory_id'] ?>_returned" value="returned" checked>
                <label for="acc_<?= $ac['accessory_id'] ?>_returned"><i class="bi bi-check2"></i> คืนครบ</label>

                <input type="radio" name="acc_disposition[<?= $ac['accessory_id'] ?>]" id="acc_<?= $ac['accessory_id'] ?>_lost" value="lost">
                <label for="acc_<?= $ac['accessory_id'] ?>_lost"><i class="bi bi-x-circle"></i> หาย</label>

                <input type="radio" name="acc_disposition[<?= $ac['accessory_id'] ?>]" id="acc_<?= $ac['accessory_id'] ?>_broken" value="broken">
                <label for="acc_<?= $ac['accessory_id'] ?>_broken"><i class="bi bi-wrench"></i> ชำรุด</label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Digital Accounts & Email Assets -->
    <?php if (!empty($digitalAccounts)): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="card-header">
            <h3><i class="bi bi-shield-lock-fill" style="color:var(--color-accent);"></i> Digital Accounts &amp; Email Assets (<?= count($digitalAccounts) ?>)</h3>
        </div>
        <?php foreach ($digitalAccounts as $da): ?>
        <div class="rr-item-row">
            <div>
                <div class="rr-item-name" style="display:flex; align-items:center; gap:8px;">
                    <?= htmlspecialchars($da['name']) ?>
                    <?php if (!empty($da['badge'])): ?>
                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-family:monospace; font-weight:700; font-size:.78rem; padding:3px 8px; border-radius:4px;">
                            <?= htmlspecialchars($da['badge']) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="rr-item-meta">
                    <?= htmlspecialchars($da['meta']) ?>
                </div>
            </div>
            <div class="rr-radio-group">
                <?php if ($da['type'] === 'license'): ?>
                    <input type="radio" name="lic_disposition[<?= $da['license_id'] ?>]" id="lic_<?= $da['license_id'] ?>_revoke" value="revoke" checked>
                    <label for="lic_<?= $da['license_id'] ?>_revoke"><i class="bi bi-slash-circle"></i> เพิกถอนสิทธิ์</label>
                    <input type="radio" name="lic_disposition[<?= $da['license_id'] ?>]" id="lic_<?= $da['license_id'] ?>_keep" value="keep">
                    <label for="lic_<?= $da['license_id'] ?>_keep"><i class="bi bi-bookmark"></i> เก็บไว้ไป assign ใหม่</label>
                <?php else: ?>
                    <input type="radio" name="digital_disposition[<?= $da['id'] ?>]" id="da_<?= $da['id'] ?>_revoke" value="revoke" checked>
                    <label for="da_<?= $da['id'] ?>_revoke"><i class="bi bi-slash-circle"></i> เพิกถอนสิทธิ์ (Disable)</label>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($totalItems === 0): ?>
    <div class="card" style="margin-bottom:14px;">
        <div class="empty-state" style="padding:30px;">
            <i class="bi bi-check-circle" style="color:var(--color-success);"></i>
            <p>พนักงานคนนี้ไม่มีของในความดูแล — กดดำเนินการลาออกได้เลย</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div style="padding:16px 22px; display:flex; gap:10px; justify-content:flex-end; align-items:center;">
            <span class="text-muted" style="font-size:.82rem; margin-right:auto;">
                <i class="bi bi-info-circle"></i>
                เมื่อกดยืนยัน: ของ "คืนครบ" → เข้า stock, "หาย" → status lost, "ชำรุด" → status repair, ผู้ใช้จะถูกลบ
            </span>
            <a href="users.php" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger edit-only">
                <i class="bi bi-person-dash"></i> ดำเนินการลาออก
            </button>
        </div>
    </div>
</form>

<script>
function setAll(kind, value) {
    const prefix = kind === 'asset' ? 'ast_' : 'acc_';
    document.querySelectorAll('input[type="radio"]').forEach(r => {
        if (r.id.startsWith(prefix) && r.value === value) r.checked = true;
    });
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
