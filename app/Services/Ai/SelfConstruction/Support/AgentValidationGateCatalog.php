<?php

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Catalog of canonical Atlas Self-Construction agent validation gates.
 *
 * Defines the 10 gates an agent output may be checked against. The catalog
 * itself runs no commands and produces no real validation results. Every
 * entry is metadata only: gate identity, type, severity, blocking policy,
 * required artifact, command shape and default repair hint.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGateCatalog
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_catalog.v1';

    public const MODE = 'read_only_agent_validation_gate_catalog';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cached = null;

    /**
     * Return the canonical catalog.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $gates = $this->gates();
        $ordered = array_values($gates);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'available',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'gate_count' => count($ordered),
            'gates' => $ordered,
            'gate_ids' => array_map(static fn (array $g): string => $g['id'], $ordered),
            'types' => $this->typeIndex($ordered),
            'severities' => $this->severityIndex($ordered),
            'blocking_ids' => $this->filterBy($ordered, 'blocking', true),
            'non_blocking_ids' => $this->filterBy($ordered, 'blocking', false),
            'command_required_ids' => $this->filterBy($ordered, 'requires_command', true),
            'internal_only_ids' => $this->filterBy($ordered, 'requires_command', false),
            'catalog_hash' => $this->hashOf($ordered),
            'risk_summary' => $this->riskSummary($ordered),
            'runtime_safety' => $this->runtimeSafety(),
        ];
    }

    /**
     * Counts by severity, blocking status, command requirement and internal-only status, derived
     * from the live catalog — never a hardcoded fixture total, so it stays correct as gates change.
     *
     * @param  array<int, array<string, mixed>>  $gates
     * @return array<string, mixed>
     */
    public function riskSummary(?array $gates = null): array
    {
        $gates ??= array_values($this->gates());

        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $blockingCount = 0;
        $commandRequiredCount = 0;
        $internalOnlyCount = 0;

        foreach ($gates as $g) {
            $severity = (string) ($g['severity'] ?? '');
            if (array_key_exists($severity, $severityCounts)) {
                $severityCounts[$severity]++;
            }
            if ((bool) ($g['blocking'] ?? false)) {
                $blockingCount++;
            }
            if ((bool) ($g['requires_command'] ?? false)) {
                $commandRequiredCount++;
            } else {
                $internalOnlyCount++;
            }
        }

        return [
            'total_gates' => count($gates),
            'critical_count' => $severityCounts['critical'],
            'high_count' => $severityCounts['high'],
            'medium_count' => $severityCounts['medium'],
            'low_count' => $severityCounts['low'],
            'blocking_count' => $blockingCount,
            'non_blocking_count' => count($gates) - $blockingCount,
            'command_required_count' => $commandRequiredCount,
            'internal_only_count' => $internalOnlyCount,
        ];
    }

    /**
     * Return all gates as an associative map by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function gates(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $all = [
            $this->gate(
                id: 'php_lint',
                name: 'PHP Lint',
                type: 'lint',
                severity: 'high',
                blocking: true,
                requiresCommand: true,
                command: 'php -l',
                expectedArtifact: 'no_syntax_error_message',
                repairHint: 'fix_php_syntax_error_in_listed_file',
            ),
            $this->gate(
                id: 'unit_tests',
                name: 'Unit Tests',
                type: 'test',
                severity: 'critical',
                blocking: true,
                requiresCommand: true,
                command: 'phpunit --testsuite Unit',
                expectedArtifact: 'all_unit_tests_green',
                repairHint: 'repair_failing_unit_test_inside_scope',
            ),
            $this->gate(
                id: 'focused_tests',
                name: 'Focused Tests',
                type: 'test',
                severity: 'high',
                blocking: true,
                requiresCommand: true,
                command: 'phpunit --filter <focused_filter>',
                expectedArtifact: 'focused_filter_green',
                repairHint: 'repair_focused_assertion_or_fixture_inside_scope',
            ),
            $this->gate(
                id: 'docs_health',
                name: 'Documentation Health',
                type: 'docs',
                severity: 'high',
                blocking: true,
                requiresCommand: true,
                command: 'php artisan atlas:engineering:knowledge docs-health --json',
                expectedArtifact: 'docs_health_status_ok',
                repairHint: 'fix_canonical_module_or_frontmatter_violation',
            ),
            $this->gate(
                id: 'architecture_validate',
                name: 'Architecture Validate',
                type: 'architecture',
                severity: 'high',
                blocking: true,
                requiresCommand: true,
                command: 'php artisan atlas:ai:architecture-validate --json',
                expectedArtifact: 'architecture_validate_status_ok',
                repairHint: 'restore_architecture_invariant_in_listed_layer',
            ),
            $this->gate(
                id: 'diff_check',
                name: 'Diff Check',
                type: 'vcs',
                severity: 'medium',
                blocking: true,
                requiresCommand: true,
                command: 'git diff --check',
                expectedArtifact: 'no_whitespace_or_conflict_marker',
                repairHint: 'remove_whitespace_error_or_resolve_conflict_marker',
            ),
            $this->gate(
                id: 'scope_check',
                name: 'Scope Check',
                type: 'scope',
                severity: 'critical',
                blocking: true,
                requiresCommand: false,
                command: 'internal:scope_validator',
                expectedArtifact: 'no_forbidden_or_unknown_file_changed',
                repairHint: 'revert_change_outside_allowed_files',
            ),
            $this->gate(
                id: 'evidence_check',
                name: 'Evidence Check',
                type: 'evidence',
                severity: 'high',
                blocking: true,
                requiresCommand: false,
                command: 'internal:evidence_validator',
                expectedArtifact: 'required_evidence_present_and_hashed',
                repairHint: 'attach_missing_evidence_or_hash_to_receipt',
            ),
            $this->gate(
                id: 'continuation_summary_check',
                name: 'Continuation Summary Check',
                type: 'continuation',
                severity: 'medium',
                blocking: false,
                requiresCommand: false,
                command: 'internal:continuation_summary_validator',
                expectedArtifact: 'continuation_summary_present_and_fresh',
                repairHint: 'rebuild_continuation_summary_for_next_session',
            ),
            $this->gate(
                id: 'rollback_plan_check',
                name: 'Rollback Plan Check',
                type: 'rollback',
                severity: 'critical',
                blocking: true,
                requiresCommand: false,
                command: 'internal:rollback_plan_validator',
                expectedArtifact: 'rollback_plan_present_and_runnable',
                repairHint: 'attach_or_repair_rollback_plan_inside_receipt',
            ),
        ];
        $indexed = [];
        foreach ($all as $g) {
            $indexed[$g['id']] = $g;
        }
        ksort($indexed);
        $this->cached = $indexed;

        return $indexed;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        return $this->gates()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /** @return array<int, string> */
    public function ids(): array
    {
        return array_keys($this->gates());
    }

    /** @return array<int, string> */
    public function blockingIds(): array
    {
        return $this->filterBy(array_values($this->gates()), 'blocking', true);
    }

    /** @return array<int, string> */
    public function nonBlockingIds(): array
    {
        return $this->filterBy(array_values($this->gates()), 'blocking', false);
    }

    /** @return array<string, array<int, string>> */
    public function idsByType(): array
    {
        return $this->typeIndex(array_values($this->gates()));
    }

    public function hash(): string
    {
        return $this->hashOf(array_values($this->gates()));
    }

    /** @param array<int, array<string, mixed>> $gates */
    private function typeIndex(array $gates): array
    {
        $out = [];
        foreach ($gates as $g) {
            $out[$g['type']][] = $g['id'];
        }
        ksort($out);

        return $out;
    }

    /** @param array<int, array<string, mixed>> $gates */
    private function severityIndex(array $gates): array
    {
        $out = [];
        foreach ($gates as $g) {
            $out[$g['severity']][] = $g['id'];
        }
        ksort($out);

        return $out;
    }

    /** @param array<int, array<string, mixed>> $gates */
    private function filterBy(array $gates, string $field, mixed $value): array
    {
        $out = [];
        foreach ($gates as $g) {
            if (($g[$field] ?? null) === $value) {
                $out[] = $g['id'];
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private function gate(
        string $id,
        string $name,
        string $type,
        string $severity,
        bool $blocking,
        bool $requiresCommand,
        string $command,
        string $expectedArtifact,
        string $repairHint,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'severity' => $severity,
            'blocking' => $blocking,
            'requires_command' => $requiresCommand,
            'command' => $command,
            'expected_artifact' => $expectedArtifact,
            'repair_hint' => $repairHint,
        ];
    }

    /** @param array<int, array<string, mixed>> $gates */
    private function hashOf(array $gates): string
    {
        $payload = json_encode($gates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $payload);
    }

    /** @var array<string,array<int,string>> task_family => covering gate ids (must all exist in gates()). */
    private const FAMILY_GATE_COVERAGE = [
        'pure_value_object'    => ['php_lint', 'unit_tests', 'focused_tests', 'scope_check', 'evidence_check'],
        'self_construction_service' => ['php_lint', 'unit_tests', 'focused_tests', 'scope_check', 'evidence_check', 'rollback_plan_check'],
        'http_controller'       => ['php_lint', 'unit_tests', 'scope_check', 'evidence_check'],
        'cli_command'           => ['php_lint', 'unit_tests', 'scope_check'],
        'migration'             => ['php_lint', 'scope_check', 'rollback_plan_check'],
        'queue_worker'          => ['php_lint', 'unit_tests', 'scope_check'],
        'docs_or_wiki'          => ['docs_health', 'scope_check'],
    ];

    /**
     * @var array<string,array<int,string>> task_family => known blind-spot evidence
     * kinds NOT covered by any of the 10 gates — what could still slip through green.
     */
    private const FAMILY_BLIND_SPOTS = [
        'pure_value_object'    => [],
        'self_construction_service' => ['runtime_integration_proof'],
        'http_controller'       => ['runtime_integration_proof', 'security_review'],
        'cli_command'           => ['runtime_integration_proof'],
        'migration'             => ['data_backfill_correctness', 'runtime_integration_proof'],
        'queue_worker'          => ['runtime_integration_proof', 'concurrency_safety'],
        'docs_or_wiki'          => ['link_freshness'],
    ];

    /**
     * @var array<string,int> blind-spot evidence kind => give_back/fake-green risk weight (higher = worse).
     */
    private const BLIND_SPOT_RISK_WEIGHT = [
        'concurrency_safety'         => 5,
        'data_backfill_correctness'  => 5,
        'security_review'            => 4,
        'runtime_integration_proof'  => 3,
        'link_freshness'             => 1,
    ];

    /**
     * Map every known task family to its covering gates, evidence types, coverage
     * strength and known blind spots, then rank the riskiest gap and recommend the
     * concrete gate task that would close it.
     *
     * @return array<string, mixed>
     */
    public function coverageMap(): array
    {
        $gateIds = $this->ids();
        $families = [];
        $allGapEntries = [];

        foreach (self::FAMILY_GATE_COVERAGE as $family => $coveringGates) {
            $coveringGates = array_values(array_intersect($coveringGates, $gateIds));
            $blindSpots = self::FAMILY_BLIND_SPOTS[$family] ?? [];
            $coverageStrength = $this->coverageStrength(count($coveringGates), count($blindSpots));

            $families[$family] = [
                'task_family'        => $family,
                'covering_gates'     => $coveringGates,
                'evidence_types'     => array_map(fn (string $id) => $this->get($id)['expected_artifact'] ?? $id, $coveringGates),
                'coverage_strength'  => $coverageStrength,
                'blind_spots'        => $blindSpots,
            ];

            foreach ($blindSpots as $spot) {
                $allGapEntries[] = [
                    'task_family' => $family,
                    'blind_spot'  => $spot,
                    'risk_weight' => self::BLIND_SPOT_RISK_WEIGHT[$spot] ?? 1,
                ];
            }
        }

        usort($allGapEntries, static function (array $a, array $b): int {
            $cmp = $b['risk_weight'] <=> $a['risk_weight'];

            return $cmp !== 0 ? $cmp : strcmp($a['task_family'].$a['blind_spot'], $b['task_family'].$b['blind_spot']);
        });

        $gateGapRank = [];
        foreach ($allGapEntries as $i => $entry) {
            $gateGapRank[] = array_merge($entry, ['rank' => $i + 1]);
        }

        $topGap = $gateGapRank[0] ?? null;
        $recommendedHint = $topGap === null
            ? null
            : sprintf('build a %s validation gate covering %s', $topGap['blind_spot'], $topGap['task_family']);

        return [
            'schema_version'                  => self::SCHEMA_VERSION,
            'families'                        => $families,
            'gate_gap_rank'                   => $gateGapRank,
            'recommended_gate_task_hint'      => $recommendedHint,
        ];
    }

    private function coverageStrength(int $gateCount, int $blindSpotCount): string
    {
        if ($blindSpotCount === 0 && $gateCount >= 3) {
            return 'strong';
        }
        if ($gateCount === 0) {
            return 'weak';
        }

        return 'partial';
    }

    /** @return array<string, bool> */
    private function runtimeSafety(): array
    {
        return [
            'runtime_safety_all_false' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
        ];
    }
}
