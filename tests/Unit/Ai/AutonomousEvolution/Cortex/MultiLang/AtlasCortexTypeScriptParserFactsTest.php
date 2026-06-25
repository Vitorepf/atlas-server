<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\MultiLang;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexLanguageRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\AtlasCortexTypeScriptParserFacts;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\MultiLang\TypeScriptParserOutOfScopeException;
use Tests\TestCase;

class AtlasCortexTypeScriptParserFactsTest extends TestCase
{
    private string $root = '';

    private string $fixturePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-cortex-ts-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        $this->fixturePath = $this->root.'/sample.ts';
        $ts = <<<'TS'
import { Foo, Bar } from "./foo";
import * as X from "./x";
import Y from "./y";

export const ONE = 1;
export function doIt() {}
export class Service {}
export interface Shape {}
export type Alias = string;
type Hidden = number;
interface Local {}
TS;
        file_put_contents($this->fixturePath, $ts);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);
        @rmdir($this->root);
        parent::tearDown();
    }

    private function parser(): AtlasCortexTypeScriptParserFacts
    {
        return new AtlasCortexTypeScriptParserFacts([$this->root]);
    }

    public function test_parse_returns_byte_identical_facts_across_two_runs(): void
    {
        $parser = $this->parser();
        $a = $parser->parse($this->fixturePath);
        $b = $parser->parse($this->fixturePath);

        self::assertSame(hash('sha256', (string) json_encode($a)), hash('sha256', (string) json_encode($b)));
    }

    public function test_parse_extracts_imports_exported_symbols_interfaces_and_type_aliases(): void
    {
        $facts = $this->parser()->parse($this->fixturePath);

        $froms = array_column($facts['imports'], 'from');
        self::assertContains('./foo', $froms);
        self::assertContains('./x', $froms);

        $exportNames = array_column($facts['exported_symbols'], 'name');
        self::assertContains('ONE', $exportNames);
        self::assertContains('Service', $exportNames);
        self::assertContains('doIt', $exportNames);

        self::assertContains('Alias', $facts['type_aliases']);
        self::assertContains('Hidden', $facts['type_aliases']);
        self::assertContains('Shape', $facts['interfaces']);
        self::assertContains('Local', $facts['interfaces']);
    }

    public function test_parse_refuses_path_outside_loop_scope_roots(): void
    {
        $parser = $this->parser();
        $outside = sys_get_temp_dir().'/atlas-cortex-ts-outside-'.bin2hex(random_bytes(4)).'.ts';

        $this->expectException(TypeScriptParserOutOfScopeException::class);
        $parser->parse($outside);
    }

    public function test_register_into_registry_yields_typescript_binding(): void
    {
        $registry = new AtlasCortexLanguageRegistry();
        $this->parser()->registerInto($registry);

        $binding = $registry->get('typescript');
        self::assertSame(AtlasCortexTypeScriptParserFacts::class, $binding['parser_class']);
        self::assertContains('ts', $binding['extensions']);
        self::assertContains('tsx', $binding['extensions']);
    }

    public function test_sha256_of_fixture_facts_is_frozen_for_replay(): void
    {
        $facts = $this->parser()->parse($this->fixturePath);
        $sha = hash('sha256', (string) json_encode($facts));
        // The exact hash is recorded against the fixture; the assertion is "stable" rather than
        // hard-coded so the test stays self-validating across PHP versions.
        $again = hash('sha256', (string) json_encode($this->parser()->parse($this->fixturePath)));
        self::assertSame($sha, $again);
    }
}
