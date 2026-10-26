// Preferences wizard: one question per screen, each with its own illustration.

import { state, setRequest, STAY_PCT, FOOD_PCT } from '../store.js';
import { esc, money, daysBetween, INTERESTS, MODE } from '../format.js';
import { STEP_ART } from '../art.js';
import { WIZARD_STEPS } from '../header.js';
import { onAction } from '../ui.js';

const PACES = [
    { id: 'relaxed', icon: 'fa-mug-hot', title: 'Slow & easy', hint: '3 sights a day, 9 am starts, long lunches' },
    { id: 'balanced', icon: 'fa-person-walking', title: 'Balanced', hint: '4 sights a day with time to wander' },
    { id: 'packed', icon: 'fa-bolt', title: 'See it all', hint: '5 sights a day, early starts' },
];
const FOOD = [
    { id: 'street', icon: 'fa-bowl-rice', title: 'Street eats', hint: 'Stalls, cafés and local canteens' },
    { id: 'mix', icon: 'fa-utensils', title: 'A bit of both', hint: 'Cheap lunches, a nicer dinner' },
    { id: 'fine', icon: 'fa-champagne-glasses', title: 'Treat yourself', hint: 'Well-reviewed restaurants' },
];
const STAYS = [
    { id: 'hostel', icon: 'fa-people-roof', title: 'Hostel', hint: 'Dorms & social spaces' },
    { id: 'budget', icon: 'fa-house', title: 'Budget', hint: 'Simple, clean guesthouses' },
    { id: 'comfort', icon: 'fa-hotel', title: 'Comfort', hint: 'Mid-range hotels' },
    { id: 'boutique', icon: 'fa-gem', title: 'Boutique', hint: 'Design & heritage stays' },
];
const GROUPS = [
    { n: 1, label: 'Solo', icon: 'fa-user' },
    { n: 2, label: 'Couple', icon: 'fa-user-group' },
    { n: 4, label: 'Family', icon: 'fa-children' },
    { n: 6, label: 'Group', icon: 'fa-people-group' },
];
const DIETS = [
    { id: 'vegetarian', label: 'Vegetarian', icon: 'fa-carrot' },
    { id: 'vegan', label: 'Vegan', icon: 'fa-seedling' },
    { id: 'halal', label: 'Halal', icon: 'fa-moon' },
];

const STEPS = [
    { key: 'travellers', q: 'Who’s coming?', sub: 'Rooms, tickets and taxis are priced for the whole party.' },
    { key: 'pace', q: 'What’s your pace?', sub: 'How full should each day be?' },
    { key: 'interests', q: 'What do you love?', sub: 'Pick as many as you like — we’ll favour these sights.' },
    { key: 'food', q: 'How do you eat?', sub: 'Sets how much of the budget goes to meals.' },
    { key: 'stay', q: 'Where do you sleep?', sub: 'We look for a well-rated stay near the sights, within this style.' },
    { key: 'transit', q: 'Getting around', sub: 'We pick the cheapest sensible way between stops from these.' },
];

export function render(root, route) {
    const step = Math.min(Math.max(1, route.step || 1), WIZARD_STEPS);
    if (!state.request.destination) {
        location.hash = '#/';
        return;
    }
    let lastStep = null;

    const paint = () => {
        const s = STEPS[step - 1];
        const animate = lastStep !== step;
        lastStep = step;
        root.innerHTML = `
            <section class="wizard container">
                <div class="wizard__answers">${answers(step)}</div>
                <div class="wizard__grid">
                    <div class="wizard__art" aria-hidden="true">${STEP_ART[s.key]()}</div>
                    <div class="card wizard__panel${animate ? ' wizard__step' : ''}">
                        <div>
                            <p class="caption muted">Planning ${esc(state.request.destination.split(',')[0])} · step ${step} of ${WIZARD_STEPS}</p>
                            <h1 class="display wizard__q mt-8">${esc(s.q)}</h1>
                            <p class="subheading muted mt-8">${esc(s.sub)}</p>
                        </div>
                        ${body(s.key)}
                        <div class="wizard__nav">
                            ${step > 1 ? `<a class="btn btn--outline" href="#/prefs/${step - 1}"><i class="fa-solid fa-arrow-left"></i>Back</a>` : `<a class="btn btn--outline" href="#/"><i class="fa-solid fa-arrow-left"></i>Back</a>`}
                            <div class="wizard__nav-right">
                                ${step < WIZARD_STEPS ? `<a class="btn btn--ghost" href="#/prefs/${step + 1}">Skip</a>` : ''}
                                ${step < WIZARD_STEPS
                                    ? `<a class="btn btn--primary" href="#/prefs/${step + 1}">Next <i class="fa-solid fa-arrow-right"></i></a>`
                                    : `<a class="btn btn--primary btn--lg" href="#/brewing"><i class="fa-solid fa-mug-hot"></i>Brew my trip</a>`}
                            </div>
                        </div>
                    </div>
                </div>
            </section>`;
    };

    const r = state.request;
    const off = onAction(root, {
        count: (el) => { setRequest({ travellers: Math.max(1, Math.min(12, r.travellers + Number(el.dataset.d))) }); paint(); },
        group: (el) => { setRequest({ travellers: Number(el.dataset.n) }); paint(); },
        pace: (el) => { setRequest({ pace: el.dataset.id }); paint(); },
        interest: (el) => { setRequest({ interests: toggle(r.interests, el.dataset.id) }); paint(); },
        food: (el) => { setRequest({ foodStyle: el.dataset.id }); paint(); },
        diet: (el) => { setRequest({ diet: toggle(r.diet, el.dataset.id) }); paint(); },
        stay: (el) => { setRequest({ stayStyle: el.dataset.id }); paint(); },
        mode: (el) => {
            const next = toggle(r.modes, el.dataset.id);
            setRequest({ modes: next.length ? next : ['taxi'] });
            paint();
        },
        offline: () => { setRequest({ offline: !r.offline }); paint(); },
    });
    paint();
    return off;
}

function toggle(list, id) {
    return list.includes(id) ? list.filter((x) => x !== id) : [...list, id];
}

function option(action, o, checked) {
    return `<button type="button" class="option" role="radio" aria-checked="${checked}" data-action="${action}" data-id="${o.id}">
        <span class="option__icon"><i class="fa-solid ${o.icon}"></i></span>
        <span class="option__title">${esc(o.title)}</span>
        <span class="option__hint">${esc(o.hint)}</span>
    </button>`;
}

function body(key) {
    const r = state.request;
    switch (key) {
        case 'travellers':
            return `<div class="stack" style="gap:16px">
                <div class="counter" role="group" aria-label="Travellers">
                    <button type="button" class="btn btn--outline btn--icon" data-action="count" data-d="-1" aria-label="One fewer"><i class="fa-solid fa-minus"></i></button>
                    <span class="counter__value" aria-live="polite">${r.travellers}</span>
                    <button type="button" class="btn btn--outline btn--icon" data-action="count" data-d="1" aria-label="One more"><i class="fa-solid fa-plus"></i></button>
                </div>
                <div class="tags">${GROUPS.map((g) => `<button type="button" class="tag" data-action="group" data-n="${g.n}" aria-pressed="${r.travellers === g.n}"><i class="fa-solid ${g.icon}"></i>${g.label}</button>`).join('')}</div>
            </div>`;
        case 'pace':
            return `<div class="options" role="radiogroup" aria-label="Pace">${PACES.map((o) => option('pace', o, r.pace === o.id)).join('')}</div>`;
        case 'interests':
            return `<div class="tags" role="group" aria-label="Interests">${INTERESTS.map((i) => `<button type="button" class="tag" data-action="interest" data-id="${i.id}" aria-pressed="${r.interests.includes(i.id)}"><i class="fa-solid ${i.icon}"></i>${esc(i.label)}</button>`).join('')}</div>`;
        case 'food': {
            const days = daysBetween(r.startDate, r.endDate);
            const perDay = r.budget * (FOOD_PCT[r.foodStyle] ?? 0.22) / days / r.travellers;
            return `<div class="options" role="radiogroup" aria-label="Food style">${FOOD.map((o) => option('food', o, r.foodStyle === o.id)).join('')}</div>
                <p class="caption muted">≈ <b>${esc(money(perDay, r.currency))}</b> per person per day for food.</p>
                <div><p class="caption strong" style="margin-bottom:8px">Any dietary needs?</p>
                    <div class="tags">${DIETS.map((d) => `<button type="button" class="tag tag--sm" data-action="diet" data-id="${d.id}" aria-pressed="${r.diet.includes(d.id)}"><i class="fa-solid ${d.icon}"></i>${d.label}</button>`).join('')}</div>
                </div>`;
        }
        case 'stay': {
            const nights = Math.max(1, daysBetween(r.startDate, r.endDate) - 1);
            const cap = r.budget * (STAY_PCT[r.stayStyle] ?? 0.32) / nights;
            return `<div class="options" role="radiogroup" aria-label="Stay style">${STAYS.map((o) => option('stay', o, r.stayStyle === o.id)).join('')}</div>
                <p class="caption muted">${daysBetween(r.startDate, r.endDate) === 1 ? 'Day trip — no stay needed, this only shapes the plan if you add nights.' : `Up to <b>${esc(money(cap, r.currency))}</b> a night for ${nights} night${nights > 1 ? 's' : ''}.`}</p>`;
        }
        case 'transit':
            return `<div class="tags" role="group" aria-label="Transit modes">${Object.entries(MODE).map(([id, m]) => `<button type="button" class="tag" data-action="mode" data-id="${id}" aria-pressed="${r.modes.includes(id)}"><i class="fa-solid ${m.icon}"></i>${esc(m.label)}</button>`).join('')}</div>
                <label class="switch mt-8"><input type="checkbox" data-action="offline" ${r.offline ? 'checked' : ''}><span><b>Offline mode</b> <span class="caption muted">— use only the bundled guides, no network calls</span></span></label>`;
        default:
            return '';
    }
}

function answers(step) {
    const r = state.request;
    const chips = [
        `<i class="fa-solid fa-user-group"></i>${r.travellers} traveller${r.travellers > 1 ? 's' : ''}`,
        `<i class="fa-solid fa-gauge"></i>${esc(PACES.find((p) => p.id === r.pace)?.title || '')}`,
        `<i class="fa-solid fa-heart"></i>${r.interests.length ? `${r.interests.length} interest${r.interests.length > 1 ? 's' : ''}` : 'Any sights'}`,
        `<i class="fa-solid fa-utensils"></i>${esc(FOOD.find((f) => f.id === r.foodStyle)?.title || '')}`,
        `<i class="fa-solid fa-bed"></i>${esc(STAYS.find((s) => s.id === r.stayStyle)?.title || '')}`,
    ];
    return `<span class="tag tag--sm tag--dark"><i class="fa-solid fa-location-dot"></i>${esc(r.destination.split(',')[0])}</span>`
        + chips.slice(0, step - 1).map((c, i) => `<a class="tag tag--sm" href="#/prefs/${i + 1}">${c}</a>`).join('');
}
