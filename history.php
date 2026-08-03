<?php
// history.php — Asset Detail Page (action center)
require_once 'includes/db_connect.php';

$asset_id = (int)($_GET['asset_id'] ?? 0);
if (!$asset_id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("
    SELECT a.*, l.location_name, u.full_name AS assigned_to, u.user_id AS assigned_user_id
    FROM   assets a
    LEFT JOIN locations l ON a.location_id = l.location_id
    LEFT JOIN users     u ON a.user_id     = u.user_id
    WHERE  a.asset_id = ?
");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) { header('Location: index.php'); exit; }

// Lifecycle log
$stmtLog = $pdo->prepare("
    SELECT l.*, uf.full_name AS from_name, ut.full_name AS to_name
    FROM   logs l
    LEFT JOIN users uf ON l.from_user_id = uf.user_id
    LEFT JOIN users ut ON l.to_user_id   = ut.user_id
    WHERE  l.asset_id = ? ORDER BY l.created_at DESC
");
$stmtLog->execute([$asset_id]);
$logs = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

// Linked accessories (this asset is parent)
$linkedAcc = $pdo->prepare("
    SELECT accessory_id, category, name, serial_number, status, user_id
    FROM   accessories
    WHERE  parent_asset_id = ?
    ORDER BY category, name
");
$linkedAcc->execute([$asset_id]);
$linkedAcc = $linkedAcc->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns for modals
$users     = $pdo->query("SELECT user_id, full_name, department FROM users WHERE resigned_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch map info for this asset's location
$mapZone = null;
if (!empty($asset['location_id'])) {
    $stmtMap = $pdo->prepare("
        SELECT m.image_path, mz.polygon_json, m.map_id
        FROM map_zones mz
        JOIN maps m ON mz.map_id = m.map_id
        WHERE mz.location_id = ? LIMIT 1
    ");
    $stmtMap->execute([$asset['location_id']]);
    $mapZone = $stmtMap->fetch(PDO::FETCH_ASSOC);
}

function tlIcon($action) {
    $map = [
        'assign' => ['bi-person-plus',       'assign'],
        'return' => ['bi-arrow-return-left', 'return'],
        'repair' => ['bi-tools',             'repair'],
        'retire' => ['bi-archive',           'retire'],
        'update' => ['bi-pencil',            'update'],
        'import' => ['bi-download',          'import'],
    ];
    [$icon, $cls] = $map[$action] ?? ['bi-circle', 'default'];
    return "<div class='tl-icon {$cls}'><i class='bi {$icon}'></i></div>";
}
function badge($status) {
    $safe = htmlspecialchars($status);
    return "<span class='badge badge-{$safe}'>" . ucfirst(str_replace('_', ' ', $status)) . "</span>";
}

$canRepair       = !in_array($asset['status'], ['repair','retired','lost']);
$canFix          = $asset['status'] === 'repair';
$canDeploy       = in_array($asset['status'], ['in_stock','repair']);
$canReturn       = $asset['status'] === 'deployed';
$canRetire       = !in_array($asset['status'], ['retired','lost']);
$canVendor       = $asset['status'] === 'in_stock';
$canRma          = in_array($asset['status'], ['in_stock','repair','deployed']);

$pageTitle = 'Asset Detail';
$activeNav = 'dashboard';
require_once 'includes/layout.php';
?>

<!-- Styles for this page are in assets/style.css (.detail-grid, .adv-grid, .action-bar, .linked-acc-row) -->


<style>
        :root {
            --color-bg-body: #f1f5f9;
            --color-card-bg: #ffffff;
            --color-border-soft: #e2e8f0;
            --color-text-main: #1e293b;
            --color-text-muted: #64748b;
            --color-primary: #3b82f6;
        }
        body { background-color: var(--color-bg-body); font-family: 'Inter', system-ui, sans-serif; color: var(--color-text-main); }
        
        /* Navbar matching system */
        .navbar-top { background: white; border-bottom: 1px solid var(--color-border-soft); padding: 12px 24px; display:flex; align-items:center; }
        
        .dashboard-card { 
            background: var(--color-card-bg); 
            border: 1px solid var(--color-border-soft); 
            border-radius: 12px; 
            padding: 20px; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); 
            height: 100%;
            transition: all 0.2s ease;
        }
        .dashboard-card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        
        .dash-header { 
            font-size: 0.75rem; 
            text-transform: uppercase; 
            letter-spacing: 1.5px; 
            color: var(--color-text-muted); 
            font-weight: 700; 
            margin-bottom: 16px; 
            display:flex; 
            align-items:center; 
            gap: 8px; 
        }
        .dash-value { font-size: 0.95rem; color: var(--color-text-main); font-weight: 600; margin-top: 2px;}
        .dash-label { font-size: 0.75rem; color: var(--color-text-muted); font-weight: 500; }
        
        .grid-container { display: grid; gap: 20px; }
        .main-grid { grid-template-columns: 2fr 1fr; }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }
        
        .kv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px 16px; }
        
        .hero-title { font-size: 1.75rem; font-weight: 800; margin: 0; letter-spacing: -0.5px; }
        .hero-subtitle { font-size: 0.95rem; color: var(--color-text-muted); font-weight: 500; margin-top: 4px; }
        
        .metrics-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .metric-box { 
            background: #f8fafc; 
            border: 1px solid var(--color-border-soft); 
            border-radius: 12px; 
            padding: 16px; 
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            text-align: center; 
        }
        .metric-value { font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-top: 4px; line-height: 1; }
        .metric-title { font-size: 0.75rem; text-transform: uppercase; color: var(--color-text-muted); font-weight: 700; letter-spacing: 1px;}
        
        .floorplan-ph { 
            background: #0f172a; 
            border: 1px solid #334155; 
            border-radius: 8px; 
            height: 200px; 
            display: flex; flex-direction:column; align-items: center; justify-content: center; 
            color: #94a3b8; font-size: 0.9rem; font-weight: 500;
            position: relative;
            overflow: hidden;
        }
        
        .btn-action-bar { border-radius: 20px; padding: 6px 16px; font-weight: 600; font-size: 0.85rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05);}
        
        .badge-status { padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.75rem; text-transform: uppercase; letter-spacing:1px;}
        .badge-in_stock { background: #dcfce7; color: #166534; }
        .badge-deployed { background: #dbeafe; color: #1e40af; }
        .badge-repair { background: #fef3c7; color: #92400e; }
        .badge-retired { background: #f1f5f9; color: #475569; }
        .badge-lost { background: #fee2e2; color: #991b1b; }
        
        /* Utility classes from Bootstrap */
        .d-flex { display: flex; }
        .flex-column { flex-direction: column; }
        .justify-content-between { justify-content: space-between; }
        .align-items-center { align-items: center; }
        .align-items-end { align-items: flex-end; }
        .align-items-start { align-items: flex-start; }
        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        .mt-1 { margin-top: 0.25rem; }
        .mt-2 { margin-top: 0.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .me-3 { margin-right: 1rem; }
        .ps-3 { padding-left: 1rem; }
        .p-4 { padding: 1.5rem; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 1rem; }
        .gap-4 { gap: 1.5rem; }
        .gap-5 { gap: 3rem; }
        .flex-wrap { flex-wrap: wrap; }
        .text-primary { color: var(--color-primary); }
        .text-danger { color: #dc2626; }
        .text-muted { color: var(--color-text-muted); }
        .text-dark { color: #0f172a; }
        .fw-bold { font-weight: 700; }
        .fw-bolder { font-weight: 800; }
        .fw-medium { font-weight: 500; }
        .fs-5 { font-size: 1.25rem; }
        .opacity-75 { opacity: 0.75; }
        .text-decoration-none { text-decoration: none; }
        .position-relative { position: relative; }
        .position-absolute { position: absolute; }
        .bg-primary { background-color: var(--color-primary); }
        .rounded-circle { border-radius: 50%; }
        .text-center { text-align: center; }
        .container-fluid { width: 100%; margin-right: auto; margin-left: auto; }
        @media (min-width: 1200px) { .p-xl-5 { padding: 3rem !important; } }
    </style>
<div class="container-fluid p-4 p-xl-5" style="max-width: 1400px; margin: 0 auto;">
    
    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <div class="d-flex align-items-center gap-3 mb-2">
                <h1 class="hero-title"><?= htmlspecialchars($asset['hostname'] ?: 'No Hostname') ?></h1>
                <span class="badge-status badge-<?= $asset['status'] ?>">
                    <i class="bi bi-circle-fill me-1" style="font-size:0.5rem; vertical-align:middle;"></i> 
                    <?= str_replace('_', ' ', $asset['status']) ?>
                </span>
            </div>
            <div class="hero-subtitle">
                <i class="bi bi-tag-fill me-1"></i> <?= htmlspecialchars($asset['category']) ?> &nbsp;&bull;&nbsp; 
                <?= htmlspecialchars($asset['model'] ?: 'Unknown Model') ?>
            </div>
        </div>
        <!-- Action Bar -->
    <div class="action-bar edit-only">
        <button onclick="openEdit()" class="btn btn-sm" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">
            <i class="bi bi-pencil"></i> Edit
        </button>

        <?php if ($canDeploy): ?>
        <button onclick="openOut('assign')" class="btn btn-sm" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">
            <i class="bi bi-person-plus"></i> <?= htmlspecialchars(__('Deploy')) ?>
        </button>
        <button onclick="openOut('borrow')" class="btn btn-sm" style="background:#faf5ff;color:#9333ea;border:1px solid #e9d5ff;">
            <i class="bi bi-clock"></i> <?= htmlspecialchars(__('Loan')) ?>
        </button>
        <?php endif; ?>

        <?php if ($canReturn): ?>
        <form action="actions.php" method="POST" style="margin:0;display:inline-block;"
              data-confirm="คืน asset นี้กลับเข้า stock?" data-confirm-title="Return Asset" data-confirm-kind="info">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="return">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <input type="hidden" name="user_id" value="<?= $asset['user_id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1px solid #dcfce7;">
                <i class="bi bi-arrow-return-left"></i> <?= htmlspecialchars(__('Return IN')) ?>
            </button>
        </form>
        <?php endif; ?>

        <?php if ($canRepair): ?>
        <button onclick="openRepairSend()" class="btn btn-sm" style="background:#fffbeb;color:#d97706;border:1px solid #fef3c7;">
            <i class="bi bi-wrench"></i> <?= htmlspecialchars(__('Send Repair')) ?>
        </button>
        <?php endif; ?>

        <?php if ($canFix): ?>
        <button onclick="openRepairReturn()" class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1px solid #dcfce7;">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars(__('Mark Fixed')) ?>
        </button>
        <?php endif; ?>

        <?php if ($canRma): ?>
        <button onclick="openRma()" class="btn btn-sm" style="background:#fff7ed;color:#ea580c;border:1px solid #ffedd5;">
            <i class="bi bi-arrow-repeat"></i> RMA
        </button>
        <?php endif; ?>

        <?php if ($canVendor): ?>
        <button onclick="openVendor()" class="btn btn-sm" style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;">
            <i class="bi bi-truck"></i> Vendor
        </button>
        <?php endif; ?>

        <?php if ($canRetire): ?>
        <form action="actions.php" method="POST" style="margin:0;display:inline-block;"
              data-confirm="Retire asset นี้?" data-confirm-title="Retire Asset">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_retired">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <button type="submit" class="btn btn-sm" style="background:#f8fafc;color:#64748b;border:1px solid #cbd5e1;">
                <i class="bi bi-archive"></i> <?= htmlspecialchars(__('Retire')) ?>
            </button>
        </form>
        <?php endif; ?>

        <div style="width:1px;height:22px;background:var(--color-border);margin:0 4px;"></div>

        <a href="print_labels.php?ids=<?= $asset_id ?>" target="_blank" class="btn btn-sm btn-ghost">
            <i class="bi bi-printer"></i> <?= htmlspecialchars(__('Label')) ?>
        </a>
    </div>
</div>

    <div class="grid-container main-grid">
        <!-- Left Column: Core Data -->
        <div class="d-flex flex-column gap-4">
            
            <div class="dashboard-card">
                <div class="dash-header"><i class="bi bi-fingerprint text-primary fs-6"></i> Hardware Identity & Specifications</div>
                <div class="kv-grid">
                    <div>
                        <div class="dash-label">Serial Number</div>
                        <div class="dash-value"><?= htmlspecialchars($asset['serial_number'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="dash-label">MAC Address</div>
                        <div class="dash-value font-monospace"><?= htmlspecialchars($asset['mac_address'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="dash-label">Service Tag</div>
                        <div class="dash-value"><?= htmlspecialchars($asset['service_tag'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="dash-label">Ownership</div>
                        <div class="dash-value"><?= htmlspecialchars($asset['ownership_type'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="dash-label">Purchase Date</div>
                        <div class="dash-value"><?= htmlspecialchars($asset['purchase_date'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="dash-label">End of Life</div>
                        <div class="dash-value"><?= htmlspecialchars($asset['end_of_life_date'] ?: '-') ?></div>
                    </div>
                </div>
            </div>

            <div class="dashboard-card border-primary" style="background: #f0f9ff;">
                <div class="dash-header text-primary"><i class="bi bi-geo-alt-fill fs-6"></i> Current Assignment</div>
                <div class="d-flex align-items-center gap-5 flex-wrap">
                    <div>
                        <div class="dash-label text-primary opacity-75">Assigned To</div>
                        <div class="dash-value text-primary fw-bolder fs-5">
                            <?= htmlspecialchars($asset['assigned_to'] ?: 'Unassigned') ?>
                        </div>
                    </div>
                    <div>
                        <div class="dash-label text-primary opacity-75">Location</div>
                        <div class="dash-value text-dark fw-bold fs-5">
                            <?= htmlspecialchars($asset['location_name'] ?: 'No Location') ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="metrics-row">
                <div class="metric-box">
                    <div class="metric-title">Lifecycle Events</div>
                    <div class="metric-value text-primary"><?= count($logs) ?></div>
                </div>
                <div class="metric-box">
                    <div class="metric-title">Assignments</div>
                    <?php 
                        $assignCount = 0;
                        foreach($logs as $l) if($l['action_type'] === 'assign') $assignCount++;
                    ?>
                    <div class="metric-value"><?= $assignCount ?></div>
                </div>
                <div class="metric-box">
                    <div class="metric-title">Asset Age</div>
                    <div class="metric-value">
                        <?php 
                        if (!empty($asset['purchase_date'])) {
                            $diff = (new DateTime())->diff(new DateTime($asset['purchase_date']));
                            echo $diff->y > 0 ? $diff->y . ' <span style="font-size:0.9rem;">yrs</span>' : $diff->m . ' <span style="font-size:0.9rem;">mos</span>';
                        } elseif (!empty($asset['purchase_year'])) {
                            echo (date('Y') - $asset['purchase_year']) . ' <span style="font-size:0.9rem;">yrs</span>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </div>
                </div>
                <div class="metric-box" style="border-color: #fca5a5; background: #fef2f2;">
                    <div class="metric-title text-danger">Days to EOL</div>
                    <div class="metric-value text-danger">
                        <?php
                        if (!empty($asset['end_of_life_date'])) {
                            $diff = (new DateTime('today'))->diff(new DateTime($asset['end_of_life_date']));
                            echo $diff->format('%r%a');
                        } else {
                            echo '-';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Map & Extras -->
        <div class="d-flex flex-column gap-4">
            <!-- Premium Dark Map -->
            <div class="dashboard-card" style="background:#1e293b; color:white; border:none; padding: 24px;">
                <div class="dash-header" style="color:#94a3b8;"><i class="bi bi-pin-map-fill text-info fs-6"></i> 2D Location Map</div>
                
                <?php if ($mapZone && !empty($mapZone['polygon_json'])): 
                    $pts = json_decode($mapZone['polygon_json'], true);
                    $cx = 0; $cy = 0;
                    if (is_array($pts) && count($pts) > 0) {
                        foreach($pts as $p) { $cx += $p['x']; $cy += $p['y']; }
                        $cx /= count($pts); $cy /= count($pts);
                    }
                    $pointsStr = implode(' ', array_map(function($p){return $p['x'].','.$p['y'];}, $pts));
                ?>
                <div class="mb-3" style="position:relative; width:100%; height:200px; overflow:hidden; background:#0f172a; border-radius:8px; border:1px solid #334155;">
                    <div style="position:absolute; left:50%; top:50%; transform:translate(-<?= $cx ?>px, -<?= $cy ?>px);">
                        <img src="<?= htmlspecialchars($mapZone['image_path']) ?>" style="display:block; max-width:none; opacity:0.8;" alt="Floor Plan">
                        <svg style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;">
                            <polygon points="<?= $pointsStr ?>" fill="rgba(37,99,235,0.3)" stroke="#3b82f6" stroke-width="2"/>
                        </svg>
                        <div style="position:absolute; left:<?= $cx ?>px; top:<?= $cy ?>px; transform:translate(-50%, -100%);">
                            <i class="bi bi-geo-alt-fill text-danger" style="font-size:2.2rem; filter:drop-shadow(0 4px 6px rgba(0,0,0,0.5));"></i>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="floorplan-ph mb-3">
                    <i class="bi bi-map text-secondary mb-2" style="font-size:2rem;"></i>
                    <?= htmlspecialchars(__('No Map Configured for this Location')) ?>
                </div>
                <?php endif; ?>
                
                <div style="font-size:0.85rem; color:#cbd5e1;">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Physical Location:</span>
                        <span class="text-white fw-bold"><?= htmlspecialchars($asset['location_name'] ?: 'Unknown') ?></span>
                    </div>
                </div>
            </div>

            <!-- Sleek Timeline -->
            <div class="dashboard-card">
                <div class="dash-header"><i class="bi bi-clock-history fs-6 text-secondary"></i> Recent Audit Log</div>
                <div class="mt-3">
                    <?php if(empty($logs)): ?>
                        <div class="text-muted text-center py-3" style="font-size:0.85rem;">No activity recorded yet.</div>
                    <?php else: ?>
                        <div class="position-relative ps-3" style="border-left: 2px solid #e2e8f0;">
                            <?php foreach(array_slice($logs, 0, 4) as $log): ?>
                                <div class="mb-4 position-relative">
                                    <div class="position-absolute bg-primary rounded-circle" style="width:10px; height:10px; left:-22px; top:4px; border:2px solid white;"></div>
                                    <div class="text-primary fw-bold" style="font-size:0.85rem;">
                                        <?php
                                        $from = $log['from_name'] ? htmlspecialchars($log['from_name']) : null;
                                        $to   = $log['to_name']   ? htmlspecialchars($log['to_name'])   : null;
                                        switch ($log['action_type']) {
                                            case 'assign':  echo 'Assigned to <strong>' . $to . '</strong>'; break;
                                            case 'return':  echo 'Returned to stock' . ($from ? ' (from <strong>' . $from . '</strong>)' : ''); break;
                                            case 'repair':  echo 'Sent for repair'; break;
                                            case 'retire':  echo 'Marked as retired'; break;
                                            case 'update':  echo 'Asset record updated'; break;
                                            case 'import':  echo 'Imported into system'; break;
                                            default:        echo strtoupper(htmlspecialchars($log['action_type']));
                                        }
                                        ?>
                                    </div>
                                    <?php if (!empty($log['note'])): ?>
                                    <div class="text-dark fw-medium mt-1" style="font-size:0.9rem;">
                                        <?= htmlspecialchars($log['note']) ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-muted mt-1" style="font-size:0.75rem;">
                                        <?php
                                            $dt = new DateTime($log['created_at'], new DateTimeZone('UTC'));
                                            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
                                            echo '<i class="bi bi-clock"></i> ' . $dt->format('M d, Y H:i');
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-center mt-2">
                    <a href="#" class="text-decoration-none fw-bold" style="font-size:0.85rem;">View Full History &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════ MODALS ═══════════════════ -->

<!-- Edit -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:560px;max-width:94vw;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-pencil" style="color:#2563eb;"></i> Edit Asset</h3>
            <button onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="edit_asset">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Category *</label>
                    <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($asset['category']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Hostname</label>
                    <input type="text" name="hostname" class="form-control" value="<?= htmlspecialchars($asset['hostname'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control" value="<?= htmlspecialchars($asset['serial_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Ownership *</label>
                    <select name="ownership_type" class="form-control form-select" required>
                        <?php foreach (['Buy','Rent','Borrow'] as $o): ?>
                        <option value="<?= $o ?>" <?= $asset['ownership_type']===$o?'selected':'' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control form-select">
                        <?php foreach (['in_stock','deployed','repair','retired','lost'] as $s): ?>
                        <option value="<?= $s ?>" <?= $asset['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-control form-select">
                        <option value="">— None —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['location_id'] ?>" <?= $asset['location_id']==$loc['location_id']?'selected':'' ?>><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:18px;border-top:1px dashed #e8eaed;padding-top:14px;">
                <div style="font-size:.78rem;font-weight:600;color:var(--color-text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;">Advanced</div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= htmlspecialchars($asset['model'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">Purchase Year</label><input type="number" name="purchase_year" class="form-control" value="<?= htmlspecialchars($asset['purchase_year'] ?? '') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Service Tag</label><input type="text" name="service_tag" class="form-control" value="<?= htmlspecialchars($asset['service_tag'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">MAC Address</label><input type="text" name="mac_address" class="form-control" value="<?= htmlspecialchars($asset['mac_address'] ?? '') ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-control" value="<?= htmlspecialchars($asset['purchase_date'] ?? '') ?>"></div>
                    <div class="form-group"><label class="form-label">EOL Date</label><input type="date" name="end_of_life_date" class="form-control" value="<?= htmlspecialchars($asset['end_of_life_date'] ?? '') ?>"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Note</label>
                <input type="text" name="note" class="form-control" value="<?= htmlspecialchars($asset['note'] ?? '') ?>">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Out (Deploy / Loan) -->
<div id="outModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="outTitle" style="font-size:1rem;font-weight:600;"></h3>
            <button onclick="document.getElementById('outModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   id="outAction" value="">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <div class="form-group">
                <label class="form-label">Assign To User</label>
                <select name="user_id_direct" class="form-control form-select" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>"><?= htmlspecialchars($u['full_name']) ?><?= $u['department'] ? ' ('.$u['department'].')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="outBorrowGroup" class="form-group" style="display:none;">
                <label class="form-label">กำหนดคืน <span style="color:#9ca3af;font-weight:400;">— optional</span></label>
                <input type="date" name="borrow_due_date" id="outDueDate" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('outModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" id="outBtn" class="btn btn-primary"></button>
            </div>
        </form>
    </div>
</div>

<!-- Repair Send -->
<div id="repairSendModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:440px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-wrench" style="color:#d97706;"></i> ส่งซ่อม</h3>
            <button onclick="document.getElementById('repairSendModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="set_repair">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <div class="form-group"><label class="form-label">ร้าน / Vendor</label><input type="text" name="repair_shop" class="form-control" placeholder="ชื่อร้านซ่อม"></div>
            <div class="form-group"><label class="form-label">ETA</label><input type="date" name="repair_eta" class="form-control" min="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label class="form-label">อาการ / หมายเหตุ</label><textarea name="repair_note" class="form-control" rows="3"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('repairSendModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-wrench"></i> ส่งซ่อม</button>
            </div>
        </form>
    </div>
</div>

<!-- Repair Return -->
<div id="repairReturnModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:420px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-check-circle" style="color:#16a34a;"></i> รับกลับจากซ่อม</h3>
            <button onclick="document.getElementById('repairReturnModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="set_fixed">
            <input type="hidden" name="asset_id" value="<?= $asset_id ?>">
            <div class="form-group"><label class="form-label">หมายเหตุ (อะไรซ่อม / ค่าใช้จ่าย)</label><textarea name="completion_note" class="form-control" rows="3"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('repairReturnModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-check-circle"></i> ยืนยันรับกลับ</button>
            </div>
        </form>
    </div>
</div>

<!-- Return to Vendor -->
<div id="vendorModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:440px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-truck" style="color:#e11d48;"></i> ส่งคืน Vendor</h3>
            <button onclick="document.getElementById('vendorModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action"   value="return_to_vendor">
            <input type="hidden" name="asset_ids" value="<?= $asset_id ?>">
            <div class="form-row">
                <div class="form-group"><label class="form-label">วันที่ส่ง</label><input type="date" name="vendor_return_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group"><label class="form-label">ส่งให้ใคร</label><input type="text" name="vendor_return_to" class="form-control" placeholder="ชื่อ vendor"></div>
            </div>
            <div class="form-group"><label class="form-label">วิธีการส่ง</label><input type="text" name="vendor_return_method" class="form-control" placeholder="เช่น Kerry, ส่งเอง"></div>
            <div class="form-group"><label class="form-label">หมายเหตุ</label><textarea name="note" class="form-control" rows="2"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('vendorModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-truck"></i> ส่งคืน</button>
            </div>
        </form>
    </div>
</div>

<!-- RMA -->
<div id="rmaModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
    <div style="background:#fff;border-radius:12px;width:460px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #e8eaed;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:1rem;font-weight:600;"><i class="bi bi-arrow-repeat" style="color:#ea580c;"></i> RMA Replacement</h3>
            <button onclick="document.getElementById('rmaModal').style.display='none'" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <form action="actions.php" method="POST" style="padding:18px 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rma_replace">
            <input type="hidden" name="old_asset_id" value="<?= $asset_id ?>">
            <div style="margin-bottom:14px; padding:10px; background:#fff7ed; border:1px solid #ffedd5; border-radius:6px; font-size:.82rem; color:#ea580c;">
                <i class="bi bi-info-circle"></i> Retire เครื่องเดิม + สร้างเครื่องใหม่ identical. ถ้าเดิม deployed อยู่ ระบบจะ assign user เดิมให้อัตโนมัติ
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">New Hostname</label><input type="text" name="hostname" class="form-control" value="<?= htmlspecialchars($asset['hostname'] ?? '') ?>"></div>
                <div class="form-group"><label class="form-label">New Serial</label><input type="text" name="serial_number" class="form-control" placeholder="SN ของเครื่องใหม่"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Service Tag</label><input type="text" name="service_tag" class="form-control"></div>
                <div class="form-group"><label class="form-label">MAC Address</label><input type="text" name="mac_address" class="form-control"></div>
            </div>
            <div class="form-group"><label class="form-label">หมายเหตุ</label><textarea name="note" class="form-control" rows="2"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('rmaModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-repeat"></i> RMA</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit()         { document.getElementById('editModal').style.display = 'flex'; }
function openRepairSend()   { document.getElementById('repairSendModal').style.display = 'flex'; }
function openRepairReturn() { document.getElementById('repairReturnModal').style.display = 'flex'; }
function openVendor()       { document.getElementById('vendorModal').style.display = 'flex'; }
function openRma()          { document.getElementById('rmaModal').style.display = 'flex'; }

function openOut(mode) {
    document.getElementById('outAction').value  = mode === 'borrow' ? 'assign_borrow' : 'assign_direct';
    document.getElementById('outTitle').textContent = mode === 'borrow' ? '↔ Loan Out' : '↑ Deploy';
    document.getElementById('outBtn').innerHTML = mode === 'borrow' ? '<i class="bi bi-clock"></i> Confirm Loan' : '<i class="bi bi-person-up"></i> Confirm Deploy';
    document.getElementById('outBtn').className = mode === 'borrow' ? 'btn' : 'btn btn-primary';
    if (mode === 'borrow') document.getElementById('outBtn').style.cssText = 'background:#9333ea;color:#fff;border:none;';
    else document.getElementById('outBtn').removeAttribute('style');
    document.getElementById('outBorrowGroup').style.display = mode === 'borrow' ? 'block' : 'none';
    document.getElementById('outDueDate').value = '';
    document.getElementById('outModal').style.display = 'flex';
}

// Backdrop close
['editModal','outModal','repairSendModal','repairReturnModal','vendorModal','rmaModal'].forEach(id => {
    const m = document.getElementById(id);
    if (m) m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});

// Load attachments
fetch('attachments.php?action=list&asset_id=<?= $asset_id ?>')
    .then(r => r.text())
    .then(html => { document.getElementById('attBox').innerHTML = html; });
</script>

<?php require_once 'includes/layout_end.php'; ?>
