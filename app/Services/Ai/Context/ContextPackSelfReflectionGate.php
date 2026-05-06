<?php

namespace App\Services\Ai\Context;

use App\Services\Ai\ValueObjects\AiContextPack;
use Illuminate\Support\Str;

class ContextPackSelfReflectionGate
{
    public const STATUS_SUFFICIENT = 'sufficient';

    public const STATUS_INSUFFICIENT = 'insufficient';

    public const STATUS_CONTRADICTORY = 'contradictory';

    public const STATUS_RISKY = 'risky';

    /**
     * @return array<string,mixed>
     */
    public function assess(AiContextPack|array $contextPack): array
    {
        $data = $contextPack instanceof AiContextPack ? $contextPack->toArray() : $contextPack;
        $contextRefs = $contextPack instanceof AiContextPack ? $contextPack->contextRefs() : (array) data_get($data, 'context_refs', []);
        $counts = $this->counts($data, $contextRefs);
        $reasons = [];
        $status = self::STATUS_SUFFICIENT;

        if ($this->hasContradiction($data, $contextRefs)) {
            $status = self::STATUS_CONTRADICTORY;
            $reasons[] = 'context_contains_contradiction_signal';
        } elseif ($this->isRisky($data)) {
            $status = self::STATUS_RISKY;
            $reasons[] = 'context_requires_careful_review_before_execution';
        } elseif ($this->isInsufficient($counts)) {
            $status = self::STATUS_INSUFFICIENT;
            $reasons[] = 'context_has_no_reusable_sources';
        }

        if ($reasons === []) {
            $reasons[] = 'context_has_reusable_sources';
        }

        return [
            'schema_version' => 'atlas.context_pack.self_reflection.v1',
            'status' => $status,
            'reasons' => $reasons,
            'counts' => $counts,
            'recommended_action' => $this->recommendedAction($status),
            'assessed_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     * @return array<string,int>
     */
    private function counts(array $data, array $contextRefs): array
    {
        return [
            'context_refs' => count($contextRefs),
            'recent_turns' => count((array) data_get($data, 'conversation.recent_turns', [])),
            'memory_recall' => count((array) data_get($data, 'memory.recall', [])),
            'memory_registry' => count((array) data_get($data, 'memory.registry', [])),
            'memory_verbatim' => count((array) data_get($data, 'memory.verbatim', [])),
            'memory_semantic' => count((array) data_get($data, 'memory.semantic', [])),
            'open_questions' => count((array) data_get($data, 'open_questions', [])),
            'excluded_context' => count((array) data_get($data, 'excluded_context', [])),
        ];
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function isInsufficient(array $counts): bool
    {
        return ($counts['context_refs']
            + $counts['recent_turns']
            + $counts['memory_recall']
            + $counts['memory_registry']
            + $counts['memory_verbatim']
            + $counts['memory_semantic']) === 0;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function isRisky(array $data): bool
    {
        return in_array((string) data_get($data, 'task.risk_level'), ['high', 'irreversible'], true)
            || (bool) data_get($data, 'gates.human_approval_required', false)
            || in_array((string) data_get($data, 'constraints.privacy_class'), ['private', 'sensitive', 'secret'], true);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     */
    private function hasContradiction(array $data, array $contextRefs): bool
    {
        $haystack = json_encode([
            'open_questions' => data_get($data, 'open_questions', []),
            'excluded_context' => data_get($data, 'excluded_context', []),
            'memory' => data_get($data, 'memory', []),
            'context_refs' => $contextRefs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return Str::of($haystack)->lower()->contains([
            'contradiction',
            'contradictory',
            'contraditorio',
            'contraditório',
            'contradiz',
            'conflict',
            'conflito',
        ]);
    }

    private function recommendedAction(string $status): string
    {
        return match ($status) {
            self::STATUS_SUFFICIENT => 'continue_with_context',
            self::STATUS_INSUFFICIENT => 'refresh_or_request_context',
            self::STATUS_CONTRADICTORY => 'surface_conflict_before_execution',
            self::STATUS_RISKY => 'require_review_before_execution',
            default => 'review_context_gate',
        };
    }
}
