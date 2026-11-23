(() => {
    const root = document.documentElement;
    const isMobile = () => window.matchMedia('(max-width: 899px)').matches;

    // Sidebar: hidden completely when toggled. On desktop the choice is remembered;
    // on small screens it slides in over the content.
    const sidebarHidden = () => isMobile()
        ? !root.classList.contains('sidebar-open')
        : root.classList.contains('sidebar-collapsed');

    const syncSidebarButtons = () => {
        const hidden = sidebarHidden();
        document.querySelectorAll('.toolbar [data-sidebar-toggle]').forEach((button) => {
            button.setAttribute('aria-expanded', String(!hidden));
            button.title = hidden ? 'Show sidebar ( [ )' : 'Hide sidebar ( [ )';
        });
    };

    const toggleSidebar = () => {
        if (isMobile()) {
            root.classList.toggle('sidebar-open');
        } else {
            const collapsed = root.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('fv.sidebar', collapsed ? 'collapsed' : 'open'); } catch (e) {}
        }
        syncSidebarButtons();
    };

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            toggleSidebar();
            button.closest('details')?.removeAttribute('open');
        });
    });
    syncSidebarButtons();
    window.matchMedia('(max-width: 899px)').addEventListener('change', syncSidebarButtons);

    // Carousels: previous/next buttons page through a horizontally scrolling row.
    document.querySelectorAll('[data-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('[data-carousel-track]');
        const controls = carousel.querySelector('[data-carousel-controls]');
        const prev = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');
        if (!track || !prev || !next) return;

        const update = () => {
            const maxScroll = track.scrollWidth - track.clientWidth;
            const canPrev = track.scrollLeft > 2;
            const canNext = track.scrollLeft < maxScroll - 2;
            prev.disabled = !canPrev;
            next.disabled = !canNext;
            controls.hidden = maxScroll <= 2;
            carousel.classList.toggle('can-prev', canPrev);
            carousel.classList.toggle('can-next', canNext);
        };

        const page = (direction) => {
            const visible = [...track.children].filter((item) => !item.hidden);
            const step = visible.length > 1 ? visible[1].offsetLeft - visible[0].offsetLeft : track.clientWidth;
            const perPage = Math.max(1, Math.floor(track.clientWidth / step));
            track.scrollBy({ left: direction * perPage * step, behavior: 'smooth' });
        };

        prev.addEventListener('click', () => page(-1));
        next.addEventListener('click', () => page(1));
        track.addEventListener('scroll', update, { passive: true });
        track.addEventListener('keydown', (event) => {
            if (event.target.closest('input, textarea, select')) return;
            if (event.key === 'ArrowRight') { event.preventDefault(); page(1); }
            if (event.key === 'ArrowLeft') { event.preventDefault(); page(-1); }
        });
        new ResizeObserver(update).observe(track);
        carousel.addEventListener('carousel:update', update);
        update();
    });

    // YAML editor: Tab inserts two spaces instead of leaving the field.
    document.querySelectorAll('[data-yaml-editor]').forEach((editor) => {
        editor.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
            event.preventDefault();
            editor.setRangeText('  ', editor.selectionStart, editor.selectionEnd, 'end');
        });
    });

    // Ask before destructive submits.
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });

    // Only one dropdown open at a time.
    document.querySelectorAll('details.menu').forEach((menu) => {
        menu.addEventListener('toggle', () => {
            if (!menu.open) return;
            document.querySelectorAll('details.menu[open]').forEach((other) => {
                if (other !== menu) other.removeAttribute('open');
            });
        });
    });

    // Single-key shortcuts, ignored while typing.
    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest('input, textarea, select, [contenteditable]');
        if (event.key === 'Escape') {
            document.querySelectorAll('details.menu[open]').forEach((menu) => menu.removeAttribute('open'));
        }
        if (typing || event.ctrlKey || event.metaKey || event.altKey) return;
        if (event.key === '[') {
            event.preventDefault();
            toggleSidebar();
        } else if (event.key.toLowerCase() === 'n' && document.body.dataset.newTicketUrl) {
            window.location.href = document.body.dataset.newTicketUrl;
        }
    });

    // Filter forms apply as soon as a value changes.
    document.querySelectorAll('form[data-auto-submit] select').forEach((select) => {
        select.addEventListener('change', () => select.form.requestSubmit());
    });

    document.addEventListener('click', (event) => {
        if (isMobile() && root.classList.contains('sidebar-open')
            && !event.target.closest('#sidebar, [data-sidebar-toggle]')) {
            root.classList.remove('sidebar-open');
            syncSidebarButtons();
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
                if (!('searchServer' in search.dataset)) {
                    search.value = '';
                    search.dispatchEvent(new Event('input'));
                }
                search.blur();
            }
        });

        if (!('searchServer' in search.dataset)) search.addEventListener('input', () => {
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
                section.querySelectorAll('.tile-add, .action-new').forEach((el) => { el.hidden = Boolean(query); });
                section.dispatchEvent(new Event('carousel:update'));
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
