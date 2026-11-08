<?php

namespace App\Support;

use App\Models\Template;

/**
 * Optional starter templates for the NIS2 Art. 21(2) measures and Art. 23
 * reporting. Headings and prompts only: each organisation writes its own
 * content and checks it against its national transposition.
 */
class Nis2Templates
{
    /** Installs missing templates; existing names are left untouched. */
    public static function install(): int
    {
        $created = 0;

        foreach (self::definitions() as [$name, $basis, $sections]) {
            if (Template::where('name', $name)->exists()) {
                continue;
            }

            $template = Template::create([
                'name'          => $name,
                'description'   => "NIS2 starter: {$basis}.",
                'content'       => self::content($basis, $sections),
                'created_by_id' => auth()->id(),
            ]);

            Audit::record('template.created', $template, ['name' => $name]);
            $created++;
        }

        return $created;
    }

    /** @param array<string,list<string>> $sections */
    private static function content(string $basis, array $sections): array
    {
        $nodes = [
            self::paragraph("Reference: {$basis}. Starting point only: adapt it to your organisation and national NIS2 transposition."),
            self::heading('Document control'),
            self::bullets(['Owner:', 'Approved by (management body):', 'Approval date:', 'Next review date:', 'Classification:']),
        ];

        foreach ($sections as $heading => $prompts) {
            $nodes[] = self::heading($heading);
            $nodes[] = self::bullets($prompts);
        }

        return ['type' => 'doc', 'content' => $nodes];
    }

    private static function heading(string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function bullets(array $items): array
    {
        return ['type' => 'bulletList', 'content' => array_map(
            fn (string $item) => ['type' => 'listItem', 'content' => [self::paragraph($item)]],
            $items,
        )];
    }

    /** @return list<array{0:string,1:string,2:array<string,list<string>>}> */
    private static function definitions(): array
    {
        return [
            ['NIS2: Information security policy', 'Art. 20, Art. 21(2)(a), Regulation 2024/2690 Annex 1', [
                'Scope and objectives' => ['Which services, networks and systems are covered?', 'What security objectives has management set?'],
                'Roles and responsibilities' => ['Who is accountable in the management body?', 'Who performs the security role, and how do they report to management?'],
                'Commitments' => ['Which topic-specific policies support this one?', 'How are exceptions requested, approved and recorded?'],
                'Review' => ['How often is this reviewed, and what triggers an earlier review?'],
            ]],
            ['NIS2: Risk management procedure', 'Art. 21(2)(a), Annex 2', [
                'Method' => ['What scales are used for likelihood and impact?', 'What are the risk acceptance criteria, and who approved them?'],
                'Process' => ['How are risks identified, analysed and evaluated?', 'How often is the assessment repeated?'],
                'Treatment' => ['How are treatment options selected and owners assigned?', 'Who formally accepts residual risk?'],
                'Compliance monitoring' => ['How is compliance with policies checked?', 'When is the independent review performed, and by whom?'],
            ]],
            ['NIS2: Incident handling procedure', 'Art. 21(2)(b), Art. 23, Annex 3', [
                'Detection and reporting' => ['What is monitored and logged, and for how long are logs kept?', 'How do staff report suspicious events?'],
                'Assessment' => ['How are events classified?', 'What criteria make an incident significant?'],
                'Response' => ['Who is on the response team, and how are they reached out of band?', 'What are the containment, eradication and recovery steps?'],
                'Notification' => ['Who decides to notify the CSIRT or competent authority?', 'Who informs affected service recipients?'],
            ]],
            ['NIS2: Significant incident record', 'Art. 23(4)', [
                'Summary' => ['Incident reference:', 'Date and time detected:', 'Date and time judged significant:', 'Authority or CSIRT notified:'],
                'Early warning (within 24 hours of awareness)' => ['Time submitted:', 'Is unlawful or malicious action suspected?', 'Could there be a cross-border impact?'],
                'Incident notification (within 72 hours of awareness)' => ['Time submitted:', 'Initial assessment of severity and impact:', 'Indicators of compromise, where available:'],
                'Intermediate reports' => ['Requested by the authority? Date and status updates:'],
                'Final report (within one month of the notification)' => ['Time submitted:', 'Detailed description, severity and impact:', 'Type of threat or likely root cause:', 'Mitigation applied and ongoing:', 'Cross-border impact:'],
            ]],
            ['NIS2: Post-incident review', 'Art. 21(2)(b), Annex 3.6', [
                'Timeline' => ['What happened and when?', 'How was the incident detected?'],
                'Analysis' => ['What was the root cause?', 'What worked well, and what did not?'],
                'Improvements' => ['Which corrective actions were agreed, with owner and due date?', 'Which risks, policies or runbooks need updating?'],
            ]],
            ['NIS2: Business continuity and disaster recovery plan', 'Art. 21(2)(c), Annex 4', [
                'Business impact' => ['Which services are critical?', 'What are the recovery time and recovery point objectives?'],
                'Backup' => ['What is backed up, how often, and where is it kept?', 'When was restoration last tested, and what was the result?'],
                'Recovery' => ['What is the recovery order and who performs each step?', 'What are the dependencies and alternative sites?'],
                'Crisis management' => ['Who forms the crisis team and who has authority?', 'How does the team communicate if normal systems are down?'],
                'Testing' => ['What is the exercise schedule, and where are the results recorded?'],
            ]],
            ['NIS2: Supply chain security policy', 'Art. 21(2)(d), Art. 21(3), Annex 5', [
                'Supplier directory' => ['Where is the supplier register kept?', 'How is each supplier rated for criticality?'],
                'Selection' => ['What security criteria apply when choosing a supplier?', 'How are supplier vulnerabilities and development practices assessed?'],
                'Contracts' => ['What security, incident notification and audit clauses are required?', 'What applies at the end of a contract?'],
                'Monitoring' => ['How often are suppliers reviewed?', 'What happens when a supplier has an incident?'],
            ]],
            ['NIS2: Secure acquisition, development and vulnerability handling', 'Art. 21(2)(e), Annex 6', [
                'Acquisition and development' => ['What security requirements apply to purchased or built systems?', 'What secure development rules apply?'],
                'Configuration and change' => ['What are the secure baselines?', 'How are changes approved, tested and rolled back?'],
                'Patching' => ['What are the patch timelines by severity?', 'How are exceptions handled?'],
                'Testing and vulnerabilities' => ['What security testing is performed, and how often?', 'How are vulnerabilities received, prioritised and disclosed?'],
                'Network security' => ['How are networks segmented and protected?', 'How is malicious software prevented and detected?'],
            ]],
            ['NIS2: Effectiveness assessment', 'Art. 21(2)(f), Annex 7', [
                'Programme' => ['Which measures are assessed, and how often?', 'Who performs the assessment, and how independent are they?'],
                'Metrics' => ['Which indicators show that each measure works?'],
                'Results' => ['What were the findings?', 'Which corrective actions were agreed and reported to management?'],
            ]],
            ['NIS2: Cyber hygiene and training plan', 'Art. 20(2), Art. 21(2)(g), Annex 8', [
                'Awareness' => ['What basic practices must all staff follow?', 'How are awareness messages delivered and how often?'],
                'Training' => ['What training does each role need?', 'What training do management body members complete?'],
                'Records' => ['Where is attendance recorded?', 'How is effectiveness measured?'],
            ]],
            ['NIS2: Cryptography policy', 'Art. 21(2)(h), Annex 9', [
                'Requirements' => ['Which data must be encrypted in transit and at rest?', 'Which algorithms and key lengths are approved?'],
                'Key management' => ['How are keys generated, stored, rotated and revoked?', 'How are keys escrowed and recovered?'],
                'Review' => ['How often are approved algorithms reviewed?'],
            ]],
            ['NIS2: Human resources, access control and asset management', 'Art. 21(2)(i), Annex 10, 11 and 12', [
                'Human resources' => ['What screening applies, and for which roles?', 'What happens to access and assets when someone leaves or changes role?'],
                'Access control' => ['How is access requested, approved and reviewed?', 'How are privileged and administrative accounts controlled?'],
                'Asset management' => ['Where is the asset inventory, and who maintains it?', 'How are assets classified and handled?', 'How is removable media controlled?'],
            ]],
            ['NIS2: Authentication and secure communications standard', 'Art. 21(2)(j), Annex 11.6 and 11.7', [
                'Authentication' => ['Where is multi-factor or continuous authentication required?', 'What are the password and credential rules?'],
                'Communications' => ['Which voice, video and text channels are approved?', 'Which secured emergency communication channel is used?'],
                'Exceptions' => ['Which systems cannot meet this standard, and what compensating controls apply?'],
            ]],
        ];
    }
}
