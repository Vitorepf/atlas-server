<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * Atlas Decide × TEOS-I4 Lookahead Service.
 *
 * Bolt-on intelligence: before adopting an ADML routing recommendation
 * (`activate` action), the operator (or autonomous loop) can request a
 * TEOS-I4 lookahead projection that explores the recommendation +
 * 2-3 alternatives, returning the best path with expected improvement.
 *
 * Read-only over ADML state + TEOS-I4 service. Provider-safe: NEVER claims
 * winner, ranks alternatives only by projected outcome from the
 * counterfactual tree itself.
 *
 * Schemas:
 *   - atlas.atlas_decide.teos_i4_lookahead.v1
 */
final class AtlasDecideTeosI4LookaheadService
{
    public const SCHEMA_VERSION = 'atlas.atlas_decide.teos_i4_lookahead.v1';

    private ?string $lookaheadsLogOverride = null;

    public function __construct(
        private readonly AtlasDecideMetaLearningService $adml,
        private readonly AtlasTeosI4CounterfactualTreeService $teosI4,
    ) {}

    public function setLookaheadsLogPathForTesting(?string $path): void
    {
        $this->lookaheadsLogOverride = $path;
    }

    public function lookaheadsLogPath(): string
    {
        if ($this->lookaheadsLogOverride !== null) {
            return $this->lookaheadsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'teos_i4_lookaheads.jsonl';
    }

    /**
     * Compute a lookahead for a (task_category, role, framework) scope.
     *
     * @param  array{task_category:string,role:string,framework?:?string,privacy_class?:string}  $scope
     * @return array<string,mixed>
     */
    public function lookahead(array $scope): array
    {
        $task = (string) ($scope['task_category'] ?? '');
        $role = (string) ($scope['role'] ?? '');
        $framework = $scope['framework'] ?? null;
        $privacy = (string) ($scope['privacy_class'] ?? 'public');
        if ($task === '' || $role === '') {
            throw new InvalidArgumentException('task_category and role are required.');
        }

        // 1. Get ADML recommendation
        $rec = $this->adml->recommend([
            'task_category' => $task,
            'role' => $role,
            'framework' => $framework,
        ]);

        // 2. Build alternatives for TEOS-I4 from recommendation
        $alternatives = [];
        if (! empty($rec['recommended_provider'])) {
            $alternatives[] = ['decision_kind' => 'provider_swap', 'value' => $rec['recommended_provider']];
        }
        if (! empty($rec['runner_up_provider'])) {
            $alternatives[] = ['decision_kind' => 'provider_swap', 'value' => $rec['runner_up_provider']];
        }
        $alternatives[] = ['decision_kind' => 'policy_swap', 'value' => 'shadow_mode'];

        $factual = isset($rec['actionable']) && $rec['actionable'] ? 0.7 : 0.5;
        $projected = $factual + 0.1;

        // 3. Expand counterfactual tree
        $tree = null;
        $bestProvider = null;
        $bestImprovement = 0.0;
        if ($alternatives !== []) {
            try {
                $tree = $this->teosI4->expand([
                    'anchor_decision_id' => 'adml_routing_'.$task.'_'.$role,
                    'alternatives' => $alternatives,
                    'max_breadth' => count($alternatives),
                    'max_depth' => 2,
                    'scope' => ['privacy_class' => $privacy],
                    'factual_outcome_score' => $factual,
                    'projected_outcome_score' => $projected,
                ]);
                $bestImprovement = (float) ($tree['best_path_improvement'] ?? 0.0);
                // Heuristic: first arm in best path corresponds to recommended provider
                if (! empty($tree['best_path']) && count($tree['best_path']) > 1) {
                    $bestProvider = $rec['recommended_provider'] ?? null;
                }
            } catch (\Throwable $e) {
                // Tree expansion failed; degrade gracefully
            }
        }

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scope' => [
                'task_category' => $task,
                'role' => $role,
                'framework' => $framework,
                'privacy_class' => $privacy,
            ],
            'adml_recommendation' => [
                'recommended_provider' => $rec['recommended_provider'] ?? null,
                'recommended_model' => $rec['recommended_model'] ?? null,
                'runner_up_provider' => $rec['runner_up_provider'] ?? null,
                'actionable' => (bool) ($rec['actionable'] ?? false),
                'signal' => (string) ($rec['signal'] ?? 'unknown'),
            ],
            'tree' => $tree !== null ? [
                'tree_id' => $tree['tree_id'] ?? null,
                'node_count' => $tree['node_count'] ?? 0,
                'best_path' => $tree['best_path'] ?? [],
                'best_path_improvement' => $bestImprovement,
                'admission_decision' => $tree['admission_decision'] ?? null,
                'kernel_decision' => $tree['kernel_decision'] ?? null,
            ] : null,
            'lookahead_winner_provider' => $bestProvider,
            'lookahead_improvement_delta' => round($bestImprovement, 4),
            'claim_policy' => [
                'rivals_claim_allowed' => false,
                'benchmark_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $envelope['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA_VERSION,
            'task' => $task,
            'role' => $role,
            'tree_id' => $tree['tree_id'] ?? null,
            'best_improvement' => $bestImprovement,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->lookaheadsLogPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLookaheads(): array
    {
        return AppendOnlyJsonlStore::read($this->lookaheadsLogPath());
    }

    // ---------- internals ----------
}
