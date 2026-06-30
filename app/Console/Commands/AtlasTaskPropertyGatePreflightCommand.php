<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\GovernedTargets\AtlasTaskPropertyGatedTargetPolicy;
use Illuminate\Console\Command;

/**
 * Read-only preflight: classify allowed_files targets and print the required
 * constitution evidence contract before a muscle starts a Brain / AutonomousEvolution task.
 *
 * NO enqueue, NO report, NO commit, NO git, NO provider calls — pure classification output.
 */
final class AtlasTaskPropertyGatePreflightCommand extends Command
{
    protected $signature = 'atlas:task:property-gate-preflight
        {--task-packet-id= : Resolve file list from the task serving queue (not yet wired — use --files)}
        {--files=* : File paths to classify (may be repeated or comma-separated within each value)}
        {--json : Emit structured JSON output instead of human-readable lines}';

    protected $description = 'Classify task file targets and print the required constitution evidence contract. Read-only.';

    public function handle(AtlasTaskPropertyGatedTargetPolicy $policy): int
    {
        $files = $this->resolveFiles();

        if ($files === []) {
            $this->error('No files to classify. Provide --files or --task-packet-id.');

            return self::FAILURE;
        }

        $classified = $policy->classifyAll($files);

        $requiredEvidence = $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED] !== []
            ? ['constitution_gate_receipt']
            : [];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ordinary' => $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_ORDINARY],
                'property_gated' => $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED],
                'forbidden' => $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN],
                'required_evidence' => $requiredEvidence,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN] !== []
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->line('--- Property Gate Preflight ---');
        $this->printSection('ordinary', $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_ORDINARY]);
        $this->printSection('property_gated', $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_PROPERTY_GATED]);
        $this->printSection('forbidden', $classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN]);

        if ($requiredEvidence !== []) {
            $this->line('required_evidence: '.implode(', ', $requiredEvidence));
            $this->line('  → include constitution_gate_receipt in required_evidence before assigning to a muscle.');
        } else {
            $this->line('required_evidence: (none beyond normal gates)');
        }

        if ($classified[AtlasTaskPropertyGatedTargetPolicy::CLASSIFICATION_FORBIDDEN] !== []) {
            $this->error('BLOCKED: forbidden pétreo targets present — remove them before implementing.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $items */
    private function printSection(string $label, array $items): void
    {
        $this->line("{$label}:");
        if ($items === []) {
            $this->line('  (none)');
        } else {
            foreach ($items as $item) {
                $this->line("  - {$item}");
            }
        }
    }

    /** @return list<string> */
    private function resolveFiles(): array
    {
        $files = [];

        foreach ((array) ($this->option('files') ?? []) as $item) {
            foreach (explode(',', (string) $item) as $f) {
                $f = trim($f);
                if ($f !== '') {
                    $files[] = $f;
                }
            }
        }

        if ($files === [] && (string) ($this->option('task-packet-id') ?? '') !== '') {
            $this->warn('--task-packet-id lookup is not yet wired to the serving disk. Provide --files explicitly.');
        }

        return $files;
    }
}
