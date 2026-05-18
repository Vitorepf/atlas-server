<?php

namespace App\Console\Commands;

use App\Services\Ai\ResearchDomain\ResearchControlPlaneProjection;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchDomainManifestSeeder;
use App\Services\Ai\ResearchDomain\ResearchReadinessService;
use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiResearchDomainCommand extends Command
{
    protected $signature = 'atlas:ai:research-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-manifest, smoke, control-plane}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Research Company Runtime (Meta 8A): source plan, source quality, claims, contradiction check, synthesis, evidence pack, certification.';

    public function handle(
        ResearchReadinessService $readiness,
        ResearchDomainManifestSeeder $manifestSeeder,
        ResearchRuntimeService $runtime,
        ResearchControlPlaneProjection $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-manifest' => $this->renderSeedManifest($manifestSeeder),
                'smoke' => $this->renderSmoke($runtime, $controlPlane),
                'control-plane' => $this->renderControlPlane($controlPlane),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $this->line($this->encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ]));

            return self::FAILURE;
        }
    }

    private function renderReadiness(ResearchReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
            foreach ($payload['checks'] as $check) {
                $this->components->twoColumnDetail((string) $check['name'], (string) $check['status']);
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSeedManifest(ResearchDomainManifestSeeder $seeder): int
    {
        $manifest = $seeder->seed();
        $payload = [
            'ok' => true,
            'action' => 'seed-manifest',
            'schema' => 'atlas.ai.research_domain.seed_manifest.v1',
            'manifest' => [
                'uuid' => $manifest->uuid,
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'status' => $manifest->status,
                'maturity_stage' => $manifest->maturity_stage,
                'manifest_hash' => $manifest->manifest_hash,
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('domain_id', (string) $payload['manifest']['domain_id']);
            $this->components->twoColumnDetail('manifest_hash', (string) ($payload['manifest']['manifest_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        ResearchRuntimeService $runtime,
        ResearchControlPlaneProjection $controlPlane,
    ): int {
        $payload = $runtime->smokeRun();
        $snapshot = $controlPlane->snapshot();
        $run = $payload['run'];
        $synthesis = $payload['synthesis'];

        $body = [
            'ok' => $run->certification_status === ResearchDomainCanon::CERT_PASSED
                || $run->certification_status === ResearchDomainCanon::CERT_FAILED,
            'action' => 'smoke',
            'schema' => 'atlas.ai.research_domain.smoke.v1',
            'research_run' => [
                'uuid' => $run->uuid,
                'status' => $run->status,
                'certification_status' => $run->certification_status,
                'missing_requirements' => $run->missing_requirements,
                'source_diversity' => (float) ($run->source_diversity ?? 0.0),
                'overall_confidence' => (float) ($run->overall_confidence ?? 0.0),
                'certification_hash' => $run->certification_hash,
                'evidence_pack_hash' => $run->evidence_pack_hash,
            ],
            'synthesis' => [
                'uuid' => $synthesis->uuid,
                'synthesis_hash' => $synthesis->synthesis_hash,
                'claim_ref_count' => is_array($synthesis->claim_refs) ? count($synthesis->claim_refs) : 0,
                'source_ref_count' => is_array($synthesis->source_refs) ? count($synthesis->source_refs) : 0,
            ],
            'snapshot_summary' => [
                'runs' => $snapshot['runs']['count'],
                'sources' => $snapshot['sources']['count'],
                'claims' => $snapshot['claims']['count'],
                'syntheses' => $snapshot['syntheses']['count'],
            ],
        ];
        $this->emit($body, function () use ($body): void {
            $this->components->twoColumnDetail('certification_status', (string) ($body['research_run']['certification_status'] ?? ''));
            $this->components->twoColumnDetail('synthesis_hash', (string) ($body['synthesis']['synthesis_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(ResearchControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('runs', (string) $payload['runs']['count']);
            $this->components->twoColumnDetail('sources', (string) $payload['sources']['count']);
            $this->components->twoColumnDetail('claims', (string) $payload['claims']['count']);
            $this->components->twoColumnDetail('syntheses', (string) $payload['syntheses']['count']);
        });

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->line($this->encode([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => "invalid action [{$action}] for atlas:ai:research-domain",
        ]));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
