<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\Obra\AtlasBlastRadiusService;
use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * WO-17-T3 — deterministic blast radius + multi-project:
 *   - the radius finds the files that REFERENCE a changed file's symbol (affected call
 *     graph) and splits out the covering tests — over a hermetic temp repo, no fixtures;
 *   - the brief generates under a DISTINCT project scope (multi-project: the retriever
 *     is workspace-scoped; the scope is the repo, not a fixed name).
 */
final class AtlasBlastRadiusTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $repo;

    private string $briefDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->repo = sys_get_temp_dir().'/atlas-radius-'.bin2hex(random_bytes(6));
        @mkdir($this->repo.'/app/Services', 0775, true);
        @mkdir($this->repo.'/tests/Feature', 0775, true);
        file_put_contents($this->repo.'/app/Services/PaymentGateway.php', "<?php\nclass PaymentGateway { public function charge() {} }\n");
        file_put_contents($this->repo.'/app/Services/Checkout.php', "<?php\nclass Checkout { public function __construct(PaymentGateway \$g) {} }\n");
        file_put_contents($this->repo.'/tests/Feature/PaymentGatewayTest.php', "<?php\nclass PaymentGatewayTest { public function test() { new PaymentGateway; } }\n");
        file_put_contents($this->repo.'/app/Services/Unrelated.php', "<?php\nclass Unrelated {}\n");

        $this->briefDir = sys_get_temp_dir().'/atlas-brief3-'.bin2hex(random_bytes(6));
        $this->app->instance(
            AtlasDeterministicBriefService::class,
            new AtlasDeterministicBriefService($this->app->make(AtlasMemoryPrivacyService::class), $this->briefDir),
        );
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        foreach (['app/Services/PaymentGateway.php', 'app/Services/Checkout.php', 'tests/Feature/PaymentGatewayTest.php', 'app/Services/Unrelated.php'] as $f) {
            @unlink($this->repo.'/'.$f);
        }
        @rmdir($this->repo.'/tests/Feature');
        @rmdir($this->repo.'/tests');
        @rmdir($this->repo.'/app/Services');
        @rmdir($this->repo.'/app');
        @rmdir($this->repo);
        foreach (glob($this->briefDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->briefDir);
        parent::tearDown();
    }

    public function test_blast_radius_finds_referencers_and_covering_tests(): void
    {
        $radius = $this->app->make(AtlasBlastRadiusService::class)
            ->radiusFor(['app/Services/PaymentGateway.php'], $this->repo);

        // Checkout references PaymentGateway → affected; the test → covering test.
        $this->assertContains('app/Services/Checkout.php', $radius['affected'], 'quem referencia o símbolo mudado entra no raio');
        $this->assertContains('tests/Feature/PaymentGatewayTest.php', $radius['tests'], 'o teste que cobre entra separado');
        // The changed file and an unrelated file are NOT false-positives.
        $this->assertNotContains('app/Services/PaymentGateway.php', $radius['affected']);
        $this->assertNotContains('app/Services/Unrelated.php', $radius['affected']);
    }

    public function test_brief_generates_under_a_distinct_project_scope(): void
    {
        $brief = $this->app->make(AtlasDeterministicBriefService::class);
        $brief->generate('cliente-repo-x', $this->repo);

        $read = $brief->read('cliente-repo-x');
        $this->assertNotNull($read, 'o brief é escrito sob o scope do projeto (multi-projeto)');
        $this->assertSame('cliente-repo-x', $read['scope']);
        $this->assertNull($brief->read('outro-projeto-inexistente'), 'scopes de projetos diferentes não se misturam');
    }
}
