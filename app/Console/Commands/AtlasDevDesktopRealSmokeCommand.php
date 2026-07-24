<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevReadinessService;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenKeyMissingException;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;
use App\Support\YesNo;

final class AtlasDevDesktopRealSmokeCommand extends Command
{
    protected $signature = 'atlas:dev:desktop:real-smoke
        {--workspace= : Existing workspace to mutate; defaults to an isolated temporary smoke workspace}
        {--provider-timeout=120 : Maximum seconds allowed for the provider call}
        {--keep-workspace : Keep the temporary workspace after the smoke}
        {--yes : Confirm the external provider call}
        {--json : Emit canonical JSON}';

    protected $description = 'Run a confirmed Atlas Dev Desktop smoke with the real provider/runtime path.';

    public function handle(
        AtlasDevReadinessService $readiness,
        AtlasDevFastPathOrchestrator $orchestrator,
        RunExecutor $executor,
        ConfirmationTokenService $tokens,
        AtlasDevRunIndexRepository $runIndex,
        ReceiptStorage $storage,
    ): int {
        if (! (bool) $this->option('yes')) {
            $this->error('atlas:dev:desktop:real-smoke requires --yes because it spends a provider call and mutates a workspace.');

            return self::FAILURE;
        }

        $readinessReport = $readiness->inspect(strict: true, providerSafe: true);
        if (($readinessReport['status'] ?? null) !== 'passed') {
            return $this->finish([
                'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                'status' => 'blocked',
                'external_provider_call' => false,
                'reason' => 'readiness_blocked',
                'readiness' => $readinessReport,
            ], self::FAILURE);
        }

        [$workspace, $createdTempWorkspace] = $this->resolveWorkspace();
        config()->set('atlas_dev.provider.timeout_seconds', max(1, (int) $this->option('provider-timeout')));
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', false);

        try {
            $plan = $orchestrator->planOnly(
                surfaceId: 'atlas_desktop_ai',
                workspace: $workspace,
                rawIntent: 'Corrija o typo em src/SmokeSubject.php: o metodo greeting retorna helo atlas mas o teste tests/SmokeSubjectTest.php espera hello atlas. Mude somente src/SmokeSubject.php e rode composer test.',
                userConstraints: [
                    'allowed_files=src/SmokeSubject.php',
                    'validation_command=composer test',
                ],
                surfaceHints: [
                    'thread_id' => 'desktop-real-smoke',
                    'composer_mode' => 'dev',
                    'composer_task' => 'repair',
                ],
            );

            if (! $plan->isFastPath()) {
                return $this->finish([
                    'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                    'status' => 'blocked',
                    'external_provider_call' => false,
                    'reason' => 'plan_not_fast_path',
                    'run_id' => $plan->envelope->runId,
                    'routing' => [
                        'kind' => $plan->routingKind(),
                        'blockers' => $plan->blockers,
                    ],
                    'workspace_label' => basename($workspace),
                    'workspace_hash' => $plan->envelope->workspaceHash,
                ], self::FAILURE);
            }

            $runIndex->upsertFromPlan(
                runId: $plan->envelope->runId,
                surfaceId: $plan->envelope->surfaceId,
                workspaceHash: $plan->envelope->workspaceHash,
                routingDecision: $plan->routingKind(),
                taskKind: $plan->compactSdd->taskKind,
                riskLevel: $plan->compactSdd->riskLevel,
                threadId: $plan->envelope->surfaceContext->threadId,
            );

            try {
                $confirmation = $tokens->issue(
                    runId: $plan->envelope->runId,
                    taskContractHash: $plan->taskContract->taskContractHash,
                    surfaceId: $plan->envelope->surfaceId,
                    compactSddHash: $plan->compactSdd->compactSddHash,
                );
            } catch (ConfirmationTokenKeyMissingException $e) {
                return $this->finish([
                    'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                    'status' => 'blocked',
                    'external_provider_call' => false,
                    'reason' => 'confirmation_token_key_missing',
                    'error' => $e->getMessage(),
                    'run_id' => $plan->envelope->runId,
                    'workspace_label' => basename($workspace),
                    'workspace_hash' => $plan->envelope->workspaceHash,
                ], self::FAILURE);
            }

            $confirmationResult = $tokens->validateAndConsume(
                runId: $plan->envelope->runId,
                taskContractHash: $plan->taskContract->taskContractHash,
                plaintext: $confirmation->plaintext,
            );

            if (! $confirmationResult->ok) {
                return $this->finish([
                    'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                    'status' => 'blocked',
                    'external_provider_call' => false,
                    'reason' => 'confirmation_token_not_consumed',
                    'token_reason' => $confirmationResult->reason,
                    'run_id' => $plan->envelope->runId,
                    'workspace_label' => basename($workspace),
                    'workspace_hash' => $plan->envelope->workspaceHash,
                ], self::FAILURE);
            }

            $run = $executor->execute(
                envelope: $plan->envelope,
                taskContract: $plan->taskContract,
                promptProjection: $plan->promptProjection,
                runId: $plan->envelope->runId,
                expectedCompactSddHash: $confirmationResult->expectedCompactSddHash,
            );
            $runIndex->updateCompletion($plan->envelope->runId, $run->completionState, $run->verificationReceiptHash);

            $subjectPath = $workspace.'/src/SmokeSubject.php';
            $subjectContents = is_file($subjectPath) ? (string) file_get_contents($subjectPath) : '';
            $receipt = $storage->read($plan->envelope->runId, ArtifactNames::VERIFICATION_RECEIPT);
            $patchApply = $storage->read($plan->envelope->runId, ArtifactNames::PATCH_APPLY_RESULT);
            $workspaceAssertionPassed = str_contains($subjectContents, "return 'hello atlas';");
            $passed = $run->completionState === 'passed'
                && $run->scopeGuardStatus === 'passed'
                && $run->verificationStatus === 'passed'
                && $workspaceAssertionPassed
                && is_array($receipt);

            return $this->finish([
                'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                'status' => $passed ? 'passed' : 'failed',
                'external_provider_call' => true,
                'surface_id' => 'atlas_desktop_ai',
                'run_id' => $plan->envelope->runId,
                'workspace_label' => basename($workspace),
                'workspace_hash' => $plan->envelope->workspaceHash,
                'routing' => [
                    'kind' => $plan->routingKind(),
                    'risk_level' => $plan->riskLevel,
                    'task_kind' => $plan->classification->taskKind,
                ],
                'operator_confirmation' => [
                    'token_issued' => true,
                    'token_consumed' => true,
                    'task_contract_hash' => $plan->taskContract->taskContractHash,
                    'compact_sdd_hash_pinned' => $confirmationResult->expectedCompactSddHash === $plan->compactSdd->compactSddHash,
                ],
                'run' => [
                    'completion_state' => $run->completionState,
                    'scope_guard_status' => $run->scopeGuardStatus,
                    'verification_status' => $run->verificationStatus,
                    'provider' => $run->providerCallSummary['provider'] ?? null,
                    'model_family' => $run->providerCallSummary['model_family'] ?? null,
                    'provider_calls' => $run->providerCallSummary['provider_calls'] ?? null,
                    'exit_code' => $run->providerCallSummary['exit_code'] ?? null,
                    'duration_ms' => $run->providerCallSummary['duration_ms'] ?? null,
                    'verification_receipt_hash' => $run->verificationReceiptHash,
                    'scope_guard_receipt_hash' => $run->scopeGuardReceiptHash,
                    'diff_hash' => $run->diffHash,
                    'patch_apply_status' => is_array($patchApply) ? ($patchApply['status'] ?? null) : null,
                ],
                'receipt' => [
                    'present' => is_array($receipt),
                    'changed_files' => is_array($receipt) ? array_values((array) ($receipt['changed_files'] ?? [])) : [],
                    'tests_count' => is_array($receipt) ? count((array) ($receipt['tests'] ?? [])) : 0,
                    'honesty_flags' => is_array($receipt) ? array_values((array) data_get($receipt, 'completion.honesty_flags', [])) : [],
                ],
                'workspace_assertion' => [
                    'file' => 'src/SmokeSubject.php',
                    'expected' => "return 'hello atlas';",
                    'passed' => $workspaceAssertionPassed,
                ],
            ], $passed ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $e) {
            return $this->finish([
                'schema_version' => 'atlas.dev.desktop_real_smoke.v1',
                'status' => 'failed',
                'external_provider_call' => true,
                'reason' => 'exception',
                'error' => $e->getMessage(),
                'workspace_label' => basename($workspace),
            ], self::FAILURE);
        } finally {
            if ($createdTempWorkspace && ! (bool) $this->option('keep-workspace')) {
                File::deleteDirectory($workspace);
            }
        }
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function resolveWorkspace(): array
    {
        $workspace = (string) ($this->option('workspace') ?: '');
        if ($workspace !== '') {
            return [realpath($workspace) ?: $workspace, false];
        }

        $workspace = sys_get_temp_dir().'/atlas-dev-desktop-real-smoke-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src');
        File::ensureDirectoryExists($workspace.'/tests');
        File::ensureDirectoryExists($workspace.'/.git/refs/heads');
        file_put_contents($workspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($workspace.'/.git/refs/heads/main', '0123456789abcdef0123456789abcdef01234567');
        file_put_contents($workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php tests/SmokeSubjectTest.php',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
        file_put_contents($workspace.'/src/SmokeSubject.php', <<<'PHP'
<?php
namespace Smoke;

final class SmokeSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);
        file_put_contents($workspace.'/tests/SmokeSubjectTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/SmokeSubject.php';

$subject = new \Smoke\SmokeSubject();
if ($subject->greeting() !== 'hello atlas') {
    fwrite(STDERR, 'Expected hello atlas, got '.$subject->greeting().PHP_EOL);
    exit(1);
}

echo "ok\n";
PHP);

        return [$workspace, true];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $payload = $this->persistAcceptanceEvidence($payload);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev Desktop real smoke', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('External provider call', YesNo::format($payload['external_provider_call'] ?? false));
            if (isset($payload['run_id'])) {
                $this->components->twoColumnDetail('Run ID', (string) $payload['run_id']);
            }
            if (isset($payload['run']['completion_state'])) {
                $this->components->twoColumnDetail('Completion', (string) $payload['run']['completion_state']);
            }
        }

        return $exitCode;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function persistAcceptanceEvidence(array $payload): array
    {
        $status = (string) ($payload['status'] ?? 'unknown');
        if (! in_array($status, ['passed', 'failed', 'blocked'], true)) {
            $status = 'unknown';
        }

        $runId = isset($payload['run_id']) && is_string($payload['run_id']) && $payload['run_id'] !== ''
            ? $payload['run_id']
            : 'no-run-'.date('YmdHis');

        $base = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'desktop_acceptance';
        File::ensureDirectoryExists($base);

        $report = [
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'recorded_at' => now()->toISOString(),
            'source_command' => 'atlas:dev:desktop:real-smoke',
            'status' => $status,
            'run_id' => $payload['run_id'] ?? null,
            'external_provider_call' => (bool) ($payload['external_provider_call'] ?? false),
            'provider' => data_get($payload, 'run.provider'),
            'model_family' => data_get($payload, 'run.model_family'),
            'completion_state' => data_get($payload, 'run.completion_state'),
            'scope_guard_status' => data_get($payload, 'run.scope_guard_status'),
            'verification_status' => data_get($payload, 'run.verification_status'),
            'patch_apply_status' => data_get($payload, 'run.patch_apply_status'),
            'receipt_hash' => data_get($payload, 'run.verification_receipt_hash'),
            'changed_files' => array_values((array) data_get($payload, 'receipt.changed_files', [])),
            'tests_count' => (int) data_get($payload, 'receipt.tests_count', 0),
            'honesty_flags' => array_values((array) data_get($payload, 'receipt.honesty_flags', [])),
            'workspace_assertion_passed' => (bool) data_get($payload, 'workspace_assertion.passed', false),
            'operator_confirmation' => (array) ($payload['operator_confirmation'] ?? []),
            'reason' => $payload['reason'] ?? null,
        ];

        $filename = $this->safeFileName($runId).'.json';
        $path = $base.DIRECTORY_SEPARATOR.$filename;
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
        File::put($base.DIRECTORY_SEPARATOR.'latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $payload['acceptance_evidence'] = [
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'ref' => 'desktop_acceptance/'.$filename,
            'latest_ref' => 'desktop_acceptance/latest.json',
            'status' => $status,
        ];

        return $payload;
    }

    private function safeFileName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $value);

        return is_string($safe) && $safe !== '' ? $safe : 'unknown';
    }

}
