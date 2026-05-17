<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsIndustrialBenchmarkSuiteService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class AtlasForgeRivalsIndustrialBenchmarkSuiteCertification
{
    public const SCHEMA_VERSION = 'atlas.forge_rivals_industrial_benchmark_suite_certification.v1';

    public const CERTIFICATION_KEY = 'atlas_forge_rivals_industrial_benchmark_suite_certification';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MISSING_ARTIFACTS = 'missing_artifacts';

    /** @var list<string> */
    public const REQUIRED_INVARIANTS = [
        'canonical_doc_exists',
        'industrial_suite_action_wired',
        'industrial_presets_declared',
        'industrial_50_count_is_50',
        'industrial_100_count_is_100',
        'industrial_200_count_is_200',
        'specialized_presets_have_min_50',
        'required_domains_covered',
        'case_specs_have_required_industrial_metadata',
        'statistical_repeat_declares_repetition',
        'strong_claim_gates_fail_closed',
        'external_rivals_certification_blocked',
        'no_provider_call_in_readiness',
    ];

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly AtlasForgeRivalsIndustrialBenchmarkSuiteService $suite,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $repoRoot = $this->resolveRepoRoot($options);
        $invariants = $this->invariants($repoRoot);
        $artifacts = [
            'canonical_doc' => [
                'path' => 'docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md',
                'present' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md'),
            ],
            'suite_service' => [
                'class' => AtlasForgeRivalsIndustrialBenchmarkSuiteService::class,
                'present' => class_exists(AtlasForgeRivalsIndustrialBenchmarkSuiteService::class),
            ],
            'corpus_service' => [
                'class' => AtlasForgeRivalsProviderArenaCorpusService::class,
                'present' => class_exists(AtlasForgeRivalsProviderArenaCorpusService::class),
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
            'external_provider_call' => false,
            'external_rivals_certification_unlocked' => false,
            'separated_from' => 'external_rivals_certification',
            'evidence_command' => 'php artisan atlas:forge:rivals industrial-suite --json',
            'related_docs' => [
                'docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md',
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function invariants(string $repoRoot): array
    {
        $readiness = $this->suite->snapshot();
        $presets = (array) ($readiness['presets'] ?? []);
        $claimReadiness = (array) ($readiness['claim_readiness'] ?? []);
        $caseAudit = (array) ($readiness['case_spec_audit'] ?? []);
        $domainCoverage = (array) ($readiness['domain_coverage'] ?? []);

        $checks = [
            'canonical_doc_exists' => is_file($repoRoot.'/docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md'),
            'industrial_suite_action_wired' => in_array('industrial-suite', AtlasForgeRivalsCommand::ACTIONS, true),
            'industrial_presets_declared' => count(AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SETS) === 8,
            'industrial_50_count_is_50' => (int) data_get($presets, 'industrial-50.count', 0) === 50,
            'industrial_100_count_is_100' => (int) data_get($presets, 'industrial-100.count', 0) === 100,
            'industrial_200_count_is_200' => (int) data_get($presets, 'industrial-200.count', 0) === 200,
            'specialized_presets_have_min_50' => $this->specializedPresetsOk($presets),
            'required_domains_covered' => $this->domainsCovered($domainCoverage),
            'case_specs_have_required_industrial_metadata' => (bool) ($caseAudit['ok'] ?? false),
            'statistical_repeat_declares_repetition' => $this->statisticalRepeatOk(),
            'strong_claim_gates_fail_closed' => (bool) ($claimReadiness['ready_for_strong_benchmark_claim'] ?? true) === false
                && in_array('evidence_pack_complete', (array) ($claimReadiness['missing_gates'] ?? []), true)
                && in_array('replay_green', (array) ($claimReadiness['missing_gates'] ?? []), true),
            'external_rivals_certification_blocked' => (bool) ($readiness['external_rivals_certification_unlocked'] ?? true) === false
                && (bool) ($readiness['external_claim_allowed'] ?? true) === false,
            'no_provider_call_in_readiness' => (bool) ($readiness['external_provider_call'] ?? true) === false
                && (bool) ($readiness['provider_tokens_spent'] ?? true) === false,
        ];

        $out = [];
        foreach (self::REQUIRED_INVARIANTS as $name) {
            $out[$name] = [
                'ok' => (bool) ($checks[$name] ?? false),
                'status' => 'industrial_suite_v1',
                'description' => $name,
                'check' => $name,
                'evidence' => [
                    'app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsIndustrialBenchmarkSuiteService.php',
                    'app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php',
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $presets
     */
    private function specializedPresetsOk(array $presets): bool
    {
        foreach ([
            'ambiguous-bugs',
            'multi-day-refactors',
            'incident-response',
            'product-security-migrations',
            'statistical-repeat',
        ] as $preset) {
            if ((int) data_get($presets, $preset.'.count', 0) < 50) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $coverage
     */
    private function domainsCovered(array $coverage): bool
    {
        foreach (AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_DOMAINS as $domain) {
            if (! (bool) data_get($coverage, $domain.'.covered', false)) {
                return false;
            }
        }

        return true;
    }

    private function statisticalRepeatOk(): bool
    {
        $cases = $this->corpus->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT);
        if (count($cases) < 50) {
            return false;
        }
        foreach ($cases as $case) {
            if ((int) data_get($case, 'statistical_repeat.required_repetitions', 0) < 3) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function resolveRepoRoot(array $options): string
    {
        $root = trim((string) ($options['repo_root'] ?? base_path()));

        return rtrim($root, DIRECTORY_SEPARATOR);
    }
}
