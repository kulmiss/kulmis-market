// Kulmis Market storefront — small vanilla JS (no framework, no trackers).
// Talks to the REST API with the essential cart cookie only.

import { $, $$, say, store } from './lib.js';

const API = '/api/v1';
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


const formData = (form) => Object.fromEntries(new FormData(form).entries());

/** Runs fn with the form's buttons disabled, so a double click sends it once. */
const busy = async (form, fn) => {
    if (form.dataset.busy) return;
    form.dataset.busy = '1';
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach((b) => { b.disabled = true; });
    try { await fn(); } finally { delete form.dataset.busy; buttons.forEach((b) => { b.disabled = false; }); }
};

/* "Are you sure?" box (partials/ask): data-confirm="Question?", data-confirm-ok="Button text",
   data-confirm-more="Small print". Old browsers get the plain confirm box. */
const askBox = $('[data-ask]');
askBox?.addEventListener('click', (e) => { if (e.target === askBox) askBox.close('cancel'); }); // tap outside = Cancel
const ask = (question, ok, more) => new Promise((resolve) => {
    if (!askBox || typeof askBox.showModal !== 'function') { resolve(window.confirm(question)); return; }
    $('[data-ask-text]', askBox).textContent = question;
    $('[data-ask-ok]', askBox).textContent = ok || 'Yes';
    const small = $('[data-ask-more]', askBox);
    small.textContent = more || '';
    small.hidden = !more;
    askBox.returnValue = '';
    askBox.addEventListener('close', () => resolve(askBox.returnValue === 'ok'), { once: true });
    askBox.showModal();
});

/* Plain (non-JS) forms: block a second submit while the page is loading.
   data-confirm asks first (no inline scripts: the CSP forbids them). */
$$('form[method="post"]').forEach((f) => f.addEventListener('submit', (e) => {
    if (f.dataset.confirm && !f.dataset.confirmed && !f.dataset.sent) {
        e.preventDefault();
        if (f.dataset.asking) return;
        f.dataset.asking = '1';
        ask(f.dataset.confirm, f.dataset.confirmOk, f.dataset.confirmMore).then((yes) => {
            delete f.dataset.asking;
            if (yes) { f.dataset.confirmed = '1'; f.requestSubmit(); }
        });
        return;
    }
    if (f.dataset.sent) { e.preventDefault(); return; }
    f.dataset.sent = '1';
    setTimeout(() => {
        // A JS handler took over (fetch): it manages its own buttons.
        if (e.defaultPrevented) { delete f.dataset.sent; return; }
        f.querySelectorAll('button').forEach((b) => { b.disabled = true; });
    }, 0);
    // Back-button / bfcache: let the form be used again.
    window.addEventListener('pageshow', () => { delete f.dataset.sent; delete f.dataset.confirmed; f.querySelectorAll('button').forEach((b) => { b.disabled = false; }); }, { once: true });
}));

/* ---------- "You are signed out": keep the message, drop ?signed_out from the address bar ---------- */
if (new URLSearchParams(location.search).has('signed_out')) {
    const u = new URL(location.href);
    u.searchParams.delete('signed_out');
    history.replaceState(history.state, '', u.pathname + u.search + u.hash);
}

/* ---------- share (phones with a share sheet) ---------- */
$$('[data-share]').forEach((b) => {
    if (!navigator.share) return;
    b.classList.remove('hidden');
    b.addEventListener('click', () => navigator.share({ url: b.dataset.shareUrl, text: b.dataset.shareText }).catch(() => {}));
});

/* Announcement bar past its end date (on a saved copy of the page): hide it. */
$$('[data-announce][data-ends]').forEach((b) => { if (Date.parse(b.dataset.ends) <= Date.now()) b.remove(); });

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
// After the page has loaded and the phone is idle: the count never delays the first view.
const whenIdle = (fn) => (window.requestIdleCallback ? requestIdleCallback(fn, { timeout: 1500 }) : setTimeout(fn, 150));
if (document.readyState === 'complete') whenIdle(refreshCount);
else window.addEventListener('load', () => whenIdle(refreshCount), { once: true });

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

$$('[data-add-bundle]').forEach((b) => b.addEventListener('click', async () => {
    if (b.disabled) return;
    b.disabled = true;
    const msg = b.parentElement.querySelector('[data-bundle-message]');
    try {
        await api(`/cart/bundles/${Number(b.dataset.addBundle)}`, { method: 'POST' });
        msg.innerHTML = 'The set is in your cart. <a class="underline" href="/cart">View cart</a>';
        msg.className = 'text-sm text-leaf-600';
        refreshCount();
    } catch (err) { say(msg, err.message); }
    b.disabled = false;
}));

/* Flash-sale countdown. When it reaches zero the sale box goes away (the server
   price is the only one that counts, so a stale page can't sell at the sale price). */
$$('[data-countdown]').forEach((el) => {
    const end = Date.parse(el.dataset.countdown);
    if (Number.isNaN(end)) return;
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
        const left = Math.max(0, Math.floor((end - Date.now()) / 1000));
        const d = Math.floor(left / 86400), h = Math.floor((left % 86400) / 3600), m = Math.floor((left % 3600) / 60), sec = left % 60;
        el.textContent = (d ? `${d}d ` : '') + `${pad(h)}:${pad(m)}:${pad(sec)}`;
        if (left === 0) { clearInterval(timer); el.closest('[data-flash-sale]')?.remove(); }
    };
    const timer = setInterval(tick, 1000);
    tick();
});

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
function totalsHtml(q, gc = null) {
    const row = (l, v, cls = '') => `<div class="flex justify-between ${cls}"><span>${l}</span><span>${v}</span></div>`;
    const card = gc && gc.use > 0 ? [row(`Gift card ••••${esc(gc.last4)}`, '−' + etb(gc.use), 'text-brand-600'), row('To pay', etb(q.total - gc.use), 'text-base font-semibold')] : [];
    return [
        row('Subtotal', etb(q.list_subtotal)),
        q.member_discount ? row('Member discount', '−' + etb(q.member_discount), 'text-brand-600') : '',
        q.bundle_discount ? row('Set saving' + (q.bundles?.length ? ' (' + q.bundles.map((b) => esc(b.name)).join(', ') + ')' : ''), '−' + etb(q.bundle_discount), 'text-brand-600') : '',
        q.coupon_discount ? row(`Coupon ${esc(q.coupon)}`, '−' + etb(q.coupon_discount), 'text-brand-600') : '',
        q.coupon_capped ? '<p class="muted text-xs">This coupon is smaller on this order: these items are already at their lowest price.</p>' : '',
        q.shipping_capped ? '<p class="muted text-xs">Free delivery doesn\'t fit this order to your city, so part of the delivery is charged.</p>' : '',
        q.shipping ? row('Shipping', q.shipping_discount ? 'Free' : etb(q.shipping_payable)) : row('Shipping', 'at checkout'),
        ...(q.extras || []).map((x) => row(esc(x.label), etb(x.amount))),
        row('Total', etb(q.total), 'mt-1 text-base font-semibold'),
        ...card,
    ].join('');
}

/** "You may also like" with a one-tap Add (cart page and checkout). */
function renderSuggestions(box, items, after) {
    if (!box) return;
    if (!items?.length) { box.innerHTML = ''; return; }
    box.innerHTML = `<h2 class="mb-3 font-sans text-base font-semibold">You may also like</h2><div class="grid gap-2">${items.map((s) => `
        <div class="flex items-center gap-3 rounded-2xl border border-mist-200 p-2 dark:border-ink-700">
            ${s.image ? `<img src="${esc(s.image)}" alt="" width="56" height="56" loading="lazy" class="size-14 rounded-xl object-cover">` : '<div class="size-14 rounded-xl bg-mist-200"></div>'}
            <div class="min-w-0 flex-1"><a class="block truncate font-medium hover:underline" href="/shop/${esc(s.slug)}">${esc(s.name)}</a>
                <span class="muted text-sm">${s.price < s.regular_price ? `<s>${etb(s.regular_price)}</s> ` : ''}${etb(s.price)}</span></div>
            ${s.one_tap ? `<button type="button" class="btn-ghost" data-quick-add="${s.product_id}">Add</button>` : `<a class="btn-ghost" href="/shop/${esc(s.slug)}">Choose</a>`}
        </div>`).join('')}</div>`;
    $$('[data-quick-add]', box).forEach((b) => b.addEventListener('click', async () => {
        if (b.disabled) return;
        b.disabled = true;
        try { await api('/cart/items', { method: 'POST', body: { product_id: Number(b.dataset.quickAdd), quantity: 1 } }); refreshCount(); after(); }
        catch (err) { b.disabled = false; alert(err.message); }
    }));
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
              ${l ? `<div class="muted text-sm">${l.regular_unit_price ? `<s>${etb(l.regular_unit_price)}</s> <b class="text-ink-900 dark:text-mist-100">Sale</b> ` : ''}${etb(l.unit_price)} each${!cart.is_member && l.member_unit_price < l.unit_price ? ` · <span class="text-brand-600">member price ${etb(l.member_unit_price)}</span>` : ''}</div>` : ''}</div>
            <input type="number" min="0" max="99" value="${i.quantity}" class="input w-20" data-qty="${i.id}" aria-label="Quantity">
        </div>`;
    }).join('');
    if (cart.problem) lines.insertAdjacentHTML('beforeend', `<p class="text-sm text-danger-600">${esc(cart.problem)}</p>`);
    if (q) $('[data-totals]', body).innerHTML = totalsHtml(q, cart.gift_card);
    const gf = $('[data-giftcard-form]', body);
    if (gf) {
        if (cart.gift_card) gf.code.placeholder = `Gift card ••••${cart.gift_card.last4} added`;
        gf.addEventListener('submit', async (e) => {
            e.preventDefault();
            try { await api('/cart/gift-card', { method: 'PUT', body: { code: gf.code.value || null } }); renderCart(); } catch (err) { alert(err.message); }
        });
    }
    renderSuggestions($('[data-suggestions]', body), cart.suggestions, renderCart);
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
                + '<hr class="my-2 border-mist-200">' + (cart.quote ? totalsHtml(cart.quote, cart.gift_card) : `<p class="text-danger-600">${esc(cart.problem)}</p>`);
            renderSuggestions($('[data-checkout-suggestions]'), cart.suggestions, refreshSummary);
        } catch (e) { summary.textContent = e.message; }
    };
    // Back button after placing an order: the page comes from the browser's memory, so show the real (now empty) cart.
    window.addEventListener('pageshow', (e) => { if (e.persisted) refreshSummary(); });
    api('/shipping/zones').then(({ data }) => {
        zonesSel.insertAdjacentHTML('beforeend', data.map((z) => `<option value="${z.id}">${esc(z.name)} — from ${etb(z.base_fee)}</option>`).join(''));
        $('[data-cities]').innerHTML = [...new Set(data.flatMap((z) => z.cities))].map((c) => `<option value="${esc(c)}">`).join('');
    }).catch(() => {});
    zonesSel?.addEventListener('change', refreshSummary);
    // Paid extras (gift wrap, express, insurance): saved on the cart, priced by the server.
    $$('[data-extra]', checkoutForm).forEach((box) => box.addEventListener('change', async () => {
        const extras = $$('[data-extra]:checked', checkoutForm).map((b) => b.value);
        try { await api('/cart/extras', { method: 'PUT', body: { extras } }); } catch (e) { alert(e.message); }
        refreshSummary();
    }));
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

/* ---------- help centre ---------- */
const helpSearch = $('[data-help-search]');
if (helpSearch) {
    const input = $('input', helpSearch);
    const none = $('[data-help-none]');
    // Filter as you type (the form still works without JavaScript).
    input.addEventListener('input', () => {
        const words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let shown = 0;
        $$('[data-help-group]').forEach((g) => {
            let inGroup = 0;
            $$('[data-help-item]', g).forEach((item) => {
                const hit = words.every((w) => item.textContent.toLowerCase().includes(w));
                item.hidden = !hit;
                if (hit) inGroup++;
            });
            g.hidden = inGroup === 0;
            shown += inGroup;
        });
        none?.classList.toggle('hidden', shown > 0);
    });
}
$('[data-contact-form]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msg = $('[data-form-message]', form);
    await busy(form, async () => {
        try {
            const { data } = await api('/contact', { method: 'POST', body: formData(form) });
            say(msg, data.message, true);
            form.reset();
        } catch (err) { say(msg, err.message); }
    });
});

/* ---------- gift membership ---------- */
$('[data-custom-form]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msg = $('[data-form-message]', form);
    await busy(form, async () => {
        try { const { data } = await api('/custom-requests', { method: 'POST', form: new FormData(form) }); say(msg, data.message, true); form.reset(); } catch (err) { say(msg, err.message); }
    });
});
$('[data-giftcard-buy]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msg = $('[data-form-message]', form);
    await busy(form, async () => {
        try { const { data } = await api('/gift-cards', { method: 'POST', form: new FormData(form) }); say(msg, data.message, true); form.reset(); } catch (err) { say(msg, err.message); }
    });
});
$('[data-gift-form]')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const msg = $('[data-form-message]', form);
    await busy(form, async () => {
        try {
            const { data } = await api('/gifts', { method: 'POST', form: new FormData(form) });
            say(msg, data.message, true);
            form.reset();
        } catch (err) { say(msg, err.message); }
    });
});

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
    const src = $('[data-copy-source]', b.closest('[data-share-block]') || b.parentElement);
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

/* ---------- checkout: pick a saved address ---------- */
$$('[data-saved-address]').forEach((sel) => {
    const fill = () => {
        const opt = sel.selectedOptions[0];
        const data = opt?.dataset.fill ? JSON.parse(opt.dataset.fill) : null;
        const form = sel.closest('form');
        ['first_name', 'last_name', 'phone', 'city', 'address'].forEach((k) => {
            const el = form.elements[k];
            if (el) el.value = data ? data[k] ?? '' : (k === 'first_name' || k === 'last_name' || k === 'phone' ? el.value : '');
        });
    };
    sel.addEventListener('change', fill);
    fill();
});

/* ---------- "tell me when it's back" ---------- */
$$('[data-stock-alert]').forEach((form) => form.addEventListener('submit', (e) => {
    e.preventDefault();
    busy(form, async () => {
        const msg = $('[data-alert-message]', form);
        try {
            const d = formData(form);
            const res = await api('/stock-alerts', { method: 'POST', body: { product_id: Number(form.dataset.product), variant_id: d.variant_id ? Number(d.variant_id) : null, contact: d.contact, website: d.website } });
            say(msg, res.message, true);
        } catch (err) { say(msg, err.message); }
    });
}));

/* ---------- folded on phones, open on desktop (remembered per screen) ---------- */
const wide = window.matchMedia('(min-width: 640px)');
$$('details[data-collapse]').forEach((d) => {
    const key = `km.collapse.${location.pathname}.${d.dataset.collapse}`;
    const set = () => { d.open = wide.matches ? true : store.get(key) === true; };
    set();
    wide.addEventListener?.('change', set);
    d.addEventListener('toggle', () => { if (!wide.matches) store.set(key, d.open); });
});

/* ---------- page parts loaded only where they are used (smaller first download) ---------- */
if ($('[data-card]')) import('./chunks/picture-card.js');
if ($('[data-install], [data-push]')) import('./chunks/member-app.js');
if ($('[data-mtable]')) import('./chunks/member-tables.js');

/* "What you could earn" (member home): the same sums as App\Reports\WhatIf::forMember, from the real settings. */
$$('[data-earn-calc]').forEach((box) => {
    const c = JSON.parse(box.dataset.earnCalc);
    const fmt = etb;
    const out = $('[data-earn-result]', box);
    const row = (label, v, strong) => `<div class="flex justify-between gap-2${strong ? ' font-semibold' : ''}"><dt>${label}</dt><dd class="tabular-nums">${fmt(v)}</dd></div>`;
    const draw = () => {
        const d = Math.max(0, Math.min(500, parseInt($('[data-earn-direct]', box).value, 10) || 0));
        const t = Math.max(0, Math.min(50, parseInt($('[data-earn-theirs]', box).value, 10) || 0));
        const l1 = c.rates[0] * d, l2 = c.rates[1] * d * t, l3 = c.rates[2] * d * t * t;
        const badge = c.ladder.find(([th]) => d >= th);
        const bonus = badge ? Math.min(badge[1], Math.floor(d * c.price * c.teamLimit / 10000)) : 0;
        out.innerHTML = row(`Level 1 (${d} people)`, l1) + (c.rates[1] ? row(`Level 2 (${d * t} people)`, l2) : '')
            + (c.rates[2] ? row(`Level 3 (${d * t * t} people)`, l3) : '') + (bonus ? row(`${badge[2]} badge bonus`, bonus) : '')
            + row('Every month', l1 + l2 + l3 + bonus, true) + (c.first ? row('Once, at their first payment', c.first * d) : '');
    };
    $$('input', box).forEach((i) => i.addEventListener('input', draw));
    draw();
});
