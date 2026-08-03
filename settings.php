<?php
// settings.php — System Settings
require_once 'includes/db_connect.php';
require_once 'includes/column_helper.php';

// Fetch locations
$locations = $pdo->query("SELECT * FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch alert settings
try {
    $alertSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_alert_days'")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $alertSettings = [];
}
$leaseAlertDays    = (int)($alertSettings['lease_alert_days'] ?? 30);
$warrantyAlertDays = (int)($alertSettings['warranty_alert_days'] ?? 30);
$softwareAlertDays = (int)($alertSettings['software_alert_days'] ?? 30);

// Fetch location usage count (to prevent deleting locations in use)
// Count assets, accessories, and users that reference each location
$locUsageRaw = $pdo->query("
    SELECT location_id, COUNT(*) as cnt FROM assets WHERE location_id IS NOT NULL GROUP BY location_id
    UNION ALL
    SELECT location_id, COUNT(*) FROM accessories WHERE location_id IS NOT NULL GROUP BY location_id
    UNION ALL
    SELECT location_id, COUNT(*) FROM users WHERE location_id IS NOT NULL GROUP BY location_id
")->fetchAll(PDO::FETCH_ASSOC);
$locUsage = [];
foreach ($locUsageRaw as $r) {
    $lid = $r['location_id'];
    $locUsage[$lid] = ($locUsage[$lid] ?? 0) + $r['cnt'];
}

// Fetch column settings
$columnSettings = getColumnSettings($pdo);

$msg      = $_GET['msg']   ?? '';
$error    = $_GET['error'] ?? '';
$activeTab = $_GET['tab']  ?? 'locations';

$pageTitle = 'Settings';
$activeNav = 'settings';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Changes saved successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ─── Tab Switcher ─────────────────────────────────────── -->
<div style="display:flex; gap:4px; margin-bottom:20px; border-bottom:2px solid var(--color-border);">
    <a href="settings.php?tab=locations"
       style="padding:10px 20px; font-size:.88rem; font-weight:600; text-decoration:none; border-radius:6px 6px 0 0; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s;
              <?= $activeTab === 'locations' ? 'background:var(--color-accent-lite); color:var(--color-accent-dark); border-bottom-color:var(--color-accent);' : 'color:var(--color-text-sub);' ?>">
        <i class="bi bi-geo-alt"></i> Locations
    </a>
    <a href="settings.php?tab=columns"
       style="padding:10px 20px; font-size:.88rem; font-weight:600; text-decoration:none; border-radius:6px 6px 0 0; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s;
              <?= $activeTab === 'columns' ? 'background:var(--color-accent-lite); color:var(--color-accent-dark); border-bottom-color:var(--color-accent);' : 'color:var(--color-text-sub);' ?>">
        <i class="bi bi-layout-three-columns"></i> Column Designer
    </a>
    <a href="settings.php?tab=alerts"
       style="padding:10px 20px; font-size:.88rem; font-weight:600; text-decoration:none; border-radius:6px 6px 0 0; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s;
              <?= $activeTab === 'alerts' ? 'background:var(--color-accent-lite); color:var(--color-accent-dark); border-bottom-color:var(--color-accent);' : 'color:var(--color-text-sub);' ?>">
        <i class="bi bi-bell"></i> Alerts & Notifications
    </a>
    <a href="settings.php?tab=branding"
       style="padding:10px 20px; font-size:.88rem; font-weight:600; text-decoration:none; border-radius:6px 6px 0 0; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s;
              <?= $activeTab === 'branding' ? 'background:var(--color-accent-lite); color:var(--color-accent-dark); border-bottom-color:var(--color-accent);' : 'color:var(--color-text-sub);' ?>">
        <i class="bi bi-image"></i> Company Logo & Branding
    </a>
    <a href="settings.php?tab=access"
       style="padding:10px 20px; font-size:.88rem; font-weight:600; text-decoration:none; border-radius:6px 6px 0 0; border-bottom:2px solid transparent; margin-bottom:-2px; transition:.15s;
              <?= $activeTab === 'access' ? 'background:var(--color-accent-lite); color:var(--color-accent-dark); border-bottom-color:var(--color-accent);' : 'color:var(--color-text-sub);' ?>">
        <i class="bi bi-shield-lock"></i> Access & QR
    </a>
</div>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- TAB: LOCATIONS                                             -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'locations'): ?>
<div style="max-width:800px;">
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); margin-bottom:0;">
        <div class="card-header" style="padding:16px 24px; background:var(--color-surface);">
            <h3 style="font-size:1.05rem; margin:0;"><i class="bi bi-geo-alt" style="color:var(--color-accent); margin-right:8px;"></i> Manage Locations</h3>
        </div>
        <div class="card-body" style="padding:20px 24px;">
            
            <form action="actions.php" method="POST" class="edit-only" style="display:flex; gap:10px; margin-bottom:20px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_location">
                <input type="text" name="location_name" class="form-control" placeholder="New Location Name (e.g. HQ - Level 2)" required style="max-width:400px;">
                <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Location</button>
            </form>

            <table style="border: 1px solid var(--color-border-soft); border-radius:6px; overflow:hidden;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:10px 16px; border-bottom:1px solid var(--color-border-soft);">Location Name</th>
                        <th style="padding:10px 16px; border-bottom:1px solid var(--color-border-soft); text-align:center;">Assets Linked</th>
                        <th style="padding:10px 16px; border-bottom:1px solid var(--color-border-soft); text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($locations)): ?>
                    <tr><td colspan="3" style="text-align:center; padding:20px; color:var(--color-text-muted);">No locations defined.</td></tr>
                <?php else: ?>
                    <?php foreach ($locations as $loc): ?>
                        <?php $inUse = ($locUsage[$loc['location_id']] ?? 0) > 0; ?>
                        <tr>
                            <td class="fw-600" style="padding:12px 16px;"><?= htmlspecialchars($loc['location_name']) ?></td>
                            <td style="padding:12px 16px; text-align:center;">
                                <?php if ($inUse): ?>
                                    <span class="badge" style="background:var(--color-accent-lite); color:var(--color-accent-dark);"><?= $locUsage[$loc['location_id']] ?> assets</span>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; text-align:right;">
                                <?php if (!$inUse): ?>
                                    <form action="actions.php" method="POST" class="edit-only"
                                          data-confirm="ลบ location นี้?" data-confirm-title="Delete Location" style="margin:0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_location">
                                        <input type="hidden" name="location_id" value="<?= $loc['location_id'] ?>">
                                        <button type="submit" class="btn btn-danger-ghost btn-sm" style="padding:5px 8px; border-radius:6px;">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button disabled class="btn btn-ghost btn-sm" style="padding:5px 8px; opacity:.5; cursor:not-allowed;" title="Cannot delete location currently in use">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- TAB: COLUMN DESIGNER                                       -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'columns'): ?>
<div style="display:grid; grid-template-columns:1fr 340px; gap:20px; align-items:start;">

    <!-- Left: Column List -->
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
        <div class="card-header" style="padding:16px 24px; background:var(--color-surface); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:1.05rem; margin:0;">
                <i class="bi bi-layout-three-columns" style="color:var(--color-accent); margin-right:8px;"></i>
                Column Designer
                <span style="font-size:.75rem; font-weight:400; color:var(--color-text-muted); margin-left:6px;">Drag to reorder · Toggle eye to show/hide</span>
            </h3>
            <button onclick="saveColumns()" class="btn btn-primary btn-sm" style="padding:6px 16px;">
                <i class="bi bi-save"></i> Save Changes
            </button>
        </div>
        <div class="card-body" style="padding:16px 24px;">

            <!-- Legend -->
            <div style="display:flex; gap:16px; margin-bottom:16px; font-size:.78rem; color:var(--color-text-muted);">
                <span><i class="bi bi-grip-vertical"></i> Drag to reorder</span>
                <span style="background:#eff6ff; color:#2563eb; padding:1px 6px; border-radius:3px; font-size:.7rem;">SYSTEM</span>
                <span style="background:#f0fdf4; color:#16a34a; padding:1px 6px; border-radius:3px; font-size:.7rem;">CUSTOM</span>
            </div>

            <form id="colForm" action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_column_settings">
                <div id="colList" style="display:flex; flex-direction:column; gap:6px;">
                    <?php foreach ($columnSettings as $col): ?>
                    <div class="col-row" data-key="<?= htmlspecialchars($col['col_key']) ?>"
                         draggable="true"
                         style="display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border:1px solid var(--color-border-soft); border-radius:8px; cursor:grab; transition:.15s; user-select:none;"
                         onmouseenter="this.style.background='#f8fafc'"
                         onmouseleave="this.style.background='#fff'">

                        <!-- Drag Handle -->
                        <i class="bi bi-grip-vertical" style="color:#94a3b8; font-size:1rem; flex-shrink:0;"></i>

                        <!-- Visibility Select -->
                        <select class="form-control form-select col-vis-select" style="width:110px; font-size:.75rem; padding:2px 6px; height:26px; flex-shrink:0;">
                            <option value="main" <?= ($col['col_visible'] == 1) ? 'selected' : '' ?>>Main Table</option>
                            <option value="adv" <?= ($col['col_visible'] == 0 && $col['is_advanced'] == 1) ? 'selected' : '' ?>>Advanced</option>
                            <option value="hide" <?= ($col['col_visible'] == 0 && $col['is_advanced'] == 0) ? 'selected' : '' ?>>Hidden</option>
                        </select>

                        <!-- Label (editable inline) -->
                        <span class="col-label-display" style="flex:1; font-weight:600; font-size:.88rem; color:var(--color-text);"><?= htmlspecialchars($col['col_label']) ?></span>
                        <input type="text" class="col-label-input form-control" value="<?= htmlspecialchars($col['col_label']) ?>"
                               style="display:none; flex:1; padding:4px 8px; font-size:.85rem; height:30px;"
                               data-key="<?= htmlspecialchars($col['col_key']) ?>">

                        <!-- Edit label button -->
                        <button type="button" onclick="toggleLabelEdit(this)" title="Rename column"
                                style="background:none; border:none; cursor:pointer; color:#94a3b8; padding:2px 6px; border-radius:4px; font-size:.85rem; flex-shrink:0;"
                                onmouseenter="this.style.color='#2563eb'"
                                onmouseleave="this.style.color='#94a3b8'">
                            <i class="bi bi-pencil-square"></i>
                        </button>

                        <!-- Type badge -->
                        <span style="font-size:.68rem; padding:2px 7px; border-radius:4px; font-weight:600; flex-shrink:0;
                              background:<?= $col['col_type']==='date' ? '#fef3c7' : ($col['col_type']==='number' ? '#ede9fe' : '#f1f5f9') ?>;
                              color:<?= $col['col_type']==='date' ? '#b45309' : ($col['col_type']==='number' ? '#6d28d9' : '#475569') ?>;">
                            <?= strtoupper($col['col_type']) ?>
                        </span>

                        <!-- System / Custom badge -->
                        <span style="font-size:.68rem; padding:2px 7px; border-radius:4px; font-weight:600; flex-shrink:0;
                              background:<?= $col['is_system'] ? '#eff6ff' : '#f0fdf4' ?>;
                              color:<?= $col['is_system'] ? '#2563eb' : '#16a34a' ?>;">
                            <?= $col['is_system'] ? 'SYSTEM' : 'CUSTOM' ?>
                        </span>

                        <!-- Delete (custom only) -->
                        <?php if (!$col['is_system']): ?>
                        <form action="actions.php" method="POST" style="margin:0;" class="edit-only" onsubmit="if(this.dataset.confirmed==='1') return true; event.preventDefault(); confirmDeleteColumn('<?= htmlspecialchars(addslashes($col['col_label'])) ?>').then(ok=>{ if(ok){ this.dataset.confirmed='1'; this.submit(); } }); return false;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_custom_column">
                            <input type="hidden" name="col_key" value="<?= htmlspecialchars($col['col_key']) ?>">
                            <button type="submit" title="Delete custom column"
                                    style="background:none; border:none; cursor:pointer; color:#e11d48; padding:2px 6px; border-radius:4px; font-size:.85rem; flex-shrink:0;"
                                    onmouseenter="this.style.background='#fff1f2'"
                                    onmouseleave="this.style.background='none'">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <span style="width:26px; flex-shrink:0;"></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Hidden inputs populated by JS on save -->
                <div id="colHiddenInputs"></div>
            </form>
        </div>
    </div>

    <!-- Right: Add Custom Column + Info -->
    <div style="display:flex; flex-direction:column; gap:16px;">

        <!-- Add Custom Column Card -->
        <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border);">
            <div class="card-header" style="padding:14px 20px; background:var(--color-surface);">
                <h3 style="font-size:.95rem; margin:0;">
                    <i class="bi bi-plus-circle" style="color:#16a34a; margin-right:8px;"></i>
                    Add Custom Column
                </h3>
            </div>
            <div class="card-body" style="padding:16px 20px;">
                <form action="actions.php" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_custom_column">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">Column Label <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="col_label" class="form-control" placeholder="e.g. Ticket Number"
                               required maxlength="60" style="font-size:.88rem;">
                        <div style="font-size:.75rem; color:var(--color-text-muted); margin-top:4px;">
                            Will be stored as <code>custom_ticket_number</code>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label">Data Type</label>
                        <select name="col_type" class="form-control form-select" style="font-size:.88rem;">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="bi bi-plus-lg"></i> Add Column
                    </button>
                </form>
            </div>
        </div>

        <!-- Info Card -->
        <div style="background:#f8fafc; border:1px solid var(--color-border-soft); border-radius:10px; padding:16px 20px; font-size:.82rem; color:var(--color-text-sub); line-height:1.7;">
            <div style="font-weight:700; color:var(--color-text); margin-bottom:8px;"><i class="bi bi-info-circle" style="color:var(--color-accent);"></i> How it works</div>
            <ul style="margin:0; padding-left:18px;">
                <li><strong>System columns</strong> are built-in and cannot be deleted, but can be hidden or renamed.</li>
                <li><strong>Custom columns</strong> add a real database field to every asset. Data entered per asset is saved permanently.</li>
                <li><strong>Hidden columns</strong> still store data — they are just not displayed in the table.</li>
                <li>Drag rows to reorder how columns appear left-to-right in the Assets table.</li>
                <li>Click the <i class="bi bi-pencil-square"></i> icon to rename any column label.</li>
            </ul>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;
// ─── Drag & Drop Reorder ─────────────────────────────────────
let dragSrc = null;
document.querySelectorAll('.col-row').forEach(row => {
    row.addEventListener('dragstart', function(e) {
        dragSrc = this;
        this.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', function() {
        this.style.opacity = '1';
        document.querySelectorAll('.col-row').forEach(r => r.style.background = '#fff');
    });
    row.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        this.style.background = '#eff6ff';
        this.style.borderColor = 'var(--color-accent)';
        return false;
    });
    row.addEventListener('dragleave', function() {
        this.style.background = '#fff';
        this.style.borderColor = 'var(--color-border-soft)';
    });
    row.addEventListener('drop', function(e) {
        e.stopPropagation();
        this.style.background = '#fff';
        this.style.borderColor = 'var(--color-border-soft)';
        if (dragSrc !== this) {
            const list = document.getElementById('colList');
            const rows = [...list.querySelectorAll('.col-row')];
            const srcIdx  = rows.indexOf(dragSrc);
            const destIdx = rows.indexOf(this);
            if (srcIdx < destIdx) {
                list.insertBefore(dragSrc, this.nextSibling);
            } else {
                list.insertBefore(dragSrc, this);
            }
        }
        return false;
    });
});

// ─── Inline Label Edit ───────────────────────────────────────
function toggleLabelEdit(btn) {
    const row = btn.closest('.col-row');
    const display = row.querySelector('.col-label-display');
    const input   = row.querySelector('.col-label-input');
    const pencil  = btn.querySelector('i');

    if (input.style.display === 'none') {
        display.style.display = 'none';
        input.style.display = 'block';
        input.focus();
        pencil.className = 'bi bi-check-lg';
        btn.style.color = '#16a34a';
        btn.title = 'Confirm rename';
    } else {
        const newLabel = input.value.trim();
        if (!newLabel) { alert('Label cannot be empty.'); return; }
        display.textContent = newLabel;
        display.style.display = '';
        input.style.display = 'none';
        pencil.className = 'bi bi-pencil-square';
        btn.style.color = '#94a3b8';
        btn.title = 'Rename column';
        // Mark row as needing rename
        input.dataset.renamed = '1';
    }
}

// ─── Save Columns (order + visibility + renames) ─────────────
function saveColumns() {
    const form = document.getElementById('colForm');
    const container = document.getElementById('colHiddenInputs');
    container.innerHTML = '';

    const rows = document.querySelectorAll('#colList .col-row');
    const renames = []; // {key, label} for renamed columns

    rows.forEach((row, idx) => {
        const key   = row.dataset.key;
        const input = row.querySelector('.col-label-input');
        const select = row.querySelector('.col-vis-select');

        // Order key
        const hKey = document.createElement('input');
        hKey.type = 'hidden'; hKey.name = 'columns[]'; hKey.value = key;
        container.appendChild(hKey);

        // Visibility
        if (select) {
            const visVal = select.value;
            if (visVal === 'main') {
                const hVis = document.createElement('input'); hVis.type = 'hidden'; hVis.name = 'visible[]'; hVis.value = key; container.appendChild(hVis);
            } else if (visVal === 'adv') {
                const hAdv = document.createElement('input'); hAdv.type = 'hidden'; hAdv.name = 'advanced[]'; hAdv.value = key; container.appendChild(hAdv);
            }
        }

        // Collect renames
        if (input && input.dataset.renamed === '1') {
            renames.push({ key, label: input.value.trim() });
        }
    });

    if (renames.length > 0) {
        // Submit renames first via fetch, then submit main form
        Promise.all(renames.map(r => {
            const fd = new FormData();
            fd.append('action', 'rename_column');
            fd.append('col_key', r.key);
            fd.append('col_label', r.label);
            fd.append('_csrf_token', csrfToken);
            return fetch('actions.php', { method: 'POST', body: fd });
        })).then(() => form.submit());
    } else {
        form.submit();
    }
}

// ─── Confirm delete (custom column) ──────────────────────────
// Returns a Promise so callers can await before submitting.
function confirmDeleteColumn(label) {
    return askConfirm({
        title: 'ลบ Custom Column',
        message: 'ลบ column "' + label + '"? ข้อมูลใน column นี้ของ asset ทุกชิ้นจะหายถาวร ย้อนกลับไม่ได้',
        confirmText: 'ลบ Column'
    });
}
</script>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- TAB: ALERTS & NOTIFICATIONS                                -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'alerts'): ?>
<div style="max-width:700px;">
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); margin-bottom:20px;">
        <div class="card-header" style="padding:16px 24px; background:var(--color-surface);">
            <h3 style="font-size:1.05rem; margin:0;"><i class="bi bi-bell" style="color:var(--color-accent); margin-right:8px;"></i> Alert Notification Periods</h3>
            <span style="font-size:.8rem; color:var(--color-text-muted); display:block; margin-top:4px;">Configure how many days before expiration you want to be notified on the dashboard and the notification bell.</span>
        </div>
        <div class="card-body" style="padding:20px 24px;">
            <form action="actions.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_alert_settings">

                <!-- Lease Alert -->
                <div style="display:flex; align-items:center; gap:16px; padding:16px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; margin-bottom:14px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:#dc2626; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="bi bi-calendar-event" style="color:#fff; font-size:1.2rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:.92rem; color:#991b1b;">Lease Expiry (Rental Assets)</div>
                        <div style="font-size:.78rem; color:#b91c1c; margin-top:2px;">Alert when <code>ownership_type = Rent</code> and <code>lease_expiry</code> is within this period</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <input type="number" name="lease_alert_days" value="<?= $leaseAlertDays ?>" min="1" max="365"
                               class="form-control" style="width:80px; text-align:center; font-weight:700; font-size:1rem;">
                        <span style="font-size:.82rem; color:#991b1b; font-weight:600;">days</span>
                    </div>
                </div>

                <!-- Warranty Alert -->
                <div style="display:flex; align-items:center; gap:16px; padding:16px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; margin-bottom:14px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:#d97706; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="bi bi-shield-check" style="color:#fff; font-size:1.2rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:.92rem; color:#92400e;">Warranty Expiry (Purchased Assets)</div>
                        <div style="font-size:.78rem; color:#b45309; margin-top:2px;">Alert when <code>ownership_type = Buy</code> and <code>lease_expiry</code> is within this period</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <input type="number" name="warranty_alert_days" value="<?= $warrantyAlertDays ?>" min="1" max="365"
                               class="form-control" style="width:80px; text-align:center; font-weight:700; font-size:1rem;">
                        <span style="font-size:.82rem; color:#92400e; font-weight:600;">days</span>
                    </div>
                </div>

                <!-- Software License Alert -->
                <div style="display:flex; align-items:center; gap:16px; padding:16px; background:#fdf4ff; border:1px solid #f0abfc; border-radius:10px; margin-bottom:20px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:#c026d3; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="bi bi-key" style="color:#fff; font-size:1.2rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:.92rem; color:#86198f;">Software License Expiry</div>
                        <div style="font-size:.78rem; color:#a21caf; margin-top:2px;">Alert when <code>software_licenses.expiry_date</code> is within this period</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <input type="number" name="software_alert_days" value="<?= $softwareAlertDays ?>" min="1" max="365"
                               class="form-control" style="width:80px; text-align:center; font-weight:700; font-size:1rem;">
                        <span style="font-size:.82rem; color:#86198f; font-weight:600;">days</span>
                    </div>
                </div>

                <!-- Consumable Low Stock Alert -->
                <div style="display:flex; align-items:center; gap:16px; padding:16px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; margin-bottom:20px;">
                    <div style="width:44px; height:44px; border-radius:10px; background:#059669; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="bi bi-box-seam" style="color:#fff; font-size:1.2rem;"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:.92rem; color:#065f46;">Consumable Low Stock</div>
                        <div style="font-size:.78rem; color:#047857; margin-top:2px;">แจ้งเตือนเมื่อ Balance ของ Consumable ใดๆ เหลือน้อยกว่าหรือเท่ากับค่านี้ (0 = แจ้งเฉพาะหมดสต็อก)</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <input type="number" name="consumable_alert_qty" value="<?= (int)($alertSettings['consumable_alert_qty'] ?? 5) ?>" min="0" max="999"
                               class="form-control" style="width:80px; text-align:center; font-weight:700; font-size:1rem;">
                        <span style="font-size:.82rem; color:#065f46; font-weight:600;">pcs</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="bi bi-save"></i> Save Alert Settings
                </button>
            </form>
        </div>
    </div>

    <!-- Info Card -->
    <div style="background:#f8fafc; border:1px solid var(--color-border-soft); border-radius:10px; padding:16px 20px; font-size:.82rem; color:var(--color-text-sub); line-height:1.7;">
        <div style="font-weight:700; color:var(--color-text); margin-bottom:8px;"><i class="bi bi-info-circle" style="color:var(--color-accent);"></i> How Alerts Work</div>
        <ul style="margin:0; padding-left:18px;">
            <li><strong>Lease Alerts</strong> trigger for assets where <code>ownership_type = Rent</code> and the <code>lease_expiry</code> date is within the configured days.</li>
            <li><strong>Warranty Alerts</strong> trigger for assets where <code>ownership_type = Buy</code> and the <code>lease_expiry</code> date (used as warranty end) is within the configured days.</li>
            <li><strong>Software Alerts</strong> trigger for active/assigned software licenses whose <code>expiry_date</code> is within the configured days.</li>
            <li>The <strong>notification bell</strong> <i class="bi bi-bell"></i> in the top bar updates on every page and shows a breakdown of expiring items.</li>
            <li>The <strong>dashboard</strong> cards will reflect these custom thresholds as well.</li>
            <li><strong>Power Automate Integration:</strong> The system automatically exports these alerts to <code>automated_exports/</code> as CSV files whenever you view the dashboard.</li>
            <li><strong>OneDrive Milestone Notifications:</strong> For <strong>30, 25, and 20 day</strong> milestones, the system generates unique timestamped CSVs in your specified OneDrive folder. It tracks these in the database to ensure each milestone is only notified <strong>once</strong>.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════ -->
<!-- TAB: BRANDING & LOGO PORTAL                                -->
<!-- ══════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'branding'): 
    $cur_comp_name = _auth_get_setting($pdo, 'company_name', 'COMPANY NAME');
    $cur_comp_dept = _auth_get_setting($pdo, 'company_dept', 'Information Technology Department');
    $cur_comp_logo = _auth_get_setting($pdo, 'company_logo', '');
?>
<div style="max-width:760px;">
    <div class="card" style="box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); margin-bottom:20px;">
        <div class="card-header" style="padding:16px 24px; background:var(--color-surface);">
            <h3 style="font-size:1.05rem; margin:0;"><i class="bi bi-image" style="color:var(--color-accent); margin-right:8px;"></i> Company Branding & Logo Upload Portal</h3>
        </div>
        <div style="padding:24px;">
            <p class="text-muted" style="font-size:.85rem; margin-bottom:20px;">
                อัปโหลดโลโก้บริษัทและตั้งชื่อองค์กรเพื่อแสดงบนหัวเอกสารใบรับมอบ/ส่งคืนอุปกรณ์ (`Handover Certificates`), ฉลากพิมพ์ Barcode, และส่วนหัวของระบบ
            </p>

            <!-- Current Logo Preview Box -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:20px; text-align:center; margin-bottom:24px;">
                <div style="font-size:.78rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:12px;">Current Uploaded Logo Preview</div>
                <?php if ($cur_comp_logo && file_exists(__DIR__ . '/' . $cur_comp_logo)): ?>
                    <img src="<?= htmlspecialchars($cur_comp_logo) ?>" alt="Company Logo" style="max-height:80px; max-width:260px; object-fit:contain; border-radius:6px; padding:6px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.06);">
                    <div style="margin-top:14px;">
                        <form action="actions.php" method="POST" class="edit-only" style="display:inline;" data-confirm="Delete company logo and reset to default text?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_company_logo">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:#dc2626; border:1px solid #fca5a5;">
                                <i class="bi bi-trash"></i> Remove Logo & Reset to Default
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="padding:20px; border:2px dashed #cbd5e1; border-radius:8px; background:#fff; color:#94a3b8; font-size:.85rem;">
                        <i class="bi bi-file-earmark-image" style="font-size:2rem; display:block; margin-bottom:6px;"></i>
                        No custom logo uploaded yet. (System uses default text logo: <strong><?= htmlspecialchars($cur_comp_name) ?></strong>)
                    </div>
                <?php endif; ?>
            </div>

            <!-- Upload Logo Form -->
            <form action="actions.php" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_branding_settings">
                
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="font-weight:700;">Company / Organization Name</label>
                    <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($cur_comp_name) ?>" required placeholder="e.g. Company Name" <?= !is_edit_mode() ? 'disabled' : '' ?>>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="font-weight:700;">Department Name</label>
                    <input type="text" name="company_dept" class="form-control" value="<?= htmlspecialchars($cur_comp_dept) ?>" required placeholder="e.g. Information Technology Department" <?= !is_edit_mode() ? 'disabled' : '' ?>>
                </div>

                <div class="form-group edit-only" style="margin-bottom:20px;">
                    <label class="form-label" style="font-weight:700;">Upload New Logo Image (PNG, JPG, WEBP, SVG)</label>
                    <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
                    <small class="text-muted" style="font-size:.72rem;">Recommended size: Transparent PNG or SVG with max height 120px.</small>
                </div>

                <button type="submit" class="btn btn-primary edit-only">
                    <i class="bi bi-cloud-arrow-up-fill"></i> Save Branding & Logo Settings
                </button>
            </form>

            <?php if (!is_edit_mode()): ?>
            <div class="alert alert-info" style="margin-top:16px;">
                <i class="bi bi-info-circle"></i> คุณอยู่ในโหมดดูอย่างเดียว — กรอก PIN ที่หน้า <a href="login.php" class="text-primary-link">login</a> เพื่อเปิดโหมดแก้ไขและอัปโหลดโลโก้
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ══════════════════════════════════════════════════════════
// TAB: ACCESS & QR
// ══════════════════════════════════════════════════════════
if ($activeTab === 'access'):
    $settingsRow = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $cur_pin     = $settingsRow['edit_pin']         ?? '123456';
    $cur_minutes = (int)($settingsRow['session_minutes'] ?? 60);
    $cur_qrbase  = $settingsRow['qr_base_url']      ?? '';
?>
<div style="max-width:760px; display:flex; flex-direction:column; gap:18px;">

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-shield-lock"></i> Edit PIN & Session</h3>
        </div>
        <div style="padding:20px 24px;">
            <p class="text-muted" style="font-size:.85rem; margin-bottom:14px;">
                ผู้ใช้คนใดมี PIN นี้จะปลดล็อกโหมดแก้ไขได้ (เพิ่ม/แก้/ลบ). ผู้ที่ไม่กรอก PIN จะดูได้อย่างเดียว.
                Session จะหมดอายุอัตโนมัติเมื่อไม่มีการใช้งานนานเกินกำหนด.
            </p>
            <form action="actions.php" method="POST" class="edit-only">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_access_settings">
                <input type="hidden" name="qr_base_url" value="<?= htmlspecialchars($cur_qrbase) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Edit PIN <span style="color:#9ca3af;">(4-8 หลัก)</span></label>
                        <input type="text" name="edit_pin" pattern="[0-9]{4,8}" maxlength="8" class="form-control"
                               value="<?= htmlspecialchars($cur_pin) ?>" required style="font-family:monospace; letter-spacing:.3em; font-size:1.05rem;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Session Timeout (นาที)</label>
                        <input type="number" name="session_minutes" min="5" max="720" class="form-control"
                               value="<?= $cur_minutes ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
            </form>
            <?php if (!is_edit_mode()): ?>
            <div class="alert alert-info" style="margin-top:16px;">
                <i class="bi bi-info-circle"></i> คุณอยู่ในโหมดดูอย่างเดียว — กรอก PIN ที่หน้า <a href="login.php" class="text-primary-link">login</a> เพื่อแก้ไขค่าได้
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-qr-code"></i> QR Code Base URL</h3>
        </div>
        <div style="padding:20px 24px;">
            <p class="text-muted" style="font-size:.85rem; margin-bottom:14px;">
                URL ที่ QR code บน label จะชี้ไป. กำหนดเป็น IP หรือชื่อเครื่อง server บนเครือข่ายภายในที่เครื่องอื่นเข้าถึงได้
                (เช่น <code>http://192.168.1.10:8000</code>). ถ้าเว้นว่าง ระบบจะใช้ URL ของหน้าที่กำลังเปิดอยู่.
            </p>
            <form action="actions.php" method="POST" class="edit-only">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_access_settings">
                <input type="hidden" name="edit_pin"        value="<?= htmlspecialchars($cur_pin) ?>">
                <input type="hidden" name="session_minutes" value="<?= $cur_minutes ?>">
                <div class="form-group">
                    <label class="form-label">Base URL</label>
                    <input type="text" name="qr_base_url" class="form-control"
                           value="<?= htmlspecialchars($cur_qrbase) ?>"
                           placeholder="http://192.168.x.x:8000">
                    <small class="text-muted" style="font-size:.72rem;">QR จะ encode <code>{base}/history.php?asset_id=...</code></small>
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> บันทึก</button>
            </form>
        </div>
    </div>

    <!-- DATABASE BACKUP -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-database-down" style="color:var(--color-accent);"></i> <?= htmlspecialchars(__('Database Backup')) ?></h3>
        </div>
        <div style="padding:20px 24px;">
            <p class="text-muted" style="font-size:.85rem; margin-bottom:14px;">
                สำรองข้อมูลไฟล์ <code>it_assets.db</code> เก็บไว้ในเครื่องหรือ External Storage เพื่อความปลอดภัย สามารถกดดาวน์โหลดไฟล์ SQLite ล่าสุดได้ทันทีในคลิกเดียว
            </p>
            <form action="export_handler.php" method="GET" style="margin:0;">
                <input type="hidden" name="type" value="backup_db">
                <button type="submit" class="btn btn-ghost" style="border:1px solid var(--color-border); font-weight:600;">
                    <i class="bi bi-download" style="color:var(--color-accent);"></i> <?= htmlspecialchars(__('Download DB Backup (.db)')) ?>
                </button>
            </form>
        </div>
    </div>

    <!-- DANGER ZONE -->
    <div class="card" style="border: 1px solid #fecaca;">
        <div class="card-header" style="background: #fef2f2; color: #dc2626; border-bottom: 1px solid #fecaca;">
            <h3 style="color: #dc2626;"><i class="bi bi-exclamation-triangle-fill"></i> Danger Zone (Factory Reset)</h3>
        </div>
        <div style="padding:20px 24px; background: #fff5f5;">
            <p style="font-size:.85rem; color: #991b1b; margin-bottom:14px;">
                <strong>คำเตือน:</strong> การกดปุ่มนี้จะเป็นการล้างข้อมูลทั้งหมดในระบบ (ลบไฟล์ฐานข้อมูล <code>it_assets.db</code>) ข้อมูล Asset, ประวัติ, พนักงาน และตั้งค่าทั้งหมดจะถูกลบทิ้งอย่างถาวร <strong>ไม่สามารถกู้คืนได้</strong>
            </p>
            <form action="actions.php" method="POST" class="edit-only" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลทั้งหมด? ข้อมูลจะหายไปถาวร!');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="factory_reset">
                <div class="form-group" style="max-width: 300px;">
                    <label class="form-label" style="color: #dc2626; font-weight: bold;">ยืนยัน PIN (เพื่อป้องกันการกดพลาด)</label>
                    <input type="password" name="reset_pin" class="form-control" required style="border-color: #fca5a5;">
                </div>
                <button type="submit" class="btn btn-danger-ghost" style="background: #dc2626; color: white; border: none; font-weight: bold;">
                    <i class="bi bi-trash-fill"></i> ล้างข้อมูลทั้งหมด (บึ้มฐานข้อมูล)
                </button>
            </form>
            <?php if (!is_edit_mode()): ?>
            <div class="alert alert-danger" style="margin-top:16px; background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5;">
                <i class="bi bi-info-circle"></i> คุณต้องปลดล็อกโหมดแก้ไขก่อนจึงจะสามารถทำ Factory Reset ได้
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php endif; ?>

<?php require_once 'includes/layout_end.php'; ?>
