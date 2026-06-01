<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasScopeValidatorContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Scope Validator CLI.
 *
 *   php artisan atlas:aaeos:scope-validator-contract
 *     [--packet=AIP-YYYYMMDD-0001]                  // switches scope source to work_splitter_packet
 *     [--allowed=app/Services/Foo.php,docs/foo.md]
 *     [--forbidden=routes/api.php]
 *     [--hot-scopes=runtimes/python/voice_realtime/]
 *     [--generated=docs/code-intel/]
 *     [--changed=app/Services/Foo.php]
 *     [--untracked=docs/foo.report.json]
 *     [--json]
 *
 * Read-only, deterministic. Classifies changed/untracked paths against the
 * packet write contract and emits pass|fail|blocked evidence. It NEVER mutates
 * files or repairs scope by itself.
 *
 * @see docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
 */
class AtlasScopeValidatorContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:scope-validator-contract
        {--packet= : Work Splitter packet id; presence switches scope source to work_splitter_packet}
        {--allowed= : comma-separated allowed file paths/prefixes}
        {--forbidden= : comma-separated forbidden file paths/prefixes}
        {--hot-scopes= : comma-separated paths owned by another active front}
        {--generated= : comma-separated generated index/sync output paths}
        {--changed= : comma-separated changed files (git diff --name-only)}
        {--untracked= : comma-separated untracked files (git status --short)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · scope validator that classifies changed/untracked files against a packet (pass|fail|blocked).';

    public function handle(AtlasScopeValidatorContractService $service): int
    {
        try {
            $packetOpt = $this->option('packet');
            $hasPacket = is_string($packetOpt) && trim($packetOpt) !== '';

            $packet = [
                'packet_id' => $hasPacket ? trim($packetOpt) : 'AIP-LOCAL-0001',
                'requested_packet_id' => $hasPacket ? trim($packetOpt) : 'AIP-LOCAL-0001',
                'packet_scope_source' => $hasPacket
                    ? AtlasScopeValidatorContractService::SOURCE_WORK_SPLITTER
                    : AtlasScopeValidatorContractService::SOURCE_IMPLEMENTATION,
                'allowed_files' => $this->list('allowed'),
                'forbidden_files' => $this->list('forbidden'),
                'hot_scopes' => $this->list('hot-scopes'),
                'generated_globs' => $this->list('generated'),
                'changed_files' => $this->list('changed'),
                'untracked_files' => $this->list('untracked'),
            ];

            $result = $service->validate($packet);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasScopeValidatorContractService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'scope_validator_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
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
