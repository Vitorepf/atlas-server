<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\CaptureHmacLineageService;
use Illuminate\Console\Command;

final class AtlasImmuneVerifyLineageCommand extends Command
{
    protected $signature = 'atlas:immune:verify-lineage
        {--ref= : capture|packet|memory ref (e.g. capture:uuid, packet:uuid, memory:uuid)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'MAXI-07 verify tamper-evident HMAC capture lineage chain.';

    public function handle(CaptureHmacLineageService $lineage): int
    {
        $ref = trim((string) $this->option('ref'));
        if ($ref === '') {
            $payload = [
                'schema_version' => 'atlas.capture.hmac_lineage.verify.v1',
                'slice' => 'MAXI-07',
                'status' => 'ref_required',
                'coverage_window' => $lineage->coverageWindowReport(),
            ];
            $this->emit($payload);

            return self::FAILURE;
        }

        $result = $lineage->verifyRef($ref);
        $payload = [
            'schema_version' => 'atlas.capture.hmac_lineage.verify.v1',
            'slice' => 'MAXI-07',
            'ref' => $ref,
            'status' => $result['status'],
            'verify' => $result['verify'],
            'chain' => $result['chain'],
            'coverage_window' => $lineage->coverageWindowReport(),
        ];

        $this->emit($payload);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $encoded = (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        $this->line($encoded);
    }
}
