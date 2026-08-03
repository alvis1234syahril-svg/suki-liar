const profile = document.querySelector('[data-client-profile]');

if (profile) {
    const tabs = [...profile.querySelectorAll('[data-profile-tab]')];
    const panels = [...profile.querySelectorAll('[data-profile-panel]')];

    const activateTab = (name, updateHash = true) => {
        const target = tabs.some(tab => tab.dataset.profileTab === name) ? name : 'overview';

        tabs.forEach(tab => {
            const active = tab.dataset.profileTab === target;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.setAttribute('tabindex', active ? '0' : '-1');
        });

        panels.forEach(panel => {
            panel.classList.toggle('hidden', panel.dataset.profilePanel !== target);
        });

        if (updateHash) {
            history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${target}`);
        }
    };

    tabs.forEach((tab, index) => {
        tab.setAttribute('role', 'tab');
        tab.addEventListener('click', () => activateTab(tab.dataset.profileTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const offset = event.key === 'ArrowRight' ? 1 : -1;
            const next = tabs[(index + offset + tabs.length) % tabs.length];
            activateTab(next.dataset.profileTab);
            next.focus();
        });
    });

    const requestedTab = window.location.hash.slice(1);
    const initialTab = requestedTab || (new URLSearchParams(window.location.search).has('page') ? 'logs' : 'overview');
    activateTab(initialTab, false);

    const copyButton = profile.querySelector('[data-copy-ip]');
    copyButton?.addEventListener('click', async () => {
        const original = copyButton.textContent;
        try {
            await navigator.clipboard.writeText(copyButton.dataset.copyIp);
            copyButton.textContent = 'IP tersalin';
        } catch {
            copyButton.textContent = copyButton.dataset.copyIp;
        }
        window.setTimeout(() => { copyButton.textContent = original; }, 1800);
    });

    const explorer = profile.querySelector('[data-log-explorer]');
    if (explorer) {
        const rows = [...explorer.querySelectorAll('[data-log-row]')];
        const filters = [...explorer.querySelectorAll('[data-log-filter]')];
        const search = explorer.querySelector('[data-log-search]');
        const visibleCount = explorer.querySelector('[data-visible-log-count]');
        const empty = explorer.querySelector('[data-log-empty]');
        let activeFilter = 'all';

        const applyFilters = () => {
            const query = (search?.value ?? '').trim().toLocaleLowerCase('id-ID');
            let count = 0;

            rows.forEach(row => {
                const filterMatch = activeFilter === 'all'
                    || (activeFilter === 'deny' && row.dataset.action === 'deny')
                    || (activeFilter === 'accept' && row.dataset.action !== 'deny')
                    || (activeFilter === 'risky' && row.dataset.risky === 'true');
                const searchMatch = !query || row.dataset.search.includes(query);
                const visible = filterMatch && searchMatch;
                row.classList.toggle('hidden', !visible);
                if (visible) count++;
            });

            visibleCount.textContent = new Intl.NumberFormat('id-ID').format(count);
            empty?.classList.toggle('hidden', count !== 0);
        };

        filters.forEach(button => {
            button.addEventListener('click', () => {
                activeFilter = button.dataset.logFilter;
                filters.forEach(item => item.classList.toggle('is-active', item === button));
                applyFilters();
            });
        });

        search?.addEventListener('input', applyFilters);
    }
}
