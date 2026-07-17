<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Console\Command;

/**
 * Atlas Agentic Engineering OS — operator entry point.
 *
 * Implements the canonical AAEOS CLI suite mandated by the runbook doc:
 *
 *   atlas:aaeos:runbook            — show the 17-phase canonical runbook
 *   atlas:aaeos:phase-handoff      — emit and validate an atlas.aaeos.phase.v1 envelope
 *   atlas:aaeos:phase-skip         — record a justified phase skip with receipt
 *   atlas:aaeos:department-status  — show department catalogue + canon-field coverage
 *   atlas:aaeos:cockpit            — render mission-control cockpit snapshot
 *   atlas:aaeos:universal-gates    — evaluate the 15 universal gates from a signals JSON file
 *
 * All sub-commands accept `--json` for machine output. Operator input is
 * limited to identifiers and hashes; raw payloads are rejected by the
 * underlying services (provider-safe by default).
 */
final class AtlasAaeosCommand extends Command
{
    protected $signature = 'atlas:aaeos
        {action : runbook|phase-handoff|phase-skip|department-status|cockpit|universal-gates|http-path-status}
        {--intent= : intent_id used by phase-handoff/cockpit/universal-gates}
        {--phase-in= : phase_in for phase-handoff/phase-skip}
        {--phase-out= : phase_out for phase-handoff}
        {--actor-kind=system : agent|operator|system}
        {--actor-id=aaeos.cli : actor identifier}
        {--evidence=* : sha256:* hashes (repeatable)}
        {--operator-signature= : operator signature for phase-handoff}
        {--autonomy=L1 : L0|L1|L2|L3|L4|L5|L6|L7}
        {--receipt= : receipt_id used by phase-skip}
        {--reason= : human-readable reason for phase-skip}
        {--signals= : JSON file with universal-gate signals}
        {--delivery-pack= : JSON file with delivery-pack composition (derives delivery_pack_completeness_min_0_95)}
        {--spec= : JSON file with compiled-spec shape (observe-only specCompletenessSignal)}
        {--quality-bar= : JSON file with quality-bar telemetry (observe-only M5 contract)}
        {--architect-spec-pack= : JSON file with architect spec-pack gate payload (observe-only M1)}
        {--predicted-impact= : JSON file with predicted-impact candidate (observe-only band classify)}
        {--predicted-impact-calibration= : JSON file with predicted-impact calibration rows (observe-only)}
        {--pre-review= : JSON file with pre-review advisory features (observe-only MULTN15-08)}
        {--reality-compiler-slice= : JSON file with Reality Compiler slice map (observe-only contract)}
        {--esp09-challenger= : JSON file with ESP-09 challenger context (observe-only advisory)}
        {--esp09-promotion-gate= : JSON file with ESP-09 promotion-gate context (observe-only delay)}
        {--esp09-refutation-series= : JSON file with ESP-09 refutation series events (observe-only)}
        {--dogfooding-leads= : JSON file with dogfooding friction events (observe-only mine)}
        {--reactive-saturation= : JSON file with reactive saturation windows (observe-only classify)}
        {--blocker-severity= : JSON file with phase blockers (observe-only severity gate)}
        {--phase-advance= : JSON file with atlas.aaeos.phase.v1 envelope (observe-only verdict)}
        {--required-gate-coverage= : JSON file with required/passed gate lists (observe-only)}
        {--outcome-causality= : JSON file with OutcomeEnvelope map (observe-only causality rank)}
        {--summary-fidelity= : JSON file with required_items + summary_text (observe-only fidelity coverage)}
        {--memory-injection-budget= : JSON file with ranked_items + budget caps (observe-only allocation)}
        {--memory-feedback-decay= : JSON file with memory feedback signals (observe-only decay score)}
        {--segment-importance= : JSON file with segments + token_budget (observe-only ranking)}
        {--context-pareto= : JSON file with variants + objective_direction (observe-only Pareto)}
        {--memory-recall-rank= : JSON file with memory candidate rows (observe-only recall rank)}
        {--portfolio-budget= : JSON file with portfolio allocation input (observe-only MULTK-06)}
        {--ambition-rung= : JSON file with ambition rung candidates + context (observe-only)}
        {--domain-lexical= : JSON file with query + fields (observe-only domain lexical score)}
        {--gated-corpus= : JSON file with corpus sources (observe-only gated candidate mine)}
        {--structured-facts= : JSON file with memory_type + facts (observe-only schema validate)}
        {--citation-grounding= : JSON file with response rows (observe-only citation grounding)}
        {--provenance-weight= : JSON file with evidence_refs + verified_refs (observe-only provenance weight)}
        {--recall-gap= : JSON file with weak-recall events (observe-only recall gap aggregate)}
        {--belief-cascade= : JSON file with origin + graph (observe-only belief cascade plan)}
        {--ledger-rotation= : JSON file with series id (observe-only ledger rotation policy)}
        {--evidence-vision= : JSON file with thesis + optional forbidden (observe-only fence)}
        {--gate-signal-spec-pack= : JSON file with acceptance_criteria (observe-only gate signal)}
        {--gate-signal-intent= : JSON file with intent disambiguation features (observe-only)}
        {--gate-signal-task-pack= : JSON file with tasks (observe-only task-pack atomicity)}
        {--gate-signal-phase= : JSON file with phaseOutputs (observe-only phase gate rollup)}
        {--threshold-ladder= : JSON file with level band ladder (observe-only normalize)}
        {--kb-embedding-coverage= : JSON file (any object) to observe KB embedding coverage ruler}
        {--code-symbol-embedding-coverage= : JSON file (any object) to observe code-symbol coverage}
        {--predicted-revert-digest= : JSON file with review-debt items (observe-only TETO-10 digest)}
        {--jina-dual-read-ledger= : JSON file with optional path (observe-only MAXA-04 ledger)}
        {--resource-budget= : JSON file with optional budget override (observe-only ELEV-27)}
        {--model-capability-spec= : JSON file with function+model (observe-only ELEV-29s)}
        {--measure-series-freshness= : JSON file with registry entry (observe-only ELEV-31)}
        {--verified-share= : JSON file with optional days (observe-only ELEV-12)}
        {--ragx-chain= : JSON file with optional deps (observe-only RAGX stages)}
        {--procedural-skill-promoter= : JSON file with optional floor/enqueue (observe-only MULTJ-04)}
        {--aaeos-phase-router= : JSON file with optional phase override (observe-only HTTP path phase)}
        {--aaeos-quality-bar= : JSON file (any object) to observe AAEOS department quality bar}
        {--aaeos-department-maturity= : JSON file (any object) to observe AAEOS department maturity}
        {--veto-propagation-watchdog= : JSON file with veto events (observe-only choreography)}
        {--repair-loop-guard= : JSON file with current_iteration (observe-only repair guard)}
        {--generated-contract-gate= : JSON file (any object) to observe Generated quarantine gate}
        {--maturity-band-classifier= : JSON file with band ladder + metrics (observe-only)}
        {--promotion-eligibility= : JSON file with department+metrics(+options) (observe-only)}
        {--debug-root-cause= : JSON file with debug context (observe-only root-cause)}
        {--cross-department-choreography= : JSON file with mode+payload (observe-only choreography)}
        {--docs-authority-locate= : JSON file with needle(+limit) (observe-only docs locate)}
        {--department-level-classifier= : JSON file with department+metrics+ladder (observe-only)}
        {--quality-bar-level-classifier= : JSON file with department+metrics+ladder (observe-only)}
        {--implementation-truth-evaluate= : JSON file with claimed_state+resolutions (observe-only)}
        {--phase-handoff-catalogue= : JSON file with optional autonomy_level (observe-only phases)}
        {--golden-counterfactual-replay= : JSON file with optional runs_path (observe-only)}
        {--composed-obra-arc= : JSON file with candidates(+context) (observe-only MULTN17-02)}
        {--exploratory-bets-portfolio= : JSON file with candidates(+context) (observe-only MULTN17-05)}
        {--n-capture-drill= : JSON file with optional days (observe-only N-capture report)}
        {--lote2-counterfactual-lift= : JSON file (any object) to observe MULTJ-03 lift}
        {--string-list-normalize= : JSON file with values list (observe-only string normalize)}
        {--threshold-comparator= : JSON file with comparator+observed+threshold (observe-only)}
        {--evidence-ref-normalize= : JSON file with evidence_refs list (observe-only)}
        {--doc-maturity-classify= : JSON file with sections map (observe-only DOC L0..L4)}
        {--claim-definition-of-done= : JSON file with claim map (observe-only DoD verdict)}
        {--array-field-reader= : JSON file with row+key (observe-only stringField)}
        {--veto-propagation-resolve= : JSON file with origin+veto_kind(+repair_iteration) (observe-only)}
        {--department-registry-validate= : JSON file with department or departments list (observe-only)}
        {--cognitive-immune-classify= : JSON file with text(+metadata) (observe-only immune class)}
        {--department-canonical-list= : JSON file (any object) to observe canonical department ids}
        {--universal-gates-catalogue= : JSON file (any object) to observe the 15-gate catalogue}
        {--outcome-attribution-types= : JSON file (any object) to observe outcome attribution types}
        {--phase-router-valid-phases= : JSON file (any object) to observe AAEOS valid phases}
        {--choreography-handoff-kinds= : JSON file (any object) to observe choreography handoff kinds}
        {--reality-compiler-phases= : JSON file (any object) to observe RealityCompiler execution phases}
        {--scope-risk-classes= : JSON file (any object) to observe SelfConstruction scope risk classes}
        {--organ-mesh-phases= : JSON file (any object) to observe ExternalBrain organ-mesh phases}
        {--telemetry-surfaces= : JSON file (any object) to observe telemetry surfaces/runtimes}
        {--phase-signature-l4= : JSON file (any object) to observe phases requiring L4 signature}
        {--blocker-severity-levels= : JSON file (any object) to observe AAEOS blocker severity levels}
        {--scope-high-risks= : JSON file (any object) to observe SelfConstruction high-risk classes}
        {--architect-spec-catalogue= : JSON file (any object) to observe architect spec-pack catalogue}
        {--surprise-gate-bands= : JSON file (any object) to observe surprise-gate band defaults}
        {--immune-calibration-contract= : JSON file (any object) to observe immune-calibration contract}
        {--cognitive-immune-check-contract= : JSON file (any object) to observe cognitive-immune check contract}
        {--cognition-evidence-statuses= : JSON file (any object) to observe cognition evidence statuses}
        {--capture-hmac-lineage= : JSON file (any object) to observe capture HMAC lineage stages}
        {--cognitive-function-axes= : JSON file (any object) to observe cognitive-function axes}
        {--gate-signal-contract= : JSON file (any object) to observe AAEOS gate-signal contract}
        {--rollback-trigger-contract= : JSON file (any object) to observe ACOS rollback-trigger contract}
        {--long-horizon-gate-contract= : JSON file (any object) to observe ACOS long-horizon gate contract}
        {--immune-signature-store-contract= : JSON file (any object) to observe immune signature store contract}
        {--promotion-protocol-states= : JSON file (any object) to observe ACOS promotion-protocol states}
        {--autonomous-work-cycle-stages= : JSON file (any object) to observe autonomous work cycle stages}
        {--immune-verdict-ledger-labels= : JSON file (any object) to observe immune verdict ledger labels}
        {--flywheel-funnel-stages= : JSON file (any object) to observe Atlas M flywheel funnel stages}
        {--mission-control-cockpit-schema= : JSON file (any object) to observe mission-control cockpit schema}
        {--evidence-vision-thesis-lifecycle= : JSON file (any object) to observe evidence-vision thesis lifecycle}
        {--exploratory-bets-portfolio-contract= : JSON file (any object) to observe exploratory bets portfolio contract}
        {--composed-obra-arc-contract= : JSON file (any object) to observe composed obra-arc contract}
        {--memory-feedback-decay-contract= : JSON file (any object) to observe memory feedback decay contract}
        {--spec-completeness-contract= : JSON file (any object) to observe SpecCompleteness contract}
        {--context-retention-schemas= : JSON file (any object) to observe summary-fidelity/segment-importance schemas}
        {--context-budget-schemas= : JSON file (any object) to observe memory-injection/Pareto/delivery-pack schemas}
        {--outcome-envelope-contract= : JSON file (any object) to observe ESP-06 OutcomeEnvelope contract}
        {--pre-review-advisory-contract= : JSON file (any object) to observe MULTN15-08 pre-review advisory contract}
        {--ambition-rung-policy-contract= : JSON file (any object) to observe MULTN17-01 ambition rung policy}
        {--reactive-saturation-contract= : JSON file (any object) to observe reactive saturation floors}
        {--portfolio-budget-contract= : JSON file (any object) to observe MULTK-06 portfolio budget contract}
        {--predicted-impact-band-contract= : JSON file (any object) to observe predicted-impact band contract}
        {--gated-corpus-contract= : JSON file (any object) to observe gated corpus miner contract}
        {--claim-definition-of-done-contract= : JSON file (any object) to observe Claim DoD contract fields}
        {--quality-bar-telemetry-contract= : JSON file (any object) to observe M5 quality-bar telemetry contract}
        {--doc-maturity-contract= : JSON file (any object) to observe DOC L0..L4 maturity contract}
        {--attempt-lifecycle-contract= : JSON file (any object) to observe ESP-01 attempt lifecycle contract}
        {--esp09-challenger-contract= : JSON file (any object) to observe ESP-09 challenger advisory contract}
        {--memory-weight-floors-contract= : JSON file (any object) to observe provenance/recall-gap/citation floors}
        {--delivery-pack-contract= : JSON file (any object) to observe delivery-pack completeness contract}
        {--domain-lexical-fact-schema-contract= : JSON file (any object) to observe domain-lexical + structured-fact floors}
        {--phase-advance-blocker-contract= : JSON file (any object) to observe phase-advance + blocker severity contract}
        {--outcome-causality-comparator-contract= : JSON file (any object) to observe outcome-causality + threshold floors}
        {--segment-importance-contract= : JSON file (any object) to observe segment-importance kind weights + bonuses}
        {--cognitive-immune-promotion-gate-contract= : JSON file (any object) to observe cognitive-immune G0..G8 contract}
        {--cognitive-immune-input-classifier-contract= : JSON file (any object) to observe immune input classifier + veto/promotion floors}
        {--window-evolution-hybrid-contract= : JSON file (any object) to observe window/evolution/hybrid floors}
        {--implementation-authority-contract= : JSON file (any object) to observe impl-truth/docs-authority/verified-share floors}
        {--evidence-volume-deferred-contract= : JSON file (any object) to observe evidence/volume/deferred/scorecard floors}
        {--watchdog-health-floors-contract= : JSON file (any object) to observe ACOS watchdog health floors}
        {--evidence-vision-composer-contract= : JSON file (any object) to observe evidence-vision composer floors}
        {--measure-series-maxa04-contract= : JSON file (any object) to observe measure-series freshness + Maxa04 dual-read floors}
        {--ragx-choreography-budget-contract= : JSON file (any object) to observe RAGX + choreography + budget floors}
        {--verified-share-scorecard-contract= : JSON file (any object) to observe verified-share + scorecard + golden/asef floors}
        {--aaeos-evidence-maturity-contract= : JSON file (any object) to observe AAEOS evidence/maturity/test/deferred floors}
        {--lote2-quality-bar-contract= : JSON file (any object) to observe lote-2 measure ids + quality-bar floors}
        {--embedding-coverage-truth-contract= : JSON file (any object) to observe embedding coverage + N-capture + truth floors}
        {--phase-gates-flywheel-contract= : JSON file (any object) to observe phase-gates map + flywheel + parallel/surprise floors}
        {--frontier-watchdog-cockpit-contract= : JSON file (any object) to observe frontier/watchdog/cockpit/window floors}
        {--runbook-department-atlas-contract= : JSON file (any object) to observe runbook/department/atlas/memory-fabric floors}
        {--outcome-causality-weights-contract= : JSON file (any object) to observe outcome-causality cause weight floors}
        {--watchdog-canary-floors-contract= : JSON file (any object) to observe remaining watchdog health + daily-canary floors}
        {--http-path-facade-contract= : JSON file (any object) to observe HTTP path facade + phase-router + department-id floors}
        {--phase-doc-promotion-ids-contract= : JSON file (any object) to observe phase/doc-maturity/promotion individual id floors}
        {--outcome-immune-scorecard-ids-contract= : JSON file (any object) to observe outcome/immune/scorecard/delivery/blocker/fabric id floors}
        {--gate-evolution-skill-freeze-contract= : JSON file (any object) to observe gate-signal/evolution/skill/immune-freeze floors}
        {--residual-schema-ledger-contract= : JSON file (any object) to observe residual schema/ledger/weights floors}
        {--unwired-watchdog-checks-contract= : JSON file (any object) to observe published unwired watchdog check floors}
        {--watchdog-runner-autonomy-ladder-contract= : JSON file (any object) to observe watchdog-runner aggregate + autonomy-ladder check floors}
        {--outcome-envelope-adapters-contract= : JSON file (any object) to observe ESP-06 outcome envelope adapter kinds}
        {--implementation-truth-rank-contract= : JSON file (any object) to observe implementation-truth rank + capture-hmac stage floors}
        {--secondary-report-schemas-contract= : JSON file (any object) to observe secondary report schema floors (locate/bridge/lote2/calibration)}
        {--department-io-schemas-contract= : JSON file (any object) to observe department catalogue IO/evidence schema floors}
        {--http-path-watchdog-observe-schemas-contract= : JSON file (any object) to observe HTTP-path + watchdog + evaluator observe schemas}
        {--evaluator-observe-helpers-contract= : JSON file (any object) to observe remaining evaluator observe-helper + long-horizon area schemas}
        {--gate-report-schema-contract= : JSON file (any object) to observe gate-report schema + catalogue floor counts}
        {--docs-authority-confidence-keys-contract= : JSON file (any object) to observe docs-authority confidence key floors}
        {--maxa04-promotion-floors-contract= : JSON file (any object) to observe MAXA-04 embedding + promotion/memory-fabric floors}
        {--composed-obra-lifecycle-floors-contract= : JSON file (any object) to observe composed-obra + evidence-vision lifecycle floors}
        {--resource-budget-host-floors-contract= : JSON file (any object) to observe resource-budget host/engine floor defaults}
        {--verified-share-procedural-floors-contract= : JSON file (any object) to observe verified-share + procedural + n-capture floor defaults}
        {--long-horizon-gate-floors-contract= : JSON file (any object) to observe long-horizon gate + ESP-09 refutation floor defaults}
        {--ledger-rotation-impact-floors-contract= : JSON file (any object) to observe ledger-rotation + predicted-impact + vision consecutive floors}
        {--observe-helper-limit-floors-contract= : JSON file (any object) to observe recall-gap/belief-cascade/teto10/docs-locate helper limit floors}
        {--outcome-envelope-bool-fields-contract= : JSON file (any object) to observe outcome-envelope adapter bool field floors + boolOrNull helper}
        {--quality-bar-cognitive-floors-contract= : JSON file (any object) to observe quality-bar + cognitive-atlas + long-horizon enabled floors}
        {--parallel-substrate-bridge-floors-contract= : JSON file (any object) to observe parallel/substrate/envelope-bridge + procedural enqueue floors}
        {--ops-config-toggle-floors-contract= : JSON file (any object) to observe http-path/remint/watchdog/rollback/scorecard/generated/evolution/immune config-toggle floors}
        {--ragx-immune-substrate-config-floors-contract= : JSON file (any object) to observe RAGX flags + substrate/immune/surprise/compaction config floors}
        {--residual-ops-config-floors-contract= : JSON file (any object) to observe residual RAGX seam flags + phase/disk/hmac/model-budget config floors}
        {--ragx-stage-mechanism-floors-contract= : JSON file (any object) to observe RAGX stage/mechanism/pending-window + evolution evidence path floors}
        {--department-extended-io-procedural-floors-contract= : JSON file (any object) to observe department extended IO schemas + procedural promoter floors}
        {--runtime-status-mode-floors-contract= : JSON file (any object) to observe RAGX/window/parallel runtime status-mode floors}
        {--outcome-maxa04-lote2-status-floors-contract= : JSON file (any object) to observe OutcomeEnvelope/Maxa04/Lote2 status floors}
        {--lote2-reason-ambition-portfolio-floors-contract= : JSON file (any object) to observe Lote2 reason + ambition/portfolio class floors}
        {--esp09-bets-obra-status-floors-contract= : JSON file (any object) to observe Esp09/bets/obra status-reason floors}
        {--ncapture-promotion-lifecycle-status-floors-contract= : JSON file (any object) to observe NCapture/promotion/lifecycle status-reason floors}
        {--asef-remint-immune-ragx-status-floors-contract= : JSON file (any object) to observe ASEF/remint/immune/RAGX status-reason floors}
        {--decay-veto-numeric-choreography-floors-contract= : JSON file (any object) to observe decay/veto/numeric-range/choreography floors}
        {--evidence-temporal-hmac-calibration-floors-contract= : JSON file (any object) to observe evidence/temporal/hmac/calibration floors}
        {--verified-share-capability-truth-ambition-floors-contract= : JSON file (any object) to observe verified-share/capability/truth/ambition floors}
        {--canary-integrity-window-rotation-floors-contract= : JSON file (any object) to observe canary/integrity/window/rotation floors}
        {--golden-pareto-scorer-maxa04-floors-contract= : JSON file (any object) to observe golden/pareto/scorer/maxa04 floors}
        {--parallel-procedural-watchdog-residual-floors-contract= : JSON file (any object) to observe parallel/procedural/watchdog residual floors}
        {--lote2-decomposer-redaction-unobserved-floors-contract= : JSON file (any object) to observe lote2/decomposer/redaction + previously unobserved floors}
        {--unobserved-status-basis-handoff-floors-contract= : JSON file (any object) to observe remaining published but unwired status/basis/handoff floors}
        {--choreography-repair-review-measure-freeze-floors-contract= : JSON file (any object) to observe choreography repair/review + measure-freeze/autonomy-ladder export floors}
        {--residual-error-basis-status-floors-contract= : JSON file (any object) to observe residual error/basis/status floors + newly published ready/calibrated/rotate-age}
        {--signature-mode-suspended-unknown-floors-contract= : JSON file (any object) to observe signature modes + suspended/unknown/operator floors}
        {--architect-verdict-freeze-ready-floors-contract= : JSON file (any object) to observe architect/verdict/freeze/ready/rollback floors}
        {--mission-control-pending-partial-floors-contract= : JSON file (any object) to observe mission-control/reality-compiler/claim partial floors}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Agentic Engineering OS — operator CLI for the 17-phase runbook.';

    public function handle(
        DepartmentContractRuntime $departments,
        AaeosPhaseHandoffService $phases,
        AtlasUniversalGatesEvaluator $gates,
        RunbookOrchestrator $runbook,
        AtlasMissionControlCockpitService $cockpit,
        AtlasAaeosHttpPathFacadeService $httpPath,
    ): int {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        return match ($action) {
            'runbook' => $this->runbook($runbook, $phases, $json),
            'phase-handoff' => $this->phaseHandoff($phases, $json),
            'phase-skip' => $this->phaseSkip($phases, $json),
            'department-status' => $this->departmentStatus($departments, $json),
            'cockpit' => $this->cockpit($cockpit, $json),
            'universal-gates' => $this->universalGates($gates, $json),
            'http-path-status' => $this->httpPathStatus($httpPath, $json),
            default => $this->failWith("unknown action '{$action}'"),
        };
    }

    private function httpPathStatus(AtlasAaeosHttpPathFacadeService $httpPath, bool $json): int
    {
        $configured = (string) config('atlas.aaeos.http_path_phase', 'legacy');
        $payload = $httpPath->telemetrySnapshot($configured);

        if ($json) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('AAEOS HTTP Path · '.$configured);
        $this->line('  facade_active: '.($payload['facade_active'] ? 'yes' : 'no'));
        foreach ($payload['counters'] as $name => $value) {
            $this->line(sprintf('  counter.%s: %d', $name, $value));
        }
        $samples = (int) ($payload['latency_ms']['samples'] ?? 0);
        if ($samples > 0) {
            $avg = (int) round(((int) $payload['latency_ms']['sum']) / max(1, $samples));
            $this->line(sprintf('  latency_ms.avg: %d (samples=%d, max=%d)', $avg, $samples, (int) $payload['latency_ms']['max']));
        } else {
            $this->line('  latency_ms: no samples yet');
        }

        return self::SUCCESS;
    }

    private function runbook(RunbookOrchestrator $runbook, AaeosPhaseHandoffService $phases, bool $json): int
    {
        $payload = [
            'schema' => 'atlas.aaeos.runbook.v1',
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => array_map(
                fn (string $p, int $i) => [
                    'index' => $i,
                    'phase' => $p,
                    'gates' => $phases->gatesForPhase($p),
                ],
                AaeosPhaseHandoffService::PHASES,
                array_keys(AaeosPhaseHandoffService::PHASES),
            ),
            'department_flow' => RunbookOrchestrator::DEFAULT_FLOW,
        ];

        $this->emit($payload, $json);

        return self::SUCCESS;
    }

    private function phaseHandoff(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phaseIn = (string) ($this->option('phase-in') ?? '');
        $phaseOut = (string) ($this->option('phase-out') ?? '');
        if ($intent === '' || $phaseIn === '' || $phaseOut === '') {
            return $this->failWith('phase-handoff requires --intent, --phase-in and --phase-out');
        }

        try {
            $envelope = $phases->emit(
                intentId: $intent,
                phaseIn: $phaseIn,
                phaseOut: $phaseOut,
                actor: [
                    'kind' => (string) $this->option('actor-kind'),
                    'id' => (string) $this->option('actor-id'),
                    'provider' => null,
                ],
                inputs: [],
                outputs: [],
                evidenceHashes: array_values(array_filter((array) $this->option('evidence'))),
                operatorSignature: (string) ($this->option('operator-signature') ?? '') ?: null,
                autonomyLevel: (string) $this->option('autonomy'),
            );
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function phaseSkip(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phase = (string) ($this->option('phase-in') ?? '');
        $receipt = (string) ($this->option('receipt') ?? '');
        $reason = (string) ($this->option('reason') ?? '');
        if ($intent === '' || $phase === '' || $receipt === '' || $reason === '') {
            return $this->failWith('phase-skip requires --intent --phase-in --receipt --reason');
        }
        try {
            $envelope = $phases->skip($intent, $phase, $receipt, $reason, (string) $this->option('autonomy'));
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function departmentStatus(DepartmentContractRuntime $departments, bool $json): int
    {
        $catalogue = $departments->catalogue();
        $missing = $departments->missingFieldsByDepartment();
        $this->emit([
            'schema' => $catalogue['schema_version'],
            'department_count' => $catalogue['department_count'],
            'canon_department_count' => $catalogue['canon_department_count'],
            'schema_fields_12_present' => $catalogue['schema_fields_12_present'],
            'departments' => array_keys($catalogue['departments']),
            'missing_fields' => $missing,
        ], $json);

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function cockpit(AtlasMissionControlCockpitService $cockpit, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('cockpit requires --intent');
        }
        $signals = $this->loadSignals();
        $snapshot = $cockpit->snapshot(
            intentId: $intent,
            phaseEnvelopes: [],
            gateSignals: $signals,
            autonomyLevel: (string) $this->option('autonomy'),
        );
        $this->emit($snapshot, $json);

        return self::SUCCESS;
    }

    private function universalGates(AtlasUniversalGatesEvaluator $gates, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('universal-gates requires --intent');
        }
        $signals = $this->loadSignals();
        $deliveryPackPath = (string) ($this->option('delivery-pack') ?? '');
        if ($deliveryPackPath !== '') {
            $composition = $this->loadJsonFile($deliveryPackPath);
            if ($composition === null) {
                return $this->failWith('universal-gates --delivery-pack must be a readable JSON object');
            }
            $signals['delivery_pack_completeness_min_0_95'] = $gates->deliveryPackCompletenessSignal($composition);
            $report = $gates->evaluate($intent, $signals);
            $report['observe'] = array_merge(
                AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
                ['delivery_pack_completeness' => $gates->deliveryPackCompletenessScoreObserve($composition)],
            );
        } else {
            $report = $gates->evaluate($intent, $signals);
        }

        $specPath = (string) ($this->option('spec') ?? '');
        if ($specPath !== '') {
            $spec = $this->loadJsonFile($specPath);
            if ($spec === null) {
                return $this->failWith('universal-gates --spec must be a readable JSON object');
            }
            $report['observe'] = array_merge(
                AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
                [
                    'spec_completeness' => $gates->specCompletenessSignal($spec),
                    'spec_completeness_score' => $gates->specCompletenessScoreObserve($spec),
                ],
            );
        }

        // Observe-only projectors: do not add universal-gate ids (catalogue stays 15).
        foreach ([
            ['quality-bar', 'quality_bar_telemetry', fn (array $p) => $gates->qualityBarTelemetryObserve($p)],
            ['architect-spec-pack', 'architect_spec_pack_gate', fn (array $p) => $gates->architectSpecPackObserve($p)],
            ['predicted-impact', 'predicted_impact_band', fn (array $p) => $gates->predictedImpactBandObserve($p)],
            ['predicted-impact-calibration', 'predicted_impact_calibration', fn (array $p) => $gates->predictedImpactCalibrationObserve($p)],
            ['pre-review', 'pre_review_advisory', fn (array $p) => $gates->preReviewAdvisoryObserve($p)],
            ['reality-compiler-slice', 'reality_compiler_slice', fn (array $p) => $gates->realityCompilerSliceObserve($p)],
            ['esp09-challenger', 'esp09_challenger', fn (array $p) => $gates->esp09ChallengerObserve($p)],
            ['esp09-promotion-gate', 'esp09_promotion_gate', fn (array $p) => $gates->esp09PromotionGateObserve($p)],
            ['esp09-refutation-series', 'esp09_refutation_series', fn (array $p) => $gates->esp09RefutationSeriesObserve($p)],
            ['dogfooding-leads', 'dogfooding_friction_leads', fn (array $p) => $gates->dogfoodingFrictionLeadsObserve($p)],
            ['reactive-saturation', 'reactive_saturation', fn (array $p) => $gates->reactiveSaturationObserve($p)],
            ['blocker-severity', 'blocker_severity', fn (array $p) => $gates->blockerSeverityObserve($p)],
            ['phase-advance', 'phase_advance_verdict', fn (array $p) => $gates->phaseAdvanceVerdictObserve($p)],
            ['required-gate-coverage', 'required_gate_coverage', fn (array $p) => $gates->requiredGateCoverageObserve($p)],
            ['outcome-causality', 'outcome_causality', fn (array $p) => $gates->outcomeCausalityObserve($p)],
            ['summary-fidelity', 'summary_fidelity_coverage', fn (array $p) => $gates->summaryFidelityCoverageObserve($p)],
            ['memory-injection-budget', 'memory_injection_budget', fn (array $p) => $gates->memoryInjectionBudgetObserve($p)],
            ['memory-feedback-decay', 'memory_feedback_decay', fn (array $p) => $gates->memoryFeedbackDecayObserve($p)],
            ['segment-importance', 'segment_importance', fn (array $p) => $gates->segmentImportanceObserve($p)],
            ['context-pareto', 'context_pareto_dominance', fn (array $p) => $gates->contextParetoDominanceObserve($p)],
            ['memory-recall-rank', 'memory_recall_rank', fn (array $p) => $gates->memoryRecallRankObserve($p)],
            ['portfolio-budget', 'portfolio_budget', fn (array $p) => $gates->portfolioBudgetObserve($p)],
            ['ambition-rung', 'ambition_rung', fn (array $p) => $gates->ambitionRungObserve($p)],
            ['domain-lexical', 'domain_lexical', fn (array $p) => $gates->domainLexicalObserve($p)],
            ['gated-corpus', 'gated_corpus_candidates', fn (array $p) => $gates->gatedCorpusCandidatesObserve($p)],
            ['structured-facts', 'structured_fact_schema', fn (array $p) => $gates->structuredFactSchemaObserve($p)],
            ['citation-grounding', 'citation_grounding', fn (array $p) => $gates->citationGroundingObserve($p)],
            ['provenance-weight', 'provenance_weight', fn (array $p) => $gates->provenanceWeightObserve($p)],
            ['recall-gap', 'recall_gap', fn (array $p) => $gates->recallGapObserve($p)],
            ['belief-cascade', 'belief_cascade', fn (array $p) => $gates->beliefCascadeObserve($p)],
            ['ledger-rotation', 'ledger_rotation', fn (array $p) => $gates->ledgerRotationObserve($p)],
            ['evidence-vision', 'evidence_vision', fn (array $p) => $gates->evidenceVisionObserve($p)],
            ['gate-signal-spec-pack', 'gate_signal_spec_pack', fn (array $p) => $gates->gateSignalSpecPackObserve($p)],
            ['gate-signal-intent', 'gate_signal_intent', fn (array $p) => $gates->gateSignalIntentObserve($p)],
            ['gate-signal-task-pack', 'gate_signal_task_pack', fn (array $p) => $gates->gateSignalTaskPackObserve($p)],
            ['gate-signal-phase', 'gate_signal_phase', fn (array $p) => $gates->gateSignalPhaseObserve($p)],
            ['threshold-ladder', 'threshold_ladder', fn (array $p) => $gates->thresholdLadderObserve($p)],
            ['kb-embedding-coverage', 'kb_embedding_coverage', fn (array $p) => $gates->kbEmbeddingCoverageObserve($p)],
            ['code-symbol-embedding-coverage', 'code_symbol_embedding_coverage', fn (array $p) => $gates->codeSymbolEmbeddingCoverageObserve($p)],
            ['predicted-revert-digest', 'predicted_revert_digest', fn (array $p) => $gates->predictedRevertDigestObserve($p)],
            ['jina-dual-read-ledger', 'jina_dual_read_ledger', fn (array $p) => $gates->jinaDualReadLedgerObserve($p)],
            ['resource-budget', 'resource_budget', fn (array $p) => $gates->resourceBudgetObserve($p)],
            ['model-capability-spec', 'model_capability_spec', fn (array $p) => $gates->modelCapabilitySpecObserve($p)],
            ['measure-series-freshness', 'measure_series_freshness', fn (array $p) => $gates->measureSeriesFreshnessObserve($p)],
            ['verified-share', 'verified_share', fn (array $p) => $gates->verifiedShareObserve($p)],
            ['ragx-chain', 'ragx_chain', fn (array $p) => $gates->ragxChainObserve($p)],
            ['procedural-skill-promoter', 'procedural_skill_promoter', fn (array $p) => $gates->proceduralSkillPromoterObserve($p)],
            ['aaeos-phase-router', 'aaeos_phase_router', fn (array $p) => $gates->aaeosPhaseRouterObserve($p)],
            ['aaeos-quality-bar', 'aaeos_quality_bar', fn (array $p) => $gates->aaeosQualityBarObserve($p)],
            ['aaeos-department-maturity', 'aaeos_department_maturity', fn (array $p) => $gates->aaeosDepartmentMaturityObserve($p)],
            ['veto-propagation-watchdog', 'veto_propagation_watchdog', fn (array $p) => $gates->vetoPropagationWatchdogObserve($p)],
            ['repair-loop-guard', 'repair_loop_guard', fn (array $p) => $gates->repairLoopGuardObserve($p)],
            ['generated-contract-gate', 'generated_contract_gate', fn (array $p) => $gates->generatedContractGateObserve($p)],
            ['maturity-band-classifier', 'maturity_band_classifier', fn (array $p) => $gates->maturityBandClassifierObserve($p)],
            ['promotion-eligibility', 'promotion_eligibility', fn (array $p) => $gates->promotionEligibilityObserve($p)],
            ['debug-root-cause', 'debug_root_cause', fn (array $p) => $gates->debugRootCauseObserve($p)],
            ['cross-department-choreography', 'cross_department_choreography', fn (array $p) => $gates->crossDepartmentChoreographyObserve($p)],
            ['docs-authority-locate', 'docs_authority_locate', fn (array $p) => $gates->docsAuthorityLocateObserve($p)],
            ['department-level-classifier', 'department_level_classifier', fn (array $p) => $gates->departmentLevelClassifierObserve($p)],
            ['quality-bar-level-classifier', 'quality_bar_level_classifier', fn (array $p) => $gates->qualityBarLevelClassifierObserve($p)],
            ['implementation-truth-evaluate', 'implementation_truth_evaluate', fn (array $p) => $gates->implementationTruthEvaluateObserve($p)],
            ['phase-handoff-catalogue', 'phase_handoff_catalogue', fn (array $p) => $gates->phaseHandoffCatalogueObserve($p)],
            ['golden-counterfactual-replay', 'golden_counterfactual_replay', fn (array $p) => $gates->goldenCounterfactualReplayObserve($p)],
            ['composed-obra-arc', 'composed_obra_arc', fn (array $p) => $gates->composedObraArcObserve($p)],
            ['exploratory-bets-portfolio', 'exploratory_bets_portfolio', fn (array $p) => $gates->exploratoryBetsPortfolioObserve($p)],
            ['n-capture-drill', 'n_capture_drill', fn (array $p) => $gates->nCaptureDrillObserve($p)],
            ['lote2-counterfactual-lift', 'lote2_counterfactual_lift', fn (array $p) => $gates->lote2CounterfactualLiftObserve($p)],
            ['string-list-normalize', 'string_list_normalize', fn (array $p) => $gates->stringListNormalizeObserve($p)],
            ['threshold-comparator', 'threshold_comparator', fn (array $p) => $gates->thresholdComparatorObserve($p)],
            ['evidence-ref-normalize', 'evidence_ref_normalize', fn (array $p) => $gates->evidenceRefNormalizeObserve($p)],
            ['doc-maturity-classify', 'doc_maturity_classify', fn (array $p) => $gates->docMaturityClassifyObserve($p)],
            ['claim-definition-of-done', 'claim_definition_of_done', fn (array $p) => $gates->claimDefinitionOfDoneObserve($p)],
            ['array-field-reader', 'array_field_reader', fn (array $p) => $gates->arrayFieldReaderObserve($p)],
            ['veto-propagation-resolve', 'veto_propagation_resolve', fn (array $p) => $gates->vetoPropagationResolveObserve($p)],
            ['department-registry-validate', 'department_registry_validate', fn (array $p) => $gates->departmentRegistryValidateObserve($p)],
            ['cognitive-immune-classify', 'cognitive_immune_classify', fn (array $p) => $gates->cognitiveImmuneClassifyObserve($p)],
            ['department-canonical-list', 'department_canonical_list', fn (array $p) => $gates->departmentCanonicalListObserve($p)],
            ['universal-gates-catalogue', 'universal_gates_catalogue', fn (array $p) => $gates->universalGatesCatalogueObserve($p)],
            ['outcome-attribution-types', 'outcome_attribution_types', fn (array $p) => $gates->outcomeAttributionTypesObserve($p)],
            ['phase-router-valid-phases', 'phase_router_valid_phases', fn (array $p) => $gates->phaseRouterValidPhasesObserve($p)],
            ['choreography-handoff-kinds', 'choreography_handoff_kinds', fn (array $p) => $gates->choreographyHandoffKindsObserve($p)],
            ['reality-compiler-phases', 'reality_compiler_phases', fn (array $p) => $gates->realityCompilerPhasesObserve($p)],
            ['scope-risk-classes', 'scope_risk_classes', fn (array $p) => $gates->scopeRiskClassesObserve($p)],
            ['organ-mesh-phases', 'organ_mesh_phases', fn (array $p) => $gates->organMeshPhasesObserve($p)],
            ['telemetry-surfaces', 'telemetry_surfaces', fn (array $p) => $gates->telemetrySurfacesObserve($p)],
            ['phase-signature-l4', 'phase_signature_l4', fn (array $p) => $gates->phaseSignatureL4Observe($p)],
            ['blocker-severity-levels', 'blocker_severity_levels', fn (array $p) => $gates->blockerSeverityLevelsObserve($p)],
            ['scope-high-risks', 'scope_high_risks', fn (array $p) => $gates->scopeHighRisksObserve($p)],
            ['architect-spec-catalogue', 'architect_spec_catalogue', fn (array $p) => $gates->architectSpecCatalogueObserve($p)],
            ['surprise-gate-bands', 'surprise_gate_bands', fn (array $p) => $gates->surpriseGateBandsObserve($p)],
            ['immune-calibration-contract', 'immune_calibration_contract', fn (array $p) => $gates->immuneCalibrationContractObserve($p)],
            ['cognitive-immune-check-contract', 'cognitive_immune_check_contract', fn (array $p) => $gates->cognitiveImmuneCheckContractObserve($p)],
            ['cognition-evidence-statuses', 'cognition_evidence_statuses', fn (array $p) => $gates->cognitionEvidenceStatusesObserve($p)],
            ['capture-hmac-lineage', 'capture_hmac_lineage', fn (array $p) => $gates->captureHmacLineageObserve($p)],
            ['cognitive-function-axes', 'cognitive_function_axes', fn (array $p) => $gates->cognitiveFunctionAxesObserve($p)],
            ['gate-signal-contract', 'gate_signal_contract', fn (array $p) => $gates->gateSignalContractObserve($p)],
            ['rollback-trigger-contract', 'rollback_trigger_contract', fn (array $p) => $gates->rollbackTriggerContractObserve($p)],
            ['long-horizon-gate-contract', 'long_horizon_gate_contract', fn (array $p) => $gates->longHorizonGateContractObserve($p)],
            ['immune-signature-store-contract', 'immune_signature_store_contract', fn (array $p) => $gates->immuneSignatureStoreContractObserve($p)],
            ['promotion-protocol-states', 'promotion_protocol_states', fn (array $p) => $gates->promotionProtocolStatesObserve($p)],
            ['autonomous-work-cycle-stages', 'autonomous_work_cycle_stages', fn (array $p) => $gates->autonomousWorkCycleStagesObserve($p)],
            ['immune-verdict-ledger-labels', 'immune_verdict_ledger_labels', fn (array $p) => $gates->immuneVerdictLedgerLabelsObserve($p)],
            ['flywheel-funnel-stages', 'flywheel_funnel_stages', fn (array $p) => $gates->flywheelFunnelStagesObserve($p)],
            ['mission-control-cockpit-schema', 'mission_control_cockpit_schema', fn (array $p) => $gates->missionControlCockpitSchemaObserve($p)],
            ['evidence-vision-thesis-lifecycle', 'evidence_vision_thesis_lifecycle', fn (array $p) => $gates->evidenceVisionThesisLifecycleObserve($p)],
            ['exploratory-bets-portfolio-contract', 'exploratory_bets_portfolio_contract', fn (array $p) => $gates->exploratoryBetsPortfolioContractObserve($p)],
            ['composed-obra-arc-contract', 'composed_obra_arc_contract', fn (array $p) => $gates->composedObraArcContractObserve($p)],
            ['memory-feedback-decay-contract', 'memory_feedback_decay_contract', fn (array $p) => $gates->memoryFeedbackDecayContractObserve($p)],
            ['spec-completeness-contract', 'spec_completeness_contract', fn (array $p) => $gates->specCompletenessContractObserve($p)],
            ['context-retention-schemas', 'context_retention_schemas', fn (array $p) => $gates->contextRetentionSchemasObserve($p)],
            ['context-budget-schemas', 'context_budget_schemas', fn (array $p) => $gates->contextBudgetSchemasObserve($p)],
            ['outcome-envelope-contract', 'outcome_envelope_contract', fn (array $p) => $gates->outcomeEnvelopeContractObserve($p)],
            ['pre-review-advisory-contract', 'pre_review_advisory_contract', fn (array $p) => $gates->preReviewAdvisoryContractObserve($p)],
            ['ambition-rung-policy-contract', 'ambition_rung_policy_contract', fn (array $p) => $gates->ambitionRungPolicyContractObserve($p)],
            ['reactive-saturation-contract', 'reactive_saturation_contract', fn (array $p) => $gates->reactiveSaturationContractObserve($p)],
            ['portfolio-budget-contract', 'portfolio_budget_contract', fn (array $p) => $gates->portfolioBudgetContractObserve($p)],
            ['predicted-impact-band-contract', 'predicted_impact_band_contract', fn (array $p) => $gates->predictedImpactBandContractObserve($p)],
            ['gated-corpus-contract', 'gated_corpus_contract', fn (array $p) => $gates->gatedCorpusContractObserve($p)],
            ['claim-definition-of-done-contract', 'claim_definition_of_done_contract', fn (array $p) => $gates->claimDefinitionOfDoneContractObserve($p)],
            ['quality-bar-telemetry-contract', 'quality_bar_telemetry_contract', fn (array $p) => $gates->qualityBarTelemetryContractObserve($p)],
            ['doc-maturity-contract', 'doc_maturity_contract', fn (array $p) => $gates->docMaturityContractObserve($p)],
            ['attempt-lifecycle-contract', 'attempt_lifecycle_contract', fn (array $p) => $gates->attemptLifecycleContractObserve($p)],
            ['esp09-challenger-contract', 'esp09_challenger_contract', fn (array $p) => $gates->esp09ChallengerContractObserve($p)],
            ['memory-weight-floors-contract', 'memory_weight_floors_contract', fn (array $p) => $gates->memoryWeightFloorsContractObserve($p)],
            ['delivery-pack-contract', 'delivery_pack_contract', fn (array $p) => $gates->deliveryPackContractObserve($p)],
            ['domain-lexical-fact-schema-contract', 'domain_lexical_fact_schema_contract', fn (array $p) => $gates->domainLexicalFactSchemaContractObserve($p)],
            ['phase-advance-blocker-contract', 'phase_advance_blocker_contract', fn (array $p) => $gates->phaseAdvanceBlockerContractObserve($p)],
            ['outcome-causality-comparator-contract', 'outcome_causality_comparator_contract', fn (array $p) => $gates->outcomeCausalityComparatorContractObserve($p)],
            ['segment-importance-contract', 'segment_importance_contract', fn (array $p) => $gates->segmentImportanceContractObserve($p)],
            ['cognitive-immune-promotion-gate-contract', 'cognitive_immune_promotion_gate_contract', fn (array $p) => $gates->cognitiveImmunePromotionGateContractObserve($p)],
            ['cognitive-immune-input-classifier-contract', 'cognitive_immune_input_classifier_contract', fn (array $p) => $gates->cognitiveImmuneInputClassifierContractObserve($p)],
            ['window-evolution-hybrid-contract', 'window_evolution_hybrid_contract', fn (array $p) => $gates->windowEvolutionHybridContractObserve($p)],
            ['implementation-authority-contract', 'implementation_authority_contract', fn (array $p) => $gates->implementationAuthorityContractObserve($p)],
            ['evidence-volume-deferred-contract', 'evidence_volume_deferred_contract', fn (array $p) => $gates->evidenceVolumeDeferredContractObserve($p)],
            ['watchdog-health-floors-contract', 'watchdog_health_floors_contract', fn (array $p) => $gates->watchdogHealthFloorsContractObserve($p)],
            ['evidence-vision-composer-contract', 'evidence_vision_composer_contract', fn (array $p) => $gates->evidenceVisionComposerContractObserve($p)],
            ['measure-series-maxa04-contract', 'measure_series_maxa04_contract', fn (array $p) => $gates->measureSeriesMaxa04ContractObserve($p)],
            ['ragx-choreography-budget-contract', 'ragx_choreography_budget_contract', fn (array $p) => $gates->ragxChoreographyBudgetContractObserve($p)],
            ['verified-share-scorecard-contract', 'verified_share_scorecard_contract', fn (array $p) => $gates->verifiedShareScorecardContractObserve($p)],
            ['aaeos-evidence-maturity-contract', 'aaeos_evidence_maturity_contract', fn (array $p) => $gates->aaeosEvidenceMaturityContractObserve($p)],
            ['lote2-quality-bar-contract', 'lote2_quality_bar_contract', fn (array $p) => $gates->lote2QualityBarContractObserve($p)],
            ['embedding-coverage-truth-contract', 'embedding_coverage_truth_contract', fn (array $p) => $gates->embeddingCoverageTruthContractObserve($p)],
            ['phase-gates-flywheel-contract', 'phase_gates_flywheel_contract', fn (array $p) => $gates->phaseGatesFlywheelContractObserve($p)],
            ['frontier-watchdog-cockpit-contract', 'frontier_watchdog_cockpit_contract', fn (array $p) => $gates->frontierWatchdogCockpitContractObserve($p)],
            ['runbook-department-atlas-contract', 'runbook_department_atlas_contract', fn (array $p) => $gates->runbookDepartmentAtlasContractObserve($p)],
            ['outcome-causality-weights-contract', 'outcome_causality_weights_contract', fn (array $p) => $gates->outcomeCausalityWeightsContractObserve($p)],
            ['watchdog-canary-floors-contract', 'watchdog_canary_floors_contract', fn (array $p) => $gates->watchdogCanaryFloorsContractObserve($p)],
            ['http-path-facade-contract', 'http_path_facade_contract', fn (array $p) => $gates->httpPathFacadeContractObserve($p)],
            ['phase-doc-promotion-ids-contract', 'phase_doc_promotion_ids_contract', fn (array $p) => $gates->phaseDocPromotionIdsContractObserve($p)],
            ['outcome-immune-scorecard-ids-contract', 'outcome_immune_scorecard_ids_contract', fn (array $p) => $gates->outcomeImmuneScorecardIdsContractObserve($p)],
            ['gate-evolution-skill-freeze-contract', 'gate_evolution_skill_freeze_contract', fn (array $p) => $gates->gateEvolutionSkillFreezeContractObserve($p)],
            ['residual-schema-ledger-contract', 'residual_schema_ledger_contract', fn (array $p) => $gates->residualSchemaLedgerContractObserve($p)],
            ['unwired-watchdog-checks-contract', 'unwired_watchdog_checks_contract', fn (array $p) => $gates->unwiredWatchdogChecksContractObserve($p)],
            ['watchdog-runner-autonomy-ladder-contract', 'watchdog_runner_autonomy_ladder_contract', fn (array $p) => $gates->watchdogRunnerAutonomyLadderContractObserve($p)],
            ['outcome-envelope-adapters-contract', 'outcome_envelope_adapters_contract', fn (array $p) => $gates->outcomeEnvelopeAdaptersContractObserve($p)],
            ['implementation-truth-rank-contract', 'implementation_truth_rank_contract', fn (array $p) => $gates->implementationTruthRankContractObserve($p)],
            ['secondary-report-schemas-contract', 'secondary_report_schemas_contract', fn (array $p) => $gates->secondaryReportSchemasContractObserve($p)],
            ['department-io-schemas-contract', 'department_io_schemas_contract', fn (array $p) => $gates->departmentIoSchemasContractObserve($p)],
            ['http-path-watchdog-observe-schemas-contract', 'http_path_watchdog_observe_schemas_contract', fn (array $p) => $gates->httpPathWatchdogObserveSchemasContractObserve($p)],
            ['evaluator-observe-helpers-contract', 'evaluator_observe_helpers_contract', fn (array $p) => $gates->evaluatorObserveHelpersContractObserve($p)],
            ['gate-report-schema-contract', 'gate_report_schema_contract', fn (array $p) => $gates->gateReportSchemaContractObserve($p)],
            ['docs-authority-confidence-keys-contract', 'docs_authority_confidence_keys_contract', fn (array $p) => $gates->docsAuthorityConfidenceKeysContractObserve($p)],
            ['maxa04-promotion-floors-contract', 'maxa04_promotion_floors_contract', fn (array $p) => $gates->maxa04PromotionFloorsContractObserve($p)],
            ['composed-obra-lifecycle-floors-contract', 'composed_obra_lifecycle_floors_contract', fn (array $p) => $gates->composedObraLifecycleFloorsContractObserve($p)],
            ['resource-budget-host-floors-contract', 'resource_budget_host_floors_contract', fn (array $p) => $gates->resourceBudgetHostFloorsContractObserve($p)],
            ['verified-share-procedural-floors-contract', 'verified_share_procedural_floors_contract', fn (array $p) => $gates->verifiedShareProceduralFloorsContractObserve($p)],
            ['long-horizon-gate-floors-contract', 'long_horizon_gate_floors_contract', fn (array $p) => $gates->longHorizonGateFloorsContractObserve($p)],
            ['ledger-rotation-impact-floors-contract', 'ledger_rotation_impact_floors_contract', fn (array $p) => $gates->ledgerRotationImpactFloorsContractObserve($p)],
            ['observe-helper-limit-floors-contract', 'observe_helper_limit_floors_contract', fn (array $p) => $gates->observeHelperLimitFloorsContractObserve($p)],
            ['outcome-envelope-bool-fields-contract', 'outcome_envelope_bool_fields_contract', fn (array $p) => $gates->outcomeEnvelopeBoolFieldsContractObserve($p)],
            ['quality-bar-cognitive-floors-contract', 'quality_bar_cognitive_floors_contract', fn (array $p) => $gates->qualityBarCognitiveFloorsContractObserve($p)],
            ['parallel-substrate-bridge-floors-contract', 'parallel_substrate_bridge_floors_contract', fn (array $p) => $gates->parallelSubstrateBridgeFloorsContractObserve($p)],
            ['ops-config-toggle-floors-contract', 'ops_config_toggle_floors_contract', fn (array $p) => $gates->opsConfigToggleFloorsContractObserve($p)],
            ['ragx-immune-substrate-config-floors-contract', 'ragx_immune_substrate_config_floors_contract', fn (array $p) => $gates->ragxImmuneSubstrateConfigFloorsContractObserve($p)],
            ['residual-ops-config-floors-contract', 'residual_ops_config_floors_contract', fn (array $p) => $gates->residualOpsConfigFloorsContractObserve($p)],
            ['ragx-stage-mechanism-floors-contract', 'ragx_stage_mechanism_floors_contract', fn (array $p) => $gates->ragxStageMechanismFloorsContractObserve($p)],
            ['department-extended-io-procedural-floors-contract', 'department_extended_io_procedural_floors_contract', fn (array $p) => $gates->departmentExtendedIoProceduralFloorsContractObserve($p)],
            ['runtime-status-mode-floors-contract', 'runtime_status_mode_floors_contract', fn (array $p) => $gates->runtimeStatusModeFloorsContractObserve($p)],
            ['outcome-maxa04-lote2-status-floors-contract', 'outcome_maxa04_lote2_status_floors_contract', fn (array $p) => $gates->outcomeMaxa04Lote2StatusFloorsContractObserve($p)],
            ['lote2-reason-ambition-portfolio-floors-contract', 'lote2_reason_ambition_portfolio_floors_contract', fn (array $p) => $gates->lote2ReasonAmbitionPortfolioFloorsContractObserve($p)],
            ['esp09-bets-obra-status-floors-contract', 'esp09_bets_obra_status_floors_contract', fn (array $p) => $gates->esp09BetsObraStatusFloorsContractObserve($p)],
            ['ncapture-promotion-lifecycle-status-floors-contract', 'ncapture_promotion_lifecycle_status_floors_contract', fn (array $p) => $gates->ncapturePromotionLifecycleStatusFloorsContractObserve($p)],
            ['asef-remint-immune-ragx-status-floors-contract', 'asef_remint_immune_ragx_status_floors_contract', fn (array $p) => $gates->asefRemintImmuneRagxStatusFloorsContractObserve($p)],
            ['decay-veto-numeric-choreography-floors-contract', 'decay_veto_numeric_choreography_floors_contract', fn (array $p) => $gates->decayVetoNumericChoreographyFloorsContractObserve($p)],
            ['evidence-temporal-hmac-calibration-floors-contract', 'evidence_temporal_hmac_calibration_floors_contract', fn (array $p) => $gates->evidenceTemporalHmacCalibrationFloorsContractObserve($p)],
            ['verified-share-capability-truth-ambition-floors-contract', 'verified_share_capability_truth_ambition_floors_contract', fn (array $p) => $gates->verifiedShareCapabilityTruthAmbitionFloorsContractObserve($p)],
            ['canary-integrity-window-rotation-floors-contract', 'canary_integrity_window_rotation_floors_contract', fn (array $p) => $gates->canaryIntegrityWindowRotationFloorsContractObserve($p)],
            ['golden-pareto-scorer-maxa04-floors-contract', 'golden_pareto_scorer_maxa04_floors_contract', fn (array $p) => $gates->goldenParetoScorerMaxa04FloorsContractObserve($p)],
            ['parallel-procedural-watchdog-residual-floors-contract', 'parallel_procedural_watchdog_residual_floors_contract', fn (array $p) => $gates->parallelProceduralWatchdogResidualFloorsContractObserve($p)],
            ['lote2-decomposer-redaction-unobserved-floors-contract', 'lote2_decomposer_redaction_unobserved_floors_contract', fn (array $p) => $gates->lote2DecomposerRedactionUnobservedFloorsContractObserve($p)],
            ['unobserved-status-basis-handoff-floors-contract', 'unobserved_status_basis_handoff_floors_contract', fn (array $p) => $gates->unobservedStatusBasisHandoffFloorsContractObserve($p)],
            ['choreography-repair-review-measure-freeze-floors-contract', 'choreography_repair_review_measure_freeze_floors_contract', fn (array $p) => $gates->choreographyRepairReviewMeasureFreezeFloorsContractObserve($p)],
            ['residual-error-basis-status-floors-contract', 'residual_error_basis_status_floors_contract', fn (array $p) => $gates->residualErrorBasisStatusFloorsContractObserve($p)],
            ['signature-mode-suspended-unknown-floors-contract', 'signature_mode_suspended_unknown_floors_contract', fn (array $p) => $gates->signatureModeSuspendedUnknownFloorsContractObserve($p)],
            ['architect-verdict-freeze-ready-floors-contract', 'architect_verdict_freeze_ready_floors_contract', fn (array $p) => $gates->architectVerdictFreezeReadyFloorsContractObserve($p)],
            ['mission-control-pending-partial-floors-contract', 'mission_control_pending_partial_floors_contract', fn (array $p) => $gates->missionControlPendingPartialFloorsContractObserve($p)],
        ] as [$option, $observeKey, $projector]) {
            $failed = $this->appendOptionalJsonObserve($report, $option, $observeKey, $projector);
            if ($failed !== null) {
                return $failed;
            }
        }

        $this->emit($report, $json);

        return $report['outcome'] === 'green' || $report['outcome'] === 'exception' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  callable(array<string,mixed>):mixed  $projector
     */
    private function appendOptionalJsonObserve(array &$report, string $option, string $observeKey, callable $projector): ?int
    {
        $path = (string) ($this->option($option) ?? '');
        if ($path === '') {
            return null;
        }
        $payload = $this->loadJsonFile($path);
        if ($payload === null) {
            return $this->failWith('universal-gates --'.$option.' must be a readable JSON object');
        }
        $report['observe'] = array_merge(
            AiValueNormalizer::arrayOrEmpty($report['observe'] ?? null),
            [$observeKey => $projector($payload)],
        );

        return null;
    }

    /** @return array<string,bool|string|null> */
    private function loadSignals(): array
    {
        $path = (string) ($this->option('signals') ?? '');
        if ($path === '') {
            return [];
        }
        $decoded = $this->loadJsonFile($path);

        return AiValueNormalizer::arrayOrEmpty($decoded);
    }

    /** @return array<string,mixed>|null */
    private function loadJsonFile(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function emit(mixed $payload, bool $json): void
    {
        // --json is currently the same pretty provider-safe encoding; flag kept
        // for callers/docs that already pass it.
        unset($json);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function failWith(string $reason): int
    {
        $this->error($reason);

        return self::FAILURE;
    }
}
