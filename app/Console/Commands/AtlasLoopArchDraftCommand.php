<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopArchitectureDraftService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopArchitectureDraftService::draft()} at the operator surface: drafts an
 * architecture (proposed files + seams) from a leverage-decision envelope and an orientation snapshot, via the
 * frontier writer + a cross-model critic — or null when the feature flag is off / inputs are insufficient.
 *
 * Read-only: it DRAFTS only (the critic can refuse it) and writes nothing. Flag-gated default-OFF ⇒ null.
 */
final class AtlasLoopArchDraftCommand extends Command
{
    protected $signature = 'atlas:loop:arch-draft {--leverage=} {--orientation=} {--json}';

    protected $description = 'Read-only architecture draft from a leverage decision + orientation snapshot (or null).';

    public function handle(): int
    {
        $leverage = $this->readJson('leverage');
        $orientation = $this->readJson('orientation');
        if ($leverage === null) {
            return $this->refuse('arch-draft requires --leverage=<json object or path>');
        }
        if ($orientation === null) {
            return $this->refuse('arch-draft requires --orientation=<json object or path>');
        }

        $draft = app(AtlasLoopArchitectureDraftService::class)->draft($leverage, $orientation);

        $facts = ['schema' => 'atlas.loop.arch_draft.v1', 'draft' => $draft];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('draft: '.($draft === null ? 'null' : (($draft['drafted'] ?? false) ? 'drafted' : ('refused:'.($draft['reason'] ?? '?')))));
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
