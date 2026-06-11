<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevRiskNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use Illuminate\Support\Str;

class DevTaskPacketRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.dev.task_packet.v1';

    public function __construct(
        private readonly AtlasExecutionDoctrineRuntimeService $aedpds = new AtlasExecutionDoctrineRuntimeService,
        private readonly AtlasExecutionDoctrineGateService $aedpdsGate = new AtlasExecutionDoctrineGateService,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public function build(array $input): array
    {
        $runId = $this->string($input['run_id'] ?? null) ?? 'dev-run-'.Str::uuid()->toString();
        $taskId = $this->string($input['task_id'] ?? null) ?? 'dev-task-'.substr(MissionCanonicalHash::sha256($input), 0, 12);
        $riskBand = AtlasDevRiskNormalizer::runtimeRiskBand($this->string($input['risk_band'] ?? $input['risk'] ?? null));
        $taskClass = $this->normalizeTaskClass($this->string($input['task_class'] ?? $input['task'] ?? null), $riskBand);
        $objective = $this->string($input['objective'] ?? $input['intent'] ?? null) ?? 'Atlas Dev task';
        $expectedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['expected_files'] ?? []);
        $contextRefs = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['context_refs'] ?? []);
        $allowedFiles = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['allowed_files'] ?? []);
        $reviewRefs = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['review_refs'] ?? []);
        $suggestedTests = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['suggested_tests'] ?? $input['tests'] ?? []);
        $acceptanceCriteria = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['acceptance_criteria'] ?? []);
        $requiredEvidence = AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['required_evidence'] ?? []);
        $doctrine = $this->aedpds->select([
            'task' => $objective,
            'surface' => 'atlas_dev',
            'workspace' => $this->string($input['workspace_slug'] ?? $input['workspace'] ?? null),
            'task_type' => $taskClass,
            'risk_level' => $riskBand,
            'code_changes_requested' => ! in_array($taskClass, ['trivial', 'read_only', 'review'], true),
            'files' => $expectedFiles,
            'missing_context' => $contextRefs === [] && $expectedFiles === [] && $allowedFiles === [],
            'senior_review_present' => $reviewRefs !== [],
        ]);
        $gate = $this->aedpdsGate->evaluate([
            'doctrine' => $doctrine,
            'acceptance_criteria' => $acceptanceCriteria,
            'context_refs' => $contextRefs,
            'tests' => $suggestedTests,
            'contracts' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['contracts'] ?? $input['contract_refs'] ?? []),
            'docs' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['canonical_docs'] ?? []),
            'review' => $reviewRefs,
            'evidence' => $requiredEvidence,
            'ux_expectations' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['ux_expectations'] ?? []),
        ]);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'task_id' => $taskId,
            'objective' => $objective,
            'task_class' => $taskClass,
            'risk_band' => $riskBand,
            'workspace_slug' => $this->string($input['workspace_slug'] ?? $input['workspace'] ?? null),
            'allowed_files' => $allowedFiles,
            'forbidden_files' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['forbidden_files'] ?? []),
            'context_refs' => $this->mergeStrings($contextRefs, $this->prefix('aedpds_context:', $doctrine['required_context'] ?? [])),
            'expected_files' => $expectedFiles,
            'suggested_tests' => $this->mergeStrings($suggestedTests, $this->prefix('aedpds_test:', $doctrine['required_tests'] ?? [])),
            'acceptance_criteria' => $this->mergeStrings($acceptanceCriteria, in_array('atdd', (array) ($doctrine['selected_primary_drivers'] ?? []), true) ? ['aedpds_acceptance_required'] : []),
            'required_evidence' => $this->mergeStrings($requiredEvidence, $this->prefix('aedpds_evidence:', $doctrine['required_evidence'] ?? [])),
            'source' => $this->string($input['source'] ?? null) ?? 'atlas_dev_runtime_intelligence',
            'aedpds' => [
                'doctrine' => $doctrine,
                'gate' => $gate,
            ],
        ];

        $payload['task_packet_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input): AtlasDevTaskPacket
    {
        $packet = $this->build($input);

        return AtlasDevTaskPacket::query()->updateOrCreate(
            ['run_id' => $packet['run_id'], 'task_id' => $packet['task_id']],
            [
                'schema_version' => $packet['schema_version'],
                'uuid' => $packet['task_packet_hash'],
                'objective' => $packet['objective'],
                'task_class' => $packet['task_class'],
                'risk_band' => $packet['risk_band'],
                'workspace_slug' => $packet['workspace_slug'],
                'allowed_files' => $packet['allowed_files'],
                'forbidden_files' => $packet['forbidden_files'],
                'context_refs' => $packet['context_refs'],
                'expected_files' => $packet['expected_files'],
                'suggested_tests' => $packet['suggested_tests'],
                'acceptance_criteria' => $packet['acceptance_criteria'],
                'required_evidence' => $packet['required_evidence'],
                'source' => $packet['source'],
                'task_packet_hash' => $packet['task_packet_hash'],
            ],
        );
    }

    private function normalizeTaskClass(?string $taskClass, string $riskBand): string
    {
        if (in_array($taskClass, ['trivial', 'read_only', 'patch', 'debug', 'review', 'repair', 'feature'], true)) {
            return $taskClass;
        }

        return $riskBand === 'low' ? 'patch' : 'feature';
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function mergeStrings(array $left, array $right): array
    {
        return AtlasDevStringListNormalizer::uniqueMergedStrings($left, $right);
    }

    /**
     * @return list<string>
     */
    private function prefix(string $prefix, mixed $items): array
    {
        return array_map(
            static fn (string $item): string => $prefix.$item,
            is_array($items) ? array_values(array_filter($items, 'is_string')) : [],
        );
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
