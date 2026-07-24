<?php

namespace App\Services\Ai\MarketingDomain\Content;

use App\Models\AiMarketingVslAsset;
use Illuminate\Support\Str;

/**
 * VslSalesPageHtmlRenderer — builds the SALES page that hosts the VSL and converts to checkout. Unlike
 * the bridge (an advertorial that warms and sends to the video), this is the page where the pitch
 * lives: an autoplay video, a CTA that REVEALS at the pitch moment (delayed JS), the offer/price
 * stack, guarantee, bonuses, proof, objection FAQ, scarcity and a compliance footer.
 *
 * It is built deterministically from the dissected VSL (metrics/offer/objection_rebuttals/persuasion)
 * — the offer data already exists, so the page is assembled, not hallucinated. A short headline can be
 * passed in (from the composer); everything else is grounded. The single goal is "buy".
 */
class VslSalesPageHtmlRenderer
{
    public function __construct(
        private readonly BridgeHeadlineForge $headlineForge = new BridgeHeadlineForge,
        private readonly ProofForge $proofForge = new ProofForge,
    ) {}

    /**
     * @param  array<string,mixed>  $opts  headline, brand, vsl_embed_html, checkout_url (the producer's
     *                                     hoplink — supplied by the operator, never invented),
     *                                     reveal_seconds (when the CTA appears)
     */
    public function render(AiMarketingVslAsset $asset, array $opts = []): string
    {
        $lng = ['lang' => str_starts_with(strtolower((string) ($opts['lang'] ?? $asset->target_geo)), 'port') || str_contains(strtolower((string) $asset->target_geo), 'br') ? 'pt' : 'en'];
        $forged = $this->headlineForge->forge($asset, $lng);
        $brand = $this->esc((string) ($opts['brand'] ?? $asset->mechanism_name ?: 'Official Offer'));
        // headline: supplied → forged elite → core promise (never the weak raw field alone)
        $headline = $this->esc((string) ($opts['headline'] ?? ($forged[0] ?? null) ?? $asset->core_promise ?: $asset->big_idea ?: 'A New Way Forward'));
        $lang = $this->esc((string) ($opts['lang'] ?? 'en'));
        $checkout = (string) ($opts['checkout_url'] ?? '#order');
        $reveal = max(0, (int) ($opts['reveal_seconds'] ?? ($asset->pitch_starts_at_seconds ?? 0)));
        $vsl = (string) ($opts['vsl_embed_html'] ?? $this->placeholder());

        $metrics = is_array($asset->metrics) ? $asset->metrics : [];
        $offer = is_array($asset->offer) ? $asset->offer : [];

        $ctaLabel = $this->esc((string) ($opts['cta_label'] ?? 'Claim Your Discounted Package'));
        $packages = $this->renderPackages($metrics, $offer, $checkout, $ctaLabel);
        $guarantee = $this->renderGuarantee($metrics);
        $proof = $this->renderProof($asset, $lng);
        $faq = $this->renderFaq($asset);
        $scarcity = $this->renderScarcity($metrics);
        $disclaimer = $this->esc((string) ($opts['disclaimer'] ?? 'These statements have not been evaluated by the FDA. This product is not intended to diagnose, treat, cure or prevent any disease. Individual results vary.'));

        $css = $this->css();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$brand}</title>
<meta name="robots" content="noindex">
<style>{$css}</style>
</head>
<body>
<main class="wrap">
  <h1 class="vh">{$headline}</h1>
  <section id="vsl" class="vsl">{$vsl}</section>

  <div id="offer" class="reveal" data-reveal="{$reveal}">
    {$scarcity}
    {$packages}
    {$guarantee}
    {$proof}
    {$faq}
  </div>
</main>
<footer class="foot"><p class="disc">{$disclaimer}</p></footer>
<script>
(function(){
  var el = document.getElementById('offer');
  if(!el) return;
  var delay = parseInt(el.getAttribute('data-reveal')||'0',10);
  if(delay>0){ el.classList.add('hidden'); setTimeout(function(){ el.classList.remove('hidden'); }, delay*1000); }
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $offer
     */
    private function renderPackages(array $metrics, array $offer, string $checkout, string $ctaLabel): string
    {
        $packages = $this->extractPackages($metrics, $offer);
        if ($packages === []) {
            // graceful default from the price string
            $price = $this->esc((string) ($metrics['price'] ?? 'Special price inside'));

            return '<section class="pkgs"><div class="pkg best"><div class="pname">Best Value</div><div class="pprice">'.$price.'</div>'
                .$this->orderBtn($checkout, $ctaLabel).'</div></section>';
        }
        $cards = '';
        foreach ($packages as $i => $p) {
            $best = $i === 0 ? ' best' : '';
            $badge = $i === 0 ? '<div class="badge">MOST POPULAR</div>' : '';
            $cards .= '<div class="pkg'.$best.'">'.$badge
                .'<div class="pname">'.$this->esc($p['name']).'</div>'
                .'<div class="pprice">'.$this->esc($p['price']).'</div>'
                .($p['sub'] !== '' ? '<div class="psub">'.$this->esc($p['sub']).'</div>' : '')
                .$this->orderBtn($checkout, $ctaLabel).'</div>';
        }

        return '<section class="pkgs">'.$cards.'</section>';
    }

    private function orderBtn(string $checkout, string $label): string
    {
        $href = $this->esc($checkout);

        return '<a class="order" href="'.$href.'" data-goal="buy" rel="nofollow sponsored">'.$label.'</a>';
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $offer
     * @return array<int,array{name:string,price:string,sub:string}>
     */
    private function extractPackages(array $metrics, array $offer): array
    {
        // offer may carry a structured "packages"/"bundles" list; otherwise parse nothing and let caller default.
        foreach (['packages', 'bundles', 'tiers', 'options'] as $key) {
            if (is_array($offer[$key] ?? null)) {
                $out = [];
                foreach ($offer[$key] as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $out[] = [
                        'name' => (string) ($p['name'] ?? $p['label'] ?? $p['qty'] ?? 'Package'),
                        'price' => (string) ($p['price'] ?? $p['total'] ?? $p['cost'] ?? ''),
                        'sub' => (string) ($p['note'] ?? $p['per_unit'] ?? $p['shipping'] ?? ''),
                    ];
                }
                $out = array_values(array_filter($out, static fn ($p): bool => $p['price'] !== ''));
                if ($out !== []) {
                    return $out;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function renderGuarantee(array $metrics): string
    {
        $days = (int) ($metrics['guarantee_days'] ?? 0);
        if ($days <= 0) {
            return '';
        }

        return '<section class="guarantee"><div class="seal">'.$days.'</div><div><strong>'.$days.'-Day Money-Back Guarantee</strong>'
            .'<p>Try it risk-free. If you are not thrilled, contact us within '.$days.' days for a full refund — no questions asked.</p></div></section>';
    }

    /**
     * @param  array<string,string>  $lng
     */
    private function renderProof(AiMarketingVslAsset $asset, array $lng): string
    {
        $forged = $this->proofForge->forge($asset, $lng);
        $items = '';
        foreach (array_slice($forged['testimonials'], 0, 4) as $t) {
            $name = $this->esc((string) ($t['name'] ?? ''));
            $result = $this->esc((string) ($t['result'] ?? ''));
            $quote = $this->esc((string) ($t['quote'] ?? ''));
            if ($quote === '') {
                continue;
            }
            $tag = $result !== '' ? ' <span class="pr">'.$result.'</span>' : '';
            $items .= '<li>“'.$quote.'”<br><b>'.$name.'</b>'.$tag.'</li>';
        }
        $title = ($lng['lang'] ?? 'en') === 'pt' ? 'Gente real, resultados reais' : 'Real People, Real Results';

        return $items === '' ? '' : '<section class="proof"><h2>'.$title.'</h2><ul>'.$items.'</ul></section>';
    }

    private function renderFaq(AiMarketingVslAsset $asset): string
    {
        $objections = is_array($asset->objection_rebuttals) ? $asset->objection_rebuttals : [];
        $rows = '';
        foreach (array_slice($objections, 0, 8) as $o) {
            $q = is_array($o) ? (string) ($o['objection'] ?? $o['q'] ?? '') : '';
            $a = is_array($o) ? (string) ($o['rebuttal'] ?? $o['answer'] ?? $o['a'] ?? '') : (string) $o;
            if ($q === '' && $a === '') {
                continue;
            }
            $rows .= '<details class="q"><summary>'.$this->esc($q ?: 'Question').'</summary><p>'.$this->esc($a).'</p></details>';
        }

        return $rows === '' ? '' : '<section class="faq"><h2>Frequently Asked Questions</h2>'.$rows.'</section>';
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function renderScarcity(array $metrics): string
    {
        $nums = $metrics['scarcity_numbers'] ?? [];
        $first = is_array($nums) ? (string) (reset($nums) ?: '') : (string) $nums;
        if (trim($first) === '') {
            return '';
        }

        return '<section class="scarcity">⚠ '.$this->esc(Str::limit($first, 140)).'</section>';
    }

    private function placeholder(): string
    {
        return '<div class="vsl-ph"><span>▶</span><p>VSL embed goes here (autoplay).<br><code>[VSL_EMBED]</code></p></div>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars(trim($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return ':root{--ink:#15181e;--mut:#5b6470;--bg:#fff;--soft:#f5f6f8;--line:#e6e8ec;--cta:#16a34a;--cta2:#0a7d33;--warn:#b45309}'
            .'*{box-sizing:border-box}body{margin:0;font:17px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:var(--bg)}'
            .'.wrap{max-width:720px;margin:0 auto;padding:20px 16px 60px}'
            .'.vh{font-size:27px;line-height:1.2;text-align:center;font-weight:850;letter-spacing:-.3px;margin:6px 0 14px}'
            .'.vsl{background:#000;border-radius:14px;overflow:hidden;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;margin:0 0 18px}'
            .'.vsl-ph{color:#cfd3da;text-align:center}.vsl-ph span{display:block;font-size:46px;color:#fff;opacity:.85}.vsl-ph code{color:#7fd0ff}'
            .'.reveal.hidden{display:none}'
            .'.scarcity{background:#fff7ed;border:1px solid #fed7aa;color:var(--warn);font-weight:700;text-align:center;padding:10px;border-radius:10px;margin:0 0 16px;font-size:14.5px}'
            .'.pkgs{display:grid;gap:12px;margin:0 0 20px}@media(min-width:620px){.pkgs{grid-template-columns:repeat(3,1fr)}}'
            .'.pkg{position:relative;border:2px solid var(--line);border-radius:14px;padding:18px 14px;text-align:center;background:#fff}'
            .'.pkg.best{border-color:var(--cta);box-shadow:0 8px 22px rgba(22,163,74,.18)}'
            .'.badge{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--cta);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;padding:3px 10px;border-radius:20px}'
            .'.pname{font-weight:800;font-size:15px;margin-bottom:6px}.pprice{font-size:26px;font-weight:850}.psub{color:var(--mut);font-size:13px;margin-bottom:4px}'
            .'.order{display:block;margin-top:12px;background:var(--cta);color:#fff;text-decoration:none;font-weight:800;padding:14px;border-radius:10px;box-shadow:0 6px 16px rgba(22,163,74,.3)}'
            .'.order:active{transform:translateY(1px)}'
            .'.guarantee{display:flex;gap:14px;align-items:center;background:var(--soft);border-radius:14px;padding:16px;margin:0 0 20px}'
            .'.seal{flex:none;width:54px;height:54px;border-radius:50%;background:var(--cta);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:850;font-size:20px}'
            .'.guarantee p{margin:4px 0 0;color:var(--mut);font-size:14.5px}'
            .'.proof{margin:0 0 20px}.proof ul{list-style:none;padding:0;display:grid;gap:10px}.proof li{background:var(--soft);border-radius:10px;padding:12px 14px;font-size:15px}.proof li b{color:var(--ink)}.proof .pr{color:var(--cta2);font-weight:800}'
            .'.faq{margin:0 0 10px}.faq h2,.proof h2{font-size:21px;margin:0 0 10px}.q{border-bottom:1px solid var(--line);padding:10px 0}.q summary{font-weight:800;cursor:pointer}.q p{color:var(--mut);margin:8px 0 0}'
            .'.foot{border-top:1px solid var(--line);padding:18px 16px;color:var(--mut)}.disc{max-width:720px;margin:0 auto;font-size:12px;line-height:1.5}';
    }
}
