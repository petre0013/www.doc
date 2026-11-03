import React from 'react';
import { IconAlertTriangle, IconRefresh, IconDeviceFloppy } from '@tabler/icons-react';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import TipTapEditor from '@/components/editor/TipTapEditor';

/**
 * Shown when an optimistic-locking check rejects a save: the page was edited by
 * someone else after this editor loaded it. We don't auto-merge — the user sees
 * their draft next to the server's current version and chooses which wins.
 *
 * `theirs` is the fresh server state from the saveConflict flash
 * ({ title, content, version, updated_at, updated_by }); `mine` is the current
 * editor JSON (the unsaved draft).
 */
export default function ConflictDialog({ open, theirs, mine, resolvedLinks = {}, onReloadTheirs, onOverwrite, onKeepEditing }) {
    if (!theirs) return null;

    const who = theirs.updated_by ? `by ${theirs.updated_by}` : 'by someone else';

    return (
        <Dialog open={open} onOpenChange={(next) => { if (!next) onKeepEditing?.(); }}>
            <DialogContent className="max-w-5xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <IconAlertTriangle className="h-5 w-5 text-amber-600" stroke={1.5} />
                        This page changed while you were editing
                    </DialogTitle>
                    <DialogDescription>
                        It was updated {who} after you started editing, so your save was held back to
                        avoid overwriting their changes. Compare the two versions and choose how to proceed.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="flex flex-col gap-2 min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wide text-text-tertiary">
                            Their version (current)
                        </p>
                        <div className="max-h-72 overflow-auto rounded-lg border border-border-subtle bg-surface-sunken/40 p-3">
                            <TipTapEditor
                                key="conflict-theirs"
                                content={theirs.content}
                                editable={false}
                                resolvedLinks={resolvedLinks}
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-2 min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wide text-text-tertiary">
                            Your version (unsaved)
                        </p>
                        <div className="max-h-72 overflow-auto rounded-lg border border-border-subtle bg-surface-sunken/40 p-3">
                            <TipTapEditor
                                key="conflict-mine"
                                content={mine}
                                editable={false}
                                resolvedLinks={resolvedLinks}
                            />
                        </div>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={onKeepEditing}>
                        Keep editing
                    </Button>
                    <Button variant="outline" onClick={onReloadTheirs}>
                        <IconRefresh className="h-4 w-4" stroke={1.5} />
                        Reload theirs
                    </Button>
                    <Button onClick={onOverwrite}>
                        <IconDeviceFloppy className="h-4 w-4" stroke={1.5} />
                        Overwrite with mine
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
