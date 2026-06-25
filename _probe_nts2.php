<?php

require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\MarketingDomain\Content\NarrativeTensionScorer;

$s = new NarrativeTensionScorer;

function show(string $label, array $r): void
{
    printf(
        "%-44s score=%-3d grade=%-9s open=%-2d rev=%-2d fwd=%-2d loop=%-2d dens=%.2f sent=%-2d\n",
        $label, $r['score'], $r['grade'], $r['open_strength'], $r['reveal_deferral'],
        $r['forward_pull'], $r['loop_discipline'], $r['beat_density'], $r['sentences']
    );
}

echo "===== The killer: pure Lorem Ipsum with markers in the right slots =====\n";
// Absolute worst case: NO real content at all, just latin filler + perfectly placed beats.
$lorem = "Imagine the possibilities. Keep reading. "
    ."Lorem ipsum dolor sit amet consectetur adipiscing elit. "
    ."Sed do eiusmod tempor incididunt ut labore et dolore magna. "
    ."Ut enim ad minim veniam quis nostrud exercitation ullamco. "
    ."But first, duis aute irure dolor in reprehenderit voluptate. "
    ."Excepteur sint occaecat cupidatat non proident sunt culpa. "
    ."It gets better, nemo enim ipsam voluptatem quia voluptas. "
    ."Neque porro quisquam est qui dolorem ipsum quia dolor. "
    ."You will see why, ut aliquam quaerat voluptatem aspernatur. "
    ."Quis autem vel eum iure reprehenderit qui in ea voluptate. "
    ."Here is how it works: sed ut perspiciatis unde omnis iste. "
    ."The secret is totam rem aperiam eaque ipsa quae ab illo. "
    ."Order now to claim your spot today.";
show('PURE LOREM IPSUM + correct beats', $s->score($lorem));

echo "\n===== Does '?' alone (no real curiosity) earn full open_strength? =====\n";
$qmark = "Tax season? "
    ."We do bookkeeping for small businesses in your area now. "
    ."Our team has twelve years of experience with returns. "
    ."But first, we offer a free consultation to all clients. "
    ."We file electronically and handle all the paperwork too. "
    ."It gets better because we also do payroll services. "
    ."You will see why local owners trust us with their finances. "
    ."Here is how our onboarding process works step by step. "
    ."The answer is professional service at a fair price point. "
    ."Call now to schedule your appointment with our office.";
show('boring B2B copy, opens with one "?"', $s->score($qmark));

echo "\n===== Real elite VSL open with NO dictionary phrase (Carlton/Halbert style) =====\n";
$halbert = "If you have ever felt your heart skip a beat for no reason, read every word on this page. "
    ."Because what a Tokyo researcher found buried in a 1974 study just cost the supplement industry billions. "
    ."My name does not matter. What matters is the photo at the bottom of this letter. "
    ."It is my father, six weeks before the widowmaker took him at 51. "
    ."And it is me, at 58, with a calcium score a cardiologist called impossible. "
    ."The difference between those two photos is one nightly habit that costs about eleven cents. "
    ."It has nothing to do with statins, fish oil, or anything your doctor has mentioned. "
    ."In fact, three of the things your doctor recommends quietly make it worse. "
    ."Give me four minutes and I will show you exactly what it is.";
show('elite cold-open (Halbert-style) no-markers', $s->score($halbert));

echo "\n===== Sanity: real leaked-reveal junk that SHOULD score low (and does) =====\n";
$junk = "The secret is MetaboFix. Here is how it works: take it daily. Buy now. "
    ."It is called the fat burner. The mechanism is simple. Order now. Click below.";
show('genuinely bad leaked junk', $s->score($junk));
