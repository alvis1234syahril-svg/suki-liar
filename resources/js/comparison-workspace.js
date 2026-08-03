const comparisonPanel = document.getElementById('comparison-client-panel');
const comparisonContent = document.getElementById('comparison-client-content');
const comparisonStorageKey = 'log-traffic-hidden-comparison-units-v1';
const comparisonCards = [...document.querySelectorAll('[data-comparison-unit-card]')];
const comparisonToggles = [...document.querySelectorAll('[data-comparison-unit-toggle]')];
const comparisonCount = document.querySelector('[data-comparison-visible-count]');
const comparisonShowAll = document.querySelector('[data-comparison-show-all]');

const readHiddenUnits = () => {
    try {
        const stored = JSON.parse(localStorage.getItem(comparisonStorageKey) || '[]');
        return new Set(Array.isArray(stored) ? stored.map(String) : []);
    } catch {
        return new Set();
    }
};

const hiddenComparisonUnits = readHiddenUnits();
const availableComparisonLabels = comparisonCards.map(card => card.dataset.unitLabel);

const applyComparisonVisibility = () => {
    comparisonCards.forEach(card => {
        const hidden = hiddenComparisonUnits.has(card.dataset.unitLabel);
        card.classList.toggle('is-comparison-hidden', hidden);
        const content = card.querySelector('[data-comparison-unit-content]');
        const visibleControls = card.querySelector('[data-comparison-unit-visible-controls]');
        const hiddenLabel = card.querySelector('[data-comparison-unit-hidden-label]');
        if (content) content.hidden = hidden;
        if (visibleControls) visibleControls.hidden = hidden;
        if (hiddenLabel) hiddenLabel.hidden = !hidden;
    });

    comparisonToggles.forEach(button => {
        const hidden = hiddenComparisonUnits.has(button.dataset.unitLabel);
        button.setAttribute('aria-pressed', hidden ? 'false' : 'true');
        button.setAttribute(
            'aria-label',
            `${hidden ? 'Tampilkan' : 'Sembunyikan'} ${button.dataset.unitLabel} ${hidden ? 'dalam' : 'dari'} perbandingan`
        );
        button.querySelector('[data-eye-open]').hidden = hidden;
        button.querySelector('[data-eye-closed]').hidden = !hidden;
    });

    const visibleLabels = availableComparisonLabels.filter(label => !hiddenComparisonUnits.has(label));
    if (comparisonCount) {
        comparisonCount.textContent = `${visibleLabels.length} dari ${availableComparisonLabels.length} unit tampil`;
    }
    if (comparisonShowAll) {
        comparisonShowAll.disabled = hiddenComparisonUnits.size === 0;
    }

    document.querySelectorAll('[data-comparison-charts] [data-traffic-chart]').forEach(chart => {
        chart.dispatchEvent(new CustomEvent('traffic-chart:filter-labels', {
            detail: { visibleLabels },
        }));
    });

    localStorage.setItem(comparisonStorageKey, JSON.stringify([...hiddenComparisonUnits]));
};

comparisonToggles.forEach(button => {
    button.addEventListener('click', () => {
        const label = button.dataset.unitLabel;
        if (hiddenComparisonUnits.has(label)) hiddenComparisonUnits.delete(label);
        else hiddenComparisonUnits.add(label);

        if (comparisonPanel?.dataset.activeUnit === label && hiddenComparisonUnits.has(label)) {
            comparisonPanel.classList.add('hidden');
            comparisonPanel.removeAttribute('data-active-unit');
            if (comparisonContent) comparisonContent.replaceChildren();
        }
        applyComparisonVisibility();
    });
});

comparisonShowAll?.addEventListener('click', () => {
    hiddenComparisonUnits.clear();
    applyComparisonVisibility();
});

document.querySelectorAll('[data-load-comparison-clients]').forEach((button) => {
    button.addEventListener('click', async () => {
        if (!comparisonPanel || !comparisonContent) return;

        comparisonPanel.classList.remove('hidden');
        comparisonPanel.dataset.activeUnit = button.closest('[data-comparison-unit-card]')?.dataset.unitLabel || '';
        comparisonContent.innerHTML = '<div class="py-8 text-center text-sm text-slate-500">Memuat profil user…</div>';
        comparisonPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        button.disabled = true;

        try {
            const response = await fetch(button.dataset.url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            comparisonContent.innerHTML = await response.text();
        } catch {
            comparisonContent.innerHTML = '<div class="py-8 text-center text-sm text-red-600">Profil user belum dapat dimuat. Coba lagi.</div>';
        } finally {
            button.disabled = false;
        }
    });
});

applyComparisonVisibility();
