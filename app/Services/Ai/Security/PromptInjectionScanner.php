<?php

namespace App\Services\Ai\Security;

class PromptInjectionScanner
{
    /**
     * @return array<int,array{code:string,message:string}>
     */
    public static function scan(string $content): array
    {
        $issues = [];
        $patterns = [
            'ignore_previous_instructions' => '/ignore\s+(all\s+)?previous\s+instructions/i',
            'disregard_prior_instructions' => '/disregard\s+(all\s+)?prior/i',
            'hidden_user_deception' => '/do\s+not\s+tell\s+the\s+(user|operator)/i',
            'html_hidden_instruction' => '/<div[^>]*style=["\'][^"\']*display\s*:\s*none/i',
            'html_comment_instruction' => '/<!--(?:(?!-->).)*(ignore\s+(all\s+)?previous\s+instructions|disregard\s+(all\s+)?prior|do\s+not\s+tell\s+the\s+(user|operator))/is',
            'token_exfiltration_curl' => '/curl\s+https?:\/\/[^\s]*\?.*token/i',
            'secret_exfiltration_instruction' => '/(?:cat|open|read|send|upload|exfiltrate)\s+[^.\n]*(?:\.env|secrets?|credentials?)/i',
        ];

        foreach ($patterns as $code => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $issues[] = [
                    'code' => $code,
                    'message' => self::message($code),
                ];
            }
        }

        if (preg_match('/[\x{200B}\x{200C}\x{200D}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $content) === 1) {
            $issues[] = [
                'code' => 'invisible_unicode',
                'message' => 'Conteudo contem caracteres Unicode invisiveis ou bidi usados em prompt injection.',
            ];
        }

        return $issues;
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'ignore_previous_instructions' => 'Instrucao tenta ignorar instrucoes anteriores.',
            'disregard_prior_instructions' => 'Instrucao tenta descartar contexto anterior.',
            'hidden_user_deception' => 'Instrucao tenta esconder comportamento do operador.',
            'html_hidden_instruction' => 'Instrucao escondida em HTML invisivel.',
            'html_comment_instruction' => 'Instrucao suspeita escondida em comentario HTML.',
            'token_exfiltration_curl' => 'Comando curl suspeito com token em query string.',
            'secret_exfiltration_instruction' => 'Conteudo orienta leitura ou envio de arquivos de segredo.',
            default => 'Conteudo contem padrao suspeito.',
        };
    }
}
