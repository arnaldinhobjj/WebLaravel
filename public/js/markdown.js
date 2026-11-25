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
    // 3000: ~2 min), so very deep replies are shown as plain text instead.
    const TOO_DEEP = /^(?:[ \t]{200,}|(?:[ \t]*>){50,})/m;

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
            if (TOO_DEEP.test(String(text ?? ''))) {
                throw new Error('This reply is nested too deeply to format.');
            }
            const html = marked.parse(String(text ?? ''), { gfm: true, breaks: true, async: false });
            target.replaceChildren(DOMPurify.sanitize(html, {
                ALLOWED_TAGS,
                ALLOWED_ATTR,
                ALLOWED_URI_REGEXP: /^(?:https?:|mailto:)/i,
                RETURN_DOM_FRAGMENT: true,
            }));
        },
    };
})();
