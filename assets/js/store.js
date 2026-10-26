// App state: the trip request, the planned trip, and the add-ons the traveller
// has toggled. Persists to localStorage so a refresh never loses a plan.

const KEY = 'wanderbean.v1';
const BOOT = window.WB_BOOT || { defaults: {} };

/** Food style and stay style set the budget split the planner works with. */
export const FOOD_PCT = { street: 0.16, mix: 0.22, fine: 0.30 };
export const STAY_PCT = { hostel: 0.24, budget: 0.32, comfort: 0.40, boutique: 0.48 };

function fresh() {
    return { request: { ...BOOT.defaults }, plan: null, applied: {}, tierPct: null };
}

function load() {
    try {
        const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (saved && saved.request) return { ...fresh(), ...saved, request: { ...BOOT.defaults, ...saved.request } };
    } catch (_) { /* storage blocked or corrupt: start fresh */ }
    return fresh();
}

export const state = load();
const listeners = new Set();

export function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

export function save() {
    try {
        localStorage.setItem(KEY, JSON.stringify(state));
    } catch (_) {
        // Quota exceeded on a very large plan: keep the request at least.
        try { localStorage.setItem(KEY, JSON.stringify({ ...state, plan: null })); } catch (__) { /* ignore */ }
    }
}

export function emit() {
    save();
    listeners.forEach((fn) => fn());
}

export function setRequest(patch) {
    Object.assign(state.request, patch);
    emit();
}

export function setPlan(plan) {
    state.plan = plan;
    state.applied = {};
    state.tierPct = null;
    emit();
}

export function resetTrip() {
    Object.assign(state, fresh());
    emit();
}

/** Payload for api/plan_trip.php. */
export function payload() {
    const r = state.request;
    return {
        destination: r.destination,
        startDate: r.startDate,
        endDate: r.endDate,
        budget: Number(r.budget),
        currency: r.currency,
        travellers: Number(r.travellers) || 1,
        pace: r.pace,
        interests: r.interests,
        diet: r.diet,
        modes: r.modes,
        stayStyle: r.stayStyle,
        lodgingPct: STAY_PCT[r.stayStyle] ?? 0.32,
        foodPct: FOOD_PCT[r.foodStyle] ?? 0.22,
        offline: !!r.offline,
    };
}

// ─── Add-ons & the live ledger ───────────────────────────────────────────

export const addon = (id) => state.plan?.addons?.[id];
export const isApplied = (id) => { const a = addon(id); return !!a && state.applied[a.group] === id; };
export const appliedList = () => Object.values(state.applied).map(addon).filter(Boolean);
export const tierBy = (pct) => state.plan?.tiers.find((t) => t.pct === pct);
export const allNodes = () => (state.plan?.days || []).flatMap((d) => d.nodes);
export const findNode = (id) => allNodes().find((n) => n.id === id);

/** A node with every applied add-on patched in (swapped venue, new leg, extra cost). */
export function effective(node) {
    if (!node) return node;
    const list = appliedList().filter((a) => a.nodeId === node.id);
    if (!list.length) return node;
    const n = { ...node, tags: [...(node.tags || [])], transit: node.transit ? { ...node.transit } : null, upgraded: true };
    for (const a of list) {
        const p = a.patch || {};
        if (a.affects === 'stop') n.cost = (n.cost || 0) + a.delta;
        for (const k of ['title', 'area', 'summary', 'rating', 'ratingCount', 'image', 'dish', 'style', 'pricePerNight', 'lat', 'lng']) {
            if (p[k] !== undefined && p[k] !== null && p[k] !== '') n[k] = p[k];
        }
        if (p.title) {
            n.reviews = [];
            n.tip = '';
            n.mapsUri = null;
            n.hoursText = [];
            n.popularity = null;
        }
        if (p.tag) n.tags.push(p.tag);
        if (p.transit && n.transit) Object.assign(n.transit, p.transit, { covered: p.transit.cost === 0 ? n.transit.covered : null });
    }
    return n;
}

export function ledger() {
    const b = state.plan.budget;
    const cats = { ...b.ledger };
    for (const a of appliedList()) cats[a.category] = (cats[a.category] || 0) + a.delta;
    const total = Object.values(cats).reduce((s, v) => s + v, 0);
    const boost = state.tierPct ? tierBy(state.tierPct)?.increase || 0 : 0;
    const budget = b.total + boost;
    return { cats, total, budget, boost, buffer: budget - total, over: total > budget + 0.5 };
}

/** Toggle an add-on; returns true when it is now applied. */
export function toggleAddon(id) {
    const a = addon(id);
    if (!a) return false;
    const on = state.applied[a.group] !== id;
    if (on) state.applied[a.group] = id;
    else delete state.applied[a.group];
    emit();
    return on;
}

export function toggleTier(pct) {
    const revert = (t) => t?.addonIds.forEach((id) => { const a = addon(id); if (a && state.applied[a.group] === id) delete state.applied[a.group]; });
    if (state.tierPct === pct) {
        revert(tierBy(pct));
        state.tierPct = null;
    } else {
        if (state.tierPct) revert(tierBy(state.tierPct));
        tierBy(pct).addonIds.forEach((id) => { const a = addon(id); if (a) state.applied[a.group] = id; });
        state.tierPct = pct;
    }
    emit();
    return state.tierPct === pct;
}

export const currency = () => state.plan?.meta.currency || state.request.currency;
export const boot = BOOT;
