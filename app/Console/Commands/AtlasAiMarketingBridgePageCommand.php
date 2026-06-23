<?php

namespace App\Console\Commands;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\BridgePageComposerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * atlas:ai:marketing:bridge-page — Atlas authors an aggressive, high-converting bridge (pre-sell
 * advertorial) from a dissected VSL asset + the winning patterns that actually sold + the affiliate
 * skills. The bridge's job: warm the cold lead and maximize VSL watch-through. Writes the rendered
 * HTML to storage and persists the structured bridge as an AiMarketingArtifact.
 */
class AtlasAiMarketingBridgePageCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:bridge-page
        {--id= : VSL asset id}
        {--hash= : VSL content hash}
        {--label= : Match the latest asset whose label contains this}
        {--angle= : Override the bridge angle (default = the VSL mechanism/big idea)}
        {--language= : Force the copy language (default = the market language from target_geo)}
        {--pattern-niche= : Force which winning-pattern niche grounds the keywords}
        {--aggressiveness=max : max | high | balanced}
        {--brand= : Publisher/brand name shown on the page}
        {--out= : Write the HTML to this path (default = storage/app/marketing/bridge/<slug>.html)}
        {--no-persist : Do not store an AiMarketingArtifact}
        {--json : Machine-readable JSON output}';

    protected $description = 'Compose an aggressive, policy-durable bridge page from a dissected VSL + winning patterns + skills.';

    public function handle(BridgePageComposerService $composer): int
    {
        try {
            $asset = $this->resolveAsset();
            if ($asset === null) {
                return $this->failOut('no asset found — pass --id=, --hash=, or --label=');
            }

            $result = $composer->compose($asset, array_filter([
                'angle' => $this->option('angle'),
                'language' => $this->option('language'),
                'pattern_niche' => $this->option('pattern-niche'),
                'aggressiveness' => (string) $this->option('aggressiveness'),
                'brand' => $this->option('brand'),
            ], static fn ($v): bool => $v !== null && $v !== ''));

            $bridge = $result['bridge'];
            $html = (string) ($result['html'] ?? '');
            $slug = (string) ($bridge['meta']['slug'] ?? Str::slug(Str::limit((string) $bridge['headline'], 50, '')));

            $outPath = $this->writeHtml($slug, $html, (string) $this->option('out'));
            $result['html_path'] = $outPath;

            $artifactId = null;
            if (! $this->option('no-persist')) {
                $artifactId = $this->persist($asset, $result, $outPath);
                $result['artifact_id'] = $artifactId;
            }

            return $this->emit($asset, $result, $outPath);
        } catch (Throwable $e) {
            return $this->failOut($e->getMessage(), $e::class);
        }
    }

    private function resolveAsset(): ?AiMarketingVslAsset
    {
        $q = AiMarketingVslAsset::query();
        if ($id = trim((string) $this->option('id'))) {
            return $q->where('id', $id)->first();
        }
        if ($hash = trim((string) $this->option('hash'))) {
            return $q->where('content_hash', $hash)->first();
        }
        if ($label = trim((string) $this->option('label'))) {
            return $q->where('label', 'like', "%{$label}%")->orderByDesc('updated_at')->first();
        }

        // default: the most recently dissected asset
        return $q->whereNotNull('big_idea')->orderByDesc('updated_at')->first()
            ?? $q->orderByDesc('updated_at')->first();
    }

    private function writeHtml(string $slug, string $html, string $override): string
    {
        $path = $override !== ''
            ? $override
            : storage_path('app/marketing/bridge/'.($slug ?: 'bridge').'-'.substr((string) Str::ulid(), -6).'.html');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $html);

        return $path;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function persist(AiMarketingVslAsset $asset, array $result, string $outPath): string
    {
        $bridge = $result['bridge'];
        $payload = $result;
        unset($payload['html']); // the heavy HTML lives on disk; keep the artifact row lean

        $artifact = AiMarketingArtifact::create([
            'schema_version' => 'atlas.vsl.bridge.v1',
            'uuid' => (string) Str::uuid(),
            'artifact_type' => 'bridge_page',
            'title' => Str::limit((string) ($bridge['headline'] ?? 'Bridge page'), 180, ''),
            'payload' => $payload,
            'status' => ($result['validation']['policy']['safe_to_publish'] && $result['validation']['keyword_relevance']['safe_to_publish']) ? 'ready' : 'needs_fix',
            'artifact_hash' => hash('sha256', (string) ($result['html'] ?? json_encode($bridge))),
            'metadata' => [
                'asset_id' => $asset->id,
                'asset_label' => $asset->label,
                'html_path' => $outPath,
                'policy_verdict' => $result['validation']['policy']['verdict'],
                'keyword_relevance' => $result['validation']['keyword_relevance']['verdict'],
                'keyword_anchor_rate' => $result['validation']['keyword_relevance']['anchor_rate'],
                'message_match' => $result['validation']['message_match']['score'],
                'keyword_coverage' => $result['validation']['keyword_coverage']['score'],
                'page_audit' => $result['validation']['page_audit']['overall_score'],
                'niche' => $result['grounding']['niche'] ?? null,
                'model' => $result['grounding']['model'],
            ],
        ]);

        return $artifact->id;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function emit(AiMarketingVslAsset $asset, array $result, string $outPath): int
    {
        $policy = $result['validation']['policy'];
        $mm = $result['validation']['message_match'];
        $audit = $result['validation']['page_audit'];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ok' => true,
                'asset_id' => $asset->id,
                'artifact_id' => $result['artifact_id'] ?? null,
                'html_path' => $outPath,
                'headline' => $result['bridge']['headline'] ?? null,
                'policy_verdict' => $policy['verdict'],
                'safe_to_publish' => $policy['safe_to_publish'] && $result['validation']['keyword_relevance']['safe_to_publish'],
                'keyword_relevance' => $result['validation']['keyword_relevance']['verdict'],
                'keyword_orphans' => array_map(fn ($o) => $o['keyword'], $result['validation']['keyword_relevance']['orphan_keywords']),
                'keyword_anchor_rate' => $result['validation']['keyword_relevance']['anchor_rate'],
                'message_match' => $mm['score'],
                'keyword_coverage' => $result['validation']['keyword_coverage']['score'],
                'page_audit' => $audit['overall_score'],
                'grounding' => $result['grounding'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $policy['safe_to_publish'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('🌉 Bridge page composta para: '.$asset->label);
        $this->line('  Headline : '.($result['bridge']['headline'] ?? '—'));
        $this->line('  Ângulo   : '.$result['grounding']['angle']);
        $this->line('  Idioma   : '.$result['grounding']['language'].'   Agressividade: '.$result['grounding']['aggressiveness']);
        $this->line('  Nicho    : '.($result['grounding']['niche'] ?? '—'));
        $this->line('  Keywords : '.implode(', ', array_slice($result['grounding']['vsl_keywords_used'] ?? [], 0, 6)));
        $this->newLine();
        $this->line('  Policy/uptime : '.strtoupper($policy['verdict']).'  (publicável: '.($policy['safe_to_publish'] ? 'sim' : 'NÃO').')');
        foreach ($policy['blocks'] as $b) {
            $this->error('   ✗ '.$b['rule'].' — '.$b['why']);
        }
        foreach ($policy['warnings'] as $w) {
            $this->warn('   ! '.$w['rule'].' — '.$w['why']);
        }
        $rel = $result['validation']['keyword_relevance'];
        if ($rel['safe_to_publish']) {
            $this->line('  Keyword↔VSL (anti-crime): OK — '.$rel['anchor_rate'].'% das keywords temáticas ancoradas na VSL/padrões');
        } else {
            $this->error('  ⛔ CRIME keyword↔VSL: '.count($rel['orphan_keywords']).' keyword(s) órfã(s) — '.implode(', ', array_map(fn ($o) => $o['keyword'], $rel['orphan_keywords'])));
        }
        $cq = $result['validation']['copy_quality'] ?? null;
        if ($cq !== null) {
            $tag = $cq['verdict'] === 'ok' ? 'OK' : strtoupper($cq['verdict']);
            $this->line('  Qualidade da copy: '.$tag.'  (ganchos concretos da VSL usados: '.($cq['concrete_hooks_count'] ?? 0).')');
            if ($cq['verdict'] !== 'ok') {
                $this->warn('   ! '.$cq['note']);
            }
        }
        $cov = $result['validation']['keyword_coverage'];
        $this->line('  Cobertura de keywords campeãs (recall): '.$cov['score'].'%  ['.implode(', ', array_slice($cov['covered'], 0, 5)).']');
        $this->line('  Message-match Jaccard (referência conservadora): '.$mm['score'].'/100');
        $this->line('  Page audit: '.$audit['overall_score'].'/100  (gargalo: '.$audit['bottleneck_lever'].')');
        $this->newLine();
        $this->info('  HTML salvo em: '.$outPath);
        if ($id = ($result['artifact_id'] ?? null)) {
            $this->line('  Artefato: '.$id);
        }

        return $policy['safe_to_publish'] ? self::SUCCESS : self::FAILURE;
    }

    private function failOut(string $message, ?string $type = null): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'error' => $message, 'type' => $type], JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
