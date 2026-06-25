<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeDossierExporter;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeFinalizationGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas-native completion CLI for Self-Construction OS closure.
 *   verify  — evidence verifier verdict over evidence_facts.
 *   gate    — finalization gate verdict over the full facts payload.
 *   dossier — final dossier export (finalization + evidence + receipts).
 *
 * Read-only finalization surface: NO worker start, NO queue mutation, NO subprocess, NO receipt
 * writes. Empty facts ⇒ hold/blocked (never ready).
 */
final class AtlasSelfConstructionAtlasNativeCompletionCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:atlas-native-completion {action : verify|gate|dossier} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Atlas-native completion CLI: verify | gate | dossier.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $factsPath = (string) ($this->option('facts') ?? '');
        $facts = $this->readJson($factsPath);
        if ($factsPath !== '' && $facts === null) {
            $payload = ['status' => 'usage_error', 'reason' => 'invalid_facts_path:'.$factsPath];
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }
        $facts ??= [];

        $payload = match ($action) {
            'verify' => $this->verify($facts),
            'gate' => $this->gate($facts),
            'dossier' => $this->dossier($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function verify(array $facts): array
    {
        $evidenceFacts = is_array($facts['evidence_facts'] ?? null) ? $facts['evidence_facts'] : $facts;
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeEvidenceVerifier::class)->verify($evidenceFacts);

        return ['status' => 'ok', 'verify' => $verdict];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function gate(array $facts): array
    {
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeFinalizationGate::class)->finalize($facts);

        return ['status' => 'ok', 'gate' => $verdict];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function dossier(array $facts): array
    {
        // Auto-derive finalization sub-fact from gate if not pre-supplied — keeps the CLI flow honest.
        if (! isset($facts['finalization'])) {
            $facts['finalization'] = $this->app()->make(AtlasSelfConstructionAtlasNativeFinalizationGate::class)->finalize($facts);
        }
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeDossierExporter::class)->export($facts);

        return ['status' => 'ok', 'dossier' => $verdict];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if ($path === '') {
            return null;
        }
        if (! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
