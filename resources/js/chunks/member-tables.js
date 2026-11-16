// Loaded only on member pages with a table.
import { $, $$, store } from '../lib.js';

/* ---------- member tables: saved view, columns, selection, CSV ---------- */
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

