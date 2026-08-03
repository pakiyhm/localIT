<?php
// includes/csrf.php — CSRF Token Protection
// Provides functions for generating and validating CSRF tokens.

/**
 * Generate or retrieve the current CSRF token.
 * Tokens are stored in the session and rotated per-session.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden input field with the CSRF token.
 * Usage: <?= csrf_field() ?> inside any <form>
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Validate the submitted CSRF token against the session token.
 * Call at the top of POST-handling scripts (e.g. actions.php).
 * Returns true if valid, false if invalid.
 */
function csrf_verify(): bool {
    $submitted = $_POST['_csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (empty($expected) || empty($submitted)) {
        return false;
    }
    return hash_equals($expected, $submitted);
}

/**
 * Require valid CSRF token or die with 403.
 * Usage: require_csrf(); at the top of actions.php
 */
function require_csrf(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
}
