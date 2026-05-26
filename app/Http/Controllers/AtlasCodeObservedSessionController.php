<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AtlasProject;
use App\Services\AtlasCode\AtlasCodeObservedSessionService;
use App\Services\AtlasCode\AtlasCodeVerificationCommandRunner as VerificationCommandRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Observed Session endpoints (Interactive Observed Provider Workflow).
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 *   GET  /api/atlas-code/works/{project}/observed-sessions                · list
 *   POST /api/atlas-code/works/{project}/observed-sessions                · open
 *   GET  /api/atlas-code/works/{project}/observed-sessions/{session}      · show
 *   POST /api/atlas-code/works/{project}/observed-sessions/{session}/state · transition (running, waiting_result_import)
 *   POST /api/atlas-code/works/{project}/observed-sessions/{session}/import · import operator report+diff
 *   POST /api/atlas-code/works/{project}/observed-sessions/{session}/decide · human decision: accept/reject/request_repair/block
 *
 * IMPORTANT: this controller NEVER executes a provider binary. Every action
 * is operator-driven (`waiting_operator` → manually mark running → manually
 * import). Atlas prepares state; the human runs the provider.
 */
final class AtlasCodeObservedSessionController extends Controller
{
    public function __construct(
        private readonly AtlasCodeObservedSessionService $sessions,
        private readonly VerificationCommandRunner $verifier = new VerificationCommandRunner
    ) {}

    public function index(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'schema_version' => AtlasCodeObservedSessionService::SCHEMA_VERSION,
            'data' => $this->sessions->listForObra((string) $project->getKey()),
        ]);
    }

    public function store(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'work_packet_id' => ['required', 'string', 'max:80'],
            'provider_id' => ['required', 'string', 'max:80'],
        ]);
        try {
            $session = $this->sessions->open($project, $data['work_packet_id'], $data['provider_id']);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $session], 201);
    }

    public function show(AtlasProject $project, string $session): JsonResponse
    {
        $row = $this->sessions->find((string) $project->getKey(), $session);
        if ($row === null) {
            return response()->json(['error' => 'observed_session_not_found', 'session' => $session], 404);
        }

        return response()->json(['session' => $row]);
    }

    public function state(Request $request, AtlasProject $project, string $session): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'in:running,waiting_result_import'],
        ]);
        try {
            $row = match ($data['state']) {
                'running' => $this->sessions->markRunning((string) $project->getKey(), $session),
                'waiting_result_import' => $this->sessions->markWaitingImport((string) $project->getKey(), $session),
            };
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $row]);
    }

    public function import(Request $request, AtlasProject $project, string $session): JsonResponse
    {
        $data = $request->validate([
            'report_text' => ['required', 'string', 'max:32000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string', 'max:320'],
            'diff_excerpt' => ['nullable', 'string', 'max:32000'],
        ]);
        try {
            $row = $this->sessions->importResult((string) $project->getKey(), $session, $data);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $row]);
    }

    public function decide(Request $request, AtlasProject $project, string $session): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:accept,reject,request_repair,block'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $row = $this->sessions->decide((string) $project->getKey(), $session, $data['action'], $data['reason'] ?? null);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $row]);
    }

    /**
     * Run advisory gates on an imported observed session.
     *
     * Honest implementation: Atlas does NOT execute the packet's
     * verification_commands itself. It runs the gates it can actually
     * check (scope guard, presence of acceptance_criteria, packet/report/
     * diff state) and reports `commands_available_but_not_run` for the
     * commands the operator must run manually.
     */
    public function runGates(AtlasProject $project, string $session): JsonResponse
    {
        try {
            $row = $this->sessions->runGates((string) $project->getKey(), $session);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $row]);
    }

    /** Semantic alias for `POST /state {state:"running"}`. */
    public function markRunning(AtlasProject $project, string $session): JsonResponse
    {
        try {
            $row = $this->sessions->markRunning((string) $project->getKey(), $session);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $row]);
    }

    /**
     * One-shot "Abrir Claude Code observado" — create packet + open session
     * in one request. Useful when the operator wants to start immediately
     * from the Operating Room CTA without authoring a packet first.
     *
     * POST /atlas-code/works/{project}/observed-sessions/claude-code
     */
    /**
     * Run a single verification command (dry_run by default).
     *
     * Body:
     *   - command: string (must match allowlist)
     *   - mode: 'dry_run' | 'execute' (default dry_run)
     *   - operator_override_token: required for execute
     *
     * Honest behavior: NEVER executes the command unless mode=execute AND
     * config flag enabled AND token validated. Otherwise returns dry_run
     * with allowlist match information.
     */
    public function verificationRun(Request $request, AtlasProject $project, string $session): JsonResponse
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:240'],
            'mode' => ['nullable', 'string', 'in:dry_run,execute'],
            'operator_override_token' => ['nullable', 'string', 'max:240'],
        ]);
        $row = $this->sessions->find((string) $project->getKey(), $session);
        if ($row === null) {
            return response()->json(['error' => 'observed_session_not_found', 'session' => $session], 404);
        }
        $workspacePath = (string) ($row['workspace_path'] ?? '');
        $result = $this->verifier->run(
            (string) $project->getKey(),
            $session,
            (string) $data['command'],
            $workspacePath,
            (string) ($data['mode'] ?? 'dry_run'),
            isset($data['operator_override_token']) ? (string) $data['operator_override_token'] : null
        );

        return response()->json(['verification_run' => $result], 200);
    }

    public function verificationRunIndex(AtlasProject $project, string $session): JsonResponse
    {
        $runs = $this->verifier->listForSession((string) $project->getKey(), $session);

        return response()->json([
            'schema_version' => VerificationCommandRunner::SCHEMA_VERSION,
            'data' => $runs,
        ]);
    }

    public function claudeCodeOneShot(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'objective' => ['required', 'string', 'max:480'],
            'context_summary' => ['nullable', 'string', 'max:4000'],
            'allowed_files' => ['nullable', 'array'],
            'allowed_files.*' => ['string', 'max:320'],
            'forbidden_files' => ['nullable', 'array'],
            'forbidden_files.*' => ['string', 'max:320'],
            'acceptance_criteria' => ['required', 'array', 'min:1'],
            'acceptance_criteria.*' => ['string', 'max:480'],
            'verification_commands' => ['nullable', 'array'],
            'verification_commands.*' => ['string', 'max:240'],
            'role_slot' => ['nullable', 'string', 'max:80'],
            'risk_band' => ['nullable', 'string', 'max:40'],
            'provider_id' => ['nullable', 'string', 'max:80'],
        ]);
        $providerId = $data['provider_id'] ?? 'claude_code';
        unset($data['provider_id']);
        try {
            $result = $this->sessions->quickOpenClaudeCodeObserved($project, $data, $providerId);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }
}
