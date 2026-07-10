<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\FaseAClosureReceipt;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FaseAClosureReceiptTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_closure_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_closure_is_machine_generated_fail_closed_and_tamper_evident(): void
    {
        $closure = new FaseAClosureReceipt;
        $receipt = $closure->build([
            'tests' => ['passed' => true],
            'docs_health' => ['passed' => true],
        ]);

        $this->assertFalse($receipt['fase_a_100_percent_authorized']);
        $this->assertNotEmpty($receipt['blockers']);
        $this->assertFileExists(RunPaths::closureReceiptPath());
        $this->assertTrue($closure->verify()['verified']);
        $this->assertFalse($closure->verify()['authorized']);

        $data = json_decode(file_get_contents(RunPaths::closureReceiptPath()), true);
        $data['fase_a_100_percent_authorized'] = true;
        file_put_contents(RunPaths::closureReceiptPath(), json_encode($data));
        $this->assertFalse($closure->verify()['verified']);
        $this->assertContains('closure_hash_mismatch', $closure->verify()['failures']);
    }
}
