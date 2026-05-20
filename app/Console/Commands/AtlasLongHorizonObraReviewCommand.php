<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\ObraReviewService;
use Illuminate\Console\Command;

final class AtlasLongHorizonObraReviewCommand extends Command
{
    protected $signature = 'atlas:long-horizon:obra-review
        {--intake= : Forge intake id or uuid; defaults to latest}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'TEOS-I3 weekly/monthly Forge Obra review receipt. Advisory-only; no mutation.';

    public function handle(ObraReviewService $service): int
    {
        $payload = $service->review(['intake' => $this->option('intake')]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('<info>Atlas TEOS-I3 Obra Review</info>');
            $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
            $this->line('Review kind: <comment>'.($payload['review_kind'] ?? 'unknown').'</comment>');
            $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
            $this->line('Review hash: <comment>'.($payload['review_hash'] ?? 'missing').'</comment>');
        }

        if ((bool) $this->option('strict') && ($payload['status'] ?? null) !== ObraReviewService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
