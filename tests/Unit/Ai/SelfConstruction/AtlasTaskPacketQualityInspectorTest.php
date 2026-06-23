<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use PHPUnit\Framework\TestCase;

/**
 * FROZEN proof of the task-packet self-sufficiency inspector — the crystallized FACTS that decide whether a
 * COLD client could implement and prove a served packet. Facts, never a score.
 */
final class AtlasTaskPacketQualityInspectorTest extends TestCase
{
    private function packet(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'wire X into Y',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'acceptance_criteria' => ['the test passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    public function test_a_complete_packet_is_self_sufficient(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet());

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame([], $r['blocking_deficiencies']);
        $this->assertTrue($r['facts']['has_objective']);
        $this->assertSame(1, $r['facts']['acceptance_criteria_count']);
    }

    public function test_missing_acceptance_criteria_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['acceptance_criteria' => []]));

        $this->assertFalse($r['self_sufficient'], 'no acceptance ⇒ the client cannot know when it is done');
        $this->assertContains('missing_acceptance_criteria', $r['blocking_deficiencies']);
    }

    public function test_missing_required_evidence_is_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['required_evidence' => []]));

        $this->assertFalse($r['self_sufficient'], 'no required evidence ⇒ the report gate cannot validate the work');
        $this->assertContains('missing_required_evidence', $r['blocking_deficiencies']);
    }

    public function test_bare_directory_in_allowed_files_is_blocking(): void
    {
        // A directory write-scope guarantees a files_changed_outside_allowed_scope failure at completion (MF-12).
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'],
        ]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('bare_directory_in_allowed_files', $r['blocking_deficiencies']);
        $this->assertSame(['app/Services/Ai/SelfConstruction/'], $r['facts']['bare_directories']);
    }

    public function test_missing_objective_and_empty_allowed_files_are_blocking(): void
    {
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet(['objective' => '   ', 'allowed_files' => []]));

        $this->assertFalse($r['self_sufficient']);
        $this->assertContains('missing_objective', $r['blocking_deficiencies']);
        $this->assertContains('empty_allowed_files', $r['blocking_deficiencies']);
    }

    public function test_scope_incoherence_is_advisory_not_blocking(): void
    {
        // allowed_files not covered by scope_in is surfaced, but does NOT disqualify a packet on its own.
        $r = (new AtlasTaskPacketQualityInspector)->inspect($this->packet([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Other/Unrelated.php'],
        ]));

        $this->assertTrue($r['self_sufficient'], 'scope incoherence alone is advisory');
        $this->assertContains('scope_incoherent', $r['deficiencies']);
        $this->assertNotContains('scope_incoherent', $r['blocking_deficiencies']);
    }

    public function test_normalized_scope_shape_is_read(): void
    {
        // The served projection nests scope under normalized_scope — the inspector must read both shapes.
        $r = (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => 'x',
            'normalized_scope' => ['allowed_files' => ['app/A/B.php'], 'scope_in' => ['app/A/B.php']],
            'acceptance_criteria' => ['done'],
            'required_evidence' => ['ev'],
        ]);

        $this->assertTrue($r['self_sufficient']);
        $this->assertSame(1, $r['facts']['allowed_files_count']);
    }
}
