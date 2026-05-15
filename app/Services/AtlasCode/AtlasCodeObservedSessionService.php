<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasCodeObservedSession;
use App\Models\AtlasProject;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Atlas Code Observed Session service.
 *
 * Canon:
 *   - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *   - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 *   - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
 *
 * Schema: atlas.code.observed_session.v1
 *
 * An Observed Session represents one interactive provider run inside an Obra:
 *   1. Atlas exports a Work Packet (markdown + copy-safe prompt).
 *   2. Atlas opens a terminal at the workspace_path.
 *   3. The operator runs `claude` / `codex` / `gemini` and pastes the prompt.
 *   4. The provider works interactively.
 *   5. The operator clicks "Import Result" and pastes the report + diff.
 *   6. Atlas runs gates and asks for human acceptance.
 *
 * State machine:
 *   waiting_operator → running → waiting_result_import → imported
 *     → review_required → accepted | rejected | repair_required | blocked
 *
 * The provider NEVER declares completion. The provider's text is just a
 * report — completion is an Atlas decision after gates + human acceptance.
 *
 * Storage: filesystem JSON under
 *   `storage/app/atlas-code/observed-sessions/{obra_id}/{session_id}.json`
 */
final class AtlasCodeObservedSessionService
{
    public const SCHEMA_VERSION = 'atlas.code.observed_session.v1';

    public const ALLOWED_STATES = [
        'waiting_operator',
        'running',
        'waiting_result_import',
        'imported',
        'review_required',
        'gates_running',
        'gates_passed',
        'gates_failed',
        'accepted',
        'rejected',
        'repair_required',
        'blocked',
    ];

    private const TRANSITIONS = [
        'waiting_operator' => ['running', 'blocked'],
        'running' => ['waiting_result_import', 'blocked'],
        'waiting_result_import' => ['imported', 'blocked'],
        // imported can auto-progress to review_required (existing flow) or
        // operator may run gates first; or scope guard may move to blocked.
        'imported' => ['review_required', 'gates_running', 'repair_required', 'blocked'],
        'review_required' => ['accepted', 'rejected', 'repair_required', 'gates_running', 'blocked'],
        'gates_running' => ['gates_passed', 'gates_failed', 'blocked'],
        // After gates the operator still has the final say; gates are
        // advisory. accepted is allowed straight from gates_passed only when
        // imported_report is already present (enforced by decide()).
        'gates_passed' => ['accepted', 'rejected', 'repair_required', 'review_required', 'blocked'],
        'gates_failed' => ['repair_required', 'rejected', 'review_required', 'blocked'],
        'accepted' => [],
        'rejected' => [],
        'repair_required' => ['waiting_operator', 'blocked'],
        'blocked' => ['waiting_operator', 'rejected'],
    ];

    public function __construct(
        private readonly AtlasCodeWorkPacketService $packets,
        private readonly AtlasCodeProviderGovernanceService $governance,
        private readonly AtlasCodeScopeGuardMatcher $scopeMatcher = new AtlasCodeScopeGuardMatcher(),
        private readonly GitWorkspaceInspector $git = new GitWorkspaceInspector(),
        private readonly HumanDecisionReceiptSigner $signer = new HumanDecisionReceiptSigner()
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForObra(string $obraId): array
    {
        if ($this->usesDatabase()) {
            return AtlasCodeObservedSession::query()
                ->where('obra_id', $obraId)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (AtlasCodeObservedSession $m): array => $this->shape($this->modelToArray($m)))
                ->all();
        }
        $dir = $this->sessionsDir($obraId);
        if (! is_dir($dir)) {
            return [];
        }
        $sessions = [];
        foreach ((array) glob($dir.'/*.json') as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $sessions[] = $this->shape($decoded);
            }
        }
        usort($sessions, static fn (array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? '')
        ));
        return $sessions;
    }

    public function find(string $obraId, string $sessionId): ?array
    {
        if ($this->usesDatabase()) {
            $row = AtlasCodeObservedSession::query()
                ->where('obra_id', $obraId)
                ->where('id', $sessionId)
                ->first();
            return $row ? $this->shape($this->modelToArray($row)) : null;
        }
        $path = $this->sessionPath($obraId, $sessionId);
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }
        return $this->shape($decoded);
    }

    private function usesDatabase(): bool
    {
        try {
            return Schema::hasTable('atlas_code_observed_sessions');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToArray(AtlasCodeObservedSession $m): array
    {
        $arr = $m->toArray();
        $arr['schema_version'] = self::SCHEMA_VERSION;
        return $arr;
    }

    /**
     * Open a new Observed Session for an Obra + Work Packet + Provider.
     *
     * This is the entry point of the "Abrir Claude Code observado" CTA. It:
     *   - validates governance (must allow `interactive_observed`/`manual_import`)
     *   - validates packet exists, is exportable
     *   - calls AtlasCodeWorkPacketService::exportForObservedSession (writes
     *     .atlas/packets/{id}.md when workspace path is writable)
     *   - persists the session with state=waiting_operator + prompt + packet_md
     *
     * @return array<string, mixed>
     */
    public function open(AtlasProject $obra, string $packetId, string $providerId): array
    {
        $governance = $this->governance->snapshot();
        $provider = $this->governance->findProvider($providerId);
        if ($provider === null) {
            throw new RuntimeException("observed_session_provider_unknown: {$providerId}");
        }
        if (! in_array($provider['invocation_mode'], $governance['allowed_invocation_modes'], true)) {
            throw new RuntimeException("observed_session_invocation_mode_blocked: {$provider['invocation_mode']}");
        }
        // Observed sessions are interactive-only by definition; under the
        // "blocked" full kill switch we also refuse to open new sessions.
        if (! empty($governance['full_block'])) {
            throw new RuntimeException('observed_session_blocked_by_policy:full_block');
        }

        $obraId = (string) $obra->getKey();

        // Honest workspace blockers BEFORE packet export so the operator
        // gets a clear error code instead of "work_packet_persist_failed".
        $workspacePath = (string) (data_get($obra->metadata, 'workspace_path') ?? '');
        if ($workspacePath !== '') {
            if (! @is_dir($workspacePath)) {
                throw new RuntimeException("observed_session_blocked:workspace_missing:{$workspacePath}");
            }
            if (! @is_writable($workspacePath)) {
                throw new RuntimeException("observed_session_blocked:workspace_not_writable:{$workspacePath}");
            }
        }

        try {
            $export = $this->packets->exportForObservedSession($obraId, $packetId, $providerId);
        } catch (RuntimeException $e) {
            // Surface canonical blocker codes per spec PART 6.
            $msg = $e->getMessage();
            if (str_contains($msg, 'work_packet_not_found')) {
                throw new RuntimeException('observed_session_blocked:obra_not_ready:work_packet_not_found');
            }
            if (str_contains($msg, 'work_packet_incomplete')) {
                throw new RuntimeException('observed_session_blocked:obra_not_ready:'.$msg);
            }
            throw new RuntimeException('observed_session_blocked:packet_generation_failed:'.$msg);
        }

        $sessionId = 'os_'.Str::ulid()->toBase32();
        $now = now()->toJSON();

        $session = [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $sessionId,
            'obra_id' => $obraId,
            'obra_title' => (string) ($obra->title ?? ''),
            'work_packet_id' => $packetId,
            'provider_id' => $providerId,
            'provider_name' => (string) $provider['name'],
            'provider_family' => (string) $provider['family'],
            'invocation_mode' => (string) $provider['invocation_mode'],
            'role_slot' => (string) ($export['packet']['role_slot'] ?? 'implementation_lead'),
            'workspace_slug' => $export['packet']['workspace_slug'] ?? null,
            'workspace_path' => $export['packet']['workspace_path'] ?? null,
            'packet_md_path' => $export['packet_md_path'],
            'packet_md_status' => $export['packet_md_status'],
            'packet_md_excerpt' => mb_substr($export['packet_md'], 0, 2000),
            'prompt' => $export['prompt'],
            'prompt_hash' => (string) ($export['packet']['prompt_hash'] ?? ''),
            'terminal_command_hint' => $this->terminalCommandHint($provider),
            'state' => 'waiting_operator',
            'state_history' => [
                ['state' => 'waiting_operator', 'at' => $now, 'reason' => 'session_opened'],
            ],
            'operator_opened_terminal_at' => null,
            'operator_marked_running_at' => null,
            'result_imported_at' => null,
            'report_text' => null,
            'report_files' => [],
            'diff_excerpt' => null,
            'diff_hash' => null,
            'gates' => [],
            'gates_summary' => [
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'pending' => 0,
            ],
            'human_decision' => null,
            'blocker_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'governance' => [
                'claude_programmatic_policy' => (string) $governance['claude_programmatic_policy'],
                'effective_policy' => (string) $governance['effective_policy'],
                'programmatic_invocation_allowed' => (bool) $governance['programmatic_invocation_allowed'],
                'productive_headless_allowed' => (bool) $governance['productive_headless_allowed'],
                'interactive_only' => (bool) $governance['interactive_only'],
                'invocation_mode_allowed' => true,
                'invocation_mode' => (string) $provider['invocation_mode'],
            ],
        ];

        $this->persist($session);
        return $this->shape($session);
    }

    /**
     * One-shot helper for the "Abrir Claude Code observado" CTA.
     *
     * Creates a Work Packet from the operator's quick-form payload, then
     * opens an Observed Session bound to that packet, all in one round-trip.
     * Useful when the operator wants to start immediately without authoring
     * the packet separately. The packet remains a first-class artifact and
     * can be inspected/edited later.
     *
     * @param  array<string, mixed>  $packetPayload
     * @return array{session: array<string, mixed>, packet: array<string, mixed>}
     */
    public function quickOpenClaudeCodeObserved(AtlasProject $obra, array $packetPayload, string $providerId = 'claude_code'): array
    {
        $packet = $this->packets->create($obra, $packetPayload);
        $session = $this->open($obra, (string) $packet['id'], $providerId);
        return ['session' => $session, 'packet' => $packet];
    }

    /**
     * Mark the session as running (operator confirmed they sent the prompt
     * and the provider is working interactively).
     */
    public function markRunning(string $obraId, string $sessionId): array
    {
        return $this->transition($obraId, $sessionId, 'running', [
            'operator_marked_running_at' => now()->toJSON(),
        ], 'operator_started_provider_session');
    }

    public function markWaitingImport(string $obraId, string $sessionId): array
    {
        return $this->transition($obraId, $sessionId, 'waiting_result_import', [], 'provider_session_paused_pending_report');
    }

    /**
     * Import the operator's result: free-text report + optional file list +
     * optional diff excerpt. We hash the diff and store an excerpt; full
     * diff/scope guard belongs to the gates layer.
     *
     * @param  array{report_text: string, files?: array<int, string>, diff_excerpt?: string}  $payload
     */
    public function importResult(string $obraId, string $sessionId, array $payload): array
    {
        $existing = $this->find($obraId, $sessionId);
        if ($existing === null) {
            throw new RuntimeException("observed_session_not_found: {$sessionId}");
        }
        $report = trim((string) ($payload['report_text'] ?? ''));
        if ($report === '') {
            throw new RuntimeException('observed_session_import_requires_report_text');
        }
        $files = array_values(array_filter(
            (array) ($payload['files'] ?? []),
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
        $diff = isset($payload['diff_excerpt']) ? (string) $payload['diff_excerpt'] : null;
        $diffHash = $diff !== null ? hash('sha256', $diff) : null;

        // Auto-capture via git when the operator did not paste diff/files
        // AND the workspace is a git repo. Honest fallback when not git.
        $gitSnapshot = null;
        $workspacePath = (string) ($existing['workspace_path'] ?? '');
        $needsAutoCapture = ($files === [] || $diff === null) && $workspacePath !== '';
        if ($needsAutoCapture) {
            $gitSnapshot = $this->git->captureSnapshot($workspacePath);
            if (! empty($gitSnapshot['success'])) {
                if ($files === [] && is_array($gitSnapshot['files_changed']) && $gitSnapshot['files_changed'] !== []) {
                    $files = $gitSnapshot['files_changed'];
                }
                if ($diff === null && is_string($gitSnapshot['diff_excerpt']) && $gitSnapshot['diff_excerpt'] !== '') {
                    $diff = $gitSnapshot['diff_excerpt'];
                    $diffHash = isset($gitSnapshot['diff_hash']) && is_string($gitSnapshot['diff_hash'])
                        ? $gitSnapshot['diff_hash']
                        : hash('sha256', $diff);
                }
            }
        }

        // Scope guard against the original packet's allowed_files/forbidden_files.
        // Per canon PART 7: when allowed_files exists and report.files lists
        // entries outside it, move to `blocked` with `scope_violation_detected`
        // instead of `review_required`. Atlas never accepts silently.
        $packet = $this->packets->find($obraId, (string) ($existing['work_packet_id'] ?? ''));
        $allowedFiles = is_array($packet) ? (array) ($packet['allowed_files'] ?? []) : [];
        $forbiddenFiles = is_array($packet) ? (array) ($packet['forbidden_files'] ?? []) : [];
        $scopeGuard = $this->evaluateScopeGuard($files, $allowedFiles, $forbiddenFiles);

        $now = now()->toJSON();
        $existing['result_imported_at'] = $now;
        $existing['report_text'] = $report;
        $existing['report_files'] = $files;
        $existing['diff_excerpt'] = $diff !== null ? mb_substr($diff, 0, 8000) : null;
        $existing['diff_hash'] = $diffHash;
        $existing['scope_guard'] = $scopeGuard;
        $existing['git_snapshot'] = $gitSnapshot;
        $existing['updated_at'] = $now;

        $this->persistRaw($existing);
        // Always pass through `imported` first to preserve the audit trail.
        $this->transition($obraId, $sessionId, 'imported', [], 'operator_imported_result');

        if ($scopeGuard['status'] === 'failed') {
            // Honest blocker — operator must repair packet OR reject/repair.
            $existing = $this->find($obraId, $sessionId) ?? $existing;
            $existing['blocker_reason'] = 'scope_violation_detected: '.$scopeGuard['summary'];
            $this->persistRaw($existing);
            return $this->transition($obraId, $sessionId, 'blocked', [], 'scope_violation_detected');
        }

        return $this->transition($obraId, $sessionId, 'review_required', [], 'imported_auto_to_review');
    }

    /**
     * Run advisory gates on an imported session. Honest implementation —
     * Atlas does NOT execute verification_commands itself (operator runs
     * them). The gates we compute are:
     *   - scope_guard: re-evaluates report.files vs packet.allowed_files
     *   - acceptance_criteria_declared: packet has criteria
     *   - verification_commands_declared: packet has commands (not run)
     *   - report_imported: report_text was imported
     *   - diff_imported: diff_excerpt was imported (warning only when missing)
     *
     * Transition: caller state must be `imported`, `review_required` or one
     * of the gates_* terminal states. Service moves through gates_running
     * → gates_passed | gates_failed.
     *
     * @return array<string, mixed>
     */
    public function runGates(string $obraId, string $sessionId): array
    {
        $session = $this->find($obraId, $sessionId);
        if ($session === null) {
            throw new RuntimeException("observed_session_not_found: {$sessionId}");
        }
        $state = (string) ($session['state'] ?? '');
        if (! in_array($state, ['imported', 'review_required', 'gates_passed', 'gates_failed'], true)) {
            throw new RuntimeException("observed_session_gates_not_available_from_state:{$state}");
        }
        $packet = $this->packets->find($obraId, (string) ($session['work_packet_id'] ?? ''));
        if (! is_array($packet)) {
            // Packet missing — gates cannot run, but be honest about it.
            $session['gates'] = [[
                'gate_id' => 'packet_present',
                'status' => 'unsupported',
                'detail' => 'work_packet_not_found · gates cannot be evaluated',
            ]];
            $session['gates_summary'] = ['total' => 1, 'passed' => 0, 'failed' => 1, 'pending' => 0, 'unsupported' => 1];
            $this->persistRaw($session);
            return $this->transition($obraId, $sessionId, 'gates_failed', [], 'gates_packet_missing');
        }

        // Move to gates_running for auditability.
        $this->transition($obraId, $sessionId, 'gates_running', [], 'gates_run_started');
        $session = $this->find($obraId, $sessionId) ?? $session;

        $gates = [];
        $reportFiles = (array) ($session['report_files'] ?? []);
        $allowed = (array) ($packet['allowed_files'] ?? []);
        $forbidden = (array) ($packet['forbidden_files'] ?? []);

        // 1. Scope guard
        $scope = $this->evaluateScopeGuard($reportFiles, $allowed, $forbidden);
        $gates[] = [
            'gate_id' => 'scope_guard',
            'status' => $scope['status'],
            'detail' => $scope['summary'],
            'violations' => $scope['violations'],
            'allowed_files_declared' => count($allowed),
            'report_files_listed' => count($reportFiles),
        ];

        // 2. Acceptance criteria declared
        $criteria = (array) ($packet['acceptance_criteria'] ?? []);
        $gates[] = [
            'gate_id' => 'acceptance_criteria_declared',
            'status' => count($criteria) > 0 ? 'passed' : 'failed',
            'detail' => count($criteria) > 0
                ? count($criteria).' criteria declared in packet'
                : 'No acceptance criteria — operator must amend packet before accept',
        ];

        // 3. Verification commands declared (NOT executed by Atlas).
        $vcmd = (array) ($packet['verification_commands'] ?? []);
        $gates[] = [
            'gate_id' => 'verification_commands_declared',
            'status' => count($vcmd) > 0 ? 'commands_available_but_not_run' : 'not_configured',
            'detail' => count($vcmd) > 0
                ? count($vcmd).' commands declared; operator must run them in the workspace terminal and report outcomes manually'
                : 'Packet did not declare validation_commands — gates run is advisory only',
            'commands' => $vcmd,
        ];

        // 4. Report imported
        $reportText = (string) ($session['report_text'] ?? '');
        $gates[] = [
            'gate_id' => 'report_imported',
            'status' => $reportText !== '' ? 'passed' : 'failed',
            'detail' => $reportText !== ''
                ? 'Report text imported ('.mb_strlen($reportText).' chars)'
                : 'No report imported — operator must import before gates',
        ];

        // 5. Diff imported (warning only)
        $diffHash = $session['diff_hash'] ?? null;
        $gates[] = [
            'gate_id' => 'diff_imported',
            'status' => $diffHash !== null ? 'passed' : 'commands_available_but_not_run',
            'detail' => $diffHash !== null
                ? 'Diff hash recorded ('.substr((string) $diffHash, 0, 16).')'
                : 'No diff excerpt imported — scope guard relies on report.files only',
        ];

        // Summarize.
        $counts = ['total' => count($gates), 'passed' => 0, 'failed' => 0, 'pending' => 0, 'unsupported' => 0];
        $hasFailure = false;
        foreach ($gates as $g) {
            $st = $g['status'];
            if ($st === 'passed') {
                $counts['passed']++;
            } elseif ($st === 'failed') {
                $counts['failed']++;
                $hasFailure = true;
            } elseif ($st === 'unsupported') {
                $counts['unsupported']++;
            } else {
                // commands_available_but_not_run / not_configured → pending advisory
                $counts['pending']++;
            }
        }

        $session = $this->find($obraId, $sessionId) ?? $session;
        $session['gates'] = $gates;
        $session['gates_summary'] = $counts;
        $session['gates_evaluated_at'] = now()->toJSON();
        $this->persistRaw($session);

        $next = $hasFailure ? 'gates_failed' : 'gates_passed';
        return $this->transition($obraId, $sessionId, $next, [], 'gates_'.$next);
    }

    /**
     * Evaluate scope guard via the canonical fnmatch-based matcher.
     *
     * @param  array<int, string>  $reportFiles
     * @param  array<int, string>  $allowedFiles
     * @param  array<int, string>  $forbiddenFiles
     * @return array<string, mixed>
     */
    private function evaluateScopeGuard(array $reportFiles, array $allowedFiles, array $forbiddenFiles): array
    {
        $violations = $this->scopeMatcher->violations($reportFiles, $allowedFiles, $forbiddenFiles);
        if ($violations !== []) {
            return [
                'status' => 'failed',
                'summary' => count($violations).' file(s) outside scope',
                'violations' => $violations,
            ];
        }
        if ($allowedFiles === [] && $forbiddenFiles === []) {
            return [
                'status' => 'unsupported',
                'summary' => 'packet declared no allowed_files/forbidden_files',
                'violations' => [],
            ];
        }
        if ($reportFiles === []) {
            return [
                'status' => 'commands_available_but_not_run',
                'summary' => 'report did not list files; scope guard could not run',
                'violations' => [],
            ];
        }
        return [
            'status' => 'passed',
            'summary' => 'no scope violations detected',
            'violations' => [],
        ];
    }

    /**
     * Apply a human decision: accept | reject | request_repair | block.
     *
     * Completion is ONLY recorded when the operator accepts AND the session
     * has an imported report. We never accept based on provider text alone.
     */
    public function decide(string $obraId, string $sessionId, string $action, ?string $reason): array
    {
        $session = $this->find($obraId, $sessionId);
        if ($session === null) {
            throw new RuntimeException("observed_session_not_found: {$sessionId}");
        }
        if (! in_array($action, ['accept', 'reject', 'request_repair', 'block'], true)) {
            throw new RuntimeException("observed_session_action_invalid: {$action}");
        }
        $now = now()->toJSON();
        $next = match ($action) {
            'accept' => 'accepted',
            'reject' => 'rejected',
            'request_repair' => 'repair_required',
            'block' => 'blocked',
        };
        if ($action === 'accept' && $session['result_imported_at'] === null) {
            throw new RuntimeException('observed_session_cannot_accept_without_imported_report');
        }
        $cleanedReason = $reason !== null ? trim($reason) : null;
        $decisionPayload = [
            'session_id' => $sessionId,
            'obra_id' => $obraId,
            'work_packet_id' => (string) ($session['work_packet_id'] ?? ''),
            'action' => $action,
            'reason' => $cleanedReason,
            'decided_at' => $now,
        ];
        $receipt = $this->signer->signDecision($decisionPayload);

        $session['human_decision'] = array_merge($decisionPayload, [
            'signing_status' => $receipt['signing_status'],
            'signature' => $receipt['signature'],
            'public_key' => $receipt['public_key'],
            'canonical_payload_hash' => $receipt['canonical_payload_hash'],
            'signed_at' => $receipt['signed_at'],
            'signer_id' => $receipt['signer_id'],
            'receipt_schema' => $receipt['schema_version'],
        ]);
        if ($next === 'blocked') {
            $session['blocker_reason'] = $cleanedReason ?? 'operator_blocked_without_reason';
        }
        $this->persistRaw($session);
        return $this->transition($obraId, $sessionId, $next, [], 'human_'.$action);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(string $obraId, string $sessionId, string $nextState, array $extra, string $reason): array
    {
        $session = $this->find($obraId, $sessionId);
        if ($session === null) {
            throw new RuntimeException("observed_session_not_found: {$sessionId}");
        }
        $current = (string) ($session['state'] ?? 'waiting_operator');
        if ($current === $nextState) {
            // Idempotent: same state, just refresh updated_at.
            $session['updated_at'] = now()->toJSON();
            $this->persistRaw($session);
            return $this->shape($session);
        }
        $allowed = self::TRANSITIONS[$current] ?? [];
        if (! in_array($nextState, $allowed, true)) {
            throw new RuntimeException("observed_session_transition_invalid: {$current} -> {$nextState}");
        }
        $session = array_merge($session, $extra);
        $session['state'] = $nextState;
        $session['updated_at'] = now()->toJSON();
        $session['state_history'] = array_slice(
            array_merge(
                (array) ($session['state_history'] ?? []),
                [['state' => $nextState, 'at' => $session['updated_at'], 'reason' => $reason]]
            ),
            -32 // keep last 32 transitions
        );
        $this->persistRaw($session);
        return $this->shape($session);
    }

    public function sessionsBaseDir(): string
    {
        return storage_path('app/atlas-code/observed-sessions');
    }

    public function sessionsDir(string $obraId): string
    {
        return $this->sessionsBaseDir().'/'.$this->safeSegment($obraId);
    }

    public function sessionPath(string $obraId, string $sessionId): string
    {
        return $this->sessionsDir($obraId).'/'.$this->safeSegment($sessionId).'.json';
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function terminalCommandHint(array $provider): ?string
    {
        $bin = $provider['binary_hint'] ?? null;
        return is_string($bin) && $bin !== '' ? $bin : null;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function persist(array $session): void
    {
        $this->persistRaw($session);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function persistRaw(array $session): void
    {
        $obraId = (string) ($session['obra_id'] ?? '');
        $sessionId = (string) ($session['id'] ?? '');
        if ($obraId === '' || $sessionId === '') {
            throw new RuntimeException('observed_session_persist_missing_keys');
        }

        if ($this->usesDatabase()) {
            $hd = $session['human_decision'] ?? null;
            $attrs = [
                'id' => $sessionId,
                'obra_id' => $obraId,
                'obra_title' => $session['obra_title'] ?? null,
                'work_packet_id' => (string) ($session['work_packet_id'] ?? ''),
                'provider_id' => (string) ($session['provider_id'] ?? ''),
                'provider_name' => $session['provider_name'] ?? null,
                'provider_family' => $session['provider_family'] ?? null,
                'invocation_mode' => (string) ($session['invocation_mode'] ?? 'interactive_observed'),
                'role_slot' => $session['role_slot'] ?? null,
                'workspace_slug' => $session['workspace_slug'] ?? null,
                'workspace_path' => $session['workspace_path'] ?? null,
                'packet_md_path' => $session['packet_md_path'] ?? null,
                'packet_md_status' => (string) ($session['packet_md_status'] ?? 'unknown'),
                'packet_md_excerpt' => $session['packet_md_excerpt'] ?? null,
                'prompt' => (string) ($session['prompt'] ?? ''),
                'prompt_hash' => $session['prompt_hash'] ?? null,
                'terminal_command_hint' => $session['terminal_command_hint'] ?? null,
                'state' => (string) ($session['state'] ?? 'waiting_operator'),
                'state_history' => $session['state_history'] ?? [],
                'operator_opened_terminal_at' => $session['operator_opened_terminal_at'] ?? null,
                'operator_marked_running_at' => $session['operator_marked_running_at'] ?? null,
                'result_imported_at' => $session['result_imported_at'] ?? null,
                'report_text' => $session['report_text'] ?? null,
                'report_files' => $session['report_files'] ?? [],
                'diff_excerpt' => $session['diff_excerpt'] ?? null,
                'diff_hash' => $session['diff_hash'] ?? null,
                'gates' => $session['gates'] ?? [],
                'gates_summary' => $session['gates_summary'] ?? null,
                'gates_evaluated_at' => $session['gates_evaluated_at'] ?? null,
                'scope_guard' => $session['scope_guard'] ?? null,
                'git_snapshot' => $session['git_snapshot'] ?? null,
                'human_decision' => $hd,
                'decision_signature' => is_array($hd) ? ($hd['signature'] ?? null) : null,
                'decision_public_key' => is_array($hd) ? ($hd['public_key'] ?? null) : null,
                'decision_signing_status' => is_array($hd) ? ($hd['signing_status'] ?? null) : null,
                'decision_signed_at' => is_array($hd) ? ($hd['signed_at'] ?? null) : null,
                'blocker_reason' => $session['blocker_reason'] ?? null,
                'governance' => $session['governance'] ?? null,
            ];
            AtlasCodeObservedSession::query()->updateOrCreate(['id' => $sessionId], $attrs);
            return;
        }

        $dir = $this->sessionsDir($obraId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $path = $this->sessionPath($obraId, $sessionId);
        $written = @file_put_contents($path, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            throw new RuntimeException("observed_session_persist_failed: {$path}");
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function shape(array $session): array
    {
        return [
            'schema_version' => (string) ($session['schema_version'] ?? self::SCHEMA_VERSION),
            'id' => (string) ($session['id'] ?? ''),
            'obra_id' => (string) ($session['obra_id'] ?? ''),
            'obra_title' => (string) ($session['obra_title'] ?? ''),
            'work_packet_id' => (string) ($session['work_packet_id'] ?? ''),
            'provider_id' => (string) ($session['provider_id'] ?? ''),
            'provider_name' => (string) ($session['provider_name'] ?? ''),
            'provider_family' => (string) ($session['provider_family'] ?? ''),
            'invocation_mode' => (string) ($session['invocation_mode'] ?? ''),
            'role_slot' => (string) ($session['role_slot'] ?? ''),
            'workspace_slug' => isset($session['workspace_slug']) && $session['workspace_slug'] !== ''
                ? (string) $session['workspace_slug']
                : null,
            'workspace_path' => isset($session['workspace_path']) && $session['workspace_path'] !== ''
                ? (string) $session['workspace_path']
                : null,
            'packet_md_path' => isset($session['packet_md_path']) && $session['packet_md_path'] !== ''
                ? (string) $session['packet_md_path']
                : null,
            'packet_md_status' => (string) ($session['packet_md_status'] ?? 'unknown'),
            'packet_md_excerpt' => (string) ($session['packet_md_excerpt'] ?? ''),
            'prompt' => (string) ($session['prompt'] ?? ''),
            'prompt_hash' => (string) ($session['prompt_hash'] ?? ''),
            'terminal_command_hint' => isset($session['terminal_command_hint']) && $session['terminal_command_hint'] !== ''
                ? (string) $session['terminal_command_hint']
                : null,
            'state' => (string) ($session['state'] ?? 'waiting_operator'),
            'state_history' => array_values((array) ($session['state_history'] ?? [])),
            'operator_opened_terminal_at' => isset($session['operator_opened_terminal_at']) && $session['operator_opened_terminal_at'] !== ''
                ? (string) $session['operator_opened_terminal_at']
                : null,
            'operator_marked_running_at' => isset($session['operator_marked_running_at']) && $session['operator_marked_running_at'] !== ''
                ? (string) $session['operator_marked_running_at']
                : null,
            'result_imported_at' => isset($session['result_imported_at']) && $session['result_imported_at'] !== ''
                ? (string) $session['result_imported_at']
                : null,
            'report_text' => isset($session['report_text']) ? (string) $session['report_text'] : null,
            'report_files' => array_values((array) ($session['report_files'] ?? [])),
            'diff_excerpt' => isset($session['diff_excerpt']) ? (string) $session['diff_excerpt'] : null,
            'diff_hash' => isset($session['diff_hash']) ? (string) $session['diff_hash'] : null,
            'gates' => array_values((array) ($session['gates'] ?? [])),
            'gates_summary' => (array) ($session['gates_summary'] ?? ['total' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0, 'unsupported' => 0]),
            'gates_evaluated_at' => isset($session['gates_evaluated_at']) && $session['gates_evaluated_at'] !== ''
                ? (string) $session['gates_evaluated_at']
                : null,
            'scope_guard' => isset($session['scope_guard']) && is_array($session['scope_guard'])
                ? $session['scope_guard']
                : null,
            'git_snapshot' => isset($session['git_snapshot']) && is_array($session['git_snapshot'])
                ? $session['git_snapshot']
                : null,
            'human_decision' => isset($session['human_decision']) && is_array($session['human_decision'])
                ? $session['human_decision']
                : null,
            'blocker_reason' => isset($session['blocker_reason']) && $session['blocker_reason'] !== ''
                ? (string) $session['blocker_reason']
                : null,
            'created_at' => (string) ($session['created_at'] ?? ''),
            'updated_at' => (string) ($session['updated_at'] ?? ''),
            'governance' => (array) ($session['governance'] ?? []),
        ];
    }

    private function safeSegment(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '_', $value) ?? '';
        if ($clean === '') {
            throw new RuntimeException('observed_session_unsafe_id');
        }
        return $clean;
    }
}
