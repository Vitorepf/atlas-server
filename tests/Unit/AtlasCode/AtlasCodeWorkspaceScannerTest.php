<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeWorkspaceScanner;
use PHPUnit\Framework\TestCase;

/**
 * A descoberta reflete o Mac de verdade: pasta de produto contém repos;
 * pasta NÃO é repositório quebrado. Recentes são atalho, não cópia.
 */
final class AtlasCodeWorkspaceScannerTest extends TestCase
{
    public function test_human_name_reads_like_portuguese_not_kebab(): void
    {
        $scanner = new AtlasCodeWorkspaceScanner();

        self::assertSame('Atlas Native', $scanner->humanName('atlas-native'));
        self::assertSame('Nivor Back End', $scanner->humanName('nivor-back-end'));
        self::assertSame('Blackink', $scanner->humanName('blackink'));
        // Siglas continuam siglas.
        self::assertSame('Atlas AI', $scanner->humanName('atlas-ai'));
    }

    public function test_recents_are_the_newest_and_folders_keep_everything(): void
    {
        $scanner = new AtlasCodeWorkspaceScanner();

        $organized = $scanner->organize([
            ['slug' => 'atlas-native', 'name' => 'Atlas Native', 'path' => '/d/Atlas/atlas-native', 'folder' => 'Atlas', 'last_commit_at' => 300],
            ['slug' => 'atlas-server', 'name' => 'Atlas Server', 'path' => '/d/Atlas/atlas-server', 'folder' => 'Atlas', 'last_commit_at' => 290],
            ['slug' => 'atlas-desktop', 'name' => 'Atlas Desktop', 'path' => '/d/Atlas/atlas-desktop', 'folder' => 'Atlas', 'last_commit_at' => 100],
            ['slug' => 'blackink-website', 'name' => 'Blackink Website', 'path' => '/d/blackink/blackink-website', 'folder' => 'blackink', 'last_commit_at' => 280],
            ['slug' => 'vitorepf-site', 'name' => 'Vitorepf Site', 'path' => '/d/vitorepf-site', 'folder' => null, 'last_commit_at' => 50],
        ], recentLimit: 3);

        // Os três mais recentes, na ordem do trabalho real.
        self::assertSame(['atlas-native', 'atlas-server', 'blackink-website'], array_column($organized['recents'], 'slug'));

        // As pastas continuam completas: o recente é atalho, não remoção.
        $atlas = $organized['folders'][0];
        self::assertSame('Atlas', $atlas['name']);
        self::assertSame(3, $atlas['repositories']);
        self::assertSame('atlas-native', $atlas['repos'][0]['slug']);

        // A pasta mais viva vem primeiro.
        self::assertSame(['Atlas', 'Blackink'], array_column($organized['folders'], 'name'));

        // Repo solto não vira pasta fantasma.
        self::assertSame(['vitorepf-site'], array_column($organized['loose'], 'slug'));
    }

    public function test_repository_without_history_never_ranks_as_recent(): void
    {
        $scanner = new AtlasCodeWorkspaceScanner();

        $organized = $scanner->organize([
            ['slug' => 'sem-historia', 'name' => 'Sem História', 'path' => '/d/x/sem', 'folder' => 'x', 'last_commit_at' => null],
            ['slug' => 'com-historia', 'name' => 'Com História', 'path' => '/d/x/com', 'folder' => 'x', 'last_commit_at' => 10],
        ], recentLimit: 3);

        // Sem data não há pódio — e ausência jamais vira epoch 0.
        self::assertSame(['com-historia'], array_column($organized['recents'], 'slug'));
        // Mas a pasta continua contando os dois: o repo existe.
        self::assertSame(2, $organized['folders'][0]['repositories']);
    }

    public function test_discovery_sees_folders_of_products_not_broken_repos(): void
    {
        $root = sys_get_temp_dir().'/atlas-ws-'.uniqid();
        mkdir($root.'/Produto/repo-a/.git', 0o777, true);
        mkdir($root.'/Produto/repo-b/.git', 0o777, true);
        mkdir($root.'/solto/.git', 0o777, true);
        mkdir($root.'/Produto/node_modules/lixo/.git', 0o777, true);
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$root);

        $scanner = new AtlasCodeWorkspaceScanner();
        $repos = $scanner->discover();
        putenv('ATLAS_CODE_WORKSPACE_ROOT');

        $slugs = array_column($repos, 'slug');
        sort($slugs);
        // Os dois repos da pasta + o solto. 'Produto' NÃO aparece como repo.
        self::assertSame(['repo-a', 'repo-b', 'solto'], $slugs);
        // Ruído de máquina fica fora.
        self::assertNotContains('lixo', $slugs);

        $folders = array_column($repos, 'folder');
        self::assertContains('Produto', $folders);
        self::assertContains(null, $folders);
    }
}
