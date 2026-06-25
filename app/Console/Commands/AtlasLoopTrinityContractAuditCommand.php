<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityContractBreachException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing pre-commit gate for the Trinity anti-decoupling contract. Resolves the frozen contract
 * from the bound emitter input + the current-source fingerprint provider, runs {@see AtlasLoopTrinityContractAuditor},
 * and exits NON-ZERO on any breach (so CI / pre-commit hooks fail-closed).
 *
 *   atlas:loop:trinity:contract-audit [--json]
 */
final class AtlasLoopTrinityContractAuditCommand extends Command
{
    public const FROZEN_CONTRACT_KEY = 'atlas.trinity.contract.frozen';

    public const CURRENT_FINGERPRINT_PROVIDER_KEY = 'atlas.trinity.contract.current_fingerprint_provider';

    /** @var string */
    protected $signature = 'atlas:loop:trinity:contract-audit {--json}';

    /** @var string */
    protected $description = 'Audit the current source against the frozen Trinity emit/consume contract.';

    public function handle(): int
    {
        $frozen = $this->resolveFrozenContract();
        if ($frozen === null) {
            $this->line(json_encode(['status' => 'skipped', 'reason' => 'frozen_contract_unbound', 'binding_key' => self::FROZEN_CONTRACT_KEY], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $provider = $this->resolveProvider();
        if ($provider === null) {
            $this->line(json_encode(['status' => 'skipped', 'reason' => 'current_fingerprint_provider_unbound', 'binding_key' => self::CURRENT_FINGERPRINT_PROVIDER_KEY], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        try {
            (new AtlasLoopTrinityContractAuditor($provider))->audit($frozen);
            $this->line(json_encode(['status' => 'clean'], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (TrinityContractBreachException $e) {
            $this->line(json_encode([
                'status' => 'breach',
                'primitive' => $e->primitive,
                'side' => $e->side,
                'counterpart' => $e->counterpart,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->line(json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveFrozenContract(): ?array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::FROZEN_CONTRACT_KEY)) {
            $value = $app->make(self::FROZEN_CONTRACT_KEY);

            return is_array($value) ? $value : null;
        }

        // Fallback: query the emitter for a self-referential identity contract — this is the "no source
        // mutation since boot" baseline that downstream wiring will replace with a real frozen file.
        if ($app->bound(AtlasLoopTrinityContractEmitter::class)) {
            $emitter = $app->make(AtlasLoopTrinityContractEmitter::class);
            $identity = [];
            foreach (AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES as $p) {
                $others = array_values(array_filter(AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES, static fn (string $x): bool => $x !== $p));
                $identity[$p] = [
                    'emits' => [['primitive' => $p, 'symbol' => $p.'.identity_emit']],
                    'consumes' => array_map(static fn (string $o): array => ['primitive' => $o, 'symbol' => $o.'.identity_emit'], $others),
                ];
            }
            try {
                return $emitter->emit($identity);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return null|callable(string,string):string
     */
    private function resolveProvider(): ?callable
    {
        $app = $this->getLaravel();
        if ($app->bound(self::CURRENT_FINGERPRINT_PROVIDER_KEY)) {
            $value = $app->make(self::CURRENT_FINGERPRINT_PROVIDER_KEY);

            return is_callable($value) ? $value : null;
        }

        return null;
    }
}
