<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognitive\Staleness;

use App\Services\Ai\Cognitive\Staleness\ContextPackStalenessClassifier;
use PHPUnit\Framework\TestCase;

final class ContextPackStalenessClassifierTest extends TestCase
{
    private ContextPackStalenessClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ContextPackStalenessClassifier();
    }

    public function testHighAgeRatioYieldsCriticalAndReindexRequired(): void
    {
        // index_age 400000 / max_fresh 86400 => age_ratio ~= 4.63 (>= 4) => critical.
        $result = $this->classifier->classify([
            'index_age_seconds' => 400000,
            'changed_files_since_index' => 0,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('atlas.cognitive.staleness.context_pack.v1', $result['schema_version']);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame('reindex_required', $result['recommended_action']);
        $this->assertSame(1.0, $result['score']);
        $this->assertSame(400000, $result['index_age_seconds']);
    }

    public function testHighChangedFilesAloneYieldsCriticalAndReindexRequired(): void
    {
        // changed_files 200 (>= 200) forces critical even with zero index age.
        $result = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 200,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('critical', $result['severity']);
        $this->assertSame('reindex_required', $result['recommended_action']);
        $this->assertSame(1.0, $result['score']);
        $this->assertSame(200, $result['changed_files_since_index']);
    }

    public function testStaleAgeRatioYieldsStaleAndReindexRequired(): void
    {
        // index_age 200000 / 86400 => age_ratio ~= 2.31 (>= 2, < 4) => stale.
        // score = round(min(1.0, max(2.3148.../4, 0)), 3) = round(0.5787..., 3) = 0.579.
        $result = $this->classifier->classify([
            'index_age_seconds' => 200000,
            'changed_files_since_index' => 0,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('stale', $result['severity']);
        $this->assertSame('reindex_required', $result['recommended_action']);
        $this->assertSame(0.579, $result['score']);
    }

    public function testStaleChangedFilesAloneYieldsStale(): void
    {
        // changed_files 50 (>= 50, < 200) with zero age => stale.
        // score = round(max(0, 50/200), 3) = 0.25.
        $result = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 50,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('stale', $result['severity']);
        $this->assertSame('reindex_required', $result['recommended_action']);
        $this->assertSame(0.25, $result['score']);
    }

    public function testAgeRatioExactlyOneWithZeroChangedFilesIsAgingSoftBoundary(): void
    {
        // index_age == max_fresh => age_ratio == 1.0 exactly, changed_files == 0.
        // Soft boundary: must classify as aging, never stale/critical.
        $result = $this->classifier->classify([
            'index_age_seconds' => 86400,
            'changed_files_since_index' => 0,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('aging', $result['severity']);
        $this->assertNotSame('stale', $result['severity']);
        $this->assertNotSame('critical', $result['severity']);
        $this->assertSame('reindex_recommended', $result['recommended_action']);
        // score = round(min(1.0, max(1.0/4, 0)), 3) = 0.25.
        $this->assertSame(0.25, $result['score']);
    }

    public function testFreshInputsYieldFreshAndNoAction(): void
    {
        // Zero drift on every axis => fresh.
        $result = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 0,
            'last_query_age_seconds' => 0,
            'max_fresh_seconds' => 86400,
        ]);

        $this->assertSame('fresh', $result['severity']);
        $this->assertSame('none', $result['recommended_action']);
        $this->assertSame(0.0, $result['score']);
    }

    public function testScoreGrowsMonotonicallyWithBothDriftAxes(): void
    {
        // Age axis (changed_files fixed at 0): rising index_age strictly raises score.
        $ageLow = $this->classifier->classify([
            'index_age_seconds' => 21600,
            'changed_files_since_index' => 0,
            'max_fresh_seconds' => 86400,
        ])['score'];
        $ageMid = $this->classifier->classify([
            'index_age_seconds' => 43200,
            'changed_files_since_index' => 0,
            'max_fresh_seconds' => 86400,
        ])['score'];
        $ageHigh = $this->classifier->classify([
            'index_age_seconds' => 86400,
            'changed_files_since_index' => 0,
            'max_fresh_seconds' => 86400,
        ])['score'];

        $this->assertGreaterThan($ageLow, $ageMid);
        $this->assertGreaterThan($ageMid, $ageHigh);
        $this->assertSame(0.063, $ageLow);
        $this->assertSame(0.125, $ageMid);
        $this->assertSame(0.25, $ageHigh);

        // Changed-files axis (index_age fixed at 0): rising drift strictly raises score.
        $filesLow = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 10,
            'max_fresh_seconds' => 86400,
        ])['score'];
        $filesMid = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 50,
            'max_fresh_seconds' => 86400,
        ])['score'];
        $filesHigh = $this->classifier->classify([
            'index_age_seconds' => 0,
            'changed_files_since_index' => 100,
            'max_fresh_seconds' => 86400,
        ])['score'];

        $this->assertGreaterThan($filesLow, $filesMid);
        $this->assertGreaterThan($filesMid, $filesHigh);
        $this->assertSame(0.05, $filesLow);
        $this->assertSame(0.25, $filesMid);
        $this->assertSame(0.5, $filesHigh);
    }

    public function testNegativeAndMissingSignalsAreClampedAndMaxFreshDefaultsApply(): void
    {
        // Negative index_age and changed_files clamp to 0; missing max_fresh defaults to 86400.
        // last_query_age 90000 > 86400 default => aging via the third ordered rule.
        $result = $this->classifier->classify([
            'index_age_seconds' => -500,
            'changed_files_since_index' => -7,
            'last_query_age_seconds' => 90000,
        ]);

        $this->assertSame('aging', $result['severity']);
        $this->assertSame('reindex_recommended', $result['recommended_action']);
        $this->assertSame(0, $result['index_age_seconds']);
        $this->assertSame(0, $result['changed_files_since_index']);
        $this->assertSame(0.0, $result['score']);
    }
}
