<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Models\AiInboxItem;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeRuntimeResultEventService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * AP-765 · Evidence / Product Mode runtime result bridge.
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This bridge
 * CLOSES the first complete 24h cycle: after Area Focus finds something,
 * Self-Directed Evolution drafts a spec, the Branch Sandbox materializes a
 * worktree (AP-756) and Atlas Dev/Forge run the owner runtime, the runtime
 * result has to come back to the operator. This service takes that direct
 * `execution_result` plus the loop ids (finding/spec/handoff/sandbox) and turns
 * it into five reviewable artifacts:
 *
 *   1. a structured evidence pack;
 *   2. a real, light operator review inbox item (via the canonical
 *      {@see ProposalInboxEmitter}, which writes through AtlasInboxService);
 *   3. a Product Mode visibility event ({@see ProductModeRuntimeResultEventService});
 *   4. a portfolio/area health signal in the exact shape
 *      {@see PortfolioStewardshipHealthModelService} already consumes;
 *   5. a final cycle receipt.
 *
 * Non-duplication: it does NOT re-implement the AP-750
 * {@see StewardshipOwnerRuntimeResultBridgeService} identity/isolation gates,
 * nor create a parallel Evidence Ledger or Inbox. It reuses the canonical
 * Evidence Ledger ({@see AtlasEvidenceLedger}), the canonical inbox path and the
 * AP-751 portfolio feed shape. It never invokes a provider, opens a branch,
 * mutates the target repo, merges, deploys, touches secrets or auto-approves.
 */
final class StewardshipRuntimeResultBridgeService implements StewardshipRuntimeResultProjector
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.runtime_result_bridge.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.runtime_result_bridge_record.v1';

    public const EVIDENCE_PACK_SCHEMA = 'atlas.software_company_stewardship.runtime_result_evidence_pack.v1';

    public const INBOX_ITEM_SCHEMA = 'atlas.software_company_stewardship.runtime_result_inbox_item.v1';

    public const PORTFOLIO_FEED_SCHEMA = 'atlas.software_company_stewardship.runtime_result_portfolio_feed.v1';

    public const STATUS_READY = 'ready_for_operator_review';

    public const STATUS_RECORDED = 'runtime_result_bridge_recorded';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public const DEFAULT_PORTFOLIO_ID = 'atlas_software_company';

    private const VALID_RESULT_STATUSES = ['completed', 'failed', 'blocked', 'partial'];

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AtlasEvidenceLedger $evidenceLedger,
        private readonly ProposalInboxEmitter $proposalInbox,
        private readonly ProductModeRuntimeResultEventService $productModeEvents,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/runtime_result_bridge')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/runtime_result_bridge';
    }

    public function bridgeFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $areaId = $this->areaId($input);
        $portfolioId = $this->portfolioId($input);
        $loopRefs = $this->loopRefs($input);
        $owner = $this->owner($input);

        $result = $this->executionResult($input);
        if ($result === []) {
            return $this->blocked($areaId, $portfolioId, $owner, $loopRefs, 'execution_result_required', 'AP-765 requires an execution_result receipt from Atlas Dev/Forge or the owner runtime.');
        }

        $status = $this->resultStatus($result);
        if (! in_array($status, self::VALID_RESULT_STATUSES, true)) {
            return $this->blocked($areaId, $portfolioId, $owner, $loopRefs, 'result_status_invalid', 'execution_result.result_status must be completed|failed|blocked|partial.');
        }

        $evidenceCheck = $this->evidenceCheck($result);
        if ($evidenceCheck['ok'] !== true) {
            return $this->blocked($areaId, $portfolioId, $owner, $loopRefs, 'result_evidence_insufficient', 'AP-765 needs at least tests/test_results/validation commands and a summary before it can build an evidence pack.', ['evidence_check' => $evidenceCheck]);
        }

        $approval = is_array($input['irreversible_approval_receipt'] ?? null) ? $input['irreversible_approval_receipt'] : [];
        $irreversibleCheck = $this->irreversibleCheck($result, $approval);
        if ($irreversibleCheck['ok'] !== true) {
            return $this->blocked($areaId, $portfolioId, $owner, $loopRefs, 'irreversible_action_without_operator_approval', 'Merge, deploy, external push, secrets or destructive changes require an explicit operator approval receipt.', ['irreversible_check' => $irreversibleCheck]);
        }

        $recordEvidence = (bool) ($input['record_evidence'] ?? false);
        $emitInbox = (bool) ($input['emit_inbox'] ?? false);
        $recordEvent = (bool) ($input['record_event'] ?? false);
        $recordCycle = (bool) ($input['record_cycle'] ?? false);
        $actor = trim((string) ($input['actor'] ?? $input['operator_actor'] ?? 'system')) ?: 'system';

        // 1. Evidence pack (structured) — built first so every other artifact can reference it.
        $evidencePack = $this->buildEvidencePack($areaId, $owner, $loopRefs, $result, $status);
        $evidencePackId = (string) $evidencePack['pack_id'];
        $evidencePackHash = (string) $evidencePack['pack_hash'];

        $resultBridgeId = $this->resultBridgeId($areaId, $owner, $loopRefs, $evidencePackHash);
        $productModeEventId = $this->productModeEventId($resultBridgeId, $evidencePackHash, $areaId);

        // 2. Evidence Ledger (canonical) — projected unless explicitly recorded.
        $evidenceLedgerStatus = $this->maybeRecordEvidence($evidencePack, $areaId, $resultBridgeId, $recordEvidence, $actor);

        // 3. Inbox item — always a light plan; emitted to the real backend only when requested.
        $inboxItem = $this->buildInboxItemPlan($areaId, $owner, $loopRefs, $result, $status, $resultBridgeId, $evidencePackId, $evidencePackHash, $productModeEventId);
        $inboxItemId = null;
        if ($emitInbox) {
            [$inboxItemId, $inboxItem['inbox_status']] = $this->emitInbox($inboxItem, $result, $loopRefs);
        }

        // 4. Product Mode visibility event.
        $productModeEvent = $this->buildProductModeEvent($productModeEventId, $areaId, $portfolioId, $owner, $loopRefs, $result, $status, $resultBridgeId, $evidencePackId, $evidencePackHash, $evidenceLedgerStatus, $inboxItem, $inboxItemId);
        $productModeEventStatus = 'projected';
        if ($recordEvent) {
            $recorded = $this->productModeEvents->record($productModeEvent);
            $productModeEvent = $recorded;
            $productModeEventStatus = (string) ($recorded['event_storage_status'] ?? 'recorded');
            $productModeEventId = (string) ($recorded['event_id'] ?? $productModeEventId);
        }

        // 5. Portfolio / area health signal (AP-751 feed shape).
        $portfolioFeed = $this->portfolioFeed($areaId, $portfolioId, $status, $resultBridgeId);
        $portfolioSignalId = 'srps_'.substr(MissionCanonicalHash::sha256([$areaId, $resultBridgeId, 'portfolio']), 0, 16);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-765',
            'status' => self::STATUS_READY,
            'mode' => 'evidence_product_mode_runtime_result_bridge',
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-716', 'AP-718', 'AP-720', 'AP-740', 'AP-750', 'AP-751', 'AP-765'],
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'sandbox_id' => $loopRefs['sandbox_id'],
            'loop_refs' => $loopRefs,
            'result_bridge_id' => $resultBridgeId,
            'result_status' => $status,
            'evidence_pack' => $evidencePack,
            'evidence_pack_id' => $evidencePackId,
            'evidence_pack_hash' => $evidencePackHash,
            'evidence_ledger_status' => $evidenceLedgerStatus,
            'inbox_item' => $inboxItem,
            'inbox_item_id' => $inboxItemId,
            'product_mode_event' => $productModeEvent,
            'product_mode_event_id' => $productModeEventId,
            'product_mode_event_status' => $productModeEventStatus,
            'portfolio_signal' => $portfolioFeed,
            'portfolio_signal_id' => $portfolioSignalId,
            'operator_next_step' => $this->operatorNextStep($status, $resultBridgeId),
            'acceptance_options' => $this->acceptanceOptions($resultBridgeId, $areaId, $actor),
            'safety_summary' => $this->safetySummary($irreversibleCheck, $evidencePack),
            'reused_owners' => $this->reusedOwners(),
            'emit_inbox_requested' => $emitInbox,
            'record_evidence_requested' => $recordEvidence,
            'record_event_requested' => $recordEvent,
            'record_cycle_requested' => $recordCycle,
            'claim_policy' => $this->claimPolicy($recordEvidence, $emitInbox, $recordEvent, $recordCycle),
        ];
        $payload['result_bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $recordCycle);
    }

    // ------------------------------------------------------------------
    // Input extraction
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function executionResult(array $input): array
    {
        foreach (['execution_result', 'owner_result', 'owner_runtime_result', 'result', 'result_receipt'] as $key) {
            if (is_array($input[$key] ?? null) && $input[$key] !== []) {
                return $input[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{finding_id:string,finding_ref:string,spec_id:string,spec_ref:string,handoff_id:string,handoff_ref:string,sandbox_id:string,branch_ref:string,worktree_ref:string}
     */
    private function loopRefs(array $input): array
    {
        $result = $this->executionResult($input);

        return [
            'finding_id' => $this->str($input['finding_id'] ?? $result['finding_id'] ?? data_get($result, 'evidence_pack.finding_id', '')),
            'finding_ref' => $this->str($input['finding_ref'] ?? $result['finding_ref'] ?? ''),
            'spec_id' => $this->str($input['spec_id'] ?? $result['spec_id'] ?? data_get($result, 'evidence_pack.spec_id', '')),
            'spec_ref' => $this->str($input['spec_ref'] ?? $result['spec_ref'] ?? ''),
            'handoff_id' => $this->str($input['handoff_id'] ?? $result['handoff_id'] ?? ''),
            'handoff_ref' => $this->str($input['handoff_ref'] ?? $result['handoff_ref'] ?? ''),
            'sandbox_id' => $this->str($input['sandbox_id'] ?? $result['sandbox_id'] ?? data_get($result, 'evidence_pack.sandbox_id', '')),
            'branch_ref' => $this->str($input['branch_ref'] ?? $result['branch_ref'] ?? data_get($result, 'branch_name', '')),
            'worktree_ref' => $this->str($input['worktree_ref'] ?? $result['worktree_ref'] ?? data_get($result, 'worktree_path', '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function owner(array $input): string
    {
        $result = $this->executionResult($input);
        $owner = trim((string) ($input['owner'] ?? $input['target_owner'] ?? $result['owner'] ?? $result['target_owner'] ?? ''));

        return $owner !== '' ? $owner : 'unknown_owner';
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function resultStatus(array $result): string
    {
        return strtolower(trim((string) ($result['result_status'] ?? $result['status'] ?? '')));
    }

    // ------------------------------------------------------------------
    // Gates
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function evidenceCheck(array $result): array
    {
        $pack = is_array($result['evidence_pack'] ?? null) ? $result['evidence_pack'] : [];
        $tests = $this->stringList($result['tests'] ?? $result['tests_run'] ?? data_get($pack, 'tests', data_get($pack, 'tests_run', [])));
        $testResults = $this->normalizeTestResults($result['test_results'] ?? data_get($pack, 'test_results', []));
        $validation = $this->stringList($result['validation_commands'] ?? data_get($pack, 'validation_commands', []));
        $summary = trim((string) ($result['summary'] ?? data_get($pack, 'summary', '')));
        $missing = [];

        if ($tests === [] && $testResults === [] && $validation === []) {
            $missing[] = 'tests_or_test_results_required';
        }
        if ($summary === '') {
            $missing[] = 'summary_required';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap765_result_evidence_check.v1',
            'ok' => $missing === [],
            'test_count' => count($tests),
            'test_result_count' => count($testResults),
            'validation_command_count' => count($validation),
            'has_summary' => $summary !== '',
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $approval
     * @return array<string,mixed>
     */
    private function irreversibleCheck(array $result, array $approval): array
    {
        $flags = [
            'merge_performed' => (bool) ($result['merge_performed'] ?? false),
            'deploy_performed' => (bool) ($result['deploy_performed'] ?? false),
            'external_push_performed' => (bool) ($result['external_push_performed'] ?? $result['pushed_external'] ?? false),
            'secret_access' => (bool) ($result['secret_access'] ?? false),
            'destructive_change' => (bool) ($result['destructive_change'] ?? false),
        ];
        $triggered = array_keys(array_filter($flags));
        $approved = in_array((string) ($approval['decision'] ?? ''), ['approve_irreversible_result', 'approve_merge_deploy_secret'], true)
            && trim((string) ($approval['operator_actor'] ?? '')) !== '';

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap765_irreversible_action_check.v1',
            'ok' => $triggered === [] || $approved,
            'triggered_flags' => $triggered,
            'operator_approval_present' => $approved,
            'approval_actor' => (string) ($approval['operator_actor'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Artifact builders
    // ------------------------------------------------------------------

    /**
     * @param  array<string,string>  $loopRefs
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildEvidencePack(string $areaId, string $owner, array $loopRefs, array $result, string $status): array
    {
        $pack = is_array($result['evidence_pack'] ?? null) ? $result['evidence_pack'] : [];
        $changedFiles = $this->stringList($result['changed_files'] ?? data_get($pack, 'changed_files', []));
        $tests = $this->stringList($result['tests'] ?? $result['tests_run'] ?? data_get($pack, 'tests', data_get($pack, 'tests_run', [])));
        $testResults = $this->normalizeTestResults($result['test_results'] ?? data_get($pack, 'test_results', []));
        $validation = $this->stringList($result['validation_commands'] ?? data_get($pack, 'validation_commands', []));
        $risks = $this->stringList($result['risks'] ?? data_get($pack, 'risks', []));
        $summary = trim((string) ($result['summary'] ?? data_get($pack, 'summary', '')));
        $rollback = trim((string) ($result['rollback'] ?? $result['rollback_instruction'] ?? $result['cleanup'] ?? data_get($pack, 'rollback', '')));
        if ($rollback === '') {
            $rollback = $loopRefs['worktree_ref'] !== '' || $loopRefs['branch_ref'] !== ''
                ? 'Discard the isolated git branch/worktree ('.($loopRefs['branch_ref'] ?: $loopRefs['worktree_ref']).'); no merge was performed, so nothing reaches the main branch.'
                : 'No merge/deploy performed; discard the isolated owner runtime sandbox to roll back.';
        }

        $core = [
            'schema_version' => self::EVIDENCE_PACK_SCHEMA,
            'area_id' => $areaId,
            'owner' => $owner,
            'result_status' => $status,
            'summary' => $summary,
            'finding_refs' => array_values(array_filter([$loopRefs['finding_id'], $loopRefs['finding_ref']], static fn (string $v): bool => $v !== '')),
            'spec_refs' => array_values(array_filter([$loopRefs['spec_id'], $loopRefs['spec_ref']], static fn (string $v): bool => $v !== '')),
            'handoff_refs' => array_values(array_filter([$loopRefs['handoff_id'], $loopRefs['handoff_ref']], static fn (string $v): bool => $v !== '')),
            'branch_refs' => array_values(array_filter([$loopRefs['branch_ref']], static fn (string $v): bool => $v !== '')),
            'worktree_refs' => array_values(array_filter([$loopRefs['worktree_ref'], $loopRefs['sandbox_id']], static fn (string $v): bool => $v !== '')),
            'changed_files' => $changedFiles,
            'tests_run' => $tests,
            'test_results' => $testResults,
            'validation_commands' => $validation,
            'risks' => $risks,
            'rollback' => $rollback,
            'no_auto_merge' => true,
            'operator_review_required' => true,
            'counts' => [
                'changed_file_count' => count($changedFiles),
                'test_count' => count($tests),
                'test_result_count' => count($testResults),
                'validation_command_count' => count($validation),
                'risk_count' => count($risks),
            ],
            'claim_policy' => [
                'evidence_pack_is_projection_of_runtime_result' => true,
                'provider_invoked' => false,
                'opens_branch' => false,
                'mutates_target_repo' => false,
                'merges' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'secrets_in_payload' => false,
            ],
        ];
        $core['pack_id'] = 'srep_'.substr(MissionCanonicalHash::sha256([
            $areaId, $owner, $loopRefs['finding_id'], $loopRefs['spec_id'], $loopRefs['handoff_id'], $loopRefs['sandbox_id'], $changedFiles, $tests,
        ]), 0, 16);
        $core['pack_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);

        return $core;
    }

    /**
     * Build the LIGHT inbox item plan. The list payload is a bridge to detail
     * only (ids + counts + detail command) — never the full changed files,
     * tests or diff, which belong in the context bundle / evidence pack.
     *
     * @param  array<string,string>  $loopRefs
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildInboxItemPlan(string $areaId, string $owner, array $loopRefs, array $result, string $status, string $resultBridgeId, string $evidencePackId, string $evidencePackHash, string $productModeEventId): array
    {
        $summary = trim((string) ($result['summary'] ?? data_get($result, 'evidence_pack.summary', '')));
        $changedFileCount = count($this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', [])));
        $testCount = count($this->stringList($result['tests'] ?? $result['tests_run'] ?? data_get($result, 'evidence_pack.tests', [])));
        $hasPatch = $loopRefs['branch_ref'] !== '' || $loopRefs['worktree_ref'] !== '' || $loopRefs['sandbox_id'] !== '';

        $actions = [
            ['id' => 'mark_reviewed', 'label' => 'Marcar como revisado', 'style' => 'primary'],
            ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
            ['id' => 'snooze', 'label' => 'Adiar', 'style' => 'default'],
            ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
        ];
        if ($hasPatch) {
            array_unshift($actions, ['id' => 'review_patch', 'label' => 'Revisar patch', 'style' => 'primary']);
        }

        return [
            'schema_version' => self::INBOX_ITEM_SCHEMA,
            'type' => 'proposal',
            'category' => 'software_company_stewardship',
            'severity' => $this->severity($status),
            'title' => 'Revisar resultado do runtime '.$owner.' em '.$areaId.' antes de merge/deploy',
            'summary' => $this->shortSummary($summary, $status, $owner),
            'status' => 'unread',
            'operator_review_required' => true,
            'dedupe_key' => 'stewardship:ap765:'.$resultBridgeId,
            'available_actions' => $actions,
            'payload' => [
                'result_bridge_id' => $resultBridgeId,
                'evidence_pack_id' => $evidencePackId,
                'evidence_pack_hash' => $evidencePackHash,
                'product_mode_event_id' => $productModeEventId,
                'area_id' => $areaId,
                'owner' => $owner,
                'sandbox_id' => $loopRefs['sandbox_id'],
                'finding_id' => $loopRefs['finding_id'],
                'spec_id' => $loopRefs['spec_id'],
                'handoff_id' => $loopRefs['handoff_id'],
                'result_status' => $status,
                'changed_file_count' => $changedFileCount,
                'test_count' => $testCount,
                'detail_command' => 'php artisan atlas:software-company-stewardship runtime-result-bridge --area='.$areaId.' --execution='.$resultBridgeId.' --json',
                'no_auto_merge' => true,
                'operator_review_required' => true,
            ],
            'inbox_status' => 'projected',
        ];
    }

    /**
     * @param  array<string,mixed>  $inboxItem
     * @param  array<string,mixed>  $result
     * @param  array<string,string>  $loopRefs
     * @return array{0:int|string|null,1:string}
     */
    private function emitInbox(array $inboxItem, array $result, array $loopRefs): array
    {
        if (! Schema::hasTable('ai_inbox_items') || ! Schema::hasTable('ai_context_bundles')) {
            return [null, 'skipped_missing_inbox_tables'];
        }

        $payload = is_array($inboxItem['payload'] ?? null) ? $inboxItem['payload'] : [];
        $changedFiles = $this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', []));

        /** @var AiInboxItem|null $emitted */
        $emitted = $this->proposalInbox->emit([
            'title' => (string) ($inboxItem['title'] ?? 'Revisar resultado do runtime'),
            'category' => 'software_company_stewardship',
            'problem' => 'O resultado do runtime '.(string) ($payload['owner'] ?? 'owner').' esta pronto para revisao do operador antes de qualquer merge ou deploy.',
            'solution' => 'Revise o evidence pack '.(string) ($payload['evidence_pack_id'] ?? '').', depois aceite/rejeite/adie/peca mudancas. Aceitar nao executa nem faz merge.',
            'worth_it' => 'Fecha o ciclo 24h de stewardship com evidencia revisavel; nenhuma mudanca chega ao main sem o operador.',
            'dedupe_key' => (string) ($inboxItem['dedupe_key'] ?? ''),
            'confidence' => 0.9,
            'source_type' => 'software_company_stewardship',
            // source_id is a UUID column in ai_inbox_items; the result_bridge_id
            // (not a UUID) is carried in source_refs + payload instead.
            'source_id' => null,
            'source_refs' => [
                ['type' => 'stewardship_runtime_result', 'id' => (string) ($payload['result_bridge_id'] ?? '')],
                ['type' => 'evidence_pack', 'id' => (string) ($payload['evidence_pack_id'] ?? '')],
                ['type' => 'product_mode_event', 'id' => (string) ($payload['product_mode_event_id'] ?? '')],
            ],
            'file_refs' => array_map(static fn (string $f): array => ['type' => 'changed_file', 'path' => $f], array_slice($changedFiles, 0, 50)),
            'available_actions' => (array) ($inboxItem['available_actions'] ?? []),
            'payload' => $payload,
            'metadata' => [
                'schema_version' => self::INBOX_ITEM_SCHEMA,
                'review_signal' => [
                    'status' => 'review_required',
                    'severity' => (string) ($inboxItem['severity'] ?? 'info') === 'critical' ? 'high' : 'medium',
                    'recommended_action' => 'mark_reviewed',
                    'reason' => 'AP-765 runtime result requires operator review before any merge/deploy.',
                ],
            ],
        ]);

        return [$emitted?->id, $emitted instanceof AiInboxItem ? 'emitted_or_existing' : 'skipped_missing_inbox_tables'];
    }

    /**
     * @param  array<string,string>  $loopRefs
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $inboxItem
     * @return array<string,mixed>
     */
    private function buildProductModeEvent(string $eventId, string $areaId, string $portfolioId, string $owner, array $loopRefs, array $result, string $status, string $resultBridgeId, string $evidencePackId, string $evidencePackHash, string $evidenceLedgerStatus, array $inboxItem, int|string|null $inboxItemId): array
    {
        return [
            'schema_version' => ProductModeRuntimeResultEventService::EVENT_SCHEMA,
            'event_id' => $eventId,
            'ap_contract' => 'AP-765',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'result_bridge_id' => $resultBridgeId,
            'loop' => [
                'finding_id' => $loopRefs['finding_id'],
                'spec_id' => $loopRefs['spec_id'],
                'handoff_id' => $loopRefs['handoff_id'],
            ],
            'sandbox' => [
                'sandbox_id' => $loopRefs['sandbox_id'],
                'branch_ref' => $loopRefs['branch_ref'],
                'worktree_ref' => $loopRefs['worktree_ref'],
            ],
            'execution' => [
                'execution_id' => $this->str($result['execution_id'] ?? $result['owner_execution_id'] ?? ''),
                'result_status' => $status,
                'runtime_execution_started' => (bool) ($result['runtime_execution_started'] ?? true),
                'provider_invoked' => (bool) ($result['provider_invoked'] ?? false),
            ],
            'result' => [
                'status' => $status,
                'summary' => $this->shortSummary(trim((string) ($result['summary'] ?? '')), $status, $owner),
                'changed_file_count' => count($this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', []))),
                'test_count' => count($this->stringList($result['tests'] ?? $result['tests_run'] ?? data_get($result, 'evidence_pack.tests', []))),
            ],
            'evidence' => [
                'evidence_pack_id' => $evidencePackId,
                'evidence_pack_hash' => $evidencePackHash,
                'evidence_ledger_status' => $evidenceLedgerStatus,
            ],
            'inbox' => [
                'inbox_item_id' => $inboxItemId,
                'dedupe_key' => (string) ($inboxItem['dedupe_key'] ?? ''),
                'status' => 'unread',
                'operator_review_required' => true,
            ],
            'operator_review_required' => true,
            'claim_policy' => [
                'read_only_event' => true,
                'provider_invoked' => false,
                'merges' => false,
                'deploys' => false,
                'touches_secrets' => false,
                'is_new_os' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function portfolioFeed(string $areaId, string $portfolioId, string $status, string $resultBridgeId): array
    {
        return [
            'schema_version' => self::PORTFOLIO_FEED_SCHEMA,
            'source_ap_contracts' => ['AP-765', 'AP-751'],
            'portfolio_id' => $portfolioId,
            'integration' => 'live',
            'consume_command' => 'php artisan atlas:software-company-stewardship portfolio-health --json',
            'areas' => [[
                'area_id' => $areaId,
                'owner_runtime_result_count' => 1,
                'completed_result_count' => (int) ($status === 'completed'),
                'failed_result_count' => (int) in_array($status, ['failed', 'blocked'], true),
                'partial_result_count' => (int) ($status === 'partial'),
                'result_ids' => [$resultBridgeId],
                'result_health_signal' => $status === 'completed' ? 'positive_execution_outcome' : 'followup_required',
                'recommended_portfolio_action' => $status === 'completed'
                    ? 'review_owner_runtime_result_before_merge_or_next_cycle'
                    : 'prioritize_owner_runtime_followup_before_new_allocation',
            ]],
            'claim_policy' => [
                'portfolio_input_only' => true,
                'portfolio_decision_executed' => false,
                'dev_or_forge_invoked' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptanceOptions(string $resultBridgeId, string $areaId, string $actor): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.ap765_acceptance_options.v1',
            'options' => ['accept', 'reject', 'defer', 'request_changes'],
            'accept_executes' => false,
            'accept_requires_owner_execution' => true,
            'auto_approval' => false,
            'decision_owner' => 'AP-731 Stewardship Evolution Decision Ledger',
            'decision_command' => 'php artisan atlas:software-company-stewardship evolution-decision --decision=accept --target-type=runtime_result --target-id='.$resultBridgeId.' --area='.$areaId.' --actor='.($actor !== 'system' ? $actor : '<operator>').' --json',
        ];
    }

    /**
     * @param  array<string,mixed>  $irreversibleCheck
     * @param  array<string,mixed>  $evidencePack
     * @return array<string,mixed>
     */
    private function safetySummary(array $irreversibleCheck, array $evidencePack): array
    {
        return [
            'no_auto_merge' => true,
            'no_auto_deploy' => true,
            'no_secret_access' => true,
            'auto_approval' => false,
            'auto_implementation' => false,
            'operator_review_required' => true,
            'provider_invoked_by_bridge' => false,
            'irreversible_flags_triggered' => (array) ($irreversibleCheck['triggered_flags'] ?? []),
            'irreversible_operator_approval_present' => (bool) ($irreversibleCheck['operator_approval_present'] ?? false),
            'rollback' => (string) ($evidencePack['rollback'] ?? ''),
        ];
    }

    private function operatorNextStep(string $status, string $resultBridgeId): string
    {
        if ($status === 'completed') {
            return 'Revise o evidence pack e o inbox item, depois aceite/rejeite/adie via AP-731. Aceitar nao faz merge nem deploy; a execucao continua sendo do owner runtime.';
        }

        return 'Revise o resultado '.$status.' no inbox; roteie o follow-up por Area Focus, Self-Directed Evolution ou pelo owner runtime. Nao reexecute fora dos owner runtimes.';
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $evidencePack
     */
    private function maybeRecordEvidence(array $evidencePack, string $areaId, string $resultBridgeId, bool $record, string $actor): string
    {
        if (! $record) {
            return 'projected';
        }
        if (! Schema::hasTable('atlas_ledger_events')) {
            return 'skipped_missing_atlas_ledger_events_table';
        }

        $eventId = 'srev_'.substr(MissionCanonicalHash::sha256([$resultBridgeId, (string) ($evidencePack['pack_hash'] ?? '')]), 0, 26);
        if (AtlasLedgerEvent::query()->whereKey($eventId)->exists()) {
            return 'existing';
        }

        $recorded = $this->evidenceLedger->record(LedgerEventType::EvidencePacked, $evidencePack, [
            'event_id' => $eventId,
            'tenant_id' => 'default',
            'operator_id' => $actor !== '' ? $actor : 'system',
            'envelope_id' => 'stewardship_runtime_result:'.$areaId,
            'correlation_id' => 'AP-765:'.$areaId,
            'scope_type' => 'software_company_stewardship',
            'scope_id' => $areaId,
            'emitter_stage' => 'atlas.software_company_stewardship.ap765',
            'emitter_version' => 'AP-765',
        ]);

        return $recorded instanceof AtlasLedgerEvent ? 'recorded' : 'skipped_by_evidence_ledger';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['cycle_storage_status' => 'projected'];
        }

        $path = $this->bridgeFilePath($areaId);
        $existing = $this->findRecord($path, (string) ($payload['result_bridge_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['cycle_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;

        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['cycle_storage_status' => 'recorded'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRecord(string $path, string $resultBridgeId): ?array
    {
        if ($resultBridgeId === '' || ! is_file($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['result_bridge_id'] ?? '') === $resultBridgeId) {
                return $decoded;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Blocked + helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,string>  $loopRefs
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $portfolioId, string $owner, array $loopRefs, string $reason, string $detail, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-765',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'evidence_product_mode_runtime_result_bridge',
            'area_id' => $areaId,
            'portfolio_id' => $portfolioId,
            'owner' => $owner,
            'sandbox_id' => $loopRefs['sandbox_id'],
            'loop_refs' => $loopRefs,
            'reason' => $reason,
            'detail' => $detail,
            'blockers' => [$reason],
            'next_actions' => ['Resolva o blocker AP-765 antes do resultado alimentar Evidence, Inbox, Product Mode ou Portfolio.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false, false, false, false),
        ] + $extra;
        $payload['result_bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function reusedOwners(): array
    {
        return [
            'evidence_ledger' => ['ap' => 'AP-740', 'owner_service' => AtlasEvidenceLedger::class],
            'inbox' => ['ap' => 'AP-740', 'owner_service' => ProposalInboxEmitter::class],
            'product_mode_event' => ['ap' => 'AP-765', 'owner_service' => ProductModeRuntimeResultEventService::class],
            'portfolio' => ['ap' => 'AP-751', 'owner_service' => PortfolioStewardshipHealthModelService::class],
            'owner_runtime_result_bridge' => ['ap' => 'AP-750', 'owner_service' => StewardshipOwnerRuntimeResultBridgeService::class],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recordEvidence, bool $emitInbox, bool $recordEvent, bool $recordCycle): array
    {
        return [
            'mode' => 'evidence_product_mode_runtime_result_bridge',
            'records_evidence_ledger_when_requested' => $recordEvidence,
            'emits_inbox_when_requested' => $emitInbox,
            'records_product_mode_event_when_requested' => $recordEvent,
            'records_cycle_receipt_when_requested' => $recordCycle,
            'no_auto_merge' => true,
            'no_auto_deploy' => true,
            'no_secret_access' => true,
            'auto_approval' => false,
            'auto_implementation' => false,
            'provider_invoked_by_bridge' => false,
            'dev_or_forge_invoked_by_bridge' => false,
            'opens_branch' => false,
            'mutates_target_repo' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'operator_review_required' => true,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
            'parallel_ledger_created' => false,
            'parallel_inbox_created' => false,
        ];
    }

    private function resultBridgeId(string $areaId, string $owner, array $loopRefs, string $evidencePackHash): string
    {
        return 'srrb_'.substr(MissionCanonicalHash::sha256([
            'AP-765', $areaId, $owner, $loopRefs['finding_id'], $loopRefs['spec_id'], $loopRefs['handoff_id'], $loopRefs['sandbox_id'], $evidencePackHash,
        ]), 0, 18);
    }

    private function productModeEventId(string $resultBridgeId, string $evidencePackHash, string $areaId): string
    {
        return 'pmre_'.substr(MissionCanonicalHash::sha256(['AP-765', $resultBridgeId, $evidencePackHash, $areaId]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['result_bridge_hash'], $copy['cycle_storage_status'], $copy['recorded_at']);

        return $copy;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function areaId(array $input): string
    {
        $value = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));

        return $value !== '' ? $this->slug($value) : self::DEFAULT_AREA_ID;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function portfolioId(array $input): string
    {
        $value = trim((string) ($input['portfolio_id'] ?? $input['portfolio'] ?? ''));

        return $value !== '' ? $this->slug($value) : self::DEFAULT_PORTFOLIO_ID;
    }

    private function shortSummary(string $summary, string $status, string $owner): string
    {
        if ($summary === '') {
            $summary = 'Runtime '.$owner.' terminou com status '.$status.'.';
        }
        $summary = trim((string) preg_replace('/\s+/', ' ', $summary));

        return mb_strlen($summary) > 180 ? mb_substr($summary, 0, 177).'...' : $summary;
    }

    private function severity(string $status): string
    {
        return match ($status) {
            'failed', 'blocked' => 'critical',
            'partial' => 'warning',
            default => 'info',
        };
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function normalizeTestResults(mixed $value): array
    {
        $out = [];
        if (is_string($value) && trim($value) !== '') {
            return [['summary' => trim($value)]];
        }
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            } elseif (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = ['summary' => trim((string) $item)];
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
