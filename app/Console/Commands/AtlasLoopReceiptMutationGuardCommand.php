<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptMutationGuardService;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasSelfConstructionHumanCompletionReceiptMutationGuardService::compare()} at the
 * operator surface: reads a BEFORE and an AFTER human-completion receipt (JSON files) and emits the mutation
 * verdict — whether any protected field was tampered between them — as deterministic facts. Read-only: it
 * only compares the two payloads; it grants no execution, dispatch, or promotion.
 */
final class AtlasLoopReceiptMutationGuardCommand extends Command
{
    protected $signature = 'atlas:loop:receipt-mutation-guard {--before=} {--after=} {--json}';

    protected $description = 'Read-only: detect tampering of protected human-completion receipt fields (before vs after).';

    public function handle(AtlasSelfConstructionHumanCompletionReceiptMutationGuardService $guard): int
    {
        $before = $this->readReceipt('before');
        if ($before === null) {
            return self::INVALID;
        }
        $after = $this->readReceipt('after');
        if ($after === null) {
            return self::INVALID;
        }

        $this->line((string) json_encode(
            $guard->compare($before, $after),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readReceipt(string $option): ?array
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $option.'_required'], JSON_UNESCAPED_SLASHES));

            return null;
        }
        if (! is_file($path)) {
            $this->line((string) json_encode(['status' => 'receipt_not_found', 'option' => $option, 'path' => $path], JSON_UNESCAPED_SLASHES));

            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'option' => $option, 'path' => $path], JSON_UNESCAPED_SLASHES));

            return null;
        }

        return $decoded;
    }
}
