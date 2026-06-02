<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesProfileHomeProvisioner;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class HermesProfileHomeProvisionerTest extends TestCase
{
    private ?string $operatorHome = null;

    private string|false $previousHermesHome = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate a fake operator HERMES_HOME so symlink assertions are real but
        // never touch the operator's actual ~/.hermes.
        $this->operatorHome = sys_get_temp_dir().'/hermes-profile-op-'.uniqid('', true);
        @mkdir($this->operatorHome, 0700, true);
        file_put_contents($this->operatorHome.'/.env', "OPERATOR=secret\n");
        @mkdir($this->operatorHome.'/skills', 0700, true);

        $value = getenv('HERMES_HOME');
        $this->previousHermesHome = $value === false ? false : $value;
        putenv('HERMES_HOME='.$this->operatorHome);
    }

    protected function tearDown(): void
    {
        // Clean up any provisioned managed homes from this run.
        foreach ([
            'mesh-engineer-trace-001',
            'mesh-engineer',
            '..-..-etc-passwd',
        ] as $role) {
            $files = new Filesystem;
            foreach ([null, 'trace-001', '../../etc/passwd'] as $trace) {
                $dir = (new HermesProfileHomeProvisioner($files))->path($role, $trace);
                if ($files->isDirectory($dir)) {
                    $files->deleteDirectory($dir);
                }
            }
        }

        if ($this->operatorHome !== null && is_dir($this->operatorHome)) {
            (new Filesystem)->deleteDirectory($this->operatorHome);
        }

        if ($this->previousHermesHome === false) {
            putenv('HERMES_HOME');
        } else {
            putenv('HERMES_HOME='.$this->previousHermesHome);
        }

        parent::tearDown();
    }

    private function provisioner(): HermesProfileHomeProvisioner
    {
        return new HermesProfileHomeProvisioner(new Filesystem);
    }

    public function test_approved_path_materializes_managed_home_with_config_and_symlinks(): void
    {
        $home = $this->provisioner()->provision(
            'mesh-engineer',
            [
                'toolsets' => ['fs', 'git', 'fs'],
                'provider' => 'openai',
                'model' => 'gpt-5.5',
                'skills' => ['refactor'],
            ],
            'trace-001',
        );

        $this->assertNotNull($home);
        $this->assertDirectoryExists($home);
        $this->assertFileExists($home.'/config.yaml');

        $config = json_decode(file_get_contents($home.'/config.yaml'), true);
        $this->assertSame('openai', $config['model']['provider']);
        $this->assertSame('gpt-5.5', $config['model']['model']);
        // toolsets deduped and recorded as both a hint and explicit list.
        $this->assertSame(['fs', 'git'], $config['toolsets']);
        $this->assertSame(['fs', 'git'], $config['tools']['toolsets']);
        $this->assertSame(['refactor'], $config['skills']);

        // Operator assets are symlinked in, never copied/mutated.
        $this->assertTrue(is_link($home.'/.env'));
        $this->assertTrue(is_link($home.'/skills'));
        $this->assertSame("OPERATOR=secret\n", file_get_contents($home.'/.env'));

        // Managed home lives strictly under storage/app/hermes/profiles.
        $this->assertStringContainsString('/hermes/profiles/', $home);
    }

    public function test_provision_with_only_provider_still_materializes(): void
    {
        $home = $this->provisioner()->provision('planner', ['provider' => 'minimax'], 'trace-001');

        $this->assertNotNull($home);
        $config = json_decode(file_get_contents($home.'/config.yaml'), true);
        $this->assertSame('minimax', $config['model']['provider']);
        $this->assertArrayNotHasKey('model', $config['model']);
        $this->assertSame([], $config['toolsets']);
    }

    public function test_default_off_returns_null_when_no_toolsets_and_no_provider(): void
    {
        // Fail-closed: nothing to specialize => no managed home is created.
        $home = $this->provisioner()->provision(
            'mesh-engineer',
            ['toolsets' => [], 'provider' => null, 'model' => 'gpt-5.5', 'skills' => ['x']],
            'trace-001',
        );

        $this->assertNull($home);
        $this->assertDirectoryDoesNotExist(
            $this->provisioner()->path('mesh-engineer', 'trace-001'),
        );
    }

    public function test_invalid_input_is_handled_safely(): void
    {
        // Garbage profile shape: invalid types fall back to empty => null.
        $home = $this->provisioner()->provision('mesh-engineer', [
            'toolsets' => 'not-an-array',
            'provider' => '   ',
            'model' => 42,
            'skills' => 'nope',
        ], 'trace-001');

        $this->assertNull($home);
    }

    public function test_slug_never_traverses_outside_storage(): void
    {
        $base = realpath(storage_path('app/hermes')) ?: storage_path('app/hermes');

        $path = $this->provisioner()->path('../../etc/passwd', '../../secrets');

        $this->assertStringStartsWith(storage_path('app/hermes/profiles/'), $path);
        $this->assertStringNotContainsString('..', basename($path));

        // Provisioning with a traversal-laden role stays under the managed base.
        $home = $this->provisioner()->provision('../../etc/passwd', ['provider' => 'openai'], '../../secrets');
        $this->assertNotNull($home);
        $resolved = realpath($home);
        $this->assertNotFalse($resolved);
        $this->assertStringStartsWith($base.'/profiles/', $resolved);
    }

    public function test_path_is_deterministic_and_forget_removes_managed_home(): void
    {
        $p = $this->provisioner();

        $a = $p->path('mesh-engineer', 'trace-001');
        $b = $p->path('mesh-engineer', 'trace-001');
        $this->assertSame($a, $b, 'path() must be deterministic for the same role+trace');

        $home = $p->provision('mesh-engineer', ['provider' => 'openai'], 'trace-001');
        $this->assertNotNull($home);
        $this->assertDirectoryExists($home);

        $p->forget('mesh-engineer', 'trace-001');
        $this->assertDirectoryDoesNotExist($home);

        // forget() on a missing home is a safe no-op.
        $p->forget('never-provisioned', 'trace-001');
        $this->assertTrue(true);
    }

    public function test_role_and_trace_distinguish_managed_homes(): void
    {
        $p = $this->provisioner();

        $this->assertNotSame(
            $p->path('mesh-engineer', 'trace-001'),
            $p->path('mesh-engineer', 'trace-002'),
        );
        $this->assertNotSame(
            $p->path('mesh-engineer', 'trace-001'),
            $p->path('mesh-planner', 'trace-001'),
        );
    }
}
