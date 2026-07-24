<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\Teto10PredictedRevertReviewDigest;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasAcosTeto10ReviewDigestCommand extends Command
{
    use EmitsCanonicalJson;

    public const DEFAULT_INPUT_RELATIVE_PATH = 'app/atlas/acos/teto10-review-digest-items.jsonl';

    protected $signature = 'atlas:acos:teto10-review-digest
        {--input= : JSONL review item rows}
        {--limit=50 : Maximum review items to show}
        {--json : Emit canonical JSON instead of markdown}';

    protected $description = 'TETO-10 predicted-revert review digest (markdown/CLI only).';

    public function handle(): int
    {
        $digest = Teto10PredictedRevertReviewDigest::compose(
            $this->readRows($this->inputPath()),
            max(1, min(200, (int) $this->option('limit'))),
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($digest));

            return self::SUCCESS;
        }

        $this->line(Teto10PredictedRevertReviewDigest::renderMarkdown($digest));

        return self::SUCCESS;
    }

    private function inputPath(): string
    {
        $input = $this->option('input');
        if (is_string($input) && trim($input) !== '') {
            return trim($input);
        }

        return storage_path(self::DEFAULT_INPUT_RELATIVE_PATH);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }

                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
