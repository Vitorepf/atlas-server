<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::finalize()} at
 * the operator surface: computes the runtime-promotion receipt hash for an operator draft and (only when the
 * explicit write flag is set, and the draft is operator-ready with no forbidden runtime flags) writes the
 * finalized hash back into the draft artifact, reporting the computed hash + status.
 *
 * Read-only MODE read_only_runtime_promotion_draft_hash_finalizer: the only write is the draft artifact on its
 * configured disk — no queue/app mutation, no runtime enabled, no provider call.
 */
final class AtlasLoopDraftHashFinalizeCommand extends Command
{
    protected $signature = 'atlas:loop:draft-hash-finalize {--options=} {--json}';

    protected $description = 'Compute/persist a runtime-promotion draft receipt hash (writes only the draft artifact).';

    public function handle(): int
    {
        $options = [];
        $raw = trim((string) $this->option('options'));
        if ($raw !== '') {
            if (is_file($raw) && is_readable($raw)) {
                $raw = (string) file_get_contents($raw);
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->line((string) json_encode([
                    'outcome' => 'refused',
                    'reason' => 'usage_error',
                    'message' => '--options must be a JSON object',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                return self::FAILURE;
            }
            $options = $decoded;
        }

        $result = app(AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::class)->finalize($options);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status: '.$result['status'].'  written: '.($result['written'] ? 'yes' : 'no'));
            $this->line('computed_receipt_hash: '.$result['computed_receipt_hash']);
        }

        return self::SUCCESS;
    }
}
