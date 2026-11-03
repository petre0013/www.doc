import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

/** A light email sanity check for gating client-side actions (server still validates). */
export function isEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value ?? '').trim());
}

/** CSRF token for raw fetch() calls — Inertia visits already send it themselves. */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/** "512 KB" / "1.2 MB" style file size. Returns '—' for empty/zero. */
export function formatBytes(bytes) {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let n = bytes, i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`;
}
