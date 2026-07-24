<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * Shared later-cycle chain projection/resolve for CodexReviewMerge Part* sub-sections.
 *
 * Full-pass reuse: de-duplicates byte-identical laterCycleChain* methods across Part07–Part11.
 * Expects host to define LATER_CYCLE_CHAIN_SPECS and inject $this->parent readiness service.
 */
trait CodexReviewMergeLaterCycleChain
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function laterCycleChainProjection(string $method, array $options): array
    {
        $spec = self::LATER_CYCLE_CHAIN_SPECS[$method];
        $env = ['options' => $options];
        [$upVar, $upMethod] = $spec['u'];
        $env[$upVar] = $this->parent->{$upMethod}($options);

        foreach ($spec['x'] as [$var, $src, $key]) {
            $env[$var] = (array) data_get($env[$src], $key, []);
        }

        $ready = data_get($env[$spec['r'][0]], $spec['r'][1]) === $spec['r'][2];

        foreach ($spec['l'] as $name => $list) {
            $env[$name] = ($list[0] ?? null) === '@tl' ? ($ready ? $list[1] : $list[2]) : $list;
        }

        $payload = $this->laterCycleChainResolve($spec['b'], $env, $ready, null);

        return $this->laterCycleChainResolve($spec['e'], $env, $ready, $payload);
    }

    private function laterCycleChainResolve(mixed $node, array $env, bool $ready, ?array $payload): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        switch ($node[0] ?? null) {
            case '@t': return $ready ? $node[1] : $node[2];
            case '@g': return data_get($env[$node[1]], $node[2]);
            case '@ga': return (array) data_get($env[$node[1]], $node[2], []);
            case '@gd': return data_get($env[$node[1]], $node[2], []);
            case '@c': return count($env[$node[1]]);
            case '@v': return $env[$node[1]];
            case '@p': return $payload;
            case '@h': return ReadinessHash::stable($payload);
        }

        $resolved = [];
        foreach ($node as $key => $value) {
            $resolved[$key] = $this->laterCycleChainResolve($value, $env, $ready, $payload);
        }

        return $resolved;
    }
}
