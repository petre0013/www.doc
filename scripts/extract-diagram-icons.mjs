// One-off generator: pulls the raw Tabler outline geometry for each diagram node
// kind into resources/js/components/editor/diagramIcons.json — the single source
// of icon paths shared by diagramSvg.js (PNG) and DiagramSvg.php (PDF). Re-run if
// the kind→icon map changes. Run inside the vite container (has node_modules).
import { readFileSync, writeFileSync } from 'fs';

// kind id  ->  tabler outline svg basename (mirrors NODE_KINDS in DiagramCanvas.jsx)
const MAP = {
    server: 'server', database: 'database', router: 'router', switch: 'switch-3',
    firewall: 'shield-lock', cloud: 'cloud', workstation: 'device-desktop',
    storage: 'server-2', loadbalancer: 'arrows-split-2', vpn: 'key', internet: 'world',
    ap: 'access-point', wifi: 'wifi', laptop: 'device-laptop', mobile: 'device-mobile',
    phone: 'phone', printer: 'printer', camera: 'device-cctv', iot: 'broadcast',
    container: 'brand-docker', vm: 'stack-2', mail: 'mail', monitor: 'activity',
    security: 'lock', user: 'user', users: 'users',
};

const DIR = 'node_modules/@tabler/icons/icons/outline';
const out = {};

for (const [kind, file] of Object.entries(MAP)) {
    const svg = readFileSync(`${DIR}/${file}.svg`, 'utf8');
    // Inner of <svg>…</svg>, minus the invisible 24×24 bounding-box path Tabler
    // prepends (stroke="none" … fill="none"). The rest inherits stroke/fill from
    // the wrapping <g> we render it in.
    const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>[\s\S]*$/, '');
    const body = inner
        .replace(/<path\s+stroke="none"[^>]*\/>/g, '')
        .replace(/\s+/g, ' ')
        .trim();
    out[kind] = body;
}

writeFileSync('resources/js/components/editor/diagramIcons.json', JSON.stringify(out, null, 0) + '\n');
console.log('wrote', Object.keys(out).length, 'icons');
