// Headless check that the compositing math in traceit-qr-thumbnail.js produces
// a sane image: same dimensions as the source photo, QR present in the chosen
// corner. Mirrors the browser code path using the same canvas API.
const fs = require('fs');
const path = require('path');
const { createCanvas, loadImage } = require('@napi-rs/canvas');

const PUB = path.join(__dirname, '..', 'public');
const OUT = path.join(__dirname, '..', 'verify-output');

function roundedRect(ctx, x, y, w, h, r) {
  r = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

async function run() {
  fs.mkdirSync(OUT, { recursive: true });
  const qr = await loadImage(path.join(PUB, 'assets', 'sample-qr.png'));

  for (const file of ['article-1.jpg', 'article-2.jpg', 'article-3.jpg']) {
    const photo = await loadImage(path.join(PUB, 'assets', file));
    const W = photo.width;
    const H = photo.height;
    const shortSide = Math.min(W, H);

    const canvas = createCanvas(W, H);
    const ctx = canvas.getContext('2d');
    ctx.drawImage(photo, 0, 0, W, H);

    let qrW = Math.round(shortSide * 0.28);
    qrW = Math.max(120, Math.min(420, qrW));
    qrW = Math.min(qrW, Math.round(W * 0.45));
    const qrH = Math.round(qrW * (qr.height / qr.width));

    const pad = Math.round(shortSide * 0.035);
    const platePad = Math.round(qrW * 0.07);
    const plateW = qrW + platePad * 2;
    const plateH = qrH + platePad * 2;
    const px = W - plateW - pad;
    const py = H - plateH - pad;

    ctx.save();
    ctx.globalAlpha = 0.96;
    ctx.fillStyle = '#ffffff';
    ctx.shadowColor = 'rgba(0,0,0,0.30)';
    ctx.shadowBlur = Math.round(qrW * 0.1);
    ctx.shadowOffsetY = Math.round(qrW * 0.02);
    roundedRect(ctx, px, py, plateW, plateH, Math.round(qrW * 0.06));
    ctx.fill();
    ctx.restore();

    ctx.drawImage(qr, px + platePad, py + platePad, qrW, qrH);

    const buf = canvas.toBuffer('image/png');
    const outFile = path.join(OUT, file.replace(/\.jpg$/, '-qr.png'));
    fs.writeFileSync(outFile, buf);

    const fits = px >= 0 && py >= 0 && px + plateW <= W && py + plateH <= H;
    console.log(
      `${file}: source ${W}x${H} -> composite ${canvas.width}x${canvas.height}, ` +
      `qr ${qrW}x${qrH} at (${px},${py}), in-bounds=${fits}, ${(buf.length / 1024).toFixed(0)}KB`
    );
    if (!fits) throw new Error('QR placement out of bounds for ' + file);
    if (canvas.width !== W || canvas.height !== H) throw new Error('dimension drift');
  }
  console.log('\nAll composites OK ->', OUT);
}

run().catch((e) => { console.error(e); process.exit(1); });
