<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Self-Construction OS sub-command shell (placeholder).
 *
 * This file was committed as a broken template (empty family, invalid `:::`
 * signature, and a fatal `=> ,` syntax error) which aborted Laravel console
 * command auto-discovery — making EVERY Artisan::call fail with
 * CommandNotFoundException in the full-app/test kernel. Repaired to a valid,
 * harmless placeholder. The real, filled family shells are
 * `atlas:ai:self-construction:{codex,control-plane,core,...}`; the canonical
 * action set is served by the `atlas:ai:self-construction` mother command.
 */
class AtlasAiSelfConstructionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:self-construction:shell-placeholder {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — unassigned placeholder shell (no family wired).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'unassigned',
            'estimated_service_count' => 0,
            'status' => 'shell_placeholder',
            'detail' => 'Unassigned placeholder shell. The canonical action set is served by the atlas:ai:self-construction mother command; filled family shells are atlas:ai:self-construction:{codex,control-plane,core,...}.',
            'mother_command' => 'atlas:ai:self-construction',
            'docs_canon' => [
                'docs/engineering-knowledge-base/atlas-self-construction-catalog.md',
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
            ],
        ];
        $this->jsonLine($payload);

        return self::SUCCESS;
    }
}
