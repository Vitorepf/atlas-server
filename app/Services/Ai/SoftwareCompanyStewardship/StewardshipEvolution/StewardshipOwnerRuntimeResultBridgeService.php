<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-750 · owner runtime result bridge.
 *
 * Bridges Atlas Dev / Forge owner execution results back into Stewardship
 * Evidence, Morning Inbox and Portfolio signals. It never invokes Dev/Forge,
 * creates branches, merges, deploys, pushes externally or touches secrets.
 */
final class StewardshipOwnerRuntimeResultBridgeService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.owner_runtime_result_bridge.v1';

    public const RESULT_SCHEMA = 'atlas.software_company_stewardship.owner_runtime_result.v1';

    public const RECORD_SCHEMA = 'atlas.software_company_stewardship.owner_runtime_result_record.v1';

    public const STATUS_READY = 'ready_for_operator_result_review';

    public const STATUS_RECORDED = 'owner_runtime_result_recorded';

    public const STATUS_BLOCKED = 'blocked';

    private ?string $storageRootOverride = null;

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
            ? storage_path('atlas/software_company_stewardship/owner_runtime_results')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/owner_runtime_results';
    }

    public function resultFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array
    {
        $consumption = $this->consumption($input);
        if ($consumption === []) {
            return $this->blocked('agentic_engineering_os', 'ap749_consumption_required', 'AP-750 requires an AP-749 owner queue consumption report or record.');
        }

        $areaId = trim((string) ($consumption['area_id'] ?? $input['area_id'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';

        if (! $this->isAp749Consumption($consumption)) {
            return $this->blocked($areaId, 'ap749_consumption_required', 'AP-750 can bridge only AP-749 owner queue consumption reports or records.', $consumption);
        }

        if (! in_array((string) ($consumption['status'] ?? ''), [
            AreaFocusOwnerQueueConsumptionGateService::STATUS_READY,
            AreaFocusOwnerQueueConsumptionGateService::STATUS_RECORDED,
        ], true)) {
            return $this->blocked($areaId, 'ap749_consumption_not_ready', 'AP-749 must be ready or recorded before AP-750 can accept owner runtime results.', $consumption);
        }

        $result = $this->ownerResult($input);
        if ($result === []) {
            return $this->blocked($areaId, 'owner_runtime_result_required', 'AP-750 requires an owner runtime result receipt from Atlas Dev or Forge.', $consumption);
        }

        $identityCheck = $this->identityCheck($consumption, $result);
        if ($identityCheck['ok'] !== true) {
            return $this->blocked($areaId, 'owner_runtime_result_identity_mismatch', 'Owner runtime result must match AP-749 consumption id, queue item and owner.', $consumption, [
                'identity_check' => $identityCheck,
            ]);
        }

        $evidenceCheck = $this->evidenceCheck($result);
        if ($evidenceCheck['ok'] !== true) {
            return $this->blocked($areaId, 'owner_runtime_result_evidence_incomplete', 'Owner runtime result must include an evidence pack before it can feed Stewardship outcomes.', $consumption, [
                'evidence_check' => $evidenceCheck,
            ]);
        }

        $isolationCheck = $this->isolationCheck($consumption, $result);
        if ($isolationCheck['ok'] !== true) {
            return $this->blocked($areaId, 'owner_runtime_result_isolation_violation', 'Owner runtime result changed files outside the AP-749 isolation boundary.', $consumption, [
                'isolation_check' => $isolationCheck,
            ]);
        }

        $irreversibleCheck = $this->irreversibleCheck($result, is_array($input['irreversible_approval_receipt'] ?? null) ? $input['irreversible_approval_receipt'] : []);
        if ($irreversibleCheck['ok'] !== true) {
            return $this->blocked($areaId, 'irreversible_action_without_operator_approval', 'Merge, deploy, external push, secrets or destructive changes require an explicit operator approval receipt.', $consumption, [
                'irreversible_check' => $irreversibleCheck,
            ]);
        }

        $record = (bool) ($input['record_result'] ?? false);
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-750',
            'status' => self::STATUS_READY,
            'mode' => 'owner_runtime_result_bridge',
            'area_id' => $areaId,
            'portfolio_id' => (string) ($input['portfolio_id'] ?? $result['portfolio_id'] ?? 'atlas_software_company'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-750'],
            'consumption_id' => $this->consumptionId($consumption),
            'release_id' => (string) ($consumption['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'target_owner' => (string) ($consumption['target_owner'] ?? ''),
            'target_runtime_schema' => (string) ($consumption['target_runtime_schema'] ?? data_get($consumption, 'owner_runtime_input.target_runtime_schema', '')),
            'owner_result_id' => $this->ownerResultId($result),
            'owner_result_status' => (string) ($result['result_status'] ?? $result['status'] ?? ''),
            'owner_result_summary' => $this->resultSummary($result),
            'identity_check' => $identityCheck,
            'evidence_check' => $evidenceCheck,
            'isolation_check' => $isolationCheck,
            'irreversible_check' => $irreversibleCheck,
            'record_result_requested' => $record,
            'evidence_items' => $this->evidenceItems($consumption, $result),
            'morning_inbox_items' => $this->morningInboxItems($consumption, $result),
            'portfolio_feed' => $this->portfolioFeed($areaId, (string) ($input['portfolio_id'] ?? $result['portfolio_id'] ?? 'atlas_software_company'), $result),
            'next_actions' => $this->nextActions($result),
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy($record),
        ];
        $payload['result_bridge_id'] = $this->resultBridgeId($payload);
        $payload['result_bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));
        $payload['generated_at'] = $this->now();

        return $this->maybeRecord($areaId, $payload, $record);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function consumption(array $input): array
    {
        foreach (['consumption_report', 'consumption_record', 'owner_queue_consumption'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        foreach (['consumption_reports', 'consumption_records'] as $key) {
            foreach ((array) ($input[$key] ?? []) as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function ownerResult(array $input): array
    {
        foreach (['owner_result', 'owner_runtime_result', 'execution_result', 'result_receipt'] as $key) {
            if (is_array($input[$key] ?? null)) {
                return $input[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $consumption
     */
    private function isAp749Consumption(array $consumption): bool
    {
        return (string) ($consumption['ap_contract'] ?? '') === 'AP-749'
            || in_array((string) ($consumption['schema_version'] ?? ''), [
                AreaFocusOwnerQueueConsumptionGateService::REPORT_SCHEMA,
                AreaFocusOwnerQueueConsumptionGateService::RECORD_SCHEMA,
            ], true);
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function identityCheck(array $consumption, array $result): array
    {
        $expectedConsumption = $this->consumptionId($consumption);
        $expectedQueue = (string) ($consumption['queue_item_id'] ?? '');
        $expectedOwner = (string) ($consumption['target_owner'] ?? '');
        $expectedRelease = (string) ($consumption['release_id'] ?? '');
        $actualSchema = (string) ($result['schema_version'] ?? '');
        $actualConsumption = (string) ($result['consumption_id'] ?? $result['target_consumption_id'] ?? '');
        $actualQueue = (string) ($result['queue_item_id'] ?? $result['target_queue_item_id'] ?? '');
        $actualOwner = (string) ($result['target_owner'] ?? $result['owner'] ?? '');
        $actualRelease = (string) ($result['release_id'] ?? $result['target_release_id'] ?? '');
        $status = (string) ($result['result_status'] ?? $result['status'] ?? '');
        $missing = [];

        foreach ([
            'consumption_id' => $expectedConsumption,
            'release_id' => $expectedRelease,
            'queue_item_id' => $expectedQueue,
            'target_owner' => $expectedOwner,
        ] as $field => $value) {
            if ($value === '') {
                $missing[] = 'ap749_'.$field.'_missing';
            }
        }

        foreach ([
            'consumption_id' => $actualConsumption,
            'release_id' => $actualRelease,
            'queue_item_id' => $actualQueue,
            'target_owner' => $actualOwner,
        ] as $field => $value) {
            if ($value === '') {
                $missing[] = 'owner_result_'.$field.'_required';
            }
        }

        if ($actualSchema !== self::RESULT_SCHEMA) {
            $missing[] = 'owner_result_schema_version_invalid';
        }

        if ($actualConsumption !== '' && $actualConsumption !== $expectedConsumption) {
            $missing[] = 'consumption_id_mismatch';
        }
        if ($actualQueue !== '' && $actualQueue !== $expectedQueue) {
            $missing[] = 'queue_item_id_mismatch';
        }
        if ($actualOwner !== '' && $actualOwner !== $expectedOwner) {
            $missing[] = 'target_owner_mismatch';
        }
        if ($actualRelease !== '' && $actualRelease !== $expectedRelease) {
            $missing[] = 'release_id_mismatch';
        }
        if (! in_array($status, ['completed', 'failed', 'blocked', 'partial'], true)) {
            $missing[] = 'result_status_invalid';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap750_identity_check.v1',
            'ok' => $missing === [],
            'expected' => [
                'consumption_id' => $expectedConsumption,
                'release_id' => $expectedRelease,
                'queue_item_id' => $expectedQueue,
                'target_owner' => $expectedOwner,
            ],
            'actual' => [
                'schema_version' => $actualSchema,
                'consumption_id' => $actualConsumption,
                'release_id' => $actualRelease,
                'queue_item_id' => $actualQueue,
                'target_owner' => $actualOwner,
                'result_status' => $status,
            ],
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function evidenceCheck(array $result): array
    {
        $pack = is_array($result['evidence_pack'] ?? null) ? $result['evidence_pack'] : [];
        $tests = $this->stringList($result['tests'] ?? data_get($pack, 'tests', []));
        $missing = [];
        if ($pack === []) {
            $missing[] = 'evidence_pack_required';
        }
        if ((string) ($pack['evidence_hash'] ?? $result['evidence_hash'] ?? '') === '') {
            $missing[] = 'evidence_hash_required';
        }
        if ((string) ($pack['summary'] ?? $result['summary'] ?? '') === '') {
            $missing[] = 'summary_required';
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap750_result_evidence_check.v1',
            'ok' => $missing === [],
            'evidence_hash' => (string) ($pack['evidence_hash'] ?? $result['evidence_hash'] ?? ''),
            'test_count' => count($tests),
            'tests' => $tests,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function isolationCheck(array $consumption, array $result): array
    {
        $allowed = $this->stringList(data_get($consumption, 'branch_isolation.allowed_paths', []));
        $forbidden = $this->stringList(data_get($consumption, 'branch_isolation.forbidden_paths', ['.env']));
        $changed = $this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', []));
        $violations = [];

        if ($allowed === []) {
            $violations[] = 'allowed_paths_missing_from_ap749';
        }

        foreach ($changed as $file) {
            if (! $this->isAllowedPath($file, $allowed)) {
                $violations[] = 'changed_file_outside_allowed_paths:'.$file;
            }
            if ($this->isForbiddenPath($file, $forbidden)) {
                $violations[] = 'changed_file_in_forbidden_path:'.$file;
            }
        }

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap750_isolation_check.v1',
            'ok' => $violations === [],
            'allowed_paths' => $allowed,
            'forbidden_paths' => $forbidden,
            'changed_files' => $changed,
            'violations' => $violations,
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
            'schema_version' => 'atlas.software_company_stewardship.ap750_irreversible_action_check.v1',
            'ok' => $triggered === [] || $approved,
            'triggered_flags' => $triggered,
            'operator_approval_present' => $approved,
            'approval_actor' => (string) ($approval['operator_actor'] ?? ''),
            'merge_deploy_secrets_destructive_without_operator' => $triggered !== [] && ! $approved,
        ];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $result
     * @return list<array<string,mixed>>
     */
    private function evidenceItems(array $consumption, array $result): array
    {
        $payload = [
            'schema_version' => StewardshipOutcomeEvidenceBridgeService::EVIDENCE_SCHEMA,
            'source_kind' => 'ap750_owner_runtime_result',
            'source_ap_contract' => 'AP-750',
            'bridge_ap_contract' => 'AP-750',
            'area_id' => (string) ($consumption['area_id'] ?? 'agentic_engineering_os'),
            'portfolio_id' => (string) ($result['portfolio_id'] ?? 'atlas_software_company'),
            'consumption_id' => $this->consumptionId($consumption),
            'release_id' => (string) ($consumption['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'target_owner' => (string) ($consumption['target_owner'] ?? ''),
            'owner_result_id' => $this->ownerResultId($result),
            'owner_result_status' => (string) ($result['result_status'] ?? $result['status'] ?? ''),
            'evidence_hash' => (string) data_get($result, 'evidence_pack.evidence_hash', $result['evidence_hash'] ?? ''),
            'changed_files' => $this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', [])),
            'tests' => $this->stringList($result['tests'] ?? data_get($result, 'evidence_pack.tests', [])),
            'operator_review_required' => true,
            'auto_merge_allowed' => false,
            'auto_deploy_allowed' => false,
            'secret_access_allowed' => false,
        ];
        $sourceHash = 'sha256:'.MissionCanonicalHash::sha256($payload);

        return [[
            'schema_version' => StewardshipOutcomeEvidenceBridgeService::EVIDENCE_SCHEMA,
            'event_id' => 'scoev_'.substr(MissionCanonicalHash::sha256(['AP-750', $payload['owner_result_id'], $sourceHash]), 0, 26),
            'event_type' => 'EVIDENCE_PACKED',
            'source_kind' => 'ap750_owner_runtime_result',
            'source_id' => (string) $payload['owner_result_id'],
            'source_hash' => $sourceHash,
            'payload_hash' => MissionCanonicalHash::sha256($payload),
            'ledger_status' => 'projected',
            'payload' => $payload,
        ]];
    }

    /**
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $result
     * @return list<array<string,mixed>>
     */
    private function morningInboxItems(array $consumption, array $result): array
    {
        $resultId = $this->ownerResultId($result);

        return [[
            'schema_version' => StewardshipOutcomeEvidenceBridgeService::MORNING_INBOX_SCHEMA,
            'kind' => 'ap750_owner_runtime_result_review',
            'result_id' => $resultId,
            'consumption_id' => $this->consumptionId($consumption),
            'release_id' => (string) ($consumption['release_id'] ?? ''),
            'queue_item_id' => (string) ($consumption['queue_item_id'] ?? ''),
            'target_owner' => (string) ($consumption['target_owner'] ?? ''),
            'dedupe_key' => 'stewardship:ap750:'.$resultId,
            'title' => 'Review owner runtime result before merge/deploy/follow-up',
            'recommended_action' => 'review_owner_runtime_result_before_merge_deploy_or_followup',
            'risk_level' => $this->riskLevel($result),
            'operator_review_required' => true,
            'autoimplementation_allowed' => false,
            'irreversible_action_allowed' => false,
            'inbox_status' => 'projected',
        ]];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function portfolioFeed(string $areaId, string $portfolioId, array $result): array
    {
        $status = (string) ($result['result_status'] ?? $result['status'] ?? '');

        return [
            'schema_version' => 'atlas.software_company.stewardship_owner_runtime_result_portfolio_feed.v1',
            'portfolio_id' => $portfolioId,
            'areas' => [[
                'area_id' => $areaId,
                'owner_runtime_result_count' => 1,
                'completed_result_count' => (int) ($status === 'completed'),
                'failed_result_count' => (int) in_array($status, ['failed', 'blocked'], true),
                'partial_result_count' => (int) ($status === 'partial'),
                'result_health_signal' => $status === 'completed' ? 'positive_execution_outcome' : 'followup_required',
                'recommended_portfolio_action' => $status === 'completed'
                    ? 'review_completed_owner_runtime_result_for_merge_or_next_cycle'
                    : 'prioritize_owner_runtime_followup_before_new_allocation',
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function resultSummary(array $result): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.owner_runtime_result_summary.v1',
            'result_status' => (string) ($result['result_status'] ?? $result['status'] ?? ''),
            'summary' => (string) ($result['summary'] ?? data_get($result, 'evidence_pack.summary', '')),
            'changed_file_count' => count($this->stringList($result['changed_files'] ?? data_get($result, 'evidence_pack.changed_files', []))),
            'test_count' => count($this->stringList($result['tests'] ?? data_get($result, 'evidence_pack.tests', []))),
            'branch_created' => (bool) ($result['branch_created'] ?? false),
            'runtime_execution_started' => (bool) ($result['runtime_execution_started'] ?? true),
            'provider_invoked' => (bool) ($result['provider_invoked'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return list<string>
     */
    private function nextActions(array $result): array
    {
        $status = (string) ($result['result_status'] ?? $result['status'] ?? '');
        if ($status === 'completed') {
            return [
                'Review AP-750 Evidence and Morning Inbox item before any merge, deploy or external push.',
                'Feed the portfolio signal into AP-733/AP-734 before allocating the next Area Stewardship cycle.',
            ];
        }

        return [
            'Review AP-750 failure/partial result in Morning Inbox.',
            'Route follow-up through Area Focus, Self-Directed Evolution, Atlas Dev or Forge; do not retry outside owner runtimes.',
        ];
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function reusedOwners(): array
    {
        return [
            'owner_consumption_gate' => ['ap' => 'AP-749', 'owner_service' => AreaFocusOwnerQueueConsumptionGateService::class],
            'outcome_bridge' => ['ap' => 'AP-740/AP-748', 'owner_service' => StewardshipOutcomeEvidenceBridgeService::class],
            'evidence' => ['ap' => 'AP-740', 'role' => 'canonical evidence item schema'],
            'morning_inbox' => ['ap' => 'AP-740', 'role' => 'canonical morning inbox item schema'],
            'portfolio' => ['ap' => 'AP-733/AP-734', 'role' => 'portfolio feed consumer'],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(bool $recorded): array
    {
        return [
            'mode' => 'owner_runtime_result_bridge',
            'requires_ap749_consumption' => true,
            'requires_owner_runtime_result_receipt' => true,
            'requires_evidence_pack' => true,
            'result_recorded_when_requested' => $recorded,
            'owner_runtime_invoked_by_bridge' => false,
            'provider_invoked_by_bridge' => false,
            'branch_created_by_bridge' => false,
            'worktree_created_by_bridge' => false,
            'target_repo_mutated_by_bridge' => false,
            'merge_performed_by_bridge' => false,
            'deploy_performed_by_bridge' => false,
            'pushed_external_by_bridge' => false,
            'secret_access_by_bridge' => false,
            'destructive_change_by_bridge' => false,
            'parallel_runtime_created' => false,
            'new_os_created' => false,
            'operator_review_required_before_irreversible_action' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function maybeRecord(string $areaId, array $payload, bool $record): array
    {
        if (! $record) {
            return $payload + ['result_storage_status' => 'projected'];
        }

        if (($payload['status'] ?? '') !== self::STATUS_READY) {
            return $payload + ['result_storage_status' => 'not_recorded_until_ready'];
        }

        $path = $this->resultFilePath($areaId);
        File::ensureDirectoryExists(dirname($path));
        $existing = $this->findRecord($path, (string) ($payload['result_bridge_id'] ?? ''));
        if ($existing !== null) {
            return $existing + ['result_storage_status' => 'existing'];
        }

        $recordPayload = [
            'schema_version' => self::RECORD_SCHEMA,
            'recorded_at' => $this->now(),
        ] + $payload;
        $recordPayload['status'] = self::STATUS_RECORDED;
        File::append($path, json_encode($recordPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return $recordPayload + ['result_storage_status' => 'recorded'];
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

    /**
     * @param  array<string,mixed>  $source
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(string $areaId, string $reason, string $detail, array $source = [], array $extra = []): array
    {
        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-750',
            'status' => self::STATUS_BLOCKED,
            'mode' => 'owner_runtime_result_bridge',
            'area_id' => $areaId,
            'consumption_id' => $this->consumptionId($source),
            'release_id' => (string) ($source['release_id'] ?? ''),
            'queue_item_id' => (string) ($source['queue_item_id'] ?? ''),
            'target_owner' => (string) ($source['target_owner'] ?? ''),
            'reason' => $reason,
            'detail' => $detail,
            'source_ap_contracts' => ['AP-747', 'AP-748', 'AP-749', 'AP-750'],
            'blockers' => [$reason],
            'next_actions' => ['Resolve AP-750 blocker before the owner runtime result feeds Evidence, Inbox or Portfolio.'],
            'reused_owners' => $this->reusedOwners(),
            'claim_policy' => $this->claimPolicy(false),
        ] + $extra;
        $payload['result_bridge_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $this->now();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $consumption
     */
    private function consumptionId(array $consumption): string
    {
        return (string) ($consumption['consumption_id'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function ownerResultId(array $result): string
    {
        $existing = trim((string) ($result['result_id'] ?? $result['owner_result_id'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return 'afres_'.substr(MissionCanonicalHash::sha256([
            'AP-750',
            (string) ($result['consumption_id'] ?? ''),
            (string) ($result['queue_item_id'] ?? ''),
            (string) ($result['result_status'] ?? $result['status'] ?? ''),
            (string) data_get($result, 'evidence_pack.evidence_hash', $result['evidence_hash'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resultBridgeId(array $payload): string
    {
        return 'afobr_'.substr(MissionCanonicalHash::sha256([
            'AP-750',
            (string) ($payload['consumption_id'] ?? ''),
            (string) ($payload['owner_result_id'] ?? ''),
            (string) ($payload['target_owner'] ?? ''),
        ]), 0, 18);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        $copy = $payload;
        unset($copy['generated_at'], $copy['result_bridge_hash'], $copy['result_storage_status'], $copy['recorded_at']);

        return $copy;
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

    /**
     * @param  list<string>  $allowed
     */
    private function isAllowedPath(string $file, array $allowed): bool
    {
        foreach ($allowed as $prefix) {
            $normalized = rtrim($prefix, '/');
            if ($file === $normalized || str_starts_with($file, $normalized.'/')) {
                return true;
            }
        }

        return $allowed === [] && $file !== '';
    }

    /**
     * @param  list<string>  $forbidden
     */
    private function isForbiddenPath(string $file, array $forbidden): bool
    {
        foreach ($forbidden as $prefix) {
            $normalized = trim($prefix, '/');
            if ($normalized !== '' && ($file === $normalized || str_starts_with($file, $normalized.'/') || str_contains($file, '/'.$normalized.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function riskLevel(array $result): string
    {
        $status = (string) ($result['result_status'] ?? $result['status'] ?? '');

        return match ($status) {
            'completed' => 'high',
            'partial' => 'high',
            default => 'critical',
        };
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: 'agentic_engineering_os';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
