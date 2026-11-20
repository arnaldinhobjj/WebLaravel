(() => {
    const root = document.documentElement;
    const isMobile = () => window.matchMedia('(max-width: 899px)').matches;

    // Sidebar toggle: collapses on desktop (remembered), slides in over content on small screens.
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            if (isMobile()) {
                root.classList.toggle('sidebar-open');
                return;
            }
            const collapsed = root.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('fv.sidebar', collapsed ? 'collapsed' : 'open'); } catch (e) {}
        });
    });

    document.addEventListener('click', (event) => {
        if (isMobile() && root.classList.contains('sidebar-open')
            && !event.target.closest('#sidebar, [data-sidebar-toggle]')) {
            root.classList.remove('sidebar-open');
        }

        // Close any open "more" menus when clicking elsewhere.
        document.querySelectorAll('details.menu[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });

    // Search: Ctrl/Cmd+K focuses, typing filters favorites, actions and mentions.
    const search = document.querySelector('[data-search-input]');
    if (search) {
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                search.focus();
                search.select();
            } else if (event.key === 'Escape' && document.activeElement === search) {
                search.value = '';
                search.dispatchEvent(new Event('input'));
                search.blur();
            }
        });

        search.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();
            document.querySelectorAll('[data-search-section]').forEach((section) => {
                const items = section.querySelectorAll('[data-search-item]');
                let visible = 0;
                items.forEach((item) => {
                    const match = !query || item.dataset.searchItem.toLowerCase().includes(query);
                    item.hidden = !match;
                    if (match) visible++;
                });
                const empty = section.querySelector('[data-search-empty]');
                if (empty) empty.hidden = !(query && items.length && visible === 0);
                section.querySelectorAll('.tile-add').forEach((el) => { el.hidden = Boolean(query); });
            });
        });
    }

    // Dialogs.
    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal());
    });
    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    });

    // Mention menu items.
    document.querySelectorAll('[data-expand-message]').forEach((button) => {
        button.addEventListener('click', () => {
            const mention = button.closest('.mention');
            const expanded = mention.classList.toggle('is-expanded');
            button.lastChild.textContent = expanded ? ' Collapse message' : ' Show full message';
            button.closest('details').removeAttribute('open');
        });
    });
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copy);
                toast(`Copied ${button.dataset.copy}`);
            } catch (e) {
                toast('Could not copy to clipboard');
            }
            button.closest('details').removeAttribute('open');
        });
    });

    // Toasts.
    function toast(message) {
        document.querySelectorAll('[data-toast]').forEach((el) => el.remove());
        const el = document.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', 'status');
        el.dataset.toast = '';
        el.textContent = message;
        document.body.appendChild(el);
        dismissLater(el);
    }

    function dismissLater(el) {
        setTimeout(() => {
            el.classList.add('is-hiding');
            setTimeout(() => el.remove(), 300);
        }, 3200);
    }

    document.querySelectorAll('[data-toast]').forEach(dismissLater);
})();
