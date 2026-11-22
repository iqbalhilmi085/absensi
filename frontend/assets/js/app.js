const App = (() => {
  const state = {
    meta: null,
    user: null,
    org: null,
    presets: {},
    hariLabels: {},
    route: null,
    kmeans: null,
    axel: null,
    tim: []
  };

  // ---------------- Fungsi Bantu ----------------
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const byId = (id) => document.getElementById(id);

  const STATUS_LABEL = { hadir: 'Hadir', terlambat: 'Terlambat', izin: 'Izin', sakit: 'Sakit', alpa: 'Tidak Masuk (Alpa)' };
  const PERAN_LABEL = { karyawan: 'Karyawan (Absen QR)', atasan: 'Redaktur / Supervisor', admin: 'Admin Ketik.com' };
  const HARI_NAMA = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
  const HARI_PENDEK = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
  const BLN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

  function todayISO(d = new Date()) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  function firstOfMonthISO() { return todayISO().slice(0, 8) + '01'; }
  function endOfMonthISO() { const d = new Date(); return todayISO(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }

  function fmtTanggal(iso) {
    if (!iso) return '—';
    const d = new Date(iso + 'T00:00:00');
    if (isNaN(d)) return iso;
    return `${HARI_PENDEK[d.getDay()]}, ${d.getDate()} ${BLN[d.getMonth()]} ${d.getFullYear()}`;
  }

  function fmtTanggalLengkap(d = new Date()) {
    return `${HARI_NAMA[d.getDay()]}, ${d.getDate()} ${BLN[d.getMonth()]} ${d.getFullYear()}`;
  }

  const fmtJam = (t) => (t ? String(t).slice(0, 5) : '—');

  function fmtDurasi(menit) {
    if (menit == null) return '—';
    const h = Math.floor(menit / 60), m = menit % 60;
    return `${h} jam ${m > 0 ? m + ' mnt' : ''}`.trim();
  }

  function badge(status) {
    const s = String(status || 'alpa');
    const safeClass = esc(s.replace(/[^a-z0-9_-]/gi, ''));
    return `<span class="badge badge-${safeClass}">${esc(STATUS_LABEL[s] || s)}</span>`;
  }

  function modeBadge(mode) {
    if (!mode) return '<span class="muted small">—</span>';
    const m = String(mode).toLowerCase();
    if (m === 'wfh') return '<span class="badge-wfh">WFH</span>';
    if (m === 'wfo') return '<span class="badge-wfo">WFO</span>';
    return `<span class="shift-tag">${esc(m.toUpperCase())}</span>`;
  }

  function empCell(nama) {
    const clean = String(nama || '').trim();
    const parts = clean.split(/\s+/).filter(Boolean);
    const initials = parts.length >= 2
      ? (parts[0][0] + parts[1][0]).toUpperCase()
      : (clean.slice(0, 2) || '?').toUpperCase();
    return `<span class="emp-cell"><span class="emp-initial">${esc(initials)}</span><span style="font-weight:600">${esc(clean)}</span></span>`;
  }

  function parseCatatanBukti(row) {
    const rawCatatan = String(row.catatan || '').trim();
    const rawKegiatan = String(row.kegiatan || '').trim();
    let shiftLabel = '';
    let buktiUrl = '';
    let cleanNote = rawCatatan;
    let kegiatanText = '';

    if (/^https?:\/\//i.test(rawKegiatan)) {
      buktiUrl = rawKegiatan;
    } else if (rawKegiatan) {
      kegiatanText = rawKegiatan;
    }

    const urlMatch = cleanNote.match(/https?:\/\/[^\s|]+/i);
    if (urlMatch) {
      if (!buktiUrl) buktiUrl = urlMatch[0];
      cleanNote = cleanNote.replace(/\|\s*Bukti:\s*https?:\/\/[^\s|]+/i, '').replace(/Bukti:\s*https?:\/\/[^\s|]+/i, '').trim();
    }

    const shiftMatch = cleanNote.match(/Shift:\s*([^|]+)/i);
    if (shiftMatch) {
      shiftLabel = shiftMatch[1].trim();
      cleanNote = cleanNote.replace(/Shift:\s*[^|]+\|?/i, '').trim();
    }

    return { shiftLabel, buktiUrl, cleanNote, kegiatanText };
  }

  function renderBuktiCell(row) {
    const { buktiUrl } = parseCatatanBukti(row);
    const parts = [];
    if (row.foto_masuk) {
      const url = `${Api.base}/media.php?f=${encodeURIComponent(row.foto_masuk)}`;
      parts.push(`<img class="thumb" src="${url}" alt="Foto masuk" data-photo="${esc(row.foto_masuk)}">`);
    }
    if (row.foto_pulang) {
      const url = `${Api.base}/media.php?f=${encodeURIComponent(row.foto_pulang)}`;
      parts.push(`<img class="thumb" src="${url}" alt="Foto pulang" data-photo="${esc(row.foto_pulang)}">`);
    }
    if (buktiUrl) {
      parts.push(`<a class="proof-link" href="${esc(buktiUrl)}" target="_blank" rel="noopener noreferrer">Lihat Foto Bukti</a>`);
    }
    return parts.length ? parts.join(' ') : '<span class="muted small">—</span>';
  }

  function renderModeKegiatanCell(row) {
    const { shiftLabel, cleanNote, kegiatanText } = parseCatatanBukti(row);
    const bits = [];
    if (row.mode) {
      bits.push(modeBadge(row.mode));
    }
    if (kegiatanText) {
      bits.push(`<span>${esc(kegiatanText)}</span>`);
    }
    if (shiftLabel) {
      bits.push(`<span class="shift-tag">${esc(shiftLabel)}</span>`);
    }
    if (cleanNote && cleanNote !== kegiatanText) {
      bits.push(`<span class="muted small">${esc(cleanNote)}</span>`);
    }
    return bits.length ? `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px">${bits.join('')}</div>` : '<span class="muted small">—</span>';
  }

  function toast(msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = `toast toast-${type === 'ok' ? 'ok' : 'err'}`;
    el.textContent = msg;
    byId('toasts').append(el);
    setTimeout(() => el.remove(), 3400);
  }

  function openModal(html, opts = {}) {
    const root = byId('modal-root');
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<div class="glass modal ${opts.wide ? 'modal-wide' : ''}">${html}</div>`;
    root.append(overlay);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    function close() { overlay.remove(); }
    return { overlay, close, $: (sel) => overlay.querySelector(sel) };
  }

  function downloadBlob(content, mime, filename) {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  function exportCsv(filename, columns, rows) {
    const lines = [columns, ...rows].map((r) => r.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(';'));
    downloadBlob('\ufeff' + lines.join('\r\n'), 'text/csv;charset=utf-8', filename);
  }

  function exportExcel(filename, title, columns, rows) {
    const head = columns.map((c) => `<th style="background:#0f172a;color:#ffffff;padding:6px 10px">${esc(c)}</th>`).join('');
    const body = rows.map((r) => `<tr>${r.map((c) => `<td style="padding:5px 10px">${esc(c)}</td>`).join('')}</tr>`).join('');
    const html = `<html><head><meta charset="utf-8"></head><body><h3>${esc(title)}</h3>
      <table border="1" cellspacing="0" cellpadding="4"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`;
    downloadBlob('\ufeff' + html, 'application/vnd.ms-excel', filename);
  }

  const ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    dash: '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    cal: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    nodes: '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="9" r="3"/><circle cx="10" cy="18" r="3"/><path d="M8.5 7.2l6.8 1.2M7.2 8.7l1.8 6.5"/>',
    db: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8M8 9h2"/>',
    users: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M17 4a4 4 0 0 1 0 8M22 21c0-3-1.5-5-4-5.5"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    building: '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01"/>',
    laptop: '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    mapPin: '<path d="M12 21s-6-5.3-6-10a6 6 0 1 1 12 0c0 4.7-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    alert: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    qr: '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM17 17h4v4h-4zM14 20h2"/>'
  };

  function ico(name, size = 18) {
    return `<svg class="ico" viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] || ''}</svg>`;
  }

  // ---------------- Menu Navigasi Admin Ketik.com (Ringkas & Jelas) ----------------
  const ROUTES = {
    dasbor: { label: 'Beranda', group: 'Utama', roles: ['admin'], icon: 'dash', render: (v) => viewDasbor(v) },
    harian: { label: 'Pantau Absen', group: 'Operasional', roles: ['admin'], icon: 'clock', render: (v) => viewPantau(v, state.subTab || 'hari') },
    laporan: { label: 'Laporan & Rekap', group: 'Laporan', roles: ['admin'], icon: 'chart', render: (v) => viewLaporan(v, state.subTab || 'riwayat') },
    karyawan: { label: 'Karyawan & QR', group: 'Administrasi', roles: ['admin'], icon: 'users', render: (v) => viewKaryawan(v) },
    pengaturan: { label: 'Pengaturan Kantor', group: 'Administrasi', roles: ['admin'], icon: 'gear', render: (v) => viewPengaturan(v) }
  };

  function allowedRoutes() {
    return Object.entries(ROUTES).filter(([, r]) => r.roles.includes(state.user.peran));
  }

  function renderNav() {
    const items = allowedRoutes();
    let lastGroup = '';
    const desktopHtml = items.map(([key, r]) => {
      const grp = r.group;
      const head = grp !== lastGroup ? `<div class="nav-group-label">${esc(grp)}</div>` : '';
      lastGroup = grp;
      return `${head}<button data-route="${key}" class="${state.route === key ? 'active' : ''}">${ico(r.icon)}<span>${r.label}</span></button>`;
    }).join('');
    byId('nav-desktop').innerHTML = desktopHtml;
    byId('nav-mobile').innerHTML = items.map(([key, r]) =>
      `<button data-route="${key}" class="${state.route === key ? 'active' : ''}">${ico(r.icon)}<span>${r.label}</span></button>`
    ).join('');
    byId('nav-desktop').querySelectorAll('[data-route]').forEach((b) =>
      b.addEventListener('click', () => { state.subTab = null; go(b.dataset.route); }));
    byId('nav-mobile').querySelectorAll('[data-route]').forEach((b) =>
      b.addEventListener('click', () => { state.subTab = null; go(b.dataset.route); }));
  }

  async function go(route, subTab = null) {
    const alias = { izin: ['harian', 'izin'], axel: ['laporan', 'riwayat'], rekap: ['laporan', 'rekap'] };
    if (alias[route]) {
      subTab = subTab || alias[route][1];
      route = alias[route][0];
    }
    if (!ROUTES[route] || !ROUTES[route].roles.includes(state.user.peran)) return;
    state.route = route;
    state.subTab = subTab;
    renderNav();
    const view = byId('view');
    view.innerHTML = '<div class="glass card"><p class="muted">Sedang memuat data…</p></div>';
    try {
      await ROUTES[route].render(view);
    } catch (err) {
      view.innerHTML = `<div class="glass card"><div class="alert alert-error">${esc(err.message)}</div></div>`;
    }
  }

  function pageHead(title, subtitle, right = '') {
    return `<div class="page-head"><div><h1>${esc(title)}</h1><p>${esc(subtitle)}</p></div><div class="toolbar">${right}</div></div>`;
  }

  // ---------------- Beranda Admin ----------------
  async function viewDasbor(view) {
    const { data } = await Api.get('dashboard');
    const h = data.hari_ini;
    const totalKaryawan = Number(h.total_karyawan || 0);
    const jmlTepat = Number(h.hadir || 0);
    const jmlTerlambat = Number(h.terlambat || 0);
    const jmlIzinSakit = Number(h.izin || 0) + Number(h.sakit || 0);
    const jmlBelum = Number(h.belum || 0) + Number(h.alpa || 0);
    const totalHadirHariIni = jmlTepat + jmlTerlambat;
    const pctHadir = totalKaryawan > 0 ? Math.round((totalHadirHariIni / totalKaryawan) * 100) : 0;

    // Hari kerja aktif (1=Senin .. 7=Minggu)
    const hariKerjaSet = new Set(String(state.org.hari_kerja || '1,2,3,4,5').split(',').map((x) => Number(x.trim())));
    const isWorkday = (iso) => {
      const d = new Date(iso + 'T00:00:00');
      const dow = d.getDay() === 0 ? 7 : d.getDay();
      return hariKerjaSet.has(dow);
    };

    // Data 30 hari kalender terakhir
    const mapTren = {};
    (data.tren || []).forEach((t) => { mapTren[t.tanggal] = t; });
    const full30Days = [];
    for (let i = 29; i >= 0; i--) {
      const d = new Date();
      d.setDate(d.getDate() - i);
      const iso = todayISO(d);
      const row = mapTren[iso] || { tanggal: iso, hadir: 0, terlambat: 0, izin_sakit: 0, alpa: 0 };
      const totalMasuk = Number(row.hadir || 0);
      const terlambat = Number(row.terlambat || 0);
      const tepat = Math.max(0, totalMasuk - terlambat);
      full30Days.push({
        tanggal: iso,
        hadir: totalMasuk,
        tepat,
        terlambat,
        izin_sakit: Number(row.izin_sakit || 0),
        alpa: Number(row.alpa || 0),
        kerja: isWorkday(iso)
      });
    }

    // Donut SVG sederhana untuk Komposisi Hari Ini
    function buildDonutSvg() {
      const r = 64;
      const circ = 2 * Math.PI * r;
      if (totalKaryawan <= 0) {
        return `<svg class="donut-svg" viewBox="0 0 160 160">
          <circle cx="80" cy="80" r="${r}" fill="none" stroke="var(--line)" stroke-width="20"></circle>
        </svg>`;
      }
      const segments = [
        { val: jmlTepat, color: '#16a34a' },
        { val: jmlTerlambat, color: '#f59e0b' },
        { val: jmlIzinSakit, color: '#0A88C9' },
        { val: jmlBelum, color: '#94a3b8' }
      ].filter((s) => s.val > 0);

      let offset = 0;
      const circles = segments.map((s) => {
        const len = (s.val / totalKaryawan) * circ;
        const dash = `${len.toFixed(2)} ${(circ - len).toFixed(2)}`;
        const segEl = `<circle cx="80" cy="80" r="${r}" fill="none" stroke="${s.color}" stroke-width="20" stroke-dasharray="${dash}" stroke-dashoffset="${(-offset).toFixed(2)}" stroke-linecap="butt"></circle>`;
        offset += len;
        return segEl;
      }).join('');

      return `<svg class="donut-svg" viewBox="0 0 160 160">
        <circle cx="80" cy="80" r="${r}" fill="none" stroke="var(--line)" stroke-width="20"></circle>
        ${circles}
      </svg>`;
    }

    const pctOf = (n) => (totalKaryawan > 0 ? Math.round((n / totalKaryawan) * 100) : 0);
    const jmlWfo = Number(h.wfo || 0);
    const jmlWfh = Number(h.wfh || 0);
    const wfoPct = totalHadirHariIni > 0 ? Math.round((jmlWfo / totalHadirHariIni) * 100) : 0;
    const wfhPct = totalHadirHariIni > 0 ? Math.max(0, 100 - wfoPct) : 0;
    const jamNow = new Date().getHours();
    const sapaanAdmin = jamNow < 11 ? 'Selamat pagi' : jamNow < 15 ? 'Selamat siang' : jamNow < 19 ? 'Selamat sore' : 'Selamat malam';

    view.innerHTML = `
      <div class="stack">
        <section class="ketik-dash-hero">
          <div>
            <span class="hero-kicker">${ico('qr', 13)} Command Center Ketik.com</span>
            <h2>${sapaanAdmin}, ${esc(state.user.nama.split(' ')[0] || 'Admin')}</h2>
            <p class="hero-sub">Pantau absensi tim redaksi kantor &amp; liputan lapangan secara real-time · ${fmtTanggal(todayISO())}</p>
            <div class="hero-actions">
              <button class="btn btn-sm hero-btn-light" id="dash-go-harian">${ico('clock', 15)} Pantau Absen</button>
              <button class="btn btn-sm hero-btn-glass" id="dash-go-karyawan">${ico('qr', 15)} Kirim QR Karyawan</button>
              <button class="btn btn-sm hero-btn-glass" id="dash-go-laporan">${ico('db', 15)} Laporan &amp; Ekspor</button>
            </div>
          </div>
          <div class="hero-split-box">
            <div class="hero-split-head">
              <span>Distribusi Mode Kerja Hari Ini</span>
              <b>${pctHadir}% Hadir</b>
            </div>
            <div class="hero-split-track" aria-label="Sebaran WFO dan WFH hari ini">
              <span class="hero-split-wfo" style="width:${totalHadirHariIni ? wfoPct : 50}%"></span>
              <span class="hero-split-wfh" style="width:${totalHadirHariIni ? wfhPct : 50}%"></span>
            </div>
            <div class="hero-split-meta">
              <span><i class="hero-dot" style="background:#38bdf8"></i>Kantor Redaksi (WFO): <b>${jmlWfo} org</b></span>
              <span><i class="hero-dot" style="background:#3ABCA5"></i>Liputan / WFH: <b>${jmlWfh} org</b></span>
            </div>
          </div>
        </section>

        <div class="grid grid-4">
          <div class="glass card metric accent-hadir">
            <div class="metric-top">
              <div class="label">Sudah Masuk Hari Ini</div>
              <span class="metric-ico">${ico('check', 16)}</span>
            </div>
            <div class="value">${totalHadirHariIni} <span style="font-size:13.5px;font-weight:500;color:var(--text-muted)">/ ${totalKaryawan} org</span></div>
            <div class="metric-bar"><span style="width:${pctHadir}%"></span></div>
            <div class="sublabel">${pctHadir}% hadir · WFO: ${jmlWfo} · WFH: ${jmlWfh}</div>
          </div>
          <div class="glass card metric accent-terlambat">
            <div class="metric-top">
              <div class="label">Datang Terlambat</div>
              <span class="metric-ico">${ico('clock', 16)}</span>
            </div>
            <div class="value">${jmlTerlambat} <span style="font-size:13.5px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="metric-bar"><span style="width:${pctOf(jmlTerlambat)}%"></span></div>
            <div class="sublabel">Lewat batas toleransi ${state.org.toleransi_menit} menit</div>
          </div>
          <div class="glass card metric accent-izin">
            <div class="metric-top">
              <div class="label">Izin / Sakit</div>
              <span class="metric-ico">${ico('cal', 16)}</span>
            </div>
            <div class="value">${jmlIzinSakit} <span style="font-size:13.5px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="metric-bar"><span style="width:${pctOf(jmlIzinSakit)}%"></span></div>
            <div class="sublabel">Izin: ${h.izin || 0} · Sakit: ${h.sakit || 0}</div>
          </div>
          <div class="glass card metric accent-alpa">
            <div class="metric-top">
              <div class="label">Belum Absen</div>
              <span class="metric-ico">${ico('alert', 16)}</span>
            </div>
            <div class="value">${jmlBelum} <span style="font-size:13.5px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="metric-bar"><span style="width:${pctOf(jmlBelum)}%"></span></div>
            <div class="sublabel">Belum absen: ${h.belum} · Alpa: ${h.alpa}</div>
          </div>
        </div>

        <div class="dash-charts-grid">
          <div class="glass card">
            <div class="card-head">
              <div>
                <h2>Komposisi Kehadiran</h2>
                <p class="muted small">Pembagian status seluruh tim hari ini</p>
              </div>
              <span class="pill-note">${totalKaryawan} karyawan</span>
            </div>
            <div class="donut-layout">
              <div class="donut-box">
                ${buildDonutSvg()}
                <div class="donut-center">
                  <div class="donut-pct">${pctHadir}%</div>
                  <div class="donut-sub">Sudah Masuk</div>
                </div>
              </div>
              <div class="donut-legend">
                <div class="donut-row">
                  <span class="donut-row-l"><i class="donut-dot" style="background:#16a34a"></i>Tepat Waktu</span>
                  <span class="donut-row-r">${jmlTepat} org <span>(${pctOf(jmlTepat)}%)</span></span>
                </div>
                <div class="donut-row">
                  <span class="donut-row-l"><i class="donut-dot" style="background:#f59e0b"></i>Terlambat</span>
                  <span class="donut-row-r">${jmlTerlambat} org <span>(${pctOf(jmlTerlambat)}%)</span></span>
                </div>
                <div class="donut-row">
                  <span class="donut-row-l"><i class="donut-dot" style="background:#0A88C9"></i>Izin / Sakit</span>
                  <span class="donut-row-r">${jmlIzinSakit} org <span>(${pctOf(jmlIzinSakit)}%)</span></span>
                </div>
                <div class="donut-row">
                  <span class="donut-row-l"><i class="donut-dot" style="background:#94a3b8"></i>Belum Absen</span>
                  <span class="donut-row-r">${jmlBelum} org <span>(${pctOf(jmlBelum)}%)</span></span>
                </div>
              </div>
            </div>
          </div>

          <div class="glass card">
            <div class="card-head" style="flex-wrap:wrap">
              <div>
                <h2 id="trend-title">Kehadiran 7 Hari Terakhir</h2>
                <p class="muted small">Klik batang tanggal untuk melihat rincian &amp; daftar nama</p>
              </div>
              <div class="seg-tabs" role="tablist" aria-label="Rentang waktu grafik">
                <button class="seg-tab active" data-range="7">7 Hari</button>
                <button class="seg-tab" data-range="14">14 Hari</button>
                <button class="seg-tab" data-range="30">30 Hari</button>
              </div>
            </div>

            <div class="trend-summary-pills" id="trend-summary"></div>

            <div class="trend-chart-wrap">
              <div class="trend-yaxis" id="trend-yaxis"></div>
              <div class="bars" id="trend-bars"></div>
            </div>

            <div class="bar-legend">
              <span><i class="legend-dot" style="background:#16a34a"></i>Hijau = Tepat Waktu</span>
              <span><i class="legend-dot" style="background:#f59e0b"></i>Kuning = Terlambat</span>
              <span><i class="legend-dot" style="background:var(--line)"></i>Abu-abu = Hari Libur</span>
            </div>

            <div class="trend-detail-bar" id="trend-detail"></div>
          </div>
        </div>

        <div class="glass card">
          <div class="card-head">
            <div>
              <h2>Perlu Dicek Hari Ini</h2>
              <p class="muted small">Karyawan yang terlambat atau belum melakukan scan QR (${fmtTanggal(todayISO())})</p>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
              <span class="pill-note">${data.perlu_dicek.length} orang</span>
              <button class="btn btn-secondary btn-sm" id="dash-go-izin">Catat Izin / Sakit</button>
            </div>
          </div>
          <div class="riwayat">
            ${data.perlu_dicek.length ? data.perlu_dicek.map((r) => `
              <div class="riwayat-row">
                <div>
                  <div class="t">${empCell(r.nama)}</div>
                  <div class="s" style="margin-top:4px">${r.jam_masuk ? 'Masuk jam ' + fmtJam(r.jam_masuk) : 'Belum ada scan QR hari ini'}</div>
                </div>
                ${badge(r.status)}
              </div>`).join('') : '<p class="muted small">Semua karyawan hadir tepat waktu hari ini.</p>'}
          </div>
        </div>
      </div>`;

    let activeRange = 7;
    let selectedDate = todayISO();

    function renderTrendChart() {
      const slice = full30Days.slice(full30Days.length - activeRange);
      if (!slice.some((d) => d.tanggal === selectedDate) && slice.length) {
        selectedDate = slice[slice.length - 1].tanggal;
      }

      byId('trend-title').textContent = `Kehadiran ${activeRange} Hari Terakhir`;

      const workSlice = slice.filter((d) => d.kerja || d.hadir > 0);
      const denomDays = workSlice.length || slice.length || 1;
      const sumHadir = slice.reduce((a, b) => a + b.hadir, 0);
      const sumTepat = slice.reduce((a, b) => a + b.tepat, 0);
      const avgHadir = (sumHadir / denomDays).toFixed(1);
      const pctTepat = sumHadir > 0 ? Math.round((sumTepat / sumHadir) * 100) : 0;

      byId('trend-summary').innerHTML = `
        <span class="trend-pill">Rata-rata masuk: <b>${avgHadir} dari ${totalKaryawan} org/hari</b></span>
        <span class="trend-pill">Ketepatan waktu: <b style="color:var(--status-hadir)">${pctTepat}%</b></span>`;

      const maxScale = Math.max(totalKaryawan, ...slice.map((t) => t.hadir), 4);
      const midScale = Math.round(maxScale / 2);
      byId('trend-yaxis').innerHTML = `
        <span>${maxScale}</span>
        <span>${midScale}</span>
        <span>0</span>`;

      const barsEl = byId('trend-bars');
      barsEl.className = activeRange >= 30 ? 'bars bars-dense' : 'bars';
      barsEl.innerHTML = slice.map((t, idx) => {
        const isHoliday = !t.kerja && t.hadir === 0;
        const totalPct = isHoliday ? 6 : Math.max(t.hadir > 0 ? 10 : 3, Math.round((t.hadir / maxScale) * 100));
        const latePct = t.hadir > 0 ? Math.round((t.terlambat / t.hadir) * 100) : 0;
        const ontimePct = 100 - latePct;
        const dObj = new Date(t.tanggal + 'T00:00:00');
        const tglNum = dObj.getDate();
        const hariStr = HARI_PENDEK[dObj.getDay()];
        const isToday = t.tanggal === todayISO();
        const lbl = activeRange <= 7
          ? (isToday ? 'Hari Ini' : `${hariStr} ${tglNum}`)
          : (activeRange <= 14 ? `${tglNum} ${BLN[dObj.getMonth()]}` : (idx === 0 || tglNum === 1 ? `${tglNum} ${BLN[dObj.getMonth()]}` : `${tglNum}`));
        const isActive = t.tanggal === selectedDate;

        return `<div class="bar-col ${isActive ? 'active' : ''} ${isHoliday ? 'is-holiday' : ''}" data-date="${t.tanggal}" title="${fmtTanggal(t.tanggal)}: ${isHoliday ? 'Hari Libur' : `${t.hadir} masuk (${t.tepat} tepat waktu, ${t.terlambat} terlambat)`}">
          <span class="bar-num">${isHoliday ? 'Libur' : t.hadir}</span>
          <div class="bar-track">
            <div class="bar-stack" style="height:${totalPct}%">
              ${!isHoliday && t.terlambat > 0 ? `<div class="bar-seg-late" style="height:${latePct}%"></div>` : ''}
              ${!isHoliday && t.tepat > 0 ? `<div class="bar-seg-ontime" style="height:${ontimePct}%"></div>` : ''}
            </div>
          </div>
          <span class="bar-lbl">${lbl}</span>
        </div>`;
      }).join('');

      barsEl.querySelectorAll('.bar-col').forEach((col) => {
        col.addEventListener('click', () => {
          selectedDate = col.dataset.date;
          renderTrendChart();
        });
      });

      const sel = slice.find((d) => d.tanggal === selectedDate) || slice[slice.length - 1];
      if (sel) {
        const tidakMasuk = Math.max(0, totalKaryawan - sel.hadir);
        const isLibur = !sel.kerja && sel.hadir === 0;
        byId('trend-detail').innerHTML = `
          <div>
            <div style="font-weight:700;font-size:13.5px;color:var(--text)">
              ${fmtTanggal(sel.tanggal)} ${isLibur ? '· <span class="muted">Hari Libur Kantor</span>' : ''}
            </div>
            <div class="small" style="margin-top:4px;display:flex;flex-wrap:wrap;gap:12px">
              ${isLibur
                ? '<span class="muted">Tidak ada jadwal kerja kantor pada tanggal ini.</span>'
                : `<span>Masuk: <b>${sel.hadir} dari ${totalKaryawan} orang</b></span>
                   <span style="color:var(--status-hadir)">● Tepat Waktu: <b>${sel.tepat}</b></span>
                   <span style="color:var(--status-terlambat)">● Terlambat: <b>${sel.terlambat}</b></span>
                   <span class="muted">● Tidak Masuk: <b>${tidakMasuk}</b></span>`}
            </div>
          </div>
          <button class="btn btn-primary btn-sm" id="trend-open-date">
            Lihat Daftar Nama →
          </button>`;

        byId('trend-open-date')?.addEventListener('click', () => {
          state.selectedHarianDate = sel.tanggal;
          go('harian');
        });
      }
    }

    view.querySelectorAll('[data-range]').forEach((btn) => {
      btn.addEventListener('click', () => {
        activeRange = Number(btn.dataset.range);
        view.querySelectorAll('[data-range]').forEach((b) => {
          b.className = b === btn ? 'seg-tab active' : 'seg-tab';
        });
        renderTrendChart();
      });
    });

    renderTrendChart();

    byId('dash-go-harian')?.addEventListener('click', () => go('harian'));
    byId('dash-go-karyawan')?.addEventListener('click', () => go('karyawan'));
    byId('dash-go-izin')?.addEventListener('click', () => go('izin'));
    byId('dash-go-laporan')?.addEventListener('click', () => go('axel'));
    byId('dash-go-pengaturan')?.addEventListener('click', () => go('pengaturan'));
    byId('dash-go-rekap')?.addEventListener('click', () => go('rekap'));
  }

  // ---------------- Pantau Absen (Absen Hari Ini + Catat Izin / Sakit) ----------------
  async function viewPantau(view, activeTab = 'hari') {
    const tab = activeTab === 'izin' ? 'izin' : 'hari';
    view.innerHTML = `
      ${pageHead('Pantau Absen Harian', 'Cek kehadiran karyawan hari ini, ubah jika ada kesalahan, atau catat izin/sakit', `
        <div class="sub-tabs" role="tablist">
          <button type="button" class="sub-tab ${tab === 'hari' ? 'active' : ''}" data-ptab="hari">${ico('clock', 15)} Absen Hari Ini</button>
          <button type="button" class="sub-tab ${tab === 'izin' ? 'active' : ''}" data-ptab="izin">${ico('cal', 15)} Catat Izin / Sakit</button>
        </div>`)}
      <div id="pantau-body"></div>`;

    view.querySelectorAll('[data-ptab]').forEach((b) => {
      b.addEventListener('click', () => {
        state.subTab = b.dataset.ptab;
        viewPantau(view, b.dataset.ptab);
      });
    });

    const body = byId('pantau-body');
    if (tab === 'izin') {
      await renderIzinSection(body);
    } else {
      await renderHarianSection(body);
    }
  }

  async function renderHarianSection(container) {
    const initialDate = state.selectedHarianDate || todayISO();
    state.selectedHarianDate = null;
    container.innerHTML = `
      <div class="glass card" id="harian-box">
        <div class="card-head" style="flex-wrap:wrap">
          <div>
            <h2 id="harian-title">Daftar Kehadiran · ${fmtTanggal(initialDate)}</h2>
            <p class="muted small" id="harian-sub">Memuat data kehadiran…</p>
          </div>
          <div class="toolbar">
            <input type="search" id="harian-search" placeholder="Cari nama atau divisi…" style="width:200px">
            <input type="date" id="harian-tgl" value="${initialDate}" style="width:160px">
            <button class="btn btn-primary" id="harian-load">Muat Tanggal</button>
          </div>
        </div>
        <div class="table-wrap" id="harian-table-wrap"><p class="muted" style="padding:16px">Sedang memuat data…</p></div>
      </div>`;

    let allRows = [];
    let currentTgl = initialDate;

    function renderHarianTable() {
      const q = (byId('harian-search')?.value || '').trim().toLowerCase();
      const rows = q
        ? allRows.filter((r) => (r.nama || '').toLowerCase().includes(q) || (r.tim || '').toLowerCase().includes(q))
        : allRows;
      const hadirCount = allRows.filter((r) => r.status === 'hadir' || r.status === 'terlambat').length;
      byId('harian-title').textContent = `Daftar Kehadiran · ${fmtTanggal(currentTgl)}`;
      byId('harian-sub').textContent = `Sudah masuk: ${hadirCount} dari ${allRows.length} karyawan`;

      byId('harian-table-wrap').innerHTML = `<table>
        <thead>
          <tr>
            <th>No</th>
            <th>Karyawan &amp; Divisi</th>
            <th>Status</th>
            <th>Masuk / Pulang</th>
            <th>Mode &amp; Kegiatan</th>
            <th>Foto</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody id="harian-tbody">
          ${rows.map((r, idx) => `<tr>
            <td class="muted tabular">${idx + 1}</td>
            <td>
              <div class="cell-stack">
                ${empCell(r.nama)}
                <span class="cell-sub">${esc(r.tim)}</span>
              </div>
            </td>
            <td>${r.status ? badge(r.status) : '<span class="badge badge-netral">Belum Absen</span>'}</td>
            <td class="tabular">
              <div class="cell-stack">
                <span>Masuk: <b>${fmtJam(r.jam_masuk)}</b></span>
                <span class="cell-sub">Pulang: ${fmtJam(r.jam_pulang)}</span>
              </div>
            </td>
            <td class="wrap">${renderModeKegiatanCell(r)}</td>
            <td>${renderBuktiCell(r)}</td>
            <td>
              <button class="btn btn-secondary btn-sm"
                data-koreksi="${r.id}"
                data-nama="${esc(r.nama)}"
                data-status="${r.status || 'hadir'}"
                data-masuk="${r.jam_masuk ? r.jam_masuk.slice(0, 5) : ''}"
                data-pulang="${r.jam_pulang ? r.jam_pulang.slice(0, 5) : ''}">
                Ubah
              </button>
            </td>
          </tr>`).join('') || '<tr><td colspan="7" class="muted">Tidak ada data karyawan sesuai pencarian.</td></tr>'}
        </tbody>
      </table>`;

      byId('harian-tbody').querySelectorAll('[data-koreksi]').forEach((b) =>
        b.addEventListener('click', () => openKoreksi(b.dataset, currentTgl, load)));
      byId('harian-tbody').querySelectorAll('[data-photo]').forEach((img) =>
        img.addEventListener('click', () => viewPhoto(img.dataset.photo)));
    }

    async function load() {
      currentTgl = byId('harian-tgl').value || todayISO();
      const { data } = await Api.get('absensi.harian', { tanggal: currentTgl });
      allRows = data || [];
      renderHarianTable();
    }
    byId('harian-search').addEventListener('input', renderHarianTable);
    byId('harian-load').addEventListener('click', load);
    byId('harian-tgl').addEventListener('change', load);
    await load();
  }

  function openKoreksi(d, tanggal, reload) {
    const opts = Object.entries(STATUS_LABEL).map(([k, v]) =>
      `<option value="${k}" ${d.status === k ? 'selected' : ''}>${v}</option>`).join('');
    const m = openModal(`
      <div class="modal-head"><h2>Ubah Data Absen Karyawan</h2><button class="icon-btn" type="button" data-close>×</button></div>
      <p class="muted small" style="margin-bottom:14px">Nama: <b>${esc(d.nama)}</b> · Tanggal: <b>${fmtTanggal(tanggal)}</b></p>
      <div class="form">
        <label class="field"><span>Status Kehadiran</span><select id="k-status">${opts}</select></label>
        <div class="field-row">
          <label class="field"><span>Jam Masuk</span><input type="time" id="k-masuk" value="${esc(d.masuk)}"></label>
          <label class="field"><span>Jam Pulang</span><input type="time" id="k-pulang" value="${esc(d.pulang)}"></label>
        </div>
        <label class="field"><span>Alasan Perubahan (Wajib diisi)</span><textarea id="k-catatan" placeholder="Contoh: Lupa absen pulang atau penyesuaian jam kerja"></textarea></label>
        <div class="alert alert-error" id="k-err" hidden></div>
        <div style="display:flex;justify-content:flex-end;gap:10px">
          <button class="btn btn-secondary" type="button" data-close-btn>Batal</button>
          <button class="btn btn-primary" type="button" id="k-save">Simpan Perubahan</button>
        </div>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);
    m.$('[data-close-btn]').addEventListener('click', m.close);
    m.$('#k-save').addEventListener('click', async () => {
      const catatan = m.$('#k-catatan').value.trim();
      const errBox = m.$('#k-err');
      if (!catatan) { errBox.textContent = 'Mohon isi alasan perubahan terlebih dahulu.'; errBox.hidden = false; return; }
      try {
        await Api.post('absensi.koreksi', {
          pengguna_id: Number(d.koreksi),
          tanggal,
          status: m.$('#k-status').value,
          jam_masuk: m.$('#k-masuk').value,
          jam_pulang: m.$('#k-pulang').value,
          catatan
        });
        m.close(); toast('Data absen berhasil diubah'); reload();
      } catch (e) { errBox.textContent = e.message; errBox.hidden = false; }
    });
  }

  // ---------------- Laporan & Rekap (Riwayat Ekspor + Rekap Bulanan) ----------------
  async function viewLaporan(view, activeTab = 'riwayat') {
    const tab = activeTab === 'rekap' ? 'rekap' : 'riwayat';
    view.innerHTML = `
      ${pageHead('Laporan & Rekap Kehadiran', 'Lihat riwayat absen lengkap, rekap bulanan per karyawan, dan ekspor PDF/Excel/CSV', `
        <div class="sub-tabs" role="tablist">
          <button type="button" class="sub-tab ${tab === 'riwayat' ? 'active' : ''}" data-ltab="riwayat">${ico('db', 15)} Riwayat &amp; Ekspor</button>
          <button type="button" class="sub-tab ${tab === 'rekap' ? 'active' : ''}" data-ltab="rekap">${ico('chart', 15)} Rekap Bulanan</button>
        </div>`)}
      <div id="laporan-body"></div>`;

    view.querySelectorAll('[data-ltab]').forEach((b) => {
      b.addEventListener('click', () => {
        state.subTab = b.dataset.ltab;
        viewLaporan(view, b.dataset.ltab);
      });
    });

    const body = byId('laporan-body');
    if (tab === 'rekap') {
      await renderRekapSection(body);
    } else {
      await renderRiwayatSection(body);
    }
  }

  async function renderRekapSection(container) {
    const curYear = new Date().getFullYear();
    const timList = (await Api.get('tim.list').catch(() => ({ data: [] }))).data;
    container.innerHTML = `
      <div class="stack">
        <div class="glass card">
          <div class="filter-grid">
            <label class="field"><span>Dari Tanggal</span><input type="date" id="r-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai Tanggal</span><input type="date" id="r-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Divisi</span><select id="r-tim"><option value="">Semua Divisi</option>${timList.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select></label>
          </div>
          <div class="filter-actions">
            <button class="btn btn-primary btn-sm" id="r-load">Tampilkan Rekap</button>
            <button class="btn btn-ghost btn-sm" id="r-all-year">Setahun Penuh (${curYear})</button>
            <span class="spacer"></span>
            <button class="btn btn-secondary btn-sm" id="rekap-csv">Unduh CSV</button>
            <button class="btn btn-primary btn-sm" id="rekap-xls">Unduh Excel</button>
          </div>
        </div>
        <div class="glass card" id="r-box"><p class="muted">Sedang memuat data…</p></div>
      </div>`;

    let rows = [];
    function columnsData() {
      const cols = ['No', 'Nama Karyawan', 'Divisi', 'Jumlah Hari Masuk', 'Tidak Masuk', 'Total Hari Kerja', 'Rata-rata Terlambat (Menit)', 'Persentase Tidak Masuk', 'Rata-rata Jam Kerja'];
      const data = rows.map((r, i) => [i + 1, r.nama, r.tim, r.hari_tercatat, r.hari_tidak_hadir, r.total_hari, r.rata_terlambat, r.persen_tidak_hadir + '%', r.rata_jam_kerja + ' jam']);
      return { cols, data };
    }

    async function load() {
      const dari = byId('r-dari').value;
      const sampai = byId('r-sampai').value;
      if (dari && sampai && sampai < dari) {
        toast('Tanggal selesai tidak boleh sebelum tanggal mulai.', 'err');
        return;
      }
      const box = byId('r-box');
      try {
        const { data } = await Api.get('rekap', {
          dari, sampai, tim: byId('r-tim').value
        });
        rows = data;
      } catch (e) {
        toast(e.message, 'err');
        return;
      }
      const totHadir = rows.reduce((a, b) => a + Number(b.hari_tercatat || 0), 0);
      const avgLateAll = rows.length ? (rows.reduce((a, b) => a + Number(b.rata_terlambat || 0), 0) / rows.length).toFixed(1) : '0';
      const avgWorkAll = rows.length ? (rows.reduce((a, b) => a + Number(b.rata_jam_kerja || 0), 0) / rows.length).toFixed(1) : '0';

      box.innerHTML = `
        <div class="card-head">
          <h2>Hasil Rekap Kehadiran Karyawan</h2>
          <span class="pill-note">${rows.length} karyawan</span>
        </div>
        <div class="trend-summary-strip">
          <div class="trend-stat-box">
            <div class="ts-label">Jumlah Karyawan</div>
            <div class="ts-val">${rows.length} orang</div>
          </div>
          <div class="trend-stat-box">
            <div class="ts-label">Total Kehadiran Tercatat</div>
            <div class="ts-val" style="color:var(--status-hadir)">${totHadir} hari kerja</div>
          </div>
          <div class="trend-stat-box">
            <div class="ts-label">Rata-rata Keterlambatan</div>
            <div class="ts-val" style="color:var(--status-terlambat)">${avgLateAll} menit</div>
          </div>
          <div class="trend-stat-box">
            <div class="ts-label">Rata-rata Lama Kerja</div>
            <div class="ts-val">${avgWorkAll} jam / hari</div>
          </div>
        </div>
        <div class="table-wrap"><table>
          <thead>
            <tr>
              <th>No</th>
              <th>Karyawan &amp; Divisi</th>
              <th>Jumlah Masuk</th>
              <th>Tidak Masuk</th>
              <th>Rata Terlambat</th>
              <th>% Tidak Masuk</th>
              <th>Lama Kerja</th>
            </tr>
          </thead>
          <tbody>${rows.map((r, i) => `<tr>
            <td class="muted tabular">${i + 1}</td>
            <td><div class="cell-stack">${empCell(r.nama)}<span class="cell-sub">${esc(r.tim)}</span></div></td>
            <td class="tabular"><span class="badge badge-hadir">${r.hari_tercatat} hari</span></td>
            <td class="tabular">${r.hari_tidak_hadir} hari</td>
            <td class="tabular">${r.rata_terlambat} menit</td>
            <td class="tabular">${r.persen_tidak_hadir}%</td>
            <td class="tabular">${r.rata_jam_kerja} jam/hari</td>
          </tr>`).join('') || '<tr><td colspan="7" class="muted">Tidak ada data pada tanggal yang dipilih.</td></tr>'}</tbody>
        </table></div>`;
    }
    byId('r-load').addEventListener('click', load);
    byId('r-all-year').addEventListener('click', () => {
      byId('r-dari').value = `${curYear}-01-01`;
      byId('r-sampai').value = todayISO();
      load();
    });
    byId('rekap-csv').addEventListener('click', () => {
      if (!rows.length) { toast('Tidak ada data untuk diunduh', 'err'); return; }
      const { cols, data } = columnsData();
      exportCsv(`rekap-absen-${todayISO()}.csv`, cols, data);
    });
    byId('rekap-xls').addEventListener('click', () => {
      if (!rows.length) { toast('Tidak ada data untuk diunduh', 'err'); return; }
      const { cols, data } = columnsData();
      exportExcel(`rekap-absen-${todayISO()}.xls`, `Rekap Kehadiran (${byId('r-dari').value} s/d ${byId('r-sampai').value})`, cols, data);
    });
    await load();
  }

  // ---------------- Riwayat Absen Lengkap ----------------
  async function renderRiwayatSection(container) {
    const curYear = new Date().getFullYear();
    const [karyawanRes, timRes] = await Promise.all([
      Api.get('karyawan.list').catch(() => ({ data: [] })),
      Api.get('tim.list').catch(() => ({ data: [] }))
    ]);
    const karyawan = karyawanRes.data.filter((k) => k.peran === 'karyawan');
    const timList = timRes.data;

    container.innerHTML = `
      <div class="stack">
        <div class="glass card">
          <div class="filter-grid">
            <label class="field"><span>Dari Tanggal</span><input type="date" id="a-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai Tanggal</span><input type="date" id="a-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Nama Karyawan</span><select id="a-karyawan"><option value="">Semua Karyawan</option>${karyawan.map((k) => `<option value="${k.id}">${esc(k.nama)}</option>`).join('')}</select></label>
            <label class="field"><span>Divisi</span><select id="a-tim"><option value="">Semua Divisi</option>${timList.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select></label>
            <label class="field"><span>Mode Kerja</span><select id="a-mode"><option value="">Semua Mode</option><option value="wfo">Kantor (WFO)</option><option value="wfh">Liputan / WFH</option></select></label>
            <label class="field"><span>Status</span><select id="a-status"><option value="">Semua Status</option>${Object.entries(STATUS_LABEL).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
          </div>
          <div class="filter-actions">
            <button class="btn btn-primary btn-sm" id="a-load">Tampilkan Data</button>
            <button class="btn btn-ghost btn-sm" id="a-all-year">Semua Data ${curYear}</button>
            <span class="spacer"></span>
            <button class="btn btn-secondary btn-sm" id="axel-csv">Unduh CSV</button>
            <button class="btn btn-secondary btn-sm" id="axel-xls">Unduh Excel</button>
            <button class="btn btn-primary btn-sm" id="axel-pdf">${ico('db', 15)} Cetak PDF</button>
          </div>
        </div>

        <div class="grid grid-4" id="a-summary"></div>

        <div class="glass card">
          <div class="card-head" style="flex-wrap:wrap">
            <div>
              <h2>Daftar Riwayat Kehadiran</h2>
              <p class="muted small" id="a-count-sub">Memuat…</p>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="search" id="a-search" placeholder="Ketik nama karyawan atau tanggal…" style="min-height:36px;width:240px">
              <span class="pill-note" id="a-count">0 data</span>
            </div>
          </div>
          <div class="table-wrap" id="a-table"><p class="muted">Sedang memuat data…</p></div>
          <div class="pagination" id="a-pagination"></div>
        </div>
      </div>`;

    let dataset = { data: [], ringkasan: {}, filter: {} };
    let filteredRows = [];
    let currentPage = 1;
    const pageSize = 25;

    function currentFilters() {
      const tim = byId('a-tim').value;
      const mode = byId('a-mode').value;
      const karyOpt = byId('a-karyawan').selectedOptions[0];
      const status = byId('a-status').value;
      return {
        dari: byId('a-dari').value,
        sampai: byId('a-sampai').value,
        karyawan: byId('a-karyawan').value,
        karyawanNama: karyOpt ? karyOpt.textContent : 'Semua Karyawan',
        tim, mode, status
      };
    }

    function renderSummary(s) {
      byId('a-summary').innerHTML = `
        <div class="glass card metric">
          <div class="label">Total Data Absen</div>
          <div class="value">${s.total || 0}</div>
          <div class="sublabel">Jumlah catatan pada tanggal terpilih</div>
        </div>
        <div class="glass card metric accent-hadir">
          <div class="label">Hadir Tepat Waktu</div>
          <div class="value">${s.hadir || 0}</div>
          <div class="sublabel">Total masuk kerja: ${(s.hadir || 0) + (s.terlambat || 0)}</div>
        </div>
        <div class="glass card metric accent-terlambat">
          <div class="label">Datang Terlambat</div>
          <div class="value">${s.terlambat || 0}</div>
          <div class="sublabel">Rata-rata terlambat: ${s.rata_terlambat || 0} menit</div>
        </div>
        <div class="glass card metric accent-izin">
          <div class="label">Izin / Sakit / Tidak Masuk</div>
          <div class="value">${(s.izin || 0) + (s.sakit || 0) + (s.alpa || 0)}</div>
          <div class="sublabel">Izin: ${s.izin || 0} · Sakit: ${s.sakit || 0} · Alpa: ${s.alpa || 0}</div>
        </div>`;
    }

    function applySearch() {
      const q = (byId('a-search')?.value || '').trim().toLowerCase();
      const all = dataset.data || [];
      if (!q) {
        filteredRows = all;
      } else {
        filteredRows = all.filter((r) =>
          (r.nama || '').toLowerCase().includes(q) ||
          (r.tim || '').toLowerCase().includes(q) ||
          (r.tanggal || '').toLowerCase().includes(q) ||
          (r.kegiatan || '').toLowerCase().includes(q) ||
          (r.mode || '').toLowerCase().includes(q) ||
          (r.catatan || '').toLowerCase().includes(q) ||
          (r.status || '').toLowerCase().includes(q)
        );
      }
      currentPage = 1;
      renderTable();
    }

    function renderTable() {
      const total = filteredRows.length;
      const totalPages = Math.max(1, Math.ceil(total / pageSize));
      if (currentPage > totalPages) currentPage = totalPages;
      const startIdx = (currentPage - 1) * pageSize;
      const pageRows = filteredRows.slice(startIdx, startIdx + pageSize);

      byId('a-count').textContent = `${total} data`;
      byId('a-count-sub').textContent = total
        ? `Menampilkan urutan ${startIdx + 1} sampai ${startIdx + pageRows.length} dari total ${total} data absen`
        : 'Tidak ada data absen pada filter ini';

      byId('a-table').innerHTML = `
        <table>
          <thead><tr>
            <th>No</th>
            <th>Tanggal</th>
            <th>Nama Karyawan</th>
            <th>Divisi</th>
            <th>Status</th>
            <th>Jam Masuk</th>
            <th>Jam Pulang</th>
            <th>Lama Kerja</th>
            <th>Terlambat</th>
            <th>Mode &amp; Kegiatan</th>
            <th>Foto Bukti</th>
          </tr></thead>
          <tbody>${pageRows.map((r, idx) => `<tr>
            <td class="muted tabular">${startIdx + idx + 1}</td>
            <td>${fmtTanggal(r.tanggal)}</td>
            <td>${empCell(r.nama)}</td>
            <td>${esc(r.tim)}</td>
            <td>${badge(r.status)}</td>
            <td class="tabular">${fmtJam(r.jam_masuk)}</td>
            <td class="tabular">${fmtJam(r.jam_pulang)}</td>
            <td class="tabular">${fmtDurasi(r.durasi_kerja)}</td>
            <td class="tabular">${r.menit_terlambat == null ? '—' : (r.menit_terlambat === 0 ? '<span class="muted">Tepat waktu</span>' : `<span style="color:var(--status-terlambat);font-weight:600">${r.menit_terlambat} menit</span>`)}</td>
            <td class="wrap">${renderModeKegiatanCell(r)}</td>
            <td>${renderBuktiCell(r)}</td>
          </tr>`).join('') || '<tr><td colspan="11" class="muted">Tidak ada data sesuai pencarian.</td></tr>'}</tbody>
        </table>`;

      byId('a-table').querySelectorAll('[data-photo]').forEach((img) =>
        img.addEventListener('click', () => viewPhoto(img.dataset.photo)));

      const pag = byId('a-pagination');
      if (total <= pageSize) {
        pag.innerHTML = '';
      } else {
        pag.innerHTML = `
          <span>Halaman <b>${currentPage}</b> dari <b>${totalPages}</b> (${total} data)</span>
          <div class="pagination-btns">
            <button class="btn btn-secondary btn-sm" id="pg-first" ${currentPage <= 1 ? 'disabled' : ''}>« Awal</button>
            <button class="btn btn-secondary btn-sm" id="pg-prev" ${currentPage <= 1 ? 'disabled' : ''}>‹ Sebelumnya</button>
            <button class="btn btn-secondary btn-sm" id="pg-next" ${currentPage >= totalPages ? 'disabled' : ''}>Berikutnya ›</button>
            <button class="btn btn-secondary btn-sm" id="pg-last" ${currentPage >= totalPages ? 'disabled' : ''}>Akhir »</button>
          </div>`;
        byId('pg-first')?.addEventListener('click', () => { currentPage = 1; renderTable(); });
        byId('pg-prev')?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderTable(); } });
        byId('pg-next')?.addEventListener('click', () => { if (currentPage < totalPages) { currentPage++; renderTable(); } });
        byId('pg-last')?.addEventListener('click', () => { currentPage = totalPages; renderTable(); });
      }
    }

    async function load() {
      const f = currentFilters();
      if (f.dari && f.sampai && f.sampai < f.dari) {
        toast('Tanggal selesai tidak boleh sebelum tanggal mulai.', 'err');
        return;
      }
      try {
        const res = await Api.get('axel.dataset', {
          dari: f.dari, sampai: f.sampai, karyawan: f.karyawan, tim: f.tim, mode: f.mode, status: f.status
        });
        dataset = res;
        renderSummary(res.ringkasan);
        applySearch();
      } catch (e) {
        toast(e.message, 'err');
      }
    }

    function buildExportRows() {
      const columns = ['No', 'Tanggal', 'Nama Karyawan', 'Divisi', 'Mode', 'Status', 'Jam Masuk', 'Jam Pulang', 'Lama Kerja', 'Terlambat (Menit)', 'Kegiatan / Catatan'];
      const rows = filteredRows.map((r, i) => {
        const { shiftLabel, cleanNote, kegiatanText } = parseCatatanBukti(r);
        return [
          i + 1,
          fmtTanggal(r.tanggal),
          r.nama,
          r.tim,
          (r.mode || 'wfo').toUpperCase(),
          STATUS_LABEL[r.status] || r.status,
          fmtJam(r.jam_masuk),
          fmtJam(r.jam_pulang),
          fmtDurasi(r.durasi_kerja),
          r.menit_terlambat == null ? '-' : r.menit_terlambat,
          [kegiatanText, shiftLabel, cleanNote].filter(Boolean).join(' - ') || '-'
        ];
      });
      return { columns, rows };
    }

    byId('a-load').addEventListener('click', load);
    byId('a-all-year').addEventListener('click', () => {
      byId('a-dari').value = `${curYear}-01-01`;
      byId('a-sampai').value = todayISO();
      load();
    });
    byId('a-search').addEventListener('input', applySearch);
    byId('axel-csv').addEventListener('click', () => {
      if (!filteredRows.length) { toast('Tidak ada data untuk diunduh', 'err'); return; }
      const { columns, rows } = buildExportRows();
      exportCsv(`riwayat-absen-${todayISO()}.csv`, columns, rows);
    });
    byId('axel-xls').addEventListener('click', () => {
      if (!filteredRows.length) { toast('Tidak ada data untuk diunduh', 'err'); return; }
      const f = currentFilters();
      const { columns, rows } = buildExportRows();
      exportExcel(`riwayat-absen-${todayISO()}.xls`, `Riwayat Kehadiran ${state.org.nama} (${f.dari} s/d ${f.sampai})`, columns, rows);
    });
    byId('axel-pdf').addEventListener('click', () => {
      if (!filteredRows.length) { toast('Tidak ada data untuk dicetak', 'err'); return; }
      const f = currentFilters();
      const { columns, rows } = buildExportRows();
      const s = dataset.ringkasan || {};
      PdfExport.dataset({
        title: 'Laporan Kehadiran Karyawan',
        orgName: state.org.nama,
        filterLines: [
          `Periode Tanggal: ${f.dari} s/d ${f.sampai}`,
          `Karyawan: ${f.karyawanNama} | Divisi: ${f.tim || 'Semua Divisi'} | Mode: ${f.mode ? f.mode.toUpperCase() : 'Semua Mode'} | Status: ${f.status ? STATUS_LABEL[f.status] : 'Semua Status'}`,
          `Jumlah Data: ${filteredRows.length} baris`
        ],
        columns,
        rows,
        summaryLines: [
          `Hadir Tepat Waktu: ${s.hadir || 0}   |   Terlambat: ${s.terlambat || 0}   |   Izin: ${s.izin || 0}   |   Sakit: ${s.sakit || 0}   |   Tidak Masuk: ${s.alpa || 0}`,
          `Rata-rata Keterlambatan: ${s.rata_terlambat || 0} menit`
        ]
      });
      toast('Laporan PDF berhasil diunduh');
    });

    await load();
  }

  function viewPhoto(file) {
    const url = `${Api.base}/media.php?f=${encodeURIComponent(file)}`;
    const m = openModal(`
      <div class="modal-head"><h2>Foto Bukti Absen</h2><button class="icon-btn" type="button" data-close>×</button></div>
      <img src="${url}" alt="Foto kehadiran" style="width:100%;border-radius:10px">`);
    m.$('[data-close]').addEventListener('click', m.close);
  }

  // ---------------- Pengaturan Kantor ----------------
  async function viewPengaturan(view) {
    const o = state.org;
    const presets = state.presets;
    const hariBoxes = Object.entries(state.hariLabels).map(([num, label]) => {
      const on = o.hari_kerja.split(',').includes(num);
      return `<label class="day-pill"><input type="checkbox" value="${num}" ${on ? 'checked' : ''}> <span>${label}</span></label>`;
    }).join('');

    function summaryText(orgObj) {
      const aturan = [
        Number(orgObj.wajib_foto) ? 'Wajib Foto' : 'Foto Opsional',
        Number(orgObj.wajib_barcode) ? 'Wajib Scan QR' : 'Tanpa Scan QR',
        (Number(orgObj.wajib_gps) || Number(orgObj.radius_masuk_m) > 0)
          ? (Number(orgObj.radius_masuk_m) > 0 ? `Wajib di Area Kantor (${orgObj.radius_masuk_m} m)` : 'Wajib GPS Aktif')
          : 'GPS Bebas'
      ];
      return `Jam kerja <b>${esc(orgObj.jam_masuk)} – ${esc(orgObj.jam_pulang)}</b> (toleransi <b>${orgObj.toleransi_menit} menit</b>) · ${aturan.join(' · ')}`;
    }

    const curRadius = Number(o.radius_masuk_m) || 0;

    view.innerHTML = `
      ${pageHead('Pengaturan Kantor', 'Atur jadwal kerja dan cara absen dengan bahasa yang sederhana')}
      <div class="stack">
        <div class="config-summary" id="o-summary">
          <span><b>Aturan Aktif Saat Ini:</b> ${summaryText(o)}</span>
        </div>

        <div class="grid grid-2">
          <div class="glass card">
            <div class="card-head"><h2>Pengaturan Kantor &amp; Aturan Absen</h2></div>
            <form class="form" id="org-form">
              <div class="config-section">
                <div class="config-section-title"><span class="step-num">1</span> Profil &amp; Jam Kerja Kantor</div>
                <label class="field"><span>Nama Kantor / Perusahaan</span><input id="o-nama" value="${esc(o.nama)}" required placeholder="Contoh: PT Ketik Media Siber (Ketik.com)"></label>
                <label class="field"><span>Pilih Jenis Usaha (Isi Jam Otomatis)</span><select id="o-industri">
                  ${Object.entries(presets).map(([k, p]) => `<option value="${k}" ${o.industri === k ? 'selected' : ''}>${esc(p.label)} (${esc(p.jam_masuk)}–${esc(p.jam_pulang)})</option>`).join('')}
                </select></label>
                <div class="field-row">
                  <label class="field"><span>Jam Masuk</span><input type="time" id="o-masuk" value="${esc(o.jam_masuk)}"></label>
                  <label class="field"><span>Jam Pulang</span><input type="time" id="o-pulang" value="${esc(o.jam_pulang)}"></label>
                  <label class="field"><span>Toleransi Telat (Menit)</span><input type="number" id="o-toleransi" min="0" max="180" value="${o.toleransi_menit}"></label>
                </div>
                <p class="muted small">Contoh: jika jam masuk 08:00 dan toleransi 15 menit, karyawan dianggap terlambat bila masuk lewat 08:15.</p>
                <div class="field"><span>Hari Kerja Aktif</span><div class="day-pills" id="o-hari-wrap">${hariBoxes}</div></div>
              </div>

              <div class="config-section">
                <div class="config-section-title"><span class="step-num">2</span> Cara Karyawan Absen (Kartu QR Pribadi &amp; Verifikasi)</div>
                <p class="muted small">Setiap karyawan memindai <b>Kartu QR Pribadi</b> di halaman depan tanpa login. Aturan radius lokasi berlaku khusus untuk mode <b>Kantor Redaksi (WFO)</b>, sedangkan tim <b>Liputan / WFH</b> tetap bisa absen dengan wajib mengisi catatan kegiatan.</p>
                <div class="rule-cards">
                  <label class="rule-card">
                    <input type="checkbox" id="o-foto" ${Number(o.wajib_foto) ? 'checked' : ''}>
                    <div>
                      <div class="rule-card-title">Wajib Ambil Foto Selfie</div>
                      <div class="rule-card-desc">Karyawan harus mengambil foto wajah saat absen masuk dan pulang.</div>
                    </div>
                  </label>
                  <label class="rule-card">
                    <input type="checkbox" id="o-barcode" ${Number(o.wajib_barcode) ? 'checked' : ''}>
                    <div>
                      <div class="rule-card-title">Verifikasi QR Tambahan Kantor (WFO)</div>
                      <div class="rule-card-desc">Wajibkan pindai QR lokasi kantor saat karyawan memilih mode hadir WFO.</div>
                    </div>
                  </label>
                  <label class="rule-card">
                    <input type="checkbox" id="o-gps" ${Number(o.wajib_gps) ? 'checked' : ''}>
                    <div>
                      <div class="rule-card-title">Wajib Cek Lokasi GPS</div>
                      <div class="rule-card-desc">Pastikan lokasi GPS aktif saat absen masuk/pulang (pembatasan radius hanya untuk WFO).</div>
                    </div>
                  </label>
                </div>
              </div>

              <div class="config-section">
                <div class="config-section-title"><span class="step-num">3</span> Pembatasan Lokasi GPS Kantor (Khusus WFO)</div>
                <input type="hidden" id="o-kode" value="${esc(o.kode_lokasi || 'KETIK-SBY-01')}">
                <div class="field">
                  <span>Batas Jarak Maksimal dari Kantor</span>
                  <div class="quick-pills" id="o-radius-pills" style="margin-bottom:6px">
                    <button type="button" class="quick-pill ${curRadius === 0 ? 'active' : ''}" data-rad="0">Bebas (Tanpa Batas)</button>
                    <button type="button" class="quick-pill ${curRadius === 50 ? 'active' : ''}" data-rad="50">50 m (Dalam Gedung)</button>
                    <button type="button" class="quick-pill ${curRadius === 100 ? 'active' : ''}" data-rad="100">100 m (Area Kantor)</button>
                    <button type="button" class="quick-pill ${curRadius === 250 ? 'active' : ''}" data-rad="250">250 m (Area Luas)</button>
                  </div>
                  <div class="field-row">
                    <label class="field"><span>Jarak Maksimal (Meter)</span><input id="o-radius" type="number" min="0" max="100000" value="${curRadius}"></label>
                  </div>
                </div>
                <div class="field">
                  <span>Titik Lokasi Kantor (Diisi jika pakai GPS / batas jarak)</span>
                  <div class="muted small" style="margin-bottom:6px">Saat Anda berada di kantor redaksi, klik tombol di bawah agar titik lokasi terisi otomatis.</div>
                  <button type="button" class="btn btn-secondary btn-sm" id="o-ambil-lokasi" style="width:fit-content">${ico('mapPin', 15)} Ambil Lokasi Kantor Otomatis</button>
                  <div class="field-row" style="margin-top:8px">
                    <label class="field"><span>Garis Lintang (Latitude)</span><input id="o-lat" type="number" step="any" value="${o.lat_kantor != null && o.lat_kantor !== '' ? esc(String(o.lat_kantor)) : ''}" placeholder="Otomatis terisi"></label>
                    <label class="field"><span>Garis Bujur (Longitude)</span><input id="o-lng" type="number" step="any" value="${o.lng_kantor != null && o.lng_kantor !== '' ? esc(String(o.lng_kantor)) : ''}" placeholder="Otomatis terisi"></label>
                  </div>
                </div>
              </div>

              <div class="alert alert-error" id="o-err" hidden></div>
              <button class="btn btn-primary btn-lg" type="submit">Simpan Semua Pengaturan</button>
            </form>
          </div>

          <div class="glass card stack" style="height:fit-content">
            <div class="card-head">
              <h2>Alur Presensi QR Pribadi Ketik.com</h2>
              <span class="shift-tag">Tanpa Login Karyawan</span>
            </div>
            <div class="config-section">
              <div class="config-section-title"><span class="step-num">1</span> QR Otomatis per Karyawan</div>
              <p class="muted small">Setiap karyawan redaksi yang ditambahkan otomatis mendapatkan kode &amp; kartu QR pribadi unik di menu <b>Karyawan &amp; QR</b>.</p>
            </div>
            <div class="config-section">
              <div class="config-section-title"><span class="step-num">2</span> Admin Mengirimkan ke Karyawan</div>
              <p class="muted small">Admin cukup klik <b>Kirim QR</b> untuk membagikan link kartu QR via WhatsApp, Email, unduh gambar kartu PNG, atau cetak massal.</p>
            </div>
            <div class="config-section">
              <div class="config-section-title"><span class="step-num">3</span> Scan QR di Halaman Depan</div>
              <p class="muted small">Karyawan memindai QR pribadinya di halaman portal depan, memilih <b>Kantor Redaksi (WFO)</b> atau <b>Liputan / WFH</b>, lalu mengambil foto selfie.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button type="button" class="btn btn-primary btn-sm" id="set-go-qr">${ico('qr', 15)} Kelola &amp; Kirim QR Karyawan</button>
              <a class="btn btn-secondary btn-sm" href="login.html" target="_blank" rel="noopener noreferrer">Buka Portal Scan QR</a>
            </div>
          </div>
        </div>
      </div>`;

    byId('set-go-qr')?.addEventListener('click', () => go('karyawan'));

    function syncRadiusPills(val) {
      view.querySelectorAll('#o-radius-pills .quick-pill').forEach((p) => {
        p.classList.toggle('active', Number(p.dataset.rad) === Number(val));
      });
    }
    view.querySelectorAll('#o-radius-pills .quick-pill').forEach((pill) => {
      pill.addEventListener('click', () => {
        byId('o-radius').value = pill.dataset.rad;
        syncRadiusPills(pill.dataset.rad);
      });
    });
    byId('o-radius').addEventListener('input', (e) => syncRadiusPills(e.target.value));

    byId('o-ambil-lokasi').addEventListener('click', async () => {
      const btnLok = byId('o-ambil-lokasi');
      btnLok.disabled = true;
      btnLok.textContent = 'Sedang mencari lokasi…';
      try {
        const loc = await Scanner.acquireLocation({ targetAkurasi: 25 });
        if (!loc.ok) { toast(loc.error, 'err'); return; }
        byId('o-lat').value = loc.lat.toFixed(7);
        byId('o-lng').value = loc.lng.toFixed(7);
        toast('Titik lokasi kantor berhasil diambil');
      } catch (e) {
        toast('Gagal mengambil lokasi.', 'err');
      } finally {
        btnLok.disabled = false;
        btnLok.innerHTML = `${ico('mapPin', 15)} Ambil Lokasi Kantor Otomatis`;
      }
    });

    byId('o-industri').addEventListener('change', (e) => {
      const p = presets[e.target.value];
      if (!p) return;
      byId('o-masuk').value = p.jam_masuk;
      byId('o-pulang').value = p.jam_pulang;
      byId('o-toleransi').value = p.toleransi_menit;
      const days = p.hari_kerja.split(',');
      view.querySelectorAll('#o-hari-wrap input[type="checkbox"]').forEach((c) => (c.checked = days.includes(c.value)));
      toast(`Jam kerja diisi otomatis sesuai jenis usaha "${p.label}"`);
    });

    byId('org-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errBox = byId('o-err');
      errBox.hidden = true;
      const hariKerja = [...view.querySelectorAll('#o-hari-wrap input[type="checkbox"]')].filter((c) => c.checked).map((c) => Number(c.value));
      if (!hariKerja.length) { errBox.textContent = 'Pilih minimal satu hari kerja.'; errBox.hidden = false; return; }

      const wajibGps = byId('o-gps').checked ? 1 : 0;
      const radiusVal = Number(byId('o-radius').value) || 0;
      const latVal = byId('o-lat').value.trim();
      const lngVal = byId('o-lng').value.trim();
      if ((wajibGps || radiusVal > 0) && (!latVal || !lngVal)) {
        errBox.textContent = 'Anda mengaktifkan GPS atau batas jarak. Klik tombol "Ambil Lokasi Kantor Otomatis" terlebih dahulu.';
        errBox.hidden = false;
        return;
      }

      try {
        const res = await Api.post('org.update', {
          nama: byId('o-nama').value,
          industri: byId('o-industri').value,
          jam_masuk: byId('o-masuk').value,
          jam_pulang: byId('o-pulang').value,
          toleransi_menit: Number(byId('o-toleransi').value),
          hari_kerja: hariKerja,
          kode_lokasi: byId('o-kode').value,
          wajib_foto: byId('o-foto').checked ? 1 : 0,
          wajib_barcode: byId('o-barcode').checked ? 1 : 0,
          lat_kantor: latVal,
          lng_kantor: lngVal,
          radius_masuk_m: radiusVal,
          wajib_gps: wajibGps
        });
        state.org = res.organisasi;
        byId('side-org').textContent = state.org.nama;
        if (byId('top-org')) byId('top-org').textContent = state.org.nama;
        if (byId('top-schedule')) byId('top-schedule').innerHTML = `${ico('clock', 14)}<span>Jam Kerja ${esc(state.org.jam_masuk)}–${esc(state.org.jam_pulang)} WIB</span>`;
        byId('o-summary').innerHTML = `<span><b>Aturan Aktif Saat Ini:</b> ${summaryText(state.org)}</span>`;
        toast('Pengaturan kantor berhasil disimpan');
      } catch (err) { errBox.textContent = err.message; errBox.hidden = false; }
    });
  }

  // ---------------- Daftar Karyawan & QR Pribadi Otomatis ----------------
  function qrPortalUrl(code) {
    return `${location.href.replace(/[^/]*$/, '')}login.html?kartu=${encodeURIComponent(code)}`;
  }

  async function viewKaryawan(view) {
    view.innerHTML = `
      ${pageHead('Karyawan & QR Pribadi', 'Setiap karyawan otomatis mendapat QR unik pribadi. Admin dapat langsung mengirim, mengunduh, atau mencetak kartu QR.', `
        <button class="btn btn-secondary" id="print-all-qr">${ico('qr', 15)} Cetak Semua Kartu QR</button>
        <button class="btn btn-primary" id="add-emp">+ Tambah Karyawan</button>`)}
      <div class="glass card" id="emp-box"><p class="muted">Sedang memuat data…</p></div>`;

    let allEmployees = [];

    function renderEmpTable() {
      const q = (byId('emp-search')?.value || '').trim().toLowerCase();
      const rows = q
        ? allEmployees.filter((u) =>
            (u.nama || '').toLowerCase().includes(q) ||
            (u.email || '').toLowerCase().includes(q) ||
            (u.tim || '').toLowerCase().includes(q) ||
            (u.jabatan || '').toLowerCase().includes(q) ||
            (u.kode_qr || '').toLowerCase().includes(q))
        : allEmployees;

      const tbody = byId('emp-tbody');
      if (tbody) {
        tbody.innerHTML = rows.map((u, idx) => `<tr>
          <td class="muted tabular">${idx + 1}</td>
          <td>
            <div class="cell-stack">
              ${empCell(u.nama)}
              <span class="cell-sub">${esc(u.email)}</span>
            </div>
          </td>
          <td>
            <div class="cell-stack">
              <span class="shift-tag" style="width:fit-content">${esc(u.tim)}</span>
              <span class="cell-sub">${esc(u.jabatan || 'Staf')}</span>
            </div>
          </td>
          <td>${PERAN_LABEL[u.peran] || esc(u.peran)}</td>
          <td>${u.peran === 'karyawan' && u.kode_qr ? `<span class="qr-code-pill">${esc(u.kode_qr)}</span>` : '<span class="muted small">Khusus Admin</span>'}</td>
          <td>${Number(u.aktif) ? '<span class="badge badge-hadir">Aktif</span>' : '<span class="badge badge-netral">Nonaktif</span>'}</td>
          <td>
            <div class="row-actions">
              ${u.peran === 'karyawan' && u.kode_qr ? `<button class="btn btn-primary btn-sm" data-qr='${esc(JSON.stringify(u))}'>${ico('qr', 14)} Kirim QR</button>` : ''}
              <button class="btn btn-secondary btn-sm" data-edit='${esc(JSON.stringify(u))}'>Ubah</button>
              ${u.id !== state.user.id ? `<button class="btn btn-ghost btn-sm" data-toggle="${u.id}" data-aktif="${Number(u.aktif) ? 0 : 1}">${Number(u.aktif) ? 'Nonaktif' : 'Aktifkan'}</button>` : ''}
            </div>
          </td>
        </tr>`).join('') || '<tr><td colspan="7" class="muted">Tidak ada karyawan sesuai pencarian.</td></tr>';
        tbody.querySelectorAll('[data-qr]').forEach((b) =>
          b.addEventListener('click', () => openQrCard(JSON.parse(b.dataset.qr), load)));
        tbody.querySelectorAll('[data-edit]').forEach((b) =>
          b.addEventListener('click', () => openEmpForm(JSON.parse(b.dataset.edit), load)));
        tbody.querySelectorAll('[data-toggle]').forEach((b) =>
          b.addEventListener('click', async () => {
            try {
              await Api.post('karyawan.toggle', { id: Number(b.dataset.toggle), aktif: Number(b.dataset.aktif) });
              toast('Status karyawan berhasil diubah'); load();
            } catch (e) { toast(e.message, 'err'); }
          }));
        return;
      }

      const activeQrCount = allEmployees.filter((u) => u.peran === 'karyawan' && u.kode_qr && Number(u.aktif) === 1).length;
      byId('emp-box').innerHTML = `
        <div class="card-head" style="flex-wrap:wrap">
          <div>
            <h2>Semua Karyawan &amp; Akun Admin</h2>
            <p class="muted small">${activeQrCount} karyawan aktif sudah memiliki QR pribadi otomatis</p>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="search" id="emp-search" placeholder="Cari nama, divisi, atau kode QR…" style="min-height:36px;width:250px">
            <span class="pill-note">${allEmployees.length} akun</span>
          </div>
        </div>
        <div class="table-wrap"><table>
          <thead>
            <tr>
              <th>No</th>
              <th>Karyawan &amp; Email</th>
              <th>Divisi &amp; Jabatan</th>
              <th>Peran</th>
              <th>QR Pribadi</th>
              <th>Status</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody id="emp-tbody"></tbody>
        </table></div>`;
      byId('emp-search').addEventListener('input', renderEmpTable);
      renderEmpTable();
    }

    async function load() {
      const { data } = await Api.get('karyawan.list');
      allEmployees = data || [];
      byId('emp-box').innerHTML = '';
      renderEmpTable();
      return allEmployees;
    }
    byId('add-emp').addEventListener('click', () => openEmpForm(null, load));
    byId('print-all-qr').addEventListener('click', () => printAllQrCards(allEmployees));
    await load();
  }

  function printAllQrCards(employees) {
    const list = (employees || []).filter((u) => u.peran === 'karyawan' && u.kode_qr && Number(u.aktif) === 1);
    if (!list.length) { toast('Belum ada karyawan aktif dengan QR pribadi', 'err'); return; }
    const win = window.open('', '_blank', 'width=900,height=740');
    if (!win) { toast('Izinkan pop-up browser untuk mencetak semua kartu QR', 'err'); return; }
    const cardsHtml = list.map((u) => {
      let svg = '';
      try {
        const qr = qrcode(0, 'M');
        qr.addData(u.kode_qr);
        qr.make();
        svg = qr.createSvgTag(4, 5);
      } catch (e) {}
      return `<div class="card"><div class="head">KETIK.COM · KARTU PRESENSI</div>
        <div class="body"><div class="qr">${svg}</div><div class="name">${esc(u.nama)}</div>
        <div class="meta">${esc(u.tim)}${u.jabatan ? ' · ' + esc(u.jabatan) : ''}</div>
        <div class="code">${esc(u.kode_qr)}</div></div></div>`;
    }).join('');
    win.document.write(`<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Cetak Semua Kartu QR · Ketik.com</title>
      <style>body{font-family:sans-serif;padding:20px;color:#0f172a}
      h2{text-align:center;margin:0 0 16px;color:#0B1E33}
      .grid{display:grid;grid-template-columns:repeat(2,minmax(260px,1fr));gap:16px}
      .card{border:2px solid #0A88C9;border-radius:12px;overflow:hidden;text-align:center;break-inside:avoid}
      .head{background:#0A88C9;border-bottom:4px solid #3ABCA5;color:#fff;padding:10px;font-weight:700;font-size:12px;letter-spacing:0.05em}
      .body{padding:16px}.qr svg{width:170px;height:170px}.name{font-size:16px;font-weight:700;margin-top:8px}
      .meta{color:#475569;font-size:12px;margin-top:2px}.code{margin-top:10px;font-family:monospace;font-size:12px;font-weight:700;color:#0A88C9}</style>
      </head><body><h2>Daftar Kartu QR Karyawan · PT Ketik Media Siber</h2><div class="grid">${cardsHtml}</div>
      <script>window.onload=function(){window.print();}<\/script></body></html>`);
    win.document.close();
  }

  function openQrCard(u, reload) {
    let curCode = u.kode_qr || '';
    const m = openModal(`
      <div class="modal-head"><h2>Kirim &amp; Kelola Kartu QR Pribadi</h2><button class="icon-btn" type="button" data-close>×</button></div>
      <div class="stack">
        <div class="qr-id-card">
          <div class="qr-id-head">
            <span class="qr-id-logo"><img src="assets/img/ketik-logo.png" alt="Ketik.com"></span>
            <span class="small" style="font-weight:700">KARTU PRESENSI</span>
          </div>
          <div class="qr-id-body">
            <div class="qr-holder" id="emp-qr-svg" style="padding:10px"></div>
            <div>
              <div class="qr-id-name">${esc(u.nama)}</div>
              <div class="qr-id-role">${esc(u.tim)}${u.jabatan ? ' · ' + esc(u.jabatan) : ''}</div>
            </div>
            <div class="qr-id-code" id="emp-qr-code-txt">${esc(curCode)}</div>
          </div>
        </div>
        <div class="qr-share-grid">
          <button type="button" class="btn btn-primary btn-sm" id="emp-qr-copy">Salin Link Kartu QR</button>
          <a class="btn btn-secondary btn-sm" id="emp-qr-wa" target="_blank" rel="noopener noreferrer">Kirim via WhatsApp</a>
          <a class="btn btn-secondary btn-sm" id="emp-qr-mail">Kirim via Email</a>
          <button type="button" class="btn btn-secondary btn-sm" id="emp-qr-dl">Unduh Kartu PNG</button>
          <button type="button" class="btn btn-secondary btn-sm" id="emp-qr-print">Cetak Kartu</button>
          <button type="button" class="btn btn-ghost btn-sm" id="emp-qr-rotate">Putar QR Baru</button>
        </div>
        <p class="muted small" style="text-align:center">Kirim link kartu atau gambar PNG ini ke <b>${esc(u.nama)}</b>. Karyawan cukup buka link / scan QR di halaman depan tanpa perlu login. Jika kartu tersebar, klik <b>Putar QR Baru</b>.</p>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);

    function syncShareLinks(code) {
      const link = qrPortalUrl(code);
      const msg = `Halo ${u.nama}, berikut Kartu QR Presensi Pribadi Ketik.com Anda (${u.tim}).\nKode QR: ${code}\nBuka link ini untuk lihat kartu QR & langsung absen masuk/pulang: ${link}`;
      const waBtn = m.$('#emp-qr-wa');
      const mailBtn = m.$('#emp-qr-mail');
      if (waBtn) waBtn.href = `https://wa.me/?text=${encodeURIComponent(msg)}`;
      if (mailBtn) mailBtn.href = `mailto:${encodeURIComponent(u.email || '')}?subject=${encodeURIComponent('Kartu QR Presensi Ketik.com - ' + u.nama)}&body=${encodeURIComponent(msg)}`;
    }

    function drawEmpQr(code) {
      const holder = m.$('#emp-qr-svg');
      try {
        const qr = qrcode(0, 'M');
        qr.addData(code);
        qr.make();
        holder.innerHTML = qr.createSvgTag(5, 6);
      } catch (e) {
        holder.innerHTML = '<p class="muted small">Gagal membuat QR.</p>';
      }
      m.$('#emp-qr-code-txt').textContent = code;
      syncShareLinks(code);
    }
    drawEmpQr(curCode);

    m.$('#emp-qr-copy').addEventListener('click', async () => {
      const link = qrPortalUrl(curCode);
      try {
        await navigator.clipboard.writeText(link);
        toast('Link kartu QR pribadi berhasil disalin');
      } catch (e) {
        window.prompt('Salin link Kartu QR ini untuk dikirim ke karyawan:', link);
      }
    });

    m.$('#emp-qr-dl').addEventListener('click', () => {
      const svg = m.$('#emp-qr-svg svg');
      if (!svg) return;
      const xml = new XMLSerializer().serializeToString(svg);
      const img = new Image();
      img.onload = () => {
        const c = document.createElement('canvas');
        c.width = 520; c.height = 660;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.fillStyle = '#0A88C9';
        ctx.fillRect(0, 0, c.width, 82);
        ctx.fillStyle = '#3ABCA5';
        ctx.fillRect(0, 82, c.width, 8);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 20px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('KETIK.COM · KARTU PRESENSI REDAKSI', c.width / 2, 50);
        ctx.drawImage(img, 80, 115, 360, 360);
        ctx.fillStyle = '#0f172a';
        ctx.font = 'bold 24px sans-serif';
        ctx.fillText(String(u.nama || '').slice(0, 30), c.width / 2, 520);
        ctx.fillStyle = '#475569';
        ctx.font = '17px sans-serif';
        ctx.fillText(`${u.tim || ''}${u.jabatan ? ' · ' + u.jabatan : ''}`.slice(0, 42), c.width / 2, 552);
        ctx.fillStyle = '#0A88C9';
        ctx.font = 'bold 18px monospace';
        ctx.fillText(curCode, c.width / 2, 600);
        const a = document.createElement('a');
        a.href = c.toDataURL('image/png');
        a.download = `kartu-qr-${(u.nama || 'karyawan').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`;
        a.click();
        toast('Kartu QR PNG siap dikirim');
      };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
    });

    m.$('#emp-qr-print').addEventListener('click', () => {
      const svg = m.$('#emp-qr-svg').innerHTML;
      const win = window.open('', '_blank', 'width=480,height=640');
      if (!win) { toast('Izinkan pop-up browser untuk mencetak kartu QR', 'err'); return; }
      win.document.write(`<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Kartu QR · ${esc(u.nama)}</title>
        <style>body{font-family:sans-serif;text-align:center;padding:30px;color:#0f172a}
        .card{border:2px solid #0A88C9;border-radius:14px;overflow:hidden;max-width:340px;margin:0 auto}
        .head{background:#0A88C9;border-bottom:4px solid #3ABCA5;color:#fff;padding:14px;font-weight:700;font-size:14px}
        .body{padding:22px}.qr svg{width:220px;height:220px}.name{font-size:18px;font-weight:700;margin-top:10px}
        .meta{color:#475569;font-size:13px;margin-top:4px}.code{margin-top:12px;font-family:monospace;font-weight:700;color:#0A88C9}</style>
        </head><body><div class="card"><div class="head">KETIK.COM · KARTU PRESENSI REDAKSI</div>
        <div class="body"><div class="qr">${svg}</div><div class="name">${esc(u.nama)}</div>
        <div class="meta">${esc(u.tim)}${u.jabatan ? ' · ' + esc(u.jabatan) : ''}</div>
        <div class="code">${esc(curCode)}</div></div></div>
        <script>window.onload=function(){window.print();}<\/script></body></html>`);
      win.document.close();
    });

    m.$('#emp-qr-rotate').addEventListener('click', async () => {
      try {
        const res = await Api.post('karyawan.rotate_qr', { id: u.id });
        curCode = res.kode_qr;
        u.kode_qr = curCode;
        drawEmpQr(curCode);
        toast('Kode QR pribadi baru berhasil dibuat');
        reload();
      } catch (e) { toast(e.message, 'err'); }
    });
  }

  function openEmpForm(u, reload) {
    const edit = !!u;
    const m = openModal(`
      <div class="modal-head"><h2>${edit ? 'Ubah Data' : 'Tambah'} Karyawan</h2><button class="icon-btn" type="button" data-close>×</button></div>
      <div class="form">
        <label class="field"><span>Nama Lengkap</span><input id="e-nama" value="${esc(u?.nama || '')}" required placeholder="Masukkan nama lengkap"></label>
        <div class="field-row">
          <label class="field"><span>Alamat Email</span><input type="email" id="e-email" value="${esc(u?.email || '')}" required placeholder="nama@ketik.com"></label>
          <label class="field"><span>Hak Akses</span><select id="e-peran">${Object.entries(PERAN_LABEL).map(([k, v]) => `<option value="${k}" ${(u?.peran || 'karyawan') === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        </div>
        <div class="field-row">
          <label class="field"><span>Divisi / Departemen</span><input id="e-tim" list="ketik-divisi-list" value="${esc(u?.tim || 'Redaksi')}" placeholder="Contoh: Redaksi / Liputan"></label>
          <label class="field"><span>Jabatan</span><input id="e-jabatan" list="ketik-jabatan-list" value="${esc(u?.jabatan || 'Reporter / Jurnalis')}" placeholder="Contoh: Reporter / Editor"></label>
        </div>
        <datalist id="ketik-divisi-list">
          <option value="Redaksi">
          <option value="Liputan / Jurnalis">
          <option value="Editor">
          <option value="IT & Digital">
          <option value="Desain & Konten">
          <option value="Bisnis & Kemitraan">
          <option value="HR & Keuangan">
        </datalist>
        <datalist id="ketik-jabatan-list">
          <option value="Pemimpin Redaksi">
          <option value="Redaktur Pelaksana">
          <option value="Redaktur">
          <option value="Reporter / Jurnalis">
          <option value="Editor Konten">
          <option value="Video & Fotografer">
          <option value="Social Media Specialist">
          <option value="Fullstack Developer">
          <option value="HR & GA">
          <option value="Finance">
        </datalist>
        <label class="field" id="e-sandi-wrap">
          <span id="e-sandi-label">Kata Sandi Login ${edit ? '(Kosongkan jika tidak ingin mengganti)' : '(Khusus Admin)'}</span>
          <input type="password" id="e-sandi" placeholder="Minimal 8 karakter untuk Admin">
        </label>
        <label style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="e-aktif" ${!edit || Number(u.aktif) ? 'checked' : ''}> <span>Karyawan Masih Aktif Bekerja</span></label>
        <div class="alert alert-error" id="e-err" hidden></div>
        <div style="display:flex;justify-content:flex-end;gap:10px">
          <button class="btn btn-secondary" type="button" data-close-btn>Batal</button>
          <button class="btn btn-primary" type="button" id="e-save">Simpan</button>
        </div>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);
    m.$('[data-close-btn]').addEventListener('click', m.close);
    const syncPassField = () => {
      const isAdm = m.$('#e-peran').value === 'admin';
      m.$('#e-sandi-wrap').hidden = !isAdm;
    };
    m.$('#e-peran').addEventListener('change', syncPassField);
    syncPassField();
    m.$('#e-save').addEventListener('click', async () => {
      const errBox = m.$('#e-err'); errBox.hidden = true;
      const nama = m.$('#e-nama').value.trim();
      const email = m.$('#e-email').value.trim();
      const peran = m.$('#e-peran').value;
      const sandi = m.$('#e-sandi').value;
      if (!nama) { errBox.textContent = 'Nama lengkap wajib diisi.'; errBox.hidden = false; return; }
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errBox.textContent = 'Masukkan alamat email yang valid.'; errBox.hidden = false; return;
      }
      if (peran === 'admin' && ((!edit && sandi.length < 8) || (edit && sandi !== '' && sandi.length < 8))) {
        errBox.textContent = 'Kata sandi Admin minimal 8 karakter.'; errBox.hidden = false; return;
      }
      try {
        const res = await Api.post('karyawan.save', {
          id: u?.id || 0,
          nama,
          email,
          peran: m.$('#e-peran').value,
          tim: m.$('#e-tim').value,
          jabatan: m.$('#e-jabatan').value,
          kata_sandi: sandi,
          aktif: m.$('#e-aktif').checked ? 1 : 0
        });
        m.close();
        const list = await reload();
        if (!edit && peran === 'karyawan' && Array.isArray(list)) {
          const created = list.find((x) => Number(x.id) === Number(res.id));
          if (created && created.kode_qr) {
            toast('Karyawan ditambahkan & QR pribadi otomatis dibuat');
            openQrCard(created, reload);
            return;
          }
        }
        toast('Data karyawan berhasil disimpan');
      } catch (e) { errBox.textContent = e.message; errBox.hidden = false; }
    });
  }

  // ---------------- Catat Izin / Sakit (Admin Only) ----------------
  async function renderIzinSection(container) {
    const [empRes, izinRes] = await Promise.all([
      Api.get('karyawan.list').catch(() => ({ data: [] })),
      Api.get('absensi.izin_list').catch(() => ({ data: [] }))
    ]);
    const karyawanList = (empRes.data || []).filter((k) => k.peran !== 'admin' && Number(k.aktif) === 1);
    const riwayatIzin = izinRes.data || [];

    container.innerHTML = `
      <div class="grid grid-2">
        <div class="glass card">
          <div class="card-head"><h2>Form Izin / Sakit</h2></div>
          <form class="form" id="izin-form">
            <label class="field">
              <span>Pilih Nama Karyawan</span>
              <select id="i-pengguna" required ${karyawanList.length ? '' : 'disabled'}>
                ${karyawanList.length ? karyawanList.map((k) => `<option value="${k.id}">${esc(k.nama)} — ${esc(k.tim)}</option>`).join('') : '<option value="">Belum ada karyawan aktif</option>'}
              </select>
            </label>
            <label class="field">
              <span>Alasan Tidak Masuk</span>
              <select id="i-jenis">
                <option value="izin">Izin (Keperluan Keluarga / Cuti)</option>
                <option value="sakit">Sakit</option>
              </select>
            </label>
            <div class="field-row">
              <label class="field"><span>Mulai Tanggal</span><input type="date" id="i-mulai" value="${todayISO()}"></label>
              <label class="field"><span>Sampai Tanggal</span><input type="date" id="i-sampai" value="${todayISO()}"></label>
            </div>
            <label class="field"><span>Keterangan</span><textarea id="i-catatan" required placeholder="Contoh: Sakit demam (ada surat dokter) atau izin acara keluarga"></textarea></label>
            <div class="field">
              <span>Foto Surat / Bukti (Opsional)</span>
              <button type="button" class="btn btn-secondary btn-sm" id="i-foto" style="width:fit-content">Ambil / Pilih Foto</button>
              <img id="i-preview" class="shot-preview" style="max-width:220px;margin-top:10px;display:none" alt="Foto bukti">
            </div>
            <div class="alert alert-error" id="i-err" hidden></div>
            <button class="btn btn-primary" type="submit">Simpan Data Izin / Sakit</button>
          </form>
        </div>

        <div class="glass card">
          <div class="card-head">
            <h2>Daftar Karyawan yang Pernah Izin / Sakit</h2>
            <span class="pill-note">${riwayatIzin.length} data</span>
          </div>
          ${riwayatIzin.length ? `
          <div class="table-wrap"><table>
            <thead><tr><th>Tanggal</th><th>Nama Karyawan</th><th>Status</th><th>Keterangan</th></tr></thead>
            <tbody>${riwayatIzin.map((r) => `<tr>
              <td>${fmtTanggal(r.tanggal)}</td>
              <td style="font-weight:600">${esc(r.nama)}</td>
              <td>${badge(r.status)}</td>
              <td class="wrap">${esc(r.catatan || '—')}</td>
            </tr>`).join('')}</tbody>
          </table></div>` : '<p class="muted small">Belum ada catatan karyawan yang izin atau sakit.</p>'}
        </div>
      </div>`;

    let foto = null;
    byId('i-foto').addEventListener('click', async () => {
      try {
        const shot = await Scanner.open({ wajibBarcode: false, wajibFoto: true, title: 'Foto Bukti Izin / Sakit' });
        foto = shot.foto;
        const img = byId('i-preview');
        img.src = foto; img.style.display = 'block';
      } catch (e) { if (e.message !== 'dibatalkan') toast(e.message, 'err'); }
    });

    byId('izin-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errBox = byId('i-err'); errBox.hidden = true;
      const mulai = byId('i-mulai').value;
      const sampai = byId('i-sampai').value;
      const catatan = byId('i-catatan').value.trim();
      if (!karyawanList.length) {
        errBox.textContent = 'Belum ada karyawan aktif untuk dicatat izin/sakit.';
        errBox.hidden = false;
        return;
      }
      if (!mulai || !sampai) {
        errBox.textContent = 'Tanggal mulai dan selesai wajib diisi.';
        errBox.hidden = false;
        return;
      }
      if (sampai < mulai) {
        errBox.textContent = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
        errBox.hidden = false;
        return;
      }
      const diffDays = Math.round((new Date(sampai + 'T00:00:00') - new Date(mulai + 'T00:00:00')) / 86400000);
      if (diffDays > 30) {
        errBox.textContent = 'Rentang pengajuan maksimal 30 hari.';
        errBox.hidden = false;
        return;
      }
      if (!catatan) {
        errBox.textContent = 'Mohon isi keterangan izin/sakit.';
        errBox.hidden = false;
        return;
      }
      try {
        const payload = {
          jenis: byId('i-jenis').value,
          mulai,
          sampai,
          catatan,
          foto,
          pengguna_id: Number(byId('i-pengguna').value)
        };
        const r = await Api.post('absensi.izin', payload);
        toast(`${r.pesan} (${r.data.hari} hari)`);
        go('izin');
      } catch (err) {
        errBox.textContent = err.message;
        errBox.hidden = false;
      }
    });
  }

  // ---------------- Tema & Jam ----------------
  function initTheme() {
    const toggleBtn = byId('theme-toggle');
    const syncLabel = (theme) => {
      if (toggleBtn) {
        toggleBtn.innerHTML = theme === 'dark'
          ? `${ico('sun', 14)}<span>Mode Terang</span>`
          : `${ico('moon', 14)}<span>Mode Gelap</span>`;
      }
    };
    const saved = localStorage.getItem('absensi-theme') || 'light';
    document.documentElement.dataset.theme = saved;
    syncLabel(saved);
    toggleBtn.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      localStorage.setItem('absensi-theme', next);
      syncLabel(next);
      window.dispatchEvent(new CustomEvent('themechange', { detail: next }));
    });
  }

  function initTopbarClock() {
    const el = byId('top-clock');
    if (!el) return;
    const update = () => {
      const now = new Date();
      const jam = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')} WIB`;
      el.textContent = `${fmtTanggalLengkap(now)} · ${jam}`;
    };
    update();
    setInterval(update, 1000);
  }

  async function init() {
    initTheme();
    initTopbarClock();
    byId('logout-btn').addEventListener('click', async () => {
      try { await Api.post('auth.logout', {}); } catch (e) {}
      location.href = 'login.html';
    });

    try {
      const meta = await Api.get('meta');
      state.meta = meta; state.user = meta.pengguna; state.org = meta.organisasi;
      state.presets = meta.preset; state.hariLabels = meta.hari;
    } catch (e) {
      location.href = 'login.html';
      return;
    }

    byId('side-org').textContent = state.org.nama;
    if (byId('top-org')) byId('top-org').textContent = state.org.nama;
    if (byId('top-schedule')) byId('top-schedule').innerHTML = `${ico('clock', 14)}<span>Jam Kerja ${esc(state.org.jam_masuk)}–${esc(state.org.jam_pulang)} WIB</span>`;
    byId('user-name').textContent = state.user.nama;
    byId('user-role').textContent = PERAN_LABEL[state.user.peran] || state.user.peran;
    byId('user-avatar').textContent = (state.user.nama || 'A').charAt(0).toUpperCase();

    const def = 'dasbor';
    await go(def);
  }

  return { init, go };
})();

App.init();
