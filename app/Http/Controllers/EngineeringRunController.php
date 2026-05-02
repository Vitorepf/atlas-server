<?php

namespace App\Http\Controllers;

use App\Models\AtlasEngineeringControl;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunAttempt;
use App\Models\AtlasEngineeringTestRun;
use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringHarnessabilityService;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringReviewFindingService;
use App\Services\Engineering\EngineeringRunOperatorActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EngineeringRunController extends Controller
{
    public function indexForTask(AtlasTask $task, EngineeringHarnessRunnerService $runner): JsonResponse
    {
        $runs = AtlasEngineeringRun::query()
            ->where('task_id', $task->id)
            ->with(['attempts', 'patchArtifacts', 'controlResults', 'testRuns', 'reviewFindings'])
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(fn (AtlasEngineeringRun $run): array => $runner->runSummary($run))
            ->values()
            ->all();

        return response()->json([
            'task_id' => $task->id,
            'runs' => $runs,
        ]);
    }

    public function storeForTask(Request $request, AtlasTask $task, EngineeringHarnessRunnerService $runner): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_policy' => ['nullable', Rule::in(['fixed', 'off', 'auto', 'balanced', 'best_quality', 'best-quality', 'fastest', 'cheapest'])],
            'permission' => ['nullable', Rule::in(['auto', 'read', 'write', 'danger'])],
            'sandbox' => ['nullable', Rule::in(['workspace', 'worktree', 'docker'])],
            'docker_service' => ['nullable', 'string', 'max:120'],
            'docker_image' => ['nullable', 'string', 'max:180'],
            'docker_workdir' => ['nullable', 'string', 'max:200'],
            'docker_cache' => ['nullable', Rule::in(['auto', 'off'])],
            'docker_network' => ['nullable', Rule::in(['profile', 'none', 'bridge'])],
            'docker_healthcheck_services' => ['nullable', 'array'],
            'docker_healthcheck_services.*' => ['string', 'max:120'],
            'docker_healthcheck_timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'docker_artifact_paths' => ['nullable', 'array'],
            'docker_artifact_paths.*' => ['string', 'max:300'],
            'docker_artifact_max_files' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'docker_artifact_max_bytes' => ['nullable', 'integer', 'min:1', 'max:104857600'],
            'provider_runtime' => ['nullable', Rule::in(['host', 'docker', 'auto'])],
            'provider_docker_compose_file' => ['nullable', 'string', 'max:1000'],
            'provider_docker_service' => ['nullable', 'string', 'max:120'],
            'provider_docker_app_dir' => ['nullable', 'string', 'max:200'],
            'provider_docker_workspace_dir' => ['nullable', 'string', 'max:200'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'test_command' => ['nullable', 'string', 'max:500'],
            'visual_e2e' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_scan' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_profile' => ['nullable', Rule::in(['auto', 'fast', 'standard', 'release', 'deep'])],
            'quality_changed_only' => ['nullable', 'boolean'],
            'harness_policy' => ['nullable', Rule::in(['auto', 'off', 'strict'])],
            'control_profile' => ['nullable', 'string', 'max:120'],
            'complete' => ['nullable', 'boolean'],
            'auto_test' => ['nullable', 'boolean'],
            'critical' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'no_provider' => ['nullable', 'boolean'],
            'keep_workspace' => ['nullable', 'boolean'],
            'apply_isolated_patch' => ['nullable', 'boolean'],
        ]);

        $payload = $runner->run($task, [
            'workspace' => $data['workspace'],
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'model_policy' => $data['model_policy'] ?? 'fixed',
            'permission' => $data['permission'] ?? 'auto',
            'sandbox' => $data['sandbox'] ?? 'workspace',
            'docker_service' => $data['docker_service'] ?? null,
            'docker_image' => $data['docker_image'] ?? null,
            'docker_workdir' => $data['docker_workdir'] ?? null,
            'docker_cache' => $data['docker_cache'] ?? null,
            'docker_network' => $data['docker_network'] ?? null,
            'docker_healthcheck_services' => $data['docker_healthcheck_services'] ?? [],
            'docker_healthcheck_timeout' => $data['docker_healthcheck_timeout'] ?? null,
            'docker_artifact_paths' => $data['docker_artifact_paths'] ?? [],
            'docker_artifact_max_files' => $data['docker_artifact_max_files'] ?? null,
            'docker_artifact_max_bytes' => $data['docker_artifact_max_bytes'] ?? null,
            'provider_runtime' => $data['provider_runtime'] ?? 'host',
            'provider_docker_compose_file' => $data['provider_docker_compose_file'] ?? null,
            'provider_docker_service' => $data['provider_docker_service'] ?? null,
            'provider_docker_app_dir' => $data['provider_docker_app_dir'] ?? null,
            'provider_docker_workspace_dir' => $data['provider_docker_workspace_dir'] ?? null,
            'max_attempts' => $data['max_attempts'] ?? 1,
            'test_command' => $data['test_command'] ?? null,
            'visual_e2e' => $data['visual_e2e'] ?? 'auto',
            'quality_scan' => $data['quality_scan'] ?? 'off',
            'quality_profile' => $data['quality_profile'] ?? 'auto',
            'quality_changed_only' => $data['quality_changed_only'] ?? false,
            'harness_policy' => $data['harness_policy'] ?? 'auto',
            'control_profile' => $data['control_profile'] ?? null,
            'complete' => $data['complete'] ?? false,
            'auto_test' => $data['auto_test'] ?? false,
            'critical' => $data['critical'] ?? false,
            'dry_run' => $data['dry_run'] ?? false,
            'no_provider' => $data['no_provider'] ?? false,
            'keep_workspace' => $data['keep_workspace'] ?? false,
            'apply_isolated_patch' => $data['apply_isolated_patch'] ?? true,
        ]);

        return response()->json($payload, 201);
    }

    public function show(AtlasEngineeringRun $run, EngineeringHarnessRunnerService $runner): JsonResponse
    {
        return response()->json([
            'run' => $runner->runSummary($run),
        ]);
    }

    public function replay(Request $request, AtlasEngineeringRun $run, EngineeringHarnessRunnerService $runner): JsonResponse
    {
        $data = $this->validateReplayRequest($request);

        return response()->json($runner->replay($run, $data), 201);
    }

    public function replayAttempt(
        Request $request,
        AtlasEngineeringRun $run,
        AtlasEngineeringRunAttempt $attempt,
        EngineeringHarnessRunnerService $runner,
    ): JsonResponse {
        abort_unless($attempt->engineering_run_id === $run->id, 404);

        $data = $this->validateReplayRequest($request);

        return response()->json($runner->replayAttempt($attempt, $data), 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function validateReplayRequest(Request $request): array
    {
        return $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_policy' => ['nullable', Rule::in(['fixed', 'off', 'auto', 'balanced', 'best_quality', 'best-quality', 'fastest', 'cheapest'])],
            'provider_replay' => ['nullable', 'boolean'],
            'same_sandbox' => ['nullable', 'boolean'],
            'permission' => ['nullable', Rule::in(['auto', 'read', 'write', 'danger'])],
            'sandbox' => ['nullable', Rule::in(['workspace', 'worktree', 'docker'])],
            'docker_service' => ['nullable', 'string', 'max:120'],
            'docker_image' => ['nullable', 'string', 'max:180'],
            'docker_workdir' => ['nullable', 'string', 'max:200'],
            'docker_cache' => ['nullable', Rule::in(['auto', 'off'])],
            'docker_network' => ['nullable', Rule::in(['profile', 'none', 'bridge'])],
            'docker_healthcheck_services' => ['nullable', 'array'],
            'docker_healthcheck_services.*' => ['string', 'max:120'],
            'docker_healthcheck_timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'docker_artifact_paths' => ['nullable', 'array'],
            'docker_artifact_paths.*' => ['string', 'max:300'],
            'docker_artifact_max_files' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'docker_artifact_max_bytes' => ['nullable', 'integer', 'min:1', 'max:104857600'],
            'provider_runtime' => ['nullable', Rule::in(['host', 'docker', 'auto'])],
            'provider_docker_compose_file' => ['nullable', 'string', 'max:1000'],
            'provider_docker_service' => ['nullable', 'string', 'max:120'],
            'provider_docker_app_dir' => ['nullable', 'string', 'max:200'],
            'provider_docker_workspace_dir' => ['nullable', 'string', 'max:200'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'test_command' => ['nullable', 'string', 'max:500'],
            'visual_e2e' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_scan' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_profile' => ['nullable', Rule::in(['auto', 'fast', 'standard', 'release', 'deep'])],
            'quality_changed_only' => ['nullable', 'boolean'],
            'harness_policy' => ['nullable', Rule::in(['auto', 'off', 'strict'])],
            'control_profile' => ['nullable', 'string', 'max:120'],
            'auto_test' => ['nullable', 'boolean'],
            'critical' => ['nullable', 'boolean'],
            'keep_workspace' => ['nullable', 'boolean'],
            'apply_isolated_patch' => ['nullable', 'boolean'],
        ]);
    }

    public function cancel(
        Request $request,
        AtlasEngineeringRun $run,
        EngineeringHarnessRunnerService $runner,
        EngineeringRunOperatorActionService $operatorActions,
    ): JsonResponse {
        $data = $request->validate([
            'actor' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payload' => ['nullable', 'array'],
        ]);

        $action = $operatorActions->apply($run, 'cancel', $data);

        return response()->json([
            'operator_action' => $this->operatorActionPayload($action),
            'run' => $runner->runSummary($run->refresh()),
        ]);
    }

    public function operatorAction(
        Request $request,
        AtlasEngineeringRun $run,
        EngineeringHarnessRunnerService $runner,
        EngineeringRunOperatorActionService $operatorActions,
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'accept', 'needs_human', 'reject'])],
            'actor' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payload' => ['nullable', 'array'],
        ]);

        $action = $operatorActions->apply($run, (string) $data['action'], $data);

        return response()->json([
            'operator_action' => $this->operatorActionPayload($action),
            'run' => $runner->runSummary($run->refresh()),
        ]);
    }

    public function showPatchDiff(
        Request $request,
        AtlasEngineeringRun $run,
        AtlasEngineeringPatchArtifact $patch,
    ): JsonResponse {
        abort_unless($patch->engineering_run_id === $run->id, 404);

        $data = $request->validate([
            'max_bytes' => ['nullable', 'integer', 'min:1024', 'max:1048576'],
        ]);
        $maxBytes = (int) ($data['max_bytes'] ?? 262144);

        $content = (string) ($patch->diff_excerpt ?? '');
        $source = 'excerpt';
        $truncated = false;
        $sizeBytes = strlen($content);
        $computedHash = $content !== '' ? hash('sha256', $content) : null;

        if (is_string($patch->diff_path) && $patch->diff_path !== '' && File::exists($patch->diff_path)) {
            $diffPath = $this->safePatchDiffPath($run, $patch->diff_path);
            abort_unless($diffPath !== null, 403);

            if (File::isFile($diffPath)) {
                $sizeBytes = File::size($diffPath);
                $read = file_get_contents($diffPath, false, null, 0, $maxBytes + 1);
                $content = is_string($read) ? $read : '';
                $truncated = strlen($content) > $maxBytes;
                if ($truncated) {
                    $content = substr($content, 0, $maxBytes);
                }

                $source = 'diff_path';
                $computedHash = hash_file('sha256', $diffPath) ?: null;
            }
        }

        return response()->json([
            'patch_artifact' => [
                'id' => $patch->id,
                'engineering_run_id' => $patch->engineering_run_id,
                'attempt_id' => $patch->attempt_id,
                'diff_hash' => $patch->diff_hash,
                'computed_hash' => $computedHash,
                'hash_matches' => $patch->diff_hash && $computedHash ? hash_equals($patch->diff_hash, $computedHash) : null,
                'changed_files' => $patch->changed_files_json,
                'created_files' => $patch->created_files_json,
                'deleted_files' => $patch->deleted_files_json,
                'risk_flags' => $patch->risk_flags_json,
            ],
            'diff' => [
                'content' => $content,
                'source' => $source,
                'size_bytes' => $sizeBytes,
                'returned_bytes' => strlen($content),
                'truncated' => $truncated,
                'max_bytes' => $maxBytes,
            ],
        ]);
    }

    public function testRunArtifacts(
        Request $request,
        AtlasEngineeringRun $run,
        AtlasEngineeringTestRun $testRun,
    ): JsonResponse {
        abort_unless($testRun->engineering_run_id === $run->id, 404);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $limit = (int) ($data['limit'] ?? 150);
        $root = $this->safeTestArtifactRoot($run, $testRun);
        abort_unless($root !== null, 404);

        $allFiles = File::allFiles($root);
        $files = [];
        $totalBytes = 0;
        foreach ($allFiles as $file) {
            if (count($files) >= $limit) {
                break;
            }

            $path = $file->getPathname();
            $bytes = $file->getSize();
            $mimeType = $this->mimeType($path);
            $relativePath = $this->relativeArtifactPath($root, $path);
            $totalBytes += $bytes;

            $files[] = [
                'path' => $relativePath,
                'bytes' => $bytes,
                'mime_type' => $mimeType,
                'kind' => $this->artifactKind($relativePath, $mimeType),
                'readable_inline' => $this->isReadableInline($relativePath, $mimeType, $bytes),
                'sha256' => $bytes <= 5_242_880 ? hash_file('sha256', $path) : null,
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
            ];
        }

        return response()->json([
            'run_id' => $run->id,
            'test_run_id' => $testRun->id,
            'artifact_root_hash' => hash('sha256', $root),
            'file_count' => count($files),
            'total_listed_bytes' => $totalBytes,
            'truncated' => count($allFiles) > $limit,
            'files' => $files,
        ]);
    }

    public function showTestRunArtifact(
        Request $request,
        AtlasEngineeringRun $run,
        AtlasEngineeringTestRun $testRun,
    ): JsonResponse {
        abort_unless($testRun->engineering_run_id === $run->id, 404);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'max_bytes' => ['nullable', 'integer', 'min:1024', 'max:1048576'],
        ]);
        $maxBytes = (int) ($data['max_bytes'] ?? 262144);
        $root = $this->safeTestArtifactRoot($run, $testRun);
        abort_unless($root !== null, 404);

        $path = $this->safeTestArtifactPath($root, (string) $data['path']);
        abort_unless($path !== null && File::isFile($path), 404);

        $sizeBytes = File::size($path);
        $mimeType = $this->mimeType($path);
        $relativePath = $this->relativeArtifactPath($root, $path);
        $kind = $this->artifactKind($relativePath, $mimeType);
        $readBytes = min($sizeBytes, $maxBytes + 1);
        $raw = file_get_contents($path, false, null, 0, $readBytes);
        $raw = is_string($raw) ? $raw : '';
        $truncated = strlen($raw) > $maxBytes;
        if ($truncated) {
            $raw = substr($raw, 0, $maxBytes);
        }

        $inlineText = $this->isTextArtifact($relativePath, $mimeType);
        $inlineImage = str_starts_with((string) $mimeType, 'image/') && $sizeBytes <= $maxBytes;

        return response()->json([
            'run_id' => $run->id,
            'test_run_id' => $testRun->id,
            'artifact' => [
                'path' => $relativePath,
                'bytes' => $sizeBytes,
                'returned_bytes' => strlen($raw),
                'mime_type' => $mimeType,
                'kind' => $kind,
                'sha256' => hash_file('sha256', $path),
                'truncated' => $truncated,
                'max_bytes' => $maxBytes,
            ],
            'content' => $inlineText ? $raw : null,
            'content_base64' => $inlineImage ? base64_encode($raw) : null,
            'data_url' => $inlineImage ? 'data:'.$mimeType.';base64,'.base64_encode($raw) : null,
        ]);
    }

    public function reviewFindings(AtlasEngineeringRun $run, EngineeringReviewFindingService $findings): JsonResponse
    {
        $run->loadMissing(['reviewFindings']);

        return response()->json([
            'run_id' => $run->id,
            'summary' => $findings->summary($run),
            'findings' => $run->reviewFindings
                ->map(fn (AtlasEngineeringReviewFinding $finding): array => $this->findingPayload($finding))
                ->values()
                ->all(),
        ]);
    }

    public function storeReviewFinding(Request $request, AtlasEngineeringRun $run, EngineeringReviewFindingService $findings): JsonResponse
    {
        $data = $request->validate([
            'attempt_id' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:80'],
            'severity' => ['required', Rule::in(['p0', 'p1', 'p2', 'p3'])],
            'status' => ['nullable', Rule::in(['open', 'resolved', 'dismissed'])],
            'title' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:12000'],
            'file_path' => ['nullable', 'string', 'max:1000'],
            'start_line' => ['nullable', 'integer', 'min:1'],
            'end_line' => ['nullable', 'integer', 'min:1'],
            'evidence' => ['nullable', 'array'],
        ]);

        $finding = $findings->record($run, $data);

        return response()->json([
            'finding' => $finding ? $this->findingPayload($finding) : null,
            'summary' => $findings->summary($run->refresh()),
        ], 201);
    }

    public function updateReviewFinding(
        Request $request,
        AtlasEngineeringReviewFinding $finding,
        EngineeringReviewFindingService $findings,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved', 'dismissed'])],
            'resolution' => ['nullable', 'array'],
        ]);

        $finding = $findings->transition($finding, $data['status'], $data['resolution'] ?? []);

        return response()->json([
            'finding' => $this->findingPayload($finding),
        ]);
    }

    public function controls(): JsonResponse
    {
        return response()->json([
            'controls' => AtlasEngineeringControl::query()
                ->orderBy('slug')
                ->get()
                ->map(fn (AtlasEngineeringControl $control): array => [
                    'id' => $control->id,
                    'slug' => $control->slug,
                    'name' => $control->name,
                    'definition_hash' => $control->definition_hash,
                    'version' => $control->version,
                    'versioned_at' => $control->versioned_at?->toJSON(),
                    'direction' => $control->direction,
                    'execution_type' => $control->execution_type,
                    'regulation_category' => $control->regulation_category,
                    'timing' => $control->timing,
                    'required' => $control->required,
                    'failure_policy' => $control->failure_policy,
                    'command' => $control->command,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function harnessability(Request $request, EngineeringHarnessabilityService $harnessability): JsonResponse
    {
        $data = $request->validate([
            'workspace' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json($harnessability->score($data['workspace']));
    }

    public function harnessabilityCalibration(EngineeringHarnessabilityService $harnessability): JsonResponse
    {
        return response()->json([
            'harnessability_calibration' => $harnessability->latestCalibration(),
        ]);
    }

    public function calibrateHarnessability(Request $request, EngineeringHarnessabilityService $harnessability): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return response()->json([
            'harnessability_calibration' => $harnessability->calibrate($data),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function findingPayload(AtlasEngineeringReviewFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'engineering_run_id' => $finding->engineering_run_id,
            'attempt_id' => $finding->attempt_id,
            'task_id' => $finding->task_id,
            'source' => $finding->source,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'title' => $finding->title,
            'body' => $finding->body,
            'file_path' => $finding->file_path,
            'start_line' => $finding->start_line,
            'end_line' => $finding->end_line,
            'evidence' => $finding->evidence_json,
            'resolution' => $finding->resolution_json,
            'detected_at' => $finding->detected_at?->toJSON(),
            'resolved_at' => $finding->resolved_at?->toJSON(),
            'created_at' => $finding->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operatorActionPayload(mixed $action): array
    {
        return [
            'id' => $action->id,
            'engineering_run_id' => $action->engineering_run_id,
            'action' => $action->action,
            'actor' => $action->actor,
            'status_before' => $action->status_before,
            'decision_before' => $action->decision_before,
            'status_after' => $action->status_after,
            'decision_after' => $action->decision_after,
            'note' => $action->note,
            'payload' => $action->payload_json,
            'acted_at' => $action->acted_at?->toJSON(),
            'created_at' => $action->created_at?->toJSON(),
        ];
    }

    private function safePatchDiffPath(AtlasEngineeringRun $run, string $path): ?string
    {
        $root = realpath(storage_path('app/engineering-runs/'.$run->id));
        $resolved = realpath($path);
        if (! $root || ! $resolved) {
            return null;
        }

        return Str::startsWith($resolved, $root.DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    private function safeTestArtifactRoot(AtlasEngineeringRun $run, AtlasEngineeringTestRun $testRun): ?string
    {
        if (! is_string($testRun->artifact_path) || $testRun->artifact_path === '') {
            return null;
        }

        $runRoot = realpath(storage_path('app/engineering-runs/'.$run->id));
        $artifactRoot = realpath($testRun->artifact_path);
        if (! $runRoot || ! $artifactRoot || ! File::isDirectory($artifactRoot)) {
            return null;
        }

        $runRoot = rtrim($runRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $artifactRoot = rtrim($artifactRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return Str::startsWith($artifactRoot, $runRoot) ? rtrim($artifactRoot, DIRECTORY_SEPARATOR) : null;
    }

    private function safeTestArtifactPath(string $root, string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_starts_with($relativePath, '/')) {
            return null;
        }

        $resolved = realpath(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relativePath);
        if (! $resolved) {
            return null;
        }

        $root = rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return Str::startsWith($resolved, $root) ? $resolved : null;
    }

    private function relativeArtifactPath(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', ltrim(Str::after($path, rtrim($root, DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR));
    }

    private function mimeType(string $path): ?string
    {
        try {
            $mime = File::mimeType($path);
        } catch (\Throwable) {
            $mime = null;
        }

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function artifactKind(string $path, ?string $mimeType): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            str_starts_with((string) $mimeType, 'image/') => 'image',
            in_array($extension, ['html', 'htm'], true) => 'html',
            $extension === 'json' => 'json',
            in_array($extension, ['xml', 'junit'], true) => 'xml',
            $this->isTextArtifact($path, $mimeType) => 'text',
            default => 'binary',
        };
    }

    private function isReadableInline(string $path, ?string $mimeType, int $bytes): bool
    {
        return $bytes <= 1_048_576 && ($this->isTextArtifact($path, $mimeType) || str_starts_with((string) $mimeType, 'image/'));
    }

    private function isTextArtifact(string $path, ?string $mimeType): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (str_starts_with((string) $mimeType, 'text/')) {
            return true;
        }

        return in_array($mimeType, ['application/json', 'application/xml', 'application/javascript', 'image/svg+xml'], true)
            || in_array($extension, ['txt', 'log', 'md', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'tsx', 'jsx', 'yml', 'yaml', 'csv'], true);
    }
}
