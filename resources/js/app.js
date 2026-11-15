// Kulmis Market storefront — small vanilla JS (no framework, no trackers).
// Talks to the REST API with the essential cart cookie only.

const API = '/api/v1';
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
// Shop currency format from Settings → General (same rules as App\Support\Currency).
const CUR = (() => {
    try { return JSON.parse(document.querySelector('meta[name=km-currency]')?.content || '{}'); } catch { return {}; }
})();
const etb = (santim) => {
    const minor = Math.round(santim ?? 0);
    const abs = Math.abs(minor);
    const dec = CUR.decimals === '0' ? 0 : CUR.decimals === '2' ? 2 : (abs % 100 ? 2 : 0);
    const fixed = (abs / 100).toFixed(dec);
    const [whole, frac] = fixed.split('.');
    const sep = CUR.thousands_char ?? ',';
    const num = whole.replace(/\B(?=(\d{3})+(?!\d))/g, sep) + (frac ? (CUR.decimal_point ?? '.') + frac : '');
    const sym = CUR.symbol ?? 'ETB';
    const out = { after: num + sym, before_space: sym + ' ' + num, before: sym + num }[CUR.position] ?? num + ' ' + sym;
    return (minor < 0 ? '-' : '') + out;
};
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

async function api(path, { method = 'GET', body, form } = {}) {
    const res = await fetch(API + path, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        body: form ?? (body ? JSON.stringify(body) : undefined),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data.message || 'Something went wrong. Please try again.');
    }
    return data;
}

const say = (el, text, ok = false) => {
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('text-leaf-600', ok);
    el.classList.toggle('text-danger-600', !ok);
};

const formData = (form) => Object.fromEntries(new FormData(form).entries());

/** Runs fn with the form's buttons disabled, so a double click sends it once. */
const busy = async (form, fn) => {
    if (form.dataset.busy) return;
    form.dataset.busy = '1';
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach((b) => { b.disabled = true; });
    try { await fn(); } finally { delete form.dataset.busy; buttons.forEach((b) => { b.disabled = false; }); }
};

/* Plain (non-JS) forms: block a second submit while the page is loading. */
$$('form[method="post"]').forEach((f) => f.addEventListener('submit', (e) => {
    if (f.dataset.sent) { e.preventDefault(); return; }
    f.dataset.sent = '1';
    setTimeout(() => {
        // A JS handler took over (fetch): it manages its own buttons.
        if (e.defaultPrevented) { delete f.dataset.sent; return; }
        f.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    }, 0);
    // Back-button / bfcache: let the form be used again.
    window.addEventListener('pageshow', () => { delete f.dataset.sent; f.querySelectorAll('button').forEach((b) => { b.disabled = false; }); }, { once: true });
}));

/* ---------- share (phones with a share sheet) ---------- */
$$('[data-share]').forEach((b) => {
    if (!navigator.share) return;
    b.classList.remove('hidden');
    b.addEventListener('click', () => navigator.share({ url: b.dataset.shareUrl, text: b.dataset.shareText }).catch(() => {}));
});

/* ---------- theme ---------- */
$$('[data-theme-toggle]').forEach((b) =>
    b.addEventListener('click', () => {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('km-theme', dark ? 'dark' : 'light'); } catch { /* private mode */ }
    }),
);

/* ---------- cart count in the header ---------- */
async function refreshCount() {
    const badge = $('[data-cart-count]');
    if (!badge) return;
    try {
        const cart = await api('/cart');
        const n = cart.items.reduce((s, i) => s + i.quantity, 0);
        badge.textContent = String(n);
        badge.classList.toggle('hidden', n === 0);
    } catch { /* ignore */ }
}
refreshCount();

/* ---------- product page ---------- */
$$('[data-add-to-cart]').forEach((form) =>
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = $('[data-cart-message]', form);
        const d = formData(form);
        try {
            await api('/cart/items', { method: 'POST', body: { product_id: Number(form.dataset.product), variant_id: d.variant_id ? Number(d.variant_id) : null, quantity: Number(d.quantity || 1) } });
            msg.innerHTML = 'Added to your cart. <a class="underline" href="/cart">View cart</a>';
            msg.className = 'text-sm text-leaf-600';
            refreshCount();
        } catch (err) { say(msg, err.message); }
    }),
);

const zoom = $('[data-zoom]');
if (zoom) {
    const img = $('img', zoom);
    zoom.addEventListener('mousemove', (e) => {
        const r = zoom.getBoundingClientRect();
        img.style.transformOrigin = `${((e.clientX - r.left) / r.width) * 100}% ${((e.clientY - r.top) / r.height) * 100}%`;
        img.style.transform = 'scale(1.8)';
    });
    zoom.addEventListener('mouseleave', () => { img.style.transform = ''; });
    zoom.addEventListener('click', () => window.open(img.currentSrc || img.src, '_blank', 'noopener'));
    $$('[data-thumb]').forEach((t) => t.addEventListener('click', () => {
        $$('source', zoom).forEach((s) => s.remove());
        img.src = t.dataset.thumb;
        img.removeAttribute('srcset');
    }));
}

/* ---------- cart page ---------- */
function totalsHtml(q) {
    const row = (l, v, cls = '') => `<div class="flex justify-between ${cls}"><span>${l}</span><span>${v}</span></div>`;
    return [
        row('Subtotal', etb(q.list_subtotal)),
        q.member_discount ? row('Member discount', '−' + etb(q.member_discount), 'text-brand-600') : '',
        q.coupon_discount ? row(`Coupon ${esc(q.coupon)}`, '−' + etb(q.coupon_discount), 'text-brand-600') : '',
        q.shipping ? row('Shipping', q.shipping_discount ? 'Free' : etb(q.shipping_payable)) : row('Shipping', 'at checkout'),
        row('Total', etb(q.total), 'mt-1 text-base font-semibold'),
    ].join('');
}

async function renderCart() {
    const body = $('[data-cart-body]');
    if (!body) return;
    let cart;
    try { cart = await api('/cart'); } catch (e) { body.innerHTML = `<p class="text-danger-600">${esc(e.message)}</p>`; return; }
    if (!cart.items.length) { body.innerHTML = '<p class="muted">Your cart is empty. <a class="underline" href="/shop">Visit the shop</a></p>'; return; }
    body.innerHTML = $('#cart-tpl').innerHTML;
    const lines = $('[data-lines]', body);
    const q = cart.quote;
    lines.innerHTML = cart.items.map((i, idx) => {
        const l = q?.lines[idx];
        return `<div class="card flex items-center gap-4 p-3">
            ${i.image ? `<img src="${esc(i.image)}" alt="" width="64" height="64" class="size-16 rounded-xl object-cover">` : '<div class="size-16 rounded-xl bg-mist-200"></div>'}
            <div class="flex-1"><a class="font-medium hover:underline" href="/shop/${esc(i.slug)}">${esc(i.name)}</a>
              ${l ? `<div class="muted text-sm">${etb(l.unit_price)} each${!cart.is_member && l.member_unit_price < l.unit_price ? ` · <span class="text-brand-600">member price ${etb(l.member_unit_price)}</span>` : ''}</div>` : ''}</div>
            <input type="number" min="0" max="99" value="${i.quantity}" class="input w-20" data-qty="${i.id}" aria-label="Quantity">
        </div>`;
    }).join('');
    if (cart.problem) lines.insertAdjacentHTML('beforeend', `<p class="text-sm text-danger-600">${esc(cart.problem)}</p>`);
    if (q) $('[data-totals]', body).innerHTML = totalsHtml(q);
    if (q && q.member_savings_preview > 0) {
        const js = $('[data-join-save]', body);
        js.classList.remove('hidden');
        js.innerHTML = `<b>Join &amp; save ${etb(q.member_savings_preview)}</b> on this order as a member — plus free signals and earnings. <a class="underline" href="/membership">See membership</a>`;
    }
    $$('[data-qty]', body).forEach((inp) => inp.addEventListener('change', async () => {
        await api(`/cart/items/${inp.dataset.qty}`, { method: 'PATCH', body: { quantity: Number(inp.value) } }).catch((e) => alert(e.message));
        renderCart(); refreshCount();
    }));
    const cf = $('[data-coupon-form]', body);
    if (cart.coupon_code) cf.code.value = cart.coupon_code;
    cf.addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await api('/cart/coupon', { method: 'PUT', body: { code: cf.code.value || null } }); renderCart(); } catch (err) { alert(err.message); }
    });
}
renderCart();
window.addEventListener('pageshow', (e) => { if (e.persisted) renderCart(); });

/* ---------- checkout ---------- */
const checkoutForm = $('[data-checkout-form]');
if (checkoutForm) {
    const summary = $('[data-checkout-summary]');
    const zonesSel = $('[data-zones]');
    const refreshSummary = async () => {
        const params = new URLSearchParams();
        if (zonesSel?.value) params.set('shipping_zone_id', zonesSel.value);
        if (checkoutForm.city.value) params.set('city', checkoutForm.city.value);
        try {
            const cart = await api('/cart?' + params);
            if (!cart.items.length) { summary.innerHTML = '<p>Your cart is empty. <a class="underline" href="/shop">Shop</a></p>'; return; }
            summary.innerHTML = cart.items.map((i) => `<div class="flex justify-between"><span>${i.quantity} × ${esc(i.name)}</span></div>`).join('')
                + '<hr class="my-2 border-mist-200">' + (cart.quote ? totalsHtml(cart.quote) : `<p class="text-danger-600">${esc(cart.problem)}</p>`);
        } catch (e) { summary.textContent = e.message; }
    };
    // Back button after placing an order: the page comes from the browser's memory, so show the real (now empty) cart.
    window.addEventListener('pageshow', (e) => { if (e.persisted) refreshSummary(); });
    api('/shipping/zones').then(({ data }) => {
        zonesSel.insertAdjacentHTML('beforeend', data.map((z) => `<option value="${z.id}">${esc(z.name)} — from ${etb(z.base_fee)}</option>`).join(''));
        $('[data-cities]').innerHTML = [...new Set(data.flatMap((z) => z.cities))].map((c) => `<option value="${esc(c)}">`).join('');
    }).catch(() => {});
    zonesSel?.addEventListener('change', refreshSummary);
    checkoutForm.city.addEventListener('change', refreshSummary);
    refreshSummary();

    if (checkoutForm.hasAttribute('data-guest-checkout')) {
        checkoutForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = $('button', checkoutForm);
            btn.disabled = true;
            const d = formData(checkoutForm);
            if (!d.shipping_zone_id) delete d.shipping_zone_id;
            try {
                const { data } = await api('/checkout', { method: 'POST', body: d });
                location.href = data.status === 'pending_payment'
                    ? `/pay/${encodeURIComponent(data.number)}?phone=${encodeURIComponent(d.phone)}`
                    : (data.track_url || '/');
            } catch (err) { say($('[data-form-message]', checkoutForm), err.message); btn.disabled = false; }
        });
    }
}

/* ---------- pay page ---------- */
const payPage = $('[data-pay-page]');
if (payPage) {
    const { number, phone } = payPage.dataset;
    const msg = $('[data-form-message]', payPage);
    $('[data-proof-form]', payPage)?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = new FormData(e.target);
        form.append('phone', phone);
        await busy(e.target, async () => {
            try {
                const { data } = await api(`/orders/${encodeURIComponent(number)}/payment-proof`, { method: 'POST', form });
                say(msg, data.message, true);
                e.target.hidden = true; // one receipt is enough: no second click
            } catch (err) { say(msg, err.message); }
        });
    });
}

/* ---------- tracking ---------- */
const trackForm = $('[data-track-form]');
if (trackForm) {
    const out = $('[data-track-result]');
    const params = new URLSearchParams(location.search);
    if (params.get('number')) trackForm.number.value = params.get('number');
    trackForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const { data: o } = await api('/orders/track', { method: 'POST', body: formData(trackForm) });
            const steps = ['pending_payment', 'paid', 'making', 'shipped', 'delivered'];
            out.innerHTML = `<div class="card">
                <div class="flex flex-wrap justify-between gap-2"><b>${esc(o.number)}</b><span class="badge capitalize">${esc(o.status.replaceAll('_', ' '))}</span></div>
                <ol class="mt-4 grid grid-cols-5 gap-1 text-center text-xs">${steps.map((s) => {
                    const done = o.timeline.some((t) => t.status === s);
                    return `<li class="rounded-lg px-1 py-2 ${done ? 'bg-brand-500 text-white' : 'bg-mist-100 dark:bg-ink-900'}">${s.replace('_', ' ')}</li>`;
                }).join('')}</ol>
                ${o.tracking_number ? `<p class="mt-3 text-sm">Courier tracking: <b>${esc(o.tracking_number)}</b></p>` : ''}
                <ul class="mt-4 grid gap-1 text-sm">${o.items.map((i) => `<li>${i.quantity} × ${esc(i.name)}${i.made_to_order ? ` <span class="muted">(made to order, ~${i.making_days} days)</span>` : ''}</li>`).join('')}</ul>
                <p class="mt-3 font-semibold">Total ${etb(o.total)}</p>
                ${o.receipt_under_review ? '<p class="mt-4 rounded-xl bg-mist-100 p-3 text-sm dark:bg-ink-900">We have your receipt and are checking it. No need to pay again.</p>'
                    : o.amount_due > 0 ? `<a class="btn-primary mt-4" href="/pay/${encodeURIComponent(o.number)}?phone=${encodeURIComponent(trackForm.phone.value)}">Pay ${etb(o.amount_due)}</a>` : ''}
            </div>`;
        } catch (err) { out.innerHTML = `<p class="text-danger-600">${esc(err.message)}</p>`; }
    });
    if (trackForm.number.value && trackForm.phone.value) trackForm.requestSubmit();
}

/* ---------- signals (guests) ---------- */
const signalsForm = $('[data-signals-form]');
if (signalsForm) {
    signalsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        await busy(signalsForm, async () => {
            try {
                const { data } = await api('/signals/payment-proof', { method: 'POST', form: new FormData(signalsForm) });
                say($('[data-form-message]', signalsForm), data.message || 'Thank you! We check your receipt and send your Telegram link by SMS.', true);
                signalsForm.reset();
            } catch (err) { say($('[data-form-message]', signalsForm), err.message); }
        });
    });
}

/* ---------- password reset ---------- */
const forgot = $('[data-forgot-page]');
if (forgot) {
    const msg = $('[data-form-message]', forgot);
    $('[data-forgot-form]', forgot).addEventListener('submit', async (e) => {
        e.preventDefault();
        try { const d = await api('/auth/forgot', { method: 'POST', body: formData(e.target) }); say(msg, d.message, true); $('[data-reset-form]').phone.value = e.target.phone.value; }
        catch (err) { say(msg, err.message); }
    });
    $('[data-reset-form]', forgot).addEventListener('submit', async (e) => {
        e.preventDefault();
        try { await api('/auth/reset', { method: 'POST', body: formData(e.target) }); say(msg, 'Done! You can sign in with your new password.', true); }
        catch (err) { say(msg, err.message); }
    });
}

/* ---------- auto-submit selects (no inline handlers: strict CSP) ---------- */
$$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form?.submit()));

/* ---------- copy buttons ---------- */
$$('[data-copy]').forEach((b) => b.addEventListener('click', () => {
    const src = $('[data-copy-source]', b.parentElement);
    navigator.clipboard?.writeText(src.value).then(() => { b.textContent = 'Copied!'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); });
}));

/* ---------- instant navigation fallback (browsers without Speculation Rules) ---------- */
const NO_PREFETCH = /^\/(account|admin|api|cart|checkout|pay|login|register|logout|r)(\/|$)/;
const html = document.documentElement;
if (html.dataset.instant !== 'off' && !(HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules'))) {
    const done = new Set();
    const warm = (e) => {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.origin !== location.origin || a.search || a.hasAttribute('data-no-prefetch') || NO_PREFETCH.test(a.pathname) || done.has(a.pathname)) return;
        done.add(a.pathname);
        const l = document.createElement('link');
        l.rel = 'prefetch'; l.href = a.pathname;
        document.head.appendChild(l);
    };
    document.addEventListener('pointerover', warm, { passive: true });
    document.addEventListener('touchstart', warm, { passive: true });
}

/* ---------- service worker: fast repeat visits + offline page ---------- */
if ('serviceWorker' in navigator && html.dataset.offline === 'off') {
    // Switched off by the Super Admin: remove the saved pages and the worker.
    navigator.serviceWorker.getRegistrations().then((regs) => regs.forEach((r) => r.unregister())).catch(() => {});
    if (window.caches) caches.keys().then((keys) => keys.forEach((k) => caches.delete(k))).catch(() => {});
} else if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    // Signing in or out forgets saved pages.
    $$('form[action$="/logout"], form[action$="/login"]').forEach((f) => f.addEventListener('submit', () => {
        navigator.serviceWorker.controller?.postMessage('km-clear-pages');
    }));
}

/* ---------- member tables: saved view, columns, selection, CSV ---------- */
const store = {
    get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* private mode */ } },
    del(k) { try { localStorage.removeItem(k); } catch { /* ignore */ } },
};
$$('[data-mtable]').forEach((root) => {
    const key = `km.mtable.${root.dataset.mtable}`;
    const params = root.dataset.mtableKeys.split(',');
    const url = new URL(location.href);
    const saved = store.get(key) || {};
    // Saved sort / page size / filters come back when you open the page without any.
    if (saved.query && !params.some((p) => url.searchParams.has(p)) && !url.searchParams.has(root.dataset.mtablePage)) {
        const q = Object.entries(saved.query).filter(([, v]) => v);
        if (q.length) {
            q.forEach(([k, v]) => url.searchParams.set(k, v));
            location.replace(url);
            return;
        }
    }
    const remember = () => {
        const now = new URL(location.href);
        const query = {};
        params.filter((p) => !p.endsWith('q')).forEach((p) => { if (now.searchParams.get(p)) query[p] = now.searchParams.get(p); });
        store.set(key, { ...store.get(key), query });
    };
    remember();

    // Show / hide columns (kept in this browser).
    const hidden = new Set(saved.hidden || []);
    const apply = () => $$('[data-col]', root).forEach((el) => el.classList.toggle('hidden', hidden.has(el.dataset.col)));
    $$('[data-col-toggle]', root).forEach((box) => {
        if (saved.hidden) box.checked = !hidden.has(box.dataset.colToggle);
        else if (!box.checked) hidden.add(box.dataset.colToggle);
        box.addEventListener('change', () => {
            box.checked ? hidden.delete(box.dataset.colToggle) : hidden.add(box.dataset.colToggle);
            store.set(key, { ...store.get(key), hidden: [...hidden] });
            apply();
        });
    });
    apply();

    const form = $('[data-mtable-form]', root);
    $$('[data-autosubmit]', root).forEach((s) => s.addEventListener('change', () => form.requestSubmit()));
    $('[data-mtable-per-page]', root)?.addEventListener('change', (e) => {
        $('[data-mtable-per-page-field]', form).value = e.target.value;
        form.requestSubmit();
    });
    form?.addEventListener('submit', () => {
        // Empty fields stay out of the address.
        $$('input, select', form).forEach((el) => { if (!el.value) el.disabled = true; });
    });
    $('[data-mtable-reset]', root)?.addEventListener('click', () => store.del(key));

    // Selection + CSV of the selected rows (phone cards and table rows are the same rows).
    const bulk = $('[data-mtable-bulk]', root);
    const picked = new Set();
    const sync = () => {
        $$('[data-mtable-row]', root).forEach((r) => { const box = $('[data-mtable-select]', r); if (box) box.checked = picked.has(r.dataset.mtableRow); });
        bulk.classList.toggle('hidden', picked.size === 0);
        bulk.classList.toggle('flex', picked.size > 0);
        $('[data-mtable-count]', root).textContent = picked.size;
    };
    $$('[data-mtable-select]', root).forEach((box) => box.addEventListener('change', () => {
        const id = box.closest('[data-mtable-row]').dataset.mtableRow;
        box.checked ? picked.add(id) : picked.delete(id);
        sync();
    }));
    $('[data-mtable-all]', root)?.addEventListener('change', (e) => {
        $$('tr[data-mtable-row]', root).forEach((r) => (e.target.checked ? picked.add(r.dataset.mtableRow) : picked.delete(r.dataset.mtableRow)));
        sync();
    });
    $('[data-mtable-clear]', root)?.addEventListener('click', () => { picked.clear(); sync(); });
    $('[data-mtable-csv]', root)?.addEventListener('click', () => {
        const cell = (v) => `"${String(v).replaceAll('"', '""')}"`;
        const head = $$('thead th[data-col]', root).map((th) => cell(th.textContent.replace(/[↑↓↕]/g, '').trim()));
        const lines = $$('tr[data-mtable-row]', root).filter((r) => picked.has(r.dataset.mtableRow))
            .map((r) => $$('td[data-col]', r).map((td) => cell(td.dataset.csv)).join(','));
        const blob = new Blob(['﻿', [head.join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `${root.dataset.mtable}-selected.csv`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
});

/* ---------- folded on phones, open on desktop (remembered per screen) ---------- */
const wide = window.matchMedia('(min-width: 640px)');
$$('details[data-collapse]').forEach((d) => {
    const key = `km.collapse.${location.pathname}.${d.dataset.collapse}`;
    const set = () => { d.open = wide.matches ? true : store.get(key) === true; };
    set();
    wide.addEventListener?.('change', set);
    d.addEventListener('toggle', () => { if (!wide.matches) store.set(key, d.open); });
});
