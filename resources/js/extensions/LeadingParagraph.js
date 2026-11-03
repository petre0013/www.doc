import { Extension } from '@tiptap/core';
import { Selection, TextSelection } from 'prosemirror-state';

/**
 * Lets you escape ABOVE a block that opens the document.
 *
 * When a document starts with a non-textblock container — a list, table,
 * blockquote, callout — the cursor lives inside it and there is no position
 * above to click or arrow into, so you can't write intro text before it
 * (ProseMirror's gap cursor doesn't reliably catch this at doc start).
 *
 * ArrowUp (from the top line of the first block) and ArrowLeft (from the very
 * first caret position) insert an empty paragraph at the top and drop the
 * cursor there. Guards keep this from touching normal caret navigation:
 * it only acts inside the document's first textblock, only when that first
 * block isn't itself a plain textblock you could already prepend to, and — for
 * ArrowUp — only on the block's top visual line.
 *
 * The escape is reversible: Backspace in that empty leading paragraph (while a
 * non-textblock block still follows it) removes it and drops the cursor back
 * into the block below, so changing your mind costs one keystroke and leaves no
 * stray blank line.
 */
export const LeadingParagraph = Extension.create({
    name: 'leadingParagraph',

    addKeyboardShortcuts() {
        // Shared preconditions: collapsed caret sitting in the document's first
        // textblock, whose top-level block is a non-textblock container.
        const trappedAtStart = () => {
            const { state } = this.editor;
            const { selection, doc } = state;
            if (!selection.empty) return false;
            const first = doc.firstChild;
            if (!first || first.isTextblock) return false;
            return selection.$from.sameParent(Selection.atStart(doc).$from);
        };

        const insertLeadingParagraph = () => {
            const { state, view } = this.editor;
            const tr = state.tr.insert(0, state.schema.nodes.paragraph.create());
            tr.setSelection(TextSelection.create(tr.doc, 1));
            view.dispatch(tr.scrollIntoView());
            return true;
        };

        return {
            // Only on the top visual line, so ArrowUp still moves between wrapped
            // lines of a tall first block.
            ArrowUp: () =>
                trappedAtStart() && this.editor.view.endOfTextblock('up')
                    ? insertLeadingParagraph()
                    : false,
            // Only when there's nothing to the left in the whole document.
            ArrowLeft: () => {
                const { selection, doc } = this.editor.state;
                return trappedAtStart() && selection.from === Selection.atStart(doc).from
                    ? insertLeadingParagraph()
                    : false;
            },
            // Reverse the escape: an empty leading paragraph followed by the
            // non-textblock block we escaped above is removed on Backspace,
            // returning the cursor into that block. Default Backspace does
            // nothing here (there is no block before to join into).
            Backspace: () => {
                const { state, view } = this.editor;
                const { selection, doc } = state;
                if (!selection.empty) return false;
                const { $from } = selection;
                // Empty paragraph that is the document's first block…
                if ($from.parent.type.name !== 'paragraph') return false;
                if ($from.parent.content.size !== 0) return false;
                if ($from.before(1) !== 0) return false;
                // …with a following non-textblock block to fall back into.
                if (doc.childCount < 2 || doc.child(1).isTextblock) return false;

                const tr = state.tr.delete(0, $from.after(1));
                tr.setSelection(Selection.atStart(tr.doc));
                view.dispatch(tr.scrollIntoView());
                return true;
            },
        };
    },
});
