<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\AtlasTeosIncrement2CertificationService;
use Illuminate\Console\Command;

final class AtlasTeosIncrement2CertifyCommand extends Command
{
    protected $signature = 'atlas:teos:i2-certify
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'TEOS-I2 release certification: replay manifest + causal graph + continuity certification. Read-only; no providers/rivals.';

    public function handle(AtlasTeosIncrement2CertificationService $service): int
    {
        $payload = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderHuman($payload);
        }

        if ((bool) $this->option('strict')
            && ($payload['status'] ?? null) !== AtlasTeosIncrement2CertificationService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->line(sprintf(
            '<info>Atlas TEOS-I2 Certification</info> (schema %s)',
            $payload['schema_version'] ?? 'unknown',
        ));
        $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
        $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->line('Benchmark: <comment>'.(((bool) data_get($payload, 'claim_policy.benchmark_not_run', false)) ? 'not_run' : 'unknown').'</comment>');
        $this->newLine();

        foreach ((array) ($payload['checks'] ?? []) as $check) {
            $status = (string) ($check['status'] ?? '');
            $tag = match ($status) {
                AtlasTeosIncrement2CertificationService::CHECK_STATUS_PASS => '<info>PASS</info>',
                AtlasTeosIncrement2CertificationService::CHECK_STATUS_WARN => '<comment>WARN</comment>',
                default => '<error>FAIL</error>',
            };
            $this->line(sprintf(
                '%s [%s] %s — %s',
                $tag,
                $check['severity'] ?? '?',
                $check['check_id'] ?? '?',
                $check['reason'] ?? '',
            ));
        }
    }
}
