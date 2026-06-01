<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPlanesAndAuthorityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas master "Planes and Authority" decider CLI.
 *
 *   php artisan atlas:aaeos:planes-and-authority
 *     [--plane=runtime]        // which authority plane to test
 *     [--capability=execution] // capability / action the plane wants to own
 *     [--has-receipt]          // the action carries a compiled receipt
 *     [--json]
 *
 * Read-only, deterministic. Emits one authorize() verdict plus the full
 * six-plane manifest, so a plane can never silently take a capability another
 * plane owns (e.g. Surface deciding a provider, or Runtime choosing policy).
 *
 * @see docs/engineering-knowledge-base/master-architecture/planes-and-authority.md
 */
class AtlasPlanesAndAuthorityCommand extends Command
{
    protected $signature = 'atlas:aaeos:planes-and-authority
        {--plane= : authority plane (control|domain|runtime|evidence|learning|surface)}
        {--capability= : capability / action key the plane wants to own}
        {--has-receipt : the action carries a compiled receipt}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas master architecture · authorize a (plane, capability) pair against the six-plane authority model.';

    public function handle(AtlasPlanesAndAuthorityService $service): int
    {
        try {
            $plane = $this->option('plane') ?: AtlasPlanesAndAuthorityService::PLANE_RUNTIME;
            $capability = $this->option('capability') ?: 'execution';
            $hasReceipt = (bool) $this->option('has-receipt');

            $verdict = $service->authorize((string) $plane, (string) $capability, $hasReceipt);

            $this->line((string) json_encode([
                'ok' => true,
                'schema' => AtlasPlanesAndAuthorityService::RECEIPT_SCHEMA,
                'authorize' => $verdict,
                'manifest' => $service->manifest(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'planes_and_authority_evaluation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
