<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\Route;

final class AtlasDevDesktopCertificationService
{
    public const SCHEMA_VERSION = 'atlas.dev.desktop_certification.v1';

    public function __construct(
        private readonly AtlasDevReadinessService $readiness,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $readiness = $this->readiness->inspect(strict: true, providerSafe: true);
        $stages = [
            $this->stageFromReadiness($readiness),
            $this->httpSurfaceStage(),
            $this->runtimeStage(),
            $this->compactSddIntegrityStage(),
            $this->realProviderAcceptanceStage(),
            $this->desktopClientStage(),
            $this->sseStage(),
        ];

        $blockers = [];
        foreach ($stages as $stage) {
            if (($stage['status'] ?? null) !== 'passed') {
                $blockers[] = (string) ($stage['name'] ?? 'unknown_stage');
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'external_provider_call' => false,
            'certifies' => [
                'desktop_entrypoint_ready',
                'plan_first_no_provider_before_confirmation',
                'operator_confirmed_run_surface',
                'process_worker_runtime',
                'snapshot_stream_rest_fallback',
                'run_history_resume',
                'operator_cancellation',
                'readiness_gated_run_button',
                'readiness_visual_smoke_covered',
                'expired_confirmation_token_client_blocked',
                'live_confirmation_token_expiry_guard',
                'provider_runtime_launchable_without_model_call',
                'real_smoke_command_available',
                'desktop_acceptance_evidence_gate_available',
                'desktop_contract_test_harness_available',
                'desktop_certification_script_available',
                'compact_sdd_hash_pin_enforced',
                'real_http_pipeline_smoke_available',
                'real_provider_smoke_passed',
            ],
            'stages' => $stages,
            'stage_summary' => [
                'total' => count($stages),
                'passed' => collect($stages)->where('status', 'passed')->count(),
                'blocked' => count($blockers),
            ],
            'remaining_blockers' => $blockers,
            'commands' => [
                'self' => 'php artisan atlas:dev:desktop:certify --json --strict',
                'backend_suite' => 'php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev',
                'desktop_certify' => 'npm run atlas-dev:certify',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'real_provider_smoke' => 'php artisan atlas:dev:desktop:real-smoke --yes --json',
                'acceptance_evidence' => 'php artisan atlas:dev:desktop:acceptance --json --strict',
            ],
            'note' => 'Local certification only: real_provider_smoke is explicit-operator and may call the configured provider; acceptance_evidence audits persisted real-smoke evidence without provider calls.',
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    private function stageFromReadiness(array $readiness): array
    {
        return [
            'name' => 'runtime_readiness',
            'status' => ($readiness['status'] ?? null) === 'passed' ? 'passed' : 'blocked',
            'readiness_status' => $readiness['status'] ?? 'unknown',
            'summary' => $readiness['summary'] ?? [],
            'provider_runtime' => collect((array) ($readiness['checks'] ?? []))
                ->firstWhere('id', 'provider.runtime'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function httpSurfaceStage(): array
    {
        $required = [
            'atlas-dev.readiness',
            'atlas-dev.plan',
            'atlas-dev.run',
            'atlas-dev.runs.index',
            'atlas-dev.runs.cancel',
            'atlas-dev.runs.show',
            'atlas-dev.runs.stream',
        ];
        $missing = array_values(array_filter($required, static fn (string $route): bool => ! Route::has($route)));

        return [
            'name' => 'http_surface_contract',
            'status' => $missing === [] ? 'passed' : 'blocked',
            'required_routes' => $required,
            'missing_routes' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeStage(): array
    {
        $requiredClasses = [
            'App\\Http\\Controllers\\AtlasDev\\Support\\PipelineRunExecutor',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Runtime\\ProcOpenRunWorkerDispatcher',
            'App\\Console\\Commands\\AtlasDevRunWorkerCommand',
            'App\\Console\\Commands\\AtlasDevDesktopRealSmokeCommand',
            'App\\Console\\Commands\\AtlasDevDesktopAcceptanceCommand',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Runtime\\AtlasDevDesktopAcceptanceEvidenceService',
            'App\\Http\\Controllers\\AtlasDev\\CancelController',
            'App\\Http\\Controllers\\AtlasDev\\IndexController',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Security\\ConfirmationTokenService',
            'App\\Services\\Ai\\Programming\\AtlasDev\\RunIndex\\AtlasDevRunIndexRepository',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Surface\\HttpResponseRedactor',
        ];
        $missing = array_values(array_filter($requiredClasses, static fn (string $class): bool => ! class_exists($class)));

        return [
            'name' => 'backend_runtime_components',
            'status' => $missing === [] ? 'passed' : 'blocked',
            'required_classes' => $requiredClasses,
            'missing_classes' => $missing,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function compactSddIntegrityStage(): array
    {
        $checks = [
            'app/Services/Ai/Programming/AtlasDev/Security/ConfirmationTokenService.php' => [
                'compact_sdd_hash',
                '?string $compactSddHash = null',
                'expectedCompactSddHash: $expectedCompactSddHash',
            ],
            'app/Services/Ai/Programming/AtlasDev/Security/ConfirmationTokenResult.php' => [
                'expectedCompactSddHash',
            ],
            'app/Http/Controllers/AtlasDev/PlanController.php' => [
                'compactSddHash: $plan->compactSdd->compactSddHash',
            ],
            'app/Http/Controllers/AtlasDev/RunController.php' => [
                'expectedCompactSddHash: $tokenResult->expectedCompactSddHash',
            ],
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php' => [
                'assertCompactSddHashPinned',
                'compact_sdd_hash does not match the server-side pin issued at plan time',
                'compact_sdd_hash does not match the hash pinned by mini_programming_spec',
            ],
            'tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php' => [
                'test_real_executor_composes_receipt_with_task_kind_and_risk_level_from_compact_sdd',
                'test_hash_tampered_compact_sdd_returns_422_and_provider_is_never_called',
                'test_rehashed_compact_sdd_still_fails_when_server_side_pin_disagrees',
                'test_rehashed_compact_sdd_still_fails_when_mini_spec_pin_disagrees',
                'test_missing_compact_sdd_returns_422_and_provider_is_never_called',
            ],
        ];

        $missingFiles = [];
        $missingTokens = [];
        foreach ($checks as $relative => $tokens) {
            $path = base_path($relative);
            if (! is_file($path)) {
                $missingFiles[] = $relative;

                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach ($tokens as $token) {
                if (! str_contains($contents, $token)) {
                    $missingTokens[] = $relative.'::'.$token;
                }
            }
        }

        return [
            'name' => 'compact_sdd_integrity_contract',
            'status' => $missingFiles === [] && $missingTokens === [] ? 'passed' : 'blocked',
            'checked_files' => array_keys($checks),
            'missing_files' => $missingFiles,
            'missing_tokens' => $missingTokens,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function realProviderAcceptanceStage(): array
    {
        $path = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'desktop_acceptance'.DIRECTORY_SEPARATOR.'latest.json';

        if (! is_file($path)) {
            return [
                'name' => 'real_provider_acceptance_evidence',
                'status' => 'blocked',
                'latest_ref' => 'desktop_acceptance/latest.json',
                'reason' => 'missing_latest_acceptance_evidence',
                'required_command' => 'php artisan atlas:dev:desktop:real-smoke --yes --json',
            ];
        }

        $payload = JsonFileStore::readArray($path);
        if ($payload === null) {
            return [
                'name' => 'real_provider_acceptance_evidence',
                'status' => 'blocked',
                'latest_ref' => 'desktop_acceptance/latest.json',
                'reason' => 'invalid_latest_acceptance_json',
                'required_command' => 'php artisan atlas:dev:desktop:real-smoke --yes --json',
            ];
        }

        $honestyFlags = array_values((array) ($payload['honesty_flags'] ?? []));
        $checks = [
            'schema_version' => ($payload['schema_version'] ?? null) === 'atlas.dev.desktop_acceptance_evidence.v1',
            'status_passed' => ($payload['status'] ?? null) === 'passed',
            'external_provider_call' => ($payload['external_provider_call'] ?? null) === true,
            'provider_recorded' => is_string($payload['provider'] ?? null) && $payload['provider'] !== '',
            'model_family_recorded' => is_string($payload['model_family'] ?? null) && $payload['model_family'] !== '',
            'completion_passed' => ($payload['completion_state'] ?? null) === 'passed',
            'scope_guard_passed' => ($payload['scope_guard_status'] ?? null) === 'passed',
            'verification_passed' => ($payload['verification_status'] ?? null) === 'passed',
            'patch_applied' => ($payload['patch_apply_status'] ?? null) === 'applied',
            'receipt_hash_recorded' => is_string($payload['receipt_hash'] ?? null) && $payload['receipt_hash'] !== '',
            'changed_file_recorded' => in_array('src/SmokeSubject.php', (array) ($payload['changed_files'] ?? []), true),
            'tests_ran' => (int) ($payload['tests_count'] ?? 0) >= 1,
            'honesty_flags_empty' => $honestyFlags === [],
            'workspace_assertion_passed' => ($payload['workspace_assertion_passed'] ?? null) === true,
            'token_consumed' => data_get($payload, 'operator_confirmation.token_consumed') === true,
            'compact_sdd_hash_pinned' => data_get($payload, 'operator_confirmation.compact_sdd_hash_pinned') === true,
        ];

        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

        return [
            'name' => 'real_provider_acceptance_evidence',
            'status' => $failed === [] ? 'passed' : 'blocked',
            'latest_ref' => 'desktop_acceptance/latest.json',
            'run_id' => is_string($payload['run_id'] ?? null) ? $payload['run_id'] : null,
            'recorded_at' => is_string($payload['recorded_at'] ?? null) ? $payload['recorded_at'] : null,
            'provider' => is_string($payload['provider'] ?? null) ? $payload['provider'] : null,
            'model_family' => is_string($payload['model_family'] ?? null) ? $payload['model_family'] : null,
            'completion_state' => $payload['completion_state'] ?? null,
            'tests_count' => (int) ($payload['tests_count'] ?? 0),
            'failed_checks' => $failed,
            'required_command' => 'php artisan atlas:dev:desktop:real-smoke --yes --json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function desktopClientStage(): array
    {
        $desktopRoot = realpath(base_path('../atlas-desktop'));
        if (! is_string($desktopRoot)) {
            return [
                'name' => 'desktop_client_contract',
                'status' => 'blocked',
                'missing_files' => ['atlas-desktop workspace'],
            ];
        }

        $checks = [
            'package.json' => [
                '"atlas-dev:test": "npm run atlas-dev:test --workspace=@atlas/desktop"',
                '"atlas-dev:certify": "npm run atlas-dev:test && npm run visual-smoke --workspace=@atlas/desktop && npm run build --workspace=@atlas/desktop"',
            ],
            'apps/desktop/package.json' => [
                '"atlas-dev:test"',
                '"atlas-dev:certify"',
                'npx tsx',
                'npm run visual-smoke',
                'npm run build',
                'apiShapes.test.ts',
                'planResponseNormaliser.test.ts',
                'runApiContract.test.ts',
                'sseParser.test.ts',
                'useAtlasDevRunRaceGuard.test.ts',
                'readinessGateSource.test.ts',
            ],
            'apps/desktop/src/components/atlasDev/api.ts' => [
                'fetchAtlasDevReadiness',
                'cancelAtlasDevRun',
                'fetchAtlasDevRunIndex',
            ],
            'apps/desktop/src/components/atlasDev/useAtlasDevRun.ts' => [
                'cancelAtlasDevRun',
                'loadStatus',
            ],
            'apps/desktop/src/components/atlasDev/AtlasDevRunWorkbench.tsx' => [
                'AtlasDevRunHistoryPanel',
                'ReadinessGate',
                'disabled={disabled || !readinessReady}',
            ],
            'apps/desktop/src/components/atlasDev/ReadinessGate.tsx' => [
                'fetchAtlasDevReadiness({ strict: true })',
                "readiness?.status === 'passed'",
                'readiness.provider_safe === true',
            ],
            'apps/desktop/src/components/atlasDev/RunPanel.tsx' => [
                'setInterval(() => setNowMs(Date.now()), 1000)',
                'isExpired(plan?.confirmation_expires_at ?? null, nowMs)',
                'Token de confirmação expirado',
            ],
            'apps/desktop/src/components/atlasDev/AtlasDevRunHistoryPanel.tsx' => [
                'fetchAtlasDevRunIndex',
            ],
            'apps/desktop/src/surfaces/atlas-ai/components/AtlasAiPlanPanel.tsx' => [
                'fetchAtlasDevReadiness',
                'AtlasDevReadinessPanel',
            ],
            'apps/desktop/scripts/visualRender.mjs' => [
                'ReadinessGate · passed runtime unlocks operator run path',
                'ReadinessGate · blocked runtime exposes blockers before Run',
                'readinessgate-passed.html',
                'readinessgate-blocked.html',
                'RunPanel · expired confirmation token disables Run before backend reject',
                'runpanel-expired-client-block.html',
            ],
        ];

        $missingFiles = [];
        $missingTokens = [];
        foreach ($checks as $relative => $tokens) {
            $path = $desktopRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($path)) {
                $missingFiles[] = $relative;

                continue;
            }

            $contents = (string) file_get_contents($path);
            foreach ($tokens as $token) {
                if (! str_contains($contents, $token)) {
                    $missingTokens[] = $relative.'::'.$token;
                }
            }
        }

        return [
            'name' => 'desktop_client_contract',
            'status' => $missingFiles === [] && $missingTokens === [] ? 'passed' : 'blocked',
            'checked_files' => array_keys($checks),
            'missing_files' => $missingFiles,
            'missing_tokens' => $missingTokens,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sseStage(): array
    {
        $path = app_path('Http/Controllers/AtlasDev/StreamController.php');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $code = $this->sourceWithoutComments($contents);
        $hasSnapshotMode = str_contains($contents, 'snapshot-replay-then-close');
        $hasSleep = preg_match('/\bsleep\s*\(/', $code) === 1;
        $hasDeadlineLoop = preg_match('/while\s*\([^)]*deadline/i', $code) === 1;

        return [
            'name' => 'stream_runtime_contract',
            'status' => $hasSnapshotMode && ! $hasSleep && ! $hasDeadlineLoop ? 'passed' : 'blocked',
            'mode' => $hasSnapshotMode ? 'snapshot-replay-then-close' : 'unknown',
            'sleep_call_present' => $hasSleep,
            'deadline_loop_present' => $hasDeadlineLoop,
        ];
    }

    private function sourceWithoutComments(string $source): string
    {
        $tokens = token_get_all($source);
        $parts = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $parts[] = is_array($token) ? $token[1] : $token;
        }

        return implode('', $parts);
    }
}
