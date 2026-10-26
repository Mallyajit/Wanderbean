// Wanderbean — hash router and app bootstrap.
//
//   #/                    landing
//   #/prefs/:step         preference wizard (1–6)
//   #/brewing             planner running
//   #/trip                calendar
//   #/trip/day/:n         one day's timeline
//   #/trip/map[/:n]       map (optionally one day; ?focus=<stop id>)
//   #/trip/stay | budget | extras

import { subscribe } from './store.js';
import { renderHeader } from './header.js';
import { closeSheet } from './ui.js';
import * as landing from './views/landing.js';
import * as wizard from './views/wizard.js';
import * as brewing from './views/brewing.js';
import * as trip from './views/trip.js';

const VIEWS = { landing, prefs: wizard, brewing, trip };
const main = document.getElementById('view');
let route = parse();
let cleanup = null;
let pendingScroll = null;

function parse() {
    const raw = location.hash.replace(/^#/, '') || '/';
    const [path, query = ''] = raw.split('?');
    const q = new URLSearchParams(query);
    const p = path.split('/').filter(Boolean);
    if (p[0] === 'prefs') return { name: 'prefs', step: Number(p[1]) || 1 };
    if (p[0] === 'brewing') return { name: 'brewing' };
    if (p[0] === 'trip') {
        const tab = p[1] || 'calendar';
        if (tab === 'day') return { name: 'trip', tab: 'day', day: Number(p[2]) || 1 };
        if (tab === 'map') return { name: 'trip', tab: 'map', day: p[2] ? Number(p[2]) : null, focus: q.get('focus') };
        return { name: 'trip', tab: ['stay', 'budget', 'extras'].includes(tab) ? tab : 'calendar' };
    }
    return { name: 'landing' };
}

function mount() {
    const prev = route;
    route = parse();
    cleanup?.();
    cleanup = null;
    closeSheet();
    renderHeader(route);
    cleanup = VIEWS[route.name].render(main, route) || null;

    if (pendingScroll) {
        document.getElementById(pendingScroll)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        pendingScroll = null;
    } else if (!(prev.name === 'trip' && route.name === 'trip' && prev.tab === route.tab && route.tab === 'map')) {
        window.scrollTo(0, 0);
    }
    // Move focus to the new screen for keyboard and screen-reader users.
    main.focus({ preventScroll: true });
}

// Add-ons, tiers and swaps change the ledger: refresh what shows money.
subscribe(() => {
    renderHeader(route);
    if (route.name !== 'trip') return;
    if (trip.canUpdate(route)) {
        trip.update(route);
        return;
    }
    const y = window.scrollY;
    cleanup?.();
    cleanup = trip.render(main, route) || null;
    window.scrollTo(0, y);
});

// Header links that jump to a landing-page section.
document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-scroll]');
    if (!link) return;
    e.preventDefault();
    const id = link.dataset.scroll;
    if (route.name === 'landing') {
        document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (id === 'plan') document.getElementById('dest')?.focus({ preventScroll: true });
    } else {
        pendingScroll = id;
        location.hash = '#/';
    }
});

const header = document.getElementById('app-header');
window.addEventListener('scroll', () => header.classList.toggle('is-scrolled', window.scrollY > 8), { passive: true });
window.addEventListener('hashchange', mount);
mount();
