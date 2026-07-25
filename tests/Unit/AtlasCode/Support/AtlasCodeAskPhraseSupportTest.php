<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode\Support;

use App\Services\AtlasCode\AtlasCodeAskService as Host;
use App\Services\AtlasCode\AtlasCodeQuestionRouter;
use App\Services\AtlasCode\Support\AtlasCodeAskPhraseSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Atlas Código ask phrase/parse/window helpers —
 * no Process, git, DB, ledger, DI, or provider I/O.
 *
 * Explicit path proof: host imports Support and thin-forwards the peeled pure
 * cluster (phrase/parse/ago/window/shape/zone).
 */
final class AtlasCodeAskPhraseSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/AtlasCode/Support/AtlasCodeAskPhraseSupport.php';

    private const HOST_PATH = 'app/Services/AtlasCode/AtlasCodeAskService.php';

    /** @var list<string> */
    private const PEELED = [
        'phraseChanges',
        'parseWork',
        'phraseProblems',
        'oldestViolation',
        'phraseRule',
        'phraseWhoTouched',
        'phraseSubjectOnly',
        'phraseHottest',
        'parseBranches',
        'phraseOtherBranches',
        'accentTolerantPattern',
        'ago',
        'phraseFind',
        'windowStart',
        'zone',
        'parseCommits',
        'shape',
    ];

    /** Host residual orchestrators / I/O that must stay. */
    /** @var list<string> */
    private const HOST_RESIDUAL = [
        'answer',
        'git',
        'answerProblems',
        'answerChanges',
        'answerWhoTouched',
        'answerFind',
        'startReview',
        'collectReview',
        'consultBrain',
        'commitRoll',
        'provenanceRecordingIsLive',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 4);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\AtlasCode\Support\AtlasCodeAskPhraseSupport;',
            $hostSrc,
            'Host must import AtlasCodeAskPhraseSupport',
        );

        foreach ([
            'AtlasCodeAskPhraseSupport::phraseChanges',
            'AtlasCodeAskPhraseSupport::parseWork',
            'AtlasCodeAskPhraseSupport::phraseProblems',
            'AtlasCodeAskPhraseSupport::oldestViolation',
            'AtlasCodeAskPhraseSupport::phraseRule',
            'AtlasCodeAskPhraseSupport::phraseWhoTouched',
            'AtlasCodeAskPhraseSupport::phraseSubjectOnly',
            'AtlasCodeAskPhraseSupport::phraseHottest',
            'AtlasCodeAskPhraseSupport::parseBranches',
            'AtlasCodeAskPhraseSupport::phraseOtherBranches',
            'AtlasCodeAskPhraseSupport::accentTolerantPattern',
            'AtlasCodeAskPhraseSupport::ago',
            'AtlasCodeAskPhraseSupport::phraseFind',
            'AtlasCodeAskPhraseSupport::windowStart',
            'AtlasCodeAskPhraseSupport::zone',
            'AtlasCodeAskPhraseSupport::parseCommits',
            'AtlasCodeAskPhraseSupport::shape',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            // Public thin facades preserved for existing host path tests / API.
            if (in_array($method, ['zone', 'shape'], true)) {
                $this->assertTrue($host->hasMethod($method), "Host keeps private {$method} thin forward");
                $this->assertTrue($host->getMethod($method)->isPrivate());

                continue;
            }
            $this->assertTrue($host->hasMethod($method), "Host keeps public thin facade {$method}");
            $this->assertTrue($host->getMethod($method)->isPublic());
        }

        foreach (self::HOST_RESIDUAL as $method) {
            $this->assertTrue($host->hasMethod($method), "Host residual I/O/orchestrator {$method} must remain");
        }
    }

    #[Test]
    public function phrase_changes_counts_hands_only_when_more_than_one_and_carries_work(): void
    {
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 100, 'message' => 'x'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Vitor Freire', 'authored_at' => 90, 'message' => 'y'],
            ['hash' => str_repeat('c', 40), 'author_name' => 'forge', 'authored_at' => 80, 'message' => 'z'],
        ];

        $this->assertSame(
            '3 commits hoje — 2 de Vitor Freire, 1 de forge.',
            Support::phraseChanges($commits, AtlasCodeQuestionRouter::WINDOW_TODAY),
        );
        $this->assertSame(
            '2 commits hoje.',
            Support::phraseChanges(array_slice($commits, 0, 2), AtlasCodeQuestionRouter::WINDOW_TODAY),
        );

        $work = [
            'files' => 47,
            'additions' => 2104,
            'deletions' => 890,
            'top' => [['path' => 'App/Atlas/AtlasCodeView.swift', 'touches' => 9]],
        ];
        $this->assertSame(
            '2 commits hoje: 47 arquivos, +2104 −890. O mais mexido: AtlasCodeView.swift (9×).',
            Support::phraseChanges(array_slice($commits, 0, 2), AtlasCodeQuestionRouter::WINDOW_TODAY, $work),
        );
        $this->assertSame('nenhum commit hoje.', Support::phraseChanges([], AtlasCodeQuestionRouter::WINDOW_TODAY));
    }

    #[Test]
    public function parse_work_counts_distinct_files_and_never_invents_binary_lines(): void
    {
        $numstat = implode("\n", [
            "7\t6\tApp/Atlas/AtlasCodeView.swift",
            "271\t168\tApp/Atlas/AtlasCodeRadarView.swift",
            "-\t-\tApp/Assets/icon.png",
            "12\t3\tApp/Atlas/AtlasCodeView.swift",
        ]);

        $work = Support::parseWork($numstat);
        $this->assertSame(3, $work['files']);
        $this->assertSame(290, $work['additions']);
        $this->assertSame(177, $work['deletions']);
        $this->assertSame('App/Atlas/AtlasCodeView.swift', $work['top'][0]['path']);
        $this->assertSame(2, $work['top'][0]['touches']);
    }

    #[Test]
    public function phrase_rule_translates_five_real_rules_and_leaks_unknown_ids(): void
    {
        $this->assertSame('2 obras fora da production', Support::phraseRule('main_only', 2, 'production'));
        $this->assertSame('1 obra que não voltou no prazo', Support::phraseRule('obra_return_deadline', 1));
        $this->assertSame('3 branches que nunca voltaram', Support::phraseRule('orphan_branch', 3));
        $this->assertSame('1 worktree fora do lugar', Support::phraseRule('worktree_allowlist', 1));
        $this->assertSame('2 espelhos atrasados', Support::phraseRule('mirror_drift', 2));
        $this->assertSame('2 × rule_from_the_future', Support::phraseRule('rule_from_the_future', 2));
    }

    #[Test]
    public function phrase_problems_groups_rules_and_surfaces_oldest_when_dated(): void
    {
        $now = 1_700_000_000;
        $violations = [
            ['rule_id' => 'main_only', 'since' => gmdate('c', $now - 3 * 86_400)],
            ['rule_id' => 'main_only', 'since' => gmdate('c', $now - 86_400)],
            ['rule_id' => 'orphan_branch', 'since' => gmdate('c', $now - 2 * 86_400)],
        ];

        $phrase = Support::phraseProblems($violations, 'main', $now);
        $this->assertStringStartsWith('3 exceções:', $phrase);
        $this->assertStringContainsString('2 obras fora da main', $phrase);
        $this->assertStringContainsString('1 branch que nunca voltou', $phrase);
        $this->assertStringContainsString('A mais antiga há 3 dias.', $phrase);
        $this->assertSame('3 dias', Support::oldestViolation($violations, $now));
        $this->assertSame('nada fora do lugar neste repositório.', Support::phraseProblems([]));
        $this->assertNull(Support::oldestViolation([['rule_id' => 'main_only']]));
    }

    #[Test]
    public function accent_tolerant_pattern_uses_alternation_not_byte_classes(): void
    {
        $this->assertSame('p(i|í)l(u|ú|ü)l(a|á|à|ã|â)', Support::accentTolerantPattern('pilula'));
        $this->assertSame('(c|ç)(o|ó|õ|ô)d(i|í)g(o|ó|õ|ô)', Support::accentTolerantPattern('codigo'));
        // Operator metacharacters stay literal — not wildcards.
        $this->assertStringContainsString('\.', Support::accentTolerantPattern('v1.*'));
        $this->assertStringContainsString('\*', Support::accentTolerantPattern('v1.*'));
    }

    #[Test]
    public function ago_uses_portuguese_singular_and_plural_units(): void
    {
        $now = 1_700_000_000;
        $this->assertSame('1 minuto', Support::ago($now - 5, $now));
        $this->assertSame('30 minutos', Support::ago($now - 1800, $now));
        $this->assertSame('1 hora', Support::ago($now - 3600, $now));
        $this->assertSame('5 horas', Support::ago($now - 18000, $now));
        $this->assertSame('1 dia', Support::ago($now - 86400, $now));
        $this->assertSame('3 dias', Support::ago($now - 3 * 86400, $now));
        $this->assertSame('1 mês', Support::ago($now - 31 * 86400, $now));
        $this->assertSame('2 meses', Support::ago($now - 60 * 86400, $now));
    }

    #[Test]
    public function parse_commits_and_branches_are_pure_over_git_text(): void
    {
        $h1 = str_repeat('a', 40);
        $h2 = str_repeat('b', 40);
        $output = $h1."\x1fVitor\x1f100\x1ffeat: a | b | c\n".$h2."\x1fForge\x1f90\x1ffix: z\n";
        $commits = Support::parseCommits($output);
        $this->assertCount(2, $commits);
        $this->assertSame($h1, $commits[0]['hash']);
        $this->assertSame('feat: a | b | c', $commits[0]['message']);
        $this->assertSame(100, $commits[0]['authored_at']);

        $branches = Support::parseBranches(
            "100|main\n50|feature/x\n75|hotfix|with|pipes\n",
            'main',
        );
        $this->assertCount(2, $branches);
        $this->assertSame('feature/x', $branches[0]['name']);
        $this->assertSame(50, $branches[0]['at']);
        $this->assertSame('hotfix|with|pipes', $branches[1]['name']);
    }

    #[Test]
    public function phrase_find_who_touched_subject_hottest_and_other_branches(): void
    {
        $now = 1_700_000_000;
        $commits = [
            ['hash' => str_repeat('a', 40), 'author_name' => 'Vitor', 'authored_at' => $now - 3600, 'message' => 'feat sanitizer'],
            ['hash' => str_repeat('b', 40), 'author_name' => 'Forge', 'authored_at' => $now - 7200, 'message' => 'fix sanitizer'],
        ];

        $this->assertStringContainsString(
            '2 commits falam de “sanitizer”',
            Support::phraseFind('sanitizer', $commits, $now),
        );
        $this->assertStringContainsString('feat sanitizer', Support::phraseFind('sanitizer', $commits, $now));

        $who = Support::phraseWhoTouched('worker', $commits, ['files' => 2, 'additions' => 10, 'deletions' => 3, 'top' => []], $now);
        $this->assertStringContainsString('2 commits tocaram “worker”', $who);
        $this->assertStringContainsString('1 de Vitor', $who);
        $this->assertStringContainsString('+10 −3', $who);

        $this->assertSame(
            'nenhum arquivo com “metal” no nome — mas 1 commit fala disso. O último há 1 hora.',
            Support::phraseSubjectOnly('metal', [array_merge($commits[0], ['message' => 'metal'])], $now),
        );

        $hot = Support::phraseHottest(
            ['files' => 10, 'additions' => 1, 'deletions' => 1, 'top' => [
                ['path' => 'App/Hot.swift', 'touches' => 5],
                ['path' => 'App/Warm.swift', 'touches' => 2],
            ]],
            AtlasCodeQuestionRouter::WINDOW_TODAY,
        );
        $this->assertStringContainsString('o esforço bateu em: Hot.swift (5×), Warm.swift (2×)', $hot);

        $others = [
            ['name' => 'old-branch', 'at' => $now - 10 * 86_400],
            ['name' => 'fresh', 'at' => $now - 3600],
        ];
        $phrase = Support::phraseOtherBranches('main', $others, $now);
        $this->assertStringContainsString('você está na main', $phrase);
        $this->assertStringContainsString('old-branch', $phrase);
        $this->assertStringContainsString('fresh', $phrase);
        $this->assertSame(
            'você está na production, e não existe outra branch neste repositório.',
            Support::phraseOtherBranches('production', []),
        );
    }

    #[Test]
    public function window_start_uses_operator_timezone_and_zone_falls_back_to_utc(): void
    {
        $now = strtotime('2026-07-15 12:00:00 UTC');
        $this->assertSame(
            strtotime('2026-07-15 00:00:00 UTC'),
            Support::windowStart(AtlasCodeQuestionRouter::WINDOW_TODAY, $now, 'UTC'),
        );
        $this->assertSame(
            strtotime('2026-07-14 00:00:00 UTC'),
            Support::windowStart(AtlasCodeQuestionRouter::WINDOW_YESTERDAY, $now, 'UTC'),
        );
        $this->assertSame($now - 7 * 86_400, Support::windowStart(AtlasCodeQuestionRouter::WINDOW_WEEK, $now, 'UTC'));
        $this->assertSame($now - 30 * 86_400, Support::windowStart(AtlasCodeQuestionRouter::WINDOW_MONTH, $now, 'UTC'));

        $saoPaulo = Support::windowStart(AtlasCodeQuestionRouter::WINDOW_TODAY, $now, 'America/Sao_Paulo');
        $utc = Support::windowStart(AtlasCodeQuestionRouter::WINDOW_TODAY, $now, 'UTC');
        $this->assertNotSame($saoPaulo, $utc);

        $this->assertSame(
            Support::windowStart(AtlasCodeQuestionRouter::WINDOW_TODAY, $now, 'UTC'),
            Support::windowStart(AtlasCodeQuestionRouter::WINDOW_TODAY, $now, 'Marte/Olympus_Mons'),
        );
        $this->assertSame('UTC', Support::zone('Marte/Olympus_Mons')->getName());
    }

    #[Test]
    public function shape_envelope_is_stable_and_host_facade_matches_support(): void
    {
        $shaped = Support::shape(true, 'ok', ['abc'], [['kind' => 'rule', 'ref' => 'main_only']], Host::SOURCE_RULES);
        $this->assertSame([
            'answered' => true,
            'answer' => 'ok',
            'commits' => ['abc'],
            'evidence' => [['kind' => 'rule', 'ref' => 'main_only']],
            'source' => Host::SOURCE_RULES,
        ], $shaped);

        $host = new Host;
        $this->assertSame(
            Support::phraseChanges([], AtlasCodeQuestionRouter::WINDOW_TODAY),
            $host->phraseChanges([], AtlasCodeQuestionRouter::WINDOW_TODAY),
        );
        $this->assertSame(
            Support::ago(100, 4600),
            Host::ago(100, 4600),
        );
        $this->assertSame(
            Support::accentTolerantPattern('pilula'),
            $host->accentTolerantPattern('pilula'),
        );
    }
}
