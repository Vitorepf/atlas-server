<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Console\Command;

class AtlasAiSelfConstructionCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction
        {--workspace= : Workspace root for reporting}
        {--meta-sdd : Generate a read-only Meta-SDD candidate packet}
        {--receipt-preview : Generate a read-only Decision Receipt preview}
        {--traceability : Audit Self-Construction documentation traceability}
        {--promotion-gate : Evaluate read-only phase promotion readiness}
        {--execution-candidate : Generate a read-only Phase 5 execution candidate}
        {--approval-packet : Generate a read-only human approval packet}
        {--receipt-draft : Generate a read-only signed receipt draft}
        {--execution-preflight : Evaluate read-only execution preflight}
        {--signature-request : Generate a read-only human signature request}
        {--execution-runbook : Generate a read-only post-signature execution runbook}
        {--evidence-packet : Generate a read-only post-execution evidence packet template}
        {--completion-readiness : Evaluate read-only completion readiness}
        {--residual-risk : Evaluate read-only residual risk before completion}
        {--handoff-packet : Generate a read-only handoff packet for the next operator}
        {--next-action : Select the next read-only safe action}
        {--surface-matrix : Generate a read-only command surface matrix}
        {--external-blockers : Report read-only blockers outside Self-Construction ownership}
        {--cold-lane-certification : Certify read-only Self-Construction cold lane status}
        {--operator-checklist : Generate a read-only operator checklist for the next safe review}
        {--promotion-blockers : Consolidate read-only blockers for promotion and completion}
        {--readiness-digest : Generate a compact read-only readiness digest}
        {--governance-scorecard : Generate a read-only governance scorecard}
        {--integrity-manifest : Generate a read-only integrity manifest of governed packets}
        {--continuation-token : Generate a compact read-only continuation token}
        {--ownership-boundary : Generate a read-only ownership boundary report}
        {--phase-ledger : Generate a read-only Self-Construction phase ledger}
        {--implementation-packet : Generate a read-only AI implementation packet}
        {--work-splitter : Generate read-only disjoint work packets for parallel AI sessions}
        {--scope-validator : Validate current diff against read-only packet scope}
        {--assignment-preview : Select one read-only packet for one AI session without persisting a claim}
        {--packet-runbook : Generate read-only packet consumption runbook for the selected assignment}
        {--packet-evidence-report : Review selected packet evidence without marking completion}
        {--packet-completion-gate : Evaluate packet completion readiness without persisting completion}
        {--reservation-ledger-preview : Preview future durable packet reservation without persisting a claim}
        {--durable-reservation-ledger-plan : Generate the read-only durable reservation ledger implementation plan}
        {--durable-reservation-ap-candidate : Generate the read-only AP candidate for durable packet reservations}
        {--durable-reservation-approval-request : Generate the read-only approval request for durable reservation implementation}
        {--durable-reservation-approval-decision : Generate the read-only approval decision template for durable reservation implementation}
        {--durable-reservation-post-approval-preflight : Generate the read-only post-approval preflight for durable reservation implementation}
        {--durable-reservation-implementation-packet : Generate the read-only implementation packet for durable reservation work}
        {--durable-reservation-storage-schema : Generate the read-only storage schema packet for durable reservation migrations}
        {--durable-reservation-repository-contract : Generate the read-only repository contract for durable reservation claims}
        {--durable-reservation-collision-guard : Generate the read-only collision guard contract for durable reservation claims}
        {--durable-reservation-lease-lifecycle : Generate the read-only lease lifecycle contract for durable reservation claims}
        {--durable-reservation-readiness-projection : Generate the read-only readiness projection contract for durable reservation state}
        {--durable-reservation-implementation-preflight : Generate the read-only final implementation preflight for durable reservation work}
        {--durable-reservation-migration-blueprint : Generate the read-only migration blueprint for durable reservation storage}
        {--durable-reservation-repository-blueprint : Generate the read-only repository implementation blueprint for durable reservation storage}
        {--durable-reservation-collision-guard-blueprint : Generate the read-only collision guard implementation blueprint for durable reservation claims}
        {--durable-reservation-lease-lifecycle-blueprint : Generate the read-only lease lifecycle implementation blueprint for durable reservation claims}
        {--durable-reservation-readiness-projection-blueprint : Generate the read-only readiness projection implementation blueprint for durable reservation state}
        {--durable-reservation-runtime-build-packet : Generate the read-only runtime build packet for durable reservation implementation}
        {--ai-session-bootstrap : Generate one read-only bootstrap packet for a new AI implementation session}
        {--packet-queue : Generate a read-only queue of available, blocked and withheld packets}
        {--parallel-session-plan : Plan up to five read-only AI session slots without claims or dispatch}
        {--collision-matrix : Generate a read-only packet collision matrix for parallel safety}
        {--dependency-unlock-plan : Preview which completed packets would unlock blocked work}
        {--multi-session-readiness-gate : Decide whether multiple AI sessions may safely proceed}
        {--single-session-instruction-packet : Generate the canonical read-only instruction packet for one AI session}
        {--codex-launch-plan : Generate a read-only launch plan with up to five Codex start commands}
        {--codex-execution-status : Generate a read-only execution status monitor for parallel Codex sessions}
        {--codex-integration-report : Generate a read-only integration report for completed Codex packets}
        {--codex-merge-readiness : Evaluate read-only merge review readiness for completed Codex packets}
        {--codex-final-review-packet : Generate a read-only final review packet for the principal integrator}
        {--codex-review-decision-template : Generate a read-only principal integrator decision template}
        {--codex-review-receipt-draft : Generate a read-only unsigned principal integrator review receipt draft}
        {--codex-review-signature-request : Generate a read-only signature request for the Codex review receipt draft}
        {--codex-review-post-signature-runbook : Generate a read-only post-signature runbook for the Codex review flow}
        {--codex-review-merge-action-template : Generate a read-only explicit merge action template for the Codex review flow}
        {--codex-review-merge-preflight : Evaluate read-only preflight for a future explicit Codex merge action}
        {--codex-review-merge-action-draft : Generate a read-only explicit Codex merge action draft without executing it}
        {--codex-review-merge-receipt-draft : Generate a read-only unsigned Codex merge receipt draft without authorizing merge}
        {--codex-review-merge-signature-request : Generate a read-only signature request for the Codex merge receipt draft}
        {--codex-review-merge-post-signature-runbook : Generate a read-only post-signature runbook for the Codex merge flow}
        {--codex-review-merge-execution-checklist : Generate a read-only final execution checklist for a future Codex merge action}
        {--codex-review-merge-authorization-template : Generate a read-only authorization template for a future Codex merge action}
        {--codex-review-merge-authorization-receipt-draft : Generate a read-only unsigned authorization receipt draft for a future Codex merge action}
        {--codex-review-merge-authorization-signature-request : Generate a read-only signature request for the Codex merge authorization receipt draft}
        {--codex-review-merge-authorization-post-signature-runbook : Generate a read-only post-signature runbook for the Codex merge authorization flow}
        {--codex-review-merge-final-authorization-preflight : Evaluate read-only final authorization preflight before any future Codex merge executor}
        {--codex-review-merge-authorizing-action-template : Generate a read-only template for the future Codex merge authorizing action}
        {--codex-review-merge-final-receipt-draft : Generate a read-only final merge receipt draft without authorizing or executing merge}
        {--codex-review-merge-final-signature-request : Generate a read-only signature request for the final merge receipt draft}
        {--codex-review-merge-final-post-signature-runbook : Generate a read-only post-signature runbook for the final merge receipt flow}
        {--codex-review-merge-signed-final-receipt-template : Generate a read-only template for a future signed final merge receipt}
        {--codex-review-merge-signed-final-receipt-preflight : Evaluate read-only preflight for a future signed final merge receipt}
        {--codex-review-merge-signed-final-receipt-persistence-template : Generate a read-only template for future signed final receipt persistence}
        {--codex-review-merge-executor-release-preflight : Evaluate read-only preflight for a future Codex merge executor release}
        {--codex-review-merge-executor-contract-template : Generate a read-only contract template for a future Codex merge executor}
        {--codex-review-merge-execution-receipt-template : Generate a read-only post-execution receipt template for a future Codex merge executor}
        {--codex-review-merge-post-execution-preflight : Evaluate read-only preflight for a future post-execution Codex merge}
        {--codex-review-merge-post-execution-action-template : Generate a read-only action template for a future post-execution Codex merge}
        {--codex-review-merge-post-execution-action-receipt-draft : Generate a read-only unsigned receipt draft for a future post-execution Codex merge action}
        {--codex-review-merge-post-execution-action-signature-request : Generate a read-only signature request for the future post-execution Codex merge action receipt}
        {--codex-review-merge-post-execution-action-post-signature-runbook : Generate a read-only post-signature runbook for the future post-execution Codex merge action receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-template : Generate a read-only signed receipt template for the future post-execution Codex merge action}
        {--codex-review-merge-post-execution-action-signed-receipt-preflight : Evaluate read-only preflight for future signed post-execution Codex merge action receipt persistence}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-template : Generate a read-only persistence template for the future signed post-execution Codex merge action receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft : Generate a read-only receipt draft for future signed post-execution Codex merge action receipt persistence}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-preflight : Evaluate read-only preflight for future signed post-execution Codex merge action receipt persistence}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook : Generate a read-only post-preflight runbook for future signed post-execution Codex merge action receipt persistence}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template : Generate a read-only append-only event payload template for future signed post-execution Codex merge action receipt persistence}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight : Evaluate read-only preflight for a future signed post-execution Codex merge action receipt persistence writer}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template : Generate a read-only contract template for a future signed post-execution Codex merge action receipt persistence writer}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight : Evaluate read-only preflight for a future signed post-execution Codex merge action receipt persistence writer implementation}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template : Generate a read-only release authorization template for a future signed post-execution Codex merge action receipt persistence writer}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight : Evaluate read-only preflight for future signed post-execution Codex merge action receipt persistence writer release authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft : Generate a read-only unsigned receipt draft for future signed post-execution Codex merge action receipt persistence writer release authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request : Generate a read-only signature request for the future writer release authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook : Generate a read-only post-signature runbook for the future writer release authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template : Generate a read-only signed receipt template for the future writer release authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight : Evaluate read-only preflight for future signed post-execution Codex merge action receipt persistence writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft : Generate a read-only unsigned receipt draft for future signed post-execution Codex merge action receipt persistence writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request : Generate a read-only signature request for the future writer release receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook : Generate a read-only post-signature runbook for the future writer release receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template : Generate a read-only signed receipt template for the future writer release receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight : Evaluate read-only preflight before any future writer release execution contract}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template : Generate a read-only execution contract template for a future writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template : Generate a read-only disable contract template for a future writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template : Generate a read-only observability contract template for a future writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template : Generate a read-only post-monitoring review template for a future writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template : Generate a read-only re-enable review packet template for a future writer release}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template : Generate a read-only fresh authorization request template for a future writer re-enable}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template : Generate a read-only unsigned receipt draft template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template : Generate a read-only signature request template for a future writer re-enable fresh authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template : Generate a read-only post-signature runbook template for a future writer re-enable fresh authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template : Generate a read-only signed receipt template for a future writer re-enable fresh authorization receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template : Generate a read-only execution contract preflight template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template : Generate a read-only execution contract template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template : Generate a read-only disable contract template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template : Generate a read-only observability contract template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template : Generate a read-only post-monitoring review template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template : Generate a read-only health decision template for a future writer re-enable fresh authorization}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template : Generate a read-only disable request template for a future writer re-enable fresh authorization health decision}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template : Generate a read-only new cycle request template for a future writer re-enable fresh authorization health decision}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template : Generate a read-only authorization request template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template : Generate a read-only unsigned receipt draft template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template : Generate a read-only signature request template for a future writer re-enable fresh authorization new cycle receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template : Generate a read-only post-signature runbook template for a future writer re-enable fresh authorization new cycle receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template : Generate a read-only signed receipt template for a future writer re-enable fresh authorization new cycle receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template : Generate a read-only execution contract preflight template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template : Generate a read-only execution contract template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template : Generate a read-only disable contract template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template : Generate a read-only observability contract template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template : Generate a read-only post-monitoring review template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template : Generate a read-only health decision template for a future writer re-enable fresh authorization new cycle}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template : Generate a read-only disable request template for a future writer re-enable fresh authorization new cycle health decision}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template : Generate a read-only disable execution preflight template for a future writer re-enable fresh authorization new cycle disable request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template : Generate a read-only disable execution receipt draft template for a future writer re-enable fresh authorization new cycle disable preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template : Generate a read-only signed disable execution receipt template for a future writer re-enable fresh authorization new cycle disable execution receipt draft}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template : Generate a read-only persistence preflight template for a future writer re-enable fresh authorization new cycle disable execution signed receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template : Generate a read-only persistence receipt template for a future writer re-enable fresh authorization new cycle disable execution persistence preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template : Generate a read-only post-persistence review template for a future writer re-enable fresh authorization new cycle disable execution persistence receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template : Generate a read-only follow-up observability template for a future writer re-enable fresh authorization new cycle disable execution post-persistence review}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template : Generate a read-only evidence repair request template for a future writer re-enable fresh authorization new cycle disable execution follow-up observability}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template : Generate a read-only repaired evidence packet template for a future writer re-enable fresh authorization new cycle disable execution evidence repair request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template : Generate a read-only repair review template for a future writer re-enable fresh authorization new cycle disable execution repaired evidence packet}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template : Generate a read-only repair outcome packet template for a future writer re-enable fresh authorization new cycle disable execution repair review}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template : Generate a read-only later-cycle request template for a future writer re-enable fresh authorization new cycle disable execution repair outcome}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template : Generate a read-only later-cycle preflight template for a future writer re-enable fresh authorization new cycle disable execution later-cycle request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template : Generate a read-only later-cycle authorization request template for a future writer re-enable fresh authorization new cycle disable execution later-cycle preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template : Generate a read-only later-cycle authorization receipt draft template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template : Generate a read-only later-cycle authorization signature request template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization receipt draft}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template : Generate a read-only later-cycle authorization post-signature runbook template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization signature request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template : Generate a read-only later-cycle authorization signature validation report template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization post-signature runbook}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template : Generate a read-only later-cycle authorization signed receipt template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization signature validation report}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template : Generate a read-only later-cycle authorization signed receipt preflight template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization signed receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template : Generate a read-only later-cycle authorization persistence preflight template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization signed receipt preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template : Generate a read-only later-cycle authorization persistence receipt template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization persistence preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template : Generate a read-only later-cycle authorization post-persistence review template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization persistence receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template : Generate a read-only later-cycle authorization follow-up observability template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization post-persistence review}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template : Generate a read-only later-cycle authorization evidence repair request template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization follow-up observability}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template : Generate a read-only later-cycle authorization repaired evidence packet template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization evidence repair request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template : Generate a read-only later-cycle authorization repair review template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization repaired evidence packet}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template : Generate a read-only later-cycle authorization persistence rejection template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization repair review}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template : Generate a read-only later-cycle authorization human escalation template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization persistence rejection}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template : Generate a read-only later-cycle authorization manual decision request template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization human escalation}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-response-template : Generate a read-only later-cycle authorization manual decision response template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization manual decision request}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-draft-template : Generate a read-only later-cycle authorization decision record draft template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization manual decision response}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-preflight-template : Generate a read-only later-cycle authorization decision record persistence preflight template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record draft}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-receipt-template : Generate a read-only later-cycle authorization decision record persistence receipt template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record persistence preflight}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-post-persistence-review-template : Generate a read-only later-cycle authorization decision record post-persistence review template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record persistence receipt}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-rejection-template : Generate a read-only later-cycle authorization decision record persistence rejection template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record post-persistence review}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-follow-up-observability-template : Generate a read-only later-cycle authorization decision record follow-up observability template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record persistence rejection}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-final-non-execution-report-template : Generate a read-only later-cycle authorization decision record final non-execution report template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record follow-up observability}
        {--codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-archive-index-template : Generate a read-only later-cycle authorization decision record archive index template for a future writer re-enable fresh authorization new cycle disable execution later-cycle authorization decision record final non-execution report}
        {--codex-start-packet : Durably claim the next packet and emit the canonical Codex session start contract}
        {--reservation-status : Read durable local packet reservation ledger status}
        {--claim-next-packet : Durably claim the next available Work Splitter packet and return scoped bootstrap instructions}
        {--claim-packet : Durably claim one Work Splitter packet in the local reservation ledger}
        {--release-packet : Release one active local packet reservation}
        {--complete-packet : Mark one active local packet reservation completed}
        {--target= : Target capability for Meta-SDD candidate generation}
        {--packet= : Packet id for packet-scoped bootstrap, runbook and validation}
        {--actor= : Reservation actor for packet claim/release}
        {--session= : Reservation session id for packet claim/release}
        {--lease-minutes=120 : Reservation lease duration in minutes}
        {--reason= : Release reason}
        {--evidence-hash= : Optional evidence hash for packet completion}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas Self-Construction OS readiness and next safe governed construction blocks.';

    public function handle(AtlasSelfConstructionReadinessService $readiness): int
    {
        $options = [
            'workspace' => $this->option('workspace'),
            'target' => $this->option('target'),
            'packet' => $this->option('packet'),
            'actor' => $this->option('actor'),
            'session' => $this->option('session'),
            'lease_minutes' => $this->option('lease-minutes'),
            'reason' => $this->option('reason'),
            'evidence_hash' => $this->option('evidence-hash'),
        ];

        $payload = match (true) {
            (bool) $this->option('release-packet') => $readiness->releasePacket($options),
            (bool) $this->option('complete-packet') => $readiness->completePacket($options),
            (bool) $this->option('codex-start-packet') => $readiness->codexStartPacket($options),
            (bool) $this->option('claim-next-packet') => $readiness->claimNextPacket($options),
            (bool) $this->option('claim-packet') => $readiness->claimPacket($options),
            (bool) $this->option('reservation-status') => $readiness->reservationStatus($options),
            (bool) $this->option('scope-validator') => $readiness->scopeValidator($options),
            (bool) $this->option('single-session-instruction-packet') => $readiness->singleSessionInstructionPacket($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-archive-index-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordArchiveIndexTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-final-non-execution-report-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFinalNonExecutionReportTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-follow-up-observability-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordFollowUpObservabilityTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-rejection-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceRejectionTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-post-persistence-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPostPersistenceReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistenceReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordPersistencePreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-draft-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationDecisionRecordDraftTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-response-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionResponseTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationManualDecisionRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationHumanEscalationTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceRejectionTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRepairedEvidencePacketTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationEvidenceRepairRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationFollowUpObservabilityTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostPersistenceReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistenceReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPersistencePreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptPreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureValidationReportTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationPostSignatureRunbookTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationSignatureRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationReceiptDraftTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleAuthorizationRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCyclePreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionLaterCycleRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairOutcomePacketTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionRepairedEvidencePacketTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionEvidenceRepairRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionFollowUpObservabilityTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPostPersistenceReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistenceReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPersistencePreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionReceiptDraftTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableExecutionPreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleHealthDecisionTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostMonitoringReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleObservabilityContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleDisableContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleExecutionContractPreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCyclePostSignatureRunbookTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleSignatureRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleReceiptDraftTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleAuthorizationRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationNewCycleRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationHealthDecisionTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostMonitoringReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationObservabilityContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationDisableContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationExecutionContractPreflightTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationPostSignatureRunbookTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationSignatureRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationReceiptDraftTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseFreshAuthorizationRequestTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReenableReviewPacketTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostMonitoringReviewTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseObservabilityContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseDisableContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseExecutionContractPreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseSignatureRequest($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseReceiptDraft($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleasePreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationSignatureRequest($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationReceiptDraft($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationPreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterReleaseAuthorizationTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterImplementationPreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterContractTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceWriterPreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceAppendOnlyEventPayloadTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistencePostPreflightRunbook($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistencePreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceReceiptDraft($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPersistenceTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-preflight') => $readiness->codexReviewMergePostExecutionActionSignedReceiptPreflight($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-template') => $readiness->codexReviewMergePostExecutionActionSignedReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-action-post-signature-runbook') => $readiness->codexReviewMergePostExecutionActionPostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-post-execution-action-signature-request') => $readiness->codexReviewMergePostExecutionActionSignatureRequest($options),
            (bool) $this->option('codex-review-merge-post-execution-action-receipt-draft') => $readiness->codexReviewMergePostExecutionActionReceiptDraft($options),
            (bool) $this->option('codex-review-merge-post-execution-action-template') => $readiness->codexReviewMergePostExecutionActionTemplate($options),
            (bool) $this->option('codex-review-merge-post-execution-preflight') => $readiness->codexReviewMergePostExecutionPreflight($options),
            (bool) $this->option('codex-review-merge-execution-receipt-template') => $readiness->codexReviewMergeExecutionReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-executor-contract-template') => $readiness->codexReviewMergeExecutorContractTemplate($options),
            (bool) $this->option('codex-review-merge-executor-release-preflight') => $readiness->codexReviewMergeExecutorReleasePreflight($options),
            (bool) $this->option('codex-review-merge-signed-final-receipt-persistence-template') => $readiness->codexReviewMergeSignedFinalReceiptPersistenceTemplate($options),
            (bool) $this->option('codex-review-merge-signed-final-receipt-preflight') => $readiness->codexReviewMergeSignedFinalReceiptPreflight($options),
            (bool) $this->option('codex-review-merge-signed-final-receipt-template') => $readiness->codexReviewMergeSignedFinalReceiptTemplate($options),
            (bool) $this->option('codex-review-merge-final-post-signature-runbook') => $readiness->codexReviewMergeFinalPostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-final-signature-request') => $readiness->codexReviewMergeFinalSignatureRequest($options),
            (bool) $this->option('codex-review-merge-final-receipt-draft') => $readiness->codexReviewMergeFinalReceiptDraft($options),
            (bool) $this->option('codex-review-merge-authorizing-action-template') => $readiness->codexReviewMergeAuthorizingActionTemplate($options),
            (bool) $this->option('codex-review-merge-final-authorization-preflight') => $readiness->codexReviewMergeFinalAuthorizationPreflight($options),
            (bool) $this->option('codex-review-merge-authorization-post-signature-runbook') => $readiness->codexReviewMergeAuthorizationPostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-authorization-signature-request') => $readiness->codexReviewMergeAuthorizationSignatureRequest($options),
            (bool) $this->option('codex-review-merge-authorization-receipt-draft') => $readiness->codexReviewMergeAuthorizationReceiptDraft($options),
            (bool) $this->option('codex-review-merge-authorization-template') => $readiness->codexReviewMergeAuthorizationTemplate($options),
            (bool) $this->option('codex-review-merge-execution-checklist') => $readiness->codexReviewMergeExecutionChecklist($options),
            (bool) $this->option('codex-review-merge-post-signature-runbook') => $readiness->codexReviewMergePostSignatureRunbook($options),
            (bool) $this->option('codex-review-merge-signature-request') => $readiness->codexReviewMergeSignatureRequest($options),
            (bool) $this->option('codex-review-merge-receipt-draft') => $readiness->codexReviewMergeReceiptDraft($options),
            (bool) $this->option('codex-review-merge-action-draft') => $readiness->codexReviewMergeActionDraft($options),
            (bool) $this->option('codex-review-merge-preflight') => $readiness->codexReviewMergePreflight($options),
            (bool) $this->option('codex-review-merge-action-template') => $readiness->codexReviewMergeActionTemplate($options),
            (bool) $this->option('codex-review-post-signature-runbook') => $readiness->codexReviewPostSignatureRunbook($options),
            (bool) $this->option('codex-review-signature-request') => $readiness->codexReviewSignatureRequest($options),
            (bool) $this->option('codex-review-receipt-draft') => $readiness->codexReviewReceiptDraft($options),
            (bool) $this->option('codex-review-decision-template') => $readiness->codexReviewDecisionTemplate($options),
            (bool) $this->option('codex-final-review-packet') => $readiness->codexFinalReviewPacket($options),
            (bool) $this->option('codex-merge-readiness') => $readiness->codexMergeReadiness($options),
            (bool) $this->option('codex-integration-report') => $readiness->codexIntegrationReport($options),
            (bool) $this->option('codex-execution-status') => $readiness->codexExecutionStatus($options),
            (bool) $this->option('codex-launch-plan') => $readiness->codexLaunchPlan($options),
            (bool) $this->option('multi-session-readiness-gate') => $readiness->multiSessionReadinessGate($options),
            (bool) $this->option('dependency-unlock-plan') => $readiness->dependencyUnlockPlan($options),
            (bool) $this->option('collision-matrix') => $readiness->collisionMatrix($options),
            (bool) $this->option('parallel-session-plan') => $readiness->parallelSessionPlan($options),
            (bool) $this->option('packet-queue') => $readiness->packetQueue($options),
            (bool) $this->option('ai-session-bootstrap') => $readiness->aiSessionBootstrap($options),
            (bool) $this->option('durable-reservation-runtime-build-packet') => $readiness->durableReservationRuntimeBuildPacket($options),
            (bool) $this->option('durable-reservation-readiness-projection-blueprint') => $readiness->durableReservationReadinessProjectionBlueprint($options),
            (bool) $this->option('durable-reservation-lease-lifecycle-blueprint') => $readiness->durableReservationLeaseLifecycleBlueprint($options),
            (bool) $this->option('durable-reservation-collision-guard-blueprint') => $readiness->durableReservationCollisionGuardBlueprint($options),
            (bool) $this->option('durable-reservation-repository-blueprint') => $readiness->durableReservationRepositoryBlueprint($options),
            (bool) $this->option('durable-reservation-migration-blueprint') => $readiness->durableReservationMigrationBlueprint($options),
            (bool) $this->option('durable-reservation-implementation-preflight') => $readiness->durableReservationImplementationPreflight($options),
            (bool) $this->option('durable-reservation-readiness-projection') => $readiness->durableReservationReadinessProjection($options),
            (bool) $this->option('durable-reservation-lease-lifecycle') => $readiness->durableReservationLeaseLifecycle($options),
            (bool) $this->option('durable-reservation-collision-guard') => $readiness->durableReservationCollisionGuard($options),
            (bool) $this->option('durable-reservation-repository-contract') => $readiness->durableReservationRepositoryContract($options),
            (bool) $this->option('durable-reservation-storage-schema') => $readiness->durableReservationStorageSchema($options),
            (bool) $this->option('durable-reservation-implementation-packet') => $readiness->durableReservationImplementationPacket($options),
            (bool) $this->option('durable-reservation-post-approval-preflight') => $readiness->durableReservationPostApprovalPreflight($options),
            (bool) $this->option('durable-reservation-approval-decision') => $readiness->durableReservationApprovalDecisionTemplate($options),
            (bool) $this->option('durable-reservation-approval-request') => $readiness->durableReservationApprovalRequest($options),
            (bool) $this->option('durable-reservation-ap-candidate') => $readiness->durableReservationApCandidate($options),
            (bool) $this->option('durable-reservation-ledger-plan') => $readiness->durableReservationLedgerImplementationPlan($options),
            (bool) $this->option('reservation-ledger-preview') => $readiness->reservationLedgerPreview($options),
            (bool) $this->option('packet-completion-gate') => $readiness->packetCompletionGate($options),
            (bool) $this->option('packet-evidence-report') => $readiness->packetEvidenceReport($options),
            (bool) $this->option('packet-runbook') => $readiness->packetRunbook($options),
            (bool) $this->option('assignment-preview') => $readiness->assignmentPreview($options),
            (bool) $this->option('work-splitter') => $readiness->workSplitter($options),
            (bool) $this->option('implementation-packet') => $readiness->implementationPacket($options),
            (bool) $this->option('ownership-boundary') => $readiness->ownershipBoundary($options),
            (bool) $this->option('continuation-token') => $readiness->continuationToken($options),
            (bool) $this->option('integrity-manifest') => $readiness->integrityManifest($options),
            (bool) $this->option('governance-scorecard') => $readiness->governanceScorecard($options),
            (bool) $this->option('readiness-digest') => $readiness->readinessDigest($options),
            (bool) $this->option('promotion-blockers') => $readiness->promotionBlockers($options),
            (bool) $this->option('operator-checklist') => $readiness->operatorChecklist($options),
            (bool) $this->option('cold-lane-certification') => $readiness->coldLaneCertification($options),
            (bool) $this->option('external-blockers') => $readiness->externalBlockers($options),
            (bool) $this->option('surface-matrix') => $readiness->surfaceMatrix($options),
            (bool) $this->option('phase-ledger') => $readiness->phaseLedger($options),
            (bool) $this->option('next-action') => $readiness->nextAction($options),
            (bool) $this->option('handoff-packet') => $readiness->handoffPacket($options),
            (bool) $this->option('residual-risk') => $readiness->residualRisk($options),
            (bool) $this->option('completion-readiness') => $readiness->completionReadiness($options),
            (bool) $this->option('evidence-packet') => $readiness->evidencePacket($options),
            (bool) $this->option('execution-runbook') => $readiness->executionRunbook($options),
            (bool) $this->option('signature-request') => $readiness->signatureRequest($options),
            (bool) $this->option('execution-preflight') => $readiness->executionPreflight($options),
            (bool) $this->option('receipt-draft') => $readiness->receiptDraft($options),
            (bool) $this->option('approval-packet') => $readiness->approvalPacket($options),
            (bool) $this->option('execution-candidate') => $readiness->executionCandidate($options),
            (bool) $this->option('promotion-gate') => $readiness->promotionGate($options),
            (bool) $this->option('traceability') => $readiness->traceabilityAudit($options),
            (bool) $this->option('receipt-preview') => $readiness->receiptPreview($options),
            (bool) $this->option('meta-sdd') => $readiness->metaSddPacket($options),
            default => $readiness->snapshot($options),
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Self-Construction OS</>', (string) $payload['status']);

        if ((bool) $this->option('reservation-status')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Active reservations', (string) data_get($payload, 'ledger.active_count'));
            $this->components->twoColumnDetail('Completed reservations', (string) data_get($payload, 'ledger.completed_count'));
            $this->components->twoColumnDetail('Ledger hash', (string) data_get($payload, 'ledger_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('claim-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'claim.packet_id'));
            $this->components->twoColumnDetail('Claim hash', (string) data_get($payload, 'claim_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('claim-next-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet_id'));
            $this->components->twoColumnDetail('Start hash', (string) data_get($payload, 'start_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-start-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet_id'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-launch-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Launchable sessions', (string) data_get($payload, 'plan.launchable_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-execution-status')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Active', (string) data_get($payload, 'monitor.counts.claimed'));
            $this->components->twoColumnDetail('Completed', (string) data_get($payload, 'monitor.counts.completed'));
            $this->components->twoColumnDetail('Available', (string) data_get($payload, 'monitor.counts.available'));
            $this->components->twoColumnDetail('Monitor hash', (string) data_get($payload, 'monitor_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-integration-report')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Integration status', (string) data_get($payload, 'report.integration_status'));
            $this->components->twoColumnDetail('Ready to review', (string) data_get($payload, 'report.counts.ready_to_review'));
            $this->components->twoColumnDetail('Missing packets', (string) data_get($payload, 'report.counts.missing_packets'));
            $this->components->twoColumnDetail('Report hash', (string) data_get($payload, 'report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-merge-readiness')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Merge review status', (string) data_get($payload, 'readiness.merge_review_status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'readiness.blocking_count'));
            $this->components->twoColumnDetail('Ready packets', (string) data_get($payload, 'readiness.ready_packet_count'));
            $this->components->twoColumnDetail('Readiness hash', (string) data_get($payload, 'readiness_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-final-review-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'packet.review_status'));
            $this->components->twoColumnDetail('Decision required', data_get($payload, 'packet.decision_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ready packets', (string) data_get($payload, 'packet.ready_packet_count'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-decision-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'template.decision_status'));
            $this->components->twoColumnDetail('Recording allowed', data_get($payload, 'template.decision_recording_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'template.default_decision'));
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'receipt.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'receipt.default_decision'));
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signable hash', (string) data_get($payload, 'signable_payload_hash'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-action-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Merge action status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Explicit action required', data_get($payload, 'template.explicit_merge_action_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-action-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Draft status', (string) data_get($payload, 'draft.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'draft.default_decision'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Draft hash', (string) data_get($payload, 'draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'receipt.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-execution-checklist')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Checklist status', (string) data_get($payload, 'checklist.status'));
            $this->components->twoColumnDetail('External authorization required', data_get($payload, 'checklist.external_authorization_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Checklist hash', (string) data_get($payload, 'checklist_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-authorization-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'template.default_decision'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-authorization-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'receipt.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-authorization-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-authorization-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-final-authorization-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Authorization ready', data_get($payload, 'preflight.authorization_ready') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-authorizing-action-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'template.default_decision'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-final-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'receipt.default_decision'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-final-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-final-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-signed-final-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-signed-final-receipt-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Executor allowed', data_get($payload, 'executor_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-signed-final-receipt-persistence-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Executor allowed', data_get($payload, 'executor_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-executor-release-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Executor allowed', data_get($payload, 'executor_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-executor-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Patch execution allowed', data_get($payload, 'patch_execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-execution-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Patch executed', data_get($payload, 'patch_executed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Execution receipt persisted', data_get($payload, 'execution_receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'template.default_decision'));
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Default decision', (string) data_get($payload, 'receipt.default_decision'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'receipt.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature present', data_get($payload, 'signature_request.signature_present') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signable hash', (string) data_get($payload, 'signable_payload_hash'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'runbook.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Merge allowed', data_get($payload, 'merge_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-post-preflight-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-append-only-event-payload-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Payload status', (string) data_get($payload, 'payload.status'));
            $this->components->twoColumnDetail('Event type', (string) data_get($payload, 'payload.event_type'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Payload hash', (string) data_get($payload, 'payload_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Writer preflight status', (string) data_get($payload, 'writer_preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'writer_preflight.blocking_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Writer preflight hash', (string) data_get($payload, 'writer_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'contract.status'));
            $this->components->twoColumnDetail('Capability count', (string) data_get($payload, 'contract.capability_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-implementation-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Implementation preflight status', (string) data_get($payload, 'implementation_preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'implementation_preflight.blocking_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Implementation preflight hash', (string) data_get($payload, 'implementation_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Release authorization status', (string) data_get($payload, 'authorization.status'));
            $this->components->twoColumnDetail('Required evidence count', (string) data_get($payload, 'authorization.required_evidence_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Authorization hash', (string) data_get($payload, 'authorization_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Release authorization preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Selected decision', (string) data_get($payload, 'receipt.selected_decision'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature request status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'signature_request.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-authorization-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Release preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt.status'));
            $this->components->twoColumnDetail('Selected decision', (string) data_get($payload, 'receipt.selected_decision'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature request status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Signature required', data_get($payload, 'signature_request.signature_required') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-signature-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-execution-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'contract.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'contract.blocking_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-disable-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Disable status', (string) data_get($payload, 'disable_contract.status'));
            $this->components->twoColumnDetail('Trigger count', (string) data_get($payload, 'disable_contract.trigger_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Disable hash', (string) data_get($payload, 'disable_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-observability-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Observability status', (string) data_get($payload, 'observability_contract.status'));
            $this->components->twoColumnDetail('Signal count', (string) data_get($payload, 'observability_contract.signal_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Observability hash', (string) data_get($payload, 'observability_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-post-monitoring-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'review_template.status'));
            $this->components->twoColumnDetail('Decision count', (string) data_get($payload, 'review_template.allowed_decision_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review hash', (string) data_get($payload, 'review_template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-reenable-review-packet-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Re-enable status', (string) data_get($payload, 'reenable_packet.status'));
            $this->components->twoColumnDetail('Requirement count', (string) data_get($payload, 'reenable_packet.requirement_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Re-enable hash', (string) data_get($payload, 'reenable_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Authorization status', (string) data_get($payload, 'authorization_request.status'));
            $this->components->twoColumnDetail('Required signer count', (string) data_get($payload, 'authorization_request.required_signer_count'));
            $this->components->twoColumnDetail('Approval granted', data_get($payload, 'approval_granted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Authorization hash', (string) data_get($payload, 'authorization_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-receipt-draft-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt_draft.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt draft hash', (string) data_get($payload, 'receipt_draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signature-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Required signer count', (string) data_get($payload, 'signature_request.required_signer_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signature request hash', (string) data_get($payload, 'signature_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-signature-runbook-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_condition_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-execution-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'contract.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'contract.blocking_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Disable status', (string) data_get($payload, 'disable_contract.status'));
            $this->components->twoColumnDetail('Trigger count', (string) data_get($payload, 'disable_contract.trigger_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Disable hash', (string) data_get($payload, 'disable_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-observability-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Observability status', (string) data_get($payload, 'observability_contract.status'));
            $this->components->twoColumnDetail('Signal count', (string) data_get($payload, 'observability_contract.signal_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Observability hash', (string) data_get($payload, 'observability_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-post-monitoring-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'review_template.status'));
            $this->components->twoColumnDetail('Decision count', (string) data_get($payload, 'review_template.allowed_decision_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review hash', (string) data_get($payload, 'review_template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-health-decision-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'health_decision.status'));
            $this->components->twoColumnDetail('Decision state count', (string) data_get($payload, 'health_decision.allowed_decision_state_count'));
            $this->components->twoColumnDetail('Decision recorded', data_get($payload, 'decision_recorded') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision hash', (string) data_get($payload, 'health_decision_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-disable-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Disable request status', (string) data_get($payload, 'disable_request.status'));
            $this->components->twoColumnDetail('Trigger count', (string) data_get($payload, 'disable_request.trigger_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Disable request hash', (string) data_get($payload, 'disable_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('New cycle status', (string) data_get($payload, 'new_cycle_request.status'));
            $this->components->twoColumnDetail('Requirement count', (string) data_get($payload, 'new_cycle_request.requirement_count'));
            $this->components->twoColumnDetail('Approval granted', data_get($payload, 'approval_granted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('New cycle hash', (string) data_get($payload, 'new_cycle_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-authorization-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Authorization status', (string) data_get($payload, 'authorization_request.status'));
            $this->components->twoColumnDetail('Required signer count', (string) data_get($payload, 'authorization_request.required_signer_count'));
            $this->components->twoColumnDetail('Approval granted', data_get($payload, 'approval_granted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Authorization hash', (string) data_get($payload, 'authorization_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-receipt-draft-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'receipt_draft.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt draft hash', (string) data_get($payload, 'receipt_draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signature-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_request.status'));
            $this->components->twoColumnDetail('Required signer count', (string) data_get($payload, 'signature_request.required_signer_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signature request hash', (string) data_get($payload, 'signature_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-signature-runbook-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook.status'));
            $this->components->twoColumnDetail('Step count', (string) data_get($payload, 'runbook.step_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Template status', (string) data_get($payload, 'template.status'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Template hash', (string) data_get($payload, 'template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'preflight.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'preflight.blocking_condition_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-execution-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'contract.status'));
            $this->components->twoColumnDetail('Blocking count', (string) data_get($payload, 'contract.blocking_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Disable status', (string) data_get($payload, 'disable_contract.status'));
            $this->components->twoColumnDetail('Trigger count', (string) data_get($payload, 'disable_contract.trigger_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Disable hash', (string) data_get($payload, 'disable_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-observability-contract-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Observability status', (string) data_get($payload, 'observability_contract.status'));
            $this->components->twoColumnDetail('Signal count', (string) data_get($payload, 'observability_contract.signal_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Observability hash', (string) data_get($payload, 'observability_contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-post-monitoring-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'review_template.status'));
            $this->components->twoColumnDetail('Allowed decision count', (string) data_get($payload, 'review_template.allowed_decision_count'));
            $this->components->twoColumnDetail('Writer file creation allowed', data_get($payload, 'writer_file_creation_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review hash', (string) data_get($payload, 'review_template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-health-decision-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'health_decision.status'));
            $this->components->twoColumnDetail('Decision state count', (string) data_get($payload, 'health_decision.allowed_decision_state_count'));
            $this->components->twoColumnDetail('Decision recorded', data_get($payload, 'decision_recorded') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision hash', (string) data_get($payload, 'health_decision_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Disable request status', (string) data_get($payload, 'disable_request.status'));
            $this->components->twoColumnDetail('Trigger count', (string) data_get($payload, 'disable_request.trigger_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Disable request hash', (string) data_get($payload, 'disable_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'disable_execution_preflight.status'));
            $this->components->twoColumnDetail('Check count', (string) data_get($payload, 'disable_execution_preflight.check_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'disable_execution_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-receipt-draft-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Receipt status', (string) data_get($payload, 'disable_execution_receipt_draft.status'));
            $this->components->twoColumnDetail('Receipt field count', (string) data_get($payload, 'disable_execution_receipt_draft.required_receipt_field_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt draft hash', (string) data_get($payload, 'disable_execution_receipt_draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Signed receipt status', (string) data_get($payload, 'disable_execution_signed_receipt.status'));
            $this->components->twoColumnDetail('Required signer count', (string) data_get($payload, 'disable_execution_signed_receipt.required_signer_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signed receipt hash', (string) data_get($payload, 'disable_execution_signed_receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Persistence preflight status', (string) data_get($payload, 'disable_execution_persistence_preflight.status'));
            $this->components->twoColumnDetail('Check count', (string) data_get($payload, 'disable_execution_persistence_preflight.check_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Persistence preflight hash', (string) data_get($payload, 'disable_execution_persistence_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-persistence-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Persistence receipt status', (string) data_get($payload, 'disable_execution_persistence_receipt.status'));
            $this->components->twoColumnDetail('Receipt field count', (string) data_get($payload, 'disable_execution_persistence_receipt.required_receipt_field_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Persistence receipt hash', (string) data_get($payload, 'disable_execution_persistence_receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-post-persistence-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Review status', (string) data_get($payload, 'disable_execution_post_persistence_review.status'));
            $this->components->twoColumnDetail('Review decision count', (string) data_get($payload, 'disable_execution_post_persistence_review.allowed_review_decision_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Review hash', (string) data_get($payload, 'disable_execution_post_persistence_review_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-follow-up-observability-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Observability status', (string) data_get($payload, 'disable_execution_follow_up_observability.status'));
            $this->components->twoColumnDetail('Signal count', (string) data_get($payload, 'disable_execution_follow_up_observability.observation_signal_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Observability hash', (string) data_get($payload, 'disable_execution_follow_up_observability_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-evidence-repair-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Repair request status', (string) data_get($payload, 'disable_execution_evidence_repair_request.status'));
            $this->components->twoColumnDetail('Repair item count', (string) data_get($payload, 'disable_execution_evidence_repair_request.repair_item_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Repair request hash', (string) data_get($payload, 'disable_execution_evidence_repair_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repaired-evidence-packet-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Repaired packet status', (string) data_get($payload, 'disable_execution_repaired_evidence_packet.status'));
            $this->components->twoColumnDetail('Packet field count', (string) data_get($payload, 'disable_execution_repaired_evidence_packet.required_packet_field_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Repaired packet hash', (string) data_get($payload, 'disable_execution_repaired_evidence_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Repair review status', (string) data_get($payload, 'disable_execution_repair_review.status'));
            $this->components->twoColumnDetail('Review outcome count', (string) data_get($payload, 'disable_execution_repair_review.allowed_repair_review_outcome_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Repair review hash', (string) data_get($payload, 'disable_execution_repair_review_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-repair-outcome-packet-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Repair outcome status', (string) data_get($payload, 'disable_execution_repair_outcome_packet.status'));
            $this->components->twoColumnDetail('Outcome field count', (string) data_get($payload, 'disable_execution_repair_outcome_packet.required_outcome_field_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Repair outcome hash', (string) data_get($payload, 'disable_execution_repair_outcome_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle request status', (string) data_get($payload, 'disable_execution_later_cycle_request.status'));
            $this->components->twoColumnDetail('Request field count', (string) data_get($payload, 'disable_execution_later_cycle_request.required_request_field_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle request hash', (string) data_get($payload, 'disable_execution_later_cycle_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle preflight status', (string) data_get($payload, 'disable_execution_later_cycle_preflight.status'));
            $this->components->twoColumnDetail('Preflight check count', (string) data_get($payload, 'disable_execution_later_cycle_preflight.preflight_check_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle preflight hash', (string) data_get($payload, 'disable_execution_later_cycle_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization request status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_request.status'));
            $this->components->twoColumnDetail('Authorization request field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_request.required_authorization_request_field_count'));
            $this->components->twoColumnDetail('Approval granted', data_get($payload, 'approval_granted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization request hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-receipt-draft-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization receipt draft status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_receipt_draft.status'));
            $this->components->twoColumnDetail('Receipt draft field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_receipt_draft.required_receipt_draft_field_count'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization receipt draft hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_receipt_draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization signature request status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_request.status'));
            $this->components->twoColumnDetail('Signature request field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_request.required_signature_request_field_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization signature request hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-signature-runbook-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization post-signature runbook status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_signature_runbook.status'));
            $this->components->twoColumnDetail('Runbook step count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_signature_runbook.runbook_step_count'));
            $this->components->twoColumnDetail('Signature valid', data_get($payload, 'signature_valid') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization post-signature runbook hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_signature_runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signature-validation-report-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization signature validation report status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_validation_report.status'));
            $this->components->twoColumnDetail('Validation check count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_validation_report.validation_check_count'));
            $this->components->twoColumnDetail('Signature accepted', data_get($payload, 'signature_accepted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization signature validation report hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signature_validation_report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization signed receipt template status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_template.status'));
            $this->components->twoColumnDetail('Signed receipt field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_template.required_signed_receipt_field_count'));
            $this->components->twoColumnDetail('Receipt signed', data_get($payload, 'receipt_signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization signed receipt template hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-signed-receipt-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization signed receipt preflight status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_preflight.status'));
            $this->components->twoColumnDetail('Preflight check count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_preflight.preflight_check_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization signed receipt preflight hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_signed_receipt_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization persistence preflight status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_preflight.status'));
            $this->components->twoColumnDetail('Persistence preflight check count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_preflight.persistence_preflight_check_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization persistence preflight hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization persistence receipt template status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_receipt_template.status'));
            $this->components->twoColumnDetail('Persistence receipt field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_receipt_template.required_persistence_receipt_field_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization persistence receipt template hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_receipt_template_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-post-persistence-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization post-persistence review status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_persistence_review.status'));
            $this->components->twoColumnDetail('Review decision count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_persistence_review.allowed_review_decision_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization post-persistence review hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_post_persistence_review_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-follow-up-observability-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization follow-up observability status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_follow_up_observability.status'));
            $this->components->twoColumnDetail('Observation signal count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_follow_up_observability.observation_signal_count'));
            $this->components->twoColumnDetail('Later cycle authorized', data_get($payload, 'later_cycle_authorized') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization follow-up observability hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_follow_up_observability_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-evidence-repair-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization evidence repair request status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_evidence_repair_request.status'));
            $this->components->twoColumnDetail('Repair item count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_evidence_repair_request.repair_item_count'));
            $this->components->twoColumnDetail('Dispatch allowed', data_get($payload, 'dispatch_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization evidence repair request hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_evidence_repair_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repaired-evidence-packet-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization repaired evidence packet status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repaired_evidence_packet.status'));
            $this->components->twoColumnDetail('Packet field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repaired_evidence_packet.required_packet_field_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization repaired evidence packet hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repaired_evidence_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-repair-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization repair review status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repair_review.status'));
            $this->components->twoColumnDetail('Review outcome count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repair_review.allowed_repair_review_outcome_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization repair review hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_repair_review_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-persistence-rejection-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization persistence rejection status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_rejection.status'));
            $this->components->twoColumnDetail('Rejection field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_rejection.required_rejection_field_count'));
            $this->components->twoColumnDetail('Receipt persisted', data_get($payload, 'receipt_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization persistence rejection hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_persistence_rejection_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-human-escalation-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization human escalation status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_human_escalation.status'));
            $this->components->twoColumnDetail('Escalation field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_human_escalation.required_escalation_field_count'));
            $this->components->twoColumnDetail('Dispatch allowed', data_get($payload, 'dispatch_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization human escalation hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_human_escalation_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-request-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization manual decision request status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_request.status'));
            $this->components->twoColumnDetail('Decision field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_request.required_decision_request_field_count'));
            $this->components->twoColumnDetail('Decision requested', data_get($payload, 'manual_decision_requested') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization manual decision request hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-manual-decision-response-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization manual decision response status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_response.status'));
            $this->components->twoColumnDetail('Response field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_response.required_decision_response_field_count'));
            $this->components->twoColumnDetail('Decision recorded', data_get($payload, 'decision_recorded') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization manual decision response hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_manual_decision_response_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-draft-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record draft status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_draft.status'));
            $this->components->twoColumnDetail('Draft field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_draft.required_decision_record_draft_field_count'));
            $this->components->twoColumnDetail('Decision recorded', data_get($payload, 'decision_recorded') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record draft hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_draft_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-preflight-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence preflight status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_preflight.status'));
            $this->components->twoColumnDetail('Preflight check count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_preflight.required_persistence_preflight_check_count'));
            $this->components->twoColumnDetail('Persistence allowed', data_get($payload, 'decision_record_persistence_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence preflight hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-receipt-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence receipt status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_receipt.status'));
            $this->components->twoColumnDetail('Receipt field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_receipt.required_persistence_receipt_field_count'));
            $this->components->twoColumnDetail('Decision record persisted', data_get($payload, 'decision_record_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence receipt hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-post-persistence-review-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record post-persistence review status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_post_persistence_review.status'));
            $this->components->twoColumnDetail('Review check count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_post_persistence_review.required_post_persistence_review_check_count'));
            $this->components->twoColumnDetail('Persistence accepted', data_get($payload, 'decision_record_persistence_accepted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record post-persistence review hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_post_persistence_review_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-persistence-rejection-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence rejection status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_rejection.status'));
            $this->components->twoColumnDetail('Rejection field count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_rejection.required_rejection_field_count'));
            $this->components->twoColumnDetail('Persistence rejected', data_get($payload, 'decision_record_persistence_rejected') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record persistence rejection hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_persistence_rejection_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-follow-up-observability-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record follow-up observability status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_follow_up_observability.status'));
            $this->components->twoColumnDetail('Observation signal count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_follow_up_observability.observation_signal_count'));
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record follow-up observability hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_follow_up_observability_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-final-non-execution-report-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record final non-execution report status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_final_non_execution_report.status'));
            $this->components->twoColumnDetail('Report assertion count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_final_non_execution_report.required_report_assertion_count'));
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record final non-execution report hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_final_non_execution_report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('codex-review-merge-post-execution-action-signed-receipt-persistence-writer-release-fresh-authorization-new-cycle-disable-execution-later-cycle-authorization-decision-record-archive-index-template')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Later cycle authorization decision record archive index status', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_archive_index.status'));
            $this->components->twoColumnDetail('Archive entry count', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_archive_index.archive_entry_count'));
            $this->components->twoColumnDetail('Archive index persisted', data_get($payload, 'decision_record_archive_index_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Later cycle authorization decision record archive index hash', (string) data_get($payload, 'disable_execution_later_cycle_authorization_decision_record_archive_index_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('release-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Released', data_get($payload, 'release.released') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'release.packet_id'));
            $this->components->twoColumnDetail('Release hash', (string) data_get($payload, 'release_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('complete-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Completed', data_get($payload, 'completion.completed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'completion.packet_id'));
            $this->components->twoColumnDetail('Completion hash', (string) data_get($payload, 'completion_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('ownership-boundary')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Allowed files', (string) data_get($payload, 'allowed_file_count'));
            $this->components->twoColumnDetail('Forbidden scopes', (string) data_get($payload, 'forbidden_scope_count'));
            $this->components->twoColumnDetail('Boundary hash', (string) data_get($payload, 'boundary_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('scope-validator')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Validator status', (string) $payload['status']);
            $this->components->twoColumnDetail('Blocking violations', (string) data_get($payload, 'summary.blocking_count'));
            $this->components->twoColumnDetail('Validator hash', (string) data_get($payload, 'validator_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('reservation-ledger-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'reservation.selected_packet_id'));
            $this->components->twoColumnDetail('Reservation hash', (string) data_get($payload, 'reservation_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-ledger-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Ledger write allowed', data_get($payload, 'ledger_write_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Storage objects', (string) data_get($payload, 'plan.storage_object_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-ap-candidate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('AP id', (string) data_get($payload, 'candidate.ap_id'));
            $this->components->twoColumnDetail('Packet count', (string) data_get($payload, 'candidate.packet_count'));
            $this->components->twoColumnDetail('Candidate hash', (string) data_get($payload, 'candidate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-approval-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval status', (string) data_get($payload, 'request.approval_status'));
            $this->components->twoColumnDetail('Required signers', (string) data_get($payload, 'request.required_signer_count'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-approval-decision')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision status', (string) data_get($payload, 'decision.decision_status'));
            $this->components->twoColumnDetail('Signer slots', (string) data_get($payload, 'decision.signer_slot_count'));
            $this->components->twoColumnDetail('Decision hash', (string) data_get($payload, 'decision_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-post-approval-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight decision', (string) data_get($payload, 'preflight.decision'));
            $this->components->twoColumnDetail('Blocking checks', (string) data_get($payload, 'preflight.blocking_check_count'));
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-implementation-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet status', (string) data_get($payload, 'packet.packet_status'));
            $this->components->twoColumnDetail('Work packets', (string) data_get($payload, 'packet.work_packet_count'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-storage-schema')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Schema status', (string) data_get($payload, 'storage_schema.schema_status'));
            $this->components->twoColumnDetail('Tables', (string) data_get($payload, 'storage_schema.table_count'));
            $this->components->twoColumnDetail('Schema hash', (string) data_get($payload, 'schema_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-repository-contract')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Contract status', (string) data_get($payload, 'repository_contract.contract_status'));
            $this->components->twoColumnDetail('Methods', (string) data_get($payload, 'repository_contract.method_count'));
            $this->components->twoColumnDetail('Contract hash', (string) data_get($payload, 'contract_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-collision-guard')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Guard status', (string) data_get($payload, 'collision_guard.guard_status'));
            $this->components->twoColumnDetail('Blocking decisions', (string) data_get($payload, 'collision_guard.blocking_decision_count'));
            $this->components->twoColumnDetail('Guard hash', (string) data_get($payload, 'guard_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-lease-lifecycle')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Lifecycle status', (string) data_get($payload, 'lease_lifecycle.lifecycle_status'));
            $this->components->twoColumnDetail('States', (string) data_get($payload, 'lease_lifecycle.state_count'));
            $this->components->twoColumnDetail('Lifecycle hash', (string) data_get($payload, 'lifecycle_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-readiness-projection')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Projection status', (string) data_get($payload, 'readiness_projection.projection_status'));
            $this->components->twoColumnDetail('Queue states', (string) data_get($payload, 'readiness_projection.queue_state_count'));
            $this->components->twoColumnDetail('Projection hash', (string) data_get($payload, 'projection_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-implementation-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Preflight status', (string) data_get($payload, 'implementation_preflight.preflight_status'));
            $this->components->twoColumnDetail('Contract hashes', (string) data_get($payload, 'implementation_preflight.contract_hash_count'));
            $this->components->twoColumnDetail('Preflight hash', (string) data_get($payload, 'preflight_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-migration-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'migration_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Tables', (string) data_get($payload, 'migration_blueprint.table_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-repository-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'repository_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Classes', (string) data_get($payload, 'repository_blueprint.class_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-collision-guard-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'collision_guard_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'collision_guard_blueprint.blocker_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-lease-lifecycle-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'lease_lifecycle_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('States', (string) data_get($payload, 'lease_lifecycle_blueprint.state_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-readiness-projection-blueprint')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blueprint status', (string) data_get($payload, 'readiness_projection_blueprint.blueprint_status'));
            $this->components->twoColumnDetail('Queue states', (string) data_get($payload, 'readiness_projection_blueprint.queue_state_count'));
            $this->components->twoColumnDetail('Blueprint hash', (string) data_get($payload, 'blueprint_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('durable-reservation-runtime-build-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Build status', (string) data_get($payload, 'runtime_build_packet.build_status'));
            $this->components->twoColumnDetail('Slices', (string) data_get($payload, 'runtime_build_packet.slice_count'));
            $this->components->twoColumnDetail('Build hash', (string) data_get($payload, 'build_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('ai-session-bootstrap')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'bootstrap.selected_packet_id'));
            $this->components->twoColumnDetail('Bootstrap hash', (string) data_get($payload, 'bootstrap_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-queue')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Claim persisted', data_get($payload, 'claim_persisted') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Queue entries', (string) data_get($payload, 'queue.entry_count'));
            $this->components->twoColumnDetail('Queue hash', (string) data_get($payload, 'queue_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('parallel-session-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Dispatch allowed', data_get($payload, 'dispatch_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Session slots', (string) data_get($payload, 'plan.slot_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('collision-matrix')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Pair count', (string) data_get($payload, 'matrix.pair_count'));
            $this->components->twoColumnDetail('Blocked pairs', (string) data_get($payload, 'matrix.blocked_pair_count'));
            $this->components->twoColumnDetail('Matrix hash', (string) data_get($payload, 'matrix_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('dependency-unlock-plan')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blocked packets', (string) data_get($payload, 'plan.blocked_packet_count'));
            $this->components->twoColumnDetail('Unlock edges', (string) data_get($payload, 'plan.unlock_edge_count'));
            $this->components->twoColumnDetail('Plan hash', (string) data_get($payload, 'plan_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('multi-session-readiness-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision', (string) data_get($payload, 'gate.decision'));
            $this->components->twoColumnDetail('Multi-session allowed', data_get($payload, 'multi_session_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Gate hash', (string) data_get($payload, 'gate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('single-session-instruction-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'instruction.selected_packet_id'));
            $this->components->twoColumnDetail('Safe next', (string) data_get($payload, 'instruction.safe_next_instruction'));
            $this->components->twoColumnDetail('Instruction hash', (string) data_get($payload, 'instruction_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-completion-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Decision', (string) data_get($payload, 'gate.decision'));
            $this->components->twoColumnDetail('Gate hash', (string) data_get($payload, 'gate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-evidence-report')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Report status', (string) $payload['status']);
            $this->components->twoColumnDetail('Report hash', (string) data_get($payload, 'report_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('packet-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook', (string) data_get($payload, 'runbook.runbook_id'));
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'runbook.selected_packet_id'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('assignment-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Assignment', (string) data_get($payload, 'assignment.assignment_id'));
            $this->components->twoColumnDetail('Selected packet', (string) data_get($payload, 'assignment.selected_packet_id'));
            $this->components->twoColumnDetail('Assignment hash', (string) data_get($payload, 'assignment_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('work-splitter')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packets', (string) data_get($payload, 'packet_count'));
            $this->components->twoColumnDetail('Withheld', (string) data_get($payload, 'withheld_count'));
            $this->components->twoColumnDetail('Split hash', (string) data_get($payload, 'split_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('implementation-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'packet.packet_id'));
            $this->components->twoColumnDetail('Risk', (string) data_get($payload, 'packet.risk_level'));
            $this->components->twoColumnDetail('Packet hash', (string) data_get($payload, 'packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('continuation-token')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Token id', (string) data_get($payload, 'token.id'));
            $this->components->twoColumnDetail('Token hash', (string) data_get($payload, 'token_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('integrity-manifest')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Manifest entries', (string) data_get($payload, 'entry_count'));
            $this->components->twoColumnDetail('Manifest hash', (string) data_get($payload, 'manifest_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('governance-scorecard')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Score', (string) data_get($payload, 'scorecard.score'));
            $this->components->twoColumnDetail('Rating', (string) data_get($payload, 'scorecard.rating'));
            $this->components->twoColumnDetail('Scorecard hash', (string) data_get($payload, 'scorecard_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('readiness-digest')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'digest.current_phase'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'digest.next_action_id'));
            $this->components->twoColumnDetail('Digest hash', (string) data_get($payload, 'digest_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('promotion-blockers')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Promotion allowed', data_get($payload, 'promotion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'blocker_count'));
            $this->components->twoColumnDetail('Blocker hash', (string) data_get($payload, 'blocker_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('operator-checklist')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Checklist items', (string) data_get($payload, 'checklist_count'));
            $this->components->twoColumnDetail('Checklist hash', (string) data_get($payload, 'checklist_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('cold-lane-certification')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action_id'));
            $this->components->twoColumnDetail('External blockers', (string) data_get($payload, 'external_blocker_count'));
            $this->components->twoColumnDetail('Certification hash', (string) data_get($payload, 'certification_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('external-blockers')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blockers', (string) data_get($payload, 'blocker_count'));
            $this->components->twoColumnDetail('Surface status', (string) data_get($payload, 'self_construction_surface_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('surface-matrix')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Surfaces', (string) data_get($payload, 'surface_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('phase-ledger')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'current_phase'));
            $this->components->twoColumnDetail('Next action', (string) data_get($payload, 'next_action_id'));
            $this->components->twoColumnDetail('Blocked phases', (string) data_get($payload, 'ledger_summary.blocked_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('next-action')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Selected action', (string) data_get($payload, 'selected_action.id'));
            $this->components->twoColumnDetail('Handoff hash', (string) data_get($payload, 'handoff_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('handoff-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Handoff', (string) data_get($payload, 'handoff_packet.id'));
            $this->components->twoColumnDetail('Handoff hash', (string) data_get($payload, 'handoff_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('residual-risk')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Highest severity', (string) data_get($payload, 'risk_summary.highest_severity'));
            $this->components->twoColumnDetail('Blocking risks', (string) data_get($payload, 'risk_summary.blocking_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('completion-readiness')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Completion allowed', data_get($payload, 'completion_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->components->twoColumnDetail('Evidence hash', (string) data_get($payload, 'evidence_packet_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('evidence-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Evidence packet', (string) data_get($payload, 'evidence_packet.id'));
            $this->components->twoColumnDetail('Evidence hash', (string) data_get($payload, 'evidence_packet_hash'));
            $this->components->twoColumnDetail('Runbook status', (string) data_get($payload, 'runbook_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-runbook')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Runbook', (string) data_get($payload, 'runbook.id'));
            $this->components->twoColumnDetail('Runbook hash', (string) data_get($payload, 'runbook_hash'));
            $this->components->twoColumnDetail('Signature status', (string) data_get($payload, 'signature_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('signature-request')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Signature request', (string) data_get($payload, 'signature_request.id'));
            $this->components->twoColumnDetail('Request hash', (string) data_get($payload, 'request_hash'));
            $this->components->twoColumnDetail('Preflight', (string) data_get($payload, 'preflight_status'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-preflight')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt_id'));
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->components->twoColumnDetail('Next required action', (string) data_get($payload, 'next_required_action'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('receipt-draft')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt draft', (string) data_get($payload, 'receipt_draft.id'));
            $this->components->twoColumnDetail('Signed', data_get($payload, 'receipt_draft.signed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt hash', (string) data_get($payload, 'receipt_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('approval-packet')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval', (string) data_get($payload, 'approval.id'));
            $this->components->twoColumnDetail('Approved', data_get($payload, 'approval.approved') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Approval hash', (string) data_get($payload, 'approval_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('execution-candidate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Candidate', (string) data_get($payload, 'candidate.id'));
            $this->components->twoColumnDetail('Autonomy', (string) data_get($payload, 'candidate.autonomy_level'));
            $this->components->twoColumnDetail('Candidate hash', (string) data_get($payload, 'candidate_hash'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('promotion-gate')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Current phase', (string) data_get($payload, 'current_phase'));
            $this->components->twoColumnDetail('Recommended next phase', (string) data_get($payload, 'recommended_next_phase'));
            $this->components->twoColumnDetail('Blocking failures', (string) count((array) data_get($payload, 'blocking_failures', [])));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('traceability')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Required docs', (string) data_get($payload, 'summary.required_doc_count'));
            $this->components->twoColumnDetail('Violations', (string) data_get($payload, 'summary.violation_count'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('receipt-preview')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Receipt', (string) data_get($payload, 'receipt_preview.id'));
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'receipt_preview.target_capability'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        if ((bool) $this->option('meta-sdd')) {
            $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
            $this->components->twoColumnDetail('Execution allowed', data_get($payload, 'execution_allowed') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Target capability', (string) data_get($payload, 'meta_spec.target_capability'));
            $this->components->twoColumnDetail('Target maturity', (string) data_get($payload, 'meta_spec.target_maturity'));
            $this->newLine();
            $this->line((string) $payload['human_summary']);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('Runtime phase', (string) data_get($payload, 'summary.runtime_phase'));
        $this->components->twoColumnDetail('Docs missing', (string) data_get($payload, 'summary.missing_doc_count'));
        $this->components->twoColumnDetail('Self-programming allowed', data_get($payload, 'safety_contract.self_programming_allowed') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Next target', (string) data_get($payload, 'maturity.next_target'));

        $blocks = (array) data_get($payload, 'next_safe_blocks', []);
        if ($blocks !== []) {
            $this->newLine();
            $this->line('Next safe blocks:');
            foreach ($blocks as $block) {
                $this->line('  - '.data_get($block, 'order').'. '.data_get($block, 'block').' ['.data_get($block, 'risk').']');
            }
        }

        return self::SUCCESS;
    }
}
