<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Analysis\AnalysisJudgePanelService;
use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * G6 — run a structured analysis through the cross-domain judge panel +
 * metric-family-aware honesty gate. Deterministic, decision-only: no provider
 * call, no write, no merge. The VERDICT (certified or refused) is data, not an
 * error — the command exits 0 whenever it ran; exit 1 only on unreadable input.
 */
class AtlasDomainAnalyzeCommand extends Command
{
    protected $signature = 'atlas:domain:analyze
        {--input= : Path to a JSON file with the analysis payload}
        {--json : Print the full result as JSON}';

    protected $description = 'Adjudicate a structured cross-domain analysis through the G6 lens panel and honesty gate (deterministic, decision-only).';

    public function handle(
        AnalysisJudgePanelService $panel,
        AtlasDomainProfileRegistry $domains,
    ): int {
        $path = trim((string) ($this->option('input') ?? ''));

        if ($path === '') {
            $this->error('Missing --input=<path to analysis JSON file>.');

            return self::FAILURE;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Input file is not readable: '.$path);

            return self::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        $analysis = json_decode($raw, true);

        if (! is_array($analysis)) {
            $this->error('Input file does not contain a JSON object: '.$path);

            return self::FAILURE;
        }

        $result = $panel->run($analysis);
        $result['domain_resolution'] = $this->resolveDomain($domains, $analysis);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('decision: '.$result['decision']);
        $this->line('certified: '.(YesNo::format($result['certified'])));
        $this->line('metric_family: '.$result['honesty']['metric_family']);
        $this->line(sprintf(
            'panel: %d accept / %d refute (majority threshold %d)',
            $result['panel']['accept_count'],
            $result['panel']['refute_count'],
            $result['panel']['majority_threshold'],
        ));

        $domainResolution = $result['domain_resolution'];
        if ($domainResolution !== null) {
            $this->line('domain: '.$domainResolution['declared'].' ('.$domainResolution['note'].')');
        }

        foreach ($result['reasons'] as $reason) {
            $this->line('reason: '.$reason);
        }

        // The verdict is data, not an error: exit 0 whenever the panel ran.
        return self::SUCCESS;
    }

    /**
     * Unknown domain = annotate, never refuse.
     *
     * @param  array<string,mixed>  $analysis
     * @return array{declared:string, resolved_domain_id:string|null, known:bool, note:string}|null
     */
    private function resolveDomain(AtlasDomainProfileRegistry $domains, array $analysis): ?array
    {
        $declared = trim((string) ($analysis['domain'] ?? ''));

        if ($declared === '') {
            return null;
        }

        try {
            $resolved = $domains->resolve($declared);
            $domainProfile = is_array($resolved['domain_profile'] ?? null) ? $resolved['domain_profile'] : [];
            $registryTag = (string) (($domainProfile['metadata'] ?? [])['registry'] ?? '');
            $known = $registryTag !== 'generated_fallback';

            return [
                'declared' => $declared,
                'resolved_domain_id' => (string) ($resolved['domain_id'] ?? ''),
                'known' => $known,
                'note' => $known ? 'known_domain' : 'unknown_domain_annotated',
            ];
        } catch (Throwable) {
            return [
                'declared' => $declared,
                'resolved_domain_id' => null,
                'known' => false,
                'note' => 'unknown_domain_annotated',
            ];
        }
    }
}
