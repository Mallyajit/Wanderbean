// Thin client for the PHP endpoints.

async function getJSON(url, init) {
    let res;
    try {
        res = await fetch(url, init);
    } catch (_) {
        throw new Error('Could not reach the Wanderbean server. Is it running?');
    }
    const json = await res.json().catch(() => null);
    if (!res.ok || !json || json.ok === false) {
        throw new Error(json?.error || `The server answered HTTP ${res.status}.`);
    }
    return json;
}

export function planTrip(payload, signal) {
    return getJSON('api/plan_trip.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
        signal,
    });
}

export async function suggest(q, signal) {
    const r = await getJSON(`api/suggest.php?q=${encodeURIComponent(q)}`, { signal });
    return r.results || [];
}

const routeCache = new Map();
/** Road geometry ([lat, lng] pairs) through the given points, via OSRM. */
export async function route(points) {
    const key = points.map((p) => `${p.lng.toFixed(5)},${p.lat.toFixed(5)}`).join(';');
    if (!routeCache.has(key)) {
        routeCache.set(key, getJSON(`api/route.php?c=${encodeURIComponent(key)}`)
            .then((r) => r.coordinates.map(([lng, lat]) => [lat, lng]))
            .catch(() => points.map((p) => [p.lat, p.lng])));
    }
    return routeCache.get(key);
}
