<?php
// print_labels.php — Asset Label Printer (Horizontal Sticker — matches physical label)
require_once 'includes/db_connect.php';

$ids    = $_GET['ids']    ?? '';
$type   = $_GET['type']   ?? '';
$cat    = $_GET['cat']    ?? '';
$status = $_GET['status'] ?? 'all';
$own    = $_GET['own']    ?? 'all';
$loc    = $_GET['loc']    ?? 'all';

$items = [];

function build_asset_items(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $in = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT a.asset_id, a.hostname, a.serial_number, a.service_tag, a.category, a.model, a.ownership_type, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id WHERE a.asset_id IN ($in)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id'=>(int)$r['asset_id'], 'kind'=>'asset', 'name'=>$r['hostname']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??''];
    }
    return $out;
}

function build_accessory_items(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $in = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT ac.accessory_id, ac.name, ac.serial_number, ac.service_tag, ac.category, ac.model, ac.ownership_type, l.location_name FROM accessories ac LEFT JOIN locations l ON ac.location_id = l.location_id WHERE ac.accessory_id IN ($in)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id'=>(int)$r['accessory_id'], 'kind'=>'accessory', 'name'=>$r['name']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??''];
    }
    return $out;
}

function all_assets(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT a.asset_id, a.hostname, a.serial_number, a.service_tag, a.category, a.model, a.ownership_type, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id'=>(int)$r['asset_id'], 'kind'=>'asset', 'name'=>$r['hostname']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??''];
    }
    return $out;
}

function all_accessories(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT ac.accessory_id, ac.name, ac.serial_number, ac.service_tag, ac.category, ac.model, ac.ownership_type, l.location_name FROM accessories ac LEFT JOIN locations l ON ac.location_id = l.location_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id'=>(int)$r['accessory_id'], 'kind'=>'accessory', 'name'=>$r['name']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??''];
    }
    return $out;
}

if ($ids && $type !== 'accessories') {
    $items = build_asset_items($pdo, array_filter(array_map('intval', explode(',', $ids))));
} elseif ($type === 'accessories') {
    $items = $ids ? build_accessory_items($pdo, array_filter(array_map('intval', explode(',', $ids)))) : all_accessories($pdo);
} elseif ($type === 'all') {
    $items = all_assets($pdo);
} elseif ($cat) {
    if ($cat === 'ALL_ASSETS') { $items = all_assets($pdo); }
    elseif ($cat === 'ALL_ACCESSORIES') { $items = all_accessories($pdo); }
    else {
        $stmtA = $pdo->prepare("SELECT a.asset_id, a.hostname, a.serial_number, a.service_tag, a.category, a.model, a.ownership_type, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id WHERE a.category = :c");
        $stmtA->execute(['c' => $cat]);
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $r) { $items[] = ['id'=>(int)$r['asset_id'], 'kind'=>'asset', 'name'=>$r['hostname']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??'']; }
        $stmtB = $pdo->prepare("SELECT ac.accessory_id, ac.name, ac.serial_number, ac.service_tag, ac.category, ac.model, ac.ownership_type, l.location_name FROM accessories ac LEFT JOIN locations l ON ac.location_id = l.location_id WHERE ac.category = :c");
        $stmtB->execute(['c' => $cat]);
        foreach ($stmtB->fetchAll(PDO::FETCH_ASSOC) as $r) { $items[] = ['id'=>(int)$r['accessory_id'], 'kind'=>'accessory', 'name'=>$r['name']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??'']; }
    }
} elseif ($type === 'custom') {
    $whereA = []; $params = [];
    if ($status !== 'all') { $whereA[] = "a.status = :status"; $params['status'] = $status; }
    if ($own !== 'all') { $whereA[] = "a.ownership_type = :own"; $params['own'] = $own; }
    if ($loc !== 'all') { $whereA[] = "a.location_id = :loc"; $params['loc'] = (int)$loc; }
    $w = $whereA ? "WHERE " . implode(" AND ", $whereA) : "";
    $stmt = $pdo->prepare("SELECT a.asset_id, a.hostname, a.serial_number, a.service_tag, a.category, a.model, a.ownership_type, l.location_name FROM assets a LEFT JOIN locations l ON a.location_id = l.location_id $w");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $items[] = ['id'=>(int)$r['asset_id'], 'kind'=>'asset', 'name'=>$r['hostname']??'', 'sn'=>$r['serial_number']??'', 'service_tag'=>$r['service_tag']??'', 'category'=>$r['category']??'', 'model'=>$r['model']??'', 'ownership'=>$r['ownership_type']??'Buy', 'location'=>$r['location_name']??'']; }
}

if (empty($items)) {
    die("<div style='font-family:sans-serif; padding:40px; text-align:center;'>No assets selected for Label printing.<br><br><a href='javascript:window.close()'>Close Window</a></div>");
}

require_once 'includes/auth.php';
$comp_logo = _auth_get_setting($pdo, 'company_logo', '');
$comp_name = _auth_get_setting($pdo, 'company_name', 'COMPANY');
$label_owner_text = _auth_get_setting($pdo, 'label_owner_text', 'IT ASSET TAG');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Asset Labels (<?= count($items) ?>)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@600;700;800&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; background: #e2e8f0; margin: 0; padding: 24px; }

    .toolbar {
        max-width: 960px; margin: 0 auto 20px;
        background: #fff; border-radius: 10px; padding: 14px 20px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;
    }
    .toolbar-title { font-size: 15px; font-weight: 800; color: #0f172a; }
    .toolbar-count { font-size: 13px; color: #64748b; margin-left: 8px; }
    .btn { padding: 8px 16px; border-radius: 8px; border: none; font-weight: 600; font-size: 13px; cursor: pointer; }
    .btn-primary { background: #2563eb; color: #fff; }
    .btn-primary:hover { background: #1d4ed8; }
    .btn-ghost { background: #f1f5f9; color: #475569; }
    .btn-ghost:hover { background: #e2e8f0; }

    .settings-panel {
        max-width: 960px; margin: 0 auto 20px;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        padding: 14px 20px; display: none; gap: 14px; align-items: center; flex-wrap: wrap; font-size: 12px;
    }
    .settings-panel.active { display: flex; }
    .settings-panel label { font-weight: 600; color: #475569; }
    .settings-panel input[type="text"] { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; font-size: 12px; width: 200px; }

    /* ─── Sticker Grid ─── */
    .sticker-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 10px;
        max-width: 960px;
        margin: 0 auto;
    }

    /* ─── Horizontal Sticker (matches physical label) ─── */
    .sticker {
        width: 100%;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid #999;
    }

    /* Top dark band: Logo + "IT ASSET TAG" */
    .sticker-top {
        background: #4a4a4a;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
    }
    .sticker-top img {
        height: 24px;
        width: 24px;
        object-fit: contain;
        filter: brightness(0) invert(1);
        opacity: 0.9;
        flex-shrink: 0;
    }
    .sticker-top .owner-txt {
        font-size: 11px;
        font-weight: 700;
        color: #ffffff;
        white-space: nowrap;
    }

    /* Bottom white band: Hostname */
    .sticker-bottom {
        background: #ffffff;
        padding: 6px 10px;
        display: flex;
        align-items: center;
    }
    .sticker-bottom .host-txt {
        font-family: 'JetBrains Mono', monospace;
        font-size: 16px;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: 0.02em;
    }

    /* ─── Print: 2 columns, 10mm x 20mm stickers ─── */
    @media print {
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        body { background: #fff; padding: 0; margin: 0; }
        .toolbar, .settings-panel { display: none !important; }
        .sticker-grid {
            display: grid;
            grid-template-columns: 1fr 1fr !important;
            gap: 1mm;
            max-width: 100%;
        }
        .sticker {
            border: 0.3px solid #666 !important;
        }
        .sticker-top {
            padding: 1.5mm 2mm !important;
            gap: 1.5mm !important;
            background: #4a4a4a !important;
        }
        .sticker-top img { height: 3.5mm !important; width: 3.5mm !important; }
        .sticker-top .owner-txt { font-size: 5.5px !important; }
        .sticker-bottom { padding: 1mm 2mm !important; }
        .sticker-bottom .host-txt { font-size: 7px !important; }
        @page { size: 100mm 200mm; margin: 2mm; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <div>
        <span class="toolbar-title">Asset Label Printer</span>
        <span class="toolbar-count">&bull; <?= count($items) ?> label(s)</span>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-ghost" onclick="document.querySelector('.settings-panel').classList.toggle('active')">Settings</button>
        <button class="btn btn-primary" onclick="triggerSafePrint()">Print Labels</button>
    </div>
</div>

<div class="settings-panel">
    <label>Owner Text:</label>
    <input type="text" id="ctrlOwner" value="<?= htmlspecialchars($label_owner_text) ?>" oninput="document.querySelectorAll('.owner-txt').forEach(e=>e.innerText=this.value)">
</div>

<div class="sticker-grid">
<?php foreach ($items as $item):
    $name = $item['name'] ?: ($item['service_tag'] ?: ($item['sn'] ?: 'NO-ID'));
?>
    <div class="sticker">
        <div class="sticker-top">
            <?php if ($comp_logo && file_exists(__DIR__ . '/' . $comp_logo)): ?>
                <img src="<?= htmlspecialchars($comp_logo) ?>" alt="Logo">
            <?php endif; ?>
            <span class="owner-txt"><?= htmlspecialchars($label_owner_text) ?></span>
        </div>
        <div class="sticker-bottom">
            <span class="host-txt"><?= htmlspecialchars($name) ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<script>
function triggerSafePrint() {
    if (document.activeElement && document.activeElement.blur) {
        document.activeElement.blur();
    }
    requestAnimationFrame(() => {
        setTimeout(() => {
            window.print();
        }, 400);
    });
}
</script>
</body>
</html>
