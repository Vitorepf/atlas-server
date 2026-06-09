<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compiles a narrow human intent into a frozen executable verifier packet.
 *
 * This is the step before P4 grinding: do not let a provider implement until the
 * Atlas side has a RED, sealed verifier that the candidate cannot edit.
 */
final class AtlasLoopIntentVerifierFactory
{
    public const SCHEMA = 'atlas.loop.intent_verifier_factory.v1';

    public function __construct(
        private readonly AtlasLoopFrameworkMaterializer $frameworkMaterializer,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function compileFrameworkPacket(string $repoRoot, string $intent, array $payload = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $target = $this->normalizeRelative((string) ($payload['target_relative_path'] ?? $payload['target_path'] ?? ''));
        $targetPath = $target === '' ? '' : $repoRoot.'/'.$target;
        $metadata = $targetPath !== '' && is_file($targetPath) ? $this->classMetadata($targetPath) : [
            'class' => null,
            'constructor_required_params' => 0,
        ];

        $atoms = $this->verificationAtoms($payload, $intent);
        $blockers = $this->compileBlockers($repoRoot, $intent, $target, $metadata, $atoms, $payload);
        $testPath = (string) ($payload['frozen_test_path'] ?? $this->defaultTestPath($target, $intent, $atoms));

        $packet = [
            'schema_version' => self::SCHEMA,
            'status' => 'blocked',
            'ready' => false,
            'intent' => $intent,
            'target_relative_path' => $target,
            'target_class' => $metadata['class'],
            'verification_atoms' => $atoms,
            'blockers' => $blockers,
            'critic' => [
                'schema_version' => self::SCHEMA.'.critic.v1',
                'status' => $blockers === [] ? 'clean' : 'blocked',
                'blocking_issue_count' => count($blockers),
            ],
            'proposal_only' => true,
            'merged_to_main' => false,
        ];

        if ($blockers !== []) {
            return $packet;
        }

        $class = (string) $metadata['class'];
        $timeout = max(1, (int) ($payload['timeout_seconds'] ?? data_get($payload, 'acceptance.timeout_seconds', 120)));
        $testContent = $this->frozenTestContent($testPath, $target, $class, $atoms);
        $acceptanceCommand = 'php '.$testPath;
        $acceptance = [
            'commands' => [$acceptanceCommand],
            'allowed_globs' => $this->allowedFiles($payload, $target),
            'frozen_globs' => [$testPath],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            'revert_recheck' => true,
            'timeout_seconds' => $timeout,
        ];
        $sealedHoldouts = array_values(array_unique(array_merge(
            $this->stringList($payload['sealed_holdout_commands'] ?? []),
            [
                'php -l '.escapeshellarg($target),
                'php -r '.escapeshellarg("require 'vendor/autoload.php'; exit(class_exists(".var_export($class, true).") ? 0 : 1);"),
            ],
        )));

        $compiledPayload = array_merge($payload, [
            'materializer' => 'framework',
            'target_relative_path' => $target,
            'frozen_tests' => [[
                'path' => $testPath,
                'content' => $testContent,
            ]],
            'acceptance' => $acceptance,
            'allowed_files' => $this->allowedFiles($payload, $target),
            'validation_commands' => [$acceptanceCommand],
            'sealed_holdout_commands' => $sealedHoldouts,
            'intent_verifier_factory_compiled' => true,
            'intent_verifier_factory_hash' => '',
        ]);
        $compiledPayload['intent_verifier_factory_hash'] = $this->stableHash([
            'intent' => $intent,
            'target' => $target,
            'atoms' => $atoms,
            'acceptance' => $acceptance,
            'test_content' => $testContent,
        ]);

        $packet = array_merge($packet, [
            'status' => 'ready',
            'ready' => true,
            'verifier_hash' => $compiledPayload['intent_verifier_factory_hash'],
            'frozen_tests' => $compiledPayload['frozen_tests'],
            'acceptance' => $acceptance,
            'sealed_holdout_commands' => $sealedHoldouts,
            'task_payload' => $compiledPayload,
            'red_preflight' => null,
            'verifier_refuters' => null,
        ]);

        if ((bool) ($payload['intent_verifier_preflight'] ?? true)) {
            $packet['red_preflight'] = $this->redPreflight($repoRoot, $intent, $compiledPayload);
            if (($packet['red_preflight']['status'] ?? null) !== 'red') {
                $packet['status'] = 'blocked';
                $packet['ready'] = false;
                $packet['blockers'] = array_values(array_unique(array_merge(
                    $blockers,
                    ['compiled_verifier_not_red_on_baseline'],
                )));
            }
        }

        $packet['verifier_refuters'] = $this->runVerifierRefuters($repoRoot, $packet, $payload);
        if (! (bool) data_get($packet, 'verifier_refuters.met_required', true) || (int) data_get($packet, 'verifier_refuters.refuted', 0) > 0) {
            $packet['status'] = 'blocked';
            $packet['ready'] = false;
            $packet['blockers'] = array_values(array_unique(array_merge(
                (array) ($packet['blockers'] ?? []),
                ['verifier_refuted_or_missing_required_refuter'],
            )));
        }

        return $packet;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function taskPayloadOrFail(array $packet): array
    {
        if (! (bool) ($packet['ready'] ?? false) || ! is_array($packet['task_payload'] ?? null)) {
            throw new RuntimeException('intent verifier factory blocked: '.implode(',', (array) ($packet['blockers'] ?? ['unknown'])));
        }

        return $packet['task_payload'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array{class:?string,constructor_required_params:int}  $metadata
     * @param  list<array<string,mixed>>  $atoms
     * @return list<string>
     */
    private function compileBlockers(string $repoRoot, string $intent, string $target, array $metadata, array $atoms, array $payload): array
    {
        $blockers = [];
        if ($repoRoot === '' || ! is_dir($repoRoot.'/.git')) {
            $blockers[] = 'repo_root_not_git';
        }
        if (trim($intent) === '') {
            $blockers[] = 'intent_missing';
        }
        if ($target === '' || ! is_file($repoRoot.'/'.$target)) {
            $blockers[] = 'target_relative_path_missing_or_not_found';
        }
        if (! is_string($metadata['class'] ?? null) || $metadata['class'] === '') {
            $blockers[] = 'target_class_not_resolved';
        }
        $constructorArgs = $this->literalList($payload['constructor_args'] ?? []);
        if ((int) ($metadata['constructor_required_params'] ?? 0) > count($constructorArgs)) {
            $blockers[] = 'constructor_requires_explicit_args';
        }
        if ($atoms === []) {
            $blockers[] = 'no_executable_verification_atom';
        }
        foreach ($atoms as $atom) {
            $type = (string) ($atom['type'] ?? '');
            if ($type === 'method_return' && is_string($atom['method'] ?? null) && $atom['method'] !== '') {
                continue;
            }
            if ($type === 'command_output' && is_string($atom['command'] ?? null) && $atom['command'] !== '') {
                continue;
            }
            if ($type === 'http_response' && is_string($atom['path'] ?? null) && $atom['path'] !== '') {
                continue;
            }
            if (! in_array($type, ['method_return', 'command_output', 'http_response'], true)) {
                $blockers[] = 'unsupported_or_incomplete_verification_atom';
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function verificationAtoms(array $payload, string $intent): array
    {
        $atoms = [];
        foreach (is_array($payload['verification_atoms'] ?? null) ? $payload['verification_atoms'] : [] as $atom) {
            if (is_array($atom)) {
                $normalized = $this->normalizeAtom($atom);
                if ($normalized !== null) {
                    $atoms[] = $normalized;
                }
            }
        }

        $method = trim((string) ($payload['method'] ?? $payload['expected_method'] ?? ''));
        if ($method !== '' && array_key_exists('returns', $payload)) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'method_return',
                'method' => $method,
                'expected' => $payload['returns'],
                'constructor_args' => $payload['constructor_args'] ?? [],
                'method_args' => $payload['method_args'] ?? [],
            ]);
        }
        $command = trim((string) ($payload['command'] ?? $payload['expected_command'] ?? ''));
        if ($command !== '' && (array_key_exists('output_contains', $payload) || array_key_exists('output_exact', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'command_output',
                'command' => $command,
                'output_contains' => $payload['output_contains'] ?? null,
                'output_exact' => $payload['output_exact'] ?? null,
                'exit_code' => $payload['exit_code'] ?? 0,
            ]);
        }
        $httpPath = trim((string) ($payload['http_path'] ?? ''));
        if ($httpPath !== '' && (array_key_exists('http_body_contains', $payload) || array_key_exists('http_body_exact', $payload) || array_key_exists('http_status', $payload))) {
            $atoms[] = $this->normalizeAtom([
                'type' => 'http_response',
                'method' => $payload['http_method'] ?? 'GET',
                'path' => $httpPath,
                'status' => $payload['http_status'] ?? 200,
                'body_contains' => $payload['http_body_contains'] ?? null,
                'body_exact' => $payload['http_body_exact'] ?? null,
            ]);
        }

        if ($atoms === []) {
            $inferred = $this->inferMethodReturnAtom($intent) ?? $this->inferCommandOutputAtom($intent);
            if ($inferred !== null) {
                $atoms[] = $inferred;
            }
        }

        return array_values(array_filter($atoms));
    }

    /**
     * @param  array<string,mixed>  $atom
     * @return array<string,mixed>|null
     */
    private function normalizeAtom(array $atom): ?array
    {
        $type = (string) ($atom['type'] ?? 'method_return');
        if ($type === 'command_output') {
            $command = trim((string) ($atom['command'] ?? ''));
            if ($command === '') {
                return null;
            }
            $contains = array_key_exists('output_contains', $atom) ? $this->literal($atom['output_contains']) : null;
            $exact = array_key_exists('output_exact', $atom) ? $this->literal($atom['output_exact']) : null;
            if (! is_string($contains) && ! is_string($exact)) {
                return null;
            }

            return [
                'type' => 'command_output',
                'command' => $command,
                'output_contains' => is_string($contains) ? $contains : null,
                'output_exact' => is_string($exact) ? $exact : null,
                'exit_code' => is_numeric($atom['exit_code'] ?? null) ? (int) $atom['exit_code'] : 0,
            ];
        }
        if ($type === 'http_response') {
            $path = trim((string) ($atom['path'] ?? ''));
            if ($path === '' || ! str_starts_with($path, '/')) {
                return null;
            }
            $contains = array_key_exists('body_contains', $atom) ? $this->literal($atom['body_contains']) : null;
            $exact = array_key_exists('body_exact', $atom) ? $this->literal($atom['body_exact']) : null;
            if (! is_string($contains) && ! is_string($exact)) {
                return null;
            }

            return [
                'type' => 'http_response',
                'method' => strtoupper(trim((string) ($atom['method'] ?? 'GET'))) ?: 'GET',
                'path' => $path,
                'status' => is_numeric($atom['status'] ?? null) ? (int) $atom['status'] : 200,
                'body_contains' => is_string($contains) ? $contains : null,
                'body_exact' => is_string($exact) ? $exact : null,
            ];
        }

        $method = trim((string) ($atom['method'] ?? ''));
        if ($method === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            return null;
        }

        return [
            'type' => 'method_return',
            'method' => $method,
            'expected' => $this->literal($atom['expected'] ?? null),
            'constructor_args' => $this->literalList($atom['constructor_args'] ?? []),
            'method_args' => $this->literalList($atom['method_args'] ?? []),
            'static' => (bool) ($atom['static'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inferMethodReturnAtom(string $intent): ?array
    {
        $quoted = '(?:"([^"]*)"|\'([^\']*)\'|`([^`]*)`)';
        $bare = '([A-Za-z0-9_.:\\/-]+|true|false|null)';
        $value = '(?:'.$quoted.'|'.$bare.')';
        $patterns = [
            '/\b(?:method|metodo|m[eé]todo)\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(\))?\s+(?:returns?|returning|retorna|retorne|deve retornar|should return)\s+'.$value.'/iu',
            '/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(\)\s+(?:returns?|retorna|deve retornar|should return)\s+'.$value.'/iu',
            '/\b(?:add|create|adicionar|adicione|criar|crie)\b.*?\b([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(\)|method|m[eé]todo)?\b.*?\b(?:returns?|returning|retorna|retorne|retornar)\s+'.$value.'/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $intent, $m) === 1) {
                $method = (string) $m[1];
                $raw = null;
                for ($i = 2; $i <= 5; $i++) {
                    if (array_key_exists($i, $m) && $m[$i] !== '') {
                        $raw = $m[$i];
                        break;
                    }
                }

                return $this->normalizeAtom([
                    'type' => 'method_return',
                    'method' => $method,
                    'expected' => $this->literal($raw),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inferCommandOutputAtom(string $intent): ?array
    {
        $pattern = '/\b(?:command|comando)\s+(?:"([^"]+)"|`([^`]+)`|\'([^\']+)\')\s+(?:outputs?|imprime|deve imprimir|should output)\s+(?:"([^"]*)"|`([^`]*)`|\'([^\']*)\')/iu';
        if (preg_match($pattern, $intent, $m) !== 1) {
            return null;
        }
        $command = null;
        for ($i = 1; $i <= 3; $i++) {
            if (array_key_exists($i, $m) && $m[$i] !== '') {
                $command = $m[$i];
                break;
            }
        }
        $expected = null;
        for ($i = 4; $i <= 6; $i++) {
            if (array_key_exists($i, $m) && $m[$i] !== '') {
                $expected = $m[$i];
                break;
            }
        }
        if (! is_string($command) || ! is_string($expected)) {
            return null;
        }

        return $this->normalizeAtom([
            'type' => 'command_output',
            'command' => $command,
            'output_contains' => $expected,
            'exit_code' => 0,
        ]);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function literal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if ((str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
            || (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, '`') && str_ends_with($trimmed, '`'))) {
            return substr($trimmed, 1, -1);
        }
        $lower = strtolower($trimmed);

        return match (true) {
            $lower === 'true' => true,
            $lower === 'false' => false,
            $lower === 'null' => null,
            preg_match('/^-?\d+$/', $trimmed) === 1 => (int) $trimmed,
            preg_match('/^-?\d+\.\d+$/', $trimmed) === 1 => (float) $trimmed,
            default => $trimmed,
        };
    }

    /**
     * @param  mixed  $values
     * @return list<mixed>
     */
    private function literalList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(fn (mixed $v): mixed => $this->literal($v), $values));
    }

    /**
     * @return array{class:?string,constructor_required_params:int}
     */
    private function classMetadata(string $file): array
    {
        $src = (string) @file_get_contents($file);
        $parser = (new ParserFactory)->createForHostVersion();
        $finder = new NodeFinder;
        try {
            $stmts = $parser->parse($src) ?? [];
        } catch (Throwable) {
            return ['class' => null, 'constructor_required_params' => 0];
        }

        $namespace = null;
        $ns = $finder->findFirstInstanceOf($stmts, Node\Stmt\Namespace_::class);
        if ($ns instanceof Node\Stmt\Namespace_ && $ns->name !== null) {
            $namespace = $ns->name->toString();
        }
        $class = $finder->findFirstInstanceOf($stmts, Node\Stmt\Class_::class);
        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return ['class' => null, 'constructor_required_params' => 0];
        }

        $required = 0;
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() !== '__construct') {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param->default === null && ! $param->variadic) {
                    $required++;
                }
            }
        }

        return [
            'class' => ($namespace !== null ? $namespace.'\\' : '').$class->name->toString(),
            'constructor_required_params' => $required,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $atoms
     */
    private function frozenTestContent(string $testPath, string $target, string $class, array $atoms): string
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
    private function commandOutputAssertionLines(int $index, string $prefix, array $atom): array
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
    private function httpResponseAssertionLines(int $index, array $atom): array
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

    private function relativePrefix(string $dir): string
    {
        $dir = trim($dir, '/');
        if ($dir === '' || $dir === '.') {
            return '';
        }
        $depth = count(array_filter(explode('/', $dir), static fn (string $s): bool => $s !== ''));

        return str_repeat('../', $depth);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function allowedFiles(array $payload, string $target): array
    {
        $files = $this->stringList($payload['allowed_files'] ?? []);
        if ($files === []) {
            $files = [$target];
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string,mixed>  $compiledPayload
     * @return array<string,mixed>
     */
    private function redPreflight(string $repoRoot, string $intent, array $compiledPayload): array
    {
        try {
            [$task, $cleanup] = $this->frameworkMaterializer->materializeBase($repoRoot, $intent, $compiledPayload);
            $workspace = (string) ($task['base_workspace'] ?? '');
            $results = [];
            $allGreen = true;
            foreach ($this->stringList(data_get($compiledPayload, 'acceptance.commands', [])) as $command) {
                $result = $this->runCommand($command, $workspace, (int) data_get($compiledPayload, 'acceptance.timeout_seconds', 120));
                $results[] = $result;
                if (! (bool) ($result['passed'] ?? false)) {
                    $allGreen = false;
                    break;
                }
            }
            $cleanup();

            return [
                'schema_version' => self::SCHEMA.'.red_preflight.v1',
                'status' => $allGreen ? 'green_on_baseline' : 'red',
                'baseline_red' => ! $allGreen,
                'command_results' => $results,
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => self::SCHEMA.'.red_preflight.v1',
                'status' => 'blocked',
                'baseline_red' => false,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function runVerifierRefuters(string $repoRoot, array $packet, array $payload): array
    {
        $commands = array_values(array_unique(array_merge(
            $this->stringList($payload['verifier_refuter_commands'] ?? []),
            $this->stringList($payload['spec_refuter_commands'] ?? []),
        )));
        $required = $this->requiredRefuters($payload, count($commands));
        $timeout = max(1, (int) ($payload['verifier_refuter_timeout_seconds'] ?? 120));
        $packetPath = tempnam(sys_get_temp_dir(), 'atlas-intent-verifier-');
        if ($packetPath === false) {
            throw new RuntimeException('intent verifier factory: cannot create refuter packet');
        }

        file_put_contents($packetPath, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $verdicts = [];
        try {
            foreach ($commands as $index => $command) {
                $process = Process::fromShellCommandline($command, $repoRoot, [
                    'ATLAS_INTENT_VERIFIER_PACKET' => $packetPath,
                    'ATLAS_INTENT_VERIFIER_REFUTER_INDEX' => (string) ($index + 1),
                ], null, (float) $timeout);
                $process->run();
                $verdicts[] = $this->refuterVerdict($index + 1, $command, $process);
            }
        } finally {
            @unlink($packetPath);
        }

        $refuted = count(array_filter($verdicts, static fn (array $v): bool => (bool) ($v['refuted'] ?? false)));

        return [
            'schema_version' => self::SCHEMA.'.verifier_refuters.v1',
            'required' => $required,
            'configured' => count($commands),
            'executed' => count($verdicts),
            'met_required' => count($verdicts) >= $required,
            'refuted' => $refuted,
            'verdicts' => $verdicts,
            'fail_closed' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function requiredRefuters(array $payload, int $configured): int
    {
        foreach (['verifier_refuters_required', 'spec_refuters_required'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }

        return $configured;
    }

    /**
     * @return array<string,mixed>
     */
    private function refuterVerdict(int $index, string $command, Process $process): array
    {
        $stdout = trim((string) $process->getOutput());
        $stderr = trim((string) $process->getErrorOutput());
        $json = $stdout !== '' ? json_decode($stdout, true) : null;
        $parsed = is_array($json);
        $exit = $process->getExitCode() ?? 1;
        $refuted = $exit !== 0;
        $reason = $refuted ? 'command_exit_'.$exit : 'no_refutation';
        if ($parsed) {
            $refuted = (bool) ($json['refuted'] ?? $refuted);
            $reason = trim((string) ($json['reason'] ?? $json['detail'] ?? $reason)) ?: $reason;
        }

        return [
            'index' => $index,
            'command' => $command,
            'exit_code' => $exit,
            'refuted' => $refuted,
            'reason' => $reason,
            'stdout' => $this->excerpt($stdout),
            'stderr' => $this->excerpt($stderr),
            'parsed_json' => $parsed,
        ];
    }

    /**
     * @return array{command:string,passed:bool,exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(string $command, string $workspace, int $timeout): array
    {
        $process = Process::fromShellCommandline($command, $workspace, null, null, (float) $timeout);
        $process->run();

        return [
            'command' => $command,
            'passed' => $process->isSuccessful(),
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $this->excerpt($process->getOutput()),
            'stderr' => $this->excerpt($process->getErrorOutput()),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($value) ? $value : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    private function defaultTestPath(string $target, string $intent, array $atoms): string
    {
        $hash = substr($this->stableHash([$target, $intent, $atoms]), 0, 12);

        return 'tests/Feature/Loop/IntentVerifier/'.$hash.'.php';
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'...' : $text;
    }
}
