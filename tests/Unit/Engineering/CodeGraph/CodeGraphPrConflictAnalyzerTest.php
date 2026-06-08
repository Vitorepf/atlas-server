<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphPrConflictAnalyzer;
use PHPUnit\Framework\TestCase;

class CodeGraphPrConflictAnalyzerTest extends TestCase
{
    /**
     * Static file -> community map used by the resolver in tests, so the analyzer
     * stays a pure transform with no DB/index dependency.
     *
     * @param  array<string,string>  $map
     * @return callable(string):?string
     */
    private function resolver(array $map): callable
    {
        return static fn (string $file): ?string => $map[$file] ?? null;
    }

    public function test_two_prs_in_same_community_are_flagged(): void
    {
        $prChangedFiles = [
            101 => ['app/Services/Ai/Router.php'],
            102 => ['app/Services/Ai/Decide.php'], // different file, SAME community
        ];
        $communityForFile = $this->resolver([
            'app/Services/Ai/Router.php' => 'community:ai',
            'app/Services/Ai/Decide.php' => 'community:ai',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertSame(CodeGraphPrConflictAnalyzer::SCHEMA, $result['schema_version']);
        $this->assertCount(1, $result['risks']);
        $risk = $result['risks'][0];
        $this->assertSame('community:ai', $risk['community_id']);
        $this->assertSame(['101', '102'], $risk['prs']);
        $this->assertSame(2, $risk['pr_count']);
        $this->assertSame(2, $risk['file_count']);
    }

    public function test_prs_in_disjoint_communities_are_not_flagged(): void
    {
        $prChangedFiles = [
            201 => ['app/Services/Ai/Router.php'],
            202 => ['app/Models/User.php'],
        ];
        $communityForFile = $this->resolver([
            'app/Services/Ai/Router.php' => 'community:ai',
            'app/Models/User.php' => 'community:models',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertSame([], $result['risks']);
        // Both communities were touched, but each by only one PR.
        $this->assertSame(2, $result['stats']['communities_touched']);
        $this->assertSame(2, $result['stats']['files_resolved']);
    }

    public function test_single_pr_touching_a_community_is_not_a_risk(): void
    {
        // One PR touching two files in the same community is not a MERGE-ORDER risk
        // (no second PR to order against).
        $prChangedFiles = [
            301 => ['app/Services/Ai/Router.php', 'app/Services/Ai/Decide.php'],
        ];
        $communityForFile = $this->resolver([
            'app/Services/Ai/Router.php' => 'community:ai',
            'app/Services/Ai/Decide.php' => 'community:ai',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertSame([], $result['risks']);
    }

    public function test_risks_are_ranked_by_pr_count_desc_then_deterministic(): void
    {
        $prChangedFiles = [
            1 => ['a.php', 'shared.php'],
            2 => ['b.php', 'shared.php'],
            3 => ['c.php', 'shared.php'], // community:hot touched by 3 PRs
            4 => ['x.php'],
            5 => ['y.php'],              // community:warm touched by 2 PRs
        ];
        $communityForFile = $this->resolver([
            'a.php' => 'community:hot',
            'b.php' => 'community:hot',
            'c.php' => 'community:hot',
            'shared.php' => 'community:hot',
            'x.php' => 'community:warm',
            'y.php' => 'community:warm',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertCount(2, $result['risks']);
        // Highest PR count first.
        $this->assertSame('community:hot', $result['risks'][0]['community_id']);
        $this->assertSame(3, $result['risks'][0]['pr_count']);
        $this->assertSame(['1', '2', '3'], $result['risks'][0]['prs']);
        $this->assertSame('community:warm', $result['risks'][1]['community_id']);
        $this->assertSame(2, $result['risks'][1]['pr_count']);
    }

    public function test_ranking_tie_breaks_on_community_id_when_pr_and_file_counts_equal(): void
    {
        // Two communities each touched by exactly 2 PRs with 2 files each =>
        // identical pr_count and file_count; tie-break must be community_id asc.
        $prChangedFiles = [
            10 => ['zeta1.php', 'alpha1.php'],
            11 => ['zeta2.php', 'alpha2.php'],
        ];
        $communityForFile = $this->resolver([
            'zeta1.php' => 'community:zeta',
            'zeta2.php' => 'community:zeta',
            'alpha1.php' => 'community:alpha',
            'alpha2.php' => 'community:alpha',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertCount(2, $result['risks']);
        $this->assertSame('community:alpha', $result['risks'][0]['community_id']);
        $this->assertSame('community:zeta', $result['risks'][1]['community_id']);
    }

    public function test_unresolved_files_are_ignored_and_counted(): void
    {
        $prChangedFiles = [
            401 => ['app/Services/Ai/Router.php', 'README.md'],
            402 => ['app/Services/Ai/Decide.php', 'docs/notes.md'],
        ];
        // README.md / docs/notes.md have no known community (return null).
        $communityForFile = $this->resolver([
            'app/Services/Ai/Router.php' => 'community:ai',
            'app/Services/Ai/Decide.php' => 'community:ai',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertCount(1, $result['risks']);
        $this->assertSame(2, $result['risks'][0]['file_count']);
        $this->assertSame(4, $result['stats']['files_seen']);
        $this->assertSame(2, $result['stats']['files_resolved']);
        $this->assertSame(2, $result['stats']['files_unresolved']);
    }

    public function test_duplicate_file_in_one_pr_does_not_inflate_file_count(): void
    {
        $prChangedFiles = [
            501 => ['app/Services/Ai/Router.php', 'app/Services/Ai/Router.php'],
            502 => ['app/Services/Ai/Decide.php'],
        ];
        $communityForFile = $this->resolver([
            'app/Services/Ai/Router.php' => 'community:ai',
            'app/Services/Ai/Decide.php' => 'community:ai',
        ]);

        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity($prChangedFiles, $communityForFile);

        $this->assertCount(1, $result['risks']);
        $this->assertSame(2, $result['risks'][0]['file_count']);
    }

    public function test_path_suffix_match_is_boundary_safe(): void
    {
        $analyzer = new CodeGraphPrConflictAnalyzer;

        // Exact match.
        $this->assertTrue($analyzer->pathSuffixMatches('app/Services/Ai/Router.php', 'app/Services/Ai/Router.php'));
        // Genuine path-boundary suffix (PR reported a longer prefix).
        $this->assertTrue($analyzer->pathSuffixMatches('repo/app/Services/Ai/Router.php', 'app/Services/Ai/Router.php'));
        $this->assertTrue($analyzer->pathSuffixMatches('app/Services/Ai/Router.php', 'Ai/Router.php'));
        // Backslash normalization (Windows-style prefix still matches).
        $this->assertTrue($analyzer->pathSuffixMatches('repo\\app\\Services\\Ai\\Router.php', 'Ai/Router.php'));

        // Substring-but-not-boundary must NOT match.
        $this->assertFalse($analyzer->pathSuffixMatches('app/Foo/SuperRouter.php', 'Router.php'));
        $this->assertFalse($analyzer->pathSuffixMatches('app/Services/Ai/Router.php', 'outer.php'));
        // Empty inputs never match.
        $this->assertFalse($analyzer->pathSuffixMatches('', 'Router.php'));
        $this->assertFalse($analyzer->pathSuffixMatches('app/Router.php', ''));
    }

    public function test_empty_input_yields_no_risks(): void
    {
        $result = (new CodeGraphPrConflictAnalyzer)->conflictsByCommunity([], $this->resolver([]));

        $this->assertSame([], $result['risks']);
        $this->assertSame(0, $result['stats']['prs']);
        $this->assertSame(0, $result['stats']['communities_touched']);
    }
}
