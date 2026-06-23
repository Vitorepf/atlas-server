<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\VslPersuasionAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Audit a REAL extracted VSL against the playbook anatomy + Cialdini + awareness — the
 * persuasion-auditor skill made executable on real data. Surfaces what is MISSING (where CVR
 * leaks) and maps each weak block to an action in the 9-action space.
 */
class AtlasAiMarketingAuditVslCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:audit-vsl
        {--vsl= : VSL asset id}
        {--campaign= : VSL campaign_ref (latest)}
        {--json : saída JSON}';

    protected $description = 'Atlas Marketing: audita uma VSL real (anatomia + Cialdini + awareness) → score + correções.';

    public function handle(VslPersuasionAuditService $auditor): int
    {
        try {
            $vsl = $this->resolveVsl();
            if ($vsl === null) {
                return $this->respondError('VSL asset não encontrado — passe --vsl=<id> ou --campaign=<ref>');
            }

            $audit = $auditor->audit($vsl);

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['ok' => true, 'audit' => $audit], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('VSL', (string) ($audit['campaign_ref'] ?? $audit['vsl_id']));
            $this->components->twoColumnDetail('SCORE', $audit['score'].'/100');
            $this->components->twoColumnDetail('anatomia', (string) $audit['score_breakdown']['anatomy_blocks_present']);
            $this->components->twoColumnDetail('Cialdini', (string) $audit['score_breakdown']['cialdini_principles_present']);
            $this->components->twoColumnDetail('awareness alinhado', $audit['awareness']['aligned'] ? 'sim' : 'não');
            foreach ((array) $audit['fixes'] as $f) {
                $this->components->twoColumnDetail("[{$f['priority']}] {$f['target']}", (string) $f['issue'].' → '.(string) ($f['action'] ?? ''));
            }
            $this->components->info((string) $audit['note']);

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
