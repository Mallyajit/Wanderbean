// Calendar: the trip as a month grid. Each trip day is one card; tapping it
// opens that day's timeline.

import { state, effective, currency } from '../store.js';
import { esc, money, two, sum, parseISO, isoAdd, weatherIcon, nodeIcon } from '../format.js';

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function render(root) {
    const days = state.plan.days;
    const byDate = new Map(days.map((d) => [d.date, d]));
    const first = days[0].date;
    const last = days[days.length - 1].date;

    // Pad to whole Monday–Sunday weeks.
    const lead = (parseISO(first).getUTCDay() + 6) % 7;
    const tail = 6 - ((parseISO(last).getUTCDay() + 6) % 7);
    const cells = [];
    for (let d = isoAdd(first, -lead); d <= isoAdd(last, tail); d = isoAdd(d, 1)) cells.push(d);

    const months = [...new Set(cells.filter((d) => d >= first && d <= last).map((d) => parseISO(d).toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' })))];
    const stops = sum(days, (d) => d.nodes.filter((n) => ['attraction', 'market', 'activity'].includes(n.type)).length);

    root.innerHTML = `
        <div class="card cal">
            <div class="cal__month">
                <h2 class="heading">${esc(months.join(' – '))}</h2>
                <span class="caption muted">${days.length} day${days.length > 1 ? 's' : ''} · ${stops} stops</span>
            </div>
            <div class="cal__grid" role="grid" aria-label="Trip calendar">
                ${DOW.map((d) => `<div class="cal__dow" role="columnheader">${d}</div>`).join('')}
                ${cells.map((iso) => cell(iso, byDate.get(iso))).join('')}
            </div>
            <div class="cal-list">${days.map((d) => cell(d.date, d, true)).join('')}</div>
            <p class="cal__hint caption muted"><i class="fa-solid fa-hand-pointer"></i> Tap a day to see its timeline</p>
        </div>`;
}

function cell(iso, day, listMode = false) {
    const date = parseISO(iso);
    if (!day) {
        return `<div class="cal-day" role="gridcell" aria-hidden="true"><span class="cal-day__date">${date.getUTCDate()}</span></div>`;
    }
    const nodes = day.nodes.map(effective);
    const cost = sum(nodes, (n) => n.cost) + sum(nodes, (n) => n.transit?.cost);
    const stops = nodes.filter((n) => ['attraction', 'market', 'activity'].includes(n.type));
    const icons = [...new Set(stops.map(nodeIcon))].slice(0, 4);
    const w = day.weather;
    const weekday = date.toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });
    const label = `Day ${day.index + 1}, ${date.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })}: ${day.theme}. ${stops.length} stops, ${money(cost, currency())}.`;

    if (listMode) {
        return `<a class="cal-day cal-day--trip" href="#/trip/day/${day.index + 1}" aria-label="${esc(label)}">
            <span><span class="cal-day__date">${date.getUTCDate()}</span><br><span class="caption muted">${weekday}</span></span>
            <span><span class="cal-day__theme">${esc(day.theme)}</span><span class="caption muted">${stops.length} stops · ${esc(money(cost, currency()))}</span></span>
            <span class="tag tag--sm tag--blue cal-day__badge">Day ${day.index + 1}</span>
        </a>`;
    }
    return `<a class="cal-day cal-day--trip" role="gridcell" href="#/trip/day/${day.index + 1}" aria-label="${esc(label)}">
        <span class="cal-day__date">${date.getUTCDate()}</span>
        <span class="tag tag--sm tag--blue cal-day__badge">Day ${two(day.index + 1)}</span>
        <span class="cal-day__wx"><i class="fa-solid ${weatherIcon(w.code)}"></i>${w.tmax != null ? `${w.tmax}°` : esc(w.label === 'Forecast opens 16 days out' ? 'No forecast yet' : w.label)}</span>
        <span class="cal-day__theme">${esc(day.theme)}</span>
        <span class="cal-day__foot">
            <span class="cal-day__icons">${icons.map((i) => `<i class="fa-solid ${i}"></i>`).join('')}</span>
            <span>${esc(money(cost, currency()))}</span>
        </span>
    </a>`;
}
