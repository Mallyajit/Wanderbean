// Map: Leaflet + OpenStreetMap tiles, numbered pins for each
// stop, the basecamp, and road routes from OSRM.

import { state, effective } from '../store.js';
import { esc, typeLabel, nodeIcon } from '../format.js';
import { onAction } from '../ui.js';
import { route as fetchRoute } from '../api.js';
import { openEvent } from './eventSheet.js';

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors · routes OSRM';

const mappable = (n) => !['pause', 'return'].includes(n.type);

/**
 * Build a map inside `el`. dayIndex = null shows every day.
 * Returns { focus(id), destroy() }.
 */
export function createMap(el, { dayIndex = null, compact = false, focusId = null, onSelect = openEvent } = {}) {
    if (!el || !window.L) {
        if (el) el.innerHTML = '<div class="empty"><p>The map library could not load — check your connection.</p></div>';
        return null;
    }
    const L = window.L;
    const map = L.map(el, { zoomControl: !compact, scrollWheelZoom: !compact, attributionControl: true });
    L.tileLayer(TILES, { attribution: ATTRIBUTION, maxZoom: 19 }).addTo(map);

    const plan = state.plan;
    const markers = new Map();
    const bounds = [];
    let destroyed = false;
    const hotel = plan.hotel ? effective(plan.hotel) : null;

    if (hotel) {
        const m = L.marker([hotel.lat, hotel.lng], {
            icon: L.divIcon({ className: '', html: '<div class="pin pin--home"><i class="fa-solid fa-bed"></i></div>', iconSize: [34, 34], iconAnchor: [17, 17] }),
            title: hotel.title,
            keyboard: true,
        }).addTo(map);
        m.bindPopup(popup(hotel, 'Your stay', 'hotel'));
        markers.set('hotel', m);
        bounds.push([hotel.lat, hotel.lng]);
    }

    const days = dayIndex == null ? plan.days : [plan.days[dayIndex]];
    days.forEach((day) => {
        const stops = day.nodes.filter(mappable).map(effective);
        stops.forEach((n, i) => {
            const label = dayIndex == null ? `${day.index + 1}.${i + 1}` : String(i + 1);
            const m = L.marker([n.lat, n.lng], {
                icon: L.divIcon({ className: '', html: `<div class="pin" data-pin="${esc(n.id)}">${label}</div>`, iconSize: [34, 34], iconAnchor: [17, 17] }),
                title: n.title,
                keyboard: true,
                riseOnHover: true,
            }).addTo(map);
            m.bindPopup(popup(n, `${typeLabel(n)} · Day ${day.index + 1} · ${n.start}`, n.id));
            markers.set(n.id, m);
            bounds.push([n.lat, n.lng]);
        });

        // Road route: basecamp → stops → basecamp.
        const pts = [...(hotel ? [hotel] : []), ...stops, ...(hotel ? [hotel] : [])].map((p) => ({ lat: p.lat, lng: p.lng }));
        if (pts.length >= 2) {
            fetchRoute(pts).then((line) => {
                if (destroyed) return;
                L.polyline(line, { color: '#202020', weight: dayIndex == null ? 3 : 4, opacity: dayIndex == null ? 0.55 : 0.85, dashArray: dayIndex == null ? '6 8' : null, lineCap: 'round' }).addTo(map);
            });
        }
    });

    if (bounds.length) map.fitBounds(bounds, { padding: [36, 36], maxZoom: 15 });
    else map.setView([plan.city.lat, plan.city.lng], 13);

    let active = null;
    const setActive = (id) => {
        el.querySelectorAll('.pin--active').forEach((p) => p.classList.remove('pin--active'));
        el.querySelector(`[data-pin="${CSS.escape(id)}"]`)?.classList.add('pin--active');
        active = id;
    };
    map.on('popupopen', (e) => {
        const id = [...markers].find(([, m]) => m === e.popup._source)?.[0];
        if (id) setActive(id);
        e.popup.getElement()?.querySelector('[data-details]')?.addEventListener('click', (ev) => {
            ev.preventDefault();
            onSelect(ev.currentTarget.dataset.details);
        });
    });

    const api = {
        focus(id) {
            const m = markers.get(id);
            if (!m) return;
            map.flyTo(m.getLatLng(), Math.max(map.getZoom(), 15), { duration: 0.6 });
            m.openPopup();
            setActive(id);
        },
        destroy() { destroyed = true; map.remove(); },
        get active() { return active; },
    };
    if (focusId) setTimeout(() => api.focus(focusId), 350);
    // Containers that start hidden or resize need a nudge.
    setTimeout(() => map.invalidateSize(), 60);
    return api;
}

function popup(n, kicker, id) {
    return `<div><span class="caption muted">${esc(kicker)}</span><br><b>${esc(n.title)}</b>
        <div class="popup-actions"><button type="button" class="btn btn--dark btn--sm" data-details="${esc(id)}">Details</button></div></div>`;
}

// ─── Map tab ─────────────────────────────────────────────────────────────

let instance = null;

export function render(root, route) {
    const days = state.plan.days;
    const dayIndex = route.day ? Math.min(route.day, days.length) - 1 : null;
    const list = (dayIndex == null ? days : [days[dayIndex]]).flatMap((d) =>
        d.nodes.filter(mappable).map(effective).map((n, i) => ({ n, label: dayIndex == null ? `${d.index + 1}.${i + 1}` : String(i + 1), day: d.index })));

    root.innerHTML = `
        <div class="map-layout">
            <aside class="card map-panel" aria-label="Stops">
                <div>
                    <p class="strong">Show</p>
                    <div class="tags mt-8" style="gap:6px">
                        <a class="tag tag--sm${dayIndex == null ? ' is-active' : ''}" href="#/trip/map">All days</a>
                        ${days.map((d) => `<a class="tag tag--sm${dayIndex === d.index ? ' is-active' : ''}" href="#/trip/map/${d.index + 1}">Day ${d.index + 1}</a>`).join('')}
                    </div>
                </div>
                ${state.plan.hotel ? `<button type="button" class="map-stop" data-action="focus" data-id="hotel"><span class="map-stop__n" style="background:#202020;color:#fff"><i class="fa-solid fa-bed"></i></span><span><span class="strong">${esc(effective(state.plan.hotel).title)}</span><br><span class="caption muted">Your stay</span></span></button>` : ''}
                <div class="list">
                    ${list.map(({ n, label, day }) => `<button type="button" class="map-stop" data-action="focus" data-id="${esc(n.id)}">
                        <span class="map-stop__n">${label}</span>
                        <span style="min-width:0"><span class="strong" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(n.title)}</span><span class="caption muted"><i class="fa-solid ${nodeIcon(n)}"></i> ${esc(typeLabel(n))} · ${dayIndex == null ? `Day ${day + 1} · ` : ''}${esc(n.start)}</span></span>
                    </button>`).join('')}
                </div>
            </aside>
            <div class="card map-canvas" id="map-canvas" aria-label="Trip map"></div>
        </div>`;

    instance = createMap(root.querySelector('#map-canvas'), { dayIndex, focusId: route.focus });
    const off = onAction(root, {
        focus: (el) => {
            root.querySelectorAll('.map-stop.is-active').forEach((b) => b.classList.remove('is-active'));
            el.classList.add('is-active');
            instance?.focus(el.dataset.id);
            if (window.innerWidth < 900) root.querySelector('#map-canvas').scrollIntoView({ behavior: 'smooth', block: 'center' });
        },
    });
    if (route.focus) root.querySelector(`.map-stop[data-id="${CSS.escape(route.focus)}"]`)?.classList.add('is-active');
    return () => { off(); instance?.destroy(); instance = null; };
}

/** Budget changes don't move pins: keep the map as it is. */
export function update() {}
