// UI primitives: toasts, the modal sheet, and a tiny delegated-events helper.

import { esc } from './format.js';

/** Delegate clicks inside root to handlers keyed by data-action. */
export function onAction(root, handlers) {
    const listener = (e) => {
        const el = e.target.closest('[data-action]');
        if (!el || !root.contains(el)) return;
        const fn = handlers[el.dataset.action];
        if (fn) {
            e.preventDefault();
            fn(el, e);
        }
    };
    root.addEventListener('click', listener);
    return () => root.removeEventListener('click', listener);
}

export function toast(message, { action, onAction: run, timeout = 4200 } = {}) {
    const host = document.getElementById('toasts');
    const el = document.createElement('div');
    el.className = 'toast';
    el.innerHTML = `<span>${esc(message)}</span>${action ? `<button type="button" class="btn btn--primary btn--sm">${esc(action)}</button>` : ''}`;
    host.appendChild(el);
    const close = () => el.remove();
    el.querySelector('button')?.addEventListener('click', () => { run?.(); close(); });
    setTimeout(close, timeout);
}

let sheetCleanup = null;

/**
 * Open the modal sheet. `render(body)` fills it and may return a cleanup.
 * Esc, the close button and a backdrop click all dismiss it; focus returns
 * to whatever opened it.
 */
export function openSheet(render, { label = 'Details' } = {}) {
    closeSheet();
    const root = document.getElementById('sheet-root');
    const opener = document.activeElement;
    root.innerHTML = `
        <div class="sheet-backdrop" data-backdrop>
            <section class="sheet" role="dialog" aria-modal="true" aria-label="${esc(label)}" tabindex="-1">
                <button type="button" class="btn btn--icon sheet__close" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                <div data-sheet-body></div>
            </section>
        </div>`;
    const sheet = root.querySelector('.sheet');
    const body = root.querySelector('[data-sheet-body]');
    const inner = render(body) || null;

    const onKey = (e) => {
        if (e.key === 'Escape') closeSheet();
        if (e.key === 'Tab') {
            const f = [...sheet.querySelectorAll('button, a[href], input, select, [tabindex]:not([tabindex="-1"])')].filter((x) => !x.disabled && x.offsetParent);
            if (!f.length) return;
            if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
            else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
        }
    };
    const onClick = (e) => {
        if (e.target.matches('[data-backdrop]') || e.target.closest('[data-close]')) closeSheet();
    };
    document.addEventListener('keydown', onKey);
    root.addEventListener('click', onClick);
    document.body.style.overflow = 'hidden';
    sheet.focus();

    sheetCleanup = () => {
        document.removeEventListener('keydown', onKey);
        root.removeEventListener('click', onClick);
        if (typeof inner === 'function') inner();
        root.innerHTML = '';
        document.body.style.overflow = '';
        opener?.focus?.({ preventScroll: true });
        sheetCleanup = null;
    };
    return { sheet, body, close: closeSheet };
}

export function closeSheet() {
    sheetCleanup?.();
}

export const sheetOpen = () => !!sheetCleanup;
