<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;

/** Applies only reversible preferred-route changes through the canonical routing owner. */
final class CausalLearningRoutingPromotionOwner implements CausalLearningPromotionOwner
{
    public const OWNER = 'atlas_conductor_routing_memory';

    public function __construct(private readonly AtlasConductorRoutingMemory $routing) {}

    public function apply(CausalLearningCandidate $candidate, string $nextVersion): array
    {
        $data = $candidate->data;
        $route = [
            'task_category' => trim((string) ($data['task_category'] ?? '')),
            'role' => trim((string) ($data['role'] ?? '')),
            'provider' => trim((string) ($data['provider'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
        ];
        if ($route['task_category'] === '' || $route['role'] === '' || $route['provider'] === '') {
            return $this->refused('routing_owner_scope_incomplete');
        }

        $before = ['version' => (string) ($data['baseline'] ?? ''), 'route' => $this->routing->preferredFor($route['task_category'], $route['role']) ?? []];
        $this->routing->applyPreferred($route);
        $after = ['version' => $nextVersion, 'route' => $this->routing->preferredFor($route['task_category'], $route['role']) ?? []];

        return [
            'applied' => true,
            'owner' => self::OWNER,
            'before_state' => $before,
            'after_state' => $after,
            'effect_receipt_hash' => CompoundingHash::make(['owner' => self::OWNER, 'action' => 'apply', 'before' => $before, 'after' => $after, 'candidate' => $candidate->candidateHash]),
        ];
    }

    public function rollback(CausalLearningPromotion $promotion): array
    {
        $task = $this->taskCategory($promotion);
        $role = $this->role($promotion);
        if ($task === '' || $role === '') {
            return [
                'rolled_back' => false,
                'owner' => self::OWNER,
                'before_state' => [],
                'after_state' => [],
                'effect_receipt_hash' => '',
                'reason' => 'routing_owner_scope_incomplete',
            ];
        }
        $before = ['version' => $promotion->activeVersion, 'route' => $this->routing->preferredFor($task, $role) ?? []];
        $this->routing->clearPreferred($task, $role);
        $after = ['version' => $promotion->rollbackVersion, 'route' => $this->routing->preferredFor($task, $role) ?? []];

        return [
            'rolled_back' => true,
            'owner' => self::OWNER,
            'before_state' => $before,
            'after_state' => $after,
            'effect_receipt_hash' => CompoundingHash::make(['owner' => self::OWNER, 'action' => 'rollback', 'before' => $before, 'after' => $after, 'candidate' => $promotion->candidateHash]),
        ];
    }

    private function taskCategory(CausalLearningPromotion $promotion): string
    {
        return trim((string) ($promotion->ownerBinding['task_category'] ?? ''));
    }

    private function role(CausalLearningPromotion $promotion): string
    {
        return trim((string) ($promotion->ownerBinding['role'] ?? ''));
    }

    /** @return array{applied:bool,owner:string,before_state:array<string,mixed>,after_state:array<string,mixed>,effect_receipt_hash:string,reason:string} */
    private function refused(string $reason): array
    {
        return ['applied' => false, 'owner' => self::OWNER, 'before_state' => [], 'after_state' => [], 'effect_receipt_hash' => '', 'reason' => $reason];
    }
}
