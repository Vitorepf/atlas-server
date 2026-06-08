<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\SemanticExtractionRequestBuilder;
use PHPUnit\Framework\TestCase;

class SemanticExtractionRequestBuilderTest extends TestCase
{
    public function test_schema_op_and_domain_are_correct(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build([
            'app/Services/Ai/Router.php',
        ]);

        $this->assertSame(SemanticExtractionRequestBuilder::SCHEMA, $request['schema_version']);
        $this->assertSame('atlas.code_graph.semantic_extraction_request.v1', $request['schema_version']);
        $this->assertSame('semantic_extract', $request['op']);
        $this->assertSame('programming', $request['domain_id']);
        $this->assertSame(['app/Services/Ai/Router.php'], $request['files']);
    }

    public function test_routing_strategy_is_atlas_decide_and_local_first_default_on(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build(['app/A.php']);

        $this->assertArrayHasKey('routing', $request);
        $this->assertSame('atlas_decide', $request['routing']['strategy']);
        // Builder requests a strategy; it never resolves to a concrete provider.
        $this->assertArrayNotHasKey('provider', $request['routing']);
        $this->assertArrayNotHasKey('model', $request['routing']);
        $this->assertTrue($request['routing']['allow_local_first']);
    }

    public function test_local_first_can_be_disabled_via_opts(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build(
            ['app/A.php'],
            ['allow_local_first' => false],
        );

        $this->assertFalse($request['routing']['allow_local_first']);
    }

    public function test_sensitive_files_are_excluded_from_file_list(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build([
            'app/Services/Ai/Router.php',
            '.env',
            'config/.env.production',
            'app/Support/secrets.php',
            'storage/keys/private_key.pem',
            'deploy/id_rsa',
            'certs/server.key',
            'app/Models/User.php',
        ]);

        // Only the two non-sensitive source files survive.
        $this->assertSame(
            ['app/Services/Ai/Router.php', 'app/Models/User.php'],
            $request['files'],
        );

        // None of the sensitive markers leak into the shipped file list.
        foreach ($request['files'] as $file) {
            $this->assertStringNotContainsString('.env', $file);
            $this->assertStringNotContainsString('secret', strtolower($file));
            $this->assertStringNotContainsString('.key', strtolower($file));
            $this->assertStringNotContainsString('.pem', strtolower($file));
        }
    }

    public function test_privacy_block_marks_exclusion_and_audits_dropped_paths(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build([
            'app/A.php',
            '.env',
            'app/B.php',
            'config/database/secret.php',
        ]);

        $this->assertTrue($request['privacy']['sensitive_excluded']);
        $this->assertSame(2, $request['privacy']['excluded_count']);
        $this->assertContains('.env', $request['privacy']['excluded_paths']);
        $this->assertContains('config/database/secret.php', $request['privacy']['excluded_paths']);
    }

    public function test_sensitive_excluded_flag_is_true_even_when_nothing_matched(): void
    {
        // The flag asserts the *policy ran*, not that a file happened to match.
        $request = (new SemanticExtractionRequestBuilder)->build(['app/A.php']);

        $this->assertTrue($request['privacy']['sensitive_excluded']);
        $this->assertSame(0, $request['privacy']['excluded_count']);
        $this->assertSame([], $request['privacy']['excluded_paths']);
    }

    public function test_invalid_blank_and_duplicate_paths_are_normalized(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build([
            '  app/A.php  ',
            'app/A.php',
            '',
            '   ',
            42,
            null,
            ['nested'],
            'app/B.php',
        ]);

        $this->assertSame(['app/A.php', 'app/B.php'], $request['files']);
    }

    public function test_extra_sensitive_markers_are_honored(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build(
            [
                'app/A.php',
                'app/Internal/CrownJewels.php',
            ],
            ['extra_sensitive_markers' => ['crownjewels']],
        );

        $this->assertSame(['app/A.php'], $request['files']);
        $this->assertContains('app/Internal/CrownJewels.php', $request['privacy']['excluded_paths']);
    }

    public function test_metadata_carries_counts_and_optional_reason(): void
    {
        $request = (new SemanticExtractionRequestBuilder)->build(
            ['app/A.php', '.env', 'app/B.php'],
            ['reason' => 'AP-812 backfill'],
        );

        $this->assertSame(SemanticExtractionRequestBuilder::SCHEMA, $request['metadata']['builder']);
        $this->assertSame(3, $request['metadata']['candidate_count']);
        $this->assertSame(2, $request['metadata']['requested_count']);
        $this->assertSame('AP-812 backfill', $request['metadata']['reason']);
    }
}
