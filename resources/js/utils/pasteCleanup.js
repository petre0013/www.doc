const BLOCK_TAGS = new Set([
    'p', 'div', 'section', 'article', 'header', 'footer',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'blockquote', 'pre', 'figure', 'figcaption',
]);

const KEEP_ATTRS_ON_INLINE = new Set(['href', 'src', 'alt', 'target', 'rel']);

// Word MSO heading class → tag name
const MSO_HEADING = {
    MsoHeading1: 'h1',
    MsoHeading2: 'h2',
    MsoHeading3: 'h3',
    MsoHeading4: 'h4',
    MsoHeading5: 'h5',
    MsoHeading6: 'h6',
};

// Attributes that carry SCHEMA information for specific tags — the signatures
// our own RenderDocument/TipTap emit. Kept so copy/paste round-trips task
// lists, callouts, and code-block languages instead of flattening them.
function isSchemaAttr(tag, name, value) {
    if ((tag === 'ul' || tag === 'li') && name === 'data-type') {
        return value === 'taskList' || value === 'taskItem';
    }
    if (tag === 'li' && name === 'data-checked') return true;
    if (tag === 'div' && name === 'data-callout') return true;
    if (tag === 'code' && name === 'class') return /^language-[\w+#-]+$/.test(value);
    return false;
}

function stripAttributes(el, isBlock) {
    const tag = el.tagName.toLowerCase();
    const toRemove = [];
    for (const attr of el.attributes) {
        const name = attr.name;
        if (isSchemaAttr(tag, name, attr.value)) continue;
        if (isBlock) {
            // Keep only safe inline attributes on block elements
            if (!KEEP_ATTRS_ON_INLINE.has(name)) toRemove.push(name);
        } else {
            // Inline: keep href/src/alt/target/rel/style — strip data-* and class/id
            if (name === 'class' || name === 'id' || name.startsWith('data-') || name.startsWith('on')) {
                toRemove.push(name);
            }
        }
    }
    toRemove.forEach(n => el.removeAttribute(n));
}

function walk(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    const tag = node.tagName.toLowerCase();
    const isBlock = BLOCK_TAGS.has(tag);

    // Convert MSO heading paragraphs
    const cls = node.className || '';
    for (const [msoClass, headingTag] of Object.entries(MSO_HEADING)) {
        if (cls.includes(msoClass)) {
            const heading = node.ownerDocument.createElement(headingTag);
            heading.innerHTML = node.innerHTML;
            node.replaceWith(heading);
            walk(heading);
            return;
        }
    }

    stripAttributes(node, isBlock);

    // Recurse children first so replacements above don't break iteration
    for (const child of Array.from(node.childNodes)) {
        walk(child);
    }
}

/**
 * Sanitise pasted HTML before TipTap processes it.
 * Removes dangerous tags, strips noisy attributes, and converts Word MSO headings.
 */
export function cleanPastedHtml(html) {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // Remove entire subtrees we never want
    const REMOVE_SELECTORS = [
        'script', 'style', 'meta', 'link', 'form', 'input',
        'select', 'textarea', 'button', 'iframe', 'object', 'embed',
        '[style*="display:none"]', '[style*="display: none"]',
    ];
    doc.querySelectorAll(REMOVE_SELECTORS.join(',')).forEach(el => el.remove());

    // Walk and sanitise remaining nodes
    walk(doc.body);

    // Remove block elements left empty after cleaning (but keep <br>)
    doc.querySelectorAll('p, div, li').forEach(el => {
        if (!el.textContent.trim() && !el.querySelector('img, br, table')) {
            el.remove();
        }
    });

    return doc.body.innerHTML;
}
