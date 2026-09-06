<?php
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Mirror production's .htaccess: strip a leading /api/ prefix before
// looking for the file, since backend/public files sit flat (no api/ subfolder).
$relative = preg_replace('#^/api/#', '/', $path);
$file = __DIR__ . $relative;

if ($relative !== '/' && is_file($file)) {
    // Rewrite REQUEST_URI so the target script sees the corrected path if it inspects it
    $_SERVER['SCRIPT_NAME'] = $relative;
    require $file;
    return true;
}

require __DIR__ . '/index.php';
