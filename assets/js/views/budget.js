// Budget: hero figure + meter, where the money goes (sorted bars), and spend
// per day (columns). One hue throughout — identity comes from labels, so no
// chart ever relies on telling colours apart.

import { state, ledger, effective, currency } from '../store.js';
import { esc, money, compact, sum, dateLabel } from '../format.js';

const CATS = [
    ['accommodation', 'Stay', 'fa-bed'],
    ['food', 'Food', 'fa-utensils'],
    ['entry', 'Tickets', 'fa-ticket'],
    ['activities', 'Markets & fun', 'fa-masks-theater'],
    ['transit', 'Getting around', 'fa-bus'],
];

let resizeObs = null;

export function render(root) {
    const L = ledger();
    const cur = currency();
    const m = state.plan.meta;
    const pct = Math.min(100, (L.total / L.budget) * 100);
    const perPerson = L.total / m.travellers;
    const perDay = L.total / m.days;
    const cats = CATS.map(([id, label, icon]) => ({ id, label, icon, value: Math.max(0, L.cats[id] || 0) })).sort((a, b) => b.value - a.value);
    const maxCat = Math.max(...cats.map((c) => c.value), 1);

    root.innerHTML = `
        <div class="budget-grid">
            <section class="card stack" aria-labelledby="spent-h">
                <div>
                    <p class="caption strong" id="spent-h">Planned spend</p>
                    <p class="hero-figure mt-8">${esc(money(L.total, cur))}</p>
                    <p class="muted mt-8">of ${esc(money(L.budget, cur))} budget${L.boost ? ` (incl. ${esc(money(L.boost, cur))} boost)` : ''}</p>
                    <div class="meter__track big-meter" role="meter" aria-valuemin="0" aria-valuemax="${Math.round(L.budget)}" aria-valuenow="${Math.round(L.total)}" aria-label="Share of budget used"><span class="meter__fill" style="width:${pct.toFixed(1)}%;${L.over ? 'background:#202020' : ''}"></span></div>
                    <p class="mt-16 strong">${L.over
                        ? `<i class="fa-solid fa-triangle-exclamation"></i> Over budget by ${esc(money(L.total - L.budget, cur))} — undo an upgrade or apply a saving.`
                        : `<i class="fa-solid fa-circle-check"></i> ${esc(money(L.buffer, cur))} left as a buffer.`}</p>
                </div>
                <div class="stats">
                    <div class="stat"><div class="stat__label">Per person</div><div class="stat__value">${esc(money(perPerson, cur))}</div></div>
                    <div class="stat"><div class="stat__label">Per day</div><div class="stat__value">${esc(money(perDay, cur))}</div></div>
                    <div class="stat"><div class="stat__label">Food per person / day</div><div class="stat__value">${esc(money((L.cats.food || 0) / m.travellers / m.days, cur))}</div></div>
                </div>
                ${state.plan.budget.fitLog.length ? `<details class="notice"><summary><i class="fa-solid fa-scale-balanced"></i> ${state.plan.budget.fitLog.length} swaps were made to fit your budget</summary><ul class="caption">${state.plan.budget.fitLog.map((f) => `<li>${esc(f)}</li>`).join('')}</ul></details>` : ''}
            </section>

            <section class="card" aria-labelledby="cats-h">
                <p class="chart-title" id="cats-h">Where the money goes</p>
                <p class="chart-sub">Sorted by size · share of the planned spend</p>
                <div class="hbars" role="list">
                    ${cats.map((c) => `
                        <div class="hbar" role="listitem" tabindex="0" aria-label="${esc(`${c.label}: ${money(c.value, cur)}, ${share(c.value, L.total)} of spend`)}">
                            <span class="hbar__label"><i class="fa-solid ${c.icon}"></i>${esc(c.label)}</span>
                            <span class="hbar__track">
                                <span class="hbar__bar" style="width:${(c.value / maxCat * 78).toFixed(2)}%"></span>
                                <span class="hbar__value">${esc(money(c.value, cur))} <span class="muted">· ${share(c.value, L.total)}</span></span>
                            </span>
                        </div>`).join('')}
                </div>
            </section>
        </div>

        <section class="card mt-24" aria-labelledby="days-h">
            <div class="row between">
                <div>
                    <p class="chart-title" id="days-h">Spend by day</p>
                    <p class="chart-sub">Tickets, meals, markets and transport — the stay is counted separately</p>
                </div>
                <a class="btn btn--outline btn--sm" href="#/trip/extras"><i class="fa-solid fa-wand-magic-sparkles"></i>See upgrades</a>
            </div>
            <div class="cols" id="cols"></div>
            <details class="mt-16">
                <summary class="caption strong" style="cursor:pointer">Show as a table</summary>
                <table class="table">
                    <thead><tr><th>Day</th><th>Theme</th><th>Spend</th></tr></thead>
                    <tbody>${dayTotals().map((d) => `<tr><td>Day ${d.n} · ${esc(dateLabel(d.date))}</td><td>${esc(d.theme)}</td><td>${esc(money(d.value, cur))}</td></tr>`).join('')}</tbody>
                </table>
            </details>
        </section>`;

    const host = root.querySelector('#cols');
    const draw = () => drawColumns(host, dayTotals(), cur);
    draw();
    resizeObs?.disconnect();
    resizeObs = new ResizeObserver(() => draw());
    resizeObs.observe(host);
    return () => { resizeObs?.disconnect(); resizeObs = null; };
}

const share = (v, total) => `${total > 0 ? Math.round((v / total) * 100) : 0}%`;

function dayTotals() {
    return state.plan.days.map((d) => {
        const nodes = d.nodes.map(effective);
        return { n: d.index + 1, date: d.date, theme: d.theme, value: sum(nodes, (x) => x.cost) + sum(nodes, (x) => x.transit?.cost) };
    });
}

/** Column chart: ≤24px columns, 4px rounded data end, square at the baseline. */
function drawColumns(host, data, cur) {
    const W = Math.max(280, host.clientWidth);
    const H = 240;
    const pad = { t: 24, r: 8, b: 28, l: 48 };
    const max = Math.max(...data.map((d) => d.value), 1);
    const step = niceStep(max / 4);
    const top = Math.ceil(max / step) * step;
    const y = (v) => pad.t + (H - pad.t - pad.b) * (1 - v / top);
    const band = (W - pad.l - pad.r) / data.length;
    const bw = Math.min(24, band * 0.5);
    const peak = data.reduce((a, b) => (b.value > a.value ? b : a), data[0]);

    const grid = [];
    for (let v = 0; v <= top + 1e-9; v += step) {
        grid.push(`<line class="grid-line" x1="${pad.l}" x2="${W - pad.r}" y1="${y(v)}" y2="${y(v)}"/>
                   <text class="axis-label" x="${pad.l - 8}" y="${y(v) + 4}" text-anchor="end">${esc(compact(v))}</text>`);
    }
    const cols = data.map((d, i) => {
        const cx = pad.l + band * i + band / 2;
        const x = cx - bw / 2;
        const yy = y(d.value);
        const h = Math.max(0, H - pad.b - yy);
        const r = Math.min(4, h);
        const path = h > 0
            ? `M${x},${H - pad.b} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + bw - r} Q${x + bw},${yy} ${x + bw},${yy + r} V${H - pad.b} Z`
            : '';
        return `<rect class="col-hit" x="${pad.l + band * i}" y="${pad.t}" width="${band}" height="${H - pad.t - pad.b}" tabindex="0" data-i="${i}" aria-label="Day ${d.n}: ${esc(money(d.value, cur))}"/>
            <path class="col" d="${path}"/>
            <text class="axis-label" x="${cx}" y="${H - 8}" text-anchor="middle">Day ${d.n}</text>
            ${d === peak ? `<text class="val-label" x="${cx}" y="${yy - 8}" text-anchor="middle">${esc(money(d.value, cur))}</text>` : ''}`;
    }).join('');

    host.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="Spend per day">${grid.join('')}<line class="grid-line" x1="${pad.l}" x2="${W - pad.r}" y1="${H - pad.b}" y2="${H - pad.b}" style="stroke:#9a9ca1"/>${cols}</svg><div class="chart-tip" hidden></div>`;

    // Tooltip: value leads, label follows; built with textContent.
    const tip = host.querySelector('.chart-tip');
    const show = (el) => {
        const d = data[Number(el.dataset.i)];
        const b = el.getBBox();
        tip.replaceChildren();
        const strong = document.createElement('b');
        strong.textContent = money(d.value, cur);
        tip.append(strong, document.createTextNode(`Day ${d.n} · ${d.theme}`));
        tip.style.left = `${b.x + b.width / 2}px`;
        tip.style.top = `${y(d.value)}px`;
        tip.hidden = false;
    };
    host.querySelectorAll('.col-hit').forEach((el) => {
        el.addEventListener('pointerenter', () => show(el));
        el.addEventListener('focus', () => show(el));
        el.addEventListener('pointerleave', () => { tip.hidden = true; });
        el.addEventListener('blur', () => { tip.hidden = true; });
    });
}

function niceStep(raw) {
    const p = 10 ** Math.floor(Math.log10(Math.max(raw, 1)));
    const f = raw / p;
    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
}
