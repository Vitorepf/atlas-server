<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\HermesCapabilityCandidate;
use App\Models\HermesSkillCandidate;
use App\Services\Ai\Concerns\HasAttachmentPath;
use App\Services\Ai\Concerns\RunsCliProcesses;
use App\Services\Ai\Hermes\Acp\AtlasHermesAcpRuntime;
use App\Services\Ai\Hermes\Acp\HermesAcpChannel;
use App\Services\Ai\Hermes\Acp\HermesAcpSessionPool;
use App\Services\Ai\Hermes\Acp\HermesAcpTransport;
use App\Services\Ai\Hermes\HermesCapabilityInvocationBuilder;
use App\Services\Ai\Hermes\HermesCapabilityRegistry;
use App\Services\Ai\Hermes\HermesDelegationAdapter;
use App\Services\Ai\Hermes\HermesExecutiveMissionFactory;
use App\Services\Ai\Hermes\HermesHookBridge;
use App\Services\Ai\Hermes\HermesMcpAdapter;
use App\Services\Ai\Hermes\HermesMemoryAdapter;
use App\Services\Ai\Hermes\HermesNativeFcCapabilityAttestor;
use App\Services\Ai\Hermes\HermesNativeFunctionCallSupport;
use App\Services\Ai\Hermes\HermesProcedureAdapter;
use App\Services\Ai\Hermes\HermesResultPacketFactory;
use App\Services\Ai\Hermes\HermesScheduleAdapter;
use App\Services\Ai\Hermes\HermesSkillProvisioner;
use App\Services\Ai\Hermes\ManagedHermesHome;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Skills\Governance\HermesSkillProvisionGate;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class HermesCliProvider implements AiProvider
{
    use HasAttachmentPath;
    use RunsCliProcesses;

    public function __construct(
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
        private readonly HermesExecutiveMissionFactory $missions,
        private readonly HermesMemoryAdapter $memoryAdapter,
        private readonly HermesScheduleAdapter $scheduleAdapter,
        private readonly HermesProcedureAdapter $procedureAdapter,
        private readonly HermesCapabilityRegistry $capabilityRegistry,
        private readonly HermesCapabilityInvocationBuilder $capabilityBuilder,
        private readonly HermesMcpAdapter $mcpAdapter,
        private readonly HermesDelegationAdapter $delegationAdapter,
        private readonly HermesHookBridge $hookBridge,
        private readonly HermesResultPacketFactory $resultPackets,
        private readonly AtlasHermesAcpRuntime $acpRuntime = new AtlasHermesAcpRuntime,
        private readonly HermesAcpSessionPool $acpPool = new HermesAcpSessionPool,
        private readonly HermesSkillProvisioner $skillProvisioner = new HermesSkillProvisioner(new Filesystem, new HermesSkillProvisionGate),
        private readonly ManagedHermesHome $managedHome = new ManagedHermesHome(new Filesystem),
    ) {}

    /**
     * Reason the last ACP attempt fell back to the CLI transport (e.g.
     * acp_initialize_failed, acp_session_new_failed, acp_prompt_incomplete,
     * acp_transport_exception), or null when ACP was not attempted or succeeded.
     * Stamped into the result metadata so every attempt records WHY it used the
     * transport it used — auditable in ai_job_attempts.metadata.
     */
    private ?string $lastAcpFallbackReason = null;

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
        $this->lastAcpFallbackReason = null;
        $memoryPolicy = $this->memoryPolicy($job, $provider);
        $schedulePolicy = $this->schedulePolicy($job, $provider);
        $procedurePolicy = $this->procedurePolicy($job, $provider);
        $binary = (string) ($provider['binary'] ?? 'hermes');
        $args = $this->ensureChatCommand($this->sanitizeConfiguredArgs((array) ($provider['args'] ?? ['chat', '--quiet'])));
        $args = $this->withHermesRuntimeArgs($args, $job, $provider);
        $args = $this->withImageAttachments($args, $job);

        $fileAttachments = $this->fileAttachmentAccessPaths($job);
        if ($fileAttachments !== []) {
            $prompt = $this->promptWithFileAttachmentAccess($prompt, $fileAttachments);
        }

        // Multimodal: mission envelope defaults vision=false and is injected into the
        // prompt. Hermes then refuses to "see" images. Auto-enable when we have paths.
        $this->enableVisionCapabilityWhenImagesPresent($job);

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
        // The delegation adapter (like the mesh) consumes the FLAT manifest entry
        // list, whereas the capability builder + MCP adapter read the full manifest
        // document and pull `.entries` themselves. Hand delegation the entries so
        // its `delegation:supported` probe actually resolves.
        $delegationReceipt = $this->delegationAdapter->authorize($job, $mission, $capabilityContext, $delegationPolicy, $this->permissionModeForJob($job), $this->capabilityManifestEntries($capabilityManifest));
        if ((bool) data_get($delegationReceipt, 'delegation_enabled', false)) {
            $args = $this->mergeToolset($args, 'delegation');
            // Materialize Atlas's CLAMPED caps into a managed HERMES_HOME config.yaml
            // so Hermes enforces them (never its own defaults). Merge into the SAME
            // home the MCP boundary already created when present — never a second.
            $managedConfigPath = $this->materializeDelegationCaps($job, $mission, $delegationReceipt, $managedConfigPath);
        }

        // Governed skill provisioning (default-off). When the policy is the Atlas
        // adapter, promote approved+installable skills onto disk and emit ONLY the
        // Atlas-provisioned subset via the SAME --skills arg. When off, this is a
        // no-op and --skills stays byte-identical to what withHermesRuntimeArgs set.
        [$args, $skillProvisionReceipt] = $this->withGovernedSkills($args, $job, $provider, $mission);

        $prompt = $this->promptWithExecutiveMission($prompt, $mission);

        // R104-TRANSPORT: when the job requests native FC lift, append the Atlas
        // tool declaration so Hermes text channel can return structured tool_calls.
        // Does not claim capability from model labels — only from job contract/config.
        $jobPayload = is_array($job->payload) ? $job->payload : [];
        if (HermesNativeFcCapabilityAttestor::jobRequestsNativeFcLift($jobPayload)) {
            $prompt = rtrim($prompt)."\n\n".HermesNativeFunctionCallSupport::declarePromptBlock();
        }

        if ($model = $this->invocationModel($job, $provider)) {
            $args[] = '--model';
            $args[] = $model;
        }

        // CLI invocation mode. `hermes chat` is the INTERACTIVE subcommand: in a
        // headless run (no TTY) it can block waiting on input — exactly the hang
        // that stalled the autonomous loop (every grind burned the full attempt
        // budget with zero output). The top-level `hermes -z PROMPT` one-shot is
        // non-interactive ("send a single prompt and print ONLY the final
        // response … intended for scripts / pipes"; approvals auto-bypassed) and
        // still loads config.yaml/tools/memory/AGENTS.md as normal, so the model
        // fallback chain, reasoning_effort and max_turns are honored from config.
        // Forge provider invocations (loop/missions) MUST take the CLI path (they
        // carry a per-call process env the warm ACP pool cannot) and never reuse a
        // session, so they default to one-shot; session continuity (resume/
        // continue) always forces `chat`. {@see useCliOneShot}
        // The session source tag is recorded in the invocation fingerprint either
        // way (audit), but is only passed as a `--source` arg on the chat path —
        // the top-level one-shot parser has no `--source` flag.
        $source = $this->cleanString(data_get($job->payload, 'hermes.source') ?: ($provider['source'] ?? 'tool')) ?: 'tool';

        $cliOneShot = $this->useCliOneShot($job, $provider);
        $usageFile = $cliOneShot ? $this->usageFileForJob($job) : null;
        if ($usageFile !== null) {
            $args = $this->withArgValue($args, '--usage-file', $usageFile);
        }

        if ($cliOneShot) {
            $command = $this->buildOneShotCommand($binary, $args, $prompt);
        } else {
            $args = $this->withArgValue($args, '--source', $source);

            $maxTurns = $this->positiveInt(data_get($job->payload, 'hermes.max_turns') ?: ($provider['max_turns'] ?? null));
            if ($maxTurns !== null) {
                $args = $this->withArgValue($args, '--max-turns', (string) $maxTurns);
            }

            $args[] = '--query';
            $args[] = $prompt;

            $command = array_values(array_merge([$binary], $args));
        }
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
            'capability_policy_enabled' => (bool) data_get($capabilityPolicy, 'enabled', false),
            'capabilities_receipt_hash' => data_get($capabilityReceipt, 'receipt_hash'),
            'executive_mission_hash' => $mission['mission_hash'] ?? null,
            'executive_mission_id' => $mission['mission_id'] ?? null,
        ]);

        $permissionMode = $this->permissionModeForJob($job);
        $hookPolicy = $this->hookPolicy($job, $provider);
        $hookSessionContext = [
            'trace_id' => $job->trace_id,
            'hermes_home' => $this->hermesHome($provider),
            'accept_hooks' => in_array($permissionMode, ['write', 'danger'], true) && (bool) ($provider['accept_hooks'] ?? true),
            'sink' => [
                'host' => (string) ($provider['hook_sink_host'] ?? '127.0.0.1'),
                'base_url' => $provider['hook_sink_base_url'] ?? null,
            ],
            'mission_scope' => data_get($mission, 'scope', []),
        ];
        $hookBridgeReceipt = $this->hookBridge->register($job, $mission, $invocation, $hookPolicy, $permissionMode, $capabilityManifest, $hookSessionContext);

        $usage = [];
        $truncationRetries = 0;
        try {
            // Transport strategy: when execution_transport=acp, run the mission
            // through the persistent `hermes acp` session (robust: warm, structured,
            // no stdout parsing); on ANY ACP failure it returns null and we fall back
            // to the CLI `hermes chat` process. Either way $result is a raw
            // AiProviderResult that feeds the SAME packet factory + governance gates
            // below — so memory/schedule/procedure candidate extraction runs
            // identically regardless of transport.
            $processEnv = $this->forgeProviderProcessEnv($job, $managedConfigPath);
            $result = ($this->hasForgeProviderProcessEnv($job) ? null : $this->maybeRunViaAcp($job, $mission, $prompt, $invocation, $cwd, $managedConfigPath, $provider, $timeout, $onEvent))
                ?? $this->runProcessStreaming(
                    command: $command,
                    input: '',
                    timeoutSeconds: $timeout,
                    cwd: $cwd,
                    onEvent: $onEvent,
                    job: $job,
                    extraEnv: $processEnv,
                );
            $usage = $this->consumeUsageFile($usageFile);
            // GARANTIA DE COMPLETUDE (ordem do operador, 20/07): o transporte CLI
            // do hermes às vezes perde o chunk final do stdout (GAP-HERMES-01) e a
            // resposta chega cortada — inaceitável para engenharia séria. O
            // usage-file é a verdade-terrestre do que o modelo GEROU: texto
            // recebido menor que os tokens gerados = truncado → re-executa (até
            // 2×), nunca em silêncio. Vale para TODO caller do runtime
            // (Dev/Forge/benchmark): usar o Hermes COM Atlas nunca pode ser mais
            // frágil do que usá-lo cru — com esta guarda, é mais seguro.
            while ($usageFile !== null
                && $truncationRetries < 2
                && $this->outputLooksTruncated($usage, (string) $result->output)) {
                $truncationRetries++;
                Log::warning('hermes_oneshot_output_truncated_retry', [
                    'attempt' => $truncationRetries,
                    'received_bytes' => strlen((string) $result->output),
                    'usage_output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                ]);
                $result = $this->runProcessStreaming(
                    command: $command,
                    input: '',
                    timeoutSeconds: $timeout,
                    cwd: $cwd,
                    onEvent: $onEvent,
                    job: $job,
                    extraEnv: $processEnv,
                );
                $usage = $this->consumeUsageFile($usageFile);
            }
        } finally {
            if ((bool) data_get($hookBridgeReceipt, 'hooks_registered', false)) {
                $this->hookBridge->revoke($job, $hookSessionContext);
            }
        }
        $resultPacket = $this->resultPackets->build($job, $result, $mission, $invocation);
        $memoryAdapterReceipt = $this->memoryAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $memoryPolicy);
        $scheduleAdapterReceipt = $this->scheduleAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $schedulePolicy);
        $procedureAdapterReceipt = $this->procedureAdapter->persistCandidates($job, $resultPacket, $mission, $invocation, $procedurePolicy);

        $nativeFcMeta = $this->liftNativeFunctionCallMetadata($job, (string) $result->output, is_array($result->metadata) ? $result->metadata : []);

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
            metadata: array_merge($result->metadata, $nativeFcMeta, [
                // Transport actually used: 'acp' when the persistent JSON-RPC session
                // carried the run (stamped by maybeRunViaAcp), else 'cli'. The fallback
                // reason records WHY ACP was not used, so a silent CLI fallback is never
                // invisible — both are auditable in ai_job_attempts.metadata.
                'hermes_transport' => $this->cleanString($result->metadata['hermes_transport'] ?? null) ?? 'cli',
                'hermes_usage' => $usage,
                'hermes_acp_fallback_reason' => $this->lastAcpFallbackReason,
                // Quantas re-execuções a guarda de completude precisou (0 = veio
                // inteiro de primeira). Visível no recibo — truncamento nunca
                // é silencioso, mesmo quando recuperado.
                'hermes_truncation_retries' => $truncationRetries,
                'executive_mission' => $mission,
                'cli_invocation' => $invocation,
                'hermes_result_packet' => $resultPacket,
                'hermes_memory_adapter' => $memoryAdapterReceipt,
                'hermes_schedule_adapter' => $scheduleAdapterReceipt,
                'hermes_procedure_adapter' => $procedureAdapterReceipt,
                'hermes_mcp_adapter' => $mcpReceipt,
                'hermes_delegation_adapter' => $delegationReceipt,
                'hermes_skill_provisioner' => $skillProvisionReceipt,
                'hermes_hook_bridge' => $hookBridgeReceipt,
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
                ],
            ]),
        );
    }

    private function hasForgeProviderProcessEnv(AiJob $job): bool
    {
        return is_array(data_get($job->payload, 'forge_provider_invocation_env'));
    }

    /**
     * @return array<string,string|int|float|false>|null
     */
    private function forgeProviderProcessEnv(AiJob $job, ?string $managedConfigPath): ?array
    {
        $env = [];
        $requested = data_get($job->payload, 'forge_provider_invocation_env');
        if (is_array($requested)) {
            foreach ($requested as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                if (is_string($value) || is_numeric($value) || $value === false) {
                    $env[$key] = $value;
                }
            }
        }
        if ($managedConfigPath !== null) {
            $env['HERMES_HOME'] = $managedConfigPath;
        }

        return $env !== [] ? $env : null;
    }

    private function hasForgeProviderInvocation(AiJob $job): bool
    {
        return is_array(data_get($job->payload, 'forge_provider_invocation'));
    }

    /**
     * Whether the CLI path should invoke the top-level one-shot `hermes -z PROMPT`
     * form instead of the interactive `hermes chat` subcommand.
     *
     * `hermes chat` starts an interactive session; headless (no TTY) it can block
     * waiting on input, which is exactly the hang that stalled the autonomous loop
     * (every grind ate the full attempt budget with zero output). The top-level
     * `-z`/`--oneshot` flag is non-interactive — "send a single prompt and print
     * ONLY the final response … intended for scripts / pipes", approvals
     * auto-bypassed — and loads config.yaml/tools/memory/AGENTS.md as normal.
     *
     * Scope (fail-safe, smallest blast radius):
     *   - resume/continue requested  → false (session continuity needs `chat`);
     *   - image attachments present  → false (vision only via `chat --image`;
     *     top-level `-z` has no `--image` flag, and buildOneShotCommand drops it);
     *   - explicit `hermes.cli_oneshot` payload bool → honored (except images above);
     *   - Forge provider invocation  → `…cli_oneshot_for_forge` (default ON);
     *   - any other CLI caller       → `…cli_oneshot` (default OFF, `chat` as before).
     *
     * @param  array<string,mixed>  $provider
     */
    private function useCliOneShot(AiJob $job, array $provider): bool
    {
        // Session continuity is only available through the `chat` subcommand's
        // session handling; never one-shot a resume/continue request.
        if ($this->cleanString(data_get($job->payload, 'hermes.resume')) !== null
            || data_get($job->payload, 'hermes.continue') !== null) {
            return false;
        }

        // Multimodal: hermes only accepts --image on `chat`. One-shot drops it.
        // Overrides explicit cli_oneshot=true (Terminal Dev paste path).
        if ($this->jobHasImageAttachments($job)) {
            return false;
        }

        $explicit = data_get($job->payload, 'hermes.cli_oneshot');
        if (is_bool($explicit)) {
            return $explicit;
        }

        if ($this->hasForgeProviderInvocation($job)) {
            return (bool) ($provider['cli_oneshot_for_forge']
                ?? config('atlas.ai.providers.hermes_cli.cli_oneshot_for_forge', true));
        }

        return (bool) ($provider['cli_oneshot']
            ?? config('atlas.ai.providers.hermes_cli.cli_oneshot', false));
    }

    /**
     * True when the job carries resolvable image paths that would be passed as
     * `--image` (direct attachments.images or rendered PDF/office page images).
     */
    private function jobHasImageAttachments(AiJob $job): bool
    {
        $images = data_get($job->payload, 'attachments.images', []);
        $images = is_array($images) ? $images : [];
        foreach ($images as $image) {
            $path = $this->attachmentPath(is_array($image) ? ($image['path'] ?? null) : null);
            if ($path !== null) {
                return true;
            }
        }

        $pageLimit = max(0, (int) config('atlas.attachments.pdf.vision_page_limit', 12));
        if ($pageLimit <= 0) {
            return false;
        }

        $files = data_get($job->payload, 'attachments.files', []);
        $files = is_array($files) ? $files : [];
        $seen = 0;
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $pages = is_array($file['pdf_rendered_pages'] ?? null)
                ? $file['pdf_rendered_pages']
                : (is_array($file['office_rendered_pages'] ?? null) ? $file['office_rendered_pages'] : []);
            foreach ($pages as $page) {
                $path = $this->attachmentPath(is_array($page) ? ($page['path'] ?? null) : null);
                if ($path !== null) {
                    return true;
                }
                $seen++;
                if ($seen >= $pageLimit) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * When the job carries image attachments, stamp hermes.capabilities.vision=true
     * so the executive mission envelope does not claim vision=false (which makes
     * Hermes deny pasted images even if --image is on the argv).
     */
    private function enableVisionCapabilityWhenImagesPresent(AiJob $job): void
    {
        if (! $this->jobHasImageAttachments($job)) {
            return;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $hermes = is_array($payload['hermes'] ?? null) ? $payload['hermes'] : [];
        $caps = is_array($hermes['capabilities'] ?? null) ? $hermes['capabilities'] : [];
        if (($caps['vision'] ?? null) === true) {
            return;
        }
        $caps['vision'] = true;
        $hermes['capabilities'] = $caps;
        $payload['hermes'] = $hermes;
        $job->payload = $payload;
    }

    /**
     * Convert the fully-resolved `chat` arg list into the top-level one-shot
     * command: drop the `chat` positional and every chat-only flag the top-level
     * parser does not accept, then express the prompt via `-z PROMPT`.
     *
     * Dropped bare: `chat`, `--quiet`/`-Q`, `--checkpoints`. Dropped with their
     * value: `--query`/`-q`, `--source`, `--max-turns`, `--image`. Everything else
     * (`--provider`, `--model`/`-m`, `--toolsets`/`-t`, `--skills`/`-s`,
     * `--worktree`, `--accept-hooks`, `--yolo`, `--ignore-rules`, …) is a valid
     * top-level flag and passes through unchanged.
     *
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function buildOneShotCommand(string $binary, array $args, string $prompt): array
    {
        $dropBare = ['chat', '--quiet', '-Q', '--checkpoints'];
        $dropWithValue = ['--query', '-q', '--source', '--max-turns', '--image'];

        $clean = [];
        $args = array_values($args);
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (in_array($arg, $dropBare, true)) {
                continue;
            }
            if (in_array($arg, $dropWithValue, true)) {
                $i++; // skip the flag's value as well

                continue;
            }
            $clean[] = $arg;
        }

        return array_values(array_merge([$binary, '-z', $prompt], $clean));
    }

    private function usageFileForJob(AiJob $job): ?string
    {
        $path = data_get($job->payload, 'hermes.usage_file');
        if (! is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            return null;
        }
        $path = trim($path);
        $directory = realpath(dirname($path));
        if ($directory === false || ! is_writable($directory)) {
            return null;
        }

        return $directory.DIRECTORY_SEPARATOR.basename($path);
    }

    /** @return array<string, mixed> */
    /**
     * Verdade-terrestre da completude: o usage-file do hermes DECLARA o estado
     * da sessão (`completed`/`failed`). Sessão não-completada = resposta
     * parcial/cortada (GAP-HERMES-01) → retry. Heurística de bytes-vs-tokens
     * foi descartada: em contexto de AGENTE, output_tokens conta os turnos
     * internos e a resposta final pode ser legitimamente curta ("OK" com 22
     * tokens — falso-positivo provado ao vivo em 20/07). Sem os campos (hermes
     * antigo) não há verdade-terrestre → nunca chutar.
     *
     * @param  array<string,mixed>  $usage
     */
    private function outputLooksTruncated(array $usage, string $output): bool
    {
        if (($usage['failed'] ?? false) === true) {
            return true;
        }

        return array_key_exists('completed', $usage) && $usage['completed'] !== true;
    }

    private function consumeUsageFile(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }
        try {
            $usage = json_decode((string) file_get_contents($path), true);

            return is_array($usage) ? $usage : [];
        } finally {
            @unlink($path);
        }
    }

    /**
     * Run the mission through the persistent ACP transport when selected; return
     * null (→ CLI) when transport != acp OR on ACP fallback_required, so the caller
     * transparently falls back to `hermes chat`. The returned AiProviderResult feeds
     * the SAME packet factory + governance gates as the CLI path.
     *
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $provider
     */
    /**
     * L3-3: um resultado ACP "succeeded" mas com output VAZIO é o sucesso-falso que causava
     * o sintoma diff-0 (ACP não editava nada mas reportava sucesso, sem disparar fallback).
     * Tratá-lo como vazio força o fallback para o CLI provado. Predicado puro p/ congelar a
     * regressão. Gated (default ON) — desligar restaura o comportamento antigo.
     */
    public function acpResultIsEmptySuccess(bool $ok, string $text): bool
    {
        return $ok
            && trim($text) === ''
            && (bool) config('atlas.ai.hermes.acp_empty_output_fallback', true);
    }

    private function maybeRunViaAcp(AiJob $job, array $mission, string $prompt, array $invocation, string $cwd, ?string $managedConfigPath, array $provider, int $timeout, ?callable $onEvent = null): ?AiProviderResult
    {
        if ($this->executionTransport($job, $provider) !== 'acp') {
            return null;
        }

        $binary = (string) ($provider['binary'] ?? 'hermes');
        $extraEnv = $managedConfigPath !== null ? ['HERMES_HOME' => $managedConfigPath] : null;
        $options = ['cwd' => $cwd, 'prompt_timeout' => $timeout];
        $pooled = $this->acpWarmPoolEnabled($provider);
        $startedAt = microtime(true);

        if ($pooled) {
            // Reuse ONE warm `hermes acp` process per (binary, cwd, managed home)
            // across this worker's jobs, so only the first job pays the ~5s cold
            // start. The factory builds a fresh channel only when none is warm/alive.
            $key = hash('sha256', $binary.'|'.$cwd.'|'.($managedConfigPath ?? ''));
            $packet = $this->acpRuntime->runPooled(
                $this->acpPool,
                $key,
                fn (): HermesAcpChannel => new HermesAcpTransport($binary, $cwd, $extraEnv),
                $mission,
                $prompt,
                $invocation,
                $options,
                $onEvent,
            );
        } else {
            $packet = $this->acpRuntime->run($mission, $prompt, $invocation, new HermesAcpTransport($binary, $cwd, $extraEnv), $options, $onEvent);
        }

        if ((bool) ($packet['fallback_required'] ?? false) === true) {
            $this->lastAcpFallbackReason = $this->cleanString($packet['reason'] ?? null) ?? 'acp_fallback_unspecified';

            return null;
        }

        $text = (string) data_get($packet, 'output.text', '');
        $usage = is_array(data_get($packet, 'usage')) ? data_get($packet, 'usage') : [];
        $ok = ($packet['status'] ?? null) === 'succeeded';

        // L3-3: guard de regressão do transporte ACP. O sintoma diff-0 era um ACP que
        // reportava `succeeded` mas devolvia output VAZIO (nenhum produto de trabalho) — pior
        // que falhar, porque o sucesso-falso não disparava o fallback e o loop via diff 0.
        // Tratar "succeeded + output vazio" como fallback-required: cai para o CLI provado,
        // com razão auditável. Gated (default ON); desligar restaura o comportamento antigo.
        if ($this->acpResultIsEmptySuccess($ok, $text)) {
            $this->lastAcpFallbackReason = 'acp_succeeded_empty_output';

            return null;
        }

        $metadata = [
            'hermes_transport' => 'acp',
            'acp_pooled' => $pooled,
            'acp_usage' => $usage,
            'acp_session_present' => (bool) data_get($packet, 'session_id_hash'),
            'acp_permission_decisions' => is_array($packet['permission_decisions'] ?? null) ? $packet['permission_decisions'] : [],
        ];
        $metadata = array_merge($metadata, $this->liftNativeFunctionCallMetadata($job, $text, $metadata));

        return new AiProviderResult(
            ok: $ok,
            output: $text,
            command: [$binary, 'acp'],
            exitCode: $ok ? 0 : 1,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            stdout: $text,
            stderr: '',
            errorCode: $ok ? null : 'acp_run_incomplete',
            errorMessage: null,
            metadata: $metadata,
        );
    }

    /**
     * Whether the warm ACP session pool is enabled (reuse one `hermes acp` process
     * across a worker's jobs vs. cold-start per call). Default-on; per-provider
     * override wins, else config, else true. Disabling reverts to a fresh
     * cold-started ACP process per call (still ACP, just no reuse).
     *
     * @param  array<string,mixed>  $provider
     */
    private function acpWarmPoolEnabled(array $provider): bool
    {
        if (array_key_exists('acp_warm_pool', $provider)) {
            return (bool) $provider['acp_warm_pool'];
        }

        return (bool) config('atlas.ai.providers.hermes_cli.acp_warm_pool', true);
    }

    /**
     * Selected execution transport: 'acp' (persistent JSON-RPC, robust) or 'cli'
     * (per-call `hermes chat`, fallback). Default-safe to 'cli'.
     *
     * @param  array<string,mixed>  $provider
     */
    private function executionTransport(AiJob $job, array $provider): string
    {
        $value = $this->cleanString(data_get($job->payload, 'hermes.execution_transport'))
            ?: $this->cleanString($provider['execution_transport'] ?? null)
            ?: $this->cleanString(config('atlas.ai.providers.hermes_cli.execution_transport'))
            ?: 'cli';

        $value = strtolower(trim($value));

        return in_array($value, ['cli', 'acp'], true) ? $value : 'cli';
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
            '--provider' => $this->safeHermesProvider(data_get($job->payload, 'hermes.provider') ?: ($provider['provider'] ?? null)),
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

        // Response-only adapters (for example a benchmark harness that owns
        // the tool loop) must not inherit user rules, plugins, MCP servers or
        // a configured fallback chain. This is explicit and default-off so
        // every existing Hermes caller remains byte-identical.
        if (data_get($job->payload, 'hermes.safe_mode') === true) {
            $args[] = '--safe-mode';
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

        return AiStringListNormalizer::uniqueStrings($args);
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
            return $this->safeHermesModel(null, $provider);
        }

        $model = $job->model ?: ($provider['model'] ?? null);
        $model = $this->safeHermesModel($this->cleanString($model), $provider);

        return $model === null || str_ends_with($model, '_default') ? null : $model;
    }

    /**
     * Hermes is the runtime; Codex/GPT must never be its hidden sub-model.
     *
     * @param  array<string,mixed>  $provider
     */
    private function safeHermesModel(?string $model, array $provider): ?string
    {
        if ($model !== null && ! str_ends_with($model, '_default') && ! $this->isForbiddenHermesModel($model)) {
            return $model;
        }

        foreach ([$provider['model'] ?? null, $provider['model_identity'] ?? null, 'qwen3.6-27b'] as $candidate) {
            $candidate = $this->cleanString($candidate);
            if ($candidate !== null && ! str_ends_with($candidate, '_default') && ! $this->isForbiddenHermesModel($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function safeHermesProvider(mixed $provider): mixed
    {
        $provider = $this->cleanString($provider);
        if ($provider === null) {
            return null;
        }

        return $this->isForbiddenHermesModel($provider) || in_array($provider, ['openai', 'openai_codex', 'codex'], true)
            ? 'verboo'
            : $provider;
    }

    private function isForbiddenHermesModel(string $model): bool
    {
        $model = strtolower($model);

        return str_contains($model, 'codex') || str_contains($model, 'gpt');
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
     * @param  array<string,mixed>  $provider
     */
    private function skillProvisionPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(
            data_get($job->payload, 'hermes.skill_provision_policy')
                ?: ($provider['skill_provision_policy'] ?? config('atlas.ai.providers.hermes_cli.skill_provision_policy', 'off')),
        ) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    private function hookPolicy(AiJob $job, array $provider): string
    {
        $policy = $this->cleanString(data_get($job->payload, 'hermes.hook_policy') ?: ($provider['hook_policy'] ?? 'off')) ?: 'off';

        return in_array($policy, ['off', 'atlas_adapter'], true) ? $policy : 'off';
    }

    /**
     * @param  array<string,mixed>  $provider
     */
    private function hermesHome(array $provider): string
    {
        $home = $this->cleanString($provider['hermes_home'] ?? null);
        if ($home === null) {
            $env = getenv('HERMES_HOME');
            $home = is_string($env) && trim($env) !== '' ? trim($env) : null;
        }
        if ($home === null) {
            $base = rtrim((string) (getenv('HOME') ?: ''), '/');
            $home = $base !== '' ? $base.'/.hermes' : '';
        }

        return $home;
    }

    /**
     * Normalize the capability manifest to the FLAT entry list the delegation
     * adapter + mesh consume. `latestManifest()` returns the full
     * `atlas.hermes.capability_manifest.v1` document ({entries:[...]}); callers
     * that read `.entries` themselves (builder, MCP) get the document, delegation
     * gets the list. A manifest that is already a flat list passes through.
     *
     * @param  array<string,mixed>  $capabilityManifest
     * @return array<int,array<string,mixed>>
     */
    private function capabilityManifestEntries(array $capabilityManifest): array
    {
        $entries = $capabilityManifest['entries'] ?? null;
        if (! is_array($entries)) {
            // Already a flat list (zero-indexed) of entry maps, or empty.
            $entries = array_is_list($capabilityManifest) ? $capabilityManifest : [];
        }

        return array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry)));
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

        $args[(int) $index + 1] = implode(',', AiStringListNormalizer::csvOrArray((string) $args[$index + 1].','.$toolset));

        return array_values($args);
    }

    /**
     * Governed skill provisioning seam. OFF by default: when the policy is not the
     * Atlas adapter this returns $args UNCHANGED (so --skills stays exactly what
     * withHermesRuntimeArgs emitted) and an empty receipt. When ON: it promotes
     * the operator-approved + installable Atlas skills onto disk via the canonical
     * {@see HermesSkillProvisioner} (which re-runs the promotion + provision gates),
     * then re-points --skills at ONLY the Atlas-provisioned subset of what the
     * mission/job requested — Hermes can never receive a skill Atlas did not write.
     *
     * @param  array<int,string>  $args
     * @param  array<string,mixed>  $provider
     * @param  array<string,mixed>  $mission
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function withGovernedSkills(array $args, AiJob $job, array $provider, array $mission): array
    {
        if ($this->skillProvisionPolicy($job, $provider) !== 'atlas_adapter') {
            return [$args, []];
        }

        $externalDir = $this->cleanPath($provider['skills_external_dir'] ?? null)
            ?? $this->cleanPath(config('atlas.ai.providers.hermes_cli.skills_external_dir'))
            ?? storage_path('app/atlas/hermes-skills');

        // The skills the operator/mission asked for this run (same source the
        // unguarded path reads). Stamp them as `requested_skills` so the
        // provisioner computes ONE authoritative selection (its receipt's
        // skills_selection) that we then emit — no second, divergent selection.
        $requested = $this->requestedSkills($job, $provider);
        $missionForProvision = array_merge($mission, ['requested_skills' => $requested]);

        $invocationContext = [
            'provider_cli' => $this->key(),
            'executive_mission_id' => $mission['mission_id'] ?? null,
            'executive_mission_hash' => $mission['mission_hash'] ?? null,
        ];

        $receipt = $this->skillProvisioner->provision(
            $this->promotedSkillRecords(),
            $missionForProvision,
            $invocationContext,
            'atlas_adapter',
            $externalDir,
        );

        // The emitted set is the provisioner's binding selection: the intersection
        // of what was requested and what Atlas actually wrote to disk.
        $selected = is_array(data_get($receipt, 'skills_selection.selected'))
            ? data_get($receipt, 'skills_selection.selected')
            : [];

        $args = $selected === []
            ? $this->removeArgValue($args, '--skills')
            : $this->withArgValue($args, '--skills', implode(',', $selected));

        return [array_values($args), $receipt];
    }

    /**
     * Operator-approved, installable Atlas skills, shaped into the $promotedSkills
     * records {@see HermesSkillProvisioner::provision()} expects. Mirrors
     * {@see approvedCapabilityIds()}: storage-guarded, fail-closed to [] when the
     * table is absent. The provisioner + gate re-verify promotion on each record,
     * so this query is only the candidate source, never the authority.
     *
     * @return array<int,array<string,mixed>>
     */
    private function promotedSkillRecords(): array
    {
        if (! DatabaseTableAvailability::has('hermes_skill_candidates')) {
            return [];
        }

        return HermesSkillCandidate::query()
            ->where('install_allowed', true)
            ->where('status', 'approved_for_atlas_skill_provision')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['payload_json'])
            ->map(fn (HermesSkillCandidate $candidate): array => is_array($candidate->payload_json) ? $candidate->payload_json : [])
            ->filter(fn (array $record): bool => $record !== [])
            ->values()
            ->all();
    }

    /**
     * Skills the operator/mission requested for THIS run — the same source the
     * unguarded path reads, so the governed path filters exactly that set.
     *
     * @param  array<string,mixed>  $provider
     * @return array<int,string>
     */
    private function requestedSkills(AiJob $job, array $provider): array
    {
        $value = data_get($job->payload, 'hermes.skills') ?: ($provider['skills'] ?? null);

        return AiStringListNormalizer::csvOrArray($value);
    }

    /**
     * Drop a value-bearing flag (flag + its single value) from the arg list.
     *
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function removeArgValue(array $args, string $name): array
    {
        $args = array_values($args);
        $index = array_search($name, $args, true);
        if ($index === false) {
            return $args;
        }

        unset($args[$index]);
        if (isset($args[$index + 1])) {
            unset($args[$index + 1]);
        }

        return array_values($args);
    }

    /**
     * Materialize the delegation adapter's CLAMPED config_plan into a managed
     * HERMES_HOME so Hermes enforces Atlas's ceilings. When the MCP boundary
     * already provisioned a home for this run ($existingManagedConfigPath), MERGE
     * the delegation block into that SAME home (one HERMES_HOME, not two);
     * otherwise materialize a delegation-only home. Returns the HERMES_HOME path
     * the process/ACP transport must use.
     *
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $delegationReceipt
     */
    private function materializeDelegationCaps(AiJob $job, array $mission, array $delegationReceipt, ?string $existingManagedConfigPath): ?string
    {
        $delegationBlock = data_get($delegationReceipt, 'config_plan.delegation');
        if (! is_array($delegationBlock) || $delegationBlock === []) {
            return $existingManagedConfigPath;
        }

        $seed = $this->managedHomeSeed($job, $mission);

        // Merge into the SAME 'home' kind+seed the MCP provisioner uses so a single
        // config.yaml carries both mcp_servers and delegation when both are active.
        return $this->managedHome->merge('home', $seed, ['delegation' => $delegationBlock]);
    }

    /**
     * Managed-home seed for THIS run — IDENTICAL to
     * {@see HermesManagedMcpConfigProvisioner::seed()} (job trace, mission_id
     * fallback) so the delegation merge lands in the exact home MCP wrote.
     *
     * @param  array<string,mixed>  $mission
     */
    private function managedHomeSeed(AiJob $job, array $mission): string
    {
        if (is_string($job->trace_id) && trim($job->trace_id) !== '') {
            return trim($job->trace_id);
        }

        return is_string($mission['mission_id'] ?? null) ? (string) $mission['mission_id'] : 'no_trace';
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

        $allow = AiStringListNormalizer::uniqueMergedTrimmedStrings(
            is_array($policy['allow'] ?? null) ? array_values(array_filter($policy['allow'], 'is_string')) : [],
            $this->csvCapabilityIds(data_get($job->payload, 'hermes.capability_allow')),
            $enabled ? $this->approvedCapabilityIds() : [],
        );

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
        if (! DatabaseTableAvailability::has('hermes_capability_candidates')) {
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
        return AiStringListNormalizer::csvOrArray($value);
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

    /**
     * Trim a filesystem path without the cleanString length cap (a skills dir can
     * exceed 180 chars). Null for empty/non-string.
     */
    private function cleanPath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * R104-TRANSPORT: declare atlas_apply_patch and lift structured tool_calls
     * into metadata so AgentExecutionProviderPortAdapter can package patch_plan
     * without model-authored JSON³. Never invents capability from model labels.
     *
     * @param  array<string,mixed>  $existingMetadata
     * @return array<string,mixed>
     */
    private function liftNativeFunctionCallMetadata(AiJob $job, string $output, array $existingMetadata): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $requestsLift = HermesNativeFcCapabilityAttestor::jobRequestsNativeFcLift($payload);
        $declared = $requestsLift || HermesNativeFcCapabilityAttestor::nativeFcEnabled();

        $meta = [
            'atlas_apply_patch_declared' => $declared,
            'atlas_apply_patch_tool' => HermesNativeFunctionCallSupport::TOOL_NAME,
            'atlas_native_fc_transport' => $requestsLift,
        ];

        $existingCalls = HermesNativeFunctionCallSupport::normalizeToolCallsList(
            is_array($existingMetadata['tool_calls'] ?? null) ? $existingMetadata['tool_calls'] : [],
        );
        $parsed = HermesNativeFunctionCallSupport::parseToolCallsFromText($output);
        $calls = $existingCalls !== [] ? $existingCalls : $parsed;

        if ($calls === []) {
            $meta['atlas_native_fc_lifted'] = false;

            return $meta;
        }

        $meta['tool_calls'] = $calls;
        $meta['atlas_native_fc_lifted'] = true;
        $meta['atlas_native_fc_call_count'] = count($calls);

        return $meta;
    }
}
