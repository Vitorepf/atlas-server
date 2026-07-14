<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Services\Ai\AiCouncilCoordinator;
use App\Services\Ai\AiWorker;
use PHPUnit\Framework\TestCase;

/**
 * C18 — o shortstat do git vira estatística verificável (nunca inventada).
 * C21 — cada membro do conselho deixa registro público com campos reais.
 */
class DiffStatsAndCouncilReviewTest extends TestCase
{
    public function test_shortstat_completo_vira_estatistica(): void
    {
        $stats = AiWorker::parseGitShortstat(" 3 files changed, 48 insertions(+), 12 deletions(-)\n");

        $this->assertSame(['files_touched' => 3, 'lines_added' => 48, 'lines_removed' => 12], $stats);
    }

    public function test_singular_e_partes_ausentes(): void
    {
        $this->assertSame(
            ['files_touched' => 1, 'lines_added' => 1, 'lines_removed' => 0],
            AiWorker::parseGitShortstat('1 file changed, 1 insertion(+)'),
        );
        $this->assertSame(
            ['files_touched' => 2, 'lines_added' => 0, 'lines_removed' => 7],
            AiWorker::parseGitShortstat('2 files changed, 7 deletions(-)'),
        );
    }

    public function test_sem_diff_nao_fabrica_zero(): void
    {
        $this->assertNull(AiWorker::parseGitShortstat(''));
        $this->assertNull(AiWorker::parseGitShortstat("  \n"));
        $this->assertNull(AiWorker::parseGitShortstat('fatal: not a git repository'));
    }

    public function test_council_review_registra_cada_membro_com_campos_reais(): void
    {
        $ok = new AiJob(['provider' => 'claude_cli', 'model' => 'opus', 'status' => 'succeeded', 'result_text' => 'resposta A']);
        $fail = new AiJob(['provider' => 'codex_cli', 'model' => 'gpt', 'status' => 'failed', 'error_code' => 'timeout']);

        $review = AiCouncilCoordinator::councilReview([$ok, $fail]);

        $this->assertCount(2, $review);
        $this->assertSame('claude_cli', $review[0]['provider']);
        $this->assertSame('succeeded', $review[0]['status']);
        $this->assertSame(hash('sha256', 'resposta A'), $review[0]['response_hash']);
        $this->assertArrayNotHasKey('error_code', $review[0]);
        $this->assertSame('failed', $review[1]['status']);
        $this->assertSame('timeout', $review[1]['error_code']);
        // divergência = status distinto entre membros, jamais um "voto" inventado
        $this->assertArrayNotHasKey('response_hash', $review[1]);
        $this->assertArrayNotHasKey('verdict', $review[0]);
    }
}
