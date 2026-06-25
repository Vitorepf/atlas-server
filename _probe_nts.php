<?php

require __DIR__.'/vendor/autoload.php';

use App\Services\Ai\MarketingDomain\Content\NarrativeTensionScorer;

$s = new NarrativeTensionScorer;

function show(string $label, array $r): void
{
    printf(
        "%-40s score=%-3d grade=%-9s open=%-2d rev=%-2d fwd=%-2d loop=%-2d dens=%.2f sent=%-2d weak=%s\n",
        $label, $r['score'], $r['grade'], $r['open_strength'], $r['reveal_deferral'],
        $r['forward_pull'], $r['loop_discipline'], $r['beat_density'], $r['sentences'], $r['weakest_beat']
    );
}

echo "===== BASELINE (test fixtures) =====\n";
$grippingHealth = 'Have you ever wondered why nothing works after 40? Stick with me, '
    .'because the answer surprised even my doctor. I spent three years gaining weight no matter what I '
    .'ate. Every diet failed by week two. My doctor ran a panel and found something most women are '
    .'never told. But first, you need to understand what actually happens to your metabolism past 40. '
    .'Three hormones that regulate fat storage quietly fall out of rhythm. It is not willpower. It gets '
    .'worse: the standard advice makes it harder. After months of testing, I found a pattern in the '
    .'women who recovered. Here\'s how a two-minute morning ritual nudges those hormones back into '
    .'rhythm. That is the mechanism nobody explains. Click below to watch the full presentation.';
show('baseline grippingHealth', $s->score($grippingHealth));

echo "\n===== FALSE POSITIVE candidates (scorer loves, human closes fast) =====\n";

// FP1: hollow skeleton — correct beat positions, zero substance between them
$fp1 = "What if everything you know is wrong? Stick with me. "
    ."This is a thing that happened. It was a situation. Some stuff occurred. "
    ."There was a development. Things continued to happen over time. "
    ."But first, you should know there is context here. Context matters a lot. "
    ."It gets better as we go along here. More things happened next. "
    ."You will see why this is relevant eventually somehow. Time passed. "
    ."Here is how it all works out in the end for you. "
    ."The mechanism is the thing that does it. That is the secret. "
    ."Click below to get started now.";
show('FP1 hollow skeleton', $s->score($fp1));

// FP2: generic motivational fluff, correctly placed markers, no real curiosity gap
$fp2 = "Imagine a better life for yourself today. Keep reading to learn more. "
    ."Life can be hard sometimes for many people everywhere. "
    ."We all want to feel good and be happy in our days. "
    ."But first, let us think about what matters most to us. "
    ."It gets better when you believe in yourself fully. "
    ."You will see why positivity changes everything around you. "
    ."Here is how you can start your new journey today. "
    ."The secret is simply to begin with one small step. "
    ."Order now to join our community of happy people.";
show('FP2 motivational fluff', $s->score($fp2));

// FP3: padded so reveal lands late, but body is vague benefit listicle
$fp3 = "Have you ever wondered what is holding you back? Stay with me. "
    ."My name is John and I have a story to tell you today. "
    ."I was once just like you in every single possible way. "
    ."Then one day everything changed for me completely. "
    ."But first let me share some background information here. "
    ."There are many factors involved in this whole process. "
    ."It gets better the more you understand the full picture. "
    ."People often ask me about how this all came together. "
    ."You will see why in just a moment if you keep going. "
    ."Here is how the entire system finally works for anyone. "
    ."That is the real reason it succeeds where others fail. "
    ."Click here to begin your transformation right now today.";
show('FP3 padded vague listicle', $s->score($fp3));

// FP4: pad the opening with neutral non-beat filler to push reveal past 55%, then leak it.
$fp4 = "Here is how to lose weight: eat less. The secret is calories. "
    ."Lorem ipsum dolor sit amet consectetur adipiscing elit sed. "
    ."Duis aute irure dolor in reprehenderit in voluptate velit esse. "
    ."Excepteur sint occaecat cupidatat non proident sunt in culpa. "
    ."Ut enim ad minim veniam quis nostrud exercitation ullamco. "
    ."Sed do eiusmod tempor incididunt ut labore et dolore magna. "
    ."Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut. "
    ."What if I told you there is more? Stick with me here please. "
    ."The mechanism is metabolism and here is how it actually works. "
    ."Buy now to claim your discount today.";
show('FP4 leak-then-pad-to-fake-late', $s->score($fp4));

echo "\n===== FALSE NEGATIVE candidates (scorer hates, human keeps reading) =====\n";

// FN1: genuinely gripping concrete mystery, ZERO dictionary marker phrases
$fn1 = "The paramedic looked at my chart twice before he said anything. "
    ."My resting heart rate was 38. For a 34-year-old who never exercised, that should be impossible. "
    ."Three weeks earlier I could not climb stairs without stopping. "
    ."My cardiologist had no explanation, so he sent the bloodwork to a lab in Zurich. "
    ."What came back changed how I think about the human body. "
    ."The lab found a marker that only shows up in elite endurance athletes and, oddly, in people who eat one specific way. "
    ."I had stumbled onto it by accident six weeks before, after my wife put me on her grandmother's village diet. "
    ."Nobody in that village has ever had a heart attack. Not one, in recorded history. "
    ."I spent the next year proving why, and what I found is on this page.";
show('FN1 concrete mystery no-markers', $s->score($fn1));

// FN2: visceral specific story, reveal genuinely withheld, no marker phrases
$fn2 = "At 2am the casino floor manager comped my room and quietly asked me to leave. "
    ."I had turned 400 dollars into 19,000 in four hours playing a game everyone says is unbeatable. "
    ."I was not counting cards. I was not cheating. I was using something far more boring. "
    ."A spreadsheet I built after my statistics professor said one sentence that I could not forget. "
    ."He said the house edge is a lie of averages, and averages hide a door. "
    ."It took me eight months to find that door. "
    ."When I did, I stopped gambling entirely and started doing only this. "
    ."It made me more last year than my engineering salary, working nine minutes a day. "
    ."I am going to show you the door he was talking about.";
show('FN2 visceral withheld no-markers', $s->score($fn2));

// FN3: punchy short-sentence hook, dense tension, none of the dictionary phrases
$fn3 = "She was dead for four minutes. The ER doctor signed the time. "
    ."Then her hand moved. "
    ."What she described from those four minutes is now being studied at two universities. "
    ."Not a tunnel. Not a light. Something far stranger, and far more useful to the living. "
    ."I interviewed her for nine hours. "
    ."By the end I had quit my job at the newspaper. "
    ."Because if what she experienced is real, almost everything we are taught about fear is backwards. "
    ."And there is a way to use it, starting tonight, before you sleep.";
show('FN3 punchy no-dictionary hook', $s->score($fn3));

echo "\n===== GAMING vectors =====\n";

// G1: take a WEAK flat copy and bolt the dictionary phrases on in the right slots
$g1weakbase = "Our program helps you get your ex back. It is good. People like it. "
    ."It has many techniques. You will learn communication. It is comprehensive. "
    ."Many people have tried it. It is available now.";
show('G1a weak base (before)', $s->score($g1weakbase));

$g1gamed = "What if you could get your ex back? Stick with me. "
    ."Our program helps you get your ex back. It is good. People like it. "
    ."But first, it has many techniques. It gets better. "
    ."You will learn communication. You will see why it is comprehensive. "
    ."Many people have tried it. Here is how the answer is simple. "
    ."Order now, it is available now.";
show('G1b same copy + bolted markers', $s->score($g1gamed));

// G2: identical-content question-mark spam to lift open_strength + density games
$g2 = "Want results? Ready? Curious? Sure? Right? "
    ."My program teaches dating. It has modules. There is a workbook. "
    ."But first? More on that? "
    ."The secret is practice. Here is how: do the modules. "
    ."Order now.";
show('G2 question-mark + filler', $s->score($g2));

echo "\nDONE\n";
