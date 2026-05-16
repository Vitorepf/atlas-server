<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * Atlas Self-Construction OS · Agent Control Plane · One-Shot Worker Packet.
 *
 * Builds a copy-pasteable worker prompt + structured envelope so an external
 * agent (Claude, Codex, Gemini, future runners) can implement a claimed
 * task_packet without any additional context. Read-only: never invokes a
 * provider, never mutates the lease, the queue or any ledger. Runtime
 * safety flags are always false.
 *
 * Inputs:
 *   - task_packet_id
 *   - lease_id
 *   - actor (the agent_id that owns the lease)
 *   - mode (canon: claimed_task)
 *
 * Blockers (each emits status=blocked with reason):
 *   - missing task_packet_id
 *   - missing lease_id
 *   - lease_not_found
 *   - lease_task_packet_mismatch
 *   - lease_not_active
 *   - lease_owner_mismatch (when actor is supplied)
 *   - task_packet_not_found
 *
 * Output envelope (`atlas.self_construction.agent_control_plane_one_shot_worker_packet.v1`):
 *   - worker_prompt_full  — multi-line prompt for direct paste into a worker
 *   - worker_prompt_goal_short — single-line objective summary
 *   - task_packet         — the original packet payload
 *   - queue_record        — queue projection (status, enqueued_at, etc.)
 *   - lease               — the bound lease record (active)
 *   - allowed_files / forbidden_files
 *   - required_docs       — canonical context the worker must read
 *   - acceptance_criteria
 *   - required_tests / required_guardrails
 *   - evidence_contract   — what the worker must return to claim completion
 *   - completion_command  — exact CLI to run for dry-run completion
 *   - lease_renew_command — exact CLI to extend the lease
 *   - continuation_summary_template / failure_report_template
 *   - forbidden_actions
 *   - runtime_safety      — every flag false
 *   - one_shot_packet_hash — deterministic sha256 of envelope minus the hash
 */
final class AgentControlPlaneOneShotWorkerPacketService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_one_shot_worker_packet.v1';

    public const MODE_CLAIMED_TASK = 'claimed_task';

    public const REPO_PATH_DEFAULT = '/Users/vitorepf/develop/Atlas/atlas-server';

    public function __construct(
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array
    {
        $taskPacketId = trim((string) ($input['task_packet_id'] ?? ''));
        $leaseId = trim((string) ($input['lease_id'] ?? ''));
        $actor = trim((string) ($input['actor'] ?? ''));
        $mode = trim((string) ($input['mode'] ?? self::MODE_CLAIMED_TASK));
        if ($mode === '') {
            $mode = self::MODE_CLAIMED_TASK;
        }

        if ($taskPacketId === '') {
            return $this->blocked('missing_task_packet_id', 'task_packet_id is required');
        }
        if ($leaseId === '') {
            return $this->blocked('missing_lease_id', 'lease_id is required');
        }

        $lease = $this->leases->get($leaseId);
        if ($lease === null) {
            return $this->blocked('lease_not_found', "lease {$leaseId} does not exist");
        }
        if ((string) ($lease['task_packet_id'] ?? '') !== $taskPacketId) {
            return $this->blocked(
                'lease_task_packet_mismatch',
                'lease '.$leaseId.' belongs to '.((string) ($lease['task_packet_id'] ?? 'unknown')).', not '.$taskPacketId,
            );
        }
        if ((string) ($lease['lease_status'] ?? '') !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
            return $this->blocked(
                'lease_not_active',
                'lease status is '.((string) ($lease['lease_status'] ?? 'unknown')).' (must be active)',
            );
        }
        if ($actor !== '' && (string) ($lease['agent_id'] ?? '') !== $actor) {
            return $this->blocked(
                'lease_owner_mismatch',
                'lease owner is '.((string) ($lease['agent_id'] ?? 'unknown')).' but actor is '.$actor,
            );
        }

        $record = $this->queue->get($taskPacketId);
        if ($record === null) {
            return $this->blocked('task_packet_not_found', "task_packet {$taskPacketId} not in queue");
        }
        $taskPacket = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : [];

        $normalizedScope = is_array($taskPacket['normalized_scope'] ?? null) ? $taskPacket['normalized_scope'] : [];
        $allowedFiles = $this->firstNonEmptyStringList(
            (array) ($taskPacket['allowed_files'] ?? []),
            (array) ($normalizedScope['allowed_files'] ?? []),
        );
        $forbiddenFiles = $this->stringList(array_merge(
            (array) ($taskPacket['forbidden_files'] ?? []),
            (array) ($normalizedScope['forbidden_files'] ?? []),
            $this->defaultForbiddenAxes(),
        ));
        $forbiddenFiles = array_values(array_unique($forbiddenFiles));
        $scopeBlockers = $this->scopeBlockers($allowedFiles, $normalizedScope);
        if ($scopeBlockers !== []) {
            return $this->blockedWith(
                'unsafe_worker_scope',
                'task packet cannot be handed to a worker until scope is non-empty and free of forbidden overlaps',
                [
                    'scope_blockers' => $scopeBlockers,
                    'allowed_files' => $allowedFiles,
                    'forbidden_files' => $forbiddenFiles,
                ],
            );
        }
        $requiredDocs = $this->stringList((array) ($taskPacket['required_docs']
            ?? $taskPacket['context_docs']
            ?? $this->defaultRequiredDocs()));
        $acceptanceCriteria = $this->stringList((array) ($taskPacket['acceptance_criteria'] ?? []));
        $requiredTests = $this->stringList((array) ($taskPacket['required_tests']
            ?? $taskPacket['required_gates']
            ?? $this->defaultRequiredTests()));
        $requiredGuardrails = $this->stringList((array) ($taskPacket['required_guardrails']
            ?? $this->defaultGuardrails()));
        $forbiddenActions = $this->stringList((array) ($taskPacket['forbidden_actions']
            ?? $this->defaultForbiddenActions()));

        $objective = (string) ($taskPacket['objective'] ?? $taskPacket['title'] ?? $taskPacketId);
        $rationale = (string) ($taskPacket['rationale'] ?? '');
        $packetHash = (string) ($taskPacket['task_packet_hash'] ?? $taskPacket['packet_hash'] ?? '');

        $completionCommand = sprintf(
            'php artisan atlas:ai:self-construction --agent-control-plane-task-queue-complete-dry-run-status --packet=%s --lease-id=%s --actor=%s --evidence-hash=<sha256-of-final-evidence> --completion-evidence-json=@/path/to/completion-evidence.json --json',
            $taskPacketId,
            $leaseId,
            $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
        );
        $leaseRenewCommand = sprintf(
            'php artisan atlas:ai:self-construction --agent-control-plane-claim-lease-runtime-status --packet=%s --lease-id=%s --actor=%s --json',
            $taskPacketId,
            $leaseId,
            $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
        );
        $resumptionContract = $this->resumptionContract(
            taskPacketId: $taskPacketId,
            leaseId: $leaseId,
            actor: $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
        );

        $evidenceContract = [
            'packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'completion_evidence_schema_version' => 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1',
            'completion_evidence_template_path' => '/path/to/completion-evidence.json',
            'evidence_hash_algorithm' => 'sha256(canonical_json(completion_evidence_without_evidence_hash_fields))',
            'evidence_hash_rule' => 'Build completion-evidence.json, remove evidence_hash/operator_supplied_evidence_hash fields at every level, sort object keys, JSON encode without escaped slashes/unicode, then sha256 the result.',
            'must_report' => [
                'packet_id',
                'lease_id',
                'files_changed',
                'commands_run',
                'tests_or_gates_result',
                'evidence_hash',
                'scope_deviations',
                'residual_risks',
                'next_recommended_packet',
            ],
            'must_run_before_complete' => $requiredTests,
            'must_attach_after_complete' => [
                'completion-evidence.json containing files_changed, commands_run, tests_or_gates_result, git_status_short and git_diff_check_result',
                'git status --short output',
                'git diff --check output',
                'php artisan atlas:engineering:knowledge docs-health --json',
            ],
        ];
        $completionEvidenceTemplate = $this->completionEvidenceTemplate(
            taskPacketId: $taskPacketId,
            leaseId: $leaseId,
            actor: $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
            allowedFiles: $allowedFiles,
            requiredTests: $requiredTests,
        );

        $continuationTemplate = $this->continuationSummaryTemplate($taskPacketId, $leaseId, $objective);
        $failureTemplate = $this->failureReportTemplate($taskPacketId, $leaseId);
        $workerPromptFull = $this->buildWorkerPrompt(
            taskPacketId: $taskPacketId,
            leaseId: $leaseId,
            actor: $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
            mode: $mode,
            objective: $objective,
            rationale: $rationale,
            allowedFiles: $allowedFiles,
            forbiddenFiles: $forbiddenFiles,
            requiredDocs: $requiredDocs,
            acceptanceCriteria: $acceptanceCriteria,
            requiredTests: $requiredTests,
            requiredGuardrails: $requiredGuardrails,
            forbiddenActions: $forbiddenActions,
            completionCommand: $completionCommand,
            leaseRenewCommand: $leaseRenewCommand,
            resumptionContract: $resumptionContract,
            packetHash: $packetHash,
            lease: $lease,
        );
        $workerPromptShort = $this->buildShortGoal($taskPacketId, $leaseId, $objective);

        $envelope = [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => $mode,
            'actor' => $actor !== '' ? $actor : (string) ($lease['agent_id'] ?? 'unknown_actor'),
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'worker_prompt_goal_short' => $workerPromptShort,
            'worker_prompt_full' => $workerPromptFull,
            'task_packet' => $taskPacket,
            'queue_record' => $this->projectQueueRecord($record),
            'lease' => $this->projectLease($lease),
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $forbiddenFiles,
            'required_docs' => $requiredDocs,
            'acceptance_criteria' => $acceptanceCriteria,
            'required_tests' => $requiredTests,
            'required_guardrails' => $requiredGuardrails,
            'evidence_contract' => $evidenceContract,
            'completion_evidence_template' => $completionEvidenceTemplate,
            'completion_evidence_template_json' => json_encode($completionEvidenceTemplate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'resumption_contract' => $resumptionContract,
            'completion_command' => $completionCommand,
            'lease_renew_command' => $leaseRenewCommand,
            'continuation_summary_template' => $continuationTemplate,
            'failure_report_template' => $failureTemplate,
            'forbidden_actions' => $forbiddenActions,
            'runtime_safety' => $this->runtimeSafetyAllFalse(),
            'separated_from_external_rivals_certification' => true,
            'one_shot_packet_hash' => '',
        ];

        $envelope['one_shot_packet_hash'] = $this->computeHash($envelope);

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason, string $detail): array
    {
        return $this->blockedWith($reason, $detail);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blockedWith(string $reason, string $detail, array $extra = []): array
    {
        return array_merge([
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'blockers' => [$reason],
            'reason' => $reason,
            'detail' => $detail,
            'runtime_safety' => $this->runtimeSafetyAllFalse(),
            'one_shot_packet_hash' => '',
            'separated_from_external_rivals_certification' => true,
        ], $extra);
    }

    /**
     * @return array<string,bool>
     */
    private function runtimeSafetyAllFalse(): array
    {
        return [
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'external_rivals_unlock_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultForbiddenAxes(): array
    {
        return [
            'runtimes/python/voice_realtime/**',
            'app/Services/Ai/Voice/**',
            'app/Services/Ai/Programming/ForgeRivals/**',
            'app/Services/Ai/Kernel/Architecture/**',
            'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
            '.env',
            '.env.*',
            'storage/secrets/**',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultRequiredDocs(): array
    {
        return [
            'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
            'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultRequiredTests(): array
    {
        return [
            'php artisan test --filter=<scoped-suite>',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:ai:architecture-validate --json',
            'git diff --check',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultGuardrails(): array
    {
        return [
            'never_invoke_provider',
            'never_spend_tokens',
            'never_dispatch_real_runtime',
            'never_advance_completion_pointer',
            'never_unlock_external_rivals_certification',
            'preserve_worktree_outside_allowed_files',
            'preserve_uncommitted_work_of_other_agents',
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultForbiddenActions(): array
    {
        return [
            'git reset --hard',
            'git push --force',
            'git push -f',
            'rm -rf storage/',
            'rm -rf runtimes/',
            'php artisan migrate:fresh',
            'invoke claude/codex provider CLI for token-spending dispatch',
            'amend or rewrite history on the worker branch',
            'modify files outside allowed_files',
            'unlock external_rivals_certification',
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function projectQueueRecord(array $record): array
    {
        return [
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
            'status' => (string) ($record['status'] ?? 'unknown'),
            'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
            'enqueued_by' => (string) ($record['enqueued_by'] ?? ''),
            'metadata' => is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $lease
     * @return array<string,mixed>
     */
    private function projectLease(array $lease): array
    {
        return [
            'lease_id' => (string) ($lease['lease_id'] ?? ''),
            'task_packet_id' => (string) ($lease['task_packet_id'] ?? ''),
            'agent_id' => (string) ($lease['agent_id'] ?? ''),
            'lease_status' => (string) ($lease['lease_status'] ?? ''),
            'acquired_at' => (string) ($lease['acquired_at'] ?? ''),
            'expires_at' => (string) ($lease['expires_at'] ?? ''),
            'ttl_seconds' => (int) ($lease['ttl_seconds'] ?? 0),
            'renew_count' => (int) ($lease['renew_count'] ?? 0),
            'write_set' => array_values((array) ($lease['write_set'] ?? [])),
            'read_set' => array_values((array) ($lease['read_set'] ?? [])),
            'runtime_execution_allowed' => (bool) ($lease['runtime_execution_allowed'] ?? false),
            'dispatch_allowed' => (bool) ($lease['dispatch_allowed'] ?? false),
            'provider_call_allowed' => (bool) ($lease['provider_call_allowed'] ?? false),
            'token_spend_allowed' => (bool) ($lease['token_spend_allowed'] ?? false),
            'self_programming_allowed' => (bool) ($lease['self_programming_allowed'] ?? false),
            'ledger_write_allowed' => (bool) ($lease['ledger_write_allowed'] ?? false),
            'completion_real_allowed' => (bool) ($lease['completion_real_allowed'] ?? false),
        ];
    }

    private function continuationSummaryTemplate(string $taskPacketId, string $leaseId, string $objective): string
    {
        return <<<TEMPLATE
## Continuation summary

- packet_id: {$taskPacketId}
- lease_id: {$leaseId}
- objective: {$objective}
- files_changed: <list>
- commands_run: <list>
- tests_or_gates_result: <pass|fail>
- evidence_hash: <sha256>
- scope_deviations: none|<list>
- residual_risks: <list>
- next_recommended_packet: <id or none>
TEMPLATE;
    }

    private function failureReportTemplate(string $taskPacketId, string $leaseId): string
    {
        return <<<TEMPLATE
## Failure report

- packet_id: {$taskPacketId}
- lease_id: {$leaseId}
- blocker: <single-sentence blocker>
- root_cause: <single-sentence root cause>
- attempted_paths: <list>
- evidence_paths: <list>
- recommended_next_step: <single-sentence>
TEMPLATE;
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $requiredTests
     * @return array<string,mixed>
     */
    private function completionEvidenceTemplate(string $taskPacketId, string $leaseId, string $actor, array $allowedFiles, array $requiredTests): array
    {
        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1',
            'packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'files_changed' => $allowedFiles === [] ? ['<path>'] : $allowedFiles,
            'commands_run' => $requiredTests === [] ? ['<command + result>'] : array_map(
                static fn (string $command): string => $command.' => <passed|failed>',
                $requiredTests,
            ),
            'tests_or_gates_result' => 'passed',
            'git_status_short' => '<paste git status --short output>',
            'git_diff_check_result' => 'clean',
            'evidence_hash' => '<sha256-of-final-evidence>',
            'evidence_hash_algorithm' => 'sha256(canonical_json(completion_evidence_without_evidence_hash_fields))',
            'scope_deviations' => [],
            'residual_risks' => [],
            'next_recommended_packet' => 'none',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resumptionContract(string $taskPacketId, string $leaseId, string $actor): array
    {
        $recoveryCommand = sprintf(
            'php artisan atlas:ai:self-construction --agent-control-plane-task-lease-recovery-status --packet=%s --actor=%s --reason=worker_resume_after_interruption --json',
            $taskPacketId,
            $actor,
        );
        $bootstrapCommand = sprintf(
            'php artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status --actor=%s --json',
            $actor,
        );
        $packetCommand = sprintf(
            'php artisan atlas:ai:self-construction --agent-control-plane-one-shot-worker-packet-status --packet=%s --lease-id=%s --actor=%s --json',
            $taskPacketId,
            $leaseId,
            $actor,
        );

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'actor' => $actor,
            'lease_bound' => true,
            'can_resume_without_new_lease' => false,
            'resume_requires_active_lease' => true,
            'if_worker_is_interrupted' => [
                'do_not_continue_blindly_after_returning',
                'inspect_current_git_status_and_preserve_unrelated_changes',
                'run_recovery_command_to_classify_or_expire_the_old_lease',
                'if_task_returns_claimable_run_terminal_worker_bootstrap_for_a_fresh_lease',
                'regenerate_one_shot_packet_after_any_new_claim',
                'carry_forward_continuation_summary_and_failure_report',
            ],
            'if_lease_expired' => [
                'stop_writing_immediately',
                'do_not_run_completion_command_with_expired_lease',
                'run_recovery_command',
                'claim_fresh_packet_or_wait_for_operator',
            ],
            'resume_commands' => [
                'inspect_or_recover_current_packet' => $recoveryCommand,
                'claim_next_after_recovery' => $bootstrapCommand,
                'regenerate_current_one_shot_packet_if_lease_still_active' => $packetCommand,
            ],
            'required_resume_evidence' => [
                'last_git_status_short',
                'failure_report_template_completed_when_interrupted',
                'continuation_summary_template_completed_when_partial_progress_exists',
                'recovery_command_output',
                'new_lease_id_when_reclaimed',
            ],
            'forbidden_resume_shortcuts' => [
                'do_not_reuse_expired_lease',
                'do_not_complete_with_a_different_agents_lease',
                'do_not_reopen_completed_dry_run_packet',
                'do_not_bypass_task_lease_recovery_surface',
                'do_not_claim_same_packet_by_manual_file_edit',
            ],
            'non_execution_guarantees' => [
                'resumption_contract_does_not_start_codex',
                'resumption_contract_does_not_call_provider',
                'resumption_contract_does_not_spend_tokens',
                'resumption_contract_does_not_dispatch_work',
                'resumption_contract_does_not_enable_runtime',
                'resumption_contract_does_not_promote_completion',
            ],
        ];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<string>  $requiredDocs
     * @param  list<string>  $acceptanceCriteria
     * @param  list<string>  $requiredTests
     * @param  list<string>  $requiredGuardrails
     * @param  list<string>  $forbiddenActions
     * @param  array<string,mixed>  $lease
     */
    private function buildWorkerPrompt(
        string $taskPacketId,
        string $leaseId,
        string $actor,
        string $mode,
        string $objective,
        string $rationale,
        array $allowedFiles,
        array $forbiddenFiles,
        array $requiredDocs,
        array $acceptanceCriteria,
        array $requiredTests,
        array $requiredGuardrails,
        array $forbiddenActions,
        string $completionCommand,
        string $leaseRenewCommand,
        array $resumptionContract,
        string $packetHash,
        array $lease,
    ): string {
        $repoPath = self::REPO_PATH_DEFAULT;
        $allowedBlock = $this->bulletList($allowedFiles, '_(nenhum arquivo permitido — packet inválido)_');
        $forbiddenBlock = $this->bulletList($forbiddenFiles, '_(nenhuma exclusão explícita — confiar nos forbidden_axes do canon)_');
        $docsBlock = $this->bulletList($requiredDocs, '_(sem docs obrigatórias além do canon)_');
        $acceptanceBlock = $this->bulletList($acceptanceCriteria, '_(packet sem acceptance criteria — bloquear até spec corrigida)_');
        $testsBlock = $this->bulletList($requiredTests, '_(sem testes obrigatórios — bloquear até spec corrigida)_');
        $guardrailsBlock = $this->bulletList($requiredGuardrails, '_(sem guardrails extra — canon padrão se aplica)_');
        $forbiddenActionsBlock = $this->bulletList($forbiddenActions, '_(sem ações proibidas adicionais)_');
        $resumeStepsBlock = $this->bulletList((array) data_get($resumptionContract, 'if_worker_is_interrupted', []), '_(sem plano de retomada — bloquear)_');
        $resumeCommands = (array) data_get($resumptionContract, 'resume_commands', []);
        $rationaleLine = $rationale === '' ? '' : "\n**Por que esta é a próxima peça correta:** {$rationale}\n";
        $packetHashLine = $packetHash === '' ? '' : "\n**packet_hash:** `{$packetHash}`";
        $expiresAt = (string) ($lease['expires_at'] ?? 'unknown');

        return <<<PROMPT
# Atlas Self-Construction · One-Shot Worker Packet

Você é um worker de implementação chamado **{$actor}**. Trabalhe a partir do repo local:

```
cd {$repoPath}
```

**Mode:** `{$mode}`
**Packet id:** `{$taskPacketId}`
**Lease id:** `{$leaseId}` (active, expira em {$expiresAt}){$packetHashLine}

## Objetivo exato

{$objective}
{$rationaleLine}
## Regras canônicas (não negociáveis)

- Preserve a worktree fora de `allowed_files`. Nada de `git reset --hard`, `git push --force`, `rm -rf` em `storage/` ou `runtimes/`.
- Preserve qualquer trabalho não-commitado de outros agentes que já esteja na working tree. Inspecione `git status --short` antes de tocar qualquer arquivo.
- **NÃO chame provider externo.** Não dispare claude/codex/gemini CLI para gastar tokens.
- **NÃO gaste tokens em real-time dispatch.** Esta tarefa é read-only/local, todo o trabalho roda contra o repo + testes locais.
- **NÃO destrava `external_rivals_certification`.** Esse claim continua `blocked_requires_operator_approval` por design.
- Só edite arquivos listados em `allowed_files`. Qualquer arquivo em `forbidden_files` é proibido.

## Docs obrigatórias antes de codar

{$docsBlock}

## Escopo permitido (`allowed_files`)

{$allowedBlock}

## Escopo proibido (`forbidden_files` + forbidden axes canônicos)

{$forbiddenBlock}

## Acceptance criteria

{$acceptanceBlock}

## Testes obrigatórios antes do completion

{$testsBlock}

## Guardrails obrigatórios

{$guardrailsBlock}

## Ações explicitamente proibidas

{$forbiddenActionsBlock}

## Quando terminar

1. Rode `git status --short` e cole o output no relatório final.
2. Rode `git diff --check` — qualquer warning de whitespace deve ser corrigido antes do completion.
3. Rode os testes obrigatórios listados acima.
4. Rode `docs-health` e `architecture-validate`.
5. Execute o completion command em modo dry-run:

```
{$completionCommand}
```

O arquivo `completion-evidence.json` precisa conter, no mínimo:

```json
{
  "files_changed": ["<path>"],
  "commands_run": ["<command + result>"],
  "tests_or_gates_result": "passed",
  "git_status_short": "<output>",
  "git_diff_check_result": "clean",
  "evidence_hash_algorithm": "sha256(canonical_json(completion_evidence_without_evidence_hash_fields))",
  "evidence_hash": "<sha256-of-final-evidence>"
}
```

Calcule `evidence_hash` sobre o próprio `completion-evidence.json` depois de remover qualquer campo
`evidence_hash`/`operator_supplied_evidence_hash` em todos os níveis e ordenar as chaves de objetos.
O Atlas rejeita completion se o hash só tiver formato válido mas não bater com o payload.

Se a lease estiver próxima de expirar, renove com:

```
{$leaseRenewCommand}
```

## Se você for interrompido ou a lease expirar

Siga esta ordem antes de continuar qualquer edição:

{$resumeStepsBlock}

Comandos de retomada:

```bash
{$resumeCommands['inspect_or_recover_current_packet']}
{$resumeCommands['claim_next_after_recovery']}
{$resumeCommands['regenerate_current_one_shot_packet_if_lease_still_active']}
```

## Formato obrigatório do relatório final

- `packet_id`: {$taskPacketId}
- `lease_id`: {$leaseId}
- `files_changed`: <list dos arquivos efetivamente modificados — comparar com `allowed_files`>
- `commands_run`: <list dos comandos de teste + gates rodados>
- `tests_or_gates_result`: pass | fail (cite tests falhos pré-existentes vs. novos)
- `evidence_hash`: sha256 do snapshot de evidência quando aplicável
- `scope_deviations`: none ou <list explicando por que houve deviation e qual gate aprovou>
- `residual_risks`: <list>
- `next_recommended_packet`: <id ou "none">

Se você não conseguir completar a tarefa, devolva o **failure report** seguindo o template fornecido (`failure_report_template`).

## Invariantes finais

- Esta one-shot packet **NÃO** autoriza você a chamar provider, gastar tokens, dispatchar runtime real, mexer em eixos proibidos, escrever no ledger, declarar completion como definitivo, ou destravar `external_rivals_certification`. Tudo isso continua governance-gated em outras camadas.
- A lease ainda é sua: ela bloqueia outro worker de tomar o mesmo packet. Quando terminar, libere com receipt apropriado.
PROMPT;
    }

    private function buildShortGoal(string $taskPacketId, string $leaseId, string $objective): string
    {
        return sprintf(
            'Implementar packet %s sob lease %s — %s',
            $taskPacketId,
            $leaseId,
            $objective !== '' ? $objective : '(objetivo ausente — checar task_packet)',
        );
    }

    /**
     * @param  list<string>  $items
     */
    private function bulletList(array $items, string $emptyMarker): string
    {
        if ($items === []) {
            return $emptyMarker;
        }
        $rows = [];
        foreach ($items as $item) {
            $rows[] = '- `'.$item.'`';
        }

        return implode("\n", $rows);
    }

    /**
     * @param  array<int,mixed>  $list
     * @return list<string>
     */
    private function stringList(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            $trim = trim($item);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int,mixed>  ...$candidates
     * @return list<string>
     */
    private function firstNonEmptyStringList(array ...$candidates): array
    {
        foreach ($candidates as $candidate) {
            $list = $this->stringList($candidate);
            if ($list !== []) {
                return $list;
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  array<string,mixed>  $normalizedScope
     * @return list<string>
     */
    private function scopeBlockers(array $allowedFiles, array $normalizedScope): array
    {
        $blockers = [];
        if ($allowedFiles === []) {
            $blockers[] = 'allowed_files_empty';
        }
        if ($this->stringList((array) ($normalizedScope['forbidden_in_allowed'] ?? [])) !== []) {
            $blockers[] = 'forbidden_files_overlap_allowed_files';
        }
        if ($this->stringList((array) ($normalizedScope['forbidden_axis_hits'] ?? [])) !== []) {
            $blockers[] = 'forbidden_axis_hits_allowed_files';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function computeHash(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['one_shot_packet_hash']);
        $this->sortRecursive($copy);
        $json = json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $json);
    }

    private function sortRecursive(mixed &$node): void
    {
        if (! is_array($node)) {
            return;
        }
        ksort($node);
        foreach ($node as &$v) {
            $this->sortRecursive($v);
        }
    }
}
