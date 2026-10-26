// Trip dashboard shell: title, a few facts, the tab bar and the active tab.

import { state } from '../store.js';
import { esc, dateLabel, weatherIcon } from '../format.js';
import * as calendar from './calendar.js';
import * as day from './day.js';
import * as map from './map.js';
import * as stay from './stay.js';
import * as budget from './budget.js';
import * as extras from './extras.js';

const TABS = [
    ['calendar', 'Calendar', 'fa-calendar-days', '#/trip'],
    ['map', 'Map', 'fa-map-location-dot', '#/trip/map'],
    ['stay', 'Stay', 'fa-bed', '#/trip/stay'],
    ['budget', 'Budget', 'fa-wallet', '#/trip/budget'],
    ['extras', 'Upgrades', 'fa-wand-magic-sparkles', '#/trip/extras'],
];
const VIEWS = { calendar, day, map, stay, budget, extras };

export function render(root, route) {
    if (!state.plan) {
        location.hash = '#/';
        return;
    }
    const m = state.plan.meta;
    const tab = route.tab === 'day' ? 'calendar' : route.tab;
    const days = state.plan.days;
    const wet = days.filter((d) => d.weather.wet).length;
    const temps = days.map((d) => d.weather.tmax).filter((t) => t != null);

    root.innerHTML = `
        <section class="trip container">
            <header class="trip-head">
                <div>
                    <h1 class="display">${esc(m.destination)}</h1>
                    <div class="trip-head__meta">
                        <span class="tag tag--sm"><i class="fa-solid fa-calendar"></i>${esc(dateLabel(m.startDate))} – ${esc(dateLabel(m.endDate))}</span>
                        <span class="tag tag--sm"><i class="fa-solid fa-user-group"></i>${m.travellers}</span>
                        <span class="tag tag--sm"><i class="fa-solid fa-moon"></i>${m.nights} night${m.nights === 1 ? '' : 's'}</span>
                        ${temps.length ? `<span class="tag tag--sm"><i class="fa-solid ${weatherIcon(days[0].weather.code)}"></i>${Math.min(...temps)}–${Math.max(...temps)}°${wet ? ` · ${wet} wet day${wet > 1 ? 's' : ''}` : ''}</span>` : ''}
                    </div>
                </div>
                <nav class="tabs" aria-label="Trip sections">
                    ${TABS.map(([id, label, icon, href]) => `<a class="tab" href="${href}" ${id === tab ? 'aria-current="page"' : ''}><i class="fa-solid ${icon}"></i>${label}</a>`).join('')}
                </nav>
            </header>
            ${m.warnings.length && tab === 'calendar' && route.tab !== 'day' ? `
                <details class="notice">
                    <summary><i class="fa-solid fa-circle-info"></i> Good to know (${m.warnings.length}) <i class="fa-solid fa-chevron-down" style="font-size:12px"></i></summary>
                    <ul class="caption">${m.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>
                </details>` : ''}
            <div class="trip-body" id="trip-body"></div>
        </section>`;

    const view = VIEWS[route.tab] || calendar;
    return view.render(root.querySelector('#trip-body'), route);
}

/** Whether a store change can be absorbed by the active tab without a full re-render. */
export function canUpdate(route) {
    const view = VIEWS[route.tab];
    return !!view?.update;
}

export function update(route) {
    VIEWS[route.tab]?.update?.(route);
}
