<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasAucriOptimizationAuditService
{
    public const SCHEMA_VERSION = 'atlas.aucri.optimization_audit.v1';

    private const STATUS_READY = 'ready';

    private const STATUS_BLOCKED = 'blocked';

    private const STATUS_PARTIAL = 'partial';

    /**
     * @var array<int, array{block:int, acronym:string, path:string}>
     */
    private const BLOCK_DOCS = [
        ['block' => 1, 'acronym' => 'ASEF', 'path' => 'docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md'],
        ['block' => 2, 'acronym' => 'AHRI', 'path' => 'docs/engineering-knowledge-base/atlas-hybrid-retrieval-infrastructure.md'],
        ['block' => 3, 'acronym' => 'AARF', 'path' => 'docs/engineering-knowledge-base/atlas-agentic-rag-framework.md'],
        ['block' => 4, 'acronym' => 'ACRS', 'path' => 'docs/engineering-knowledge-base/atlas-context-ranking-system.md'],
        ['block' => 5, 'acronym' => 'ACFQ', 'path' => 'docs/engineering-knowledge-base/atlas-context-freshness-quality-gate.md'],
        ['block' => 6, 'acronym' => 'ARFL', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-feedback-loop.md'],
        ['block' => 7, 'acronym' => 'AGRN', 'path' => 'docs/engineering-knowledge-base/atlas-graph-retrieval-network.md'],
        ['block' => 8, 'acronym' => 'AURG', 'path' => 'docs/engineering-knowledge-base/atlas-unified-reality-graph.md'],
        ['block' => 9, 'acronym' => 'APDR', 'path' => 'docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md'],
        ['block' => 10, 'acronym' => 'AREBA', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md'],
        ['block' => 11, 'acronym' => 'ARCLG', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-cost-latency-governor.md'],
        ['block' => 12, 'acronym' => 'ACOP', 'path' => 'docs/engineering-knowledge-base/atlas-context-observability-plane.md'],
        ['block' => 13, 'acronym' => 'ARPTL', 'path' => 'docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md'],
        ['block' => 14, 'acronym' => 'AKIF', 'path' => 'docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md'],
        ['block' => 15, 'acronym' => 'ACMF', 'path' => 'docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md'],
        ['block' => 16, 'acronym' => 'ACCR', 'path' => 'docs/engineering-knowledge-base/atlas-context-compiler-runtime.md'],
        ['block' => 17, 'acronym' => 'ATER', 'path' => 'docs/engineering-knowledge-base/atlas-token-economy-runtime.md'],
        ['block' => 18, 'acronym' => 'ACPFR', 'path' => 'docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md'],
    ];

    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $checks = [
            $this->checkMotherIndex(),
            $this->checkAllBlockDocsExist(),
            $this->checkOptimizationProtocol(),
            $this->checkTokenQualityGuards(),
            $this->checkCompilerLossAccounting(),
            $this->checkMemoryReservePolicy(),
            $this->checkEvaluationAndObservability(),
            $this->checkPrivacyAndIngestionLineage(),
            $this->checkParetoFrontierOptimization(),
            $this->checkParetoFrontierRuntimeSurface(),
        ];

        $criticalFailures = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'critical',
        ));
        $warnings = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ($check['status'] ?? null) !== 'passed'
                && ($check['severity'] ?? 'critical') === 'warn',
        ));

        $status = match (true) {
            $criticalFailures !== [] => self::STATUS_BLOCKED,
            $warnings !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'passed')),
                'critical_failed' => count($criticalFailures),
                'warn_failed' => count($warnings),
                'aucri_blocks' => count(self::BLOCK_DOCS),
            ],
            'checks' => $checks,
            'remaining_blockers' => array_values(array_map(
                static fn (array $check): string => (string) ($check['id'] ?? 'unknown'),
                $criticalFailures,
            )),
            'claims' => [
                'runtime_implemented' => false,
                'providers_invoked' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'scope' => 'documentation_and_contract_audit',
            ],
            'next_actions' => [
                'Implementar atlas.aucri.optimization_experiment.v1 receipts.',
                'Executar canary set de regressao token/qualidade em traces reais.',
                'Adicionar frontier report runtime para medir candidatos Pareto em Atlas Dev e Forge.',
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['audit_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMotherIndex(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md';
        $contents = $this->contents($path);
        $missing = [];

        foreach (self::BLOCK_DOCS as $doc) {
            if (! str_contains($contents, '| '.$doc['block'].' | '.$doc['acronym'].' |')) {
                $missing[] = $doc['acronym'];
            }
        }

        return $this->check(
            'mother_index_lists_18_blocks',
            $missing === [],
            'critical',
            ['path' => $path, 'missing_blocks' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAllBlockDocsExist(): array
    {
        $missing = array_values(array_filter(
            self::BLOCK_DOCS,
            fn (array $doc): bool => ! is_file($this->absolute($doc['path'])),
        ));

        return $this->check(
            'all_18_block_docs_exist',
            $missing === [],
            'critical',
            [
                'expected_count' => count(self::BLOCK_DOCS),
                'missing' => array_column($missing, 'path'),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkOptimizationProtocol(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md';
        $contents = $this->contents($path);
        $tokens = [
            'Context Ablation Testing',
            'Semantic Redundancy Removal',
            'Prompt Distillation',
            'Local Tool Substitution',
            'Provider-Aware Packing',
            'Segment ROI Scoring',
            'Regression Canary Set',
            'must_keep_coverage',
        ];

        return $this->checkTokens('continuous_optimization_protocol_present', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTokenQualityGuards(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-token-economy-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'must_keep_coverage = 1.0',
            'quality_gate_status',
            'loss_score',
            'Atlas Quality Token Check',
            'Atlas Local Pre-Reasoning Runtime',
            'Atlas Provider Model Selector',
        ];

        return $this->checkTokens('token_quality_guards_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCompilerLossAccounting(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-context-compiler-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'atlas.context.loss_check.v1',
            'Provider Profile Registry',
            'Must-Keep Preserver',
            'Prompt Budget Allocator',
            'Loss Accounting Engine',
            'Compiled Pack Hasher',
        ];

        return $this->checkTokens('compiler_loss_accounting_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkMemoryReservePolicy(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-cognitive-memory-fabric.md';
        $contents = $this->contents($path);
        $tokens = [
            'reserva absoluta do usuario: 3GB',
            'Atlas Memory Pressure Governor',
            'emergency_trim',
            'Atlas Context Delta Engine',
            'Atlas Snapshot & Memory Spillover',
        ];

        return $this->checkTokens('memory_reserve_policy_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkEvaluationAndObservability(): array
    {
        $arena = $this->contents('docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md');
        $observability = $this->contents('docs/engineering-knowledge-base/atlas-context-observability-plane.md');
        $missing = [];

        foreach (['golden sets', 'groundedness', 'context ROI', 'regressao'] as $token) {
            if (! str_contains($arena, $token)) {
                $missing[] = "AREBA:{$token}";
            }
        }

        foreach (['misses', 'ruido', 'blockers', 'sem texto cru'] as $token) {
            if (! str_contains($observability, $token)) {
                $missing[] = "ACOP:{$token}";
            }
        }

        return $this->check(
            'evaluation_and_observability_support_token_quality',
            $missing === [],
            'critical',
            ['missing_tokens' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPrivacyAndIngestionLineage(): array
    {
        $privacy = $this->contents('docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md');
        $ingestion = $this->contents('docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md');
        $missing = [];

        foreach (['provider-safe', 'redaction', 'retention', 'trust receipt'] as $token) {
            if (! str_contains($privacy, $token)) {
                $missing[] = "ARPTL:{$token}";
            }
        }

        foreach (['source packet', 'lineage', 'confidence', 'source_hash'] as $token) {
            if (! str_contains($ingestion, $token)) {
                $missing[] = "AKIF:{$token}";
            }
        }

        return $this->check(
            'privacy_and_ingestion_lineage_defined',
            $missing === [],
            'critical',
            ['missing_tokens' => $missing],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkParetoFrontierOptimization(): array
    {
        $path = 'docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md';
        $contents = $this->contents($path);
        $tokens = [
            'Pareto frontier search',
            'Marginal utility per token',
            'Constrained utility function',
            'Shadow multi-armed bandit',
            'must_keep_coverage < 1.0',
            'Pareto-dominado',
        ];

        return $this->checkTokens('pareto_frontier_optimization_defined', $path, $contents, $tokens);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkParetoFrontierRuntimeSurface(): array
    {
        $missing = [];

        foreach ([
            'app/Services/Ai/Context/AtlasContextParetoFrontierRuntimeService.php',
            'app/Console/Commands/AtlasContextParetoFrontierCommand.php',
            'tests/Feature/Ai/Context/ContextParetoFrontierRuntimeTest.php',
        ] as $path) {
            if (! is_file($this->absolute($path))) {
                $missing[] = $path;
            }
        }

        return $this->check(
            'pareto_frontier_runtime_surface_present',
            $missing === [],
            'critical',
            ['missing' => $missing],
        );
    }

    /**
     * @param  array<int,string>  $tokens
     * @return array<string,mixed>
     */
    private function checkTokens(string $id, string $path, string $contents, array $tokens): array
    {
        $missing = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! str_contains($contents, $token),
        ));

        return $this->check($id, $missing === [], 'critical', [
            'path' => $path,
            'missing_tokens' => $missing,
        ]);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, string $severity, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
            'evidence' => $evidence,
        ];
    }

    private function contents(string $path): string
    {
        $absolute = $this->absolute($path);

        return is_file($absolute) ? (string) file_get_contents($absolute) : '';
    }

    private function absolute(string $path): string
    {
        return rtrim($this->repoRoot ?? base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }
}
