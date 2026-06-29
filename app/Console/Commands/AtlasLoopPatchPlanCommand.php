<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchPlanner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionNativePatchPlanner::plan()} at the operator surface:
 * previews the native patch plan for a packet (target files, matched template ids, variables, required imports,
 * test plan, risk notes) — refusing packets that need provider reasoning, lack allowed_files, escape scope, or
 * match no template.
 *
 * Pure + read-only: PLANNING only — it applies no patch and mutates nothing.
 */
final class AtlasLoopPatchPlanCommand extends Command
{
    protected $signature = 'atlas:loop:patch-plan {--packet=} {--json}';

    protected $description = 'Read-only native patch plan preview for a packet (no patch applied).';

    public function handle(): int
    {
        $raw = trim((string) $this->option('packet'));
        if ($raw === '') {
            return $this->refuse('patch-plan requires --packet=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $packet = json_decode($raw, true);
        if (! is_array($packet)) {
            return $this->refuse('--packet must be a JSON object');
        }

        try {
            $plan = app(AtlasSelfConstructionNativePatchPlanner::class)->plan($packet);
        } catch (RuntimeException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'plan_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('plan_id: '.$plan['plan_id']);
            $this->line('templates: '.implode(',', $plan['template_ids']).'  targets: '.implode(',', $plan['target_files']));
        }

        return self::SUCCESS;
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
