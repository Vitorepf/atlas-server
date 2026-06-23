<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * VslPlayerPageRenderer — renders the ELITE VSL page that wraps a vTurb (converteai) smartplayer for a
 * long-form (60-90 min) VSL. Built to the master-panel spec (congruence + dopamine/retention + CRO +
 * the 40-60 skeptical-woman persona + an 8-figure VSL media buyer), then pruned by the anti-Goodhart
 * rule (every element must have a PROVEN conversion mechanism; anything merely "believed" to convert,
 * or that competes with the video, is cut).
 *
 * What it is:
 *   1. CONGRUENT — the same warm "Daily Wellness Report" editorial publication (cream #faf6ef, white
 *      article sheet, serif, #c0231f rule). Breaking the visual scent from the advertorial is the #1
 *      conversion killer for this cold-traffic audience; a cold/black page severs it in the first 3s.
 *   2. ATTENTION RATIO 1:1 — the player is the only interactive element. No page CTA (vTurb reveals
 *      the CTA at the pitch second), no nav, no exit links, no readable sales copy before the pitch.
 *   3. TIMED DOPAMINE, SYNCED TO REAL PLAYBACK — a rotating serif "kicker whisper" (open loops) + a
 *      thin real-currentTime progress underline carry retention across the hour. Driven by
 *      smartplayer.instances[0].on('timeupdate'), never a wall-clock timer (so they survive pauses).
 *   4. PITCH SUPPORT, REVEALED ONLY AT THE PITCH — a calm guarantee/verified cluster is revealed by
 *      vTurb's displayHiddenElements(...,{persist:true}) to FRAME (not replace) the in-player CTA.
 *
 * Explicitly CUT by the anti-Goodhart pass (do not re-add): fabricated named social-proof toasts,
 * persistent drifting viewer counters, live pulse dots, seekable/numeric progress, countdown/scarcity
 * theatre, email capture, any wall-clock reveal. Provider-free templating.
 */
class VslPlayerPageRenderer
{
    /**
     * @param  array{player_id:string,script_src:string}  $embed
     * @param  array<int,array{t:int,text:string}>  $kickerSchedule  rotating open-loop whispers by REAL video second
     * @param  array<string,mixed>  $opts  headline, dek, pitch_seconds, brand, masthead_section, pitch_html, disclaimer
     */
    public function render(array $embed, array $kickerSchedule, array $opts = []): string
    {
        $playerId = $this->esc((string) ($embed['player_id'] ?? ''));
        $scriptSrc = $this->esc((string) ($embed['script_src'] ?? ''));
        $pitch = (int) ($opts['pitch_seconds'] ?? 3960);
        $brand = $this->esc((string) ($opts['brand'] ?? 'The Daily Wellness Report'));
        $masthead = $this->esc((string) ($opts['masthead_section'] ?? 'HEALTH & SCIENCE'));
        $headline = $this->esc((string) ($opts['headline'] ?? "The 'willpower' story you were told about your weight may have the cause backwards."));
        $dek = $this->esc((string) ($opts['dek'] ?? 'The Daily Wellness Report\'s companion video briefing — the full findings, in her words. Press play and stay with it to the end.'));
        $disclaimer = $this->esc((string) ($opts['disclaimer'] ??
            'Individual results vary. This presentation is for educational purposes. Statements have not been '
            .'evaluated by the FDA. Not intended to diagnose, treat, cure, or prevent any disease.'));

        // Rotating "kicker whisper" channel — open loops synced to REAL video seconds (the retention lever).
        $kickers = array_values(array_filter($kickerSchedule, fn ($k) => isset($k['t'], $k['text'])));
        usort($kickers, fn ($a, $b) => $a['t'] <=> $b['t']);
        if ($kickers === []) {
            $kickers = [['t' => 0, 'text' => 'Special Health Briefing — continued']];
        }
        $kickerJson = json_encode(array_map(fn ($k) => ['t' => (int) $k['t'], 'x' => (string) $k['text']], $kickers), JSON_UNESCAPED_UNICODE);
        $firstKicker = $this->esc((string) $kickers[0]['text']);

        // Pitch-time cluster (revealed by vTurb at the pitch). Decision support, NOT a CTA. Removable.
        $pitchHtml = (string) ($opts['pitch_html'] ?? $this->defaultPitchCluster());

        $h = [];
        $h[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $h[] = '<meta name="robots" content="noindex,nofollow">';
        $h[] = '<title>'.$brand.' — Special Presentation</title>';
        $h[] = '<style>'.$this->css().'</style></head><body>';

        // Masthead — the congruence anchor (same publication; static, non-clickable, no nav).
        $h[] = '<header class="mast"><div class="mast-name">'.$brand.'</div><div class="mast-sec">'.$masthead.'</div></header>';

        $h[] = '<main class="vsl">';

        // Kicker whisper (rotates via timeupdate) — the open-loop / anticipation eyebrow.
        $h[] = '<p id="vsl-kicker" class="vsl-kicker">'.$firstKicker.'</p>';

        // Static serif headline (the hook) + one-line dek — the frame, never readable sales copy.
        $h[] = '<h1 class="vsl-headline">'.$headline.'</h1>';
        $h[] = '<p class="vsl-dek">'.$dek.'</p>';

        // The player — the only interactive element (attention ratio 1:1).
        $h[] = '<div class="vsl-player">';
        $h[] = '<vturb-smartplayer id="'.$playerId.'" style="display:block;margin:0 auto;width:100%"></vturb-smartplayer>';
        $h[] = '</div>';

        // Real-playback progress underline (no number, no seek, no duration) — forward motion only.
        $h[] = '<div class="vsl-prog"><span id="vsl-prog-bar" class="vsl-prog-bar"></span></div>';
        $h[] = '<div class="vsl-prog-label">Report progress</div>';

        // Sound nudge (vTurb Smart Autoplay handles unmute; reinforces). Plain text, non-clickable.
        $h[] = '<p class="vsl-sound">Please make sure your sound is on — this report has audio and has already begun.</p>';

        // Pitch-time decision-support cluster (hidden until the pitch; revealed by vTurb).
        $h[] = '<div class="vsl-pitch hide">'.$pitchHtml.'</div>';

        $h[] = '</main>';

        $h[] = '<footer class="vsl-foot">'.$brand.' · '.$disclaimer.'</footer>';

        // vTurb loader (operator's exact embed loader).
        $h[] = '<script type="text/javascript">var s=document.createElement("script");s.src="'.$scriptSrc.'",s.async=!0,document.head.appendChild(s);</script>';

        $h[] = '<script>'.$this->js($pitch, $kickerJson).'</script>';

        $h[] = '</body></html>';

        return implode("\n", $h);
    }

    private function js(int $pitch, string $kickerJson): string
    {
        return
            // Native vTurb pitch reveal (persist/caching → returning viewers resume at peak warmth).
            'var vp=document.querySelector("vturb-smartplayer");'
            .'if(vp){vp.addEventListener("player:ready",function(){try{vp.displayHiddenElements('.$pitch.',[".hide"],{persist:true});}catch(e){}});}'
            .'var KICK='.$kickerJson.';var kEl=document.getElementById("vsl-kicker"),lastK="";'
            .'var pbar=document.getElementById("vsl-prog-bar");'
            .'function pickK(ct){var t=KICK[0].x;for(var i=0;i<KICK.length;i++){if(ct>=KICK[i].t)t=KICK[i].x;}return t;}'
            .'function setK(x){if(x===lastK)return;lastK=x;kEl.style.opacity="0";setTimeout(function(){kEl.textContent=x;kEl.style.opacity="1";},220);}'
            .'function onTime(ct,dur){setK(pickK(ct));if(pbar&&dur>0){var pct=Math.max(0,Math.min(100,(ct/dur)*100));pbar.style.width=pct+"%";}}'
            .'function bind(tries){if(typeof smartplayer==="undefined"||!(smartplayer.instances&&smartplayer.instances.length)){if(tries<60){setTimeout(function(){bind(tries+1);},500);}return;}'
            .'var inst=smartplayer.instances[0];inst.on("timeupdate",function(){try{onTime(inst.video.currentTime,inst.video.duration||0);}catch(e){}});}'
            .'bind(0);';
    }

    /** Pitch-time decision-support (revealed only at the pitch). Frames the vTurb CTA; never a CTA itself. */
    private function defaultPitchCluster(): string
    {
        return '<div class="vsl-cluster">'
            .'<div class="vsl-verified">✓ 60-day money-back guarantee — if it isn\'t for you, you pay nothing.</div>'
            .'<div class="vsl-cluster-sub">Backed by published research · Made in an FDA-registered facility · Thousands of women have started.</div>'
            .'</div>';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}
html{background:#faf6ef;min-height:100%}
body{margin:0;background:#faf6ef;color:#1a1a1a;font-family:Georgia,'Times New Roman',serif;min-height:100vh}
.mast{border-bottom:2px solid #c0231f;max-width:860px;margin:0 auto;padding:16px 20px 10px;text-align:center;background:#faf6ef}
.mast-name{font-size:23px;font-weight:700;letter-spacing:.4px;color:#141414}
.mast-sec{font-family:system-ui,-apple-system,Arial,sans-serif;font-size:10px;letter-spacing:3px;color:#6b6256;margin-top:3px}
.vsl{max-width:760px;margin:0 auto;padding:22px 20px 40px;width:100%;background:#fff;border-left:1px solid #e7ddcb;border-right:1px solid #e7ddcb;min-height:60vh}
.vsl-kicker{font-family:system-ui,-apple-system,Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#c0231f;text-align:center;margin:2px 0 10px;transition:opacity .22s ease;min-height:1.1em}
.vsl-headline{font-size:30px;line-height:1.18;font-weight:700;text-align:center;letter-spacing:-.3px;color:#141414;margin:0 0 12px}
.vsl-dek{font-size:18px;line-height:1.55;text-align:center;color:#5a5349;margin:0 auto 20px;max-width:600px}
.vsl-player{border-radius:8px;overflow:hidden;box-shadow:0 16px 50px rgba(40,30,20,.26);background:#000;border:1px solid #e2dccf}
.vsl-prog{height:4px;background:#efe7d8;border-radius:3px;overflow:hidden;margin:14px 0 5px}
.vsl-prog-bar{display:block;height:100%;width:0;background:#c0231f;transition:width .8s linear}
.vsl-prog-label{font-family:system-ui,-apple-system,Arial,sans-serif;font-size:10px;letter-spacing:1.2px;text-transform:uppercase;color:#9a9184;text-align:center}
.vsl-sound{text-align:center;font-size:14px;font-weight:700;margin:14px 0 0;color:#8a5a1a;font-family:system-ui,-apple-system,Arial,sans-serif}
.vsl-pitch{margin-top:22px}
.hide{display:none}
.vsl-cluster{background:#f4f9f4;border:1px solid #cfe6d4;border-radius:10px;padding:18px 20px;text-align:center}
.vsl-verified{color:#2f7d4f;font-weight:700;font-size:17px}
.vsl-cluster-sub{font-family:system-ui,-apple-system,Arial,sans-serif;font-size:13px;color:#6b6256;margin-top:8px}
.vsl-foot{max-width:760px;margin:0 auto;padding:20px;color:#9a9388;font-size:11px;line-height:1.5;text-align:center}
@media(max-width:560px){.vsl-headline{font-size:23px}.vsl{padding:18px 14px 30px}.vsl-dek{font-size:16px}}
CSS;
    }
}
