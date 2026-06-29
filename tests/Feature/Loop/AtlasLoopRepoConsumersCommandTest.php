<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the repo-wide consumers provider is live at the operator surface: in a model where App\B uses App\A,
 * consumersOf(App\A) lists App\B; an fqcn nobody consumes returns an empty list.
 */
final class AtlasLoopRepoConsumersCommandTest extends TestCase
{
    /** @return array<string,mixed> */
    private function model(): array
    {
        return [
            'file_contents_by_path' => [
                'app/A.php' => "<?php\nnamespace App;\nclass A {}\n",
                'app/B.php' => "<?php\nnamespace App;\nuse App\\A;\nclass B {\n    public function go() { return new A(); }\n}\n",
            ],
            'fqcn_by_path' => [
                'app/A.php' => 'App\\A',
                'app/B.php' => 'App\\B',
            ],
        ];
    }

    private function consumers(string $fqcn): array
    {
        $exit = Artisan::call('atlas:loop:repo-consumers', [
            '--fqcn' => $fqcn,
            '--model' => (string) json_encode($this->model()),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_lists_the_consuming_class(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->consumers('App\\A');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.repo_consumers.v1', $d['schema']);
        $this->assertContains('App\\B', $d['consumers'], (string) json_encode($d));
        $this->assertNotContains('App\\A', $d['consumers']); // a class is never its own consumer
    }

    public function test_fqcn_with_no_consumers_is_empty(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->consumers('App\\Ghost');

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['consumer_count']);
        $this->assertSame([], $d['consumers']);
    }

    public function test_missing_model_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:repo-consumers', ['--fqcn' => 'App\\A', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
