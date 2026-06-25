<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\MarketingDomain\Content\BridgePageComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end proof that the ConversionCriticGate is LIVE-wired into the bridge composer (not orphan): a
 * full compose() run with a faked provider surfaces the critic verdict on the SHIPPED bridge, and the
 * critic_enabled flag gates it end-to-end (off = the 'off' stub). The re-roll COUNT behaviour is covered
 * at the component level (the loop boolean + correctionNote tests); this test pins the wiring + flag.
 */
class BridgeComposerCriticWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // RefreshDatabase runs the FULL migration suite, which is Postgres-only (the core migration does
        // `CREATE EXTENSION IF NOT EXISTS pgcrypto`) and fails under the default sqlite :memory: test DB.
        // So this end-to-end test runs only when the test DB is Postgres; otherwise it skips cleanly
        // (keeps the suite green). The wiring is also covered at the component level regardless.
        // Checked PRE-boot via getenv (config()/the app are not available before parent::setUp()).
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite')) !== 'pgsql') {
            $this->markTestSkipped('Bridge composer e2e needs a Postgres test DB (migrations use CREATE EXTENSION pgcrypto).');
        }

        parent::setUp();
    }

    private function fakeProviders(string $bridgeJson): AiProviderManager
    {
        $provider = new class($bridgeJson) implements AiProvider
        {
            public function __construct(private string $json) {}

            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return new AiProviderResult(true, $this->json, [], 0, 1, $this->json, '', null, null, ['model' => 'fake']);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                throw new \RuntimeException('not used');
            }
        };

        return new class($provider) extends AiProviderManager
        {
            public function __construct(private AiProvider $p) {}

            public function get(?string $provider = null): AiProvider
            {
                return $this->p;
            }
        };
    }

    private function asset(): AiMarketingVslAsset
    {
        $asset = new AiMarketingVslAsset;
        $asset->forceFill([
            'label' => 'e2e-critic',
            'niche' => 'weight_loss',
            'target_geo' => 'US',
            'language' => 'en',
            'awareness_level' => 'solution_aware',
            'sophistication_level' => 5,
            'transcript' => 'A short transcript about an at-home daily drop ritual for women over forty.',
            'big_idea' => 'a hidden metabolic trigger that stalls after forty',
            'core_promise' => 'support your metabolism at home',
            'mechanism_name' => 'Triple Hormone Drops',
            'keywords' => ['clusters' => [[
                'name' => 'Drops', 'awareness' => 'solution_aware', 'intent' => 'solution',
                'match_type' => 'phrase', 'terms' => ['glp 1 drops', 'weight loss drops'],
            ]]],
            'avatar' => ['quem' => 'women over 40', 'dores' => ['the scale will not move'], 'desejos' => ['lose weight without injections']],
        ])->save();

        return $asset->refresh();
    }

    private function substantialBridgeJson(): string
    {
        $para = 'If you are a woman over forty and the scale will not move no matter how hard you try, this short report explains what researchers now believe is really going on with your metabolism after forty, and why willpower was never the problem. ';

        return (string) json_encode([
            'schema_version' => 'atlas.vsl.bridge.v1',
            'meta' => ['slug' => 'drops-report', 'lang' => 'English (US)'],
            'kicker' => 'SPECIAL HEALTH REPORT',
            'headline' => 'What Women Over 40 Are Learning About Weight Loss Drops',
            'subheadline' => 'A simple at-home ritual that many say changed everything.',
            'hero_cta' => ['label' => 'Watch the free presentation', 'target' => '#vsl'],
            'trust_bar' => ['Over 150,000 readers', '4.8/5 rating'],
            'lead_paragraph' => str_repeat($para, 2),
            'mechanism_tease' => str_repeat($para, 1),
            'body_sections' => array_map(static fn (int $i): array => [
                'heading' => 'Reason '.$i,
                'body' => str_repeat($para, 2),
                'open_loop' => 'The detail is in the presentation above.',
            ], [1, 2, 3, 4, 5]),
            'cta_blocks' => [['label' => 'Watch now', 'target' => '#vsl'], ['label' => 'See the video', 'target' => '#vsl']],
            'disclosure' => 'Advertorial. Results vary.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function test_critic_verdict_is_surfaced_on_the_shipped_bridge(): void
    {
        $svc = new BridgePageComposerService($this->fakeProviders($this->substantialBridgeJson()));

        $result = $svc->compose($this->asset(), ['render' => false, 'learn' => false, 'max_attempts' => 1]);

        $this->assertArrayHasKey('conversion_critic', $result['validation']);
        $critic = $result['validation']['conversion_critic'];
        $this->assertContains($critic['verdict'], ['block', 'warn', 'ok']);
        $this->assertIsBool($critic['structural_pass']);
        $this->assertIsArray($critic['reasons']);
        $this->assertNotSame('off', $critic['threshold']); // critic ran for real (not the disabled stub)
    }

    public function test_critic_flag_off_is_the_disabled_stub_end_to_end(): void
    {
        $svc = new BridgePageComposerService($this->fakeProviders($this->substantialBridgeJson()));

        $result = $svc->compose($this->asset(), ['render' => false, 'learn' => false, 'max_attempts' => 1, 'critic_enabled' => false]);

        $critic = $result['validation']['conversion_critic'];
        $this->assertSame('off', $critic['threshold']);
        $this->assertSame('ok', $critic['verdict']);
        $this->assertTrue($critic['structural_pass']);
    }
}
