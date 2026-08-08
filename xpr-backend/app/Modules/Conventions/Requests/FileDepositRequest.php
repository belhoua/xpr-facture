<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Requests;

use App\Modules\Conventions\Enums\DepositStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enregistrement et correction d'un dépôt de dossier.
 *
 * UNE seule classe pour les deux verbes, contrairement aux conventions et aux
 * tiers : un dépôt tient en cinq champs qu'on saisit et corrige d'un bloc, il
 * n'a ni unicité à ignorer sur lui-même ni règle propre à la création. Deux
 * classes identiques ne feraient que doubler l'endroit où une règle se modifie.
 *
 * Le `conventionId` n'est PAS dans ce payload : il vient du chemin de la route
 * (`conventions/{convention}/deposits`) et la convention est résolue sous le
 * scope tenant avant que le service ne soit appelé. L'accepter dans le corps de
 * la requête ouvrirait la possibilité d'attacher un dépôt à la convention d'une
 * autre société (§5.3).
 */
final class FileDepositRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Référence du récépissé remis au guichet (« 0003439/AK/26 »).
            // Format LIBRE : chaque commune a le sien, et l'imposer ferait
            // rejeter un récépissé authentique.
            'reference' => ['required', 'string', 'min:2', 'max:40'],
            'depositedAt' => ['required', 'date'],
            'organisation' => ['required', 'string', 'min:2', 'max:255'],
            'status' => ['nullable', Rule::enum(DepositStatus::class)],
            // Miroir de la contrainte CHECK `file_deposits_decided_after_check` :
            // une décision ne précède pas le dépôt qu'elle tranche. Le service
            // efface de toute façon cette date sur un statut non tranché.
            'decidedAt' => ['nullable', 'date', 'after_or_equal:depositedAt'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
