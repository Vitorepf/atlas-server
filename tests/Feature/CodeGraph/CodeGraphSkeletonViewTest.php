<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSkeletonView;
use Tests\TestCase;

/**
 * AP-815 · E-7 — proves progressive disclosure: long bodies come back as
 * signature-only skeletons + a drill map, short ones come back whole, and the
 * skeleton token cost is provably below the full read. Pure transform, no DB.
 */
final class CodeGraphSkeletonViewTest extends TestCase
{
    private function skeletonView(): CodeGraphSkeletonView
    {
        return new CodeGraphSkeletonView;
    }

    public function test_long_body_is_elided_to_signature_only_and_is_drillable(): void
    {
        $longBody = "function big(): void {\n".str_repeat("    doWork();\n", 60).'}';

        $out = $this->skeletonView()->skeletonize([
            [
                'id' => 'app\\big::method',
                'signature' => 'public function big(): void',
                'body' => $longBody,
                'kind' => 'method',
            ],
        ]);

        $this->assertCount(1, $out['skeletons']);
        $skeleton = $out['skeletons'][0];

        $this->assertSame('app\\big::method', $skeleton['id']);
        $this->assertSame('public function big(): void', $skeleton['signature']);
        $this->assertSame('method', $skeleton['kind']);
        $this->assertTrue($skeleton['elided'], 'a body over the threshold must be elided');

        // The drill map names exactly the elided symbol.
        $this->assertSame(['app\\big::method' => true], $out['drillable']);

        // The body itself never leaks into the skeleton — only the signature survives.
        $this->assertStringNotContainsString('doWork();', $skeleton['signature']);

        $this->assertSame(1, $out['stats']['total']);
        $this->assertSame(1, $out['stats']['elided']);
        // Skeleton cost (signature only) is strictly cheaper than the full body read.
        $this->assertLessThan(
            $out['stats']['approx_tokens_full'],
            $out['stats']['approx_tokens_skeleton'],
            'eliding a big body must save tokens',
        );
    }

    public function test_short_symbol_is_not_elided_and_not_drillable(): void
    {
        $out = $this->skeletonView()->skeletonize([
            [
                'id' => 'helpers::tiny',
                'signature' => 'function tiny(): int',
                'content' => 'function tiny(): int { return 1; }',
                'kind' => 'function',
            ],
        ]);

        $skeleton = $out['skeletons'][0];
        $this->assertFalse($skeleton['elided'], 'a body under the threshold is returned whole');
        $this->assertSame([], $out['drillable'], 'a non-elided symbol is not drillable');
        $this->assertSame(0, $out['stats']['elided']);
        $this->assertSame(1, $out['stats']['total']);
    }

    public function test_token_math_skeleton_is_cheaper_overall_with_a_mix(): void
    {
        $bigA = str_repeat('A', 4000);
        $bigB = str_repeat('B', 2000);

        $out = $this->skeletonView()->skeletonize([
            ['id' => 'a', 'signature' => 'sig a', 'content' => $bigA, 'kind' => 'class'],
            ['id' => 'b', 'signature' => 'sig b', 'content' => $bigB, 'kind' => 'class'],
            ['id' => 'c', 'signature' => 'sig c', 'content' => 'short()', 'kind' => 'fn'],
        ]);

        $this->assertSame(3, $out['stats']['total']);
        $this->assertSame(2, $out['stats']['elided'], 'the two big bodies elide, the short one does not');
        $this->assertSame(['a' => true, 'b' => true], $out['drillable']);

        // Token estimates follow chars/4. Skeleton keeps only the three "sig x" (5 chars
        // each = 15 chars => ceil(15/4) = 4). Full reads 4000 + 2000 + 7 = 6007 chars
        // => ceil(6007/4) = 1502. The skeleton is dramatically cheaper.
        $this->assertSame(4, $out['stats']['approx_tokens_skeleton']);
        $this->assertSame(1502, $out['stats']['approx_tokens_full']);
        $this->assertLessThan($out['stats']['approx_tokens_full'], $out['stats']['approx_tokens_skeleton']);
    }

    public function test_empty_input_is_fully_zeroed(): void
    {
        $out = $this->skeletonView()->skeletonize([]);

        $this->assertSame([], $out['skeletons']);
        $this->assertSame([], $out['drillable']);
        $this->assertSame([
            'total' => 0,
            'elided' => 0,
            'approx_tokens_skeleton' => 0,
            'approx_tokens_full' => 0,
        ], $out['stats']);
    }

    public function test_signature_falls_back_to_first_non_empty_line_of_content(): void
    {
        $body = "\n   \nclass Derived {\n".str_repeat("  prop;\n", 80)."}\n";

        $out = $this->skeletonView()->skeletonize([
            ['id' => 'derived', 'content' => $body, 'kind' => 'class'],
        ]);

        $skeleton = $out['skeletons'][0];
        // Leading blank lines are skipped; the class header becomes the signature.
        $this->assertSame('class Derived {', $skeleton['signature']);
        $this->assertTrue($skeleton['elided']);
    }

    public function test_missing_fields_are_tolerated_and_id_is_synthesised(): void
    {
        $out = $this->skeletonView()->skeletonize([
            [],                       // wholly empty symbol
            ['kind' => 'orphan'],     // kind only, no id/signature/body
            'not-an-array',           // skipped entirely
            ['id' => '', 'content' => ''], // blank id + empty content
        ]);

        // The non-array entry is dropped; three skeletons remain.
        $this->assertSame(3, $out['stats']['total']);
        $this->assertSame(0, $out['stats']['elided']);
        $this->assertSame([], $out['drillable'], 'no body means nothing to elide or drill');

        // Synthetic, deterministic, collision-free ids by original position.
        $this->assertSame('__symbol_0', $out['skeletons'][0]['id']);
        $this->assertSame('__symbol_1', $out['skeletons'][1]['id']);
        $this->assertSame('orphan', $out['skeletons'][1]['kind']);
        // The non-array sat at index 2, so the blank-id symbol keeps index 3.
        $this->assertSame('__symbol_3', $out['skeletons'][2]['id']);
        $this->assertSame('', $out['skeletons'][0]['signature']);
    }

    public function test_content_is_preferred_over_body_when_both_present(): void
    {
        $out = $this->skeletonView()->skeletonize([
            [
                'id' => 'dual',
                'content' => 'the-real-content-line',
                'body' => 'the-ignored-body-line',
            ],
        ]);

        // No explicit signature => first line of the PREFERRED `content`.
        $this->assertSame('the-real-content-line', $out['skeletons'][0]['signature']);
    }

    public function test_opts_override_threshold_and_zero_elides_every_non_empty_body(): void
    {
        $symbols = [
            ['id' => 'x', 'signature' => 'sig', 'content' => 'tiny'],
        ];

        // Default threshold (200) leaves a 4-char body untouched.
        $this->assertFalse($this->skeletonView()->skeletonize($symbols)['skeletons'][0]['elided']);

        // A 0 threshold elides any non-empty body (most aggressive, still well-defined).
        $aggressive = $this->skeletonView()->skeletonize($symbols, ['elide_chars' => 0]);
        $this->assertTrue($aggressive['skeletons'][0]['elided']);
        $this->assertSame(['x' => true], $aggressive['drillable']);

        // A garbage threshold (NaN) falls back to the default — body stays whole.
        $this->assertFalse(
            $this->skeletonView()->skeletonize($symbols, ['elide_chars' => NAN])['skeletons'][0]['elided'],
        );
    }

    public function test_threshold_boundary_is_strictly_greater_than(): void
    {
        $exactly = str_repeat('x', 10); // length == threshold
        $over = str_repeat('x', 11);    // length > threshold

        $out = $this->skeletonView()->skeletonize([
            ['id' => 'eq', 'signature' => 's', 'content' => $exactly],
            ['id' => 'gt', 'signature' => 's', 'content' => $over],
        ], ['elide_chars' => 10]);

        $this->assertFalse($out['skeletons'][0]['elided'], 'length == threshold is NOT elided');
        $this->assertTrue($out['skeletons'][1]['elided'], 'length > threshold IS elided');
    }

    public function test_output_is_deterministic_for_identical_input(): void
    {
        $symbols = [
            ['id' => 'a', 'signature' => 'sa', 'content' => str_repeat('z', 500), 'kind' => 'm'],
            ['id' => 'b', 'content' => 'short', 'kind' => 'fn'],
        ];

        $this->assertSame(
            $this->skeletonView()->skeletonize($symbols),
            $this->skeletonView()->skeletonize($symbols),
            'a pure transform must yield byte-identical output for identical input',
        );
    }
}
