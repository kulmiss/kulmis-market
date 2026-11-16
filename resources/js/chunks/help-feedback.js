// Loaded only on help articles.
import { $, $$ } from '../lib.js';

/* ---------- help article: "Was this helpful?" (one answer per article and browser) ---------- */
const helpful = $('[data-help-feedback]');
{
    const slug = helpful.dataset.helpFeedback;
    const msg = $('[data-helpful-msg]', helpful);
    const key = 'km-helpful-' + slug;
    let done = null;
    try { done = localStorage.getItem(key); } catch { /* private mode */ }
    if (done) { $$('[data-helpful]', helpful).forEach((b) => { b.disabled = true; }); msg.textContent = 'Thank you!'; }
    $$('[data-helpful]', helpful).forEach((b) => b.addEventListener('click', async () => {
        $$('[data-helpful]', helpful).forEach((x) => { x.disabled = true; });
        try {
            const res = await fetch(`/api/v1/help/${slug}/feedback`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ helpful: b.dataset.helpful === '1' }) });
            const r = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(r.message || 'Something went wrong. Please try again.');
            msg.textContent = r.message || 'Thank you!';
            try { localStorage.setItem(key, '1'); } catch { /* private mode */ }
        } catch (e) {
            msg.textContent = e.message;
            $$('[data-helpful]', helpful).forEach((x) => { x.disabled = false; });
        }
    }));
}
