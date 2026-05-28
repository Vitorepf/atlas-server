<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * AP-790 · append-only quarantine ledger for area/focus loop candidates.
 *
 * Findings that waste provider/sandbox budget or fail repair policy are recorded
 * here so AP-786 selection and AP-790 duplicate protection never repeat them.
 * Historical session JSONL remains authoritative for audit; this ledger is the
 * fast machine-readable skip list for the 24h runner.
 */
final class AreaFocusCandidateQuarantineService
{
    public const SCHEMA = 'atlas.software_company_stewardship.ap790_candidate_quarantine.v1';

    public const FAILED_GATE_CAPSULE_SCHEMA = 'atlas.software_company_stewardship.ap786_failed_gate_capsule.v1';

    /** Blockers that permanently skip re-selection until operator clears quarantine. */
    public const PERMANENT_BLOCKERS = [
        'owner_runtime_no_patch_needed',
        'owner_runtime_result_failed',
        'owner_runtime_senior_loop_execution_not_passed',
        'owner_runtime_routing_not_executable',
        'branch_already_merged_or_ancestor_of_base',
        'provider_scope_violation',
        'finding_false_positive',
        'ap748_false_positive',
        'ap748_interface_false_positive_test',
    ];

    /** Blockers quarantined after controlled repair is exhausted. */
    public const POST_REPAIR_BLOCKERS = [
        'validation_failed',
    ];

    /**
     * Transient infrastructure blockers. These describe the attempt failing for
     * environmental reasons (provider/runtime never produced a verdict), NOT a
     * property of the finding. A finding must never be permanently quarantined
     * on a transient blocker — doing so burns good work whenever the provider
     * times out or rate-limits. Historically a provider timeout co-occurred with
     * `owner_runtime_senior_loop_execution_not_passed`, so a single 120s timeout
     * permanently quarantined real findings and starved the loop into synthetic
     * recovery work. Transient blockers take precedence: the attempt is retried.
     */
    public const TRANSIENT_BLOCKERS = [
        'owner_runtime_provider_timeout',
        'owner_runtime_provider_rate_limited',
        'owner_runtime_provider_unavailable',
    ];

    private const ROUTING_RETRY_AFTER_SECONDS = 600;

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
            ? storage_path('atlas/software_company_stewardship/area_focus_candidate_quarantine')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_candidate_quarantine';
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->key($areaId, $focus).'.jsonl';
    }

    /**
     * @return array<string,true>
     */
    public function quarantinedFindingKeys(string $areaId, string $focus): array
    {
        $keys = [];
        foreach ($this->readEntries($areaId, $focus) as $entry) {
            if (! $this->entryIsActive($entry)) {
                continue;
            }
            foreach ($this->entryFindingKeys($entry) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    public function isQuarantined(array $finding, string $areaId, string $focus): bool
    {
        $locked = $this->quarantinedFindingKeys($areaId, $focus);
        foreach ($this->findingKeys($finding) as $key) {
            if (isset($locked[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     */
    public function shouldQuarantine(array $blockers, array $cycle = []): bool
    {
        if ($blockers === []) {
            return false;
        }
        // A transient infra failure (provider timeout / rate limit / outage)
        // means the attempt never produced a real verdict, so it must never
        // permanently quarantine the finding — it is retried on a later cycle.
        if ($this->hasTransientBlocker($blockers)) {
            return false;
        }
        foreach ($blockers as $blocker) {
            if ($this->isQuarantineBlocker((string) $blocker)) {
                return true;
            }
        }
        if (($cycle['quarantine_after_repair_exhausted'] ?? false) === true) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $blockers
     */
    public function hasTransientBlocker(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (in_array((string) $blocker, self::TRANSIENT_BLOCKERS, true)) {
                return true;
            }
        }

        return false;
    }

    public function isQuarantineBlocker(string $blocker): bool
    {
        return in_array($blocker, self::PERMANENT_BLOCKERS, true)
            || in_array($blocker, self::POST_REPAIR_BLOCKERS, true);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{action:string,max_retries:int,emit_failure_capsule:bool,stop_session:bool,reason:string}
     */
    public function repairPolicyForBlockers(array $blockers): array
    {
        if (in_array('provider_scope_violation', $blockers, true)) {
            return [
                'action' => 'quarantine_stop',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => true,
                'reason' => 'scope_violation',
            ];
        }
        if (in_array('owner_runtime_routing_not_executable', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'routing_not_executable',
            ];
        }
        if (in_array('owner_runtime_no_patch_needed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'no_patch_needed',
            ];
        }
        if (in_array('owner_runtime_result_failed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'owner_runtime_result_failed',
            ];
        }
        if (in_array('validation_failed', $blockers, true)) {
            return [
                'action' => 'retry_then_quarantine',
                'max_retries' => 1,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'validation_failed',
            ];
        }
        if (in_array('owner_runtime_senior_loop_execution_not_passed', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => true,
                'stop_session' => false,
                'reason' => 'senior_loop_execution_not_passed',
            ];
        }
        if (in_array('branch_already_merged_or_ancestor_of_base', $blockers, true)) {
            return [
                'action' => 'quarantine_continue',
                'max_retries' => 0,
                'emit_failure_capsule' => false,
                'stop_session' => false,
                'reason' => 'branch_already_merged_or_ancestor_of_base',
            ];
        }
        foreach (['finding_false_positive', 'ap748_false_positive', 'ap748_interface_false_positive_test'] as $falsePositive) {
            if (in_array($falsePositive, $blockers, true)) {
                return [
                    'action' => 'quarantine_continue',
                    'max_retries' => 0,
                    'emit_failure_capsule' => false,
                    'stop_session' => false,
                    'reason' => 'false_positive',
                ];
            }
        }

        return [
            'action' => 'none',
            'max_retries' => 0,
            'emit_failure_capsule' => false,
            'stop_session' => false,
            'reason' => 'not_repairable',
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function appendFromCycle(string $areaId, string $focus, array $finding, array $blockers, array $context = []): array
    {
        $primaryBlocker = $this->primaryBlocker($blockers);
        $retryAfter = $context['retry_after'] ?? null;
        if ($retryAfter === null && $primaryBlocker === 'owner_runtime_routing_not_executable') {
            $retryAfter = $this->retryAfter(self::ROUTING_RETRY_AFTER_SECONDS);
        }
        $permanent = $retryAfter === null || $retryAfter === 'permanent';

        $entry = [
            'schema_version' => self::SCHEMA,
            'quarantine_id' => 'afq_'.substr(MissionCanonicalHash::sha256([
                $areaId, $focus, $this->findingKeys($finding), $primaryBlocker, $this->now(),
            ]), 0, 18),
            'area_id' => $areaId,
            'focus' => $focus,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'blocker' => $primaryBlocker,
            'blockers' => array_values(array_unique($blockers)),
            'owner' => (string) ($context['owner'] ?? ''),
            'branch_ref' => (string) ($context['branch_ref'] ?? ''),
            'worktree_path' => (string) ($context['worktree_path'] ?? ''),
            'failure_signature' => $this->failureSignature($blockers, $context),
            'retry_after' => $permanent ? 'permanent' : (string) $retryAfter,
            'reason' => (string) ($context['reason'] ?? $this->repairPolicyForBlockers($blockers)['reason']),
            'recorded_at' => $this->now(),
        ];
        $entry['entry_hash'] = 'sha256:'.MissionCanonicalHash::sha256($entry);
        $this->append($areaId, $focus, $entry);

        return $entry;
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $context
     */
    public function failureSignature(array $blockers, array $context = []): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256([
            'blockers' => array_values(array_unique($blockers)),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'branch_ref' => (string) ($context['branch_ref'] ?? ''),
            'sandbox_id' => (string) ($context['sandbox_id'] ?? ''),
            'post_execution_skip' => (string) ($context['post_execution_skip'] ?? ''),
        ]);
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function buildFailureCapsule(array $blockers, array $finding, array $allowedFiles = [], array $changedFiles = []): array
    {
        $primary = $this->primaryBlocker($blockers);
        $capsule = [
            'schema_version' => self::FAILED_GATE_CAPSULE_SCHEMA,
            'failure_class' => $primary,
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
            'title' => (string) ($finding['title'] ?? ''),
            'allowed_files' => $allowedFiles,
            'changed_files' => $changedFiles,
            'blockers' => array_values(array_unique($blockers)),
            'root_cause_present' => $primary !== '',
            'generated_at' => $this->now(),
        ];
        $capsule['capsule_hash'] = 'sha256:'.MissionCanonicalHash::sha256($capsule);

        return $capsule;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    public function append(string $areaId, string $focus, array $entry): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readEntries(string $areaId, string $focus): array
    {
        $path = $this->ledgerPath($areaId, $focus);
        if (! is_file($path)) {
            return [];
        }
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === self::SCHEMA) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    private function entryFindingKeys(array $entry): array
    {
        return array_values(array_unique(array_filter([
            (string) ($entry['finding_id'] ?? ''),
            (string) ($entry['finding_hash'] ?? ''),
            (string) ($entry['title'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return list<string>
     */
    private function findingKeys(array $finding): array
    {
        return array_values(array_unique(array_filter([
            (string) ($finding['finding_id'] ?? ''),
            (string) ($finding['finding_hash'] ?? ''),
            (string) ($finding['title'] ?? ''),
        ], static fn (string $value): bool => $value !== '')));
    }

    /** @param list<string> $blockers */
    private function primaryBlocker(array $blockers): string
    {
        foreach (array_merge(self::PERMANENT_BLOCKERS, self::POST_REPAIR_BLOCKERS) as $known) {
            if (in_array($known, $blockers, true)) {
                return $known;
            }
        }

        return (string) ($blockers[0] ?? 'unknown_blocker');
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function entryIsActive(array $entry): bool
    {
        $retryAfter = (string) ($entry['retry_after'] ?? 'permanent');
        if ($retryAfter !== '' && $retryAfter !== 'permanent') {
            $retryAt = strtotime($retryAfter) ?: 0;

            return $retryAt === 0 || $retryAt > time();
        }

        if ((string) ($entry['blocker'] ?? '') !== 'owner_runtime_routing_not_executable') {
            return true;
        }

        $recordedAt = strtotime((string) ($entry['recorded_at'] ?? '')) ?: 0;
        if ($recordedAt === 0) {
            return true;
        }

        return ($recordedAt + self::ROUTING_RETRY_AFTER_SECONDS) > time();
    }

    private function key(string $areaId, string $focus): string
    {
        $slug = static fn (string $value): string => trim(strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: ''), '_');

        return $slug($areaId).'__'.$slug($focus);
    }

    private function retryAfter(int $seconds): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+'.$seconds.' seconds')
            ->format(DateTimeInterface::ATOM);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
