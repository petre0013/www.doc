import { useEffect, useState } from 'react';
import { IconSun, IconMoon, IconDeviceDesktop, IconPalette, IconViewportNarrow, IconViewportWide } from '@tabler/icons-react';
import {
    THEMES,
    ACCENTS,
    WIDTHS,
    SYSTEM,
    getPreference,
    setPreference,
    onPreferenceChange,
    getAccent,
    setAccent,
    onAccentChange,
    getWidth,
    setWidth,
    onWidthChange,
} from '@/lib/theme';

// Options render from the theme registries so a future scheme or accent only
// needs its app.css block + a registry row (and an icon here if it wants one).
const ICONS = { [SYSTEM]: IconDeviceDesktop, light: IconSun, dark: IconMoon };
const WIDTH_ICONS = { boxed: IconViewportNarrow, full: IconViewportWide };
const OPTIONS = [{ id: SYSTEM, label: 'System' }, ...THEMES];

function iconFor(id) {
    return ICONS[id] ?? IconPalette;
}

function useThemePreference() {
    const [pref, setPref] = useState(getPreference);
    useEffect(() => onPreferenceChange(setPref), []);
    return [pref, setPreference];
}

/** Shared pill button for the segmented pickers below. */
function SegmentButton({ selected, onClick, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={
                'flex items-center gap-1.5 rounded-full px-3 py-1 text-xs transition-colors ' +
                (selected
                    ? 'border border-transparent bg-accent-100 font-medium text-accent-600'
                    : 'border border-border bg-surface text-text-secondary hover:bg-surface-hover')
            }
        >
            {children}
        </button>
    );
}

/** Segmented theme control — lives in Settings › Profile ("Appearance"). */
export function ThemeSegments() {
    const [pref, setPref] = useThemePreference();

    return (
        <div className="flex flex-wrap gap-1.5">
            {OPTIONS.map((opt) => {
                const Icon = iconFor(opt.id);
                return (
                    <SegmentButton key={opt.id} selected={pref === opt.id} onClick={() => setPref(opt.id)}>
                        <Icon className="h-3.5 w-3.5" stroke={1.5} aria-hidden="true" />
                        {opt.label}
                    </SegmentButton>
                );
            })}
        </div>
    );
}

/** Segmented accent-hue control — sits under ThemeSegments in "Appearance".
    The swatch dot always shows the hue's LIGHT accent-400 (a stable color
    chip, like the avatar picker), independent of the active scheme. The
    role=group wrapper keeps these buttons distinguishable from the avatar
    picker's, which shares names like "Sage" and "Rose" on the same page. */
/** Segmented width control — reading/editing column boxed vs full width.
    Applies on document pages only (DocsLayout `wideable`); grouped for the
    same reason as AccentSegments. */
export function WidthSegments() {
    const [width, setPref] = useState(getWidth);
    useEffect(() => onWidthChange(setPref), []);

    return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Page width">
            {WIDTHS.map((opt) => {
                const Icon = WIDTH_ICONS[opt.id] ?? IconPalette;
                return (
                    <SegmentButton key={opt.id} selected={width === opt.id} onClick={() => setWidth(opt.id)}>
                        <Icon className="h-3.5 w-3.5" stroke={1.5} aria-hidden="true" />
                        {opt.label}
                    </SegmentButton>
                );
            })}
        </div>
    );
}

export function AccentSegments() {
    const [accent, setPref] = useState(getAccent);
    useEffect(() => onAccentChange(setPref), []);

    return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Accent">
            {ACCENTS.map((opt) => (
                <SegmentButton key={opt.id} selected={accent === opt.id} onClick={() => setAccent(opt.id)}>
                    <span
                        className="h-3 w-3 rounded-full border border-border-subtle"
                        style={{ backgroundColor: opt.swatch }}
                        aria-hidden="true"
                    />
                    {opt.label}
                </SegmentButton>
            ))}
        </div>
    );
}
