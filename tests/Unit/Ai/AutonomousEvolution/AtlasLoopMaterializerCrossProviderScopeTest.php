<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializer;
use Tests\TestCase;

/**
 * ACDE lever #1 — when the multi-engine best-of-N portfolio is armed, the materializer derives the
 * FrozenJudge SCOPE globs from allowed_files so agentic CLI engines (which edit in place) cannot make an
 * out-of-scope edit that the default ['**'] allow-all would miss. OFF => byte-identical.
 */
final class AtlasLoopMaterializerCrossProviderScopeTest extends TestCase
{
    /** @var list<callable> */
    private array $cleanups = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanups as $c) {
            $c();
        }
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $extra  merged into the payload (e.g. the injected flag / explicit globs)
     * @return array<string,mixed> the explorerTask
     */
    private function materialize(array $extra = []): array
    {
        [$task, $cleanup] = (new AtlasLoopWorkspaceMaterializer)->materialize('make Foo good', array_merge([
            'target_relative_path' => 'src/Foo.php',
            'target_content' => "<?php\nclass Foo { public function v(): int { return 1; } }\n",
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
            'allowed_files' => ['src/Foo.php'],
        ], $extra));
        $this->cleanups[] = $cleanup;

        return $task;
    }

    public function test_armed_derives_allowed_globs_from_allowed_files(): void
    {
        // The grinder injects the payload flag from config; here we pass it directly (the materializer is
        // config-free so the pure-unit lane stays container-independent).
        $task = $this->materialize(['cross_provider_best_of_n' => true]);

        $this->assertSame(['src/Foo.php'], $task['acceptance']['allowed_globs'] ?? null,
            'armed => the writable scope is tightened to allowed_files for the agentic editors');
    }

    public function test_off_is_byte_identical_no_globs_added(): void
    {
        $task = $this->materialize(); // no flag => today's behaviour

        $this->assertArrayNotHasKey('allowed_globs', $task['acceptance'],
            'OFF => the acceptance is untouched (byte-identical)');
    }

    public function test_armed_does_not_override_explicit_globs(): void
    {
        $task = $this->materialize([
            'cross_provider_best_of_n' => true,
            'acceptance' => ['commands' => ['php tests/FooTest.php'], 'allowed_globs' => ['src/**']],
        ]);

        $this->assertSame(['src/**'], $task['acceptance']['allowed_globs'],
            'an explicit acceptance scope is never overridden');
    }
}
