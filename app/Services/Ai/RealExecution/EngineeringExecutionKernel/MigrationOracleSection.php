<?php

namespace App\Services\Ai\RealExecution\EngineeringExecutionKernel;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;

class MigrationOracleSection
{
    /** @param array<string,string> $sources @return array<string,mixed> */
    public function runIsolatedMigrationOracle(array $sources, bool $staticSafe, string $sandbox, string $caseHash): array
    {
        if (! $staticSafe || ! extension_loaded('pdo_sqlite')) {
            return ['status' => $staticSafe ? 'unavailable' : 'static_refused', 'forward_passed' => false,
                'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $trustedTempRoot = '/private/tmp';
        $oracleRoot = $trustedTempRoot.'/atlas-owned-migration-oracle-'.bin2hex(random_bytes(16));
        if (! mkdir($oracleRoot, 0700) || is_link($oracleRoot)) {
            return ['status' => 'oracle_root_invalid', 'forward_passed' => false, 'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $oracleReal = realpath($oracleRoot);
        $sandboxExec = '/usr/bin/sandbox-exec';
        if ($oracleReal === false || dirname($oracleReal) !== $trustedTempRoot || ! is_executable($sandboxExec)) {
            return ['status' => 'oracle_sandbox_unavailable', 'forward_passed' => false, 'n_minus_1_passed' => false, 'rollback_passed' => false];
        }
        $db = $oracleReal.'/candidate.sqlite';
        File::put($db, '');
        $sourcePaths = [];
        try {
            foreach ($sources as $path => $source) {
                $sourcePath = $oracleReal.'/migration-'.substr(hash('sha256', $path), 0, 12).'.php';
                File::put($sourcePath, $source);
                $real = realpath($sourcePath);
                if ($real === false || ! str_starts_with($real, $oracleReal.'/') || is_link($sourcePath)
                    || ! hash_equals(hash('sha256', $source), (string) hash_file('sha256', $real))) {
                    throw new \RuntimeException('isolated_migration_source_copy_invalid');
                }
                $sourcePaths[] = $real;
            }
            $runner = <<<'PHP'
                require $argv[1];
                $db = $argv[2];
                $action = $argv[3];
                $sources = array_slice($argv, 4);
                $capsuleClass = implode(chr(92), ['Illuminate', 'Database', 'Capsule', 'Manager']);
                $facadeClass = implode(chr(92), ['Illuminate', 'Support', 'Facades', 'Facade']);
                $migrationClass = implode(chr(92), ['Illuminate', 'Database', 'Migrations', 'Migration']);
                $capsule = new $capsuleClass();
                $capsule->addConnection(['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true]);
                $capsule->setAsGlobal(); $capsule->bootEloquent();
                $container = $capsule->getContainer();
                $container->singleton('db.schema', static fn () => $capsule->getConnection()->getSchemaBuilder());
                $facadeClass::setFacadeApplication($container);
                $connection = $capsule->getConnection();
                $connection->statement('PRAGMA foreign_keys = ON');
                $migrations = [];
                foreach ($sources as $source) {
                    $migration = require $source;
                    if (! $migration instanceof $migrationClass) { throw new RuntimeException('candidate_migration_contract_invalid'); }
                    $migrations[] = $migration;
                }
                if ($action === 'up') { foreach ($migrations as $migration) { $migration->up(); } }
                elseif ($action === 'down') { foreach (array_reverse($migrations) as $migration) { $migration->down(); } }
                else { throw new RuntimeException('candidate_migration_action_invalid'); }
                PHP;
            $vendor = $oracleReal.'/vendor';
            foreach (['composer', 'laravel', 'psr', 'symfony', 'nesbot', 'doctrine', 'brick', 'carbonphp'] as $package) {
                if (is_dir(base_path('vendor/'.$package))) {
                    File::copyDirectory(base_path('vendor/'.$package), $vendor.'/'.$package);
                }
            }
            File::put($vendor.'/autoload.php', <<<'PHP'
                <?php
                require __DIR__.'/composer/ClassLoader.php';
                $loader = new Composer\Autoload\ClassLoader(__DIR__);
                foreach (require __DIR__.'/composer/autoload_psr4.php' as $prefix => $paths) { $loader->setPsr4($prefix, $paths); }
                $loader->addClassMap(require __DIR__.'/composer/autoload_classmap.php');
                $loader->register(true);
                require __DIR__.'/laravel/framework/src/Illuminate/Collections/functions.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Collections/helpers.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Support/functions.php';
                require __DIR__.'/laravel/framework/src/Illuminate/Support/helpers.php';
                PHP);
            $snapshot = static function (string $database): array {
                $pdo = new \PDO('sqlite:'.$database);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $schema = $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(\PDO::FETCH_ASSOC);
                $data = [];
                foreach (['atlas_probe_parents', 'atlas_probe_records'] as $table) {
                    $data[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
                }

                return ['schema' => $schema, 'data' => $data];
            };
            $pdo = new \PDO('sqlite:'.$db);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec("CREATE TABLE atlas_probe_parents (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL DEFAULT 'parent')");
            $pdo->exec("CREATE TABLE atlas_probe_records (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, legacy_value VARCHAR NOT NULL DEFAULT 'legacy', status VARCHAR NOT NULL DEFAULT 'active', FOREIGN KEY(parent_id) REFERENCES atlas_probe_parents(id))");
            $pdo->exec('CREATE INDEX atlas_probe_records_legacy_value_index ON atlas_probe_records (legacy_value)');
            $pdo->exec("INSERT INTO atlas_probe_parents (id,name) VALUES (1,'parent')");
            $pdo->exec("INSERT INTO atlas_probe_records (id,parent_id,legacy_value,status) VALUES (1,1,'legacy','active')");
            unset($pdo);
            $before = $snapshot($db);
            $profile = '(version 1)(deny default)(deny network*)(allow process-exec (literal "'.PHP_BINARY.'"))'
                .'(allow process-fork)(allow sysctl-read)(allow mach-lookup)'
                .'(allow file-read-metadata (literal "/") (literal "/usr") (literal "/System") (literal "/private") (subpath "'.dirname($oracleReal).'") (literal "/opt") (literal "/dev") (literal "'.$oracleReal.'"))'
                .'(allow file-read* (literal "/") (subpath "/opt/homebrew/Cellar") (subpath "/opt/homebrew/opt") (subpath "/opt/homebrew/lib") (subpath "/usr/lib") (subpath "/System/Library") (subpath "/Library") (subpath "/private/etc") (literal "'.$db.'") (subpath "'.$oracleReal.'") (literal "/dev/null") (literal "/dev/urandom"))'
                .'(allow file-write* (literal "'.$db.'") (literal "'.$db.'-journal") (literal "'.$db.'-wal") (literal "'.$db.'-shm") (literal "/dev/null"))';
            $run = function (string $action) use ($sandboxExec, $profile, $runner, $vendor, $db, $sourcePaths): Process {
                $process = new Process([$sandboxExec, '-p', $profile, PHP_BINARY, '-n',
                    '-d', 'display_errors=stderr', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec', '-r', $runner,
                    $vendor.'/autoload.php', $db, $action, ...$sourcePaths], '/', ['APP_ENV' => 'testing', 'HOME' => '/nonexistent']);
                $process->setTimeout(60);
                try {
                    $process->run();
                } catch (\Throwable $e) {
                    throw new \RuntimeException($e->getMessage().' stderr='.$process->getErrorOutput(), 0, $e);
                }

                return $process;
            };
            $up = $run('up');
            if (! $up->isSuccessful() || $up->getOutput() !== '' || $up->getErrorOutput() !== '') {
                throw new \RuntimeException('candidate_migration_up_process_untrusted stdout='.$up->getOutput().' stderr='.$up->getErrorOutput().' exit='.$up->getExitCode());
            }
            $forward = $snapshot($db);
            $legacyExpectation = ['id' => 1, 'parent_id' => 1, 'legacy_value' => 'legacy', 'status' => 'active'];
            $n1Passed = array_intersect_key((array) ($forward['data']['atlas_probe_records'][0] ?? []), $legacyExpectation)
                === $legacyExpectation;
            $down = $run('down');
            if (! $down->isSuccessful() || $down->getOutput() !== '' || $down->getErrorOutput() !== '') {
                throw new \RuntimeException('candidate_migration_down_process_untrusted');
            }
            $after = $snapshot($db);
            $receipt = ['status' => 'executed_isolated_laravel_sqlite', 'forward_passed' => $forward !== $before,
                'n_minus_1_passed' => $n1Passed, 'rollback_passed' => $after === $before,
                'before_hash' => RealExecutionHash::make($before), 'forward_hash' => RealExecutionHash::make($forward),
                'after_hash' => RealExecutionHash::make($after), 'database' => 'isolated_tempfile',
                'runner_hash' => hash('sha256', $runner), 'supervisor' => AtlasRealEngineeringExecutionKernelService::class];
            $receipt['supervisor_hmac'] = hash_hmac('sha256', RealExecutionHash::make($receipt), (string) config('app.key'));

            return $receipt;
        } catch (\Throwable $e) {
            return ['status' => 'oracle_failed', 'error_class' => $e::class, 'error_hash' => hash('sha256', $e->getMessage()), 'forward_passed' => false,
                'n_minus_1_passed' => false, 'rollback_passed' => false];
        } finally {
            foreach ($sourcePaths as $path) {
                @unlink($path);
            }
            @unlink($db);
            if (str_starts_with($oracleReal, $trustedTempRoot.'/atlas-owned-migration-oracle-')) {
                File::deleteDirectory($oracleReal, true);
            }
        }
    }
}
