<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewPacketBuilder;
use Tests\TestCase;

final class AgentMergeReviewPacketBuilderTest extends TestCase
{
    private function builder(): AgentMergeReviewPacketBuilder
    {
        return new AgentMergeReviewPacketBuilder();
    }

    private function diffManifest(array $overrides = []): array
    {
        return array_merge([
            'source' => 'synthetic_diff_manifest',
            'base_revision' => 'abc123',
            'head_revision' => 'def456',
            'files' => [
                ['path' => 'app/Services/Foo.php', 'change_kind' => 'modified', 'lines_added' => 10, 'lines_deleted' => 2, 'hunk_count' => 1],
                ['path' => 'tests/Unit/FooTest.php', 'change_kind' => 'added', 'lines_added' => 20, 'lines_deleted' => 0, 'hunk_count' => 1],
            ],
        ], $overrides);
    }

    private function artifactManifest(array $overrides = []): array
    {
        return array_merge([
            'artifacts' => [
                ['kind' => 'phpunit', 'name' => 'test_suite', 'status' => 'passed'],
            ],
        ], $overrides);
    }

    // ── AC: packets include artifact_provenance with hashes for diff, evidence and review inputs ──

    public function test_packet_includes_artifact_provenance(): void
    {
        $r = $this->builder()->build($this->diffManifest(), $this->artifactManifest());

        $this->assertArrayHasKey('artifact_provenance', $r['packet']);
        $provenance = $r['packet']['artifact_provenance'];
        $this->assertArrayHasKey('diff_hash', $provenance);
        $this->assertArrayHasKey('evidence_hash', $provenance);
        $this->assertArrayHasKey('review_input_hash', $provenance);
        $this->assertNotEmpty($provenance['diff_hash']);
        $this->assertNotEmpty($provenance['evidence_hash']);
        $this->assertNotEmpty($provenance['review_input_hash']);
    }

    public function test_artifact_provenance_hashes_are_deterministic(): void
    {
        $diff = $this->diffManifest();
        $artifacts = $this->artifactManifest();
        $context = ['task_packet_id' => 'tp-1'];

        $a = $this->builder()->build($diff, $artifacts, $context);
        $b = $this->builder()->build($diff, $artifacts, $context);

        $this->assertSame($a['packet']['artifact_provenance']['diff_hash'], $b['packet']['artifact_provenance']['diff_hash']);
        $this->assertSame($a['packet']['artifact_provenance']['evidence_hash'], $b['packet']['artifact_provenance']['evidence_hash']);
    }

    // ── AC: changed files are grouped into risk buckets ──

    public function test_changed_files_grouped_into_risk_buckets(): void
    {
        $r = $this->builder()->build([
            'files' => [
                ['path' => 'app/Services/Foo.php', 'change_kind' => 'modified'],
                ['path' => 'tests/Unit/FooTest.php', 'change_kind' => 'added'],
                ['path' => 'database/migrations/2026_01_01_create_foo.php', 'change_kind' => 'added'],
                ['path' => 'storage/app/test.txt', 'change_kind' => 'modified'],
                ['path' => 'config/app.php', 'change_kind' => 'modified'],
                ['path' => 'README.md', 'change_kind' => 'modified'],
            ],
        ]);

        $groups = $r['packet']['changed_file_risk_groups'];
        $this->assertArrayHasKey('implementation', $groups);
        $this->assertArrayHasKey('test', $groups);
        $this->assertArrayHasKey('migration', $groups);
        $this->assertArrayHasKey('storage', $groups);
        $this->assertArrayHasKey('config', $groups);
        $this->assertArrayHasKey('other', $groups);
        $this->assertContains('app/Services/Foo.php', $groups['implementation']);
        $this->assertContains('tests/Unit/FooTest.php', $groups['test']);
        $this->assertContains('database/migrations/2026_01_01_create_foo.php', $groups['migration']);
        $this->assertContains('storage/app/test.txt', $groups['storage']);
        $this->assertContains('config/app.php', $groups['config']);
        $this->assertContains('README.md', $groups['other']);
    }

    // ── AC: executable_proof_requirements are emitted and generic green text is not enough ──

    public function test_executable_proof_requirements_emitted(): void
    {
        $r = $this->builder()->build($this->diffManifest(), $this->artifactManifest());

        $this->assertArrayHasKey('executable_proof_requirements', $r['packet']);
        $reqs = $r['packet']['executable_proof_requirements'];
        $this->assertArrayHasKey('requirements', $reqs);
        $this->assertArrayHasKey('generic_green_text_insufficient', $reqs);
        $this->assertArrayHasKey('review_ready', $reqs);
    }

    public function test_generic_green_text_is_insufficient_for_review(): void
    {
        $r = $this->builder()->build($this->diffManifest(), [
            'artifacts' => [
                ['kind' => 'unknown', 'name' => 'generic', 'status' => 'green'],
            ],
        ]);

        $reqs = $r['packet']['executable_proof_requirements'];
        $this->assertTrue($reqs['generic_green_text_insufficient']);
        $this->assertFalse($reqs['review_ready']);
    }

    public function test_passed_test_suite_with_concrete_kind_meets_executable_proof(): void
    {
        $r = $this->builder()->build($this->diffManifest(), [
            'artifacts' => [
                ['kind' => 'phpunit', 'name' => 'test_suite', 'status' => 'passed'],
            ],
        ]);

        $reqs = $r['packet']['executable_proof_requirements'];
        $this->assertFalse($reqs['generic_green_text_insufficient']);
        $this->assertTrue($reqs['review_ready']);
    }

    public function test_failed_artifact_does_not_meet_executable_proof(): void
    {
        $r = $this->builder()->build($this->diffManifest(), [
            'artifacts' => [
                ['kind' => 'phpunit', 'name' => 'test_suite', 'status' => 'failed'],
            ],
        ]);

        $reqs = $r['packet']['executable_proof_requirements'];
        $this->assertFalse($reqs['review_ready']);
    }

    // ── schema and structure ──

    public function test_output_has_required_keys(): void
    {
        $r = $this->builder()->build($this->diffManifest(), $this->artifactManifest());

        $this->assertSame(AgentMergeReviewPacketBuilder::SCHEMA_VERSION, $r['schema_version']);
        $this->assertArrayHasKey('packet', $r);
        $this->assertArrayHasKey('packet_hash', $r);
    }

    public function test_packet_is_deterministic(): void
    {
        $diff = $this->diffManifest();
        $artifacts = $this->artifactManifest();
        $context = ['packet_id' => 'pkt-1', 'generated_at' => '2026-07-05T10:00:00Z'];

        $a = $this->builder()->build($diff, $artifacts, $context);
        $b = $this->builder()->build($diff, $artifacts, $context);

        $this->assertSame($a['packet_hash'], $b['packet_hash']);
    }
}
