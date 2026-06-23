<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * EliteAdvertorialRenderer — renders a structured copy "deck" (headline/lead/mechanism/sections/proof/
 * objection/faq/ctas) into a complete, mobile-responsive editorial advertorial bridge page in the
 * "Daily Wellness Report" register: sticky countdown, AS-SEEN-ON bar, drop-cap lead, pull-quotes,
 * ingredient/hormone cards, stat tiles, verified-buyer testimonial cards, repeated + sticky CTAs,
 * rotating social-proof toasts, FAQ. Provider-free (pure templating). The deck is the elite COPY
 * (written by the copy engine); this is the elite CHROME. Before/after stay labeled producer slots.
 */
class EliteAdvertorialRenderer
{
    /**
     * @param  array<string,mixed>  $deck
     * @param  array<string,mixed>  $opts  brand, vsl_url, countdown_seconds, before_after (producer slots), toasts
     */
    public function render(array $deck, array $opts = []): string
    {
        $brand = (string) ($opts['brand'] ?? 'The Daily Wellness Report');
        $vsl = $this->esc((string) ($opts['vsl_url'] ?? '#watch'));
        $cd = (int) ($opts['countdown_seconds'] ?? 720);

        $kicker = $this->esc((string) ($deck['kicker'] ?? 'SPECIAL REPORT'));
        $headline = $this->esc((string) ($deck['headline'] ?? ''));
        $dek = $this->esc((string) ($deck['dek'] ?? ''));
        $byline = $this->esc((string) ($deck['byline'] ?? 'By the Editorial Desk · 6 min read'));
        $countdownText = $this->esc((string) ($deck['countdown_text'] ?? 'This special report is scheduled to be taken offline in'));
        $scarcity = $this->esc((string) ($deck['scarcity_line'] ?? 'Free to watch only while the video stays up.'));

        $css = $this->css();
        $cta = fn (string $label, string $sub = '') => $this->ctaBlock($label, $sub, $vsl);

        $h = [];
        $h[] = "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">";
        $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $h[] = '<title>'.$headline.'</title><style>'.$css.'</style></head><body>';

        // Sticky countdown bar
        $h[] = '<div class="countbar">'.$countdownText.' <span id="cd" class="cd">12:00</span></div>';

        // Masthead
        $h[] = '<header class="mast"><div class="mast-name">'.$this->esc($brand).'</div><div class="mast-sec">HEALTH &amp; SCIENCE</div></header>';

        $h[] = '<main class="wrap">';

        // Title block
        $h[] = '<p class="kicker">'.$kicker.'</p>';
        $h[] = '<h1 class="hl">'.$headline.'</h1>';
        if ($dek !== '') {
            $h[] = '<p class="dek">'.$dek.'</p>';
        }
        $h[] = '<div class="byline"><span class="avatar"></span><span>'.$byline.'</span></div>';

        // AS-SEEN-ON / trust bar
        $trust = (array) ($deck['trust_bar'] ?? ['CBS', 'ABC NEWS', 'FOX', 'NBC', 'USA TODAY']);
        $h[] = '<div class="seenon"><span class="seenon-lbl">AS SEEN ON</span>'
            .implode('', array_map(fn ($t) => '<span class="seenon-item">'.$this->esc((string) $t).'</span>', $trust)).'</div>';

        // Hero video block
        $heroStat = $this->esc((string) ($deck['mechanism']['name'] ?? '63 Lbs Gone'));
        $h[] = '<div class="hero">';
        $h[] = '<div class="hero-tags"><span class="tag-leak">LEAKED · SPECIAL REPORT</span><span class="tag-news">AS SEEN ON NATIONAL NEWS</span></div>';
        $h[] = '<div class="hero-big">'.$this->esc((string) ($deck['proof']['stats'][0]['value'] ?? '63 Lbs Gone')).' —<br><span class="hero-accent">No Injection. No Ozempic.</span></div>';
        $h[] = '<div class="hero-sub">'.$this->esc((string) ($deck['dek'] ?? 'The 4-ingredient protocol Big Pharma won\'t name')).'</div>';
        $h[] = '<div class="play">▶</div>';
        $h[] = $cta('Watch the Free Presentation →');
        $h[] = '<div class="scar">⚠ '.$scarcity.'</div>';
        $h[] = '</div>';

        // Trust chips — spoiler-safe defaults (NO guarantee number, NO product details). Override via deck['chips'].
        $chips = (array) ($deck['chips'] ?? ['As seen on national news', 'No injection, no needle', 'Free presentation', 'For women 40+']);
        $h[] = '<div class="chips">'.implode('', array_map(fn ($c) => '<span>✔ '.$this->esc((string) $c).'</span>', $chips)).'</div>';

        // Curiosity skim path (for busy_mom) — spoiler-safe: curiosity lines, not answers.
        $skim = (array) ($deck['skim'] ?? []);
        if ($skim !== []) {
            $h[] = '<aside class="tldr"><div class="tldr-h">No time? The 30-second version</div>'
                .implode('', array_map(fn ($s) => '<p>'.$this->esc((string) $s).'</p>', $skim)).'</aside>';
        } elseif (! empty($deck['mechanism']['why_diets_fail'])) {
            $h[] = '<aside class="tldr"><div class="tldr-h">The 30-second version</div><p>'
                .$this->esc((string) $deck['mechanism']['why_diets_fail']).'</p></aside>';
        }

        // Lead with drop-cap
        $lead = (array) ($deck['lead'] ?? []);
        foreach ($lead as $i => $p) {
            $cls = $i === 0 ? 'lead dropcap' : 'lead';
            $h[] = '<p class="'.$cls.'">'.$this->esc((string) $p).'</p>';
        }

        // Micro-scenes
        $scenes = (array) ($deck['micro_scenes'] ?? []);
        if ($scenes !== []) {
            $h[] = '<blockquote class="scenes">'.implode('', array_map(fn ($s) => '<p>'.$this->esc((string) $s).'</p>', $scenes)).'</blockquote>';
        }

        // The intrigue (spoiler-safe): the Melania/government/Big-Pharma curiosity + TEASED concept.
        foreach (['the_intrigue', 'why_it_was_hidden'] as $blockKey) {
            $blk = (array) ($deck[$blockKey] ?? []);
            if (! empty($blk['heading']) || ! empty($blk['body'])) {
                $h[] = '<section class="mech">';
                if (! empty($blk['heading'])) {
                    $h[] = '<h2>'.$this->esc((string) $blk['heading']).'</h2>';
                }
                foreach ((array) ($blk['body'] ?? []) as $p) {
                    $h[] = '<p>'.$this->esc((string) $p).'</p>';
                }
                $h[] = '</section>';
            }
        }

        // Mechanism — hormones + ingredient cards (ONLY rendered if present; a spoiler-safe bridge omits these).
        $mech = (array) ($deck['mechanism'] ?? []);
        if (! empty($mech['hormones']) || ! empty($mech['ingredients'])) {
            $h[] = '<section class="mech">';
            if (! empty($mech['name'])) {
                $h[] = '<h2>'.$this->esc((string) $mech['name']).'</h2>';
            }
            foreach (['hormones' => 'The three hormones', 'ingredients' => 'The four ingredients'] as $k => $title) {
                if (! empty($mech[$k])) {
                    $h[] = '<h3 class="grid-h">'.$title.'</h3><div class="cards">';
                    foreach ((array) $mech[$k] as $c) {
                        $h[] = '<div class="card"><div class="card-t">'.$this->esc((string) ($c['name'] ?? '')).'</div><div class="card-b">'.$this->esc((string) ($c['role'] ?? '')).'</div></div>';
                    }
                    $h[] = '</div>';
                }
            }
            $h[] = '</section>';
        }

        // What the presentation reveals — open-loop curiosity bullets pointing INTO the video.
        $reveals = (array) ($deck['what_the_presentation_reveals'] ?? []);
        if ($reveals !== []) {
            $h[] = '<aside class="reveals"><div class="reveals-h">Inside the free presentation, you\'ll discover</div><ul>';
            foreach ($reveals as $r) {
                $h[] = '<li>'.$this->esc((string) $r).'</li>';
            }
            $h[] = '</ul></aside>';
        }

        $h[] = $cta('Watch the Free Presentation →', 'Get the at-home method →');

        // Numbered sections with pull-quotes
        foreach ((array) ($deck['sections'] ?? []) as $idx => $sec) {
            $num = $this->esc((string) ($sec['number'] ?? (string) ($idx + 1)));
            $h[] = '<section class="sec"><h2 class="sec-h"><span class="num">'.$num.'</span>'.$this->esc((string) ($sec['heading'] ?? '')).'</h2>';
            foreach ((array) ($sec['body'] ?? []) as $b) {
                $h[] = '<p>'.$this->esc((string) $b).'</p>';
            }
            if (! empty($sec['pullquote'])) {
                $h[] = '<p class="pull">“'.$this->esc((string) $sec['pullquote']).'”</p>';
            }
            $h[] = '</section>';
        }

        // Proof — stat tiles
        $stats = (array) ($deck['proof']['stats'] ?? []);
        if ($stats !== []) {
            $h[] = '<div class="stats">';
            foreach ($stats as $s) {
                $h[] = '<div class="stat"><div class="stat-v">'.$this->esc((string) ($s['value'] ?? '')).'</div><div class="stat-l">'.$this->esc((string) ($s['label'] ?? '')).'</div></div>';
            }
            $h[] = '</div>';
        }

        // Before/after — real <img> elements, labeled PRODUCER SLOTS (integrity line). The producer
        // swaps the src for release-approved photos; until then these are clearly-marked placeholders.
        $ba = (array) ($opts['before_after'] ?? []);
        if ($ba !== []) {
            $h[] = '<div class="ba">';
            foreach ($ba as $t) {
                $before = $this->esc((string) ($t['before'] ?? ''));
                $after = $this->esc((string) ($t['after'] ?? ''));
                $imgs = $before !== '' && $after !== ''
                    ? '<img class="ba-img" src="'.$before.'" alt="before" loading="lazy"><img class="ba-img" src="'.$after.'" alt="after" loading="lazy">'
                    : '<div class="ba-ph"></div><div class="ba-ph ba-ph2"></div>';
                $h[] = '<figure class="ba-card"><div class="ba-imgs">'.$imgs
                    .'<span class="ba-b">BEFORE</span><span class="ba-a">AFTER</span><span class="ba-tag">'.$this->esc((string) ($t['result'] ?? '')).'</span></div>'
                    .'<figcaption class="ba-name">'.$this->esc((string) ($t['name'] ?? '')).' · '.$this->esc((string) ($t['weeks'] ?? '')).'</figcaption></figure>';
            }
            $h[] = '</div><p class="slot-note">▲ Before/after are producer-supplied, release-approved photo slots — swap the image src for the real photos.</p>';
        }

        // Testimonials — verified-buyer cards
        $tests = (array) ($deck['proof']['testimonials'] ?? []);
        if ($tests !== []) {
            $h[] = '<div class="tests">';
            foreach ($tests as $t) {
                $badge = ! empty($t['verified']) ? '<span class="vbadge">✔ Verified buyer</span>' : '';
                $meta = trim($this->esc((string) ($t['location'] ?? '')).' '.($t['result'] ? '· <span class="t-res">'.$this->esc((string) $t['result']).'</span>' : ''));
                $h[] = '<div class="tcard"><div class="stars">★★★★★</div>'.$badge
                    .'<div class="t-name">'.$this->esc((string) ($t['name'] ?? '')).'</div><div class="t-meta">'.$meta.'</div>'
                    .'<p class="t-quote">“'.$this->esc((string) ($t['quote'] ?? '')).'”</p></div>';
            }
            $h[] = '</div>';
        }

        // Objection crush
        $obj = (array) ($deck['objection'] ?? []);
        if (! empty($obj['heading']) || ! empty($obj['body'])) {
            $h[] = '<section class="sec obj"><h2 class="sec-h"><span class="num">!</span>'.$this->esc((string) ($obj['heading'] ?? 'But isn\'t this just another scam?')).'</h2>';
            foreach ((array) ($obj['body'] ?? []) as $b) {
                $h[] = '<p>'.$this->esc((string) $b).'</p>';
            }
            $h[] = '</section>';
        }

        // FAQ
        $faq = (array) ($deck['faq'] ?? []);
        if ($faq !== []) {
            $h[] = '<section class="faq"><h2>Quick questions</h2>';
            foreach ($faq as $f) {
                $h[] = '<div class="qa"><div class="q">'.$this->esc((string) ($f['q'] ?? '')).'</div><div class="a">'.$this->esc((string) ($f['a'] ?? '')).'</div></div>';
            }
            $h[] = '</section>';
        }

        // Final CTA
        $h[] = $cta('Watch the Free Presentation →', 'See the 4-ingredient protocol →');

        // PS — strip any leading "P.S./P.P.S." the copy already included so we don't double it.
        foreach ((array) ($deck['ps'] ?? []) as $i => $ps) {
            $clean = preg_replace('/^\s*P\.?\s?P?\.?S\.?\s*[—:-]?\s*/iu', '', (string) $ps);
            $label = $i === 0 ? 'P.S.' : 'P.P.S.';
            $h[] = '<p class="ps"><strong>'.$label.'</strong> '.$this->esc((string) $clean).'</p>';
        }

        $h[] = '<footer class="foot">This is an advertorial. Individual results vary. Statements have not been evaluated by the FDA. '
            .'This product is not intended to diagnose, treat, cure, or prevent any disease. Testimonials reflect individual experiences.</footer>';
        $h[] = '</main>';

        // Sticky bottom CTA
        $h[] = '<div class="sticky-cta"><a href="'.$vsl.'">Watch the Free Presentation →</a></div>';

        // Social-proof toasts + countdown JS
        // Spoiler-safe toasts — about WATCHING the presentation, never about ordering/bottles/price.
        $toasts = (array) ($opts['toasts'] ?? [
            ['Donna from Mesa, AZ', 'is watching the free presentation'],
            ['Patricia from Akron, OH', 'just started the presentation'],
            ['Linda from Tampa, FL', 'requested the free protocol'],
            ['Susan from Boise, ID', 'is watching the presentation now'],
        ]);
        $h[] = '<div id="toast" class="toast" style="display:none"><span class="t-av"></span><span><b id="t-name"></b><br><span id="t-act" class="t-act"></span> · just now</span></div>';
        $h[] = '<script>'.$this->js($cd, $toasts).'</script>';

        $h[] = '</body></html>';

        return implode("\n", $h);
    }

    private function ctaBlock(string $label, string $sub, string $vsl): string
    {
        $s = $sub !== '' ? '<span class="cta-sub">'.$this->esc($sub).'</span>' : '';

        return '<a class="cta" href="'.$vsl.'">'.$this->esc($label).$s.'</a>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** @param array<int,array<int,string>> $toasts */
    private function js(int $seconds, array $toasts): string
    {
        $data = json_encode(array_map(fn ($t) => ['n' => $t[0] ?? '', 'a' => $t[1] ?? ''], $toasts), JSON_UNESCAPED_UNICODE);

        return "var T={$seconds};var cd=document.getElementById('cd');"
            ."function tick(){var m=Math.floor(T/60),s=T%60;cd.textContent=m+':'+(s<10?'0':'')+s;if(T>0)T--;}"
            ."tick();setInterval(tick,1000);"
            ."var TS={$data},ti=0;var el=document.getElementById('toast');"
            ."function toast(){if(!TS.length)return;var t=TS[ti%TS.length];ti++;"
            ."document.getElementById('t-name').textContent=t.n;document.getElementById('t-act').textContent=t.a;"
            ."el.style.display='flex';el.classList.add('show');setTimeout(function(){el.classList.remove('show');setTimeout(function(){el.style.display='none';},400);},4500);}"
            ."setTimeout(toast,3000);setInterval(toast,11000);";
    }

    private function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;font-family:Georgia,'Times New Roman',serif;color:#1a1a1a;background:#fff;line-height:1.6;font-size:18px}
.countbar{position:sticky;top:0;z-index:50;background:#c0231f;color:#fff;text-align:center;font-family:Arial,sans-serif;font-size:13px;font-weight:700;padding:8px}
.cd{background:#fff;color:#c0231f;padding:1px 7px;border-radius:3px;font-variant-numeric:tabular-nums}
.mast{border-bottom:2px solid #111;max-width:760px;margin:0 auto;padding:18px 20px 12px;text-align:center}
.mast-name{font-size:24px;font-weight:700;letter-spacing:.5px}
.mast-sec{font-family:Arial,sans-serif;font-size:11px;letter-spacing:3px;color:#666;margin-top:3px}
.wrap{max-width:680px;margin:0 auto;padding:24px 20px 120px}
.kicker{font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:2px;color:#c0231f;margin:8px 0 4px}
.hl{font-size:40px;line-height:1.12;font-weight:700;margin:6px 0 14px;letter-spacing:-.5px}
.dek{font-size:21px;color:#333;margin:0 0 18px}
.byline{display:flex;align-items:center;gap:10px;font-family:Arial,sans-serif;font-size:14px;color:#666;border-bottom:1px solid #eee;padding-bottom:16px;margin-bottom:20px}
.avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#e8b9a0,#c98b6e);display:inline-block}
.seenon{display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:center;background:#f5f5f5;padding:14px;border-radius:6px;margin-bottom:22px;font-family:Arial,sans-serif}
.seenon-lbl{font-size:11px;letter-spacing:1.5px;color:#999}
.seenon-item{font-weight:700;color:#555;font-size:15px;letter-spacing:.5px}
.hero{background:#111;color:#fff;border:3px solid #c0231f;border-radius:10px;padding:22px;text-align:center;margin-bottom:14px}
.hero-tags{display:flex;justify-content:space-between;font-family:Arial,sans-serif;font-size:11px;font-weight:700;margin-bottom:18px;flex-wrap:wrap;gap:6px}
.tag-leak{background:#c0231f;padding:4px 8px;border-radius:3px}
.tag-news{color:#888;letter-spacing:1px}
.hero-big{font-size:33px;font-weight:700;line-height:1.15}
.hero-accent{color:#f5a623}
.hero-sub{font-family:Arial,sans-serif;font-size:15px;color:#ccc;margin:12px 0 18px}
.play{width:74px;height:74px;border-radius:50%;background:#c0231f;color:#fff;font-size:30px;line-height:74px;margin:0 auto 18px;cursor:pointer;box-shadow:0 6px 24px rgba(192,35,31,.5)}
.cta{display:block;background:#ef6c1a;color:#fff;text-decoration:none;text-align:center;font-family:Arial,sans-serif;font-weight:700;font-size:20px;padding:18px;border-radius:8px;margin:14px 0;box-shadow:0 4px 0 #c9560e;transition:transform .1s}
.cta:active{transform:translateY(2px)}
.cta-sub{display:block;font-size:13px;font-weight:400;opacity:.92;margin-top:4px}
.scar{color:#f5a623;font-family:Arial,sans-serif;font-size:13px;font-weight:700;margin-top:10px}
.chips{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;background:#f5f5f5;padding:14px;border-radius:6px;font-family:Arial,sans-serif;font-size:14px;color:#2a7a3a;font-weight:700;margin-bottom:26px}
.tldr{background:#fff8e6;border-left:4px solid #f5a623;padding:14px 18px;border-radius:0 6px 6px 0;margin:0 0 26px}
.tldr-h{font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:1px;color:#b8860b;text-transform:uppercase;margin-bottom:4px}
.tldr p{margin:0 0 8px;font-size:17px}
.tldr p:last-child{margin:0}
.reveals{background:#0f1c2e;color:#eaf0f7;border-radius:10px;padding:20px 22px;margin:8px 0 22px}
.reveals-h{font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#f5a623;margin-bottom:12px}
.reveals ul{margin:0;padding-left:0;list-style:none}
.reveals li{font-family:Arial,sans-serif;font-size:16px;padding:8px 0 8px 28px;position:relative;border-bottom:1px solid rgba(255,255,255,.08)}
.reveals li:last-child{border-bottom:0}
.reveals li::before{content:'▸';position:absolute;left:6px;color:#f5a623}
.lead{font-size:19px;margin:0 0 18px}
.dropcap::first-letter{font-size:62px;line-height:48px;float:left;font-weight:700;padding:4px 10px 0 0;color:#c0231f}
.scenes{border-left:3px solid #ddd;margin:0 0 24px;padding:6px 0 6px 20px;color:#444}
.scenes p{margin:0 0 12px;font-style:italic}
.mech{margin:8px 0 10px}
.mech h2,.faq h2{font-size:27px;margin:30px 0 14px}
.grid-h{font-family:Arial,sans-serif;font-size:13px;letter-spacing:1px;text-transform:uppercase;color:#888;margin:18px 0 10px}
.cards{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.card{background:#f7f7f7;border-radius:8px;padding:14px}
.card-t{font-weight:700;font-size:17px;margin-bottom:5px}
.card-b{font-family:Arial,sans-serif;font-size:14px;color:#555}
.sec{margin:26px 0}
.sec-h{font-size:27px;margin:0 0 14px;display:flex;align-items:flex-start;gap:12px;line-height:1.2}
.num{flex:none;width:30px;height:30px;border-radius:50%;background:#c0231f;color:#fff;font-family:Arial,sans-serif;font-size:16px;font-weight:700;text-align:center;line-height:30px;margin-top:4px}
.pull{font-size:23px;font-weight:700;color:#111;border-left:4px solid #c0231f;padding-left:18px;margin:20px 0;line-height:1.3}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:24px 0}
.stat{border:1px solid #eee;border-radius:8px;padding:14px 8px;text-align:center}
.stat-v{font-size:25px;font-weight:700;color:#c0231f}
.stat-l{font-family:Arial,sans-serif;font-size:12px;color:#777;margin-top:4px}
.ba{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0 6px}
.ba-card{border:1px solid #eee;border-radius:8px;overflow:hidden}
.ba-imgs{position:relative;height:160px;display:flex;background:#e8edf0;overflow:hidden}
.ba-img{width:50%;height:160px;object-fit:cover;display:block}
.ba-ph{width:50%;height:160px;background:#cdd5da}
.ba-ph2{background:#9fb4c2}
.ba-card figcaption{margin:0}
.ba-b,.ba-a{position:absolute;bottom:6px;font-family:Arial,sans-serif;font-size:10px;font-weight:700;color:#fff;background:rgba(0,0,0,.55);padding:2px 6px;border-radius:3px}
.ba-b{left:6px}.ba-a{right:6px}
.ba-tag{position:absolute;top:8px;left:50%;transform:translateX(-50%);background:#fff;color:#2a7a3a;font-family:Arial,sans-serif;font-size:13px;font-weight:700;padding:3px 10px;border-radius:20px}
.ba-name{font-family:Arial,sans-serif;font-size:13px;padding:8px 10px;color:#444}
.slot-note{text-align:center;font-family:Arial,sans-serif;font-size:11px;color:#aaa;margin:0 0 24px}
.tests{display:grid;gap:14px;margin:20px 0}
.tcard{background:#f9f9f9;border-radius:8px;padding:16px 18px}
.stars{color:#f5a623;letter-spacing:2px}
.vbadge{font-family:Arial,sans-serif;font-size:11px;color:#2a7a3a;font-weight:700;margin-left:8px}
.t-name{font-weight:700;margin-top:6px}
.t-meta{font-family:Arial,sans-serif;font-size:13px;color:#777}
.t-res{color:#2a7a3a;font-weight:700}
.t-quote{margin:8px 0 0;font-style:italic}
.obj .num{background:#111}
.faq{margin:30px 0}
.qa{border-top:1px solid #eee;padding:14px 0}
.q{font-weight:700;font-size:18px}
.a{font-family:Arial,sans-serif;font-size:16px;color:#444;margin-top:4px}
.ps{font-size:18px;background:#fff8e6;padding:14px 16px;border-radius:6px;margin:10px 0}
.foot{font-family:Arial,sans-serif;font-size:12px;color:#999;border-top:1px solid #eee;margin-top:34px;padding-top:18px;line-height:1.5}
.sticky-cta{position:fixed;bottom:0;left:0;right:0;z-index:40;padding:10px;background:linear-gradient(180deg,transparent,#fff 30%)}
.sticky-cta a{display:block;max-width:660px;margin:0 auto;background:#ef6c1a;color:#fff;text-decoration:none;text-align:center;font-family:Arial,sans-serif;font-weight:700;font-size:18px;padding:15px;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.25)}
.toast{position:fixed;left:16px;bottom:84px;z-index:45;background:#fff;border:1px solid #e5e5e5;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.15);padding:12px 16px;display:flex;align-items:center;gap:10px;font-family:Arial,sans-serif;font-size:13px;max-width:260px;opacity:0;transform:translateY(10px);transition:all .4s}
.toast.show{opacity:1;transform:translateY(0)}
.t-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#d8a0b9,#9e6ec9);flex:none}
.t-act{color:#777}
@media(max-width:560px){.hl{font-size:31px}.cards{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}.ba{grid-template-columns:1fr}.wrap{padding:18px 16px 120px}}
CSS;
    }
}
