// Day view: a compact timeline for one day plus a mini map of its route.
// Every row opens the event sheet with the full details.

import { state, effective, currency } from '../store.js';
import { esc, money, sum, two, km, dateLabel, weatherIcon, nodeIcon, typeLabel, MODE } from '../format.js';
import { onAction } from '../ui.js';
import { openEvent } from './eventSheet.js';
import { createMap } from './map.js';

export function render(root, route) {
    const days = state.plan.days;
    const n = Math.min(Math.max(1, route.day || 1), days.length);
    const day = days[n - 1];
    const nodes = day.nodes.map(effective);
    const cur = currency();
    const cost = sum(nodes, (x) => x.cost) + sum(nodes, (x) => x.transit?.cost);
    const stops = nodes.filter((x) => ['attraction', 'market', 'activity'].includes(x.type)).length;
    const w = day.weather;

    root.innerHTML = `
        <div class="day-nav">
            <a class="btn btn--outline btn--sm" href="#/trip"><i class="fa-solid fa-arrow-left"></i>Calendar</a>
            <div class="row" style="gap:8px">
                <a class="btn btn--ghost btn--icon" href="#/trip/day/${Math.max(1, n - 1)}" aria-label="Previous day" ${n === 1 ? 'aria-disabled="true"' : ''}><i class="fa-solid fa-chevron-left"></i></a>
                <div class="tags" style="gap:6px">${days.map((d) => `<a class="tag tag--sm${d.index + 1 === n ? ' is-active' : ''}" href="#/trip/day/${d.index + 1}" ${d.index + 1 === n ? 'aria-current="page"' : ''}>Day ${d.index + 1}</a>`).join('')}</div>
                <a class="btn btn--ghost btn--icon" href="#/trip/day/${Math.min(days.length, n + 1)}" aria-label="Next day" ${n === days.length ? 'aria-disabled="true"' : ''}><i class="fa-solid fa-chevron-right"></i></a>
            </div>
        </div>

        <section class="feature day-hero">
            <div>
                <p class="caption strong">Day ${two(n)} · ${esc(dateLabel(day.date, { weekday: 'long', day: 'numeric', month: 'long' }))}</p>
                <h2 class="heading mt-8">${esc(day.theme)}</h2>
                <div class="day-hero__facts">
                    <span class="tag tag--sm"><i class="fa-solid ${weatherIcon(w.code)}"></i>${esc(w.tmax != null ? `${w.label} · ${w.tmax}°/${w.tmin}°${w.rain != null ? ` · ${w.rain}% rain` : ''}` : 'Forecast opens 16 days out')}</span>
                    <span class="tag tag--sm"><i class="fa-solid fa-sun"></i>${esc(day.sunrise)} – ${esc(day.sunset)}</span>
                    <span class="tag tag--sm"><i class="fa-solid fa-location-dot"></i>${stops} stops</span>
                    <span class="tag tag--sm"><i class="fa-solid fa-wallet"></i>${esc(money(cost, cur))}</span>
                </div>
            </div>
            <a class="btn btn--primary" href="#/trip/map/${n}"><i class="fa-solid fa-map-location-dot"></i>Show day on map</a>
        </section>

        <div class="day-layout">
            <ol class="timeline" aria-label="Day ${n} timeline">${nodes.map((x, i) => item(x, i, cur)).join('')}</ol>
            <aside class="day-side">
                <div class="card mini-map" id="mini-map" aria-label="Map of day ${n}"></div>
                <div class="card card--tight">
                    <p class="strong">Tap any stop for details</p>
                    <p class="caption muted mt-8">Each stop opens its route, reviews, food nearby and upgrades. Swaps update the budget meter at the top straight away.</p>
                </div>
            </aside>
        </div>`;

    const off = onAction(root, { open: (el) => openEvent(el.dataset.id) });
    const map = createMap(root.querySelector('#mini-map'), { dayIndex: n - 1, compact: true, onSelect: (id) => openEvent(id) });
    return () => { off(); map?.destroy(); };
}

function item(n, i, cur) {
    const leg = n.transit && i > 0 && n.transit.minutes > 2
        ? `<li class="leg" aria-hidden="true"><i class="fa-solid ${MODE[n.transit.mode]?.icon || 'fa-route'}"></i>${esc(MODE[n.transit.mode]?.label || n.transit.mode)} · ${n.transit.minutes} min · ${esc(km(n.transit.km))}${n.transit.covered ? ' · in pass' : n.transit.cost > 0 ? ` · ${esc(money(n.transit.cost, cur))}` : ''}</li>`
        : '';
    const cost = n.cost > 0 ? esc(money(n.cost, cur))
        : (n.tags || []).includes('PASS') ? '<i class="fa-solid fa-circle-check"></i> In pass'
        : ['pause', 'return'].includes(n.type) ? '' : 'Free';
    const sub = [typeLabel(n), n.area, (n.tags || []).includes('GOLDEN HOUR') ? 'Golden hour' : ''].filter(Boolean).join(' · ');
    return `${leg}<li>
        <button type="button" class="event event--${n.type}" data-action="open" data-id="${esc(n.id)}" aria-label="${esc(`${n.start} ${n.title}`)}">
            <span class="event__time">${esc(n.start)}${n.type !== 'return' ? `<small>${esc(n.end)}</small>` : ''}</span>
            <span class="event__icon"><i class="fa-solid ${nodeIcon(n)}"></i></span>
            <span style="min-width:0">
                <span class="event__title" style="display:block">${esc(n.title)}${n.upgraded ? ' <i class="fa-solid fa-star" style="font-size:12px" title="Upgraded"></i>' : ''}</span>
                <span class="event__sub" style="display:block">${esc(sub)}</span>
            </span>
            <span class="event__cost">${cost}</span>
        </button>
    </li>`;
}
