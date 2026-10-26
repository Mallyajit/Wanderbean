// Formatting, icon vocabulary and small pure helpers shared by every view.

export function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const moneyFormats = {};
export function money(value, currency) {
    if (!moneyFormats[currency]) {
        moneyFormats[currency] = new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', {
            style: 'currency', currency, maximumFractionDigits: 0, minimumFractionDigits: 0,
        });
    }
    return moneyFormats[currency].format(Math.round(value || 0));
}
export const signed = (v, cur) => (v >= 0 ? '+' : '−') + money(Math.abs(v), cur);
export const compact = (n) => new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
export const km = (v) => (v < 1 ? `${Math.round(v * 1000)} m` : `${v.toFixed(1)} km`);
export const mins = (m) => (m >= 60 ? `${Math.floor(m / 60)} h ${m % 60 ? `${m % 60} min` : ''}`.trim() : `${m} min`);
export const two = (n) => String(n).padStart(2, '0');
export const sum = (list, fn) => list.reduce((s, x) => s + (Number(fn(x)) || 0), 0);
export const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');

export function parseISO(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}
export function dateLabel(iso, opts = { weekday: 'short', day: 'numeric', month: 'short' }) {
    return parseISO(iso).toLocaleDateString('en-GB', { ...opts, timeZone: 'UTC' });
}
export function isoAdd(iso, days) {
    const d = parseISO(iso);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
}
export function daysBetween(a, b) {
    return Math.round((parseISO(b) - parseISO(a)) / 864e5) + 1;
}
export const todayISO = () => new Date().toISOString().slice(0, 10);

/** Open-Meteo weather code → Font Awesome icon. */
export function weatherIcon(code) {
    if (code == null) return 'fa-sun';
    if (code === 0) return 'fa-sun';
    if (code <= 2) return 'fa-cloud-sun';
    if (code === 3) return 'fa-cloud';
    if (code <= 48) return 'fa-smog';
    if (code <= 67) return 'fa-cloud-rain';
    if (code <= 77) return 'fa-snowflake';
    if (code <= 82) return 'fa-cloud-showers-heavy';
    return 'fa-cloud-bolt';
}

export const MODE = {
    walk: { label: 'Walk', icon: 'fa-person-walking' },
    metro: { label: 'Metro', icon: 'fa-train-subway' },
    bus: { label: 'Bus', icon: 'fa-bus' },
    shared: { label: 'Shared ride', icon: 'fa-van-shuttle' },
    taxi: { label: 'Taxi', icon: 'fa-taxi' },
};

export function nodeIcon(n) {
    if (n.type === 'meal') return { breakfast: 'fa-mug-hot', lunch: 'fa-bowl-food', dinner: 'fa-utensils' }[n.slot] || 'fa-utensils';
    if (n.type === 'market') return 'fa-store';
    if (n.type === 'activity') return n.slot === 'dinner' ? 'fa-champagne-glasses' : 'fa-masks-theater';
    if (n.type === 'pause') return 'fa-couch';
    if (n.type === 'return') return 'fa-bed';
    if (n.type === 'hotel') return 'fa-bed';
    const i = n.interests || [];
    if (i.includes('religion')) return 'fa-place-of-worship';
    if (i.includes('museums')) return 'fa-building-columns';
    if (i.includes('nature')) return 'fa-tree';
    if (i.includes('views')) return 'fa-binoculars';
    if (i.includes('history')) return 'fa-landmark';
    return 'fa-location-dot';
}

export function typeLabel(n) {
    if (n.type === 'meal') return cap(n.slot || 'meal');
    return { attraction: 'Sight', market: 'Market', activity: n.slot === 'dinner' ? 'Dinner experience' : 'Experience', pause: 'Free time', return: 'Back to stay', hotel: 'Stay' }[n.type] || 'Stop';
}

export const INTERESTS = [
    { id: 'history', label: 'History', icon: 'fa-landmark' },
    { id: 'museums', label: 'Museums', icon: 'fa-building-columns' },
    { id: 'nature', label: 'Nature & parks', icon: 'fa-tree' },
    { id: 'religion', label: 'Temples & churches', icon: 'fa-place-of-worship' },
    { id: 'views', label: 'Viewpoints', icon: 'fa-binoculars' },
    { id: 'art', label: 'Art & design', icon: 'fa-palette' },
    { id: 'food', label: 'Food', icon: 'fa-utensils' },
    { id: 'shopping', label: 'Markets & shopping', icon: 'fa-bag-shopping' },
    { id: 'nightlife', label: 'Nights out', icon: 'fa-moon' },
];

export const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'SGD', 'AED', 'CAD'];
