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

  async function getStream(facing) {
    stopCamera();
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: facing } }, audio: false
    });
    return stream;
  }

  function open(options) {
    const opt = Object.assign(
      { wajibBarcode: true, wajibFoto: true, kodeLokasi: '', title: 'Absen' },
      options
    );

    return new Promise((resolve, reject) => {
      const overlay = h('div', { class: 'overlay', role: 'dialog', 'aria-modal': 'true' });
      const modal = h('div', { class: 'glass modal' });
      overlay.append(modal);
      document.body.append(overlay);

      const state = { barcode: null, foto: null, phase: null };
      const cleanup = () => { stopCamera(); overlay.remove(); };
      const cancel = () => { cleanup(); reject(new Error('dibatalkan')); };
      const head = (title) => h('div', { class: 'modal-head' }, [
        h('h2', {}, title),
        h('button', { class: 'icon-btn', onclick: cancel, 'aria-label': 'Tutup' }, '×')
      ]);

      function finish() {
        if (opt.wajibFoto && !state.foto) return;
        const result = { barcode: state.barcode, foto: state.foto };
        cleanup();
        resolve(result);
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
        const input = h('input', { type: 'text', placeholder: 'cth: ABSEN-KANTOR-01' });
        wrap.append(
          h('p', { class: 'alert alert-error' }, 'Kamera tidak tersedia atau izin ditolak. Masukkan kode lokasi secara manual.'),
          h('label', { class: 'field' }, [h('span', {}, 'Kode lokasi'), input]),
          h('button', {
            class: 'btn btn-primary btn-block',
            onclick: () => {
              const val = input.value.trim();
              if (!val) return;
              state.barcode = val;
              goPhoto();
            }
          }, 'Lanjut')
        );
        modal.append(wrap);
      }

      async function phasePhoto() {
        state.phase = 'photo';
        modal.innerHTML = '';
        const actions = h('div', { class: 'form', style: 'margin-top:16px' });
        actions.append(
          h('button', { class: 'btn btn-primary btn-block btn-lg', id: 'btn-capture' }, 'Ambil foto'),
          opt.wajibFoto ? null : h('button', { class: 'btn btn-secondary btn-block', onclick: finish }, 'Lewati foto'),
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
          file.addEventListener('change', () => {
            const f = file.files && file.files[0];
            if (!f) return;
            const reader = new FileReader();
            reader.onload = () => { state.foto = reader.result; goConfirm(); };
            reader.readAsDataURL(f);
          });
          actions.insertBefore(file, actions.firstChild);
        }

        modal.querySelector('#btn-capture').addEventListener('click', () => {
          if (!video.videoWidth) return;
          const shot = document.createElement('canvas');
          const maxW = 720;
          const ratio = video.videoHeight / video.videoWidth;
          shot.width = Math.min(maxW, video.videoWidth);
          shot.height = Math.round(shot.width * ratio);
          shot.getContext('2d').drawImage(video, 0, 0, shot.width, shot.height);
          state.foto = shot.toDataURL('image/jpeg', 0.82);
          goConfirm();
        });
      }

      function phaseConfirm() {
        state.phase = 'confirm';
        modal.innerHTML = '';
        const info = h('div', { class: 'stack', style: 'margin-top:16px' });
        if (opt.wajibBarcode) {
          info.append(h('div', { class: 'riwayat-row' }, [
            h('span', { class: 't' }, 'Barcode'), h('span', { class: 's' }, state.barcode || '-')
          ]));
        }
        info.append(
          h('button', { class: 'btn btn-primary btn-block btn-lg', onclick: finish }, 'Kirim absen'),
          h('button', { class: 'btn btn-secondary btn-block', onclick: () => (opt.wajibBarcode ? phaseScan() : phasePhoto()) }, 'Ulangi'),
          h('button', { class: 'btn btn-ghost btn-block', onclick: cancel }, 'Batal')
        );
        modal.append(
          head('Konfirmasi'),
          state.foto ? h('img', { class: 'shot-preview', src: state.foto, alt: 'Pratinjau foto' }) : null,
          info
        );
      }

      function goPhoto() { stopCamera(); phasePhoto(); }
      function goConfirm() { stopCamera(); phaseConfirm(); }

      if (opt.wajibBarcode) phaseScan();
      else phasePhoto();
    });
  }

  return { open };
})();
