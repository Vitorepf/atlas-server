<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Programming\Sdd\Pipeline\Intent;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Throwable;

/**
 * Assembles a versioned ContextPack from placement + Code Intelligence summary
 * + memory-safe envelope context. Output is hashable and reproducible per
 * context-packages-and-projections.md.
 */
class ContextBuilder
{
    public function __construct(
        private readonly AtlasFeaturePlacementService $placement,
        private readonly EngineeringCodeIntelligenceService $codeIntelligence,
    ) {}

    public function build(OperationEnvelope $envelope, Intent $intent): ContextPack
    {
        $placement = $this->safePlacement($envelope->rawInput);
        $codeIntel = $this->safeCodeIntelligence();

        $stack = $this->resolveStack($intent, $placement);
        $packages = $this->resolvePackages($intent, $stack);

        $payload = [
            'envelope' => $envelope->toArray(),
            'intent' => $intent->toArray(),
            'placement' => $placement,
            'code_intelligence' => [
                'status' => $codeIntel['status'] ?? 'unknown',
                'module_count' => (int) ($codeIntel['module_count'] ?? 0),
                'symbol_count' => (int) ($codeIntel['symbol_count'] ?? 0),
                'last_indexed_at' => $codeIntel['last_indexed_at'] ?? null,
            ],
        ];

        $digest = hash('sha256', json_encode([$stack, $packages, $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return new ContextPack(
            stack: $stack,
            packages: $packages,
            payload: $payload,
            digest: $digest,
        );
    }

    private function resolveStack(Intent $intent, array $placement): string
    {
        $layer = (string) data_get($placement, 'placement.layer', '');
        $domain = (string) data_get($placement, 'placement.domain', $intent->domain);
        $stack = $layer !== '' ? "{$layer}-{$domain}" : "atlas-{$domain}";

        return strtolower(preg_replace('/[^a-z0-9-]/i', '-', $stack) ?: 'atlas-default');
    }

    /**
     * @return list<string>
     */
    private function resolvePackages(Intent $intent, string $stack): array
    {
        $packages = ['atlas.base.v1'];
        $packages[] = 'atlas.intent.'.$intent->type.'.v1';
        $packages[] = "atlas.stack.{$stack}.v1";
        if ($intent->riskLevel === 'high' || $intent->riskLevel === 'critical') {
            $packages[] = 'atlas.risk.high.v1';
        }
        if ($intent->harnessRequired) {
            $packages[] = 'atlas.harness.required.v1';
        }
        if ($intent->confidenceClass->isBlocking()) {
            $packages[] = 'atlas.confidence.blocking_ambiguity.v1';
        }

        return array_values(array_unique($packages));
    }

    /**
     * @return array<string,mixed>
     */
    private function safePlacement(string $intent): array
    {
        try {
            return $this->placement->place($intent, []);
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e::class];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function safeCodeIntelligence(): array
    {
        try {
            return $this->codeIntelligence->summary();
        } catch (Throwable $e) {
            return ['status' => 'unavailable', 'error' => $e::class];
        }
    }
}
