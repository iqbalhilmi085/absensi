const Api = (() => {
  const base = (window.APP_CONFIG?.apiBase || '../backend').replace(/\/$/, '');
  let csrf = null;

  async function ensureCsrf() {
    if (csrf) return csrf;
    const res = await fetch(`${base}/api.php?action=auth.csrf`, { credentials: 'same-origin' });
    const data = await res.json();
    csrf = data.csrf || null;
    return csrf;
  }

  function buildQuery(params) {
    if (!params) return '';
    const usp = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') usp.append(k, v);
    });
    const s = usp.toString();
    return s ? `&${s}` : '';
  }

  async function handle(res) {
    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Respons server tidak valid.');
    }
    if (res.status === 401) {
      if (!location.pathname.endsWith('login.html')) {
        location.href = 'login.html';
      }
      throw new Error(data.error || 'Sesi berakhir.');
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Permintaan gagal.');
    }
    return data;
  }

  async function get(action, params) {
    const res = await fetch(`${base}/api.php?action=${encodeURIComponent(action)}${buildQuery(params)}`, {
      credentials: 'same-origin'
    });
    return handle(res);
  }

  async function post(action, data) {
    await ensureCsrf();
    const res = await fetch(`${base}/api.php?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf || ''
      },
      body: JSON.stringify(data || {})
    });
    if (res.status === 419) csrf = null;
    return handle(res);
  }

  return { get, post, base, resetCsrf: () => (csrf = null) };
})();
