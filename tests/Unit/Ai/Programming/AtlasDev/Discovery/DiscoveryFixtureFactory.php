<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;

final class DiscoveryFixtureFactory
{
    public static function workspaceA(): string
    {
        return realpath(__DIR__.'/../../../../../Fixtures/AtlasDev/discovery/workspace_a')
            ?: __DIR__.'/../../../../../Fixtures/AtlasDev/discovery/workspace_a';
    }

    public static function envelope(array $overrides = []): OperationEnvelope
    {
        $defaults = [
            'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'surface_id' => 'atlas_desktop_ai',
            'product_surface' => 'atlas_ai_desktop_mac',
            'thread_id' => null,
            'conversation_id' => null,
            'composer_mode' => 'programming',
            'composer_task' => 'dev',
            'provider_choice' => 'auto',
            'workspace' => self::workspaceA(),
            'workspace_hash' => 'a1b2c3d4e5f60718192a2b3c4d5e6f70819293a4',
            'raw_intent' => 'corrigir teste em AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            'normalized_intent' => 'corrigir teste em AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            'user_constraints' => [],
            'intent_clarity_level' => 'high',
            'dirty_worktree_policy' => 'preserve_user_changes',
            'permission_mode' => 'write',
            'write_allowed' => true,
            'operator_explicit' => false,
            'workspace_resolved' => true,
        ];

        $values = array_merge($defaults, $overrides);

        return new OperationEnvelope(
            runId: (string) $values['run_id'],
            surfaceId: (string) $values['surface_id'],
            surfaceContext: new SurfaceContext(
                productSurface: (string) $values['product_surface'],
                threadId: $values['thread_id'],
                conversationId: $values['conversation_id'],
                composerMode: $values['composer_mode'],
                composerTask: $values['composer_task'],
                providerChoice: $values['provider_choice'],
            ),
            workspace: (string) $values['workspace'],
            workspaceHash: (string) $values['workspace_hash'],
            gitState: new GitState(
                headSha: 'f4e5d6c7b8a9101112131415161718191a1b1c1d',
                dirty: false,
                untrackedCount: 0,
                pendingChangesCount: 0,
            ),
            rawIntent: (string) $values['raw_intent'],
            normalizedIntent: (string) $values['normalized_intent'],
            userConstraints: array_values((array) $values['user_constraints']),
            intentClarityLevel: (string) $values['intent_clarity_level'],
            dirtyWorktreePolicy: (string) $values['dirty_worktree_policy'],
            preflight: new Preflight(
                workspaceResolved: (bool) $values['workspace_resolved'],
                permissionMode: (string) $values['permission_mode'],
                writeAllowed: (bool) $values['write_allowed'],
                operatorExplicit: (bool) $values['operator_explicit'],
            ),
            envelopeHash: 'fixture-envelope-hash',
        );
    }

    public static function compactSdd(array $overrides = []): CompactSdd
    {
        $defaults = [
            'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'envelope_hash' => 'fixture-envelope-hash',
            'intent_raw' => 'corrigir teste em AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            'intent_normalized' => 'corrigir teste em AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            'task_kind' => 'repair',
            'risk_level' => 'R2',
            'scope_mode' => 'compact',
            'mode' => 'repair',
            'doc_tiers_required' => [],
            'verification_profile' => 'php_laravel',
            'context_digest' => null,
            'escalation_triggers' => [],
            'budget' => [
                'max_chars' => 12000,
                'max_docs' => 4,
                'max_candidate_files' => 6,
                'max_plan_steps' => 4,
                'max_provider_calls' => 1,
                'max_repair_attempts' => 1,
            ],
            'compact_sdd_hash' => 'fixture-compact-sdd-hash',
        ];

        $values = array_merge($defaults, $overrides);
        $budget = array_merge($defaults['budget'], $overrides['budget'] ?? []);

        return new CompactSdd(
            runId: (string) $values['run_id'],
            envelopeHash: (string) $values['envelope_hash'],
            intentRaw: (string) $values['intent_raw'],
            intentNormalized: (string) $values['intent_normalized'],
            taskKind: (string) $values['task_kind'],
            riskLevel: (string) $values['risk_level'],
            scopeMode: (string) $values['scope_mode'],
            mode: (string) $values['mode'],
            contextBudget: new ContextBudget(
                maxChars: (int) $budget['max_chars'],
                maxDocs: (int) $budget['max_docs'],
                maxCandidateFiles: (int) $budget['max_candidate_files'],
                maxPlanSteps: (int) $budget['max_plan_steps'],
                maxProviderCalls: (int) $budget['max_provider_calls'],
                maxRepairAttempts: (int) $budget['max_repair_attempts'],
            ),
            docTiersRequired: array_values((array) $values['doc_tiers_required']),
            verificationProfile: (string) $values['verification_profile'],
            contextDigest: $values['context_digest'],
            escalationTriggers: array_values((array) $values['escalation_triggers']),
            miniSpecHash: null,
            taskContractHash: null,
            compactSddHash: (string) $values['compact_sdd_hash'],
        );
    }
}
