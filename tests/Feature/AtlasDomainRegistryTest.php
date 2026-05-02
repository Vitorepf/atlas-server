<?php

namespace Tests\Feature;

use App\Services\AtlasDomainRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDomainRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_domains');
        Schema::create('atlas_domains', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('color_light')->default('#1B3A57');
            $table->string('color_dark')->default('#6892B5');
            $table->string('default_sensitivity')->default('normal');
            $table->string('external_ai_policy')->default('allow');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }

    public function test_registry_repairs_stale_domain_table_with_atlas_default(): void
    {
        $this->seedLegacyDomainsWithoutAtlas();

        $slugs = app(AtlasDomainRegistry::class)->activeSlugs();

        $this->assertContains('atlas', $slugs);
        $this->assertSame('private', DB::table('atlas_domains')->where('slug', 'atlas')->value('default_sensitivity'));
        $this->assertSame('block_private_sensitive', DB::table('atlas_domains')->where('slug', 'atlas')->value('external_ai_policy'));
        $this->assertTrue((bool) DB::table('atlas_domains')->where('slug', 'atlas')->value('active'));
    }

    public function test_domains_endpoint_exposes_repaired_atlas_domain(): void
    {
        $this->seedLegacyDomainsWithoutAtlas();

        $response = $this->getJson('/domains', $this->headers())
            ->assertOk();

        $slugs = collect($response->json('domains'))->pluck('slug')->all();

        $this->assertContains('atlas', $slugs);
        $this->assertContains('blackink', $slugs);
        $this->assertContains('outro', $slugs);
    }

    public function test_creating_atlas_slug_on_stale_table_is_rejected_after_repair(): void
    {
        $this->seedLegacyDomainsWithoutAtlas();

        $this->postJson('/domains', [
            'slug' => 'Atlas',
            'label' => 'Atlas',
            'default_sensitivity' => 'normal',
            'external_ai_policy' => 'allow',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);

        $this->assertSame(1, DB::table('atlas_domains')->where('slug', 'atlas')->count());
        $this->assertSame('private', DB::table('atlas_domains')->where('slug', 'atlas')->value('default_sensitivity'));
        $this->assertSame('block_private_sensitive', DB::table('atlas_domains')->where('slug', 'atlas')->value('external_ai_policy'));
    }

    public function test_canonical_domains_cannot_be_deactivated(): void
    {
        app(AtlasDomainRegistry::class)->syncConfiguredDefaults();

        $this->deleteJson('/domains/atlas', [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'canonical_domain_locked');

        $this->assertTrue((bool) DB::table('atlas_domains')->where('slug', 'atlas')->value('active'));

        $this->patchJson('/domains/atlas', ['active' => false], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['active']);

        $this->assertTrue((bool) DB::table('atlas_domains')->where('slug', 'atlas')->value('active'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];
    }

    private function seedLegacyDomainsWithoutAtlas(): void
    {
        foreach (['blackink', 'saude', 'financas', 'outro'] as $index => $slug) {
            DB::table('atlas_domains')->insert([
                'slug' => $slug,
                'label' => ucfirst($slug),
                'color_light' => '#1B3A57',
                'color_dark' => '#6892B5',
                'default_sensitivity' => 'normal',
                'external_ai_policy' => 'allow',
                'active' => true,
                'sort_order' => ($index + 1) * 10,
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
