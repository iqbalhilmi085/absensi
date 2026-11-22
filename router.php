<?php
declare(strict_types=1);

$uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$uriPath = '/' . ltrim(str_replace('\\', '/', $uriPath), '/');

if ($uriPath === '/') {
    header('Location: /frontend/login.html', true, 302);
    return true;
}

// Tolak akses langsung ke direktori/berkas sensitif pada PHP built-in server.
$blockedPrefix = '#^/(\.git|\.agents|\.superpowers|docs|tests|backend/(?:app|data|migrasi|tools)|router\.php)(/|$)#i';
$blockedExt    = '#\.(sqlite|sqlite3|db|sql|md|json|lock|log|ini|htaccess)$#i';
if (preg_match($blockedPrefix, $uriPath) || preg_match($blockedExt, $uriPath) || str_contains($uriPath, '..')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

if ($uriPath === '/backend/api.php' || $uriPath === '/backend/media.php') {
    $_SERVER['SCRIPT_NAME'] = $uriPath;
    require __DIR__ . $uriPath;
    return true;
}

$full = realpath(__DIR__ . $uriPath);
if ($full !== false && is_file($full) && str_starts_with($full, realpath(__DIR__))) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
return true;
