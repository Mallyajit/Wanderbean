// Landing: what Wanderbean does, and the three things it needs to start.

import { state, setRequest, boot } from '../store.js';
import { esc, todayISO, isoAdd, daysBetween, CURRENCIES } from '../format.js';
import { suggest } from '../api.js';
import { hero, foldedMap, calendarArt, piggy } from '../art.js';

const POPULAR_LIVE = ['Hanoi, Vietnam', 'Istanbul, Türkiye', 'Cusco, Peru', 'Marrakech, Morocco', 'Paris, France', 'Bangkok, Thailand'];

export function render(root) {
    const r = state.request;
    const offline = boot.offlineGuides || [];
    root.innerHTML = `
        <section class="hero container">
            <span class="tag tag--sm hero__kicker"><i class="fa-solid fa-mug-hot"></i>Free trip planner · built on open data</span>
            <h1 class="display-lg hero__title">Trips brewed to your budget.</h1>
            <p class="subheading hero__sub">Pick a place, your dates and what you can spend. We'll plan every day around it — and show what a little more would buy.</p>

            <form class="card search-card" id="plan" novalidate>
                <div class="search-grid">
                    <div class="field field--dest suggest">
                        <label class="field__label" for="dest">Where to?</label>
                        <input class="input" id="dest" name="destination" autocomplete="off" placeholder="City, island or region" value="${esc(r.destination)}"
                            role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="dest-list">
                        <ul class="suggest__list" id="dest-list" role="listbox" hidden></ul>
                    </div>
                    <div class="field">
                        <label class="field__label" for="from">From</label>
                        <input class="input" id="from" name="startDate" type="date" value="${esc(r.startDate)}">
                    </div>
                    <div class="field">
                        <label class="field__label" for="to">To</label>
                        <input class="input" id="to" name="endDate" type="date" value="${esc(r.endDate)}">
                    </div>
                    <div class="field field--budget">
                        <label class="field__label" for="budget">Total budget</label>
                        <div class="input-group">
                            <input class="input" id="budget" name="budget" type="number" min="1" step="any" inputmode="decimal" value="${esc(r.budget)}">
                            <select class="select" name="currency" aria-label="Currency">
                                ${CURRENCIES.map((c) => `<option ${c === r.currency ? 'selected' : ''}>${c}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                </div>
                <div class="search-card__foot">
                    <p class="search-card__error caption" id="plan-error" role="alert"></p>
                    <button class="btn btn--primary btn--lg" type="submit">Start planning <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </form>

            <div class="popular">
                <p class="caption muted">Not sure? Try one of these</p>
                <div class="tags" style="justify-content:center">
                    ${offline.map((d) => `<button type="button" class="tag tag--sm" data-pick="${esc(d)}"><i class="fa-solid fa-bookmark" title="Offline guide — works without internet"></i>${esc(d.split(',')[0])}</button>`).join('')}
                    ${POPULAR_LIVE.map((d) => `<button type="button" class="tag tag--sm" data-pick="${esc(d)}"><i class="fa-solid fa-earth-asia"></i>${esc(d.split(',')[0])}</button>`).join('')}
                </div>
            </div>
            <div class="hero__art">${hero()}</div>
        </section>

        <section class="section container" id="how">
            <div class="section-head">
                <h2 class="display">How it works</h2>
                <p class="subheading">Three small steps. No account, no sign-up.</p>
            </div>
            <div class="how">
                ${howCard(1, piggy(), 'Set the basics', 'Destination, dates and a total budget — that is all we need to start.')}
                ${howCard(2, foldedMap(), 'Tell us how you travel', 'Pace, interests, food and the kind of stay you like. Skip anything you don’t care about.')}
                ${howCard(3, calendarArt(), 'Get your calendar', 'A day-by-day plan you can open, tweak and map — with every cost on the meter up top.')}
            </div>
        </section>

        <section class="section container" id="sources">
            <div class="section-head">
                <h2 class="display">Works almost anywhere</h2>
                <p class="subheading">Every plan is assembled from free, public data — no keys required.</p>
            </div>
            <div class="sources">
                ${source('fa-book-atlas', 'Wikivoyage', 'Sights, food, markets and stays for thousands of destinations.')}
                ${source('fa-brands fa-wikipedia-w', 'Wikipedia', 'Popularity and photos for every landmark.')}
                ${source('fa-map-location-dot', 'OpenStreetMap', 'Maps, geocoding (Nominatim, Photon) and road routes (OSRM).')}
                ${source('fa-cloud-sun', 'Open-Meteo & Frankfurter', 'Forecasts and sunsets, plus live exchange rates.')}
            </div>
        </section>

        <footer class="footer-band">
            <div class="container footer-band__inner">
                <a class="brand" href="#/"><span class="brand__word">Wanderbean</span></a>
                <p class="caption">Prices and hours are estimates — always check before you go. © OpenStreetMap contributors.</p>
            </div>
        </footer>`;

    wireForm(root);
}

function howCard(n, art, title, text) {
    return `<article class="how__card">
        <div class="feature">${art}</div>
        <span class="tag tag--sm tag--oat how__step">Step ${n}</span>
        <h3 class="heading-sm">${esc(title)}</h3>
        <p class="muted mt-8">${esc(text)}</p>
    </article>`;
}

function source(icon, title, text) {
    const cls = icon.startsWith('fa-brands') ? icon : `fa-solid ${icon}`;
    return `<div class="source"><i class="${cls}"></i><div><p class="strong">${esc(title)}</p><p class="caption muted">${esc(text)}</p></div></div>`;
}

function wireForm(root) {
    const form = root.querySelector('#plan');
    const dest = form.querySelector('#dest');
    const list = form.querySelector('#dest-list');
    const err = form.querySelector('#plan-error');
    let items = [];
    let active = -1;
    let timer = 0;
    let ctrl = null;

    const close = () => { list.hidden = true; dest.setAttribute('aria-expanded', 'false'); active = -1; };
    const paint = () => {
        list.innerHTML = items.map((it, i) => `
            <li class="suggest__item" role="option" id="opt-${i}" aria-selected="${i === active}" data-i="${i}">
                <i class="fa-solid ${it.offline ? 'fa-bookmark' : 'fa-location-dot'}"></i>
                <span>${esc(it.name)} <small>${esc([it.region, it.country].filter(Boolean).join(', '))}${it.offline ? ' · works offline' : ''}</small></span>
            </li>`).join('');
        list.hidden = !items.length;
        dest.setAttribute('aria-expanded', String(!!items.length));
        dest.setAttribute('aria-activedescendant', active >= 0 ? `opt-${active}` : '');
    };
    const pick = (it) => { dest.value = it.label; close(); };

    dest.addEventListener('input', () => {
        clearTimeout(timer);
        const q = dest.value.trim();
        if (q.length < 2) { items = []; paint(); return; }
        timer = setTimeout(async () => {
            ctrl?.abort();
            ctrl = new AbortController();
            try {
                items = await suggest(q, ctrl.signal);
                active = -1;
                paint();
            } catch (_) { /* typing on: ignore aborted / failed lookups */ }
        }, 250);
    });
    dest.addEventListener('keydown', (e) => {
        if (list.hidden) return;
        if (e.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); paint(); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { active = Math.max(0, active - 1); paint(); e.preventDefault(); }
        else if (e.key === 'Enter' && active >= 0) { pick(items[active]); e.preventDefault(); }
        else if (e.key === 'Escape') close();
    });
    dest.addEventListener('blur', () => setTimeout(close, 150));
    list.addEventListener('mousedown', (e) => {
        const li = e.target.closest('[data-i]');
        if (li) { e.preventDefault(); pick(items[Number(li.dataset.i)]); }
    });

    root.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
        dest.value = b.dataset.pick;
        dest.focus();
    }));

    // Keep "To" sensible when "From" moves.
    form.startDate.addEventListener('change', () => {
        if (!form.endDate.value || form.endDate.value < form.startDate.value) form.endDate.value = isoAdd(form.startDate.value, 2);
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        [...form.querySelectorAll('[aria-invalid]')].forEach((x) => x.removeAttribute('aria-invalid'));
        const v = {
            destination: dest.value.trim(),
            startDate: form.startDate.value,
            endDate: form.endDate.value,
            budget: Number(form.budget.value),
            currency: form.currency.value,
        };
        const fail = (field, msg) => { form[field].setAttribute('aria-invalid', 'true'); form[field].focus(); err.textContent = msg; };
        if (v.destination.length < 2) return fail('destination', 'Where are you headed? Type a city or pick one below.');
        if (!v.startDate || !v.endDate) return fail(v.startDate ? 'endDate' : 'startDate', 'Choose your travel dates.');
        if (v.startDate < todayISO()) return fail('startDate', 'Pick dates from today onwards.');
        if (v.endDate < v.startDate) return fail('endDate', 'The trip has to end after it starts.');
        if (daysBetween(v.startDate, v.endDate) > (boot.maxDays || 14)) return fail('endDate', `Wanderbean plans up to ${boot.maxDays || 14} days at a time.`);
        if (!(v.budget > 0)) return fail('budget', 'Add a total budget for the whole trip.');
        err.textContent = '';
        setRequest(v);
        location.hash = '#/prefs/1';
    });
}
