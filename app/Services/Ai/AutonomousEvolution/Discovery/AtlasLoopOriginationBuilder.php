<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use Throwable;

/**
 * THE CEILING LIFT — originates a BIG objective for the leap the rédea selected.
 *
 * The loop was stuck on "small" because its objective-builders only emitted modest single-method
 * complexity refactors. This builder decides refactor vs feature by which is the bigger leap, and:
 *
 *  - FEATURE / new capability: delegates to {@see AtlasEvolutionTaskGenerator::generateBestForTarget}
 *    — the autonomous lane that authors an acceptance test and (critically) VERIFIES it is genuinely
 *    RED before accepting. That RED→GREEN, diff-earned proof is the anti-gaming gate for new value:
 *    a fabricated/vacuous "feature" can't pass. This is what takes the loop past modest refactors.
 *  - REFACTOR: delegates to the existing synthesizers (plain-php → framework), behaviour-preserving,
 *    proven by the AST complexity-drop gate.
 *
 * Builds entirely ON existing machinery; adds only the decision + orchestration. Forbidden (petreo)
 * targets are rejected up front (the alignment floor). Feature origination is flag-gated default-OFF.
 */
final class AtlasLoopOriginationBuilder
{
    public function __construct(
        private readonly ?AtlasEvolutionTaskGenerator $generator = null,
        private readonly ?AtlasLoopRefactorObjectiveSynthesizer $refactorSynth = null,
        private readonly ?AtlasLoopFrameworkRefactorSynthesizer $frameworkSynth = null,
    ) {}

    /**
     * @param  array<string,mixed>  $leap  the selected candidate (path, shape?, signals, leverage)
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string, target_path:string, shape:string, self_contained:bool}|null
     */
    public function build(StateOfAtlas $state, array $leap, string $repoRoot, string $provider, string $targetId): ?array
    {
        $path = ltrim((string) ($leap['path'] ?? ''), '/');
        if ($path === '' || $state->isForbidden($path)) {
            return null; // alignment floor — never originate on petreo/forbidden targets
        }

        $shape = (string) ($leap['shape'] ?? $this->decideShape($state, $path));

        // FEATURE origination (the ceiling lift) — RED-verified new capability.
        if ($shape === 'feature' && (bool) config('atlas.loop.producer_feature_origination_enabled', false)) {
            $feature = $this->originateFeature($repoRoot, $path, $provider);
            if ($feature !== null) {
                return $feature;
            }
            // no RED-verified feature emerged → fall back to a refactor leap rather than nothing.
        }

        return $this->originateRefactor($repoRoot, $path, $provider, $targetId);
    }

    /**
     * Bigger-leap heuristic: a strategically-aligned target in an area with maturity HEADROOM is
     * where a NEW capability is the biggest jump; a complex existing hub is where a refactor is.
     */
    private function decideShape(StateOfAtlas $state, string $path): string
    {
        $align = $state->strategicWeightFor($path);
        $maturity = $state->maturityFor($path);

        return ($align >= 0.60 && $maturity <= 0.70) ? 'feature' : 'refactor';
    }

    /** @return array<string,mixed>|null */
    private function originateFeature(string $repoRoot, string $path, string $provider): ?array
    {
        try {
            $gen = ($this->generator ?? app(AtlasEvolutionTaskGenerator::class))
                ->generateBestForTarget($repoRoot, $path, [
                    'provider' => $provider,
                    'ambition' => 'high_value_capability',
                ]);
            if (($gen['generated'] ?? false) !== true || ! is_array($gen['task'] ?? null)) {
                return null;
            }
            $task = $gen['task'];
            $payload = is_array($task['payload'] ?? null) ? $task['payload'] : $task;
            // A new-capability objective may touch siblings → route through the framework materializer.
            $payload['materializer'] = $payload['materializer'] ?? 'framework';

            return [
                'objective' => (string) ($task['objective'] ?? ''),
                'payload' => $payload,
                'acceptance_hash' => (string) ($task['acceptance_hash'] ?? ($task['acceptance']['hash'] ?? '')),
                'target_path' => $path,
                'shape' => 'feature',
                'self_contained' => false, // framework-materialized, claimable via the framework gate
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function originateRefactor(string $repoRoot, string $path, string $provider, string $targetId): ?array
    {
        try {
            $r = ($this->refactorSynth ?? new AtlasLoopRefactorObjectiveSynthesizer)
                ->synthesize($repoRoot, $path, [], $provider, $targetId);
            if ($r !== null) {
                return $this->wrapRefactor($r, $path);
            }
        } catch (Throwable) {
            // fall through to the framework synthesizer
        }

        try {
            $r = ($this->frameworkSynth ?? app(AtlasLoopFrameworkRefactorSynthesizer::class))
                ->synthesizeFrameworkRefactor($repoRoot, $path, [], $provider, $targetId);
            if ($r !== null) {
                return $this->wrapRefactor($r, $path);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function wrapRefactor(array $r, string $path): array
    {
        return [
            'objective' => (string) ($r['objective'] ?? ''),
            'payload' => is_array($r['payload'] ?? null) ? $r['payload'] : [],
            'acceptance_hash' => (string) ($r['acceptance_hash'] ?? ''),
            'target_path' => $path,
            'shape' => 'refactor',
            'self_contained' => true, // single-file complexity-drop refactor
        ];
    }
}
