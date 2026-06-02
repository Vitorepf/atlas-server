<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\HermesCapabilityCandidate;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Hermes\HermesCapabilityInvocationBuilder;
use App\Services\Ai\Hermes\HermesCapabilityRegistry;
use App\Services\Ai\Hermes\HermesDelegationAdapter;
use App\Services\Ai\Hermes\HermesExecutiveMissionFactory;
use App\Services\Ai\Hermes\HermesGatewayAdapter;
use App\Services\Ai\Hermes\HermesMcpAdapter;
use App\Services\Ai\Hermes\HermesMemoryAdapter;
use App\Services\Ai\Hermes\HermesProcedureAdapter;
use App\Services\Ai\Hermes\HermesResultPacketFactory;
use App\Services\Ai\Hermes\HermesScheduleAdapter;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HermesCliProvider implements AiProvider
{
    use RunsCliProcesses;

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
        private readonly HermesExecutiveMissionFactory $missions,
        private readonly HermesMemoryAdapter $memoryAdapter,
        private readonly HermesScheduleAdapter $scheduleAdapter,
        private readonly HermesProcedureAdapter $procedureAdapter,
        private readonly HermesGatewayAdapter $gatewayAdapter,
        private readonly HermesCapabilityRegistry $capabilityRegistry,
        private readonly HermesCapabilityInvocationBuilder $capabilityBuilder,
        private readonly HermesMcpAdapter $mcpAdapter,
        private readonly HermesDelegationAdapter $delegationAdapter,
        private readonly HermesResultPacketFactory $resultPackets,
    ) {}

    public function key(): string
    {
        return 'hermes_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->runStreaming($job, $prompt);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $provider = $this->runtimeSettings->providerConfig($this->key());
        $memoryPolicy = $this->memoryPolicy($job, $provider);
        $schedulePolicy = $this->schedulePolicy($job, $provider);
        $procedurePolicy = $this->procedurePolicy($job, $provider);
        $gatewayPolicy = $this->gatewayPolicy($job, $provider);
        $gatewayAllowed = (bool) data_get($job->payload, 'hermes.gateway_allowed', false);
        $binary = (string) ($provider['binary'] ?? 'hermes');
        $args = $this->ensureChatCommand($this->sanitizeConfiguredArgs((array) ($provider['args'] ?? ['chat', '--quiet'])));
        $args = $this->withHermesRuntimeArgs($args, $job, $provider);
        $args = $this->withImageAttachments($args, $job);

        $fileAttachments = $this->fileAttachmentAccessPaths($job);
        if ($fileAttachments !== []) {
            $prompt = $this->promptWithFileAttachmentAccess($prompt, $fileAttachments);
        }

        $mission = $this->missions->build($job, $prompt, $provider, [
            'configured_binary' => $binary,
            'configured_args_hash' => hash('sha256', json_encode($args, JSON_THROW_ON_ERROR)),
        ]);

        $capabilityPolicy = $this->capabilityPolicy($job, $provider);
        $capabilityManifest = ((bool) data_get($capabilityPolicy, 'enabled', false))
            ? ($this->capabilityRegistry->latestManifest() ?? [])
            : [];
        $capability = $this->capabilityBuilder->apply(
            $args,
            data_get($mission, 'capabilities', []),
            $capabilityManifest,
            $capabilityPolicy,
            $this->permissionModeForJob($job),
        );
        $args = is_array($capability['args'] ?? null) ? $capability['args'] : $args;
        $capabilityReceipt = is_array($capability['receipt'] ?? null) ? $capability['receipt'] : [];
        $capabilityContextRefs = is_array($capability['prompt_context_refs'] ?? null) ? $capability['prompt_context_refs'] : [];
        if ($capabilityContextRefs !== []) {
            $prompt = rtrim($prompt)."\n\n--- Atlas Capability Context (governed) ---\n".implode("\n", $capabilityContextRefs);
        }

        $mcpPolicy = $this->mcpPolicy($job, $provider);
        $delegationPolicy = $this->delegationPolicy($job, $provider);
        $capabilityContext = [
            'executive_mission_id' => $mission['mission_id'] ?? null,
            'executive_mission_hash' => $mission['mission_hash'] ?? null,
            'configured_binary' => $binary,
        ];
        $mcp = $this->mcpAdapter->resolve($job, $mission, $capabilityContext, $mcpPolicy, $capabilityManifest);
        $mcpReceipt = is_array($mcp['receipt'] ?? null) ? $mcp['receipt'] : [];
        $managedConfigPath = is_string($mcp['managed_config_path'] ?? null) ? $mcp['managed_config_path'] : null;
        $delegationReceipt = $this->delegationAdapter->authorize($job, $mission, $capabilityContext, $delegationPolicy, $this->permissionModeForJob($job), $capabilityManifest);
        if ((bool) data_get($delegationReceipt, 'delegation_enabled', false)) {
            $args = $this->mergeToolset($args, 'delegation');
        }

        $prompt = $this->promptWithExecutiveMission($prompt, $mission);

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        $source = $this->cleanString(data_get($job->payload, 'hermes.source') ?: ($provider['source'] ?? 'tool')) ?: 'tool';
        $args = $this->withArgValue($args, '--source', $source);

        $maxTurns = $this->positiveInt(data_get($job->payload, 'hermes.max_turns') ?: ($provider['max_turns'] ?? null));
        if ($maxTurns !== null) {
            $args = $this->withArgValue($args, '--max-turns', (string) $maxTurns);
        }

        $args[] = '--query';
        $args[] = $prompt;

        $command = array_values(array_merge([$binary], $args));
        $cwd = $this->workdirForJob($job);
        $timeout = $job->timeout_seconds > 0
            ? $job->timeout_seconds
            : (int) ($provider['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600));

        $invocation = $this->cliInvocationFingerprint($command, $prompt, $timeout, $cwd, $job, [
            'runtime_role' => 'executive_runtime',
            'runtime_contract' => 'atlas.hermes_cli_provider.v1',
            'source' => $source,
            'permission_mode' => $this->permissionModeForJob($job),
            'memory_policy' => $memoryPolicy,
            'schedule_policy' => $schedulePolicy,
            'procedure_policy' => $procedurePolicy,
            'gateway_policy' => $gatewayPolicy,
            'gateway_allowed' => $gatewayAllowed,
            'capability_policy_enabled' => (bool) data_get($capabilityPolicy, 'enabled', false),
            'capabilities_receipt_hash' => data_get($capabilityReceipt, 'receipt_hash'),
            'executive_mission_hash' => $mission['mission_hash'] ?? null,
            'executive_mission_id' => $mission['mission_id'] ?? null,
        ]);

        $result = $this->runProcessStreaming(
            command: $command,
            input: '',
            timeoutSeconds: $timeout,
            cwd: $cwd,
            onEvent: $onEvent,
            job: $job,
            extraEnv: $managedConfigPath !== null ? ['HERMES_CONFIG' => $managedConfigPath] : null,
        );
        $resultPacket = $this->resultPackets->build($job, $result, $mission, $invocation);
        $memoryAdapterReceipt = $this->memoryAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $memoryPolicy);
        $scheduleAdapterReceipt = $this->scheduleAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $schedulePolicy);
        $procedureAdapterReceipt = $this->procedureAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $procedurePolicy);
        $gatewayAdapterReceipt = $this->gatewayAdapter->process($job, $resultPacket, $mission, $invocation, $gatewayPolicy, $gatewayAllowed);

        return new AiProviderResult(
            ok: $result->ok,
            output: $result->output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, [
                'executive_mission' => $mission,
                'cli_invocation' => $invocation,
                'hermes_result_packet' => $resultPacket,
                'hermes_memory_adapter' => $memoryAdapterReceipt,
                'hermes_schedule_adapter' => $scheduleAdapterReceipt,
                'hermes_procedure_adapter' => $procedureAdapterReceipt,
                'hermes_gateway_adapter' => $gatewayAdapterReceipt,
                'hermes_mcp_adapter' => $mcpReceipt,
                'hermes_delegation_adapter' => $delegationReceipt,
                'hermes_runtime_router' => [
                    'schema_version' => 'atlas.hermes.runtime_router.v1',
                    'reason' => $this->cleanString(data_get($job->payload, 'hermes.runtime_router_reason')) ?? 'atlas_decide_selected_hermes_executive_runtime',
                    'runtime_role' => 'executive_runtime',
                ],
                'hermes_capability_invocation' => $capabilityReceipt,
                'hermes_capability_manifest_hash' => data_get($capabilityManifest, 'manifest_hash'),
                'hermes_runtime' => [
                    'schema_version' => 1,
                    'role' => 'executive_runtime',
                    'atlas_is_sovereign' => true,
                    'memory_policy' => $memoryPolicy,
                    'schedule_policy' => $schedulePolicy,
                    'procedure_policy' => $procedurePolicy,
                    'gateway_policy' => $gatewayPolicy,
                    'gateway_allowed' => $gatewayAllowed,
                    'executive_mission_id' => $mission['mission_id'] ?? null,
                    'executive_mission_hash' => $mission['mission_hash'] ?? null,
                    'result_packet_hash' => $resultPacket['result_hash'] ?? null,
                    'memory_delta_candidate_count' => (int) data_get($resultPacket, 'memory_gate.candidate_count', 0),
                    'memory_adapter_status' => data_get($memoryAdapterReceipt, 'status'),
                    'memory_adapter_persisted_count' => (int) data_get($memoryAdapterReceipt, 'persisted_count', 0),
                    'memory_adapter_duplicate_count' => (int) data_get($memoryAdapterReceipt, 'duplicate_count', 0),
                    'memory_adapter_receipt_hash' => data_get($memoryAdapterReceipt, 'receipt_hash'),
                    'procedure_candidate_count' => (int) data_get($resultPacket, 'procedure_gate.candidate_count', 0),
                    'procedure_adapter_status' => data_get($procedureAdapterReceipt, 'status'),
                    'procedure_adapter_persisted_count' => (int) data_get($procedureAdapterReceipt, 'persisted_count', 0),
                    'procedure_adapter_duplicate_count' => (int) data_get($procedureAdapterReceipt, 'duplicate_count', 0),
                    'procedure_adapter_receipt_hash' => data_get($procedureAdapterReceipt, 'receipt_hash'),
                    'schedule_candidate_count' => (int) data_get($resultPacket, 'schedule_gate.candidate_count', 0),
                    'schedule_adapter_status' => data_get($scheduleAdapterReceipt, 'status'),
                    'schedule_adapter_persisted_count' => (int) data_get($scheduleAdapterReceipt, 'persisted_count', 0),
                    'schedule_adapter_duplicate_count' => (int) data_get($scheduleAdapterReceipt, 'duplicate_count', 0),
                    'schedule_adapter_receipt_hash' => data_get($scheduleAdapterReceipt, 'receipt_hash'),
                    'gateway_adapter_status' => data_get($gatewayAdapterReceipt, 'status'),
                    'gateway_delivery_allowed_now' => (bool) data_get($gatewayAdapterReceipt, 'delivery_allowed_now', false),
                    'gateway_adapter_receipt_hash' => data_get($gatewayAdapterReceipt, 'receipt_hash'),
                    'gateway_delivery_authority' => 'atlas',
                ],
            ]),
        );
    }

    public function health(): AiProviderHealthCheck
    {
        $binary = (string) ($this->runtimeSettings->providerConfig($this->key())['binary'] ?? 'hermes');

        return $this->checkCliRuntimeContract(
            check: $this->checkBinary($this->key(), $binary),
            binary: $binary,
            helpArgs: ['chat', '--help'],
            requiredTokens: [
                '--query',
                '--quiet',
                '--model',
                '--provider',
                '--toolsets',
                '--skills',
                '--source',
                '--max-turns',
                '--image',
            ],
            contractName: 'hermes_cli_provider.v1',
        );
    }

    protected function extractOutput(string $stdout): string
    {
        $lines = preg_split('/\R/u', trim($stdout)) ?: [];
        $kept = array_filter($lines, function (string $line): bool {
            $trimmed = trim($line);

            return $trimmed !== ''
                && preg_match('/^(session(?:\s+id)?|source|model)\s*[:=]/i', $trimmed) !== 1;
        });

        return trim(implode("\n", $kept));
    }

    protected function redactCommand(array $command): array
    {
        $redacted = AtlasSecurity::redactCommand($command);
        $count = count($redacted);

        for ($i = 0; $i < $count; $i++) {
            $arg = is_scalar($redacted[$i] ?? null) ? (string) $redacted[$i] : '';
            if (in_array($arg, ['-q', '--query', '-z', '--oneshot'], true) && isset($redacted[$i + 1])) {
                $redacted[$i + 1] = '[prompt:redacted]';
            }

            foreach (['--query=', '--oneshot='] as $prefix) {
                if (str_starts_with($arg, $prefix)) {
                    $redacted[$i] = $prefix.'[prompt:redacted]';
                }
            }
        }

        return $redacted;
    }

    /**
     * @param  array<int,mixed>  $args
     * @return array<int,string>
     */
    private function sanitizeConfiguredArgs(array $args): array
    {
        return $this->sanitizeCliArgs(
            $args,
            [
                '-q',
                '--query',
                '-z',
                '--oneshot',
                '-m',
                '--model',
                '--provider',
                '-t',
                '--toolsets',
                '-s',
                '--skills',
                '--source',
                '--max-turns',
                '--image',
                '--resume',
                '-r',
                '--continue',
                '-c',
            ],
            [
                '--yolo',
                '--worktree',
                '--accept-hooks',
                '--checkpoints',
                '--ignore-user-config',
                '--ignore-rules',
                '--pass-session-id',
                '--tui',
                '--dev',
            ],
        );
    }

    /**
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function ensureChatCommand(array $args): array
    {
        $args = array_values($args);

        if ($args === [] || str_starts_with($args[0], '-')) {
            array_unshift($args, 'chat');
        }

        if (($args[0] ?? null) !== 'chat') {
            $args[0] = 'chat';
        }

        if (! in_array('--quiet', $args, true) && ! in_array('-Q', $args, true)) {
            $args[] = '--quiet';
        }

        return $args;
    }

    /**
     * @param  array<int,string>  $args
     * @param  array<string,mixed>  $provider
     * @return array<int,string>
     */
    private function withHermesRuntimeArgs(array $args, AiJob $job, array $provider): array
    {
        $mode = $this->permissionModeForJob($job);

        foreach ([
            '--provider' => data_get($job->payload, 'hermes.provider') ?: ($provider['provider'] ?? null),
            '--toolsets' => data_get($job->payload, 'hermes.toolsets') ?: ($provider['toolsets'] ?? null),
            '--skills' => data_get($job->payload, 'hermes.skills') ?: ($provider['skills'] ?? null),
        ] as $flag => $value) {
            $value = $this->cleanString($value);
            if ($value !== null) {
                $args = $this->withArgValue($args, $flag, $value);
            }
        }

        $resume = $this->cleanString(data_get($job->payload, 'hermes.resume'));
        if ($resume !== null) {
            $args = $this->withArgValue($args, '--resume', $resume);
        } elseif (data_get($job->payload, 'hermes.continue') === true) {
            $args[] = '--continue';
        } elseif (($continue = $this->cleanString(data_get($job->payload, 'hermes.continue'))) !== null) {
            $args[] = '--continue';
            $args[] = $continue;
        }

        if ((bool) (data_get($job->payload, 'hermes.worktree') ?? ($provider['worktree'] ?? false))) {
            $args[] = '--worktree';
        }

        if (in_array($mode, ['write', 'danger'], true)) {
            if ((bool) ($provider['accept_hooks'] ?? true)) {
                $args[] = '--accept-hooks';
            }
            if ((bool) ($provider['checkpoints'] ?? true)) {
                $args[] = '--checkpoints';
            }
        }

        if ($mode === 'danger') {
            $args[] = '--yolo';
        }

        return array_values(array_unique($args));
    }

    /**
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function withImageAttachments(array $args, AiJob $job): array
    {
        $images = data_get($job->payload, 'attachments.images', []);
        $files = data_get($job->payload, 'attachments.files', []);
        $images = is_array($images) ? $images : [];
        $files = is_array($files) ? $files : [];

        $paths = collect($images)
            ->map(fn (mixed $image): ?string => $this->attachmentPath(is_array($image) ? ($image['path'] ?? null) : null))
            ->filter()
            ->values();

        $pageLimit = max(0, (int) config('atlas.attachments.pdf.vision_page_limit', 12));
        if ($pageLimit > 0) {
            $pageImages = collect($files)
                ->flatMap(function (mixed $file): array {
                    if (! is_array($file)) {
                        return [];
                    }

                    return is_array($file['pdf_rendered_pages'] ?? null)
                        ? $file['pdf_rendered_pages']
                        : (is_array($file['office_rendered_pages'] ?? null) ? $file['office_rendered_pages'] : []);
                })
                ->map(fn (mixed $page): ?string => $this->attachmentPath(is_array($page) ? ($page['path'] ?? null) : null))
                ->filter()
                ->take($pageLimit)
                ->values();

            $paths = $paths->merge($pageImages);
        }

        foreach ($paths->unique()->values()->all() as $path) {
            $args[] = '--image';
            $args[] = $path;
        }

        return $args;
    }

    /**
     * @return array<int,array{label:string,path:string,mime:string,bytes:string}>
     */
    private function fileAttachmentAccessPaths(AiJob $job): array
    {
        $files = data_get($job->payload, 'attachments.files', []);
        $files = is_array($files) ? $files : [];
        $paths = [];

        foreach (array_slice($files, 0, 4) as $index => $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = $this->attachmentPath($file['path'] ?? null);
            if ($path === null) {
                continue;
            }

            $paths[] = [
                'label' => $this->safeAttachmentLabel((string) ($file['original_name'] ?? 'arquivo '.($index + 1))),
                'path' => $path,
                'mime' => $this->safeAttachmentLabel((string) ($file['mime_type'] ?? 'application/octet-stream')),
                'bytes' => $this->safeAttachmentLabel((string) ($file['bytes'] ?? 'desconhecido')),
            ];
        }

        return collect($paths)->unique('path')->values()->all();
    }

    /**
     * @param  array<int,array{label:string,path:string,mime:string,bytes:string}>  $attachments
     */
    private function promptWithFileAttachmentAccess(string $prompt, array $attachments): string
    {
        $lines = [
            '',
            '',
            '# Acesso local aos arquivos anexados para Hermes',
            '',
            'Use os caminhos abaixo apenas como contexto do operador. Nao edite, mova ou apague anexos sem uma permissao explicita da Executive Mission.',
        ];

        foreach ($attachments as $index => $attachment) {
            $number = $index + 1;
            $lines[] = "- arquivo {$number} ({$attachment['label']}, {$attachment['mime']}, {$attachment['bytes']} bytes): {$attachment['path']}";
        }

        return rtrim($prompt).implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $mission
     */
    private function promptWithExecutiveMission(string $prompt, array $mission): string
    {
        $missionJson = json_encode($mission, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($missionJson)) {
            $missionJson = '{}';
        }

        return implode("\n", [
            '# ATLS Executive Mission for Hermes',
            '',
            'You are Hermes acting only as an executive runtime for ATLS. ATLS is sovereign over intent, policy, context, verification and memory. Follow the mission contract below. Do not promote memory, enable gateway behavior, or expand scope unless the mission explicitly allows it.',
            '',
            '```json',
            $missionJson,
            '```',
            '',
            '# Operator Prompt Compiled By ATLS',
            '',
            $prompt,
            '',
            '# Hermes Result Contract',
            '',
            'Return the useful answer normally. If you identify reusable memory, procedure candidates, or schedule candidates, include fenced JSON blocks using schema_version "atlas.hermes.memory_delta_candidates.v1", "atlas.hermes.procedure_candidates.v1", or "atlas.hermes.schedule_candidates.v1". These candidates are only suggestions; ATLS will quarantine them for review and may not promote or activate them from this response alone.',
        ]);
    }

    private function invocationModel(AiJob $job, array $provider): ?string
    {
        $source = data_get($job->payload, 'model_identity_source') ?? data_get($job->metadata, 'model_identity_source');
        if (in_array($source, ['provider_default_identity', 'configured_model_identity'], true)) {
            return null;
        }

        $model = $job->model ?: ($provider['model'] ?? null);
        $model = $this->cleanString($model);

        return $model === null || str_ends_with($model, '_default') ? null : $model;
    }

    private function memoryPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.memory_policy') ?: ($provider['memory_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'operational_only', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function schedulePolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.schedule_policy') ?: ($provider['schedule_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function procedurePolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.procedure_policy') ?: ($provider['procedure_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function gatewayPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.gateway_policy') ?: ($provider['gateway_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function mcpPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.mcp_policy') ?: ($provider['mcp_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function delegationPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.delegation_policy') ?: ($provider['delegation_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    /**
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function mergeToolset(array $args, string $toolset): array
    {
        $index = array_search('--toolsets', $args, true);
        if ($index === false || ! isset($args[$index + 1])) {
            $args[] = '--toolsets';
            $args[] = $toolset;

            return array_values($args);
        }

        $existing = array_filter(array_map('trim', explode(',', (string) $args[$index + 1])), fn (string $t): bool => $t !== '');
        $existing[] = $toolset;
        $args[(int) $index + 1] = implode(',', array_values(array_unique($existing)));

        return array_values($args);
    }

    /**
     * @param  array<string,mixed>  $provider
     * @return array<string,mixed>
     */
    private function capabilityPolicy(AiJob $job, array $provider): array
    {
        $policy = $provider['capability_policy'] ?? config('atlas.ai.providers.hermes_cli.capability_policy', []);
        $policy = is_array($policy) ? $policy : [];
        $enabled = (bool) ($policy['enabled'] ?? false);

        $allow = array_values(array_unique(array_merge(
            is_array($policy['allow'] ?? null) ? array_values(array_filter($policy['allow'], 'is_string')) : [],
            $this->csvCapabilityIds(data_get($job->payload, 'hermes.capability_allow')),
            $enabled ? $this->approvedCapabilityIds() : [],
        )));

        return [
            'enabled' => $enabled,
            'allow' => $allow,
            'allow_by_mode' => is_array($policy['allow_by_mode'] ?? null) ? $policy['allow_by_mode'] : [],
            'always_quarantine_classes' => is_array($policy['always_quarantine_classes'] ?? null) ? $policy['always_quarantine_classes'] : [],
            'allowed_paths' => $this->capabilityAllowedPaths($job),
            'config_confirmed' => is_array($policy['config_confirmed'] ?? null) ? $policy['config_confirmed'] : [],
        ];
    }

    /**
     * Capability ids promoted to enabled by HermesCapabilityEnablementGate.
     * These extend the config allowlist at runtime so an operator-approved
     * capability becomes usable without a config edit, while staying governed.
     *
     * @return array<int,string>
     */
    private function approvedCapabilityIds(): array
    {
        if (! Schema::hasTable('hermes_capability_candidates')) {
            return [];
        }

        return HermesCapabilityCandidate::query()
            ->where('enabled', true)
            ->where('gate_status', 'approved_for_atlas_capability_use')
            ->get(['capability_class', 'capability_key'])
            ->map(fn (HermesCapabilityCandidate $candidate): string => $candidate->capability_class.':'.$candidate->capability_key)
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function capabilityAllowedPaths(AiJob $job): array
    {
        $roots = data_get($job->payload, 'tool_permissions.allowed_roots');
        if (! is_array($roots)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $path): ?string => is_string($path) && trim($path) !== '' ? trim($path) : null,
            $roots,
        )));
    }

    /**
     * @return array<int,string>
     */
    private function csvCapabilityIds(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = preg_split('/\s*,\s*/', trim($value)) ?: [];
        } else {
            $items = [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $items,
        ))));
    }

    private function attachmentPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = trim($path);
        if (File::isFile($path)) {
            return realpath($path) ?: $path;
        }

        $storagePrefix = '/app/storage/';
        if (str_starts_with($path, $storagePrefix)) {
            $candidate = storage_path(substr($path, strlen($storagePrefix)));
            if (File::isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        $appPrefix = '/app/';
        if (str_starts_with($path, $appPrefix)) {
            $candidate = base_path(substr($path, strlen($appPrefix)));
            if (File::isFile($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }

    private function safeAttachmentLabel(string $value): string
    {
        $value = trim(preg_replace('/[^\pL\pN._,@:()+= -]+/u', ' ', $value) ?? '');

        return mb_substr($value === '' ? 'arquivo' : $value, 0, 120);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 180, '');
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
