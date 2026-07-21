<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Enums;

/**
 * Cycle de vie d'une note, piloté par le support plateforme :
 * open → answered → closed. Le client crée (open) mais ne fait pas transiter.
 */
enum NoteStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';
}
