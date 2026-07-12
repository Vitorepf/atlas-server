<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use ReflectionClass;
use Tests\TestCase;

/**
 * MAXE-08 — summary-first packing (no mid-body truncation when the summary alone fits).
 *
 * Ladder: body-inteiro → summary-inteiro (body omitted, `body_omitted=true`)
 *   → body truncated with marker (last resort).
 */
final class Maxe08SummaryFirstPackingTest extends TestCase
{
    private function invoke(array $item, int $maxChars): array
    {
        $service = app(AtlasOpenBrainContextPackService::class);
        $method = (new ReflectionClass($service))->getMethod('compactMemoryItemForPack');
        $method->setAccessible(true);

        return $method->invoke($service, $item, $maxChars);
    }

    public function test_body_fits_within_budget_returns_item_unchanged(): void
    {
        $item = [
            'title' => 'T',
            'summary' => 'S',
            'body' => 'short body',
        ];

        $out = $this->invoke($item, 200);

        $this->assertSame('short body', $out['body']);
        $this->assertArrayNotHasKey('body_omitted', $out);
    }

    public function test_summary_first_when_body_does_not_fit_but_summary_does(): void
    {
        $title = 'Recall item title';
        $summary = 'Short summary that fits alone within the budget.';
        $body = str_repeat('x', 4000);

        $maxChars = strlen($title) + strlen($summary) + 100; // Enough for title+summary+small slack, not body

        $out = $this->invoke([
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
        ], $maxChars);

        $this->assertSame('', $out['body'], 'body must be omitted (no mid-body truncation)');
        $this->assertTrue($out['body_omitted'] ?? false, 'body_omitted flag must be set');
        $this->assertSame($title, $out['title'], 'title must be preserved intact');
        $this->assertSame($summary, $out['summary'], 'summary must be preserved intact');
        $this->assertStringNotContainsString('… [truncated]', (string) $out['body']);
    }

    public function test_falls_back_to_truncation_marker_when_summary_alone_overflows(): void
    {
        // title+summary already larger than the budget → we cannot preserve summary
        // intact; ladder falls back to marker-truncation of the body (last resort).
        $title = str_repeat('T', 300);
        $summary = str_repeat('S', 300);
        $body = str_repeat('B', 400);

        $out = $this->invoke([
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
        ], 400);

        $this->assertArrayNotHasKey('body_omitted', $out);
        // body is truncated with the canonical marker as last resort.
        $this->assertTrue(
            str_contains((string) $out['body'], '… [truncated]')
                || strlen((string) $out['body']) < strlen($body),
            'body must be truncated (marker or slice) as last resort',
        );
    }
}
