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

// AI page: the browser talks to the person's own OpenAI-compatible AI directly
// (it runs on their computer), streams the answer, and saves each exchange.
document.querySelectorAll('[data-ai-chat]').forEach((chat) => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const list = chat.querySelector('[data-ai-messages]');
    const empty = chat.querySelector('[data-ai-empty]');
    const error = chat.querySelector('[data-ai-error]');
    const form = chat.querySelector('[data-ai-form]');
    const input = chat.querySelector('[data-ai-input]');
    const send = chat.querySelector('[data-ai-send]');
    const stop = chat.querySelector('[data-ai-stop]');
    const history = JSON.parse(chat.querySelector('[data-ai-history]').textContent || '[]');
    let conversationId = chat.dataset.conversationId || null;
    let controller = null;

    const base = () => (chat.dataset.baseUrl || '').replace(/\/+$/, '');
    const headers = () => {
        const h = { 'Content-Type': 'application/json' };
        if (chat.dataset.apiKey) h.Authorization = `Bearer ${chat.dataset.apiKey}`;
        return h;
    };
    const showError = (message) => { error.textContent = message; error.hidden = !message; };
    const bubble = (role, text) => {
        const item = document.createElement('li');
        item.className = `ai-message ai-message-${role}`;
        const div = document.createElement('div');
        div.className = 'ai-bubble';
        div.textContent = text;
        item.append(div);
        list.append(item);
        empty.hidden = true;
        list.scrollTop = list.scrollHeight;
        return div;
    };
    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body),
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
    });
    stop.addEventListener('click', () => controller?.abort());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = input.value.trim();
        if (!text || controller) return;
        if (!chat.dataset.model) { showError('Choose a model under “Connect your AI” first.'); return; }
        showError('');

        input.value = '';
        bubble('user', text);
        const answer = bubble('assistant', 'Thinking…');
        answer.classList.add('is-thinking');
        send.hidden = true; stop.hidden = false;
        controller = new AbortController();

        const messages = [
            ...(chat.dataset.systemPrompt ? [{ role: 'system', content: chat.dataset.systemPrompt }] : []),
            ...history,
            { role: 'user', content: text },
        ];
        let reply = '';

        try {
            const response = await fetch(`${base()}/chat/completions`, {
                method: 'POST', headers: headers(), signal: controller.signal,
                body: JSON.stringify({ model: chat.dataset.model, messages, stream: true }),
            });
            if (!response.ok) throw new Error(`Your AI answered ${response.status}: ${(await response.text()).slice(0, 200)}`);

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            for (;;) {
                const { done, value } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();
                for (const line of lines) {
                    const data = line.replace(/^data:\s*/, '').trim();
                    if (!line.startsWith('data:') || data === '' || data === '[DONE]') continue;
                    const piece = JSON.parse(data).choices?.[0]?.delta?.content ?? '';
                    if (piece) {
                        reply += piece;
                        answer.classList.remove('is-thinking');
                        answer.textContent = reply;
                        list.scrollTop = list.scrollHeight;
                    }
                }
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') {
                showError(failure instanceof TypeError
                    ? `Couldn’t reach your AI at ${base()}. Is it running, and does it allow this page (CORS)? See “Connect your AI”.`
                    : failure.message);
            }
        } finally {
            controller = null;
            send.hidden = false; stop.hidden = true;
        }

        if (!reply) { answer.parentElement.remove(); input.value = text; return; }
        answer.classList.remove('is-thinking');
        history.push({ role: 'user', content: text }, { role: 'assistant', content: reply });

        // Save the exchange (creating the chat on its first message).
        try {
            if (!conversationId) {
                const created = await (await post(chat.dataset.createUrl, {})).json();
                conversationId = created.id;
                window.history.replaceState(null, '', created.url);
            }
            const saved = await (await post(chat.dataset.saveUrl.replace('{id}', conversationId), { user: text, assistant: reply, model: chat.dataset.model })).json();
            const chats = document.querySelector('[data-ai-chat-list]');
            if (chats && !chats.querySelector(`a[href$="c=${conversationId}"]`)) {
                chats.querySelector('.ai-no-chats')?.remove();
                const item = document.createElement('li');
                const link = document.createElement('a');
                link.href = `?c=${conversationId}`;
                link.className = 'is-active';
                link.textContent = saved.title || 'New chat';
                item.append(link);
                chats.prepend(item);
            }
        } catch {
            showError('The answer arrived but couldn’t be saved. Copy it if you need it.');
        }
    });
});

// AI page: "Test connection" lists the models the person's AI offers.
document.querySelectorAll('[data-ai-settings]').forEach((form) => {
    const button = form.querySelector('[data-ai-test]');
    const result = form.querySelector('[data-ai-test-result]');
    const select = form.querySelector('[data-ai-model-select]');
    button.addEventListener('click', async () => {
        const base = form.querySelector('[data-ai-base-url]').value.replace(/\/+$/, '');
        const key = form.querySelector('[data-ai-api-key]').value || document.querySelector('[data-ai-chat]')?.dataset.apiKey;
        result.textContent = 'Connecting…';
        try {
            const response = await fetch(`${base}/models`, { headers: key ? { Authorization: `Bearer ${key}` } : {} });
            if (!response.ok) throw new Error(`answered ${response.status}`);
            const models = ((await response.json()).data || []).map((model) => model.id);
            if (!models.length) { result.textContent = 'Connected, but your AI has no models yet (e.g. run “ollama pull llama3.2”).'; return; }
            const current = select.value;
            select.replaceChildren(...models.map((id) => new Option(id, id, false, id === current)));
            result.textContent = `Connected: ${models.length} ${models.length === 1 ? 'model' : 'models'}. Pick one and save.`;
        } catch (failure) {
            result.textContent = failure instanceof TypeError
                ? `Couldn’t reach ${base}. Is your AI running, and does it allow this page (CORS)? See below.`
                : `Your AI ${failure.message}.`;
        }
    });
});

// Buttons that really change things ask first.
document.querySelectorAll('[data-confirm-click]').forEach((button) => {
    button.addEventListener('click', (event) => { if (!window.confirm(button.dataset.confirmClick)) event.preventDefault(); });
});
