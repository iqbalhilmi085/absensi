const KMeans = (() => {
  const FEATURES = ['rata_terlambat', 'persen_tidak_hadir', 'rata_jam_kerja'];
  const LABELS = {
    2: ['Disiplin (Rajin)', 'Perlu Diingatkan'],
    3: ['Disiplin (Rajin)', 'Cukup Baik', 'Perlu Diingatkan'],
    4: ['Sangat Disiplin', 'Disiplin Baik', 'Cukup Baik', 'Perlu Diingatkan']
  };
  const REKOMENDASI = {
    2: ['Kehadiran sangat baik, pertahankan', 'Perlu diingatkan agar lebih tepat waktu'],
    3: ['Rajin dan tepat waktu, pertahankan', 'Kehadiran cukup baik, sesekali terlambat', 'Perlu diingatkan karena sering terlambat'],
    4: ['Sangat rajin dan selalu tepat waktu', 'Kehadiran baik, pertahankan', 'Cukup baik, sesekali terlambat', 'Perlu diingatkan karena sering terlambat']
  };
  const COLORS = {
    2: ['#15803d', '#dc2626'],
    3: ['#15803d', '#d97706', '#dc2626'],
    4: ['#15803d', '#2563eb', '#d97706', '#dc2626']
  };
  const DARK_COLORS = {
    2: ['#4ade80', '#f87171'],
    3: ['#4ade80', '#fbbf24', '#f87171'],
    4: ['#4ade80', '#60a5fa', '#fbbf24', '#f87171']
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

  function initCentroidsPlusPlus(points, k, rng) {
    const n = points.length;
    if (n === 0) return [];
    const chosen = [];
    const firstIdx = Math.floor(rng() * n);
    chosen.push(points[firstIdx].slice());

    while (chosen.length < Math.min(k, n)) {
      const dists = points.map((p) => {
        let minD = Infinity;
        chosen.forEach((c) => {
          const d = distance(p, c);
          if (d < minD) minD = d;
        });
        return minD * minD;
      });
      const sumD = dists.reduce((a, b) => a + b, 0);
      if (sumD <= 1e-12) {
        const nextIdx = chosen.length % n;
        chosen.push(points[nextIdx].slice());
        continue;
      }
      let r = rng() * sumD;
      let picked = n - 1;
      for (let i = 0; i < n; i++) {
        r -= dists[i];
        if (r <= 0) { picked = i; break; }
      }
      chosen.push(points[picked].slice());
    }
    return chosen;
  }

  function create(data, k, seed) {
    const norm = normalize(data);
    const points = norm.points.map(vec);
    const n = points.length;
    const rng = mulberry32(seed || 12345);

    let centroids = initCentroidsPlusPlus(points, k, rng);

    const state = {
      points,
      centroids,
      initialCentroids: centroids.map((c) => c.slice()),
      iteration: 0,
      sse: 0,
      done: false,
      labels: new Array(n).fill(0),
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

    state.sse = assign();

    function update() {
      const groups = centroids.map(() => []);
      points.forEach((p, i) => groups[state.labels[i]].push(p));
      let shift = 0;
      centroids = centroids.map((c, ci) => {
        const m = meanOf(groups[ci]);
        if (!m) return c;
        const d = distance(c, m);
        shift = Math.max(shift, d);
        return m;
      });
      state.centroids = centroids;
      return shift;
    }

    function step() {
      if (state.done) return state;
      const shift = update();
      state.sse = assign();
      state.iteration++;
      state.shift = shift;
      state.history.push({
        iteration: state.iteration,
        sse: Number(state.sse.toFixed(4)),
        shift: Number(shift.toFixed(6)),
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

    function rankedClusters() {
      const groups = centroids.map((_, ci) =>
        data.map((r, i) => ({ row: r, idx: i, normVec: points[i] })).filter((x) => state.labels[x.idx] === ci)
      );
      return centroids.map((c, ci) => ({
        ci,
        centroid: c,
        score: (c[0] * 0.45) + (c[1] * 0.35) + (c[2] * 0.20),
        members: groups[ci]
      })).sort((a, b) => a.score - b.score);
    }

    function result() {
      const ranked = rankedClusters();
      const labels = LABELS[k] || LABELS[3];
      const rekoms = REKOMENDASI[k] || REKOMENDASI[3];
      const colors = COLORS[k] || COLORS[3];
      const darkColors = DARK_COLORS[k] || DARK_COLORS[3];

      return ranked.map((r, rank) => {
        const members = r.members;
        const avg = (f) => members.length
          ? Number((members.reduce((a, m) => a + (Number(m.row[f]) || 0), 0) / members.length).toFixed(2))
          : 0;
        return {
          cluster: r.ci,
          rank,
          code: `K${rank + 1}`,
          label: labels[rank] || `Kategori ${rank + 1}`,
          rekomendasi: rekoms[rank] || '',
          color: colors[rank],
          darkColor: darkColors[rank],
          jumlah: members.length,
          rata_terlambat: avg('rata_terlambat'),
          persen_tidak_hadir: avg('persen_tidak_hadir'),
          rata_jam_kerja: avg('rata_jam_kerja'),
          centroid: r.centroid.map((v) => Number(v.toFixed(4))),
          anggota: members.map((m) => m.row.nama)
        };
      });
    }

    function memberDetails() {
      const ranked = rankedClusters();
      const labels = LABELS[k] || LABELS[3];
      const rekoms = REKOMENDASI[k] || REKOMENDASI[3];
      const colors = COLORS[k] || COLORS[3];
      const ciToRank = {};
      ranked.forEach((r, rank) => { ciToRank[r.ci] = rank; });

      return data.map((row, i) => {
        const p = points[i];
        const assignedCi = state.labels[i];
        const rank = ciToRank[assignedCi] ?? 0;
        const distsByRank = ranked.map((r) => Number(distance(p, r.centroid).toFixed(4)));
        const minDist = distsByRank[rank] ?? 0;
        const skorDisiplin = Math.max(0, Math.min(100, Math.round((1 - ((p[0] * 0.45) + (p[1] * 0.35) + (p[2] * 0.20))) * 100)));

        return {
          id: row.id,
          nama: row.nama,
          tim: row.tim,
          hari_tercatat: row.hari_tercatat,
          hari_tidak_hadir: row.hari_tidak_hadir,
          total_hari: row.total_hari,
          rata_terlambat: row.rata_terlambat,
          persen_tidak_hadir: row.persen_tidak_hadir,
          rata_jam_kerja: row.rata_jam_kerja,
          norm_terlambat: Number(p[0].toFixed(4)),
          norm_tidak_hadir: Number(p[1].toFixed(4)),
          norm_jam_kerja: Number(p[2].toFixed(4)),
          distances: distsByRank,
          jarak_centroid: minDist,
          skor_disiplin: skorDisiplin,
          clusterRank: rank,
          clusterCode: `K${rank + 1}`,
          clusterLabel: labels[rank] || `Kategori ${rank + 1}`,
          clusterColor: colors[rank],
          rekomendasi: rekoms[rank] || ''
        };
      }).sort((a, b) => a.clusterRank - b.clusterRank || b.skor_disiplin - a.skor_disiplin);
    }

    return { state, step, runAll, result, memberDetails, norm, k };
  }

  return { create, FEATURES, LABELS, COLORS, DARK_COLORS };
})();
