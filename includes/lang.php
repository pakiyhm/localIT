<?php
// includes/lang.php — Language Engine

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle language switch request
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'th'])) {
    $_SESSION['lang'] = $_GET['lang'];
    setcookie('lang', $_GET['lang'], time() + (86400 * 365), "/"); // Save for 1 year
    
    // Redirect back to the same page without the lang parameter
    $url = strtok($_SERVER["REQUEST_URI"], '?');
    $query = $_GET;
    unset($query['lang']);
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    header("Location: $url");
    exit;
}

// Determine current language (default: en)
$current_lang = $_SESSION['lang'] ?? $_COOKIE['lang'] ?? 'en';
$GLOBALS['current_lang'] = $current_lang;

// Load Thai dictionary if needed
$lang_dict = [];
if ($current_lang === 'th') {
    $dict_path = __DIR__ . '/lang_th.php';
    if (file_exists($dict_path)) {
        $lang_dict = require $dict_path;
    }
}

/**
 * Translate a string based on the current selected language.
 *
 * @param string $text The English text to translate
 * @return string The translated text, or the original English text if no translation found
 */
function __(string $text): string {
    global $current_lang, $lang_dict;
    
    if ($current_lang === 'th' && isset($lang_dict[$text])) {
        return $lang_dict[$text];
    }
    
    return $text;
}
