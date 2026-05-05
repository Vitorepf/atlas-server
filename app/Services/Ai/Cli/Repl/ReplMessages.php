<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Catalogo central de strings de UI do REPL (PT-BR).
 *
 * Nada de strings hardcoded espalhadas pelo command. Quando precisar i18n no
 * futuro, troca a implementacao por uma factory que carrega translations.
 */
class ReplMessages
{
    public static function imageRemoved(string $name): string
    {
        return 'Imagem removida: '.$name;
    }

    public static function imageAttached(string $name): string
    {
        return 'Anexando imagem '.$name.'...';
    }

    public static function imageAttachFailed(string $reason): string
    {
        return 'Nao consegui anexar imagem: '.$reason;
    }

    public static function clipboardImageReading(): string
    {
        return 'Lendo imagem do clipboard...';
    }

    public static function clipboardImageAttached(): string
    {
        return 'Imagem colada e anexada ao composer.';
    }

    public static function clipboardEmpty(): string
    {
        return 'Clipboard vazio. Copie um texto ou capture uma imagem (Cmd+Shift+Ctrl+4) e tente de novo.';
    }

    public static function clipboardOsascriptBlocked(): string
    {
        return 'Nao consegui inspecionar o clipboard via osascript. Verifique permissoes em Sistema > Privacidade > Automacao.';
    }

    public static function clipboardImageInvalid(string $reason): string
    {
        return 'Clipboard sem imagem utilizavel: '.$reason;
    }

    public static function imageDuplicate(string $name): string
    {
        return 'Imagem '.$name.' ja anexada (mesma SHA256). Ignorada.';
    }

    public static function imagesDeduped(int $added, int $skipped): string
    {
        if ($added === 0) {
            return 'Todas as '.$skipped.' imagens ja estavam anexadas.';
        }

        return $added.' imagem(ns) anexada(s); '.$skipped.' duplicata(s) ignorada(s).';
    }
}
