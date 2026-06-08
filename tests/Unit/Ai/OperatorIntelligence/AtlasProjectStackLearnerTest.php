<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\AtlasProjectStackLearner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Project learning must be deterministic + grounded: it reports ONLY what the real files
 * say, never an invented stack or purpose. Cite-or-omit.
 */
class AtlasProjectStackLearnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-proj-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/composer.json', (string) json_encode([
            'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
            'require-dev' => ['phpunit/phpunit' => '^12.0'],
        ]));
        File::put($this->dir.'/package.json', (string) json_encode([
            'dependencies' => ['react' => '^19.0', 'tailwindcss' => '^4.0'],
        ]));
        File::put($this->dir.'/README.md', "# My Project\n\n![badge](x)\n\nEsta aplicacao gerencia pedidos de ecommerce e integra com gateways de pagamento para o operador.\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_learns_the_real_stack_and_purpose_grounded(): void
    {
        $k = (new AtlasProjectStackLearner())->learn($this->dir);

        $this->assertContains('Laravel ^13.0', $k['stack']);
        $this->assertContains('PHP ^8.4', $k['stack']);
        $this->assertContains('React ^19.0', $k['stack']);
        $this->assertContains('Tailwind CSS ^4.0', $k['stack']);
        $this->assertStringContainsString('ecommerce', $k['summary']);
        $this->assertTrue($k['grounded']);

        // Every single fact must cite a real source file — none invented.
        foreach ($k['facts'] as $fact) {
            $this->assertNotSame('', (string) $fact['source']);
            $this->assertTrue(File::exists($this->dir.'/'.$fact['source']) || $fact['source'] === '.env');
        }
    }

    public function test_does_not_invent_a_stack_that_is_not_in_the_manifests(): void
    {
        $k = (new AtlasProjectStackLearner())->learn($this->dir);

        // Symfony/Vue/Next are in the signature table but NOT in these manifests → must be absent.
        $joined = implode('|', $k['stack']);
        $this->assertStringNotContainsString('Symfony', $joined);
        $this->assertStringNotContainsString('Vue', $joined);
        $this->assertStringNotContainsString('Next.js', $joined);
    }

    public function test_empty_project_yields_no_invented_facts(): void
    {
        $empty = sys_get_temp_dir().'/atlas-empty-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($empty);
        try {
            $k = (new AtlasProjectStackLearner())->learn($empty);
            $this->assertSame([], $k['stack']);
            $this->assertSame('', $k['summary']);
            $this->assertSame(0, $k['fact_count']);
        } finally {
            File::deleteDirectory($empty);
        }
    }
}
