<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomyAndClarificationPolicyService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas SDD Autonomy And Clarification Policy doc.
 * Without args it prints the governance snapshot (L0..L5 levels + ask/act/block
 * conditions). With --signals it runs the Ask/Act/Block gate on a request; with
 * --risk (+ --gates) it recommends the autonomy level for that risk band.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md
 */
final class AtlasAutonomyAndClarificationPolicyCommand extends Command
{
    protected $signature = 'atlas:aaeos:autonomy-and-clarification-policy
        {--signals= : JSON map of request signal flags for the ask/act/block gate}
        {--risk= : Risk band (low|medium|high|critical|unclear) to recommend an autonomy level}
        {--gates : Mark quality gates as available (used with --risk)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas SDD autonomy and clarification policy (L0..L5, ask/act/block gate) runtime.';

    public function handle(AtlasAutonomyAndClarificationPolicyService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $signals = $this->option('signals');
            if (is_string($signals) && trim($signals) !== '') {
                $decoded = json_decode($signals, true);
                $map = is_array($decoded) ? $decoded : [];

                return $this->emit($service->evaluateRequest($map), $json);
            }

            $risk = $this->option('risk');
            if (is_string($risk) && trim($risk) !== '') {
                return $this->emit($service->recommendAutonomyLevel($risk, (bool) $this->option('gates')), $json);
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasAutonomyAndClarificationPolicyService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
