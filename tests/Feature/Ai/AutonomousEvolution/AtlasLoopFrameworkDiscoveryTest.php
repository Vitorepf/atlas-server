<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use Tests\TestCase;

/**
 * L2-2 (Lista 2 — breadth): com a flag ON, a descoberta ADMITE targets framework-reach
 * (serviços REAIS do Atlas) — penalizados no score mas elegíveis, sinalizados para o
 * refiller rotear ao caminho framework (worktree + intent-verifier + certificação
 * adversarial). Com a flag OFF, o comportamento de fábrica (self-contained-only) intacto.
 */
final class AtlasLoopFrameworkDiscoveryTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function phpFile(string $body): string
    {
        $f = sys_get_temp_dir().'/atlas-fwk-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($f, $body);
        $this->tmp[] = $f;

        return $f;
    }

    private function frameworkBody(): string
    {
        $methods = '';
        for ($i = 0; $i < 12; $i++) {
            $methods .= "    public function m{$i}(int \$x): int { if (\$x > {$i}) { return \$x + {$i}; } return \$x - {$i}; }\n    // TODO: edge case\n\n";
        }

        return "<?php\nnamespace App\\Services\\Demo;\nuse Illuminate\\Support\\Str;\nfinal class RealService\n{\n{$methods}}\n";
    }

    public function test_flag_off_keeps_factory_behavior_framework_rejected(): void
    {
        config(['atlas.loop.discovery_framework_targets' => false]);
        $f = $this->phpFile($this->frameworkBody());

        $scored = app(AtlasLoopTargetDiscoveryService::class)->scoreCandidate($f, 'app/Services/Demo/RealService.php');

        $this->assertNull($scored, 'de fábrica, framework-reach continua inadmissível');
    }

    public function test_flag_on_admits_framework_target_penalized_and_signposted(): void
    {
        config(['atlas.loop.discovery_framework_targets' => true]);
        $f = $this->phpFile($this->frameworkBody());

        $scored = app(AtlasLoopTargetDiscoveryService::class)->scoreCandidate($f, 'app/Services/Demo/RealService.php');

        $this->assertNotNull($scored, 'com a flag ON, o serviço REAL é admitido');
        $this->assertSame(0.45, $scored['self_contained'], 'penalizado, não igualado ao self-contained');
        $this->assertGreaterThan(0, $scored['signals']['framework_reach'], 'sinalizado p/ o refiller rotear ao caminho framework');
    }
}
