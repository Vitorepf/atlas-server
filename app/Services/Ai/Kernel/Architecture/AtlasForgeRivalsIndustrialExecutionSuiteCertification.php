<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsIndustrialExecutionSuiteService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AtlasForgeRivalsIndustrialExecutionSuiteCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_industrial_execution_suite_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_industrial_execution_suite_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'canonical_doc_exists',
        'industrial_execution_action_wired',
        'industrial_50_has_50_executable_cases',
        'no_empty_seed_cases',
        'tests_exist_per_case',
        'expected_changed_files_exist_per_case',
        'local_fake_execution_ready',
        'replay_required_before_claim',
        'matrix_required_before_claim',
        'statistical_repeat_blocks_until_repetitions_complete',
        'external_rivals_certification_blocked',
        'no_provider_call_in_readiness',
        'atlas_decide_advisory_only',
    ];

    public function __construct(
        private readonly AtlasForgeRivalsIndustrialExecutionSuiteService $suite,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $readiness = $this->suite->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            'ensure_fixtures' => true,
        ]);
        $statistical = $this->suite->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
            'ensure_fixtures' => false,
        ]);
        $invariants = $this->invariants($repoRoot, $readiness, $statistical);
        $artifacts = [
            'canonical_doc' => [
                'path' => AtlasForgeRivalsIndustrialExecutionSuiteService::CANONICAL_DOC,
                'present' => is_file($repoRoot.'/'.AtlasForgeRivalsIndustrialExecutionSuiteService::CANONICAL_DOC),
            ],
            'execution_suite_service' => [
                'class' => AtlasForgeRivalsIndustrialExecutionSuiteService::class,
                'present' => class_exists(AtlasForgeRivalsIndustrialExecutionSuiteService::class),
            ],
        ];
        $missing = array_values(array_map(
            static fn (string $key): string => 'missing_artifact:'.$key,
            array_keys(array_filter($artifacts, static fn (array $row): bool => ! (bool) ($row['present'] ?? false))),
        ));
        $blocked = array_keys(array_filter($invariants, static fn (array $row): bool => ! (bool) ($row['ok'] ?? false)));
        $status = match (true) {
            $missing !== [] => self::STATUS_MISSING_ARTIFACTS,
            $blocked !== [] => self::STATUS_BLOCKED,
            default => self::STATUS_AVAILABLE,
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certification_key' => self::CERTIFICATION_KEY,
            'status' => $status,
            'ok' => $status === self::STATUS_AVAILABLE,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'invariants' => $invariants,
            'invariants_all_true' => $status === self::STATUS_AVAILABLE,
            'invariants_summary' => array_map(static fn (array $row): bool => (bool) ($row['ok'] ?? false), $invariants),
            'artifacts' => $artifacts,
            'missing_artifacts' => $missing,
            'blockers' => array_values(array_unique(array_merge(
                array_map(static fn (string $name): string => $name.'_blocked', $blocked),
                $missing,
            ))),
            'readiness' => [
                'status' => $readiness['status'] ?? 'unknown',
                'total_cases' => $readiness['total_cases'] ?? 0,
                'executable_cases' => $readiness['executable_cases'] ?? 0,
                'claim_status' => $readiness['claim_status'] ?? [],
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'external_rivals_certification_unlocked' => false,
            'separated_from' => 'external_rivals_certification',
            'evidence_command' => 'php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json',
            'related_docs' => [
                AtlasForgeRivalsIndustrialExecutionSuiteService::CANONICAL_DOC,
                'docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $statistical
     * @return array<string,array<string,mixed>>
     */
    private function invariants(string $repoRoot, array $readiness, array $statistical): array
    {
        $claim = is_array($readiness['claim_status'] ?? null) ? $readiness['claim_status'] : [];
        $statisticalRepeat = is_array($statistical['statistical_repeat'] ?? null) ? $statistical['statistical_repeat'] : [];
        $checks = [
            'canonical_doc_exists' => is_file($repoRoot.'/'.AtlasForgeRivalsIndustrialExecutionSuiteService::CANONICAL_DOC),
            'industrial_execution_action_wired' => in_array('industrial-execution', AtlasForgeRivalsCommand::ACTIONS, true),
            'industrial_50_has_50_executable_cases' => (int) ($readiness['total_cases'] ?? 0) === 50
                && (int) ($readiness['executable_cases'] ?? 0) === 50,
            'no_empty_seed_cases' => (array) ($readiness['empty_seed_cases'] ?? []) === [],
            'tests_exist_per_case' => (array) ($readiness['missing_tests'] ?? []) === [],
            'expected_changed_files_exist_per_case' => (array) ($readiness['missing_expected_changed_files'] ?? []) === [],
            'local_fake_execution_ready' => (bool) ($readiness['local_fake_execution_ready'] ?? false),
            'replay_required_before_claim' => (bool) ($claim['requires_replay_green'] ?? false)
                && (bool) ($claim['ready_for_strong_benchmark_claim'] ?? true) === false,
            'matrix_required_before_claim' => (bool) ($claim['requires_matrix_report_green'] ?? false)
                && (bool) ($claim['ready_for_strong_benchmark_claim'] ?? true) === false,
            'statistical_repeat_blocks_until_repetitions_complete' => (bool) ($statisticalRepeat['confidence_ready'] ?? true) === false
                && in_array('statistical_repetitions_missing', (array) ($statistical['blockers'] ?? []), true),
            'external_rivals_certification_blocked' => (bool) ($readiness['external_rivals_certification_unlocked'] ?? true) === false,
            'no_provider_call_in_readiness' => (bool) ($readiness['external_provider_call'] ?? true) === false
                && (bool) ($readiness['provider_tokens_spent'] ?? true) === false,
            'atlas_decide_advisory_only' => (bool) ($readiness['advisory_only'] ?? false)
                && (bool) ($readiness['should_update_provider_topology'] ?? true) === false
                && (bool) ($readiness['never_changes_atlas_decide_topology'] ?? false)
                && ($readiness['owner_of_model_routing'] ?? null) === 'atlas_decide'
                && ($readiness['routing_effect'] ?? null) === 'none',
        ];

        $out = [];
        foreach (self::REQUIRED_INVARIANTS as $name) {
            $out[$name] = [
                'ok' => (bool) ($checks[$name] ?? false),
                'status' => 'industrial_execution_suite_v1',
                'description' => $name,
                'check' => $name,
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsIndustrialExecutionSuiteService.php',
                    'app/Console/Commands/AtlasForgeRivalsCommand.php',
                    AtlasForgeRivalsIndustrialExecutionSuiteService::CANONICAL_DOC,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $root = trim((string) ($options['repo_root'] ?? base_path()));

        return $root !== '' ? rtrim($root, '/') : base_path();
    }
}
