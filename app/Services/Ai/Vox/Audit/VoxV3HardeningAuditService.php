<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Audit;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Vox V3 hardening audit.
 *
 * Read-only auditor that independently re-verifies every V3 hard-safety
 * invariant before any V4 work begins. Sits next to — not on top of —
 * `VoxV3CertificationPackService`, which declares invariants but marks
 * several as "measured by construction" without actual verification.
 * This service closes that autoengano gap: each check has a concrete
 * source (ledger event count, file-content static read, schema constant)
 * and must produce its own pass/warn/fail/unknown — never inherit one.
 *
 * Honesty contract:
 *   - Never writes, never executes, never calls a provider.
 *   - When a check cannot be measured honestly (file missing, table
 *     missing, signal not instrumented), returns `unknown` with a clear
 *     reason — never a false `pass`.
 *   - `unknown` and `warn` both raise overall status to at least `warn`;
 *     only a clean 12/12 pass yields `pass`.
 *
 * Schema: `atlas.vox.v3_hardening_audit.v1` — pinned by tests so any
 * field rename triggers a deliberate doc-aligned bump.
 */
final class VoxV3HardeningAuditService
{
    public const SCHEMA = 'atlas.vox.v3_hardening_audit.v1';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';
    public const STATUS_UNKNOWN = 'unknown';

    public const SOURCE_LEDGER = 'ledger';
    public const SOURCE_STATIC = 'static';
    public const SOURCE_SCHEMA = 'schema';
    public const SOURCE_METRICS = 'metrics';

    /**
     * Canonical, ordered list of checks. Order is stable so consumers
     * (and Vitor's eyeball) can diff across runs deterministically.
     *
     * @return list<string>
     */
    public static function checkNames(): array
    {
        return [
            'no_raw_audio_persisted',
            'no_confirmation_bypass',
            'no_destructive_action_without_receipt',
            'terminal_execute_not_supported',
            'voice_realtime_untouched',
            'mobile_untouched',
            'provider_api_not_added',
            'confirmation_token_not_in_ledger',
            'r4_literal_required',
            'governed_execute_requires_receipt',
            'terminal_propose_command_executed_false',
            'v4_not_started',
        ];
    }

    public function __construct(
        private readonly VoxMetricsService $metrics,
    ) {}

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $snapshot = $this->metrics->snapshot();
        $checks = [
            $this->checkNoRawAudioPersisted($snapshot),
            $this->checkNoConfirmationBypass($snapshot),
            $this->checkNoDestructiveActionWithoutReceipt($snapshot),
            $this->checkTerminalExecuteNotSupported(),
            $this->checkVoiceRealtimeUntouched(),
            $this->checkMobileUntouched(),
            $this->checkProviderApiNotAdded(),
            $this->checkConfirmationTokenNotInLedger(),
            $this->checkR4LiteralRequired(),
            $this->checkGovernedExecuteRequiresReceipt(),
            $this->checkTerminalProposeCommandExecutedFalse(),
            $this->checkV4NotStarted(),
        ];

        return [
            'schema' => self::SCHEMA,
            'status' => self::aggregate($checks),
            'checks' => $checks,
            'summary' => self::summarise($checks),
            'read_only' => true,
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * Aggregation:
     *   any fail   → fail
     *   any warn   → warn
     *   any unknown→ warn  (honest: missing signal must not pretend pass)
     *   else       → pass
     *
     * @param  list<array<string,mixed>>  $checks
     */
    public static function aggregate(array $checks): string
    {
        $hasFail = false;
        $hasWarn = false;
        $hasUnknown = false;
        foreach ($checks as $c) {
            $s = (string) ($c['status'] ?? '');
            if ($s === self::STATUS_FAIL) {
                $hasFail = true;
            } elseif ($s === self::STATUS_WARN) {
                $hasWarn = true;
            } elseif ($s === self::STATUS_UNKNOWN) {
                $hasUnknown = true;
            }
        }
        if ($hasFail) {
            return self::STATUS_FAIL;
        }
        if ($hasWarn || $hasUnknown) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASS;
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private static function summarise(array $checks): array
    {
        $counts = [
            'total' => count($checks),
            self::STATUS_PASS => 0,
            self::STATUS_WARN => 0,
            self::STATUS_FAIL => 0,
            self::STATUS_UNKNOWN => 0,
        ];
        foreach ($checks as $c) {
            $s = (string) ($c['status'] ?? '');
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        return $counts;
    }

    /** @param array<string,mixed> $snapshot */
    private function checkNoRawAudioPersisted(array $snapshot): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return self::unknown(
                name: 'no_raw_audio_persisted',
                source: self::SOURCE_LEDGER,
                expected: 0,
                evidence: 'atlas_ledger_events table not present; cannot verify raw_audio counter from primary source.',
            );
        }
        $observed = (int) data_get($snapshot, 'hard_gates.raw_audio_persisted_count', 0);

        return [
            'name' => 'no_raw_audio_persisted',
            'status' => $observed === 0 ? self::STATUS_PASS : self::STATUS_FAIL,
            'source' => self::SOURCE_METRICS,
            'observed' => $observed,
            'expected' => 0,
            'evidence' => 'VOX_TRANSCRIPT_READY events with payload.raw_pcm_persisted=true counted via VoxMetricsService.',
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function checkNoConfirmationBypass(array $snapshot): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return self::unknown(
                name: 'no_confirmation_bypass',
                source: self::SOURCE_LEDGER,
                expected: 0,
                evidence: 'atlas_ledger_events table not present; cannot verify confirmation_bypass counter.',
            );
        }
        $observed = (int) data_get($snapshot, 'hard_gates.confirmation_bypass_count', 0);

        return [
            'name' => 'no_confirmation_bypass',
            'status' => $observed === 0 ? self::STATUS_PASS : self::STATUS_FAIL,
            'source' => self::SOURCE_METRICS,
            'observed' => $observed,
            'expected' => 0,
            'evidence' => 'VOX_ACTION_BLOCKED events with reason_code in {confirmation_bypass_attempted, confirmation_token_invalid, confirmation_token_expired}.',
        ];
    }

    /** @param array<string,mixed> $snapshot */
    private function checkNoDestructiveActionWithoutReceipt(array $snapshot): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return self::unknown(
                name: 'no_destructive_action_without_receipt',
                source: self::SOURCE_LEDGER,
                expected: 0,
                evidence: 'atlas_ledger_events table not present; cannot verify destructive_without_receipt counter.',
            );
        }
        $observed = (int) data_get($snapshot, 'hard_gates.destructive_action_without_receipt', 0);

        return [
            'name' => 'no_destructive_action_without_receipt',
            'status' => $observed === 0 ? self::STATUS_PASS : self::STATUS_FAIL,
            'source' => self::SOURCE_METRICS,
            'observed' => $observed,
            'expected' => 0,
            'evidence' => 'VOX_ACTION_BLOCKED events with reason_code=destructive_action_without_receipt.',
        ];
    }

    /**
     * V3 must NEVER expose a path that actually exec()s a shell command
     * through the terminal_propose surface. We verify by reading the
     * executor source and confirming:
     *   - it has no Process/exec/shell_exec/proc_open/system/passthru calls
     *   - it imports VoxActionOutcomeService::terminalProposed (which
     *     hard-codes metadata.command_executed=false)
     */
    private function checkTerminalExecuteNotSupported(): array
    {
        $executorPath = base_path('app/Services/Ai/Vox/Execution/VoxTerminalProposeExecutor.php');
        $outcomePath = base_path('app/Services/Ai/Vox/VoxActionOutcomeService.php');

        $executor = self::readFileOrNull($executorPath);
        $outcome = self::readFileOrNull($outcomePath);

        if ($executor === null || $outcome === null) {
            return self::unknown(
                name: 'terminal_execute_not_supported',
                source: self::SOURCE_STATIC,
                expected: 'no shell exec in terminal_propose path',
                evidence: 'Source file missing: '.($executor === null ? $executorPath : $outcomePath),
            );
        }

        $stripped = self::stripCommentsAndStrings($executor);
        $forbidden = [
            'Process::run',
            'Process::start',
            'shell_exec(',
            'exec(',
            'proc_open(',
            'passthru(',
            'system(',
            'popen(',
        ];
        $violations = [];
        foreach ($forbidden as $needle) {
            if (str_contains($stripped, $needle)) {
                $violations[] = $needle;
            }
        }

        // We need to see string literals here (the array key is a string),
        // so strip ONLY comments — a docblock mentioning the same phrase
        // must not satisfy the check.
        $outcomeStripped = self::stripCommentsOnly($outcome);
        $hasHardcodedFlag = preg_match(
            "/(?<![\\w])['\"]command_executed['\"]\\s*=>\\s*false\\b/u",
            $outcomeStripped
        ) === 1;

        if ($violations !== []) {
            return [
                'name' => 'terminal_execute_not_supported',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $violations,
                'expected' => [],
                'evidence' => 'VoxTerminalProposeExecutor contains forbidden shell-execution call(s): '.implode(', ', $violations),
            ];
        }

        if (! $hasHardcodedFlag) {
            return [
                'name' => 'terminal_execute_not_supported',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => 'command_executed flag absent',
                'expected' => "'command_executed' => false hard-coded in VoxActionOutcomeService::terminalProposed",
                'evidence' => 'VoxActionOutcomeService no longer hard-codes command_executed=false; flag could be set to true downstream.',
            ];
        }

        return [
            'name' => 'terminal_execute_not_supported',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'propose-only path verified',
            'expected' => 'no shell exec + command_executed=false hard-coded',
            'evidence' => 'No Process/exec/shell_exec/proc_open/system/passthru/popen calls in VoxTerminalProposeExecutor; command_executed=false hard-coded in VoxActionOutcomeService::terminalProposed.',
        ];
    }

    /**
     * Vox V3 must not import anything from app/Services/Ai/Voice/.
     * We scan every PHP file under app/Services/Ai/Vox/ for
     * `use App\Services\Ai\Voice\` statements.
     */
    private function checkVoiceRealtimeUntouched(): array
    {
        $voxDir = base_path('app/Services/Ai/Vox');
        $voiceDir = base_path('app/Services/Ai/Voice');

        if (! is_dir($voxDir)) {
            return self::unknown(
                name: 'voice_realtime_untouched',
                source: self::SOURCE_STATIC,
                expected: 'no Voice imports',
                evidence: "Vox directory not present at {$voxDir}.",
            );
        }
        if (! is_dir($voiceDir)) {
            // Voice not built in this checkout — boundary is trivially honoured.
            return [
                'name' => 'voice_realtime_untouched',
                'status' => self::STATUS_PASS,
                'source' => self::SOURCE_STATIC,
                'observed' => 'voice directory absent',
                'expected' => 'no Voice imports',
                'evidence' => 'app/Services/Ai/Voice/ is not present in this checkout — boundary trivially honoured.',
            ];
        }

        $offenders = [];
        foreach (self::iteratePhpFiles($voxDir) as $path) {
            $content = self::readFileOrNull($path);
            if ($content === null) {
                continue;
            }
            $stripped = self::stripCommentsAndStrings($content);
            if (preg_match('/\buse\s+App\\\\Services\\\\Ai\\\\Voice\\\\/u', $stripped) === 1) {
                $offenders[] = self::relativeTo($path, base_path());
            }
        }

        if ($offenders !== []) {
            return [
                'name' => 'voice_realtime_untouched',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $offenders,
                'expected' => [],
                'evidence' => 'Vox files import from App\\Services\\Ai\\Voice — boundary breached.',
            ];
        }

        return [
            'name' => 'voice_realtime_untouched',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'no Voice imports',
            'expected' => 'no Voice imports',
            'evidence' => 'No `use App\\Services\\Ai\\Voice\\…` statement found in any file under app/Services/Ai/Vox/.',
        ];
    }

    /**
     * Mobile sources live in atlas-app/, outside this repo. Verifying the
     * "mobile untouched" invariant requires looking outside atlas-server,
     * which is not the audit's job. We return `unknown` honestly rather
     * than pretending to have measured it.
     */
    private function checkMobileUntouched(): array
    {
        return self::unknown(
            name: 'mobile_untouched',
            source: self::SOURCE_STATIC,
            expected: 'no atlas-app changes from V3',
            evidence: 'atlas-app/ is a separate repo (sibling of atlas-server); this audit deliberately does not reach across repos. Verify mobile boundary manually before GATE V3.',
        );
    }

    /**
     * V3 must not have added a provider API SDK (Anthropic/OpenAI/HTTP
     * provider calls). Provider CLIs are shelled out under the gate;
     * that is allowed. We scan Vox source for SDK imports and direct
     * provider HTTP hosts.
     */
    private function checkProviderApiNotAdded(): array
    {
        $voxDir = base_path('app/Services/Ai/Vox');
        if (! is_dir($voxDir)) {
            return self::unknown(
                name: 'provider_api_not_added',
                source: self::SOURCE_STATIC,
                expected: 'no provider SDK imports / no provider HTTP hosts',
                evidence: 'Vox directory not present.',
            );
        }

        $sdkPatterns = [
            '/\buse\s+Anthropic\\\\/u',
            '/\buse\s+OpenAI\\\\/u',
            '/\buse\s+App\\\\Services\\\\Ai\\\\(?:AiProvider|AiGateway)/u',
        ];
        $hostPatterns = [
            '/api\.anthropic\.com/i',
            '/api\.openai\.com/i',
        ];

        $offenders = [];
        foreach (self::iteratePhpFiles($voxDir) as $path) {
            // The audit service itself must mention these patterns to
            // detect them — exclude the entire Audit/ directory from the
            // scan. Anything else under Vox/ is fair game.
            if (str_contains($path, DIRECTORY_SEPARATOR.'Audit'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $raw = self::readFileOrNull($path);
            if ($raw === null) {
                continue;
            }
            $stripped = self::stripCommentsAndStrings($raw);
            foreach ($sdkPatterns as $pat) {
                if (preg_match($pat, $stripped) === 1) {
                    $offenders[] = [
                        'file' => self::relativeTo($path, base_path()),
                        'kind' => 'sdk_import',
                    ];
                    break;
                }
            }
            foreach ($hostPatterns as $pat) {
                if (preg_match($pat, $raw) === 1) {
                    $offenders[] = [
                        'file' => self::relativeTo($path, base_path()),
                        'kind' => 'provider_host_url',
                    ];
                    break;
                }
            }
        }

        if ($offenders !== []) {
            return [
                'name' => 'provider_api_not_added',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $offenders,
                'expected' => [],
                'evidence' => 'Vox source references a provider SDK or HTTP host — V3 must remain CLI-shelled-out only.',
            ];
        }

        return [
            'name' => 'provider_api_not_added',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'no provider SDK / no provider host URL',
            'expected' => 'no provider SDK / no provider host URL',
            'evidence' => 'No Anthropic/OpenAI SDK imports, no AiProvider/AiGateway use, no api.anthropic.com / api.openai.com strings under app/Services/Ai/Vox.',
        ];
    }

    /**
     * Confirmation tokens are HMAC secrets and must never be ledgered.
     * Audit by scanning VOX_CONFIRMATION_REQUESTED + VOX_ACTION_DISPATCHED
     * payloads for any `confirmation_token` key.
     */
    private function checkConfirmationTokenNotInLedger(): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return self::unknown(
                name: 'confirmation_token_not_in_ledger',
                source: self::SOURCE_LEDGER,
                expected: 0,
                evidence: 'atlas_ledger_events table not present.',
            );
        }

        $candidates = [
            LedgerEventType::VoxConfirmationRequested->value,
            LedgerEventType::VoxActionDispatched->value,
            LedgerEventType::VoxEvidenceRecorded->value,
            LedgerEventType::VoxActionBlocked->value,
        ];

        $leaks = AtlasLedgerEvent::query()
            ->whereIn('event_type', $candidates)
            ->get(['event_id', 'event_type', 'payload']);

        $offenders = [];
        foreach ($leaks as $row) {
            $payload = $row->payload ?? [];
            if (! is_array($payload)) {
                continue;
            }
            if (self::payloadContainsConfirmationToken($payload)) {
                $offenders[] = [
                    'event_id' => (string) $row->event_id,
                    'event_type' => (string) $row->event_type,
                ];
            }
        }

        if ($offenders !== []) {
            return [
                'name' => 'confirmation_token_not_in_ledger',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_LEDGER,
                'observed' => count($offenders),
                'expected' => 0,
                'evidence' => 'Ledger payload contains `confirmation_token` field — token material is leaking into audit log.',
                'offenders' => $offenders,
            ];
        }

        return [
            'name' => 'confirmation_token_not_in_ledger',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_LEDGER,
            'observed' => 0,
            'expected' => 0,
            'evidence' => 'No `confirmation_token` field found in Vox confirmation/dispatch/evidence/blocked ledger payloads.',
        ];
    }

    /**
     * R4 confirmations must require a literal typed string. Static check
     * on VoxConfirmationService: the issue() method must compute
     * `$requiresLiteral = $riskClass === VoxSchema::RISK_R4;` (or
     * equivalent), and consume() must guard on
     * `requires_literal_confirmation`.
     */
    private function checkR4LiteralRequired(): array
    {
        $path = base_path('app/Services/Ai/Vox/Confirmation/VoxConfirmationService.php');
        $src = self::readFileOrNull($path);
        if ($src === null) {
            return self::unknown(
                name: 'r4_literal_required',
                source: self::SOURCE_STATIC,
                expected: 'R4 binds + verifies literal_confirmation_text',
                evidence: "Source file missing: {$path}",
            );
        }

        // The R4 binding is real code (compares a variable to a const),
        // but the guard's signal is a magic-string error code — keep
        // string literals so the docblock alone can't satisfy it.
        $codeOnly = self::stripCommentsAndStrings($src);
        $withStrings = self::stripCommentsOnly($src);

        $hasR4Bind = preg_match('/\$riskClass\s*===\s*VoxSchema::RISK_R4/u', $codeOnly) === 1
            || preg_match('/RISK_R4\s*===\s*\$riskClass/u', $codeOnly) === 1;
        $hasLiteralGuard = str_contains($withStrings, 'requires_literal_confirmation')
            && str_contains($withStrings, 'literal_confirmation_mismatch');

        if (! $hasR4Bind || ! $hasLiteralGuard) {
            $missing = [];
            if (! $hasR4Bind) {
                $missing[] = 'R4 risk binding';
            }
            if (! $hasLiteralGuard) {
                $missing[] = 'literal_confirmation guard';
            }

            return [
                'name' => 'r4_literal_required',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $missing,
                'expected' => ['R4 risk binding', 'literal_confirmation guard'],
                'evidence' => 'VoxConfirmationService no longer enforces literal confirmation for R4 risk class.',
            ];
        }

        return [
            'name' => 'r4_literal_required',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'R4 literal binding + consume guard present',
            'expected' => 'R4 binds + verifies literal_confirmation_text',
            'evidence' => 'VoxConfirmationService binds requires_literal_confirmation when risk_class=R4 and rejects mismatches with literal_confirmation_mismatch.',
        ];
    }

    /**
     * The gate must accept only `governed_execute` and must reject an
     * expired receipt before any executor runs. Static check on
     * VoxExecutionGate source.
     */
    private function checkGovernedExecuteRequiresReceipt(): array
    {
        $path = base_path('app/Services/Ai/Vox/Execution/VoxExecutionGate.php');
        $src = self::readFileOrNull($path);
        if ($src === null) {
            return self::unknown(
                name: 'governed_execute_requires_receipt',
                source: self::SOURCE_STATIC,
                expected: 'gate enforces mode=governed_execute + receipt expiry',
                evidence: "Source file missing: {$path}",
            );
        }

        $codeOnly = self::stripCommentsAndStrings($src);
        $withStrings = self::stripCommentsOnly($src);

        $hasModeGate = str_contains($codeOnly, 'MODE_GOVERNED_EXECUTE')
            && str_contains($withStrings, 'gate_only_governed_execute');
        $hasReceiptExpiry = str_contains($withStrings, 'receipt_expired')
            && (str_contains($codeOnly, 'isPast()') || str_contains($withStrings, 'expires_at'));

        if (! $hasModeGate || ! $hasReceiptExpiry) {
            $missing = [];
            if (! $hasModeGate) {
                $missing[] = 'mode=governed_execute enforcement';
            }
            if (! $hasReceiptExpiry) {
                $missing[] = 'receipt expiry check';
            }

            return [
                'name' => 'governed_execute_requires_receipt',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $missing,
                'expected' => ['mode=governed_execute enforcement', 'receipt expiry check'],
                'evidence' => 'VoxExecutionGate weakened — receipt/mode invariant no longer enforced before executors run.',
            ];
        }

        return [
            'name' => 'governed_execute_requires_receipt',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'mode + expiry guard present',
            'expected' => 'gate enforces mode=governed_execute + receipt expiry',
            'evidence' => 'VoxExecutionGate refuses non-governed_execute modes and rejects past-expiry receipts before any executor runs.',
        ];
    }

    /**
     * Every VOX_EVIDENCE_RECORDED whose executor=terminal_propose must
     * carry metadata.command_executed=false. If any ledger row violates
     * that, V3 is no longer propose-only.
     */
    private function checkTerminalProposeCommandExecutedFalse(): array
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return self::unknown(
                name: 'terminal_propose_command_executed_false',
                source: self::SOURCE_LEDGER,
                expected: 'every terminal_propose event has metadata.command_executed=false',
                evidence: 'atlas_ledger_events table not present.',
            );
        }

        $rows = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::VoxEvidenceRecorded->value)
            ->whereJsonContains('payload->executor', VoxSchema::EXECUTOR_TERMINAL_PROPOSE)
            ->get(['event_id', 'payload']);

        $offenders = [];
        $total = 0;
        foreach ($rows as $row) {
            $total++;
            $payload = $row->payload ?? [];
            $flag = data_get($payload, 'metadata.command_executed', null);
            // Honest fail-closed: only `=== false` counts as "executed
            // flag set to false". A missing flag is suspicious; anything
            // truthy is an outright fail.
            if ($flag !== false) {
                $offenders[] = [
                    'event_id' => (string) $row->event_id,
                    'observed_command_executed' => $flag,
                ];
            }
        }

        if ($offenders !== []) {
            return [
                'name' => 'terminal_propose_command_executed_false',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_LEDGER,
                'observed' => count($offenders),
                'expected' => 0,
                'evidence' => 'VOX_EVIDENCE_RECORDED for terminal_propose found with metadata.command_executed != false.',
                'offenders' => $offenders,
                'events_inspected' => $total,
            ];
        }

        return [
            'name' => 'terminal_propose_command_executed_false',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_LEDGER,
            'observed' => 0,
            'expected' => 0,
            'evidence' => "Across {$total} terminal_propose evidence event(s), metadata.command_executed is false on every row.",
            'events_inspected' => $total,
        ];
    }

    /**
     * V4 must not have started. Verify by:
     *   - no routes matching `/ai/vox/v4` or `/ai/vox/wave/4`
     *   - no service file under app/Services/Ai/Vox/V4/
     *   - all VOX_V3_CERTIFICATION_PACK_CREATED payloads carry
     *     v4_unlock_allowed=false
     *   - all VOX_V3_PROMOTION_REVIEW_RECORDED payloads carry
     *     v4_unlocked_by_review=false
     */
    private function checkV4NotStarted(): array
    {
        $signals = [];

        // 1. Code surface
        $v4Dir = base_path('app/Services/Ai/Vox/V4');
        if (is_dir($v4Dir)) {
            $signals[] = 'app/Services/Ai/Vox/V4/ directory exists';
        }

        // 2. Routes file
        $routesPath = base_path('routes/api.php');
        $routes = self::readFileOrNull($routesPath);
        if ($routes !== null) {
            if (preg_match('#/ai/vox/v4\b#u', $routes) === 1
                || preg_match('#/ai/vox/wave/4\b#u', $routes) === 1
            ) {
                $signals[] = 'routes/api.php declares a V4 vox route';
            }
        }

        // 3. Ledger — check that no cert pack / review event flipped the
        //    v4_unlock_allowed / v4_unlocked_by_review flag to true.
        $tableMissing = ! Schema::hasTable('atlas_ledger_events');
        if (! $tableMissing) {
            $allowedFlips = AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::VoxV3CertificationPackCreated->value)
                ->whereJsonContains('payload->v4_unlock_allowed', true)
                ->count();
            if ($allowedFlips > 0) {
                $signals[] = "VOX_V3_CERTIFICATION_PACK_CREATED flipped v4_unlock_allowed=true on {$allowedFlips} row(s)";
            }
            $reviewFlips = AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::VoxV3PromotionReviewRecorded->value)
                ->whereJsonContains('payload->v4_unlocked_by_review', true)
                ->count();
            if ($reviewFlips > 0) {
                $signals[] = "VOX_V3_PROMOTION_REVIEW_RECORDED flipped v4_unlocked_by_review=true on {$reviewFlips} row(s)";
            }
        }

        if ($signals !== []) {
            return [
                'name' => 'v4_not_started',
                'status' => self::STATUS_FAIL,
                'source' => self::SOURCE_STATIC,
                'observed' => $signals,
                'expected' => [],
                'evidence' => 'V4 markers detected — V4 work must remain frozen until GATE V3 passes manually.',
            ];
        }

        if ($tableMissing) {
            return [
                'name' => 'v4_not_started',
                'status' => self::STATUS_WARN,
                'source' => self::SOURCE_STATIC,
                'observed' => 'static surface clean, ledger unverifiable',
                'expected' => 'no V4 code, no V4 routes, no v4_unlock_allowed=true in ledger',
                'evidence' => 'Static surface shows no V4 markers, but atlas_ledger_events table is missing so v4_unlock_allowed/v4_unlocked_by_review flags could not be re-verified.',
            ];
        }

        return [
            'name' => 'v4_not_started',
            'status' => self::STATUS_PASS,
            'source' => self::SOURCE_STATIC,
            'observed' => 'no V4 code, no V4 routes, no ledger unlock flips',
            'expected' => 'no V4 code, no V4 routes, no v4_unlock_allowed=true in ledger',
            'evidence' => 'No app/Services/Ai/Vox/V4/ directory, no /ai/vox/v4 route, and every cert pack + review event keeps v4_unlock_allowed=false.',
        ];
    }

    /**
     * @param  array<mixed,mixed>  $payload
     */
    private static function payloadContainsConfirmationToken(array $payload): bool
    {
        foreach ($payload as $k => $v) {
            if ($k === 'confirmation_token') {
                return true;
            }
            if (is_array($v) && self::payloadContainsConfirmationToken($v)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip PHP comments only; keep string literals. Use this when the
     * check legitimately needs to read a string constant from source
     * (e.g. an array key like 'command_executed').
     */
    private static function stripCommentsOnly(string $src): string
    {
        $out = '';
        $tokens = @token_get_all($src);
        if (! is_array($tokens)) {
            return $src;
        }
        $skip = [T_COMMENT, T_DOC_COMMENT];
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                if (in_array($tok[0], $skip, true)) {
                    $out .= ' ';

                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }

    /**
     * Strip PHP comments + string literals so static checks can't be
     * fooled by example text inside a docblock or sample string.
     */
    private static function stripCommentsAndStrings(string $src): string
    {
        $out = '';
        $tokens = @token_get_all($src);
        if (! is_array($tokens)) {
            return $src;
        }
        $skip = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                if (in_array($tok[0], $skip, true)) {
                    $out .= ' ';

                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function iteratePhpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function readFileOrNull(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        $c = @file_get_contents($path);

        return $c === false ? null : $c;
    }

    private static function relativeTo(string $path, string $base): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    private static function unknown(string $name, string $source, mixed $expected, string $evidence): array
    {
        return [
            'name' => $name,
            'status' => self::STATUS_UNKNOWN,
            'source' => $source,
            'observed' => null,
            'expected' => $expected,
            'evidence' => $evidence,
        ];
    }
}
