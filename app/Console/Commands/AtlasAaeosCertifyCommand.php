<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict;
use App\Services\Ai\Aaeos\Control\AaeosCycleRuntime;
use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\Aaeos\Control\AaeosScorecardProjector;
use App\Services\Ai\Aaeos\Spine\AaeosSpineGate;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use Illuminate\Console\Command;

/**
 * Exit 0 only when AAEOS GOD/SOTA certification checks pass.
 */
class AtlasAaeosCertifyCommand extends Command
{
    protected $signature = 'atlas:aaeos:certify
        {--json : Machine-readable JSON}';

    protected $description = 'Certify AAEOS GOD/SOTA composite and structural invariants.';

    public function handle(
        AaeosCycleRuntime $runtime,
        AaeosScorecardProjector $scorecard,
        AaeosSpineGate $spineGate,
    ): int {
        $checks = [];

        $auto = $runtime->runAutonomosCycle('certify autonomos path', [], true);
        $checks['autonomos_same_bar'] = ($auto['mode']['mode'] ?? null) === AaeosExecutorMode::AUTONOMOS
            && ($auto['elite_same_bar'] ?? false) === true;

        $halt = $runtime->runCycle('production wipe of billing database', [
            'source' => 'autonomos',
            'self_evolve' => true,
            'irreversible' => true,
        ], [], true);
        $checks['irreversible_halts'] = ($halt['status'] ?? '') === 'halted'
            && ($halt['admission']['verdict'] ?? '') === AaeosAdmissionVerdict::HALT_SOVEREIGN;

        $dev = $runtime->runCycle('fix login edge case', [
            'source' => 'human',
            'interactive' => true,
        ], [], true);
        $checks['dev_interactive'] = ($dev['mode']['mode'] ?? null) === AaeosExecutorMode::DEV;

        $spineBad = $spineGate->evaluate('forge', [
            'delivery_runtime_class' => 'App\\Evil\\PrivateDelivery',
            'parallel_ledger' => true,
        ]);
        $checks['spine_blocks_parallel'] = $spineBad['ok'] === false;

        $spineOk = $spineGate->evaluate('dev', []);
        $checks['spine_allows_shared'] = $spineOk['ok'] === true;

        $checks['dualcore_has_autonomos'] = in_array(
            DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS,
            DualCoreRouteDecisionCanon::ROUTES,
            true,
        );

        $card = $scorecard->project();
        $checks['scorecard_god_sota'] = (bool) ($card['god_sota'] ?? false);
        $checks['quarantine_imports_zero'] = ((int) ($card['quarantine_production_imports'] ?? 1)) === 0;

        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => ! $ok));
        $payload = [
            'schema' => 'atlas.aaeos.god_sota_certify.v1',
            'ok' => $failed === [],
            'checks' => $checks,
            'failed' => $failed,
            'scorecard' => $card,
            'composite' => $card['composite'] ?? null,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            foreach ($checks as $name => $ok) {
                $this->components->twoColumnDetail($name, $ok ? 'PASS' : 'FAIL');
            }
            $this->components->twoColumnDetail('composite', (string) ($card['composite'] ?? ''));
            $this->components->twoColumnDetail('result', $payload['ok'] ? 'GOD_SOTA' : 'NOT_YET');
        }

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
