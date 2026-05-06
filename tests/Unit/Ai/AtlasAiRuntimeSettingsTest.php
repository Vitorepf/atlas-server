<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasAiRuntimeSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiRuntimeSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ai_runtime_settings');
        Schema::create('atlas_ai_runtime_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value_json');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ai_runtime_settings');

        parent::tearDown();
    }

    public function test_default_provider_update_enables_that_provider_for_auto_routing(): void
    {
        config([
            'atlas.ai.providers.codex_cli.allow_auto' => false,
        ]);

        $settings = app(AtlasAiRuntimeSettings::class);
        $effective = $settings->update([
            'default_provider' => 'codex_cli',
        ], 'test');

        $this->assertSame('codex_cli', $effective['default_provider']);
        $this->assertTrue((bool) data_get($effective, 'providers.codex_cli.allow_auto'));
        $this->assertTrue((bool) $settings->providerConfig('codex_cli')['allow_auto']);
    }

    public function test_default_provider_cannot_be_disabled_for_auto_routing_in_same_patch(): void
    {
        $settings = app(AtlasAiRuntimeSettings::class);

        $effective = $settings->update([
            'default_provider' => 'gemini_cli',
            'providers' => [
                'gemini_cli' => ['allow_auto' => false],
            ],
        ], 'test');

        $this->assertSame('gemini_cli', $effective['default_provider']);
        $this->assertTrue((bool) data_get($effective, 'providers.gemini_cli.allow_auto'));
    }

    public function test_current_default_provider_cannot_be_disabled_for_auto_routing_later(): void
    {
        $settings = app(AtlasAiRuntimeSettings::class);
        $settings->update([
            'default_provider' => 'codex_cli',
        ], 'test');

        $effective = $settings->update([
            'providers' => [
                'codex_cli' => ['allow_auto' => false],
            ],
        ], 'test');

        $this->assertSame('codex_cli', $effective['default_provider']);
        $this->assertTrue((bool) data_get($effective, 'providers.codex_cli.allow_auto'));
    }

    public function test_budget_window_hours_uses_explicit_policy_contract(): void
    {
        $settings = app(AtlasAiRuntimeSettings::class);

        $tooLarge = $settings->update([
            'budget' => ['window_hours' => 999],
        ], 'test');

        $this->assertSame(
            AtlasAiRuntimeSettings::MAX_BUDGET_WINDOW_HOURS,
            data_get($tooLarge, 'budget.window_hours'),
        );
        $this->assertSame(1, $settings->normalizeBudgetWindowHours(-5));
        $this->assertSame(
            AtlasAiRuntimeSettings::DEFAULT_BUDGET_WINDOW_HOURS,
            $settings->normalizeBudgetWindowHours('bad'),
        );
    }
}
