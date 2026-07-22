<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Émission d'un document. La date d'émission peut être forcée — une facture
 * saisie le 3 pour une livraison du 31 est un cas courant — mais elle désigne
 * l'exercice, donc la séquence : une date hors exercice ouvert fera répondre
 * 409 à DocumentNumberService.
 */
final class DocumentIssueRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'issuedAt' => ['nullable', 'date'],
        ];
    }
}
