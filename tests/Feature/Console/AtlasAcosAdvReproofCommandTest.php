<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasAcosAdvReproofCommandTest extends TestCase
{
    private string $jsonl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonl = sys_get_temp_dir().'/acos-adv-reproof-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->jsonl);
        parent::tearDown();
    }

    public function test_reports_counts_and_fails_when_any_verdict_is_refuted(): void
    {
        $this->writeRows([
            ['certifier_id' => 'RAG-12', 'verdict' => 'confirmed'],
            ['certifier_id' => 'COM-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'CPT-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'PIP-07', 'verdict' => 'confirmed'],
            ['certifier_id' => 'OPE-06', 'verdict' => 'refuted'],
            ['certifier_id' => 'ENG-12', 'verdict' => 'inconclusive'],
        ]);

        $payload = $this->runCommand(expectedExit: 1);

        $this->assertSame('failed', $payload['status']);
        $this->assertSame(6, $payload['total_verdicts']);
        $this->assertSame([
            'confirmed' => 4,
            'refuted' => 1,
            'inconclusive' => 1,
        ], $payload['counts']);
        $this->assertContains('refuted_verdicts_present', $payload['blocking']);
    }

    public function test_fails_when_fewer_than_six_verdicts_even_without_refutations(): void
    {
        $this->writeRows([
            ['certifier_id' => 'RAG-12', 'verdict' => 'confirmed'],
            ['certifier_id' => 'COM-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'CPT-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'PIP-07', 'verdict' => 'confirmed'],
            ['certifier_id' => 'OPE-06', 'verdict' => 'confirmed'],
        ]);

        $payload = $this->runCommand(expectedExit: 1);

        $this->assertSame('failed', $payload['status']);
        $this->assertSame(5, $payload['total_verdicts']);
        $this->assertSame(5, $payload['counts']['confirmed']);
        $this->assertContains('verdict_count_below_floor', $payload['blocking']);
    }

    public function test_succeeds_with_six_confirmed_verdicts(): void
    {
        $this->writeRows([
            ['certifier_id' => 'RAG-12', 'verdict' => 'confirmed'],
            ['certifier_id' => 'COM-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'CPT-10', 'verdict' => 'confirmed'],
            ['certifier_id' => 'PIP-07', 'verdict' => 'confirmed'],
            ['certifier_id' => 'OPE-06', 'verdict' => 'confirmed'],
            ['certifier_id' => 'ENG-12', 'verdict' => 'confirmed'],
        ]);

        $payload = $this->runCommand(expectedExit: 0);

        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['blocking']);
        $this->assertSame([
            'confirmed' => 6,
            'refuted' => 0,
            'inconclusive' => 0,
        ], $payload['counts']);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeRows(array $rows): void
    {
        $lines = array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $rows,
        );

        file_put_contents($this->jsonl, implode("\n", $lines)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function runCommand(int $expectedExit): array
    {
        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:adv-reproof', [
            '--path' => $this->jsonl,
            '--json' => true,
        ], $out);

        $this->assertSame($expectedExit, $exit);

        return json_decode($out->fetch(), true, flags: JSON_THROW_ON_ERROR);
    }
}
