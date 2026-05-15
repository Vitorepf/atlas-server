<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeScopeGuardMatcher;
use Tests\TestCase;

class AtlasCodeScopeGuardMatcherTest extends TestCase
{
    private AtlasCodeScopeGuardMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new AtlasCodeScopeGuardMatcher();
    }

    public function test_single_star_does_not_cross_slash(): void
    {
        $this->assertTrue($this->matcher->matches('src/Login.tsx', 'src/*.tsx'));
        $this->assertFalse($this->matcher->matches('src/auth/Login.tsx', 'src/*.tsx'));
    }

    public function test_double_star_crosses_slashes(): void
    {
        $this->assertTrue($this->matcher->matches('src/auth/login/index.ts', 'src/**/index.ts'));
        $this->assertTrue($this->matcher->matches('src/index.ts', 'src/**/index.ts'));
    }

    public function test_trailing_slash_means_directory(): void
    {
        $this->assertTrue($this->matcher->matches('src/auth/Login.tsx', 'src/auth/'));
        $this->assertTrue($this->matcher->matches('src/auth/sub/file.ts', 'src/auth/'));
    }

    public function test_negation_excludes_subset(): void
    {
        $decision = $this->matcher->decide('src/__tests__/x.ts', ['src/**', '!src/__tests__/**'], []);
        $this->assertFalse($decision['allowed']);
        $this->assertSame('outside_allowed_files', $decision['reason']);
    }

    public function test_forbidden_always_wins_over_allowed(): void
    {
        $decision = $this->matcher->decide('src/Auth/secret.ts', ['src/**'], ['src/Auth/**']);
        $this->assertFalse($decision['allowed']);
        $this->assertSame('in_forbidden_files', $decision['reason']);
    }

    public function test_empty_allowed_means_permitted_unless_forbidden(): void
    {
        $this->assertTrue($this->matcher->decide('anything.ts', [], [])['allowed']);
        $this->assertFalse($this->matcher->decide('node_modules/x', [], ['node_modules/**'])['allowed']);
    }

    public function test_path_normalization_strips_leading_dot_and_slash(): void
    {
        $this->assertTrue($this->matcher->matches('./src/Login.tsx', 'src/Login.tsx'));
        $this->assertTrue($this->matcher->matches('/src/Login.tsx', 'src/Login.tsx'));
        $this->assertTrue($this->matcher->matches('src\\Login.tsx', 'src/Login.tsx'));
    }

    public function test_violations_collects_all_failing_paths(): void
    {
        $violations = $this->matcher->violations(
            ['src/Checkout/Cart.tsx', 'src/Auth/login.ts', 'docs/README.md'],
            ['src/Checkout/**'],
            ['src/Auth/**']
        );
        $files = array_column($violations, 'file');
        $this->assertContains('src/Auth/login.ts', $files);
        $this->assertContains('docs/README.md', $files);
        $this->assertNotContains('src/Checkout/Cart.tsx', $files);
    }

    public function test_question_mark_matches_single_non_slash_char(): void
    {
        $this->assertTrue($this->matcher->matches('a.ts', '?.ts'));
        $this->assertFalse($this->matcher->matches('ab.ts', '?.ts'));
        $this->assertFalse($this->matcher->matches('a/b.ts', '?.ts'));
    }

    public function test_character_class(): void
    {
        $this->assertTrue($this->matcher->matches('file1.ts', 'file[0-9].ts'));
        $this->assertTrue($this->matcher->matches('fileA.ts', 'file[A-Z].ts'));
        $this->assertFalse($this->matcher->matches('file_.ts', 'file[A-Z].ts'));
    }
}
