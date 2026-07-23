<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * GOD-DEBULK extracted stateful packet-lifecycle family from AtlasSelfConstructionReadinessService (receipt preview, external blockers, implementation packet, scope validator, runbook, claim, claim-next, codex start, launch plan, execution status).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionPacketLifecycleSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionPacketLifecycleSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function receiptPreview(array $options = []): array
    {
        $packet = $this->metaSddPacket($options);
        $target = data_get($packet, 'meta_spec.target_capability');

        return [
            'schema_version' => 'atlas.self_construction_receipt_preview.v1',
            'status' => data_get($packet, 'status') === 'candidate_ready' ? 'preview_ready' : 'blocked',
            'mode' => 'receipt_preview_only',
            'execution_allowed' => false,
            'receipt_preview' => [
                'id' => 'DR-PREVIEW-SELF-CONSTRUCTION-PHASE-4',
                'operation_id' => 'OP-PREVIEW-SELF-CONSTRUCTION',
                'meta_spec_id' => data_get($packet, 'meta_spec.id'),
                'target_capability' => $target,
                'signed_by' => 'atlas_kernel_preview_only',
                'autonomy_level' => 'L0_preview_only',
                'risk_level' => data_get($packet, 'meta_spec.risk_level'),
                'decision' => [
                    'action' => 'prepare_receipt_scoped_task_plan',
                    'rationale' => 'Prepare a safe execution envelope for future human/agent review without executing writes.',
                    'selected_runtime' => 'laravel_cli_read_only',
                    'selected_agents' => [
                        'Context Scout',
                        'Spec Critic',
                        'Architecture Agent',
                        'QA Agent',
                        'Evidence Agent',
                    ],
                ],
                'scope' => [
                    'allowed_actions' => [
                        'read_canonical_docs',
                        'generate_candidate_meta_sdd',
                        'generate_receipt_preview',
                        'run_validation_commands',
                    ],
                    'forbidden_actions' => [
                        'apply_patch_without_signed_receipt',
                        'auto_merge',
                        'change_autonomy_policy',
                        'enable_self_programming_writes',
                        'modify_provider_policy',
                        'modify_memory_privacy_policy',
                    ],
                    'allowed_files' => $this->receiptAllowedFiles(),
                    'forbidden_files' => [
                        'app/Services/Ai/Kernel/**',
                        'app/Services/Ai/Memory/**',
                        'config/**',
                        'routes/api.php',
                        'database/migrations/**',
                        'runtimes/python/voice_realtime/**',
                    ],
                    'allowed_commands' => data_get($packet, 'required_gates'),
                    'forbidden_commands' => [
                        'git reset --hard',
                        'git checkout --',
                        'php artisan migrate',
                        'composer require',
                        'npm install',
                    ],
                ],
                'tasks' => data_get($packet, 'tasks'),
                'gates' => [
                    'required' => data_get($packet, 'required_gates'),
                    'blocking_failures' => [
                        'focused_test_failure',
                        'docs_health_violation',
                        'architecture_validate_failure',
                        'diff_check_failure',
                    ],
                ],
                'rollback' => [
                    'strategy' => 'revert only files listed in allowed_files for this preview scope',
                    'restore_points' => [
                        'git diff before scoped implementation',
                        'test output before scoped implementation',
                    ],
                ],
                'evidence' => [
                    'required_events' => [
                        'meta_sdd_packet',
                        'receipt_preview',
                        'focused_test_output',
                        'docs_health_output',
                        'architecture_validate_output',
                        'diff_check_output',
                    ],
                    'append_only' => true,
                ],
            ],
            'human_summary' => 'Receipt preview is ready for review. It does not sign execution, apply patches, run migrations or enable self-programming writes.',
        ];
    }

    public function externalBlockers(array $options = []): array
    {
        $surfaceMatrix = $this->surfaceMatrix($options);
        $phaseLedger = $this->phaseLedger($options);

        $blockers = [
            [
                'id' => 'voice_realtime_surface_doc_delta_hot',
                'scope' => 'hot_voice_realtime_documentation',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
                'cause' => 'Voice Realtime owner documentation is active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit this hot owner doc; Codex principal should keep AP-179/AP-185 language and docs-health green.',
            ],
            [
                'id' => 'voice_realtime_runtime_delta_hot',
                'scope' => 'hot_voice_realtime_runtime',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'runtimes/python/voice_realtime/**',
                'cause' => 'Voice/LiveKit/Product Loop runtime files are active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit these files; report blockers and keep cold lane separate.',
            ],
            [
                'id' => 'voice_realtime_ap687_runtime_entrypoint_test_gap',
                'scope' => 'hot_voice_realtime_runtime_tests',
                'status' => 'reported_not_edited',
                'severity' => 'blocks_architecture_validate',
                'path' => 'runtimes/python/voice_realtime/tests/test_livekit_runtime_entrypoint.py',
                'cause' => 'AP-687 requires worker start production promotion guardrail coverage in the hot runtime entrypoint test, currently including test_start_worker_still_blocks_after_explicit_human_review_until_daemon_is_committed.',
                'recommended_action' => 'Codex principal should add or restore the AP-687 runtime entrypoint guardrail test in the hot Voice/LiveKit lane.',
            ],
            [
                'id' => 'voice_realtime_php_delta_hot',
                'scope' => 'hot_voice_realtime_php_surface',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'app/Services/Ai/Voice/**',
                'cause' => 'Voice Realtime PHP service/certification files are active in the working tree.',
                'recommended_action' => 'Self-Construction work must not edit the Voice PHP surface; Codex principal owns this runtime promotion lane.',
            ],
            [
                'id' => 'kernel_scanner_delta_hot',
                'scope' => 'hot_kernel_static_scanner',
                'status' => 'reported_not_edited',
                'severity' => 'ownership_boundary',
                'path' => 'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'cause' => 'Kernel scanner is active in the working tree.',
                'recommended_action' => 'Do not edit from Self-Construction cold lane; let Codex principal own scanner changes.',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_external_blockers.v1',
            'status' => 'external_blockers_reported',
            'mode' => 'read_only_external_blockers',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'self_construction_surface_status' => data_get($surfaceMatrix, 'status'),
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'cold_lane_allowed_files' => $this->receiptAllowedFiles(),
            'policy' => [
                'hot_files_must_not_be_edited' => true,
                'external_blockers_must_be_reported' => true,
                'self_construction_completion_must_not_depend_on_hot_edits' => true,
            ],
            'non_execution_guarantees' => [
                'external_blockers_does_not_edit_hot_files',
                'external_blockers_does_not_apply_patch',
                'external_blockers_does_not_mark_completion',
                'external_blockers_does_not_enable_execution',
            ],
            'human_summary' => 'External blockers are reported without editing hot Voice, Product Loop or Kernel scanner files.',
        ];
    }

    public function implementationPacket(array $options = []): array
    {
        $target = ($options['target'] ?? null) ?: 'ai_implementation_packet_runtime_read_only';
        $docs = $this->snapshot($options);

        $packet = [
            'schema_version' => '1.0',
            'packet_id' => 'AIP-SELF-CONSTRUCTION-READ-ONLY-0001',
            'operation_id' => 'OP-SELF-CONSTRUCTION-AI-PACKET-0001',
            'status' => 'available',
            'lane' => 'self_construction',
            'objective' => 'Implement only the next read-only Self-Construction packet surface and validation evidence.',
            'target_capability' => $target,
            'rationale' => 'AI Implementation Packet is the first surface needed for one-line multi-agent continuation.',
            'priority' => 100,
            'risk_level' => 'medium',
            'execution_allowed' => false,
            'requires_human_signature' => true,
            'context_docs' => [
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md',
                'docs/engineering-knowledge-base/self-construction/constitution.md',
                'docs/engineering-knowledge-base/self-construction/structural-contract-gate.md',
                'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                'docs/ap/AP-691-atlas-self-construction-os-contract.md',
            ],
            'allowed_files' => [
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md',
                'docs/engineering-knowledge-base/self-construction/work-splitter-contract.md',
                'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                'docs/ap/AP-691-atlas-self-construction-os-contract.md',
            ],
            'forbidden_files' => $this->hotForbiddenFiles(),
            'forbidden_actions' => [
                'auto_merge',
                'sign_receipt',
                'enable_execution',
                'modify_voice_runtime',
                'modify_kernel_scanner',
                'run_migrations',
                'change_provider_policy',
            ],
            'acceptance_criteria' => [
                'packet exposes objective, allowed files, forbidden files, gates and evidence',
                'work splitter emits disjoint packets without hot scopes',
                'scope validator reports changed files as allowed, forbidden, unknown or hot_external',
                'all new surfaces remain read-only with execution_allowed=false',
            ],
            'required_gates' => [
                'php artisan atlas:ai:self-construction --implementation-packet --json',
                'php artisan atlas:ai:self-construction --work-splitter --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'required_evidence' => [
                'packet_hash',
                'split_hash',
                'validator_hash',
                'focused_test_output',
                'docs_health_output',
                'architecture_validate_output',
                'git_diff_check_output',
            ],
            'rollback_policy' => 'Revert only files listed in allowed_files and never revert external hot work.',
            'dependencies' => [
                'structural_contract_gate_complete',
                'ai_implementation_packet_contract_complete',
                'work_splitter_contract_complete',
                'scope_validator_contract_complete',
            ],
            'stop_conditions' => [
                'forbidden_file_changed',
                'unknown_file_changed',
                'hot_external_file_changed_by_packet_owner',
                'required_gate_failed',
                'human_signature_required_for_execution',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_ai_implementation_packet.v1',
            'status' => data_get($docs, 'summary.missing_doc_count') === 0 ? 'packet_ready' : 'blocked_missing_docs',
            'mode' => 'read_only_ai_implementation_packet',
            'execution_allowed' => false,
            'packet' => $packet,
            'packet_hash' => $this->stableHash($packet),
            'non_execution_guarantees' => [
                'implementation_packet_does_not_apply_patch',
                'implementation_packet_does_not_claim_assignment',
                'implementation_packet_does_not_sign_receipt',
                'implementation_packet_does_not_enable_execution',
            ],
            'human_summary' => 'AI Implementation Packet is ready as a read-only work contract. It can guide another AI but cannot execute, sign or merge.',
        ];
    }

    public function scopeValidator(array $options = []): array
    {
        $packetPayload = $this->implementationPacket($options);
        $selectedPacket = $this->selectedSplitPacket($options);
        $packet = $selectedPacket ?? (array) data_get($packetPayload, 'packet', []);
        $changedFiles = $this->changedFiles();
        $allowed = (array) data_get($packet, 'allowed_files', []);
        $forbidden = (array) data_get($packet, 'forbidden_files', []);

        $files = array_map(function (string $path) use ($allowed, $forbidden): array {
            $classification = $this->classifyPath($path, $allowed, $forbidden);

            return [
                'path' => $path,
                'classification' => $classification,
                'blocking' => in_array($classification, ['forbidden', 'unknown', 'hot_external'], true),
                'reason' => match ($classification) {
                    'allowed' => 'Path is inside implementation packet allowed files.',
                    'hot_external' => 'Path belongs to hot external scope and is not owned by this packet.',
                    'forbidden' => 'Path matches packet forbidden scope.',
                    default => 'Path is not declared by the packet scope.',
                },
            ];
        }, $changedFiles);

        $blocking = array_values(array_filter($files, fn (array $file): bool => (bool) $file['blocking']));

        $validator = [
            'packet_id' => data_get($packet, 'packet_id'),
            'requested_packet_id' => $options['packet'] ?? null,
            'packet_scope_source' => $selectedPacket === null ? 'implementation_packet' : 'work_splitter_packet',
            'changed_files' => $files,
            'blocking_violations' => $blocking,
            'required_next_action' => $blocking === [] ? 'continue' : 'stop_or_request_review',
        ];

        return [
            'schema_version' => 'atlas.self_construction_scope_validator.v1',
            'status' => $blocking === [] ? 'pass' : 'blocked',
            'mode' => 'read_only_scope_validator',
            'execution_allowed' => false,
            'summary' => [
                'changed_count' => count($files),
                'allowed_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'allowed')),
                'blocking_count' => count($blocking),
                'hot_external_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'hot_external')),
                'unknown_count' => count(array_filter($files, fn (array $file): bool => $file['classification'] === 'unknown')),
            ],
            'validator' => $validator,
            'validator_hash' => $this->stableHash($validator),
            'non_execution_guarantees' => [
                'scope_validator_does_not_apply_patch',
                'scope_validator_does_not_revert_files',
                'scope_validator_does_not_sign_receipt',
                'scope_validator_does_not_enable_execution',
            ],
            'human_summary' => $blocking === []
                ? 'Scope Validator passed for the selected packet scope.'
                : 'Scope Validator found blocking files outside the selected packet scope; stop or request review.',
        ];
    }

    public function packetRunbook(array $options = []): array
    {
        $assignmentPayload = $this->assignmentPreview($options);
        $assignment = (array) data_get($assignmentPayload, 'assignment', []);

        $runbook = [
            'schema_version' => 'atlas.self_construction_packet_consumption_runbook.v1',
            'runbook_id' => 'RUNBOOK-SELF-CONSTRUCTION-PACKET-0001',
            'assignment_id' => data_get($assignment, 'assignment_id'),
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'steps' => [
                ['order' => 1, 'id' => 'inspect_worktree', 'command' => 'git status --short && git diff --stat && git diff --name-only'],
                ['order' => 2, 'id' => 'read_assignment', 'command' => $this->packetCommand('assignment-preview', data_get($assignment, 'selected_packet_id'))],
                ['order' => 3, 'id' => 'read_packet', 'command' => 'php artisan atlas:ai:self-construction --implementation-packet --json'],
                ['order' => 4, 'id' => 'confirm_scope', 'command' => $this->packetCommand('scope-validator', data_get($assignment, 'selected_packet_id'))],
                ['order' => 5, 'id' => 'run_focused_tests', 'command' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php'],
                ['order' => 6, 'id' => 'run_docs_health', 'command' => 'php artisan atlas:engineering:knowledge docs-health --json'],
                ['order' => 7, 'id' => 'run_architecture_validate', 'command' => 'php artisan atlas:ai:architecture-validate --json'],
                ['order' => 8, 'id' => 'run_diff_check', 'command' => 'git diff --check'],
                ['order' => 9, 'id' => 'return_evidence', 'command' => null],
            ],
            'required_gates' => [
                $this->packetCommand('assignment-preview', data_get($assignment, 'selected_packet_id')),
                $this->packetCommand('scope-validator', data_get($assignment, 'selected_packet_id')),
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'required_evidence' => [
                'selected_packet_id',
                'assignment_hash',
                'files_changed_by_this_ai',
                'focused_test_output',
                'docs_health_output',
                'architecture_validate_output',
                'scope_validator_output',
                'git_diff_check_output',
                'residual_risk',
            ],
            'stop_conditions' => (array) data_get($assignment, 'stop_conditions', []),
            'final_response_contract' => [
                'state_packet_id',
                'state_changed_files',
                'state_gates_run',
                'state_scope_validator_status',
                'state_external_blockers',
                'state_remaining_blocks',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_consumption_runbook.v1',
            'status' => data_get($assignmentPayload, 'status') === 'claim_preview_ready' ? 'runbook_ready' : 'blocked_by_assignment',
            'mode' => 'read_only_packet_consumption_runbook',
            'execution_allowed' => false,
            'runbook' => $runbook,
            'runbook_hash' => $this->stableHash($runbook),
            'non_execution_guarantees' => [
                'packet_runbook_does_not_persist_claim',
                'packet_runbook_does_not_apply_patch',
                'packet_runbook_does_not_sign_receipt',
                'packet_runbook_does_not_enable_execution',
            ],
            'human_summary' => 'Packet runbook is ready: one AI can follow ordered steps and return evidence without persisted claim or execution authority.',
        ];
    }

    public function claimPacket(array $options = []): array
    {
        $packet = $this->selectedSplitPacket($options);
        $actor = $this->reservationActor($options);
        $session = $this->reservationSession($options);
        $leaseMinutes = max(1, (int) ($options['lease_minutes'] ?? 120));

        if ($packet === null) {
            return [
                'schema_version' => 'atlas.self_construction_claim_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_packet_claim',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => true,
                'dispatch_allowed' => false,
                'claim' => [
                    'packet_id' => $options['packet'] ?? null,
                    'blocking_reasons' => ['packet_not_found_or_missing'],
                ],
                'claim_hash' => $this->stableHash(['packet_id' => $options['packet'] ?? null, 'blocking_reasons' => ['packet_not_found_or_missing']]),
                'human_summary' => 'Packet claim is blocked because the requested Work Splitter packet was not found.',
            ];
        }

        $claim = $this->reservations->claim(
            packet: $packet,
            actor: $actor,
            session: $session,
            leaseMinutes: $leaseMinutes,
            packetHash: $this->stableHash($packet),
        );

        return [
            'schema_version' => 'atlas.self_construction_claim_packet.v1',
            'status' => data_get($claim, 'status') === 'claimed' ? 'claimed' : 'blocked',
            'mode' => 'durable_local_packet_claim',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => data_get($claim, 'status') === 'claimed',
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'claim' => [
                ...$claim,
                'packet_id' => data_get($packet, 'packet_id'),
                'actor' => $actor,
                'session' => $session,
                'lease_minutes' => $leaseMinutes,
            ],
            'claim_hash' => $this->stableHash($claim),
            'human_summary' => data_get($claim, 'status') === 'claimed'
                ? 'Packet was durably claimed in the local reservation ledger. The AI may work only inside this packet scope.'
                : 'Packet claim was blocked by the reservation ledger. The AI must choose another packet or wait.',
        ];
    }

    public function claimNextPacket(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $selected = collect((array) data_get($queuePayload, 'queue.entries', []))
            ->first(fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'
                && data_get($entry, 'collision_risk') !== 'blocked');

        if (! is_array($selected)) {
            $start = [
                'selected_packet_id' => null,
                'queue_hash' => data_get($queuePayload, 'queue_hash'),
                'blocking_reasons' => ['no_available_packet'],
                'available_count' => data_get($queuePayload, 'queue.available_count'),
                'claimed_count' => data_get($queuePayload, 'queue.claimed_count'),
                'withheld_count' => data_get($queuePayload, 'queue.withheld_count'),
            ];

            return [
                'schema_version' => 'atlas.self_construction_claim_next_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_claim_next_packet',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => true,
                'dispatch_allowed' => false,
                'packet_id' => null,
                'start' => $start,
                'start_hash' => $this->stableHash($start),
                'human_summary' => 'Claim-next is blocked because no available packet remains in the durable queue.',
            ];
        }

        $packetId = (string) data_get($selected, 'packet_id');
        $scopedOptions = [
            ...$options,
            'packet' => $packetId,
        ];

        $claimPayload = $this->claimPacket($scopedOptions);
        $bootstrapPayload = $this->aiSessionBootstrap($scopedOptions);
        $instructionPayload = $this->singleSessionInstructionPacket($scopedOptions);
        $scopePayload = $this->scopeValidator($scopedOptions);

        $start = [
            'packet_id' => $packetId,
            'lane' => data_get($selected, 'lane'),
            'objective' => data_get($selected, 'objective'),
            'actor' => $this->reservationActor($options),
            'session' => $this->reservationSession($options),
            'claim_status' => data_get($claimPayload, 'status'),
            'claim_hash' => data_get($claimPayload, 'claim_hash'),
            'bootstrap_hash' => data_get($bootstrapPayload, 'bootstrap_hash'),
            'instruction_hash' => data_get($instructionPayload, 'instruction_hash'),
            'scope_validator_hash' => data_get($scopePayload, 'validator_hash'),
            'allowed_files' => (array) data_get($selected, 'allowed_files', []),
            'forbidden_files' => (array) data_get($selected, 'forbidden_files', []),
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $this->packetCommand('ai-session-bootstrap', $packetId),
                $this->packetCommand('scope-validator', $packetId),
            ],
            'required_gates' => (array) data_get($instructionPayload, 'instruction.required_gates', []),
            'required_evidence' => (array) data_get($instructionPayload, 'instruction.required_evidence', []),
            'one_line_prompt_for_codex' => 'Continue Self-Construction using your claimed packet. Run the packet-scoped bootstrap and scope validator, touch only allowed files, and stop on any blocker.',
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($claimPayload, 'claim.blocking_reasons', []),
                (array) data_get($instructionPayload, 'instruction.stop_conditions', []),
                ['scope_validator_blocked', 'required_gate_failed']
            ))),
        ];

        return [
            'schema_version' => 'atlas.self_construction_claim_next_packet.v1',
            'status' => data_get($claimPayload, 'status') === 'claimed' ? 'claimed' : 'blocked',
            'mode' => 'durable_local_claim_next_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => (bool) data_get($claimPayload, 'claim_persisted'),
            'ledger_write_allowed' => true,
            'dispatch_allowed' => false,
            'packet_id' => $packetId,
            'claim' => data_get($claimPayload, 'claim'),
            'bootstrap' => data_get($bootstrapPayload, 'bootstrap'),
            'instruction' => data_get($instructionPayload, 'instruction'),
            'start' => $start,
            'start_hash' => $this->stableHash($start),
            'human_summary' => data_get($claimPayload, 'status') === 'claimed'
                ? 'Next packet was durably claimed and packet-scoped bootstrap instructions are ready for this Codex session.'
                : 'Claim-next selected a packet but the durable claim was blocked; refresh the queue before continuing.',
        ];
    }

    public function codexStartPacket(array $options = []): array
    {
        $claimNext = $this->claimNextPacket($options);
        $packetId = data_get($claimNext, 'packet_id');

        if (! is_string($packetId) || $packetId === '') {
            $contract = [
                'status' => 'blocked',
                'blocking_reasons' => (array) data_get($claimNext, 'start.blocking_reasons', ['no_available_packet']),
                'queue_state' => [
                    'available_count' => data_get($claimNext, 'start.available_count'),
                    'claimed_count' => data_get($claimNext, 'start.claimed_count'),
                    'withheld_count' => data_get($claimNext, 'start.withheld_count'),
                ],
            ];

            return [
                'schema_version' => 'atlas.self_construction_codex_start_packet.v1',
                'status' => 'blocked',
                'mode' => 'durable_local_codex_start_packet',
                'execution_allowed' => false,
                'completion_allowed' => false,
                'claim_persisted' => false,
                'dispatch_allowed' => false,
                'packet_id' => null,
                'contract' => $contract,
                'contract_hash' => $this->stableHash($contract),
                'human_summary' => 'Codex start packet is blocked because no available Self-Construction packet remains.',
            ];
        }

        $contract = [
            'contract_id' => 'CODEX-START-SELF-CONSTRUCTION-0001',
            'packet_id' => $packetId,
            'actor' => $this->reservationActor($options),
            'session' => $this->reservationSession($options),
            'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
            'operator_prompt' => 'Continue Atlas Self-Construction using this durably claimed packet. Read the scoped bootstrap first, validate scope before and after edits, touch only allowed files, run required gates, report evidence, and stop on any blocker.',
            'mission' => 'Implement exactly the claimed Self-Construction packet, preserve governance, run required gates, report evidence, and stop on scope blockers.',
            'claim' => [
                'persisted' => (bool) data_get($claimNext, 'claim_persisted'),
                'reservation_id' => data_get($claimNext, 'claim.reservation.reservation_id'),
                'lease_expires_at' => data_get($claimNext, 'claim.reservation.lease_expires_at'),
                'claim_hash' => data_get($claimNext, 'start.claim_hash'),
            ],
            'scope' => [
                'allowed_files' => (array) data_get($claimNext, 'start.allowed_files', []),
                'forbidden_files' => (array) data_get($claimNext, 'start.forbidden_files', []),
                'hot_scopes' => $this->hotForbiddenFiles(),
            ],
            'bootstrap_command' => $this->packetCommand('ai-session-bootstrap', $packetId),
            'scope_validator_command' => $this->packetCommand('scope-validator', $packetId),
            'required_first_commands' => (array) data_get($claimNext, 'start.required_first_commands', []),
            'required_gates' => (array) data_get($claimNext, 'start.required_gates', []),
            'required_evidence' => array_values(array_unique(array_merge(
                (array) data_get($claimNext, 'start.required_evidence', []),
                [
                    'claimed_packet_id',
                    'reservation_id',
                    'changed_files_by_this_session',
                    'scope_validator_output',
                    'focused_test_output',
                    'docs_health_output',
                    'architecture_validate_output',
                    'git_diff_check_output',
                ]
            ))),
            'implementation_rules' => [
                'touch_only_allowed_files',
                'do_not_edit_voice_or_kernel_hot_scopes',
                'do_not_revert_user_or_other_session_changes',
                'prefer_small_scoped_patch',
                'update_docs_when_contract_changes',
                'add_or_update_focused_tests_for_runtime_changes',
                'stop_if_scope_validator_blocks',
            ],
            'final_response_contract' => [
                'state_packet_id',
                'state_reservation_id',
                'state_files_changed_by_this_session',
                'state_gates_run_with_results',
                'state_scope_validator_status',
                'state_evidence_paths_or_outputs',
                'state_remaining_blockers',
                'state_next_recommended_packet_or_action',
            ],
            'release_command' => 'php artisan atlas:ai:self-construction --release-packet --packet='.$packetId
                .' --actor='.$this->reservationActor($options)
                .' --session='.$this->reservationSession($options)
                .' --reason=finished_or_blocked --json',
            'completion_command' => 'php artisan atlas:ai:self-construction --complete-packet --packet='.$packetId
                .' --actor='.$this->reservationActor($options)
                .' --session='.$this->reservationSession($options)
                .' --reason=packet_scope_finished --evidence-hash=<sha256-of-final-evidence> --json',
            'stop_conditions' => (array) data_get($claimNext, 'start.stop_conditions', []),
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_start_packet.v1',
            'status' => 'codex_start_packet_ready',
            'mode' => 'durable_local_codex_start_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => (bool) data_get($claimNext, 'claim_persisted'),
            'dispatch_allowed' => false,
            'packet_id' => $packetId,
            'claim_next_hash' => data_get($claimNext, 'start_hash'),
            'contract' => $contract,
            'contract_hash' => $this->stableHash($contract),
            'human_summary' => 'Codex start packet is ready: a packet is durably claimed and the session has scope, gates, evidence and final response contract.',
        ];
    }

    public function agentLaunchPlan(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $gatePayload = $this->multiSessionReadinessGate($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $launchable = array_slice($available, 0, 5);

        $sessions = array_map(function (array $entry, int $index): array {
            $slot = $index + 1;
            $lane = (string) data_get($entry, 'lane', 'implementation');
            $provider = $this->recommendedProviderForLane($lane);
            $actor = sprintf('%s-%d', $provider, $slot);
            $session = sprintf('self-construction-agent-session-%d', $slot);
            $role = $this->providerRoleForActor($actor);

            return [
                'slot_id' => sprintf('AGENT-LAUNCH-SLOT-%03d', $slot),
                'state' => 'ready_to_start',
                'expected_packet_id' => data_get($entry, 'packet_id'),
                'lane' => $lane,
                'actor' => $actor,
                'session' => $session,
                'recommended_provider' => $role['provider'],
                'recommended_role' => $role['role'],
                'receives' => $role['receives'],
                'command' => 'php artisan atlas:ai:self-construction --agent-start-packet'
                    .' --actor='.$actor
                    .' --session='.$session
                    .' --json',
                'codex_only_fallback_command' => 'php artisan atlas:ai:self-construction --agent-start-packet'
                    .' --actor=codex-'.$slot
                    .' --session='.$session
                    .' --json',
                'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
                'operator_instruction' => 'Open one AI session for this slot, run the command, follow the returned Forge Workspace contract, complete or release the claimed packet, and return artifacts to the workspace.',
            ];
        }, $launchable, array_keys($launchable));

        $plan = [
            'plan_id' => 'AGENT-LAUNCH-PLAN-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'max_sessions' => 5,
            'launchable_count' => count($sessions),
            'available_count' => data_get($queuePayload, 'queue.available_count'),
            'claimed_count' => data_get($queuePayload, 'queue.claimed_count'),
            'completed_count' => data_get($queuePayload, 'queue.completed_count'),
            'withheld_count' => data_get($queuePayload, 'queue.withheld_count'),
            'parallel_preview_allowed' => (bool) data_get($gatePayload, 'parallel_preview_allowed'),
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'sessions' => $sessions,
            'operator_sequence' => [
                'run_agent_launch_plan_once',
                'open_one_fresh_ai_session_per_launch_slot',
                'paste_the_slot_agent_start_command_in_each_session',
                'each_session_follows_its_returned_forge_workspace_contract',
                'each_session_returns_structured_artifacts_to_workspace',
                'each_session_runs_scope_validator_before_and_after_edits',
                'multi_session_readiness_gate_does_not_enable_dispatch',
            ],
            'codex_only_mode' => [
                'supported' => true,
                'instruction' => 'If this month only Codex is trusted, use each codex_only_fallback_command instead of the mixed-provider actor command.',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_launch_plan.v1',
            'status' => 'agent_launch_plan_ready',
            'mode' => 'read_only_provider_neutral_agent_launch_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'human_summary' => 'Agent launch plan is ready: the Forge Workspace can give multiple AI sessions scoped start commands without claims, dispatch or execution.',
        ];
    }

    public function agentExecutionStatus(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $reservationPayload = $this->reservationStatus($options);
        $launchPlanPayload = $this->agentLaunchPlan($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));

        $claimedSessions = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'active_reservation_actor'),
            'session' => data_get($entry, 'active_reservation_session'),
            'provider' => data_get($this->providerRoleForActor((string) data_get($entry, 'active_reservation_actor', 'generic')), 'provider'),
            'role' => data_get($this->providerRoleForActor((string) data_get($entry, 'active_reservation_actor', 'generic')), 'role'),
            'reservation_id' => data_get($entry, 'active_reservation_id'),
            'lease_expires_at' => data_get($entry, 'lease_expires_at'),
            'next_expected_action' => 'return_workspace_artifacts_then_complete_or_release_packet',
        ], $claimed);

        $completedPackets = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'completion_actor'),
            'provider' => data_get($this->providerRoleForActor((string) data_get($entry, 'completion_actor', 'generic')), 'provider'),
            'reservation_id' => data_get($entry, 'completed_reservation_id'),
            'completed_at' => data_get($entry, 'completed_at'),
        ], $completed);

        $monitor = [
            'monitor_id' => 'AGENT-EXECUTION-STATUS-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_reservation_hash' => data_get($reservationPayload, 'ledger_hash'),
            'source_launch_plan_hash' => data_get($launchPlanPayload, 'plan_hash'),
            'counts' => [
                'available' => count($available),
                'claimed' => count($claimed),
                'completed' => count($completed),
                'blocked' => count($blocked),
                'withheld' => count($withheld),
                'launchable' => data_get($launchPlanPayload, 'plan.launchable_count'),
                'ledger_events' => data_get($reservationPayload, 'ledger.event_count'),
            ],
            'claimed_sessions' => $claimedSessions,
            'completed_packets' => $completedPackets,
            'next_launch_commands' => array_values(array_map(
                fn (array $session): string => (string) data_get($session, 'command'),
                (array) data_get($launchPlanPayload, 'plan.sessions', [])
            )),
            'codex_only_fallback_commands' => array_values(array_map(
                fn (array $session): string => (string) data_get($session, 'codex_only_fallback_command'),
                (array) data_get($launchPlanPayload, 'plan.sessions', [])
            )),
            'recommended_next_action' => match (true) {
                count($claimed) > 0 => 'wait_for_active_agents_or_review_their_final_response_contracts',
                count($available) > 0 => 'launch_available_forge_workspace_agents',
                count($blocked) > 0 => 'review_dependency_unlock_plan',
                default => 'review_completed_packets_and_external_hot_work',
            },
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --agent-execution-status --json',
                'launch_plan' => 'php artisan atlas:ai:self-construction --agent-launch-plan --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'reservation_status' => 'php artisan atlas:ai:self-construction --reservation-status --json',
                'codex_compat_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_execution_status.v1',
            'status' => 'agent_execution_status_ready',
            'mode' => 'read_only_provider_neutral_agent_execution_status',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'monitor' => $monitor,
            'monitor_hash' => $this->stableHash($monitor),
            'non_execution_guarantees' => [
                'agent_execution_status_does_not_claim_packets',
                'agent_execution_status_does_not_complete_packets',
                'agent_execution_status_does_not_start_sessions',
                'agent_execution_status_does_not_dispatch_work',
            ],
            'human_summary' => 'Agent execution status is ready: Forge Workspace active, completed and available packet state is visible without mutating reservations or dispatching work.',
        ];
    }

}
