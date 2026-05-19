<?php

namespace App\Enums;

/**
 * Canonical YouTube translation status — mirrors
 * `YoutubeTranslationStatus` from `@atlas/rich-input-canon`.
 *
 * `TranslatedReady` is canonical but unreachable today: there is no
 * translation pipeline. Foreign-language transcripts stay `Required`
 * until a future pipeline lands. Never set `TranslatedReady` without
 * a real translation step — that would lie to the operator.
 */
enum YoutubeTranslationStatus: string
{
    case NotRequired = 'not_required';
    case Required = 'required';
    case Pending = 'pending';
    case TranslatedReady = 'translated_ready';
    case Failed = 'failed';
}
