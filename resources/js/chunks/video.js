// Loaded only on pages with a home page video. The YouTube / Vimeo player is
// put in only after a tap, so the page stays fast and sends nothing to them before.
import { $$ } from '../lib.js';

$$('[data-video]').forEach((box) => {
    const play = box.querySelector('[data-video-play]');
    play?.addEventListener('click', () => {
        const src = box.dataset.video || '';
        if (!/^https:\/\/(www\.youtube-nocookie\.com|player\.vimeo\.com)\//.test(src)) return;
        const frame = document.createElement('iframe');
        frame.src = src;
        frame.title = box.dataset.videoTitle || 'Video';
        frame.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        frame.allowFullscreen = true;
        frame.referrerPolicy = 'strict-origin-when-cross-origin';
        frame.className = 'absolute inset-0 h-full w-full';
        box.replaceChildren(frame);
        frame.focus();
    }, { once: true });
});
