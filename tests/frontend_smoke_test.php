<?php
declare(strict_types=1);

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "[PASS] {$label}\n";
    } else {
        $failures++;
        echo "[FAIL] {$label}\n";
    }
}

$login = (string) file_get_contents(__DIR__ . '/../frontend/login.html');
$index = (string) file_get_contents(__DIR__ . '/../frontend/index.html');
$css   = (string) file_get_contents(__DIR__ . '/../frontend/assets/css/app.css');

// login.html checks
check(str_contains($login, 'id="login-form"'), 'login.html has #login-form');
check(str_contains($login, 'name="email"'), 'login.html has email input');
check(str_contains($login, 'name="kata_sandi"'), 'login.html has password input');
check(str_contains($login, 'id="toggle-pass"'), 'login.html has #toggle-pass');
check(str_contains($login, 'class="auth-shell"'), 'login.html uses split-screen .auth-shell');
check(!str_contains($login, 'kiosk-open-btn'), 'login.html removes #kiosk-open-btn');
check(!str_contains($login, 'kiosk.info') && !str_contains($login, 'kiosk.absen'), 'login.html removes kiosk API calls');
check(!str_contains($login, 'Masuk sebagai Admin'), 'login.html removes admin-only button copy');

// index.html checks
check(str_contains($index, 'id="nav-desktop"') && str_contains($index, 'id="nav-mobile"'), 'index.html has desktop and mobile navs');
check(str_contains($index, 'id="top-org"') && str_contains($index, 'id="top-clock"') && str_contains($index, 'id="top-schedule"'), 'index.html has #top-org, #top-clock, and #top-schedule');
check(str_contains($index, 'id="theme-toggle"'), 'index.html has #theme-toggle');
check(!str_contains($index, 'badge-hadir">Aktif'), 'index.html removes pulsing Aktif badge');
check(!preg_match('/🌙|☀️|📍/u', $index), 'index.html contains no emoji icons');

// app.css checks
foreach (['.auth-shell', '.auth-hero', '.auth-panel', '.pass-wrap', '.mode-switch', '.badge-wfo', '.badge-wfh', '.beranda-grid', '.nav-group-label', '.schedule-pill'] as $sel) {
    check(str_contains($css, $sel), "app.css defines {$sel}");
}
check(!str_contains($css, '@keyframes pulseDot'), 'app.css removes pulsing dot animation');

// Ketik.com branding & asset checks
check(is_file(__DIR__ . '/../frontend/assets/img/ketik-logo.png'), 'ketik-logo.png asset exists locally');
check(is_file(__DIR__ . '/../frontend/assets/img/ketik-icon.png'), 'ketik-icon.png asset exists locally');
check(str_contains($login, 'ketik-logo.png') && str_contains($login, 'Ketik.com'), 'login.html displays Ketik.com logo and brand');
check(str_contains($login, 'nama@ketik.com'), 'login.html uses @ketik.com email placeholder');
check(str_contains($index, 'ketik-icon.png') && str_contains($index, 'Ketik.com'), 'index.html displays Ketik.com icon and title');
check(str_contains(strtolower($css), '#0a88c9') && str_contains(strtolower($css), '#3abca5'), 'app.css applies Ketik.com #0A88C9 and #3ABCA5 brand colors');

$appJs = (string) file_get_contents(__DIR__ . '/../frontend/assets/js/app.js');
check(str_contains($appJs, 'ketik-divisi-list') && str_contains($appJs, 'Redaksi'), 'app.js provides Ketik.com media division suggestions');

exit($failures > 0 ? 1 : 0);
