<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * VslPlayerPageRenderer — renders the ELITE VSL page: a distraction-elimination machine built around a
 * vTurb (converteai) smartplayer embed. Its only job is to carry the maximum number of qualified,
 * warmed viewers to the pitch moment with peak retention and dopamine. Doctrine: attention ratio 1:1
 * (one page, one goal = WATCH, zero exit paths). The CTA is NOT here — vTurb reveals it at the exact
 * pitch second. The page uses vTurb's native APIs: `displayHiddenElements(sec,[".hide"],{persist:true})`
 * for pitch-time reveals and `smartplayer.instances[0].on('timeupdate')` to sync a rotating retention
 * headline to the REAL playback position (survives pauses). Provider-free templating.
 */
class VslPlayerPageRenderer
{
    /**
     * @param  array{player_id:string,script_src:string}  $embed  the vTurb embed (custom element id + loader script)
     * @param  array<int,array{t:int,text:string}>  $headlineSchedule  rotating headlines keyed by REAL video second
     * @param  array<string,mixed>  $opts  pitch_seconds, brand, disclaimer, live (bool|array), pitch_html
     */
    public function render(array $embed, array $headlineSchedule, array $opts = []): string
    {
        $playerId = $this->esc((string) ($embed['player_id'] ?? ''));
        $scriptSrc = $this->esc((string) ($embed['script_src'] ?? ''));
        $pitch = (int) ($opts['pitch_seconds'] ?? 3960);
        $brand = $this->esc((string) ($opts['brand'] ?? ''));
        $disclaimer = $this->esc((string) ($opts['disclaimer'] ??
            'Individual results vary. This presentation is for educational purposes. '
            .'Statements have not been evaluated by the FDA. Not intended to diagnose, treat, cure, or prevent any disease.'));

        // Normalize the headline schedule (sorted by time), default to a single static line if empty.
        $schedule = array_values(array_filter($headlineSchedule, fn ($h) => isset($h['t'], $h['text'])));
        usort($schedule, fn ($a, $b) => $a['t'] <=> $b['t']);
        if ($schedule === []) {
            $schedule = [['t' => 0, 'text' => 'Your free presentation is starting now.']];
        }
        $scheduleJson = json_encode(array_map(fn ($h) => ['t' => (int) $h['t'], 'x' => (string) $h['text']], $schedule), JSON_UNESCAPED_UNICODE);
        $firstHeadline = $this->esc((string) $schedule[0]['text']);

        $live = $opts['live'] ?? true;
        $liveLabel = is_array($live) ? (string) ($live['label'] ?? 'Live presentation in progress') : 'Live presentation in progress';
        $liveCount = is_array($live) ? (int) ($live['count'] ?? 0) : 0; // 0 = no fabricated number (integrity-safe default)

        // Pitch-time container: hidden until the pitch second, revealed by vTurb. Empty by default —
        // the operator's offer/CTA lives inside the VSL + vTurb. Only fill if explicitly provided.
        $pitchHtml = (string) ($opts['pitch_html'] ?? '');

        $h = [];
        $h[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $h[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $h[] = '<meta name="robots" content="noindex,nofollow">';
        $h[] = '<title>'.($brand !== '' ? $brand.' — ' : '').'Free Presentation</title>';
        $h[] = '<style>'.$this->css().'</style></head><body>';

        $h[] = '<main class="vsl">';

        // Live indicator (dopamine / social proof) — non-clickable, minimal, never an exit path.
        if ($live !== false) {
            $count = $liveCount > 0
                ? ' · <span id="vsl-live-n">'.number_format($liveCount).'</span> watching now'
                : '';
            $h[] = '<div class="vsl-live"><span class="vsl-dot"></span> '.$this->esc($liveLabel).$count.'</div>';
        }

        // Rotating retention headline — the single framing element above the video.
        $h[] = '<h1 id="vsl-headline" class="vsl-headline">'.$firstHeadline.'</h1>';

        // The player — the ONLY interactive element on the page (attention ratio 1:1).
        $h[] = '<div class="vsl-player">';
        $h[] = '<vturb-smartplayer id="'.$playerId.'" style="display:block;margin:0 auto;width:100%"></vturb-smartplayer>';
        $h[] = '</div>';

        // Sound nudge (vTurb Smart Autoplay handles unmute; this reinforces — pure text, non-clickable).
        $h[] = '<p class="vsl-sound">🔊 Turn your sound on — the presentation has already begun.</p>';

        // Pitch-time reveal container (hidden until the pitch via vTurb displayHiddenElements).
        $h[] = '<div class="vsl-pitch hide">'.$pitchHtml.'</div>';

        $h[] = '</main>';

        // Footer: legal disclaimer ONLY, as plain non-clickable text (no links = no exit path).
        $h[] = '<footer class="vsl-foot">'.($brand !== '' ? $brand.' · ' : '').$disclaimer.'</footer>';

        // vTurb loader (exactly the operator's embed loader).
        $h[] = '<script type="text/javascript">var s=document.createElement("script");s.src="'.$scriptSrc.'",s.async=!0,document.head.appendChild(s);</script>';

        // Page logic: pitch reveal (native vTurb) + headline sync (timeupdate) + optional live count drift.
        $h[] = '<script>'.$this->js($pitch, $scheduleJson, $liveCount).'</script>';

        $h[] = '</body></html>';

        return implode("\n", $h);
    }

    private function js(int $pitch, string $scheduleJson, int $liveCount): string
    {
        $js = '';

        // 1) Native vTurb pitch reveal — caching/persist so returning viewers skip the wait.
        $js .= 'var vp=document.querySelector("vturb-smartplayer");'
            .'if(vp){vp.addEventListener("player:ready",function(){try{vp.displayHiddenElements('.$pitch.',[".hide"],{persist:true});}catch(e){}});}';

        // 2) Rotating headline synced to REAL playback time via the smartplayer instance.
        $js .= 'var SCH='.$scheduleJson.';var hEl=document.getElementById("vsl-headline");var lastH="";'
            .'function pickH(ct){var t=SCH[0].x;for(var i=0;i<SCH.length;i++){if(ct>=SCH[i].t)t=SCH[i].x;}return t;}'
            .'function setH(txt){if(txt===lastH)return;lastH=txt;hEl.style.opacity="0";setTimeout(function(){hEl.textContent=txt;hEl.style.opacity="1";},250);}'
            .'function bindH(tries){if(typeof smartplayer==="undefined"||!(smartplayer.instances&&smartplayer.instances.length)){if(tries<60){setTimeout(function(){bindH(tries+1);},500);}return;}'
            .'var inst=smartplayer.instances[0];inst.on("timeupdate",function(){try{setH(pickH(inst.video.currentTime));}catch(e){}});}'
            .'bindH(0);';

        // 3) Optional live-count gentle drift (only if an explicit count was set; off by default).
        if ($liveCount > 0) {
            $js .= 'var lc='.$liveCount.';var lcEl=document.getElementById("vsl-live-n");'
                .'setInterval(function(){if(!lcEl)return;lc+=(Math.random()<0.5?-1:1)*(1+Math.floor(Math.random()*3));if(lc<'.max(1, (int) ($liveCount * 0.9)).')lc='.$liveCount.';lcEl.textContent=lc.toLocaleString();},4200);';
        }

        return $js;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}
html,body{margin:0;background:#0b0e12;color:#f2f4f7}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;min-height:100vh;display:flex;flex-direction:column}
.vsl{max-width:820px;margin:0 auto;padding:22px 18px 30px;width:100%;flex:1}
.vsl-live{display:flex;align-items:center;justify-content:center;gap:8px;font-size:13px;font-weight:600;letter-spacing:.3px;color:#ff5a5a;margin:4px 0 14px;text-transform:uppercase}
.vsl-dot{width:9px;height:9px;border-radius:50%;background:#ff3b3b;box-shadow:0 0 0 0 rgba(255,59,59,.7);animation:pulse 1.6s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(255,59,59,.6)}70%{box-shadow:0 0 0 12px rgba(255,59,59,0)}100%{box-shadow:0 0 0 0 rgba(255,59,59,0)}}
.vsl-headline{font-size:30px;line-height:1.18;font-weight:800;text-align:center;letter-spacing:-.3px;margin:0 0 18px;transition:opacity .25s ease;min-height:1.2em}
.vsl-player{border-radius:12px;overflow:hidden;box-shadow:0 18px 60px rgba(0,0,0,.55);background:#000}
.vsl-sound{text-align:center;color:#aab2bd;font-size:14px;font-weight:600;margin:14px 0 0}
.vsl-pitch{margin-top:22px}
.hide{display:none}
.vsl-foot{max-width:820px;margin:0 auto;padding:18px;color:#6b7280;font-size:11px;line-height:1.5;text-align:center}
@media(max-width:560px){.vsl-headline{font-size:23px}.vsl{padding:16px 14px 24px}}
CSS;
    }
}
