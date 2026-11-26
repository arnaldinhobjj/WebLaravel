// Badges for AI tasks that actions started (the ai/report step), on every
// page, top right: "Working…" while the AI writes (click to watch it on /ai),
// "Waiting…" while an earlier task runs, then the finished report with Open
// (to My files). The stack can be closed to a caret; while it's closed, a
// number on the caret counts reports that finished and weren't opened yet.
(() => {
    const root = document.querySelector('[data-ai-tasks]');
    if (!root) return;

    const list = root.querySelector('[data-ai-tasks-list]');
    const toggle = root.querySelector('[data-ai-tasks-toggle]');
    const toggleLabel = root.querySelector('[data-ai-tasks-toggle-label]');
    const count = root.querySelector('[data-ai-tasks-count]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const STORE = 'fv.aiTasks';
    const ACTIVE = ['queued', 'working', 'stopping'];

    let tasks = JSON.parse(root.querySelector('[data-ai-tasks-initial]')?.textContent || '[]');
    let timer = null;
    let closed = false;
    // Badges already on screen: only new ones slide in (the stack is redrawn on every check).
    const shown = new Set(tasks.map((task) => task.id));
    try { closed = localStorage.getItem(STORE) === 'closed'; } catch { /* private window */ }

    // keepalive: Open navigates away at once, and the request must still arrive.
    const send = (url, method = 'POST') => fetch(url, {
        method,
        keepalive: true,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    }).catch(() => null);

    const icon = (status) => {
        const span = document.createElement('span');
        span.className = `ai-task-icon is-${status}`;
        span.setAttribute('aria-hidden', 'true');
        if (status === 'done') span.textContent = '✓';
        else if (status === 'failed' || status === 'cancelled') span.textContent = '!';
        return span;
    };

    function badge(task) {
        const item = document.createElement('li');
        item.className = `ai-task is-${task.status}${shown.has(task.id) ? '' : ' is-new'}`;
        shown.add(task.id);

        const active = ACTIVE.includes(task.status);
        // While it runs, the whole badge opens the chat it's writing in.
        const main = document.createElement(active && task.chat_url ? 'a' : 'div');
        main.className = 'ai-task-main';
        if (active && task.chat_url) {
            main.href = task.chat_url;
            main.title = 'Watch it in the AI chat';
        }

        const text = document.createElement('span');
        text.className = 'ai-task-text';
        const state = document.createElement('strong');
        state.textContent = {
            queued: task.waiting ? 'Waiting…' : 'Starting…',
            working: 'Working…',
            stopping: 'Stopping…',
            done: 'Report ready',
            failed: 'Report failed',
            cancelled: 'Stopped',
        }[task.status] || task.status;
        const title = document.createElement('span');
        title.className = 'ai-task-title';
        title.textContent = task.title;
        text.append(state, title);
        if (task.status === 'failed' && task.error) {
            const error = document.createElement('span');
            error.className = 'ai-task-error';
            error.textContent = task.error;
            text.append(error);
        }
        main.append(icon(task.status), text);
        item.append(main);

        const buttons = document.createElement('span');
        buttons.className = 'ai-task-buttons';
        if (task.status === 'done' && task.file_url) {
            const open = document.createElement('a');
            open.className = 'btn btn-primary';
            open.href = task.file_url;
            open.textContent = 'Open';
            // Once opened, the report has been seen: its badge goes away.
            open.addEventListener('click', () => {
                task.seen = true;
                send(task.dismiss_url, 'DELETE');
            });
            buttons.append(open);
        }
        if (!active && task.chat_url) {
            const chat = document.createElement('a');
            chat.className = 'btn';
            chat.href = task.chat_url;
            chat.textContent = 'Chat';
            buttons.append(chat);
        }
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'ai-task-close';
        close.setAttribute('aria-label', active ? `Stop “${task.title}”` : `Close “${task.title}”`);
        close.title = active ? 'Stop and close' : 'Close';
        close.textContent = '×';
        close.addEventListener('click', async () => {
            if (active && !window.confirm(`Stop “${task.title}”? What the AI wrote so far stays in its chat.`)) return;
            tasks = tasks.filter((other) => other.id !== task.id);
            draw();
            await send(task.dismiss_url, 'DELETE');
            refresh();
        });
        item.append(close);
        if (buttons.children.length) item.append(buttons);

        return item;
    }

    function draw() {
        root.hidden = tasks.length === 0;
        list.replaceChildren(...tasks.map(badge));
        root.classList.toggle('is-closed', closed);
        list.hidden = closed;
        toggle.setAttribute('aria-expanded', String(!closed));
        toggleLabel.textContent = closed ? 'Show AI tasks' : 'Hide AI tasks';

        // Finished reports not opened yet, shown on the caret while the stack is closed.
        const unseen = tasks.filter((task) => task.status === 'done' && !task.seen).length;
        count.textContent = String(unseen);
        count.hidden = !closed || unseen === 0;
    }

    async function refresh() {
        clearTimeout(timer);
        try {
            const response = await fetch(root.dataset.url, { headers: { Accept: 'application/json' } });
            if (response.ok) tasks = (await response.json()).tasks;
        } catch {
            // Offline for a moment: keep what we have and try again.
        }
        draw();
        schedule();
    }

    // Check often while something runs; otherwise wait for the next page.
    function schedule() {
        if (tasks.some((task) => ACTIVE.includes(task.status))) {
            timer = setTimeout(refresh, document.hidden ? 15000 : 3000);
        }
    }

    toggle.addEventListener('click', () => {
        closed = !closed;
        try { localStorage.setItem(STORE, closed ? 'closed' : 'open'); } catch { /* private window */ }
        draw();
    });
    document.addEventListener('fortvale:ai-tasks-changed', refresh);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && timer) refresh(); });

    draw();
    schedule();
})();
