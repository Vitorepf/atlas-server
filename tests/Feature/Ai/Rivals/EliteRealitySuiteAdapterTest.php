<?php

namespace Tests\Feature\Ai\Rivals2;

use App\Services\Ai\Rivals2\Adapters\EliteRealitySuiteAdapter;
use Tests\TestCase;

/** Guard pétreo: mineração roda contra repo git de fixture em temp, nunca no repo vivo. */
class EliteRealitySuiteAdapterTest extends TestCase
{
    private string $storage;

    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals2_elite_test_'.uniqid();
        $this->fixtureRepo = sys_get_temp_dir().'/rivals2_elite_repo_'.uniqid();
        config()->set('atlas_rivals2.storage_root', $this->storage);
        config()->set('atlas_rivals2.atlasbench.repo_path', $this->fixtureRepo);
        // pisos elite reais (80/3) exigiriam fixture gigante; o teste pina o MECANISMO
        config()->set('atlas_rivals2.elite.min_diff_lines', 20);
        config()->set('atlas_rivals2.elite.min_code_files', 3);
        $this->buildFixtureRepo();
    }

    protected function tearDown(): void
    {
        foreach ([$this->storage, $this->fixtureRepo] as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
        parent::tearDown();
    }

    private function git(string $cmd): void
    {
        exec('cd '.escapeshellarg($this->fixtureRepo)." && {$cmd} 2>&1");
    }

    private function writeModule(string $name, string $body): void
    {
        file_put_contents($this->fixtureRepo."/app/{$name}.php", "<?php\n// module {$name}\n{$body}\n");
    }

    private function buildFixtureRepo(): void
    {
        mkdir($this->fixtureRepo.'/app', 0755, true);
        mkdir($this->fixtureRepo.'/tests', 0755, true);
        $this->git('git init -q && git config user.email t@t && git config user.name t');

        $buggy = implode("\n", array_map(fn ($i) => "function f{$i}() { return {$i} - 1; }", range(1, 6)));
        foreach (['A', 'B', 'C'] as $m) {
            $this->writeModule($m, $buggy);
        }
        file_put_contents($this->fixtureRepo.'/tests/check.php', "<?php exit(1);\n");
        $this->git('git add -A && git commit -qm "base"');

        // golden elite: 3 arquivos de código + teste, diff >= 20 linhas, body sem receita
        $fixed = implode("\n", array_map(fn ($i) => "function f{$i}() { return {$i} + 1; }", range(1, 6)));
        foreach (['A', 'B', 'C'] as $m) {
            $this->writeModule($m, $fixed);
        }
        file_put_contents($this->fixtureRepo.'/tests/check.php', "<?php exit(0);\n");
        $this->git('git add -A && git commit -qm "fix: ledger drops entries under concurrent writes" -m "Two writers race on the shared buffer and entries vanish under load."');

        // commit contaminado: corpo entrega a receita (path de teste) → guard rejeita
        foreach (['A', 'B', 'C'] as $m) {
            $this->writeModule($m, $fixed."\nfunction g_{$m}() { return 0; }");
        }
        file_put_contents($this->fixtureRepo.'/tests/check.php', "<?php exit(0); // v2\n");
        $this->git('git add -A && git commit -qm "fix: another bug" -m "Just run tests/check.php and make it pass. 1. open file 2. patch 3. done"');
    }

    public function test_elite_mining_applies_families_guard_and_floors(): void
    {
        $cases = (new EliteRealitySuiteAdapter)->mineCases(10);

        // só o golden limpo entra: o contaminado (receita no corpo) e o commit raiz caem
        $this->assertCount(1, $cases);
        $case = $cases[0];
        $this->assertSame('concurrency_state_bug', $case['task_type']);
        $this->assertContains($case['task_type'], config('atlas_rivals2.task_types'));
        $this->assertSame([], $case['contamination']['violations']);
        $this->assertNotNull($case['contamination']['prompt_fingerprint']);
        $this->assertGreaterThanOrEqual(3, count($case['changed_files']['code']));
    }

    public function test_elite_ticket_never_leaks_commit_subject_or_recipe(): void
    {
        $adapter = new class extends EliteRealitySuiteAdapter
        {
            public function exposedTicket(array $case): string
            {
                return $this->ticketFor($case);
            }
        };

        $withBody = $adapter->exposedTicket([
            'title' => 'fix: ledger drops entries under concurrent writes',
            'ticket_body' => 'Two writers race on the shared buffer.',
            'symptom_excerpt' => 'expected 5 got 3',
        ]);
        // o subject do commit é receita — nunca aparece no ticket elite
        $this->assertStringNotContainsString('ledger drops entries', $withBody);
        $this->assertStringContainsString('Two writers race', $withBody);
        $this->assertStringContainsString('hidden checks', $withBody);

        // unknown unknown: sem corpo, o solver precisa DESCOBRIR o problema
        $unknown = $adapter->exposedTicket(['title' => 'fix: x', 'ticket_body' => '', 'symptom_excerpt' => 'boom']);
        $this->assertStringContainsString('Discover what the actual problem is', $unknown);
    }

    public function test_list_cases_expires_long_lived_corpus(): void
    {
        $adapter = new EliteRealitySuiteAdapter;
        $mined = $adapter->mineCases(10);
        $this->assertNotEmpty($adapter->listCases());

        // envelhece o corpus além do teto → some da esteira, fail-closed
        $file = $this->storage.'/elite/cases/'.$mined[0]['case_id'].'.json';
        $case = json_decode(file_get_contents($file), true);
        $case['mined_at'] = now()->subDays(90)->toIso8601String();
        file_put_contents($file, json_encode($case));

        $this->assertSame([], $adapter->listCases());
    }
}
