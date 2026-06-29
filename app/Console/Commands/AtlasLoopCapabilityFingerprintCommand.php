<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\V3\AtlasLoopV3CapabilityFingerprint;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Arms the dormant orphan {@see AtlasLoopV3CapabilityFingerprint::of()} at the operator surface: computes a
 * capability fingerprint for a class — its declared public methods plus the supplied atom kinds and path
 * patterns — and a deterministic hash for equivalence checks.
 *
 * Pure + read-only: reflection over the class shape + a hash; no provider/DB/mutation. Unknown class is refused.
 */
final class AtlasLoopCapabilityFingerprintCommand extends Command
{
    protected $signature = 'atlas:loop:capability-fingerprint {--fqcn=} {--atoms=} {--patterns=} {--json}';

    protected $description = 'Read-only capability fingerprint (public methods + atom kinds + path patterns + hash).';

    public function handle(): int
    {
        $fqcn = trim((string) $this->option('fqcn'));
        if ($fqcn === '') {
            return $this->refuse('capability-fingerprint requires --fqcn=<class>');
        }
        $atoms = $this->readJsonList('atoms');
        $patterns = $this->readJsonList('patterns');

        try {
            $fingerprint = AtlasLoopV3CapabilityFingerprint::of($fqcn, $atoms, $patterns);
        } catch (InvalidArgumentException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'fingerprint_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('hash: '.$fingerprint['hash']);
            $this->line('public_methods: '.implode(', ', $fingerprint['public_methods']));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function readJsonList(string $option): array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return [];
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_is_list($decoded) ? array_map('strval', $decoded) : [];
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
