<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Organism\ActuationReceiptStore;
use App\Services\Ai\Organism\AtlasOrganismActuationGate;
use App\Services\Ai\Organism\AtlasOrganismMissionService;
use App\Services\Ai\Organism\AtlasOrganismRegistry;
use App\Services\Ai\Organism\AtlasOrganismService;
use App\Services\Ai\Organism\EvidenceLedgerActuationReceiptStore;
use App\Services\Ai\Organism\Finance\DefaultTradingHonestyJudge;
use App\Services\Ai\Organism\Finance\FinanceDomainActuator;
use App\Services\Ai\Organism\Finance\FinanceDomainProposer;
use App\Services\Ai\Organism\Finance\FinanceDomainValidator;
use App\Services\Ai\Organism\Marketing\MarketingDomainActuator;
use App\Services\Ai\Organism\Marketing\MarketingDomainProposer;
use App\Services\Ai\Organism\Marketing\MarketingDomainValidator;
use App\Services\Ai\Organism\OpenBrainContextPackAnchor;
use App\Services\Ai\Organism\OrganismBrainAnchor;
use App\Services\Ai\Organism\OrganismProposalRecorder;
use App\Services\Ai\Organism\RealityGraphProposalRecorder;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Support\ServiceProvider;

/**
 * AOBG Organism domain DI (full-pass ASP peel).
 *
 * Propose-only multi-domain organism: finance + marketing domains, actuation
 * gate with receipt store, brain anchor + proposal recorder bindings.
 */
final class AtlasOrganismServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AOBG N4.F1–F3 — multi-domain organism (propose-only). Every domain is a
        // triplet; the actuate boundary is FINAL in AbstractDomainActuator and only ever
        // returns 'requires_operator' (no real money/orders/ad-spend/purchase/publish).
        // The brain anchor + the proposal recorder are bound to the REAL fused brain
        // (Open-Brain context pack + the AURG reality-graph store, fail-open). Tests
        // inject fakes for all three. The shipped domain is FINANCE first (sensitive,
        // on-machine, win-rate-forbidden honest metric). Constructing the registry is
        // FREE; the deterministic finance proposer spends NOTHING.
        $this->app->bind(OrganismBrainAnchor::class, OpenBrainContextPackAnchor::class);
        $this->app->bind(OrganismProposalRecorder::class, RealityGraphProposalRecorder::class);

        // N4.F4 — HARDENED PROPOSE-ONLY BOUNDARY + AUDIT. Every actuate() flows through the
        // single AtlasOrganismActuationGate: it re-admits the actuator (proves it inherits the
        // sealed, final, I/O-free act path — never re-declared), invokes it (the only outcome
        // is requires_operator), strips any executed-action artifact, and writes an append-only,
        // provider-safe audit RECEIPT to atlas_organism_actuations. Fail-open (a missing store
        // never throws/skips). Tests inject an in-memory fake receipt store.
        $this->app->bind(ActuationReceiptStore::class, EvidenceLedgerActuationReceiptStore::class);
        $this->app->singleton(AtlasOrganismActuationGate::class, function ($app): AtlasOrganismActuationGate {
            return new AtlasOrganismActuationGate($app->make(ActuationReceiptStore::class));
        });

        $this->app->singleton(AtlasOrganismRegistry::class, function (): AtlasOrganismRegistry {
            $registry = new AtlasOrganismRegistry;
            $registry->register(
                // F2: the proposer reuses the real strategy-loop backtest generation (default
                // runner = MeanReversionStrategy, on-machine, no provider); the validator's
                // FULL-bundle path delegates to the real, sealed TradingHonestyGate (DSR/PBO/
                // sealed holdout via the Python honest-metrics runtime) through the default
                // judge — win-rate forbidden, finance stays on-machine, propose-only.
                new FinanceDomainProposer,
                new FinanceDomainValidator(new HonestMetrics, new DefaultTradingHonestyJudge),
                new FinanceDomainActuator,
            );
            // F4: a 2nd domain proving the organism is DOMAIN-AGNOSTIC (not finance-special) —
            // MARKETING (non-finance, low-stakes, non-sensitive): proposes a campaign/content
            // DRAFT (deterministic, on-machine, no provider/publish), validated by a content-
            // quality heuristic (vanity engagement metrics forbidden), actuate = requires_operator
            // (NEVER publishes). Its actuator is admitted by the same propose-only gate.
            $registry->register(
                new MarketingDomainProposer,
                new MarketingDomainValidator,
                new MarketingDomainActuator,
            );

            return $registry;
        });

        // The organism service uses the WIRED actuation gate (with the receipt store) so every
        // production actuate() writes an audit receipt. The brain anchor + proposal recorder are
        // resolved from their bindings above. Constructing it is FREE.
        $this->app->singleton(AtlasOrganismService::class, function ($app): AtlasOrganismService {
            return new AtlasOrganismService(
                $app->make(AtlasOrganismRegistry::class),
                $app->make(OrganismBrainAnchor::class),
                $app->make(OrganismProposalRecorder::class),
                new CrossDomainTaxonomyMap,
                $app->make(AtlasOrganismActuationGate::class),
            );
        });

        // AOBG N4.F3 — the CROSS-DOMAIN MISSION SPINE. Reuses the N3 plan-DAG decomposition
        // (deterministic, cost-free) to break an intent that SPANS domains into nodes, routes
        // each to a canonical domain, governs each crossing with the ARPTL veto, and proposes+
        // validates per domain via the organism (propose-only; requires_operator for every
        // node). Constructing it is FREE; the decomposer/router/mesh spend NOTHING; the
        // proposers are on-machine/stubbable. Tests build it directly with fakes.
        $this->app->singleton(AtlasOrganismMissionService::class, function ($app): AtlasOrganismMissionService {
            return new AtlasOrganismMissionService(
                $app->make(AtlasOrganismService::class),
                $app->make(AtlasOrganismRegistry::class),
            );
        });
    }
}
