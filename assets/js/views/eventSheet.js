// Event sheet: everything about one stop, layered into tabs so nothing
// shouts at once. Opened from the day timeline, the map and the stay tab.

import { state, effective, findNode, addon, isApplied, toggleAddon, currency } from '../store.js';
import { esc, money, signed, compact, km, mins, cap, MODE, nodeIcon, typeLabel, INTERESTS } from '../format.js';
import { openSheet, onAction, toast, closeSheet } from '../ui.js';
import { foldedMap } from '../art.js';

const TABS = [
    ['overview', 'Overview', 'fa-circle-info'],
    ['route', 'Getting there', 'fa-route'],
    ['reviews', 'Reviews', 'fa-star'],
    ['nearby', 'Nearby', 'fa-utensils'],
    ['upgrades', 'Upgrades', 'fa-wand-magic-sparkles'],
];

/** Open the sheet for a timeline node id, or 'hotel' for the basecamp. */
export function openEvent(id, tab = 'overview') {
    let current = { id, tab };
    openSheet((body) => {
        const paint = () => {
            const base = current.id === 'hotel' ? state.plan.hotel : findNode(current.id);
            if (!base) return closeSheet();
            body.innerHTML = sheetHTML(base, effective(base), current.tab);
        };
        paint();
        const off = onAction(body, {
            tab: (el) => { current.tab = el.dataset.tab; paint(); body.querySelector(`[data-tab="${current.tab}"]`)?.focus(); },
            step: (el) => { current = { id: el.dataset.id, tab: current.tab }; paint(); },
            addon: (el) => {
                const a = addon(el.dataset.addon);
                const on = toggleAddon(el.dataset.addon);
                paint();
                toast(on ? `${a.label} · ${signed(a.delta, currency())}` : 'Change undone', {
                    action: on ? 'Undo' : null,
                    onAction: () => { toggleAddon(el.dataset.addon); paint(); },
                });
            },
            map: () => {
                const n = current.id === 'hotel' ? null : findNode(current.id);
                closeSheet();
                location.hash = n ? `#/trip/map/${n.day + 1}?focus=${encodeURIComponent(n.id)}` : '#/trip/map?focus=hotel';
            },
        });
        return off;
    }, { label: 'Stop details' });
}

function sheetHTML(base, n, tab) {
    const cur = currency();
    const isHotel = n.type === 'hotel' || base.id === 'hotel';
    const dayNodes = isHotel ? [] : state.plan.days[n.day].nodes;
    const idx = dayNodes.findIndex((x) => x.id === base.id);
    const prev = dayNodes[idx - 1];
    const next = dayNodes[idx + 1];
    const upgrades = upgradesFor(base, isHotel);

    const costLabel = isHotel ? `${money(n.pricePerNight, cur)} / night`
        : n.cost > 0 ? money(n.cost, cur) : (n.tags || []).includes('PASS') ? 'Included in pass' : 'Free';
    const facts = [
        ['fa-wallet', costLabel],
        !isHotel && ['fa-clock', `${n.start}${n.type !== 'return' ? ` – ${n.end}` : ''}`],
        n.hours && ['fa-door-open', `Open ${n.hours}`],
        n.rating != null ? ['fa-star', `${Number(n.rating).toFixed(1)}${n.ratingCount ? ` · ${compact(n.ratingCount)} ratings` : ''}`]
            : n.popularity ? ['fa-fire', `${compact(n.popularity)} reads / month`] : null,
    ].filter(Boolean);
    const tags = [
        `<span class="tag tag--sm tag--oat"><i class="fa-solid ${nodeIcon(n)}"></i>${esc(isHotel ? cap(n.style || 'Stay') : typeLabel(n))}</span>`,
        ...(n.tags || []).filter((t) => !['SHOPPING ALLOWANCE', 'FREE TIME'].includes(t)).map((t) => `<span class="tag tag--sm tag--blue">${esc(cap(t.toLowerCase()))}</span>`),
        n.upgraded ? '<span class="tag tag--sm tag--dark"><i class="fa-solid fa-star"></i>Upgraded</span>' : '',
    ].join('');

    return `
        ${n.image ? `<div class="photo sheet__media"><img src="${esc(n.image)}" alt="${esc(n.title)}" referrerpolicy="no-referrer"></div>` : ''}
        <div class="sheet__head">
            <div class="tags" style="gap:6px">${tags}</div>
            <h2 class="heading sheet__title">${esc(n.title)}</h2>
            <p class="muted">${esc([!isHotel && `Day ${n.day + 1}`, n.area].filter(Boolean).join(' · '))}</p>
            <div class="sheet__facts">${facts.map(([i, t]) => `<span class="tag tag--sm"><i class="fa-solid ${i}"></i>${esc(t)}</span>`).join('')}</div>
        </div>
        <div class="sheet__tabs" role="tablist" aria-label="Stop details">
            ${TABS.map(([id, label, icon]) => `<button type="button" class="tab" role="tab" data-action="tab" data-tab="${id}" aria-selected="${id === tab}"><i class="fa-solid ${icon}"></i>${label}${id === 'upgrades' && upgrades.length ? ` (${upgrades.length})` : ''}</button>`).join('')}
        </div>
        <div class="sheet__body" role="tabpanel">${panel(tab, base, n, isHotel, upgrades)}</div>
        <div class="sheet__foot">
            <div class="row" style="gap:6px">
                ${prev ? `<button type="button" class="btn btn--ghost btn--icon" data-action="step" data-id="${esc(prev.id)}" aria-label="Previous stop: ${esc(prev.title)}"><i class="fa-solid fa-chevron-left"></i></button>` : ''}
                ${next ? `<button type="button" class="btn btn--ghost btn--icon" data-action="step" data-id="${esc(next.id)}" aria-label="Next stop: ${esc(next.title)}"><i class="fa-solid fa-chevron-right"></i></button>` : ''}
            </div>
            <div class="sheet__foot-actions">
                ${n.transit ? `<a class="btn btn--outline" href="${esc(directions(n))}" target="_blank" rel="noopener"><i class="fa-solid fa-diamond-turn-right"></i>Directions</a>` : ''}
                <button type="button" class="btn btn--primary" data-action="map"><i class="fa-solid fa-map-location-dot"></i>Open on map</button>
            </div>
        </div>`;
}

function directions(n) {
    const engine = n.transit.mode === 'walk' ? 'fossgis_osrm_foot' : 'fossgis_osrm_car';
    return `https://www.openstreetmap.org/directions?engine=${engine}&route=${n.transit.fromLat}%2C${n.transit.fromLng}%3B${n.lat}%2C${n.lng}`;
}

function panel(tab, base, n, isHotel, upgrades) {
    switch (tab) {
        case 'route': return isHotel ? basecamp(n) : routePanel(base, n);
        case 'reviews': return reviews(n);
        case 'nearby': return nearby(base, n);
        case 'upgrades': return upgradesPanel(upgrades);
        default: return overview(n, isHotel);
    }
}

function overview(n, isHotel) {
    const interests = (n.interests || []).map((id) => INTERESTS.find((i) => i.id === id)).filter(Boolean);
    const rows = [
        [n.type === 'meal' && n.dish ? 'Order this' : null, n.dish],
        ['About', n.summary],
        ['Why this time', n.why],
        ['Insider note', n.tip],
        ['Listed price', n.priceText],
        isHotel ? ['Amenities', (n.amenities || []).join(' · ')] : null,
    ].filter((r) => r && r[0] && r[1]);
    return `<div class="kv">
        ${rows.map(([k, v]) => `<div class="kv__row"><span class="kv__label">${esc(k)}</span><p>${esc(v)}</p></div>`).join('')}
        ${interests.length ? `<div class="kv__row"><span class="kv__label">Good for</span><div class="tags" style="gap:6px">${interests.map((i) => `<span class="tag tag--sm tag--oat"><i class="fa-solid ${i.icon}"></i>${esc(i.label)}</span>`).join('')}</div></div>` : ''}
        ${!rows.length && !interests.length ? '<p class="muted">Nothing more on file for this stop.</p>' : ''}
    </div>`;
}

function routePanel(base, n) {
    const leg = n.transit;
    const cur = currency();
    if (!leg) return '<p class="muted">The day starts here — no travel needed.</p>';
    const group = `leg:${base.id}`;
    const rows = (base.transit.options || []).map((o) => {
        const id = `${group}:${o.mode}`;
        const a = addon(id);
        const current = o.mode === leg.mode;
        const cost = a ? base.transit.cost + a.delta : o.cost;
        let action = current ? '<span class="tag tag--sm tag--dark">Current</span>' : '';
        if (!current && a) action = `<button type="button" class="btn btn--outline btn--sm" data-action="addon" data-addon="${esc(id)}">Switch · ${esc(signed(a.delta, cur))}</button>`;
        if (!current && o.mode === base.transit.mode && state.applied[group]) action = `<button type="button" class="btn btn--outline btn--sm" data-action="addon" data-addon="${esc(state.applied[group])}">Restore</button>`;
        return `<div class="list-row${current ? ' is-current' : ''}">
            <span class="list-row__icon"><i class="fa-solid ${MODE[o.mode]?.icon || 'fa-route'}"></i></span>
            <span><span class="list-row__title">${esc(o.label)}</span><br><span class="list-row__sub">${esc(mins(o.minutes))} · ${esc(km(o.km))} · ${cost > 0 ? esc(money(cost, cur)) : 'free'}</span></span>
            <span class="list-row__end">${action}</span>
        </div>`;
    }).join('');
    return `<div class="kv">
        <div class="kv__row"><span class="kv__label">From</span><p class="strong">${esc(leg.from)}</p></div>
        <div class="kv__row"><span class="kv__label">Best option</span><p>${esc(leg.label)} · ${esc(mins(leg.minutes))} · ${esc(km(leg.km))}${leg.covered ? ` · covered by ${esc(leg.covered)}` : leg.cost > 0 ? ` · ${esc(money(leg.cost, cur))} for the group` : ' · free'}</p>
            <p class="caption muted">${base.transit.routed ? 'Road time from OSRM.' : 'Estimated from distance.'} Chosen for the best mix of fare and time at your pace.</p></div>
        <div class="kv__row"><span class="kv__label">Other ways</span><div class="list">${rows}</div></div>
    </div>`;
}

function basecamp(n) {
    const b = state.plan.budget;
    const cur = currency();
    return `<div class="kv">
        <div class="kv__row"><span class="kv__label">Why here</span><p>${n.centroidKm != null ? `${esc(km(n.centroidKm))} from the middle of your sights` : 'Central to the plan'}${n.hub ? `, ${esc(km(n.hub.km))} from ${esc(n.hub.name)}` : ''} — short first and last legs every day.</p></div>
        <div class="stats">
            <div class="stat"><div class="stat__label">Nightly cap</div><div class="stat__value">${esc(money(b.nightlyCap, cur))}</div></div>
            <div class="stat"><div class="stat__label">This stay</div><div class="stat__value">${esc(money(n.pricePerNight, cur))}</div></div>
            <div class="stat"><div class="stat__label">${n.nights} night${n.nights === 1 ? '' : 's'} total</div><div class="stat__value">${esc(money(n.cost, cur))}</div></div>
        </div>
    </div>`;
}

function reviews(n) {
    const src = { GOOGLE: 'Google reviews', SAMPLE: 'Rating from the offline guide (indicative)', WIKIVOYAGE: 'Listed in Wikivoyage', WIKIPEDIA: 'Notable on Wikipedia', OSM: 'Listed on OpenStreetMap' }[n.ratingSource] || '';
    const list = (n.reviews || []).map((r) => `<div class="review">
        <div class="review__meta"><span class="strong">${r.authorUri ? `<a href="${esc(r.authorUri)}" target="_blank" rel="noopener">${esc(r.author)}</a>` : esc(r.author)}${r.rating ? ` · ★ ${esc(r.rating)}` : ''}</span><span class="muted">${esc(r.relative)}</span></div>
        <p class="review__text">${esc(r.text)}</p>
    </div>`).join('');
    const headline = n.rating != null
        ? `<p class="heading-sm">★ ${Number(n.rating).toFixed(1)} <span class="caption muted">${n.ratingCount ? `${esc(Number(n.ratingCount).toLocaleString('en'))} ratings` : ''}</span></p>`
        : n.popularity ? `<p class="heading-sm">${esc(compact(n.popularity))} <span class="caption muted">Wikipedia reads in the last 30 days</span></p>` : '';
    const search = n.mapsUri || `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${n.title}, ${state.plan.meta.destination}`)}`;
    return `<div class="kv">
        <div class="kv__row"><span class="kv__label">${esc(src)}</span>${headline}</div>
        ${list || `<p class="muted">${(window.WB_BOOT?.integrations?.googlePlaces) ? 'No written reviews came back for this place.' : 'Written reviews appear here when a Google Places key is configured on the server.'}</p>`}
        <a class="btn btn--outline btn--sm" style="justify-self:start" href="${esc(search)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i>See more online</a>
    </div>`;
}

function nearby(base, n) {
    const cur = currency();
    const near = base.nearby || {};
    const swaps = base.type === 'meal'
        ? Object.values(state.plan.addons).filter((a) => a.group === `meal:${base.id}`).sort((a, b) => a.delta - b.delta)
        : [];
    const swapIds = new Set(swaps.map((a) => a.placeId));
    const swapRows = swaps.map((a) => `<div class="list-row${isApplied(a.id) ? ' is-current' : ''}">
        <span class="list-row__icon"><i class="fa-solid fa-utensils"></i></span>
        <span><span class="list-row__title">${esc(a.patch?.title || a.label)}</span><br><span class="list-row__sub">${esc(a.detail)}</span></span>
        <span class="list-row__end"><button type="button" class="btn btn--outline btn--sm" data-action="addon" data-addon="${esc(a.id)}">${isApplied(a.id) ? 'Undo' : `Swap · ${esc(signed(a.delta, cur))}`}</button></span>
    </div>`).join('');
    const food = (near.cuisine || []).filter((e) => !swapIds.has(e.id)).map((e) => `<div class="list-row">
        <span class="list-row__icon"><i class="fa-solid fa-bowl-food"></i></span>
        <span><span class="list-row__title">${esc(e.name)}</span><br><span class="list-row__sub">${esc([e.dish, km(e.km), (e.diet || []).join(' / ')].filter(Boolean).join(' · '))}</span></span>
        <span class="list-row__end caption">${esc(money(e.cost, cur))} pp</span>
    </div>`).join('');
    const markets = (near.markets || []).map((m) => `<div class="list-row">
        <span class="list-row__icon"><i class="fa-solid fa-store"></i></span>
        <span><span class="list-row__title">${esc(m.name)}</span><br><span class="list-row__sub">${esc([m.crafts, km(m.km), m.night ? 'night market' : ''].filter(Boolean).join(' · '))}</span></span>
        <span></span>
    </div>`).join('');
    if (!swapRows && !food && !markets) return `<div class="empty">${foldedMap()}<p>Nothing else listed within walking distance.</p></div>`;
    return `<div class="kv">
        ${swapRows ? `<div class="kv__row"><span class="kv__label">Swap this meal</span><div class="list">${swapRows}</div></div>` : ''}
        ${food ? `<div class="kv__row"><span class="kv__label">Food within 1.6 km</span><div class="list">${food}</div></div>` : ''}
        ${markets ? `<div class="kv__row"><span class="kv__label">Markets within 3 km</span><div class="list">${markets}</div></div>` : ''}
    </div>`;
}

function upgradesFor(base, isHotel) {
    if (isHotel) return Object.values(state.plan.addons).filter((a) => a.group === 'hotel');
    return Object.values(state.plan.addons).filter((a) => a.nodeId === base.id && !a.group.startsWith('leg:'));
}

function upgradesPanel(list) {
    const cur = currency();
    if (!list.length) return `<div class="empty">${foldedMap()}<p>No upgrades for this stop — it’s already a good one.</p></div>`;
    const card = (a) => {
        const on = isApplied(a.id);
        return `<div class="swap${on ? ' is-applied' : ''}">
            <div><p class="swap__title">${esc(a.label)}</p><p class="caption muted mt-8">${esc(a.detail || '')}</p></div>
            <div class="row between"><span class="swap__delta">${esc(signed(a.delta, cur))}</span>
                <button type="button" class="btn btn--sm ${on ? 'btn--outline' : 'btn--dark'}" data-action="addon" data-addon="${esc(a.id)}">${on ? 'Undo' : a.delta > 0 ? 'Add' : 'Save'}</button></div>
        </div>`;
    };
    const ups = list.filter((a) => a.delta > 0).sort((a, b) => b.value - a.value);
    const saves = list.filter((a) => a.delta <= 0).sort((a, b) => a.delta - b.delta);
    return `<div class="kv">
        ${ups.length ? `<div class="kv__row"><span class="kv__label">Make it better</span><div class="swaps">${ups.slice(0, 6).map(card).join('')}</div></div>` : ''}
        ${saves.length ? `<div class="kv__row"><span class="kv__label">Spend less</span><div class="swaps">${saves.slice(0, 4).map(card).join('')}</div></div>` : ''}
    </div>`;
}
