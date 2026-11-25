// Dashboard customize screen (/dashboard/{id}/edit).
//
// - Drag widgets by their handle to reorder them; the order saves at once.
// - Drag an item from "Add widgets" onto the dashboard to add it at that spot.
// - Focus a handle and use the arrow keys (Home/End too) to move without a mouse.
// - Settings open in a dialog; a table's column list can be dragged into order.
//
// Pointer events, so it works the same with a mouse, a pen or a finger.
(() => {
    const canvas = document.querySelector('[data-dashboard-canvas]');
    if (!canvas) return;

    const live = document.querySelector('[data-customize-live]');
    const status = document.querySelector('[data-customize-status]');
    const empty = canvas.querySelector('[data-canvas-empty]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const DRAG_THRESHOLD = 5;

    const widgets = () => [...canvas.querySelectorAll('[data-widget-id]')];
    const orderOf = () => widgets().map((widget) => Number(widget.dataset.widgetId));

    const announce = (text) => {
        if (!live) return;
        live.textContent = '';
        window.setTimeout(() => { live.textContent = text; }, 30);
    };

    const showStatus = (text, isError = false) => {
        if (!status) return;
        status.textContent = text;
        status.hidden = false;
        status.classList.toggle('is-error', isError);
        window.clearTimeout(showStatus.timer);
        showStatus.timer = window.setTimeout(() => { status.hidden = true; }, isError ? 8000 : 2500);
    };

    // One save at a time. Moves made while a save is on its way are sent
    // once it finishes, as the order at that moment, so an older order can
    // never arrive after (and overwrite) a newer one.
    let saving = false;
    let saveAgain = false;
    const saveOrder = async () => {
        if (saving) {
            saveAgain = true;
            return;
        }
        saving = true;
        try {
            do {
                saveAgain = false;
                const response = await fetch(canvas.dataset.reorderUrl, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ order: orderOf() }),
                });
                if (!response.ok) throw new Error(String(response.status));
            } while (saveAgain);
            showStatus('Order saved.');
        } catch {
            saveAgain = false;
            showStatus('Couldn’t save the new order. Reload the page and try again.', true);
        } finally {
            saving = false;
        }
    };

    const columnCount = () => getComputedStyle(canvas).gridTemplateColumns.split(' ').filter(Boolean).length;

    // Where a drop at (x, y) goes: before or after the widget under the pointer.
    const dropTarget = (x, y, ignore) => {
        const over = document.elementFromPoint(x, y);
        if (!over || !canvas.contains(over)) return null;

        const widget = over.closest('[data-widget-id]');
        if (!widget || widget === ignore) {
            // Empty space in the grid: after the last widget above the pointer.
            const below = widgets().find((w) => w !== ignore && w.getBoundingClientRect().top > y);
            return { before: below ?? empty };
        }

        const rect = widget.getBoundingClientRect();
        const byRows = columnCount() === 1 || widget.classList.contains('is-wide');
        const first = byRows ? y < rect.top + rect.height / 2 : x < rect.left + rect.width / 2;
        return { before: first ? widget : widget.nextElementSibling };
    };

    const autoScroll = (y) => {
        const edge = 70;
        if (y < edge) window.scrollBy(0, -14);
        else if (y > window.innerHeight - edge) window.scrollBy(0, 14);
    };

    // One drag, from a widget (move) or a library item (add).
    const startDrag = (event, { item, isWide, onDrop }) => {
        const startX = event.clientX;
        const startY = event.clientY;
        const rect = item.getBoundingClientRect();
        const fromCanvas = canvas.contains(item);
        let ghost = null;
        let placeholder = null;

        const begin = () => {
            ghost = item.cloneNode(true);
            ghost.classList.add('drag-ghost');
            ghost.removeAttribute('id');
            ghost.querySelectorAll('[id]').forEach((el) => el.removeAttribute('id'));
            ghost.style.width = `${Math.min(rect.width, 520)}px`;
            document.body.append(ghost);

            placeholder = document.createElement('div');
            placeholder.className = 'drop-placeholder';
            placeholder.classList.toggle('is-wide', isWide);
            placeholder.style.height = `${Math.min(Math.max(rect.height, 90), 260)}px`;

            if (fromCanvas) {
                item.after(placeholder);
                item.hidden = true;
            }
            document.body.classList.add('is-dragging');
            if (empty) empty.hidden = true;
        };

        const move = (e) => {
            if (!ghost) {
                if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return;
                begin();
            }
            e.preventDefault();
            ghost.style.transform = `translate(${e.clientX - 24}px, ${e.clientY - 20}px)`;

            const target = dropTarget(e.clientX, e.clientY, item);
            if (target) {
                if (target.before !== placeholder) canvas.insertBefore(placeholder, target.before ?? empty);
            } else if (!fromCanvas) {
                placeholder.remove();
            }
            autoScroll(e.clientY);
        };

        const finish = (cancelled) => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', escape);
            if (!ghost) return;

            ghost.remove();
            document.body.classList.remove('is-dragging');
            const placed = placeholder.isConnected;
            const index = placed ? [...canvas.children].filter((el) => el.matches('[data-widget-id]:not([hidden])') || el === placeholder).indexOf(placeholder) : -1;

            if (fromCanvas) {
                if (!cancelled && placed) placeholder.replaceWith(item);
                else placeholder.remove();
                item.hidden = false;
            } else {
                placeholder.remove();
            }
            if (empty) empty.hidden = widgets().length > 0;
            if (!cancelled && placed) onDrop(index);
        };

        const up = () => finish(false);
        const cancel = () => finish(true);
        const escape = (e) => { if (e.key === 'Escape') finish(true); };

        window.addEventListener('pointermove', move, { passive: false });
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', escape);
    };

    // Reordering widgets.
    canvas.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('[data-drag-handle]');
        const widget = handle?.closest('[data-widget-id]');
        if (!widget || event.button !== 0) return;
        event.preventDefault();

        const before = orderOf().join(',');
        startDrag(event, {
            item: widget,
            isWide: widget.classList.contains('is-wide'),
            onDrop: () => {
                handle.focus();
                if (orderOf().join(',') === before) return;
                announce(`“${widget.dataset.widgetTitle}” moved to position ${widgets().indexOf(widget) + 1} of ${widgets().length}.`);
                saveOrder();
            },
        });
    });

    canvas.addEventListener('keydown', (event) => {
        const handle = event.target.closest('[data-drag-handle]');
        const widget = handle?.closest('[data-widget-id]');
        if (!widget) return;

        const list = widgets();
        const index = list.indexOf(widget);
        const to = {
            ArrowUp: index - 1, ArrowLeft: index - 1,
            ArrowDown: index + 1, ArrowRight: index + 1,
            Home: 0, End: list.length - 1,
        }[event.key];
        if (to === undefined) return;
        event.preventDefault();
        if (to < 0 || to >= list.length || to === index) return;

        if (to < index) list[to].before(widget);
        else list[to].after(widget);
        handle.focus();
        widget.scrollIntoView({ block: 'nearest' });
        announce(`“${widget.dataset.widgetTitle}” moved to position ${to + 1} of ${list.length}.`);
        saveOrder();
    });

    // Adding from the library, dropped at a spot on the dashboard.
    document.querySelectorAll('[data-library-item]').forEach((item) => {
        item.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.target.closest('button, a, input, select')) return;
            // On touch screens only the handle drags, so the list still scrolls.
            if (event.pointerType === 'touch' && !event.target.closest('[data-drag-handle]')) return;
            event.preventDefault();

            startDrag(event, {
                item,
                isWide: item.dataset.wide === '1',
                onDrop: (index) => {
                    const form = item.querySelector('[data-library-form]');
                    form.querySelector('[data-position-input]').value = String(index + 1);
                    showStatus('Adding…');
                    form.requestSubmit();
                },
            });
        });
    });

    // Settings dialogs: open in place instead of reloading the page.
    document.querySelectorAll('[data-settings-open]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const dialog = document.getElementById(link.dataset.settingsOpen);
            if (!dialog) return;
            event.preventDefault();
            dialog.showModal();
        });
    });
    document.querySelectorAll('dialog.widget-settings').forEach((dialog) => {
        dialog.querySelectorAll('a[data-dialog-close]').forEach((link) => {
            link.addEventListener('click', (event) => { event.preventDefault(); dialog.close(); });
        });
        if (dialog.hasAttribute('data-open-on-load')) {
            dialog.close();
            dialog.showModal();
            if (location.search.includes('widget=')) history.replaceState(null, '', location.pathname);
        }

        // Each table has its own columns and filters; only the chosen one is sent.
        const select = dialog.querySelector('[data-table-select]');
        select?.addEventListener('change', () => {
            dialog.querySelectorAll('[data-table-options]').forEach((fieldset) => {
                const chosen = fieldset.dataset.tableOptions === select.value;
                fieldset.hidden = !chosen;
                fieldset.disabled = !chosen;
            });
        });
    });

    // Column lists: drag (or arrow keys on the handle) to set the column order.
    document.querySelectorAll('[data-sortable-list]').forEach((list) => {
        list.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('[data-drag-handle]');
            const row = handle?.closest('[data-sortable-item]');
            if (!row || event.button !== 0) return;
            event.preventDefault();
            row.classList.add('is-moving');

            const move = (e) => {
                e.preventDefault();
                const siblings = [...list.children].filter((el) => el !== row);
                const next = siblings.find((el) => {
                    const rect = el.getBoundingClientRect();
                    return e.clientY < rect.top + rect.height / 2;
                });
                if (next !== row.nextElementSibling) list.insertBefore(row, next ?? null);
            };
            const up = () => {
                row.classList.remove('is-moving');
                handle.focus();
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', up);
            };
            window.addEventListener('pointermove', move, { passive: false });
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', up);
        });

        list.addEventListener('keydown', (event) => {
            const handle = event.target.closest('[data-drag-handle]');
            const row = handle?.closest('[data-sortable-item]');
            if (!row) return;
            if (event.key === 'ArrowUp' && row.previousElementSibling) {
                row.previousElementSibling.before(row);
            } else if (event.key === 'ArrowDown' && row.nextElementSibling) {
                row.nextElementSibling.after(row);
            } else {
                return;
            }
            event.preventDefault();
            handle.focus();
            announce(`${row.querySelector('label')?.textContent.trim() ?? 'Column'} moved to position ${[...list.children].indexOf(row) + 1}.`);
        });
    });
})();
