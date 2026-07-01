<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainProposalArenaCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'arena_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:proposal-arena', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function validProposal(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'target_file' => "app/Foo{$id}.php",
            'objective' => "Implement feature {$id}",
            'evidence' => ['proof-1'],
            'scaffold_score' => 0.80,
            'blast_radius' => 0.20,
            'leverage' => 0.70,
            'implementability' => 0.70,
            'risk' => 0.20,
        ], $overrides);
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:proposal-arena');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_single_valid_proposal_wins(): void
    {
        $result = $this->callCommand(['proposals' => [$this->validProposal('p1')]]);

        $this->assertSame('winner_selected', $result['verdict']);
        $this->assertSame('p1', $result['winner']['id']);
        $this->assertSame([], $result['rejected']);
    }

    public function test_proxy_proposal_is_rejected_by_arena(): void
    {
        $result = $this->callCommand(['proposals' => [
            $this->validProposal('p1'),
            $this->validProposal('p2', ['is_proxy' => true]),
        ]]);

        $this->assertSame('p1', $result['winner']['id']);
        $rejectedIds = array_column($result['rejected'], 'proposal_id');
        $this->assertContains('p2', $rejectedIds);
    }

    public function test_template_farm_proposal_is_rejected_by_arena(): void
    {
        $result = $this->callCommand(['proposals' => [
            $this->validProposal('p1'),
            $this->validProposal('p2', ['template_similarity' => 0.90, 'repeated_pattern_count' => 5]),
        ]]);

        $rejectedIds = array_column($result['rejected'], 'proposal_id');
        $this->assertContains('p2', $rejectedIds);
        $found = array_values(array_filter($result['rejected'], static fn (array $r): bool => $r['proposal_id'] === 'p2'));
        $this->assertContains('template_farm', $found[0]['reasons']);
    }

    public function test_duplicate_target_proposal_is_rejected_by_replay_court(): void
    {
        $result = $this->callCommand([
            'proposals' => [$this->validProposal('p1')],
            'existing_queue_targets' => ['app/Foop1.php'],
        ]);

        $this->assertSame('all_rejected', $result['verdict']);
        $this->assertNull($result['winner']);
        $rejectedIds = array_column($result['rejected'], 'proposal_id');
        $this->assertContains('p1', $rejectedIds);
    }

    public function test_all_proposals_rejected_yields_no_winner(): void
    {
        $result = $this->callCommand(['proposals' => [
            $this->validProposal('p1', ['is_proxy' => true]),
            $this->validProposal('p2', ['is_duplicate' => true]),
        ]]);

        $this->assertSame('all_rejected', $result['verdict']);
        $this->assertNull($result['winner']);
        $this->assertCount(2, $result['rejected']);
    }
}
