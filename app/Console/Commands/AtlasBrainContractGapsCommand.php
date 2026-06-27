<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainScopeRegistry;
use Illuminate\Console\Command;

/**
 * BRAIN CONTRACT-GAPS — read-only. Surfaces a NEW class of high-leverage origination the brain was blind to:
 * an INTERFACE declared inside the scope that has ZERO concrete implementers anywhere in app/ — a contract the
 * architecture PROMISED but never fulfilled (architectural capability debt). This is NOT orphan-wiring (an
 * orphan class exists and lacks a caller); it is a capability the system DECLARED via a contract and never
 * delivered. The brain (the pasted decide-loop session) reads this and originates "implement contract X" specs
 * — architecture-completion leverage, not intra-graph node-wiring.
 *
 * The signal is BINARY and UNAMBIGUOUS (an interface either has an `implements`-ing class or it does not — no
 * name-variant fuzziness), so it never fires a false gap, and it is GROUNDED (real interface FQCN + the real
 * method signatures the implementation must satisfy are carried as evidence). NOT a computed scalar
 * (anti-Goodhart): a SET of zero-implementer interfaces, never a ranked score. author≠judge intact — the brain
 * only PROPOSES; Atlas's architect gate + RED→GREEN cert remain the judges. Read-only, fail-OPEN.
 */
final class AtlasBrainContractGapsCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:contract-gaps {--scope=autonomous : the scope slug to scan} {--json}';

    /** @var string */
    protected $description = 'List interfaces in a scope with ZERO concrete implementers (declared-but-unfulfilled contracts the brain can originate against).';

    public function handle(): int
    {
        $scopeDef = app(AtlasBrainScopeRegistry::class)->resolve((string) $this->option('scope'));
        $slug = (string) $scopeDef['slug'];
        $roots = array_values((array) ($scopeDef['roots'] ?? []));

        $implemented = $this->implementedInterfaceNames();
        $interfaces = $this->scopeInterfaces($roots, $implemented);
        $gaps = self::gapsFrom($interfaces);

        $payload = ['scope' => $slug, 'count' => count($gaps), 'gaps' => $gaps];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("brain contract-gaps — scope '{$slug}': {$payload['count']} declared-but-unfulfilled contract(s) (originate the implementation):");
        foreach ($gaps as $gap) {
            $this->line('  ! '.$gap['fqcn'].'  ('.$gap['file'].')');
            foreach ((array) ($gap['methods'] ?? []) as $m) {
                $this->line('      '.$m);
            }
        }
        if ($gaps === []) {
            $this->line('  (none — every declared contract in scope has a concrete implementer)');
        }

        return self::SUCCESS;
    }

    /**
     * Pure: keep only interfaces with zero implementers, sorted by FQCN. The membership signal — never a score.
     *
     * @param  list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>  $interfaces
     * @return list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>
     */
    public static function gapsFrom(array $interfaces): array
    {
        $gaps = array_values(array_filter($interfaces, static fn (array $i): bool => (int) ($i['implementer_count'] ?? 0) === 0));
        usort($gaps, static fn (array $a, array $b): int => strcmp((string) ($a['fqcn'] ?? ''), (string) ($b['fqcn'] ?? '')));

        return $gaps;
    }

    /**
     * Pure: parse the public method signatures an implementation of this interface source must satisfy. These
     * are the GROUNDED obligation the originated spec carries (so the brain authors a concrete spec, not prose).
     *
     * @return list<string>
     */
    public static function interfaceMethods(string $source): array
    {
        $methods = [];
        if (preg_match_all('/public\s+(?:static\s+)?function\s+(\w+\s*\([^;{]*\)(?:\s*:\s*[^;{\n]+)?)/m', $source, $m) === false) {
            return [];
        }
        foreach ($m[1] as $sig) {
            $methods[] = 'function '.trim((string) preg_replace('/\s+/', ' ', (string) $sig));
        }

        return $methods;
    }

    /**
     * Scope interfaces (FQCN + methods + app-wide implementer count). IO; fail-OPEN to [].
     *
     * @param  list<string>  $roots
     * @param  array<string,bool>  $implemented  short interface-name => has a concrete implementer somewhere
     * @return list<array{fqcn:string, file:string, methods:list<string>, implementer_count:int}>
     */
    private function scopeInterfaces(array $roots, array $implemented): array
    {
        $out = [];
        try {
            foreach ($roots as $root) {
                $base = base_path(trim($root, '/'));
                if (! is_dir($base)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $path) {
                    $path = (string) $path;
                    if (! str_ends_with($path, '.php')) {
                        continue;
                    }
                    $src = (string) @file_get_contents($path);
                    if (! preg_match('/^interface\s+(\w+)/m', $src, $im)) {
                        continue;
                    }
                    $short = $im[1];
                    $ns = preg_match('/^namespace\s+([^;]+);/m', $src, $nm) ? trim($nm[1]) : '';
                    $out[] = [
                        'fqcn' => $ns !== '' ? $ns.'\\'.$short : $short,
                        'file' => ltrim(str_replace(base_path(), '', $path), '/'),
                        'methods' => self::interfaceMethods($src),
                        'implementer_count' => isset($implemented[$short]) ? 1 : 0,
                    ];
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * One pass over app/: every interface short-name that appears in a concrete `implements` clause. Membership
     * is the whole signal. ponytail: full-tree scan per run; fine for an occasional read-only command, index if
     * app/ ever dwarfs this.
     *
     * @return array<string,bool>
     */
    private function implementedInterfaceNames(): array
    {
        $set = [];
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $path) {
                $path = (string) $path;
                if (! str_ends_with($path, '.php')) {
                    continue;
                }
                $src = (string) @file_get_contents($path);
                if (preg_match_all('/\bimplements\s+([^{]+?)[\n{]/m', $src, $m) === false) {
                    continue;
                }
                foreach ($m[1] as $clause) {
                    foreach (preg_split('/[\s,]+/', trim((string) $clause)) ?: [] as $name) {
                        $name = ltrim((string) $name, '\\');
                        if ($name === '') {
                            continue;
                        }
                        $parts = explode('\\', $name);
                        $set[(string) end($parts)] = true;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $set;
    }
}
