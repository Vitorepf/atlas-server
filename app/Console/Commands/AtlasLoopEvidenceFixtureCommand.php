<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService::build()} at the
 * operator surface: builds operator-evidence-receipt FIXTURES (with receipt diagnostics, a synthetic fixture
 * catalog, the anti-cheat policy and the non-execution guarantees) for tests/inspection, emitted as
 * deterministic facts. Pure and read-only — test fixtures are NOT operator evidence; it persists nothing.
 *
 * --options accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopEvidenceFixtureCommand extends Command
{
    protected $signature = 'atlas:loop:evidence-fixture {--options=} {--json}';

    protected $description = 'Read-only: build operator-evidence-receipt fixtures for tests/inspection (persists nothing).';

    public function handle(AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService $lab): int
    {
        $optionsValue = $this->option('options');
        $options = [];
        if ($optionsValue !== null && trim((string) $optionsValue) !== '') {
            $raw = is_file((string) $optionsValue) ? (string) file_get_contents((string) $optionsValue) : (string) $optionsValue;
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode(['status' => 'invalid_json', 'options' => (string) $optionsValue], JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }
            $options = $decoded;
        }

        $this->line((string) json_encode(
            $lab->build($options),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
