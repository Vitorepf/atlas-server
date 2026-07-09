<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * O2 · contrato do catálogo app-ready: o describe é a porta de descoberta da superfície
 * de produto do terminal para apps irmãos — o teste congela o SHAPE do catálogo (áreas,
 * campos, invocações JSON) e garante que o --check devolve resultados estruturados.
 * A conformidade 8/8 dos comandos em si é medida NO VIVO via `atlas:api:describe --check`.
 */
class AtlasApiDescribeContractTest extends TestCase
{
    private const REQUIRED_AREAS = [
        'cockpit', 'inbox', 'review_publish', 'review_deep', 'brain', 'task_health',
        'autonomy', 'obra', 'memory', 'context', 'dashboard', 'chat',
    ];

    public function test_catalog_covers_every_product_area_with_contract_fields(): void
    {
        $payload = $this->describe([]);

        $this->assertSame('atlas.api.catalog.v1', $payload['schema_version']);
        $this->assertSame(count(self::REQUIRED_AREAS), $payload['areas_count']);

        $byArea = collect($payload['catalog'])->keyBy('area');
        foreach (self::REQUIRED_AREAS as $area) {
            $this->assertTrue($byArea->has($area), "área ausente do catálogo: {$area}");
            $entry = $byArea->get($area);
            foreach (['command', 'json_invocation', 'version_key', 'known_schema', 'runnable', 'conformance'] as $field) {
                $this->assertArrayHasKey($field, $entry, "campo {$field} ausente em {$area}");
            }
            $this->assertContains($entry['conformance'], ['versioned', 'json_unversioned', 'interactive_only']);
        }

        // chat é a única exceção interactive-only declarada; todo o resto tem invocação JSON.
        $this->assertSame('interactive_only', $byArea->get('chat')['conformance']);
        $this->assertCount(1, collect($payload['catalog'])->where('conformance', 'interactive_only'));
    }

    public function test_check_mode_returns_structured_results_for_every_runnable_entry(): void
    {
        $payload = $this->describe(['--check' => true]);

        $check = $payload['check'];
        $runnable = collect($payload['catalog'])->where('runnable', true)->count();
        $this->assertSame($runnable, $check['ran'], 'check deve rodar exatamente as entries runnable');
        foreach ($check['results'] as $result) {
            $this->assertArrayHasKey('area', $result);
            $this->assertArrayHasKey('ok', $result);
            if ($result['ok'] !== true) {
                $this->assertArrayHasKey('reason', $result, 'falha sem reason em '.$result['area']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function describe(array $params): array
    {
        $buffer = new BufferedOutput;
        Artisan::call('atlas:api:describe', $params, $buffer);
        $decoded = json_decode(trim($buffer->fetch()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
