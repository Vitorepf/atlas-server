<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeGraphService;
use App\Services\AtlasCode\AtlasCodeProvenanceService;
use PHPUnit\Framework\TestCase;

final class AtlasCodeGraphServiceTest extends TestCase
{
    public function test_parses_topo_order_log_line_with_parents_and_refs(): void
    {
        $service = new AtlasCodeGraphService();

        $node = $service->parseLogLine(
            "9a06fd4c56|4b2b61f974 7c1e8d2a90|Vitor Freire|vitor@example.test|1784316000|HEAD -> main, origin/main|feat(brain): council_review por membro",
        );

        self::assertSame('9a06fd4c56', $node['hash']);
        self::assertSame(['4b2b61f974', '7c1e8d2a90'], $node['parents']);
        self::assertSame('Vitor Freire', $node['author_name']);
        self::assertSame('vitor@example.test', $node['author_email']);
        self::assertSame(1784316000, $node['authored_at']);
        self::assertSame(['HEAD -> main', 'origin/main'], $node['refs']);
        self::assertSame('feat(brain): council_review por membro', $node['message']);
    }

    /**
     * The commit subject is the headline of the graph screen: it must survive
     * verbatim even when it contains the field separator.
     */
    public function test_message_with_pipe_survives_intact(): void
    {
        $service = new AtlasCodeGraphService();

        $node = $service->parseLogLine(
            "abc123|def456|Fable|fable@example.test|1784316000||fix(ui): pipe | dentro da mensagem",
        );

        self::assertSame('fix(ui): pipe | dentro da mensagem', $node['message']);
        self::assertSame([], $node['refs']);
    }

    public function test_line_without_message_is_rejected_not_faked(): void
    {
        $service = new AtlasCodeGraphService();

        $this->expectExceptionMessage('invalid_git_log_line');
        $service->parseLogLine('abc123|def456|Fable|fable@example.test|1784316000|main');
    }

    public function test_parses_worktree_porcelain_without_exposing_git_metadata(): void
    {
        $service = new AtlasCodeGraphService();

        $worktrees = $service->parseWorktrees(
            "worktree /Users/vitor/worktrees/atlas\nHEAD 9a06fd4c56\nbranch refs/heads/main\n\n".
            "worktree /Users/vitor/worktrees/feature\nHEAD 683af18\nbranch refs/heads/feature\n",
        );

        self::assertSame([
            [
                'path' => '/Users/vitor/worktrees/atlas',
                'branch' => 'main',
                'head' => '9a06fd4c56',
            ],
            [
                'path' => '/Users/vitor/worktrees/feature',
                'branch' => 'feature',
                'head' => '683af18',
            ],
        ], $worktrees);
    }

    public function test_maps_known_author_and_keeps_unknown_authorship_explicit(): void
    {
        $service = new AtlasCodeProvenanceService(agentMap: [
            'operator@example.test' => 'voce',
        ]);

        self::assertSame('voce', $service->agentForAuthor('Operator@Example.Test'));
        self::assertSame('autonomo:desconhecido', $service->agentForAuthor('unknown@example.test'));
    }

    public function test_projects_only_explicit_operator_quote_and_gate_fields(): void
    {
        $service = new AtlasCodeProvenanceService();

        self::assertSame([
            'commit_hash' => 'abcdef1234567',
            'trace_id' => 'trace-real',
            'operator_quote' => 'frase real',
            'obra' => ['C23'],
            'gates' => ['ledger'],
        ], $service->projectLedgerPayload([
            'provenance' => [
                'commit_hash' => 'abcdef1234567',
                'operator_quote' => 'frase real',
                'obra' => ['C23'],
                'gates' => ['ledger'],
            ],
        ], 'trace-real'));

        self::assertNull($service->projectLedgerPayload(['message' => 'não é quote'], null)['operator_quote']);
    }
}
