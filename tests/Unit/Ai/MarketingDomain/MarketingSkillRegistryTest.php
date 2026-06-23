<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\MarketingSkillRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the self-improvement DECISION: only a HIGH-quality page (no keyword crime, policy-safe, strong
 * audit + coverage) is worth learning; a weak/criminal/blocked page teaches nothing. The persistence
 * (learn→recall) is proven live against the real DB; here we pin the gate + the score deterministically.
 */
class MarketingSkillRegistryTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $ref = new ReflectionClass(MarketingSkillRegistry::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs(new MarketingSkillRegistry, $args);
    }

    private function validation(int $audit, int $coverage, string $verdict, bool $safe): array
    {
        return [
            'keyword_relevance' => ['verdict' => $verdict, 'anchor_rate' => 100],
            'policy' => ['safe_to_publish' => $safe],
            'page_audit' => ['overall_score' => $audit],
            'keyword_coverage' => ['score' => $coverage],
        ];
    }

    public function test_high_quality_clears_the_bar(): void
    {
        $this->assertTrue($this->invoke('isHighQuality', [$this->validation(86, 100, 'ok', true)]));
        $this->assertTrue($this->invoke('isHighQuality', [$this->validation(80, 40, 'ok', true)])); // floors exactly
    }

    public function test_weak_criminal_or_blocked_pages_teach_nothing(): void
    {
        $this->assertFalse($this->invoke('isHighQuality', [$this->validation(60, 100, 'ok', true)]));      // audit too low
        $this->assertFalse($this->invoke('isHighQuality', [$this->validation(90, 100, 'critical', true)])); // keyword crime
        $this->assertFalse($this->invoke('isHighQuality', [$this->validation(90, 100, 'ok', false)]));     // policy blocked
        $this->assertFalse($this->invoke('isHighQuality', [$this->validation(90, 10, 'ok', true)]));       // coverage too low
    }

    public function test_quality_score_weights_audit_coverage_and_anchor(): void
    {
        // audit*0.5 + coverage*0.25 + anchor*0.25 = 86*.5 + 100*.25 + 100*.25 = 93
        $score = $this->invoke('qualityScore', [$this->validation(86, 100, 'ok', true)]);
        $this->assertSame(93, $score);
    }
}
