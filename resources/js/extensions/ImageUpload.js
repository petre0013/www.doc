import { Extension } from '@tiptap/core';
import { Plugin, TextSelection } from 'prosemirror-state';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export async function uploadFile(file) {
    const form = new FormData();
    form.append('file', file);
    const res = await fetch('/assets', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf() },
        body: form,
    });
    if (!res.ok) throw new Error('Upload failed');
    return res.json();
}

async function rehostUrl(url) {
    const res = await fetch('/assets/rehost', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ url }),
    });
    if (!res.ok) throw new Error('Rehost failed');
    return res.json();
}

export function dataUriToFile(dataUri, filename = 'pasted-image.png') {
    const [header, b64] = dataUri.split(',');
    const mime = header.match(/:(.*?);/)?.[1] ?? 'image/png';
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return new File([bytes], filename, { type: mime });
}

function replaceBlobSrc(view, blobUrl, realUrl) {
    const tr = view.state.tr;
    let changed = false;
    view.state.doc.descendants((node, pos) => {
        if (node.type.name === 'image' && node.attrs.src === blobUrl) {
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, src: realUrl });
            changed = true;
        }
    });
    if (changed) view.dispatch(tr);
    URL.revokeObjectURL(blobUrl);
}

export function insertFiles(editor, view, files) {
    files.forEach(file => {
        const preview = URL.createObjectURL(file);
        editor
            .chain()
            .focus()
            .setImage({ src: preview })
            // The image is a block atom node, so setImage leaves it as a
            // NodeSelection — it stays "selected", and the next paste/keystroke
            // REPLACES it instead of adding below. Drop a text cursor just after
            // the image so it deselects and subsequent inserts stack downward.
            .command(({ tr, state, dispatch }) => {
                if (dispatch) {
                    const after = tr.selection.to;
                    // When the image is the last node there's no text position
                    // after it to land in (no trailing paragraph in this schema),
                    // so a forward cursor would fall back ABOVE the image. Append
                    // an empty paragraph so the cursor sits below it instead.
                    if (after >= tr.doc.content.size) {
                        tr.insert(after, state.schema.nodes.paragraph.create());
                    }
                    tr.setSelection(TextSelection.near(tr.doc.resolve(after), 1));
                }
                return true;
            })
            .run();
        uploadFile(file)
            .then(({ url }) => replaceBlobSrc(view, preview, url))
            .catch(() => URL.revokeObjectURL(preview));
    });
}

/**
 * Handles image paste (files, base64 data URIs, external URLs) and image file drop.
 * Each image is uploaded/rehosted via the asset API, then stored as a permanent URL.
 */
export const ImageUpload = Extension.create({
    name: 'imageUpload',

    addProseMirrorPlugins() {
        const editor = this.editor;

        return [
            new Plugin({
                props: {
                    handlePaste(view, event) {
                        const cd = event.clipboardData;
                        if (!cd) return false;

                        const imageFiles = Array.from(cd.files).filter(f => f.type.startsWith('image/'));
                        const html       = cd.getData('text/html');
                        const parsed     = html ? new DOMParser().parseFromString(html, 'text/html') : null;
                        const imgs       = parsed ? Array.from(parsed.querySelectorAll('img')) : [];

                        // An <img> is only useful to us if we can turn its src into a hosted
                        // asset: data: (upload), http(s): (rehost), or file:// (paired with
                        // cd.files). Anything else — notably the origin-scoped blob: URLs that
                        // web apps like WhatsApp put on the clipboard — is a dead reference we
                        // can neither fetch nor save; the real bytes ride along in cd.files.
                        const srcOf        = img => img.getAttribute('src') ?? '';
                        const isResolvable = src => src.startsWith('data:image/')
                            || /^https?:\/\//i.test(src)
                            || src.startsWith('file://');
                        const resolvableImgs = imgs.filter(img => isResolvable(srcOf(img)));

                        // Raw image bitmap(s) with no resolvable HTML <img> — a screenshot, a
                        // copy-image, or a web app whose HTML only offers a blob: reference.
                        // Insert the file directly; the HTML would only give us a dead link.
                        if (imageFiles.length > 0 && resolvableImgs.length === 0) {
                            event.preventDefault();
                            insertFiles(editor, view, imageFiles);
                            return true;
                        }

                        // HTML paste containing resolvable <img> — handles three src formats:
                        //   data:image/…  → upload directly
                        //   https?://…    → rehost via asset API
                        //   file://…      → pair positionally with cd.files and upload
                        //                   (LibreOffice on Linux puts images as file:// refs
                        //                    alongside the image file in cd.files)
                        if (resolvableImgs.length > 0) {
                            event.preventDefault();
                            // fileIdx increments synchronously inside map() before any await,
                            // so positional pairing with cd.files is deterministic.
                            let fileIdx = 0;
                            (async () => {
                                await Promise.all(imgs.map(async img => {
                                    const src = img.getAttribute('src') ?? '';
                                    try {
                                        if (src.startsWith('data:image/')) {
                                            const { url } = await uploadFile(dataUriToFile(src));
                                            img.setAttribute('src', url);
                                        } else if (/^https?:\/\//i.test(src)) {
                                            // A single "Copy Image" from a browser puts the decoded
                                            // bitmap on the clipboard alongside an <img> pointing at
                                            // the ORIGINAL remote URL. Prefer those exact local bytes:
                                            // re-fetching the remote URL (rehost) fails for
                                            // hotlink-protected / redirecting / auth'd images, and on
                                            // failure we'd fall back to the dead external reference —
                                            // the image displays but is never saved locally. Multi-image
                                            // HTML pastes carry no bitmaps, so they still rehost each URL.
                                            if (imgs.length === 1 && imageFiles.length > 0) {
                                                const { url } = await uploadFile(imageFiles[0]);
                                                img.setAttribute('src', url);
                                            } else {
                                                const { url } = await rehostUrl(src);
                                                img.setAttribute('src', url);
                                            }
                                        } else if (src.startsWith('file://')) {
                                            const file = imageFiles[fileIdx++];
                                            if (file) {
                                                const { url } = await uploadFile(file);
                                                img.setAttribute('src', url);
                                            } else {
                                                img.remove();
                                            }
                                        }
                                    } catch {
                                        if ((img.getAttribute('src') ?? '').startsWith('file://')) img.remove();
                                    }
                                }));
                                editor.chain().focus().insertContent(parsed.body.innerHTML).run();
                            })();
                            return true;
                        }

                        return false;
                    },

                    handleDrop(view, event) {
                        const dt = event.dataTransfer;
                        if (!dt) return false;

                        const imageFiles = Array.from(dt.files).filter(f =>
                            f.type.startsWith('image/')
                        );
                        if (imageFiles.length === 0) return false;

                        event.preventDefault();

                        // Move cursor to drop position
                        const coords = view.posAtCoords({ left: event.clientX, top: event.clientY });
                        if (coords) {
                            view.dispatch(
                                view.state.tr.setSelection(
                                    TextSelection.near(view.state.doc.resolve(coords.pos))
                                )
                            );
                        }

                        insertFiles(editor, view, imageFiles);
                        return true;
                    },
                },
            }),
        ];
    },
});
