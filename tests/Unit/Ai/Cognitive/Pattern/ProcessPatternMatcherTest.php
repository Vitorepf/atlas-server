<?php

namespace Tests\Unit\Ai\Cognitive\Pattern;

use App\Services\Ai\Cognitive\Pattern\ProcessPatternMatcher;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessPatternMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_07_150000_create_process_patterns_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('process_pattern_applications');
        Schema::dropIfExists('process_patterns');

        parent::tearDown();
    }

    public function test_matcher_returns_ranked_patterns_for_problem_description(): void
    {
        $result = app(ProcessPatternMatcher::class)->match('time bloqueado em decisao reversivel sem evidencia nova', 'decision');

        $this->assertSame('atlas.cognitive.process_pattern_matcher.v1', $result['schema_version']);
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['matches']);
        $this->assertArrayHasKey('pattern', $result['matches'][0]);
    }
}
