<?php

namespace App\Console\Commands;

use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\VslSalesPageHtmlRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * atlas:ai:marketing:vsl-page — assemble the SALES page that hosts the VSL and converts to checkout,
 * built deterministically from the dissected offer (price/packages/guarantee/objections/scarcity).
 * The checkout URL is the operator's producer hoplink — supplied, never invented.
 */
class AtlasAiMarketingVslPageCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:vsl-page
        {--id= : VSL asset id}
        {--hash= : VSL content hash}
        {--label= : Match the latest asset whose label contains this}
        {--checkout-url= : The producer order/checkout URL (hoplink) — required to be live}
        {--headline= : Override the sales headline (default = the VSL core promise)}
        {--cta-label= : Order button label}
        {--reveal-seconds= : Seconds before the offer block appears (default = pitch_starts_at_seconds)}
        {--brand= : Brand/offer name}
        {--out= : Write HTML to this path}
        {--no-persist : Do not store an AiMarketingArtifact}
        {--json : Machine-readable JSON output}';

    protected $description = 'Assemble a VSL sales page (video + delayed offer/checkout) from a dissected VSL asset.';

    public function handle(VslSalesPageHtmlRenderer $renderer): int
    {
        try {
            $asset = $this->resolveAsset();
            if ($asset === null) {
                return $this->failOut('no asset found — pass --id=, --hash=, or --label=');
            }

            $opts = array_filter([
                'checkout_url' => $this->option('checkout-url'),
                'headline' => $this->option('headline'),
                'cta_label' => $this->option('cta-label'),
                'brand' => $this->option('brand'),
            ], static fn ($v): bool => $v !== null && $v !== '');
            if ($this->option('reveal-seconds') !== null) {
                $opts['reveal_seconds'] = (int) $this->option('reveal-seconds');
            }

            $html = $renderer->render($asset, $opts);
            $slug = Str::slug(Str::limit((string) ($asset->mechanism_name ?: $asset->label), 50, ''));
            $outPath = $this->writeHtml($slug ?: 'vsl-page', $html);

            $hasCheckout = ($opts['checkout_url'] ?? '#order') !== '#order';
            $artifactId = null;
            if (! $this->option('no-persist')) {
                $artifactId = AiMarketingArtifact::create([
                    'schema_version' => 'atlas.vsl.salespage.v1',
                    'uuid' => (string) Str::uuid(),
                    'artifact_type' => 'vsl_sales_page',
                    'title' => Str::limit((string) ($opts['headline'] ?? $asset->core_promise ?: 'VSL sales page'), 180, ''),
                    'payload' => ['asset_id' => $asset->id, 'options' => $opts, 'html_path' => $outPath],
                    'status' => $hasCheckout ? 'ready' : 'needs_checkout_url',
                    'artifact_hash' => hash('sha256', $html),
                    'metadata' => ['asset_id' => $asset->id, 'html_path' => $outPath, 'has_checkout_url' => $hasCheckout],
                ])->id;
            }

            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'ok' => true, 'asset_id' => $asset->id, 'artifact_id' => $artifactId,
                    'html_path' => $outPath, 'has_checkout_url' => $hasCheckout,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->info('🛒 VSL sales page montada para: '.$asset->label);
            $this->line('  HTML: '.$outPath);
            if (! $hasCheckout) {
                $this->warn('  ⚠ Sem --checkout-url: os botões apontam para #order. Passe o hoplink do produtor para publicar.');
            }
            if ($artifactId) {
                $this->line('  Artefato: '.$artifactId);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failOut($e->getMessage());
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

        return $q->whereNotNull('big_idea')->orderByDesc('updated_at')->first();
    }

    private function writeHtml(string $slug, string $html): string
    {
        $path = (string) $this->option('out')
            ?: storage_path('app/marketing/vsl-page/'.$slug.'-'.substr((string) Str::ulid(), -6).'.html');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $html);

        return $path;
    }

    private function failOut(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
