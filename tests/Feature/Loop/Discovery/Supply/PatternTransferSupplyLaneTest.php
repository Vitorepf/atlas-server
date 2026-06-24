<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\PatternTransferSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\Supply\SupplyLaneContract;
use Tests\TestCase;

final class PatternTransferSupplyLaneTest extends TestCase
{
    public function test_sibling_subdir_missing_validator_pattern_mints_one_transfer_spec(): void
    {
        $lane = new PatternTransferSupplyLane;
        $this->assertInstanceOf(SupplyLaneContract::class, $lane);
        $this->assertSame('pattern_transfer', PatternTransferSupplyLane::OBJECTIVE_KIND);

        $specs = $lane->mint($this->model([
            $this->node('app/Foo/X/AValidator.php', 'App\\Foo\\X\\AValidator'),
            $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
        ]), '/repo');

        $this->assertCount(1, $specs);
        $spec = $specs[0];
        $this->assertSame('pattern_transfer', $spec['payload']['objective_kind']);
        $this->assertSame('pattern_transfer', $spec['payload']['source']);
        $this->assertSame('Validator', $spec['payload']['pattern_suffix']);
        $this->assertSame('App\\Foo\\X\\AValidator', $spec['payload']['source_fqcn']);
        $this->assertSame('app/Foo/X/AValidator.php', $spec['payload']['source_path']);
        $this->assertSame('app/Foo/Y', $spec['payload']['target_subdir']);
        $this->assertSame('app/Foo/Y/BValidator.php', $spec['payload']['expected_path']);
        $this->assertTrue($spec['payload']['red_required']);
        $this->assertTrue($spec['payload']['comprehension_originated']);
        $this->assertSame(AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE, $spec['payload']['provenance']);
        $this->assertSame(['app/Foo/X/AValidator.php', 'app/Foo/Y'], $spec['members']);
        $this->assertStringContainsString('`*Validator`', $spec['objective']);
        $this->assertStringContainsString('Originar `BValidator.php` em app/Foo/Y', $spec['objective']);
    }

    public function test_when_both_siblings_already_have_validator_no_spec_is_minted(): void
    {
        $specs = (new PatternTransferSupplyLane)->mint($this->model([
            $this->node('app/Foo/X/AValidator.php', 'App\\Foo\\X\\AValidator'),
            $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
            $this->node('app/Foo/Y/BValidator.php', 'App\\Foo\\Y\\BValidator'),
        ]), '/repo');

        $this->assertSame([], $specs);
    }

    public function test_short_suffix_is_dropped_as_noise(): void
    {
        $specs = (new PatternTransferSupplyLane)->mint($this->model([
            $this->node('app/Foo/X/AY.php', 'App\\Foo\\X\\AY'),
            $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
        ]), '/repo');

        $this->assertSame([], $specs);
    }

    public function test_forbidden_source_is_dropped(): void
    {
        $specs = (new PatternTransferSupplyLane)->mint($this->model(
            inventory: [
                $this->node('app/Foo/X/AValidator.php', 'App\\Foo\\X\\AValidator', isForbidden: true),
                $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
            ],
            forbidden: ['app/Foo/X/AValidator.php'],
        ), '/repo');

        $this->assertSame([], $specs);
    }

    public function test_dedup_by_target_subdir_and_suffix_when_two_sources_offer_same_pattern(): void
    {
        $specs = (new PatternTransferSupplyLane)->mint($this->model([
            $this->node('app/Foo/X/AValidator.php', 'App\\Foo\\X\\AValidator'),
            $this->node('app/Foo/X/CValidator.php', 'App\\Foo\\X\\CValidator'),
            $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
        ]), '/repo');

        $this->assertCount(1, $specs);
        $this->assertSame('app/Foo/Y/BValidator.php', $specs[0]['payload']['expected_path']);
        $this->assertSame('app/Foo/X/AValidator.php', $specs[0]['payload']['source_path']);
    }

    public function test_empty_model_returns_empty_specs(): void
    {
        $this->assertSame([], (new PatternTransferSupplyLane)->mint($this->model([]), '/repo'));
    }

    public function test_specs_are_sorted_by_expected_path(): void
    {
        $specs = (new PatternTransferSupplyLane)->mint($this->model([
            $this->node('app/Foo/X/ZValidator.php', 'App\\Foo\\X\\ZValidator'),
            $this->node('app/Foo/Y/BController.php', 'App\\Foo\\Y\\BController'),
            $this->node('app/Foo/Z/AController.php', 'App\\Foo\\Z\\AController'),
        ]), '/repo');

        $this->assertSame([
            'app/Foo/Y/BValidator.php',
            'app/Foo/Z/AValidator.php',
        ], array_map(static fn (array $spec): string => (string) $spec['payload']['expected_path'], $specs));
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     * @param  list<string>  $forbidden
     */
    private function model(array $inventory, array $forbidden = []): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: [],
            orphans: [],
            cloneClusters: [],
            forbidden: $forbidden,
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'pattern-transfer-test',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function node(string $path, string $fqcn, bool $isForbidden = false): array
    {
        return [
            'rel_path' => $path,
            'fqcn' => $fqcn,
            'public_methods' => [],
            'is_orphan' => false,
            'is_forbidden' => $isForbidden,
            'clone_cluster_id' => null,
        ];
    }
}
