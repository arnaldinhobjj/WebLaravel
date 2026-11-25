// Renders AI replies as Markdown with marked (public/vendor/marked), then
// cleans the HTML with DOMPurify (public/vendor/dompurify): marked doesn't
// sanitise, and replies can be steered by pages the AI reads. Only text
// formatting survives: no images, no raw HTML, links only http(s)/mailto.
window.FortValeMarkdown = (() => {
    const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote', 'hr',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a'];
    const ALLOWED_ATTR = ['href', 'title', 'start', 'align'];
    // marked slows down sharply with nesting (400 list levels: ~0.3 s per render,
    // 3000: ~2 min; 1000 "- " on one line: ~2 s), so very deep replies are shown
    // as plain text instead. Leading whitespace sits outside the repeats so a
    // near-miss can't backtrack exponentially.
    // The last pattern catches markers mixed without spaces ('>>- >>- '); a long
    // '---' rule also matches it, which only means plain text.
    const TOO_DEEP = /^(?:[ \t]{200,}|[ \t]*(?:>[ \t]*){50,}|[ \t]*(?:(?:[-*+>]|\d{1,9}[.)])[ \t]+){30,}|[ \t>*+-]{100,})/m;
    // marked's emphasis parsing grows quadratically with length (40 KB of '*a _' ~5 s),
    // so very long replies are shown as plain text too.
    const MAX_LENGTH = 20000;
    const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

    // Raw HTML in a reply (e.g. "wrap it in a <div>", List<String>) is shown as text, not dropped.
    marked.use({ renderer: { html: ({ text }) => escapeHtml(text) } });

    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        // Links keep their real address on hover; clicking one copies it (see ai-chat.js).
        if (node.tagName === 'A') {
            if (node.getAttribute('href')) {
                node.setAttribute('title', node.getAttribute('href'));
            } else {
                node.replaceWith(...node.childNodes);
            }
        }
    });

    return {
        /** Replaces the element's contents with the rendered Markdown. */
        render(target, text) {
            const source = String(text ?? '');
            if (source.length > MAX_LENGTH || TOO_DEEP.test(source)) {
                throw new Error('This reply is too long or nested too deeply to format.');
            }
            const html = marked.parse(source, { gfm: true, breaks: true, async: false });
            target.replaceChildren(DOMPurify.sanitize(html, {
                ALLOWED_TAGS,
                ALLOWED_ATTR,
                ALLOWED_URI_REGEXP: /^(?:https?:|mailto:)/i,
                // Otherwise the URI rule above also strips these (table alignment, list start).
                ADD_URI_SAFE_ATTR: ['align', 'start'],
                RETURN_DOM_FRAGMENT: true,
            }));
        },
    };
})();
