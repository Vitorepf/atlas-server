<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AP-810 / LHL-00 — Reality Baseline & Delivery Ledger (owner AP-810).
 *
 * The foundation slice of the Long-Horizon Loop Enterprise Reliability Block.
 * It answers, deterministically and read-only, exactly two questions before any
 * of the LHL-01..LHL-19 reliability slices run:
 *
 *   1. What is the HONEST current reality of the loop substrate right now?
 *      (main sha, integration lane presence+sha, loop locks, worktrees,
 *       provider leftovers, AP-805 readiness, AP-806 autonomy, backlog depth,
 *       and which LHL services are already implemented vs absent.)
 *   2. What is the delivery state of the 20-row enterprise block? It seeds the
 *      LHL-00..LHL-19 slice ledger (status=planned) if absent and lets the
 *      operator record real slice progress as it lands.
 *
 * Read-only / deterministic / input-seam driven. It NEVER invokes a provider,
 * NEVER runs the loop, NEVER merges, NEVER deletes a branch/worktree, NEVER
 * mutates code. The ONLY side effect is appending append-only JSONL under
 * storage/. Real git/process probes only run when the matching `$input[...]`
 * seam is absent, and every probe is wrapped so it returns null/0 on failure —
 * the service never crashes on a missing key, a dirty repo, or no git at all.
 *
 * Honesty rules (operator does not accept false claims):
 *   - an absent LHL service is reported `absent`, never silently implemented;
 *   - AP-805/806 status is reported exactly as fed (or `unknown`), never upgraded;
 *   - a blocked substrate (kill switch / stale lock / leftover provider procs)
 *     yields status=blocked, never dressed as ok;
 *   - planned slice rows are `planned`, never counted as delivered.
 *
 * Contract: AP-810 build contract slice LHL-00.
 * Reference shape: TenCycleReadinessGovernorService (probes),
 *                  AreaFocusCycleRecorderService (JSONL append-only ledger).
 */
final class LongHorizonLoopDeliveryLedgerService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.long_horizon_loop_delivery_block.v1';

    public const SLICE_SCHEMA = 'atlas.software_company_stewardship.long_horizon_loop_delivery_slice.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_BLOCKED = 'blocked';

    /** Slice lifecycle states a delivery row may hold (planned is never delivered). */
    public const SLICE_STATUS_PLANNED = 'planned';

    public const SLICE_STATUS_IN_PROGRESS = 'in_progress';

    public const SLICE_STATUS_DELIVERED = 'delivered';

    public const SLICE_STATUS_BLOCKED = 'blocked';

    private const SLICE_STATUSES = [
        self::SLICE_STATUS_PLANNED,
        self::SLICE_STATUS_IN_PROGRESS,
        self::SLICE_STATUS_DELIVERED,
        self::SLICE_STATUS_BLOCKED,
    ];

    /**
     * The 20 canonical enterprise-block slices (LHL-00..LHL-19). Each row carries
     * the slice id, the owning AP, the FQN-less service class that delivers it,
     * and a short title. `class_exists` against `class` detects implemented vs
     * absent, so the baseline reports real delivery without trusting chat.
     *
     * @var list<array{slice_id:string,owner:string,class:string,title:string}>
     */
    private const SLICE_MANIFEST = [
        ['slice_id' => 'LHL-00', 'owner' => 'AP-810', 'class' => 'LongHorizonLoopDeliveryLedgerService', 'title' => 'Reality Baseline & Delivery Ledger'],
        ['slice_id' => 'LHL-01', 'owner' => 'AP-807', 'class' => 'LoopPreflightCycleFirewallService', 'title' => 'Loop Preflight Cycle Firewall'],
        ['slice_id' => 'LHL-02', 'owner' => 'AP-807', 'class' => 'LoopPostCycleAuditorService', 'title' => 'Post-Cycle Auditor'],
        ['slice_id' => 'LHL-03', 'owner' => 'AP-808', 'class' => 'LoopFlightRecorderService', 'title' => 'Flight Recorder v0'],
        ['slice_id' => 'LHL-04', 'owner' => 'AP-808', 'class' => 'LoopReliabilitySimulatorService', 'title' => 'Reliability Simulator'],
        ['slice_id' => 'LHL-05', 'owner' => 'AP-808', 'class' => 'LoopChaosHarnessService', 'title' => 'Chaos Harness'],
        ['slice_id' => 'LHL-06', 'owner' => 'AP-809', 'class' => 'LoopReadinessLadderService', 'title' => 'Readiness Ladder'],
        ['slice_id' => 'LHL-07', 'owner' => 'AP-809', 'class' => 'LoopEvidenceLedgerService', 'title' => 'Evidence Ledger'],
        ['slice_id' => 'LHL-08', 'owner' => 'AP-810', 'class' => 'LoopBudgetGuardService', 'title' => 'Budget Guard'],
        ['slice_id' => 'LHL-09', 'owner' => 'AP-810', 'class' => 'LoopLeaseLockGuardService', 'title' => 'Lease & Lock Guard'],
        ['slice_id' => 'LHL-10', 'owner' => 'AP-810', 'class' => 'LoopWorktreeJanitorService', 'title' => 'Worktree Janitor'],
        ['slice_id' => 'LHL-11', 'owner' => 'AP-810', 'class' => 'LoopMergeTruthGuardService', 'title' => 'Merge Truth Guard'],
        ['slice_id' => 'LHL-12', 'owner' => 'AP-810', 'class' => 'LoopProviderLeftoverSweeperService', 'title' => 'Provider Leftover Sweeper'],
        ['slice_id' => 'LHL-13', 'owner' => 'AP-810', 'class' => 'LoopKillSwitchGuardService', 'title' => 'Kill Switch Guard'],
        ['slice_id' => 'LHL-14', 'owner' => 'AP-810', 'class' => 'LoopBacklogTruthLedgerService', 'title' => 'Backlog Truth Ledger'],
        ['slice_id' => 'LHL-15', 'owner' => 'AP-810', 'class' => 'LoopCandidateQuarantineGuardService', 'title' => 'Candidate Quarantine Guard'],
        ['slice_id' => 'LHL-16', 'owner' => 'AP-810', 'class' => 'LoopAuthorityEnvelopeGuardService', 'title' => 'Authority Envelope Guard'],
        ['slice_id' => 'LHL-17', 'owner' => 'AP-810', 'class' => 'LoopProgressionTruthGuardService', 'title' => 'Progression Truth Guard'],
        ['slice_id' => 'LHL-18', 'owner' => 'AP-810', 'class' => 'LoopReliabilityReportService', 'title' => 'Reliability Report'],
        ['slice_id' => 'LHL-19', 'owner' => 'AP-810', 'class' => 'LongHorizonLoopReliabilityCertificationService', 'title' => 'Enterprise Block Certification'],
    ];

    /** Namespace the LHL service classes live in (used for class_exists detection). */
    private const SLICE_NAMESPACE = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\';

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Capture the honest reality baseline of the loop substrate AND seed (if
     * absent) the 20-row delivery slice ledger. Every external fact is overridable
     * via an `$input[...]` seam; real probes are the fallback only. Never crashes
     * on a missing key.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function baseline(array $input = []): array
    {
        $area = $this->normalizeSlug((string) ($input['area'] ?? 'agentic_engineering_os'), 'agentic_engineering_os');
        $focus = $this->normalizeSlug((string) ($input['focus'] ?? 'dev_forge'), 'dev_forge');
        $repoRoot = $this->repoRoot($input);

        $blockers = [];
        $warnings = [];

        $reality = $this->captureReality($input, $repoRoot, $blockers, $warnings);
        $slicesReport = $this->ensureSeeded($input, $area, $focus);

        $status = $blockers === [] ? self::STATUS_OK : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-810',
            'slice_id' => 'LHL-00',
            'status' => $status,
            'baseline_id' => 'lhd_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                (string) ($reality['main_sha'] ?? ''),
                (string) ($reality['integration_lane']['sha'] ?? ''),
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => $this->now(),
            'reality' => $reality,
            'lhl_services' => $reality['lhl_services'],
            'delivery_ledger' => [
                'slice_count' => $slicesReport['slice_count'],
                'seeded' => $slicesReport['seeded'],
                'ledger_path' => $slicesReport['ledger_path'],
                'status_counts' => $slicesReport['status_counts'],
                'implemented_count' => $slicesReport['implemented_count'],
            ],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'next_action' => $status === self::STATUS_OK ? 'continue' : 'stop_substrate_blocked',
            'claim_policy' => $this->claimPolicy(),
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($payload));

        return $payload;
    }

    /**
     * Record real delivery progress for one slice (idempotent append-only update).
     * Always re-seeds the canonical 20 rows first, then appends a new row carrying
     * the updated status for the target slice. `slices()` always returns the most
     * recent state per slice, so this never rewrites history.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordSlice(array $input = []): array
    {
        $area = $this->normalizeSlug((string) ($input['area'] ?? 'agentic_engineering_os'), 'agentic_engineering_os');
        $focus = $this->normalizeSlug((string) ($input['focus'] ?? 'dev_forge'), 'dev_forge');

        $this->ensureSeeded($input, $area, $focus);

        $sliceId = strtoupper(trim((string) ($input['slice_id'] ?? '')));
        $manifest = $this->manifestBySliceId();
        if ($sliceId === '' || ! isset($manifest[$sliceId])) {
            return [
                'schema_version' => self::SLICE_SCHEMA,
                'status' => self::STATUS_BLOCKED,
                'recorded' => false,
                'error' => 'unknown_or_missing_slice_id',
                'slice_id' => $sliceId,
            ];
        }

        $sliceStatus = $this->normalizeSliceStatus((string) ($input['slice_status'] ?? self::SLICE_STATUS_PLANNED));
        $entry = $manifest[$sliceId];
        $note = trim((string) ($input['note'] ?? ''));
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);

        $row = $this->sliceRow(
            $area,
            $focus,
            $entry,
            $sliceStatus,
            $note === '' ? null : $note,
            $evidenceRefs,
        );

        $this->appendJsonl($this->ledgerPath($area, $focus), $row);

        return [
            'schema_version' => self::SLICE_SCHEMA,
            'status' => self::STATUS_OK,
            'recorded' => true,
            'slice' => $row,
        ];
    }

    /**
     * Read the delivery ledger for an area/focus, returning the latest state per
     * slice in canonical LHL-00..LHL-19 order (corruption-tolerant; never throws).
     *
     * @return array<string,mixed>
     */
    public function slices(string $area, string $focus): array
    {
        $area = $this->normalizeSlug($area, 'agentic_engineering_os');
        $focus = $this->normalizeSlug($focus, 'dev_forge');

        [$rows, $corrupted] = $this->readRows($this->ledgerPath($area, $focus));

        // Keep the latest row per slice id (append-only history => last wins).
        $latest = [];
        foreach ($rows as $row) {
            $id = strtoupper((string) ($row['slice_id'] ?? ''));
            if ($id !== '') {
                $latest[$id] = $row;
            }
        }

        $ordered = [];
        $statusCounts = array_fill_keys(self::SLICE_STATUSES, 0);
        $implemented = 0;
        foreach (self::SLICE_MANIFEST as $entry) {
            $id = $entry['slice_id'];
            $row = $latest[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $ordered[] = $row;
            $rowStatus = $this->normalizeSliceStatus((string) ($row['slice_status'] ?? self::SLICE_STATUS_PLANNED));
            $statusCounts[$rowStatus]++;
            if ((bool) ($row['implemented'] ?? false)) {
                $implemented++;
            }
        }

        return [
            'schema_version' => self::SLICE_SCHEMA,
            'area' => $area,
            'focus' => $focus,
            'slice_count' => count($ordered),
            'status_counts' => $statusCounts,
            'implemented_count' => $implemented,
            'corrupted_line_count' => $corrupted,
            'ledger_path' => $this->ledgerPath($area, $focus),
            'slices' => $ordered,
        ];
    }

    /**
     * Public path accessor (mirrors the recorder convention `recordPath`).
     */
    public function recordPath(string $area, string $focus): string
    {
        return $this->ledgerPath(
            $this->normalizeSlug($area, 'agentic_engineering_os'),
            $this->normalizeSlug($focus, 'dev_forge'),
        );
    }

    // ----------------------------------------------------------- reality probes

    /**
     * Capture the honest current substrate reality. Each fact prefers its input
     * seam and falls back to a wrapped real probe. Substrate-fatal conditions add
     * a blocker (status=blocked); soft anomalies add a warning only.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @return array<string,mixed>
     */
    private function captureReality(array $input, string $repoRoot, array &$blockers, array &$warnings): array
    {
        $mainSha = $this->resolveString($input, 'main_sha', fn (): ?string => $this->gitRevParse($repoRoot, 'main'));

        $laneSeam = is_array($input['integration_lane'] ?? null) ? $input['integration_lane'] : null;
        if ($laneSeam !== null) {
            $lanePresent = (bool) ($laneSeam['present'] ?? ($this->nullableString($laneSeam['sha'] ?? null) !== null));
            $laneSha = $this->nullableString($laneSeam['sha'] ?? null);
            $laneRef = $this->nullableString($laneSeam['ref'] ?? null) ?? 'atlas/integration-lane';
        } else {
            $laneRef = $this->nullableString($input['integration_lane_ref'] ?? null) ?? 'atlas/integration-lane';
            $laneSha = $this->gitRevParse($repoRoot, $laneRef);
            $lanePresent = $laneSha !== null;
        }

        $killSwitch = (bool) ($input['kill_switch_active'] ?? false);
        if ($killSwitch) {
            $blockers[] = 'kill_switch_active';
        }

        $staleLock = (bool) ($input['stale_lock'] ?? false);
        $loopLockHeld = (bool) ($input['loop_lock_held'] ?? false);
        if ($staleLock) {
            $blockers[] = 'stale_loop_lock_held';
        }
        if ($loopLockHeld) {
            $warnings[] = 'loop_lock_currently_held';
        }

        $worktreeCount = array_key_exists('worktree_count', $input)
            ? max(0, (int) $input['worktree_count'])
            : $this->worktreeCount($repoRoot);
        $worktreeList = $this->stringList($input['worktree_list'] ?? []);
        if ($worktreeCount > 20) {
            $warnings[] = 'many_active_worktrees';
        }

        $providerProcs = array_key_exists('provider_processes', $input)
            ? max(0, (int) $input['provider_processes'])
            : max(0, (int) ($input['provider_processes_remaining'] ?? 0));
        if ($providerProcs > 0) {
            $cleanupPlanned = (bool) ($input['provider_leftover_cleanup_planned'] ?? false);
            if ($cleanupPlanned) {
                $warnings[] = 'provider_leftovers_cleanup_planned';
            } else {
                $blockers[] = 'provider_process_leftovers_present';
            }
        }

        $backlogDepth = max(0, (int) ($input['backlog_depth'] ?? 0));

        $ap805 = $this->normalizeReadiness((string) ($input['ap805_readiness_status'] ?? ($input['ap805_status'] ?? 'unknown')));
        $ap806 = $this->normalizeReadiness((string) ($input['ap806_autonomy_status'] ?? ($input['ap806_status'] ?? 'unknown')));

        $lhlServices = $this->detectLhlServices($input);

        return [
            'repo_root_hash' => 'sha256:'.MissionCanonicalHash::sha256($repoRoot),
            'main_sha' => $mainSha,
            'integration_lane' => [
                'ref' => $laneRef,
                'present' => $lanePresent,
                'sha' => $laneSha,
            ],
            'loop_locks' => [
                'loop_lock_held' => $loopLockHeld,
                'stale_lock' => $staleLock,
            ],
            'kill_switch_active' => $killSwitch,
            'worktrees' => [
                'count' => $worktreeCount,
                'list' => $worktreeList,
            ],
            'provider_processes' => [
                'count' => $providerProcs,
                'cleanup_planned' => (bool) ($input['provider_leftover_cleanup_planned'] ?? false),
            ],
            'backlog_depth' => $backlogDepth,
            'ap805_readiness' => $ap805,
            'ap806_autonomy' => $ap806,
            'lhl_services' => $lhlServices,
        ];
    }

    /**
     * Detect which LHL services already exist via class_exists (capability only,
     * never instantiated). Reports each slice as implemented|absent. An input seam
     * `lhl_service_overrides` (by FQN-less class name) forces the verdict for
     * deterministic tests.
     *
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function detectLhlServices(array $input): array
    {
        $overrides = is_array($input['lhl_service_overrides'] ?? null) ? $input['lhl_service_overrides'] : [];

        $out = [];
        foreach (self::SLICE_MANIFEST as $entry) {
            $class = $entry['class'];
            if (array_key_exists($class, $overrides)) {
                $present = (bool) $overrides[$class];
            } else {
                $present = class_exists(self::SLICE_NAMESPACE.$class);
            }
            $out[] = [
                'slice_id' => $entry['slice_id'],
                'owner' => $entry['owner'],
                'class' => $class,
                'state' => $present ? 'implemented' : 'absent',
                'implemented' => $present,
            ];
        }

        return $out;
    }

    // --------------------------------------------------------------- ledger seed

    /**
     * Seed the 20-row LHL-00..LHL-19 ledger (status=planned) if the file has no
     * rows yet. Idempotent: an already-seeded ledger is left untouched. Returns a
     * summary used by the baseline payload.
     *
     * @param  array<string,mixed>  $input
     * @return array{slice_count:int,seeded:bool,ledger_path:string,status_counts:array<string,int>,implemented_count:int}
     */
    private function ensureSeeded(array $input, string $area, string $focus): array
    {
        $path = $this->ledgerPath($area, $focus);
        [$rows] = $this->readRows($path);

        $services = $this->detectLhlServices($input);
        $implementedByid = [];
        foreach ($services as $svc) {
            $implementedByid[(string) $svc['slice_id']] = (bool) $svc['implemented'];
        }

        $seeded = false;
        if ($rows === []) {
            foreach (self::SLICE_MANIFEST as $entry) {
                $row = $this->sliceRow(
                    $area,
                    $focus,
                    $entry,
                    self::SLICE_STATUS_PLANNED,
                    null,
                    [],
                    $implementedByid[$entry['slice_id']] ?? null,
                );
                $this->appendJsonl($path, $row);
            }
            $seeded = true;
        }

        $report = $this->slices($area, $focus);

        return [
            'slice_count' => (int) $report['slice_count'],
            'seeded' => $seeded,
            'ledger_path' => $path,
            'status_counts' => $report['status_counts'],
            'implemented_count' => (int) $report['implemented_count'],
        ];
    }

    /**
     * Build one canonical, deterministic slice row. `slice_hash` excludes the
     * wall-clock `recorded_at`, so identical content hashes identically.
     *
     * @param  array{slice_id:string,owner:string,class:string,title:string}  $entry
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function sliceRow(string $area, string $focus, array $entry, string $sliceStatus, ?string $note, array $evidenceRefs, ?bool $implemented = null): array
    {
        if ($implemented === null) {
            $implemented = class_exists(self::SLICE_NAMESPACE.$entry['class']);
        }

        $core = [
            'schema_version' => self::SLICE_SCHEMA,
            'area' => $area,
            'focus' => $focus,
            'slice_id' => $entry['slice_id'],
            'owner' => $entry['owner'],
            'service_class' => $entry['class'],
            'title' => $entry['title'],
            'slice_status' => $sliceStatus,
            'implemented' => $implemented,
            'note' => $note,
            'evidence_refs' => $evidenceRefs,
        ];
        $core['slice_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);
        $core['recorded_at'] = $this->now();

        return $core;
    }

    /**
     * @return array<string,array{slice_id:string,owner:string,class:string,title:string}>
     */
    private function manifestBySliceId(): array
    {
        $byId = [];
        foreach (self::SLICE_MANIFEST as $entry) {
            $byId[$entry['slice_id']] = $entry;
        }

        return $byId;
    }

    // ------------------------------------------------------------------ storage

    public function storageDir(string $area, string $focus): string
    {
        $base = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/software_company_stewardship/long_horizon_loop')
                : sys_get_temp_dir().'/atlas/software_company_stewardship/long_horizon_loop');

        return $base.DIRECTORY_SEPARATOR.$area.DIRECTORY_SEPARATOR.$focus;
    }

    private function ledgerPath(string $area, string $focus): string
    {
        return $this->storageDir($area, $focus).DIRECTORY_SEPARATOR.'delivery_ledger.jsonl';
    }

    /**
     * Read all slice rows from a JSONL ledger, skipping (and counting) malformed
     * lines and lines without a slice_id. Never throws on corruption or absence.
     *
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }
        $rows = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['slice_id']) && is_string($decoded['slice_id'])) {
                $rows[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$rows, $corrupted];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (class_exists(File::class) && function_exists('app')) {
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

    // -------------------------------------------------------------------- probes

    private function gitRevParse(string $repoRoot, string $ref): ?string
    {
        $out = $this->process(['git', '-C', $repoRoot, 'rev-parse', '--verify', '--quiet', $ref], $repoRoot, 15);
        if ($out === null) {
            return null;
        }
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private function worktreeCount(string $repoRoot): int
    {
        $out = $this->process(['git', '-C', $repoRoot, 'worktree', 'list', '--porcelain'], $repoRoot, 15);
        if ($out === null) {
            return 0;
        }

        return substr_count($out, "\nworktree ") + (str_starts_with($out, 'worktree ') ? 1 : 0);
    }

    /**
     * @param  list<string>  $argv
     */
    private function process(array $argv, ?string $cwd, int $timeout): ?string
    {
        if (! class_exists(Process::class)) {
            return null;
        }
        try {
            $process = new Process($argv, $cwd, null, null, (float) $timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return (string) $process->getOutput();
        } catch (Throwable) {
            return null;
        }
    }

    private function repoRoot(array $input): string
    {
        $root = trim((string) ($input['repo_root'] ?? ''));
        if ($root !== '') {
            return $root;
        }

        return function_exists('base_path') ? base_path() : (getcwd() ?: '.');
    }

    // ------------------------------------------------------------------- helpers

    /**
     * Resolve a string fact: prefer the input seam, else run the wrapped probe.
     *
     * @param  array<string,mixed>  $input
     * @param  callable():(?string)  $probe
     */
    private function resolveString(array $input, string $key, callable $probe): ?string
    {
        if (array_key_exists($key, $input)) {
            return $this->nullableString($input[$key]);
        }

        return $probe();
    }

    private function normalizeReadiness(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['ready', 'partial', 'blocked', 'available', 'degraded'], true) ? $value : 'unknown';
    }

    private function normalizeSliceStatus(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, self::SLICE_STATUSES, true) ? $value : self::SLICE_STATUS_PLANNED;
    }

    private function normalizeSlug(string $value, string $fallback): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? $item : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'runs_provider' => false,
            'runs_loop' => false,
            'runs_merge' => false,
            'deletes_branches' => false,
            'writes_local_state' => true,
            'persistence' => 'jsonl_append_only',
            'blocked_never_dressed_as_ready' => true,
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * Strip volatile / stateful fields before hashing so the same substrate
     * reality hashes identically regardless of on-disk ledger state:
     *   - `checked_at` / `report_hash` are wall-clock / self-referential;
     *   - `delivery_ledger.seeded` and `delivery_ledger.ledger_path` reflect
     *     mutable storage state (the path is environment-specific and `seeded`
     *     flips false on the second baseline once the ledger exists).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['checked_at'], $payload['report_hash']);
        if (isset($payload['delivery_ledger']) && is_array($payload['delivery_ledger'])) {
            unset($payload['delivery_ledger']['seeded'], $payload['delivery_ledger']['ledger_path']);
        }

        return $payload;
    }
}
