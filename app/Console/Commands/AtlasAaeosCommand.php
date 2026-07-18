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
            ['volume-autonomy-coverage-unknown-floors-contract', 'volume_autonomy_coverage_unknown_floors_contract', fn (array $p) => $gates->volumeAutonomyCoverageUnknownFloorsContractObserve($p)],
            ['watchdog-health-active-disabled-floors-contract', 'watchdog_health_active_disabled_floors_contract', fn (array $p) => $gates->watchdogHealthActiveDisabledFloorsContractObserve($p)],
            ['embedding-pending-mission-outcome-floors-contract', 'embedding_pending_mission_outcome_floors_contract', fn (array $p) => $gates->embeddingPendingMissionOutcomeFloorsContractObserve($p)],
            ['local-model-embedding-immune-unavailable-floors-contract', 'local_model_embedding_immune_unavailable_floors_contract', fn (array $p) => $gates->localModelEmbeddingImmuneUnavailableFloorsContractObserve($p)],
            ['prereview-parallel-flywheel-frontier-floors-contract', 'prereview_parallel_flywheel_frontier_floors_contract', fn (array $p) => $gates->prereviewParallelFlywheelFrontierFloorsContractObserve($p)],
            ['obra-portfolio-pareto-blocked-floors-contract', 'obra_portfolio_pareto_blocked_floors_contract', fn (array $p) => $gates->obraPortfolioParetoBlockedFloorsContractObserve($p)],
            ['corpus-parallel-truth-blocked-floors-contract', 'corpus_parallel_truth_blocked_floors_contract', fn (array $p) => $gates->corpusParallelTruthBlockedFloorsContractObserve($p)],
            ['teto10-cockpit-ladder-promotion-floors-contract', 'teto10_cockpit_ladder_promotion_floors_contract', fn (array $p) => $gates->teto10CockpitLadderPromotionFloorsContractObserve($p)],
            ['dead-series-miner-signature-adapter-floors-contract', 'dead_series_miner_signature_adapter_floors_contract', fn (array $p) => $gates->deadSeriesMinerSignatureAdapterFloorsContractObserve($p)],
            ['ragx-prereview-lote2-schema-floors-contract', 'ragx_prereview_lote2_schema_floors_contract', fn (array $p) => $gates->ragxPrereviewLote2SchemaFloorsContractObserve($p)],
            ['window-gates-integrity-flag-disabled-floors-contract', 'window_gates_integrity_flag_disabled_floors_contract', fn (array $p) => $gates->windowGatesIntegrityFlagDisabledFloorsContractObserve($p)],
            ['obra-verified-long-horizon-enabled-floors-contract', 'obra_verified_long_horizon_enabled_floors_contract', fn (array $p) => $gates->obraVerifiedLongHorizonEnabledFloorsContractObserve($p)],
            ['embedding-table-fixture-measured-floors-contract', 'embedding_table_fixture_measured_floors_contract', fn (array $p) => $gates->embeddingTableFixtureMeasuredFloorsContractObserve($p)],
            ['conflict-frontier-fixture-pending-floors-contract', 'conflict_frontier_fixture_pending_floors_contract', fn (array $p) => $gates->conflictFrontierFixturePendingFloorsContractObserve($p)],
            ['queued-passed-advisory-absent-floors-contract', 'queued_passed_advisory_absent_floors_contract', fn (array $p) => $gates->queuedPassedAdvisoryAbsentFloorsContractObserve($p)],
            ['ran-accepted-keep-fixture-floors-contract', 'ran_accepted_keep_fixture_floors_contract', fn (array $p) => $gates->ranAcceptedKeepFixtureFloorsContractObserve($p)],
            ['immune-class-chunks-hmac-floors-contract', 'immune_class_chunks_hmac_floors_contract', fn (array $p) => $gates->immuneClassChunksHmacFloorsContractObserve($p)],
            ['rotation-measure-series-floors-contract', 'rotation_measure_series_floors_contract', fn (array $p) => $gates->rotationMeasureSeriesFloorsContractObserve($p)],
            ['promotion-lote2-measure-floors-contract', 'promotion_lote2_measure_floors_contract', fn (array $p) => $gates->promotionLote2MeasureFloorsContractObserve($p)],
            ['department-contract-maturity-floors-contract', 'department_contract_maturity_floors_contract', fn (array $p) => $gates->departmentContractMaturityFloorsContractObserve($p)],
            ['quality-veto-evolution-floors-contract', 'quality_veto_evolution_floors_contract', fn (array $p) => $gates->qualityVetoEvolutionFloorsContractObserve($p)],
            ['watchdog-immune-ragx-floors-contract', 'watchdog_immune_ragx_floors_contract', fn (array $p) => $gates->watchdogImmuneRagxFloorsContractObserve($p)],
            ['obra-evidence-http-floors-contract', 'obra_evidence_http_floors_contract', fn (array $p) => $gates->obraEvidenceHttpFloorsContractObserve($p)],
            ['teto-cognitive-hmac-floors-contract', 'teto_cognitive_hmac_floors_contract', fn (array $p) => $gates->tetoCognitiveHmacFloorsContractObserve($p)],
            ['promotion-asef-autonomy-floors-contract', 'promotion_asef_autonomy_floors_contract', fn (array $p) => $gates->promotionAsefAutonomyFloorsContractObserve($p)],
            ['longhorizon-window-aemor-floors-contract', 'longhorizon_window_aemor_floors_contract', fn (array $p) => $gates->longhorizonWindowAemorFloorsContractObserve($p)],
            ['test-immune-truth-floors-contract', 'test_immune_truth_floors_contract', fn (array $p) => $gates->testImmuneTruthFloorsContractObserve($p)],
            ['model-causality-skill-floors-contract', 'model_causality_skill_floors_contract', fn (array $p) => $gates->modelCausalitySkillFloorsContractObserve($p)],
            ['choreography-hybrid-dev-floors-contract', 'choreography_hybrid_dev_floors_contract', fn (array $p) => $gates->choreographyHybridDevFloorsContractObserve($p)],
            ['compounding-scorecard-canary-floors-contract', 'compounding_scorecard_canary_floors_contract', fn (array $p) => $gates->compoundingScorecardCanaryFloorsContractObserve($p)],
            ['obra-lote2-health-floors-contract', 'obra_lote2_health_floors_contract', fn (array $p) => $gates->obraLote2HealthFloorsContractObserve($p)],
            ['volume-cockpit-rollback-floors-contract', 'volume_cockpit_rollback_floors_contract', fn (array $p) => $gates->volumeCockpitRollbackFloorsContractObserve($p)],
            ['arc-segment-window-floors-contract', 'arc_segment_window_floors_contract', fn (array $p) => $gates->arcSegmentWindowFloorsContractObserve($p)],
            ['department-integrity-capture-floors-contract', 'department_integrity_capture_floors_contract', fn (array $p) => $gates->departmentIntegrityCaptureFloorsContractObserve($p)],
            ['asef-calibration-jina-floors-contract', 'asef_calibration_jina_floors_contract', fn (array $p) => $gates->asefCalibrationJinaFloorsContractObserve($p)],
            ['ledger-counterfactual-advisory-floors-contract', 'ledger_counterfactual_advisory_floors_contract', fn (array $p) => $gates->ledgerCounterfactualAdvisoryFloorsContractObserve($p)],
            ['verified-frontier-cooccurrence-floors-contract', 'verified_frontier_cooccurrence_floors_contract', fn (array $p) => $gates->verifiedFrontierCooccurrenceFloorsContractObserve($p)],
            ['docs-handoff-adversarial-floors-contract', 'docs_handoff_adversarial_floors_contract', fn (array $p) => $gates->docsHandoffAdversarialFloorsContractObserve($p)],
            ['immune-ragx-scorecard-floors-contract', 'immune_ragx_scorecard_floors_contract', fn (array $p) => $gates->immuneRagxScorecardFloorsContractObserve($p)],
            ['http-thesis-lote2-floors-contract', 'http_thesis_lote2_floors_contract', fn (array $p) => $gates->httpThesisLote2FloorsContractObserve($p)],
            ['longhorizon-watchdog-promotion-floors-contract', 'longhorizon_watchdog_promotion_floors_contract', fn (array $p) => $gates->longhorizonWatchdogPromotionFloorsContractObserve($p)],
            ['esp09-lote2-hmac-floors-contract', 'esp09_lote2_hmac_floors_contract', fn (array $p) => $gates->esp09Lote2HmacFloorsContractObserve($p)],
            ['phase-obra-bets-floors-contract', 'phase_obra_bets_floors_contract', fn (array $p) => $gates->phaseObraBetsFloorsContractObserve($p)],
            ['parallel-truth-autonomy-floors-contract', 'parallel_truth_autonomy_floors_contract', fn (array $p) => $gates->parallelTruthAutonomyFloorsContractObserve($p)],
            ['obra-thesis-skill-floors-contract', 'obra_thesis_skill_floors_contract', fn (array $p) => $gates->obraThesisSkillFloorsContractObserve($p)],
            ['mission-promotion-outcome-floors-contract', 'mission_promotion_outcome_floors_contract', fn (array $p) => $gates->missionPromotionOutcomeFloorsContractObserve($p)],
            ['immune-rollback-remint-floors-contract', 'immune_rollback_remint_floors_contract', fn (array $p) => $gates->immuneRollbackRemintFloorsContractObserve($p)],
            ['scorecard-gate-test-floors-contract', 'scorecard_gate_test_floors_contract', fn (array $p) => $gates->scorecardGateTestFloorsContractObserve($p)],
            ['ncapture-immune-coverage-floors-contract', 'ncapture_immune_coverage_floors_contract', fn (array $p) => $gates->ncaptureImmuneCoverageFloorsContractObserve($p)],
            ['cockpit-canary-adversarial-floors-contract', 'cockpit_canary_adversarial_floors_contract', fn (array $p) => $gates->cockpitCanaryAdversarialFloorsContractObserve($p)],
            ['maturity-envelope-lifecycle-floors-contract', 'maturity_envelope_lifecycle_floors_contract', fn (array $p) => $gates->maturityEnvelopeLifecycleFloorsContractObserve($p)],
            ['embedding-coverage-thesis-floors-contract', 'embedding_coverage_thesis_floors_contract', fn (array $p) => $gates->embeddingCoverageThesisFloorsContractObserve($p)],
            ['dept-level-evidence-floors-contract', 'dept_level_evidence_floors_contract', fn (array $p) => $gates->deptLevelEvidenceFloorsContractObserve($p)],
            ['schema-decomposer-surprise-floors-contract', 'schema_decomposer_surprise_floors_contract', fn (array $p) => $gates->schemaDecomposerSurpriseFloorsContractObserve($p)],
            ['evidence-flywheel-budget-floors-contract', 'evidence_flywheel_budget_floors_contract', fn (array $p) => $gates->evidenceFlywheelBudgetFloorsContractObserve($p)],
            ['portfolio-impact-corpus-floors-contract', 'portfolio_impact_corpus_floors_contract', fn (array $p) => $gates->portfolioImpactCorpusFloorsContractObserve($p)],
            ['disk-deadseries-latency-floors-contract', 'disk_deadseries_latency_floors_contract', fn (array $p) => $gates->diskDeadseriesLatencyFloorsContractObserve($p)],
            ['memory-spec-dogfood-floors-contract', 'memory_spec_dogfood_floors_contract', fn (array $p) => $gates->memorySpecDogfoodFloorsContractObserve($p)],
            ['restore-redaction-recall-floors-contract', 'restore_redaction_recall_floors_contract', fn (array $p) => $gates->restoreRedactionRecallFloorsContractObserve($p)],
            ['runner-phase-saturation-floors-contract', 'runner_phase_saturation_floors_contract', fn (array $p) => $gates->runnerPhaseSaturationFloorsContractObserve($p)],
            ['immune-scorecard-segment-floors-contract', 'immune_scorecard_segment_floors_contract', fn (array $p) => $gates->immuneScorecardSegmentFloorsContractObserve($p)],
            ['advisory-teto-jina-floors-contract', 'advisory_teto_jina_floors_contract', fn (array $p) => $gates->advisoryTetoJinaFloorsContractObserve($p)],
            ['window-canary-flywheel-floors-contract', 'window_canary_flywheel_floors_contract', fn (array $p) => $gates->windowCanaryFlywheelFloorsContractObserve($p)],
            ['verified-coverage-choreography-floors-contract', 'verified_coverage_choreography_floors_contract', fn (array $p) => $gates->verifiedCoverageChoreographyFloorsContractObserve($p)],
            ['knowledge-decomposer-promoter-floors-contract', 'knowledge_decomposer_promoter_floors_contract', fn (array $p) => $gates->knowledgeDecomposerPromoterFloorsContractObserve($p)],
            ['ncapture-obra-truth-floors-contract', 'ncapture_obra_truth_floors_contract', fn (array $p) => $gates->ncaptureObraTruthFloorsContractObserve($p)],
            ['horizon-calibration-atlas-floors-contract', 'horizon_calibration_atlas_floors_contract', fn (array $p) => $gates->horizonCalibrationAtlasFloorsContractObserve($p)],
            ['decay-portfolio-spec-floors-contract', 'decay_portfolio_spec_floors_contract', fn (array $p) => $gates->decayPortfolioSpecFloorsContractObserve($p)],
            ['composed-promotion-ragx-floors-contract', 'composed_promotion_ragx_floors_contract', fn (array $p) => $gates->composedPromotionRagxFloorsContractObserve($p)],
            ['http-cockpit-facade-floors-contract', 'http_cockpit_facade_floors_contract', fn (array $p) => $gates->httpCockpitFacadeFloorsContractObserve($p)],
            ['ncapture-promoter-jina-floors-contract', 'ncapture_promoter_jina_floors_contract', fn (array $p) => $gates->ncapturePromoterJinaFloorsContractObserve($p)],
            ['truth-obra-thesis-floors-contract', 'truth_obra_thesis_floors_contract', fn (array $p) => $gates->truthObraThesisFloorsContractObserve($p)],
            ['atlas-bets-verified-floors-contract', 'atlas_bets_verified_floors_contract', fn (array $p) => $gates->atlasBetsVerifiedFloorsContractObserve($p)],
            ['freeze-scorecard-eligibility-floors-contract', 'freeze_scorecard_eligibility_floors_contract', fn (array $p) => $gates->freezeScorecardEligibilityFloorsContractObserve($p)],
            ['drill-skill-dualread-floors-contract', 'drill_skill_dualread_floors_contract', fn (array $p) => $gates->drillSkillDualreadFloorsContractObserve($p)],
            ['envelope-cockpit-facade-floors-contract', 'envelope_cockpit_facade_floors_contract', fn (array $p) => $gates->envelopeCockpitFacadeFloorsContractObserve($p)],
            ['atlas-composed-ragx-floors-contract', 'atlas_composed_ragx_floors_contract', fn (array $p) => $gates->atlasComposedRagxFloorsContractObserve($p)],
            ['schema-aemor-lifecycle-floors-contract', 'schema_aemor_lifecycle_floors_contract', fn (array $p) => $gates->schemaAemorLifecycleFloorsContractObserve($p)],
            ['dept-quality-evidence-floors-contract', 'dept_quality_evidence_floors_contract', fn (array $p) => $gates->deptQualityEvidenceFloorsContractObserve($p)],
            ['truth-immune-veto-floors-contract', 'truth_immune_veto_floors_contract', fn (array $p) => $gates->truthImmuneVetoFloorsContractObserve($p)],
            ['dead-series-outcome-compounding-floors-contract', 'dead_series_outcome_compounding_floors_contract', fn (array $p) => $gates->deadSeriesOutcomeCompoundingFloorsContractObserve($p)],
            ['immune-integrity-obra-floors-contract', 'immune_integrity_obra_floors_contract', fn (array $p) => $gates->immuneIntegrityObraFloorsContractObserve($p)],
            ['teto-atlas-longhorizon-floors-contract', 'teto_atlas_longhorizon_floors_contract', fn (array $p) => $gates->tetoAtlasLonghorizonFloorsContractObserve($p)],
            ['watchdog-impact-budget-floors-contract', 'watchdog_impact_budget_floors_contract', fn (array $p) => $gates->watchdogImpactBudgetFloorsContractObserve($p)],
            ['longhorizon-lote2-adversarial-floors-contract', 'longhorizon_lote2_adversarial_floors_contract', fn (array $p) => $gates->longhorizonLote2AdversarialFloorsContractObserve($p)],
            ['immune-window-evidence-floors-contract', 'immune_window_evidence_floors_contract', fn (array $p) => $gates->immuneWindowEvidenceFloorsContractObserve($p)],
            ['health-canary-maxa04-floors-contract', 'health_canary_maxa04_floors_contract', fn (array $p) => $gates->healthCanaryMaxa04FloorsContractObserve($p)],
            ['joint-lote2-horizon-floors-contract', 'joint_lote2_horizon_floors_contract', fn (array $p) => $gates->jointLote2HorizonFloorsContractObserve($p)],
            ['threshold-http-immune-floors-contract', 'threshold_http_immune_floors_contract', fn (array $p) => $gates->thresholdHttpImmuneFloorsContractObserve($p)],
            ['ncapture-asef-spec-floors-contract', 'ncapture_asef_spec_floors_contract', fn (array $p) => $gates->ncaptureAsefSpecFloorsContractObserve($p)],
            ['execution-quality-immune-floors-contract', 'execution_quality_immune_floors_contract', fn (array $p) => $gates->executionQualityImmuneFloorsContractObserve($p)],
            ['health-lote2-horizon-residual-floors-contract', 'health_lote2_horizon_residual_floors_contract', fn (array $p) => $gates->healthLote2HorizonResidualFloorsContractObserve($p)],
            ['health-lote2-horizon-depth-floors-contract', 'health_lote2_horizon_depth_floors_contract', fn (array $p) => $gates->healthLote2HorizonDepthFloorsContractObserve($p)],
            ['runbook-immune-promoter-floors-contract', 'runbook_immune_promoter_floors_contract', fn (array $p) => $gates->runbookImmunePromoterFloorsContractObserve($p)],
            ['lexical-envelope-cockpit-floors-contract', 'lexical_envelope_cockpit_floors_contract', fn (array $p) => $gates->lexicalEnvelopeCockpitFloorsContractObserve($p)],
            ['ncapture-scorecard-esp09-floors-contract', 'ncapture_scorecard_esp09_floors_contract', fn (array $p) => $gates->ncaptureScorecardEsp09FloorsContractObserve($p)],
            ['flywheel-immune-obra-floors-contract', 'flywheel_immune_obra_floors_contract', fn (array $p) => $gates->flywheelImmuneObraFloorsContractObserve($p)],
            ['health-lote2-runbook-floors-contract', 'health_lote2_runbook_floors_contract', fn (array $p) => $gates->healthLote2RunbookFloorsContractObserve($p)],
            ['health-lote2-horizon-more-floors-contract', 'health_lote2_horizon_more_floors_contract', fn (array $p) => $gates->healthLote2HorizonMoreFloorsContractObserve($p)],
            ['health-deferred-runner-runbook-golden-floors-contract', 'health_deferred_runner_runbook_golden_floors_contract', fn (array $p) => $gates->healthDeferredRunnerRunbookGoldenFloorsContractObserve($p)],
            ['lexical-rerank-maturity-budget-volume-immune-floors-contract', 'lexical_rerank_maturity_budget_volume_immune_floors_contract', fn (array $p) => $gates->lexicalRerankMaturityBudgetVolumeImmuneFloorsContractObserve($p)],
            ['decomposer-evidence-teto-fact-ragx-golden-floors-contract', 'decomposer_evidence_teto_fact_ragx_golden_floors_contract', fn (array $p) => $gates->decomposerEvidenceTetoFactRagxGoldenFloorsContractObserve($p)],
            ['envelope-integrity-promoter-series-lote2-lexical-substrate-bets-floors-contract', 'envelope_integrity_promoter_series_lote2_lexical_substrate_bets_floors_contract', fn (array $p) => $gates->envelopeIntegrityPromoterSeriesLote2LexicalSubstrateBetsFloorsContractObserve($p)],
            ['pareto-window-cockpit-obra-ambition-dead-scorecard-vision-esp09-floors-contract', 'pareto_window_cockpit_obra_ambition_dead_scorecard_vision_esp09_floors_contract', fn (array $p) => $gates->paretoWindowCockpitObraAmbitionDeadScorecardVisionEsp09FloorsContractObserve($p)],
            ['evolution-reality-freshness-nudge-immune-share-flywheel-aemor-quality-floors-contract', 'evolution_reality_freshness_nudge_immune_share_flywheel_aemor_quality_floors_contract', fn (array $p) => $gates->evolutionRealityFreshnessNudgeImmuneShareFlywheelAemorQualityFloorsContractObserve($p)],
            ['promotion-parallel-docs-reality-evidence-repair-architect-delivery-scorecard-floors-contract', 'promotion_parallel_docs_reality_evidence_repair_architect_delivery_scorecard_floors_contract', fn (array $p) => $gates->promotionParallelDocsRealityEvidenceRepairArchitectDeliveryScorecardFloorsContractObserve($p)],
            ['integrity-architect-veto-bets-promotion-freeze-compounding-resolver-budget-floors-contract', 'integrity_architect_veto_bets_promotion_freeze_compounding_resolver_budget_floors_contract', fn (array $p) => $gates->integrityArchitectVetoBetsPromotionFreezeCompoundingResolverBudgetFloorsContractObserve($p)],
            ['architect-rollback-ledger-work-substrate-decay-dod-capability-maturity-floors-contract', 'architect_rollback_ledger_work_substrate_decay_dod_capability_maturity_floors_contract', fn (array $p) => $gates->architectRollbackLedgerWorkSubstrateDecayDodCapabilityMaturityFloorsContractObserve($p)],
            ['delivery-immune-registry-operator-lote2-health-horizon-promoter-floors-contract', 'delivery_immune_registry_operator_lote2_health_horizon_promoter_floors_contract', fn (array $p) => $gates->deliveryImmuneRegistryOperatorLote2HealthHorizonPromoterFloorsContractObserve($p)],
            ['lote2-health-horizon-promoter-capture-obra-dual-truth-vision-floors-contract', 'lote2_health_horizon_promoter_capture_obra_dual_truth_vision_floors_contract', fn (array $p) => $gates->lote2HealthHorizonPromoterCaptureObraDualTruthVisionFloorsContractObserve($p)],
            ['registry-spec-summary-memory-segment-pareto-recall-outcome-floors-contract', 'registry_spec_summary_memory_segment_pareto_recall_outcome_floors_contract', fn (array $p) => $gates->registrySpecSummaryMemorySegmentParetoRecallOutcomeFloorsContractObserve($p)],
            ['impact-advisory-esp09-dogfood-saturation-budget-ambition-asef-lexical-floors-contract', 'impact_advisory_esp09_dogfood_saturation_budget_ambition_asef_lexical_floors_contract', fn (array $p) => $gates->impactAdvisoryEsp09DogfoodSaturationBudgetAmbitionAsefLexicalFloorsContractObserve($p)],
            ['spec-summary-budget-decay-segment-pareto-recall-outcome-corpus-floors-contract', 'spec_summary_budget_decay_segment_pareto_recall_outcome_corpus_floors_contract', fn (array $p) => $gates->specSummaryBudgetDecaySegmentParetoRecallOutcomeCorpusFloorsContractObserve($p)],
            ['fact-citation-provenance-recall-cascade-vision-cooccur-gate-dispatch-floors-contract', 'fact_citation_provenance_recall_cascade_vision_cooccur_gate_dispatch_floors_contract', fn (array $p) => $gates->factCitationProvenanceRecallCascadeVisionCooccurGateDispatchFloorsContractObserve($p)],
            ['summary-budget-decay-segment-pareto-recall-outcome-impact-advisory-floors-contract', 'summary_budget_decay_segment_pareto_recall_outcome_impact_advisory_floors_contract', fn (array $p) => $gates->summaryBudgetDecaySegmentParetoRecallOutcomeImpactAdvisoryFloorsContractObserve($p)],
            ['esp09-dogfood-saturation-budget-ambition-lexical-corpus-fact-citation-floors-contract', 'esp09_dogfood_saturation_budget_ambition_lexical_corpus_fact_citation_floors_contract', fn (array $p) => $gates->esp09DogfoodSaturationBudgetAmbitionLexicalCorpusFactCitationFloorsContractObserve($p)],
            ['teto-ragx-promotion-envelope-golden-bets-thesis-attempt-cockpit-floors-contract', 'teto_ragx_promotion_envelope_golden_bets_thesis_attempt_cockpit_floors_contract', fn (array $p) => $gates->tetoRagxPromotionEnvelopeGoldenBetsThesisAttemptCockpitFloorsContractObserve($p)],
            ['veto-repair-phase-truth-ledger-canary-latency-dual-budget-floors-contract', 'veto_repair_phase_truth_ledger_canary_latency_dual_budget_floors_contract', fn (array $p) => $gates->vetoRepairPhaseTruthLedgerCanaryLatencyDualBudgetFloorsContractObserve($p)],
            ['joint-autonomy-dead-runner-envelope-obra-docs-floors-contract', 'joint_autonomy_dead_runner_envelope_obra_docs_floors_contract', fn (array $p) => $gates->jointAutonomyDeadRunnerEnvelopeObraDocsFloorsContractObserve($p)],
            ['health-ingest-derive-calib-dispatch-prov-cooccur-vision-cascade-floors-contract', 'health_ingest_derive_calib_dispatch_prov_cooccur_vision_cascade_floors_contract', fn (array $p) => $gates->healthIngestDeriveCalibDispatchProvCooccurVisionCascadeFloorsContractObserve($p)],
            ['promo-immune-nudge-hmac-runbook-qbar-phase-dept-ncapture-floors-contract', 'promo_immune_nudge_hmac_runbook_qbar_phase_dept_ncapture_floors_contract', fn (array $p) => $gates->promoImmuneNudgeHmacRunbookQbarPhaseDeptNcaptureFloorsContractObserve($p)],
            ['volume-sig-hybrid-delivery-autowork-mission-http-impact-floors-contract', 'volume_sig_hybrid_delivery_autowork_mission_http_impact_floors_contract', fn (array $p) => $gates->volumeSigHybridDeliveryAutoworkMissionHttpImpactFloorsContractObserve($p)],
            ['frontier-rerank-fabric-decomp-specpack-handoff-envelope-blocker-advisory-floors-contract', 'frontier_rerank_fabric_decomp_specpack_handoff_envelope_blocker_advisory_floors_contract', fn (array $p) => $gates->frontierRerankFabricDecompSpecpackHandoffEnvelopeBlockerAdvisoryFloorsContractObserve($p)],
            ['integrity-promo-share-thesis-atlas-promo-flywheel-golden-ambition-floors-contract', 'integrity_promo_share_thesis_atlas_promo_flywheel_golden_ambition_floors_contract', fn (array $p) => $gates->integrityPromoShareThesisAtlasPromoFlywheelGoldenAmbitionFloorsContractObserve($p)],
            ['ledger-disk-latency-teto-ragx-envelope-fidelity-segment-causality-floors-contract', 'ledger_disk_latency_teto_ragx_envelope_fidelity_segment_causality_floors_contract', fn (array $p) => $gates->ledgerDiskLatencyTetoRagxEnvelopeFidelitySegmentCausalityFloorsContractObserve($p)],
            ['autonomy-watchdog-scorecard-maxa-corpus-esp09-budget-recall-veto-floors-contract', 'autonomy_watchdog_scorecard_maxa_corpus_esp09_budget_recall_veto_floors_contract', fn (array $p) => $gates->autonomyWatchdogScorecardMaxaCorpusEsp09BudgetRecallVetoFloorsContractObserve($p)],
            ['health-immune-calib-deferred-cooccur-thesis-lexical-repair-docs-floors-contract', 'health_immune_calib_deferred_cooccur_thesis_lexical_repair_docs_floors_contract', fn (array $p) => $gates->healthImmuneCalibDeferredCooccurThesisLexicalRepairDocsFloorsContractObserve($p)],
            ['dept-immune-nudge-runbook-quality-dev-compound-obra-floors-contract', 'dept_immune_nudge_runbook_quality_dev_compound_obra_floors_contract', fn (array $p) => $gates->deptImmuneNudgeRunbookQualityDevCompoundObraFloorsContractObserve($p)],
            ['volume-immune-scorecard-phase-delivery-autowork-citation-cascade-budget-floors-contract', 'volume_immune_scorecard_phase_delivery_autowork_citation_cascade_budget_floors_contract', fn (array $p) => $gates->volumeImmuneScorecardPhaseDeliveryAutoworkCitationCascadeBudgetFloorsContractObserve($p)],
            ['frontier-rerank-fabric-cockpit-http-specpack-advisory-ncapture-model-floors-contract', 'frontier_rerank_fabric_cockpit_http_specpack_advisory_ncapture_model_floors_contract', fn (array $p) => $gates->frontierRerankFabricCockpitHttpSpecpackAdvisoryNcaptureModelFloorsContractObserve($p)],
            ['texec-obra-evo-window-rollback-maturity-embed-horizon-floors-contract', 'texec_obra_evo_window_rollback_maturity_embed_horizon_floors_contract', fn (array $p) => $gates->texecObraEvoWindowRollbackMaturityEmbedHorizonFloorsContractObserve($p)],
            ['maturity-attempt-scorecard-http-qbar-compound-immune-phase-dept-floors-contract', 'maturity_attempt_scorecard_http_qbar_compound_immune_phase_dept_floors_contract', fn (array $p) => $gates->maturityAttemptScorecardHttpQbarCompoundImmunePhaseDeptFloorsContractObserve($p)],
            ['gate-signal-truth-router-veto-choreo-debug-docs-watchdog-pareto-floors-contract', 'gate_signal_truth_router_veto_choreo_debug_docs_watchdog_pareto_floors_contract', fn (array $p) => $gates->gateSignalTruthRouterVetoChoreoDebugDocsWatchdogParetoFloorsContractObserve($p)],
            ['immune-freeze-outcome-window-flywheel-promo-calib-handoff-runbook-floors-contract', 'immune_freeze_outcome_window_flywheel_promo_calib_handoff_runbook_floors_contract', fn (array $p) => $gates->immuneFreezeOutcomeWindowFlywheelPromoCalibHandoffRunbookFloorsContractObserve($p)],
            ['verdict-dept-debt-canary-asef-freshness-reality-list-schema-floors-contract', 'verdict_dept_debt_canary_asef_freshness_reality_list_schema_floors_contract', fn (array $p) => $gates->verdictDeptDebtCanaryAsefFreshnessRealityListSchemaFloorsContractObserve($p)],
            ['compaction-redaction-capture-provenance-impact-bets-maturity-claim-generated-floors-contract', 'compaction_redaction_capture_provenance_impact_bets_maturity_claim_generated_floors_contract', fn (array $p) => $gates->compactionRedactionCaptureProvenanceImpactBetsMaturityClaimGeneratedFloorsContractObserve($p)],
            ['promo-handoff-blocker-protocol-replay-thesis-integrity-budget-deriver-floors-contract', 'promo_handoff_blocker_protocol_replay_thesis_integrity_budget_deriver_floors_contract', fn (array $p) => $gates->promoHandoffBlockerProtocolReplayThesisIntegrityBudgetDeriverFloorsContractObserve($p)],
            ['segment-fidelity-causality-teto-ragx-envelope-latency-watchdog-hybrid-floors-contract', 'segment_fidelity_causality_teto_ragx_envelope_latency_watchdog_hybrid_floors_contract', fn (array $p) => $gates->segmentFidelityCausalityTetoRagxEnvelopeLatencyWatchdogHybridFloorsContractObserve($p)],
            ['memory-budget-recall-maxa-corpus-esp09-dogfood-autonomy-runner-freeze-floors-contract', 'memory_budget_recall_maxa_corpus_esp09_dogfood_autonomy_runner_freeze_floors_contract', fn (array $p) => $gates->memoryBudgetRecallMaxaCorpusEsp09DogfoodAutonomyRunnerFreezeFloorsContractObserve($p)],
            ['repair-parallel-promoter-verified-cockpit-deferred-window-remint-scorecard-floors-contract', 'repair_parallel_promoter_verified_cockpit_deferred_window_remint_scorecard_floors_contract', fn (array $p) => $gates->repairParallelPromoterVerifiedCockpitDeferredWindowRemintScorecardFloorsContractObserve($p)],
            ['obra-dept-nudge-aemor-ambition-flywheel-quality-runbook-function-floors-contract', 'obra_dept_nudge_aemor_ambition_flywheel_quality_runbook_function_floors_contract', fn (array $p) => $gates->obraDeptNudgeAemorAmbitionFlywheelQualityRunbookFunctionFloorsContractObserve($p)],
            ['delivery-pack-resource-budget-belief-cascade-citation-grounding-floors-contract', 'delivery_pack_resource_budget_belief_cascade_citation_grounding_floors_contract', fn (array $p) => $gates->deliveryPackResourceBudgetBeliefCascadeCitationGroundingFloorsContractObserve($p)],
            ['n-capture-domain-lexical-evidence-vision-execution-context-floors-contract', 'n_capture_domain_lexical_evidence_vision_execution_context_floors_contract', fn (array $p) => $gates->nCaptureDomainLexicalEvidenceVisionExecutionContextFloorsContractObserve($p)],
            ['obra-retro-acos-rollback-window-orchestrator-long-aaeos-floors-contract', 'obra_retro_acos_rollback_window_orchestrator_long_aaeos_floors_contract', fn (array $p) => $gates->obraRetroAcosRollbackWindowOrchestratorLongAaeosFloorsContractObserve($p)],
            ['http-path-cognition-score-department-level-aaeos-doc-floors-contract', 'http_path_cognition_score_department_level_aaeos_doc_floors_contract', fn (array $p) => $gates->httpPathCognitionScoreDepartmentLevelAaeosDocFloorsContractObserve($p)],
            ['aaeos-implementation-context-pareto-gate-phase-immune-calibration-floors-contract', 'aaeos_implementation_context_pareto_gate_phase_immune_calibration_floors_contract', fn (array $p) => $gates->aaeosImplementationContextParetoGatePhaseImmuneCalibrationFloorsContractObserve($p)],
            ['aaeos-cognitive-implementation-veto-cross-department-lote-measure-floors-contract', 'aaeos_cognitive_implementation_veto_cross_department_lote_measure_floors_contract', fn (array $p) => $gates->aaeosCognitiveImplementationVetoCrossDepartmentLoteMeasureFloorsContractObserve($p)],
            ['aaeos-department-string-debug-root-docs-authority-daily-floors-contract', 'aaeos_department_string_debug_root_docs_authority_daily_floors_contract', fn (array $p) => $gates->aaeosDepartmentStringDebugRootDocsAuthorityDailyFloorsContractObserve($p)],
            ['generated-contract-aaeos-claim-department-exploratory-bets-provenance-floors-contract', 'generated_contract_aaeos_claim_department_exploratory_bets_provenance_floors_contract', fn (array $p) => $gates->generatedContractAaeosClaimDepartmentExploratoryBetsProvenanceFloorsContractObserve($p)],
            ['aaeos-department-evidence-vision-golden-counterfactual-promotion-protocol-floors-contract', 'aaeos_department_evidence_vision_golden_counterfactual_promotion_protocol_floors_contract', fn (array $p) => $gates->aaeosDepartmentEvidenceVisionGoldenCounterfactualPromotionProtocolFloorsContractObserve($p)],
            ['segment-importance-summary-fidelity-outcome-envelope-ragx-chain-floors-contract', 'segment_importance_summary_fidelity_outcome_envelope_ragx_chain_floors_contract', fn (array $p) => $gates->segmentImportanceSummaryFidelityOutcomeEnvelopeRagxChainFloorsContractObserve($p)],
            ['memory-recall-esp-independent-maxa-jina-immune-classifier-floors-contract', 'memory_recall_esp_independent_maxa_jina_immune_classifier_floors_contract', fn (array $p) => $gates->memoryRecallEspIndependentMaxaJinaImmuneClassifierFloorsContractObserve($p)],
            ['procedural-skill-verified-share-acos-program-deferred-phase-floors-contract', 'procedural_skill_verified_share_acos_program_deferred_phase_floors_contract', fn (array $p) => $gates->proceduralSkillVerifiedShareAcosProgramDeferredPhaseFloorsContractObserve($p)],
            ['aemor-outcome-ambition-rung-flywheel-funnel-composed-obra-floors-contract', 'aemor_outcome_ambition_rung_flywheel_funnel_composed_obra_floors_contract', fn (array $p) => $gates->aemorOutcomeAmbitionRungFlywheelFunnelComposedObraFloorsContractObserve($p)],
            ['resource-budget-belief-cascade-citation-grounding-dev-procedural-floors-contract', 'resource_budget_belief_cascade_citation_grounding_dev_procedural_floors_contract', fn (array $p) => $gates->resourceBudgetBeliefCascadeCitationGroundingDevProceduralFloorsContractObserve($p)],
            ['b375-n-capture-domain-lexical-evidence-vision-execution-context-floors-contract', 'b375_n_capture_domain_lexical_evidence_vision_execution_context_floors_contract', fn (array $p) => $gates->b375NCaptureDomainLexicalEvidenceVisionExecutionContextFloorsContractObserve($p)],
            ['aaeos-test-window-orchestrator-code-symbol-knowledge-item-floors-contract', 'aaeos_test_window_orchestrator_code_symbol_knowledge_item_floors_contract', fn (array $p) => $gates->aaeosTestWindowOrchestratorCodeSymbolKnowledgeItemFloorsContractObserve($p)],
            ['pre-review-http-path-phase-advance-cognition-score-floors-contract', 'pre_review_http_path_phase_advance_cognition_score_floors_contract', fn (array $p) => $gates->preReviewHttpPathPhaseAdvanceCognitionScoreFloorsContractObserve($p)],
            ['aaeos-implementation-phase-immune-calibration-signature-acos-watchdog-floors-contract', 'aaeos_implementation_phase_immune_calibration_signature_acos_watchdog_floors_contract', fn (array $p) => $gates->aaeosImplementationPhaseImmuneCalibrationSignatureAcosWatchdogFloorsContractObserve($p)],
            ['cross-department-portfolio-budget-aaeos-gate-implementation-phase-floors-contract', 'cross_department_portfolio_budget_aaeos_gate_implementation_phase_floors_contract', fn (array $p) => $gates->crossDepartmentPortfolioBudgetAaeosGateImplementationPhaseFloorsContractObserve($p)],
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
