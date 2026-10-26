// The app bar. It carries only what is universal for the current stage:
// navigation on the landing page, progress + budget in the wizard, and the
// trip chip + live budget meter once a trip exists.

import { state, ledger, currency } from './store.js';
import { esc, money, dateLabel } from './format.js';
import { logoMark } from './art.js';

export const WIZARD_STEPS = 6;

export function renderHeader(route) {
    const el = document.getElementById('app-header');
    el.innerHTML = `<div class="container app-header__inner">
        <a class="brand" href="#/" aria-label="Wanderbean home">${logoMark()}<span class="brand__word">Wanderbean</span></a>
        <div class="app-header__mid">${middle(route)}</div>
        <div class="app-header__end">${end(route)}</div>
    </div>`;
}

function middle(route) {
    if (route.name === 'landing') {
        return `<a class="header-link" href="#/" data-scroll="how">How it works</a>
                <a class="header-link" href="#/" data-scroll="sources">Open data</a>`;
    }
    if (route.name === 'prefs' || route.name === 'brewing') {
        const step = route.name === 'brewing' ? WIZARD_STEPS : route.step;
        const pct = route.name === 'brewing' ? 100 : ((step - 1) / WIZARD_STEPS) * 100 + 4;
        return `<div class="steps" aria-label="Step ${step} of ${WIZARD_STEPS}">
                    <span class="caption strong">${route.name === 'brewing' ? 'Brewing' : `Step ${step} of ${WIZARD_STEPS}`}</span>
                    <div class="steps__bar"><div class="steps__fill" style="width:${pct}%"></div></div>
                </div>`;
    }
    if (route.name.startsWith('trip') && state.plan) {
        const m = state.plan.meta;
        return `<a class="trip-chip" href="#/trip">
                    <span class="trip-chip__icon"><i class="fa-solid fa-location-dot"></i></span>
                    <span class="trip-chip__text"><b>${esc(m.destination)}</b> · ${esc(dateLabel(m.startDate, { day: 'numeric', month: 'short' }))} – ${esc(dateLabel(m.endDate, { day: 'numeric', month: 'short' }))}</span>
                </a>`;
    }
    return '';
}

function end(route) {
    if (route.name.startsWith('trip') && state.plan) {
        return `${meter()}<a class="btn btn--ghost btn--icon" href="#/" aria-label="Plan a new trip" title="Plan a new trip"><i class="fa-solid fa-plus"></i></a>`;
    }
    if (route.name === 'prefs' || route.name === 'brewing') {
        const r = state.request;
        return `<span class="tag tag--sm"><i class="fa-solid fa-wallet"></i><span>${esc(money(r.budget, r.currency))}</span></span>
                <a class="btn btn--ghost btn--sm" href="#/"><span>Exit</span><i class="fa-solid fa-xmark"></i></a>`;
    }
    return state.plan
        ? `<a class="btn btn--dark" href="#/trip"><i class="fa-solid fa-suitcase-rolling"></i><span>My trip</span></a>`
        : `<a class="btn btn--dark" href="#/" data-scroll="plan" aria-label="Plan a trip"><i class="fa-solid fa-mug-hot"></i><span>Plan a trip</span></a>`;
}

/** Spent vs budget: one hue, fill on a lighter step of the same blue. */
export function meter() {
    const L = ledger();
    const cur = currency();
    const pct = Math.min(100, (L.total / L.budget) * 100);
    const status = L.over
        ? `<span class="meter__status"><i class="fa-solid fa-triangle-exclamation"></i> Over by ${esc(money(L.total - L.budget, cur))}</span>`
        : `<span class="muted">of ${esc(money(L.budget, cur))}</span>`;
    return `<a class="meter${L.over ? ' is-over' : ''}" href="#/trip/budget" role="meter" aria-valuemin="0" aria-valuemax="${Math.round(L.budget)}" aria-valuenow="${Math.round(L.total)}" aria-label="Budget used: ${esc(money(L.total, cur))} of ${esc(money(L.budget, cur))}">
        <span class="meter__row"><span><span class="meter__label-long">Spent </span><b>${esc(money(L.total, cur))}</b></span>${status}</span>
        <span class="meter__track"><span class="meter__fill" style="width:${pct.toFixed(1)}%"></span></span>
    </a>`;
}
