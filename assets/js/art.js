// Hand-drawn-style line illustrations: black outlines, flat oat and Kofi Blue
// fills, no gradients. Each returns an inline SVG string.

const S = 'stroke="#000" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"';
const BLUE = '#aac9f7';
const OAT = '#e9dfd2';
const WHITE = '#fff';

/** Bean mascot at (x, y) with optional prop drawn in its "hand". */
function bean(x, y, scale = 1, mood = 'smile') {
    const mouth = mood === 'wow'
        ? `<ellipse cx="0" cy="12" rx="5" ry="6" fill="#000"/>`
        : `<path d="M-8 9 q8 9 16 0" fill="none" ${S}/>`;
    return `<g transform="translate(${x} ${y}) scale(${scale})">
        <path d="M-14 46 l-6 22 M14 46 l6 22" fill="none" ${S}/>
        <path d="M-20 68 h-8 M20 68 h8" fill="none" ${S}/>
        <ellipse cx="0" cy="0" rx="34" ry="50" fill="${OAT}" ${S}/>
        <path d="M2 -48 C-14 -20 16 14 0 49" fill="none" ${S}/>
        <circle cx="-12" cy="-6" r="3.5" fill="#000"/>
        <circle cx="12" cy="-6" r="3.5" fill="#000"/>
        <circle cx="-20" cy="6" r="5" fill="${BLUE}"/>
        <circle cx="20" cy="6" r="5" fill="${BLUE}"/>
        ${mouth}
    </g>`;
}

export function logoMark() {
    return `<svg class="brand__mark" viewBox="0 0 64 64" aria-hidden="true">
        <path d="M12 22h34v15a17 17 0 0 1-34 0z" fill="#ff5e5b" ${S}/>
        <path d="M46 26h4a8 8 0 0 1 0 16h-6" fill="none" ${S}/>
        <path d="M29 33c-3-4-9-1-6 4l6 6 6-6c3-5-3-8-6-4z" fill="#fff" stroke="#000" stroke-width="2.4" stroke-linejoin="round"/>
        <path d="M22 8c-3 4 3 6 0 10M32 6c-3 4 3 6 0 10" fill="none" ${S}/>
    </svg>`;
}

export function hero() {
    return `<svg viewBox="0 0 560 300" role="img" aria-label="A coffee-bean traveller with a suitcase and a map">
        <path d="M30 270 H530" ${S}/>
        <circle cx="470" cy="70" r="34" fill="${BLUE}" ${S}/>
        <path d="M470 18v-10M470 132v-10M418 70h-10M532 70h-10M433 33l-7-7M514 114l-7-7M433 107l-7 7M514 26l-7 7" ${S}/>
        <path d="M70 230 C 150 160, 220 250, 300 170 S 420 160, 450 120" fill="none" stroke="#000" stroke-width="3" stroke-dasharray="2 12" stroke-linecap="round"/>
        <g transform="translate(450 96)"><path d="M0 26 C-14 8 -16 -2 -16 -8 a16 16 0 0 1 32 0 c0 6 -2 16 -16 34z" fill="#ff5e5b" ${S}/><circle cx="0" cy="-8" r="6" fill="#fff" ${S}/></g>
        <g transform="translate(330 186)">
            <rect x="-48" y="-30" width="96" height="84" rx="16" fill="${BLUE}" ${S}/>
            <path d="M-18 -30 v-16 a8 8 0 0 1 8-8 h20 a8 8 0 0 1 8 8 v16" fill="none" ${S}/>
            <path d="M-48 2 h96" ${S}/>
            <circle cx="-22" cy="28" r="9" fill="${WHITE}" ${S}/>
            <rect x="8" y="16" width="26" height="18" rx="5" fill="${OAT}" ${S}/>
        </g>
        ${bean(190, 168, 1.15)}
        <g transform="translate(118 150) rotate(-8)">
            <path d="M0 0 l26 -8 l26 8 l26 -8 v58 l-26 8 l-26 -8 l-26 8z" fill="${WHITE}" ${S}/>
            <path d="M26 -8 v58 M52 0 v58" ${S}/>
            <path d="M8 34 q14 -18 30 -4 t30 -10" fill="none" stroke="#000" stroke-width="2.5" stroke-dasharray="1 7" stroke-linecap="round"/>
        </g>
        <path d="M168 168 l-22 -8" ${S}/>
    </svg>`;
}

export function travellers() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="Two bean travellers">
        <path d="M20 270 H300" ${S}/>
        ${bean(110, 170, 1)}
        ${bean(215, 176, 0.9, 'wow')}
        <path d="M146 158 q18 -10 36 2" fill="none" ${S}/>
        <g transform="translate(250 92)"><path d="M0 0 h40 l-8 14 h-32z" fill="${BLUE}" ${S}/><path d="M0 0 v40" ${S}/></g>
    </svg>`;
}

export function pace() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="An alarm clock with running shoes">
        <path d="M20 270 H300" ${S}/>
        <circle cx="160" cy="140" r="92" fill="${WHITE}" ${S}/>
        <circle cx="160" cy="140" r="74" fill="${OAT}" ${S}/>
        <path d="M86 62 l-22 -22 M234 62 l22 -22" ${S}/>
        <path d="M54 52 a26 26 0 0 1 36 -14 M266 52 a26 26 0 0 0 -36 -14" fill="${BLUE}" ${S}/>
        <path d="M160 140 v-50 M160 140 l36 22" stroke="#000" stroke-width="6" stroke-linecap="round"/>
        <circle cx="160" cy="140" r="8" fill="#000"/>
        <path d="M110 238 l-14 30 M210 238 l14 30" ${S}/>
        <path d="M84 268 h34 l6 -8 h-26z M196 268 h34 l6 -8 h-26z" fill="${BLUE}" ${S}/>
    </svg>`;
}

export function compass() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="A compass">
        <path d="M20 270 H300" ${S}/>
        <circle cx="160" cy="140" r="96" fill="${OAT}" ${S}/>
        <circle cx="160" cy="140" r="76" fill="${WHITE}" ${S}/>
        <path d="M160 64 v14 M160 202 v14 M84 140 h14 M222 140 h14" ${S}/>
        <path d="M160 140 l26 -58 l-52 58z" fill="${BLUE}" ${S}/>
        <path d="M160 140 l-26 58 l52 -58z" fill="${WHITE}" ${S}/>
        <circle cx="160" cy="140" r="9" fill="#000"/>
        <path d="M148 44 h24 v-10 h-24z" fill="${BLUE}" ${S}/>
    </svg>`;
}

export function bowl() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="A steaming bowl of noodles">
        <path d="M20 270 H300" ${S}/>
        <g class="steam"><path d="M120 92 c-12 -16 12 -22 0 -40" fill="none" ${S}/><path d="M160 86 c-12 -16 12 -22 0 -40" fill="none" ${S}/><path d="M200 92 c-12 -16 12 -22 0 -40" fill="none" ${S}/></g>
        <path d="M206 112 l70 -66 M220 118 l70 -58" stroke="#000" stroke-width="6" stroke-linecap="round"/>
        <path d="M54 130 h212 a106 106 0 0 1 -212 0z" fill="${BLUE}" ${S}/>
        <path d="M70 130 q30 -26 60 0 t60 0 t60 0" fill="${OAT}" ${S}/>
        <path d="M112 236 h96 l-10 22 h-76z" fill="${WHITE}" ${S}/>
        <circle cx="122" cy="122" r="10" fill="${WHITE}" ${S}/>
    </svg>`;
}

export function house() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="A cosy guesthouse at night">
        <path d="M20 270 H300" ${S}/>
        <path d="M250 50 a30 30 0 1 0 22 50 a24 24 0 1 1 -22 -50z" fill="${BLUE}" ${S}/>
        <path d="M60 140 L160 62 L260 140" fill="none" ${S}/>
        <rect x="80" y="130" width="160" height="140" fill="${OAT}" ${S}/>
        <path d="M60 140 L160 62 L260 140z" fill="#ff5e5b" ${S}/>
        <rect x="140" y="200" width="40" height="70" rx="20" fill="${WHITE}" ${S}/>
        <rect x="100" y="156" width="34" height="34" rx="8" fill="${BLUE}" ${S}/>
        <rect x="186" y="156" width="34" height="34" rx="8" fill="${BLUE}" ${S}/>
    </svg>`;
}

export function bus() {
    return `<svg viewBox="0 0 320 300" role="img" aria-label="A city bus">
        <path d="M20 270 H300" ${S}/>
        <rect x="64" y="50" width="192" height="196" rx="34" fill="${BLUE}" ${S}/>
        <rect x="86" y="80" width="148" height="78" rx="14" fill="${WHITE}" ${S}/>
        <path d="M160 80 v78" ${S}/>
        <circle cx="104" cy="200" r="14" fill="${WHITE}" ${S}/>
        <circle cx="216" cy="200" r="14" fill="${WHITE}" ${S}/>
        <rect x="134" y="190" width="52" height="20" rx="10" fill="${OAT}" ${S}/>
        <path d="M92 246 v20 h28 v-20 M200 246 v20 h28 v-20" fill="${OAT}" ${S}/>
        <path d="M120 62 h80" ${S}/>
    </svg>`;
}

export function brewCup() {
    return `<svg class="brew__art" viewBox="0 0 280 260" role="img" aria-label="A cup of coffee brewing">
        <g class="steam"><path d="M100 70 c-12 -16 12 -22 0 -40" fill="none" ${S}/><path d="M140 64 c-12 -16 12 -22 0 -40" fill="none" ${S}/><path d="M180 70 c-12 -16 12 -22 0 -40" fill="none" ${S}/></g>
        <path d="M40 96 h180 v70 a90 80 0 0 1 -180 0z" fill="#ff5e5b" ${S}/>
        <path d="M220 112 h14 a26 26 0 0 1 0 52 h-20" fill="none" ${S}/>
        <ellipse cx="130" cy="96" rx="90" ry="14" fill="${OAT}" ${S}/>
        <path d="M130 152 c-10 -14 -32 -4 -20 12 l20 20 l20 -20 c12 -16 -10 -26 -20 -12z" fill="${WHITE}" ${S}/>
        <path d="M20 236 h220" ${S}/>
    </svg>`;
}

export function foldedMap() {
    return `<svg viewBox="0 0 240 200" role="img" aria-label="A folded map with a pin">
        <path d="M30 50 l60 -18 l60 18 l60 -18 v120 l-60 18 l-60 -18 l-60 18z" fill="${WHITE}" ${S}/>
        <path d="M90 32 v120 M150 50 v120" ${S}/>
        <path d="M46 130 q30 -50 70 -20 t70 -50" fill="none" stroke="#000" stroke-width="3" stroke-dasharray="1 9" stroke-linecap="round"/>
        <g transform="translate(186 58)"><path d="M0 30 C-16 10 -18 0 -18 -8 a18 18 0 0 1 36 0 c0 8 -2 18 -18 38z" fill="${BLUE}" ${S}/><circle cx="0" cy="-8" r="6" fill="#fff" ${S}/></g>
    </svg>`;
}

export function calendarArt() {
    return `<svg viewBox="0 0 240 200" role="img" aria-label="A calendar">
        <rect x="40" y="36" width="160" height="140" rx="22" fill="${WHITE}" ${S}/>
        <path d="M40 74 h160" ${S}/>
        <path d="M40 58 a22 22 0 0 1 22 -22 h116 a22 22 0 0 1 22 22 v16 h-160z" fill="${BLUE}" ${S}/>
        <path d="M80 24 v24 M160 24 v24" ${S}/>
        <rect x="62" y="92" width="28" height="24" rx="7" fill="${OAT}" ${S}/>
        <rect x="106" y="92" width="28" height="24" rx="7" fill="${BLUE}" ${S}/>
        <rect x="150" y="92" width="28" height="24" rx="7" fill="${OAT}" ${S}/>
        <rect x="62" y="132" width="28" height="24" rx="7" fill="${OAT}" ${S}/>
        <path d="M112 144 l8 8 l14 -16" fill="none" ${S}/>
    </svg>`;
}

export function piggy() {
    return `<svg viewBox="0 0 240 200" role="img" aria-label="A piggy bank">
        <ellipse cx="120" cy="110" rx="78" ry="60" fill="${BLUE}" ${S}/>
        <path d="M84 60 l-10 -26 l28 14" fill="${BLUE}" ${S}/>
        <rect x="186" y="96" width="26" height="30" rx="10" fill="${OAT}" ${S}/>
        <path d="M86 164 v18 h20 v-14 M140 166 v16 h20 v-18" fill="${OAT}" ${S}/>
        <circle cx="160" cy="92" r="5" fill="#000"/>
        <path d="M100 74 h40" ${S}/>
        <circle cx="120" cy="34" r="16" fill="${OAT}" ${S}/>
        <path d="M120 26 v16" ${S}/>
    </svg>`;
}

export const STEP_ART = { travellers, pace, interests: compass, food: bowl, stay: house, transit: bus };
