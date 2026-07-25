<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Support\YesNo;
use Illuminate\Support\Str;

/**
 * Pure skill scaffold text sanitize helpers (full-pass peel).
 */
final class SkillScaffoldTextSupport
{
    public static function sanitizeSummary(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        $danger = '/(~\/\.ssh|id_rsa|rm\s+-rf|\bcurl\b|\bwget\b|\bnc\b|\/etc\/passwd|allowed[_-]tools|trust\s*:|tier\s*:|\$\(|`|\bbase64\b|\/dev\/tcp|\bsudo\b|\beval\b)/i';
        if (preg_match($danger, $s) === 1) {
            return '[recorrência redigida — conteúdo potencialmente perigoso ou sensível]';
        }

        return Str::limit($s, 300, '');
    }

    public static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return YesNo::trueFalse($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // Defense-in-depth: never let a newline/control char reach a YAML scalar (a multi-line
        // scalar would be re-read as injected top-level keys by the naive frontmatter parsers).
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
        // Quote anything with YAML-special chars or leading/trailing space; escape quotes.
        if ($s === '' || preg_match('/[:#\-\[\]{}",\n]/', $s) === 1 || trim($s) !== $s) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
        }

        return $s;
    }

    /**
     * @param  array<string,mixed>  $fm
     */
    public static function frontmatter(array $fm): string
    {
        $lines = ['---'];
        foreach ($fm as $key => $value) {
            if (is_array($value)) {
                $lines[] = $key.':';
                foreach ($value as $k => $v) {
                    $lines[] = '  '.$k.': '.self::scalar($v);
                }

                continue;
            }
            $lines[] = $key.': '.self::scalar($value);
        }
        $lines[] = '---';

        return implode("\n", $lines)."\n\n";
    }

    /**
     * @param  list<string>  $evidenceDateLines  already formatted "- YYYY-MM-DD..." lines
     */
    public static function body(
        string $title,
        string $summary,
        string $slug,
        int $occurrenceCount,
        int $windowDays,
        float $confidence,
        array $evidenceDateLines,
    ): string {
        $evidenceBlock = $evidenceDateLines === []
            ? '- (occurrences recorded in the pattern ledger)'
            : implode("\n", $evidenceDateLines);

        return <<<MD
        # {$title}

        > Rascunho **auto-gerado pelo Atlas** a partir de um padrão recorrente no seu trabalho.
        > Revise, refine os passos, e promova quando quiser. Nada aqui está ativo até você promover.

        ## O que o Atlas notou
        Você repetiu **{$occurrenceCount}x** (em {$windowDays} dias): {$summary}.
        Confiança da detecção: {$confidence}.

        ## Automação proposta (preencha/ajuste)
        Esta skill padroniza a tarefa acima. Descreva abaixo os passos que você quer que o Atlas siga
        quando esse padrão aparecer — o scaffold é um ponto de partida ancorado no que foi observado,
        não uma sequência inventada.

        1. (passo 1 — defina)
        2. (passo 2 — defina)

        ## Evidência (ocorrências reais)
        {$evidenceBlock}

        ## Segurança
        - `allowed-tools` está **vazio**: esta skill NÃO concede nenhuma ferramenta nova ao agente até você adicionar explicitamente.
        - Promover para o vault vivo: `php artisan atlas:ai:operator-skill approve {$slug} --confirm`
        - Rejeitar: `php artisan atlas:ai:operator-skill reject {$slug}`

        MD;
    }

    /** Fail-closed: tool-less + untrusted + proposed, no injected escalation keys. */
    public static function scaffoldIsSafe(string $markdown): bool
    {
        if (preg_match('/^trust:\s*untrusted\s*$/m', $markdown) !== 1) {
            return false;
        }
        if (preg_match('/^allowed-tools:\s*""\s*$/m', $markdown) !== 1) {
            return false;
        }

        return preg_match('/^(trust:\s*trusted|tier:\s*(?:trusted|certified)|allowed[_-]tools:\s*[^"\s])/mi', $markdown) !== 1;
    }
}
