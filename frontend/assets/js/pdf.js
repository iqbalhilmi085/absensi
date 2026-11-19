const PdfExport = (() => {
  function pad(n) { return String(n).padStart(2, '0'); }
  function stamp() {
    const d = new Date();
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
  }
  function nowLabel() {
    const d = new Date();
    const bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return `${d.getDate()} ${bln[d.getMonth()]} ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())} WIB`;
  }

  /**
   * Ekspor laporan data kehadiran ke PDF sesuai filter aktif.
   * @param {Object} o { orgName, periode, filterLines:[], columns:[], rows:[[]], summaryLines:[], title }
   */
  function dataset(o) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const pageW = doc.internal.pageSize.getWidth();
    const margin = 36;

    // Header Dokumen Korporat
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(15);
    doc.setTextColor(15, 23, 42);
    doc.text(o.title || 'Laporan Data Kehadiran Karyawan', margin, 42);

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor(30, 64, 175);
    doc.text(o.orgName || 'Organisasi', margin, 58);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(71, 85, 105);
    let y = 74;
    (o.filterLines || []).forEach((line) => {
      doc.text(line, margin, y);
      y += 13;
    });
    doc.text(`Tanggal Cetak: ${nowLabel()}`, pageW - margin, 42, { align: 'right' });

    doc.setDrawColor(203, 213, 225);
    doc.setLineWidth(0.75);
    doc.line(margin, y + 2, pageW - margin, y + 2);

    // Tabel
    doc.autoTable({
      head: [o.columns],
      body: o.rows,
      startY: y + 12,
      margin: { left: margin, right: margin },
      styles: { font: 'helvetica', fontSize: 8, cellPadding: 5, overflow: 'linebreak', textColor: [30, 41, 59], lineColor: [226, 232, 240] },
      headStyles: { fillColor: [15, 23, 42], textColor: 255, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [248, 250, 252] },
      theme: 'grid'
    });

    // Ringkasan
    let ry = doc.lastAutoTable.finalY + 18;
    if (ry > doc.internal.pageSize.getHeight() - 70) {
      doc.addPage();
      ry = 44;
    }
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.setTextColor(15, 23, 42);
    doc.text('Ringkasan Statistik Kehadiran', margin, ry);
    ry += 14;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(51, 65, 85);
    (o.summaryLines || []).forEach((line) => {
      doc.text(line, margin, ry);
      ry += 13;
    });

    ry += 6;
    doc.setFontSize(8);
    doc.setTextColor(100, 116, 139);
    doc.text('Dokumen resmi sistem manajemen kehadiran internal perusahaan.', margin, ry);

    // Nomor halaman
    const pages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pages; i++) {
      doc.setPage(i);
      doc.setFontSize(8);
      doc.setTextColor(100, 116, 139);
      doc.text(`Halaman ${i} dari ${pages}`, pageW - margin, doc.internal.pageSize.getHeight() - 18, { align: 'right' });
    }

    doc.save(`laporan-kehadiran-${stamp()}.pdf`);
  }

  return { dataset };
})();
