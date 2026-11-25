// A small Markdown renderer for AI replies. It builds DOM nodes and only ever
// sets text through textContent, so nothing the AI writes can become HTML.
// Covers what chat models use: paragraphs, headings, lists (nested), quotes,
// code blocks, tables, rules, **bold**, *italic*, `code`, ~~strike~~, links.
window.FortValeMarkdown = (() => {
    const el = (tag, parent) => { const node = document.createElement(tag); parent?.append(node); return node; };

    const INLINE = /(`+)([\s\S]+?)\1|\*\*(\S(?:[\s\S]*?\S)?)\*\*|(?<!\w)__(\S(?:[\s\S]*?\S)?)__(?!\w)|~~(\S(?:[\s\S]*?\S)?)~~|\*([^\s*](?:[\s\S]*?[^\s*])?)\*|(?<!\w)_([^\s_](?:[\s\S]*?[^\s_])?)_(?!\w)|\[([^\]]+)\]\(([^)\s]+)\)|(https?:\/\/[^\s<>()]*[^\s<>().,;:!?'"])/g;
    const SAFE_LINK = /^(https?:\/\/|mailto:)/i;

    function inline(text, parent) {
        let last = 0;
        for (const m of text.matchAll(INLINE)) {
            parent.append(text.slice(last, m.index));
            last = m.index + m[0].length;
            const [, , code, bold, bold2, strike, em, em2, label, href, bare] = m;
            if (code !== undefined) {
                el('code', parent).textContent = code.trim() || code;
            } else if (bold ?? bold2) {
                inline(bold ?? bold2, el('strong', parent));
            } else if (strike) {
                inline(strike, el('del', parent));
            } else if (em ?? em2) {
                inline(em ?? em2, el('em', parent));
            } else if (label && SAFE_LINK.test(href)) {
                link(href, label, parent);
            } else if (label) {
                parent.append(m[0]);
            } else if (bare) {
                link(bare, null, parent);
            }
        }
        parent.append(text.slice(last));
    }

    function link(href, label, parent) {
        const a = el('a', parent);
        a.href = href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        label === null ? (a.textContent = href) : inline(label, a);
    }

    const FENCE = /^\s*(```|~~~)/;
    const HEADING = /^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/;
    const RULE = /^\s{0,3}([-*_])(\s*\1){2,}\s*$/;
    const QUOTE = /^\s{0,3}>\s?/;
    const ITEM = /^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/;
    const TABLE_RULE = /^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/;
    const indentOf = (line) => line.match(/^\s*/)[0].replace(/\t/g, '    ').length;
    const cells = (line) => line.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
    const startsBlock = (line, next) => FENCE.test(line) || HEADING.test(line) || RULE.test(line)
        || QUOTE.test(line) || ITEM.test(line) || (line.includes('|') && TABLE_RULE.test(next ?? ''));

    function blocks(text, parent) {
        const lines = text.replace(/\r\n?/g, '\n').split('\n');
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            if (!line.trim()) { i++; continue; }

            if (FENCE.test(line)) {
                const fence = line.trim().slice(0, 3);
                const body = [];
                for (i++; i < lines.length && !lines[i].trim().startsWith(fence); i++) body.push(lines[i]);
                i++; // closing fence (missing while a reply is still streaming)
                el('code', el('pre', parent)).textContent = body.join('\n');
                continue;
            }

            let m;
            if ((m = line.match(HEADING))) {
                inline(m[2], el(`h${Math.min(m[1].length + 2, 6)}`, parent));
                i++;
                continue;
            }
            if (RULE.test(line)) { el('hr', parent); i++; continue; }

            if (QUOTE.test(line)) {
                const body = [];
                for (; i < lines.length && lines[i].trim() && QUOTE.test(lines[i]); i++) body.push(lines[i].replace(QUOTE, ''));
                blocks(body.join('\n'), el('blockquote', parent));
                continue;
            }

            if ((m = line.match(ITEM))) {
                i = list(lines, i, parent);
                continue;
            }

            if (line.includes('|') && TABLE_RULE.test(lines[i + 1] ?? '')) {
                const wrap = el('div', parent);
                wrap.className = 'md-table';
                const table = el('table', wrap);
                const head = el('tr', el('thead', table));
                cells(line).forEach((c) => inline(c, el('th', head)));
                const body = el('tbody', table);
                for (i += 2; i < lines.length && lines[i].includes('|') && lines[i].trim(); i++) {
                    const row = el('tr', body);
                    cells(lines[i]).forEach((c) => inline(c, el('td', row)));
                }
                continue;
            }

            const p = el('p', parent);
            let first = true;
            for (; i < lines.length && lines[i].trim() && (first || !startsBlock(lines[i], lines[i + 1])); i++) {
                if (!first) el('br', p);
                inline(lines[i].trim(), p);
                first = false;
            }
        }
    }

    // A list and everything indented under its items (more text, nested lists).
    function list(lines, i, parent) {
        const [, lead, marker] = lines[i].match(ITEM);
        const base = indentOf(lead);
        const ordered = /\d/.test(marker);
        const node = el(ordered ? 'ol' : 'ul', parent);
        if (ordered && parseInt(marker, 10) !== 1) node.start = parseInt(marker, 10);

        while (i < lines.length) {
            const m = lines[i].match(ITEM);
            if (!m || indentOf(m[1]) !== base || /\d/.test(m[2]) !== ordered) break;
            const body = [m[3]];
            const inner = base + m[2].length + 1;
            for (i++; i < lines.length; i++) {
                const line = lines[i];
                if (!line.trim()) {
                    const next = lines[i + 1] ?? '';
                    if (next.trim() && indentOf(next) > base) { body.push(''); continue; }
                    break;
                }
                if (indentOf(line) <= base) break;
                body.push(line.slice(Math.min(indentOf(line), inner)));
            }
            const li = el('li', node);
            blocks(body.join('\n'), li);
            // A one-paragraph item reads better without the paragraph's margins.
            if (li.children.length === 1 && li.firstChild.tagName === 'P') li.replaceChildren(...li.firstChild.childNodes);
            // A blank line between items keeps the list going.
            while (i < lines.length && !lines[i].trim() && ITEM.test(lines[i + 1] ?? '') && indentOf(lines[i + 1].match(ITEM)[1]) === base) i++;
        }
        return i;
    }

    return {
        /** Replaces the element's contents with the rendered Markdown. */
        render(target, text) {
            const fragment = document.createDocumentFragment();
            blocks(String(text ?? ''), fragment);
            target.replaceChildren(fragment);
        },
    };
})();
