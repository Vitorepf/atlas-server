<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractAuditor;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\AtlasLoopTrinityContractEmitter;
use App\Services\Ai\AutonomousEvolution\Trinity\AntiDecoupling\TrinityContractBreachException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arms the dormant {@see AtlasLoopTrinityContractAuditor::audit()} at the operator surface: ITERATES the frozen
 * Trinity contracts from the registry, runs audit() on EACH against the current source, and emits each
 * contract's anti-decoupling verdict (CLEAN, the breached primitive/side/counterpart, or skipped when no
 * live-source fingerprint provider is wired) as deterministic facts.
 *
 * Read-only — it audits, it never mutates. The registry and the current-source fingerprint provider come from
 * injectable seams; the default registry is the emitter's self-identity baseline (one frozen contract = "no
 * mutation since boot"), and without a live-source provider every contract is reported `skipped` (never a
 * fabricated CLEAN).
 */
final class AtlasLoopTrinityContractAuditCommand extends Command
{
    /** Container key for the injected frozen-contract registry (test/integration seam): list<array>|callable():list<array>. */
    private const REGISTRY_BINDING = 'atlas.loop.trinity.frozen_contracts';

    /** Container key for the injected current-source fingerprint provider: callable(string,string):string. */
    private const FINGERPRINT_PROVIDER_BINDING = 'atlas.loop.trinity.current_fingerprint_provider';

    protected $signature = 'atlas:loop:trinity-contract-audit {--json}';

    protected $description = 'Read-only anti-decoupling audit over the frozen Trinity contracts (verdict per contract).';

    public function handle(): int
    {
        $provider = $this->fingerprintProvider();
        $auditor = $provider !== null ? new AtlasLoopTrinityContractAuditor($provider) : null;

        $verdicts = [];
        foreach ($this->frozenContracts() as $contract) {
            $verdicts[] = $auditor === null
                ? ['registry_fingerprint' => (string) ($contract['registry_fingerprint'] ?? ''), 'verdict' => 'skipped', 'breach' => null]
                : $this->auditOne($auditor, $contract);
        }

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.trinity_contract_audit.v1',
            'provider_wired' => $provider !== null,
            'audited' => count($verdicts),
            'verdicts' => $verdicts,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function auditOne(AtlasLoopTrinityContractAuditor $auditor, array $contract): array
    {
        $fingerprint = (string) ($contract['registry_fingerprint'] ?? '');
        try {
            /** @var array{schema_version:string, primitives:array<string,array{emits:list<array<string,string>>,consumes:list<array<string,string>>,fingerprint:string}>, registry_fingerprint:string} $contract */
            return [
                'registry_fingerprint' => $fingerprint,
                'verdict' => $auditor->audit($contract),
                'breach' => null,
            ];
        } catch (TrinityContractBreachException $e) {
            return [
                'registry_fingerprint' => $fingerprint,
                'verdict' => 'BREACH',
                'breach' => [
                    'primitive' => $e->primitive,
                    'side' => $e->side,
                    'counterpart' => $e->counterpart,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * The frozen contracts to audit. Injectable registry seam; default = the emitter's self-identity baseline
     * (one frozen contract). Fail-closed: an unbuildable default yields an empty registry, never a throw.
     *
     * @return list<array<string,mixed>>
     */
    private function frozenContracts(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::REGISTRY_BINDING)) {
            $bound = $app->make(self::REGISTRY_BINDING);
            if (is_callable($bound)) {
                $bound = $bound();
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return $this->defaultRegistry();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultRegistry(): array
    {
        try {
            $emitter = $this->getLaravel()->make(AtlasLoopTrinityContractEmitter::class);
            $identity = [];
            foreach (AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES as $primitive) {
                $others = array_values(array_filter(
                    AtlasLoopTrinityContractEmitter::ALL_PRIMITIVES,
                    static fn (string $other): bool => $other !== $primitive,
                ));
                $identity[$primitive] = [
                    'emits' => [['primitive' => $primitive, 'symbol' => $primitive.'.identity_emit']],
                    'consumes' => array_map(
                        static fn (string $other): array => ['primitive' => $other, 'symbol' => $other.'.identity_emit'],
                        $others,
                    ),
                ];
            }

            return [$emitter->emit($identity)];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return null|callable(string,string):string
     */
    private function fingerprintProvider(): ?callable
    {
        $app = $this->getLaravel();
        if ($app->bound(self::FINGERPRINT_PROVIDER_BINDING)) {
            $bound = $app->make(self::FINGERPRINT_PROVIDER_BINDING);
            if (is_callable($bound)) {
                return $bound;
            }
        }

        return null;
    }
}
