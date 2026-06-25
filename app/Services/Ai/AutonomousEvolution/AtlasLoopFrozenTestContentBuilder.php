<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Frozen-test codegen collaborator extracted from {@see AtlasLoopIntentVerifierFactory}: pure
 * string-building (no service/state deps) that compiles atom payloads into the executable PHP
 * source of a sealed frozen test. Behavior is BYTE-IDENTICAL to the in-factory implementation
 * that previously lived at lines ~743/909-1178 — same emitted source for the same inputs.
 */
final class AtlasLoopFrozenTestContentBuilder
{
    /**
     * @param  list<array<string,mixed>>  $atoms
     */
    public function frozenTestContent(string $testPath, string $target, string $class, array $atoms): string
    {
        $prefix = $this->relativePrefix(dirname($testPath));
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Illuminate\Contracts\Console\Kernel;',
            '',
            "require __DIR__.'/".$prefix."vendor/autoload.php';",
            "if (is_file(__DIR__.'/".$prefix."bootstrap/app.php')) {",
            "    \$app = require __DIR__.'/".$prefix."bootstrap/app.php';",
            '    if (is_object($app) && method_exists($app, "make")) {',
            '        $app->make(Kernel::class)->bootstrap();',
            '    }',
            '}',
            "if (! class_exists(".var_export($class, true).")) {",
            "    require_once __DIR__.'/".$prefix.$target."';",
            '}',
            '',
            '$fail = static function (string $message): void { fwrite(STDERR, $message); exit(1); };',
            '$class = '.var_export($class, true).';',
            '',
        ];

        foreach ($atoms as $index => $atom) {
            if (($atom['type'] ?? '') === 'command_output') {
                $lines = array_merge($lines, $this->commandOutputAssertionLines($index, $prefix, $atom));
            } elseif (($atom['type'] ?? '') === 'http_response') {
                $lines = array_merge($lines, $this->httpResponseAssertionLines($index, $atom));
            } elseif (($atom['type'] ?? '') === 'event_dispatched') {
                $lines = array_merge($lines, $this->eventDispatchedAssertionLines($index, $atom));
            } elseif (($atom['type'] ?? '') === 'job_dispatched') {
                $lines = array_merge($lines, $this->jobDispatchedAssertionLines($index, $atom));
            } elseif (($atom['type'] ?? '') === 'db_state') {
                $lines = array_merge($lines, $this->dbStateAssertionLines($index, $atom));
            } else {
                $constructorArgs = var_export($atom['constructor_args'] ?? [], true);
                $methodArgs = var_export($atom['method_args'] ?? [], true);
                $method = (string) $atom['method'];
                $expected = var_export($atom['expected'] ?? null, true);
                $subject = '$subject'.$index;
                $refMethod = '$method'.$index;
                if ((bool) ($atom['static'] ?? false)) {
                    $lines[] = '$classRef'.$index.' = new ReflectionClass($class);';
                    $lines[] = 'if (! $classRef'.$index.'->hasMethod('.var_export($method, true).')) { $fail("'.$method.' missing"); }';
                    $lines[] = $refMethod.' = new ReflectionMethod($class, '.var_export($method, true).');';
                    $lines[] = 'if (! '.$refMethod.'->isPublic() || ! '.$refMethod.'->isStatic()) { $fail("'.$method.' is not public static"); }';
                    $lines[] = '$actual'.$index.' = $class::'.$method.'(...'.$methodArgs.');';
                } else {
                    $lines[] = $subject.' = new $class(...'.$constructorArgs.');';
                    $lines[] = 'if (! method_exists('.$subject.', '.var_export($method, true).')) { $fail("'.$method.' missing"); }';
                    $lines[] = $refMethod.' = new ReflectionMethod('.$subject.', '.var_export($method, true).');';
                    $lines[] = 'if (! '.$refMethod.'->isPublic()) { $fail("'.$method.' is not public"); }';
                    $lines[] = '$actual'.$index.' = '.$subject.'->'.$method.'(...'.$methodArgs.');';
                }
                $lines[] = '$expected'.$index.' = '.$expected.';';
                $lines[] = 'if ($actual'.$index.' !== $expected'.$index.') { $fail("'.$method.' returned ".var_export($actual'.$index.', true)." expected ".var_export($expected'.$index.', true)); }';
                $lines[] = '';
            }
        }

        $lines[] = 'echo "intent verifier ok";';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return list<string>
     */
    public function commandOutputAssertionLines(int $index, string $prefix, array $atom): array
    {
        $command = var_export((string) ($atom['command'] ?? ''), true);
        $expectedExit = (int) ($atom['exit_code'] ?? 0);
        $contains = is_string($atom['output_contains'] ?? null) ? var_export((string) $atom['output_contains'], true) : 'null';
        $exact = is_string($atom['output_exact'] ?? null) ? var_export((string) $atom['output_exact'], true) : 'null';

        return [
            '$process'.$index.' = Symfony\\Component\\Process\\Process::fromShellCommandline('.$command.', __DIR__.\'/'.$prefix.'\');',
            '$process'.$index.'->setTimeout(120.0);',
            '$process'.$index.'->run();',
            'if (($process'.$index.'->getExitCode() ?? 1) !== '.$expectedExit.') { $fail("command exit mismatch: ".($process'.$index.'->getExitCode() ?? 1)." stderr ".$process'.$index.'->getErrorOutput()); }',
            '$stdout'.$index.' = trim($process'.$index.'->getOutput());',
            '$contains'.$index.' = '.$contains.';',
            '$exact'.$index.' = '.$exact.';',
            'if ($exact'.$index.' !== null && $stdout'.$index.' !== $exact'.$index.') { $fail("command stdout exact mismatch: ".$stdout'.$index.'); }',
            'if ($contains'.$index.' !== null && ! str_contains($stdout'.$index.', $contains'.$index.')) { $fail("command stdout missing: ".$contains'.$index.'." in ".$stdout'.$index.'); }',
            '',
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return list<string>
     */
    public function eventDispatchedAssertionLines(int $index, array $atom): array
    {
        $event = var_export((string) ($atom['event_class'] ?? ''), true);
        $trigger = is_array($atom['trigger'] ?? null) ? $atom['trigger'] : [];
        $lines = [
            'Illuminate\\Support\\Facades\\Event::fake();',
        ];

        $lines = array_merge($lines, $this->eventTriggerLines($index, $trigger));
        $lines[] = 'try {';
        $lines[] = '    Illuminate\\Support\\Facades\\Event::assertDispatched('.$event.');';
        $lines[] = '} catch (Throwable $e) {';
        $lines[] = '    $fail("event not dispatched: '.str_replace('"', '\"', (string) ($atom['event_class'] ?? '')).' ".$e->getMessage());';
        $lines[] = '}';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return list<string>
     */
    public function jobDispatchedAssertionLines(int $index, array $atom): array
    {
        $job = var_export(ltrim((string) ($atom['job_class'] ?? ''), '\\'), true);
        $trigger = is_array($atom['trigger'] ?? null) ? $atom['trigger'] : [];
        $lines = [
            'Illuminate\\Support\\Facades\\Queue::fake();',
        ];

        $lines = array_merge($lines, $this->eventTriggerLines($index, $trigger));
        $lines[] = 'try {';
        $lines[] = '    Illuminate\\Support\\Facades\\Queue::assertPushed('.$job.');';
        $lines[] = '} catch (Throwable $e) {';
        $lines[] = '    $fail("job not dispatched: '.str_replace('"', '\"', (string) ($atom['job_class'] ?? '')).' ".$e->getMessage());';
        $lines[] = '}';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return list<string>
     */
    public function dbStateAssertionLines(int $index, array $atom): array
    {
        $setupSql = var_export(AiStringListNormalizer::trimmedStrings($atom['setup_sql'] ?? []), true);
        $table = var_export((string) ($atom['table'] ?? ''), true);
        $where = var_export(is_array($atom['where'] ?? null) ? $atom['where'] : [], true);
        $expected = (int) ($atom['expected_count'] ?? 1);
        $operator = var_export($this->normalizeCountOperator((string) ($atom['count_operator'] ?? '>=')), true);
        $trigger = is_array($atom['trigger'] ?? null) ? $atom['trigger'] : [];
        $lines = [
            '$setupSql'.$index.' = '.$setupSql.';',
            'foreach ($setupSql'.$index.' as $sql'.$index.') { Illuminate\\Support\\Facades\\DB::statement($sql'.$index.'); }',
        ];
        $lines = array_merge($lines, $this->eventTriggerLines($index, $trigger));
        $lines[] = '$query'.$index.' = Illuminate\\Support\\Facades\\DB::table('.$table.');';
        $lines[] = '$where'.$index.' = '.$where.';';
        $lines[] = 'foreach ($where'.$index.' as $column'.$index.' => $value'.$index.') { $query'.$index.'->where($column'.$index.', $value'.$index.'); }';
        $lines[] = '$actualDbCount'.$index.' = (int) $query'.$index.'->count();';
        $lines[] = '$expectedDbCount'.$index.' = '.$expected.';';
        $lines[] = '$dbCountOperator'.$index.' = '.$operator.';';
        $lines[] = '$dbCountOk'.$index.' = match ($dbCountOperator'.$index.') { "=" => $actualDbCount'.$index.' === $expectedDbCount'.$index.', ">=" => $actualDbCount'.$index.' >= $expectedDbCount'.$index.', "<=" => $actualDbCount'.$index.' <= $expectedDbCount'.$index.', ">" => $actualDbCount'.$index.' > $expectedDbCount'.$index.', "<" => $actualDbCount'.$index.' < $expectedDbCount'.$index.', default => false };';
        $lines[] = 'if (! $dbCountOk'.$index.') { $fail("db_state count mismatch: ".$actualDbCount'.$index.'." ".$dbCountOperator'.$index.'." ".$expectedDbCount'.$index.'); }';
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $trigger
     * @return list<string>
     */
    public function eventTriggerLines(int $index, array $trigger): array
    {
        $type = (string) ($trigger['type'] ?? 'method_call');
        if ($type === 'http_request') {
            $method = var_export((string) ($trigger['method'] ?? 'GET'), true);
            $path = var_export((string) ($trigger['path'] ?? '/'), true);
            $status = (int) ($trigger['status'] ?? 200);

            return [
                'if (! isset($app) || ! is_object($app) || ! method_exists($app, "make")) { $fail("laravel app missing for event http trigger"); }',
                '$kernelEvent'.$index.' = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);',
                '$requestEvent'.$index.' = Illuminate\\Http\\Request::create('.$path.', '.$method.', [], [], [], ["HTTP_ACCEPT" => "application/json"]);',
                '$responseEvent'.$index.' = $kernelEvent'.$index.'->handle($requestEvent'.$index.');',
                '$actualEventStatus'.$index.' = $responseEvent'.$index.'->getStatusCode();',
                '$bodyEvent'.$index.' = trim((string) $responseEvent'.$index.'->getContent());',
                '$kernelEvent'.$index.'->terminate($requestEvent'.$index.', $responseEvent'.$index.');',
                'if ($actualEventStatus'.$index.' !== '.$status.') { $fail("event http status mismatch: ".$actualEventStatus'.$index.'." body ".$bodyEvent'.$index.'); }',
            ];
        }
        if ($type === 'artisan_call') {
            $command = var_export((string) ($trigger['command'] ?? ''), true);
            $parameters = var_export(is_array($trigger['parameters'] ?? null) ? $trigger['parameters'] : [], true);
            $exit = (int) ($trigger['exit_code'] ?? 0);

            return [
                'if (! isset($app) || ! is_object($app) || ! method_exists($app, "make")) { $fail("laravel app missing for event artisan trigger"); }',
                '$artisanExit'.$index.' = Illuminate\\Support\\Facades\\Artisan::call('.$command.', '.$parameters.');',
                'if ($artisanExit'.$index.' !== '.$exit.') { $fail("event artisan exit mismatch: ".$artisanExit'.$index.'); }',
            ];
        }

        $constructorArgs = var_export($trigger['constructor_args'] ?? [], true);
        $methodArgs = var_export($trigger['method_args'] ?? [], true);
        $method = (string) ($trigger['method'] ?? '');
        $subject = '$eventSubject'.$index;
        $refMethod = '$eventMethod'.$index;
        if ((bool) ($trigger['static'] ?? false)) {
            return [
                '$eventClassRef'.$index.' = new ReflectionClass($class);',
                '$eventMethodName'.$index.' = '.var_export($method, true).';',
                'if (! $eventClassRef'.$index.'->hasMethod($eventMethodName'.$index.')) { $fail($eventMethodName'.$index.'." missing"); }',
                $refMethod.' = new ReflectionMethod($class, $eventMethodName'.$index.');',
                'if (! '.$refMethod.'->isPublic() || ! '.$refMethod.'->isStatic()) { $fail($eventMethodName'.$index.'." is not public static"); }',
                '$class::'.$method.'(...'.$methodArgs.');',
            ];
        }

        return [
            $subject.' = new $class(...'.$constructorArgs.');',
            '$eventMethodName'.$index.' = '.var_export($method, true).';',
            'if (! method_exists('.$subject.', $eventMethodName'.$index.')) { $fail($eventMethodName'.$index.'." missing"); }',
            $refMethod.' = new ReflectionMethod('.$subject.', $eventMethodName'.$index.');',
            'if (! '.$refMethod.'->isPublic()) { $fail($eventMethodName'.$index.'." is not public"); }',
            $subject.'->'.$method.'(...'.$methodArgs.');',
        ];
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return list<string>
     */
    public function httpResponseAssertionLines(int $index, array $atom): array
    {
        $method = var_export((string) ($atom['method'] ?? 'GET'), true);
        $path = var_export((string) ($atom['path'] ?? '/'), true);
        $status = (int) ($atom['status'] ?? 200);
        $contains = is_string($atom['body_contains'] ?? null) ? var_export((string) $atom['body_contains'], true) : 'null';
        $exact = is_string($atom['body_exact'] ?? null) ? var_export((string) $atom['body_exact'], true) : 'null';

        return [
            'if (! isset($app) || ! is_object($app) || ! method_exists($app, "make")) { $fail("laravel app missing for http_response"); }',
            '$kernel'.$index.' = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);',
            '$request'.$index.' = Illuminate\\Http\\Request::create('.$path.', '.$method.', [], [], [], ["HTTP_ACCEPT" => "application/json"]);',
            '$response'.$index.' = $kernel'.$index.'->handle($request'.$index.');',
            '$actualStatus'.$index.' = $response'.$index.'->getStatusCode();',
            '$body'.$index.' = trim((string) $response'.$index.'->getContent());',
            '$kernel'.$index.'->terminate($request'.$index.', $response'.$index.');',
            'if ($actualStatus'.$index.' !== '.$status.') { $fail("http status mismatch: ".$actualStatus'.$index.'." body ".$body'.$index.'); }',
            '$contains'.$index.' = '.$contains.';',
            '$exact'.$index.' = '.$exact.';',
            'if ($exact'.$index.' !== null && $body'.$index.' !== $exact'.$index.') { $fail("http body exact mismatch: ".$body'.$index.'); }',
            'if ($contains'.$index.' !== null && ! str_contains($body'.$index.', $contains'.$index.')) { $fail("http body missing: ".$contains'.$index.'." in ".$body'.$index.'); }',
            '',
        ];
    }

    public function relativePrefix(string $dir): string
    {
        $dir = trim($dir, '/');
        if ($dir === '' || $dir === '.') {
            return '';
        }
        $depth = count(array_filter(explode('/', $dir), static fn (string $s): bool => $s !== ''));

        return str_repeat('../', $depth);
    }

    public function normalizeCountOperator(string $operator): string
    {
        return in_array($operator, ['=', '>=', '<=', '>', '<'], true) ? $operator : '>=';
    }
}
