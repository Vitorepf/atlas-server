<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\VslPageGuard;
use App\Services\Ai\MarketingDomain\Content\VslPlayerPageRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the elite-VSL-page doctrine: attention ratio 1:1 (one page, one goal = WATCH, zero exit paths),
 * with the CTA owned by the player (revealed at the pitch), not the page. Everything that competes with
 * the video is a crime that lowers watch-through to the pitch.
 */
class VslPageGuardTest extends TestCase
{
    private function renderedPage(): string
    {
        return (new VslPlayerPageRenderer)->render(
            ['player_id' => 'vid-x', 'script_src' => 'https://scripts.example/player.js'],
            [['t' => 0, 'text' => 'Your free presentation is starting now.'],
                ['t' => 90, 'text' => 'In a few minutes she explains the real reason.']],
            ['pitch_seconds' => 3960, 'brand' => 'The Daily Wellness Report'],
        );
    }

    public function test_a_rendered_vsl_page_is_clean_with_1to1_attention(): void
    {
        $g = (new VslPageGuard)->inspect($this->renderedPage());
        $this->assertSame('clean', $g['verdict']);
        $this->assertSame('1:1', $g['attention_ratio']);
        $this->assertSame(0, $g['n']);
    }

    public function test_page_cta_is_a_crime(): void
    {
        $html = '<vturb-smartplayer id="x"></vturb-smartplayer><a href="#" class="cta">Buy Now</a>';
        $g = (new VslPageGuard)->inspect($html);
        $this->assertContains('own_cta', array_column($g['crimes'], 'key'));
        $this->assertSame('distracting', $g['verdict']);
    }

    public function test_exit_links_are_a_crime(): void
    {
        $html = '<vturb-smartplayer id="x"></vturb-smartplayer><a href="https://other.com/shop">Our store</a>';
        $g = (new VslPageGuard)->inspect($html);
        $this->assertContains('exit_link', array_column($g['crimes'], 'key'));
    }

    public function test_navigation_is_a_crime(): void
    {
        $html = '<nav><a href="#a">A</a></nav><vturb-smartplayer id="x"></vturb-smartplayer>';
        $this->assertContains('nav_menu', array_column((new VslPageGuard)->inspect($html)['crimes'], 'key'));
    }

    public function test_a_second_video_is_a_crime(): void
    {
        $html = '<vturb-smartplayer id="x"></vturb-smartplayer><iframe src="https://yt/embed"></iframe>';
        $this->assertContains('multiple_players', array_column((new VslPageGuard)->inspect($html)['crimes'], 'key'));
    }

    public function test_visible_sales_copy_before_pitch_is_a_crime(): void
    {
        $copy = str_repeat('This product re-syncs three hormones and changes everything for women over forty. ', 30);
        $html = '<vturb-smartplayer id="x"></vturb-smartplayer><section>'.$copy.'</section>';
        $this->assertContains('sales_copy_before_pitch', array_column((new VslPageGuard)->inspect($html)['crimes'], 'key'));
    }

    public function test_content_hidden_until_pitch_is_allowed(): void
    {
        // Same heavy copy, but inside the .hide pitch container → revealed only at the pitch → not a crime.
        $copy = str_repeat('Revealed only when the pitch lands so it never competes with the video. ', 30);
        $html = '<vturb-smartplayer id="x"></vturb-smartplayer><div class="vsl-pitch hide">'.$copy.'</div>';
        $g = (new VslPageGuard)->inspect($html);
        $this->assertNotContains('sales_copy_before_pitch', array_column($g['crimes'], 'key'));
    }

    public function test_no_player_is_flagged(): void
    {
        $this->assertSame('no_player', (new VslPageGuard)->inspect('<h1>nothing here</h1>')['verdict']);
    }
}
