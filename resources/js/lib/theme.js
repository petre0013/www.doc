// Theme runtime — three independent axes, all stamped on <html>:
//   data-theme  = scheme (light/dark). The stored PREFERENCE is a theme id or
//                 'system' (resolved here against prefers-color-scheme — the
//                 CSS deliberately has no media query, so JS is the single
//                 theming mechanism).
//   data-accent = accent hue. Always an explicit pick (default sage); 'system'
//                 applies to the scheme only, never the accent.
//   data-width  = reading/editing column (boxed/full). Only the document page
//                 opts in (DocsLayout `wideable`); other pages ignore it.
// The CSS in app.css defines the first two as [data-*] token override blocks;
// width is consumed via a [data-width] variant on the opted-in container. A
// tiny inline copy of this resolution runs pre-paint in app.blade.php; keep
// the storage keys, id lists, and fallbacks in sync with it.

// Scheme registry. Adding a scheme = one CSS override block in app.css
// (values designed in the sage-design skill first) + one row here.
export const THEMES = [
    { id: 'light', label: 'Light' },
    { id: 'dark', label: 'Dark' },
];

// Accent registry. Adding a hue = light+dark override blocks in app.css
// (designed & contrast-validated in the sage-design skill first) + one row
// here. `swatch` is the hue's light accent-400, for picker dots.
export const ACCENTS = [
    { id: 'sage', label: 'Sage', swatch: '#7E9D72' },
    { id: 'pink', label: 'Pink', swatch: '#B4809D' },
    { id: 'blue', label: 'Blue', swatch: '#7793C5' },
    { id: 'rose', label: 'Rose', swatch: '#CA7683' },
    { id: 'ochre', label: 'Ochre', swatch: '#A69150' },
];

// Width registry: boxed = today's centered column, full = edge-to-edge.
export const WIDTHS = [
    { id: 'boxed', label: 'Boxed' },
    { id: 'full', label: 'Full width' },
];

export const SYSTEM = 'system';
const STORAGE_KEY = 'wwwdoc:theme';
const ACCENT_STORAGE_KEY = 'wwwdoc:accent';
const WIDTH_STORAGE_KEY = 'wwwdoc:width';
const DEFAULT_THEME = 'light';
const DEFAULT_ACCENT = 'sage';
const DEFAULT_WIDTH = 'boxed';

const listeners = new Set();
const accentListeners = new Set();
const widthListeners = new Set();

function systemTheme() {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function read(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null; // Storage unavailable (privacy mode) — fall through to defaults.
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Not persistable — still apply for this page's lifetime.
    }
}

export function getPreference() {
    const stored = read(STORAGE_KEY);
    if (stored === SYSTEM || THEMES.some((t) => t.id === stored)) return stored;
    return SYSTEM;
}

export function resolveTheme(preference = getPreference()) {
    if (preference === SYSTEM) return systemTheme();
    return THEMES.some((t) => t.id === preference) ? preference : DEFAULT_THEME;
}

function normalizeAccent(accent) {
    return ACCENTS.some((a) => a.id === accent) ? accent : DEFAULT_ACCENT;
}

export function getAccent() {
    return normalizeAccent(read(ACCENT_STORAGE_KEY));
}

function normalizeWidth(width) {
    return WIDTHS.some((w) => w.id === width) ? width : DEFAULT_WIDTH;
}

export function getWidth() {
    return normalizeWidth(read(WIDTH_STORAGE_KEY));
}

function apply() {
    document.documentElement.setAttribute('data-theme', resolveTheme());
    document.documentElement.setAttribute('data-accent', getAccent());
    document.documentElement.setAttribute('data-width', getWidth());
}

// The setters stamp from their ARGUMENT, not a storage re-read: when
// localStorage is unavailable (privacy mode) the write is lost but the pick
// must still apply for this page's lifetime.
export function setPreference(preference) {
    write(STORAGE_KEY, preference);
    document.documentElement.setAttribute('data-theme', resolveTheme(preference));
    listeners.forEach((fn) => fn(preference));
}

export function setAccent(accent) {
    const id = normalizeAccent(accent);
    write(ACCENT_STORAGE_KEY, id);
    document.documentElement.setAttribute('data-accent', id);
    accentListeners.forEach((fn) => fn(id));
}

export function setWidth(width) {
    const id = normalizeWidth(width);
    write(WIDTH_STORAGE_KEY, id);
    document.documentElement.setAttribute('data-width', id);
    widthListeners.forEach((fn) => fn(id));
}

/** Subscribe to scheme-preference changes (returns an unsubscribe fn). */
export function onPreferenceChange(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/** Subscribe to accent changes (returns an unsubscribe fn). */
export function onAccentChange(fn) {
    accentListeners.add(fn);
    return () => accentListeners.delete(fn);
}

/** Subscribe to width changes (returns an unsubscribe fn). */
export function onWidthChange(fn) {
    widthListeners.add(fn);
    return () => widthListeners.delete(fn);
}

/**
 * Called once at app boot: re-asserts the pre-paint script's decision (no-op
 * visually) and follows OS scheme changes while the preference is 'system'.
 */
export function initTheme() {
    apply();
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
        if (getPreference() === SYSTEM) apply();
    });
}
