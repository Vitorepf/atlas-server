<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Adoção de identidade no índice de exemplares: greens históricos rodaram em
 * sandboxes efêmeros sem sidecar de origin — com identidade de caller o
 * retriever retornava 0 PARA SEMPRE (73/73 auditado 03/07). A adoção atribui
 * o origin do repo aos greens cujos files_touched existem nele (inferência
 * registrada em origin_adopted).
 */
final class ExemplarIndexAdoptOriginTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
        parent::tearDown();
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    public function test_adopt_origin_makes_identity_scoped_retrieval_work_for_legacy_greens(): void
    {
        $store = $this->tempDir('exemplar-store-');
        $ws = $this->tempDir('exemplar-ws-');
        mkdir($ws.'/app', 0777, true);
        file_put_contents($ws.'/app/Real.php', "<?php\n");

        mkdir($store.'/dev-1000-legacy', 0777, true);
        file_put_contents($store.'/dev-1000-legacy/verification_receipt.json', json_encode([
            'run_id' => 'dev-1000-legacy',
            'task_kind' => 'patch',
            'design_path' => 'safe_refactor',
            'task_contract_hash' => hash('sha256', 'x'),
            'changed_files' => ['app/Real.php'],
            'tests' => [['command' => 'php artisan test', 'ok' => true]],
            'completion' => ['status' => 'passed'],
        ], JSON_UNESCAPED_SLASHES));

        config(['atlas_dev.receipts_path' => $store]);
        (new DevGreenRunExemplarRetriever($store))->indexAll();

        $origin = WorkspaceOriginIdentity::hash($ws);
        $before = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5, null, $origin);
        $this->assertSame([], $before, 'sem adoção, identidade não casa green legado');

        // O comando lê o índice em storage_path fixo — aponta via symlink de teste.
        $target = storage_path('atlas-dev/receipts/exemplar_index.jsonl');
        $backup = is_file($target) ? file_get_contents($target) : null;
        @mkdir(dirname($target), 0777, true);
        copy($store.'/exemplar_index.jsonl', $target);
        try {
            Artisan::call('atlas:dev:exemplar-index', ['--adopt-origin' => $ws]);
            copy($target, $store.'/exemplar_index.jsonl');
        } finally {
            if ($backup !== null) {
                file_put_contents($target, $backup);
            } else {
                @unlink($target);
            }
        }

        $after = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5, null, $origin);
        $this->assertCount(1, $after, 'pós-adoção o green legado casa por origin');
        $this->assertSame('dev-1000-legacy', $after[0]['run_id']);
    }
}
