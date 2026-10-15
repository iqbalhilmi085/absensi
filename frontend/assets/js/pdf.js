const PdfExport = (() => {
  function pad(n) { return String(n).padStart(2, '0'); }
  function stamp() {
    const d = new Date();
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
  }
  function nowLabel() {
    const d = new Date();
    const bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return `${d.getDate()} ${bln[d.getMonth()]} ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  /**
   * Ekspor dataset absensi (Axel) ke PDF sesuai filter aktif.
   * @param {Object} o { orgName, periode, filterLines:[], columns:[], rows:[[]], summaryLines:[], title }
   */
  function dataset(o) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const pageW = doc.internal.pageSize.getWidth();
    const margin = 36;

    // Kepala
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text(o.title || 'Dataset Absensi', margin, 44);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.setTextColor(90);
    doc.text(o.orgName || '', margin, 62);

    let y = 78;
    (o.filterLines || []).forEach((line) => {
      doc.text(line, margin, y);
      y += 14;
    });
    doc.text(`Dicetak: ${nowLabel()}`, margin, y);
    y += 6;

    doc.setDrawColor(210);
    doc.line(margin, y + 4, pageW - margin, y + 4);

    // Tabel
    doc.autoTable({
      head: [o.columns],
      body: o.rows,
      startY: y + 14,
      margin: { left: margin, right: margin },
      styles: { font: 'helvetica', fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
      headStyles: { fillColor: [79, 124, 255], textColor: 255, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [244, 247, 255] },
      theme: 'grid'
    });

    // Ringkasan
    let ry = doc.lastAutoTable.finalY + 18;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(40);
    doc.text('Ringkasan', margin, ry);
    ry += 15;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(70);
    (o.summaryLines || []).forEach((line) => {
      doc.text(line, margin, ry);
      ry += 13;
    });

    ry += 6;
    doc.setFontSize(8);
    doc.setTextColor(130);
    const note = 'Dokumen ini dihasilkan otomatis dari Absensi Universal. Data bersifat internal organisasi.';
    const note2 = 'Semakin tinggi angka, semakin perlu perhatian. ' + note;
    doc.text(note2, margin, ry);

    // Nomor halaman
    const pages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= pages; i++) {
      doc.setPage(i);
      doc.setFontSize(8);
      doc.setTextColor(150);
      doc.text(`Halaman ${i} / ${pages}`, pageW - margin, doc.internal.pageSize.getHeight() - 20, { align: 'right' });
    }

    doc.save(`axel-dataset-${stamp()}.pdf`);
  }

  return { dataset };
})();
