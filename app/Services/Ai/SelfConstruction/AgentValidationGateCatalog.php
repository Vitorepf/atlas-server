<?php

namespace App\Services\Ai\SelfConstruction;

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
            'runtime_safety' => $this->runtimeSafety(),
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
