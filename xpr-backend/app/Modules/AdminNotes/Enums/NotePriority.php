<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Enums;

/**
 * Priorités adossées à la contrainte CHECK admin_notes_priority_check et à
 * NOTE_PRIORITIES côté front : les trois listes évoluent ensemble.
 */
enum NotePriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
