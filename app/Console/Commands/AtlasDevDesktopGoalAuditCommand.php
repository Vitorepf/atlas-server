<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopCertificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AtlasDevDesktopGoalAuditCommand extends Command
{
    protected $signature = 'atlas:dev:desktop:goal-audit
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the goal is not fully proven}
        {--efficiency-evidence= : Optional path to a comparative efficiency evidence JSON file}';

    protected $description = 'Audit whether Atlas Dev Desktop satisfies the full operator goal, including comparative efficiency evidence.';

    public function handle(AtlasDevDesktopCertificationService $certification): int
    {
        $desktopCertification = $certification->certify();
        $acceptanceEvidence = $this->readJson($this->receiptPath('desktop_acceptance/latest.json'));
        $efficiencyEvidence = $this->readJson(
            $this->option('efficiency-evidence')
                ? (string) $this->option('efficiency-evidence')
                : $this->receiptPath('desktop_efficiency/latest.json'),
        );

        $criteria = [
            $this->desktopCertificationCriterion($desktopCertification),
            $this->realProviderAcceptanceCriterion($acceptanceEvidence),
            $this->comparativeEfficiencyCriterion($efficiencyEvidence),
        ];

        $missing = array_values(array_map(
            static fn (array $criterion): string => (string) $criterion['id'],
            array_filter($criteria, static fn (array $criterion): bool => $criterion['status'] !== 'passed'),
        ));

        $payload = [
            'schema_version' => 'atlas.dev.desktop_goal_audit.v1',
            'status' => $missing === [] ? 'passed' : 'blocked',
            'objective' => [
                'desktop_usable_enterprise' => true,
                'requires_real_provider_acceptance' => true,
                'requires_comparative_efficiency_multiplier' => 10.0,
                'required_comparison_engines' => ['claude_code', 'codex'],
            ],
            'criteria' => $criteria,
            'missing_or_unproven' => $missing,
            'completion_claim_allowed' => $missing === [],
            'evidence_refs' => [
                'desktop_certification_command' => 'php artisan atlas:dev:desktop:certify --json --strict',
                'real_provider_smoke_command' => 'php artisan atlas:dev:desktop:real-smoke --yes --json',
                'efficiency_evidence_default_ref' => 'desktop_efficiency/latest.json',
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev Desktop goal audit', (string) $payload['status']);
            foreach ($criteria as $criterion) {
                $this->components->twoColumnDetail((string) $criterion['id'], (string) $criterion['status']);
            }
        }

        return (bool) $this->option('strict') && $payload['status'] !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $certification
     * @return array<string,mixed>
     */
    private function desktopCertificationCriterion(array $certification): array
    {
        return [
            'id' => 'desktop_operational_enterprise_certification',
            'status' => ($certification['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
            'evidence_schema' => $certification['schema_version'] ?? null,
            'stage_summary' => $certification['stage_summary'] ?? null,
            'blockers' => array_values((array) ($certification['remaining_blockers'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $evidence
     * @return array<string,mixed>
     */
    private function realProviderAcceptanceCriterion(?array $evidence): array
    {
        $violations = [];
        if ($evidence === null) {
            $violations[] = 'missing_desktop_acceptance_latest';
        } else {
            $expectations = [
                'status' => 'passed',
                'external_provider_call' => true,
                'completion_state' => 'passed',
                'scope_guard_status' => 'passed',
                'verification_status' => 'passed',
                'patch_apply_status' => 'applied',
                'workspace_assertion_passed' => true,
            ];
            foreach ($expectations as $key => $expected) {
                if (($evidence[$key] ?? null) !== $expected) {
                    $violations[] = $key;
                }
            }
            if ((int) ($evidence['tests_count'] ?? 0) < 1) {
                $violations[] = 'tests_count';
            }
            if ((array) ($evidence['honesty_flags'] ?? []) !== []) {
                $violations[] = 'honesty_flags';
            }
        }

        return [
            'id' => 'desktop_real_provider_acceptance',
            'status' => $violations === [] ? 'passed' : 'blocked',
            'evidence_schema' => $evidence['schema_version'] ?? null,
            'run_id' => $evidence['run_id'] ?? null,
            'provider' => $evidence['provider'] ?? null,
            'model_family' => $evidence['model_family'] ?? null,
            'receipt_hash' => $evidence['receipt_hash'] ?? null,
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $evidence
     * @return array<string,mixed>
     */
    private function comparativeEfficiencyCriterion(?array $evidence): array
    {
        $violations = [];
        if ($evidence === null) {
            $violations[] = 'missing_comparative_efficiency_evidence';
        } else {
            $engines = array_values(array_map('strval', (array) ($evidence['compared_against'] ?? [])));
            if (($evidence['schema_version'] ?? null) !== 'atlas.dev.desktop_efficiency_evidence.v1') {
                $violations[] = 'schema_version';
            }
            if (($evidence['status'] ?? null) !== 'passed') {
                $violations[] = 'status';
            }
            if (($evidence['measurement_mode'] ?? null) !== 'observed_operator_runs') {
                $violations[] = 'measurement_mode';
            }
            if ((float) ($evidence['measured_multiplier'] ?? 0.0) < 10.0) {
                $violations[] = 'measured_multiplier';
            }
            if (! is_string($evidence['input_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $evidence['input_sha256']) !== 1) {
                $violations[] = 'input_sha256';
            }
            foreach (['claude_code', 'codex'] as $requiredEngine) {
                if (! in_array($requiredEngine, $engines, true)) {
                    $violations[] = 'compared_against:'.$requiredEngine;
                }
                if ((float) data_get($evidence, 'aggregate_multipliers.'.$requiredEngine, 0.0) < 10.0) {
                    $violations[] = 'aggregate_multipliers:'.$requiredEngine;
                }
            }
            if ((int) ($evidence['case_count'] ?? 0) < 5) {
                $violations[] = 'case_count';
            }
            if (data_get($evidence, 'evidence_quality.required_task_kinds_covered') !== true) {
                $violations[] = 'required_task_kinds_covered';
            }
            if (data_get($evidence, 'evidence_quality.participant_evidence_refs_required') !== true) {
                $violations[] = 'participant_evidence_refs_required';
            }
            if (! is_array($evidence['cases'] ?? null) || count((array) ($evidence['cases'] ?? [])) < 5) {
                $violations[] = 'cases';
            }
            if ((array) ($evidence['blocking_findings'] ?? []) !== []) {
                $violations[] = 'blocking_findings';
            }
        }

        return [
            'id' => 'comparative_efficiency_10x_proof',
            'status' => $violations === [] ? 'passed' : 'blocked',
            'evidence_schema' => $evidence['schema_version'] ?? null,
            'measured_multiplier' => $evidence['measured_multiplier'] ?? null,
            'compared_against' => array_values((array) ($evidence['compared_against'] ?? [])),
            'case_count' => $evidence['case_count'] ?? null,
            'measurement_mode' => $evidence['measurement_mode'] ?? null,
            'evidence_quality' => $evidence['evidence_quality'] ?? null,
            'violations' => $violations,
        ];
    }

    private function receiptPath(string $relative): string
    {
        return rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
