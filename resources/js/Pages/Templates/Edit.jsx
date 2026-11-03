import { useCallback, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { IconChevronRight, IconDeviceFloppy } from '@tabler/icons-react';
import DocsLayout from '@/Layouts/DocsLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import ConfirmDialog from '@/components/ui/ConfirmDialog';
import TipTapEditor from '@/components/editor/TipTapEditor';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';

/**
 * Edit a page template: name, description and TipTap content. Always in edit
 * mode — a template has no read view, versions or optimistic locking; it's
 * just reusable starting content.
 */
export default function TemplatesEdit({ template }) {
    const [name, setName] = useState(template.name);
    const [description, setDescription] = useState(template.description ?? '');
    const [saving, setSaving] = useState(false);

    // The editor owns content between saves; a ref avoids re-rendering the
    // whole page on every keystroke (same pattern as the document editor).
    const contentRef = useRef(template.content);
    const dirtyRef = useRef(false);
    // The editor's own normalized content at load. It fills schema defaults the
    // stored JSON omits (e.g. textAlign), so comparing raw updates to
    // template.content would read as dirty on load — with the editor always
    // editable here, that popped the discard prompt on leave without any edit.
    const baselineRef = useRef(null);

    const handleEditorReady = useCallback((json) => {
        baselineRef.current = JSON.stringify(json);
        contentRef.current = json;
    }, []);

    const handleEditorUpdate = useCallback((json, userInitiated) => {
        contentRef.current = json;
        // Ignore load-time settling (fires while the editor is unfocused); only a
        // focused edit that actually diverges from the baseline is dirty.
        if (!userInitiated) return;
        dirtyRef.current = baselineRef.current === null
            || JSON.stringify(json) !== baselineRef.current;
    }, []);

    const { promptOpen, confirmDiscard, dismissPrompt } = useUnsavedChangesGuard({
        active: true,
        dirtyRef,
        revert: () => {},
    });

    function save() {
        setSaving(true);
        router.patch(`/templates/${template.id}`, {
            name: name.trim(),
            description: description.trim() || null,
            content: contentRef.current,
        }, {
            onSuccess: () => {
                // The saved content is the new clean state — re-baseline so later
                // edits compare against it, not the original load.
                baselineRef.current = JSON.stringify(contentRef.current);
                dirtyRef.current = false;
            },
            onFinish: () => setSaving(false),
        });
    }

    return (
        <>
        <DocsLayout>
            <Head title={`${template.name} — Templates`} />

            {/* Breadcrumb */}
            <div className="flex items-center gap-1 text-sm text-text-tertiary">
                <Link href="/templates" className="transition-colors hover:text-foreground">Templates</Link>
                <IconChevronRight className="h-3.5 w-3.5" stroke={1.5} />
                <span className="text-text-secondary">{template.name}</span>
            </div>

            {/* Name + description + save */}
            <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <input
                        type="text"
                        value={name}
                        onChange={(e) => { setName(e.target.value); dirtyRef.current = true; }}
                        placeholder="Template name"
                        className="w-full max-w-xl rounded-sm border border-transparent bg-transparent px-2 py-1 text-[19px] font-semibold text-foreground outline-none transition-[border-color,box-shadow] duration-150 hover:border-border focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    />
                    <input
                        type="text"
                        value={description}
                        onChange={(e) => { setDescription(e.target.value); dirtyRef.current = true; }}
                        placeholder="Short description shown in the New page picker (optional)"
                        className="w-full max-w-xl rounded-sm border border-transparent bg-transparent px-2 py-1 text-sm text-text-secondary placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 hover:border-border focus:border-accent-400 focus:ring-[3px] focus:ring-accent-200"
                    />
                </div>
                <Button
                    onClick={save}
                    disabled={saving || !name.trim()}
                    className="self-start bg-accent-400 hover:bg-accent-500 text-text-inverse"
                >
                    <IconDeviceFloppy stroke={1.5} />
                    {saving ? 'Saving…' : 'Save'}
                </Button>
            </div>

            {/* Content */}
            <div className="mt-4">
                <Card className="overflow-clip">
                    <TipTapEditor
                        content={template.content}
                        editable={true}
                        suggestions={[]}
                        onReady={handleEditorReady}
                        onUpdate={handleEditorUpdate}
                    />
                </Card>
            </div>
        </DocsLayout>

        <ConfirmDialog
            open={promptOpen}
            title="Discard changes?"
            message="You have unsaved template changes. Leaving this page will discard them permanently."
            confirmLabel="Discard changes"
            cancelLabel="Keep editing"
            variant="danger"
            onConfirm={confirmDiscard}
            onCancel={dismissPrompt}
        />
        </>
    );
}
