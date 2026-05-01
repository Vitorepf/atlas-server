<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\IntentPermissionResolver;
use Tests\TestCase;

class IntentPermissionResolverTest extends TestCase
{
    private IntentPermissionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new IntentPermissionResolver;
    }

    public function test_pure_explanation_stays_read_only(): void
    {
        $resolution = $this->resolver->resolve('explique como funciona o auth', 'read');

        $this->assertSame('read', $resolution->required);
        $this->assertFalse($resolution->changed);
        $this->assertSame([], $resolution->signals);
    }

    public function test_listing_or_reading_stays_read_only(): void
    {
        foreach (['liste os arquivos do app', 'mostre o git status', 'me explique', 'que tem aqui?'] as $input) {
            $resolution = $this->resolver->resolve($input, 'read');
            $this->assertSame('read', $resolution->required, "input: {$input}");
            $this->assertFalse($resolution->changed, "input: {$input}");
        }
    }

    public function test_install_dependencies_requires_write(): void
    {
        $resolution = $this->resolver->resolve('instale phpunit como dependencia de dev', 'read');

        $this->assertSame('write', $resolution->required);
        $this->assertTrue($resolution->changed);
        $this->assertNotSame([], $resolution->signals);
    }

    public function test_run_tests_requires_write(): void
    {
        foreach (['rode os testes', 'run the tests', 'execute phpunit'] as $input) {
            $resolution = $this->resolver->resolve($input, 'read');
            $this->assertSame('write', $resolution->required, "input: {$input}");
            $this->assertTrue($resolution->changed, "input: {$input}");
        }
    }

    public function test_create_or_edit_requires_write(): void
    {
        foreach ([
            'crie um arquivo novo',
            'edite o composer.json',
            'escreva um service',
            'refatore os handlers',
            'remova o arquivo legado',
        ] as $input) {
            $resolution = $this->resolver->resolve($input, 'read');
            $this->assertSame('write', $resolution->required, "input: {$input}");
            $this->assertTrue($resolution->changed, "input: {$input}");
        }
    }

    public function test_sudo_or_system_install_requires_danger(): void
    {
        foreach ([
            'rode com sudo o composer',
            'brew install php@8.5',
            'apt-get install ffmpeg',
            'rm -rf /tmp/foo',
            'chmod 777 storage',
        ] as $input) {
            $resolution = $this->resolver->resolve($input, 'read');
            $this->assertSame('danger', $resolution->required, "input: {$input}");
            $this->assertTrue($resolution->changed, "input: {$input}");
        }
    }

    public function test_force_push_in_either_order_requires_danger(): void
    {
        foreach ([
            'git push --force origin main',
            'force push origin main',
            'git reset --hard HEAD',
        ] as $input) {
            $resolution = $this->resolver->resolve($input, 'read');
            $this->assertSame('danger', $resolution->required, "input: {$input}");
        }
    }

    public function test_write_intent_does_not_escalate_when_already_write(): void
    {
        $resolution = $this->resolver->resolve('crie o arquivo Foo.php', 'write');

        $this->assertSame('write', $resolution->required);
        $this->assertFalse($resolution->changed);
    }

    public function test_danger_does_not_escalate_when_already_danger(): void
    {
        $resolution = $this->resolver->resolve('apt-get install nginx', 'danger');

        $this->assertSame('danger', $resolution->required);
        $this->assertFalse($resolution->changed);
    }

    public function test_slash_commands_do_not_trigger_classification(): void
    {
        $resolution = $this->resolver->resolve('/install foo', 'read');

        $this->assertSame('read', $resolution->required);
        $this->assertFalse($resolution->changed);
    }

    public function test_empty_input_is_read_only(): void
    {
        $resolution = $this->resolver->resolve('   ', 'read');

        $this->assertSame('read', $resolution->required);
        $this->assertFalse($resolution->changed);
    }

    public function test_resolution_helpers_match_required_level(): void
    {
        $write = $this->resolver->resolve('rode os testes', 'read');
        $danger = $this->resolver->resolve('apt install foo', 'read');
        $read = $this->resolver->resolve('explique X', 'read');

        $this->assertTrue($write->isWrite());
        $this->assertTrue($danger->isDanger());
        $this->assertTrue($read->isReadOnly());
    }

    public function test_unknown_current_permission_normalizes_to_read(): void
    {
        $resolution = $this->resolver->resolve('crie arquivo X', 'auto');

        $this->assertSame('read', $resolution->current);
        $this->assertSame('write', $resolution->required);
        $this->assertTrue($resolution->changed);
    }

    public function test_reason_describes_first_match_concisely(): void
    {
        $resolution = $this->resolver->resolve('por favor instale o phpunit', 'read');

        $this->assertNotEmpty($resolution->reason);
        $this->assertNotSame('leitura/explicacao', $resolution->reason);
    }
}
