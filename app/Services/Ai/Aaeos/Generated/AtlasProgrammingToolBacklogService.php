<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Programming Tool Backlog — pure, deterministic backlog decider.
 *
 * Turns the programming-tool-backlog doc into runtime decision logic. The doc is
 * an implementation backlog for making programming tools fully usable in Atlas
 * automation. Its frontmatter states the single canonical decision verbatim:
 * "Tool backlog is promoted through recipes, normalizers, gates and UX, never by
 * ad hoc command execution." This service encodes the doc's four-priority backlog
 * plus the P1 normalizer contract and the P2 gate-by-authority rule as pure
 * functions, and enforces that promotion rule. It NEVER executes a tool, runs a
 * command, touches a database, calls a provider or mutates state — it only
 * classifies, gates and reports.
 *
 * Concrete contracts implemented (directly from the doc body):
 *
 *  - Priorities. The doc body is four ordered priority sections, each enumerating
 *    its scope:
 *      P0 "Real Cheap Recipes": lint/typecheck, secret/dependency scan,
 *         sbom/release, docker/iac, fast code search and context.
 *      P1 "Specific Normalizers": promote generic output into structured findings
 *         (severity, file/line, fingerprint, rule id, authority group, repair
 *         hint, artifact refs).
 *      P2 "Gates By Authority": gates use the primary tool per authority group and
 *         suppress duplicate findings from complementary tools.
 *      P3 "Operational UX": findings by authority group, stale/missing tool
 *         evidence, repair hints, waiver workflow, links from evidence to code and
 *         docs.
 *    {@see priorities()} is that ordered read model.
 *
 *  - Promotion rule. The frontmatter decision: a backlog slice is promoted ONLY
 *    through the four governed channels — recipe, normalizer, gate, ux — and
 *    "never by ad hoc command execution". {@see classifyPromotion()} accepts a
 *    promotion only via an allowed channel and rejects ad-hoc command execution
 *    (or any unknown channel) outright.
 *
 *  - Normalizer contract (P1). A "Specific Normalizer" must turn generic output
 *    into a structured finding carrying all seven documented fields. {@see
 *    evaluateNormalizer()} marks output as structured ONLY when every required
 *    field is present, and reports each missing field otherwise (generic output is
 *    not yet a structured finding).
 *
 *  - Gate by authority (P2). Within one authority group the gate uses the primary
 *    tool and suppresses duplicate findings emitted by complementary tools. {@see
 *    evaluateAuthorityGate()} keeps exactly one authoritative (primary) finding and
 *    suppresses the complementary duplicates, never the other way round.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md
 */
final class AtlasProgrammingToolBacklogService
{
    public const SCHEMA_VERSION = 'atlas.programming_tool_backlog.v1';

    /**
     * The four priority sections of the doc body, in order, each with the scope
     * items it enumerates. Rank encodes the documented P0 -> P3 order (lower is
     * sooner / cheaper / higher-signal, as the doc states for P0).
     *
     * @var array<string,array{rank:int,title:string,items:list<string>}>
     */
    private const PRIORITIES = [
        'P0' => [
            'rank' => 0,
            'title' => 'Real Cheap Recipes',
            'items' => [
                'lint_typecheck_recipes',
                'secret_dependency_scan_recipes',
                'sbom_release_recipes',
                'docker_iac_recipes',
                'fast_code_search_and_context_recipes',
            ],
        ],
        'P1' => [
            'rank' => 1,
            'title' => 'Specific Normalizers',
            'items' => [
                'severity',
                'file_line',
                'fingerprint',
                'rule_id',
                'authority_group',
                'repair_hint',
                'artifact_refs',
            ],
        ],
        'P2' => [
            'rank' => 2,
            'title' => 'Gates By Authority',
            'items' => [
                'use_primary_tool_per_authority_group',
                'suppress_duplicate_findings_from_complementary_tools',
            ],
        ],
        'P3' => [
            'rank' => 3,
            'title' => 'Operational UX',
            'items' => [
                'findings_by_authority_group',
                'stale_tool_evidence',
                'missing_tool_evidence',
                'repair_hints',
                'waiver_workflow',
                'links_from_evidence_to_code_and_docs',
            ],
        ],
    ];

    /**
     * The governed promotion channels through which a backlog slice may ship. The
     * doc's decision lists exactly these four: recipes, normalizers, gates and UX.
     *
     * @var list<string>
     */
    public const PROMOTION_CHANNELS = [
        'recipe',
        'normalizer',
        'gate',
        'ux',
    ];

    /**
     * The seven fields a P1 "Specific Normalizer" must attach to turn generic tool
     * output into a structured finding (the P1 list, verbatim, in doc order).
     *
     * @var list<string>
     */
    public const NORMALIZER_REQUIRED_FIELDS = [
        'severity',
        'file_line',
        'fingerprint',
        'rule_id',
        'authority_group',
        'repair_hint',
        'artifact_refs',
    ];

    /**
     * The four priority sections as an ordered read model (P0 -> P3).
     *
     * @return array{
     *   schema_version:string,
     *   count:int,
     *   priorities:list<array{id:string,rank:int,title:string,item_count:int,items:list<string>}>
     * }
     */
    public function priorities(): array
    {
        $priorities = [];
        foreach (self::PRIORITIES as $id => $row) {
            $priorities[] = [
                'id' => $id,
                'rank' => $row['rank'],
                'title' => $row['title'],
                'item_count' => count($row['items']),
                'items' => $row['items'],
            ];
        }

        usort($priorities, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($priorities),
            'priorities' => $priorities,
        ];
    }

    /**
     * Decide whether a backlog slice may be promoted via the requested channel.
     *
     * Documented rule enforced (frontmatter decision): the backlog is promoted
     * "through recipes, normalizers, gates and UX, never by ad hoc command
     * execution". Therefore:
     *  - only the four governed channels (recipe, normalizer, gate, ux) may
     *    promote a slice;
     *  - ad-hoc command execution is explicitly rejected;
     *  - any unknown / unnamed channel is rejected too (a slice can never reach
     *    runtime through an unrecognized path).
     *
     * @return array{
     *   schema_version:string,
     *   channel:string,
     *   allowed_channels:list<string>,
     *   is_ad_hoc_command:bool,
     *   promotable:bool,
     *   reason:string
     * }
     */
    public function classifyPromotion(string $channel): array
    {
        $key = strtolower(trim($channel));
        $isAdHoc = in_array($key, ['ad_hoc_command', 'ad-hoc-command', 'command', 'ad_hoc_command_execution'], true);
        $promotable = ! $isAdHoc && in_array($key, self::PROMOTION_CHANNELS, true);

        $reason = match (true) {
            $isAdHoc => 'ad_hoc_command_execution_is_never_a_promotion_channel',
            $promotable => 'promoted_through_governed_channel',
            default => 'unknown_channel_must_use_recipe_normalizer_gate_or_ux',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'channel' => $key,
            'allowed_channels' => self::PROMOTION_CHANNELS,
            'is_ad_hoc_command' => $isAdHoc,
            'promotable' => $promotable,
            'reason' => $reason,
        ];
    }

    /**
     * Evaluate a P1 normalizer: generic tool output becomes a structured finding
     * only when ALL seven documented fields are present. Any missing field means
     * the output is still generic (not yet a structured finding) and is reported.
     *
     * @param  array<string,bool>  $fields  required-field key -> present
     * @return array{
     *   schema_version:string,
     *   structured:bool,
     *   present:list<string>,
     *   missing:list<string>,
     *   total_required:int,
     *   reason:string
     * }
     */
    public function evaluateNormalizer(array $fields): array
    {
        $present = [];
        $missing = [];
        foreach (self::NORMALIZER_REQUIRED_FIELDS as $field) {
            if (($fields[$field] ?? false) === true) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $structured = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'structured' => $structured,
            'present' => $present,
            'missing' => $missing,
            'total_required' => count(self::NORMALIZER_REQUIRED_FIELDS),
            'reason' => $structured
                ? 'all_seven_normalizer_fields_present_structured_finding'
                : 'generic_output_missing_required_normalizer_fields',
        ];
    }

    /**
     * Evaluate the P2 gate-by-authority rule for a set of findings that share one
     * authority group. The gate uses the primary tool per authority group and
     * suppresses duplicate findings from complementary tools.
     *
     * Documented behavior enforced:
     *  - exactly one authoritative finding is kept: the one whose tool holds the
     *    `primary` role in the authority group;
     *  - findings from complementary tools that duplicate the primary one (same
     *    fingerprint) are suppressed — never the primary;
     *  - if no primary finding is present, nothing is treated as authoritative and
     *    no complementary finding is silently promoted (the gate cannot invent a
     *    primary).
     *
     * @param  list<array{tool:string,role?:string,fingerprint?:string}>  $findings
     * @return array{
     *   schema_version:string,
     *   authority_group:string,
     *   has_primary:bool,
     *   authoritative:list<array{tool:string,fingerprint:string}>,
     *   suppressed:list<array{tool:string,fingerprint:string}>,
     *   suppressed_count:int,
     *   reason:string
     * }
     */
    public function evaluateAuthorityGate(string $authorityGroup, array $findings): array
    {
        $group = strtolower(trim($authorityGroup));

        // Collect the fingerprints that a primary tool has already reported.
        $primaryFingerprints = [];
        foreach ($findings as $finding) {
            if (($finding['role'] ?? '') === 'primary') {
                $primaryFingerprints[(string) ($finding['fingerprint'] ?? '')] = true;
            }
        }
        $hasPrimary = $primaryFingerprints !== [];

        $authoritative = [];
        $suppressed = [];
        foreach ($findings as $finding) {
            $tool = (string) ($finding['tool'] ?? '');
            $role = (string) ($finding['role'] ?? 'complementary');
            $fingerprint = (string) ($finding['fingerprint'] ?? '');
            $entry = ['tool' => $tool, 'fingerprint' => $fingerprint];

            if ($role === 'primary') {
                // The primary tool is always authoritative; never suppressed.
                $authoritative[] = $entry;

                continue;
            }

            // Complementary finding: suppress it iff a primary already reported
            // the same fingerprint (a duplicate of the authoritative finding).
            if ($hasPrimary && isset($primaryFingerprints[$fingerprint])) {
                $suppressed[] = $entry;
            } else {
                $authoritative[] = $entry;
            }
        }

        $reason = match (true) {
            ! $hasPrimary => 'no_primary_tool_finding_nothing_authoritative_for_group',
            $suppressed !== [] => 'primary_kept_complementary_duplicates_suppressed',
            default => 'primary_authoritative_no_complementary_duplicates',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authority_group' => $group,
            'has_primary' => $hasPrimary,
            'authoritative' => $authoritative,
            'suppressed' => $suppressed,
            'suppressed_count' => count($suppressed),
            'reason' => $reason,
        ];
    }

    /**
     * Primary entry point: produce the full programming-tool-backlog governance
     * snapshot used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   priorities:array<string,mixed>,
     *   promotion_channels:list<string>,
     *   normalizer_required_fields:list<string>,
     *   promotion_example:array<string,mixed>,
     *   normalizer_example:array<string,mixed>,
     *   authority_gate_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'priorities' => $this->priorities(),
            'promotion_channels' => self::PROMOTION_CHANNELS,
            'normalizer_required_fields' => self::NORMALIZER_REQUIRED_FIELDS,
            // Worked example: ad-hoc command execution is never a promotion path.
            'promotion_example' => $this->classifyPromotion('ad_hoc_command'),
            // Worked example: output missing fields is not yet a structured finding.
            'normalizer_example' => $this->evaluateNormalizer([
                'severity' => true,
                'file_line' => true,
                'fingerprint' => true,
            ]),
            // Worked example: a complementary duplicate is suppressed behind the primary.
            'authority_gate_example' => $this->evaluateAuthorityGate('static_analysis', [
                ['tool' => 'primary_linter', 'role' => 'primary', 'fingerprint' => 'fp-1'],
                ['tool' => 'complementary_linter', 'role' => 'complementary', 'fingerprint' => 'fp-1'],
            ]),
        ];
    }
}
