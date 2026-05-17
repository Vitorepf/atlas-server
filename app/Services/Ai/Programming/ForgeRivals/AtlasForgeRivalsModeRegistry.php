<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use InvalidArgumentException;

/**
 * Atlas Forge Rivals · Mode Registry.
 *
 * The five official modes that govern what `atlas:forge:rivals` is allowed to
 * do on each invocation:
 *   - fair         : same model on both arms, no Atlas Decide / topology,
 *                    canonical comparison for claim-bearing runs.
 *   - full_power   : Atlas may use Decide / topology / governed fallback,
 *                    every provider used must be declared in the receipt.
 *   - diagnostic   : never dispatches provider; local validation only.
 *   - replay_only  : reads existing evidence; no provider call, no test exec.
 *   - local_fake   : in-process fake provider for CI smoke; never claims.
 *
 * Each mode declares whether it requires a real provider, allows
 * topology/Atlas Decide, and which models are admissible. The match
 * expression is exhaustive — unknown modes throw deterministically.
 */
final class AtlasForgeRivalsModeRegistry
{
    public const MODE_FAIR = 'fair';

    public const MODE_FULL_POWER = 'full_power';

    public const MODE_PROVIDER_ARENA = 'provider_arena';

    public const MODE_PROVIDER_PURE = 'provider_pure';

    public const MODE_DIAGNOSTIC = 'diagnostic';

    public const MODE_REPLAY_ONLY = 'replay_only';

    public const MODE_LOCAL_FAKE = 'local_fake';

    /** @var list<string> */
    public const MODES = [
        self::MODE_FAIR,
        self::MODE_FULL_POWER,
        self::MODE_PROVIDER_ARENA,
        self::MODE_PROVIDER_PURE,
        self::MODE_DIAGNOSTIC,
        self::MODE_REPLAY_ONLY,
        self::MODE_LOCAL_FAKE,
    ];

    public function __construct(
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
    ) {}

    /**
     * @return array{
     *   mode:string,
     *   requires_provider:bool,
     *   allows_atlas_decide:bool,
     *   allows_topology_declaration:bool,
     *   claim_eligible:bool,
     *   allowed_models:list<string>,
     *   note:string
     * }
     */
    public function mode(string $mode): array
    {
        return match (strtolower(trim($mode))) {
            self::MODE_FAIR => [
                'mode' => self::MODE_FAIR,
                'requires_provider' => true,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => false,
                'claim_eligible' => true,
                'allowed_models' => ['claude_sonnet', 'claude_opus', 'codex'],
                'note' => 'Fair: same model on both arms; canonical comparison for claims.',
            ],
            self::MODE_FULL_POWER => [
                'mode' => self::MODE_FULL_POWER,
                'requires_provider' => true,
                'allows_atlas_decide' => true,
                'allows_topology_declaration' => true,
                'claim_eligible' => true,
                'allowed_models' => ['claude_sonnet', 'claude_opus', 'codex', 'auto'],
                'note' => 'Full power: Atlas Decide and topology allowed; every provider must be declared.',
            ],
            self::MODE_PROVIDER_ARENA => [
                'mode' => self::MODE_PROVIDER_ARENA,
                'requires_provider' => true,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => false,
                'claim_eligible' => true,
                'allowed_models' => $this->models->canonicalModels(),
                'note' => 'Provider arena: cross-provider runner comparison with explicit arms, models and evidence.',
            ],
            self::MODE_PROVIDER_PURE => [
                'mode' => self::MODE_PROVIDER_PURE,
                'requires_provider' => true,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => false,
                'claim_eligible' => true,
                'allowed_models' => $this->models->canonicalModels(),
                'note' => 'Provider pure: raw provider runner against raw provider runner; Atlas system power excluded.',
            ],
            self::MODE_DIAGNOSTIC => [
                'mode' => self::MODE_DIAGNOSTIC,
                'requires_provider' => false,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => false,
                'claim_eligible' => false,
                'allowed_models' => ['claude_sonnet', 'claude_opus', 'codex'],
                'note' => 'Diagnostic: never calls provider; local validation only.',
            ],
            self::MODE_REPLAY_ONLY => [
                'mode' => self::MODE_REPLAY_ONLY,
                'requires_provider' => false,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => false,
                'claim_eligible' => false,
                'allowed_models' => [],
                'note' => 'Replay only: re-executes evidence; no provider, no test run.',
            ],
            self::MODE_LOCAL_FAKE => [
                'mode' => self::MODE_LOCAL_FAKE,
                'requires_provider' => false,
                'allows_atlas_decide' => false,
                'allows_topology_declaration' => true,
                'claim_eligible' => false,
                'allowed_models' => ['claude_sonnet', 'claude_opus', 'codex'],
                'note' => 'Local fake: in-process fake provider for CI; never produces a claim.',
            ],
            default => throw new InvalidArgumentException(
                "Unknown forge rivals mode: '{$mode}'. Supported: ".implode(', ', self::MODES).'.'
            ),
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::MODES as $m) {
            $out[$m] = $this->mode($m);
        }

        return $out;
    }
}
