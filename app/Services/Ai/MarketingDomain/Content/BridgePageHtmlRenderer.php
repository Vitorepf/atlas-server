<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * BridgePageHtmlRenderer — turns the structured bridge (BridgePageComposerService output) into a
 * self-contained, conversion-shaped advertorial page: mobile-first, fast (inline critical CSS, no
 * external blocking assets), trust above the fold, ONE goal (watch the VSL). Every CTA routes to the
 * embedded VSL (#vsl). Deterministic — same bridge in → same HTML out; no LLM, no DB.
 */
class BridgePageHtmlRenderer
{
    /**
     * @param  array<string,mixed>  $bridge
     * @param  array<string,mixed>  $opts  vsl_embed_html (string), brand (string)
     */
    public function render(array $bridge, array $opts = []): string
    {
        // Fixed UI labels follow the PAGE language (a US/English page must not carry PT chrome).
        $rawLang = (string) ($bridge['meta']['lang'] ?? 'en');
        $L = $this->labels($rawLang);

        $brand = $this->esc((string) ($opts['brand'] ?? $L['brand']));
        $kicker = $this->esc((string) ($bridge['kicker'] ?? $L['kicker']));
        $h1 = $this->esc((string) ($bridge['headline'] ?? ''));
        $dek = $this->esc((string) ($bridge['subheadline'] ?? ''));
        $lead = $this->paras((string) ($bridge['lead_paragraph'] ?? ''));
        $heroCtaLabel = $this->esc((string) ($bridge['hero_cta']['label'] ?? $L['cta']));

        $seoTitle = $this->esc((string) ($bridge['meta']['seo_title'] ?? ($bridge['headline'] ?? $brand)));
        $seoDesc = $this->esc((string) ($bridge['meta']['seo_description'] ?? ($bridge['subheadline'] ?? '')));
        $lang = $this->esc($rawLang);

        // Player slot: a real embed wins; else the generated thumbnail (poster + play); else placeholder.
        $embed = trim((string) ($opts['vsl_embed_html'] ?? ''));
        $thumb = trim((string) ($opts['thumbnail_svg'] ?? ''));
        $vslEmbed = $embed !== '' ? $embed : ($thumb !== '' ? $thumb : $this->placeholderEmbed($L));

        $trust = $this->renderTrustBar($bridge['trust_bar'] ?? []);
        $sections = $this->renderSections($bridge['body_sections'] ?? $bridge['sections'] ?? []);
        $mechanism = $this->renderMechanism((string) ($bridge['mechanism_tease'] ?? ''), $L);
        $proof = $this->renderProof($bridge['proof_block'] ?? $bridge['proof'] ?? [], $L);
        $objections = $this->renderObjections($bridge['objection_flips'] ?? [], $L);
        $ctaBlocks = $this->renderCtaBlocks($bridge['cta_blocks'] ?? [], $heroCtaLabel);
        $ps = $this->renderPs((string) ($bridge['ps'] ?? ''), $L);
        $tagLabel = $this->esc($L['tag']);
        $disclosure = $this->esc((string) ($bridge['disclosure'] ?? $L['disclosure']));

        $css = $this->css();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$seoTitle}</title>
<meta name="description" content="{$seoDesc}">
<meta name="robots" content="noindex">
<style>{$css}</style>
</head>
<body>
<header class="topbar"><span class="brand">{$brand}</span><span class="tag">{$tagLabel}</span></header>

<main>
  <article class="wrap">
    <p class="kicker">{$kicker}</p>
    <h1>{$h1}</h1>
    <p class="dek">{$dek}</p>

    <section id="vsl" class="vsl">{$vslEmbed}</section>
    <a class="cta cta-hero" href="#vsl" data-goal="watch_video">{$heroCtaLabel}</a>

    {$trust}

    <div class="body">
      {$lead}
      {$mechanism}
      {$sections}
      {$proof}
      {$objections}
    </div>

    {$ctaBlocks}
    {$ps}
  </article>
</main>

<footer class="foot">
  <p class="disclosure">{$disclosure}</p>
</footer>

<a class="cta cta-sticky" href="#vsl" data-goal="watch_video">{$heroCtaLabel}</a>
</body>
</html>
HTML;
    }

    /**
     * @param  array<int,mixed>|mixed  $items
     */
    private function renderTrustBar(mixed $items): string
    {
        $items = is_array($items) ? $items : [];
        if ($items === []) {
            return '';
        }
        $cells = '';
        foreach (array_slice($items, 0, 5) as $it) {
            $txt = is_array($it) ? (string) ($it['text'] ?? $it['label'] ?? reset($it)) : (string) $it;
            if (trim($txt) === '') {
                continue;
            }
            $cells .= '<li>'.$this->esc($txt).'</li>';
        }

        return $cells === '' ? '' : '<ul class="trust">'.$cells.'</ul>';
    }

    /**
     * @param  array<int,mixed>|mixed  $sections
     */
    private function renderSections(mixed $sections): string
    {
        $sections = is_array($sections) ? array_values(array_filter($sections, 'is_array')) : [];
        if ($sections === []) {
            return '';
        }
        $out = '';
        $n = 1;
        foreach ($sections as $s) {
            $heading = $this->esc((string) ($s['heading'] ?? $s['title'] ?? ''));
            $body = $this->paras((string) ($s['body'] ?? $s['text'] ?? $s['copy'] ?? ''));
            $loop = trim((string) ($s['open_loop'] ?? ''));
            $loopHtml = $loop !== '' ? '<p class="loop">'.$this->esc($loop).'</p>' : '';
            $num = $heading !== '' ? '<span class="num">'.$n.'</span>' : '';
            $out .= "<section class=\"item\"><h2>{$num}{$heading}</h2>{$body}{$loopHtml}</section>";
            $n++;
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $L
     */
    private function renderMechanism(string $tease, array $L): string
    {
        if (trim($tease) === '') {
            return '';
        }

        return '<aside class="mechanism"><h3>'.$this->esc($L['mechanism_h']).'</h3>'.$this->paras($tease).'<p class="loop">'.$this->esc($L['mechanism_loop']).'</p></aside>';
    }

    /**
     * @param  array<string,mixed>|mixed  $proof
     * @param  array<string,string>  $L
     */
    private function renderProof(mixed $proof, array $L): string
    {
        $proof = is_array($proof) ? $proof : [];
        $testimonials = is_array($proof['testimonials'] ?? null) ? $proof['testimonials'] : [];
        $stats = is_array($proof['stat_callouts'] ?? null) ? $proof['stat_callouts'] : (is_array($proof['stats'] ?? null) ? $proof['stats'] : []);

        $cards = '';
        foreach (array_slice($testimonials, 0, 6) as $t) {
            if (! is_array($t)) {
                continue;
            }
            $name = $this->esc((string) ($t['name'] ?? $L['verified']));
            $result = $this->esc((string) ($t['result'] ?? ''));
            $quote = $this->esc((string) ($t['quote'] ?? $t['text'] ?? ''));
            $resultHtml = $result !== '' ? '<span class="result">'.$result.'</span>' : '';
            $cards .= "<figure class=\"tcard\"><blockquote>“{$quote}”</blockquote><figcaption>{$name} {$resultHtml}</figcaption></figure>";
        }
        $statHtml = '';
        foreach (array_slice($stats, 0, 4) as $st) {
            $txt = is_array($st) ? (string) ($st['text'] ?? reset($st)) : (string) $st;
            if (trim($txt) !== '') {
                $statHtml .= '<li>'.$this->esc($txt).'</li>';
            }
        }
        $statBlock = $statHtml !== '' ? '<ul class="stats">'.$statHtml.'</ul>' : '';
        $cardBlock = $cards !== '' ? '<div class="tgrid">'.$cards.'</div>' : '';
        if ($statBlock === '' && $cardBlock === '') {
            return '';
        }

        return '<section class="proof"><h2>'.$this->esc($L['proof_h']).'</h2>'.$statBlock.$cardBlock.'</section>';
    }

    /**
     * @param  array<int,mixed>|mixed  $objections
     * @param  array<string,string>  $L
     */
    private function renderObjections(mixed $objections, array $L): string
    {
        $objections = is_array($objections) ? array_values(array_filter($objections, 'is_array')) : [];
        if ($objections === []) {
            return '';
        }
        $rows = '';
        foreach (array_slice($objections, 0, 5) as $o) {
            $q = $this->esc((string) ($o['objection'] ?? $o['q'] ?? ''));
            $a = $this->esc((string) ($o['flip'] ?? $o['answer'] ?? $o['a'] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $rows .= "<details class=\"obj\"><summary>{$q}</summary><p>{$a}</p></details>";
        }

        return $rows === '' ? '' : '<section class="objections"><h2>'.$this->esc($L['objections_h']).'</h2>'.$rows.'</section>';
    }

    /**
     * @param  array<int,mixed>|mixed  $blocks
     */
    private function renderCtaBlocks(mixed $blocks, string $fallbackLabel): string
    {
        $blocks = is_array($blocks) ? $blocks : [];
        $out = '';
        foreach (array_slice($blocks, 0, 4) as $b) {
            $label = $this->esc((string) (is_array($b) ? ($b['label'] ?? $b['text'] ?? $fallbackLabel) : $b));
            $sub = $this->esc((string) (is_array($b) ? ($b['subtext'] ?? '') : ''));
            $subHtml = $sub !== '' ? '<span class="sub">'.$sub.'</span>' : '';
            $out .= "<div class=\"ctabox\"><a class=\"cta\" href=\"#vsl\" data-goal=\"watch_video\">{$label}</a>{$subHtml}</div>";
        }
        if ($out === '') {
            $out = "<div class=\"ctabox\"><a class=\"cta\" href=\"#vsl\" data-goal=\"watch_video\">{$fallbackLabel}</a></div>";
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $L
     */
    private function renderPs(string $ps, array $L): string
    {
        $ps = trim($ps);
        if ($ps === '') {
            return '';
        }
        // strip a leading "P.S." the model may have written, so the label isn't duplicated.
        $ps = (string) preg_replace('/^\s*p\.?\s*s\.?\s*[:\-—]?\s*/iu', '', $ps);

        return '<p class="ps"><strong>'.$this->esc($L['ps']).'</strong> '.$this->esc($ps).'</p>';
    }

    /**
     * @param  array<string,string>  $L
     */
    private function placeholderEmbed(array $L): string
    {
        return '<div class="vsl-ph"><span>▶</span><p>'.$this->esc($L['vsl_ph']).'<br><code>[VSL_EMBED]</code></p></div>';
    }

    /**
     * Fixed UI labels in the page language (English default; Portuguese when the page is PT).
     *
     * @return array<string,string>
     */
    private function labels(string $lang): array
    {
        $l = mb_strtolower($lang);
        $isPt = str_contains($l, 'pt') || str_contains($l, 'portug') || str_contains($l, 'brasil') || str_contains($l, 'brazil');

        if ($isPt) {
            return [
                'brand' => 'Saúde em Foco', 'tag' => 'Publieditorial', 'kicker' => 'REPORTAGEM ESPECIAL',
                'cta' => 'Assistir à apresentação gratuita →', 'ps' => 'P.S.', 'verified' => 'Cliente verificado',
                'mechanism_h' => 'O mecanismo (e por que só funciona desse jeito)',
                'mechanism_loop' => 'A explicação completa — e a receita exata — estão na apresentação acima. ▲',
                'proof_h' => 'O que está acontecendo com quem já testou',
                'objections_h' => '"Mas e se…"',
                'vsl_ph' => 'Sua VSL entra aqui (auto-play, controles ocultos).',
                'disclosure' => 'Conteúdo publicitário. Resultados individuais variam e não são garantidos. Não substitui aconselhamento médico — consulte um profissional de saúde.',
            ];
        }

        return [
            'brand' => 'The Daily Health Report', 'tag' => 'Advertorial', 'kicker' => 'SPECIAL HEALTH REPORT',
            'cta' => 'Watch the Free Presentation →', 'ps' => 'P.S.', 'verified' => 'Verified customer',
            'mechanism_h' => 'The mechanism (and why it only works this way)',
            'mechanism_loop' => 'The full explanation — and the exact recipe — are in the presentation above. ▲',
            'proof_h' => 'What is happening for people who tried it',
            'objections_h' => '"But what if…"',
            'vsl_ph' => 'Your VSL goes here (auto-play, controls hidden).',
            'disclosure' => 'Advertising content. Individual results vary and are not guaranteed. This is not medical advice — consult a healthcare professional.',
        ];
    }

    /** Split text into <p> blocks on blank lines / sentence-group breaks. */
    private function paras(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $blocks = preg_split('/\n{2,}|\r\n\r\n/u', $text) ?: [$text];
        $out = '';
        foreach ($blocks as $b) {
            $b = trim($b);
            if ($b !== '') {
                $out .= '<p>'.$this->esc($b).'</p>';
            }
        }

        return $out;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars(trim($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return ':root{--ink:#16181d;--mut:#5b6470;--bg:#fff;--soft:#f5f6f8;--line:#e6e8ec;--accent:#d8232a;--accent2:#0a7d33;--cta:#e8400a}'
            .'*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}'
            .'body{margin:0;font:17px/1.62 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:var(--bg)}'
            .'.topbar{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);font-size:13px}'
            .'.brand{font-weight:800;letter-spacing:.2px}.tag{color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.12em}'
            .'main{padding:0 16px}.wrap{max-width:680px;margin:0 auto;padding:18px 0 120px}'
            .'.kicker{color:var(--accent);font-weight:800;font-size:12.5px;letter-spacing:.14em;text-transform:uppercase;margin:6px 0 8px}'
            .'h1{font-size:30px;line-height:1.18;margin:0 0 10px;letter-spacing:-.4px;font-weight:850}'
            .'.dek{font-size:19px;color:var(--mut);margin:0 0 16px}'
            .'.vsl{background:#000;border-radius:14px;overflow:hidden;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;margin:8px 0 14px}'
            .'.vsl-ph{color:#cfd3da;text-align:center;font-size:14px}.vsl-ph span{display:block;font-size:46px;margin-bottom:6px;color:#fff;opacity:.85}.vsl-ph code{color:#7fd0ff}'
            .'.cta{display:block;background:var(--cta);color:#fff;text-decoration:none;text-align:center;font-weight:800;font-size:19px;padding:17px 20px;border-radius:12px;box-shadow:0 6px 18px rgba(232,64,10,.32);transition:transform .06s}'
            .'.cta:active{transform:translateY(1px)}.cta-hero{margin:0 0 14px}'
            .'.ctabox{margin:22px 0;text-align:center}.ctabox .sub{display:block;color:var(--mut);font-size:13.5px;margin-top:8px}'
            .'.cta-sticky{position:fixed;left:12px;right:12px;bottom:12px;max-width:660px;margin:0 auto;font-size:17px;padding:15px 18px;z-index:50}'
            .'.trust{list-style:none;display:flex;flex-wrap:wrap;gap:8px;justify-content:center;padding:12px;margin:0 0 18px;background:var(--soft);border-radius:12px;font-size:13px;color:var(--ink)}'
            .'.trust li{font-weight:700}.trust li:before{content:"★ ";color:#f5a623}'
            .'.body h2{font-size:22px;line-height:1.25;margin:30px 0 8px;font-weight:820;letter-spacing:-.2px}'
            .'.body h3{font-size:18px;margin:6px 0}.body p{margin:0 0 13px}'
            .'.item .num{display:inline-flex;width:30px;height:30px;margin-right:10px;align-items:center;justify-content:center;background:var(--accent);color:#fff;border-radius:50%;font-size:15px;font-weight:800;vertical-align:middle}'
            .'.loop{color:var(--accent);font-weight:700}'
            .'.mechanism{background:#fff8e8;border:1px solid #f0dca6;border-left:4px solid #e0a800;border-radius:12px;padding:14px 16px;margin:22px 0}'
            .'.mechanism h3{margin-top:0}'
            .'.proof{margin:26px 0}.stats{list-style:none;padding:0;margin:0 0 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}'
            .'.stats li{background:var(--soft);border-radius:10px;padding:10px 12px;font-weight:700;font-size:14.5px}'
            .'.tgrid{display:grid;gap:12px}.tcard{margin:0;background:var(--soft);border-radius:12px;padding:14px 16px}'
            .'.tcard blockquote{margin:0 0 8px;font-size:15.5px}.tcard figcaption{font-size:13px;color:var(--mut);font-weight:700}'
            .'.tcard .result{color:var(--accent2);font-weight:800}'
            .'.objections{margin:26px 0}.obj{border-bottom:1px solid var(--line);padding:10px 0}.obj summary{font-weight:800;cursor:pointer;font-size:16px}.obj p{margin:8px 0 0;color:var(--mut)}'
            .'.ps{background:var(--soft);border-radius:12px;padding:14px 16px;font-size:15px;margin:24px 0}'
            .'.foot{border-top:1px solid var(--line);padding:18px 16px 90px;color:var(--mut)}.disclosure{max-width:680px;margin:0 auto;font-size:12px;line-height:1.5}'
            .'@media(min-width:560px){h1{font-size:38px}.stats{grid-template-columns:repeat(4,1fr)}.tgrid{grid-template-columns:1fr 1fr}}';
    }
}
