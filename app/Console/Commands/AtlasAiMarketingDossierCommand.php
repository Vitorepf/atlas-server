<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\MarketingVslDossierService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The per-VSL capstone command: one call composes economics + bid plan + the real VSL audit +
 * the execution briefs + a launch-readiness score + the recommended first move — the single
 * artifact the operator acts on for a given VSL.
 */
class AtlasAiMarketingDossierCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:dossier
        {--vsl= : VSL asset id}
        {--campaign= : VSL campaign_ref (latest)}
        {--payout= : Payout per sale (trava a economia)}
        {--margin=0.30 : Target net margin}
        {--refund=0.10 : Expected refund rate}
        {--cvr= : Click->sale CVR override}
        {--json : saída JSON}';

    protected $description = 'Atlas Marketing: dossiê completo de uma VSL (economia+lance+auditoria+briefs+readiness+1ª jogada).';

    public function handle(MarketingVslDossierService $dossier): int
    {
        try {
            $vsl = $this->resolveVsl();
            if ($vsl === null) {
                return $this->respondError('VSL asset não encontrado — passe --vsl=<id> ou --campaign=<ref>');
            }

            $inputs = array_filter([
                'payout' => $this->floatOpt('payout'),
                'margin' => $this->floatOpt('margin'),
                'refund' => $this->floatOpt('refund'),
                'cvr' => $this->floatOpt('cvr'),
            ], static fn ($v): bool => $v !== null);

            $d = $dossier->compile($vsl, $inputs);

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['ok' => true, 'dossier' => $d], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('VSL', (string) ($d['vsl']['campaign_ref'] ?? $d['vsl']['id']));
            $this->components->twoColumnDetail('readiness', $d['readiness']['score'].'/100 — '.($d['readiness']['launchable'] ? 'PRONTO' : 'bloqueado'));
            $this->components->twoColumnDetail('script (auditoria)', $d['vsl_audit']['score'].'/100');
            if (! empty($d['economics'])) {
                $this->components->twoColumnDetail('Max CPA', (string) ($d['economics']['max_cpa'] ?? '?'));
            }
            foreach ((array) $d['readiness']['blockers'] as $b) {
                $this->components->twoColumnDetail('[bloqueio]', (string) $b);
            }
            $fm = (array) $d['recommended_first_move'];
            $this->components->twoColumnDetail('1ª JOGADA', (string) ($fm['move'] ?? '?').' — '.(string) ($fm['why'] ?? ''));
            $this->components->info('Dossiê completo. Use --json pra economia/lance/briefs/auditoria detalhados.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    private function resolveVsl(): ?AiMarketingVslAsset
    {
        if ($id = trim((string) $this->option('vsl'))) {
            return AiMarketingVslAsset::query()->where('id', $id)->first();
        }
        if ($ref = trim((string) $this->option('campaign'))) {
            return AiMarketingVslAsset::query()->where('campaign_ref', $ref)->latest('last_ingested_at')->first();
        }

        return null;
    }

    private function floatOpt(string $name): ?float
    {
        $v = $this->option($name);

        return ($v !== null && $v !== '' && is_numeric($v)) ? (float) $v : null;
    }

    private function respondError(string $message, ?string $type = null): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_filter(['ok' => false, 'error' => $message, 'type' => $type])) ?: '{}');
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
