<?php
// includes/auth.php — PIN-based edit guard with session timeout
// Usage:
//   require_once 'includes/db_connect.php';  // starts session + creates settings
//   require_once 'includes/auth.php';
//   if (!is_edit_mode()) { /* read-only UI */ }
//   require_edit();                          // top of actions.php

function _auth_get_setting($pdo, $key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}

function _auth_session_minutes($pdo) {
    return max(5, min(720, (int)_auth_get_setting($pdo, 'session_minutes', '60')));
}

function _auth_touch() {
    $_SESSION['edit_last_seen'] = time();
}

function _auth_expired($pdo) {
    if (empty($_SESSION['edit_mode'])) return true;
    $last = (int)($_SESSION['edit_last_seen'] ?? 0);
    $ttl  = _auth_session_minutes($pdo) * 60;
    return ($last + $ttl) < time();
}

function is_edit_mode() {
    global $pdo;
    if (_auth_expired($pdo)) {
        $_SESSION['edit_mode'] = false;
        return false;
    }
    _auth_touch();
    return true;
}

function auth_remaining_minutes() {
    global $pdo;
    if (_auth_expired($pdo)) return 0;
    $last = (int)($_SESSION['edit_last_seen'] ?? 0);
    $ttl  = _auth_session_minutes($pdo) * 60;
    return max(0, (int)(($last + $ttl - time()) / 60));
}

function require_edit() {
    if (!is_edit_mode()) {
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: login.php?next=' . urlencode($back));
        exit;
    }
}

function attempt_login($pin) {
    global $pdo;
    $expected = (string)_auth_get_setting($pdo, 'edit_pin', '123456');
    if (hash_equals($expected, (string)$pin)) {
        $_SESSION['edit_mode'] = true;
        _auth_touch();
        return true;
    }
    return false;
}

function logout() {
    $_SESSION['edit_mode'] = false;
    unset($_SESSION['edit_last_seen']);
}
