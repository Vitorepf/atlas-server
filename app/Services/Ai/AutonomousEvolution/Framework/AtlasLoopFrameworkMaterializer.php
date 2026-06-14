<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Framework;

use App\Services\Ai\Support\AiStringListNormalizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Materializes a FRAMEWORK-COUPLED (stage-1) target into a real, bootable
 * worktree so a PHPUnit test that boots the Laravel app can pin the improved behavior —
 * the extension beyond self-contained pure-PHP targets.
 *
 * The load-bearing correctness property (verified): the worktree's OWN autoloader must
 * resolve the EDITED target file, never the canonical repo's copy. With an optimized
 * (classmap) autoloader a naive worktree + symlinked vendor would silently run the
 * CANONICAL class (fake-green). The recipe below regenerates the autoloader INSIDE the
 * worktree (real-copied vendor/composer + vendor/bin) so App\ resolves to the worktree's
 * app/, and asserts it at materialize time via ReflectionClass — a loud early abort, not
 * a silent wrong-file run. The canonical repo's autoloader is never touched.
 *
 * Stage-1 is intentionally narrow: it proves git worktree + local autoload + hermetic
 * test env + target frozen tests. Stateful DB targets still need a later fresh-schema
 * strategy, but this materializer never points at the operator's Postgres.
 */
final class AtlasLoopFrameworkMaterializer
{
    public const SCHEMA = 'atlas.loop.framework_materialization.v1';

    /**
     * @param  array<string,mixed>  $payload  { target_relative_path, frozen_tests:[{path,content}], acceptance, support_files? }
     * @return array{0: array<string,mixed>, 1: callable}  [explorerTask, cleanup]
     */
    public function materializeBase(string $canonicalRepoRoot, string $objective, array $payload): array
    {
        $canonical = rtrim($canonicalRepoRoot, '/');
        $targetRel = $this->normalizeRelative((string) ($payload['target_relative_path'] ?? ''));
        $acceptance = is_array($payload['acceptance'] ?? null) ? $payload['acceptance'] : [];
        if ($targetRel === '' || ! is_file($canonical.'/'.$targetRel) || ($acceptance['commands'] ?? []) === []) {
            throw new RuntimeException('framework materialize: payload requires target_relative_path (existing) + acceptance.commands');
        }

        $base = sys_get_temp_dir().'/atlas-loop-fw-'.bin2hex(random_bytes(5));
        $cleanup = function () use ($canonical, $base): void {
            try {
                (new Process(['git', '-C', $canonical, 'worktree', 'remove', '--force', $base]))->setTimeout(60.0)->run();
                (new Process(['git', '-C', $canonical, 'worktree', 'prune']))->setTimeout(30.0)->run();
            } catch (Throwable) {
                // ignore
            }
            if (is_dir($base)) {
                (new Process(['rm', '-rf', $base]))->run();
            }
        };

        try {
            // 1. A detached worktree off HEAD — shares .git, checks out tracked files only (no vendor).
            $this->git(['-C', $canonical, 'worktree', 'add', '--detach', $base, 'HEAD'], 'worktree_add');

            // 2. Provision vendor: symlink the heavy packages read-only; REAL-copy autoload.php +
            //    composer/ + bin/ so the worktree's autoloader/phpunit bind to the worktree.
            $this->provisionVendor($canonical, $base);

            // 3. Regenerate the autoloader INSIDE the worktree (resolves App\ -> worktree app/).
            $dump = new Process(['composer', 'dump-autoload', '--no-scripts', '--quiet', '-d', $base], null, null, null, 180.0);
            $dump->run();
            if (! $dump->isSuccessful()) {
                throw new RuntimeException('framework materialize: composer dump-autoload failed in worktree: '.mb_substr($dump->getErrorOutput(), 0, 200));
            }

            // 4. Provision env (APP_KEY etc. so artisan/app boots); .env.testing is
            //    hermetic and points DB work at sqlite :memory:, never the operator DB.
            if (is_file($canonical.'/.env')) {
                copy($canonical.'/.env', $base.'/.env');
            }
            $this->writeHermeticTestingEnv($canonical, $base);
            $this->ensureLaravelWritableDirs($base);

            // 5. Write the frozen PHPUnit test(s) + any support files into the worktree.
            foreach ((is_array($payload['frozen_tests'] ?? null) ? $payload['frozen_tests'] : []) as $test) {
                if (is_array($test) && is_string($test['path'] ?? null) && is_string($test['content'] ?? null)) {
                    $this->writeFile($base, $this->normalizeRelative($test['path']), $test['content']);
                }
            }
            foreach ((is_array($payload['support_files'] ?? null) ? $payload['support_files'] : []) as $file) {
                if (is_array($file) && is_string($file['path'] ?? null) && is_string($file['content'] ?? null)) {
                    $this->writeFile($base, $this->normalizeRelative($file['path']), $file['content']);
                }
            }

            // 6. Reproducibility pin: the worktree target must be byte-identical to canonical HEAD.
            if (hash_file('sha256', $base.'/'.$targetRel) !== hash_file('sha256', $canonical.'/'.$targetRel)) {
                throw new RuntimeException('framework materialize: target drifted between canonical and worktree');
            }

            // 7. VERIFY-AT-MATERIALIZE (the silent-failure killer): the worktree autoloader MUST
            //    resolve the target class to the worktree file, not the canonical one.
            $this->assertResolvesInsideWorktree($base, $targetRel);

            // 8. Baseline commit on the detached HEAD (vendor + .env are gitignored -> only the test shows).
            $this->git(['-C', $base, 'add', '-A'], 'baseline_add');
            if ($this->hasStagedChanges($base)) {
                $this->git(['-C', $base, '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'scenario baseline'], 'baseline_commit');
            }
        } catch (Throwable $e) {
            $cleanup();
            throw $e;
        }

        // Anti-fake proof: for a NEW-behavior framework target, revert_recheck=true proves the
        // diff earned the green (reverting it turns the test RED). A behavior-PRESERVING refactor
        // (objective_kind=refactor_*, complexity_proof) would stay GREEN with the diff reverted by
        // design — diff-earned does NOT apply — so its anti-fake proof is the AST complexity DROP
        // enforced by the certifier instead. Keep revert_recheck OFF for refactor contracts; never
        // weaken it for the (default) new-behavior path.
        $isRefactor = str_starts_with(trim((string) ($payload['objective_kind'] ?? '')), 'refactor_')
            && (bool) ($acceptance['complexity_proof'] ?? false);
        $explorerTask = [
            'objective' => $objective,
            'base_workspace' => $base,
            'materializer' => 'framework',
            'scenario_clone_mode' => 'worktree', // the explorer clones per-scenario worktrees, not cp -R + git init
            'acceptance' => $isRefactor ? $acceptance : array_merge($acceptance, ['revert_recheck' => true]), // anti-fake on for new-behavior framework targets
            'allowed_files' => AiStringListNormalizer::trimmedStrings($payload['allowed_files'] ?? [$targetRel]),
            'validation_commands' => AiStringListNormalizer::trimmedStrings($payload['validation_commands'] ?? []),
        ];
        $provider = trim((string) ($payload['provider'] ?? ''));
        if ($provider !== '') {
            $explorerTask['provider'] = $provider;
        }
        foreach (['scenario_strategies', 'scenario_strategy_keys', 'min_scenarios', 'max_scenarios', 'search_patience', 'search_time_budget_seconds', 'keep_workspaces', 'workspace_root'] as $passthrough) {
            if (array_key_exists($passthrough, $payload)) {
                $explorerTask[$passthrough] = $payload[$passthrough];
            }
        }

        return [$explorerTask, $cleanup];
    }

    private function writeHermeticTestingEnv(string $canonical, string $base): void
    {
        $appKey = $this->envValue($canonical.'/.env', 'APP_KEY');
        if ($appKey === null || $appKey === '') {
            $appKey = 'base64:'.base64_encode(random_bytes(32));
        }

        $env = [
            'APP_NAME=Atlas',
            'APP_ENV=testing',
            'APP_KEY='.$appKey,
            'APP_DEBUG=true',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=:memory:',
            'CACHE_STORE=array',
            'SESSION_DRIVER=array',
            'QUEUE_CONNECTION=sync',
            'MAIL_MAILER=array',
            'BCRYPT_ROUNDS=4',
        ];

        file_put_contents($base.'/.env.testing', implode("\n", $env)."\n");
    }

    private function envValue(string $path, string $key): ?string
    {
        if (! is_file($path)) {
            return null;
        }
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return trim(substr($line, strlen($key) + 1), "\"'");
            }
        }

        return null;
    }

    private function ensureLaravelWritableDirs(string $base): void
    {
        foreach ([
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relative) {
            $dir = $base.'/'.$relative;
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    private function provisionVendor(string $canonical, string $base): void
    {
        $srcVendor = $canonical.'/vendor';
        $dstVendor = $base.'/vendor';
        if (! is_dir($srcVendor)) {
            throw new RuntimeException('framework materialize: canonical vendor/ missing — run composer install');
        }
        if (! is_dir($dstVendor) && ! mkdir($dstVendor, 0o755, true) && ! is_dir($dstVendor)) {
            throw new RuntimeException('framework materialize: cannot create worktree vendor/');
        }

        // Symlink every package dir EXCEPT composer + bin (those must be real local copies).
        foreach (scandir($srcVendor) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'composer' || $entry === 'bin' || $entry === 'autoload.php') {
                continue;
            }
            @symlink($srcVendor.'/'.$entry, $dstVendor.'/'.$entry);
        }
        // Real copies: autoload.php + composer/ (so dump-autoload writes here) + bin/ (so phpunit
        // binds to THIS worktree's autoload, avoiding a Cannot-redeclare-ComposerAutoloaderInit fatal).
        if (is_file($srcVendor.'/autoload.php')) {
            copy($srcVendor.'/autoload.php', $dstVendor.'/autoload.php');
        }
        $this->copyDir($srcVendor.'/composer', $dstVendor.'/composer');
        if (is_dir($srcVendor.'/bin')) {
            $this->copyDir($srcVendor.'/bin', $dstVendor.'/bin');
        }
    }

    private function assertResolvesInsideWorktree(string $base, string $targetRel): void
    {
        $class = $this->classFromFile($base.'/'.$targetRel);
        if ($class === null) {
            return; // no declared class to verify (defensive)
        }
        $probe = new Process([
            PHP_BINARY, '-r',
            'require "vendor/autoload.php"; $c=new ReflectionClass('.var_export($class, true).'); echo $c->getFileName();',
        ], $base, null, null, 60.0);
        $probe->run();
        $resolved = trim((string) $probe->getOutput());
        $expected = $base.'/'.$targetRel;
        if ($resolved === '' || realpath($resolved) !== realpath($expected)) {
            throw new RuntimeException('framework materialize: autoload_resolves_outside_worktree (got "'.$resolved.'", expected "'.$expected.'")');
        }
    }

    private function classFromFile(string $file): ?string
    {
        $src = (string) @file_get_contents($file);
        if (! preg_match('/^\s*namespace\s+([^;]+);/m', $src, $ns)) {
            return null;
        }
        if (! preg_match('/\b(?:final\s+|abstract\s+)*(?:class|enum|trait|interface)\s+(\w+)/', $src, $cls)) {
            return null;
        }

        return trim($ns[1]).'\\'.$cls[1];
    }

    private function copyDir(string $src, string $dst): void
    {
        if (! is_dir($src)) {
            return;
        }
        (new Process(['bash', '-lc', 'cp -R '.escapeshellarg($src).' '.escapeshellarg($dst)]))->setTimeout(120.0)->run();
    }

    /**
     * @param  list<string>  $argv  git sub-command argv (without the leading 'git')
     */
    private function git(array $argv, string $stage): void
    {
        $argv = array_values(array_filter($argv, static fn ($a): bool => $a !== ''));
        $p = new Process(array_merge(['git'], $argv), null, null, null, 180.0);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('framework materialize: git '.$stage.' failed: '.mb_substr($p->getErrorOutput() ?: $p->getOutput(), -200));
        }
    }

    private function hasStagedChanges(string $repo): bool
    {
        $process = new Process(['git', '-C', $repo, 'diff', '--cached', '--quiet', '--exit-code'], null, null, null, 30.0);
        $process->run();
        $exit = $process->getExitCode();
        if ($exit === 1) {
            return true;
        }
        if ($exit === 0) {
            return false;
        }

        throw new RuntimeException('framework materialize: git staged diff failed: '.mb_substr($process->getErrorOutput() ?: $process->getOutput(), -200));
    }

    private function writeFile(string $base, string $relative, string $content): void
    {
        $relative = $this->normalizeRelative($relative);
        if ($relative === '') {
            return;
        }
        $dir = dirname($base.'/'.$relative);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('framework materialize: cannot create dir '.$dir);
        }
        file_put_contents($base.'/'.$relative, $content);
    }

    private function normalizeRelative(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

}
