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
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function acceptanceCriteriaCitesRealSymbol(array $packet): bool
    {
        $text = implode("\n", array_map('strval', (array) ($packet['acceptance_criteria'] ?? [])));
        if (preg_match_all('/\b(?:[A-Z][A-Za-z0-9_]*\\\\)*Atlas[A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)?\b/', $text, $matches) !== 1) {
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
