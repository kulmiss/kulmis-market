// Loaded only on the bell page: home-screen icon + phone notifications.
import { $, $$, say } from '../lib.js';

/* ---------- member app: home-screen icon + phone notifications ---------- */
const installBox = $('[data-install]');
if (installBox && !window.matchMedia('(display-mode: standalone)').matches) {
    let deferred = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        installBox.hidden = false;
        $('[data-install-btn]', installBox).hidden = false;
    });
    $('[data-install-btn]', installBox)?.addEventListener('click', async () => {
        if (!deferred) return;
        deferred.prompt();
        await deferred.userChoice.catch(() => null);
        deferred = null;
        installBox.hidden = true;
    });
    if (/iphone|ipad|ipod/i.test(navigator.userAgent) && !navigator.standalone) {
        installBox.hidden = false;
        $('[data-install-ios]', installBox).hidden = false;
    }
}

const pushBox = $('[data-push]');
const pushKey = document.querySelector('meta[name=km-push-key]')?.content;
if (pushBox && pushKey && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window) {
    pushBox.hidden = false;
    const msg = $('[data-push-msg]', pushBox);
    const on = $('[data-push-on]', pushBox);
    const off = $('[data-push-off]', pushBox);
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const send = async (method, body) => {
        const res = await fetch('/account/push', { method, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body) });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.message || 'Something went wrong. Please try again.');
        return data;
    };
    const keyBytes = () => {
        const b64 = (pushKey + '='.repeat((4 - (pushKey.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    };
    const device = () => {
        const ua = navigator.userAgent;
        const os = /android/i.test(ua) ? 'Android' : /iphone|ipad/i.test(ua) ? 'iPhone' : /windows/i.test(ua) ? 'Windows' : /mac/i.test(ua) ? 'Mac' : 'Computer';
        const br = /edg\//i.test(ua) ? 'Edge' : /firefox/i.test(ua) ? 'Firefox' : /chrome/i.test(ua) ? 'Chrome' : /safari/i.test(ua) ? 'Safari' : 'Browser';
        return `${os} · ${br}`;
    };
    const kinds = () => $$('[data-push-kinds] input:checked', pushBox).map((i) => i.value);
    const show = (subscribed) => { on.textContent = subscribed ? 'Save my choices' : 'Turn on for this phone'; off.hidden = !subscribed; };
    navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((s) => show(!!s)).catch(() => {});
    on.addEventListener('click', async () => {
        on.disabled = true;
        try {
            if (Notification.permission === 'denied') throw new Error('Notifications are blocked for this site. Allow them in your browser settings first.');
            if ((await Notification.requestPermission()) !== 'granted') throw new Error('You did not allow notifications.');
            const reg = await navigator.serviceWorker.ready;
            const sub = (await reg.pushManager.getSubscription()) || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
            const { data } = await send('POST', { endpoint: sub.endpoint, device: device(), kinds: kinds() });
            say(msg, data.message, true);
            show(true);
        } catch (err) { say(msg, err.message); }
        on.disabled = false;
    });
    off.addEventListener('click', async () => {
        off.disabled = true;
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            await send('DELETE', { endpoint: sub?.endpoint });
            await sub?.unsubscribe();
            say(msg, 'Phone notifications are off.', true);
            show(false);
        } catch (err) { say(msg, err.message); }
        off.disabled = false;
    });
}

