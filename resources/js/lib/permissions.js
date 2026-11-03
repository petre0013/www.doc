// Frontend mirror of the backend resource policies (admin/editor/viewer). This
// only hides controls the user can't use — the server still enforces every
// action via $this->authorize(). Derive from the shared auth.user.roles.
export function can(auth) {
    const roles = auth?.user?.roles ?? [];
    const has = (role) => roles.includes(role);
    const isAdmin = has('admin');
    const isEditor = has('editor');

    return {
        isAdmin,
        create: isAdmin || isEditor,
        update: isAdmin || isEditor,
        delete: isAdmin,
        // Templates are invisible to viewers (they can't create pages);
        // editors and admins manage them fully — mirrors TemplatePolicy.
        templates: isAdmin || isEditor,
    };
}
