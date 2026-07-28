<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

/**
 * Pure operator learning classification helpers (full-pass peel).
 */
final class OperatorLearningClassifySupport
{
    public static function sanitizeClaim(string $claim): string
    {
        $claim = trim(preg_replace('/\s+/', ' ', $claim) ?? '');

        return Str::limit($claim, 1000, '');
    }

    /**
     * Casa PALAVRA INTEIRA contra um padrao que ja declara a propria flexao.
     *
     * Fronteira dura dos dois lados nao serve para portugues: `\bresposta\b` NAO casa
     * "respostas", e o `str_contains` original casava — por acidente, mas casava. Trocar um
     * pelo outro sem declarar a flexao troca um falso positivo por um falso negativo.
     * Medido: "prefiro respostas curtas" caiu de COL-156 para OP-124.
     *
     * Por isso cada alvo traz a propria forma: `curt[oa]s?` pega curto/curta/curtos/curtas
     * e nao pega "curtir"; `long[oa]s?` nao pega "longe"; `autonom\w*` pega a familia
     * inteira de propositio.
     */
    private static function casa(string $textoDobrado, string $padrao): bool
    {
        return preg_match('/\\b(?:'.$padrao.')\\b/u', $textoDobrado) === 1;
    }

    /**
     * @param  list<string>  $padroes
     */
    private static function casaAlgum(string $textoDobrado, array $padroes): bool
    {
        foreach ($padroes as $padrao) {
            if (self::casa($textoDobrado, $padrao)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizeTaxonomy(string $taxonomy, string $claim): string
    {
        $taxonomy = strtoupper(trim($taxonomy));
        if (preg_match('/^(SYS|OP|COL)-\d{3}$/', $taxonomy) === 1) {
            return $taxonomy;
        }

        // Sem fronteira de palavra, 'tom' casava dentro de "tomar", "bottom", "custom",
        // "sintoma" e "atomo" — medido: 4 de 7 frases realistas em portugues caiam em
        // COL-156 ("Seu vocabulario proprio") por acidente de substring. E havia o erro
        // espelhado: sem dobrar acento, 'nao mexa' nunca casava "nao mexa" escrito com til,
        // que e como o operador escreve. Um lado inventava rotulo, o outro perdia o certo.
        $texto = self::semAcento(Str::lower($claim));

        if (self::casaAlgum($texto, ['autonom\w*', 'approval', 'aprov\w*'])) {
            return 'COL-157';
        }
        if (self::casaAlgum($texto, ['curt[oa]s?', 'long[oa]s?', 'tons?', 'respostas?'])) {
            return 'COL-156';
        }
        if (self::casaAlgum($texto, ['gost[oa]', 'prefiro'])) {
            return 'OP-124';
        }
        if (self::casaAlgum($texto, ['nunca', 'nao mexa', 'bloque\w*'])) {
            return 'OP-140';
        }

        return 'OP-071';
    }

    public static function inferSignalKind(string $claim, string $taxonomy): string
    {
        // Mesmo defeito, mesmo conserto: aqui o custo de errar e maior, porque
        // `operator_boundary` e o rotulo de LIMITE — o que o operador proibiu.
        $texto = self::semAcento(Str::lower($claim));
        if (self::casaAlgum($texto, ['nunca', 'nao mexa'])) {
            return 'operator_boundary';
        }
        if (str_starts_with($taxonomy, 'COL-')) {
            return 'collaboration_preference';
        }

        return 'operator_preference';
    }

    /**
     * Dobra acento para ASCII — sem isso o termo acentuado nunca casa a lista.
     */
    private static function semAcento(string $texto): string
    {
        return strtr($texto, [
            "\u{e1}" => 'a', "\u{e0}" => 'a', "\u{e3}" => 'a', "\u{e2}" => 'a', "\u{e4}" => 'a',
            "\u{e9}" => 'e', "\u{ea}" => 'e', "\u{e8}" => 'e', "\u{eb}" => 'e',
            "\u{ed}" => 'i', "\u{ee}" => 'i', "\u{ec}" => 'i', "\u{ef}" => 'i',
            "\u{f3}" => 'o', "\u{f5}" => 'o', "\u{f4}" => 'o', "\u{f2}" => 'o', "\u{f6}" => 'o',
            "\u{fa}" => 'u', "\u{fb}" => 'u', "\u{f9}" => 'u', "\u{fc}" => 'u',
            "\u{e7}" => 'c', "\u{f1}" => 'n',
        ]);
    }

    public static function inferPrivacy(string $claim): string
    {
        // `str_contains` sem fronteira de palavra fazia `rg` (o documento) casar dentro
        // de "larga", "carga", "energia", "margem", "target" — e a consequencia nao e
        // rotulo errado, e DESTRUICAO: privacidade `sensitive` dispara `redactIfSensitive`,
        // que troca o texto duravel por um hash. Todo principio em portugues com essas
        // tres letras perdia o conteudo no banco. Medido: "range larga", "a carga do
        // argumento", "margem de erro" -> todos `sensitive`. `key` casava em "monkey".
        //
        // E havia o erro espelhado, invisivel: sem dobrar acento, "saude" com acento NAO
        // casava a lista — falso negativo justo no termo que ela existe para pegar.
        // Um lado apagava o que devia guardar; o outro guardava o que devia proteger.
        $lower = self::semAcento(Str::lower($claim));
        foreach (['senha', 'token', 'secret', 'key', 'credential', 'credencial'] as $needle) {
            if (preg_match('/\\b'.preg_quote($needle, '/').'\\b/u', $lower) === 1) {
                return 'secret';
            }
        }
        foreach (['saude', 'familia', 'relacionamento', 'dinheiro', 'documento', 'cpf', 'rg'] as $needle) {
            if (preg_match('/\\b'.preg_quote($needle, '/').'\\b/u', $lower) === 1) {
                return 'sensitive';
            }
        }

        return 'normal';
    }

    public static function inferRisk(string $claim, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return 'high';
        }

        $lower = Str::lower($claim);
        // 'delet' covers delete/deletar; keep explicit apagar/publicar/enviar/comprar/vender/overwrite.
        foreach (['delet', 'apagar', 'overwrite', 'comprar', 'vender', 'publicar', 'enviar'] as $needle) {
            if (str_contains($lower, $needle)) {
                return 'high';
            }
        }

        return 'low';
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function normalizeFromList(string $value, array $allowed, string $default): string
    {
        $value = Str::lower(trim($value));

        return in_array($value, $allowed, true) ? $value : $default;
    }

    public static function confidence(mixed $value, string $risk, string $privacy, string $inferenceType = 'explicit', string $tier = ''): float
    {
        $confidence = AiValueNormalizer::finiteFloatOrNull($value) ?? 0.5;
        $confidence = max(0.0, min(1.0, $confidence));

        if ($inferenceType === 'implicit' || in_array($tier, ['single_inference', 'repeated'], true)) {
            $confidence = min($confidence, 0.6);
        }
        if ($risk !== 'low' || $privacy !== 'normal') {
            $confidence = min($confidence, 0.74);
        }

        return $confidence;
    }

    public static function redactIfSensitive(string $claim, string $privacy): string
    {
        if (in_array($privacy, ['sensitive', 'secret'], true)) {
            return '[redacted:'.$privacy.':'.substr(hash('sha256', $claim), 0, 12).']';
        }

        return $claim;
    }

    public static function privacyRaiseOnly(string $a, string $b): string
    {
        $left = $a !== '' ? $a : 'normal';
        $right = $b !== '' ? $b : 'normal';

        return OperatorComprehensionGateSupport::raisePrivacy($left, $right);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function rawExcerptHash(array $input): ?string
    {
        $raw = $input['raw_excerpt'] ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return self::nullableString($input['raw_excerpt_hash'] ?? null);
        }

        return hash('sha256', $raw);
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function clean(string $value, string $default): string
    {
        $value = trim($value);

        return $value === '' ? $default : Str::limit($value, 160, '');
    }
}
