// Loaded only on pages with a picture-card button (member share kit, product page for members).
import { $, $$ } from '../lib.js';

/* ---------- picture card for sharing (made on the phone, nothing uploaded) ---------- */
const loadImg = (src) => new Promise((ok, fail) => { const i = new Image(); i.onload = () => ok(i); i.onerror = fail; i.src = src; });
const wrap = (ctx, text, max) => {
    const words = String(text).split(/\s+/);
    const lines = [];
    let line = '';
    words.forEach((w) => { const t = line ? line + ' ' + w : w; if (ctx.measureText(t).width > max && line) { lines.push(line); line = w; } else line = t; });
    if (line) lines.push(line);
    return lines;
};
$$('[data-card]').forEach((b) => b.addEventListener('click', async () => {
    const d = b.dataset;
    const msg = $('[data-card-msg]', b.closest('[data-share-block]'));
    b.disabled = true;
    try {
        const W = 1080, H = 1350, c = document.createElement('canvas');
        c.width = W; c.height = H;
        const ctx = c.getContext('2d');
        const font = getComputedStyle(document.body).fontFamily || 'sans-serif';
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);
        try {
            const img = await loadImg(d.cardImage);
            const s = Math.max(W / img.width, 810 / img.height);
            ctx.drawImage(img, (W - img.width * s) / 2, (810 - img.height * s) / 2, img.width * s, img.height * s);
        } catch { ctx.fillStyle = '#e8f1eb'; ctx.fillRect(0, 0, W, 810); }
        ctx.fillStyle = '#13241a';
        ctx.font = `700 64px ${font}`;
        wrap(ctx, d.cardTitle, W - 120).slice(0, 2).forEach((l, i) => ctx.fillText(l, 60, 900 + i * 76));
        ctx.font = `500 44px ${font}`;
        ctx.fillStyle = '#1f8a4c';
        ctx.fillText(d.cardLine, 60, 1060);
        ctx.fillStyle = '#13241a';
        ctx.font = `600 40px ${font}`;
        ctx.fillText('My code:', 60, 1170);
        ctx.font = `800 72px ${font}`;
        ctx.fillText(d.cardCode, 60, 1250);
        if (d.cardQr) {
            try { const q = await loadImg(d.cardQr); ctx.drawImage(q, W - 330, H - 330, 270, 270); } catch { /* no QR */ }
        }
        const blob = await new Promise((ok) => c.toBlob(ok, 'image/png'));
        const file = new File([blob], `kulmis-${d.cardCode}.png`, { type: 'image/png' });
        if (navigator.canShare?.({ files: [file] })) {
            await navigator.share({ files: [file], text: d.cardText, url: d.cardLink }).catch(() => {});
        } else {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = file.name;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            if (msg) { msg.textContent = 'Saved. Send the picture with your link.'; }
        }
    } catch { if (msg) msg.textContent = 'Could not make the picture. Please try again.'; }
    b.disabled = false;
}));

