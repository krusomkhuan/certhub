/* QR encoding stays in the browser; no third-party requests. */
(() => {
  const link = document.getElementById('certificate-qr');
  if (!link) return;
  try {
    const code = qrcode(0, 'M');
    code.addData(link.dataset.url);
    code.make();
    link.querySelector('.qr-image').innerHTML = code.createSvgTag({cellSize: 3, margin: 12, scalable: true});
  } catch (error) {
    link.querySelector('.qr-image').textContent = 'ไม่สามารถสร้าง QR ได้';
  }
})();
