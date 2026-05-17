<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Industrial Benchmark Suite v1 readiness.
 *
 * Local-only read model for industrial presets, case-spec coverage and
 * strong-claim gates. It never invokes a provider and never unlocks
 * external Rivals certification.
 */
final class AtlasForgeRivalsIndustrialBenchmarkSuiteService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.industrial_benchmark_suite.v1';

    public const CANONICAL_PHRASE = 'Rivals emits measured evidence; Atlas Decide decides model routing.';

    /** @var list<string> */
    private const STRONG_CLAIM_GATES = [
        'minimum_50_valid_cases',
        'evidence_pack_complete',
        'replay_green',
        'scorecard_per_case',
        'adjudicator_green',
        'matrix_report_green',
        'statistical_repetition_when_required',
        'confidence_explicit',
        'external_claim_blocked_without_human_approval',
    ];

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $presetRows = $this->presetRows();
        $domainCoverage = $this->domainCoverage();
        $caseSpecAudit = $this->caseSpecAudit();
        $claimReadiness = $this->strongClaimReadiness($input);

        $blockers = [];
        foreach ($presetRows as $preset => $row) {
            if (! (bool) ($row['ok'] ?? false)) {
                $blockers[] = 'industrial_preset_not_ready:'.$preset;
            }
        }
        foreach ($domainCoverage as $domain => $row) {
            if (! (bool) ($row['covered'] ?? false)) {
                $blockers[] = 'industrial_domain_not_covered:'.$domain;
            }
        }
        foreach ($caseSpecAudit['violations'] as $violation) {
            $blockers[] = 'case_spec_violation:'.$violation;
        }

        return [
            'status' => $blockers === [] ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'suite_id' => 'atlas-forge-rivals-industrial-benchmark-suite-v1',
            'canonical_phrase' => self::CANONICAL_PHRASE,
            'presets' => $presetRows,
            'required_domains' => AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_DOMAINS,
            'domain_coverage' => $domainCoverage,
            'case_spec_audit' => $caseSpecAudit,
            'strong_claim_gates' => self::STRONG_CLAIM_GATES,
            'claim_readiness' => $claimReadiness,
            'external_claim_allowed' => false,
            'external_rivals_certification' => 'blocked_until_human_approval',
            'external_rivals_certification_unlocked' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'blockers' => array_values(array_unique($blockers)),
            'next_command' => 'php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --dry-run --json',
            'note' => 'Readiness local da suite industrial. Sem evidence/replay/scorecard real, qualquer claim forte permanece bloqueado.',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function strongClaimReadiness(array $input): array
    {
        $preset = strtolower(trim((string) ($input['preset'] ?? AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50)));
        $validCases = (int) ($input['valid_cases'] ?? 0);
        if ($validCases <= 0 && in_array($preset, AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SETS, true)) {
            $validCases = count($this->corpus->casesForCaseSet($preset));
        }

        $requiresRepetition = $preset === AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT
            || (bool) ($input['requires_statistical_repetition'] ?? false);
        $gates = [
            'minimum_50_valid_cases' => $validCases >= 50,
            'evidence_pack_complete' => (bool) ($input['evidence_pack_complete'] ?? false),
            'replay_green' => (bool) ($input['replay_green'] ?? false),
            'scorecard_per_case' => (bool) ($input['scorecard_per_case'] ?? false),
            'adjudicator_green' => (bool) ($input['adjudicator_green'] ?? false),
            'matrix_report_green' => (bool) ($input['matrix_report_green'] ?? false),
            'statistical_repetition_when_required' => $requiresRepetition
                ? (bool) ($input['statistical_repetition_complete'] ?? false)
                : true,
            'confidence_explicit' => trim((string) ($input['confidence'] ?? '')) !== '',
            'external_claim_blocked_without_human_approval' => ! (bool) ($input['external_human_approval'] ?? false),
        ];

        $missing = [];
        foreach ($gates as $gate => $ok) {
            if (! $ok) {
                $missing[] = $gate;
            }
        }

        return [
            'preset' => $preset,
            'valid_cases' => $validCases,
            'requires_statistical_repetition' => $requiresRepetition,
            'gates' => $gates,
            'ready_for_strong_benchmark_claim' => $missing === [],
            'claim_level' => $missing === [] ? 'internal_benchmark_claim_candidate' : 'claim_blocked',
            'confidence' => trim((string) ($input['confidence'] ?? '')),
            'missing_gates' => $missing,
            'external_claim_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function presetRows(): array
    {
        $rows = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SETS as $preset) {
            $count = count($this->corpus->casesForCaseSet($preset));
            $min = AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_CASE_SET_MIN_VALID_CASES[$preset] ?? 50;
            $rows[$preset] = [
                'case_set' => $preset,
                'count' => $count,
                'min_valid_cases' => $min,
                'ok' => $count >= $min,
                'strong_claim_floor' => 'requires evidence/replay/scorecard/adjudicator/matrix/confidence before claim',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string,array{covered:bool,count:int}>
     */
    private function domainCoverage(): array
    {
        $coverage = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::INDUSTRIAL_DOMAINS as $domain) {
            $coverage[$domain] = ['covered' => false, 'count' => 0];
        }

        foreach ($this->corpus->industrialCases() as $case) {
            foreach ((array) ($case['industrial_domains'] ?? []) as $domain) {
                if (! isset($coverage[$domain])) {
                    continue;
                }
                $coverage[$domain]['covered'] = true;
                $coverage[$domain]['count']++;
            }
        }

        return $coverage;
    }

    /**
     * @return array<string,mixed>
     */
    private function caseSpecAudit(): array
    {
        $required = [
            'case_id',
            'category',
            'difficulty',
            'task_type',
            'objective',
            'acceptance_criteria',
            'evidence_requirements',
            'invalid_if',
            'expected_changed_files',
            'scoring_dimensions',
            'oracle',
            'hidden_oracle_metadata',
        ];
        $violations = [];
        foreach ($this->corpus->industrialCases() as $case) {
            foreach ($required as $field) {
                if (! array_key_exists($field, $case) || $case[$field] === [] || $case[$field] === '') {
                    $violations[] = (string) ($case['case_id'] ?? 'unknown').':missing_'.$field;
                }
            }
            foreach (['missing_evidence_pack', 'missing_replay', 'missing_case_scorecard', 'external_rivals_unlock_attempted'] as $gate) {
                if (! in_array($gate, (array) ($case['invalid_if'] ?? []), true)) {
                    $violations[] = (string) ($case['case_id'] ?? 'unknown').':invalid_if_missing_'.$gate;
                }
            }
        }

        return [
            'required_fields' => $required,
            'audited_cases' => count($this->corpus->industrialCases()),
            'ok' => $violations === [],
            'violations' => array_values(array_unique($violations)),
        ];
    }
}
