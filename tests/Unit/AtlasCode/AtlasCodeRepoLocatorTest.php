<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeRepoLocator;
use App\Services\AtlasCode\AtlasCodeWorkspaceScanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * O radar lista a frota lendo o Mac. Se o grafo só abre repositório com perfil
 * registrado, o app promete 12 e entrega 3. Aqui está o contrato que fecha isso
 * sem dar autoridade de governança a quem não foi registrado.
 */
final class AtlasCodeRepoLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/atlas-locator-'.uniqid();
        mkdir($this->root.'/blackink/nivor-back-end/.git', 0o777, true);
        mkdir($this->root.'/solto/.git', 0o777, true);
        putenv('ATLAS_CODE_WORKSPACE_ROOT='.$this->root);
    }

    protected function tearDown(): void
    {
        putenv('ATLAS_CODE_WORKSPACE_ROOT');
    }

    public function test_repository_without_profile_still_opens_from_disk(): void
    {
        // Era o 404 real: o radar mostrava 'nivor-back-end', o grafo recusava.
        $located = (new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()))->locate('nivor-back-end');

        self::assertSame('nivor-back-end', $located['slug']);
        self::assertSame($this->root.'/blackink/nivor-back-end', $located['path']);
    }

    public function test_loose_repository_at_the_root_opens_too(): void
    {
        $located = (new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()))->locate('solto');

        self::assertSame($this->root.'/solto', $located['path']);
    }

    public function test_unknown_repository_is_reported_not_invented(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('repository_profile_not_found');

        (new AtlasCodeRepoLocator(scanner: new AtlasCodeWorkspaceScanner()))->locate('nao-existe');
    }

    public function test_slug_can_never_escape_the_workspace(): void
    {
        $scanner = new AtlasCodeWorkspaceScanner();

        // Travessia de caminho não é slug — e não vira leitura de disco.
        self::assertNull($scanner->locate('../../etc'));
        self::assertNull($scanner->locate('blackink/nivor-back-end'));
        self::assertNull($scanner->locate('..'));
    }

    public function test_ambiguous_slug_stays_silent_instead_of_showing_the_wrong_repo(): void
    {
        // Dois produtos com um repo de mesmo nome: abrir "algum" seria mentira.
        mkdir($this->root.'/produto-a/docs/.git', 0o777, true);
        mkdir($this->root.'/produto-b/docs/.git', 0o777, true);

        self::assertNull((new AtlasCodeWorkspaceScanner())->locate('docs'));
    }
}
