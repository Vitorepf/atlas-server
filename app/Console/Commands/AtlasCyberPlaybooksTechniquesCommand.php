<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberPlaybooksTechniquesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Playbooks and Techniques CLI.
 *
 *   php artisan atlas:aaeos:cyber-playbooks-techniques
 *     [--technique="ntlm relay"]
 *     [--environment=prod]                 // prod | sandbox | test
 *     [--clauses=red-team-c2]
 *     [--json]
 *
 * Read-only, deterministic. A cyber-* skill consults this router to classify a
 * requested technique into its canonical category, surface the governing
 * frameworks, the owning skill and the documented execution gate (e.g.
 * red-team-c2, sandbox-only). It NEVER executes the technique.
 *
 * @see docs/engineering-knowledge-base/cyber-security/playbooks-techniques.md
 */
class AtlasCyberPlaybooksTechniquesCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-playbooks-techniques
        {--technique= : requested technique/action label (e.g. "ntlm relay", "idor", "prompt injection")}
        {--environment= : execution environment: prod | sandbox | test}
        {--clauses= : comma-separated clause tokens supplied by the operator (e.g. red-team-c2,scope_proof)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · classify a Red technique into its canonical category, frameworks, owning skill and execution gate from the playbooks-techniques KB.';

    public function handle(AtlasCyberPlaybooksTechniquesService $service): int
    {
        try {
            $request = [
                'technique' => $this->str('technique') ?? 'ntlm relay',
                'environment' => $this->str('environment') ?? 'prod',
                'clauses' => $this->list('clauses'),
            ];

            $result = $service->classify($request);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // A gated technique whose precondition is not met is a non-zero exit so
            // callers and CI can gate on it.
            return ($result['allowed_to_propose'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cyber_playbooks_techniques_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option): ?string
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
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
