<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopConstraintsBlockAssembler;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopConstraintsBlockAssembler::build()} at the operator surface: reads
 * machine-validated findings from a JSON file and, for a campaign, emits the assembled constraints block
 * (tree shape + pruned lessons from the campaign tree + the validated findings) as deterministic facts.
 * Read-only — it only reads the campaign tree and assembles text; it writes nothing.
 */
final class AtlasLoopConstraintsBlockCommand extends Command
{
    protected $signature = 'atlas:loop:constraints-block-assemble {--campaign=} {--input=} {--note=} {--json}';

    protected $description = 'Read-only: assemble the campaign constraints block (tree shape + pruned lessons + validated findings).';

    public function handle(AtlasLoopConstraintsBlockAssembler $assembler): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'campaign_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $findings = $this->validatedFindings();
        if ($findings === null) {
            return self::INVALID;
        }

        $note = trim((string) $this->option('note'));
        $block = $assembler->build($campaign, $findings, $note !== '' ? $note : null);

        $this->line((string) json_encode([
            'schema' => 'atlas.loop.constraints_block.v1',
            'campaign_id' => $campaign,
            'finding_count' => count($findings),
            'block' => $block,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null  null signals an emitted error (caller returns INVALID)
     */
    private function validatedFindings(): ?array
    {
        $input = trim((string) $this->option('input'));
        if ($input === '') {
            return [];
        }
        if (! is_file($input)) {
            $this->line((string) json_encode(['status' => 'input_not_found', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return null;
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'input' => $input], JSON_UNESCAPED_SLASHES));

            return null;
        }

        return array_values(array_map(static fn ($v): string => (string) $v, array_filter($decoded, static fn ($v): bool => is_string($v) || is_numeric($v))));
    }
}
