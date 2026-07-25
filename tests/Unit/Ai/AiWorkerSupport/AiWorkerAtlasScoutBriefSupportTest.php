<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AiWorkerSupport;

use App\Services\Ai\AiWorkerSupport\AiWorkerAtlasScoutBriefSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerMacBackgroundReadinessSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerPendingSteerPromptSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerProgrammingRepairContractsSupport;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure Support peels for AiWorker scout brief / pending-steer / repair contracts — no I/O, no DB.
 */
final class AiWorkerAtlasScoutBriefSupportTest extends TestCase
{
    #[Test]
    public function success_brief_embeds_provider_model_job_and_output(): void
    {
        $text = AiWorkerAtlasScoutBriefSupport::successBrief('claude_cli', 'sonnet', 'job-42', "found X\n");

        $this->assertStringContainsString('Atlas Decide context scout concluido.', $text);
        $this->assertStringContainsString('provider: claude_cli', $text);
        $this->assertStringContainsString('model: sonnet', $text);
        $this->assertStringContainsString('job_id: job-42', $text);
        $this->assertStringContainsString('found X', $text);
        $this->assertSame($text, trim($text));
    }

    #[Test]
    public function failure_brief_defaults_unknown_and_error_fields(): void
    {
        $text = AiWorkerAtlasScoutBriefSupport::failureBrief(null, null, null, null, null);

        $this->assertStringContainsString('Atlas Decide context scout degradado.', $text);
        $this->assertStringContainsString('provider: unknown', $text);
        $this->assertStringContainsString('model: unknown', $text);
        $this->assertStringContainsString('job_id: unknown', $text);
        $this->assertStringContainsString('error_code: scout_unavailable', $text);
        $this->assertStringContainsString('Scout de contexto indisponivel', $text);
        $this->assertStringContainsString('Siga com o contexto original.', $text);
    }

    #[Test]
    public function failure_brief_uses_explicit_error_and_identity(): void
    {
        $text = AiWorkerAtlasScoutBriefSupport::failureBrief(
            'gemini',
            'flash',
            'scout-9',
            'dependency_timeout',
            'timed out',
        );

        $this->assertStringContainsString('provider: gemini', $text);
        $this->assertStringContainsString('model: flash', $text);
        $this->assertStringContainsString('job_id: scout-9', $text);
        $this->assertStringContainsString('error_code: dependency_timeout', $text);
        $this->assertStringContainsString('error_message: timed out', $text);
    }

    #[Test]
    public function prompt_with_brief_appends_section_and_limits_brief(): void
    {
        $prompt = "Do the work.\n";
        $out = AiWorkerAtlasScoutBriefSupport::promptWithBrief($prompt, "  brief body  ");

        $this->assertStringStartsWith("Do the work.", $out);
        $this->assertStringContainsString("# Atlas Decide Context Scout", $out);
        $this->assertStringContainsString('brief body', $out);
        $this->assertStringEndsWith("\n", $out);

        $huge = str_repeat('x', 25000);
        $limited = AiWorkerAtlasScoutBriefSupport::promptWithBrief('P', $huge);
        $this->assertStringContainsString('...', $limited);
        $this->assertLessThan(21000, strlen($limited));
    }

    #[Test]
    public function pending_steer_prompt_injection(): void
    {
        $out = AiWorkerPendingSteerPromptSupport::promptWithPendingSteer("base prompt\n", 'please also fix Y');

        $this->assertStringStartsWith('base prompt', $out);
        $this->assertStringContainsString('# Pedido adicional do operador', $out);
        $this->assertStringContainsString('[STEER] please also fix Y', $out);
    }

    #[Test]
    public function programming_repair_contracts_pure_helpers(): void
    {
        $this->assertSame(
            ['a' => 1],
            AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([[], null, ['a' => 1], ['b' => 2]]),
        );
        $this->assertSame([], AiWorkerProgrammingRepairContractsSupport::firstNonEmptyArray([null, []]));

        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::gateRequiresEvidence(['evidence_required' => true]));
        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::gateRequiresEvidence(['minimum_gate' => 'strict']));
        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::gateRequiresEvidence(['minimum_gate' => 'release']));
        $this->assertFalse(AiWorkerProgrammingRepairContractsSupport::gateRequiresEvidence(['minimum_gate' => 'soft']));

        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::allowsWorkspaceWrite([]));
        $this->assertFalse(AiWorkerProgrammingRepairContractsSupport::allowsWorkspaceWrite(['mode' => 'read_only']));
        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::allowsWorkspaceWrite(['mode' => 'workspace_write']));
        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::allowsWorkspaceWrite(['workspace_write' => true]));

        $this->assertTrue(AiWorkerProgrammingRepairContractsSupport::qualityWorsened('failed', 'passed'));
        $this->assertFalse(AiWorkerProgrammingRepairContractsSupport::qualityWorsened('passed', 'failed'));
        $this->assertFalse(AiWorkerProgrammingRepairContractsSupport::qualityWorsened('failed', null));
        $this->assertSame(4, AiWorkerProgrammingRepairContractsSupport::statusRank('passed'));
        $this->assertSame(0, AiWorkerProgrammingRepairContractsSupport::statusRank('weird'));

        $quality = [
            'status' => 'failed',
            'score' => 0.2,
            'decision' => 'repair',
            'diff_hash' => 'abc',
            'test_command' => 'phpunit',
            'tests' => ['status' => 'failed'],
            'lint' => ['status' => 'ok'],
            'typecheck' => ['status' => 'ok'],
        ];
        $ledger = AiWorkerProgrammingRepairContractsSupport::ledgerPayload($quality, ['extra' => 1]);
        $this->assertSame('failed', $ledger['quality_status']);
        $this->assertSame(0.2, $ledger['quality_score']);
        $this->assertSame(hash('sha256', 'phpunit'), $ledger['test_command_hash']);
        $this->assertSame(1, $ledger['extra']);
        $this->assertNotEmpty($ledger['evidence_hash']);

        $this->assertSame(FailureDomain::GateFailed, AiWorkerProgrammingRepairContractsSupport::failureDomain('failed'));
        $this->assertSame(FailureDomain::OutputInvalid, AiWorkerProgrammingRepairContractsSupport::failureDomain('passed'));

        $refs = AiWorkerProgrammingRepairContractsSupport::evidenceRefs('t1', 'j1', 'a1', $quality);
        $this->assertSame([
            'trace://t1',
            'ai-job://j1',
            'ai-attempt://a1',
            'diff-hash://abc',
        ], $refs);

        $noTrace = AiWorkerProgrammingRepairContractsSupport::evidenceRefs(null, 'j1', 'a1', []);
        $this->assertSame(['ai-job://j1', 'ai-attempt://a1'], $noTrace);
    }

    #[Test]
    public function mac_background_readiness_pure_helpers(): void
    {
        $this->assertTrue(AiWorkerMacBackgroundReadinessSupport::requiresReadiness('scheduled'));
        $this->assertTrue(AiWorkerMacBackgroundReadinessSupport::requiresReadiness('chat', [
            'atlas_workflow_mode' => 'background',
        ]));
        $this->assertTrue(AiWorkerMacBackgroundReadinessSupport::requiresReadiness('chat', [
            'app_surface' => 'atlas_cli_schedule',
        ]));
        $this->assertTrue(AiWorkerMacBackgroundReadinessSupport::requiresReadiness('chat', [
            'scheduled_task' => ['id' => 'st-1'],
        ]));
        $this->assertFalse(AiWorkerMacBackgroundReadinessSupport::requiresReadiness('chat', []));

        $this->assertNull(AiWorkerMacBackgroundReadinessSupport::deferProjection(
            ['ready_for_background_jobs' => true],
            '2026-07-24T00:00:00Z',
        ));

        $defer = AiWorkerMacBackgroundReadinessSupport::deferProjection(
            [
                'overall' => 'degraded',
                'ready_for_remote' => true,
                'ready_for_scheduled_wake' => false,
                'ready_for_background_jobs' => false,
                'power_ready_for_background_jobs' => false,
                'blockers' => ['power'],
                'warnings' => [],
            ],
            '2026-07-24T12:00:00Z',
            300,
        );
        $this->assertIsArray($defer);
        $this->assertSame('deferred', $defer['status']);
        $this->assertSame('mac_background_not_ready', $defer['reason']);
        $this->assertSame(300, $defer['retry_after_seconds']);
        $this->assertSame('2026-07-24T12:00:00Z', $defer['checked_at']);
        $this->assertSame(['power'], $defer['readiness']['blockers']);
    }
}
