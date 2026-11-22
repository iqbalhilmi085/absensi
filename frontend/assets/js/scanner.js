const Scanner = (() => {
  let stream = null;
  let rafId = null;

  function stopCamera() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
  }

  function h(tag, attrs = {}, children = []) {
    const el = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'style') el.setAttribute('style', v);
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else el.setAttribute(k, v);
    });
    (Array.isArray(children) ? children : [children]).forEach((c) => c && el.append(c));
    return el;
  }

  // ---------------- Kompresi gambar di sisi klien ----------------
  // Mengubah data URL menjadi JPEG terkompresi agar unggahan cepat dan
  // hemat penyimpanan peladen.
  function compress(dataUrl, maxW = 720, quality = 0.8) {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => {
        const ratio = img.height / img.width;
        const w = Math.min(maxW, img.width);
        const ht = Math.max(1, Math.round(w * ratio));
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = ht;
        canvas.getContext('2d').drawImage(img, 0, 0, w, ht);
        try {
          resolve(canvas.toDataURL('image/jpeg', quality));
        } catch (e) {
          resolve(dataUrl);
        }
      };
      img.onerror = () => resolve(dataUrl);
      img.src = dataUrl;
    });
  }

  // ---------------- GPS ----------------
  function haversine(aLat, aLng, bLat, bLng) {
    const R = 6371000;
    const dLat = ((bLat - aLat) * Math.PI) / 180;
    const dLng = ((bLng - aLng) * Math.PI) / 180;
    const s = Math.sin(dLat / 2) ** 2 +
      Math.cos((aLat * Math.PI) / 180) * Math.cos((bLat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s)));
  }

  function geoErrorMessage(err) {
    if (!err) return 'Lokasi tidak tersedia.';
    switch (err.code) {
      case 1: return 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan peramban, lalu coba lagi.';
      case 2: return 'Lokasi tidak dapat ditemukan. Pastikan GPS aktif dan Anda berada di area terbuka.';
      case 3: return 'Pengambilan lokasi melebihi batas waktu. Coba lagi di area dengan sinyal lebih baik.';
      default: return 'Gagal mendapatkan lokasi: ' + (err.message || 'kesalahan tidak diketahui');
    }
  }

  /**
   * Mengambil titik lokasi dengan akurasi tertinggi menggunakan sensor
   * perangkat. Menampung beberapa sampel lalu memilih yang paling akurat
   * dalam jendela waktu tertentu.
   *
   * Deteksi GPS palsu bersifat heuristik (peramban tidak menyediakan API
   * mock-location resmi): akurasi mustahil (0), koordinat (0,0), atau
   * lompatan melebihi kecepatan fisik wajar.
   */
  function acquireLocation(opts = {}) {
    const targetAkurasi = opts.targetAkurasi || 30;
    const maxWaitMs = opts.maxWaitMs || 12000;
    return new Promise((resolve) => {
      if (!('geolocation' in navigator)) {
        resolve({ ok: false, error: 'Peramban ini tidak mendukung layanan lokasi (GPS).' });
        return;
      }
      let best = null;
      let prev = null;
      let palsu = false;
      let lastError = null;
      let selesai = false;

      const finish = () => {
        if (selesai) return;
        selesai = true;
        clearTimeout(deadline);
        try { navigator.geolocation.clearWatch(watchId); } catch (e) { /* noop */ }
        if (best) resolve({ ok: true, lat: best.lat, lng: best.lng, akurasi: best.akurasi, palsu });
        else resolve({ ok: false, error: geoErrorMessage(lastError) || 'Lokasi tidak tersedia.' });
      };

      const deadline = setTimeout(finish, maxWaitMs);

      const watchId = navigator.geolocation.watchPosition(
        (pos) => {
          const c = pos.coords;
          const lat = c.latitude;
          const lng = c.longitude;
          const akurasi = Number(c.accuracy);

          if (lat === 0 && lng === 0) palsu = true;
          if (!isFinite(akurasi) || akurasi <= 0 || akurasi < 1) palsu = true;

          if (prev) {
            const dt = Math.max(0.001, (pos.timestamp - prev.t) / 1000);
            const jarak = haversine(prev.lat, prev.lng, lat, lng);
            if (jarak / dt > 300) palsu = true;
          }
          prev = { lat, lng, t: pos.timestamp };

          if (!best || akurasi < best.akurasi) {
            best = { lat, lng, akurasi: Math.round(akurasi) };
          }
          if (akurasi <= targetAkurasi) finish();
        },
        (err) => { lastError = err; if (!best) finish(); },
        { enableHighAccuracy: true, maximumAge: 0, timeout: maxWaitMs }
      );
    });
  }

  async function getStream(facing) {
    stopCamera();
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: facing } }, audio: false
    });
    return stream;
  }

  function open(options) {
    const opt = Object.assign(
      {
        mode: 'wfo',
        wajibBarcode: true,
        wajibFoto: true,
        wajibGps: false,
        radius: 0,
        latKantor: null,
        lngKantor: null,
        kodeLokasi: '',
        title: 'Absen'
      },
      options
    );

    const isWfh = opt.mode === 'wfh';
    const needBarcode = !isWfh && !!opt.wajibBarcode;
    const needFoto = !!opt.wajibFoto;
    const butuhGps = !isWfh && !!(opt.wajibGps || opt.radius > 0);

    if (!needBarcode && !needFoto && !butuhGps) {
      return Promise.resolve({
        barcode: null,
        foto: null,
        gps: null,
        palsu: false
      });
    }

    return new Promise((resolve, reject) => {
      const overlay = h('div', { class: 'overlay', role: 'dialog', 'aria-modal': 'true' });
      const modal = h('div', { class: 'glass modal' });
      overlay.append(modal);
      document.body.append(overlay);

      const state = {
        barcode: null,
        foto: null,
        gps: null,
        gpsError: '',
        gpsLoading: false,
        palsu: false,
        phase: null
      };
      const cleanup = () => { stopCamera(); overlay.remove(); };
      const cancel = () => { cleanup(); reject(new Error('dibatalkan')); };
      const head = (title) => h('div', { class: 'modal-head' }, [
        h('h2', {}, title),
        h('button', { class: 'icon-btn', onclick: cancel, 'aria-label': 'Tutup' }, '×')
      ]);

      function jarakKeKantor(gps) {
        if (!gps || opt.latKantor == null || opt.lngKantor == null) return null;
        return haversine(opt.latKantor, opt.lngKantor, gps.lat, gps.lng);
      }

      function expectsBarcode() { return needBarcode; }
      function readyToSubmit() {
        if (needFoto && !state.foto) return false;
        if (butuhGps && !state.gps) return false;
        return true;
      }

      function finish() {
        if (!readyToSubmit()) return;
        const result = {
          barcode: state.barcode,
          foto: state.foto,
          gps: state.gps,
          palsu: state.palsu
        };
        cleanup();
        resolve(result);
      }

      // Mulai akuisisi lokasi sedini mungkin agar siap saat konfirmasi.
      function mulaiGps() {
        if (state.gpsLoading) return;
        state.gpsLoading = true;
        state.gpsError = '';
        acquireLocation().then((res) => {
          state.gpsLoading = false;
          if (res.ok) {
            state.gps = { lat: res.lat, lng: res.lng, akurasi: res.akurasi, jarak: jarakKeKantor(res) };
            state.palsu = !!res.palsu;
          } else {
            state.gpsError = res.error;
          }
          if (state.phase === 'confirm') renderConfirm();
        });
      }

      async function phaseScan() {
        state.phase = 'scan';
        modal.innerHTML = '';
        const status = h('p', { class: 'cam-status' }, opt.kodeLokasi
          ? `Arahkan kamera ke barcode lokasi (kode: ${opt.kodeLokasi}).`
          : 'Arahkan kamera ke barcode lokasi.');
        modal.append(
          head('Pindai barcode lokasi'),
          h('div', { class: 'cam-wrap' }, [
            h('video', { playsinline: 'true', muted: 'true', autoplay: 'true' }),
            h('div', { class: 'scan-frame' })
          ]),
          status
        );

        const video = modal.querySelector('video');
        try {
          stream = await getStream('environment');
          video.srcObject = stream;
          await video.play();
        } catch (e) {
          showManualInput();
          return;
        }

        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        const expected = (opt.kodeLokasi || '').trim().toLowerCase();
        let lastWarn = 0;

        const tick = () => {
          if (state.phase !== 'scan') return;
          if (video.readyState >= 2 && window.jsQR) {
            const w = video.videoWidth, ht = video.videoHeight;
            if (w && ht) {
              canvas.width = w; canvas.height = ht;
              ctx.drawImage(video, 0, 0, w, ht);
              const img = ctx.getImageData(0, 0, w, ht);
              const code = jsQR(img.data, w, ht, { inversionAttempts: 'dontInvert' });
              if (code && code.data) {
                const got = String(code.data).trim();
                if (expected && got.toLowerCase() !== expected) {
                  if (Date.now() - lastWarn > 1500) {
                    lastWarn = Date.now();
                    status.textContent = `Kode terbaca "${got}" tidak cocok dengan lokasi ini.`;
                    status.style.color = 'var(--danger)';
                    setTimeout(() => {
                      if (state.phase === 'scan') {
                        status.style.color = '';
                        status.textContent = `Arahkan kamera ke barcode lokasi (kode: ${opt.kodeLokasi}).`;
                      }
                    }, 1500);
                  }
                } else {
                  state.barcode = got;
                  stopCamera();
                  goPhoto();
                  return;
                }
              }
            }
          }
          rafId = requestAnimationFrame(tick);
        };
        rafId = requestAnimationFrame(tick);
      }

      function showManualInput() {
        const wrap = h('div', { class: 'form', style: 'margin-top:16px' });
        const input = h('input', { type: 'text', placeholder: 'Contoh: ABSEN-KANTOR-01' });
        const errEl = h('p', { class: 'alert alert-error', style: 'display:none' });
        const submitManual = () => {
          const val = input.value.trim();
          if (!val) {
            errEl.textContent = 'Mohon ketik kode lokasi kantor terlebih dahulu.';
            errEl.style.display = 'block';
            return;
          }
          state.barcode = val;
          goPhoto();
        };
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') { e.preventDefault(); submitManual(); }
        });
        wrap.append(
          h('p', { class: 'alert alert-error' }, 'Kamera tidak tersedia atau izin ditolak. Masukkan kode lokasi kantor secara manual.'),
          h('label', { class: 'field' }, [h('span', {}, 'Kode QR / Lokasi Kantor'), input]),
          errEl,
          h('button', { class: 'btn btn-primary btn-block', onclick: submitManual }, 'Lanjut')
        );
        modal.append(wrap);
      }

      async function phasePhoto() {
        state.phase = 'photo';
        modal.innerHTML = '';
        const actions = h('div', { class: 'form', style: 'margin-top:16px' });
        actions.append(
          h('button', { class: 'btn btn-primary btn-block btn-lg', id: 'btn-capture' }, 'Ambil foto'),
          opt.wajibFoto ? null : h('button', { class: 'btn btn-secondary btn-block', onclick: goConfirm }, 'Lewati foto'),
          h('button', { class: 'btn btn-ghost btn-block', onclick: cancel }, 'Batal')
        );
        modal.append(
          head('Ambil foto'),
          h('div', { class: 'cam-wrap' }, [h('video', { playsinline: 'true', muted: 'true', autoplay: 'true' })]),
          h('p', { class: 'cam-status' }, opt.wajibFoto ? 'Foto wajib diambil untuk melanjutkan.' : 'Foto opsional.'),
          actions
        );

        const video = modal.querySelector('video');
        try {
          stream = await getStream('user');
          video.srcObject = stream;
          await video.play();
        } catch (e) {
          modal.querySelector('.cam-status').textContent = 'Kamera tidak tersedia. Unggah foto sebagai gantinya.';
          const file = h('input', { type: 'file', accept: 'image/*' });
          file.addEventListener('change', async () => {
            const f = file.files && file.files[0];
            if (!f) return;
            const reader = new FileReader();
            reader.onload = async () => {
              state.foto = await compress(reader.result, 720, 0.8);
              goConfirm();
            };
            reader.readAsDataURL(f);
          });
          actions.insertBefore(file, actions.firstChild);
        }

        modal.querySelector('#btn-capture').addEventListener('click', async () => {
          if (!video.videoWidth) {
            const st = modal.querySelector('.cam-status');
            if (st) st.textContent = 'Kamera sedang bersiap, tunggu sebentar lalu klik lagi.';
            return;
          }
          const shot = document.createElement('canvas');
          const maxW = 720;
          const ratio = video.videoHeight / video.videoWidth;
          shot.width = Math.min(maxW, video.videoWidth);
          shot.height = Math.round(shot.width * ratio);
          shot.getContext('2d').drawImage(video, 0, 0, shot.width, shot.height);
          state.foto = await compress(shot.toDataURL('image/jpeg', 0.82), 720, 0.8);
          goConfirm();
        });
      }

      function renderConfirm() {
        modal.innerHTML = '';
        const info = h('div', { class: 'stack', style: 'margin-top:16px' });
        if (needBarcode) {
          info.append(h('div', { class: 'riwayat-row' }, [
            h('span', { class: 't' }, 'Kode QR Kantor'), h('span', { class: 's' }, state.barcode || '-')
          ]));
        }

        // Status lokasi.
        let lokasiText = isWfh ? 'Mode WFH · Lokasi kantor tidak diwajibkan.' : 'Lokasi GPS tidak diperlukan.';
        let lokasiClass = 'muted small';
        if (butuhGps || state.gpsLoading || state.gps || state.gpsError) {
          if (state.gpsLoading) {
            lokasiText = 'Mengambil lokasi GPS (akurasi tertinggi)…';
          } else if (state.gps) {
            const j = state.gps.jarak;
            lokasiText = `Lokasi siap · akurasi ±${state.gps.akurasi} m` +
              (j != null ? ` · jarak ${j} m dari kantor` : '') +
              (state.palsu ? ' · peringatan: lokasi mencurigakan' : '');
          } else if (state.gpsError) {
            lokasiText = state.gpsError;
            lokasiClass = 'alert alert-error';
          } else {
            lokasiText = 'Menunggu lokasi…';
          }
        }
        const lokasiEl = h('div', { class: lokasiClass, id: 'loc-status' }, lokasiText);
        if (butuhGps && !state.gps && !state.gpsLoading) {
          lokasiEl.append(h('button', {
            class: 'btn btn-secondary btn-sm', style: 'margin-top:8px;width:100%',
            onclick: () => { state.gpsError = ''; mulaiGps(); renderConfirm(); }
          }, 'Coba ambil lokasi lagi'));
        }

        const siap = readyToSubmit();
        const kirim = h('button', {
          class: 'btn btn-primary btn-block btn-lg',
          id: 'btn-send',
          disabled: !siap,
          onclick: finish
        }, siap ? 'Kirim absen' : (state.gpsLoading ? 'Menunggu lokasi…' : 'Lengkapi data…'));

        info.append(
          h('div', { class: 'riwayat-row' }, [h('span', { class: 't' }, 'Lokasi'), lokasiEl]),
          kirim,
          h('button', { class: 'btn btn-secondary btn-block', onclick: () => (expectsBarcode() ? phaseScan() : phasePhoto()) }, 'Ulangi'),
          h('button', { class: 'btn btn-ghost btn-block', onclick: cancel }, 'Batal')
        );
        modal.append(
          head('Konfirmasi'),
          state.foto ? h('img', { class: 'shot-preview', src: state.foto, alt: 'Pratinjau foto' }) : null,
          info
        );
      }

      function phaseConfirm() {
        state.phase = 'confirm';
        modal.innerHTML = '';
        renderConfirm();
      }

      function goPhoto() { stopCamera(); phasePhoto(); }
      function goConfirm() { stopCamera(); phaseConfirm(); }

      if (butuhGps) mulaiGps();
      if (needBarcode) phaseScan();
      else if (needFoto) phasePhoto();
      else phaseConfirm();
    });
  }

  return { open, acquireLocation, compress };
})();
