<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneGovernanceDossier;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasProjectLaneGovernanceDossier::export()} at the operator surface: rolls a project
 * lane's governance gates (admission / isolation / verification-court / release-governor / receipt-policy /
 * rollback / knowledge-sync) plus the autonomy-readiness composition into ONE dossier, emitting the proof
 * summary as deterministic facts. Pure and read-only.
 *
 * --sections / --autonomy-readiness accept inline JSON or a path to a JSON file.
 */
final class AtlasLoopLaneGovernanceCommand extends Command
{
    protected $signature = 'atlas:loop:lane-governance {--project-id=} {--sections=} {--autonomy-readiness=} {--json}';

    protected $description = 'Read-only: roll a project lane governance gates into one dossier (proof summary).';

    public function handle(AtlasProjectLaneGovernanceDossier $dossier): int
    {
        $projectId = trim((string) $this->option('project-id'));
        if ($projectId === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'project_id_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $this->line((string) json_encode(
            $dossier->export(
                $projectId,
                $this->jsonOption('sections'),
                $this->jsonOption('autonomy-readiness'),
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOption(string $name): array
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return [];
        }
        $raw = is_file((string) $value) ? (string) file_get_contents((string) $value) : (string) $value;
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
