// Brewing: runs the planner and narrates what it is doing.

import { state, setPlan, payload } from '../store.js';
import { esc } from '../format.js';
import { planTrip } from '../api.js';
import { brewCup } from '../art.js';

const STEPS = [
    ['fa-location-crosshairs', 'Finding your destination'],
    ['fa-book-atlas', 'Reading the guidebooks'],
    ['fa-ranking-star', 'Ranking sights by what you love'],
    ['fa-bed', 'Choosing a stay near the sights'],
    ['fa-object-group', 'Grouping sights into days'],
    ['fa-cloud-sun', 'Checking the forecast & sunsets'],
    ['fa-route', 'Routing every leg'],
    ['fa-scale-balanced', 'Balancing the budget'],
];

export function render(root) {
    if (!state.request.destination) {
        location.hash = '#/';
        return;
    }
    const ctrl = new AbortController();
    let i = 0;
    root.innerHTML = `
        <section class="brew container" aria-live="polite">
            ${brewCup()}
            <h1 class="display">Brewing your trip…</h1>
            <p class="subheading muted">${esc(state.request.destination.split(',')[0])} · this takes a few seconds the first time.</p>
            <ol class="card brew__steps">${STEPS.map(([icon, label]) => `<li><i class="fa-solid ${icon}"></i>${esc(label)}</li>`).join('')}</ol>
        </section>`;
    const items = [...root.querySelectorAll('.brew__steps li')];
    const tick = () => {
        items.forEach((li, k) => {
            li.className = k < i ? 'is-done' : k === i ? 'is-now' : '';
            li.querySelector('i').className = `fa-solid ${k < i ? 'fa-circle-check' : STEPS[k][0]}`;
        });
        i = Math.min(i + 1, STEPS.length - 1);
    };
    tick();
    const timer = setInterval(tick, 700);

    planTrip(payload(), ctrl.signal)
        .then((plan) => {
            setPlan(plan);
            location.hash = '#/trip';
        })
        .catch((err) => {
            if (ctrl.signal.aborted) return;
            root.innerHTML = `
                <section class="card error-card container">
                    ${brewCup()}
                    <h1 class="heading">That one didn’t brew</h1>
                    <p class="muted">${esc(err.message)}</p>
                    <div class="row" style="justify-content:center">
                        <a class="btn btn--outline" href="#/"><i class="fa-solid fa-pen"></i>Change destination</a>
                        <a class="btn btn--primary" href="#/brewing" data-retry><i class="fa-solid fa-rotate-right"></i>Try again</a>
                    </div>
                </section>`;
            root.querySelector('[data-retry]').addEventListener('click', (e) => {
                e.preventDefault();
                render(root);
            });
        })
        .finally(() => clearInterval(timer));

    return () => {
        ctrl.abort();
        clearInterval(timer);
    };
}
