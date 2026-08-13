// Generates three placeholder news thumbnails so the demo ships with no
// third-party photography. Pure node-canvas, run once via `npm run thumbs`.
const fs = require('fs');
const path = require('path');
const { createCanvas } = require('@napi-rs/canvas');

const OUT = path.join(__dirname, '..', 'public', 'assets');

const ARTICLES = [
  {
    file: 'article-1.jpg',
    tint: ['#123a5e', '#2b7fb8'],
    kicker: 'PARLIAMENT',
    caption: 'MP holds up the letter at the media briefing',
  },
  {
    file: 'article-2.jpg',
    tint: ['#4a2410', '#c07a33'],
    kicker: 'ECONOMY',
    caption: 'Container terminal operations at the main port',
  },
  {
    file: 'article-3.jpg',
    tint: ['#123d2c', '#3f9c6d'],
    kicker: 'ENVIRONMENT',
    caption: 'Reservoir levels in the central highlands',
  },
];

// Deterministic pseudo-random so regenerating gives identical files.
function mulberry32(seed) {
  return function () {
    seed |= 0;
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function draw({ file, tint, kicker, caption }, index) {
  const W = 1200;
  const H = 800;
  const canvas = createCanvas(W, H);
  const ctx = canvas.getContext('2d');
  const rand = mulberry32(index * 7919 + 13);

  const bg = ctx.createLinearGradient(0, 0, W, H);
  bg.addColorStop(0, tint[0]);
  bg.addColorStop(1, tint[1]);
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, W, H);

  // Soft abstract shapes, enough to read as a photograph at thumbnail size.
  for (let i = 0; i < 26; i++) {
    ctx.beginPath();
    ctx.globalAlpha = 0.05 + rand() * 0.12;
    ctx.fillStyle = i % 3 === 0 ? '#ffffff' : '#000000';
    ctx.ellipse(
      rand() * W,
      rand() * H,
      60 + rand() * 320,
      40 + rand() * 220,
      rand() * Math.PI,
      0,
      Math.PI * 2
    );
    ctx.fill();
  }
  ctx.globalAlpha = 1;

  // Bottom scrim so the caption stays legible.
  const scrim = ctx.createLinearGradient(0, H - 240, 0, H);
  scrim.addColorStop(0, 'rgba(0,0,0,0)');
  scrim.addColorStop(1, 'rgba(0,0,0,0.72)');
  ctx.fillStyle = scrim;
  ctx.fillRect(0, H - 240, W, 240);

  ctx.fillStyle = '#ffd24a';
  ctx.font = 'bold 30px sans-serif';
  ctx.letterSpacing = '4px';
  ctx.fillText(kicker, 56, H - 132);

  ctx.fillStyle = '#ffffff';
  ctx.font = '34px sans-serif';
  ctx.letterSpacing = '0px';
  ctx.fillText(caption, 56, H - 76);

  fs.writeFileSync(path.join(OUT, file), canvas.toBuffer('image/jpeg', 88));
  console.log('wrote', file);
}

fs.mkdirSync(OUT, { recursive: true });
ARTICLES.forEach(draw);
