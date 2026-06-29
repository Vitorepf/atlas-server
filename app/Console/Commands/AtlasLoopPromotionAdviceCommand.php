<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\SelfConstructionCapabilityPromotionStepAdvisor;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see SelfConstructionCapabilityPromotionStepAdvisor::adviseNext()} at the operator
 * surface: given the current ladder level + prerequisite signals, emits the next promotion step the capability
 * must achieve (label, next_level, required proof, what is still missing, is-promotable-now).
 *
 * Pure + read-only: it advises and reports — it mutates nothing, calls no provider/DB.
 */
final class AtlasLoopPromotionAdviceCommand extends Command
{
    protected $signature = 'atlas:loop:promotion-advice {--level=0} {--signals=} {--json}';

    protected $description = 'Read-only next capability-ladder promotion step advice for the current level + signals.';

    public function handle(): int
    {
        $level = (int) $this->option('level');

        $signals = [];
        $raw = trim((string) $this->option('signals'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--signals must be a JSON object',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $signals = $decoded;
        }

        $advice = app(SelfConstructionCapabilityPromotionStepAdvisor::class)->adviseNext($level, $signals);

        if ($this->option('json')) {
            $this->line((string) json_encode($advice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            if ($advice['at_ceiling']) {
                $this->line('at_ceiling: no further promotion');
            } else {
                $this->line($advice['next_promotion'].' -> level '.$advice['next_level'].'  promotable_now: '.($advice['is_promotable_now'] ? 'yes' : 'no'));
                $this->line('required: '.$advice['required_proof']);
            }
        }

        return self::SUCCESS;
    }
}
