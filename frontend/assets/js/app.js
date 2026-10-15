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

  // ---------------- Util ----------------
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const byId = (id) => document.getElementById(id);

  const STATUS_LABEL = { hadir: 'Hadir', terlambat: 'Terlambat', izin: 'Izin', sakit: 'Sakit', alpa: 'Alpa' };
  const PERAN_LABEL = { karyawan: 'Karyawan', atasan: 'Atasan', admin: 'Admin HR' };
  const HARI_NAMA = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
  const BLN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

  function todayISO(d = new Date()) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  function firstOfMonthISO() { return todayISO().slice(0, 8) + '01'; }
  function endOfMonthISO() { const d = new Date(); return todayISO(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }
  function fmtTanggal(iso) {
    if (!iso) return '-';
    const d = new Date(iso + 'T00:00:00');
    return `${HARI_NAMA[d.getDay()]}, ${d.getDate()} ${BLN[d.getMonth()]} ${d.getFullYear()}`;
  }
  function fmtTanggalPendek(iso) {
    if (!iso) return '-';
    const d = new Date(iso + 'T00:00:00');
    return `${d.getDate()} ${BLN[d.getMonth()]}`;
  }
  const fmtJam = (t) => (t ? String(t).slice(0, 5) : 'â€”');
  function fmtMenitDariJam(t) {
    if (!t) return 'â€”';
    const [h, m] = String(t).split(':').map(Number);
    return `${h * 60 + m}`;
  }
  function fmtDurasi(menit) {
    if (menit == null) return 'â€”';
    const h = Math.floor(menit / 60), m = menit % 60;
    return `${h}j ${String(m).padStart(2, '0')}m`;
  }

  function badge(status) {
    const s = status || 'alpa';
    return `<span class="badge badge-${s}">${STATUS_LABEL[s] || s}</span>`;
  }

  function toast(msg, type = 'ok') {
    const el = document.createElement('div');
    el.className = `toast toast-${type === 'ok' ? 'ok' : 'err'}`;
    el.textContent = msg;
    byId('toasts').append(el);
    setTimeout(() => el.remove(), 3200);
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
    const head = columns.map((c) => `<th>${esc(c)}</th>`).join('');
    const body = rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('');
    const html = `<html><head><meta charset="utf-8"></head><body><h3>${esc(title)}</h3>
      <table border="1"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`;
    downloadBlob('\ufeff' + html, 'application/vnd.ms-excel', filename);
  }

  const ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    dash: '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
    cal: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 9h18M8 3v4M16 3v4"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    nodes: '<circle cx="6" cy="6" r="3"/><circle cx="18" cy="9" r="3"/><circle cx="10" cy="18" r="3"/><path d="M8 8l8 0M8 8l1 7"/>',
    db: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    users: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M17 4a4 4 0 0 1 0 8M22 21c0-3-1.5-5-4-5.5"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
    logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
  };
  function ico(name) {
    return `<svg class="ico" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${ICONS[name] || ''}</svg>`;
  }

  // ---------------- Rute ----------------
  const ROUTES = {
    beranda: { label: 'Beranda', roles: ['karyawan'], icon: 'home', render: viewBeranda },
    izin: { label: 'Izin', roles: ['karyawan', 'admin', 'atasan'], icon: 'cal', render: viewIzin },
    dasbor: { label: 'Dasbor', roles: ['admin', 'atasan'], icon: 'dash', render: viewDasbor },
    harian: { label: 'Absensi hari ini', roles: ['admin', 'atasan'], icon: 'clock', render: viewHarian },
    rekap: { label: 'Rekap', roles: ['admin', 'atasan'], icon: 'chart', render: viewRekap },
    kmeans: { label: 'K-Means', roles: ['admin', 'atasan'], icon: 'nodes', render: viewKmeans },
    axel: { label: 'Axel', roles: ['admin', 'atasan'], icon: 'db', render: viewAxel },
    karyawan: { label: 'Karyawan', roles: ['admin'], icon: 'users', render: viewKaryawan },
    pengaturan: { label: 'Pengaturan', roles: ['admin'], icon: 'gear', render: viewPengaturan }
  };

  function allowedRoutes() {
    return Object.entries(ROUTES).filter(([, r]) => r.roles.includes(state.user.peran));
  }

  function renderNav() {
    const items = allowedRoutes();
    byId('nav-desktop').innerHTML = items.map(([key, r]) =>
      `<button data-route="${key}" class="${state.route === key ? 'active' : ''}">${ico(r.icon)}<span>${r.label}</span></button>`
    ).join('');
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
    view.innerHTML = '<div class="glass card"><p class="muted">Memuatâ€¦</p></div>';
    try {
      await ROUTES[route].render(view);
    } catch (err) {
      view.innerHTML = `<div class="glass card"><div class="alert alert-error">${esc(err.message)}</div></div>`;
    }
  }

  function pageHead(title, subtitle, right = '') {
    return `<div class="page-head"><div><h1>${esc(title)}</h1><p>${esc(subtitle)}</p></div><div class="toolbar">${right}</div></div>`;
  }

  // ---------------- Beranda (karyawan) ----------------
  async function viewBeranda(view) {
    const res = await Api.get('absensi.today');
    const d = res.data;
    const rec = d.record || {};
    const now = new Date();
    const sapaan = now.getHours() < 11 ? 'Selamat pagi' : now.getHours() < 15 ? 'Selamat siang' : now.getHours() < 19 ? 'Selamat sore' : 'Selamat malam';

    const state_absen = !rec.jam_masuk ? 'in' : (!rec.jam_pulang ? 'out' : 'done');
    const btnLabel = state_absen === 'in' ? 'Check-in' : state_absen === 'out' ? 'Check-out' : 'Absen hari ini selesai';
    const btnHint = state_absen === 'in'
      ? 'Pindai barcode lokasi lalu ambil foto'
      : state_absen === 'out' ? 'Jangan lupa absen pulang' : `Masuk ${fmtJam(rec.jam_masuk)} Â· Pulang ${fmtJam(rec.jam_pulang)}`;

    view.innerHTML = `
      ${pageHead(`${sapaan}, ${esc(state.user.nama.split(' ')[0])}`, fmtTanggal(d.tanggal))}
      <div class="stack">
        <div class="glass card clock-card">
          <div class="clock-time tabular" id="clock">--:--:--</div>
          <div class="clock-date">${d.hari_kerja ? `Jadwal ${esc(d.jam_masuk_jadwal)}â€“${esc(d.jam_pulang_jadwal)} Â· toleransi ${d.toleransi_menit} menit` : 'Hari ini bukan hari kerja'}</div>
          <div class="absen-actions">
            <button class="btn ${state_absen === 'done' ? 'btn-secondary' : 'btn-primary'} btn-lg big-cta" id="absen-btn" ${state_absen === 'done' ? 'disabled' : ''}>
              ${state_absen === 'in' ? ico('clock') : ''}${btnLabel}
            </button>
            <p class="muted small">${esc(btnHint)}</p>
          </div>
        </div>

        <div class="mini-stats">
          <div class="glass mini-stat"><div class="value metric accent-hadir">${res.ringkasan.hadir}</div><div class="label">Hadir bulan ini</div></div>
          <div class="glass mini-stat"><div class="value" style="color:var(--status-terlambat)">${res.ringkasan.terlambat}</div><div class="label">Terlambat</div></div>
          <div class="glass mini-stat"><div class="value" style="color:var(--status-alpa)">${res.ringkasan.tidak_hadir}</div><div class="label">Tidak hadir</div></div>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>7 hari terakhir</h2><span class="pill-note">${res.riwayat.length} catatan</span></div>
          <div class="riwayat">
            ${res.riwayat.length ? res.riwayat.map((r) => `
              <div class="riwayat-row">
                <div><div class="t">${fmtTanggal(r.tanggal)}</div>
                  <div class="s">${r.jam_masuk ? `${fmtJam(r.jam_masuk)} â†’ ${fmtJam(r.jam_pulang)}` : (r.catatan || '')}</div></div>
                ${badge(r.status)}
              </div>`).join('') : '<p class="muted small">Belum ada catatan.</p>'}
          </div>
        </div>
      </div>`;

    const clock = byId('clock');
    const tick = () => { const n = new Date(); clock.textContent = `${String(n.getHours()).padStart(2, '0')}:${String(n.getMinutes()).padStart(2, '0')}:${String(n.getSeconds()).padStart(2, '0')}`; };
    tick(); setInterval(tick, 1000);

    const btn = byId('absen-btn');
    if (state_absen === 'in' || state_absen === 'out') {
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
          const shot = await Scanner.open({
            wajibBarcode: d.wajib_barcode,
            wajibFoto: d.wajib_foto,
            kodeLokasi: d.kode_lokasi,
            title: state_absen === 'in' ? 'Check-in' : 'Check-out'
          });
          const action = state_absen === 'in' ? 'absensi.checkin' : 'absensi.checkout';
          const r = await Api.post(action, { barcode: shot.barcode, foto: shot.foto });
          toast(r.pesan || 'Berhasil');
          await go('beranda');
        } catch (err) {
          if (err.message !== 'dibatalkan') toast(err.message, 'err');
          btn.disabled = false;
        }
      });
    }
  }

  // ---------------- Dasbor (admin/atasan) ----------------
  async function viewDasbor(view) {
    const { data } = await Api.get('dashboard');
    const h = data.hari_ini;
    const maxTren = Math.max(1, ...data.tren.map((t) => Number(t.hadir)));
    const bars = data.tren.length
      ? data.tren.map((t) => `<div class="bar" style="height:${(Number(t.hadir) / maxTren) * 100}%" title="${fmtTanggalPendek(t.tanggal)}: ${t.hadir} hadir"></div>`).join('')
      : '<p class="muted small">Belum ada data.</p>';

    view.innerHTML = `
      ${pageHead('Dasbor', `${esc(state.org.nama)} Â· ${fmtTanggal(todayISO())}`)}
      <div class="stack">
        <div class="grid grid-4">
          <div class="glass card metric accent-hadir"><div class="value">${h.hadir + h.terlambat}</div><div class="label">Hadir hari ini</div></div>
          <div class="glass card metric accent-terlambat"><div class="value">${h.terlambat}</div><div class="label">Terlambat</div></div>
          <div class="glass card metric accent-izin"><div class="value">${h.izin + h.sakit}</div><div class="label">Izin / sakit</div></div>
          <div class="glass card metric accent-alpa"><div class="value">${h.alpa}</div><div class="label">Alpa</div></div>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>Kehadiran 30 hari</h2><span class="pill-note">belum absen: ${h.belum} dari ${h.total_karyawan}</span></div>
          <div class="bars">${bars}</div>
          <div class="bar-legend"><span><i class="legend-dot" style="background:var(--accent)"></i>Jumlah hadir per hari</span></div>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>Perlu dicek hari ini</h2></div>
          <div class="riwayat">
            ${data.perlu_dicek.length ? data.perlu_dicek.map((r) => `
              <div class="riwayat-row">
                <div><div class="t">${esc(r.nama)}</div><div class="s">${r.jam_masuk ? 'Masuk ' + fmtJam(r.jam_masuk) : (r.catatan || 'Tanpa keterangan')}</div></div>
                ${badge(r.status)}
              </div>`).join('') : '<p class="muted small">Tidak ada yang perlu dicek. Bagus!</p>'}
          </div>
        </div>
      </div>`;
  }

  // ---------------- Absensi harian + koreksi ----------------
  async function viewHarian(view) {
    view.innerHTML = `${pageHead('Absensi hari ini', 'Pantau kehadiran dan koreksi bila perlu', `
      <label class="field"><span>Tanggal</span><input type="date" id="harian-tgl" value="${todayISO()}"></label>
      <button class="btn btn-secondary" id="harian-load">Tampilkan</button>`)}
      <div class="glass card" id="harian-box"><p class="muted">Memuatâ€¦</p></div>`;

    async function load() {
      const tgl = byId('harian-tgl').value || todayISO();
      const box = byId('harian-box');
      const { data } = await Api.get('absensi.harian', { tanggal: tgl });
      box.innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>Karyawan</th><th>Tim</th><th>Status</th><th>Masuk</th><th>Pulang</th><th>Catatan</th><th></th></tr></thead>
          <tbody>
            ${data.map((r) => `<tr>
              <td>${esc(r.nama)}</td>
              <td>${esc(r.tim)}</td>
              <td>${r.status ? badge(r.status) : '<span class="badge badge-netral">Belum</span>'}</td>
              <td>${fmtJam(r.jam_masuk)}</td>
              <td>${fmtJam(r.jam_pulang)}</td>
              <td class="wrap">${esc(r.catatan || '')}</td>
              <td><button class="btn btn-secondary btn-sm" data-koreksi="${r.id}" data-nama="${esc(r.nama)}" data-status="${r.status || ''}" data-masuk="${r.jam_masuk || ''}" data-pulang="${r.jam_pulang || ''}">Koreksi</button></td>
            </tr>`).join('') || '<tr><td colspan="7" class="muted">Tidak ada karyawan.</td></tr>'}
          </tbody>
        </table></div>`;
      box.querySelectorAll('[data-koreksi]').forEach((b) => b.addEventListener('click', () => openKoreksi(b.dataset, tgl, load)));
    }
    byId('harian-load').addEventListener('click', load);
    byId('harian-tgl').addEventListener('change', load);
    await load();
  }

  function openKoreksi(d, tanggal, reload) {
    const opts = Object.entries(STATUS_LABEL).map(([k, v]) =>
      `<option value="${k}" ${d.status === k ? 'selected' : ''}>${v}</option>`).join('');
    const m = openModal(`
      <div class="modal-head"><h2>Koreksi absensi</h2><button class="icon-btn" data-close>Ã—</button></div>
      <p class="muted small" style="margin-bottom:14px">${esc(d.nama)} Â· ${fmtTanggal(tanggal)}</p>
      <div class="form">
        <label class="field"><span>Status</span><select id="k-status">${opts}</select></label>
        <div class="field-row">
          <label class="field"><span>Jam masuk</span><input type="time" id="k-masuk" value="${d.masuk}"></label>
          <label class="field"><span>Jam pulang</span><input type="time" id="k-pulang" value="${d.pulang}"></label>
        </div>
        <label class="field"><span>Alasan koreksi (wajib, tercatat di audit)</span><textarea id="k-catatan" placeholder="cth: karyawan lupa check-out karena tugas luar"></textarea></label>
        <div class="alert alert-error" id="k-err" hidden></div>
        <button class="btn btn-primary btn-block" id="k-save">Simpan koreksi</button>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);
    m.$('#k-save').addEventListener('click', async () => {
      const catatan = m.$('#k-catatan').value.trim();
      const errBox = m.$('#k-err');
      if (!catatan) { errBox.textContent = 'Alasan koreksi wajib diisi.'; errBox.hidden = false; return; }
      try {
        await Api.post('absensi.koreksi', {
          pengguna_id: Number(d.koreksi),
          tanggal,
          status: m.$('#k-status').value,
          jam_masuk: m.$('#k-masuk').value,
          jam_pulang: m.$('#k-pulang').value,
          catatan
        });
        m.close(); toast('Koreksi tersimpan'); reload();
      } catch (e) { errBox.textContent = e.message; errBox.hidden = false; }
    });
  }

  // ---------------- Rekap ----------------
  async function viewRekap(view) {
    const timList = (await Api.get('tim.list').catch(() => ({ data: [] }))).data;
    view.innerHTML = `
      ${pageHead('Rekap', 'Ringkasan kehadiran per karyawan', `
        <button class="btn btn-secondary" id="rekap-csv">Ekspor CSV</button>
        <button class="btn btn-secondary" id="rekap-xls">Ekspor Excel</button>`)}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari</span><input type="date" id="r-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai</span><input type="date" id="r-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Tim</span><select id="r-tim"><option value="">Semua tim</option>${timList.map((t) => `<option>${esc(t)}</option>`).join('')}</select></label>
            <button class="btn btn-primary" id="r-load">Tampilkan</button>
          </div>
        </div>
        <div class="glass card" id="r-box"><p class="muted">Memuatâ€¦</p></div>
      </div>`;

    let rows = [];
    function columnsData() {
      const cols = ['Nama', 'Tim', 'Hari tercatat', 'Rata terlambat (mnt)', '% tidak hadir', 'Rata jam kerja', 'Hari tidak hadir'];
      const data = rows.map((r) => [r.nama, r.tim, r.hari_tercatat, r.rata_terlambat, r.persen_tidak_hadir + '%', r.rata_jam_kerja, r.hari_tidak_hadir]);
      return { cols, data };
    }

    async function load() {
      const box = byId('r-box');
      const { data } = await Api.get('rekap', {
        dari: byId('r-dari').value, sampai: byId('r-sampai').value, tim: byId('r-tim').value
      });
      rows = data;
      box.innerHTML = `
        <div class="card-head"><h2>Hasil rekap</h2><span class="pill-note">${rows.length} karyawan</span></div>
        <div class="table-wrap"><table>
          <thead><tr><th>Nama</th><th>Tim</th><th>Hari tercatat</th><th>Rata terlambat</th><th>% tidak hadir</th><th>Rata jam kerja</th></tr></thead>
          <tbody>${rows.map((r) => `<tr>
            <td>${esc(r.nama)}</td><td>${esc(r.tim)}</td><td>${r.hari_tercatat}</td>
            <td>${r.rata_terlambat} mnt</td><td>${r.persen_tidak_hadir}%</td><td>${r.rata_jam_kerja} jam</td>
          </tr>`).join('') || '<tr><td colspan="6" class="muted">Tidak ada data.</td></tr>'}</tbody>
        </table></div>`;
    }
    byId('r-load').addEventListener('click', load);
    byId('rekap-csv').addEventListener('click', () => { const { cols, data } = columnsData(); exportCsv(`rekap-${todayISO()}.csv`, cols, data); });
    byId('rekap-xls').addEventListener('click', () => { const { cols, data } = columnsData(); exportExcel(`rekap-${todayISO()}.xls`, `Rekap ${byId('r-dari').value} s/d ${byId('r-sampai').value}`, cols, data); });
    await load();
  }

  // ---------------- K-Means ----------------
  async function viewKmeans(view) {
    const timList = (await Api.get('tim.list').catch(() => ({ data: [] }))).data;
    view.innerHTML = `
      ${pageHead('Pengelompokan K-Means', 'Kelompokkan karyawan berdasarkan pola kehadiran')}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari</span><input type="date" id="k-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai</span><input type="date" id="k-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Tim</span><select id="k-tim"><option value="">Semua tim</option>${timList.map((t) => `<option>${esc(t)}</option>`).join('')}</select></label>
            <label class="field"><span>Jumlah kelompok (K)</span><select id="k-k">${[2, 3, 4].map((n) => `<option ${n === 3 ? 'selected' : ''}>${n}</option>`).join('')}</select></label>
            <button class="btn btn-primary" id="k-siapkan">Siapkan</button>
            <button class="btn btn-secondary" id="k-langkah" disabled>Langkah berikutnya</button>
            <button class="btn btn-secondary" id="k-jalan" disabled>Jalankan sampai selesai</button>
            <button class="btn btn-ghost" id="k-ulang" disabled>Coba ulang</button>
          </div>
          <p class="muted small" style="margin-top:12px">Syarat: karyawan punya minimal 10 hari data. Fitur: rata-rata menit terlambat, % hari tidak hadir, rata-rata jam kerja (dibalik setelah normalisasi).</p>
        </div>

        <div class="grid grid-2">
          <div class="glass card">
            <div class="card-head"><h2>Sebaran karyawan</h2></div>
            <div class="canvas-wrap"><canvas class="chart" id="k-canvas" width="800" height="360"></canvas></div>
            <div class="bar-legend"><span><i class="legend-dot" style="background:#64748b"></i>Titik = karyawan</span><span><i class="legend-dot" style="background:#0f172a"></i>Ring = centroid</span></div>
            <p class="muted small">Sumbu X: % hari tidak hadir (rendahâ†’tinggi). Sumbu Y: menit terlambat (rendahâ†’tinggi). Warna = kelompok.</p>
          </div>
          <div class="glass card">
            <div class="card-head"><h2>Catatan langkah</h2><span class="pill-note" id="k-iter">iterasi 0</span></div>
            <div class="steps" id="k-steps"><p class="muted small">Tekan "Siapkan" lalu "Langkah berikutnya".</p></div>
          </div>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>Hasil kelompok</h2><button class="btn btn-secondary btn-sm" id="k-simpan" disabled>Simpan hasil</button></div>
          <div class="cluster-list" id="k-hasil"><p class="muted small">Belum ada hasil. Jalankan sampai selesai untuk melihat kelompok.</p></div>
          <p class="notis-etik" style="margin-top:16px">Hasil K-Means adalah alat bantu analisis, bukan dasar tunggal untuk sanksi atau pemutusan hubungan kerja. Akses terbatas untuk admin dan atasan.</p>
        </div>
      </div>`;

    const canvas = byId('k-canvas');
    let data = [], seed = Date.now() % 100000, engine = null;

    function draw() {
      const ctx = canvas.getContext('2d');
      const dpr = window.devicePixelRatio || 1;
      const rect = canvas.getBoundingClientRect();
      canvas.width = rect.width * dpr; canvas.height = 360 * dpr;
      ctx.scale(dpr, dpr);
      const W = rect.width, H = 360, pad = 40;
      ctx.clearRect(0, 0, W, H);
      ctx.strokeStyle = 'rgba(120,130,160,.35)';
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(pad, 10); ctx.lineTo(pad, H - pad); ctx.lineTo(W - 10, H - pad); ctx.stroke();
      if (!engine) return;
      const pts = engine.state.points;
      const labels = engine.state.labels;
      const k = engine.k;
      let colorFor = (ci) => (KMeans.COLORS[k] || KMeans.COLORS[3])[ci] || '#64748b';
      if (engine.state.done) {
        const res = engine.result();
        const map = {}; res.forEach((r) => (map[r.cluster] = r.color));
        colorFor = (ci) => map[ci] || '#64748b';
      }
      const px = (v) => pad + v * (W - pad - 20);
      const py = (v) => (H - pad) - v * (H - pad - 20);
      pts.forEach((p, i) => {
        ctx.beginPath();
        ctx.arc(px(p.persen_tidak_hadir), py(p.rata_terlambat), 6, 0, Math.PI * 2);
        ctx.fillStyle = colorFor(labels[i]);
        ctx.globalAlpha = 0.88; ctx.fill(); ctx.globalAlpha = 1;
        ctx.strokeStyle = 'rgba(255,255,255,.8)'; ctx.stroke();
      });
      engine.state.centroids.forEach((c, ci) => {
        const x = px(c[1]), y = py(c[0]);
        ctx.beginPath(); ctx.arc(x, y, 9, 0, Math.PI * 2);
        ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 3; ctx.stroke();
        ctx.beginPath(); ctx.arc(x, y, 4, 0, Math.PI * 2);
        ctx.fillStyle = colorFor(ci); ctx.fill();
      });
    }

    function renderSteps() {
      const box = byId('k-steps');
      if (!engine.state.history.length) { box.innerHTML = '<p class="muted small">Belum ada iterasi.</p>'; return; }
      box.innerHTML = engine.state.history.map((h) =>
        `<div class="step-item"><b>Iterasi ${h.iteration}</b> â€” SSE: <span class="tabular">${h.sse}</span> Â· pergeseran centroid: <span class="tabular">${h.shift}</span></div>`
      ).join('');
      box.scrollTop = box.scrollHeight;
      byId('k-iter').textContent = `iterasi ${engine.state.iteration}`;
    }

    function renderResult(final) {
      const box = byId('k-hasil');
      if (!engine.state.done) { box.innerHTML = '<p class="muted small">Jalankan sampai selesai untuk melihat hasil akhir.</p>'; byId('k-simpan').disabled = true; return; }
      const res = engine.result();
      box.innerHTML = res.map((r) => `
        <div class="cluster-card">
          <div class="ct"><span class="chip" style="background:${r.color}">${esc(r.label)}</span><span class="pill-note">${r.jumlah} anggota</span></div>
          <div class="small muted" style="margin-top:10px">Rata terlambat ${r.rata_terlambat} mnt Â· tidak hadir ${r.persen_tidak_hadir}% Â· jam kerja ${r.rata_jam_kerja} jam</div>
          <div class="members">${r.anggota.map((a) => `<span class="member-pill">${esc(a)}</span>`).join('') || '<span class="muted small">Tidak ada anggota</span>'}</div>
        </div>`).join('');
      byId('k-simpan').disabled = false;
    }

    function sync() {
      draw(); renderSteps(); renderResult();
      byId('k-langkah').disabled = !engine || engine.state.done;
      byId('k-jalan').disabled = !engine || engine.state.done;
      byId('k-ulang').disabled = !engine;
    }

    byId('k-siapkan').addEventListener('click', async () => {
      const res = await Api.get('kmeans.dataset', { dari: byId('k-dari').value, sampai: byId('k-sampai').value, tim: byId('k-tim').value });
      data = res.data.filter((r) => r.eligible);
      const k = Number(byId('k-k').value);
      if (data.length < k) {
        toast(`Data memenuhi syarat hanya ${data.length} karyawan, minimal ${k}.`, 'err');
        return;
      }
      seed = (seed * 7 + 13) % 99991;
      engine = KMeans.create(data, k, seed);
      toast(`Disiapkan: ${data.length} karyawan, K=${k}`);
      sync();
    });
    byId('k-langkah').addEventListener('click', () => { if (engine) { engine.step(); sync(); } });
    byId('k-jalan').addEventListener('click', () => { if (engine) { engine.runAll(); sync(); } });
    byId('k-ulang').addEventListener('click', () => { if (engine) { seed = (seed * 7 + 13) % 99991; engine = KMeans.create(data, engine.k, seed); sync(); } });
    byId('k-simpan').addEventListener('click', async () => {
      if (!engine || !engine.state.done) return;
      try {
        await Api.post('klaster.save', {
          periode: `${byId('k-dari').value}..${byId('k-sampai').value}`,
          k: engine.k,
          hasil: engine.result()
        });
        toast('Hasil K-Means disimpan');
      } catch (e) { toast(e.message, 'err'); }
    });
    window.addEventListener('resize', () => { if (engine) draw(); });
    draw();
  }

  // ---------------- Axel: pemeriksa dataset ----------------
  async function viewAxel(view) {
    const [karyawanRes, timRes] = await Promise.all([
      Api.get('karyawan.list').catch(() => ({ data: [] })),
      Api.get('tim.list').catch(() => ({ data: [] }))
    ]);
    const karyawan = karyawanRes.data.filter((k) => k.peran === 'karyawan');
    const timList = timRes.data;

    view.innerHTML = `
      ${pageHead('Axel', 'Periksa dataset absensi lalu unduh sesuai filter aktif', `
        <button class="btn btn-primary" id="axel-pdf">${ico('db')} Unduh PDF</button>`)}
      <div class="stack">
        <div class="glass card">
          <div class="toolbar">
            <label class="field"><span>Dari</span><input type="date" id="a-dari" value="${firstOfMonthISO()}"></label>
            <label class="field"><span>Sampai</span><input type="date" id="a-sampai" value="${endOfMonthISO()}"></label>
            <label class="field"><span>Karyawan</span><select id="a-karyawan"><option value="">Semua karyawan</option>${karyawan.map((k) => `<option value="${k.id}">${esc(k.nama)}</option>`).join('')}</select></label>
            <label class="field"><span>Tim</span><select id="a-tim"><option value="">Semua tim</option>${timList.map((t) => `<option>${esc(t)}</option>`).join('')}</select></label>
            <label class="field"><span>Status</span><select id="a-status"><option value="">Semua status</option>${Object.entries(STATUS_LABEL).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></label>
            <button class="btn btn-primary" id="a-load">Terapkan filter</button>
          </div>
        </div>

        <div class="grid grid-4" id="a-summary"></div>

        <div class="glass card">
          <div class="card-head"><h2>Data absensi</h2><span class="pill-note" id="a-count">0 baris</span></div>
          <div class="table-wrap" id="a-table"><p class="muted">Memuatâ€¦</p></div>
        </div>
      </div>`;

    let dataset = { data: [], ringkasan: {}, filter: {} };

    function currentFilters() {
      const tim = byId('a-tim').value;
      const karyOpt = byId('a-karyawan').selectedOptions[0];
      const status = byId('a-status').value;
      return {
        dari: byId('a-dari').value,
        sampai: byId('a-sampai').value,
        karyawan: byId('a-karyawan').value,
        karyawanNama: karyOpt ? karyOpt.textContent : 'Semua karyawan',
        tim, status
      };
    }

    function renderSummary(s) {
      byId('a-summary').innerHTML = `
        <div class="glass card metric"><div class="value">${s.total || 0}</div><div class="label">Total baris</div></div>
        <div class="glass card metric accent-hadir"><div class="value">${(s.hadir || 0) + (s.terlambat || 0)}</div><div class="label">Hadir / terlambat</div></div>
        <div class="glass card metric accent-izin"><div class="value">${(s.izin || 0) + (s.sakit || 0)}</div><div class="label">Izin / sakit</div></div>
        <div class="glass card metric accent-alpa"><div class="value">${s.rata_terlambat || 0}</div><div class="label">Rata terlambat (mnt)</div></div>`;
    }

    function thumb(file) {
      if (!file) return '<span class="muted small">â€”</span>';
      const url = `${Api.base}/media.php?f=${encodeURIComponent(file)}`;
      return `<img class="thumb" src="${url}" alt="Foto absen" data-photo="${esc(file)}">`;
    }

    function renderTable() {
      const rows = dataset.data;
      byId('a-count').textContent = `${rows.length} baris`;
      byId('a-table').innerHTML = `
        <table>
          <thead><tr>
            <th>Tanggal</th><th>Karyawan</th><th>Tim</th><th>Status</th><th>Masuk</th><th>Pulang</th>
            <th>Durasi</th><th>Terlambat</th><th>Barcode</th><th>Foto</th><th>Catatan</th><th>Dikoreksi</th>
          </tr></thead>
          <tbody>${rows.map((r) => `<tr>
            <td>${fmtTanggal(r.tanggal)}</td>
            <td>${esc(r.nama)}</td>
            <td>${esc(r.tim)}</td>
            <td>${badge(r.status)}</td>
            <td>${fmtJam(r.jam_masuk)}</td>
            <td>${fmtJam(r.jam_pulang)}</td>
            <td>${fmtDurasi(r.durasi_kerja)}</td>
            <td>${r.menit_terlambat == null ? 'â€”' : r.menit_terlambat + ' mnt'}</td>
            <td class="small">${esc(r.barcode_masuk || 'â€”')}</td>
            <td>${thumb(r.foto_masuk)} ${thumb(r.foto_pulang)}</td>
            <td class="wrap">${esc(r.catatan || '')}</td>
            <td class="small">${esc(r.dikoreksi_nama || 'â€”')}</td>
          </tr>`).join('') || '<tr><td colspan="12" class="muted">Tidak ada data sesuai filter.</td></tr>'}</tbody>
        </table>`;
      byId('a-table').querySelectorAll('[data-photo]').forEach((img) =>
        img.addEventListener('click', () => viewPhoto(img.dataset.photo)));
    }

    async function load() {
      const f = currentFilters();
      const res = await Api.get('axel.dataset', {
        dari: f.dari, sampai: f.sampai, karyawan: f.karyawan, tim: f.tim, status: f.status
      });
      dataset = res;
      renderSummary(res.ringkasan);
      renderTable();
    }

    byId('a-load').addEventListener('click', load);
    byId('axel-pdf').addEventListener('click', () => exportAxelPdf());

    function exportAxelPdf() {
      if (!dataset || !dataset.data) return;
      const f = currentFilters();
      const columns = ['Tanggal', 'Karyawan', 'Tim', 'Status', 'Masuk', 'Pulang', 'Durasi', 'Terlambat (mnt)', 'Barcode', 'Catatan', 'Dikoreksi'];
      const rows = dataset.data.map((r) => [
        fmtTanggal(r.tanggal),
        r.nama,
        r.tim,
        STATUS_LABEL[r.status] || r.status,
        fmtJam(r.jam_masuk),
        fmtJam(r.jam_pulang),
        fmtDurasi(r.durasi_kerja),
        r.menit_terlambat == null ? '-' : r.menit_terlambat,
        r.barcode_masuk || '-',
        r.catatan || '-',
        r.dikoreksi_nama || '-'
      ]);
      const s = dataset.ringkasan || {};
      PdfExport.dataset({
        title: 'Dataset Absensi (Axel)',
        orgName: state.org.nama,
        filterLines: [
          `Periode: ${f.dari} s/d ${f.sampai}`,
          `Karyawan: ${f.karyawanNama} | Tim: ${f.tim || 'Semua tim'} | Status: ${f.status ? STATUS_LABEL[f.status] : 'Semua status'}`,
          `Jumlah baris: ${s.total || 0}`
        ],
        columns,
        rows,
        summaryLines: [
          `Hadir: ${s.hadir || 0}   Terlambat: ${s.terlambat || 0}   Izin: ${s.izin || 0}   Sakit: ${s.sakit || 0}   Alpa: ${s.alpa || 0}`,
          `Rata-rata menit terlambat: ${s.rata_terlambat || 0} menit`
        ]
      });
      toast('PDF diunduh');
    }

    await load();
  }

  function viewPhoto(file) {
    const url = `${Api.base}/media.php?f=${encodeURIComponent(file)}`;
    const m = openModal(`
      <div class="modal-head"><h2>Foto absensi</h2><button class="icon-btn" data-close>Ã—</button></div>
      <img src="${url}" alt="Foto absensi" style="width:100%;border-radius:14px">`);
    m.$('[data-close]').addEventListener('click', m.close);
  }

  // ---------------- Pengaturan organisasi ----------------
  async function viewPengaturan(view) {
    const o = state.org;
    const presets = state.presets;
    const hariBoxes = Object.entries(state.hariLabels).map(([num, label]) => {
      const on = o.hari_kerja.split(',').includes(num);
      return `<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" value="${num}" ${on ? 'checked' : ''}> <span>${label}</span></label>`;
    }).join('');

    view.innerHTML = `
      ${pageHead('Pengaturan organisasi', 'Preset industri, jam kerja, dan barcode lokasi')}
      <div class="grid grid-2">
        <div class="glass card">
          <form class="form" id="org-form">
            <label class="field"><span>Nama organisasi</span><input id="o-nama" value="${esc(o.nama)}" required></label>
            <label class="field"><span>Preset industri</span><select id="o-industri">
              ${Object.entries(presets).map(([k, p]) => `<option value="${k}" ${o.industri === k ? 'selected' : ''}>${esc(p.label)}</option>`).join('')}
            </select></label>
            <p class="muted small">Memilih preset akan mengisi jam masuk, pulang, toleransi, dan hari kerja secara otomatis. Anda tetap bisa menyesuaikannya.</p>
            <div class="field-row">
              <label class="field"><span>Jam masuk</span><input type="time" id="o-masuk" value="${esc(o.jam_masuk)}"></label>
              <label class="field"><span>Jam pulang</span><input type="time" id="o-pulang" value="${esc(o.jam_pulang)}"></label>
              <label class="field"><span>Toleransi (menit)</span><input type="number" id="o-toleransi" min="0" max="180" value="${o.toleransi_menit}"></label>
            </div>
            <div class="field"><span>Hari kerja</span><div class="grid grid-3">${hariBoxes}</div></div>
            <label class="field"><span>Kode lokasi (isi barcode/QR yang ditempel di lokasi)</span><input id="o-kode" value="${esc(o.kode_lokasi)}" required></label>
            <div class="field-row">
              <label style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="o-foto" ${Number(o.wajib_foto) ? 'checked' : ''}> <span>Wajib foto saat absen</span></label>
              <label style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="o-barcode" ${Number(o.wajib_barcode) ? 'checked' : ''}> <span>Wajib pindai barcode</span></label>
            </div>
            <div class="alert alert-error" id="o-err" hidden></div>
            <button class="btn btn-primary" type="submit">Simpan perubahan</button>
          </form>
        </div>

        <div class="glass card">
          <div class="card-head"><h2>Barcode lokasi</h2></div>
          <p class="muted small" style="margin-bottom:12px">Cetak atau tampilkan QR ini di pintu masuk. Karyawan memindainya saat check-in/check-out.</p>
          <div class="qr-holder" id="qr-holder"></div>
          <p class="muted small" style="margin-top:12px">Kode: <b id="qr-code">${esc(o.kode_lokasi)}</b></p>
        </div>
      </div>`;

    function renderQr(code) {
      const holder = byId('qr-holder');
      try {
        const qr = qrcode(0, 'M');
        qr.addData(code || 'ABSEN');
        qr.make();
        holder.innerHTML = qr.createSvgTag(5, 8);
      } catch (e) {
        holder.innerHTML = '<p class="muted small">Gagal membuat QR.</p>';
      }
      byId('qr-code').textContent = code || '-';
    }
    renderQr(o.kode_lokasi);
    byId('o-kode').addEventListener('input', (e) => renderQr(e.target.value));

    byId('o-industri').addEventListener('change', (e) => {
      const p = presets[e.target.value];
      if (!p) return;
      byId('o-masuk').value = p.jam_masuk;
      byId('o-pulang').value = p.jam_pulang;
      byId('o-toleransi').value = p.toleransi_menit;
      const days = p.hari_kerja.split(',');
      view.querySelectorAll('.grid-3 input[type="checkbox"]').forEach((c) => (c.checked = days.includes(c.value)));
    });

    byId('org-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errBox = byId('o-err');
      errBox.hidden = true;
      const hariKerja = [...view.querySelectorAll('.grid-3 input[type="checkbox"]')].filter((c) => c.checked).map((c) => Number(c.value));
      if (!hariKerja.length) { errBox.textContent = 'Pilih minimal satu hari kerja.'; errBox.hidden = false; return; }
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
          wajib_barcode: byId('o-barcode').checked ? 1 : 0
        });
        state.org = res.organisasi;
        byId('side-org').textContent = state.org.nama;
        toast('Pengaturan disimpan');
      } catch (err) { errBox.textContent = err.message; errBox.hidden = false; }
    });
  }

  // ---------------- Kelola karyawan ----------------
  async function viewKaryawan(view) {
    view.innerHTML = `
      ${pageHead('Karyawan', 'Tambah, ubah, dan nonaktifkan akun', `
        <button class="btn btn-primary" id="add-emp">Tambah karyawan</button>`)}
      <div class="glass card" id="emp-box"><p class="muted">Memuatâ€¦</p></div>`;

    async function load() {
      const { data } = await Api.get('karyawan.list');
      byId('emp-box').innerHTML = `
        <div class="table-wrap"><table>
          <thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Tim</th><th>Jabatan</th><th>Status</th><th></th></tr></thead>
          <tbody>${data.map((u) => `<tr>
            <td>${esc(u.nama)}</td><td>${esc(u.email)}</td><td>${PERAN_LABEL[u.peran] || u.peran}</td>
            <td>${esc(u.tim)}</td><td>${esc(u.jabatan || 'â€”')}</td>
            <td>${Number(u.aktif) ? '<span class="badge badge-hadir">Aktif</span>' : '<span class="badge badge-netral">Nonaktif</span>'}</td>
            <td class="toolbar">
              <button class="btn btn-secondary btn-sm" data-edit='${esc(JSON.stringify(u))}'>Ubah</button>
              <button class="btn btn-ghost btn-sm" data-toggle="${u.id}" data-aktif="${Number(u.aktif) ? 0 : 1}">${Number(u.aktif) ? 'Nonaktifkan' : 'Aktifkan'}</button>
            </td>
          </tr>`).join('')}</tbody>
        </table></div>`;
      byId('emp-box').querySelectorAll('[data-edit]').forEach((b) =>
        b.addEventListener('click', () => openEmpForm(JSON.parse(b.dataset.edit), load)));
      byId('emp-box').querySelectorAll('[data-toggle]').forEach((b) =>
        b.addEventListener('click', async () => {
          try {
            await Api.post('karyawan.toggle', { id: Number(b.dataset.toggle), aktif: Number(b.dataset.aktif) });
            toast('Status diperbarui'); load();
          } catch (e) { toast(e.message, 'err'); }
        }));
    }
    byId('add-emp').addEventListener('click', () => openEmpForm(null, load));
    await load();
  }

  function openEmpForm(u, reload) {
    const edit = !!u;
    const m = openModal(`
      <div class="modal-head"><h2>${edit ? 'Ubah' : 'Tambah'} karyawan</h2><button class="icon-btn" data-close>Ã—</button></div>
      <div class="form">
        <label class="field"><span>Nama</span><input id="e-nama" value="${esc(u?.nama || '')}" required></label>
        <div class="field-row">
          <label class="field"><span>Email</span><input type="email" id="e-email" value="${esc(u?.email || '')}" required></label>
          <label class="field"><span>Peran</span><select id="e-peran">${Object.entries(PERAN_LABEL).map(([k, v]) => `<option value="${k}" ${u?.peran === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label>
        </div>
        <div class="field-row">
          <label class="field"><span>Tim</span><input id="e-tim" value="${esc(u?.tim || 'Umum')}"></label>
          <label class="field"><span>Jabatan</span><input id="e-jabatan" value="${esc(u?.jabatan || '')}"></label>
        </div>
        <label class="field"><span>Kata sandi ${edit ? '(biarkan kosong bila tidak diubah)' : ''}</span><input type="password" id="e-sandi" placeholder="minimal 6 karakter"></label>
        <label style="display:flex;gap:10px;align-items:center"><input type="checkbox" id="e-aktif" ${!edit || Number(u.aktif) ? 'checked' : ''}> <span>Aktif</span></label>
        <div class="alert alert-error" id="e-err" hidden></div>
        <button class="btn btn-primary btn-block" id="e-save">Simpan</button>
      </div>`);
    m.$('[data-close]').addEventListener('click', m.close);
    m.$('#e-save').addEventListener('click', async () => {
      const errBox = m.$('#e-err'); errBox.hidden = true;
      try {
        await Api.post('karyawan.save', {
          id: u?.id || 0,
          nama: m.$('#e-nama').value,
          email: m.$('#e-email').value,
          peran: m.$('#e-peran').value,
          tim: m.$('#e-tim').value,
          jabatan: m.$('#e-jabatan').value,
          kata_sandi: m.$('#e-sandi').value,
          aktif: m.$('#e-aktif').checked ? 1 : 0
        });
        m.close(); toast('Tersimpan'); reload();
      } catch (e) { errBox.textContent = e.message; errBox.hidden = false; }
    });
  }

  // ---------------- Izin (karyawan) ----------------
  async function viewIzin(view) {
    const res = await Api.get('absensi.today');
    const d = res.data;
    const riwayat = res.riwayat.filter((r) => ['izin', 'sakit', 'alpa'].includes(r.status));
    view.innerHTML = `
      ${pageHead('Pengajuan izin / sakit', 'Lampirkan foto bukti (surat dokter, dsb.)')}
      <div class="grid grid-2">
        <div class="glass card">
          <form class="form" id="izin-form">
            <label class="field"><span>Jenis</span><select id="i-jenis"><option value="izin">Izin</option><option value="sakit">Sakit</option></select></label>
            <div class="field-row">
              <label class="field"><span>Mulai</span><input type="date" id="i-mulai" value="${todayISO()}"></label>
              <label class="field"><span>Selesai</span><input type="date" id="i-sampai" value="${todayISO()}"></label>
            </div>
            <label class="field"><span>Catatan</span><textarea id="i-catatan" placeholder="cth: demam, ada surat dokter"></textarea></label>
            <div class="field"><span>Foto bukti ${d.wajib_foto ? '(wajib)' : '(opsional)'}</span>
              <button type="button" class="btn btn-secondary" id="i-foto">Ambil foto</button>
              <img id="i-preview" class="shot-preview" style="max-width:220px;margin-top:10px;display:none" alt="Pratinjau">
            </div>
            <div class="alert alert-error" id="i-err" hidden></div>
            <button class="btn btn-primary" type="submit">Kirim pengajuan</button>
          </form>
        </div>
        <div class="glass card">
          <div class="card-head"><h2>Riwayat izin & sakit</h2></div>
          <div class="riwayat">
            ${riwayat.length ? riwayat.map((r) => `<div class="riwayat-row">
              <div><div class="t">${fmtTanggal(r.tanggal)}</div><div class="s">${esc(r.catatan || '')}</div></div>${badge(r.status)}
            </div>`).join('') : '<p class="muted small">Belum ada pengajuan.</p>'}
          </div>
        </div>
      </div>`;

    let foto = null;
    byId('i-foto').addEventListener('click', async () => {
      try {
        const shot = await Scanner.open({ wajibBarcode: false, wajibFoto: true, title: 'Foto bukti' });
        foto = shot.foto;
        const img = byId('i-preview');
        img.src = foto; img.style.display = 'block';
      } catch (e) { if (e.message !== 'dibatalkan') toast(e.message, 'err'); }
    });

    byId('izin-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errBox = byId('i-err'); errBox.hidden = true;
      if (d.wajib_foto && !foto) { errBox.textContent = 'Foto bukti wajib dilampirkan.'; errBox.hidden = false; return; }
      try {
        const r = await Api.post('absensi.izin', {
          jenis: byId('i-jenis').value,
          mulai: byId('i-mulai').value,
          sampai: byId('i-sampai').value,
          catatan: byId('i-catatan').value,
          foto
        });
        toast(`${r.pesan} (${r.data.hari} hari)`);
        go('izin');
      } catch (err) { errBox.textContent = err.message; errBox.hidden = false; }
    });
  }

  // ---------------- Tema & logout ----------------
  function initTheme() {
    const saved = localStorage.getItem('absensi-theme');
    if (saved) document.documentElement.dataset.theme = saved;
    byId('theme-toggle').addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      localStorage.setItem('absensi-theme', next);
    });
  }

  // ---------------- Init ----------------
  async function init() {
    initTheme();
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
    byId('user-name').textContent = state.user.nama;
    byId('user-role').textContent = PERAN_LABEL[state.user.peran] || state.user.peran;
    byId('user-avatar').textContent = (state.user.nama || '?').charAt(0).toUpperCase();

    const def = state.user.peran === 'karyawan' ? 'beranda' : 'dasbor';
    await go(def);
  }

  return { init, go };
})();

App.init();
