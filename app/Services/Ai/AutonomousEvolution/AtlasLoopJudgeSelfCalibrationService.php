<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * L6-2: turns historical RED-canary fix-forward cases into stricter verifier candidates.
 *
 * It only tightens: a candidate is claimable when the frozen verifier is RED on the
 * current baseline and has revert_recheck enabled. No gate is loosened, no provider
 * is called, and forbidden self-targets are refused before packet compilation.
 */
final class AtlasLoopJudgeSelfCalibrationService
{
    public const SCHEMA_VERSION = 'atlas.loop.judge_self_calibration.v1';

    public function __construct(
        private readonly AtlasLoopIntentVerifierFactory $verifierFactory,
        private readonly AtlasLoopHarnessGuard $harnessGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function calibrate(array $options = []): array
    {
        $cfg = (array) config('atlas.loop.judge_self_calibration', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $windowHours = max(1, min(2160, (int) ($options['window_hours'] ?? $cfg['window_hours'] ?? 168)));
        $limit = max(1, min(50, (int) ($options['limit'] ?? $cfg['max_cases'] ?? 8)));
        $timeout = max(30, min(900, (int) ($options['timeout_seconds'] ?? $cfg['timeout_seconds'] ?? 300)));
        $write = (bool) ($options['write'] ?? $cfg['write_packets'] ?? false);
        $manifestPath = (string) ($options['manifest_path'] ?? $cfg['manifest_path'] ?? storage_path('app/atlas/evidence/judge-self-calibration.json'));
        $packetDir = rtrim((string) ($options['packet_dir'] ?? $cfg['packet_dir'] ?? storage_path('app/atlas/evidence/judge-self-calibration-packets')), '/');

        if (! $enabled) {
            return $this->payload('disabled', [], ['judge_self_calibration_disabled'], $windowHours, $limit, $timeout, $write, $manifestPath, $packetDir);
        }

        if (! DatabaseTableAvailability::all(['atlas_loop_tasks'])) {
            return $this->payload('blocked', [], ['loop_task_table_missing'], $windowHours, $limit, $timeout, $write, $manifestPath, $packetDir);
        }

        $cases = $this->historicalFixForwardCases($windowHours, $limit);
        if ($cases === []) {
            return $this->payload('no_fix_forward_cases', [], ['no_fix_forward_canary_red_tasks_in_window'], $windowHours, $limit, $timeout, $write, $manifestPath, $packetDir);
        }

        $candidates = [];
        foreach ($cases as $case) {
            $candidates[] = $this->candidateFromCase($case, $timeout, $write, $packetDir);
        }

        $readyCount = count(array_filter($candidates, static fn (array $candidate): bool => (bool) ($candidate['would_have_caught_case'] ?? false)));
        $blockers = $readyCount > 0
            ? []
            : $this->candidateBlockers($candidates);
        $status = $readyCount > 0 ? 'verifier_candidates_ready' : 'calibration_unproven';

        $payload = $this->payload($status, $candidates, $blockers, $windowHours, $limit, $timeout, $write, $manifestPath, $packetDir);
        if ($write) {
            $this->writeManifest($manifestPath, $payload);
        }

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function historicalFixForwardCases(int $windowHours, int $limit): array
    {
        try {
            $rows = DB::table('atlas_loop_tasks')
                ->where('source', 'fix_forward')
                ->where('updated_at', '>=', Carbon::now()->subHours($windowHours))
                ->orderByDesc('updated_at')
                ->limit($limit * 3)
                ->get(['id', 'campaign_id', 'target_path', 'objective', 'payload', 'created_at', 'updated_at']);
        } catch (Throwable) {
            return [];
        }

        $cases = [];
        $seen = [];
        foreach ($rows as $row) {
            $payload = $this->arrayPayload($row->payload ?? null);
            if ((string) ($payload['origin'] ?? '') !== 'fix_forward_canary_red') {
                continue;
            }

            $target = $this->normalizeRelative((string) ($row->target_path ?? ''));
            $canary = $this->normalizeRelative((string) ($payload['canary_target'] ?? ''));
            $snapshot = trim((string) ($payload['snapshot_tag'] ?? ''));
            $dedupe = hash('sha256', $target.'|'.$canary.'|'.$snapshot);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $cases[] = [
                'source' => 'fix_forward_task',
                'task_id' => (string) $row->id,
                'campaign_id' => (string) $row->campaign_id,
                'target_path' => $target,
                'objective' => (string) ($row->objective ?? ''),
                'canary_target' => $canary,
                'snapshot_tag' => $snapshot,
                'merged_proposal_hash' => (string) ($payload['merged_proposal_hash'] ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];

            if (count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function candidateFromCase(array $case, int $timeout, bool $write, string $packetDir): array
    {
        $target = (string) ($case['target_path'] ?? '');
        $canary = (string) ($case['canary_target'] ?? '');
        $caseId = $this->caseId($case);
        $blockers = [];

        if ($target === '' || ! is_file(base_path($target))) {
            $blockers[] = 'target_file_missing';
        }
        if ($canary === '' || ! is_file(base_path($canary))) {
            $blockers[] = 'canary_test_file_missing';
        }
        if ($target !== '' && $this->harnessGuard->isForbiddenSelfTarget($target)) {
            $blockers[] = 'forbidden_self_target';
        }
        if ($canary !== '' && ! str_starts_with($canary, 'tests/')) {
            $blockers[] = 'canary_target_not_a_test_path';
        }

        $candidate = [
            'schema_version' => self::SCHEMA_VERSION.'.candidate.v1',
            'case_id' => $caseId,
            'source' => $case['source'] ?? 'fix_forward_task',
            'source_task_id' => $case['task_id'] ?? null,
            'campaign_id' => $case['campaign_id'] ?? null,
            'target_path' => $target,
            'canary_target' => $canary,
            'snapshot_tag' => $case['snapshot_tag'] ?? null,
            'merged_proposal_hash' => $case['merged_proposal_hash'] ?? null,
            'status' => 'blocked',
            'blockers' => $blockers,
            'would_have_caught_case' => false,
            'frozen' => false,
        ];

        if ($blockers !== []) {
            return $candidate;
        }

        $intent = 'Judge self-calibration: require the historical RED canary '.$canary.' to pass before accepting changes to '.$target.'.';
        $packet = $this->verifierFactory->compileFrameworkPacket(base_path(), $intent, [
            'target_relative_path' => $target,
            'allowed_files' => [$target],
            'frozen_test_path' => 'tests/Feature/Loop/JudgeSelfCalibration/'.$caseId.'.php',
            'timeout_seconds' => $timeout,
            'verification_atoms' => [[
                'type' => 'command_output',
                'command' => $this->canaryCommand($canary),
                'output_contains' => 'PASS',
                'exit_code' => 0,
            ]],
        ]);

        $packetPath = null;
        if ($write) {
            $packetPath = $this->writePacket($packetDir, $caseId, $packet);
        }

        $wouldCatch = (bool) ($packet['ready'] ?? false)
            && (string) data_get($packet, 'red_preflight.status') === 'red'
            && (bool) data_get($packet, 'acceptance.revert_recheck', false);

        return array_merge($candidate, [
            'status' => $wouldCatch ? 'frozen_verifier_ready' : 'blocked',
            'blockers' => $wouldCatch ? [] : array_values((array) ($packet['blockers'] ?? ['compiled_verifier_not_ready'])),
            'would_have_caught_case' => $wouldCatch,
            'frozen' => $wouldCatch,
            'verifier' => [
                'schema_version' => (string) ($packet['schema_version'] ?? AtlasLoopIntentVerifierFactory::SCHEMA),
                'status' => (string) ($packet['status'] ?? 'unknown'),
                'ready' => (bool) ($packet['ready'] ?? false),
                'verifier_hash' => (string) ($packet['verifier_hash'] ?? ''),
                'red_preflight_status' => (string) data_get($packet, 'red_preflight.status', 'unknown'),
                'baseline_red' => (bool) data_get($packet, 'red_preflight.baseline_red', false),
                'acceptance_revert_recheck' => (bool) data_get($packet, 'acceptance.revert_recheck', false),
                'acceptance_commands' => array_values((array) data_get($packet, 'acceptance.commands', [])),
                'frozen_tests' => array_map(
                    static fn (mixed $test): array => is_array($test) ? ['path' => (string) ($test['path'] ?? '')] : [],
                    (array) ($packet['frozen_tests'] ?? []),
                ),
                'packet_path' => $packetPath,
            ],
        ]);
    }

    private function canaryCommand(string $canaryTarget): string
    {
        return escapeshellarg(PHP_BINARY).' -d memory_limit=2048M artisan test '.escapeshellarg($canaryTarget);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<string>
     */
    private function candidateBlockers(array $candidates): array
    {
        $blockers = [];
        foreach ($candidates as $candidate) {
            foreach ((array) ($candidate['blockers'] ?? []) as $blocker) {
                $blocker = trim((string) $blocker);
                if ($blocker !== '') {
                    $blockers[$blocker] = true;
                }
            }
        }

        return array_keys($blockers);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeManifest(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function writePacket(string $dir, string $caseId, array $packet): string
    {
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$caseId.'.json';
        File::put($path, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function caseId(array $case): string
    {
        return substr(hash('sha256', json_encode([
            $case['task_id'] ?? '',
            $case['target_path'] ?? '',
            $case['canary_target'] ?? '',
            $case['snapshot_tag'] ?? '',
            $case['merged_proposal_hash'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), 0, 24);
    }

    /**
     * @return array<string,mixed>
     */
    private function arrayPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function payload(
        string $status,
        array $candidates,
        array $blockers,
        int $windowHours,
        int $limit,
        int $timeout,
        bool $write,
        string $manifestPath,
        string $packetDir,
    ): array {
        $ready = count(array_filter($candidates, static fn (array $candidate): bool => (bool) ($candidate['would_have_caught_case'] ?? false)));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => [
                'hours' => $windowHours,
                'max_cases' => $limit,
                'timeout_seconds' => $timeout,
            ],
            'counts' => [
                'historical_fix_forward_cases' => count($candidates),
                'ready_verifier_candidates' => $ready,
                'blocked_candidates' => max(0, count($candidates) - $ready),
            ],
            'candidates' => $candidates,
            'blockers' => $blockers,
            'artifacts' => [
                'write_enabled' => $write,
                'manifest_path' => $write ? $manifestPath : null,
                'packet_dir' => $write ? $packetDir : null,
            ],
            'completion_claim_allowed' => $status === 'verifier_candidates_ready' && $ready > 0,
            'claim_policy' => [
                'tightens_only' => true,
                'provider_calls_made' => false,
                'workspace_mutated' => false,
                'merge_gate_changed' => false,
                'never_merge_changed' => false,
                'forbidden_self_targets_allowed' => false,
                'requires_red_preflight' => true,
                'requires_revert_recheck' => true,
                'requires_historical_fix_forward' => true,
            ],
        ];
    }
}
