// Loaded only when the welcome tour is on the page (member home).
import { $, $$ } from '../lib.js';

/* ---------- member welcome tour: one slide at a time; Skip / Got it saves that it was seen ---------- */
const tour = $('[data-tour]');
{
    const slides = $$('[data-tour-slide]', tour);
    const dots = $$('[data-tour-dots] span', tour);
    const back = $('[data-tour-back]', tour);
    const next = $('[data-tour-next]', tour);
    let at = 0;
    const show = () => {
        slides.forEach((s, i) => { s.hidden = i !== at; });
        dots.forEach((d, i) => { d.style.opacity = i === at ? '1' : '.3'; });
        back.classList.toggle('hidden', at === 0);
        next.textContent = at === slides.length - 1 ? 'Got it' : 'Next';
        (slides[at].querySelector('h2') || slides[at]).focus?.();
    };
    const close = () => {
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
        fetch('/account/tour/seen', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } }).catch(() => {});
        tour.remove();
    };
    $$('[data-tour-form]', tour).forEach((f) => f.addEventListener('submit', (e) => {
        e.preventDefault();
        if (e.submitter === next && at < slides.length - 1) { at++; show(); return; }
        close();
    }));
    back.addEventListener('click', () => { at = Math.max(0, at - 1); show(); });
    tour.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    show();
}
