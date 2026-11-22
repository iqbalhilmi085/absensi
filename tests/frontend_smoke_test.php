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
$appJs = (string) file_get_contents(__DIR__ . '/../frontend/assets/js/app.js');

// login.html checks (Ketik.com Editorial Portal: Scan Absensi + Digital QR Link + Admin-Only Login)
check(str_contains($login, 'ketik-header') && str_contains($login, 'ketik-nav-strip'), 'login.html has Ketik.com editorial header & blue/teal nav bar');
check(str_contains($login, 'ketik-logo.png') && str_contains($login, 'Ketik.com'), 'login.html displays Ketik.com logo and brand');
check(str_contains($login, 'Scan Absensi') && str_contains($login, 'id="qr-scan-start"') && str_contains($login, 'id="qr-manual-code"'), 'login.html shows clear Scan Absensi heading with camera and manual QR input');
check(str_contains($login, 'id="qr-shift"') && str_contains($login, 'id="qr-shift-wrap"'), 'login.html includes clean Shift Kerja dropdown without forced reason textbox');
check(str_contains($login, 'qr-digital-card') && str_contains($login, "get('kartu')"), 'login.html renders Digital Personal QR Card from ?kartu= link');
check(str_contains($login, "Api.post('qr.lookup'") && str_contains($login, "Api.post('qr.absen'"), 'login.html uses qr.lookup and qr.absen endpoints');
check(str_contains($login, 'id="login-form"') && str_contains($login, 'id="toggle-pass"'), 'login.html has admin login form with password toggle');
check(str_contains($login, 'Masuk sebagai Admin') || str_contains($login, 'Login Admin'), 'login.html clearly labels admin-only login');
check(!str_contains($login, 'kiosk-open-btn') && !str_contains($login, 'kiosk.info') && !str_contains($login, 'kiosk.absen'), 'login.html has no legacy kiosk UI/endpoints');

// index.html checks (Ketik.com Modern Corporate Admin Shell)
check(str_contains($index, 'ketik-icon.png') && str_contains($index, 'ketik-logo.png') && str_contains($index, 'Ketik.com'), 'index.html displays Ketik.com logo, icon, and title');
check(str_contains($index, 'ketik-side-brand') && str_contains($index, 'ketik-side-portal'), 'index.html includes Ketik.com executive sidebar & QR portal shortcut');
check(str_contains($index, 'ketik-live-chip'), 'index.html includes Command Center topbar chip');
check(!str_contains($index, 'kmeans.js'), 'index.html does not load unused kmeans.js script');
check(str_contains($index, 'id="nav-desktop"') && str_contains($index, 'id="nav-mobile"'), 'index.html has desktop and mobile navs');
check(str_contains($index, 'id="top-org"') && str_contains($index, 'id="top-clock"') && str_contains($index, 'id="top-schedule"'), 'index.html has #top-org, #top-clock, and #top-schedule');
check(str_contains($index, 'id="theme-toggle"'), 'index.html has #theme-toggle');
check(!str_contains($index, 'badge-hadir">Aktif'), 'index.html removes pulsing Aktif badge');
check(!preg_match('/🌙|☀️|📍/u', $index), 'index.html contains no emoji icons');

// app.css checks (Golden Ratio & Ketik.com Tokens)
check(str_contains($css, '1.618fr'), 'app.css uses golden ratio (1.618fr) grid proportions');
foreach (['.ketik-header', '.ketik-nav-strip', '.ketik-dash-hero', '.ketik-side-brand', '.metric-ico', '.seg-tabs', '.sub-tabs', '.filter-grid', '.row-actions', '.qr-code-pill', '.qr-digital-mini', '.pass-wrap', '.mode-switch', '.badge-wfo', '.badge-wfh', '.nav-group-label', '.schedule-pill', '.qr-id-card'] as $sel) {
    check(str_contains($css, $sel), "app.css defines {$sel}");
}
check(str_contains(strtolower($css), '#0a88c9') && str_contains(strtolower($css), '#3abca5'), 'app.css applies Ketik.com #0A88C9 and #3ABCA5 brand colors');
check(!str_contains($css, '@keyframes pulseDot'), 'app.css removes pulsing dot animation');

// app.js checks
check(is_file(__DIR__ . '/../frontend/assets/img/ketik-logo.png'), 'ketik-logo.png asset exists locally');
check(is_file(__DIR__ . '/../frontend/assets/img/ketik-icon.png'), 'ketik-icon.png asset exists locally');
check(str_contains($appJs, 'ketik-divisi-list') && str_contains($appJs, 'Anak Magang'), 'app.js allows custom divisi & jabatan including Anak Magang');
check(str_contains($appJs, 'div-add-form') && str_contains($appJs, 'shift-add-form') && str_contains($appJs, 'e-div-hours'), 'app.js provides division hours & shift management in Settings and live preview in employee form');
check(!str_contains($appJs, 'id="o-industri"') && !str_contains($appJs, 'id="o-barcode"'), 'app.js removes unnecessary industry preset & unused barcode toggle from Settings');
check(str_contains($appJs, 'openQrCard') && str_contains($appJs, 'karyawan.rotate_qr'), 'app.js provides Personal QR Card viewer/printer & QR rotation');
check(str_contains($appJs, 'e-qr-box') && str_contains($appJs, 'Simpan &amp; Munculkan QR') && str_contains($appJs, 'res.kode_qr'), 'app.js automatically displays generated QR card right after adding a new employee');
check(str_contains($appJs, 'wa.me') && str_contains($appJs, 'mailto:') && str_contains($appJs, 'printAllQrCards'), 'app.js provides WhatsApp/Email sharing and batch print for Personal QR cards');
check(str_contains($appJs, 'viewPantau') && str_contains($appJs, 'viewLaporan') && !str_contains($appJs, 'viewKmeans'), 'app.js streamlines admin menus and removes confusing K-Means view');
check(!str_contains($appJs, "roles: ['karyawan']"), 'app.js scopes web dashboard strictly to admin role');
check(str_contains($login, 'qr-self-tabs') && str_contains($login, "Api.post('qr.riwayat'") && str_contains($login, "Api.post('qr.izin'") && str_contains($login, 'expected_state'), 'login.html supports member self-service tabs (Riwayat Saya, Ajukan Izin) and 60s expected_state guard');
check(str_contains($appJs, 'openImportModal') && str_contains($appJs, 'karyawan.revoke_qr') && str_contains($appJs, 'karyawan.delete') && str_contains($appJs, 'absensi.izin_approve') && str_contains($appJs, 'renderAuditSection') && str_contains($appJs, 'buildPrintableIdCardHtml'), 'app.js includes CSV import, card revocation, member deletion, izin approval, audit log, and 2-sided ID card print');
check(str_contains($login, 'showConfirmScreen') && str_contains($css, '.qr-confirm-card') && str_contains($css, '.qr-id-photo'), 'login.html and app.css implement mobile confirmation view (including Tercatat di luar jadwal) and circular ID Card photo');
check(str_contains($appJs, 'Rekap Per Divisi Hari Ini') && str_contains($appJs, 'id="emp-divisi"') && str_contains($appJs, 'weekly-matrix-wrap') && str_contains($appJs, 'Lintas hari'), 'app.js renders Dashboard per-division summary, Kelola Anggota filters, and Shift & Jadwal Mingguan');
check(!preg_match('/🌙|☀️|📍/u', $appJs), 'app.js remains free of emoji characters');

exit($failures > 0 ? 1 : 0);
