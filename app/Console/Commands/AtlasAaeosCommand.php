<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\Gates\AaeosUniversalGatesJsonObserveSupport;
use App\Services\Ai\AgenticEngineeringOs\Gates\AaeosUniversalGatesObserveProjectors;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Agentic Engineering OS — operator entry point.
 *
 * Implements the canonical AAEOS CLI suite mandated by the runbook doc:
 *
 *   atlas:aaeos:runbook            — show the 17-phase canonical runbook
 *   atlas:aaeos:phase-handoff      — emit and validate an atlas.aaeos.phase.v1 envelope
 *   atlas:aaeos:phase-skip         — record a justified phase skip with receipt
 *   atlas:aeos:department-status  — show department catalogue + canon-field coverage
 *   atlas:aaeos:cockpit            — render mission-control cockpit snapshot
 *   atlas:aaeos:universal-gates    — evaluate the 15 universal gates from a signals JSON file
 *
 * All sub-commands accept `--json` for machine output. Operator input is
 * limited to identifiers and hashes; raw payloads are rejected by the
 * underlying services (provider-safe by default).
 */
final class AtlasAaeosCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aeos:observe
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
        {--volume-autonomy-coverage-unknown-floors-contract= : JSON file (any object) to observe volume/autonomy/coverage/unknown floors}
        {--watchdog-health-active-disabled-floors-contract= : JSON file (any object) to observe watchdog-health/long-horizon/immune-gate/truth/deferred floors}
        {--embedding-pending-mission-outcome-floors-contract= : JSON file (any object) to observe embedding active/pending + mission outcome floors}
        {--local-model-embedding-immune-unavailable-floors-contract= : JSON file (any object) to observe local-model/embedding/immune unavailable floors}
        {--prereview-parallel-flywheel-frontier-floors-contract= : JSON file (any object) to observe prereview/parallel/flywheel/frontier residual floors}
        {--obra-portfolio-pareto-blocked-floors-contract= : JSON file (any object) to observe obra/portfolio/pareto/http blocked residual floors}
        {--corpus-parallel-truth-blocked-floors-contract= : JSON file (any object) to observe corpus/parallel/truth/phase blocked residual floors}
        {--teto10-cockpit-ladder-promotion-floors-contract= : JSON file (any object) to observe teto10/cockpit/ladder/promotion residual floors}
        {--dead-series-miner-signature-adapter-floors-contract= : JSON file (any object) to observe dead-series/miner/signature/adapter residual floors}
        {--ragx-prereview-lote2-schema-floors-contract= : JSON file (any object) to observe ragx/prereview/lote2/schema residual floors}
        {--window-gates-integrity-flag-disabled-floors-contract= : JSON file (any object) to observe window-gates/integrity/flag-disabled residual floors}
        {--obra-verified-long-horizon-enabled-floors-contract= : JSON file (any object) to observe obra/verified/long-horizon/enabled residual floors}
        {--embedding-table-fixture-measured-floors-contract= : JSON file (any object) to observe embedding/table/fixture/measured residual floors}
        {--conflict-frontier-fixture-pending-floors-contract= : JSON file (any object) to observe conflict/frontier/fixture/pending residual floors}
        {--queued-passed-advisory-absent-floors-contract= : JSON file (any object) to observe queued/passed/advisory/absent residual floors}
        {--ran-accepted-keep-fixture-floors-contract= : JSON file (any object) to observe ran/accepted/keep/fixture residual floors}
        {--immune-class-chunks-hmac-floors-contract= : JSON file (any object) to observe immune-class/chunks/hmac residual floors}
        {--rotation-measure-series-floors-contract= : JSON file (any object) to observe rotation/measure-series residual floors}
        {--promotion-lote2-measure-floors-contract= : JSON file (any object) to observe promotion/LOTE2 measure residual floors}
        {--department-contract-maturity-floors-contract= : JSON file (any object) to observe department-contract/maturity residual floors}
        {--quality-veto-evolution-floors-contract= : JSON file (any object) to observe quality/veto/evolution residual floors}
        {--watchdog-immune-ragx-floors-contract= : JSON file (any object) to observe watchdog/immune/RAGX residual floors}
        {--obra-evidence-http-floors-contract= : JSON file (any object) to observe obra/evidence/HTTP residual floors}
        {--teto-cognitive-hmac-floors-contract= : JSON file (any object) to observe teto/cognitive/HMAC residual floors}
        {--promotion-asef-autonomy-floors-contract= : JSON file (any object) to observe promotion/ASEF/autonomy residual floors}
        {--longhorizon-window-aemor-floors-contract= : JSON file (any object) to observe long-horizon/window/AEMOR residual floors}
        {--test-immune-truth-floors-contract= : JSON file (any object) to observe test-execution/immune/truth residual floors}
        {--model-causality-skill-floors-contract= : JSON file (any object) to observe model-spec/causality/skill residual floors}
        {--choreography-hybrid-dev-floors-contract= : JSON file (any object) to observe choreography/hybrid/dev-procedural residual floors}
        {--compounding-scorecard-canary-floors-contract= : JSON file (any object) to observe compounding/scorecard/canary residual floors}
        {--obra-lote2-health-floors-contract= : JSON file (any object) to observe obra/lote2/health residual floors}
        {--volume-cockpit-rollback-floors-contract= : JSON file (any object) to observe volume/cockpit/rollback residual floors}
        {--arc-segment-window-floors-contract= : JSON file (any object) to observe arc/segment/window residual floors}
        {--department-integrity-capture-floors-contract= : JSON file (any object) to observe department/integrity/capture residual floors}
        {--asef-calibration-jina-floors-contract= : JSON file (any object) to observe ASEF/immune-calibration/jina residual floors}
        {--ledger-counterfactual-advisory-floors-contract= : JSON file (any object) to observe ledger/counterfactual/advisory residual floors}
        {--verified-frontier-cooccurrence-floors-contract= : JSON file (any object) to observe verified-share/frontier/cooccurrence residual floors}
        {--docs-handoff-adversarial-floors-contract= : JSON file (any object) to observe docs/handoff/adversarial residual floors}
        {--immune-ragx-scorecard-floors-contract= : JSON file (any object) to observe immune/RAGX/scorecard residual floors}
        {--http-thesis-lote2-floors-contract= : JSON file (any object) to observe HTTP-path/thesis/LOTE2 residual floors}
        {--longhorizon-watchdog-promotion-floors-contract= : JSON file (any object) to observe long-horizon/watchdog/promotion residual floors}
        {--esp09-lote2-hmac-floors-contract= : JSON file (any object) to observe ESP-09/LOTE2/HMAC residual floors}
        {--phase-obra-bets-floors-contract= : JSON file (any object) to observe phase-handoff/obra-arc/bets residual floors}
        {--parallel-truth-autonomy-floors-contract= : JSON file (any object) to observe parallel/truth/autonomy residual floors}
        {--obra-thesis-skill-floors-contract= : JSON file (any object) to observe obra-retro/thesis/skill residual floors}
        {--mission-promotion-outcome-floors-contract= : JSON file (any object) to observe mission/promotion/outcome residual floors}
        {--immune-rollback-remint-floors-contract= : JSON file (any object) to observe immune/rollback/remint residual floors}
        {--scorecard-gate-test-floors-contract= : JSON file (any object) to observe scorecard/gate-signal/test residual floors}
        {--ncapture-immune-coverage-floors-contract= : JSON file (any object) to observe n-capture/immune-ledger/coverage residual floors}
        {--cockpit-canary-adversarial-floors-contract= : JSON file (any object) to observe cockpit/canary/adversarial residual floors}
        {--maturity-envelope-lifecycle-floors-contract= : JSON file (any object) to observe maturity/envelope/lifecycle residual floors}
        {--embedding-coverage-thesis-floors-contract= : JSON file (any object) to observe embedding-coverage/thesis residual floors}
        {--dept-level-evidence-floors-contract= : JSON file (any object) to observe department-level/evidence residual floors}
        {--schema-decomposer-surprise-floors-contract= : JSON file (any object) to observe schema/decomposer/surprise residual floors}
        {--evidence-flywheel-budget-floors-contract= : JSON file (any object) to observe evidence/flywheel/budget residual floors}
        {--portfolio-impact-corpus-floors-contract= : JSON file (any object) to observe portfolio/impact/corpus residual floors}
        {--disk-deadseries-latency-floors-contract= : JSON file (any object) to observe disk/dead-series/latency residual floors}
        {--memory-spec-dogfood-floors-contract= : JSON file (any object) to observe memory/spec/dogfood residual floors}
        {--restore-redaction-recall-floors-contract= : JSON file (any object) to observe restore/redaction/recall residual floors}
        {--runner-phase-saturation-floors-contract= : JSON file (any object) to observe watchdog-runner/phase-router/saturation residual floors}
        {--immune-scorecard-segment-floors-contract= : JSON file (any object) to observe immune/scorecard/segment residual floors}
        {--advisory-teto-jina-floors-contract= : JSON file (any object) to observe advisory/teto/jina residual floors}
        {--window-canary-flywheel-floors-contract= : JSON file (any object) to observe window/canary/flywheel residual floors}
        {--verified-coverage-choreography-floors-contract= : JSON file (any object) to observe verified/coverage/choreography residual floors}
        {--knowledge-decomposer-promoter-floors-contract= : JSON file (any object) to observe knowledge/decomposer/promoter residual floors}
        {--ncapture-obra-truth-floors-contract= : JSON file (any object) to observe ncapture/obra/truth residual floors}
        {--horizon-calibration-atlas-floors-contract= : JSON file (any object) to observe horizon/calibration/atlas residual floors}
        {--decay-portfolio-spec-floors-contract= : JSON file (any object) to observe decay/portfolio/spec residual floors}
        {--composed-promotion-ragx-floors-contract= : JSON file (any object) to observe composed/promotion/ragx residual floors}
        {--http-cockpit-facade-floors-contract= : JSON file (any object) to observe http/cockpit/facade residual floors}
        {--ncapture-promoter-jina-floors-contract= : JSON file (any object) to observe ncapture/promoter/jina residual floors}
        {--truth-obra-thesis-floors-contract= : JSON file (any object) to observe truth/obra/thesis residual floors}
        {--atlas-bets-verified-floors-contract= : JSON file (any object) to observe atlas/bets/verified residual floors}
        {--freeze-scorecard-eligibility-floors-contract= : JSON file (any object) to observe freeze/scorecard/eligibility residual floors}
        {--drill-skill-dualread-floors-contract= : JSON file (any object) to observe drill/skill/dualread residual floors}
        {--envelope-cockpit-facade-floors-contract= : JSON file (any object) to observe envelope/cockpit/facade residual floors}
        {--atlas-composed-ragx-floors-contract= : JSON file (any object) to observe atlas/composed/ragx residual floors}
        {--schema-aemor-lifecycle-floors-contract= : JSON file (any object) to observe schema/aemor/lifecycle residual floors}
        {--dept-quality-evidence-floors-contract= : JSON file (any object) to observe dept/quality/evidence residual floors}
        {--truth-immune-veto-floors-contract= : JSON file (any object) to observe truth/immune/veto residual floors}
        {--dead-series-outcome-compounding-floors-contract= : JSON file (any object) to observe dead-series/outcome/compounding residual floors}
        {--immune-integrity-obra-floors-contract= : JSON file (any object) to observe immune/integrity/obra residual floors}
        {--teto-atlas-longhorizon-floors-contract= : JSON file (any object) to observe teto/atlas/longhorizon residual floors}
        {--watchdog-impact-budget-floors-contract= : JSON file (any object) to observe watchdog/impact/budget residual floors}
        {--longhorizon-lote2-adversarial-floors-contract= : JSON file (any object) to observe longhorizon/lote2/adversarial residual floors}
        {--immune-window-evidence-floors-contract= : JSON file (any object) to observe immune/window/evidence residual floors}
        {--health-canary-maxa04-floors-contract= : JSON file (any object) to observe health/canary/maxa04 residual floors}
        {--joint-lote2-horizon-floors-contract= : JSON file (any object) to observe joint/lote2/horizon residual floors}
        {--threshold-http-immune-floors-contract= : JSON file (any object) to observe threshold/http/immune residual floors}
        {--ncapture-asef-spec-floors-contract= : JSON file (any object) to observe ncapture/asef/spec residual floors}
        {--execution-quality-immune-floors-contract= : JSON file (any object) to observe execution/quality/immune residual floors}
        {--health-lote2-horizon-residual-floors-contract= : JSON file (any object) to observe health/lote2/horizon residual floors}
        {--health-lote2-horizon-depth-floors-contract= : JSON file (any object) to observe health/lote2/horizon depth residual floors}
        {--runbook-immune-promoter-floors-contract= : JSON file (any object) to observe runbook/immune/promoter residual floors}
        {--lexical-envelope-cockpit-floors-contract= : JSON file (any object) to observe lexical/envelope/cockpit residual floors}
        {--ncapture-scorecard-esp09-floors-contract= : JSON file (any object) to observe ncapture/scorecard/esp09 residual floors}
        {--flywheel-immune-obra-floors-contract= : JSON file (any object) to observe flywheel/immune/obra residual floors}
        {--health-lote2-runbook-floors-contract= : JSON file (any object) to observe health/lote2/runbook residual floors}
        {--health-lote2-horizon-more-floors-contract= : JSON file (any object) to observe health/lote2/horizon more residual floors}
        {--health-deferred-runner-runbook-golden-floors-contract= : JSON file (any object) to observe health/deferred/runner/runbook/golden residual floors}
        {--lexical-rerank-maturity-budget-volume-immune-floors-contract= : JSON file (any object) to observe lexical/rerank/maturity/budget/volume/immune residual floors}
        {--decomposer-evidence-teto-fact-ragx-golden-floors-contract= : JSON file (any object) to observe decomposer/evidence/teto/fact/ragx/golden residual floors}
        {--envelope-integrity-promoter-series-lote2-lexical-substrate-bets-floors-contract= : JSON file (any object) to observe envelope/integrity/promoter/series/lote2/lexical/substrate/bets residual floors}
        {--pareto-window-cockpit-obra-ambition-dead-scorecard-vision-esp09-floors-contract= : JSON file (any object) to observe pareto/window/cockpit/obra/ambition/dead/scorecard/vision/esp09 residual floors}
        {--evolution-reality-freshness-nudge-immune-share-flywheel-aemor-quality-floors-contract= : JSON file (any object) to observe evolution/reality/freshness/nudge/immune/share/flywheel/aemor/quality residual floors}
        {--promotion-parallel-docs-reality-evidence-repair-architect-delivery-scorecard-floors-contract= : JSON file (any object) to observe promotion/parallel/docs/reality/evidence/repair/architect/delivery/scorecard residual floors}
        {--integrity-architect-veto-bets-promotion-freeze-compounding-resolver-budget-floors-contract= : JSON file (any object) to observe integrity/architect/veto/bets/promotion/freeze/compounding/resolver/budget residual floors}
        {--architect-rollback-ledger-work-substrate-decay-dod-capability-maturity-floors-contract= : JSON file (any object) to observe architect/rollback/ledger/work/substrate/decay/dod/capability/maturity residual floors}
        {--delivery-immune-registry-operator-lote2-health-horizon-promoter-floors-contract= : JSON file (any object) to observe delivery/immune/registry/operator/lote2/health/horizon/promoter residual floors}
        {--lote2-health-horizon-promoter-capture-obra-dual-truth-vision-floors-contract= : JSON file (any object) to observe lote2/health/horizon/promoter/capture/obra/dual/truth/vision residual floors}
        {--registry-spec-summary-memory-segment-pareto-recall-outcome-floors-contract= : JSON file (any object) to observe registry/spec/summary/memory/segment/pareto/recall/outcome residual floors}
        {--impact-advisory-esp09-dogfood-saturation-budget-ambition-asef-lexical-floors-contract= : JSON file (any object) to observe impact/advisory/esp09/dogfood/saturation/budget/ambition/asef/lexical residual floors}
        {--spec-summary-budget-decay-segment-pareto-recall-outcome-corpus-floors-contract= : JSON file (any object) to observe spec/summary/budget/decay/segment/pareto/recall/outcome/corpus residual floors}
        {--fact-citation-provenance-recall-cascade-vision-cooccur-gate-dispatch-floors-contract= : JSON file (any object) to observe fact/citation/provenance/recall/cascade/vision/cooccur/gate/dispatch residual floors}
        {--summary-budget-decay-segment-pareto-recall-outcome-impact-advisory-floors-contract= : JSON file (any object) to observe summary/budget/decay/segment/pareto/recall/outcome/impact/advisory residual floors}
        {--esp09-dogfood-saturation-budget-ambition-lexical-corpus-fact-citation-floors-contract= : JSON file (any object) to observe esp09/dogfood/saturation/budget/ambition/lexical/corpus/fact/citation residual floors}
        {--teto-ragx-promotion-envelope-golden-bets-thesis-attempt-cockpit-floors-contract= : JSON file (any object) to observe teto/ragx/promotion/envelope/golden/bets/thesis/attempt/cockpit residual floors}
        {--veto-repair-phase-truth-ledger-canary-latency-dual-budget-floors-contract= : JSON file (any object) to observe veto/repair/phase/truth/ledger/canary/latency/dual/budget residual floors}
        {--joint-autonomy-dead-runner-envelope-obra-docs-floors-contract= : JSON file (any object) to observe joint/autonomy/dead/runner/envelope/obra/docs residual floors}
        {--health-ingest-derive-calib-dispatch-prov-cooccur-vision-cascade-floors-contract= : JSON file (any object) to observe health/ingest/derive/calib/dispatch/prov/cooccur/vision/cascade residual floors}
        {--promo-immune-nudge-hmac-runbook-qbar-phase-dept-ncapture-floors-contract= : JSON file (any object) to observe promo/immune/nudge/hmac/runbook/qbar/phase/dept/ncapture residual floors}
        {--volume-sig-hybrid-delivery-autowork-mission-http-impact-floors-contract= : JSON file (any object) to observe volume/sig/hybrid/delivery/autowork/mission/http/impact residual floors}
        {--frontier-rerank-fabric-decomp-specpack-handoff-envelope-blocker-advisory-floors-contract= : JSON file (any object) to observe frontier/rerank/fabric/decomp/specpack/handoff/envelope/blocker/advisory residual floors}
        {--integrity-promo-share-thesis-atlas-promo-flywheel-golden-ambition-floors-contract= : JSON file (any object) to observe integrity/promo/share/thesis/atlas/promo/flywheel/golden/ambition residual floors}
        {--ledger-disk-latency-teto-ragx-envelope-fidelity-segment-causality-floors-contract= : JSON file (any object) to observe ledger/disk/latency/teto/ragx/envelope/fidelity/segment/causality residual floors}
        {--autonomy-watchdog-scorecard-maxa-corpus-esp09-budget-recall-veto-floors-contract= : JSON file (any object) to observe autonomy/watchdog/scorecard/maxa/corpus/esp09/budget/recall/veto residual floors}
        {--health-immune-calib-deferred-cooccur-thesis-lexical-repair-docs-floors-contract= : JSON file (any object) to observe health/immune/calib/deferred/cooccur/thesis/lexical/repair/docs residual floors}
        {--dept-immune-nudge-runbook-quality-dev-compound-obra-floors-contract= : JSON file (any object) to observe dept/immune/nudge/runbook/quality/dev/compound/obra residual floors}
        {--volume-immune-scorecard-phase-delivery-autowork-citation-cascade-budget-floors-contract= : JSON file (any object) to observe volume/immune/scorecard/phase/delivery/autowork/citation/cascade/budget residual floors}
        {--frontier-rerank-fabric-cockpit-http-specpack-advisory-ncapture-model-floors-contract= : JSON file (any object) to observe frontier/rerank/fabric/cockpit/http/specpack/advisory/ncapture/model residual floors}
        {--texec-obra-evo-window-rollback-maturity-embed-horizon-floors-contract= : JSON file (any object) to observe texec/obra/evo/window/rollback/maturity/embed/horizon residual floors}
        {--maturity-attempt-scorecard-http-qbar-compound-immune-phase-dept-floors-contract= : JSON file (any object) to observe maturity/attempt/scorecard/http/qbar/compound/immune/phase/dept residual floors}
        {--gate-signal-truth-router-veto-choreo-debug-docs-watchdog-pareto-floors-contract= : JSON file (any object) to observe gate/truth/router/veto/choreo/debug/docs/watchdog/pareto residual floors}
        {--immune-freeze-outcome-window-flywheel-promo-calib-handoff-runbook-floors-contract= : JSON file (any object) to observe immune/outcome/window/flywheel/promo/calib/handoff/runbook residual floors}
        {--verdict-dept-debt-canary-asef-freshness-reality-list-schema-floors-contract= : JSON file (any object) to observe verdict/dept/debt/canary/asef/freshness/reality/list/schema residual floors}
        {--compaction-redaction-capture-provenance-impact-bets-maturity-claim-generated-floors-contract= : JSON file (any object) to observe compaction/redaction/capture/provenance/impact/bets/maturity/claim/generated residual floors}
        {--promo-handoff-blocker-protocol-replay-thesis-integrity-budget-deriver-floors-contract= : JSON file (any object) to observe promo/handoff/blocker/protocol/replay/thesis/integrity/budget/deriver residual floors}
        {--segment-fidelity-causality-teto-ragx-envelope-latency-watchdog-hybrid-floors-contract= : JSON file (any object) to observe segment/fidelity/causality/teto/ragx/envelope/latency/watchdog/hybrid residual floors}
        {--memory-budget-recall-maxa-corpus-esp09-dogfood-autonomy-runner-freeze-floors-contract= : JSON file (any object) to observe memory/budget/recall/maxa/corpus/esp09/dogfood/autonomy/runner/freeze residual floors}
                            {--repair-parallel-promoter-verified-cockpit-deferred-window-remint-scorecard-floors-contract= : JSON file (any object) to observe repair/parallel/promoter/verified/cockpit/deferred/window/remint/scorecard floors}
                            {--obra-dept-nudge-aemor-ambition-flywheel-quality-runbook-function-floors-contract= : JSON file (any object) to observe obra/dept/nudge/aemor/ambition/flywheel/quality/runbook/function floors}
                            {--delivery-pack-resource-budget-belief-cascade-citation-grounding-floors-contract= : JSON file (any object) to observe delivery/pack/resource/budget/belief/cascade floors}
                            {--n-capture-domain-lexical-evidence-vision-execution-context-floors-contract= : JSON file (any object) to observe n/capture/domain/lexical/evidence/vision floors}
                            {--obra-retro-acos-rollback-window-orchestrator-long-aaeos-floors-contract= : JSON file (any object) to observe obra/retro/acos/rollback/window/orchestrator floors}
                            {--http-path-cognition-score-department-level-aaeos-doc-floors-contract= : JSON file (any object) to observe http/path/cognition/score/department/level floors}
                            {--aaeos-implementation-context-pareto-gate-phase-immune-calibration-floors-contract= : JSON file (any object) to observe aaeos/implementation/context/pareto/gate/phase floors}
                            {--aaeos-cognitive-implementation-veto-cross-department-lote-measure-floors-contract= : JSON file (any object) to observe aaeos/cognitive/implementation/veto/cross/department floors}
                            {--aaeos-department-string-debug-root-docs-authority-daily-floors-contract= : JSON file (any object) to observe aaeos/department/string/debug/root/docs floors}
                            {--generated-contract-aaeos-claim-department-exploratory-bets-provenance-floors-contract= : JSON file (any object) to observe generated/contract/aaeos/claim/department/exploratory floors}
                            {--aaeos-department-evidence-vision-golden-counterfactual-promotion-protocol-floors-contract= : JSON file (any object) to observe aaeos/department/evidence/vision/golden/counterfactual floors}
                            {--segment-importance-summary-fidelity-outcome-envelope-ragx-chain-floors-contract= : JSON file (any object) to observe segment/importance/summary/fidelity/outcome/envelope floors}
                            {--memory-recall-esp-independent-maxa-jina-immune-classifier-floors-contract= : JSON file (any object) to observe memory/recall/esp/independent/maxa/jina floors}
                            {--procedural-skill-verified-share-acos-program-deferred-phase-floors-contract= : JSON file (any object) to observe procedural/skill/verified/share/acos/program floors}
                            {--aemor-outcome-ambition-rung-flywheel-funnel-composed-obra-floors-contract= : JSON file (any object) to observe aemor/outcome/ambition/rung/flywheel/funnel floors}
                            {--resource-budget-belief-cascade-citation-grounding-dev-procedural-floors-contract= : JSON file (any object) to observe resource/budget/belief/cascade/citation/grounding floors}
                            {--b375-n-capture-domain-lexical-evidence-vision-execution-context-floors-contract= : JSON file (any object) to observe n/capture/domain/lexical/evidence/vision floors}
                            {--aaeos-test-window-orchestrator-code-symbol-knowledge-item-floors-contract= : JSON file (any object) to observe aaeos/test/window/orchestrator/code/symbol floors}
                            {--pre-review-http-path-phase-advance-cognition-score-floors-contract= : JSON file (any object) to observe pre/review/http/path/phase/advance floors}
                            {--aaeos-implementation-phase-immune-calibration-signature-acos-watchdog-floors-contract= : JSON file (any object) to observe aaeos/implementation/phase/immune/calibration/signature floors}
                            {--cross-department-portfolio-budget-aaeos-gate-implementation-phase-floors-contract= : JSON file (any object) to observe cross/department/portfolio/budget/aaeos/gate floors}
                            {--obra-retro-daily-canary-aaeos-gate-implementation-cross-floors-contract= : JSON file (any object) to observe obra/retro/daily/canary/aaeos/gate floors}
                            {--exploratory-bets-aaeos-implementation-cross-department-docs-authority-floors-contract= : JSON file (any object) to observe exploratory/bets/aaeos/implementation/cross/department floors}
                            {--evidence-vision-golden-counterfactual-promotion-protocol-phase-handoff-floors-contract= : JSON file (any object) to observe evidence/vision/golden/counterfactual/promotion/protocol floors}
                            {--outcome-envelope-ragx-chain-teto-predicted-immune-hybrid-floors-contract= : JSON file (any object) to observe outcome/envelope/ragx/chain/teto/predicted floors}
                            {--maxa-jina-immune-classifier-watchdog-runner-lote-measure-floors-contract= : JSON file (any object) to observe maxa/jina/immune/classifier/watchdog/runner floors}
                            {--deferred-phase-acos-window-cognition-remint-score-lote-floors-contract= : JSON file (any object) to observe deferred/phase/acos/window/cognition/remint floors}
                            {--flywheel-funnel-department-contract-runbook-cognitive-function-lote-floors-contract= : JSON file (any object) to observe flywheel/funnel/department/contract/runbook/cognitive floors}
                            {--citation-grounding-delivery-pack-cognitive-function-immune-signature-floors-contract= : JSON file (any object) to observe citation/grounding/delivery/pack/cognitive/function floors}
                            {--n-capture-domain-lexical-execution-context-aaeos-http-floors-contract= : JSON file (any object) to observe n/capture/domain/lexical/execution/context floors}
                            {--acos-evolution-long-rollback-lote-measure-code-symbol-floors-contract= : JSON file (any object) to observe acos/evolution/long/rollback/lote/measure floors}
                            {--pre-review-phase-advance-cognition-score-lote-measure-floors-contract= : JSON file (any object) to observe pre/review/phase/advance/cognition/score floors}
                            {--immune-calibration-acos-watchdog-lote-measure-n-capture-floors-contract= : JSON file (any object) to observe immune/calibration/acos/watchdog/lote/measure floors}
                            {--lote-measure-evidence-vision-exploratory-bets-pre-review-floors-contract= : JSON file (any object) to observe lote/measure/evidence/vision/exploratory/bets floors}
                            {--daily-canary-lote-measure-exploratory-bets-pre-review-floors-contract= : JSON file (any object) to observe daily/canary/lote/measure/exploratory/bets floors}
                            {--lote-measure-http-path-phase-handoff-aaeos-mission-floors-contract= : JSON file (any object) to observe lote/measure/http/path/phase/handoff floors}
                            {--lote-measure-http-path-mission-control-department-contract-floors-contract= : JSON file (any object) to observe lote/measure/http/path/mission/control floors}
                            {--aobg-latency-lote-measure-http-path-mission-control-floors-contract= : JSON file (any object) to observe aobg/latency/lote/measure/http/path floors}
                            {--immune-classifier-lote-measure-http-path-runbook-acos-floors-contract= : JSON file (any object) to observe immune/classifier/lote/measure/http/path floors}
                            {--lote-measure-runbook-acos-long-cognition-score-cognitive-floors-contract= : JSON file (any object) to observe lote/measure/runbook/acos/long/cognition floors}
                            {--b399-lote-measure-runbook-acos-long-cognition-score-cognitive-floors-contract= : JSON file (any object) to observe lote/measure/runbook/acos/long/cognition floors}
                            {--acos-watchdog-immune-calibration-long-lote-measure-runbook-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/long/lote floors}
                            {--b401-acos-watchdog-immune-calibration-long-lote-measure-runbook-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/long/lote floors}
                            {--b402-acos-watchdog-immune-calibration-long-lote-measure-runbook-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/long/lote floors}
                            {--acos-watchdog-immune-calibration-long-runbook-lote-measure-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/long/runbook floors}
                            {--acos-watchdog-immune-calibration-long-context-pareto-memory-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/long/context floors}
                            {--acos-watchdog-immune-calibration-measure-program-aemor-outcome-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/measure/program floors}
                            {--acos-watchdog-immune-calibration-n-capture-belief-cascade-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/n/capture floors}
                            {--acos-watchdog-immune-calibration-maxa-jina-outcome-envelope-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/calibration/maxa/jina floors}
                            {--acos-watchdog-phase-handoff-architect-agent-autonomous-work-floors-contract= : JSON file (any object) to observe acos/watchdog/phase/handoff/architect/agent floors}
                            {--acos-watchdog-department-contract-cognition-remint-immune-check-floors-contract= : JSON file (any object) to observe acos/watchdog/department/contract/cognition/remint floors}
                            {--acos-watchdog-dead-aobg-latency-disk-free-substrate-floors-contract= : JSON file (any object) to observe acos/watchdog/dead/aobg/latency/disk floors}
                            {--context-nudge-autonomy-ladder-mission-control-compounding-outcome-floors-contract= : JSON file (any object) to observe context/nudge/autonomy/ladder/mission/control floors}
                            {--operational-volume-context-nudge-acos-watchdog-lote-measure-floors-contract= : JSON file (any object) to observe operational/volume/context/nudge/acos/watchdog floors}
                            {--context-nudge-acos-watchdog-lote-measure-autonomy-ladder-floors-contract= : JSON file (any object) to observe context/nudge/acos/watchdog/lote/measure floors}
                            {--aaeos-department-cognitive-measure-series-health-report-code-floors-contract= : JSON file (any object) to observe aaeos/department/cognitive/measure/series/health floors}
                            {--aaeos-veto-test-evidence-ledger-predicted-impact-provider-floors-contract= : JSON file (any object) to observe aaeos/veto/test/evidence/ledger/predicted floors}
                            {--memory-recall-dogfooding-friction-portfolio-budget-operator-learning-floors-contract= : JSON file (any object) to observe memory/recall/dogfooding/friction/portfolio/budget floors}
                            {--acos-long-obra-retro-department-contract-aaeos-evolution-floors-contract= : JSON file (any object) to observe acos/long/obra/retro/department/contract floors}
                            {--knowledge-item-aemor-outcome-department-contract-aaeos-acos-floors-contract= : JSON file (any object) to observe knowledge/item/aemor/outcome/department/contract floors}
                            {--evidence-vision-composed-obra-n-capture-department-contract-floors-contract= : JSON file (any object) to observe evidence/vision/composed/obra/n/capture floors}
                            {--promotion-protocol-immune-calibration-maxa-jina-teto-predicted-floors-contract= : JSON file (any object) to observe promotion/protocol/immune/calibration/maxa/jina floors}
                            {--frontier-wave-acos-rollback-department-contract-aaeos-long-floors-contract= : JSON file (any object) to observe frontier/wave/acos/rollback/department/contract floors}
                            {--department-contract-aaeos-cognitive-measure-series-http-path-floors-contract= : JSON file (any object) to observe department/contract/aaeos/cognitive/measure/series floors}
                            {--cognitive-memory-immune-classifier-acos-dead-aobg-latency-floors-contract= : JSON file (any object) to observe cognitive/memory/immune/classifier/acos/dead floors}
                            {--compounding-outcome-acos-measure-department-contract-evolution-long-floors-contract= : JSON file (any object) to observe compounding/outcome/acos/measure/department/contract floors}
                            {--mission-control-department-contract-measure-series-http-path-floors-contract= : JSON file (any object) to observe mission/control/department/contract/measure/series floors}
                            {--lote-measure-asef-chunk-resource-budget-department-contract-floors-contract= : JSON file (any object) to observe lote/measure/asef/chunk/resource/budget floors}
                            {--immune-hybrid-capture-hmac-watchdog-runner-signature-department-floors-contract= : JSON file (any object) to observe immune/hybrid/capture/hmac/watchdog/runner floors}
                            {--aaeos-test-evidence-ledger-department-contract-cognition-health-floors-contract= : JSON file (any object) to observe aaeos/test/evidence/ledger/department/contract floors}
                            {--department-contract-http-path-frontier-wave-operational-volume-floors-contract= : JSON file (any object) to observe department/contract/http/path/frontier/wave floors}
                            {--obra-retro-department-contract-lote-measure-verified-share-floors-contract= : JSON file (any object) to observe obra/retro/department/contract/lote/measure floors}
                            {--department-contract-teto-predicted-mission-control-acos-evolution-floors-contract= : JSON file (any object) to observe department/contract/teto/predicted/mission/control floors}
                            {--department-contract-health-report-dev-procedural-execution-context-floors-contract= : JSON file (any object) to observe department/contract/health/report/dev/procedural floors}
                            {--department-contract-window-orchestrator-model-capability-n-capture-floors-contract= : JSON file (any object) to observe department/contract/window/orchestrator/model/capability floors}
                            {--cognitive-function-immune-promotion-cognition-score-aaeos-department-floors-contract= : JSON file (any object) to observe cognitive/function/immune/promotion/cognition/score floors}
                            {--immune-signature-acos-program-reactive-saturation-architect-agent-floors-contract= : JSON file (any object) to observe immune/signature/acos/program/reactive/saturation floors}
                            {--phase-advance-consolidation-rerank-immune-check-verdict-aobg-floors-contract= : JSON file (any object) to observe phase/advance/consolidation/rerank/immune/check floors}
                            {--acos-measure-local-model-composed-obra-pre-review-floors-contract= : JSON file (any object) to observe acos/measure/local/model/composed/obra floors}
                            {--cognitive-function-department-contract-immune-promotion-cognition-score-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/immune/promotion floors}
                            {--b439-cognitive-function-department-contract-immune-promotion-cognition-score-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/immune/promotion floors}
                            {--capture-hmac-cognitive-function-department-contract-immune-promotion-floors-contract= : JSON file (any object) to observe capture/hmac/cognitive/function/department/contract floors}
                            {--aaeos-test-maxa-jina-cognitive-function-department-contract-floors-contract= : JSON file (any object) to observe aaeos/test/maxa/jina/cognitive/function floors}
                            {--frontier-wave-immune-calibration-cognitive-function-department-contract-floors-contract= : JSON file (any object) to observe frontier/wave/immune/calibration/cognitive/function floors}
                            {--lote-measure-promotion-protocol-knowledge-item-verified-share-floors-contract= : JSON file (any object) to observe lote/measure/promotion/protocol/knowledge/item floors}
                            {--cognitive-memory-teto-predicted-cognition-evidence-immune-hybrid-floors-contract= : JSON file (any object) to observe cognitive/memory/teto/predicted/cognition/evidence floors}
                            {--aaeos-implementation-cognitive-function-department-contract-cognition-score-floors-contract= : JSON file (any object) to observe aaeos/implementation/cognitive/function/department/contract floors}
                            {--window-orchestrator-ragx-chain-cognitive-function-department-contract-floors-contract= : JSON file (any object) to observe window/orchestrator/ragx/chain/cognitive/function floors}
                            {--cognition-score-aaeos-http-acos-window-fact-pair-floors-contract= : JSON file (any object) to observe cognition/score/aaeos/http/acos/window floors}
                            {--reactive-saturation-architect-agent-autonomous-work-acos-program-floors-contract= : JSON file (any object) to observe reactive/saturation/architect/agent/autonomous/work floors}
                            {--phase-advance-structured-fact-immune-check-cognitive-function-floors-contract= : JSON file (any object) to observe phase/advance/structured/fact/immune/check floors}
                            {--reality-compiler-cognitive-function-department-contract-aaeos-immune-floors-contract= : JSON file (any object) to observe reality/compiler/cognitive/function/department/contract floors}
                            {--cognitive-function-department-contract-cognition-score-lote-measure-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/cognition/score floors}
                            {--cognitive-function-department-contract-model-capability-acos-evolution-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/model/capability floors}
                            {--b453-capture-hmac-cognitive-function-department-contract-immune-promotion-floors-contract= : JSON file (any object) to observe capture/hmac/cognitive/function/department/contract floors}
                            {--aaeos-test-cognitive-function-department-contract-implementation-memory-floors-contract= : JSON file (any object) to observe aaeos/test/cognitive/function/department/contract floors}
                            {--cognitive-function-department-contract-aaeos-immune-promotion-acos-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/aaeos/immune floors}
                            {--cognitive-function-department-contract-model-capability-http-path-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/model/capability floors}
                            {--cognitive-function-department-contract-capture-hmac-fact-pair-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/capture/hmac floors}
                            {--cognitive-function-department-contract-aaeos-implementation-cross-docs-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/aaeos/implementation floors}
                            {--cognitive-function-department-contract-measure-series-verified-share-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/measure/series floors}
                            {--cognitive-function-department-contract-evidence-vision-pre-review-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/evidence/vision floors}
                            {--cognitive-function-department-contract-autonomous-work-runbook-consolidation-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/autonomous/work floors}
                            {--cognitive-function-department-contract-phase-advance-immune-check-floors-contract= : JSON file (any object) to observe cognitive/function/department/contract/phase/advance floors}
                            {--cognitive-function-autonomy-ladder-compaction-recovery-daily-canary-floors-contract= : JSON file (any object) to observe cognitive/function/autonomy/ladder/compaction/recovery floors}
                            {--cognitive-function-substrate-restore-outcome-envelope-aaeos-department-floors-contract= : JSON file (any object) to observe cognitive/function/substrate/restore/outcome/envelope floors}
                            {--cognitive-function-memory-recall-verified-share-knowledge-item-floors-contract= : JSON file (any object) to observe cognitive/function/memory/recall/verified/share floors}
                            {--memory-feedback-aaeos-gate-local-model-flywheel-funnel-floors-contract= : JSON file (any object) to observe memory/feedback/aaeos/gate/local/model floors}
                            {--ragx-chain-acos-rollback-immune-hybrid-signature-department-floors-contract= : JSON file (any object) to observe ragx/chain/acos/rollback/immune/hybrid floors}
                            {--department-contract-acos-watchdog-immune-promotion-cognitive-function-floors-contract= : JSON file (any object) to observe department/contract/acos/watchdog/immune/promotion floors}
                            {--aaeos-http-department-contract-acos-watchdog-immune-promotion-floors-contract= : JSON file (any object) to observe aaeos/http/department/contract/acos/watchdog floors}
                            {--composed-obra-department-contract-acos-watchdog-immune-promotion-floors-contract= : JSON file (any object) to observe composed/obra/department/contract/acos/watchdog floors}
                            {--aaeos-implementation-docs-authority-department-cross-contract-acos-floors-contract= : JSON file (any object) to observe aaeos/implementation/docs/authority/department/cross floors}
                            {--aemor-outcome-department-contract-acos-watchdog-immune-promotion-floors-contract= : JSON file (any object) to observe aemor/outcome/department/contract/acos/watchdog floors}
                            {--department-contract-acos-watchdog-immune-promotion-aaeos-cognitive-floors-contract= : JSON file (any object) to observe department/contract/acos/watchdog/immune/promotion floors}
                            {--department-contract-acos-watchdog-immune-promotion-aaeos-gate-floors-contract= : JSON file (any object) to observe department/contract/acos/watchdog/immune/promotion floors}
                            {--department-contract-acos-watchdog-flywheel-funnel-local-model-floors-contract= : JSON file (any object) to observe department/contract/acos/watchdog/flywheel/funnel floors}
                            {--joint-resource-department-contract-acos-watchdog-cognitive-function-floors-contract= : JSON file (any object) to observe joint/resource/department/contract/acos/watchdog floors}
                            {--department-contract-acos-watchdog-aaeos-test-implementation-summary-floors-contract= : JSON file (any object) to observe department/contract/acos/watchdog/aaeos/test floors}
                            {--department-contract-verified-share-phase-advance-model-capability-floors-contract= : JSON file (any object) to observe department/contract/verified/share/phase/advance floors}
                            {--department-contract-spec-completeness-acos-measure-resource-budget-floors-contract= : JSON file (any object) to observe department/contract/spec/completeness/acos/measure floors}
                            {--department-contract-cognition-score-cognitive-memory-consolidation-rerank-floors-contract= : JSON file (any object) to observe department/contract/cognition/score/cognitive/memory floors}
                            {--department-contract-acos-dead-aobg-latency-local-model-floors-contract= : JSON file (any object) to observe department/contract/acos/dead/aobg/latency floors}
                            {--department-contract-aaeos-http-acos-evolution-immune-promotion-floors-contract= : JSON file (any object) to observe department/contract/aaeos/http/acos/evolution floors}
                            {--b483-department-contract-floors-contract= : JSON file (any object) to observe department/contract floors}
                            {--b484-department-contract-floors-contract= : JSON file (any object) to observe department/contract floors}
                            {--b485-cognition-score-ledger-rotation-health-report-aaeos-quality-floors-contract= : JSON file (any object) to observe cognition/score/ledger/rotation/health/report floors}
                            {--b486-cognition-score-promotion-protocol-ledger-rotation-health-report-floors-contract= : JSON file (any object) to observe cognition/score/promotion/protocol/ledger/rotation floors}
                            {--b487-cognition-score-promotion-protocol-ledger-rotation-health-report-floors-contract= : JSON file (any object) to observe cognition/score/promotion/protocol/ledger/rotation floors}
                            {--b488-acos-long-cognition-score-promotion-protocol-ledger-rotation-floors-contract= : JSON file (any object) to observe acos/long/cognition/score/promotion/protocol floors}
                            {--b489-cognition-score-promotion-protocol-health-report-measure-series-floors-contract= : JSON file (any object) to observe cognition/score/promotion/protocol/health/report floors}
                            {--b490-aaeos-implementation-cognition-score-promotion-protocol-quality-frontier-floors-contract= : JSON file (any object) to observe aaeos/implementation/cognition/score/promotion/protocol floors}
                            {--b491-cognition-score-acos-watchdog-autonomy-ladder-aaeos-test-floors-contract= : JSON file (any object) to observe cognition/score/acos/watchdog/autonomy/ladder floors}
                            {--b492-cognition-score-procedural-skill-esp-independent-maxa-jina-floors-contract= : JSON file (any object) to observe cognition/score/procedural/skill/esp/independent floors}
                            {--b493-cognition-score-immune-signature-verified-share-window-orchestrator-floors-contract= : JSON file (any object) to observe cognition/score/immune/signature/verified/share floors}
                            {--b494-cognition-score-watchdog-runner-acos-dead-disk-free-floors-contract= : JSON file (any object) to observe cognition/score/watchdog/runner/acos/dead floors}
                            {--b495-cognition-score-aaeos-http-spec-completeness-ledger-rotation-floors-contract= : JSON file (any object) to observe cognition/score/aaeos/http/spec/completeness floors}
                            {--b496-cognition-score-floors-contract= : JSON file (any object) to observe cognition/score floors}
                            {--b497-http-path-evidence-vision-pre-review-outcome-causality-floors-contract= : JSON file (any object) to observe http/path/evidence/vision/pre/review floors}
                            {--b498-code-symbol-immune-classifier-knowledge-item-aobg-latency-floors-contract= : JSON file (any object) to observe code/symbol/immune/classifier/knowledge/item floors}
                            {--b499-measure-series-lote-ledger-rotation-acos-watchdog-autonomy-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b500-measure-series-lote-ledger-rotation-acos-watchdog-autonomy-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b501-acos-long-measure-series-lote-ledger-rotation-watchdog-floors-contract= : JSON file (any object) to observe acos/long/measure/series/lote/ledger floors}
                            {--b502-measure-series-lote-ledger-rotation-acos-watchdog-autonomy-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b503-measure-series-lote-ledger-rotation-acos-watchdog-autonomy-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b504-runbook-measure-series-lote-ledger-rotation-acos-watchdog-floors-contract= : JSON file (any object) to observe runbook/measure/series/lote/ledger/rotation floors}
                            {--b505-cognition-score-immune-signature-maxa-jina-outcome-envelope-floors-contract= : JSON file (any object) to observe cognition/score/immune/signature/maxa/jina floors}
                            {--b506-cognitive-function-immune-calibration-portfolio-budget-phase-handoff-floors-contract= : JSON file (any object) to observe cognitive/function/immune/calibration/portfolio/budget floors}
                            {--b507-acos-evolution-memory-recall-measure-series-lote-ledger-floors-contract= : JSON file (any object) to observe acos/evolution/memory/recall/measure/series floors}
                            {--b508-spec-completeness-aaeos-http-measure-series-lote-ledger-floors-contract= : JSON file (any object) to observe spec/completeness/aaeos/http/measure/series floors}
                            {--b509-measure-series-lote-ledger-rotation-acos-watchdog-code-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b510-evidence-vision-outcome-causality-pre-review-segment-importance-floors-contract= : JSON file (any object) to observe evidence/vision/outcome/causality/pre/review floors}
                            {--b511-immune-classifier-measure-series-lote-ledger-rotation-verified-floors-contract= : JSON file (any object) to observe immune/classifier/measure/series/lote/ledger floors}
                            {--b512-measure-series-lote-ledger-rotation-cognition-score-code-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/cognition floors}
                            {--b513-measure-series-lote-ledger-rotation-acos-long-autonomy-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b514-measure-series-lote-ledger-rotation-immune-signature-health-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/immune floors}
                            {--b515-measure-series-lote-ledger-rotation-n-capture-promotion-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/n floors}
                            {--b516-measure-series-lote-ledger-rotation-acos-watchdog-outcome-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b517-measure-series-lote-ledger-rotation-immune-classifier-signature-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/immune floors}
                            {--b518-measure-series-lote-ledger-rotation-acos-rollback-aaeos-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/acos floors}
                            {--b519-measure-series-lote-ledger-rotation-window-orchestrator-acos-floors-contract= : JSON file (any object) to observe measure/series/lote/ledger/rotation/window floors}
                            {--b520-measure-series-lote-memory-recall-evidence-vision-execution-floors-contract= : JSON file (any object) to observe measure/series/lote/memory/recall/evidence floors}
                            {--b521-measure-series-lote-aaeos-http-mission-control-delivery-floors-contract= : JSON file (any object) to observe measure/series/lote/aaeos/http/mission floors}
                            {--b522-measure-series-lote-capture-hmac-immune-promotion-watchdog-floors-contract= : JSON file (any object) to observe measure/series/lote/capture/hmac/immune floors}
                            {--b523-acos-evolution-cognition-score-aaeos-quality-spec-completeness-floors-contract= : JSON file (any object) to observe acos/evolution/cognition/score/aaeos/quality floors}
                            {--b524-measure-series-http-path-acos-program-obra-retro-floors-contract= : JSON file (any object) to observe measure/series/http/path/acos/program floors}
                            {--b525-measure-series-http-path-floors-contract= : JSON file (any object) to observe measure/series/http/path floors}
                            {--b526-measure-series-floors-contract= : JSON file (any object) to observe measure/series floors}
                            {--b527-docs-authority-aaeos-veto-phase-handoff-asef-chunk-floors-contract= : JSON file (any object) to observe docs/authority/aaeos/veto/phase/handoff floors}
                            {--b528-quality-bar-aaeos-cognitive-outcome-envelope-operational-volume-floors-contract= : JSON file (any object) to observe quality/bar/aaeos/cognitive/outcome/envelope floors}
                            {--b529-acos-watchdog-long-verified-share-pre-review-golden-floors-contract= : JSON file (any object) to observe acos/watchdog/long/verified/share/pre floors}
                            {--b530-immune-signature-calibration-aemor-outcome-compounding-evidence-vision-floors-contract= : JSON file (any object) to observe immune/signature/calibration/aemor/outcome/compounding floors}
                            {--b531-memory-feedback-aaeos-test-implementation-department-contract-lote-floors-contract= : JSON file (any object) to observe memory/feedback/aaeos/test/implementation/department floors}
                            {--b532-knowledge-item-department-contract-lote-measure-acos-watchdog-floors-contract= : JSON file (any object) to observe knowledge/item/department/contract/lote/measure floors}
                            {--b533-evidence-vision-memory-recall-department-contract-lote-measure-floors-contract= : JSON file (any object) to observe evidence/vision/memory/recall/department/contract floors}
                            {--b534-delivery-pack-department-contract-lote-measure-series-docs-floors-contract= : JSON file (any object) to observe delivery/pack/department/contract/lote/measure floors}
                            {--b535-daily-canary-immune-promotion-department-contract-asef-chunk-floors-contract= : JSON file (any object) to observe daily/canary/immune/promotion/department/contract floors}
                            {--b536-cognition-score-acos-evolution-autonomy-ladder-code-symbol-floors-contract= : JSON file (any object) to observe cognition/score/acos/evolution/autonomy/ladder floors}
                            {--b537-maxa-jina-capture-hmac-acos-watchdog-immune-signature-floors-contract= : JSON file (any object) to observe maxa/jina/capture/hmac/acos/watchdog floors}
                            {--b538-aaeos-test-http-path-department-contract-verified-share-floors-contract= : JSON file (any object) to observe aaeos/test/http/path/department/contract floors}
                            {--b539-aaeos-veto-segment-importance-flywheel-funnel-pre-review-floors-contract= : JSON file (any object) to observe aaeos/veto/segment/importance/flywheel/funnel floors}
                            {--b540-immune-calibration-daily-canary-aaeos-cognitive-lote-measure-floors-contract= : JSON file (any object) to observe immune/calibration/daily/canary/aaeos/cognitive floors}
                            {--b541-acos-watchdog-immune-signature-knowledge-item-model-capability-floors-contract= : JSON file (any object) to observe acos/watchdog/immune/signature/knowledge/item floors}
                            {--b542-aaeos-test-verified-share-acos-program-http-path-floors-contract= : JSON file (any object) to observe aaeos/test/verified/share/acos/program floors}
                            {--b543-aaeos-doc-gate-context-pareto-outcome-causality-summary-floors-contract= : JSON file (any object) to observe aaeos/doc/gate/context/pareto/outcome floors}
                            {--b544-aaeos-implementation-department-value-portfolio-budget-deferred-phase-floors-contract= : JSON file (any object) to observe aaeos/implementation/department/value/portfolio/budget floors}
                            {--b545-aaeos-cognitive-implementation-veto-segment-importance-spec-completeness-floors-contract= : JSON file (any object) to observe aaeos/cognitive/implementation/veto/segment/importance floors}
                            {--b546-aaeos-department-autonomous-work-http-aobg-latency-quality-floors-contract= : JSON file (any object) to observe aaeos/department/autonomous/work/http/aobg floors}
                            {--b547-cognition-score-department-contract-measure-series-immune-promotion-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b548-cognition-score-department-contract-measure-series-immune-promotion-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b549-cognition-score-department-contract-measure-series-immune-promotion-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b550-cognition-score-department-contract-measure-series-immune-promotion-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b551-cognition-score-department-contract-measure-series-lote-phase-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b552-cognition-score-department-contract-measure-series-knowledge-item-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b553-cognition-score-department-contract-measure-series-daily-canary-floors-contract= : JSON file (any object) to observe cognition/score/department/contract/measure/series floors}
                            {--b554-cognition-score-department-contract-floors-contract= : JSON file (any object) to observe cognition/score/department/contract floors}
                            {--b555-cognition-score-department-contract-floors-contract= : JSON file (any object) to observe cognition/score/department/contract floors}
                            {--b556-cognition-score-department-contract-floors-contract= : JSON file (any object) to observe cognition/score/department/contract floors}
                            {--b557-cognition-score-floors-contract= : JSON file (any object) to observe cognition/score floors}
                            {--b558-aaeos-cognitive-function-consolidation-rerank-capture-hmac-department-floors-contract= : JSON file (any object) to observe aaeos/cognitive/function/consolidation/rerank/capture floors}
                            {--b559-aaeos-cognitive-floors-contract= : JSON file (any object) to observe aaeos/cognitive floors}
                            {--b560-aaeos-cognitive-floors-contract= : JSON file (any object) to observe aaeos/cognitive floors}
                            {--b561-evidence-vision-exploratory-bets-floors-contract= : JSON file (any object) to observe evidence/vision/exploratory/bets floors}
                            {--b562-maxa-jina-acos-long-floors-contract= : JSON file (any object) to observe maxa/jina/acos/long floors}
                            {--b563-acos-watchdog-floors-contract= : JSON file (any object) to observe acos/watchdog floors}
                            {--b564-lote-measure-floors-contract= : JSON file (any object) to observe lote/measure floors}
                            {--b565-asef-chunk-aaeos-implementation-floors-contract= : JSON file (any object) to observe asef/chunk/aaeos/implementation floors}
                            {--b566-immune-calibration-composed-obra-floors-contract= : JSON file (any object) to observe immune/calibration/composed/obra floors}
                            {--b567-teto-predicted-autonomy-ladder-floors-contract= : JSON file (any object) to observe teto/predicted/autonomy/ladder floors}
                            {--b568-verified-share-esp-independent-floors-contract= : JSON file (any object) to observe verified/share/esp/independent floors}
                            {--b569-pre-review-cognitive-function-floors-contract= : JSON file (any object) to observe pre/review/cognitive/function floors}
                            {--b570-aaeos-quality-lote-measure-procedural-skill-floors-contract= : JSON file (any object) to observe aaeos/quality/lote/measure/procedural/skill floors}
                            {--b571-capture-hmac-phase-handoff-floors-contract= : JSON file (any object) to observe capture/hmac/phase/handoff floors}
                            {--b572-execution-context-immune-signature-aaeos-veto-floors-contract= : JSON file (any object) to observe execution/context/immune/signature/aaeos/veto floors}
                            {--b573-obra-retro-evidence-vision-ragx-chain-floors-contract= : JSON file (any object) to observe obra/retro/evidence/vision/ragx/chain floors}
                            {--b574-http-path-cross-department-segment-importance-floors-contract= : JSON file (any object) to observe http/path/cross/department/segment/importance floors}
                            {--b575-parallel-execution-aemor-outcome-knowledge-item-floors-contract= : JSON file (any object) to observe parallel/execution/aemor/outcome/knowledge/item floors}
                            {--b576-composed-obra-dev-procedural-outcome-envelope-floors-contract= : JSON file (any object) to observe composed/obra/dev/procedural/outcome/envelope floors}
                            {--b577-promotion-protocol-cognition-score-evidence-ledger-floors-contract= : JSON file (any object) to observe promotion/protocol/cognition/score/evidence/ledger floors}
                            {--b578-window-orchestrator-code-symbol-exploratory-bets-maxa-jina-floors-contract= : JSON file (any object) to observe window/orchestrator/code/symbol/exploratory/bets floors}
                            {--b579-reactive-saturation-department-contract-acos-evolution-window-floors-contract= : JSON file (any object) to observe reactive/saturation/department/contract/acos/evolution floors}
                            {--b580-daily-canary-docs-authority-spec-completeness-local-model-floors-contract= : JSON file (any object) to observe daily/canary/docs/authority/spec/completeness floors}
                            {--b581-golden-counterfactual-portfolio-budget-aaeos-http-acos-long-floors-contract= : JSON file (any object) to observe golden/counterfactual/portfolio/budget/aaeos/http floors}
                            {--b582-operational-volume-capture-hmac-aaeos-department-outcome-causality-floors-contract= : JSON file (any object) to observe operational/volume/capture/hmac/aaeos/department floors}
                            {--b583-n-capture-compounding-outcome-dogfooding-friction-gated-corpus-floors-contract= : JSON file (any object) to observe n/capture/compounding/outcome/dogfooding/friction floors}
                            {--b584-cognitive-function-immune-hybrid-calibration-aobg-latency-department-floors-contract= : JSON file (any object) to observe cognitive/function/immune/hybrid/calibration/aobg floors}
                            {--b585-aaeos-quality-memory-feedback-injection-lote-measure-series-floors-contract= : JSON file (any object) to observe aaeos/quality/memory/feedback/injection/lote floors}
                            {--b586-execution-context-outcome-envelope-predicted-impact-acos-rollback-floors-contract= : JSON file (any object) to observe execution/context/outcome/envelope/predicted/impact floors}
                            {--b587-immune-signature-verdict-acos-dead-disk-free-provider-floors-contract= : JSON file (any object) to observe immune/signature/verdict/acos/dead/disk floors}
                            {--b588-obra-retro-local-model-capability-attempt-lifecycle-composed-floors-contract= : JSON file (any object) to observe obra/retro/local/model/capability/attempt floors}
                            {--b589-capture-hmac-acos-watchdog-autonomy-ladder-daily-canary-floors-contract= : JSON file (any object) to observe capture/hmac/acos/watchdog/autonomy/ladder floors}
                            {--b590-ledger-rotation-cognition-score-department-contract-acos-evolution-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b591-evidence-vision-phase-handoff-autonomy-ladder-aaeos-doc-floors-contract= : JSON file (any object) to observe evidence/vision/phase/handoff/autonomy/ladder floors}
                            {--b592-knowledge-item-composed-obra-aaeos-http-autonomous-work-floors-contract= : JSON file (any object) to observe knowledge/item/composed/obra/aaeos/http floors}
                            {--b593-ledger-rotation-cognition-score-department-contract-acos-evolution-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b594-ledger-rotation-cognition-score-department-contract-acos-evolution-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b595-ledger-rotation-cognition-score-department-contract-acos-evolution-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b596-ledger-rotation-cognition-score-department-contract-acos-evolution-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b597-ledger-rotation-cognition-score-department-contract-procedural-skill-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b598-ledger-rotation-cognition-score-department-contract-cognitive-function-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/department/contract floors}
                            {--b599-ledger-rotation-cognition-score-watchdog-runner-acos-dead-floors-contract= : JSON file (any object) to observe ledger/rotation/cognition/score/watchdog/runner floors}
                            {--b600-ledger-rotation-local-model-operator-learning-provider-bound-floors-contract= : JSON file (any object) to observe ledger/rotation/local/model/operator/learning floors}
                            {--b601-ledger-rotation-department-contract-acos-window-cognition-score-floors-contract= : JSON file (any object) to observe ledger/rotation/department/contract/acos/window floors}
                            {--b602-aaeos-veto-citation-grounding-gated-corpus-cognitive-lote-floors-contract= : JSON file (any object) to observe aaeos/veto/citation/grounding/gated/corpus floors}
                            {--b603-maxa-jina-generated-contract-lote-measure-department-acos-floors-contract= : JSON file (any object) to observe maxa/jina/generated/contract/lote/measure floors}
                            {--b604-lote-measure-department-contract-acos-evolution-operational-volume-floors-contract= : JSON file (any object) to observe lote/measure/department/contract/acos/evolution floors}
                            {--b605-promotion-protocol-phase-handoff-composed-obra-acos-watchdog-floors-contract= : JSON file (any object) to observe promotion/protocol/phase/handoff/composed/obra floors}
                            {--b606-promotion-protocol-memory-cognitive-learning-proposals-watchdog-check-floors-contract= : JSON file (any object) to observe promotion/protocol/memory/cognitive/learning/proposals floors}
                            {--b607-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b608-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b609-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b610-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b611-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b612-memory-cognitive-learning-proposals-floors-contract= : JSON file (any object) to observe memory/cognitive/learning/proposals floors}
                            {--b613-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b614-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b615-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b616-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b617-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b618-learning-proposals-memory-cognitive-floors-contract= : JSON file (any object) to observe learning/proposals/memory/cognitive floors}
                            {--b619-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b620-aaeos-phase-floors-contract= : JSON file (any object) to observe aaeos/phase floors}
                            {--b621-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b622-aaeos-threshold-test-floors-contract= : JSON file (any object) to observe aaeos/threshold/test floors}
                            {--b623-aaeos-test-floors-contract= : JSON file (any object) to observe aaeos/test floors}
                            {--b624-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b625-aaeos-threshold-string-veto-floors-contract= : JSON file (any object) to observe aaeos/threshold/string/veto floors}
                            {--b626-aaeos-string-veto-floors-contract= : JSON file (any object) to observe aaeos/string/veto floors}
                            {--b627-aaeos-veto-floors-contract= : JSON file (any object) to observe aaeos/veto floors}
                            {--b628-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b629-aaeos-claim-floors-contract= : JSON file (any object) to observe aaeos/claim floors}
                            {--b630-aaeos-quality-floors-contract= : JSON file (any object) to observe aaeos/quality floors}
                            {--b631-generated-contract-repair-loop-floors-contract= : JSON file (any object) to observe generated/contract/repair/loop floors}
                            {--b632-repair-loop-aaeos-implementation-floors-contract= : JSON file (any object) to observe repair/loop/aaeos/implementation floors}
                            {--b633-aaeos-implementation-floors-contract= : JSON file (any object) to observe aaeos/implementation floors}
                            {--b634-outcome-causality-floors-contract= : JSON file (any object) to observe outcome/causality floors}
                            {--b635-memory-recall-floors-contract= : JSON file (any object) to observe memory/recall floors}
                            {--b636-context-pareto-floors-contract= : JSON file (any object) to observe context/pareto floors}
                            {--b637-memory-injection-floors-contract= : JSON file (any object) to observe memory/injection floors}
                            {--b638-segment-importance-floors-contract= : JSON file (any object) to observe segment/importance floors}
                            {--b639-summary-fidelity-floors-contract= : JSON file (any object) to observe summary/fidelity floors}
                            {--b640-spec-completeness-floors-contract= : JSON file (any object) to observe spec/completeness floors}
                            {--b641-memory-feedback-floors-contract= : JSON file (any object) to observe memory/feedback floors}
                            {--b642-learning-proposals-floors-contract= : JSON file (any object) to observe learning/proposals floors}
                            {--b643-memory-cognitive-floors-contract= : JSON file (any object) to observe memory/cognitive floors}
                            {--b644-aaeos-value-http-path-floors-contract= : JSON file (any object) to observe aaeos/value/http/path floors}
                            {--b645-http-path-floors-contract= : JSON file (any object) to observe http/path floors}
                            {--b646-architect-agent-floors-contract= : JSON file (any object) to observe architect/agent floors}
                            {--b647-phase-advance-floors-contract= : JSON file (any object) to observe phase/advance floors}
                            {--b648-reality-compiler-required-gate-floors-contract= : JSON file (any object) to observe reality/compiler/required/gate floors}
                            {--b649-required-gate-department-contract-floors-contract= : JSON file (any object) to observe required/gate/department/contract floors}
                            {--b650-department-contract-floors-contract= : JSON file (any object) to observe department/contract floors}
                            {--b651-quality-bar-floors-contract= : JSON file (any object) to observe quality/bar floors}
                            {--b652-aaeos-http-floors-contract= : JSON file (any object) to observe aaeos/http floors}
                            {--b653-delivery-pack-floors-contract= : JSON file (any object) to observe delivery/pack floors}
                            {--b654-runbook-floors-contract= : JSON file (any object) to observe runbook floors}
                            {--b655-blocker-severity-mission-control-floors-contract= : JSON file (any object) to observe blocker/severity/mission/control floors}
                            {--b656-mission-control-floors-contract= : JSON file (any object) to observe mission/control floors}
                            {--b657-autonomous-work-floors-contract= : JSON file (any object) to observe autonomous/work floors}
                            {--b658-deferred-phase-floors-contract= : JSON file (any object) to observe deferred/phase floors}
                            {--b659-blocker-severity-phase-handoff-floors-contract= : JSON file (any object) to observe blocker/severity/phase/handoff floors}
                            {--b660-phase-handoff-floors-contract= : JSON file (any object) to observe phase/handoff floors}
                            {--b661-recall-gap-window-orchestrator-floors-contract= : JSON file (any object) to observe recall/gap/window/orchestrator floors}
                            {--b662-window-orchestrator-floors-contract= : JSON file (any object) to observe window/orchestrator floors}
                            {--b663-attempt-lifecycle-floors-contract= : JSON file (any object) to observe attempt/lifecycle floors}
                            {--b664-predicted-impact-floors-contract= : JSON file (any object) to observe predicted/impact floors}
                            {--b665-compounding-outcome-floors-contract= : JSON file (any object) to observe compounding/outcome floors}
                            {--b666-ledger-rotation-floors-contract= : JSON file (any object) to observe ledger/rotation floors}
                            {--b667-knowledge-item-floors-contract= : JSON file (any object) to observe knowledge/item floors}
                            {--b668-golden-counterfactual-floors-contract= : JSON file (any object) to observe golden/counterfactual floors}
                            {--b669-dev-procedural-floors-contract= : JSON file (any object) to observe dev/procedural floors}
                            {--b670-n-capture-floors-contract= : JSON file (any object) to observe n/capture floors}
                            {--b671-flywheel-funnel-floors-contract= : JSON file (any object) to observe flywheel/funnel floors}
                            {--b672-composed-obra-floors-contract= : JSON file (any object) to observe composed/obra floors}
                            {--b673-code-symbol-floors-contract= : JSON file (any object) to observe code/symbol floors}
                            {--b674-local-model-floors-contract= : JSON file (any object) to observe local/model floors}
                            {--b675-ambition-rung-floors-contract= : JSON file (any object) to observe ambition/rung floors}
                            {--b676-measure-series-floors-contract= : JSON file (any object) to observe measure/series floors}
                            {--b677-outcome-envelope-floors-contract= : JSON file (any object) to observe outcome/envelope floors}
                            {--b678-portfolio-budget-floors-contract= : JSON file (any object) to observe portfolio/budget floors}
                            {--b679-belief-cascade-domain-lexical-floors-contract= : JSON file (any object) to observe belief/cascade/domain/lexical floors}
                            {--b680-domain-lexical-floors-contract= : JSON file (any object) to observe domain/lexical floors}
                            {--b681-esp-independent-floors-contract= : JSON file (any object) to observe esp/independent floors}
                            {--b682-lote-measure-floors-contract= : JSON file (any object) to observe lote/measure floors}
                            {--b683-execution-context-floors-contract= : JSON file (any object) to observe execution/context floors}
                            {--b684-pre-review-floors-contract= : JSON file (any object) to observe pre/review floors}
                            {--b685-parallel-execution-floors-contract= : JSON file (any object) to observe parallel/execution floors}
                            {--b686-provenance-weight-composed-obra-floors-contract= : JSON file (any object) to observe provenance/weight/composed/obra floors}
                            {--b687-composed-obra-floors-contract= : JSON file (any object) to observe composed/obra floors}
                            {--b688-asef-chunk-floors-contract= : JSON file (any object) to observe asef/chunk floors}
                            {--b689-aemor-outcome-floors-contract= : JSON file (any object) to observe aemor/outcome floors}
                            {--b690-outcome-envelope-floors-contract= : JSON file (any object) to observe outcome/envelope floors}
                            {--b691-promotion-protocol-floors-contract= : JSON file (any object) to observe promotion/protocol floors}
                            {--b692-exploratory-bets-floors-contract= : JSON file (any object) to observe exploratory/bets floors}
                            {--b693-dogfooding-friction-floors-contract= : JSON file (any object) to observe dogfooding/friction floors}
                            {--b694-obra-retro-floors-contract= : JSON file (any object) to observe obra/retro floors}
                            {--b695-verified-share-floors-contract= : JSON file (any object) to observe verified/share floors}
                            {--b696-reactive-saturation-floors-contract= : JSON file (any object) to observe reactive/saturation floors}
                            {--b697-structured-fact-floors-contract= : JSON file (any object) to observe structured/fact floors}
                            {--b698-gated-corpus-floors-contract= : JSON file (any object) to observe gated/corpus floors}
                            {--b699-procedural-skill-floors-contract= : JSON file (any object) to observe procedural/skill floors}
                            {--b700-acos-program-floors-contract= : JSON file (any object) to observe acos/program floors}
                            {--b701-ragx-chain-floors-contract= : JSON file (any object) to observe ragx/chain floors}
                            {--b702-evidence-vision-floors-contract= : JSON file (any object) to observe evidence/vision floors}
                            {--b703-resource-budget-floors-contract= : JSON file (any object) to observe resource/budget floors}
                            {--b704-model-capability-floors-contract= : JSON file (any object) to observe model/capability floors}
                            {--b705-acos-measure-teto-predicted-floors-contract= : JSON file (any object) to observe acos/measure/teto/predicted floors}
                            {--b706-teto-predicted-floors-contract= : JSON file (any object) to observe teto/predicted floors}
                            {--b707-citation-grounding-maxa-jina-floors-contract= : JSON file (any object) to observe citation/grounding/maxa/jina floors}
                            {--b708-maxa-jina-floors-contract= : JSON file (any object) to observe maxa/jina floors}
                            {--b709-maxa-jina-floors-contract= : JSON file (any object) to observe maxa/jina floors}
                            {--b710-evidence-vision-floors-contract= : JSON file (any object) to observe evidence/vision floors}
                            {--b711-cognition-score-floors-contract= : JSON file (any object) to observe cognition/score floors}
                            {--b712-immune-promotion-floors-contract= : JSON file (any object) to observe immune/promotion floors}
                            {--b713-bigram-jaccard-cognition-score-floors-contract= : JSON file (any object) to observe bigram/jaccard/cognition/score floors}
                            {--b714-cognition-score-floors-contract= : JSON file (any object) to observe cognition/score floors}
                            {--b715-immune-signature-floors-contract= : JSON file (any object) to observe immune/signature floors}
                            {--b716-cognitive-memory-floors-contract= : JSON file (any object) to observe cognitive/memory floors}
                            {--b717-fact-pair-consolidation-rerank-floors-contract= : JSON file (any object) to observe fact/pair/consolidation/rerank floors}
                            {--b718-consolidation-rerank-floors-contract= : JSON file (any object) to observe consolidation/rerank floors}
                            {--b719-acos-long-floors-contract= : JSON file (any object) to observe acos/long floors}
                            {--b720-temporal-supersession-immune-signature-floors-contract= : JSON file (any object) to observe temporal/supersession/immune/signature floors}
                            {--b721-immune-signature-floors-contract= : JSON file (any object) to observe immune/signature floors}
                            {--b722-surprise-gate-floors-contract= : JSON file (any object) to observe surprise/gate floors}
                            {--b723-acos-evolution-floors-contract= : JSON file (any object) to observe acos/evolution floors}
                            {--b724-immune-signature-floors-contract= : JSON file (any object) to observe immune/signature floors}
                            {--b725-immune-classifier-floors-contract= : JSON file (any object) to observe immune/classifier floors}
                            {--b726-acos-rollback-floors-contract= : JSON file (any object) to observe acos/rollback floors}
                            {--b727-immune-check-floors-contract= : JSON file (any object) to observe immune/check floors}
                            {--b728-frontier-wave-floors-contract= : JSON file (any object) to observe frontier/wave floors}
                            {--b729-cognition-evidence-floors-contract= : JSON file (any object) to observe cognition/evidence floors}
                            {--b730-capture-hmac-floors-contract= : JSON file (any object) to observe capture/hmac floors}
                            {--b731-acos-window-floors-contract= : JSON file (any object) to observe acos/window floors}
                            {--b732-context-nudge-floors-contract= : JSON file (any object) to observe context/nudge floors}
                            {--b733-operational-volume-floors-contract= : JSON file (any object) to observe operational/volume floors}
                            {--b734-immune-verdict-floors-contract= : JSON file (any object) to observe immune/verdict floors}
                            {--b735-numeric-range-cognitive-function-floors-contract= : JSON file (any object) to observe numeric/range/cognitive/function floors}
                            {--b736-cognitive-function-floors-contract= : JSON file (any object) to observe cognitive/function floors}
                            {--b737-immune-calibration-floors-contract= : JSON file (any object) to observe immune/calibration floors}
                            {--b738-cognition-remint-floors-contract= : JSON file (any object) to observe cognition/remint floors}
                            {--b739-immune-hybrid-floors-contract= : JSON file (any object) to observe immune/hybrid floors}
                            {--b740-immune-signature-cognitive-function-floors-contract= : JSON file (any object) to observe immune/signature/cognitive/function floors}
                            {--b741-cognitive-function-floors-contract= : JSON file (any object) to observe cognitive/function floors}
                            {--b742-watchdog-runner-floors-contract= : JSON file (any object) to observe watchdog/runner floors}
                            {--b743-watchdog-check-acos-floors-contract= : JSON file (any object) to observe watchdog/check/acos floors}
                            {--b744-acos-watchdog-floors-contract= : JSON file (any object) to observe acos/watchdog floors}
                            {--b745-watchdog-check-health-report-floors-contract= : JSON file (any object) to observe watchdog/check/health/report floors}
                            {--b746-health-report-floors-contract= : JSON file (any object) to observe health/report floors}
                            {--b747-daily-canary-floors-contract= : JSON file (any object) to observe daily/canary floors}
                            {--b748-acos-dead-floors-contract= : JSON file (any object) to observe acos/dead floors}
                            {--b749-operator-learning-aobg-latency-floors-contract= : JSON file (any object) to observe operator/learning/aobg/latency floors}
                            {--b750-aobg-latency-floors-contract= : JSON file (any object) to observe aobg/latency floors}
                            {--b751-substrate-restore-floors-contract= : JSON file (any object) to observe substrate/restore floors}
                            {--b752-compaction-recovery-disk-free-floors-contract= : JSON file (any object) to observe compaction/recovery/disk/free floors}
                            {--b753-disk-free-floors-contract= : JSON file (any object) to observe disk/free floors}
                            {--b754-joint-resource-floors-contract= : JSON file (any object) to observe joint/resource floors}
                            {--b755-provider-bound-floors-contract= : JSON file (any object) to observe provider/bound floors}
                            {--b756-autonomy-ladder-floors-contract= : JSON file (any object) to observe autonomy/ladder floors}
                            {--b757-local-model-evidence-ledger-floors-contract= : JSON file (any object) to observe local/model/evidence/ledger floors}
                            {--b758-evidence-ledger-floors-contract= : JSON file (any object) to observe evidence/ledger floors}
                            {--b759-operator-review-aaeos-doc-floors-contract= : JSON file (any object) to observe operator/review/aaeos/doc floors}
                            {--b760-aaeos-doc-floors-contract= : JSON file (any object) to observe aaeos/doc floors}
                            {--b761-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b762-department-level-floors-contract= : JSON file (any object) to observe department/level floors}
                            {--b763-debug-root-cross-department-floors-contract= : JSON file (any object) to observe debug/root/cross/department floors}
                            {--b764-cross-department-floors-contract= : JSON file (any object) to observe cross/department floors}
                            {--b765-docs-authority-floors-contract= : JSON file (any object) to observe docs/authority floors}
                            {--b766-aaeos-gate-floors-contract= : JSON file (any object) to observe aaeos/gate floors}
                            {--b767-aaeos-evidence-veto-propagation-implementation-floors-contract= : JSON file (any object) to observe aaeos/evidence/veto/propagation/implementation floors}
                            {--b768-veto-propagation-aaeos-implementation-floors-contract= : JSON file (any object) to observe veto/propagation/aaeos/implementation floors}
                            {--b769-aaeos-implementation-floors-contract= : JSON file (any object) to observe aaeos/implementation floors}
                            {--b770-aaeos-cognitive-floors-contract= : JSON file (any object) to observe aaeos/cognitive floors}
                            {--b771-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b772-aaeos-phase-floors-contract= : JSON file (any object) to observe aaeos/phase floors}
                            {--b773-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b774-aaeos-threshold-test-floors-contract= : JSON file (any object) to observe aaeos/threshold/test floors}
                            {--b775-aaeos-test-floors-contract= : JSON file (any object) to observe aaeos/test floors}
                            {--b776-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
                            {--b777-aaeos-threshold-string-veto-floors-contract= : JSON file (any object) to observe aaeos/threshold/string/veto floors}
                            {--b778-aaeos-string-veto-floors-contract= : JSON file (any object) to observe aaeos/string/veto floors}
                            {--b779-aaeos-veto-floors-contract= : JSON file (any object) to observe aaeos/veto floors}
                            {--b780-aaeos-department-floors-contract= : JSON file (any object) to observe aaeos/department floors}
        {--json : Machine-readable JSON output}';

    protected $description = '[ADVANCED observe floors — prefer atlas:aaeos:run for daily; see atlas-cli-daily-map] Atlas Agentic Engineering OS — operator CLI for the 17-phase runbook.';

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
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->info('AAEOS HTTP Path · '.$configured);
        $this->line('  facade_active: '.(YesNo::format($payload['facade_active'])));
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
        $signals = AaeosUniversalGatesJsonObserveSupport::loadSignalsFromPath((string) ($this->option('signals') ?? ''));
        $snapshot = $cockpit->snapshot(
            intentId: $intent,
            phaseEnvelopes: [],
            gateSignals: $signals,
            autonomyLevel: (string) $this->option('autonomy'),
        );
        $this->emit($snapshot, $json);

        return self::SUCCESS;
    }

    /**
     * Observe-only optional JSON projectors for universal-gates (full-pass density table).
     *
     * @return list<array{0: string, 1: string, 2: callable(array<string,mixed>):mixed}>
     */
    private function universalGatesObserveProjectors(AtlasUniversalGatesEvaluator $gates): array
    {
        return AaeosUniversalGatesObserveProjectors::for($gates);
    }

    private function universalGates(AtlasUniversalGatesEvaluator $gates, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('universal-gates requires --intent');
        }
        $signals = AaeosUniversalGatesJsonObserveSupport::loadSignalsFromPath((string) ($this->option('signals') ?? ''));
        $deliveryPackPath = (string) ($this->option('delivery-pack') ?? '');
        if ($deliveryPackPath !== '') {
            $composition = AaeosUniversalGatesJsonObserveSupport::loadJsonFile($deliveryPackPath);
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
            $spec = AaeosUniversalGatesJsonObserveSupport::loadJsonFile($specPath);
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
        foreach ($this->universalGatesObserveProjectors($gates) as [$option, $observeKey, $projector]) {
            [$report, $failReason] = AaeosUniversalGatesJsonObserveSupport::appendOptionalJsonObserve(
                $report,
                (string) ($this->option($option) ?? ''),
                $observeKey,
                $projector,
                $option,
            );
            if ($failReason !== null) {
                return $this->failWith($failReason);
            }
        }

        $this->emit($report, $json);

        return $report['outcome'] === 'green' || $report['outcome'] === 'exception' ? self::SUCCESS : self::FAILURE;
    }

    private function emit(mixed $payload, bool $json): void
    {
        // --json is currently the same pretty provider-safe encoding; flag kept
        // for callers/docs that already pass it.
        unset($json);
        $this->line($this->encode($payload));
    }

    private function failWith(string $reason): int
    {
        $this->error($reason);

        return self::FAILURE;
    }
}
