<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasKernelStaticScansService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Kernel Static Scans CLI.
 *
 *   php artisan atlas:aaeos:kernel-static-scans
 *     [--scan-id=ap201_runtime_language_boundary_contract]
 *     [--violations=foo,bar]                 // raw violation messages
 *     [--paths=app/Services/Foo.php]         // paths involved
 *     [--symbols=Foo::bar]                   // symbols involved
 *     [--negative-regression]                // suite exercised a negative case
 *     [--json]
 *
 * Read-only, deterministic. Normalizes a raw scan result into the documented
 * "Required Scan Behavior" envelope and reports the family + compliance verdict.
 * It never runs the corpus scan and never writes anything.
 *
 * @see docs/engineering-knowledge-base/kernel/static-scans.md
 */
class AtlasKernelStaticScansCommand extends Command
{
    protected $signature = 'atlas:aaeos:kernel-static-scans
        {--scan-id= : stable scan id to normalize (e.g. ap201_runtime_language_boundary_contract)}
        {--violations= : comma-separated raw violation messages (empty = passing scan)}
        {--paths= : comma-separated paths involved}
        {--symbols= : comma-separated symbols involved}
        {--negative-regression : declare the suite exercised at least one negative regression case}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · normalize a static-scan result into the documented envelope and report the compliance verdict.';

    public function handle(AtlasKernelStaticScansService $service): int
    {
        try {
            // Safe default subject: a passing runtime-language-boundary scan,
            // the canonical merge-blocking family from the doc.
            $scanId = $this->str('scan-id', 'ap201_runtime_language_boundary_contract');

            $rawScan = [
                'scan_id' => $scanId,
                'violations' => $this->list('violations'),
                'paths' => $this->list('paths'),
                'symbols' => $this->list('symbols'),
            ];

            $summary = $service->summarize(
                [$rawScan],
                (bool) $this->option('negative-regression'),
            );

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $summary],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $summary['merge_gate'] === AtlasKernelStaticScansService::MERGE_GATE_OPEN
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'kernel_static_scans_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option, string $default): string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : $default;
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
