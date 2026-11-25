// The /ai chat. The browser talks to the person's chosen AI directly (local
// or cloud), offers it Fort Vale's skills as tools, runs the skills it asks
// for through Fort Vale (as the person), and saves every turn.
//
// Chat history is kept in one neutral shape and translated for each kind of
// AI, so adding a provider means adding one adapter, not changing the chat:
//   { role: 'user' | 'assistant' | 'tool', content: string,
//     calls?: [{ id, name, input }]             (assistant asked for skills)
//     call_id?, name?, ok?, by?: 'ai' | 'you'   (a skill's result) }
(() => {
    const chat = document.querySelector('[data-ai-chat]');
    if (!chat) return;

    const MAX_SKILL_CALLS = 8;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const read = (selector) => JSON.parse(chat.querySelector(selector)?.textContent || 'null');
    const connections = read('[data-ai-connections]') || [];
    const skills = read('[data-ai-skills]') || { abilities: [], recipes: [] };
    const abilities = new Map(skills.abilities.map((skill) => [skill.name, skill]));
    const history = (read('[data-ai-history]') || []).map((saved) => ({ role: saved.role, content: saved.content || '', ...(saved.meta || {}) }));

    const list = chat.querySelector('[data-ai-messages]');
    const empty = chat.querySelector('[data-ai-empty]');
    const error = chat.querySelector('[data-ai-error]');
    const form = chat.querySelector('[data-ai-form]');
    const input = chat.querySelector('[data-ai-input]');
    const send = chat.querySelector('[data-ai-send]');
    const stop = chat.querySelector('[data-ai-stop]');
    const picker = chat.querySelector('[data-ai-connection]');
    const menu = chat.querySelector('[data-skill-menu]');
    const menuHead = chat.querySelector('[data-skill-menu-head]');
    const menuList = chat.querySelector('[data-skill-list]');
    const skillForm = chat.querySelector('[data-skill-form]');

    let conversationId = chat.dataset.conversationId || null;
    let controller = null;
    const noTools = new Set(); // connections whose model refused tools

    const connection = () => connections.find((item) => String(item.id) === picker.value) || null;
    const showError = (message) => { error.textContent = message || ''; error.hidden = !message; };
    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
        return { ok: response.ok, status: response.status, body: await response.json().catch(() => ({})) };
    };

    // ---------- Showing messages ----------

    const scroll = () => { list.scrollTop = list.scrollHeight; };

    // Links in AI replies are copied, not opened: a reply can be steered by
    // pages the AI read, so the person sees (and pastes) the real address.
    const copyLink = async (event) => {
        if (event.type === 'auxclick' && event.button !== 1) return; // middle click only, not right click
        const link = event.target.closest('.is-markdown a[href]');
        if (!link) return;
        event.preventDefault();
        const say = (message) => window.FortValeToast?.(message, { position: 'top' });
        try {
            await navigator.clipboard.writeText(link.href);
            say('Link copied to the clipboard');
        } catch {
            say('Couldn’t copy the link');
        }
    };
    list.addEventListener('click', copyLink);
    list.addEventListener('auxclick', copyLink);

    // The AI's replies are Markdown; what the person typed stays plain text.
    const show = (div, role, text) => {
        if (role === 'assistant' && window.FortValeMarkdown) {
            try {
                window.FortValeMarkdown.render(div, text);
                div.classList.add('is-markdown');
                return;
            } catch {
                // A reply the renderer can't handle (e.g. thousands of nested quotes) still shows, as plain text.
                div.classList.remove('is-markdown');
            }
        }
        div.textContent = text;
    };

    function bubble(role, text) {
        const item = document.createElement('li');
        item.className = `ai-message ai-message-${role}`;
        const div = document.createElement('div');
        div.className = 'ai-bubble';
        show(div, role, text);
        item.append(div);
        list.append(item);
        empty.hidden = true;
        scroll();
        return div;
    }

    function skillNote(message) {
        const item = document.createElement('li');
        item.className = 'ai-message ai-message-tool';
        const box = document.createElement('details');
        box.className = `ai-skill-note${message.ok === false ? ' is-error' : ''}`;
        const summary = document.createElement('summary');
        let result = {};
        try { result = JSON.parse(message.content); } catch { result = { summary: message.content }; }
        const who = message.by === 'you' ? 'You used' : 'Used';
        summary.append(`${who} `);
        const code = document.createElement('code');
        code.textContent = message.name;
        summary.append(code, `: ${result.summary || ''}`);
        const pre = document.createElement('pre');
        pre.textContent = JSON.stringify(result.data ?? result, null, 2);
        box.append(summary, pre);
        item.append(box);
        list.append(item);
        empty.hidden = true;
        scroll();
    }

    function render(message) {
        if (message.role === 'tool') return skillNote(message);
        if (message.role === 'assistant' && !message.content) return null;
        return bubble(message.role, message.content);
    }

    history.forEach(render);

    // ---------- Translating the chat for each kind of AI ----------

    const systemText = () => {
        const own = chat.dataset.systemPrompt || '';
        const fortVale = skills.abilities.length
            ? 'You can use Fort Vale skills (tools) to look things up and act for the person, within their permissions. Ask before creating, changing, sending or deleting anything. Each skill answers with "ok" and a "summary": if ok is false, tell the person what went wrong and never guess the answer instead.'
            : '';
        return [fortVale, own].filter(Boolean).join('\n\n');
    };

    const asText = (message) => `(I used the Fort Vale skill ${message.name}. Result: ${message.content})`;

    const adapters = {
        openai: {
            tools: () => skills.abilities.map((skill) => ({
                type: 'function',
                function: { name: skill.name, description: skill.description, parameters: skill.schema },
            })),
            messages(all) {
                const system = systemText();
                const out = system ? [{ role: 'system', content: system }] : [];
                for (const message of all) {
                    if (message.role === 'user') out.push({ role: 'user', content: message.content });
                    else if (message.role === 'assistant') {
                        out.push({
                            role: 'assistant',
                            content: message.content || null,
                            ...(message.calls?.length ? { tool_calls: message.calls.map((call) => ({ id: call.id, type: 'function', function: { name: call.name, arguments: JSON.stringify(call.input || {}) } })) } : {}),
                        });
                    } else if (message.call_id) out.push({ role: 'tool', tool_call_id: message.call_id, content: message.content });
                    else out.push({ role: 'user', content: asText(message) });
                }
                return out;
            },
            async stream(conn, all, tools, onText, signal) {
                const headers = { 'Content-Type': 'application/json' };
                if (conn.api_key) headers.Authorization = `Bearer ${conn.api_key}`;
                const body = { model: conn.model, messages: this.messages(all), stream: true, ...(tools.length ? { tools } : {}) };
                const response = await fetch(`${conn.base_url}/chat/completions`, { method: 'POST', headers, body: JSON.stringify(body), signal });
                if (!response.ok) throw new ProviderError(response.status, await response.text());

                let text = '';
                const calls = [];
                await readEvents(response, (data) => {
                    const delta = data.choices?.[0]?.delta || {};
                    if (delta.content) { text += delta.content; onText(text); }
                    for (const piece of delta.tool_calls || []) {
                        const index = piece.index ?? calls.length;
                        calls[index] ??= { id: piece.id || `call_${Date.now()}_${index}`, name: '', args: '' };
                        if (piece.id) calls[index].id = piece.id;
                        if (piece.function?.name) calls[index].name += piece.function.name;
                        if (piece.function?.arguments) {
                            calls[index].args += typeof piece.function.arguments === 'string' ? piece.function.arguments : JSON.stringify(piece.function.arguments);
                        }
                    }
                });
                return { text, calls: calls.filter(Boolean).map((call) => ({ id: call.id, name: call.name, input: parseInput(call.args) })) };
            },
        },

        anthropic: {
            tools: () => skills.abilities.map((skill) => ({ name: skill.name, description: skill.description, input_schema: skill.schema })),
            messages(all) {
                const out = [];
                const add = (role, block) => {
                    const last = out[out.length - 1];
                    if (last && last.role === role) last.content.push(block);
                    else out.push({ role, content: [block] });
                };
                for (const message of all) {
                    if (message.role === 'user') add('user', { type: 'text', text: message.content });
                    else if (message.role === 'assistant') {
                        if (message.content) add('assistant', { type: 'text', text: message.content });
                        for (const call of message.calls || []) add('assistant', { type: 'tool_use', id: call.id, name: call.name, input: call.input || {} });
                    } else if (message.call_id) add('user', { type: 'tool_result', tool_use_id: message.call_id, content: message.content });
                    else add('user', { type: 'text', text: asText(message) });
                }
                return out;
            },
            async stream(conn, all, tools, onText, signal) {
                const headers = {
                    'Content-Type': 'application/json',
                    'x-api-key': conn.api_key || '',
                    'anthropic-version': '2023-06-01',
                    'anthropic-dangerous-direct-browser-access': 'true',
                };
                const system = systemText();
                const body = { model: conn.model, max_tokens: 4096, stream: true, messages: this.messages(all), ...(system ? { system } : {}), ...(tools.length ? { tools } : {}) };
                const response = await fetch(`${conn.base_url}/messages`, { method: 'POST', headers, body: JSON.stringify(body), signal });
                if (!response.ok) throw new ProviderError(response.status, await response.text());

                let text = '';
                const blocks = [];
                await readEvents(response, (data) => {
                    if (data.type === 'content_block_start' && data.content_block?.type === 'tool_use') {
                        blocks[data.index] = { id: data.content_block.id, name: data.content_block.name, json: '' };
                    } else if (data.type === 'content_block_delta') {
                        if (data.delta?.type === 'text_delta') { text += data.delta.text; onText(text); }
                        if (data.delta?.type === 'input_json_delta' && blocks[data.index]) blocks[data.index].json += data.delta.partial_json;
                    } else if (data.type === 'error') {
                        throw new ProviderError(500, data.error?.message || 'The AI reported an error.');
                    }
                });
                return { text, calls: blocks.filter(Boolean).map((block) => ({ id: block.id, name: block.name, input: parseInput(block.json) })) };
            },
        },
    };

    class ProviderError extends Error {
        constructor(status, body) {
            super(`Your AI answered ${status}: ${String(body).slice(0, 300)}`);
            this.status = status;
            this.body = String(body);
        }
    }

    function parseInput(text) {
        if (!text) return {};
        try { const value = JSON.parse(text); return value && typeof value === 'object' ? value : {}; } catch { return {}; }
    }

    // Server-sent events: "data: {...}" lines, one JSON object each.
    async function readEvents(response, onData) {
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
                if (!line.startsWith('data:')) continue;
                const data = line.slice(5).trim();
                if (data === '' || data === '[DONE]') continue;
                onData(JSON.parse(data));
            }
        }
    }

    // ---------- Running skills through Fort Vale ----------

    async function runSkill(name, skillInput) {
        const { body } = await post(chat.dataset.runUrl.replace('{name}', encodeURIComponent(name)), skillInput || {});
        return body && typeof body.ok === 'boolean' ? body : { ok: false, summary: 'Fort Vale couldn’t run that skill.' };
    }

    const resultMessage = (name, result, extra) => ({
        role: 'tool',
        name,
        ok: result.ok,
        content: JSON.stringify(result).slice(0, 20000),
        ...extra,
    });

    // ---------- Saving ----------

    async function save(messages, model) {
        if (!messages.length) return;
        try {
            if (!conversationId) {
                const created = await post(chat.dataset.createUrl, { connection_id: connection()?.id ?? null });
                conversationId = created.body.id;
                window.history.replaceState(null, '', created.body.url);
            }
            const saved = await post(chat.dataset.saveUrl.replace('{id}', conversationId), {
                model,
                messages: messages.map(({ role, content, ...meta }) => ({ role, content: content ?? '', meta: Object.keys(meta).length ? meta : null })),
            });
            addToChatList(saved.body.title);
        } catch {
            showError('This part of the chat couldn’t be saved. Copy anything you need.');
        }
    }

    function addToChatList(title) {
        const chats = document.querySelector('[data-ai-chat-list]');
        if (!chats || chats.querySelector(`a[href$="c=${conversationId}"]`)) return;
        chats.querySelector('.ai-no-chats')?.remove();
        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = `?c=${conversationId}`;
        link.className = 'is-active';
        link.textContent = title || 'New chat';
        item.append(link);
        chats.prepend(item);
    }

    // ---------- One turn: your message, the AI's answer, skills in between ----------

    async function converse(text) {
        const conn = connection();
        if (!conn || !conn.model) { showError('Choose an AI with a model under “Your AIs” first.'); return; }
        const adapter = adapters[conn.provider] || adapters.openai;
        showError('');

        const turn = [];
        const push = (message) => { history.push(message); turn.push(message); };
        push({ role: 'user', content: text });
        bubble('user', text);

        controller = new AbortController();
        send.hidden = true; stop.hidden = false;

        try {
            for (let step = 0; step <= MAX_SKILL_CALLS; step++) {
                const answer = bubble('assistant', 'Thinking…');
                answer.classList.add('is-thinking');
                const tools = skills.abilities.length && !noTools.has(conn.id) && step < MAX_SKILL_CALLS ? adapter.tools() : [];
                // Draw at most once per frame: re-rendering on every streamed chunk adds up on long replies.
                let latest = '', drawing = false;
                const onText = (sofar) => {
                    latest = sofar;
                    answer.classList.remove('is-thinking');
                    if (drawing) return;
                    drawing = true;
                    requestAnimationFrame(() => { drawing = false; show(answer, 'assistant', latest); scroll(); });
                };

                let reply;
                try {
                    reply = await adapter.stream(conn, history, tools, onText, controller.signal);
                } catch (failure) {
                    // Models that can't use tools refuse them: carry on without skills.
                    if (failure instanceof ProviderError && tools.length && /tool/i.test(failure.body)) {
                        noTools.add(conn.id);
                        reply = await adapter.stream(conn, history, [], onText, controller.signal);
                    } else {
                        throw failure;
                    }
                }

                if (!reply.text) answer.parentElement.remove(); else { show(answer, 'assistant', reply.text); scroll(); }
                push({ role: 'assistant', content: reply.text, ...(reply.calls.length ? { calls: reply.calls } : {}) });
                if (!reply.calls.length) break;

                for (const call of reply.calls) {
                    const skill = abilities.get(call.name);
                    let result;
                    if (!skill) {
                        result = { ok: false, summary: `There's no skill called ${call.name}.` };
                    } else if (skill.changes && !window.confirm(`Your AI wants to use “${skill.title}” with:\n\n${JSON.stringify(call.input, null, 2)}\n\nAllow it?`)) {
                        result = { ok: false, summary: 'The person said no. Don’t try again unless they ask.' };
                    } else {
                        result = await runSkill(call.name, call.input);
                    }
                    const message = resultMessage(call.name, result, { call_id: call.id, by: 'ai' });
                    push(message);
                    skillNote(message);
                }
                if (step === MAX_SKILL_CALLS - 1) bubble('assistant', `(Stopped after ${MAX_SKILL_CALLS} skill uses.)`);
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') {
                const missingModel = failure instanceof ProviderError && failure.status === 404 && /model/i.test(failure.body) && /not found|does not exist/i.test(failure.body);
                showError(failure instanceof TypeError
                    ? `Couldn’t reach ${conn.name} at ${conn.base_url}. Is it running, and does it allow this page (CORS)? See “Your AIs”.`
                    : missingModel
                        ? `${conn.name} doesn’t have the model “${conn.model}” (yet). If it’s still downloading, wait until it finishes (Ollama: “ollama list” shows downloaded models); otherwise pick another model under “Your AIs”.`
                        : failure.message);
            }
        } finally {
            controller = null;
            send.hidden = false; stop.hidden = true;
            list.querySelectorAll('.is-thinking').forEach((node) => node.parentElement.remove());
        }

        await save(turn.filter((message) => message.role !== 'assistant' || message.content || message.calls), conn.model);
    }

    picker?.addEventListener('change', () => showError(''));
    stop.addEventListener('click', () => controller?.abort());
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const text = input.value.trim();
        if (!text || controller) return;
        if (!menu.hidden && shown.length) { pick(); return; }
        input.value = '';
        dismissed = false;
        closeMenu();
        converse(text);
    });

    // ---------- Calling skills yourself: type "/" ----------
    // Typing "/" at the start of the message opens the menu; every letter after
    // it narrows the list. ↑ ↓ move, Enter or Tab picks, Esc closes.

    const entries = [
        ...skills.abilities.map((skill) => ({ kind: 'skill', ...skill })),
        ...skills.recipes.map((recipe) => ({ kind: 'recipe', ...recipe })),
    ];
    let shown = [];
    let selected = 0;
    let dismissed = false; // Esc closed the menu for this "/…" text

    // The menu is for "/" plus a skill name: once there's a space or a new line,
    // it's an ordinary message (e.g. "/var/log is full").
    const query = () => (/^\/[^\s]*$/.test(input.value) && !dismissed ? input.value.slice(1) : null);

    function closeMenu() {
        menu.hidden = true;
        input.removeAttribute('aria-activedescendant');
    }

    // Best matches first: the start of the name or title, then a word inside, then the description.
    function matches(words) {
        if (!words) return entries;
        const rank = (entry) => {
            const name = entry.name.toLowerCase();
            const title = entry.title.toLowerCase();
            if (name.startsWith(words) || title.startsWith(words)) return 0;
            if (name.includes(words) || title.includes(words)) return 1;
            if (words.split(/\s+/).every((word) => `${name} ${title} ${entry.description.toLowerCase()}`.includes(word))) return 2;
            return 9;
        };
        return entries.map((entry) => [rank(entry), entry]).filter(([score]) => score < 9).sort((a, b) => a[0] - b[0]).map(([, entry]) => entry);
    }

    function showMenu() {
        const words = query();
        if (words === null || controller) { closeMenu(); return; }
        skillForm.hidden = true;
        shown = matches(words.toLowerCase().trim()).slice(0, 40);
        selected = Math.min(selected, Math.max(shown.length - 1, 0));
        menuHead.textContent = words.trim() ? `Skills and recipes matching “${words.trim()}”` : 'Skills and recipes: keep typing to narrow them down';

        menuList.replaceChildren(...shown.map((entry, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.id = `skill-option-${index}`;
            button.className = 'ai-skill-choice';
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', index === selected ? 'true' : 'false');
            const title = document.createElement('strong');
            title.textContent = entry.title;
            const meta = document.createElement('small');
            meta.textContent = entry.kind === 'recipe' ? 'Recipe' : (entry.changes ? `/${entry.name} · changes things` : `/${entry.name}`);
            const about = document.createElement('span');
            about.textContent = entry.description;
            button.append(title, meta, about);
            button.addEventListener('mousedown', (event) => event.preventDefault()); // keep focus in the message box
            button.addEventListener('click', () => { selected = index; pick(); });
            item.append(button);
            return item;
        }));
        if (!shown.length) {
            const item = document.createElement('li');
            item.className = 'muted small ai-skill-none';
            item.textContent = entries.length ? 'No skill or recipe matches. Press Enter to send this as a message, or delete letters to see more.' : 'No skills are available to you.';
            menuList.replaceChildren(item);
        }
        menu.hidden = false;
        if (shown.length) {
            input.setAttribute('aria-activedescendant', `skill-option-${selected}`);
            menuList.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
        }
    }

    function move(step) {
        if (!shown.length) return;
        selected = (selected + step + shown.length) % shown.length;
        showMenu();
    }

    function pick() {
        const entry = shown[selected];
        if (!entry) return;
        closeMenu();
        openForm(entry);
    }

    input.addEventListener('keydown', (event) => {
        if (!menu.hidden) {
            if (event.key === 'ArrowDown') { event.preventDefault(); move(1); return; }
            if (event.key === 'ArrowUp') { event.preventDefault(); move(-1); return; }
            if (((event.key === 'Enter' && !event.shiftKey) || event.key === 'Tab') && shown.length) { event.preventDefault(); pick(); return; }
            if (event.key === 'Escape') { event.preventDefault(); dismissed = true; closeMenu(); return; }
        }
        if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); }
    });
    input.addEventListener('input', () => {
        selected = 0;
        if (!input.value.startsWith('/') || input.value === '/') dismissed = false;
        showMenu();
    });
    input.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== input) closeMenu(); }, 150));
    input.addEventListener('focus', showMenu);

    function field(name, schema, required) {
        const label = document.createElement('label');
        label.className = 'field';
        const caption = document.createElement('span');
        caption.textContent = required ? `${name} (required)` : name;
        let control;
        if (schema.enum || schema.type === 'boolean') {
            control = document.createElement('select');
            const options = schema.enum || ['yes', 'no'];
            control.append(new Option('', ''), ...options.map((value) => new Option(value, value)));
        } else if (schema.type === 'object' || (schema.maxLength || 0) > 500) {
            control = document.createElement('textarea');
            control.rows = 3;
            if (schema.type === 'object') control.placeholder = '{"name": "value"}';
        } else {
            control = document.createElement('input');
            control.type = schema.type === 'integer' ? 'number' : 'text';
            if (schema.type === 'array') control.placeholder = 'separate with commas';
        }
        control.name = name;
        control.dataset.type = schema.type || 'string';
        if (required) control.required = true;
        label.append(caption, control);
        if (schema.description) {
            const help = document.createElement('small');
            help.className = 'muted';
            help.textContent = schema.description;
            label.append(help);
        }
        return label;
    }

    function valuesOf(target) {
        const values = {};
        for (const control of target.querySelectorAll('[name]')) {
            const raw = control.value.trim();
            if (raw === '') continue;
            values[control.name] = {
                integer: () => Number.parseInt(raw, 10),
                number: () => Number(raw),
                boolean: () => raw === 'yes',
                array: () => raw.split(',').map((part) => part.trim()).filter(Boolean),
                object: () => parseInput(raw),
            }[control.dataset.type]?.() ?? raw;
        }
        return values;
    }

    function openForm(entry) {
        closeMenu();
        input.value = '';
        const heading = document.createElement('p');
        heading.innerHTML = '<strong></strong> <span class="muted small"></span>';
        heading.querySelector('strong').textContent = entry.title;
        heading.querySelector('span').textContent = entry.kind === 'recipe' ? 'Recipe: your AI follows these instructions' : (entry.changes ? 'Runs as you and changes things' : 'Runs as you');
        const fields = document.createElement('div');
        fields.className = 'field-row field-row-2';
        if (entry.kind === 'recipe') {
            for (const [name, definition] of Object.entries(entry.arguments || {})) {
                fields.append(field(name, { type: 'string', description: definition.description }, definition.required));
            }
        } else {
            const required = entry.schema.required || [];
            for (const [name, schema] of Object.entries(entry.schema.properties || {})) fields.append(field(name, schema, required.includes(name)));
        }
        const actions = document.createElement('div');
        actions.className = 'form-actions';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => { skillForm.hidden = true; input.focus(); });
        const go = document.createElement('button');
        go.type = 'submit';
        go.className = 'btn btn-primary';
        go.textContent = entry.kind === 'recipe' ? 'Send to my AI' : 'Run';
        actions.append(cancel, go);
        skillForm.replaceChildren(heading, fields, actions);
        skillForm.hidden = false;
        skillForm.onsubmit = async (event) => {
            event.preventDefault();
            const values = valuesOf(skillForm);
            skillForm.hidden = true;
            if (entry.kind === 'recipe') {
                const { body } = await post(chat.dataset.recipeUrl.replace('{name}', encodeURIComponent(entry.name)), { arguments: values });
                if (!body.ok) { showError(body.summary || 'That recipe couldn’t be used.'); return; }
                converse(body.data.instructions);
                return;
            }
            const result = await runSkill(entry.name, values);
            const message = resultMessage(entry.name, result, { by: 'you' });
            history.push(message);
            skillNote(message);
            await save([message], null);
        };
        skillForm.querySelector('input, select, textarea')?.focus();
    }
})();

// "Your AIs": presets fill in the form, and Test connection lists the models.
document.querySelectorAll('[data-ai-connection-form]').forEach((form) => {
    const field = (name) => form.querySelector(`[data-ai-${name}]`);
    field('preset')?.addEventListener('change', (event) => {
        const option = event.target.selectedOptions[0];
        if (!option?.value) return;
        field('base-url').value = option.value;
        field('provider').value = option.dataset.provider;
        if (!field('name').value) field('name').value = option.dataset.name;
    });

    field('test').addEventListener('click', async () => {
        const result = field('test-result');
        const base = field('base-url').value.replace(/\/+$/, '');
        const key = field('api-key').value || field('api-key').dataset.savedKey || '';
        const anthropic = field('provider').value === 'anthropic';
        const headers = anthropic
            ? { 'x-api-key': key, 'anthropic-version': '2023-06-01', 'anthropic-dangerous-direct-browser-access': 'true' }
            : (key ? { Authorization: `Bearer ${key}` } : {});
        result.textContent = 'Connecting…';
        try {
            const response = await fetch(`${base}/models`, { headers });
            if (!response.ok) {
                const hint = !key && !/localhost|127\.0\.0\.1/.test(base) ? ' — add your API key first' : (response.status === 401 || response.status === 403 ? ' (check the API key)' : '');
                throw new Error(`answered ${response.status}${hint}`);
            }
            const models = ((await response.json()).data || []).map((model) => model.id).sort();
            if (!models.length) { result.textContent = 'Connected, but there are no models yet (e.g. run “ollama pull qwen2.5:7b”).'; return; }
            const select = field('model-select');
            const current = select.value;
            select.replaceChildren(...models.map((id) => new Option(id, id, false, id === current)));
            result.textContent = `Connected: ${models.length} ${models.length === 1 ? 'model' : 'models'}. Pick one and save.`;
        } catch (failure) {
            result.textContent = failure instanceof TypeError
                ? `Couldn’t reach ${base}. Is it running, and does it allow this page (CORS)?`
                : `It ${failure.message}.`;
        }
    });
});
