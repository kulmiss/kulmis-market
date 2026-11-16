// Small helpers shared by the storefront script and the parts loaded on demand.
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

export const say = (el, text, ok = false) => {
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('text-leaf-600', ok);
    el.classList.toggle('text-danger-600', !ok);
};

export const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* private mode */ } },
    del(k) { try { localStorage.removeItem(k); } catch { /* ignore */ } },
};
