<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AreaFocusLoopReadModelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Software Company Stewardship Stack · Area Focus Loop ·
 * Durable Cycle Recorder (Slice 5, AP-720).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This service
 * records a read-only Area Focus projection ({@see AreaFocusLoopReadModelService},
 * AP-712) as an append-only JSONL cycle so the operator can replay, inspect and
 * deliver it to the Morning Inbox without re-running the scan.
 *
 * Hard invariants:
 *   - NEVER opens a branch, NEVER invokes a provider, NEVER merges/deploys,
 *     NEVER touches secrets and NEVER mutates the target repo. The only side
 *     effect is appending local runtime JSONL under storage/.
 *   - The persisted `input_digest` is a whitelist of safe scalars/flags; raw
 *     overrides and any secret-like values are never written.
 *   - `cycle_id`/`cycle_hash` are deterministic from cycle content (exclude
 *     wall-clock), so recording is idempotent (dedup on cycle_id).
 */
class AreaFocusCycleRecorderService
{
    public const CYCLE_SCHEMA = 'atlas.software_company_stewardship.area_focus_cycle.v1';

    /** Whitelisted scalar input keys persisted in the sanitized digest. */
    private const SAFE_INPUT_KEYS = ['area_id', 'hours', 'limit', 'include_area_findings'];

    /** Declared validations a cycle's evidence must satisfy (not executed here). */
    private const VALIDATION_REFS = [
        'php artisan test',
        'php artisan atlas:engineering:knowledge docs-health --json',
        'php artisan atlas:ai:architecture-validate --json',
        'git diff --check',
        'evidence_pack',
    ];

    private ?string $storageRootOverride = null;

    public function __construct(
        private readonly AreaFocusLoopReadModelService $readModel,
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
            ? storage_path('atlas/software_company_stewardship/area_focus_cycles')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/area_focus_cycles';
    }

    public function cycleFilePath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->areaSlug($areaId).'.jsonl';
    }

    /**
     * Record one read-only Area Focus cycle.
     *
     * `$input` is forwarded to the read model (accepts `area_id`, `hours`,
     * `limit`, `include_area_findings`, plus the read model's `gap_read_model` /
     * `owner_doc_status` / `area_findings` overrides for deterministic tests).
     * A `report` override (a full Area Focus report) bypasses the read model
     * entirely. Recording is idempotent: an already-recorded cycle is returned
     * as-is and not appended again.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input = []): array
    {
        $report = is_array($input['report'] ?? null)
            ? $input['report']
            : $this->readModel->project($this->readModelInput($input));

        $areaId = (string) ($report['area_id'] ?? ($input['area_id'] ?? AreaFocusLoopReadModelService::PRIORITY_AREA));

        $findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
        $inbox = is_array($report['morning_inbox'] ?? null) ? $report['morning_inbox'] : [];
        $workOrders = $this->deriveWorkOrders($findings);

        $reportHash = (string) ($report['report_hash'] ?? '');
        $findingsHash = $this->hash($findings);
        $inboxHash = $this->hash($inbox);
        $workOrdersHash = $this->hash($workOrders);
        $inputDigest = $this->sanitizeInput($input, $areaId);

        $cycleId = $this->cycleId($areaId, $reportHash, $findingsHash, $inboxHash, $workOrdersHash);

        // Idempotent: return the already-recorded cycle without a duplicate append.
        $existing = $this->findInFile($this->cycleFilePath($areaId), $cycleId);
        if ($existing !== null) {
            return $existing;
        }

        $core = [
            'schema_version' => self::CYCLE_SCHEMA,
            'cycle_id' => $cycleId,
            'area_id' => $areaId,
            'report_schema_version' => (string) ($report['schema_version'] ?? ''),
            'report_status' => (string) ($report['status'] ?? 'unknown'),
            'report_hash' => $reportHash,
            'finding_count' => count($findings),
            'input_digest' => $inputDigest,
            'input_hash' => $this->hash($inputDigest),
            'findings_hash' => $findingsHash,
            'inbox_hash' => $inboxHash,
            'inbox_decision_count' => (int) ($inbox['decision_count'] ?? 0),
            'work_orders_hash' => $workOrdersHash,
            'work_order_count' => count($workOrders),
            'routing_summary' => is_array($report['routing_summary'] ?? null) ? $report['routing_summary'] : [],
            'validation_refs' => $this->validationRefs(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $core['cycle_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);

        $record = $core;
        $record['generated_at'] = $this->now();
        $record['recorded_at'] = $this->now();

        $this->appendJsonl($this->cycleFilePath($areaId), $record);

        return $record;
    }

    /**
     * Replay a recorded cycle by id. Searches every area's JSONL file, so the
     * id alone is sufficient. Returns null when the cycle is not found.
     *
     * @return array<string,mixed>|null
     */
    public function replay(string $cycleId): ?array
    {
        foreach ($this->areaFiles() as $file) {
            $found = $this->findInFile($file, $cycleId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * List recorded cycles for an area (newest last), corruption-tolerant.
     *
     * @return array<string,mixed>
     */
    public function listCycles(string $areaId): array
    {
        [$records, $corrupted] = $this->readCycles($this->cycleFilePath($areaId));

        $summaries = [];
        foreach ($records as $record) {
            $summaries[] = [
                'cycle_id' => (string) ($record['cycle_id'] ?? ''),
                'report_status' => (string) ($record['report_status'] ?? 'unknown'),
                'finding_count' => (int) ($record['finding_count'] ?? 0),
                'inbox_decision_count' => (int) ($record['inbox_decision_count'] ?? 0),
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
                'cycle_hash' => (string) ($record['cycle_hash'] ?? ''),
            ];
        }

        return [
            'schema_version' => self::CYCLE_SCHEMA,
            'area_id' => $areaId,
            'cycle_count' => count($summaries),
            'corrupted_line_count' => $corrupted,
            'cycles' => $summaries,
        ];
    }

    /**
     * Step-2 seam for AAEOS deferred phase dispatch outcomes (P5–P9 JSONL queue).
     *
     * Validates input shape and returns the step-1 default contract. Step-3+
     * will derive outcomes from dispatch snapshots and attach them to cycle JSONL.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function deferredPhaseDispatchOutcome(array $input = []): array
    {
        $this->validateDeferredPhaseDispatchOutcomeInput($input);

        return DeferredPhaseDispatchOutcomeContract::defaults()->toArray();
    }

    // ---------- derivation / sanitization ----------

    /**
     * @param  array<string,mixed>  $input
     */
    private function validateDeferredPhaseDispatchOutcomeInput(array $input): void
    {
        if ($input === []) {
            return;
        }

        $allowedKeys = ['deferred_dispatch_count', 'next_claimed_envelope_hash'];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException("Unknown deferred phase dispatch outcome input key: {$key}");
            }
        }

        if (array_key_exists('deferred_dispatch_count', $input)
            && ! is_int($input['deferred_dispatch_count'])
            && ! (is_string($input['deferred_dispatch_count']) && ctype_digit($input['deferred_dispatch_count']))) {
            throw new \InvalidArgumentException('deferred_dispatch_count must be an integer.');
        }

        if (array_key_exists('next_claimed_envelope_hash', $input)
            && $input['next_claimed_envelope_hash'] !== null
            && ! is_string($input['next_claimed_envelope_hash'])) {
            throw new \InvalidArgumentException('next_claimed_envelope_hash must be a string or null.');
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function readModelInput(array $input): array
    {
        $out = [];
        foreach (['area_id', 'hours', 'limit', 'include_area_findings', 'gap_read_model', 'owner_doc_status', 'area_findings'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }

        return $out;
    }

    /**
     * Whitelist safe scalars + presence flags. Raw overrides and any non-listed
     * (potentially sensitive) keys are dropped — never persisted.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sanitizeInput(array $input, string $areaId): array
    {
        $digest = ['area_id' => $areaId];
        foreach (self::SAFE_INPUT_KEYS as $key) {
            if ($key === 'area_id' || ! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_scalar($value)) {
                $digest[$key] = $value;
            }
        }
        $digest['overrides'] = [
            'gap_read_model' => array_key_exists('gap_read_model', $input),
            'owner_doc_status' => array_key_exists('owner_doc_status', $input),
            'area_findings' => array_key_exists('area_findings', $input),
            'report' => array_key_exists('report', $input),
        ];

        return $digest;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private function deriveWorkOrders(array $findings): array
    {
        $orders = [];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $orders[] = [
                'finding_hash' => (string) ($finding['finding_hash'] ?? ''),
                'route' => (string) ($finding['route'] ?? ''),
                'requires_branch_isolation' => (bool) ($finding['requires_branch_isolation'] ?? false),
                'operator_decision_required' => (bool) ($finding['operator_decision_required'] ?? false),
            ];
        }

        return $orders;
    }

    private function cycleId(string $areaId, string $reportHash, string $findingsHash, string $inboxHash, string $workOrdersHash): string
    {
        $raw = hash('sha256', implode('|', [$areaId, $reportHash, $findingsHash, $inboxHash, $workOrdersHash]));

        return 'afc_'.substr($raw, 0, 16);
    }

    private function hash(mixed $value): string
    {
        return 'sha256:'.MissionCanonicalHash::sha256($value);
    }

    /**
     * @return list<array<string,string>>
     */
    private function validationRefs(): array
    {
        $refs = [];
        foreach (self::VALIDATION_REFS as $ref) {
            $refs[] = ['ref' => $ref, 'status' => 'declared', 'executed' => 'false'];
        }

        return $refs;
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'writes_repo' => false,
            'mutates_target_repo' => false,
            'writes_local_state' => true,
            'persistence' => 'jsonl_append_only',
            'provider_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'autoapproval_allowed' => false,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
            'operator_review_required' => true,
        ];
    }

    // ---------- jsonl io ----------

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $cycleId): ?array
    {
        [$records] = $this->readCycles($path);
        foreach ($records as $record) {
            if ((string) ($record['cycle_id'] ?? '') === $cycleId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Read all cycle records from a JSONL file, skipping (and counting) malformed
     * lines and lines without a cycle_id. Never throws on corruption.
     *
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readCycles(string $path): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }
        $records = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['cycle_id']) && is_string($decoded['cycle_id'])) {
                $records[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$records, $corrupted];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return list<string>
     */
    private function areaFiles(): array
    {
        $dir = $this->storageDir();
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    private function areaSlug(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?? '';

        return $slug !== '' ? $slug : 'unknown_area';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
