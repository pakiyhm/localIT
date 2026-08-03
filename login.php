<?php
// login.php — PIN entry screen
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';
require_once 'includes/lang.php';

$next  = $_GET['next'] ?? 'index.php';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pin = trim($_POST['pin'] ?? '');
    if (attempt_login($pin)) {
        $nextUrl = $_POST['next'] ?? 'index.php';
        if (!preg_match('#^https?://#i', $nextUrl)) {
            header('Location: ' . $nextUrl);
            exit;
        }
        header('Location: index.php'); exit;
    }
    $error = __('Invalid PIN');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(__('Edit Mode Login')) ?> — IT Asset Manager</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/style.css">
</head>
<body style="background:linear-gradient(135deg,#eef2ff,#f5f6fa); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px;">

<div style="background:#fff; border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.08); width:380px; max-width:100%; padding:32px;">
    <div style="text-align:center; margin-bottom:24px;">
        <div style="width:56px; height:56px; background:var(--color-accent); border-radius:14px; margin:0 auto 14px; display:flex; align-items:center; justify-content:center;">
            <i class="bi bi-shield-lock" style="color:#fff; font-size:26px;"></i>
        </div>
        <h2 style="font-size:1.15rem; font-weight:700; margin-bottom:4px;"><?= htmlspecialchars(__('Edit Mode')) ?></h2>
        <p style="font-size:.85rem; color:var(--color-text-muted);"><?= htmlspecialchars(__('Enter PIN to unlock edit permissions')) ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger" style="font-size:.85rem;">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <div class="form-group">
            <label class="form-label"><?= htmlspecialchars(__('PIN Code (4-8 digits)')) ?></label>
            <input type="password" name="pin" class="form-control" inputmode="numeric"
                   pattern="[0-9]{4,8}" maxlength="8" required autofocus
                   style="font-size:1.4rem; letter-spacing:.5em; text-align:center; padding:12px;">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px;">
            <i class="bi bi-unlock"></i> <?= htmlspecialchars(__('Unlock System')) ?>
        </button>
    </form>
</div>

</body>
</html>
