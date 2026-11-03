import Image from '@tiptap/extension-image';

/**
 * Extends TipTap's Image with two extra attributes:
 *   width  – pixel width (null = natural size)
 *   align  – 'left' | 'center' | 'right'
 *
 * Renders a NodeView with a corner resize handle (edit mode only).
 * Alignment is applied via a data-align attribute on the wrapper div.
 */
export const ResizableImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            width: {
                default: null,
                parseHTML: el => {
                    const w = el.style.width || el.getAttribute('width');
                    return w ? parseInt(w) : null;
                },
                renderHTML: attrs => (attrs.width ? { width: attrs.width } : {}),
            },
            align: {
                default: 'left',
                parseHTML: el =>
                    el.closest('[data-align]')?.getAttribute('data-align') ??
                    el.getAttribute('data-align') ??
                    'left',
                renderHTML: attrs => ({ 'data-align': attrs.align ?? 'left' }),
            },
        };
    },

    addNodeView() {
        return ({ node, editor, getPos }) => {
            let currentAttrs = { ...node.attrs };

            // Outer block — ProseMirror adds ProseMirror-selectednode here
            const wrapper = document.createElement('div');
            wrapper.className = 'image-wrapper';
            wrapper.setAttribute('data-align', currentAttrs.align ?? 'left');

            // Inner container — inline-block so it shrinks to fit the image
            // and provides a positioning context for the resize handle
            const inner = document.createElement('div');
            inner.className = 'image-inner';
            wrapper.appendChild(inner);

            const img = document.createElement('img');
            img.draggable = false;
            inner.appendChild(img);
            
            // The placeholder/error SVGs carry explicit width+height (not just a
            // viewBox): an <img> pointing at a viewBox-only SVG has no intrinsic
            // size and collapses to 0×0 in Blink until a CSS width is set, so the
            // "Loading…"/"Unavailable" state would be invisible on a fresh paste.
            const failedFallback = "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23FBFAF5%22 stroke=%22%23E2DFD4%22 stroke-width=%222%22 stroke-dasharray=%228%22 rx=%228%22 /%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22system-ui, sans-serif%22 font-size=%2214%22 font-weight=%22500%22 fill=%22%238E938E%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22%3EImage Unavailable%3C/text%3E%3C/svg%3E";
            const loadingFallback = "data:image/svg+xml;charset=UTF-8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22400%22 height=%22300%22 viewBox=%220 0 400 300%22%3E%3Crect width=%22100%25%22 height=%22100%25%22 fill=%22%23FBFAF5%22 stroke=%22%23E2DFD4%22 stroke-width=%222%22 stroke-dasharray=%228%22 rx=%228%22 /%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-family=%22system-ui, sans-serif%22 font-size=%2214%22 font-weight=%22500%22 fill=%22%238E938E%22 text-anchor=%22middle%22 dominant-baseline=%22middle%22%3ELoading image...%3C/text%3E%3C/svg%3E";

            let lastRealSrc = null;

            function loadRealImage(targetSrc) {
                if (lastRealSrc === targetSrc) return;
                lastRealSrc = targetSrc;
                
                img.src = loadingFallback;
                
                if (targetSrc) {
                    const preloader = new window.Image();
                    preloader.onload = () => {
                        if (lastRealSrc === targetSrc) img.src = targetSrc;
                    };
                    preloader.onerror = () => {
                        if (lastRealSrc === targetSrc) img.src = failedFallback;
                    };
                    preloader.src = targetSrc;
                } else {
                    img.src = failedFallback;
                }
            }

            loadRealImage(currentAttrs.src ?? '');
            
            img.alt = currentAttrs.alt ?? '';
            if (currentAttrs.title) img.title = currentAttrs.title;
            if (currentAttrs.width) img.style.width = currentAttrs.width + 'px';

            // Resize handle — only wired up in edit mode
            if (editor.isEditable) {
                const handle = document.createElement('div');
                handle.className = 'image-resize-handle';
                handle.setAttribute('contenteditable', 'false');
                inner.appendChild(handle);

                let startX = 0;
                let startWidth = 0;

                const onMouseMove = (e) => {
                    const newWidth = Math.max(50, startWidth + e.clientX - startX);
                    img.style.width = newWidth + 'px';
                };

                const onMouseUp = (e) => {
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    const newWidth = Math.max(50, startWidth + e.clientX - startX);
                    const pos = typeof getPos === 'function' ? getPos() : null;
                    if (pos !== null) {
                        const tr = editor.state.tr.setNodeMarkup(pos, undefined, {
                            ...currentAttrs,
                            width: newWidth,
                        });
                        editor.view.dispatch(tr);
                    }
                };

                handle.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    startX = e.clientX;
                    startWidth = img.offsetWidth;
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                });
            }

            return {
                dom: wrapper,

                update(updatedNode) {
                    if (updatedNode.type.name !== 'image') return false;
                    currentAttrs = { ...updatedNode.attrs };
                    
                    loadRealImage(currentAttrs.src ?? '');
                    
                    img.alt = currentAttrs.alt ?? '';
                    img.title = currentAttrs.title ?? '';
                    img.style.width = currentAttrs.width ? currentAttrs.width + 'px' : '';
                    wrapper.setAttribute('data-align', currentAttrs.align ?? 'left');
                    return true;
                },

                destroy() {},
            };
        };
    },
});
