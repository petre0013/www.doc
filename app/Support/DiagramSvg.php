<?php

namespace App\Support;

/**
 * Server-side renderer: a diagram's canonical graph JSON -> a standalone SVG
 * string, so PDF/DOCX export (and any no-JS consumer) draws a diagram straight
 * from its graph, with NO dependence on a client-captured PNG. The fidelity
 * reference is the LIVE canvas (`DiagramCanvas.jsx` node/edge components):
 * same palette, icon set, node layout and edge geometry (React Flow's bezier /
 * smooth-step / straight paths) — keep this file in step with canvas styling
 * changes.
 *
 * Node icons are the real Tabler glyphs, shared via diagramIcons.json (generated
 * by scripts/extract-diagram-icons.mjs). Arrowheads are `<polygon>` (not SVG
 * `<marker>`) so Dompdf's php-svg-lib, which has no marker support, still draws
 * them.
 *
 * Persisted node shape: { id, type:'group'|'labeled', data:{ label, color, kind },
 * width?, height?, position:{x,y}, parentId? }. Edges: { source, target,
 * sourceHandle, targetHandle, data:{ routing, arrows, lineStyle, color, label } }.
 */
class DiagramSvg
{
    private const NODE_COLORS = [
        'default'    => ['bg' => '#FBFAF5', 'border' => '#E4E2D6', 'accent' => '#4B6840', 'swatch' => '#CDDEC4'],
        'sage'       => ['bg' => '#EAF1E5', 'border' => '#BFD2B5', 'accent' => '#4B6840', 'swatch' => '#CDDEC4'],
        'blue'       => ['bg' => '#E9EFF4', 'border' => '#B8CCDD', 'accent' => '#42637E', 'swatch' => '#C4D6E4'],
        'amber'      => ['bg' => '#F6EEDC', 'border' => '#E5CF9F', 'accent' => '#9A6F2E', 'swatch' => '#EBD6A6'],
        'terracotta' => ['bg' => '#F4E5DF', 'border' => '#DDB3A6', 'accent' => '#A04A33', 'swatch' => '#E6C2B5'],
        'purple'     => ['bg' => '#EEE9F4', 'border' => '#CDBDDD', 'accent' => '#6A5286', 'swatch' => '#D6C7E6'],
    ];

    private const LABEL_COLOR = '#1F2520';
    private const KEY_COLOR   = '#5C625C';
    private const CANVAS_BG   = '#FBFAF5';

    /** @var array<string,string>|null  kind => Tabler icon inner SVG */
    private static ?array $icons = null;

    /** @var array{0:string,1:string}|null  base64 Lexend regular + bold, read once per process */
    private static ?array $fonts = null;

    /** @return array{0:string,1:string} */
    private static function fonts(): array
    {
        return self::$fonts ??= [
            base64_encode(file_get_contents(base_path('fonts/Lexend-Regular.ttf'))),
            base64_encode(file_get_contents(base_path('fonts/Lexend-Bold.ttf'))),
        ];
    }

    /**
     * @return array{svg:string,width:int,height:int}|null  null for an empty graph.
     */
    public static function render(?array $graph): ?array
    {
        $nodes = $graph['nodes'] ?? null;
        if (! is_array($nodes) || count($nodes) === 0) {
            return null;
        }

        $placed = self::place($nodes);
        if (! $placed) {
            return null;
        }

        $edges = $graph['edges'] ?? [];

        return self::build($placed, is_array($edges) ? $edges : []);
    }

    private static function icons(): array
    {
        if (self::$icons === null) {
            $path = resource_path('js/components/editor/diagramIcons.json');
            self::$icons = is_file($path)
                ? (json_decode(file_get_contents($path), true) ?: [])
                : [];
        }

        return self::$icons;
    }

    /** Resolve persisted nodes to absolute boxes with sizes (mirrors placeNodes). */
    private static function place(array $nodes): array
    {
        $pos = [];
        $parents = [];
        foreach ($nodes as $n) {
            if (isset($n['id'])) {
                $pos[$n['id']] = $n['position'] ?? ['x' => 0, 'y' => 0];
                if (isset($n['parentId'])) {
                    $parents[$n['id']] = $n['parentId'];
                }
            }
        }

        $out = [];
        foreach ($nodes as $n) {
            $type = $n['type'] ?? 'labeled';
            $w    = $n['width']  ?? ($type === 'group' ? 240 : 150);
            $h    = $n['height'] ?? ($type === 'group' ? 150 : 40);
            $p    = $n['position'] ?? ['x' => 0, 'y' => 0];
            $x    = $p['x'] ?? 0;
            $y    = $p['y'] ?? 0;

            $parentId = $n['parentId'] ?? null;
            while ($parentId && isset($pos[$parentId])) {
                $x += $pos[$parentId]['x'] ?? 0;
                $y += $pos[$parentId]['y'] ?? 0;
                $parentId = $parents[$parentId] ?? null;
            }

            $data  = $n['data'] ?? [];

            // Fit a device card (name + property rows) to its content when the
            // node carries no persisted size (never manually resized → 150×40
            // fallback). The React canvas auto-sizes via CSS; without this the
            // rows would spill past the box in this derived SVG. Only labeled
            // nodes with structured props grow.
            if ($type !== 'group') {
                $fit = self::normalizeNode(['label' => $data['label'] ?? '', 'props' => $data['props'] ?? null]);
                if ($fit['props'] !== []) {
                    $fitKind = $data['kind'] ?? null;
                    $fitIcon = $fitKind && $fitKind !== 'generic' && isset(self::icons()[$fitKind]);
                    $fitPad  = 12.0;
                    $fitIconW = $fitIcon ? 22 : 0;
                    $fitKeyW = 0.0;
                    $fitValW = 0.0;
                    foreach ($fit['props'] as $fp) {
                        if ($fp['key'] !== '') {
                            $fitKeyW = max($fitKeyW, self::textWidth($fp['key'], 5.2));
                        }
                        $fitValW = max($fitValW, self::textWidth($fp['value'], 5.2));
                    }
                    $fitRowsW = ($fitKeyW > 0 ? $fitKeyW + 8 : 0) + $fitValW;
                    $fitNameW = self::textWidth($fit['name'] !== '' ? $fit['name'] : 'Node');
                    $w = max((float) $w, $fitPad + $fitIconW + max($fitNameW, $fitRowsW) + $fitPad);
                    $h = max((float) $h, 22 + count($fit['props']) * 13 + 8);
                }
            }

            $out[] = [
                'id'       => $n['id'] ?? uniqid('n'),
                'type'     => $type,
                'x'        => (float) $x,
                'y'        => (float) $y,
                'w'        => (float) $w,
                'h'        => (float) $h,
                'label'    => (string) ($data['label'] ?? ''),
                'props'    => $data['props'] ?? null,
                'color'    => $data['color'] ?? null,
                'iconKind' => $data['kind'] ?? null,
            ];
        }

        return $out;
    }

    private static function isHex(?string $s): bool
    {
        return is_string($s) && preg_match('/^#([0-9a-f]{3,8})$/i', $s) === 1;
    }

    private static function nodeColor(?string $id): array
    {
        if ($id !== null && isset(self::NODE_COLORS[$id])) {
            return self::NODE_COLORS[$id];
        }
        if (self::isHex($id)) {
            return ['bg' => $id, 'bgOpacity' => 0.16, 'border' => $id, 'borderOpacity' => 0.55, 'accent' => $id, 'swatch' => $id];
        }

        return self::NODE_COLORS['default'];
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** A float formatted for SVG (locale-independent, trimmed). */
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function textWidth(string $s, float $perChar = 6.2): float
    {
        return mb_strlen($s) * $perChar;
    }

    private static function truncate(string $label, float $w, float $perChar = 6.2): string
    {
        $max = max(2, (int) floor($w / $perChar));

        return mb_strlen($label) > $max
            ? mb_substr($label, 0, max(1, $max - 1)) . '…'
            : $label;
    }

    /**
     * A device node's display content: its name plus ordered key/value
     * properties. Shared by the SVG renderer and RenderDocument's search-text
     * extraction so both agree. Legacy free-form labels (a multi-line `label`
     * with no `props`) migrate on read: the first line is the name and each
     * remaining non-empty line becomes a value-only property.
     *
     * @return array{name: string, props: array<int, array{key: string, value: string}>}
     */
    public static function normalizeNode(array $data): array
    {
        $label = trim((string) ($data['label'] ?? ''));

        $props = [];
        foreach ((array) ($data['props'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $key   = trim((string) ($p['key'] ?? ''));
            $value = trim((string) ($p['value'] ?? ''));
            if ($key === '' && $value === '') {
                continue; // no blank rows
            }
            $props[] = ['key' => $key, 'value' => $value];
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $label)),
            fn ($l) => $l !== ''
        ));
        $name = $lines[0] ?? '';

        // Legacy multi-line label (no structured props): extra lines become
        // value-only properties.
        if ($props === [] && count($lines) > 1) {
            foreach (array_slice($lines, 1) as $line) {
                $props[] = ['key' => '', 'value' => $line];
            }
        }

        return ['name' => $name, 'props' => $props];
    }

    /** A 16px Tabler icon (24-unit source, stroke 1.5) anchored at its top-left. */
    private static function icon(string $kind, float $x, float $y, string $color): string
    {
        $body = self::icons()[$kind] ?? null;
        if (! $body) {
            return '';
        }
        $scale = 16 / 24;

        return '<g transform="translate(' . self::n($x) . ',' . self::n($y) . ') scale(' . self::n($scale) . ')"'
            . ' fill="none" stroke="' . $color . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'
            . $body . '</g>';
    }

    /** Handle anchor point + outward normal + side for a node box. */
    private static function handle(array $b, string $side): array
    {
        return match ($side) {
            'top'   => ['x' => $b['x'] + $b['w'] / 2, 'y' => $b['y'],               'nx' => 0.0,  'ny' => -1.0, 'pos' => 'top'],
            'right' => ['x' => $b['x'] + $b['w'],     'y' => $b['y'] + $b['h'] / 2, 'nx' => 1.0,  'ny' => 0.0,  'pos' => 'right'],
            'left'  => ['x' => $b['x'],               'y' => $b['y'] + $b['h'] / 2, 'nx' => -1.0, 'ny' => 0.0,  'pos' => 'left'],
            default => ['x' => $b['x'] + $b['w'] / 2, 'y' => $b['y'] + $b['h'],     'nx' => 0.0,  'ny' => 1.0,  'pos' => 'bottom'],
        };
    }

    private static function ctrlOffset(float $d, float $c = 0.25): float
    {
        return $d >= 0 ? 0.5 * $d : $c * 25 * sqrt(-$d);
    }

    private static function control(string $pos, float $x1, float $y1, float $x2, float $y2): array
    {
        return match ($pos) {
            'left'  => [$x1 - self::ctrlOffset($x1 - $x2), $y1],
            'right' => [$x1 + self::ctrlOffset($x2 - $x1), $y1],
            'top'   => [$x1, $y1 - self::ctrlOffset($y1 - $y2)],
            default => [$x1, $y1 + self::ctrlOffset($y2 - $y1)],
        };
    }

    private static function bezier(array $s, array $t): array
    {
        [$scx, $scy] = self::control($s['pos'], $s['x'], $s['y'], $t['x'], $t['y']);
        [$tcx, $tcy] = self::control($t['pos'], $t['x'], $t['y'], $s['x'], $s['y']);

        return [
            'd'   => 'M' . self::n($s['x']) . ',' . self::n($s['y'])
                   . ' C' . self::n($scx) . ',' . self::n($scy) . ' ' . self::n($tcx) . ',' . self::n($tcy)
                   . ' ' . self::n($t['x']) . ',' . self::n($t['y']),
            'dx'  => $t['x'] - $tcx, 'dy' => $t['y'] - $tcy, 'sdx' => $s['x'] - $scx, 'sdy' => $s['y'] - $scy,
            'lx'  => $s['x'] * 0.125 + $scx * 0.375 + $tcx * 0.375 + $t['x'] * 0.125,
            'ly'  => $s['y'] * 0.125 + $scy * 0.375 + $tcy * 0.375 + $t['y'] * 0.125,
        ];
    }

    private static function straight(array $s, array $t): array
    {
        return [
            'd'  => 'M' . self::n($s['x']) . ',' . self::n($s['y']) . ' L' . self::n($t['x']) . ',' . self::n($t['y']),
            'dx' => $t['x'] - $s['x'], 'dy' => $t['y'] - $s['y'], 'sdx' => $s['x'] - $t['x'], 'sdy' => $s['y'] - $t['y'],
            'lx' => ($s['x'] + $t['x']) / 2, 'ly' => ($s['y'] + $t['y']) / 2,
        ];
    }

    /** Polyline with rounded corners (React Flow's smooth-step uses radius 8). */
    private static function roundedPath(array $pts, float $r): string
    {
        $p = [];
        foreach ($pts as $i => $q) {
            if ($i === 0 || $q[0] !== $pts[$i - 1][0] || $q[1] !== $pts[$i - 1][1]) {
                $p[] = $q;
            }
        }
        if (count($p) < 2) {
            return 'M' . self::n($p[0][0] ?? 0) . ',' . self::n($p[0][1] ?? 0);
        }

        $dist   = fn ($a, $b) => hypot($b[0] - $a[0], $b[1] - $a[1]);
        $toward = function ($from, $to, $len) use ($dist) {
            $dd = $dist($from, $to) ?: 1;
            return [$from[0] + ($to[0] - $from[0]) / $dd * $len, $from[1] + ($to[1] - $from[1]) / $dd * $len];
        };

        $d = 'M' . self::n($p[0][0]) . ',' . self::n($p[0][1]);
        for ($i = 1; $i < count($p) - 1; $i++) {
            $prev = $p[$i - 1];
            $cur  = $p[$i];
            $next = $p[$i + 1];
            $rr   = min($r, $dist($prev, $cur) / 2, $dist($cur, $next) / 2);
            $enter = $toward($cur, $prev, $rr);
            $exit  = $toward($cur, $next, $rr);
            $d .= ' L' . self::n($enter[0]) . ',' . self::n($enter[1])
                . ' Q' . self::n($cur[0]) . ',' . self::n($cur[1]) . ' ' . self::n($exit[0]) . ',' . self::n($exit[1]);
        }
        $last = $p[count($p) - 1];

        return $d . ' L' . self::n($last[0]) . ',' . self::n($last[1]);
    }

    private static function step(array $s, array $t): array
    {
        [$scx, $scy] = self::control($s['pos'], $s['x'], $s['y'], $t['x'], $t['y']);
        [$tcx, $tcy] = self::control($t['pos'], $t['x'], $t['y'], $s['x'], $s['y']);

        $sVert = $s['pos'] === 'top' || $s['pos'] === 'bottom';
        $tVert = $t['pos'] === 'top' || $t['pos'] === 'bottom';

        $pts = [];
        if ($sVert && $tVert) {
            $midX = ($s['x'] + $t['x']) / 2;
            $pts = [[$s['x'], $s['y']], [$s['x'], $scy], [$midX, $scy], [$midX, $tcy], [$t['x'], $tcy], [$t['x'], $t['y']]];
        } elseif (!$sVert && !$tVert) {
            $midY = ($s['y'] + $t['y']) / 2;
            $pts = [[$s['x'], $s['y']], [$scx, $s['y']], [$scx, $midY], [$tcx, $midY], [$tcx, $t['y']], [$t['x'], $t['y']]];
        } elseif ($sVert && !$tVert) {
            $pts = [[$s['x'], $s['y']], [$s['x'], $scy], [$tcx, $scy], [$tcx, $t['y']], [$t['x'], $t['y']]];
        } else {
            $pts = [[$s['x'], $s['y']], [$scx, $s['y']], [$scx, $tcy], [$t['x'], $tcy], [$t['x'], $t['y']]];
        }

        $a = $pts[count($pts) - 2];
        $b = $pts[count($pts) - 1];
        $a2 = $pts[1];

        // For label positioning, we find the middle segment
        $mid = intdiv(count($pts), 2);
        $lx = $pts[$mid][0] ?? ($s['x'] + $t['x']) / 2;
        $ly = $pts[$mid][1] ?? ($s['y'] + $t['y']) / 2;

        return [
            'd'  => self::roundedPath($pts, 8),
            'dx' => $b[0] - $a[0], 'dy' => $b[1] - $a[1], 'sdx' => $s['x'] - $a2[0], 'sdy' => $s['y'] - $a2[1],
            'lx' => $lx, 'ly' => $ly,
        ];
    }

    private static function edgePath(array $s, array $t, string $routing): array
    {
        return match ($routing) {
            'straight' => self::straight($s, $t),
            'step'     => self::step($s, $t),
            default    => self::bezier($s, $t),
        };
    }

    private static function arrowhead(float $x, float $y, float $dx, float $dy, string $color): string
    {
        $len = hypot($dx, $dy) ?: 1;
        $ux  = $dx / $len;
        $uy  = $dy / $len;
        $bx  = $x - $ux * 9;
        $by  = $y - $uy * 9;
        $px  = -$uy * 4.5;
        $py  = $ux * 4.5;

        return '<polygon points="' . self::n($x) . ',' . self::n($y) . ' '
            . self::n($bx + $px) . ',' . self::n($by + $py) . ' '
            . self::n($bx - $px) . ',' . self::n($by - $py) . '" fill="' . $color . '"/>';
    }



    private static function build(array $nodes, array $edges): array
    {
        $pad  = 28;
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;
        $byId = [];
        foreach ($nodes as $n) {
            $byId[$n['id']] = $n;
            $minX = min($minX, $n['x']);
            $minY = min($minY, $n['y']);
            $maxX = max($maxX, $n['x'] + $n['w']);
            $maxY = max($maxY, $n['y'] + $n['h']);
        }

        $rawBox = fn (array $n) => ['x' => $n['x'], 'y' => $n['y'], 'w' => $n['w'], 'h' => $n['h']];
        foreach ($edges as $e) {
            $sN = $byId[$e['source'] ?? ''] ?? null;
            $tN = $byId[$e['target'] ?? ''] ?? null;
            if (! $sN || ! $tN) {
                continue;
            }
            $s = self::handle($rawBox($sN), $e['sourceHandle'] ?? 'bottom');
            $t = self::handle($rawBox($tN), $e['targetHandle'] ?? 'top');
            
            [$scx, $scy] = self::control($s['pos'], $s['x'], $s['y'], $t['x'], $t['y']);
            [$tcx, $tcy] = self::control($t['pos'], $t['x'], $t['y'], $s['x'], $s['y']);

            $minX = min($minX, $scx, $tcx);
            $minY = min($minY, $scy, $tcy);
            $maxX = max($maxX, $scx, $tcx);
            $maxY = max($maxY, $scy, $tcy);
        }

        $ox     = $pad - $minX;
        $oy     = $pad - $minY;
        $width  = (int) round($maxX - $minX + $pad * 2);
        $height = (int) round($maxY - $minY + $pad * 2);

        $box  = fn (array $n) => ['x' => $n['x'] + $ox, 'y' => $n['y'] + $oy, 'w' => $n['w'], 'h' => $n['h']];

        $parts = [];

        // Zones first, behind everything: solid 1px border, swatch tint (~30%).
        // Nested zones nest correctly because the client always persists nodes
        // in topological order (outer zone before inner), so drawing in array
        // order paints an inner zone over its container. Device nodes are drawn
        // last (below), so a node never hides behind any zone.
        foreach ($nodes as $g) {
            if (($g['type'] ?? '') !== 'group') {
                continue;
            }
            $b  = $box($g);
            $c  = self::nodeColor($g['color'] ?? 'sage');
            $bo = $c['borderOpacity'] ?? 1;
            $parts[] = '<rect x="' . self::n($b['x']) . '" y="' . self::n($b['y']) . '" width="' . self::n($b['w'])
                . '" height="' . self::n($b['h']) . '" rx="6" fill="' . ($c['swatch'] ?? $c['bg'])
                . '" fill-opacity="0.3" stroke="' . $c['border'] . '" stroke-opacity="' . $bo . '" stroke-width="1"/>';
            if ($g['label'] !== '') {
                $parts[] = '<text x="' . self::n($b['x'] + 8) . '" y="' . self::n($b['y'] + 15)
                    . '" font-family="Lexend, sans-serif" font-size="12" font-weight="bold" fill="' . $c['accent'] . '">'
                    . self::esc(self::truncate($g['label'], $b['w'] - 14)) . '</text>';
            }
        }

        // Edges.
        foreach ($edges as $e) {
            $sN = $byId[$e['source'] ?? ''] ?? null;
            $tN = $byId[$e['target'] ?? ''] ?? null;
            if (! $sN || ! $tN) {
                continue;
            }
            $data  = $e['data'] ?? [];
            // Edge colour is user-controlled graph JSON interpolated into SVG
            // attributes — accept only a hex literal (mirrors nodeColor()).
            $color = self::isHex($data['color'] ?? null) ? $data['color'] : '#8E938E';
            $s     = self::handle($box($sN), $e['sourceHandle'] ?? 'bottom');
            $t     = self::handle($box($tN), $e['targetHandle'] ?? 'top');
            $p     = self::edgePath($s, $t, $data['routing'] ?? 'curved');
            $dash  = (($data['lineStyle'] ?? '') === 'dashed') ? ' stroke-dasharray="6 4"' : '';
            $parts[] = '<path d="' . $p['d'] . '" fill="none" stroke="' . $color . '" stroke-width="1.5" stroke-linecap="round"' . $dash . '/>';
            $arrows = $data['arrows'] ?? 'end';
            if ($arrows === 'end' || $arrows === 'both') {
                $parts[] = self::arrowhead($t['x'], $t['y'], $p['dx'], $p['dy'], $color);
            }
            if ($arrows === 'both') {
                $parts[] = self::arrowhead($s['x'], $s['y'], $p['sdx'], $p['sdy'], $color);
            }
            if (! empty($data['label'])) {
                // Live-canvas pill: 10px medium text, 4px side padding, 2px
                // radius. Medium isn't an embedded weight, so Regular is the
                // closer of the two (Bold read visibly heavier in exports).
                $lw = self::textWidth($data['label'], 5.2) + 10;
                $lh = 16;
                $parts[] = '<rect x="' . self::n($p['lx'] - $lw / 2) . '" y="' . self::n($p['ly'] - $lh / 2)
                    . '" width="' . self::n($lw) . '" height="' . $lh . '" rx="2" fill="#FBFAF5" fill-opacity="0.95" stroke="#E9E7DC" stroke-width="1"/>';
                $parts[] = '<text x="' . self::n($p['lx']) . '" y="' . self::n($p['ly'] + 3.5)
                    . '" text-anchor="middle" font-family="Lexend, sans-serif" font-size="10" fill="#5C625C">'
                    . self::esc($data['label']) . '</text>';
            }
        }

        // Nodes on top.
        foreach ($nodes as $n) {
            if (($n['type'] ?? '') === 'group') {
                continue;
            }
            $b  = $box($n);
            $c  = self::nodeColor($n['color'] ?? 'default');
            $op = $c['bgOpacity'] ?? 1;
            $bo = $c['borderOpacity'] ?? 1;
            $parts[] = '<rect x="' . self::n($b['x']) . '" y="' . self::n($b['y']) . '" width="' . self::n($b['w'])
                . '" height="' . self::n($b['h']) . '" rx="6" fill="' . $c['bg'] . '" fill-opacity="' . $op
                . '" stroke="' . $c['border'] . '" stroke-opacity="' . $bo . '" stroke-width="1" filter="url(#shadow)"/>';

            $cx      = $b['x'] + $b['w'] / 2;
            $cy      = $b['y'] + $b['h'] / 2;
            $kind    = $n['iconKind'] ?? null;
            $hasIcon = $kind && $kind !== 'generic' && isset(self::icons()[$kind]);
            ['name' => $name, 'props' => $props] = self::normalizeNode([
                'label' => $n['label'],
                'props' => $n['props'] ?? null,
            ]);
            $name = $name !== '' ? $name : 'Node';

            if ($props === []) {
                // Name-only node: today's single centered/icon line.
                $label = self::truncate($name, $b['w'] - ($hasIcon ? 34 : 16));
                if ($hasIcon) {
                    $tw     = self::textWidth($label);
                    $groupW = 16 + 6 + $tw;
                    $ix     = $cx - $groupW / 2;
                    $parts[] = self::icon($kind, $ix, $cy - 8, $c['accent']);
                    $parts[] = '<text x="' . self::n($ix + 22) . '" y="' . self::n($cy + 4)
                        . '" font-family="Lexend, sans-serif" font-size="12" font-weight="bold" fill="' . self::LABEL_COLOR . '">'
                        . self::esc($label) . '</text>';
                } else {
                    $parts[] = '<text x="' . self::n($cx) . '" y="' . self::n($cy + 4)
                        . '" text-anchor="middle" font-family="Lexend, sans-serif" font-size="12" font-weight="bold" fill="' . self::LABEL_COLOR . '">'
                        . self::esc($label) . '</text>';
                }
            } else {
                // Device card: left-aligned name (bold) + property rows below.
                // 12px pad = the live canvas card's px-3.
                $pad     = 12.0;
                $nameX   = $b['x'] + $pad + ($hasIcon ? 22 : 0);
                $nameY   = $b['y'] + 18;
                $nameMaxW = $b['w'] - ($nameX - $b['x']) - $pad;

                if ($hasIcon) {
                    $parts[] = self::icon($kind, $b['x'] + $pad, $b['y'] + 9, $c['accent']);
                }
                $parts[] = '<text x="' . self::n($nameX) . '" y="' . self::n($nameY)
                    . '" font-family="Lexend, sans-serif" font-size="12" font-weight="bold" fill="' . self::LABEL_COLOR . '">'
                    . self::esc(self::truncate($name, $nameMaxW)) . '</text>';

                // Value column aligns to the widest key, but the key column is
                // capped to part of the card so a long key truncates instead of
                // overflowing and shoving the value off-card.
                $availW  = $b['w'] - ($nameX - $b['x']) - $pad;
                $maxKeyW = 0.0;
                foreach ($props as $p) {
                    if ($p['key'] !== '') {
                        $maxKeyW = max($maxKeyW, self::textWidth($p['key'], 5.2));
                    }
                }
                $maxKeyW = min($maxKeyW, $availW * 0.45);
                $keyX = $nameX;
                $valX = $keyX + ($maxKeyW > 0 ? $maxKeyW + 8 : 0);
                $rowY = $nameY + 15;

                foreach ($props as $i => $p) {
                    $y = $rowY + $i * 13;
                    if ($p['key'] !== '') {
                        $parts[] = '<text x="' . self::n($keyX) . '" y="' . self::n($y)
                            . '" font-family="Lexend, sans-serif" font-size="10" fill="' . self::KEY_COLOR . '">'
                            . self::esc(self::truncate($p['key'], $maxKeyW, 5.2)) . '</text>';
                    }
                    $vx     = $p['key'] !== '' ? $valX : $keyX;
                    $vMaxW  = $b['w'] - ($vx - $b['x']) - $pad;
                    $parts[] = '<text x="' . self::n($vx) . '" y="' . self::n($y)
                        . '" font-family="Lexend, sans-serif" font-size="10" fill="' . self::LABEL_COLOR . '">'
                        . self::esc(self::truncate($p['value'], $vMaxW, 5.2)) . '</text>';
                }
            }
        }
        
        [$lexendRegular, $lexendBold] = self::fonts();

        $styles = "<style>
            @font-face {
                font-family: 'Lexend';
                font-style: normal;
                font-weight: normal;
                src: url(data:font/truetype;charset=utf-8;base64,{$lexendRegular}) format('truetype');
            }
            @font-face {
                font-family: 'Lexend';
                font-style: normal;
                font-weight: bold;
                src: url(data:font/truetype;charset=utf-8;base64,{$lexendBold}) format('truetype');
            }
        </style>
        <defs>
            <filter id=\"shadow\" x=\"-10%\" y=\"-10%\" width=\"120%\" height=\"120%\">
                <feDropShadow dx=\"0\" dy=\"1\" stdDeviation=\"2\" flood-opacity=\"0.1\" />
            </filter>
        </defs>";

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $width . '" height="' . $height
            . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . $styles
            . '<rect width="' . $width . '" height="' . $height . '" fill="' . self::CANVAS_BG . '"/>'
            . implode('', $parts) . '</svg>';

        return ['svg' => $svg, 'width' => $width, 'height' => $height];
    }
}
