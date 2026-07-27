<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\AcceptanceGate;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\VerificationCourtAcceptanceGate;

/**
 * OBRA #5 S1 — adapter do juiz de entrega de OBRA (AtlasObraCertificationService) para o piso
 * soberano, no padrão strangler dos adapters Dev/Forge/Autonomos: a superfície continua dona da
 * sua semântica local (halted / integrated check / per-step receipts), o gate soberano vira a
 * segunda opinião NÃO-ENFRAQUECÍVEL — em enforce, REFUSE só pode transformar certified→false
 * (only-adds), nunca o contrário.
 *
 * Nada fabricado: o bundle carrega exatamente o que o envelope da obra tem. A única derivação
 * (comentada) segue o precedente do automerge S0: 1 integrated check que RODOU e PASSOU = 1
 * check real com asserção de exit-code. Evidência que a esteira da obra ainda não treda
 * (judges, context_sufficiency) fica AUSENTE — o veredito soberano em observe é o raio-X do
 * gap, e o enforce só liga quando a esteira tredar (mesma postura da Obra #4 no automerge).
 */
final class AtlasObraGateAdapter implements AcceptanceGate
{
    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
    ) {}

    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        if (array_key_exists('verification_court', $bundle->nonFunctional)) {
            return (new VerificationCourtAcceptanceGate($this->floor))->certify($bundle, $trust);
        }

        return $this->floor->certify($bundle, $trust)->withInvariant(
            'verification_court_migration', 'observe', 'legacy_bundle_without_quality_foundry_court_facts',
        );
    }

    /**
     * @param  array<string,mixed>  $envelope  o envelope emitido por AtlasObraCertificationService::certify()
     */
    public function certifyObraDelivery(array $envelope): CertVerdict
    {
        return $this->certify($this->bundleFromObraEnvelope($envelope), TrustLevel::Autonomos);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    public function bundleFromObraEnvelope(array $envelope): AcceptanceBundle
    {
        $integrated = (array) ($envelope['integrated_test_result'] ?? []);
        $ranAndPassed = (bool) ($integrated['ran'] ?? false) && (bool) ($integrated['passed'] ?? false);

        $changedFiles = [];
        foreach ((array) ($envelope['nodes'] ?? []) as $node) {
            foreach ((array) (is_array($node) ? ($node['files_changed'] ?? []) : []) as $file) {
                $changedFiles[(string) $file] = true;
            }
        }

        $commands = [];
        if (isset($integrated['command']) && is_string($integrated['command']) && $integrated['command'] !== '') {
            $commands[] = $integrated['command'];
        }

        return AcceptanceBundle::fromArray([
            'criteria_hash' => (string) ($envelope['receipt_hash'] ?? ''),
            'frozen_hash' => (string) ($envelope['receipt_hash'] ?? ''),
            'changed_files' => array_keys($changedFiles),
            'changed_public_symbols' => [],
            'execution' => [
                'commands' => $commands,
                'claimed_status' => ((bool) ($envelope['certified'] ?? false)) ? 'passed' : 'failed',
                // 1 integrated check rodado+verde = 1 check real com asserção de exit-code
                // (precedente: evidência derivada do automerge S0). Sem check integrado, 0 —
                // o floor decide se os fatos sustentam a afirmação.
                'tests_run' => $ranAndPassed ? 1 : 0,
                'assertions_executed' => $ranAndPassed ? 1 : 0,
                'selected_tests' => [],
                'artifacts' => [],
            ],
            'mutation_report' => [
                'kill_ratio' => 0.0,
                'mutants_generated' => 0,
                'decision_surface_added' => false,
            ],
            // Lido do envelope como todo o resto — o docblock desta classe promete
            // "Nada fabricado", e este era o único bloco que não cumpria: quatro
            // literais afirmando scan limpo. Ausente agora vira [], e o floor
            // devolve honestamente security_scan_did_not_run, que é o mesmo
            // "raio-X do gap" que judges e context_sufficiency já produzem.
            'security_scan' => is_array($envelope['security_scan'] ?? null)
                ? (array) $envelope['security_scan']
                : [],
            'judges' => (array) ($envelope['judges'] ?? []),
            'context_sufficiency' => (int) ($envelope['context_sufficiency'] ?? 0),
            'non_functional' => [],
            'repair' => (array) ($envelope['repair'] ?? []),
        ]);
    }
}
