<?php
// includes/layout.php
// Usage: require_once 'includes/layout.php';
// Set $pageTitle and $activeNav before including.

require_once __DIR__ . '/auth.php';
require_edit(); // Force login for the entire system
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/attention_helper.php';

$pageTitle  = $pageTitle  ?? 'IT Asset Manager';
$activeNav  = $activeNav  ?? 'dashboard';
$isEditMode = true; // Always true now
$authMins   = auth_remaining_minutes();

// Fetch sidebar badges
$repairCount = 0;
$lease30     = 0;
try {
    global $pdo;
    $repairCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='repair'")->fetchColumn();
    $lease30     = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lease_expiry IS NOT NULL AND lease_expiry <= date('now','+30 days') AND lease_expiry >= date('now')")->fetchColumn();
} catch (Exception $e) { /* silent */ }

$navMain = [
    ['id'=>'dashboard', 'label'=>__('Dashboard'),     'icon'=>'bi-speedometer2', 'href'=>'index.php'],
    ['id'=>'users',     'label'=>__('Users & Assets'),'icon'=>'bi-people',       'href'=>'users.php'],
];
$navManage = [
    ['id'=>'exchange',        'label'=>__('Exchange Portal'), 'icon'=>'bi-arrow-left-right', 'href'=>'exchange.php'],
    ['id'=>'handover_portal', 'label'=>__('Handover Portal'), 'icon'=>'bi-file-earmark-check-fill', 'href'=>'handover_portal.php'],
    ['id'=>'incoming',        'label'=>__('Incoming Orders'), 'icon'=>'bi-truck',    'href'=>'incoming.php'],
    ['id'=>'assets',          'label'=>__('Device Assets'), 'icon'=>'bi-laptop',   'href'=>'assets.php'],
    ['id'=>'accessories',     'label'=>__('Accessories'),   'icon'=>'bi-plug',     'href'=>'accessories.php'],
    ['id'=>'software',        'label'=>__('Software Licenses'), 'icon'=>'bi-key', 'href'=>'software.php'],
    ['id'=>'consumables',     'label'=>__('Consumables'),   'icon'=>'bi-box-seam', 'href'=>'consumables.php'],
    ['id'=>'maintenance',     'label'=>__('Maintenance'),   'icon'=>'bi-wrench',   'href'=>'maintenance.php'],
];
$navSystem = [
    ['id'=>'audit',       'label'=>__('Audit Log'),     'icon'=>'bi-clock-history', 'href'=>'audit.php'],
    ['id'=>'scanner',     'label'=>__('QR Scanner'),    'icon'=>'bi-qr-code-scan',  'href'=>'scanner.php'],
    ['id'=>'settings',    'label'=>__('Settings'),      'icon'=>'bi-gear',          'href'=>'settings.php'],
];
$navMaps = [
    ['id'=>'map_view',    'label'=>__('2D Map'),        'icon'=>'bi-pin-map-fill',  'href'=>'map_view.php'],
    ['id'=>'map_mgr',     'label'=>__('Map Manager'),   'icon'=>'bi-bounding-box',  'href'=>'map_manager.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — IT Asset Manager</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= $isEditMode ? 'is-edit-mode' : 'is-view-mode' ?>">
<div class="app">

    <!-- ─── Sidebar ─── -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="logo-icon"><i class="bi bi-hdd-stack"></i></div>
            <div class="logo-text">
                Asset Manager
                <span>IT Department</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label"><?= htmlspecialchars(__('Overview')) ?></div>
            <?php foreach ($navMain as $item): ?>
                <a href="<?= $item['href'] ?>" class="nav-item <?= $activeNav === $item['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <?= $item['label'] ?>
                    <?php if ($item['id'] === 'dashboard' && $repairCount > 0): ?>
                        <span class="nav-badge"><?= $repairCount ?></span>
                    <?php elseif ($item['id'] === 'dashboard' && $lease30 > 0): ?>
                        <span class="nav-badge" style="background:#d97706;"><?= $lease30 ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

            <div class="nav-section-label"><?= htmlspecialchars(__('Inventory')) ?></div>
            <?php foreach ($navManage as $item): ?>
                <a href="<?= $item['href'] ?>" class="nav-item <?= $activeNav === $item['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>

            <div class="nav-section-label"><?= htmlspecialchars(__('System')) ?></div>
            <?php foreach ($navSystem as $item): ?>
                <a href="<?= $item['href'] ?>" class="nav-item <?= $activeNav === $item['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>

            <div style="margin: 20px 0 10px 0; font-size: 0.75rem; text-transform: uppercase; color: rgba(255,255,255,0.4); font-weight: 700; padding: 0 16px; letter-spacing: 0.5px;"><?= __('Tools') ?></div>
            <a href="import.php" class="nav-item <?= $activeNav === 'import' ? 'active' : '' ?>">
                <i class="bi bi-cloud-arrow-up"></i> <?= __('Import & Bulk Update') ?>
            </a>
            <a href="export.php" class="nav-item <?= $activeNav === 'export' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-spreadsheet"></i> <?= __('Export & Labels') ?>
            </a>

            <div style="margin: 20px 0 10px 0; font-size: 0.75rem; text-transform: uppercase; color: rgba(255,255,255,0.4); font-weight: 700; padding: 0 16px; letter-spacing: 0.5px;">Mapping</div>
            <?php foreach ($navMaps as $item): ?>
                <a href="<?= $item['href'] ?>" class="nav-item <?= $activeNav === $item['id'] ? 'active' : '' ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div style="font-weight:600;color:#374151;font-size:.8rem;">IT Asset System</div>
            <div style="margin-top:2px;">v1.0 &bull; Local Network</div>
        </div>
    </aside>

    <!-- Mobile Sidebar Toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- ─── Main Area ─── -->
    <div class="main">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-title">
                <?= htmlspecialchars($pageTitle) ?>
                <small id="topbar-subtitle"></small>
            </div>

            <!-- Universal Scanner Input -->
            <?php if (!in_array($activeNav, ['map_view', 'map_mgr', 'settings'])): ?>
            <form id="scannerForm" onsubmit="return handleScan(event)" style="margin-left:auto;">
                <div class="scanner-bar">
                    <i class="bi bi-upc-scan"></i>
                    <input id="scannerInput" type="text"
                           placeholder="Search current page or scan..."
                           autocomplete="off"
                           autofocus>
                </div>
            </form>
            <?php endif; ?>

            <?php
            // Unified bell feed — uses cached result if dashboard already fetched it
            if (!isset($bellItems)) {
                $bellItems = getAttentionList($pdo);
            }
            $totalAlerts = count($bellItems);
            $bellShow    = array_slice($bellItems, 0, 6);
            $alertsHash  = md5(serialize(array_column($bellItems, 'title')));
            $isAlertsRead = isset($_COOKIE['alerts_read_hash']) && $_COOKIE['alerts_read_hash'] === $alertsHash;
            $showBadge   = $totalAlerts > 0 && !$isAlertsRead;
            ?>

            <!-- Language Switcher -->
            <div style="position:relative; margin-right:8px;">
                <?php
                $url = strtok($_SERVER["REQUEST_URI"], '?');
                $q_en = $_GET; $q_en['lang'] = 'en';
                $q_th = $_GET; $q_th['lang'] = 'th';
                $link_en = $url . '?' . http_build_query($q_en);
                $link_th = $url . '?' . http_build_query($q_th);
                $is_th = ($GLOBALS['current_lang'] === 'th');
                ?>
                <button onclick="document.getElementById('langDropdown').classList.toggle('show')"
                        style="background:none; border:1px solid var(--color-border-soft); cursor:pointer; padding:4px 10px; border-radius:8px; font-size:.8rem; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <i class="bi bi-globe"></i> <?= $is_th ? 'TH' : 'EN' ?>
                </button>
                <div id="langDropdown" class="notif-dropdown" style="width:120px; right:0; left:auto; padding:6px; top:45px;">
                    <a href="<?= htmlspecialchars($link_en) ?>" style="display:block; padding:8px 12px; text-decoration:none; color:var(--color-text); border-radius:6px; background:<?= !$is_th ? 'var(--color-bg)' : 'transparent' ?>;">🇬🇧 English</a>
                    <a href="<?= htmlspecialchars($link_th) ?>" style="display:block; padding:8px 12px; text-decoration:none; color:var(--color-text); border-radius:6px; background:<?= $is_th ? 'var(--color-bg)' : 'transparent' ?>;">🇹🇭 ไทย</a>
                </div>
            </div>

            <!-- Auth Pill -->
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="auth-pill edit" style="background:#f1f5f9; color:#475569; border-color:#e2e8f0;" title="<?= htmlspecialchars(__('Secure')) ?>">
                    <i class="bi bi-shield-lock-fill" style="color:#10b981;"></i> <?= htmlspecialchars(__('Secure')) ?>
                    <a href="logout.php" style="margin-left:4px; color:#ef4444;" title="<?= htmlspecialchars(__('Logout')) ?>"><i class="bi bi-box-arrow-right"></i></a>
                </span>
            </div>

            <!-- Notification Bell -->
            <div style="position:relative;">
                <button onclick="document.getElementById('notifDropdown').classList.toggle('show')" 
                        style="background:none; border:none; cursor:pointer; padding:8px 12px; border-radius:8px; position:relative; transition:.15s;"
                        onmouseenter="this.style.background='#f1f5f9'" onmouseleave="this.style.background='none'">
                    <i id="notifBellIcon" class="bi bi-bell" style="font-size:1.25rem; color:<?= $showBadge ? '#dc2626' : '#64748b' ?>;"></i>
                    <?php if ($showBadge): ?>
                    <span id="notifBadge" style="position:absolute; top:4px; right:6px; background:#dc2626; color:#fff; font-size:.6rem; font-weight:700; padding:1px 5px; border-radius:9px; min-width:16px; text-align:center; line-height:1.4; animation: pulse 2s infinite;">
                        <?= $totalAlerts ?>
                    </span>
                    <?php endif; ?>
                </button>

                <div id="notifDropdown" class="notif-dropdown">
                    <div style="padding:14px 18px; border-bottom:1px solid var(--color-border-soft); display:flex; align-items:center; justify-content:space-between; gap:10px;">
                        <span style="font-weight:700; font-size:.92rem; white-space:nowrap;"><i class="bi bi-bell" style="margin-right:6px;"></i> <?= htmlspecialchars(__('Needs Attention')) ?></span>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <?php if ($showBadge): ?>
                            <button onclick="markAlertsRead('<?= $alertsHash ?>')" style="background:none; border:none; cursor:pointer; color:var(--color-primary); font-size:.75rem; padding:2px 6px; border-radius:4px; text-decoration:none;" title="<?= htmlspecialchars(__('Mark as Read')) ?>">
                                <i class="bi bi-check2-all" style="font-size:1rem;"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($totalAlerts > 0): ?>
                            <span style="background:#fef2f2; color:#dc2626; font-size:.72rem; font-weight:700; padding:2px 8px; border-radius:8px; white-space:nowrap;"><?= $totalAlerts ?> <?= htmlspecialchars(__('items')) ?></span>
                            <?php else: ?>
                            <span style="color:#16a34a; font-size:.78rem; font-weight:600; white-space:nowrap;"><i class="bi bi-check-circle"></i> <?= htmlspecialchars(__('Everything is fine')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($totalAlerts === 0): ?>
                    <div style="padding:28px 20px; text-align:center; color:var(--color-text-muted);">
                        <i class="bi bi-check-circle" style="font-size:1.8rem; color:#16a34a; display:block; margin-bottom:6px;"></i>
                        <div style="font-size:.85rem;"><?= htmlspecialchars(__('Everything is fine. No items need attention right now.')) ?></div>
                    </div>
                    <?php else: ?>
                    <div style="max-height:360px; overflow-y:auto;">
                        <?php foreach ($bellShow as $it):
                            [$dCls, $dTxt] = attDaysLabel((int)$it['days']);
                            $dColor = $dCls === 'overdue' ? '#991b1b' : ($dCls === 'soon' ? '#92400e' : '#64748b');
                            $dBg    = $dCls === 'overdue' ? '#fee2e2' : ($dCls === 'soon' ? '#fef3c7' : '#f1f5f9');
                        ?>
                        <a href="<?= htmlspecialchars($it['url']) ?>" style="display:flex; align-items:center; gap:11px; padding:11px 18px; text-decoration:none; border-bottom:1px solid var(--color-border-soft); color:inherit; transition:background .1s;" onmouseenter="this.style.background='var(--color-bg)'" onmouseleave="this.style.background='transparent'">
                            <div style="width:32px; height:32px; border-radius:8px; background:<?= $it['bg'] ?>; color:<?= $it['color'] ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="bi <?= $it['icon'] ?>" style="font-size:.95rem;"></i>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-weight:600; font-size:.85rem; color:var(--color-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($it['title']) ?></div>
                                <div style="font-size:.72rem; color:var(--color-text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($it['sub']) ?></div>
                            </div>
                            <span style="font-size:.7rem; font-weight:700; padding:3px 8px; border-radius:99px; background:<?= $dBg ?>; color:<?= $dColor ?>; white-space:nowrap; flex-shrink:0; font-family:'SFMono-Regular',ui-monospace,monospace;"><?= $dTxt ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalAlerts > count($bellShow)): ?>
                    <a href="audit.php" style="display:block; padding:12px; text-align:center; background:#f8fafc; font-size:.8rem; font-weight:600; color:var(--color-primary); text-decoration:none;">
                        <?= htmlspecialchars(__('View all')) ?> <?= $totalAlerts ?> <?= htmlspecialchars(__('items')) ?> →
                    </a>
                    <?php endif; ?>
                    <a href="index.php" style="display:block; padding:12px; text-align:center; background:#f8fafc; font-size:.8rem; font-weight:600; color:var(--color-primary); text-decoration:none;">
                        <?= htmlspecialchars(__('Go to Dashboard')) ?> →
                    </a>
                    <div style="display:flex; border-top:1px solid var(--color-border-soft); background:var(--color-bg);">
                        <a href="settings.php?tab=alerts" style="padding:9px 16px; font-size:.78rem; color:var(--color-text-muted); text-decoration:none; border-left:1px solid var(--color-border-soft);" title="<?= htmlspecialchars(__('Settings')) ?>" onmouseenter="this.style.background='#fff'; this.style.color='var(--color-accent)'" onmouseleave="this.style.background='transparent'; this.style.color='var(--color-text-muted)'">
                            <i class="bi bi-gear"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <style>
                @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.6; } }
            </style>
            <script>
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    const dd = document.getElementById('notifDropdown');
                    if (dd && !e.target.closest('[onclick*="notifDropdown"]') && !e.target.closest('#notifDropdown')) {
                        dd.classList.remove('show');
                    }
                    
                    // Close action dropdowns
                    if (!e.target.closest('.action-dropdown')) {
                        document.querySelectorAll('.action-dropdown.open').forEach(el => el.classList.remove('open'));
                    }
                    
                    if (!e.target.closest('#langDropdown') && !e.target.closest('[onclick*="langDropdown"]')) {
                        document.getElementById('langDropdown').classList.remove('show');
                    }
                });

                function markAlertsRead(hash) {
                    document.cookie = "alerts_read_hash=" + hash + "; path=/; max-age=86400"; // Expires in 1 day or when hash changes
                    const badge = document.getElementById('notifBadge');
                    if (badge) badge.style.display = 'none';
                    const icon = document.getElementById('notifBellIcon');
                    if (icon) icon.style.color = '#64748b';
                    
                    // Optionally, hide the button itself
                    const btn = event.currentTarget;
                    if (btn) btn.style.display = 'none';
                }
                
                function toggleActionDropdown(btn) {
                    const parent = btn.closest('.action-dropdown');
                    const wasOpen = parent.classList.contains('open');
                    document.querySelectorAll('.action-dropdown.open').forEach(el => el.classList.remove('open'));
                    if (!wasOpen) parent.classList.add('open');
                }
            </script>
        </header>

        <!-- Page Content Slot -->
        <main class="page" id="pageContent">
