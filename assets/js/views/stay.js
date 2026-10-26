// Stay: the chosen basecamp, alternatives to swap to, and passes.

import { state, effective, addon, isApplied, toggleAddon, currency } from '../store.js';
import { esc, money, signed, compact, km, cap } from '../format.js';
import { onAction, toast } from '../ui.js';
import { house } from '../art.js';
import { openEvent } from './eventSheet.js';

export function render(root) {
    const plan = state.plan;
    const cur = currency();
    if (!plan.hotel) {
        root.innerHTML = `<div class="card empty">${house()}<p class="heading-sm">A day trip — no stay needed.</p><p>Add a night to your dates and we’ll find a well-placed place to sleep.</p></div>`;
        return;
    }
    const h = effective(plan.hotel);
    const b = plan.budget;

    root.innerHTML = `
        <section class="stay-hero">
            ${h.image ? `<div class="photo"><img src="${esc(h.image)}" alt="${esc(h.title)}" referrerpolicy="no-referrer"></div>` : `<div class="photo stay-art" aria-hidden="true">${house()}</div>`}
            <div class="card stack">
                <div>
                    <div class="tags" style="gap:6px">
                        <span class="tag tag--sm tag--oat"><i class="fa-solid fa-bed"></i>${esc(cap(h.style || 'Stay'))}</span>
                        ${h.upgraded ? '<span class="tag tag--sm tag--dark"><i class="fa-solid fa-star"></i>Your pick</span>' : '<span class="tag tag--sm tag--blue"><i class="fa-solid fa-thumbs-up"></i>Our pick</span>'}
                    </div>
                    <h2 class="heading mt-16">${esc(h.title)}</h2>
                    <p class="muted mt-8">${esc([h.area, h.rating != null ? `★ ${Number(h.rating).toFixed(1)}${h.ratingCount ? ` (${compact(h.ratingCount)})` : ''}` : ''].filter(Boolean).join(' · '))}</p>
                    <p class="mt-16">${esc(h.summary || '')}</p>
                </div>
                <div class="stats">
                    <div class="stat"><div class="stat__label">Per night</div><div class="stat__value">${esc(money(h.pricePerNight, cur))}</div></div>
                    <div class="stat"><div class="stat__label">${h.nights} night${h.nights === 1 ? '' : 's'} · ${h.rooms} room${h.rooms > 1 ? 's' : ''}</div><div class="stat__value">${esc(money(h.cost, cur))}</div></div>
                    <div class="stat"><div class="stat__label">To the sights</div><div class="stat__value">${h.centroidKm != null ? esc(km(h.centroidKm)) : '—'}</div></div>
                </div>
                ${(h.amenities || []).length ? `<div class="tags" style="gap:6px">${h.amenities.map((a) => `<span class="tag tag--sm tag--oat">${esc(a)}</span>`).join('')}</div>` : ''}
                <div class="row">
                    <button type="button" class="btn btn--outline" data-action="details"><i class="fa-solid fa-circle-info"></i>Details</button>
                    <a class="btn btn--primary" href="#/trip/map?focus=hotel"><i class="fa-solid fa-map-location-dot"></i>Open on map</a>
                </div>
            </div>
        </section>

        <section class="mt-40">
            <div class="row between">
                <h3 class="heading">Other stays that fit</h3>
                <p class="caption muted">Your lodging cap is ${esc(money(b.nightlyCap, cur))} a night (${Math.round(b.lodgingPct * 100)}% of the budget).</p>
            </div>
            <div class="grid-3 mt-24">${plan.hotelAlternatives.map((alt) => altCard(alt, cur)).join('') || '<p class="muted">No other stays listed for this destination.</p>'}</div>
        </section>

        ${plan.packages.length ? `
        <section class="mt-40">
            <h3 class="heading">Passes & city cards</h3>
            <div class="grid-3 mt-24">${plan.packages.map((p) => `
                <article class="feature card--tight stack" style="gap:12px">
                    <div class="row between"><span class="tag tag--sm ${p.applied ? 'tag--dark' : ''}"><i class="fa-solid ${p.applied ? 'fa-circle-check' : 'fa-circle-minus'}"></i>${p.applied ? 'Included' : 'Skipped'}</span><span class="strong">${esc(money(p.price, cur))}</span></div>
                    <p class="heading-sm">${esc(p.label)}</p>
                    <p class="caption">${esc(p.note)}</p>
                    <p class="caption muted">${esc(p.detail)}</p>
                </article>`).join('')}</div>
        </section>` : ''}`;

    return onAction(root, {
        details: () => openEvent('hotel'),
        swap: (el) => {
            const a = addon(el.dataset.addon);
            const on = toggleAddon(el.dataset.addon);
            toast(on ? `Staying at ${a.patch.title} · ${signed(a.delta, cur)}` : 'Back to our pick', {
                action: on ? 'Undo' : null,
                onAction: () => toggleAddon(el.dataset.addon),
            });
        },
    });
}

function altCard(alt, cur) {
    const a = addon(alt.addonId);
    const on = isApplied(alt.addonId);
    return `<article class="card card--tight alt">
        ${alt.image ? `<div class="photo"><img src="${esc(alt.image)}" alt="${esc(alt.title)}" loading="lazy" referrerpolicy="no-referrer"></div>` : ''}
        <span class="tag tag--sm tag--oat" style="justify-self:start">${esc(cap(alt.style || 'Stay'))}</span>
        <p class="heading-sm">${esc(alt.title)}</p>
        <p class="caption muted">${esc([alt.area, alt.rating != null ? `★ ${Number(alt.rating).toFixed(1)}` : '', alt.centroidKm != null ? `${km(alt.centroidKm)} to sights` : ''].filter(Boolean).join(' · '))}</p>
        <div class="alt__price"><span class="strong">${esc(money(alt.pricePerNight, cur))} <span class="caption muted">/ night</span></span>${a ? `<span class="caption strong">${esc(signed(a.delta, cur))} total</span>` : ''}</div>
        ${a ? `<button type="button" class="btn btn--sm ${on ? 'btn--outline' : 'btn--dark'}" data-action="swap" data-addon="${esc(alt.addonId)}">${on ? '<i class="fa-solid fa-rotate-left"></i>Undo swap' : '<i class="fa-solid fa-right-left"></i>Swap here'}</button>` : ''}
    </article>`;
}
