// Renders AI replies as Markdown with marked (public/vendor/marked), then
// cleans the HTML with DOMPurify (public/vendor/dompurify): marked doesn't
// sanitise, and replies can be steered by pages the AI reads. Only text
// formatting survives: no images, no raw HTML, links only http(s)/mailto.
window.FortValeMarkdown = (() => {
    const ALLOWED_TAGS = ['p', 'br', 'strong', 'em', 'del', 'code', 'pre', 'blockquote', 'hr',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a'];
    const ALLOWED_ATTR = ['href', 'title', 'start', 'align'];
    // Two inputs make marked slow in real browsers (timed in WebKit and V8, not jsdom,
    // whose much slower numbers once suggested guarding one-line nesting too):
    // - deep indentation: a 3000-level list ~1.1 s per render, so lines indented
    //   200+ characters show as plain text (200 tab-levels still render in ~80 ms);
    // - emphasis parsing grows quadratically with length (19 KB of '*a _' ~0.7-1.2 s,
    //   40 KB ~3 s), so replies over 20,000 characters show as plain text.
    // Other pathological input that makes marked throw also falls back to plain text.
    const TOO_DEEP = /^[ \t]{200,}/m;
    const MAX_LENGTH = 20000;
    const escapeHtml = (text) => text.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

    // Raw HTML in a reply (e.g. "wrap it in a <div>", List<String>) is shown as text, not dropped.
    marked.use({ renderer: { html: ({ text }) => escapeHtml(text) } });

    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        // Links keep their real address on hover; clicking one asks before leaving (see ai-chat.js).
        if (node.tagName === 'A' && node.getAttribute('href')) {
            node.setAttribute('title', node.getAttribute('href'));
        }
    });

    // Links whose address was rejected become plain text. This runs after DOMPurify has
    // finished: moving a node's children while DOMPurify still walks the tree makes it
    // skip them, leaving them unsanitised.
    const unwrapLinksWithoutAddress = (fragment) => {
        fragment.querySelectorAll('a:not([href])').forEach((link) => link.replaceWith(...link.childNodes));
        return fragment;
    };

    return {
        /** Replaces the element's contents with the rendered Markdown. */
        render(target, text) {
            const source = String(text ?? '');
            if (source.length > MAX_LENGTH || TOO_DEEP.test(source)) {
                throw new Error('This reply is too long or nested too deeply to format.');
            }
            const html = marked.parse(source, { gfm: true, breaks: true, async: false });
            target.replaceChildren(unwrapLinksWithoutAddress(DOMPurify.sanitize(html, {
                ALLOWED_TAGS,
                ALLOWED_ATTR,
                ALLOWED_URI_REGEXP: /^(?:https?:|mailto:)/i,
                // Otherwise the URI rule above also strips these (table alignment, list start).
                ADD_URI_SAFE_ATTR: ['align', 'start'],
                RETURN_DOM_FRAGMENT: true,
            })));
        },
    };
})();
