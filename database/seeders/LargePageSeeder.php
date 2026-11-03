<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGroup;
use App\Services\RenderDocument;
use App\Support\DocumentDiff;
use Database\Seeders\Concerns\BuildsTipTapContent;
use Illuminate\Database\Seeder;

/**
 * Oversized pages, for exercising the limits the rest of the seed data never
 * reaches: the version-comparison size cap, long-page scroll/TOC behaviour,
 * export timings, and search over big bodies.
 *
 * `DocumentDiff` skips the inline (word-level) diff once the two rendered
 * bodies exceed a combined size cap, because php-htmldiff is roughly cubic in
 * document size — past that point the page falls back to side-by-side. Testing
 * that by hand needs pages of a size nobody writes by accident, so this seeder
 * generates them: one deliberately UNDER the cap (still diffs inline) and three
 * over it, up to absurd, where the point is that the page still renders fast.
 *
 * Every page gets real revisions (the observer snapshots each save), so
 * "compare versions" has something to compare. The prose is generated from a
 * fixed corpus with no randomness: the same run produces the same bytes, so a
 * page that sits just under the cap stays just under it.
 *
 * Standalone: `php artisan db:seed --class=LargePageSeeder`. Re-running only
 * adds pages that are missing — existing ones are left alone.
 */
class LargePageSeeder extends Seeder
{
    /** The page-content DSL (`buildContent`, `heading`, `diagram`, …). */
    use BuildsTipTapContent;

    private const WORKSPACE = 'Load & Limits';

    /**
     * Pages to generate. `bytes` is the target size of the RENDERED PROSE —
     * what the differ actually measures, so diagrams (which it strips) and
     * images are excluded from the count. The cap applies to the two sides
     * COMBINED, so ~18 KB still diffs inline against its own revision while
     * ~32 KB does not.
     */
    private const PAGES = [
        [
            'title'   => 'Runbook: Core Switch Replacement',
            'bytes'   => 18_000,
            'diagram' => false,
            'tags'    => ['Ops', 'Network'],
        ],
        [
            'title'   => 'Runbook: Datacentre Migration Wave 2',
            'bytes'   => 32_000,
            'diagram' => false,
            'tags'    => ['Ops'],
        ],
        [
            'title'   => 'Reference: Configuration Baseline (Full)',
            'bytes'   => 140_000,
            'diagram' => false,
            'tags'    => ['Network'],
        ],
        [
            'title'   => 'Capacity Review 2026 (Long, With Diagram)',
            'bytes'   => 34_000,
            'diagram' => true,
            'tags'    => ['Network', 'Ops'],
        ],
    ];

    public function run(): void
    {
        $authorIds = User::role(['admin', 'editor'])->pluck('id')->all()
            ?: User::pluck('id')->all();

        if (empty($authorIds)) {
            $this->command->warn('LargePageSeeder: no users found, skipping.');

            return;
        }

        $workspace = Workspace::firstOrCreate(
            ['name' => self::WORKSPACE],
            [
                'description' => 'Deliberately oversized pages: diff cap, long-page rendering, export timings.',
                'position'    => max(
                    (int) Workspace::whereNull('group_id')->max('position'),
                    (int) WorkspaceGroup::max('position'),
                ) + 1,
            ],
        );

        $position = (int) $workspace->documents()->whereNull('parent_id')->max('position');

        foreach (self::PAGES as $index => $spec) {
            if ($workspace->documents()->where('title', $spec['title'])->exists()) {
                $this->command->info("  · {$spec['title']} — already present, skipped");

                continue;
            }

            $this->makePage($workspace, $spec, $index, ++$position, $authorIds);
        }

        auth()->logout();
    }

    /**
     * Create one oversized page plus its revisions, then report what the REAL
     * differ does with the newest pair — measured, not assumed, so the numbers
     * stay honest if the cap is ever retuned.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<int, int>  $authorIds
     */
    protected function makePage(Workspace $workspace, array $spec, int $index, int $position, array $authorIds): void
    {
        $items = $this->prose($spec['bytes'], $index);

        // The diagram and its lead-in image sit after the opening paragraph, so
        // the prose above and below both flow through the (skipped) body diff.
        if ($spec['diagram']) {
            array_splice($items, 1, 0, [
                $this->diagramSpec('192.0.2.10'),
                ['type' => 'image', 'src' => 'https://picsum.photos/seed/capacity/900/380',
                 'alt' => 'Rack elevation, hall 2', 'align' => 'center'],
            ]);
        }

        auth()->loginUsingId($authorIds[$index % count($authorIds)]);

        $document = Document::create([
            'workspace_id' => $workspace->id,
            'parent_id'    => null,
            'position'     => $position,
            'title'        => $spec['title'],
            'content'      => $this->buildContent($items),
        ]);

        foreach ($spec['tags'] as $name) {
            $document->tags()->syncWithoutDetaching([Tag::firstOrCreate(['name' => $name])->id]);
        }

        // Revision 2, by a different editor: scattered word-level edits, the
        // kind that make an inline diff worth reading (~1 paragraph in 6 gains
        // a sentence, ~1 in 9 has a hostname swapped, one section is dropped).
        auth()->loginUsingId($authorIds[($index + 1) % count($authorIds)]);
        $revised = $this->revise($items);
        $document->update(['content' => $this->buildContent($revised)]);

        // Revision 3 on the diagram page: the graph changes and the prose does
        // NOT — the body diff has nothing to say (let alone skip), so the
        // diagram section is what has to show up.
        if ($spec['diagram']) {
            auth()->loginUsingId($authorIds[($index + 2) % count($authorIds)]);
            $at = array_search('diagram', array_map(
                fn ($item) => is_array($item) ? ($item['type'] ?? null) : null,
                $revised,
            ), true);
            $revised[$at] = $this->diagramSpec('192.0.2.99', 'Hall 2 — Core (Rebuilt)');
            $document->update(['content' => $this->buildContent($revised)]);
        }

        $this->report($document, $spec['title']);
    }

    /**
     * Report what the REAL differ does with each consecutive version pair —
     * measured, not assumed, so the numbers stay honest if the cap is ever
     * retuned. Sizes are the body HTML the differ itself weighs (diagrams
     * stripped), which is well below the page's full rendered size.
     */
    protected function report(Document $document, string $title): void
    {
        $versions = $document->versions()->get()->reverse()->values();   // oldest first

        $this->command->info(sprintf(
            '  · %s — %s words, %d versions',
            $title,
            number_format(str_word_count(strip_tags((string) $document->content_html))),
            $versions->count(),
        ));

        foreach ($versions as $i => $newer) {
            if ($i === 0) {
                continue;
            }
            $older = $versions[$i - 1];

            $started = microtime(true);
            $diff = DocumentDiff::compare(
                ['title' => $older->title, 'content' => $older->content, 'tags' => $older->tags ?? []],
                ['title' => $newer->title, 'content' => $newer->content, 'tags' => $newer->tags ?? []],
            );
            $elapsed = microtime(true) - $started;

            $body = $diff['body'];
            $verdict = match (true) {
                ! $body['changed'] => 'body unchanged, diagram-only revision',
                $body['skipped']   => 'over cap → side-by-side',
                default            => 'inline word-level diff',
            };

            $this->command->info(sprintf(
                '      v%d → v%d: %s KB + %s KB body, %s (%.2fs)',
                $i,
                $i + 1,
                number_format(strlen($body['leftHtml']) / 1024, 1),
                number_format(strlen($body['rightHtml']) / 1024, 1),
                $verdict,
                $elapsed,
            ));
        }
    }

    // ── Content generation ───────────────────────────────────────────────────

    /**
     * Build sections until the rendered prose passes $targetBytes. Measured
     * against RenderDocument (the same HTML the differ sees), not guessed from
     * word counts — the markup overhead of lists and tables is significant.
     *
     * @return array<int, mixed> content-DSL items
     */
    protected function prose(int $targetBytes, int $seed): array
    {
        $items = [
            'This page is generated seed data. It exists to be big: it exercises the '
            .'version-comparison size cap, long-page rendering, and export timings. '
            .'The prose below is synthetic but structurally realistic — headings, '
            .'paragraphs, lists, tables and code blocks in the proportions a real runbook has.',
        ];

        $section = 0;
        do {
            $items = array_merge($items, $this->section($seed * 7 + $section));
            $section++;
        } while (strlen(RenderDocument::toHtml($this->buildContent($items))) < $targetBytes);

        return $items;
    }

    /**
     * One section: a heading, several paragraphs, and — on a rotation — a list,
     * a table, a code block or a quote, so the differ's block strategies all
     * get some volume too.
     *
     * @return array<int, mixed>
     */
    protected function section(int $n): array
    {
        $items = [
            ['type' => 'heading', 'level' => 2, 'text' => $this->pick(self::TOPICS, $n).' — stage '.($n + 1)],
        ];

        foreach (range(0, 3) as $p) {
            $items[] = $this->paragraphText($n * 5 + $p);
        }

        if ($n % 3 === 0) {
            $items[] = ['type' => 'bulletList', 'items' => [
                'Confirm '.$this->pick(self::HOSTS, $n).' is reachable on `'.$this->pick(self::IPS, $n).'` before touching the standby.',
                'Drain '.$this->pick(self::IFACES, $n + 1).' and wait for the neighbour table to settle.',
                'Capture `show running-config` from both members and diff them.',
                'Announce the window in #ops and set the maintenance flag on the monitor.',
            ]];
        }

        if ($n % 4 === 1) {
            $items[] = ['type' => 'heading', 'level' => 3, 'text' => 'Checkpoints'];
            $items[] = ['type' => 'table', 'rows' => [
                ['Checkpoint', 'Owner', 'Target', 'Rollback'],
                ['Standby healthy', 'Network', $this->pick(self::IPS, $n), 'Abort, keep active member'],
                ['Uplinks up', 'Network', $this->pick(self::IFACES, $n), 'Re-enable drained port'],
                ['Traffic parity', 'Ops', 'Within 5% of baseline', 'Fail back within the window'],
                ['Monitoring green', 'Ops', 'No new alerts for 15 min', 'Escalate to the on-call lead'],
            ]];
        }

        if ($n % 5 === 2) {
            $items[] = ['type' => 'codeBlock', 'language' => 'bash', 'code' =>
                "ssh admin@".$this->pick(self::IPS, $n)." \\\n"
                ."  'show interfaces ".$this->pick(self::IFACES, $n)." | include rate'\n"
                ."snmpwalk -v2c -c public ".$this->pick(self::IPS, $n + 1)." IF-MIB::ifOperStatus\n"
                ."ping -c 20 -i 0.2 ".$this->pick(self::IPS, $n + 2),
            ];
        }

        if ($n % 7 === 3) {
            $items[] = ['type' => 'blockquote', 'text' =>
                'Do not start this stage without a confirmed rollback slot. '
                .'The change window closes at 05:00 and the '.$this->pick(self::ZONES, $n).' zone must be clean by then.',
            ];
        }

        return $items;
    }

    /** A paragraph of 5 sentences, assembled deterministically from the corpus. */
    protected function paragraphText(int $n): string
    {
        $sentences = [];
        foreach (range(0, 4) as $i) {
            // Stride 5 is coprime with the corpus size, so consecutive
            // paragraphs never repeat the same run of sentences.
            $sentences[] = $this->fill($this->pick(self::SENTENCES, $n * 5 + $i * 3), $n + $i);
        }

        return implode(' ', $sentences);
    }

    /** Substitute the corpus placeholders from the fixture pools. */
    protected function fill(string $sentence, int $n): string
    {
        return strtr($sentence, [
            '{host}'  => $this->pick(self::HOSTS, $n),
            '{host2}' => $this->pick(self::HOSTS, $n + 3),
            '{ip}'    => $this->pick(self::IPS, $n),
            '{iface}' => $this->pick(self::IFACES, $n),
            '{zone}'  => $this->pick(self::ZONES, $n),
            '{n}'     => (string) (($n % 9) + 2),
        ]);
    }

    /**
     * @param  array<int, string>  $pool
     */
    protected function pick(array $pool, int $n): string
    {
        return $pool[abs($n) % count($pool)];
    }

    /**
     * The second revision: edits spread thinly through the page, so the inline
     * diff (where it runs) shows green/red in many places rather than one
     * rewritten block. Only string items — paragraphs — are touched; the
     * diagram, images and tables are left as they are.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    protected function revise(array $items): array
    {
        $revised = [];
        $paragraph = 0;
        $section = 0;
        $dropping = false;

        foreach ($items as $item) {
            // Drop the third section whole (its H2 and everything up to the
            // next one), so the diff has a removed block as well as edited prose.
            if (! is_string($item) && ($item['type'] ?? null) === 'heading' && ($item['level'] ?? 0) === 2) {
                $dropping = ++$section === 3;
            }

            if ($dropping) {
                continue;
            }

            if (! is_string($item)) {
                $revised[] = $item;

                continue;
            }

            $paragraph++;

            if ($paragraph % 6 === 0) {
                $item .= ' Reviewed after the '.$this->pick(self::ZONES, $paragraph)
                    .' incident: the standby now holds the VIP for a full minute before the cutover is declared done.';
            }

            if ($paragraph % 9 === 0) {
                $item = str_replace(self::HOSTS[0], 'core-sw-11', $item);
                $item = str_replace('192.0.2.', '198.51.100.', $item);
            }

            $revised[] = $item;
        }

        return $revised;
    }

    /**
     * A small, real network diagram. The management IP is a property so a later
     * revision can change ONE value — the case where the body diff is skipped
     * but the diagram section still has to report the change.
     *
     * @return array<string, mixed>
     */
    protected function diagramSpec(string $mgmtIp, string $name = 'Hall 2 — Core'): array
    {
        return [
            'type'  => 'diagram',
            'name'  => $name,
            'nodes' => [
                ['id' => 'zone', 'group' => true, 'label' => 'Hall 2', 'color' => 'sage',
                 'x' => 0, 'y' => 0, 'w' => 420, 'h' => 260],
                ['id' => 'core', 'label' => "core-sw-01\nIP: {$mgmtIp}", 'kind' => 'switch',
                 'x' => 40, 'y' => 50, 'parent' => 'zone'],
                ['id' => 'dist', 'label' => "dist-sw-03\nIP: 192.0.2.21", 'kind' => 'switch',
                 'x' => 240, 'y' => 50, 'parent' => 'zone'],
                ['id' => 'fw', 'label' => "edge-fw-01\nIP: 192.0.2.1", 'kind' => 'firewall',
                 'x' => 140, 'y' => 170, 'parent' => 'zone'],
            ],
            'edges' => [
                ['from' => 'core', 'to' => 'dist', 'label' => 'Po3', 'fromSide' => 'right', 'toSide' => 'left'],
                ['from' => 'core', 'to' => 'fw', 'label' => 'Te1/0/1'],
                ['from' => 'dist', 'to' => 'fw', 'label' => 'Te1/0/2'],
            ],
        ];
    }

    // ── Fixture pools ────────────────────────────────────────────────────────
    // Documentation-range addresses only (RFC 5737), never a real internal net.

    private const HOSTS = [
        'core-sw-01', 'core-sw-02', 'dist-sw-03', 'dist-sw-04', 'edge-fw-01',
        'edge-fw-02', 'acc-sw-11', 'lb-01', 'wan-rtr-01', 'oob-sw-01',
    ];

    private const IPS = [
        '192.0.2.10', '192.0.2.11', '192.0.2.21', '192.0.2.22', '192.0.2.1',
        '192.0.2.53', '192.0.2.101', '192.0.2.240',
    ];

    private const IFACES = [
        'Te1/0/1', 'Te1/0/2', 'Gi1/0/24', 'Gi1/0/48', 'Po3', 'Po4', 'Vlan220',
    ];

    private const ZONES = ['core', 'DMZ', 'campus', 'lab', 'storage', 'out-of-band'];

    private const TOPICS = [
        'Pre-flight checks', 'Change window', 'Cabling and labelling', 'VLAN and trunk state',
        'Uplink failover', 'Routing convergence', 'Firewall policy sync', 'Load-balancer drain',
        'Monitoring and alerting', 'Backout plan', 'Post-change validation', 'Handover notes',
        'Spares and RMA', 'Out-of-band access', 'Power and cooling', 'Documentation updates',
    ];

    /** Sentence corpus; `{…}` placeholders are filled by fill(). */
    private const SENTENCES = [
        'The {host} pair carries every north-south flow for the {zone} zone, so the window opens only once the standby member reports a healthy control plane.',
        'Before draining {iface}, confirm the neighbour on {ip} has been in the forwarding state for at least {n} minutes.',
        'Traffic is expected to shift within seconds, but the routing table takes closer to {n} minutes to settle across the whole fabric.',
        'If the counters on {iface} stay flat after the cutover, the far side has not re-learned the MAC table and the port should be bounced once.',
        'Keep an SSH session open to {ip} on the out-of-band network for the whole change; the in-band path will drop during the reload.',
        'The configuration on {host} and {host2} must match line for line except for the hostname, management address and priority.',
        'Any divergence found during the diff is resolved in favour of the running config on {host}, which was last validated during the previous window.',
        'Uplink {iface} is the only path to the {zone} zone during the swap, so it is explicitly excluded from every maintenance action below.',
        'Monitoring is suppressed for the duration, which also means a genuine failure will not page anyone — a second engineer watches the dashboards instead.',
        'The rollback is a config restore from the pre-change snapshot on {host}, not a re-cable; the cabling stays exactly as documented.',
        'Latency to {ip} should stay under {n} milliseconds throughout; a sustained rise means traffic is hairpinning through the backup path.',
        'The spare unit is racked but powered down, cabled to {iface}, and ready to take the configuration if the primary fails to boot.',
        'Serial console access to {host} is via the out-of-band switch; the credentials are in the vault entry linked from this runbook.',
        'Every stage below is reversible on its own, and no stage should be started unless the previous one has been signed off.',
        'The {zone} zone tolerates a single-digit packet loss spike at cutover, but sustained loss for more than {n} minutes triggers the backout.',
        'Firmware on the replacement matches the running version on {host2}; upgrading during a replacement window is explicitly out of scope.',
        'The DHCP relay on {ip} keeps its helper address unchanged, so client leases survive the swap without renewal.',
        'Interface descriptions are copied verbatim from the old unit — the cable labels reference them and the audit report reads them back.',
        'Once traffic is stable, capture a fresh baseline from {host} so the next comparison has something current to work against.',
        'The change record needs the before and after configuration attached; the exported diff alone is not sufficient for the audit trail.',
        'Port {iface} is left administratively down until the post-change validation completes, to avoid a loop through the temporary patch.',
        'Spanning tree should not reconverge at all if the priorities were set correctly; any topology change notification is worth investigating.',
        'The load balancer drains connections gracefully over {n} minutes, so the drain is started well before the switch itself is touched.',
        'Backups of both units are written to the archive share and verified by checksum before anything is unplugged.',
        'On failure of two consecutive stages the whole window is abandoned and the {zone} zone is left on its original hardware.',
        'The upstream provider was notified of the window; their side of {iface} stays untouched but their NOC has the contact details.',
        'Power draw on the new unit is roughly {n} percent higher, which is within the rack budget but worth recording for capacity planning.',
        'Documentation, diagrams and the address plan are updated in the same session — a swap that is not documented counts as unfinished.',
    ];
}
