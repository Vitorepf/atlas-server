<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\AcosMax\Teto10PredictedRevertReviewDigest;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasAcosTeto10ReviewDigestCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/teto10-review-digest-'.bin2hex(random_bytes(4)));
        @mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_jsonl_input_emits_canonical_digest_json(): void
    {
        $input = $this->inputPath();
        $this->appendJsonl($input, $this->tenReviewItems());

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:teto10-review-digest', [
            '--input' => $input,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exit);
        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Teto10PredictedRevertReviewDigest::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(10, $payload['item_count']);
        $this->assertSame('decision:ASI-11', $payload['groups'][0]['group_key']);
        $this->assertSame('high', $payload['groups'][0]['highest_predicted_revert_band']);
        $this->assertSame('php artisan atlas:rollback-cascade --decision-id=ASI-11 --dry-run', $payload['groups'][0]['items'][0]['reverse_command']);
    }

    public function test_default_output_is_markdown_with_inline_reverse_commands(): void
    {
        $input = $this->inputPath();
        $this->appendJsonl($input, $this->tenReviewItems());

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:teto10-review-digest', [
            '--input' => $input,
        ], $output);

        $this->assertSame(0, $exit);
        $markdown = $output->fetch();

        $this->assertStringContainsString('# ACOS TETO-10 predicted-revert review digest', $markdown);
        $this->assertStringContainsString('## Predicted revert: high', $markdown);
        $this->assertStringContainsString('### decision:ASI-11', $markdown);
        $this->assertStringContainsString('evidence: evidence:high-1', $markdown);
        $this->assertStringContainsString('diff-ref: diff:high-1', $markdown);
        $this->assertStringContainsString('reverse: `php artisan atlas:rollback-cascade --decision-id=ASI-11 --dry-run`', $markdown);
    }

    private function inputPath(): string
    {
        return $this->root.'/items.jsonl';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tenReviewItems(): array
    {
        return [
            $this->item('low-1', 'Docs cleanup', 'low', null, 'docs'),
            $this->item('high-1', 'Rollback lineage fix', 'high', 'ASI-11', 'lineage'),
            $this->item('sweet-1', 'Ask cadence tune', 'sweet', 'MULTN15-05', 'operator-asks'),
            $this->item('high-2', 'Review advisory ordering', 'high', 'MULTN15-08', 'review'),
            $this->item('low-2', 'Scoreboard wording', 'low', null, 'docs'),
            $this->item('sweet-2', 'Held queue surfacing', 'sweet', 'FEE-12', 'review'),
            $this->item('high-3', 'Obra lineage stamped', 'high', 'MULTH-06', 'lineage'),
            $this->item('low-3', 'Markdown cap', 'low', null, 'review'),
            $this->item('sweet-3', 'Diff ref normalization', 'sweet', 'MAXH-04', 'handles'),
            $this->item('low-4', 'Evidence label', 'low', null, 'evidence'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function item(string $id, string $title, string $band, ?string $decisionId, string $family): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'decision_id' => $decisionId,
            'family' => $family,
            'predicted_revert_band' => $band,
            'evidence_refs' => ['evidence:'.$id],
            'diff_ref' => 'diff:'.$id,
            'reverse_command' => 'php artisan atlas:rollback-cascade --decision-id='.($decisionId ?? $family).' --dry-run',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function appendJsonl(string $path, array $rows): void
    {
        foreach ($rows as $row) {
            file_put_contents(
                $path,
                json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND,
            );
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
