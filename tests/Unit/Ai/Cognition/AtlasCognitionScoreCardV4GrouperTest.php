<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardV4Grouper;
use Tests\TestCase;

final class AtlasCognitionScoreCardV4GrouperTest extends TestCase
{
    public function test_core_rollup_fixes_patamar_group_and_excludes_consumers(): void
    {
        $rows = [
            $this->row('AHRI', 'aucri'),
            $this->row('ANCF', 'patamar_4'),
            $this->row('ASCB', 'self_construction'),
            $this->row('ACTG', 'cartography'),
            $this->row('ABDD', 'programming'),
            $this->row('ARDR', 'research_domain'),
        ];
        $grouper = new AtlasCognitionScoreCardV4Grouper;

        $core = $grouper->group($rows);
        $consumers = $grouper->groupConsumers($rows);

        $this->assertSame(['CONTEXT', 'PATAMAR4'], array_column($core, 'acronym'));
        $this->assertSame(['acos', 'acos'], array_column($core, 'boundary'));
        $this->assertSame(['CONSUMERS'], array_column($consumers, 'acronym'));
        $this->assertSame('consumer', $consumers[0]['boundary']);
        $this->assertSame(['ASCB', 'ACTG', 'ABDD', 'ARDR'], $consumers[0]['members']);
    }

    public function test_scorecard_v4_represents_declared_core_modules_missing_from_v3_facets(): void
    {
        $v4 = (new AtlasCognitionScoreCardService)->build()['v4'];
        $members = collect($v4['modules'])->flatMap(
            static fn (array $module): array => $module['members'],
        )->all();

        foreach (['ACCCR', 'ACIE', 'APCR', 'AEMOR', 'TEOS-I1', 'AVCEL', 'ACQCG', 'AOBG', 'EVIDENCE'] as $acronym) {
            $this->assertContains($acronym, $members);
        }
        foreach (['ASCB', 'ASCB-EX', 'ASCB-PP', 'ASDM', 'ACTG', 'ABDD', 'APCP', 'ARDR'] as $consumer) {
            $this->assertNotContains($consumer, $members);
        }

        $this->assertSame(9, $v4['supplemental_subsystem_count']);
        $this->assertNotEmpty($v4['consumer_modules']);
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $acronym, string $group): array
    {
        return [
            'acronym' => $acronym,
            'name' => $acronym,
            'group' => $group,
            'code_status' => 'ready',
            'doc_status' => 'ready',
            'pipeline_status' => 'ready',
        ];
    }
}
