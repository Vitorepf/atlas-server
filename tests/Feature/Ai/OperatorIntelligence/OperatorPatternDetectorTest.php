<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningSignal;
use App\Models\OperatorPatternDetection;
use App\Services\Ai\OperatorIntelligence\OperatorPatternDetector;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * The pattern detector is the anti-hallucination brain of the proactive bridges: it must
 * find REAL recurrence (≥3 operator-originated occurrences) and NEVER manufacture a
 * pattern from coincidence, from Atlas's own noise, or re-propose the same recurrence.
 */
final class OperatorPatternDetectorTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        if (! Schema::hasTable('operator_pattern_detections')) {
            Schema::create('operator_pattern_detections', function ($t): void {
                $t->uuid('id')->primary();
                $t->string('operator_id', 120);
                $t->string('pattern_id', 80);
                $t->string('kind', 40);
                $t->string('taxonomy_item_id', 16)->nullable();
                $t->text('summary');
                $t->string('signature', 255);
                $t->unsignedInteger('occurrence_count')->default(0);
                $t->unsignedSmallInteger('window_days')->default(28);
                $t->float('confidence')->default(0.0);
                $t->string('privacy_class', 20)->default('normal');
                $t->string('proposal_target', 20)->default('mission');
                $t->json('cadence')->nullable();
                $t->json('evidence');
                $t->string('status', 24)->default('detected');
                $t->string('proposed_skill_task_id')->nullable();
                $t->string('proposed_mission_id')->nullable();
                $t->timestamps();
                $t->unique(['operator_id', 'pattern_id']);
            });
        }
    }

    private function seedSignal(string $operator, string $taxonomy, string $claim, string $source = 'chat_comprehension', string $privacy = 'normal'): void
    {
        OperatorLearningSignal::query()->create([
            'operator_id' => $operator,
            'taxonomy_item_id' => $taxonomy,
            'signal_kind' => 'operator_preference',
            'source_type' => $source,
            'normalized_claim' => $claim,
            'privacy_class' => $privacy,
            'risk_level' => 'low',
            'confidence' => 0.9,
            'scope_type' => 'global',
        ]);
    }

    private function detector(): OperatorPatternDetector
    {
        return new OperatorPatternDetector();
    }

    public function test_detects_a_repeated_action_pattern_with_grounded_evidence(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedSignal('op', 'OP-073', 'Prefiro respostas curtas e diretas.');
        }

        $patterns = $this->detector()->detect('op');

        $this->assertCount(1, $patterns);
        $this->assertSame('repeated_action', $patterns[0]->kind);
        $this->assertSame('OP-073', $patterns[0]->taxonomy_item_id);
        $this->assertSame(3, $patterns[0]->occurrence_count);
        $this->assertGreaterThanOrEqual(3, count($patterns[0]->evidence)); // evidence-locked
    }

    public function test_below_three_occurrences_is_not_a_pattern(): void
    {
        $this->seedSignal('op', 'OP-073', 'Prefiro respostas curtas.');
        $this->seedSignal('op', 'OP-073', 'Prefiro respostas curtas.');

        $this->assertSame([], $this->detector()->detect('op'));
        $this->assertSame(0, OperatorPatternDetection::query()->count());
    }

    public function test_excludes_non_operator_source_signals(): void
    {
        // Atlas's OWN agent/system noise must never manufacture a "pattern" about the operator.
        for ($i = 0; $i < 4; $i++) {
            $this->seedSignal('op', 'OP-073', 'system emitted', 'agent_internal');
        }

        $this->assertSame([], $this->detector()->detect('op'));
    }

    public function test_dedups_on_rerun(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedSignal('op', 'OP-073', 'Prefiro respostas curtas.');
        }

        $this->assertCount(1, $this->detector()->detect('op'));
        $this->assertSame([], $this->detector()->detect('op'), 'a re-run must not re-detect the same recurrence');
    }

    public function test_temporal_cadence_requires_taxonomy_coherence(): void
    {
        // 3 DIFFERENT taxonomy items, each on a distinct same-weekday — the operator did
        // ANYTHING on 3 of that weekday, but never the SAME thing. Must NOT be a cadence
        // (the false-pattern the re-proof caught).
        $taxonomies = ['OP-071', 'OP-072', 'OP-073'];
        foreach ([7, 14, 21] as $i => $daysAgo) {
            $signal = OperatorLearningSignal::query()->create([
                'operator_id' => 'op-cad',
                'taxonomy_item_id' => $taxonomies[$i],
                'signal_kind' => 'operator_preference',
                'source_type' => 'chat_comprehension',
                'normalized_claim' => 'claim '.$taxonomies[$i],
                'privacy_class' => 'normal',
                'risk_level' => 'low',
                'confidence' => 0.9,
                'scope_type' => 'global',
            ]);
            $signal->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $patterns = $this->detector()->detect('op-cad');
        $cadence = array_filter($patterns, static fn ($p): bool => $p->kind === 'temporal_cadence');

        $this->assertCount(0, $cadence, '3 UNRELATED items sharing a weekday must not form a cadence');
    }

    public function test_per_run_cap_is_enforced(): void
    {
        foreach (['OP-073', 'OP-074', 'OP-075'] as $tax) {
            for ($i = 0; $i < 3; $i++) {
                $this->seedSignal('op', $tax, 'claim for '.$tax);
            }
        }

        $this->assertCount(1, $this->detector()->detect('op', ['max_patterns' => 1]));
    }
}
