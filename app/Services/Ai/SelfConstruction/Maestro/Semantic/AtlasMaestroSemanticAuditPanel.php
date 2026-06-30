<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Semantic;

final class AtlasMaestroSemanticAuditPanel
{
    public function __construct(
        private readonly ?object $allowedFilesIntentChecker = null,
        private readonly ?object $orphanCallerVerifier = null,
        private readonly ?object $symbolResolver = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array{pass:bool,votes:list<bool>,panel_reason:string}
     */
    public function audit(array $packet): array
    {
        // Hard pre-flight: proxy-only and lane/file incoherence are rejected before quorum.
        if ($this->isProxyOnlyWork($packet)) {
            return ['pass' => false, 'votes' => [], 'panel_reason' => 'proxy_only_work_rejected'];
        }
        if ($this->hasLaneFileMismatch($packet)) {
            return ['pass' => false, 'votes' => [], 'panel_reason' => 'lane_file_coherence_failed'];
        }

        $votes = [
            (bool) (($this->allowedFilesIntentChecker())->check($packet)['ok'] ?? false),
            (bool) (($this->orphanCallerVerifier())->verify($packet)['ok'] ?? false),
            $this->acceptanceCriteriaCitesRealSymbol($packet),
        ];
        $pass = count(array_filter($votes)) >= 2;

        return [
            'pass' => $pass,
            'votes' => $votes,
            'panel_reason' => $pass ? 'semantic_quorum_passed' : 'semantic_quorum_failed',
            'runnable_acceptance' => $this->hasRunnableAcceptance($packet),
            'duplicate_symbol_risk' => $this->hasDuplicateSymbolRisk($packet),
        ];
    }

    private function isProxyOnlyWork(array $packet): bool
    {
        if ((bool) ($packet['blind_orphan_wiring_proxy'] ?? false) ||
            (bool) ($packet['dormant_cli_arm_proxy'] ?? false)) {
            return true;
        }
        $objective = strtolower((string) ($packet['objective'] ?? ''));

        return (bool) preg_match(
            '/\b(proxy[- ]only|stub[- ]only|re-?export only|just (?:alias|wrap|rename))\b/',
            $objective,
        );
    }

    private function hasLaneFileMismatch(array $packet): bool
    {
        $lane = (string) ($packet['lane'] ?? '');
        if ($lane === '') {
            return false;
        }
        $allowed = array_map('strval', (array) ($packet['allowed_files'] ?? []));
        if ($allowed === []) {
            return true;
        }
        if (str_contains($lane, 'brain')) {
            foreach ($allowed as $f) {
                if (str_contains($f, 'AutonomousEvolution') || str_contains($f, 'Brain')) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function hasRunnableAcceptance(array $packet): bool
    {
        $text = implode("\n", array_map('strval', (array) ($packet['acceptance_criteria'] ?? [])));

        return str_contains($text, 'artisan test') || str_contains($text, 'phpunit');
    }

    private function hasDuplicateSymbolRisk(array $packet): bool
    {
        $seen = [];
        foreach (array_map('strval', (array) ($packet['allowed_files'] ?? [])) as $f) {
            $base = pathinfo($f, PATHINFO_FILENAME);
            if (in_array($base, $seen, true)) {
                return true;
            }
            $seen[] = $base;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function acceptanceCriteriaCitesRealSymbol(array $packet): bool
    {
        $text = implode("\n", array_map('strval', (array) ($packet['acceptance_criteria'] ?? [])));
        if (preg_match_all('/\b(?:[A-Z][A-Za-z0-9_]*\\\\)*Atlas[A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)?\b/', $text, $matches) < 1) {
            return false;
        }

        foreach (array_unique($matches[0]) as $symbol) {
            $resolved = ($this->symbolResolver())->resolve($symbol);
            if (($resolved['exists'] ?? false) === true && isset($resolved['file'], $resolved['line'])) {
                return true;
            }
        }

        return false;
    }

    private function allowedFilesIntentChecker(): object
    {
        return $this->allowedFilesIntentChecker ?? new AtlasMaestroAllowedFilesIntentChecker($this->symbolResolver());
    }

    private function orphanCallerVerifier(): object
    {
        return $this->orphanCallerVerifier ?? new AtlasMaestroOrphanCallerVerifier($this->symbolResolver());
    }

    private function symbolResolver(): object
    {
        return $this->symbolResolver ?? new AtlasMaestroSemanticSymbolResolver(base_path());
    }
}
