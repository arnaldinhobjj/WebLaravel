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

    // YAML editor: colored text drawn behind a transparent textarea; Tab inserts two spaces.
    document.querySelectorAll('[data-yaml-editor]').forEach((editor) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'yaml-code';
        const highlight = document.createElement('pre');
        highlight.className = 'yaml-highlight';
        highlight.setAttribute('aria-hidden', 'true');
        editor.before(wrapper);
        wrapper.append(highlight, editor);

        const render = () => {
            highlight.innerHTML = highlightYaml(editor.value) + '\n ';
            highlight.scrollTop = editor.scrollTop;
            highlight.scrollLeft = editor.scrollLeft;
        };
        editor.addEventListener('input', render);
        editor.addEventListener('scroll', () => {
            highlight.scrollTop = editor.scrollTop;
            highlight.scrollLeft = editor.scrollLeft;
        });
        render();

        editor.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
            event.preventDefault();
            editor.setRangeText('  ', editor.selectionStart, editor.selectionEnd, 'end');
            render();
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

    // A select's chosen option can explain itself in a hint below it.
    document.querySelectorAll('select[data-describe]').forEach((select) => {
        const hint = document.getElementById(select.dataset.describe);
        if (!hint) return;
        select.addEventListener('change', () => {
            hint.textContent = select.selectedOptions[0]?.dataset.description ?? '';
        });
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
    function toast(message, { position = 'bottom' } = {}) {
        document.querySelectorAll('[data-toast]').forEach((el) => el.remove());
        const el = document.createElement('div');
        el.className = position === 'top' ? 'toast toast-top' : 'toast';
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

    // For page scripts (e.g. the AI chat): FortValeToast('Saved', { position: 'top' }).
    window.FortValeToast = toast;
})();

/**
 * Turns YAML text into HTML with colored keys, strings, literals, ${{ }} values and comments.
 * shortcut: line-based, not a full YAML parser; good enough for action files.
 */
function highlightYaml(text) {
    const escape = (s) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]);
    const span = (kind, s) => (s ? `<span class="y-${kind}">${s}</span>` : '');
    const withExpressions = (s) => s.split(/(\$\{\{.*?\}\})/).map((part, i) => (i % 2 ? span('expr', escape(part)) : escape(part))).join('');
    const value = (s) => {
        if (/^\s*(true|false|null|~|-?\d+(\.\d+)?)\s*$/.test(s)) return span('lit', escape(s));
        return s.split(/(\$\{\{.*?\}\}|"(?:[^"\\]|\\.)*"?|'[^']*'?|[A-Za-z_][\w.-]*(?=:(?:\s|$)))/).map((part, i) => {
            if (i % 2 === 0) return escape(part);
            if (part.startsWith('${{')) return span('expr', escape(part));
            if (part[0] === '"' || part[0] === "'") return span('str', withExpressions(part));
            return span('key', escape(part));
        }).join('');
    };
    const commentStart = (line) => {
        let quote = null;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (quote) { if (c === quote) quote = null; continue; }
            if (c === '"' || c === "'") quote = c;
            else if (c === '#' && (i === 0 || /\s/.test(line[i - 1]))) return i;
        }
        return -1;
    };

    let blockIndent = -1; // indentation of the line that opened a | or > block
    return text.split('\n').map((line) => {
        const indent = line.match(/^\s*/)[0].length;
        if (blockIndent >= 0 && (line.trim() === '' || indent > blockIndent)) return span('str', withExpressions(line)) || '';
        blockIndent = -1;

        const hash = commentStart(line);
        const code = hash >= 0 ? line.slice(0, hash) : line;
        const comment = hash >= 0 ? span('comment', escape(line.slice(hash))) : '';
        const m = code.match(/^(\s*)(-\s+)?([^\s#'"{}\[\],:][^:]*?|"[^"]*"|'[^']*')(:)(?=\s|$)(.*)$/);
        if (/[|>][-+]?\s*$/.test(code)) blockIndent = indent;
        if (!m) {
            const dash = code.match(/^(\s*)(-\s+)(.*)$/);
            return (dash ? escape(dash[1]) + span('key', dash[2]) + value(dash[3]) : value(code)) + comment;
        }
        return escape(m[1]) + span('key', m[2] || '') + span('key', escape(m[3])) + m[4] + value(m[5]) + comment;
    }).join('\n');
}

// Tag permission grid: ticking anything on a page also ticks View; unticking View clears the row.
document.querySelectorAll('[data-permission-row]').forEach((row) => {
    const view = row.querySelector('[data-permission-view]');
    if (!view) return;
    row.querySelectorAll('input[type="checkbox"]').forEach((box) => {
        box.addEventListener('change', () => {
            if (box === view && !view.checked) {
                row.querySelectorAll('input[type="checkbox"]').forEach((other) => { other.checked = false; });
            } else if (box !== view && box.checked) {
                view.checked = true;
            }
        });
    });
});

// File upload: choosing or dropping files uploads them straight away.
document.querySelectorAll('[data-drop-zone]').forEach((zone) => {
    const input = zone.querySelector('[data-drop-input]');
    input.addEventListener('change', () => { if (input.files.length) zone.submit(); });
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => {
        event.preventDefault();
        zone.classList.add('is-over');
    }));
    ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, () => zone.classList.remove('is-over')));
    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        if (!event.dataTransfer.files.length) return;
        input.files = event.dataTransfer.files;
        zone.submit();
    });
});

// Email page: search Files for attachments. Ticked files move to the top and stay while you search.
document.querySelectorAll('[data-file-picker]').forEach((picker) => {
    const chosen = picker.querySelector('[data-file-chosen]');
    const results = picker.querySelector('[data-file-results]');
    const status = picker.querySelector('[data-file-status]');
    const search = picker.querySelector('[data-file-search]');
    let timer;

    const choice = (file) => {
        const label = document.createElement('label');
        label.className = 'check';
        label.dataset.fileChoice = file.id;
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.name = 'stored_files[]';
        box.value = file.id;
        const text = document.createElement('span');
        text.textContent = file.name;
        const where = document.createElement('small');
        where.textContent = file.path;
        text.append(where);
        label.append(box, text);
        return label;
    };

    picker.addEventListener('change', (event) => {
        const label = event.target.closest('[data-file-choice]');
        if (!label) return;
        (event.target.checked ? chosen : results).prepend(label);
    });

    search.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const query = search.value.trim();
            const response = await fetch(`${picker.dataset.url}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const files = await response.json();
            const taken = new Set([...chosen.querySelectorAll('[data-file-choice]')].map((label) => label.dataset.fileChoice));
            const shown = files.filter((file) => !taken.has(String(file.id)));
            results.replaceChildren(...shown.map(choice));
            status.textContent = query === '' ? 'Recently changed:' : (shown.length ? `${shown.length} found:` : 'No files match.');
        }, 200);
    });
});

// Buttons that really change things ask first.
document.querySelectorAll('[data-confirm-click]').forEach((button) => {
    button.addEventListener('click', (event) => { if (!window.confirm(button.dataset.confirmClick)) event.preventDefault(); });
});
