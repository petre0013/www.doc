<?php

namespace App\Support;

use App\Models\RegisterItem;
use Illuminate\Support\Carbon;

/** Field definitions for each register, and the rules specific to each type. */
class Registers
{
    private const LEVELS = ['1', '2', '3', '4', '5'];
    private const CRITICALITY = ['low', 'medium', 'high', 'critical'];

    public static function all(): array
    {
        return [
            'requirement' => [
                'label' => 'Requirements', 'singular' => 'requirement', 'prefix' => 'REQ',
                'statuses' => ['not_started', 'in_progress', 'implemented', 'not_applicable'], 'closed' => ['implemented', 'not_applicable'],
                'fields' => [
                    self::f('source', 'Source'),
                    self::f('control', 'How we meet it', 'textarea'),
                    self::f('justification', 'Justification if not applicable', 'textarea'),
                ],
            ],
            'incident' => [
                'label' => 'Incidents', 'singular' => 'incident', 'prefix' => 'INC',
                'statuses' => ['open', 'contained', 'resolved', 'closed'], 'closed' => ['resolved', 'closed'],
                'fields' => [
                    self::f('significant', 'Significant under Art. 23?', 'select', ['undetermined', 'yes', 'no']),
                    self::f('detected_at', 'Detected', 'datetime'),
                    self::f('aware_at', 'Became aware it was significant', 'datetime', help: 'Starts the Art. 23 reporting deadlines.'),
                    self::f('authority', 'CSIRT or authority'),
                    self::f('early_warning_at', 'Early warning sent', 'datetime'),
                    self::f('notification_at', 'Incident notification sent', 'datetime'),
                    self::f('final_report_at', 'Final report sent', 'datetime'),
                    self::f('impact', 'Severity and impact', 'textarea'),
                    self::f('root_cause', 'Root cause', 'textarea'),
                    self::f('mitigation', 'Mitigation', 'textarea'),
                ],
            ],
            'risk' => [
                'label' => 'Risks', 'singular' => 'risk', 'prefix' => 'RSK',
                'statuses' => ['identified', 'treating', 'accepted', 'closed'], 'closed' => ['accepted', 'closed'],
                'fields' => [
                    self::f('threat', 'Threat and affected assets', 'textarea'),
                    self::f('likelihood', 'Likelihood (1-5)', 'select', self::LEVELS),
                    self::f('impact', 'Impact (1-5)', 'select', self::LEVELS),
                    self::f('treatment', 'Treatment', 'select', ['mitigate', 'transfer', 'avoid', 'accept']),
                    self::f('plan', 'Treatment plan', 'textarea'),
                    self::f('residual_likelihood', 'Residual likelihood (1-5)', 'select', self::LEVELS),
                    self::f('residual_impact', 'Residual impact (1-5)', 'select', self::LEVELS),
                ],
            ],
            'asset' => [
                'label' => 'Assets', 'singular' => 'asset', 'prefix' => 'AST',
                'statuses' => ['active', 'retired'], 'closed' => ['retired'],
                'fields' => [
                    self::f('category', 'Category', 'select', ['hardware', 'software', 'data', 'service', 'site', 'other']),
                    self::f('criticality', 'Criticality', 'select', self::CRITICALITY),
                    self::f('classification', 'Classification', 'select', ['public', 'internal', 'confidential', 'restricted']),
                    self::f('location', 'Location'),
                    self::f('notes', 'Notes', 'textarea'),
                ],
            ],
            'supplier' => [
                'label' => 'Suppliers', 'singular' => 'supplier', 'prefix' => 'SUP',
                'statuses' => ['active', 'under_review', 'ended'], 'closed' => ['ended'],
                'fields' => [
                    self::f('service', 'Service provided', 'textarea'),
                    self::f('criticality', 'Criticality', 'select', self::CRITICALITY),
                    self::f('security_clauses', 'Security clauses in contract', 'select', ['unknown', 'no', 'partial', 'yes']),
                    self::f('incident_contact', 'Incident contact'),
                    self::f('contract_end', 'Contract end', 'date'),
                ],
            ],
            'training' => [
                'label' => 'Training', 'singular' => 'training record', 'prefix' => 'TRN',
                'statuses' => ['planned', 'completed'], 'closed' => ['completed'],
                'fields' => [
                    self::f('audience', 'Attendees or audience', 'textarea'),
                    self::f('management', 'Management body training (Art. 20(2))', 'select', ['no', 'yes']),
                    self::f('completed_on', 'Completed on', 'date'),
                ],
            ],
            'task' => [
                'label' => 'Tasks', 'singular' => 'task', 'prefix' => 'TSK',
                'statuses' => ['open', 'in_progress', 'done'], 'closed' => ['done'],
                'fields' => [self::f('details', 'Details', 'textarea')],
            ],
        ];
    }

    public static function types(): array
    {
        return array_keys(self::all());
    }

    public static function closedStatuses(string $type): array
    {
        return self::all()[$type]['closed'];
    }

    /** Values computed from an item's fields, for the UI and reminders. */
    public static function computed(RegisterItem $item): array
    {
        return match ($item->type) {
            'risk'     => self::riskScores($item),
            'incident' => ['deadlines' => self::incidentDeadlines($item)],
            default    => [],
        };
    }

    private static function riskScores(RegisterItem $item): array
    {
        $score = fn (string $a, string $b) => isset($item->data[$a], $item->data[$b]) && $item->data[$a] !== '' && $item->data[$b] !== ''
            ? (int) $item->data[$a] * (int) $item->data[$b] : null;

        return ['score' => $score('likelihood', 'impact'), 'residual_score' => $score('residual_likelihood', 'residual_impact')];
    }

    /** Art. 23(4): 24 hours and 72 hours from awareness, one month from the notification. */
    public static function incidentDeadlines(RegisterItem $item): array
    {
        $data = $item->data ?? [];
        if (($data['significant'] ?? null) !== 'yes' || empty($data['aware_at'])) {
            return [];
        }

        $aware = Carbon::parse($data['aware_at']);
        $notified = empty($data['notification_at']) ? null : Carbon::parse($data['notification_at']);

        $stages = [
            ['key' => 'early_warning', 'label' => 'Early warning', 'due' => $aware->copy()->addHours(24), 'sent' => $data['early_warning_at'] ?? null],
            ['key' => 'notification', 'label' => 'Incident notification', 'due' => $aware->copy()->addHours(72), 'sent' => $data['notification_at'] ?? null],
            ['key' => 'final_report', 'label' => 'Final report', 'due' => $notified?->copy()->addMonthNoOverflow(), 'sent' => $data['final_report_at'] ?? null],
        ];

        return array_map(fn (array $s) => [
            'key'   => $s['key'],
            'label' => $s['label'],
            'due'   => $s['due']?->toIso8601String(),
            'sent'  => $s['sent'] ?: null,
            'late'  => $s['due'] !== null && ($s['sent'] ? Carbon::parse($s['sent'])->gt($s['due']) : $s['due']->isPast()),
        ], $stages);
    }

    /** The NIS2 articles and Regulation 2024/2690 annex areas, as starting rows. */
    public static function catalogue(): array
    {
        return [
            ['Art. 20(1)', 'Management body approves and oversees risk-management measures'],
            ['Art. 20(2)', 'Management body members follow cybersecurity training'],
            ['Art. 21(2)(a)', 'Risk analysis and information system security policies'],
            ['Art. 21(2)(b)', 'Incident handling'],
            ['Art. 21(2)(c)', 'Business continuity, backup management, disaster recovery and crisis management'],
            ['Art. 21(2)(d)', 'Supply chain security'],
            ['Art. 21(2)(e)', 'Security in acquisition, development and maintenance, including vulnerability handling'],
            ['Art. 21(2)(f)', 'Policies and procedures to assess the effectiveness of measures'],
            ['Art. 21(2)(g)', 'Basic cyber hygiene practices and cybersecurity training'],
            ['Art. 21(2)(h)', 'Cryptography and, where appropriate, encryption'],
            ['Art. 21(2)(i)', 'Human resources security, access control and asset management'],
            ['Art. 21(2)(j)', 'Multi-factor or continuous authentication and secured communications'],
            ['Art. 21(4)', 'Corrective measures taken without undue delay on non-conformity'],
            ['Art. 23', 'Reporting of significant incidents (24 hours, 72 hours, one month)'],
            ['Art. 27', 'Registration with the competent authority, where applicable'],
            ['Annex 1', 'Security policy, roles, responsibilities and authorities'],
            ['Annex 2', 'Risk management framework, compliance monitoring and independent review'],
            ['Annex 3', 'Incident handling policy, monitoring, logging, assessment, response and review'],
            ['Annex 4', 'Continuity and recovery plans, backup and redundancy, crisis management'],
            ['Annex 5', 'Supply chain policy and directory of suppliers'],
            ['Annex 6', 'Secure acquisition, development, configuration, change, testing, patching and network security'],
            ['Annex 7', 'Effectiveness assessment'],
            ['Annex 8', 'Awareness raising and training'],
            ['Annex 9', 'Cryptography'],
            ['Annex 10', 'Human resources security'],
            ['Annex 11', 'Access control, privileged accounts, identification, authentication and MFA'],
            ['Annex 12', 'Asset classification, handling, inventory and removable media'],
            ['Annex 13', 'Environmental and physical security'],
        ];
    }

    private static function f(string $key, string $label, string $type = 'text', array $options = [], ?string $help = null): array
    {
        return compact('key', 'label', 'type', 'options', 'help');
    }
}
