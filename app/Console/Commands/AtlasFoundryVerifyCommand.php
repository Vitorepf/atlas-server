<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Foundry AP-A · evidence verify.
 *
 * Thin, verify-only entrypoint over FoundryEvidenceVerifierService::verify().
 * Reads an already-harvested dossier from a file or stdin and prints
 * confirm/refute verdicts with recorded reasons. It NEVER regenerates anchors,
 * never generates proposals, never writes canon. No git, no merge, no deploy.
 *
 * I1 (Evidence-Bound): a non-existent/false anchor is REFUTED with a reason.
 */
class AtlasFoundryVerifyCommand extends Command
{
    protected $signature = 'atlas:foundry:verify {--dossier= : Path to a harvested dossier JSON (omit to read stdin)} {--json : Emit JSON}';

    protected $description = 'Verify a harvested Foundry dossier; confirm/refute anchors with reasons (AP-A, regenerates nothing).';

    public function handle(FoundryEvidenceVerifierService $verifier): int
    {
        $path = $this->option('dossier');

        if ($path !== null && $path !== '') {
            if (! is_file((string) $path) || ! is_readable((string) $path)) {
                $this->error('Dossier file not found or unreadable: ' . (string) $path);

                return self::FAILURE;
            }
            $raw = (string) file_get_contents((string) $path);
        } else {
            $raw = (string) stream_get_contents(STDIN);
        }

        if (trim($raw) === '') {
            $this->error('No dossier JSON provided (pass --dossier=<path> or pipe JSON on stdin).');

            return self::FAILURE;
        }

        $dossier = json_decode($raw, true);
        if (! is_array($dossier)) {
            $this->error('Dossier JSON is invalid.');

            return self::FAILURE;
        }

        $result = $verifier->verify($dossier);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Anchors verified', (string) ($result['anchor_count'] ?? 0));
            $this->components->twoColumnDetail('Confirmed', (string) ($result['confirmed_count'] ?? 0));
            $this->components->twoColumnDetail('Refuted', (string) ($result['refuted_count'] ?? 0));
            $this->components->twoColumnDetail('All confirmed', YesNo::format($result['all_confirmed'] ?? false));
            $this->components->twoColumnDetail('Verification hash', (string) ($result['verification_hash'] ?? ''));
        }

        return ($result['all_confirmed'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
