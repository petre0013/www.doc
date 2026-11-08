import {
    IconDatabaseExport, IconFileText, IconFolder, IconFolders, IconHistory, IconLibrary,
    IconSettings, IconShieldLock, IconTag, IconTemplate, IconTrash, IconUsers,
} from '@tabler/icons-react';

/**
 * Presentation layer for audit events: ONE row per event mapping the stable
 * `subject.action` code to a human sentence, a tone, and a namespace icon.
 *
 * The raw code stays the contract (filters, exports, the DB) — this file only
 * decides how it reads on the admin page. When adding an event via
 * Audit::record(), add its row here (see .claude/rules/audit.md); unmapped
 * events fall back to a humanized code with the neutral tone, so forgetting
 * degrades gracefully instead of breaking.
 *
 * `text(context)` returns the phrase that follows the actor's name
 * ("<Actor> edited "Runbook""). Entries with `actorless: true` are complete
 * sentences on their own (unauthenticated or system-side events).
 * `change(context)` yields optional from → to chips.
 */

const quoted = (s) => (s ? <span className="font-medium">“{s}”</span> : <span className="font-medium">a page</span>);

function bytes(n) {
    if (n == null) return null;
    if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
    if (n >= 1024) return `${Math.round(n / 1024)} KB`;
    return `${n} B`;
}

const EVENTS = {
    // One code, three provenances: typed by hand, instantiated from a template,
    // or converted from an uploaded file. The verb follows the provenance — the
    // event code stays document.created either way.
    'document.created':          { tone: 'good',    text: (c) => <>{c?.import ? 'imported' : 'created'} {quoted(c?.title)}{c?.import ? <> from <span className="font-medium">{c.import}</span></> : ''}{c?.template ? <> from the <span className="font-medium">{c.template}</span> template</> : ''}{c?.folder ? <> in the <span className="font-medium">{c.folder}</span> folder</> : ''}</> },
    'document.updated':          { tone: 'neutral', text: (c) => <>edited {quoted(c?.title)}</> },
    'document.tags_changed':     { tone: 'neutral', text: (c) => <>changed the tags on {quoted(c?.title)}</>, change: (c) => ({ from: c?.from, to: c?.to }) },
    'document.moved': {
        tone: 'neutral',
        text: (c) => <>moved {quoted(c?.title)}</>,
        // Shared by two shapes. A folder refile carries readable folder names, so
        // it gets from→to chips; a re-parent / cross-workspace move carries raw
        // ids (from/to objects), which are not chip material — so no chips there.
        change: (c) => (c && ('from_folder' in c || 'to_folder' in c))
            ? { from: c.from_folder ?? 'Loose', to: c.to_folder ?? 'Loose' }
            : undefined,
    },
    'document.trashed':          { tone: 'warning', text: (c) => <>moved {quoted(c?.title)} to the trash</> },
    'document.restored':         { tone: 'good',    text: (c) => <>restored {quoted(c?.title)} from the trash</> },
    'document.purged':           { tone: 'danger',  text: (c) => <>permanently deleted {quoted(c?.title)}</> },
    'document.version_restored': {
        tone: 'good',
        text: (c) => <>restored {quoted(c?.title)} to an earlier version{c?.version_date ? ` (${new Date(c.version_date).toLocaleDateString()})` : ''}</>,
    },

    'document.exported':  { tone: 'neutral', text: (c) => <>exported {quoted(c?.title)}{c?.format ? ` as ${c.format.toUpperCase()}` : ''}</> },

    'attachment.added':      { tone: 'good',    text: (c) => <>attached <span className="font-medium">{c?.name}</span> to {quoted(c?.title)}</> },
    'attachment.downloaded': { tone: 'neutral', text: (c) => <>downloaded <span className="font-medium">{c?.name}</span> from {quoted(c?.title)}</> },
    'attachment.renamed':    { tone: 'neutral', text: (c) => <>renamed an attachment on {quoted(c?.title)}</>, change: (c) => ({ from: c?.from, to: c?.to }) },
    'attachment.removed':    { tone: 'warning', text: (c) => <>removed <span className="font-medium">{c?.name}</span> from {quoted(c?.title)}</> },

    'control.enabled':   { tone: 'good',    text: (c) => <>placed {quoted(c?.title)} under document control{c?.doc_number ? ` as ${c.doc_number}` : ''}</> },
    'control.updated':   { tone: 'neutral', text: (c) => <>changed the document control details of {quoted(c?.title)}</> },
    'control.submitted': { tone: 'neutral', text: (c) => <>submitted {quoted(c?.title)} for approval</> },
    'control.approved':  { tone: 'good',    text: (c) => <>approved {quoted(c?.title)}</> },
    'control.rejected':  { tone: 'warning', text: (c) => <>sent {quoted(c?.title)} back with comments</> },
    'control.reviewed':  { tone: 'good',    text: (c) => <>confirmed the review of {quoted(c?.title)}</> },
    'control.retired':   { tone: 'warning', text: (c) => <>retired {quoted(c?.title)}</> },

    'register.created':  { tone: 'good',    text: (c) => <>added {c?.reference} {quoted(c?.title)}</> },
    'register.updated':  { tone: 'neutral', text: (c) => <>updated {c?.reference} {quoted(c?.title)}{c?.changed?.length ? ` (${c.changed.join(', ')})` : ''}</> },
    'register.deleted':  { tone: 'danger',  text: (c) => <>deleted {c?.reference} {quoted(c?.title)}</> },
    'register.linked':   { tone: 'neutral', text: (c) => <>added a link to {c?.reference}</> },
    'register.unlinked': { tone: 'neutral', text: (c) => <>removed a link from {c?.reference}</> },
    'register.risk_accepted': { tone: 'warning', text: (c) => <>accepted the residual risk of {c?.reference} {quoted(c?.title)}</> },
    'register.catalogue_installed': { tone: 'good', text: (c) => <>added {c?.created} NIS2 requirements</> },
    'control.acknowledged': { tone: 'neutral', text: (c) => <>confirmed reading {quoted(c?.title)}</> },
    'audit.pack_exported': { tone: 'warning', text: () => 'exported an audit pack' },

    'access.denied':      { tone: 'danger',  text: (c) => <>was refused access to a restricted workspace{c?.path ? <> (<span className="font-medium">/{c.path}</span>)</> : ''}</> },
    'user.anonymised':    { tone: 'danger',  text: (c) => <>anonymised the account of former user #{c?.user_id}</> },

    'audit.exported':     { tone: 'warning', text: () => 'exported the audit trail' },
    'audit.pruned':       { tone: 'warning', actorless: true, text: (c) => <>Retention removed {c?.events ?? 'some'} audit events older than {c?.before}</> },
    'audit.chain_broken': { tone: 'danger',  actorless: true, text: (c) => <>Audit trail integrity check failed at event {c?.broken_at}</> },

    'workspace.created':       { tone: 'good',    text: (c) => <>created the workspace {quoted(c?.name)}{c?.group ? <> in the group {quoted(c.group)}</> : ''}</> },
    'workspace.renamed':       { tone: 'neutral', text: () => 'renamed a workspace', change: (c) => ({ from: c?.from, to: c?.to }) },
    'workspace.updated':       { tone: 'neutral', text: (c) => <>updated the description of {quoted(c?.name)}</>, change: (c) => ({ from: c?.from, to: c?.to }) },
    'workspace.restructured':  { tone: 'neutral', text: (c) => <>reorganised the page tree in {quoted(c?.name)}{c?.page_count ? ` (${c.page_count} pages)` : ''}</> },
    'workspace.moved':         { tone: 'neutral', text: (c) => <>moved the workspace {quoted(c?.name)}</>, change: (c) => ({ from: c?.from_group ?? 'Ungrouped', to: c?.to_group ?? 'Ungrouped' }) },
    'workspace.access_changed': {
        tone: 'warning',
        text: (c) => <>changed who can access {quoted(c?.name)}{c?.added?.length ? ` · added ${c.added.join(', ')}` : ''}{c?.removed?.length ? ` · removed ${c.removed.join(', ')}` : ''}</>,
        change: (c) => (c?.was !== c?.restricted ? { from: c?.was ? 'Restricted' : 'Everyone', to: c?.restricted ? 'Restricted' : 'Everyone' } : undefined),
    },
    'workspace.trashed':       { tone: 'warning', text: (c) => <>moved the workspace {quoted(c?.name)} to the trash</> },
    'workspace.restored':      { tone: 'good',    text: (c) => <>restored the workspace {quoted(c?.name)}</> },
    'workspace.purged':        { tone: 'danger',  text: (c) => <>permanently deleted the workspace {quoted(c?.name)}</> },

    'group.created':           { tone: 'good',    text: (c) => <>created the group {quoted(c?.name)}</> },
    'group.renamed':           { tone: 'neutral', text: () => 'renamed a group', change: (c) => ({ from: c?.from, to: c?.to }) },
    'group.deleted':           { tone: 'warning', text: (c) => <>deleted the group {quoted(c?.name)}</> },

    'folder.created':          { tone: 'good',    text: (c) => <>created the folder {quoted(c?.name)}{c?.workspace ? <> in {quoted(c.workspace)}</> : ''}</> },
    'folder.renamed':          { tone: 'neutral', text: () => 'renamed a folder', change: (c) => ({ from: c?.from, to: c?.to }) },
    // Non-destructive: its pages revert to loose, so this is a warning, not danger.
    'folder.deleted':          { tone: 'warning', text: (c) => <>deleted the folder {quoted(c?.name)}</> },

    'template.created': {
        tone: 'good',
        text: (c) => c?.from_document
            ? <>saved {quoted(c?.from_document)} as the template <span className="font-medium">{c?.name}</span></>
            : <>created the template <span className="font-medium">{c?.name}</span></>,
    },
    'template.updated': { tone: 'neutral', text: (c) => <>edited the template <span className="font-medium">{c?.name}</span></> },
    'template.deleted': { tone: 'danger',  text: (c) => <>deleted the template <span className="font-medium">{c?.name}</span></> },

    'tag.created': { tone: 'good',    text: (c) => <>created the tag <span className="font-medium">{c?.name}</span></> },
    'tag.renamed': { tone: 'neutral', text: () => 'renamed a tag', change: (c) => ({ from: c?.from, to: c?.to }) },
    'tag.deleted': { tone: 'danger',  text: (c) => <>deleted the tag <span className="font-medium">{c?.name}</span>{c?.documents ? ` (used on ${c.documents} page${c.documents === 1 ? '' : 's'})` : ''}</> },

    'trash.emptied': {
        tone: 'danger',
        text: (c) => <>emptied the trash{c ? ` (${c.workspaces ?? 0} workspaces, ${c.documents ?? 0} pages)` : ''}</>,
    },

    'user.created':      { tone: 'good',    text: (c) => <>created the user {c?.name ? <span className="font-medium">{c.name}</span> : ''}{c?.role ? ` (${c.role})` : ''}</> },
    'user.role_changed': { tone: 'warning', text: (c) => <>changed {c?.name ? <span className="font-medium">{c.name}</span> : 'a user'}’s role</>, change: (c) => ({ from: c?.from, to: c?.to }) },
    'user.deactivated':  { tone: 'danger',  text: (c) => <>deactivated the user {c?.name ? <span className="font-medium">{c.name}</span> : ''}{c?.email ? ` (${c.email})` : ''}</> },
    'user.reactivated':  { tone: 'warning', text: (c) => <>reactivated the user {c?.name ? <span className="font-medium">{c.name}</span> : ''}{c?.email ? ` (${c.email})` : ''}</> },
    'user.email_changed': { tone: 'warning', text: () => 'changed their email address', change: (c) => ({ from: c?.from, to: c?.to }) },
    'user.renamed':      { tone: 'neutral', text: () => 'changed their display name', change: (c) => ({ from: c?.from, to: c?.to }) },
    'user.deleted':      { tone: 'danger',  text: (c) => <>deleted the user {c?.name ? <span className="font-medium">{c.name}</span> : ''}{c?.email ? ` (${c.email})` : ''}</> },

    'auth.login':            { tone: 'neutral', text: () => 'signed in' },
    'auth.logout':           { tone: 'neutral', text: () => 'signed out' },
    'auth.login_failed':     { tone: 'danger',  actorless: true, text: (c) => <>Failed sign-in attempt for {c?.email ? <span className="font-medium">{c.email}</span> : 'an unknown email'}</> },
    'auth.password_changed': { tone: 'neutral', text: () => 'changed their password' },
    'auth.password_reset':   { tone: 'neutral', text: () => 'reset their password via an email link' },

    'settings.audit_updated':  { tone: 'warning', text: () => 'changed the audit retention period (days)', change: (c) => ({ from: String(c?.from), to: String(c?.to) }) },
    'settings.mail_updated':   { tone: 'warning', text: (c) => <>updated the email (SMTP) settings{c?.host ? <> (<span className="font-medium">{c.host}</span>)</> : ''}{c?.verify_peer === false ? ', certificate verification off' : ''}</> },
    'settings.backup_updated': { tone: 'warning', text: () => 'updated the backup settings' },
    'settings.updates_updated': { tone: 'neutral', text: (c) => <>{c?.enabled ? 'enabled' : 'disabled'} the update check</> },

    'backup.requested':         { tone: 'neutral', text: () => 'started a manual backup' },
    'backup.completed': {
        tone: 'good',
        text: (c) => <>completed a {c?.trigger === 'scheduled' ? 'scheduled ' : ''}backup{bytes(c?.size_bytes) ? ` (${bytes(c.size_bytes)})` : ''}</>,
    },
    'backup.import_requested':  { tone: 'neutral', text: (c) => <>uploaded a backup archive for import{c?.filename ? <> (<span className="font-medium">{c.filename}</span>)</> : ''}</> },
    'backup.imported':          { tone: 'good',    text: (c) => <>imported a backup archive{c?.undecryptable ? ' (undecryptable — no key)' : ''}</> },
    'backup.restore_requested': { tone: 'warning', text: () => 'started a restore from a backup' },
    'backup.restored':          { tone: 'warning', text: () => 'completed a backup restore' },
    'backup.downloaded':        { tone: 'warning', text: (c) => <>downloaded a backup archive{c?.encrypted === false ? ' (unencrypted)' : ''}</> },
    'backup.verified':          { tone: 'good',    actorless: true, text: (c) => <>Backup verified ({c?.files} files, {c?.rows} rows)</> },
    'backup.verify_failed':     { tone: 'danger',  actorless: true, text: (c) => <>Backup verification failed: {c?.error}</> },
    'backup.deleted':           { tone: 'danger',  text: (c) => <>deleted a backup archive{c?.path ? <> (<span className="font-medium">{c.path}</span>)</> : ''}</> },
};

const NAMESPACES = {
    document:  { label: 'Documents',  Icon: IconFileText },
    workspace: { label: 'Workspaces', Icon: IconFolders },
    group:     { label: 'Groups',     Icon: IconLibrary },
    folder:    { label: 'Folders',    Icon: IconFolder },
    template:  { label: 'Templates',  Icon: IconTemplate },
    tag:       { label: 'Tags',       Icon: IconTag },
    attachment: { label: 'Attachments', Icon: IconFileText },
    audit:     { label: 'Audit trail', Icon: IconHistory },
    register:  { label: 'Registers', Icon: IconFileText },
    access:    { label: 'Access', Icon: IconShieldLock },
    control:   { label: 'Document control', Icon: IconShieldLock },
    trash:     { label: 'Trash',      Icon: IconTrash },
    user:      { label: 'Users',      Icon: IconUsers },
    auth:      { label: 'Sign-ins',   Icon: IconShieldLock },
    backup:    { label: 'Backups',    Icon: IconDatabaseExport },
    settings:  { label: 'Settings',   Icon: IconSettings },
};

const TONE_CLASSES = {
    danger:  'bg-danger-surface text-danger',
    warning: 'bg-warning-surface text-warning-text',
    good:    'bg-success-surface text-success-text',
    neutral: 'bg-surface-hover text-text-secondary',
};

/** "workspace.restructured" → "Workspace restructured" (unmapped-event fallback). */
function humanize(event) {
    const [ns, action] = event.split('.');
    const words = `${ns} ${(action ?? '').replaceAll('_', ' ')}`.trim();
    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Flatten a sentence (plain string or JSX) to text — for `title` tooltips,
 *  which coerce to string and would otherwise show "[object Object]". */
function toPlainText(node) {
    if (node == null || typeof node === 'boolean') return '';
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(toPlainText).join('');
    return toPlainText(node.props?.children);
}

/** Everything the row needs to render one event. */
export function describeEvent(event, context) {
    const entry = EVENTS[event];
    const text = entry ? entry.text(context) : humanize(event);

    return {
        text,
        plainText: toPlainText(text),
        actorless: entry?.actorless ?? !entry, // fallback sentences stand alone too
        change: entry?.change?.(context) ?? null,
        toneClass: TONE_CLASSES[entry?.tone] ?? TONE_CLASSES.neutral,
    };
}

export function namespaceIcon(event) {
    return NAMESPACES[event.split('.')[0]]?.Icon ?? IconHistory;
}

export function namespaceLabel(ns) {
    return NAMESPACES[ns]?.label ?? ns.charAt(0).toUpperCase() + ns.slice(1);
}
