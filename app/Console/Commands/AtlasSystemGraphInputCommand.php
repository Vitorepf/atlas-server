<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphInputService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Input — canonical-input formation CLI.
 *
 *   php artisan atlas:aaeos:atlas-input
 *     [--origin=operator] [--surface=atlas_code] [--content="..."]
 *     [--thread=...] [--obra=...]
 *     [--plan=...]   // forbidden objective (stripped/flagged)
 *     [--json]
 *
 * Read-only, deterministic. Takes a normalized payload (as emitted by the Surface
 * Adapter) and forms the canonical input that flows to `operation-envelope`. It
 * preserves content/type/origin/attachments/limits, mints a deterministic
 * input_ref for receipts, enforces the content budget, and refuses to carry a
 * resolved plan/objective (deferred to Intent Routing). A payload with no origin
 * is rejected (the invariant: no input may lose its origin). With no flags the
 * defaults model an Atlas Code paste => outcome=canonical.
 *
 * @see docs/engineering-knowledge-base/system-graph/atlas-input.md
 */
class AtlasSystemGraphInputCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-input
        {--origin= : where the input originated (required for audit; defaults to operator)}
        {--surface= : surface id the normalized payload came from}
        {--content= : the request content carried verbatim}
        {--thread= : thread relation bound onto attachments}
        {--obra= : obra relation bound onto attachments}
        {--plan= : resolved plan/objective the input must not carry (stripped; flagged)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas input · form a canonical input from a normalized payload, preserving origin/content/attachments/limits and refusing to become a plan without intent routing.';

    public function handle(AtlasSystemGraphInputService $service): int
    {
        try {
            $payload = array_filter([
                'origin' => $this->stringOption('origin') ?? 'operator',
                'surface' => $this->stringOption('surface') ?? 'atlas_code',
                'content' => $this->stringOption('content') ?? 'preserve this canonical input',
                'thread' => $this->stringOption('thread'),
                'obra' => $this->stringOption('obra'),
                'plan' => $this->stringOption('plan'),
            ], static fn (mixed $v): bool => $v !== null);

            $result = $service->formCanonical($payload);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->getMessage()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
