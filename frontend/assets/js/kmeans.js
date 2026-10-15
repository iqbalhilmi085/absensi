const KMeans = (() => {
  const FEATURES = ['rata_terlambat', 'persen_tidak_hadir', 'rata_jam_kerja'];
  const LABELS = {
    2: ['Disiplin', 'Perlu perhatian'],
    3: ['Disiplin', 'Cukup', 'Perlu perhatian'],
    4: ['Sangat disiplin', 'Baik', 'Cukup', 'Perlu perhatian']
  };
  const COLORS = {
    2: ['#16a34a', '#dc2626'],
    3: ['#16a34a', '#d97706', '#dc2626'],
    4: ['#16a34a', '#d97706', '#7c3aed', '#dc2626']
  };
  const DARK_COLORS = {
    2: ['#4ade80', '#f87171'],
    3: ['#4ade80', '#fbbf24', '#f87171'],
    4: ['#4ade80', '#fbbf24', '#a78bfa', '#f87171']
  };

  function mulberry32(seed) {
    let a = seed >>> 0;
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function normalize(rows) {
    const min = {}, max = {};
    FEATURES.forEach((f) => {
      const vals = rows.map((r) => Number(r[f]) || 0);
      min[f] = Math.min(...vals);
      max[f] = Math.max(...vals);
    });
    const points = rows.map((r) => {
      const p = {};
      FEATURES.forEach((f) => {
        const range = max[f] - min[f];
        p[f] = range === 0 ? 0 : ((Number(r[f]) || 0) - min[f]) / range;
      });
      // Balik jam kerja: makin kecil jam kerja, makin perlu perhatian.
      p.rata_jam_kerja = 1 - p.rata_jam_kerja;
      return p;
    });
    return { min, max, points };
  }

  function vec(p) { return FEATURES.map((f) => p[f]); }

  function distance(a, b) {
    let s = 0;
    for (let i = 0; i < a.length; i++) s += (a[i] - b[i]) ** 2;
    return Math.sqrt(s);
  }

  function meanOf(vectors) {
    if (!vectors.length) return null;
    const dim = vectors[0].length;
    const out = new Array(dim).fill(0);
    vectors.forEach((v) => v.forEach((x, i) => (out[i] += x)));
    return out.map((x) => x / vectors.length);
  }

  function create(data, k, seed) {
    const norm = normalize(data);
    const points = norm.points.map(vec);
    const n = points.length;
    const rng = mulberry32(seed || 12345);

    // Titik awal acak unik
    const idx = new Set();
    let guard = 0;
    while (idx.size < Math.min(k, n) && guard < 1000) {
      idx.add(Math.floor(rng() * n));
      guard++;
    }
    let centroids = [...idx].slice(0, k).map((i) => points[i].slice());

    const state = {
      points, centroids, iteration: 0, sse: 0, done: false,
      labels: new Array(n).fill(-1),
      history: [],
      shift: 0
    };

    function assign() {
      let sse = 0;
      state.labels = points.map((p) => {
        let best = 0, bestD = Infinity;
        centroids.forEach((c, ci) => {
          const d = distance(p, c);
          if (d < bestD) { bestD = d; best = ci; }
        });
        sse += bestD * bestD;
        return best;
      });
      return sse;
    }

    function update() {
      const groups = centroids.map(() => []);
      points.forEach((p, i) => groups[state.labels[i]].push(p));
      let shift = 0;
      centroids = centroids.map((c, ci) => {
        const m = meanOf(groups[ci]);
        if (!m) return c; // kelompok kosong: biarkan
        const d = distance(c, m);
        shift = Math.max(shift, d);
        return m;
      });
      return shift;
    }

    function step() {
      if (state.done) return state;
      state.sse = assign();
      const shift = update();
      state.iteration++;
      state.shift = shift;
      state.history.push({
        iteration: state.iteration,
        sse: Number(state.sse.toFixed(6)),
        shift: Number(shift.toFixed(8)),
        centroids: centroids.map((c) => c.map((x) => Number(x.toFixed(4))))
      });
      if (shift < 0.000001 || state.iteration >= 200) state.done = true;
      return state;
    }

    function runAll() {
      let guard = 0;
      while (!state.done && guard < 250) { step(); guard++; }
      return state;
    }

    function result() {
      const groups = centroids.map((_, ci) =>
        data.map((r, i) => ({ row: r, idx: i })).filter((x) => state.labels[x.idx] === ci)
      );
      const ranked = centroids.map((c, ci) => ({
        ci,
        score: c.reduce((a, b) => a + b, 0),
        members: groups[ci]
      })).sort((a, b) => a.score - b.score);

      const labels = LABELS[k] || LABELS[3];
      const colors = COLORS[k] || COLORS[3];
      const darkColors = DARK_COLORS[k] || DARK_COLORS[3];

      const rows = ranked.map((r, rank) => {
        const members = r.members;
        const avg = (f) => members.length
          ? Number((members.reduce((a, m) => a + (Number(m.row[f]) || 0), 0) / members.length).toFixed(2))
          : 0;
        return {
          cluster: r.ci,
          rank,
          label: labels[rank] || `Kelompok ${rank + 1}`,
          color: colors[rank],
          darkColor: darkColors[rank],
          jumlah: members.length,
          rata_terlambat: avg('rata_terlambat'),
          persen_tidak_hadir: avg('persen_tidak_hadir'),
          rata_jam_kerja: avg('rata_jam_kerja'),
          centroid: state.centroids[r.ci],
          anggota: members.map((m) => m.row.nama)
        };
      });
      return rows;
    }

    return { state, step, runAll, result, norm, k };
  }

  return { create, FEATURES, LABELS, COLORS, DARK_COLORS };
})();
