<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Obra #6 V0 — o freio no OUTRO produtor de entropia da esteira: o organ 0-ref. A limpeza (05/07)
 * mediu que ~12% das classes do SelfConstruction nascem sem NENHUM caller e ficam esperando wiring
 * que nunca vem (a lição provada é que 0-ref pode estar unwired legítimo, não morto — então não se
 * bloqueia cegamente). Este gate converte "sprawl silencioso de organ unwired" em "unwired DECLARADO
 * com prazo": uma classe entregue que não tem caller real em app/ (fora da própria entrega) só é
 * aceita se o docblock trouxer `@unwired-until YYYY-MM-DD`. Sem a tag -> blocker; tag vencida ->
 * blocker; tag válida -> observação (o gate volta a cobrar quando a data passar).
 *
 * Determinístico, zero LLM, fail-open (Throwable -> passed=true). `$today` é injetável para teste
 * wiper-safe (nenhum date() no caminho testado).
 *
 * Tetos conhecidos (verify adversarial da Obra #6 V0; aceitáveis porque o modo default é observe e a
 * direção do erro é sempre CONSERVADORA — sub-bloqueia, nunca sobre-bloqueia, preservando o AC "zero
 * falso-positivo"): (a) o caller é detectado por palavra-inteira, então menção do símbolo em comentário/
 * string/nome-de-parâmetro conta como wiring (deixa passar organ 0-ref) — evita falso-positivo ao custo
 * de raros falsos-negativos; (b) event listener auto-descoberto e impl ligada só por binding de
 * interface sem referência textual ao nome escapam do check de caller — prevalência ~0 no lane
 * SelfConstruction (0 listeners), Console Command já tem exceção explícita. Fechar isto exigiria
 * AST/container-resolution, complexidade que o modo observe não justifica hoje.
 */
final class AtlasTaskWiringAdmissionGate
{
    public const SCHEMA = 'atlas.task.wiring_admission_gate.v1';

    private const TAG = '@unwired-until';

    private const DECLARATION_PATTERN = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/mi';

    /**
     * @param  list<string>  $changedFiles  paths repo-relativos
     * @return array{schema:string, passed:bool, blockers:list<string>, observations:list<string>, examined:int}
     */
    public function evaluate(array $changedFiles, ?string $repoRoot = null, ?string $today = null): array
    {
        $repoRoot = rtrim($repoRoot ?? base_path(), '/');
        $today ??= date('Y-m-d');
        $blockers = [];
        $observations = [];
        $examined = 0;

        // Canonicaliza para 'app/...' — sem isto um path './app/X.php' fura a auto-exclusão do
        // hasCaller e a classe vira caller de si mesma (falso-negativo do verify adversarial V0).
        $phpChanged = array_values(array_filter(
            array_map(fn (string $f): string => ltrim($f, './'), array_map('strval', $changedFiles)),
            static fn (string $f): bool => str_ends_with($f, '.php')
                && ! (str_starts_with($f, 'tests/') || str_contains($f, '/tests/') || str_ends_with($f, 'Test.php')),
        ));
        if ($phpChanged === []) {
            return ['schema' => self::SCHEMA, 'passed' => true, 'blockers' => [], 'observations' => [], 'examined' => 0];
        }
        $changedSet = array_flip($phpChanged);

        foreach ($phpChanged as $file) {
            $abs = $repoRoot.'/'.$file;
            if (! is_file($abs)) {
                continue;
            }
            $contents = (string) file_get_contents($abs);
            if (preg_match_all(self::DECLARATION_PATTERN, $contents, $m) < 1) {
                continue; // arquivo sem declaração de tipo (config array, helper procedural) — fora do escopo
            }
            // Laravel auto-descobre app/Console/Commands/ — um Command é wired por CONVENÇÃO do
            // framework, sem caller explícito. Tratar como ligado (senão é falso-positivo garantido).
            if (str_starts_with($file, 'app/Console/Commands/')) {
                continue;
            }

            $examined++;
            $symbols = array_values(array_unique($m[1]));

            // Um caller real (em app/, routes/, config/ ou database/) faz a classe "wired". Um caller
            // da MESMA entrega conta (ex.: Command novo + Service novo que ele usa é uma entrega ligada);
            // só o próprio arquivo não conta. Basta UM símbolo da entrega ter caller para o arquivo estar
            // ligado (helper de uma classe wired conta).
            if ($this->hasCaller($repoRoot, $symbols, $file)) {
                continue;
            }

            // Sem caller: exige a tag de unwired declarado.
            $tagDate = $this->declaredUnwiredUntil($contents);
            $fqcnHint = ($ns = $this->namespaceOf($contents)) !== '' ? $ns.'\\'.$symbols[0] : $symbols[0];
            if ($tagDate === null) {
                $blockers[] = 'unwired_class:'.$fqcnHint;
            } elseif ($tagDate < $today) {
                $blockers[] = 'unwired_expired:'.$fqcnHint.':'.$tagDate;
            } else {
                $observations[] = 'unwired_declared:'.$fqcnHint.':until_'.$tagDate;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'observations' => $observations,
            'examined' => $examined,
        ];
    }

    /**
     * Algum símbolo da entrega é referenciado (como palavra) por OUTRO arquivo .php do repo — em app/,
     * routes/, config/ ou database/ (onde vivem bindings de provider, registros de rota, refs de config
     * e factories)? Filtro rápido por strpos, confirmação por fronteira de palavra. Só o próprio arquivo
     * da classe é excluído; caller da mesma entrega conta como wiring válido.
     *
     * @param  list<string>  $symbols
     */
    private function hasCaller(string $repoRoot, array $symbols, string $selfFile): bool
    {
        foreach (['app', 'routes', 'config', 'database'] as $top) {
            $base = $repoRoot.'/'.$top;
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                $rel = $top.substr($f->getPathname(), strlen($base));
                if ($rel === $selfFile) {
                    continue; // a própria classe não é caller de si mesma
                }
                $contents = (string) file_get_contents($f->getPathname());
                foreach ($symbols as $symbol) {
                    if (str_contains($contents, $symbol)
                        && preg_match('/\b'.preg_quote($symbol, '/').'\b/', $contents) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function declaredUnwiredUntil(string $contents): ?string
    {
        if (preg_match('/'.preg_quote(self::TAG, '/').'\s+(\d{4}-\d{2}-\d{2})/', $contents, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function namespaceOf(string $contents): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $contents, $m) === 1 ? trim($m[1]) : '';
    }
}
