// Upgrades: budget-boost tiers, pick-and-choose upgrades, experiences, savings.

import { state, addon, isApplied, toggleAddon, toggleTier, currency, findNode } from '../store.js';
import { esc, money, signed } from '../format.js';
import { onAction, toast } from '../ui.js';

export function render(root) {
    const plan = state.plan;
    const cur = currency();
    const all = Object.values(plan.addons);
    const picks = all.filter((a) => a.delta > 0 && !a.group.startsWith('leg:') && !a.id.startsWith('exp:') && a.value > 0.3)
        .sort((a, b) => b.value / b.delta - a.value / a.delta).slice(0, 9);
    const experiences = all.filter((a) => a.id.startsWith('exp:')).sort((a, b) => b.value - a.value);
    const savings = all.filter((a) => a.delta < 0 && !isApplied(a.id)).sort((a, b) => a.delta - b.delta).slice(0, 6);

    root.innerHTML = `
        <section>
            <h2 class="heading">Budget boosts</h2>
            <p class="muted mt-8">What a little more money buys — each boost is filled with the best-value upgrades. Apply one to try it on; the meter up top updates instantly.</p>
            <div class="tiers mt-24">${plan.tiers.map((t) => tierCard(t, cur)).join('')}</div>
        </section>

        ${experiences.length ? `
        <section class="mt-40">
            <h2 class="heading">Experiences to unlock</h2>
            <div class="swaps mt-24">${experiences.map((a) => card(a, cur, true)).join('')}</div>
        </section>` : ''}

        ${picks.length ? `
        <section class="mt-40">
            <h2 class="heading">Pick and choose</h2>
            <p class="muted mt-8">Best value first.</p>
            <div class="swaps mt-24">${picks.map((a) => card(a, cur)).join('')}</div>
        </section>` : ''}

        ${savings.length ? `
        <section class="mt-40">
            <h2 class="heading">Ways to save</h2>
            <div class="swaps mt-24">${savings.map((a) => card(a, cur)).join('')}</div>
        </section>` : ''}`;

    return onAction(root, {
        tier: (el) => {
            const pct = Number(el.dataset.pct);
            const on = toggleTier(pct);
            toast(on ? `+${pct}% boost applied` : 'Boost removed', { action: on ? 'Undo' : null, onAction: () => toggleTier(pct) });
        },
        addon: (el) => {
            const a = addon(el.dataset.addon);
            const on = toggleAddon(el.dataset.addon);
            toast(on ? `${a.label} · ${signed(a.delta, cur)}` : 'Change undone', { action: on ? 'Undo' : null, onAction: () => toggleAddon(el.dataset.addon) });
        },
    });
}

function tierCard(t, cur) {
    const on = state.tierPct === t.pct;
    const items = t.addonIds.map(addon).filter(Boolean);
    return `<article class="feature tier${on ? ' is-applied' : ''}">
        <div class="row between"><span class="tag tag--sm">+${t.pct}% boost</span>${on ? '<span class="tag tag--sm tag--dark"><i class="fa-solid fa-check"></i>Applied</span>' : ''}</div>
        <p class="tier__amount">+${esc(money(t.increase, cur))}${t.increaseUsd ? ` <span class="caption muted">≈ $${esc(Number(t.increaseUsd).toLocaleString('en'))}</span>` : ''}</p>
        <p>${esc(t.headline)}</p>
        ${items.length ? `<ul class="tier__list">${items.slice(0, 3).map((a) => row(a, cur)).join('')}</ul>` : ''}
        ${items.length > 3 ? `<details><summary class="caption strong" style="cursor:pointer">+ ${items.length - 3} smaller upgrade${items.length - 3 > 1 ? 's' : ''}</summary><ul class="tier__list mt-8">${items.slice(3).map((a) => row(a, cur)).join('')}</ul></details>` : ''}
        ${t.bufferUsed > 0 ? `<p class="caption muted">Also uses ${esc(money(t.bufferUsed, cur))} of your unspent buffer.</p>` : ''}
        <button type="button" class="btn ${on ? 'btn--outline' : 'btn--dark'}" data-action="tier" data-pct="${t.pct}">${on ? '<i class="fa-solid fa-rotate-left"></i>Undo boost' : `Apply +${esc(money(t.increase, cur))}`}</button>
    </article>`;
}

/** "Trade up to X" says which meal; other labels are already specific. */
function describe(a) {
    const node = a.group.startsWith('meal:') ? findNode(a.nodeId) : null;
    return node && !a.id.startsWith('exp:') ? `Day ${node.day + 1} ${node.slot}: ${a.patch?.title || a.label}` : a.label;
}

function row(a, cur) {
    return `<li><span>${esc(describe(a))}</span><span>${esc(signed(a.delta, cur))}</span></li>`;
}

function card(a, cur, experience = false) {
    const on = isApplied(a.id);
    const node = a.nodeId && a.nodeId !== 'hotel' ? findNode(a.nodeId) : null;
    const where = node ? `Day ${node.day + 1} · ${node.start}` : a.group === 'hotel' ? 'Your stay' : '';
    return `<div class="swap${on ? ' is-applied' : ''}">
        <div>
            ${where ? `<span class="caption muted">${esc(where)}</span>` : ''}
            <p class="swap__title">${esc(experience ? a.label.replace(/^Unlock /, '') : describe(a))}</p>
            ${a.detail ? `<p class="caption muted mt-8">${esc(a.detail.length > 140 ? `${a.detail.slice(0, 139)}…` : a.detail)}</p>` : ''}
        </div>
        <div class="row between"><span class="swap__delta">${esc(signed(a.delta, cur))}</span>
            <button type="button" class="btn btn--sm ${on ? 'btn--outline' : 'btn--dark'}" data-action="addon" data-addon="${esc(a.id)}">${on ? 'Undo' : a.delta > 0 ? 'Add' : 'Save'}</button></div>
    </div>`;
}
