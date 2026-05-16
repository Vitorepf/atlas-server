<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use PHPUnit\Framework\TestCase;

final class IntakeNormalizerTest extends TestCase
{
    private string $tmpWorkspace;

    protected function setUp(): void
    {
        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-intake-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace, 0o755, true);
        mkdir($this->tmpWorkspace.'/.git', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            'abcdef1234567890abcdef1234567890abcdef12',
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpWorkspace);
    }

    public function test_workspace_resolved_envelope_marks_write_allowed_for_clear_action_intent(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-fixed'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_desktop_ai',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );

        $this->assertTrue($envelope->preflight->workspaceResolved);
        $this->assertTrue($envelope->preflight->writeAllowed);
        $this->assertSame(IntakeNormalizer::PERMISSION_WRITE_ALLOWED, $envelope->preflight->permissionMode);
        $this->assertSame(IntakeNormalizer::CLARITY_HIGH, $envelope->intentClarityLevel);
        $this->assertSame('abcdef1234567890abcdef1234567890abcdef12', $envelope->gitState->headSha);
        $this->assertNotSame('', $envelope->envelopeHash);
    }

    public function test_missing_workspace_marks_plan_only_and_low_clarity(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-x'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: '/nonexistent/'.bin2hex(random_bytes(4)),
            rawIntent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );

        $this->assertFalse($envelope->preflight->workspaceResolved);
        $this->assertFalse($envelope->preflight->writeAllowed);
        $this->assertSame(IntakeNormalizer::PERMISSION_PLAN_ONLY, $envelope->preflight->permissionMode);
        $this->assertSame(IntakeNormalizer::CLARITY_LOW, $envelope->intentClarityLevel);
        $this->assertNull($envelope->gitState->headSha);
    }

    public function test_empty_intent_is_blocking_clarity(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-x'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: '   ',
        );

        $this->assertSame(IntakeNormalizer::CLARITY_BLOCKING, $envelope->intentClarityLevel);
        $this->assertFalse($envelope->preflight->writeAllowed);
    }

    public function test_surface_hints_populate_surface_context(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-x'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_desktop_ai',
            workspace: $this->tmpWorkspace,
            rawIntent: 'explique o fluxo do PromptRenderer',
            userConstraints: [],
            surfaceHints: [
                'thread_id' => 'thr_1',
                'conversation_id' => 'cnv_1',
                'composer_mode' => 'planner',
                'composer_task' => 'plan_only',
                'provider_choice' => 'auto',
            ],
        );

        $this->assertSame('thr_1', $envelope->surfaceContext->threadId);
        $this->assertSame('cnv_1', $envelope->surfaceContext->conversationId);
        $this->assertSame('planner', $envelope->surfaceContext->composerMode);
        $this->assertSame('plan_only', $envelope->surfaceContext->composerTask);
        $this->assertSame('auto', $envelope->surfaceContext->providerChoice);
    }

    public function test_flow_origin_router_and_command_intent_hint_propagate_to_envelope(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-router'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_desktop_ai',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/FooTest.php',
            surfaceHints: [
                'flow_origin' => 'atlas_ai_router',
                'command_intent' => 'dev',
            ],
        );

        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('atlas_ai_router', $envelope->flowOrigin);
        $this->assertSame('dev', $envelope->commandIntent);
    }

    public function test_missing_flow_origin_hint_falls_back_to_direct_with_null_command_intent(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-direct'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );

        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('direct', $envelope->flowOrigin);
        $this->assertNull($envelope->commandIntent);
    }

    public function test_unrecognised_flow_origin_hint_is_demoted_to_direct(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-bogus'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste',
            surfaceHints: ['flow_origin' => 'forged_router'],
        );

        $this->assertSame('direct', $envelope->flowOrigin, 'unknown origins must never inherit router trust');
    }

    public function test_command_intent_hint_does_not_alter_raw_or_normalized_intent(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-hint'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_desktop_ai',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/FooTest.php',
            surfaceHints: ['command_intent' => 'review'],
        );

        $this->assertSame('corrija o teste falhando em tests/Unit/FooTest.php', $envelope->rawIntent);
        $this->assertSame('corrija o teste falhando em tests/Unit/FooTest.php', $envelope->normalizedIntent);
        $this->assertSame('review', $envelope->commandIntent);
    }

    public function test_canonical_constraints_are_unique_and_trimmed(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-x'));
        $envelope = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando',
            userConstraints: ['  prefer rg  ', 'prefer rg', '', '  open_brain=off  '],
        );

        $this->assertSame(['prefer rg', 'open_brain=off'], $envelope->userConstraints);
    }

    public function test_envelope_hash_excludes_self_and_is_stable(): void
    {
        $normalizer = new IntakeNormalizer(new FrozenRunIdGenerator('dev-stable'));
        $first = $normalizer->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste em tests/Unit/FooTest.php',
        );

        $normalizer2 = new IntakeNormalizer(new FrozenRunIdGenerator('dev-stable'));
        $second = $normalizer2->normalize(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste em tests/Unit/FooTest.php',
        );

        $this->assertSame($first->envelopeHash, $second->envelopeHash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->envelopeHash);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->rmrf($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

/**
 * Deterministic RunIdGenerator for tests.
 */
final class FrozenRunIdGenerator extends RunIdGenerator
{
    public function __construct(private readonly string $fixedId) {}

    public function generate(): string
    {
        return $this->fixedId;
    }
}
