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
  const PERAN_LABEL = { karyawan: 'Karyawan', atasan: 'Atasan / Supervisor', admin: 'Admin Kantor' };
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
    const s = status || 'alpa';
    return `<span class="badge badge-${s}">${STATUS_LABEL[s] || s}</span>`;
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
    moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'
  };

  function ico(name, size = 18) {
    return `<svg class="ico" viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] || ''}</svg>`;
  }

  // ---------------- Menu Navigasi Korporat ----------------
  const ROUTES = {
    beranda: { label: 'Absen Saya', group: 'Ruang Kerja', roles: ['karyawan'], icon: 'home', render: viewBeranda },
    dasbor: { label: 'Beranda', group: 'Pemantauan', roles: ['admin', 'atasan'], icon: 'dash', render: viewDasbor },
    harian: { label: 'Absen Hari Ini', group: 'Pemantauan', roles: ['admin', 'atasan'], icon: 'clock', render: viewHarian },
    rekap: { label: 'Rekap Bulanan', group: 'Laporan & Analitik', roles: ['admin', 'atasan'], icon: 'chart', render: viewRekap },
    axel: { label: 'Riwayat Absen', group: 'Laporan & Analitik', roles: ['admin', 'atasan'], icon: 'db', render: viewAxel },
    kmeans: { label: 'Penilaian Disiplin', group: 'Laporan & Analitik', roles: ['admin', 'atasan'], icon: 'nodes', render: viewKmeans },
    izin: { label: 'Izin / Sakit', group: 'Pengelolaan', roles: ['karyawan', 'admin', 'atasan'], icon: 'cal', render: viewIzin },
    karyawan: { label: 'Daftar Karyawan', group: 'Administrasi', roles: ['admin'], icon: 'users', render: viewKaryawan },
    pengaturan: { label: 'Pengaturan Kantor', group: 'Administrasi', roles: ['admin'], icon: 'gear', render: viewPengaturan }
  };

  function allowedRoutes() {
    return Object.entries(ROUTES).filter(([, r]) => r.roles.includes(state.user.peran));
  }

  function renderNav() {
    const items = allowedRoutes();
    let lastGroup = '';
    const desktopHtml = items.map(([key, r]) => {
      const grp = state.user.peran === 'karyawan' ? 'Ruang Kerja' : r.group;
      const head = grp !== lastGroup ? `<div class="nav-group-label">${esc(grp)}</div>` : '';
      lastGroup = grp;
      return `${head}<button data-route="${key}" class="${state.route === key ? 'active' : ''}">${ico(r.icon)}<span>${r.label}</span></button>`;
    }).join('');
    byId('nav-desktop').innerHTML = desktopHtml;
    const tabs = items.slice(0, 5);
    byId('nav-mobile').innerHTML = tabs.map(([key, r]) =>
      `<button data-route="${key}" class="${state.route === key ? 'active' : ''}">${ico(r.icon)}<span>${r.label}</span></button>`
    ).join('');
    byId('nav-desktop').querySelectorAll('[data-route]').forEach((b) =>
      b.addEventListener('click', () => go(b.dataset.route)));
    byId('nav-mobile').querySelectorAll('[data-route]').forEach((b) =>
      b.addEventListener('click', () => go(b.dataset.route)));
  }

  async function go(route) {
    if (!ROUTES[route] || !ROUTES[route].roles.includes(state.user.peran)) return;
    state.route = route;
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

  // ---------------- Absen Saya (Karyawan WFO & WFH) ----------------
  async function viewBeranda(view) {
    const res = await Api.get('absensi.today');
    const d = res.data;
    const rec = d.record || {};
    const now = new Date();
    const sapaan = now.getHours() < 11 ? 'Selamat pagi' : now.getHours() < 15 ? 'Selamat siang' : now.getHours() < 19 ? 'Selamat sore' : 'Selamat malam';

    const stateAbsen = !rec.jam_masuk ? 'in' : (!rec.jam_pulang ? 'out' : 'done');
    const activeMode = (rec.mode || 'wfo').toLowerCase() === 'wfh' ? 'wfh' : 'wfo';
    let selectedMode = stateAbsen === 'in' ? 'wfo' : activeMode;

    const btnLabel = stateAbsen === 'in' ? 'Absen Masuk Sekarang' : stateAbsen === 'out' ? 'Absen Pulang Sekarang' : 'Presensi Hari Ini Selesai';
    const wfoRuleHint = [
      d.wajib_barcode ? 'Scan QR kantor' : null,
      d.wajib_foto ? 'foto kehadiran' : null,
      d.radius_masuk_m > 0 ? `area kantor maks ${d.radius_masuk_m} m` : (d.wajib_gps ? 'GPS aktif' : null)
    ].filter(Boolean).join(', ') || 'konfirmasi langsung';
    const wfhRuleHint = `Tanpa QR kantor, ${d.wajib_foto ? 'foto kehadiran, ' : ''}wajib isi catatan kegiatan`;

    view.innerHTML = `
      ${pageHead(`${sapaan}, ${state.user.nama.split(' ')[0]}`, `${state.user.tim || 'Karyawan'} · ${fmtTanggal(d.tanggal)}`)}
      <div class="stack">
        <div class="beranda-grid">
          <div class="glass card clock-card">
            <div class="clock-header">
              <div>
                <div class="small muted" style="font-weight:600">Waktu Lokal (WIB)</div>
                <div class="clock-time tabular" id="clock">--:--:--</div>
              </div>
              <div style="text-align:right">
                <div class="pill-note">${d.hari_kerja ? `Jam Kantor ${esc(d.jam_masuk_jadwal)}–${esc(d.jam_pulang_jadwal)}` : 'Hari Libur Kantor'}</div>
                <div class="clock-date" style="margin-top:4px">Batas toleransi ${d.toleransi_menit} menit</div>
              </div>
            </div>

            ${stateAbsen === 'in' ? `
              <div class="field">
                <span>Pilih Mode Kerja Hari Ini</span>
                <div class="mode-switch" id="beranda-mode-switch">
                  <button type="button" class="mode-opt active" data-mode="wfo">
                    ${ico('building', 18)}
                    <div>
                      <span class="mode-opt-title">Di Kantor (WFO)</span>
                      <span class="mode-opt-desc">Hadir langsung di kantor</span>
                    </div>
                  </button>
                  <button type="button" class="mode-opt" data-mode="wfh">
                    ${ico('laptop', 18)}
                    <div>
                      <span class="mode-opt-title">Work From Home (WFH)</span>
                      <span class="mode-opt-desc">Kerja jarak jauh + kegiatan</span>
                    </div>
                  </button>
                </div>
                <div class="muted small" id="mode-hint" style="margin-top:4px">Syarat WFO: ${esc(wfoRuleHint)}.</div>
              </div>
              <label class="field">
                <span id="kegiatan-label">Rencana / Catatan Kegiatan (Opsional untuk WFO)</span>
                <textarea id="beranda-kegiatan" placeholder="Contoh: Review laporan keuangan bulanan dan sinkronisasi tim"></textarea>
              </label>
            ` : stateAbsen === 'out' ? `
              <div class="activity-box" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <span>Anda sudah absen masuk jam <b>${fmtJam(rec.jam_masuk)}</b></span>
                ${modeBadge(activeMode)}
              </div>
              <label class="field">
                <span>Ringkasan Kegiatan Hari Ini ${activeMode === 'wfh' ? '(Wajib diisi untuk WFH)' : '(Opsional)'}</span>
                <textarea id="beranda-kegiatan" placeholder="Tuliskan pekerjaan yang diselesaikan hari ini">${esc(rec.kegiatan || '')}</textarea>
              </label>
            ` : `
              <div class="alert alert-ok" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <span>Masuk jam <b>${fmtJam(rec.jam_masuk)}</b> · Pulang jam <b>${fmtJam(rec.jam_pulang)}</b></span>
                ${modeBadge(activeMode)}
              </div>
              ${rec.kegiatan ? `<div class="activity-box"><div class="small muted" style="font-weight:600;margin-bottom:3px">Catatan Kegiatan</div>${esc(rec.kegiatan)}</div>` : ''}
            `}

            <div class="absen-actions">
              <button class="btn ${stateAbsen === 'done' ? 'btn-secondary' : 'btn-primary'} btn-lg big-cta" id="absen-btn" ${stateAbsen === 'done' ? 'disabled' : ''}>
                ${stateAbsen === 'done' ? btnLabel : `${ico('clock')} ${btnLabel}`}
              </button>
            </div>
          </div>

          <div class="stack">
            <div class="glass card">
              <div class="card-head">
                <h2>Status Kehadiran Hari Ini</h2>
                ${rec.status ? badge(rec.status) : '<span class="badge badge-netral">Belum Absen</span>'}
              </div>
              <div class="today-summary-list">
                <div class="today-summary-item">
                  <span class="muted">Mode Kerja</span>
                  <span>${rec.jam_masuk ? modeBadge(activeMode) : '<span class="muted small">Belum dipilih</span>'}</span>
                </div>
                <div class="today-summary-item">
                  <span class="muted">Jam Masuk</span>
                  <span class="tabular" style="font-weight:700">${fmtJam(rec.jam_masuk)}</span>
                </div>
                <div class="today-summary-item">
                  <span class="muted">Jam Pulang</span>
                  <span class="tabular" style="font-weight:700">${fmtJam(rec.jam_pulang)}</span>
                </div>
              </div>
            </div>

            <div class="mini-stats">
              <div class="glass mini-stat"><div class="value" style="color:var(--status-hadir)">${res.ringkasan.hadir}</div><div class="label">Tepat Waktu</div></div>
              <div class="glass mini-stat"><div class="value" style="color:var(--status-terlambat)">${res.ringkasan.terlambat}</div><div class="label">Terlambat</div></div>
              <div class="glass mini-stat"><div class="value" style="color:var(--status-alpa)">${res.ringkasan.tidak_hadir}</div><div class="label">Izin / Alpa</div></div>
            </div>
          </div>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>Riwayat Presensi 7 Hari Terakhir</h2><span class="pill-note">${res.riwayat.length} hari</span></div>
          ${res.riwayat.length ? `<div class="table-wrap"><table>
            <thead><tr><th>Tanggal</th><th>Mode</th><th>Masuk</th><th>Pulang</th><th>Catatan Kegiatan</th><th>Status</th></tr></thead>
            <tbody>${res.riwayat.map((r) => `<tr>
              <td>${fmtTanggal(r.tanggal)}</td>
              <td>${modeBadge(r.mode)}</td>
              <td class="tabular">${fmtJam(r.jam_masuk)}</td>
              <td class="tabular">${fmtJam(r.jam_pulang)}</td>
              <td class="wrap">${esc(r.kegiatan || r.catatan || '—')}</td>
              <td>${badge(r.status)}</td>
            </tr>`).join('')}</tbody>
          </table></div>` : '<p class="muted small">Belum ada catatan absen.</p>'}
        </div>
      </div>`;

    const clock = byId('clock');
    const tick = () => {
      const n = new Date();
      if (clock) clock.textContent = `${String(n.getHours()).padStart(2, '0')}:${String(n.getMinutes()).padStart(2, '0')}:${String(n.getSeconds()).padStart(2, '0')}`;
    };
    tick();
    const timer = setInterval(() => { if (!document.body.contains(clock)) clearInterval(timer); else tick(); }, 1000);

    const modeSwitch = byId('beranda-mode-switch');
    if (modeSwitch) {
      modeSwitch.querySelectorAll('[data-mode]').forEach((mBtn) => {
        mBtn.addEventListener('click', () => {
          selectedMode = mBtn.dataset.mode === 'wfh' ? 'wfh' : 'wfo';
          modeSwitch.querySelectorAll('[data-mode]').forEach((b) => b.classList.toggle('active', b === mBtn));
          byId('mode-hint').textContent = selectedMode === 'wfh' ? `Syarat WFH: ${wfhRuleHint}.` : `Syarat WFO: ${wfoRuleHint}.`;
          byId('kegiatan-label').textContent = selectedMode === 'wfh'
            ? 'Rencana / Catatan Kegiatan Hari Ini (Wajib untuk WFH)'
            : 'Rencana / Catatan Kegiatan (Opsional untuk WFO)';
        });
      });
    }

    const btn = byId('absen-btn');
    if (stateAbsen === 'in' || stateAbsen === 'out') {
      btn.addEventListener('click', async () => {
        const kegiatanVal = (byId('beranda-kegiatan')?.value || '').trim();
        const modeUsed = stateAbsen === 'in' ? selectedMode : activeMode;
        if (modeUsed === 'wfh' && kegiatanVal.length < 3) {
          toast('Mohon isi catatan kegiatan minimal 3 karakter untuk absen WFH.', 'err');
          byId('beranda-kegiatan')?.focus();
          return;
        }
        btn.disabled = true;
        try {
          const shot = await Scanner.open({
            mode: modeUsed,
            wajibBarcode: d.wajib_barcode,
            wajibFoto: d.wajib_foto,
            wajibGps: d.wajib_gps,
            radius: d.radius_masuk_m,
            latKantor: d.lat_kantor,
            lngKantor: d.lng_kantor,
            kodeLokasi: d.kode_lokasi,
            title: `${stateAbsen === 'in' ? 'Absen Masuk' : 'Absen Pulang'} (${modeUsed.toUpperCase()})`
          });
          const action = stateAbsen === 'in' ? 'absensi.checkin' : 'absensi.checkout';
          const payload = {
            mode: modeUsed,
            kegiatan: kegiatanVal,
            barcode: shot.barcode,
            foto: shot.foto,
            palsu: !!shot.palsu
          };
          if (shot.gps) {
            payload.lat = shot.gps.lat;
            payload.lng = shot.gps.lng;
            payload.akurasi = shot.gps.akurasi;
          }
          const r = await Api.post(action, payload);
          toast(r.pesan || 'Absen berhasil disimpan');
          await go('beranda');
        } catch (err) {
          if (err.message !== 'dibatalkan') toast(err.message, 'err');
          btn.disabled = false;
        }
      });
    }
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
        { val: jmlIzinSakit, color: '#0284c7' },
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

    view.innerHTML = `
      ${pageHead('Beranda', `Ringkasan kehadiran karyawan ${state.org.nama} hari ini (${fmtTanggal(todayISO())})`, `
        <button class="btn btn-secondary btn-sm" id="dash-go-harian">Cek Absen Hari Ini</button>
        <button class="btn btn-primary btn-sm" id="dash-go-laporan">Lihat Semua Riwayat</button>
      `)}
      <div class="stack">
        <div class="grid grid-4">
          <div class="glass card metric accent-hadir">
            <div class="label">Sudah Masuk Hari Ini</div>
            <div class="value">${totalHadirHariIni} <span style="font-size:14px;font-weight:500;color:var(--text-muted)">dari ${totalKaryawan} orang</span></div>
            <div class="sublabel">${pctHadir}% hadir · WFO: ${h.wfo || 0} · WFH: ${h.wfh || 0}</div>
          </div>
          <div class="glass card metric accent-terlambat">
            <div class="label">Datang Terlambat</div>
            <div class="value">${jmlTerlambat} <span style="font-size:14px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="sublabel">Lewat batas toleransi ${state.org.toleransi_menit} menit</div>
          </div>
          <div class="glass card metric accent-izin">
            <div class="label">Izin / Sakit</div>
            <div class="value">${jmlIzinSakit} <span style="font-size:14px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="sublabel">Izin: ${h.izin || 0} · Sakit: ${h.sakit || 0}</div>
          </div>
          <div class="glass card metric accent-alpa">
            <div class="label">Belum Absen</div>
            <div class="value">${jmlBelum} <span style="font-size:14px;font-weight:500;color:var(--text-muted)">orang</span></div>
            <div class="sublabel">Belum absen: ${h.belum} · Tidak masuk: ${h.alpa}</div>
          </div>
        </div>

        <div class="dash-charts-grid">
          <div class="glass card">
            <div class="card-head">
              <div>
                <h2>Ringkasan Hari Ini</h2>
                <p class="muted small">Pembagian status seluruh karyawan</p>
              </div>
              <span class="pill-note">${totalKaryawan} orang</span>
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
                  <span class="donut-row-l"><i class="donut-dot" style="background:#0284c7"></i>Izin / Sakit</span>
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
              <div style="display:flex;gap:6px;align-items:center">
                <button class="btn btn-primary btn-sm" data-range="7">7 Hari</button>
                <button class="btn btn-secondary btn-sm" data-range="14">14 Hari</button>
                <button class="btn btn-secondary btn-sm" data-range="30">30 Hari</button>
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
            <h2>Karyawan Terlambat / Tidak Masuk Hari Ini (${fmtTanggal(todayISO())})</h2>
            <span class="pill-note">${data.perlu_dicek.length} orang</span>
          </div>
          <div class="riwayat">
            ${data.perlu_dicek.length ? data.perlu_dicek.map((r) => {
              const { shiftLabel } = parseCatatanBukti(r);
              return `
              <div class="riwayat-row">
                <div>
                  <div class="t">${esc(r.nama)}</div>
                  <div class="s">${r.jam_masuk ? 'Masuk jam ' + fmtJam(r.jam_masuk) : 'Belum ada jam masuk'} ${shiftLabel ? '· ' + esc(shiftLabel) : ''}</div>
                </div>
                ${badge(r.status)}
              </div>`;
            }).join('') : '<p class="muted small">Tidak ada yang terlambat hari ini. Semua karyawan hadir tepat waktu.</p>'}
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
          b.className = b === btn ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
        });
        renderTrendChart();
      });
    });

    renderTrendChart();

    byId('dash-go-harian')?.addEventListener('click', () => go('harian'));
    byId('dash-go-laporan')?.addEventListener('click', () => go('axel'));
  }

  // ---------------- Absen Hari Ini ----------------
  async function viewHarian(view) {
    const initialDate = state.selectedHarianDate || todayISO();
    state.selectedHarianDate = null;
    view.innerHTML = `${pageHead('Absen Hari Ini', 'Pilih tanggal untuk melihat siapa saja yang sudah absen, cek foto bukti, atau ubah data jika ada kesalahan', `
      <label class="field"><span>Pilih Tanggal</span><input type="date" id="harian-tgl" value="${initialDate}"></label>
      <button class="btn btn-primary" id="harian-load">Lihat Data</button>`)}
      <div class="glass card" id="harian-box"><p class="muted">Sedang memuat data…</p></div>`;

    let allRows = [];
    let currentTgl = initialDate;

    function renderHarianTable() {
      const box = byId('harian-box');
      const q = (byId('harian-search')?.value || '').trim().toLowerCase();
      const rows = q
        ? allRows.filter((r) => (r.nama || '').toLowerCase().includes(q) || (r.tim || '').toLowerCase().includes(q))
        : allRows;
      const hadirCount = allRows.filter((r) => r.status === 'hadir' || r.status === 'terlambat').length;

      const tbody = byId('harian-tbody');
      if (tbody) {
        tbody.innerHTML = rows.map((r, idx) => `<tr>
          <td class="muted tabular">${idx + 1}</td>
          <td>${empCell(r.nama)}</td>
          <td>${esc(r.tim)}</td>
          <td>${r.status ? badge(r.status) : '<span class="badge badge-netral">Belum Absen</span>'}</td>
          <td class="tabular">${fmtJam(r.jam_masuk)}</td>
          <td class="tabular">${fmtJam(r.jam_pulang)}</td>
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
        </tr>`).join('') || '<tr><td colspan="9" class="muted">Tidak ada data karyawan sesuai pencarian.</td></tr>';
        tbody.querySelectorAll('[data-koreksi]').forEach((b) =>
          b.addEventListener('click', () => openKoreksi(b.dataset, currentTgl, load)));
        tbody.querySelectorAll('[data-photo]').forEach((img) =>
          img.addEventListener('click', () => viewPhoto(img.dataset.photo)));
        return;
      }

      box.innerHTML = `
        <div class="card-head" style="flex-wrap:wrap">
          <div>
            <h2>Daftar Kehadiran · ${fmtTanggal(currentTgl)}</h2>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="search" id="harian-search" placeholder="Cari nama atau divisi…" style="min-height:34px;width:210px">
            <span class="pill-note">Sudah Masuk: ${hadirCount} dari ${allRows.length} orang</span>
          </div>
        </div>
        <div class="table-wrap"><table>
          <thead>
            <tr>
              <th>No</th>
              <th>Nama Karyawan</th>
              <th>Divisi</th>
              <th>Status</th>
              <th>Jam Masuk</th>
              <th>Jam Pulang</th>
              <th>Mode &amp; Kegiatan</th>
              <th>Foto Bukti</th>
              <th>Ubah Data</th>
            </tr>
          </thead>
          <tbody id="harian-tbody"></tbody>
        </table></div>`;

      byId('harian-search').addEventListener('input', renderHarianTable);
      renderHarianTable();
    }

    async function load() {
      currentTgl = byId('harian-tgl').value || todayISO();
      const box = byId('harian-box');
      if (!byId('harian-tbody')) {
        box.innerHTML = '<p class="muted">Sedang memuat data…</p>';
      }
      const { data } = await Api.get('absensi.harian', { tanggal: currentTgl });
      allRows = data || [];
      box.innerHTML = '';
      renderHarianTable();
    }
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

  // ---------------- Rekap Bulanan ----------------
  async function viewRekap(view) {
    const curYear = new Date().getFullYear();
    const timList = (await Api.get('tim.list').catch(() => ({ data: [] }))).data;
    view.innerHTML = `
      ${pageHead('Rekap Bulanan', 'Total hari masuk, tidak masuk, dan rata-rata keterlambatan setiap karyawan', `
        <button class="btn btn-secondary" id="rekap-csv">Unduh CSV</button>
        <button class="btn btn-primary" id="rekap-xls">Unduh Excel</button>`)}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari Tanggal</span><input type="date" id="r-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai Tanggal</span><input type="date" id="r-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Divisi</span><select id="r-tim"><option value="">Semua Divisi</option>${timList.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select></label>
            <button class="btn btn-primary" id="r-load">Tampilkan Rekap</button>
            <button class="btn btn-ghost" id="r-all-year">Lihat Setahun Penuh (${curYear})</button>
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
              <th>Nama Karyawan</th>
              <th>Divisi</th>
              <th>Jumlah Masuk</th>
              <th>Tidak Masuk</th>
              <th>Rata-rata Terlambat</th>
              <th>Persentase Tidak Masuk</th>
              <th>Rata-rata Lama Kerja</th>
            </tr>
          </thead>
          <tbody>${rows.map((r, i) => `<tr>
            <td class="muted tabular">${i + 1}</td>
            <td>${empCell(r.nama)}</td>
            <td>${esc(r.tim)}</td>
            <td class="tabular"><span class="badge badge-hadir">${r.hari_tercatat} hari</span></td>
            <td class="tabular">${r.hari_tidak_hadir} hari</td>
            <td class="tabular">${r.rata_terlambat} menit</td>
            <td class="tabular">${r.persen_tidak_hadir}%</td>
            <td class="tabular">${r.rata_jam_kerja} jam/hari</td>
          </tr>`).join('') || '<tr><td colspan="8" class="muted">Tidak ada data pada tanggal yang dipilih.</td></tr>'}</tbody>
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

  // ---------------- Penilaian Disiplin (K-Means untuk Orang Awam) ----------------
  async function viewKmeans(view) {
    const curYear = new Date().getFullYear();
    const [timRes, savedRes] = await Promise.all([
      Api.get('tim.list').catch(() => ({ data: [] })),
      Api.get('klaster.list').catch(() => ({ data: [] }))
    ]);
    const timList = timRes.data || [];
    let savedList = savedRes.data || [];

    view.innerHTML = `
      ${pageHead('Penilaian Disiplin Karyawan', 'Pengelompokan otomatis karyawan yang rajin dan yang perlu diingatkan berdasarkan data absen', `
        <button class="btn btn-secondary" id="k-exp-xls">Unduh Excel</button>
        <button class="btn btn-primary" id="k-simpan" disabled>Simpan Hasil Penilaian</button>
      `)}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari Tanggal</span><input type="date" id="k-dari" value="${curYear}-01-01"></label>
            <label class="field"><span>Sampai Tanggal</span><input type="date" id="k-sampai" value="${todayISO()}"></label>
            <label class="field"><span>Divisi</span><select id="k-tim"><option value="">Semua Divisi</option>${timList.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select></label>
            <label class="field"><span>Bagi Menjadi</span><select id="k-k">
              <option value="2">2 Kategori (Disiplin &amp; Perlu Diingatkan)</option>
              <option value="3" selected>3 Kategori (Disiplin, Cukup, Perlu Diingatkan)</option>
              <option value="4">4 Kategori (Sangat Disiplin s/d Perlu Diingatkan)</option>
            </select></label>
            <button class="btn btn-primary" id="k-siapkan">Tampilkan Penilaian</button>
          </div>
          <p class="muted small" style="margin-top:10px">
            <b>Cara Kerja Sederhana:</b> Sistem menilai karyawan yang sudah masuk minimal 10 hari berdasarkan 3 hal: <b>(1) seberapa sering terlambat</b>, <b>(2) seberapa sering tidak masuk</b>, dan <b>(3) rata-rata jam kerjanya</b>.
          </p>
        </div>

        <div class="grid grid-3" id="k-hasil"></div>

        <div class="glass card">
          <div class="card-head">
            <div>
              <h2>Tabel Nilai &amp; Kategori Disiplin per Karyawan</h2>
              <p class="muted small">Diurutkan dari karyawan yang paling disiplin hingga yang perlu diingatkan</p>
            </div>
            <span class="pill-note" id="k-emp-count">0 karyawan</span>
          </div>
          <div class="table-wrap" id="k-detail-table"><p class="muted">Sedang menghitung penilaian…</p></div>
        </div>

        <div class="glass card">
          <div class="card-head">
            <div>
              <h2>Grafik Posisi Kedisiplinan Karyawan</h2>
              <p class="muted small">Semakin ke <b>kiri-bawah</b> posisi nama karyawan = semakin disiplin. Semakin ke <b>kanan-atas</b> = lebih sering terlambat / tidak masuk.</p>
            </div>
          </div>
          <div class="canvas-wrap"><canvas class="chart" id="k-canvas" width="800" height="340"></canvas></div>
          <div class="bar-legend">
            <span><i class="legend-dot" style="background:#15803d;border-radius:50%"></i>Hijau = Disiplin (Rajin)</span>
            <span><i class="legend-dot" style="background:#d97706;border-radius:50%"></i>Kuning = Cukup Baik</span>
            <span><i class="legend-dot" style="background:#dc2626;border-radius:50%"></i>Merah = Perlu Diingatkan</span>
          </div>
        </div>

        <div class="glass card">
          <div class="card-head">
            <h2>Arsip Penilaian yang Pernah Disimpan</h2>
            <span class="pill-note" id="k-saved-count">${savedList.length} arsip</span>
          </div>
          <div id="k-saved-box"></div>
        </div>

        <details class="glass card" style="cursor:pointer">
          <summary style="font-weight:600;color:var(--text-secondary)">Lihat Rincian Rumus Teknis (Metode K-Means — Opsional)</summary>
          <div style="margin-top:14px;cursor:default" class="stack">
            <p class="muted small">Bagian ini hanya diperlukan bila Anda ingin melihat angka rumus perhitungan K-Means (Titik Pusat / Centroid dan Jarak Euclidean).</p>
            <div id="k-centroid-box"></div>
            <div id="k-tech-table"></div>
          </div>
        </details>
      </div>`;

    const canvas = byId('k-canvas');
    let data = [], ineligibleData = [], seed = 202611, engine = null;

    function renderSavedHistory() {
      const box = byId('k-saved-box');
      byId('k-saved-count').textContent = `${savedList.length} arsip`;
      if (!savedList.length) {
        box.innerHTML = '<p class="muted small">Belum ada hasil penilaian yang disimpan. Klik tombol <b>"Simpan Hasil Penilaian"</b> di kanan atas bila ingin menyimpan.</p>';
        return;
      }
      box.innerHTML = `<div class="table-wrap"><table>
        <thead><tr><th>No</th><th>Periode Tanggal</th><th>Jumlah Kategori</th><th>Ringkasan Hasil</th><th>Tanggal Disimpan</th></tr></thead>
        <tbody>${savedList.map((s, idx) => `<tr>
          <td class="muted tabular">${idx + 1}</td>
          <td style="font-weight:600">${esc(s.periode)}</td>
          <td><span class="pill-note">${s.k} Kategori</span></td>
          <td class="wrap">${(s.hasil || []).map((g) => `<span class="badge" style="background:${g.color || '#64748b'};color:#fff;margin-right:6px">${esc(g.label)}: ${g.jumlah} orang</span>`).join('')}</td>
          <td class="muted small tabular">${esc(s.dibuat_pada)}</td>
        </tr>`).join('')}</tbody>
      </table></div>`;
    }
    renderSavedHistory();

    function draw() {
      if (!document.body.contains(canvas)) return;
      const ctx = canvas.getContext('2d');
      const dpr = window.devicePixelRatio || 1;
      const rect = canvas.getBoundingClientRect();
      if (!rect.width) return;
      const isDark = document.documentElement.dataset.theme === 'dark';
      const H = 340;
      canvas.width = rect.width * dpr;
      canvas.height = H * dpr;
      ctx.scale(dpr, dpr);
      const W = rect.width;
      const padL = 60, padR = 30, padT = 22, padB = 44;
      const plotW = W - padL - padR;
      const plotH = H - padT - padB;
      ctx.clearRect(0, 0, W, H);

      ctx.strokeStyle = isDark ? 'rgba(148, 163, 184, 0.2)' : 'rgba(148, 163, 184, 0.25)';
      ctx.fillStyle = isDark ? '#94a3b8' : '#64748b';
      ctx.font = '11px "Plus Jakarta Sans", sans-serif';
      ctx.lineWidth = 1;

      const minX = engine ? engine.norm.min.persen_tidak_hadir : 0;
      const maxX = engine ? engine.norm.max.persen_tidak_hadir : 100;
      const minY = engine ? engine.norm.min.rata_terlambat : 0;
      const maxY = engine ? engine.norm.max.rata_terlambat : 60;

      for (let i = 0; i <= 4; i++) {
        const frac = i / 4;
        const gy = padT + plotH * (1 - frac);
        ctx.beginPath();
        ctx.moveTo(padL, gy);
        ctx.lineTo(padL + plotW, gy);
        ctx.stroke();
        const yVal = (minY + frac * (maxY - minY)).toFixed(0) + ' mnt';
        ctx.textAlign = 'right';
        ctx.fillText(yVal, padL - 8, gy + 4);

        const gx = padL + plotW * frac;
        ctx.beginPath();
        ctx.moveTo(gx, padT);
        ctx.lineTo(gx, padT + plotH);
        ctx.stroke();
        const xVal = (minX + frac * (maxX - minX)).toFixed(0) + '%';
        ctx.textAlign = 'center';
        ctx.fillText(xVal, gx, padT + plotH + 18);
      }

      ctx.strokeStyle = isDark ? 'rgba(148, 163, 184, 0.45)' : 'rgba(100, 116, 139, 0.65)';
      ctx.lineWidth = 1.5;
      ctx.beginPath();
      ctx.moveTo(padL, padT);
      ctx.lineTo(padL, padT + plotH);
      ctx.lineTo(padL + plotW, padT + plotH);
      ctx.stroke();

      ctx.fillStyle = isDark ? '#cbd5e1' : '#475569';
      ctx.font = '600 11px "Plus Jakarta Sans", sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Persentase Tidak Masuk (%) →', padL + plotW / 2, H - 8);

      if (!engine) return;
      const pts = engine.state.points;
      const labels = engine.state.labels;
      const res = engine.result();
      const ciColor = {};
      res.forEach((r) => { ciColor[r.cluster] = isDark ? (r.darkColor || r.color) : r.color; });
      const colorFor = (ci) => ciColor[ci] || '#64748b';

      const px = (v) => padL + v * plotW;
      const py = (v) => (padT + plotH) - v * plotH;

      pts.forEach((p, i) => {
        const cx = px(p[1]), cy = py(p[0]);
        const col = colorFor(labels[i]);
        ctx.beginPath();
        ctx.arc(cx, cy, 7, 0, Math.PI * 2);
        ctx.fillStyle = col;
        ctx.fill();
        ctx.strokeStyle = isDark ? '#0f172a' : '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();

        if (data[i]) {
          ctx.fillStyle = isDark ? '#f1f5f9' : '#1e293b';
          ctx.font = '600 11px "Plus Jakarta Sans", sans-serif';
          ctx.textAlign = 'left';
          const shortName = data[i].nama.split(' ').slice(0, 2).join(' ');
          ctx.fillText(shortName, Math.min(W - 95, cx + 10), cy + 4);
        }
      });
    }

    function renderResult() {
      const box = byId('k-hasil');
      const tableBox = byId('k-detail-table');
      const cBox = byId('k-centroid-box');
      const techBox = byId('k-tech-table');
      if (!engine) return;

      const res = engine.result();
      box.innerHTML = res.map((r) => `
        <div class="glass card metric" style="border-left-color:${r.color}">
          <div class="ct" style="display:flex;justify-content:space-between;align-items:center">
            <span class="chip" style="background:${r.color}">${esc(r.label)}</span>
            <span class="pill-note">${r.jumlah} orang</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px;padding:10px;background:var(--surface-subtle);border-radius:8px;border:1px solid var(--line)">
            <div><div class="muted small">Rata Terlambat</div><div style="font-weight:700" class="tabular">${r.rata_terlambat} mnt</div></div>
            <div><div class="muted small">Tidak Masuk</div><div style="font-weight:700" class="tabular">${r.persen_tidak_hadir}%</div></div>
            <div><div class="muted small">Lama Kerja</div><div style="font-weight:700" class="tabular">${r.rata_jam_kerja} jam</div></div>
          </div>
          <div class="small muted" style="margin-top:8px">${esc(r.rekomendasi)}</div>
          <div class="members">${r.anggota.map((a) => `<span class="member-pill">${esc(a)}</span>`).join('') || '<span class="muted small">Tidak ada karyawan di kategori ini</span>'}</div>
        </div>`).join('');

      const details = engine.memberDetails();
      byId('k-emp-count').textContent = `${details.length} karyawan dinilai`;

      // Tabel Utama untuk Orang Awam (Sederhana & Jelas)
      tableBox.innerHTML = `
        <table>
          <thead>
            <tr>
              <th>No</th>
              <th>Nama Karyawan</th>
              <th>Divisi</th>
              <th>Jumlah Masuk</th>
              <th>Rata-rata Terlambat</th>
              <th>Persentase Tidak Masuk</th>
              <th>Rata-rata Lama Kerja</th>
              <th>Nilai Disiplin</th>
              <th>Kategori</th>
              <th>Saran / Keterangan</th>
            </tr>
          </thead>
          <tbody>
            ${details.map((m, idx) => `<tr>
              <td class="muted tabular">${idx + 1}</td>
              <td>${empCell(m.nama)}</td>
              <td>${esc(m.tim)}</td>
              <td class="tabular">${m.hari_tercatat} dari ${m.total_hari} hari</td>
              <td class="tabular">${m.rata_terlambat} menit</td>
              <td class="tabular">${m.persen_tidak_hadir}%</td>
              <td class="tabular">${m.rata_jam_kerja} jam/hari</td>
              <td class="tabular">
                <div class="score-wrap">
                  <div class="score-bar"><div class="score-fill" style="width:${m.skor_disiplin}%;background:${m.clusterColor}"></div></div>
                  <span><b style="color:${m.clusterColor}">${m.skor_disiplin}</b>/100</span>
                </div>
              </td>
              <td><span class="chip" style="background:${m.clusterColor}">${esc(m.clusterLabel)}</span></td>
              <td class="small">${esc(m.rekomendasi)}</td>
            </tr>`).join('')}
            ${ineligibleData.map((u) => `<tr style="opacity:0.65">
              <td class="muted">—</td>
              <td>${esc(u.nama)}</td>
              <td>${esc(u.tim)}</td>
              <td class="tabular">${u.hari_tercatat} hari</td>
              <td colspan="6" class="muted small">Karyawan baru (belum mencapai minimal 10 hari kerja untuk dinilai otomatis)</td>
            </tr>`).join('')}
          </tbody>
        </table>`;

      // Tabel Teknis di dalam <details> (Tersembunyi agar tidak membingungkan pengguna awam)
      cBox.innerHTML = `<div class="table-wrap"><table>
        <thead><tr><th>Kode</th><th>Kategori</th><th>Titik Pusat Terlambat</th><th>Titik Pusat Absen</th><th>Titik Pusat Jam Kerja</th></tr></thead>
        <tbody>${res.map((r) => `<tr>
          <td><span class="chip" style="background:${r.color}">${esc(r.code)}</span></td>
          <td style="font-weight:600">${esc(r.label)}</td>
          <td class="tabular">${r.centroid[0]}</td>
          <td class="tabular">${r.centroid[1]}</td>
          <td class="tabular">${r.centroid[2]}</td>
        </tr>`).join('')}</tbody>
      </table></div>`;

      const distHeaders = res.map((r) => `<th>Jarak ke ${esc(r.code)}</th>`).join('');
      techBox.innerHTML = `<div class="table-wrap"><table>
        <thead><tr><th>Nama Karyawan</th><th>Normalisasi (Terlambat, Absen, Jam Kerja)</th>${distHeaders}<th>Kategori Terdekat</th></tr></thead>
        <tbody>${details.map((m) => `<tr>
          <td style="font-weight:600">${esc(m.nama)}</td>
          <td class="tabular muted small">(${m.norm_terlambat.toFixed(3)}, ${m.norm_tidak_hadir.toFixed(3)}, ${m.norm_jam_kerja.toFixed(3)})</td>
          ${m.distances.map((d, ri) => `<td class="tabular" style="${ri === m.clusterRank ? 'font-weight:700;color:var(--accent)' : 'color:var(--text-muted)'}">${d.toFixed(4)}</td>`).join('')}
          <td><span class="chip" style="background:${m.clusterColor}">${esc(m.clusterCode)} · ${esc(m.clusterLabel)}</span></td>
        </tr>`).join('')}</tbody>
      </table></div>`;

      byId('k-simpan').disabled = !engine.state.done;
    }

    async function loadAndRun() {
      const dari = byId('k-dari').value;
      const sampai = byId('k-sampai').value;
      if (dari && sampai && sampai < dari) {
        toast('Tanggal selesai tidak boleh sebelum tanggal mulai.', 'err');
        return false;
      }
      let res;
      try {
        res = await Api.get('kmeans.dataset', {
          dari,
          sampai,
          tim: byId('k-tim').value
        });
      } catch (e) {
        toast(e.message, 'err');
        return false;
      }
      data = res.data.filter((r) => r.eligible);
      ineligibleData = res.data.filter((r) => !r.eligible);
      const k = Number(byId('k-k').value);
      if (data.length < k) {
        engine = null;
        draw();
        byId('k-hasil').innerHTML = '';
        byId('k-emp-count').textContent = `${data.length} karyawan memenuhi syarat`;
        byId('k-simpan').disabled = true;
        byId('k-centroid-box').innerHTML = '';
        byId('k-tech-table').innerHTML = '';
        byId('k-detail-table').innerHTML = `<div style="padding:16px">
          <div class="alert alert-error">Karyawan yang sudah masuk minimal 10 hari hanya <b>${data.length} orang</b> (dibutuhkan minimal <b>${k} orang</b> untuk dibagi menjadi ${k} kategori). Perluas rentang tanggal atau pilih jumlah kategori yang lebih sedikit.</div>
          ${ineligibleData.length ? `<table style="margin-top:12px"><thead><tr><th>Nama Karyawan</th><th>Divisi</th><th>Hari Masuk</th><th>Keterangan</th></tr></thead><tbody>${ineligibleData.map((u) => `<tr><td>${esc(u.nama)}</td><td>${esc(u.tim)}</td><td class="tabular">${u.hari_tercatat} hari</td><td class="muted small">Belum mencapai minimal 10 hari masuk</td></tr>`).join('')}</tbody></table>` : ''}
        </div>`;
        toast(`Karyawan yang sudah masuk minimal 10 hari hanya ${data.length} orang. Perluas rentang tanggal.`, 'err');
        return false;
      }
      engine = KMeans.create(data, k, seed);
      engine.runAll();
      draw();
      renderResult();
      return true;
    }

    byId('k-siapkan').addEventListener('click', async () => {
      const ok = await loadAndRun();
      if (ok) toast('Penilaian disiplin karyawan diperbarui');
    });
    byId('k-k').addEventListener('change', loadAndRun);
    byId('k-tim').addEventListener('change', loadAndRun);
    byId('k-exp-xls').addEventListener('click', () => {
      if (!engine) { toast('Belum ada hasil penilaian untuk diunduh', 'err'); return; }
      const details = engine.memberDetails();
      const cols = ['No', 'Nama Karyawan', 'Divisi', 'Hari Masuk', 'Total Hari Kerja', 'Rata-rata Terlambat (Menit)', 'Persentase Tidak Masuk', 'Rata-rata Jam Kerja', 'Nilai Disiplin (0-100)', 'Kategori Disiplin', 'Keterangan'];
      const rows = details.map((m, i) => [
        i + 1, m.nama, m.tim, m.hari_tercatat, m.total_hari, m.rata_terlambat, m.persen_tidak_hadir + '%', m.rata_jam_kerja, m.skor_disiplin, m.clusterLabel, m.rekomendasi
      ]);
      exportExcel(`penilaian-disiplin-${todayISO()}.xls`, `Penilaian Disiplin Karyawan (${byId('k-dari').value} s/d ${byId('k-sampai').value})`, cols, rows);
    });
    byId('k-simpan').addEventListener('click', async () => {
      if (!engine || !engine.state.done) return;
      try {
        await Api.post('klaster.save', {
          periode: `${byId('k-dari').value} s/d ${byId('k-sampai').value}`,
          k: engine.k,
          hasil: engine.result()
        });
        toast('Hasil penilaian disiplin berhasil disimpan');
        const refreshed = await Api.get('klaster.list').catch(() => ({ data: [] }));
        savedList = refreshed.data || [];
        renderSavedHistory();
      } catch (e) { toast(e.message, 'err'); }
    });
    if (window._kmeansResize) window.removeEventListener('resize', window._kmeansResize);
    if (window._kmeansTheme) window.removeEventListener('themechange', window._kmeansTheme);
    window._kmeansResize = () => { if (engine && document.body.contains(canvas)) draw(); };
    window._kmeansTheme = () => { if (document.body.contains(canvas)) draw(); };
    window.addEventListener('resize', window._kmeansResize);
    window.addEventListener('themechange', window._kmeansTheme);

    await loadAndRun();
  }

  // ---------------- Riwayat Absen Lengkap ----------------
  async function viewAxel(view) {
    const curYear = new Date().getFullYear();
    const [karyawanRes, timRes] = await Promise.all([
      Api.get('karyawan.list').catch(() => ({ data: [] })),
      Api.get('tim.list').catch(() => ({ data: [] }))
    ]);
    const karyawan = karyawanRes.data.filter((k) => k.peran === 'karyawan');
    const timList = timRes.data;

    view.innerHTML = `
      ${pageHead('Riwayat Absen Karyawan', 'Cari dan lihat semua data kehadiran karyawan, lalu unduh ke Excel atau cetak PDF', `
        <button class="btn btn-secondary" id="axel-csv">Unduh CSV</button>
        <button class="btn btn-secondary" id="axel-xls">Unduh Excel</button>
        <button class="btn btn-primary" id="axel-pdf">${ico('db')} Cetak Laporan PDF</button>`)}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari Tanggal</span><input type="date" id="a-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai Tanggal</span><input type="date" id="a-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Nama Karyawan</span><select id="a-karyawan"><option value="">Semua Karyawan</option>${karyawan.map((k) => `<option value="${k.id}">${esc(k.nama)}</option>`).join('')}</select></label>
            <label class="field"><span>Divisi</span><select id="a-tim"><option value="">Semua Divisi</option>${timList.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('')}</select></label>
            <label class="field"><span>Mode Kerja</span><select id="a-mode"><option value="">Semua Mode</option><option value="wfo">Di Kantor (WFO)</option><option value="wfh">WFH</option></select></label>
            <label class="field"><span>Status</span><select id="a-status"><option value="">Semua Status</option>${Object.entries(STATUS_LABEL).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
            <button class="btn btn-primary" id="a-load">Tampilkan Data</button>
            <button class="btn btn-ghost" id="a-all-year">Lihat Semua Data ${curYear}</button>
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
                <label class="field"><span>Nama Kantor / Perusahaan</span><input id="o-nama" value="${esc(o.nama)}" required placeholder="Contoh: PT Maju Bersama"></label>
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
                <div class="config-section-title"><span class="step-num">2</span> Cara Karyawan Absen (Khusus WFO)</div>
                <p class="muted small">Aturan QR &amp; radius lokasi berlaku untuk karyawan yang absen <b>Di Kantor (WFO)</b>. Karyawan <b>WFH</b> tetap dapat absen dengan wajib mengisi catatan kegiatan.</p>
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
                      <div class="rule-card-title">Wajib Scan Kode QR Kantor</div>
                      <div class="rule-card-desc">Karyawan memindai gambar QR yang ditempel di pintu/meja kantor.</div>
                    </div>
                  </label>
                  <label class="rule-card">
                    <input type="checkbox" id="o-gps" ${Number(o.wajib_gps) ? 'checked' : ''}>
                    <div>
                      <div class="rule-card-title">Wajib Cek Lokasi GPS</div>
                      <div class="rule-card-desc">Pastikan karyawan menyalakan GPS di HP/komputer saat absen.</div>
                    </div>
                  </label>
                </div>
              </div>

              <div class="config-section">
                <div class="config-section-title"><span class="step-num">3</span> Kode QR &amp; Pembatasan Lokasi Kantor</div>
                <div class="field">
                  <span>Kode QR Lokasi Kantor</span>
                  <div style="display:flex;gap:8px;align-items:center">
                    <input id="o-kode" value="${esc(o.kode_lokasi)}" required placeholder="Contoh: KANTOR-01">
                    <button type="button" class="btn btn-secondary btn-sm" id="o-gen-kode">Buat Kode Acak</button>
                  </div>
                </div>
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
                  <div class="muted small" style="margin-bottom:6px">Saat Anda berada di kantor, klik tombol di bawah agar titik lokasi terisi otomatis tanpa mengetik angka koordinat.</div>
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

          <div class="glass card" style="height:fit-content">
            <div class="card-head"><h2>Poster Kode QR Kantor</h2></div>
            <p class="muted small" style="margin-bottom:14px">Cetak atau unduh gambar QR ini, lalu tempel di dekat pintu masuk kantor agar karyawan bisa memindainya saat absen.</p>
            <div class="qr-holder" id="qr-holder" style="margin:0 auto"></div>
            <p class="muted small" style="margin-top:12px;text-align:center">Kode Lokasi: <b id="qr-code">${esc(o.kode_lokasi)}</b></p>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:14px">
              <button type="button" class="btn btn-secondary btn-sm" id="qr-download">Unduh Gambar QR</button>
              <button type="button" class="btn btn-primary btn-sm" id="qr-print">Cetak Poster QR</button>
            </div>
          </div>
        </div>
      </div>`;

    function renderQr(code) {
      const holder = byId('qr-holder');
      try {
        const qr = qrcode(0, 'M');
        qr.addData(code || 'ABSEN');
        qr.make();
        holder.innerHTML = qr.createSvgTag(6, 8);
      } catch (e) {
        holder.innerHTML = '<p class="muted small">Gagal membuat gambar QR.</p>';
      }
      byId('qr-code').textContent = code || '—';
    }
    renderQr(o.kode_lokasi);
    byId('o-kode').addEventListener('input', (e) => renderQr(e.target.value));

    byId('o-gen-kode').addEventListener('click', () => {
      const rand = Math.random().toString(36).substring(2, 6).toUpperCase();
      const newCode = `ABSEN-${rand}`;
      byId('o-kode').value = newCode;
      renderQr(newCode);
      toast('Kode QR baru dibuat. Jangan lupa klik Simpan Pengaturan.');
    });

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

    byId('qr-download').addEventListener('click', () => {
      const svg = byId('qr-holder').querySelector('svg');
      if (!svg) { toast('Gambar QR belum siap', 'err'); return; }
      const xml = new XMLSerializer().serializeToString(svg);
      const img = new Image();
      img.onload = () => {
        const c = document.createElement('canvas');
        c.width = 480; c.height = 480;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 20, 20, 440, 440);
        const a = document.createElement('a');
        a.href = c.toDataURL('image/png');
        a.download = `qr-absensi-${(byId('o-kode').value || 'kantor').replace(/[^a-zA-Z0-9_-]/g, '')}.png`;
        a.click();
        toast('Gambar QR berhasil diunduh');
      };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
    });

    byId('qr-print').addEventListener('click', () => {
      const svg = byId('qr-holder').innerHTML;
      const kode = esc(byId('o-kode').value || 'ABSEN');
      const namaKantor = esc(byId('o-nama').value || state.org.nama);
      const win = window.open('', '_blank', 'width=560,height=680');
      if (!win) { toast('Izinkan pop-up browser untuk mencetak poster QR', 'err'); return; }
      win.document.write(`<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Poster QR Absensi</title>
        <style>body{font-family:sans-serif;text-align:center;padding:40px;color:#0f172a}
        .box{border:2px dashed #cbd5e1;border-radius:16px;padding:32px;max-width:420px;margin:0 auto}
        h1{margin:0 0 6px;font-size:22px}p{color:#475569;margin:0 0 20px;font-size:14px}
        .qr svg{width:260px;height:260px}.code{margin-top:14px;font-size:16px;font-weight:700;letter-spacing:1px}</style>
        </head><body><div class="box"><h1>${namaKantor}</h1><p>Pindai Kode QR ini untuk Absen Masuk &amp; Pulang</p>
        <div class="qr">${svg}</div><div class="code">${kode}</div></div>
        <script>window.onload=function(){window.print();}<\/script></body></html>`);
      win.document.close();
    });

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

  // ---------------- Daftar Karyawan ----------------
  async function viewKaryawan(view) {
    view.innerHTML = `
      ${pageHead('Daftar Karyawan', 'Tambah akun karyawan, atur divisi dan jabatan, atau setel ulang kata sandi login', `
        <button class="btn btn-primary" id="add-emp">+ Tambah Karyawan Baru</button>`)}
      <div class="glass card" id="emp-box"><p class="muted">Sedang memuat data…</p></div>`;

    let allEmployees = [];

    function renderEmpTable() {
      const q = (byId('emp-search')?.value || '').trim().toLowerCase();
      const rows = q
        ? allEmployees.filter((u) =>
            (u.nama || '').toLowerCase().includes(q) ||
            (u.email || '').toLowerCase().includes(q) ||
            (u.tim || '').toLowerCase().includes(q) ||
            (u.jabatan || '').toLowerCase().includes(q))
        : allEmployees;

      const tbody = byId('emp-tbody');
      if (tbody) {
        tbody.innerHTML = rows.map((u, idx) => `<tr>
          <td class="muted tabular">${idx + 1}</td>
          <td>${empCell(u.nama)}</td>
          <td>${esc(u.email)}</td>
          <td>${PERAN_LABEL[u.peran] || esc(u.peran)}</td>
          <td><span class="shift-tag">${esc(u.tim)}</span></td>
          <td>${esc(u.jabatan || '—')}</td>
          <td>${Number(u.aktif) ? '<span class="badge badge-hadir">Aktif</span>' : '<span class="badge badge-netral">Nonaktif</span>'}</td>
          <td class="toolbar">
            <button class="btn btn-secondary btn-sm" data-edit='${esc(JSON.stringify(u))}'>Ubah</button>
            ${u.id !== state.user.id ? `<button class="btn btn-ghost btn-sm" data-toggle="${u.id}" data-aktif="${Number(u.aktif) ? 0 : 1}">${Number(u.aktif) ? 'Nonaktifkan' : 'Aktifkan'}</button>` : ''}
          </td>
        </tr>`).join('') || '<tr><td colspan="8" class="muted">Tidak ada karyawan sesuai pencarian.</td></tr>';
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

      byId('emp-box').innerHTML = `
        <div class="card-head" style="flex-wrap:wrap">
          <h2>Semua Karyawan &amp; Admin</h2>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="search" id="emp-search" placeholder="Cari nama, email, atau divisi…" style="min-height:34px;width:220px">
            <span class="pill-note">${allEmployees.length} orang</span>
          </div>
        </div>
        <div class="table-wrap"><table>
          <thead>
            <tr>
              <th>No</th>
              <th>Nama Lengkap</th>
              <th>Email</th>
              <th>Jabatan / Akses</th>
              <th>Divisi</th>
              <th>Posisi</th>
              <th>Status</th>
              <th>Pengaturan</th>
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
    }
    byId('add-emp').addEventListener('click', () => openEmpForm(null, load));
    await load();
  }

  function openEmpForm(u, reload) {
    const edit = !!u;
    const m = openModal(`
      <div class="modal-head"><h2>${edit ? 'Ubah Data' : 'Tambah'} Karyawan</h2><button class="icon-btn" type="button" data-close>×</button></div>
      <div class="form">
        <label class="field"><span>Nama Lengkap</span><input id="e-nama" value="${esc(u?.nama || '')}" required placeholder="Masukkan nama lengkap"></label>
        <div class="field-row">
          <label class="field"><span>Alamat Email</span><input type="email" id="e-email" value="${esc(u?.email || '')}" required placeholder="nama@email.com"></label>
          <label class="field"><span>Hak Akses</span><select id="e-peran">${Object.entries(PERAN_LABEL).map(([k, v]) => `<option value="${k}" ${(u?.peran || 'karyawan') === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        </div>
        <div class="field-row">
          <label class="field"><span>Divisi / Departemen</span><input id="e-tim" value="${esc(u?.tim || 'Operasional')}" placeholder="Contoh: Operasional / Finance"></label>
          <label class="field"><span>Jabatan</span><input id="e-jabatan" value="${esc(u?.jabatan || 'Staf')}" placeholder="Contoh: Staf / Supervisor"></label>
        </div>
        <label class="field"><span>Kata Sandi Login ${edit ? '(Kosongkan jika tidak ingin mengganti)' : ''}</span><input type="password" id="e-sandi" placeholder="Minimal 6 huruf/angka (dipakai karyawan untuk login)"></label>
        <label style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="e-aktif" ${!edit || Number(u.aktif) ? 'checked' : ''}> <span>Karyawan Masih Aktif Bekerja</span></label>
        <div class="alert alert-error" id="e-err" hidden></div>
        <div style="display:flex;justify-content:flex-end;gap:10px">
          <button class="btn btn-secondary" type="button" data-close-btn>Batal</button>
          <button class="btn btn-primary" type="button" id="e-save">Simpan</button>
        </div>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);
    m.$('[data-close-btn]').addEventListener('click', m.close);
    m.$('#e-save').addEventListener('click', async () => {
      const errBox = m.$('#e-err'); errBox.hidden = true;
      const nama = m.$('#e-nama').value.trim();
      const email = m.$('#e-email').value.trim();
      const sandi = m.$('#e-sandi').value;
      if (!nama) { errBox.textContent = 'Nama lengkap wajib diisi.'; errBox.hidden = false; return; }
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errBox.textContent = 'Masukkan alamat email yang valid.'; errBox.hidden = false; return;
      }
      if ((!edit && sandi.length < 6) || (edit && sandi !== '' && sandi.length < 6)) {
        errBox.textContent = 'Kata sandi minimal 6 huruf atau angka.'; errBox.hidden = false; return;
      }
      try {
        await Api.post('karyawan.save', {
          id: u?.id || 0,
          nama,
          email,
          peran: m.$('#e-peran').value,
          tim: m.$('#e-tim').value,
          jabatan: m.$('#e-jabatan').value,
          kata_sandi: sandi,
          aktif: m.$('#e-aktif').checked ? 1 : 0
        });
        m.close(); toast('Data karyawan berhasil disimpan'); reload();
      } catch (e) { errBox.textContent = e.message; errBox.hidden = false; }
    });
  }

  // ---------------- Catat Izin / Sakit ----------------
  async function viewIzin(view) {
    const isAdmin = state.user.peran === 'admin' || state.user.peran === 'atasan';
    const [todayRes, empRes, izinRes] = await Promise.all([
      Api.get('absensi.today'),
      isAdmin ? Api.get('karyawan.list').catch(() => ({ data: [] })) : Promise.resolve({ data: [] }),
      Api.get('absensi.izin_list').catch(() => ({ data: [] }))
    ]);
    const d = todayRes.data;
    const karyawanList = (empRes.data || []).filter((k) => k.peran === 'karyawan' && Number(k.aktif) === 1);
    const riwayatIzin = izinRes.data || [];

    view.innerHTML = `
      ${pageHead('Catat Izin / Sakit', 'Catat karyawan yang berhalangan hadir karena izin keperluan atau sedang sakit')}
      <div class="grid grid-2">
        <div class="glass card">
          <div class="card-head"><h2>Form Izin / Sakit</h2></div>
          <form class="form" id="izin-form">
            ${isAdmin ? `
            <label class="field">
              <span>Pilih Nama Karyawan</span>
              <select id="i-pengguna" required ${karyawanList.length ? '' : 'disabled'}>
                ${karyawanList.length ? karyawanList.map((k) => `<option value="${k.id}">${esc(k.nama)} — ${esc(k.tim)}</option>`).join('') : '<option value="">Belum ada karyawan aktif</option>'}
              </select>
            </label>` : ''}
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
              <span>Foto Surat / Bukti ${(!isAdmin && d.wajib_foto) ? '(Wajib)' : '(Boleh dikosongkan)'}</span>
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
      if (isAdmin && !karyawanList.length) {
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
      if (!isAdmin && d.wajib_foto && !foto) {
        errBox.textContent = 'Mohon lampirkan foto bukti terlebih dahulu.';
        errBox.hidden = false;
        return;
      }
      try {
        const payload = {
          jenis: byId('i-jenis').value,
          mulai,
          sampai,
          catatan,
          foto
        };
        if (isAdmin && byId('i-pengguna')) {
          payload.pengguna_id = Number(byId('i-pengguna').value);
        }
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

    const def = state.user.peran === 'karyawan' ? 'beranda' : 'dasbor';
    await go(def);
  }

  return { init, go };
})();

App.init();
