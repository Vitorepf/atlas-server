<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ACDE lever F3 — the per-DELIVERY HMAC-signed dossier + feature-outcome ledger.
 *
 * Quality is proven PER DELIVERY (Rivals + the head-to-head are dead). This service is that proof made
 * portable: for each merged delivery it builds ONE dossier carrying the machine-resolved dimensions
 * ({@see AtlasLoopDeliveryDimensionResolver} D2 — the single Rivals-free definition, never a self-grade) plus
 * the F2 completeness checklist when present, then HMAC-signs the canonical body so a downstream consumer
 * (e.g. origination's O2 accept/reject ledger) can read a delivery's outcome back and TRUST it was not forged
 * or edited after the fact.
 *
 * SUBSTRATE: it READS what the merge already persisted (atlas_loop_proposals.merged_to_main + quality) — there
 * is NO new table and NO write on the merge critical path (same shape as D1). The "feature-outcome ledger" is
 * the signed stream derived from those rows. PROVIDER-SAFE: objective + repo path + dimensions only, never raw
 * code, never engine-vs-engine. recent() is flag-gated default-OFF => empty => byte-identical; every DB touch
 * is guarded. dossierFor()/sign()/verify() are pure (no DB) so the signature is deterministic + testable.
 */
final class AtlasLoopDeliveryDossierService
{
    public const SCHEMA = 'atlas.loop.delivery_dossier.v1';

    public function __construct(
        private readonly ?AtlasLoopDeliveryDimensionResolver $resolver = null,
        private readonly ?string $secret = null,
    ) {}

    /**
     * The recent merged deliveries as signed dossiers (newest first). Empty when OFF / no DB.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 20): array
    {
        if (! (bool) config('atlas.loop.delivery_dossier_enabled', false)) {
            return [];
        }
        if (! DatabaseTableAvailability::all(['atlas_loop_proposals'])) {
            return [];
        }

        try {
            $rows = DB::table('atlas_loop_proposals')
                ->where('merged_to_main', true)
                ->orderByDesc('updated_at')
                ->limit(max(1, min(200, $limit)))
                ->get(['id', 'target_path', 'objective', 'quality', 'updated_at']);
        } catch (Throwable) {
            return [];
        }

        $dossiers = [];
        foreach ($rows as $row) {
            $quality = json_decode((string) $row->quality, true);
            $dossiers[] = $this->dossierFor([
                'proposal_id' => (string) $row->id,
                'target_path' => (string) $row->target_path,
                'objective' => (string) $row->objective,
                'delivered_at' => (string) $row->updated_at,
                'quality' => is_array($quality) ? $quality : [],
            ]);
        }

        return $dossiers;
    }

    /**
     * Build + sign ONE delivery dossier. Pure (no DB) so the signature is deterministic and unit-testable.
     *
     * @param  array<string,mixed>  $delivery  {proposal_id, target_path, objective, delivered_at, quality}
     * @return array<string,mixed>
     */
    public function dossierFor(array $delivery): array
    {
        $resolver = $this->resolver ?? new AtlasLoopDeliveryDimensionResolver;
        $quality = is_array($delivery['quality'] ?? null) ? $delivery['quality'] : [];

        $dimensions = $resolver->resolve([
            'attempted' => true,
            'committed' => true,
            'canary' => $this->canary($quality),
            'mutation_kill_ratio' => $this->numeric($quality, ['mutation_kill_ratio', 'kill_ratio']),
            'completeness' => $this->numeric($quality, ['completeness']),
            'cyclomatic_drop' => $this->numeric($quality, ['cyclomatic_drop']),
        ]);

        // SURFACE the AtlasLoopFeatureSequenceWalker's step-by-step plan (produced into quality by
        // AtlasLoopIntentVerifierFactory) so the operator sees a multi-atom feature's progress, not just the
        // completeness checklist. The walker step shape is {step, active_atoms, regression_atoms,
        // cumulative_atoms, is_last}; the LAST step's is_last===true means its cumulative atoms ARE the whole
        // feature. Absent / non-array quality key ⇒ empty surfacing (byte-identical dossier under verify()).
        $featureSteps = is_array($quality['feature_sequence_steps'] ?? null) ? array_values($quality['feature_sequence_steps']) : [];
        $lastStep = $featureSteps === [] ? null : end($featureSteps);

        $body = [
            'schema_version' => self::SCHEMA,
            'proposal_id' => (string) ($delivery['proposal_id'] ?? ''),
            'target_path' => (string) ($delivery['target_path'] ?? ''),
            'objective' => (string) ($delivery['objective'] ?? ''),
            'delivered_at' => (string) ($delivery['delivered_at'] ?? ''),
            'dimensions' => $dimensions,
            'completeness_checklist' => is_array($quality['completeness_checklist'] ?? null) ? $quality['completeness_checklist'] : [],
            'feature_sequence_steps' => $featureSteps,
            'feature_sequence_total_steps' => count($featureSteps),
            'feature_sequence_last_is_full' => is_array($lastStep) && ($lastStep['is_last'] ?? false) === true,
        ];
        $body['signature'] = $this->sign($body);

        return $body;
    }

    /**
     * Whether a dossier's signature matches its body — tamper-evidence for any consumer reading it back.
     *
     * @param  array<string,mixed>  $dossier
     */
    public function verify(array $dossier): bool
    {
        $signature = (string) ($dossier['signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        return hash_equals($this->sign($dossier), $signature);
    }

    /**
     * Keyed HMAC over the canonical (signature-free, key-sorted) body. Deterministic for a given body+secret.
     *
     * @param  array<string,mixed>  $body
     */
    private function sign(array $body): string
    {
        unset($body['signature']);
        ksort($body);

        return hash_hmac(
            'sha256',
            (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->secret(),
        );
    }

    private function secret(): string
    {
        if (is_string($this->secret) && $this->secret !== '') {
            return $this->secret;
        }
        $configured = (string) config('atlas.loop.dossier_hmac_secret', '');

        // A non-empty default keeps the dossier tamper-evident WITHIN the box without touching any real secret
        // (the operator can override with ATLAS_LOOP_DOSSIER_HMAC_SECRET to make it cross-process verifiable).
        return $configured !== '' ? $configured : 'atlas.loop.delivery_dossier.default-key.v1';
    }

    /** Canary from the merged proposal's quality envelope (green iff it ran && passed) — mirrors D1. */
    private function canary(array $quality): string
    {
        $c = $quality['_canary'] ?? null;
        if (! is_array($c) || ($c['ran'] ?? false) !== true) {
            return 'not_run';
        }

        return ($c['passed'] ?? false) === true ? 'green' : 'red';
    }

    /**
     * First numeric value found under any of $keys in the quality envelope (clamped non-negative), else 0.0.
     *
     * @param  array<string,mixed>  $quality
     * @param  list<string>  $keys
     */
    private function numeric(array $quality, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($quality[$key]) && is_numeric($quality[$key])) {
                return max(0.0, (float) $quality[$key]);
            }
        }

        return 0.0;
    }
}
