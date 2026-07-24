<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeDossierExporter;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeFinalizationGate;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeReadinessPolicy;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomySoakPlanCompiler;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalEvidenceSourceRegistry;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:atlas-native-completion {action : verify|gate|dossier|readiness|soak} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Atlas-native completion CLI: verify | gate | dossier.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $factsPath = (string) ($this->option('facts') ?? '');
        $facts = $this->readJson($factsPath);
        if ($factsPath !== '' && $facts === null) {
            $payload = ['status' => 'usage_error', 'reason' => 'invalid_facts_path:'.$factsPath];
            $this->line($this->encode($payload));

            return self::FAILURE;
        }
        $facts ??= [];

        $payload = match ($action) {
            'verify' => $this->verify($facts),
            'gate' => $this->gate($facts),
            'dossier' => $this->dossier($facts),
            'readiness' => $this->readiness($facts),
            'soak' => $this->soak($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($payload));

        return ($payload['status'] ?? 'ok') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function verify(array $facts): array
    {
        $evidenceFacts = is_array($facts['evidence_facts'] ?? null) ? $facts['evidence_facts'] : $facts;
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeEvidenceVerifier::class)->verify($evidenceFacts);

        return [
            'status' => 'ok',
            'verify' => $verdict,
            'source_coverage' => $this->verifierSourceCoverage($verdict),
        ];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function gate(array $facts): array
    {
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeFinalizationGate::class)->finalize($facts);

        return [
            'status' => 'ok',
            'final_state' => (string) ($verdict['final_state'] ?? 'unknown'),
            'gate' => $verdict,
            'source_coverage' => is_array($verdict['source_coverage'] ?? null) ? $verdict['source_coverage'] : [],
        ];
    }

    /**
     * Wires AtlasSelfConstructionAtlasNativeReadinessPolicy into the CLI: evaluate whether the
     * supplied capability facts qualify Atlas Self-Construction as ATLAS_NATIVE-ready. Pure read.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function readiness(array $facts): array
    {
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeReadinessPolicy::class)->evaluate($facts);

        return [
            'status' => 'ok',
            'final_state' => (string) ($verdict['outcome'] ?? 'unknown'),
            'readiness' => $verdict,
        ];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function dossier(array $facts): array
    {
        // Auto-derive finalization sub-fact from gate if not pre-supplied — keeps the CLI flow honest.
        if (! isset($facts['finalization'])) {
            $facts['finalization'] = $this->app()->make(AtlasSelfConstructionAtlasNativeFinalizationGate::class)->finalize($facts);
        }
        $verdict = $this->app()->make(AtlasSelfConstructionAtlasNativeDossierExporter::class)->export($facts);
        $sections = is_array($verdict['evidence_sections'] ?? null) ? $verdict['evidence_sections'] : [];

        return [
            'status' => 'ok',
            'final_state' => (string) ($verdict['final_state'] ?? 'unknown'),
            'dossier' => $verdict,
            'evidence_source_coverage' => is_array($sections['evidence_source_coverage'] ?? null) ? $sections['evidence_source_coverage'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function verifierSourceCoverage(array $verdict): array
    {
        $registry = $this->app()->make(AtlasSelfConstructionFinalEvidenceSourceRegistry::class);
        $mandatory = [];
        foreach ((array) ($registry->describe()['required_sources'] ?? []) as $source) {
            if (is_array($source) && (bool) ($source['blocking'] ?? false)) {
                $mandatory[] = (string) $source['id'];
            }
        }
        sort($mandatory, SORT_STRING);

        $blockers = is_array($verdict['source_blockers'] ?? null) ? $verdict['source_blockers'] : [];

        return [
            'mandatory_source_ids' => $mandatory,
            'source_blockers' => $blockers,
        ];
    }

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    private function soak(array $facts): array
    {
        $verdict = $this->app()->make(AtlasSelfConstructionAutonomySoakPlanCompiler::class)->compile($facts);

        return [
            'status' => 'ok',
            'is_soak_ready' => (bool) ($verdict['is_soak_ready'] ?? false),
            'soak' => $verdict,
        ];
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
