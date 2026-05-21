<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasSoftwareTwinSnapshot;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class AtlasSoftwareTwinRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.software_twin.v1';

    public const IMPACT_SCHEMA_VERSION = 'atlas.software_twin.impact.v1';

    public const CONTEXT_SCHEMA_VERSION = 'atlas.software_twin.context_envelope.v1';

    public const QUALITY_SCHEMA_VERSION = 'atlas.software_twin.quality_score.v1';

    public const SNAPSHOT_SCHEMA_VERSION = 'atlas.software_twin.snapshot.v1';

    /**
     * @var array<int,string>
     */
    private const CORE_TARGETS = [
        'acir' => 'app/Services/Engineering/EngineeringCodeIntelligenceService.php',
        'acrui' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
        'adrs' => 'app/Services/Engineering/AtlasDocumentationRealitySystemService.php',
        'aurc' => 'app/Services/Engineering/AtlasUniversalRealityCartographyService.php',
        'astr' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
        'aveor' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
        'aver' => 'app/Services/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeService.php',
        'aemor' => 'app/Services/Ai/Aemor/AtlasAemorRuntimeService.php',
    ];

    public function __construct(
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function twin(string $target = ''): array
    {
        $target = trim($target);
        $targetPath = $target !== '' ? $this->resolveTarget($target) : null;
        $targetReality = $targetPath !== null
            ? $this->codeReality->classify($targetPath)
            : null;

        $nodes = $this->coreNodes();
        if ($targetPath !== null && ! collect($nodes)->contains(fn (array $node): bool => $node['path'] === $targetPath)) {
            $nodes[] = $this->node('target', $targetPath);
        }

        $edges = $this->edges($nodes);
        $quality = $this->qualityFrom($nodes, $edges);
        $blockers = $this->blockers($nodes, $quality);

        return $this->envelope([
            'action' => 'twin',
            'target' => $target,
            'target_path' => $targetPath,
            'target_reality' => $this->compactReality($targetReality),
            'software_twin' => [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => 'read_only_living_system_twin',
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'nodes' => $nodes,
                'edges' => $edges,
                'runtime_lenses' => [
                    'code_intelligence' => 'EngineeringCodeIntelligenceService',
                    'operational_reality' => 'AtlasCodeRealityUsageIntelligenceService',
                    'documentation_reality' => 'AtlasDocumentationRealitySystemService',
                    'human_cartography' => 'AtlasUniversalRealityCartographyService',
                    'verified_execution' => 'AtlasVerifiedExecutionRuntimeService',
                    'outcome_learning' => 'AtlasAemorRuntimeService',
                ],
            ],
            'quality_score' => $quality,
            'blockers' => $blockers,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function impact(string $target): array
    {
        $target = trim($target);
        $targetPath = $this->resolveTarget($target);
        $reality = $targetPath !== null ? $this->codeReality->usageMap($targetPath) : $this->codeReality->classify($target);
        $classification = (string) ($reality['classification'] ?? 'unknown_requires_audit');
        $reachability = (array) data_get($reality, 'usage_map.reachability', data_get($reality, 'evidence.reachability', []));
        $edges = (array) data_get($reachability, 'edges', []);
        $ownerDocs = (array) data_get($reality, 'usage_map.owner_docs', data_get($reality, 'evidence.owner_docs', []));
        $tests = (array) data_get($reality, 'usage_map.tests', data_get($reality, 'evidence.tests', []));
        $riskLevel = $this->riskLevel($classification, $reachability, $tests, $ownerDocs);

        return $this->envelope([
            'schema_version' => self::IMPACT_SCHEMA_VERSION,
            'action' => 'impact',
            'target' => $target,
            'target_path' => $targetPath,
            'classification' => $classification,
            'impact' => [
                'risk_level' => $riskLevel,
                'reachable' => data_get($reachability, 'status') === 'reachable',
                'reachability_confidence' => data_get($reachability, 'confidence', 'none'),
                'affected_edges' => array_slice($edges, 0, 20),
                'owner_docs' => array_slice($ownerDocs, 0, 12),
                'required_tests' => array_slice($tests, 0, 12),
                'required_gates' => [
                    'php artisan atlas:code-reality reachability --target="'.$target.'" --json',
                    'php artisan atlas:software-twin impact --target="'.$target.'" --json',
                    'php artisan atlas:verified-evolution proof-plan --target="'.$target.'" --objective="<objective>" --json',
                    'php artisan atlas:aver:certify --json --strict',
                ],
            ],
            'blockers' => $targetPath === null ? [['reason' => 'target_not_found', 'target' => $target]] : [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function contextEnvelope(string $task, string $target = ''): array
    {
        $impact = $target !== '' ? $this->impact($target) : null;
        $contextPack = $this->codeReality->contextPack($task);

        return $this->envelope([
            'schema_version' => self::CONTEXT_SCHEMA_VERSION,
            'action' => 'context-envelope',
            'task' => trim($task),
            'target' => trim($target),
            'provider_safe' => true,
            'minimal_sources' => array_values(array_unique(array_merge(
                (array) ($contextPack['minimal_sources'] ?? []),
                [
                    'docs/engineering-knowledge-base/code-intelligence.md',
                    'docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md',
                    'docs/engineering-knowledge-base/atlas-verified-execution-runtime.md',
                ],
                $impact !== null ? (array) data_get($impact, 'impact.owner_docs', []) : [],
            ))),
            'required_tests' => $impact !== null ? (array) data_get($impact, 'impact.required_tests', []) : [],
            'required_gates' => array_values(array_unique(array_merge(
                (array) ($contextPack['required_commands'] ?? []),
                $impact !== null ? (array) data_get($impact, 'impact.required_gates', []) : [],
            ))),
            'do_not_claim' => [
                'ASTR_complete_without_runtime_tests',
                'AVEOR_complete_without_boundary_and_proof_plan',
                'safe_to_edit_without_boundary_contract',
                'safe_to_delete_without_acrui_quarantine',
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function qualityScore(): array
    {
        $nodes = $this->coreNodes();
        $edges = $this->edges($nodes);

        return $this->envelope([
            'schema_version' => self::QUALITY_SCHEMA_VERSION,
            'action' => 'quality-score',
            'quality_score' => $this->qualityFrom($nodes, $edges),
            'coverage' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'core_targets' => array_keys(self::CORE_TARGETS),
            ],
            'blockers' => [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(string $target = ''): array
    {
        $twin = $this->twin($target);
        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'status' => $twin['status'] ?? 'blocked',
            'target' => trim($target),
            'target_path' => $twin['target_path'] ?? null,
            'software_twin' => $twin['software_twin'] ?? [],
            'quality_score' => $twin['quality_score'] ?? [],
            'blockers' => $twin['blockers'] ?? [],
            'claim_policy' => $twin['claim_policy'] ?? [],
        ];
        $payload['snapshot_hash'] = MissionCanonicalHash::sha256($payload);

        if (Schema::hasTable('atlas_software_twin_snapshots')) {
            $record = AtlasSoftwareTwinSnapshot::query()->create($payload);
            $payload['snapshot_id'] = (string) $record->id;
            $payload['writes'] = true;
        } else {
            $payload['snapshot_id'] = null;
            $payload['writes'] = false;
        }

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function coreNodes(): array
    {
        return array_map(fn (string $path, string $id): array => $this->node($id, $path), self::CORE_TARGETS, array_keys(self::CORE_TARGETS));
    }

    /**
     * @return array<string,mixed>
     */
    private function node(string $id, string $path): array
    {
        $reality = $this->codeReality->classify($path);

        return [
            'id' => $id,
            'path' => $path,
            'exists' => File::exists(base_path($path)),
            'classification' => $reality['classification'] ?? 'unknown_requires_audit',
            'reachability_status' => data_get($reality, 'evidence.reachability.status'),
            'reachability_confidence' => data_get($reality, 'evidence.reachability.confidence'),
            'owner_doc_count' => count((array) data_get($reality, 'evidence.owner_docs', [])),
            'test_count' => count((array) data_get($reality, 'evidence.tests', [])),
            'entrypoint_count' => count((array) data_get($reality, 'evidence.entrypoints', [])),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,string>>
     */
    private function edges(array $nodes): array
    {
        $ids = array_column($nodes, 'id');
        $edges = [
            ['from' => 'acir', 'to' => 'acrui', 'kind' => 'feeds_operational_reality'],
            ['from' => 'acrui', 'to' => 'astr', 'kind' => 'feeds_runtime_usage_truth'],
            ['from' => 'adrs', 'to' => 'astr', 'kind' => 'feeds_documentation_truth'],
            ['from' => 'aurc', 'to' => 'astr', 'kind' => 'projects_human_map'],
            ['from' => 'astr', 'to' => 'aver', 'kind' => 'informs_verified_execution'],
            ['from' => 'aver', 'to' => 'aemor', 'kind' => 'feeds_outcome_learning'],
        ];

        return array_values(array_filter(
            $edges,
            static fn (array $edge): bool => in_array($edge['from'], $ids, true) && in_array($edge['to'], $ids, true)
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<int,array<string,string>>  $edges
     * @return array<string,mixed>
     */
    private function qualityFrom(array $nodes, array $edges): array
    {
        $readyNodes = count(array_filter($nodes, static fn (array $node): bool => $node['exists'] && in_array($node['classification'], ['active_runtime', 'active_read_only', 'headless_available'], true)));
        $ownerNodes = count(array_filter($nodes, static fn (array $node): bool => ((int) $node['owner_doc_count']) > 0));
        $testedNodes = count(array_filter($nodes, static fn (array $node): bool => ((int) $node['test_count']) > 0));
        $total = max(count($nodes), 1);
        $score = (int) round((($readyNodes / $total) * 40) + (($ownerNodes / $total) * 25) + (($testedNodes / $total) * 25) + (min(count($edges), 6) / 6 * 10));

        return [
            'schema_version' => self::QUALITY_SCHEMA_VERSION,
            'score' => $score,
            'status' => $score >= 80 ? 'ready' : 'review',
            'ready_node_count' => $readyNodes,
            'owner_doc_node_count' => $ownerNodes,
            'tested_node_count' => $testedNodes,
            'edge_count' => count($edges),
            'quality_floor' => 80,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @param  array<string,mixed>  $quality
     * @return array<int,array<string,string>>
     */
    private function blockers(array $nodes, array $quality): array
    {
        $blockers = [];
        foreach ($nodes as $node) {
            if (! $node['exists']) {
                $blockers[] = ['reason' => 'core_twin_target_missing', 'path' => (string) $node['path']];
            }
        }
        if (($quality['status'] ?? null) !== 'ready') {
            $blockers[] = ['reason' => 'software_twin_quality_below_floor', 'score' => (string) ($quality['score'] ?? 0)];
        }

        return $blockers;
    }

    private function resolveTarget(string $target): ?string
    {
        if ($target === '') {
            return null;
        }
        if (File::exists(base_path($target))) {
            return $target;
        }
        foreach (self::CORE_TARGETS as $path) {
            if (str_contains($path, $target) || str_contains(class_basename($path), $target)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $reality
     * @return array<string,mixed>|null
     */
    private function compactReality(?array $reality): ?array
    {
        if ($reality === null) {
            return null;
        }

        return [
            'classification' => $reality['classification'] ?? null,
            'target_path' => $reality['target_path'] ?? null,
            'reachability_status' => data_get($reality, 'evidence.reachability.status'),
            'reachability_confidence' => data_get($reality, 'evidence.reachability.confidence'),
        ];
    }

    /**
     * @param  array<string,mixed>  $reachability
     * @param  array<int,string>  $tests
     * @param  array<int,string>  $ownerDocs
     */
    private function riskLevel(string $classification, array $reachability, array $tests, array $ownerDocs): string
    {
        if (! in_array($classification, ['active_runtime', 'active_read_only', 'headless_available'], true)) {
            return 'high';
        }
        if (($reachability['confidence'] ?? null) !== 'high') {
            return 'medium';
        }
        if ($tests === [] || $ownerDocs === []) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function envelope(array $payload): array
    {
        $base = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => (($payload['blockers'] ?? []) === []) ? 'ready' : 'blocked',
            'writes' => false,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'rivals_run' => false,
                'executes_commands' => false,
                'authorizes_mutation' => false,
                'software_twin_complete_claimed' => false,
            ],
        ], $payload);
        $hashPayload = $base;
        unset($hashPayload['certification_hash']);
        $base['certification_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $base;
    }
}
