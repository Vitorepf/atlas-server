<?php

namespace App\Services\Ai\Cli;

class IntentPermissionResolver
{
    /**
     * @var list<array{level:string,pattern:string,reason:string}>
     */
    private const RULES = [
        ['level' => 'danger', 'pattern' => '/\bsudo\b/i', 'reason' => 'rodar com sudo'],
        ['level' => 'danger', 'pattern' => '/\brm\s+-rf\b/i', 'reason' => 'remover arquivos recursivamente'],
        ['level' => 'danger', 'pattern' => '/\bchmod\s+777\b/i', 'reason' => 'mudar permissoes amplas'],
        ['level' => 'danger', 'pattern' => '/\bdrop\s+(table|database)\b/i', 'reason' => 'derrubar tabela ou banco'],
        ['level' => 'danger', 'pattern' => '/\bdb:wipe\b/i', 'reason' => 'limpar o banco inteiro'],
        ['level' => 'danger', 'pattern' => '/\b(force.{1,40}push|push.{1,40}force)\b/i', 'reason' => 'force push'],
        ['level' => 'danger', 'pattern' => '/\breset\s+--hard\b/i', 'reason' => 'git reset --hard'],
        ['level' => 'danger', 'pattern' => '/\bclean\s+-fd\b/i', 'reason' => 'git clean -fd'],
        ['level' => 'danger', 'pattern' => '/\bbrew\s+(install|uninstall|reinstall)\b/i', 'reason' => 'instalar/remover via Homebrew (sistema)'],
        ['level' => 'danger', 'pattern' => '/\bapt(-get)?\s+(install|remove|purge)\b/i', 'reason' => 'instalar/remover via apt (sistema)'],
        ['level' => 'danger', 'pattern' => '/\bglobal\s+install\b/i', 'reason' => 'instalar globalmente'],
        ['level' => 'danger', 'pattern' => '/\bnpm\s+(-g|--global)\b/i', 'reason' => 'instalar npm globalmente'],

        ['level' => 'write', 'pattern' => '/\binstal[a-z]*\s+(depend|pacote|package|biblioteca|lib)/i', 'reason' => 'instalar dependencias'],
        ['level' => 'write', 'pattern' => '/\binstal[ae][a-z]*\b/i', 'reason' => 'instalar pacotes'],
        ['level' => 'write', 'pattern' => '/\binstall\b/i', 'reason' => 'install packages'],
        ['level' => 'write', 'pattern' => '/\bcomposer\s+(require|update|install|remove)\b/i', 'reason' => 'mexer com Composer'],
        ['level' => 'write', 'pattern' => '/\bnpm\s+(install|i|update|uninstall|run)\b/i', 'reason' => 'rodar comando npm'],
        ['level' => 'write', 'pattern' => '/\b(pnpm|yarn|bun)\s+(install|add|remove|run)\b/i', 'reason' => 'gerenciar dependencias do projeto'],
        ['level' => 'write', 'pattern' => '/\bphp\s+artisan\s+(migrate|db:seed|tinker)\b/i', 'reason' => 'rodar artisan que altera estado'],
        ['level' => 'write', 'pattern' => '/\bmigrar\b|\bmigrate\b|\bmigracao\b|\bmigração\b/i', 'reason' => 'rodar migrations'],
        ['level' => 'write', 'pattern' => '/\bcommit(ar|e)?\b|\bgit\s+commit\b/i', 'reason' => 'criar commit'],
        ['level' => 'write', 'pattern' => '/\bgit\s+(merge|rebase|push|pull)\b/i', 'reason' => 'rodar git que altera historico'],
        ['level' => 'write', 'pattern' => '/\bdeploy\b/i', 'reason' => 'deploy'],
        ['level' => 'write', 'pattern' => '/\bcrie[a-z]*\b|\bcriar\b|\bcreate\b/i', 'reason' => 'criar arquivos'],
        ['level' => 'write', 'pattern' => '/\bedit[ae][a-z]*\b|\bedit\b/i', 'reason' => 'editar arquivos'],
        ['level' => 'write', 'pattern' => '/\bescrev[ae][a-z]*\b/i', 'reason' => 'escrever codigo'],
        ['level' => 'write', 'pattern' => '/\brefator[ae][a-z]*\b|\brefactor\b/i', 'reason' => 'refatorar'],
        ['level' => 'write', 'pattern' => '/\bremov[ae][a-z]*\b|\bremove\b|\bdelet[ae][a-z]*\b|\bdelete\b/i', 'reason' => 'remover/deletar'],
        ['level' => 'write', 'pattern' => '/\brode[a-z]*\s+(os\s+)?test/i', 'reason' => 'rodar testes'],
        ['level' => 'write', 'pattern' => '/\brodar?\s+(os\s+)?test/i', 'reason' => 'rodar testes'],
        ['level' => 'write', 'pattern' => '/\brun\s+(the\s+)?test/i', 'reason' => 'run tests'],
        ['level' => 'write', 'pattern' => '/\bphpunit\b|\bvendor\/bin\/phpunit\b/i', 'reason' => 'executar phpunit'],
        ['level' => 'write', 'pattern' => '/\bdocker\s+(build|compose|run|up|down)\b/i', 'reason' => 'mexer com docker'],
        ['level' => 'write', 'pattern' => '/\bbuild\b|\bbuildar\b/i', 'reason' => 'rodar build'],
        ['level' => 'write', 'pattern' => '/\bgerar?\s+(arquivo|migration|seed|factory|skeleton)/i', 'reason' => 'gerar artefato'],
        ['level' => 'write', 'pattern' => '/\bcorri[ja]\b|\bcorrigir\b|\bfix\b/i', 'reason' => 'corrigir / fix'],
        ['level' => 'write', 'pattern' => '/\bimplement[ae][a-z]*\b|\bimplement\b/i', 'reason' => 'implementar'],
        ['level' => 'write', 'pattern' => '/\bmover\b|\bmove\b|\brenome[ae][a-z]*\b|\brename\b/i', 'reason' => 'mover/renomear'],
        ['level' => 'write', 'pattern' => '/\baplique[a-z]*\s+patch\b|\bapply\s+patch\b/i', 'reason' => 'aplicar patch'],
    ];

    public function resolve(string $input, string $currentPermission): IntentResolution
    {
        $current = $this->normalize($currentPermission);
        $clean = trim($input);

        if ($clean === '' || $clean[0] === '/') {
            return new IntentResolution($current, $current, false, 'sem acao detectada', []);
        }

        $matches = [];
        $danger = false;
        $write = false;

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $clean) === 1) {
                $matches[] = $rule['reason'];
                if ($rule['level'] === 'danger') {
                    $danger = true;
                } elseif ($rule['level'] === 'write') {
                    $write = true;
                }
            }
        }

        $required = $danger ? 'danger' : ($write ? 'write' : 'read');
        $changed = $this->rank($required) > $this->rank($current);

        return new IntentResolution(
            current: $current,
            required: $required,
            changed: $changed,
            reason: $matches === [] ? 'leitura/explicacao' : $matches[0],
            signals: array_values(array_unique($matches)),
        );
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['read', 'write', 'danger'], true) ? $value : 'read';
    }

    private function rank(string $level): int
    {
        return match ($level) {
            'danger' => 3,
            'write' => 2,
            default => 1,
        };
    }
}
