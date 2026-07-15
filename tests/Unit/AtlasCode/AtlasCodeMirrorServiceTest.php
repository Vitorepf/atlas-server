<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeMirrorService;
use PHPUnit\Framework\TestCase;

/**
 * M5 espelho: nada sai da máquina sem varredura — e o achado é reportado
 * pelo NOME da regra, nunca com o segredo dentro.
 */
final class AtlasCodeMirrorServiceTest extends TestCase
{
    public function test_scan_flags_added_secret_by_rule_name_without_leaking_it(): void
    {
        $service = new AtlasCodeMirrorService();

        $findings = $service->scanForSecrets(
            "diff --git a/config/app.ts b/config/app.ts\n".
            "+const key = \"sk-abcdefghijklmnopqrstuvwxyz1234\";\n".
            " const other = 1;\n",
        );

        self::assertCount(1, $findings);
        self::assertSame('openai_key', $findings[0]['rule']);
        // Provider-safe: o payload não carrega o segredo.
        self::assertSame(['rule', 'line'], array_keys($findings[0]));
    }

    public function test_scan_ignores_removed_lines_and_context(): void
    {
        $service = new AtlasCodeMirrorService();

        $findings = $service->scanForSecrets(
            "-const old = \"ghp_012345678901234567890123456789abcd\";\n".
            " const ctx = \"AKIAIOSFODNN7EXAMPLE\";\n",
        );

        // Remover um segredo não é vazar um segredo.
        self::assertSame([], $findings);
    }

    public function test_scan_catches_private_key_block_and_aws_key(): void
    {
        $service = new AtlasCodeMirrorService();

        $findings = $service->scanForSecrets(
            "+-----BEGIN OPENSSH PRIVATE KEY-----\n".
            "+AKIAIOSFODNN7EXAMPLE\n",
        );

        self::assertSame(['private_key_block', 'aws_access_key'], array_column($findings, 'rule'));
    }

    public function test_clean_patch_is_silent(): void
    {
        $service = new AtlasCodeMirrorService();

        self::assertSame([], $service->scanForSecrets("+let total = soma(1, 2);\n"));
    }

    public function test_host_of_never_returns_credentials(): void
    {
        $service = new AtlasCodeMirrorService();

        self::assertSame('github.com', $service->hostOf('git@github.com:Vitorepf/atlas-native.git'));
        self::assertSame('github.com', $service->hostOf('https://github.com/Vitorepf/atlas-native.git'));
        // Uma URL com token embutido não pode vazar pelo host.
        self::assertSame('gitlab.example', $service->hostOf('https://user:token@gitlab.example/x.git'));
        self::assertNull($service->hostOf(''));
    }
}
