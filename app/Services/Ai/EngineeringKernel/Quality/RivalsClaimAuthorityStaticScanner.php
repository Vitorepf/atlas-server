<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Quality;

/**
 * Source-level guard for comparative claim writers. It permits Rivals as the
 * sole issuer while still allowing non-Rivals code to mention the vocabulary
 * in a read-only contract or request.
 */
final class RivalsClaimAuthorityStaticScanner
{
    public const SCHEMA = 'atlas.quality_foundry.rivals_claim_authority_static_scan.v1';

    /** @param array<string,string> $files @return array<string,mixed> */
    public function scan(array $files): array
    {
        $violations = [];
        foreach ($files as $path => $source) {
            if (str_contains(str_replace('\\', '/', $path), '/Rivals/')) {
                continue;
            }

            foreach (preg_split('/\R/', $source) ?: [] as $lineNumber => $line) {
                if (preg_match("/['\"]claim_eligible['\"]\s*=>\s*true/", $line) === 1) {
                    $violations[] = $this->violation($path, $lineNumber + 1, 'external_claim_eligibility_writer');
                }
                if (preg_match("/['\"](?:world_leading|world_10x_quality_proven|multiplier_proven)['\"]\s*=>/", $line) === 1) {
                    $violations[] = $this->violation($path, $lineNumber + 1, 'external_comparative_claim_writer');
                }
                if (preg_match("/(?:record|emit|write|append)[^\n]*(?:claim\.issued|claim\.revoked)/", $line) === 1) {
                    $violations[] = $this->violation($path, $lineNumber + 1, 'external_claim_event_writer');
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $violations === [] ? 'pass' : 'blocked',
            'violations' => $violations,
            'rivals_allowlist' => 'path_contains_/Rivals/',
        ];
    }

    /** @return array{file:string,line:int,reason:string} */
    private function violation(string $path, int $line, string $reason): array
    {
        return ['file' => $path, 'line' => $line, 'reason' => $reason];
    }
}
