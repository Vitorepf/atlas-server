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
        {--target= : Target capability for Meta-SDD candidate generation}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show Atlas Self-Construction OS readiness and next safe governed construction blocks.';

    public function handle(AtlasSelfConstructionReadinessService $readiness): int
    {
        $options = [
            'workspace' => $this->option('workspace'),
            'target' => $this->option('target'),
        ];

        $payload = match (true) {
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
