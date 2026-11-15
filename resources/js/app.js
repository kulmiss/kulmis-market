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
    el.classList.toggle('text-clay-600', !ok);
};

const formData = (form) => Object.fromEntries(new FormData(form).entries());

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
        q.member_discount ? row('Member discount', '−' + etb(q.member_discount), 'text-clay-600') : '',
        q.coupon_discount ? row(`Coupon ${esc(q.coupon)}`, '−' + etb(q.coupon_discount), 'text-clay-600') : '',
        q.shipping ? row('Shipping', q.shipping_discount ? 'Free' : etb(q.shipping_payable)) : row('Shipping', 'at checkout'),
        row('Total', etb(q.total), 'mt-1 text-base font-semibold'),
    ].join('');
}

async function renderCart() {
    const body = $('[data-cart-body]');
    if (!body) return;
    let cart;
    try { cart = await api('/cart'); } catch (e) { body.innerHTML = `<p class="text-clay-600">${esc(e.message)}</p>`; return; }
    if (!cart.items.length) { body.innerHTML = '<p class="muted">Your cart is empty. <a class="underline" href="/shop">Visit the shop</a></p>'; return; }
    body.innerHTML = $('#cart-tpl').innerHTML;
    const lines = $('[data-lines]', body);
    const q = cart.quote;
    lines.innerHTML = cart.items.map((i, idx) => {
        const l = q?.lines[idx];
        return `<div class="card flex items-center gap-4 p-3">
            ${i.image ? `<img src="${esc(i.image)}" alt="" width="64" height="64" class="size-16 rounded-xl object-cover">` : '<div class="size-16 rounded-xl bg-sand-200"></div>'}
            <div class="flex-1"><a class="font-medium hover:underline" href="/shop/${esc(i.slug)}">${esc(i.name)}</a>
              ${l ? `<div class="muted text-sm">${etb(l.unit_price)} each${!cart.is_member && l.member_unit_price < l.unit_price ? ` · <span class="text-clay-600">member price ${etb(l.member_unit_price)}</span>` : ''}</div>` : ''}</div>
            <input type="number" min="0" max="99" value="${i.quantity}" class="input w-20" data-qty="${i.id}" aria-label="Quantity">
        </div>`;
    }).join('');
    if (cart.problem) lines.insertAdjacentHTML('beforeend', `<p class="text-sm text-clay-600">${esc(cart.problem)}</p>`);
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
                + '<hr class="my-2 border-sand-200">' + (cart.quote ? totalsHtml(cart.quote) : `<p class="text-clay-600">${esc(cart.problem)}</p>`);
        } catch (e) { summary.textContent = e.message; }
    };
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
                    : `/track?number=${encodeURIComponent(data.number)}`;
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
        try {
            const { data } = await api(`/orders/${encodeURIComponent(number)}/payment-proof`, { method: 'POST', form });
            say(msg, data.message, true);
            e.target.reset();
        } catch (err) { say(msg, err.message); }
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
                    return `<li class="rounded-lg px-1 py-2 ${done ? 'bg-clay-500 text-white' : 'bg-sand-100 dark:bg-coffee-900'}">${s.replace('_', ' ')}</li>`;
                }).join('')}</ol>
                ${o.tracking_number ? `<p class="mt-3 text-sm">Courier tracking: <b>${esc(o.tracking_number)}</b></p>` : ''}
                <ul class="mt-4 grid gap-1 text-sm">${o.items.map((i) => `<li>${i.quantity} × ${esc(i.name)}${i.made_to_order ? ` <span class="muted">(made to order, ~${i.making_days} days)</span>` : ''}</li>`).join('')}</ul>
                <p class="mt-3 font-semibold">Total ${etb(o.total)}</p>
                ${o.amount_due > 0 ? `<a class="btn-primary mt-4" href="/pay/${encodeURIComponent(o.number)}?phone=${encodeURIComponent(trackForm.phone.value)}">Pay ${etb(o.amount_due)}</a>` : ''}
            </div>`;
        } catch (err) { out.innerHTML = `<p class="text-clay-600">${esc(err.message)}</p>`; }
    });
    if (trackForm.number.value && trackForm.phone.value) trackForm.requestSubmit();
}

/* ---------- signals (guests) ---------- */
const signalsForm = $('[data-signals-form]');
if (signalsForm) {
    signalsForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const { data } = await api('/signals/payment-proof', { method: 'POST', form: new FormData(signalsForm) });
            say($('[data-form-message]', signalsForm), data.message || 'Thank you! We check your receipt and send your Telegram link by SMS.', true);
            signalsForm.reset();
        } catch (err) { say($('[data-form-message]', signalsForm), err.message); }
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
