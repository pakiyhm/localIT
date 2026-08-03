<?php
// attachments.php — Manage file attachments for assets & accessories
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

$uploadDir = __DIR__ . '/uploads/attachments';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── List (HTML fragment) — readable in view mode ──────────────
if ($action === 'list') {
    $asset_id     = (int)($_GET['asset_id'] ?? 0);
    $accessory_id = (int)($_GET['accessory_id'] ?? 0);
    $user_id      = (int)($_GET['user_id'] ?? 0);
    if (!$asset_id && !$accessory_id && !$user_id) { echo ''; exit; }

    $col = $asset_id ? 'asset_id' : ($accessory_id ? 'accessory_id' : 'user_id');
    $val = $asset_id ?: ($accessory_id ?: $user_id);
    $stmt = $pdo->prepare("SELECT * FROM asset_attachments WHERE $col = :v ORDER BY uploaded_at DESC");
    $stmt->execute(['v' => $val]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/html; charset=utf-8');
    if (empty($rows)) {
        echo '<div class="text-muted" style="padding:10px 0;font-size:.82rem;"><i class="bi bi-paperclip"></i> ยังไม่มีไฟล์แนบ</div>';
        exit;
    }
    echo '<div class="attach-list">';
    foreach ($rows as $r) {
        $sizeKb = round((int)$r['size_bytes'] / 1024);
        $icon   = 'bi-file-earmark';
        $mt     = strtolower($r['mime_type'] ?? '');
        if (str_contains($mt, 'pdf'))                                                 $icon = 'bi-file-earmark-pdf';
        elseif (str_starts_with($mt, 'image/'))                                       $icon = 'bi-file-earmark-image';
        elseif (str_contains($mt, 'word') || str_contains($mt, 'msword'))             $icon = 'bi-file-earmark-word';
        elseif (str_contains($mt, 'sheet') || str_contains($mt, 'excel'))             $icon = 'bi-file-earmark-spreadsheet';
        elseif (str_contains($mt, 'zip')   || str_contains($mt, 'rar'))               $icon = 'bi-file-earmark-zip';
        $href = 'attachments.php?action=download&id=' . (int)$r['attachment_id'];
        $delName = htmlspecialchars($r['filename']);
        echo '<div class="attach-item">';
        echo '<i class="bi ' . $icon . ' att-icon"></i>';
        echo '<a href="' . $href . '" target="_blank" class="att-name">' . htmlspecialchars($r['filename']) . '</a>';
        echo '<span class="att-meta">' . $sizeKb . ' KB &middot; ' . htmlspecialchars(substr($r['uploaded_at'], 0, 16)) . '</span>';
        echo '<form action="attachments.php" method="POST" class="edit-only" style="display:inline;margin:0;" '
             . 'data-confirm="ลบไฟล์ ' . $delName . '?" data-confirm-title="Delete Attachment">'
             . csrf_field()
             . '<input type="hidden" name="action" value="delete">'
             . '<input type="hidden" name="id" value="' . (int)$r['attachment_id'] . '">'
             . '<button type="submit" class="btn btn-sm" style="background:none;border:none;color:#dc2626;padding:2px 6px;" title="ลบไฟล์"><i class="bi bi-trash"></i></button>'
             . '</form>';
        echo '</div>';
    }
    echo '</div>';
    exit;
}

// ── Download — requires active session (edit mode) ─────────
if ($action === 'download') {
    require_edit(); // Prevent anonymous access to attachments
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM asset_attachments WHERE attachment_id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Not found'; exit; }
    $path = $uploadDir . '/' . $row['stored_name'];
    if (!is_file($path)) { http_response_code(404); echo 'File missing'; exit; }
    header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '_', $row['filename']) . '"');
    readfile($path);
    exit;
}

// ── Mutating actions require edit ────────────────────────────
require_edit();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
function _back($referer, $params) {
    $parts = parse_url($referer);
    $path  = $parts['path'] ?? 'index.php';
    $q     = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $q);
    unset($q['msg'], $q['error']);
    $q  = array_merge($q, $params);
    $qs = http_build_query($q);
    header('Location: ' . $path . ($qs ? '?' . $qs : ''));
    exit;
}

if ($action === 'upload') {
    require_csrf();
    $asset_id     = (int)($_POST['asset_id'] ?? 0) ?: null;
    $accessory_id = (int)($_POST['accessory_id'] ?? 0) ?: null;
    $user_id      = (int)($_POST['user_id'] ?? 0) ?: null;
    $note         = trim($_POST['note'] ?? '');
    if (!$asset_id && !$accessory_id && !$user_id) _back($referer, ['error' => 'Missing target id']);
    if (empty($_FILES['file']['tmp_name'])) _back($referer, ['error' => 'No file selected']);
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) _back($referer, ['error' => 'Upload failed (err ' . $_FILES['file']['error'] . ')']);

    $size = (int)$_FILES['file']['size'];
    if ($size > 20 * 1024 * 1024) _back($referer, ['error' => 'File too large (limit 20MB)']);

    $orig = $_FILES['file']['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx', 'xlsx', 'csv', 'txt', 'zip'];
    if (!in_array($ext, $allowedExts, true)) {
        _back($referer, ['error' => 'Invalid file extension (Allowed: PDF, Images, Word, Excel, CSV, TXT, ZIP)']);
    }

    $safe = bin2hex(random_bytes(8)) . '_' . time() . ($ext ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '');
    $dest = $uploadDir . '/' . $safe;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        _back($referer, ['error' => 'Cannot save file']);
    }

    $mime = mime_content_type($dest) ?: ($_FILES['file']['type'] ?? '');
    $pdo->prepare("INSERT INTO asset_attachments (asset_id, accessory_id, user_id, filename, stored_name, mime_type, size_bytes, note)
                   VALUES (:a, :ac, :u, :fn, :sn, :mt, :sz, :note)")
        ->execute([
            'a' => $asset_id, 'ac' => $accessory_id, 'u' => $user_id,
            'fn' => $orig, 'sn' => $safe, 'mt' => $mime, 'sz' => $size, 'note' => $note ?: null
        ]);

    _back($referer, ['msg' => 'attached']);
}

if ($action === 'delete') {
    require_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT stored_name FROM asset_attachments WHERE attachment_id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $path = $uploadDir . '/' . $row['stored_name'];
        if (is_file($path)) @unlink($path);
        $pdo->prepare("DELETE FROM asset_attachments WHERE attachment_id = :id")->execute(['id' => $id]);
    }
    _back($referer, ['msg' => 'detached']);
}

_back($referer, ['error' => 'Unknown attachments action']);
