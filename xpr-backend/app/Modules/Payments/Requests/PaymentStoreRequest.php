<?php

declare(strict_types=1);

namespace App\Modules\Payments\Requests;

use App\Modules\Payments\Enums\CheckStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enregistrement d'un règlement.
 *
 * Ce qui N'EST PAS accepté ici est aussi important que le reste :
 *  - **pas d'`invoiceId`** — il vient de l'URL, sous le scope tenant. Le lire
 *    dans le corps permettrait de rattacher un encaissement à la facture d'une
 *    autre société (§5.3) ;
 *  - **pas de devise** — celle de la facture s'impose. Encaisser en euros une
 *    facture en dirhams demanderait un taux de change historisé, que le module
 *    multi-devises apportera ;
 *  - **pas de statut de facture** — il est DÉDUIT du cumul, jamais transmis.
 *
 * Les champs d'EFFET BANCAIRE (numéro, dates, statut, scan) suivent le mode :
 * exigés sur un chèque ou une LCN, ignorés ailleurs. La règle est portée deux
 * fois — ici pour un 422 lisible, et par
 * `payments_check_fields_check` en base, qui tient aussi pour les imports.
 */
class PaymentStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // En CENTIMES entiers, comme toute la chaîne de montants (§7). Le
            // plafond reprend celui des documents : il protège du dépassement
            // d'entier 64 bits lors des sommes.
            'amountCents' => ['required', 'integer', 'min:1', 'max:9999999999999'],
            'paidOn' => ['required', 'date'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // ── Effets bancaires ─────────────────────────────────────────
            'checkNumber' => [
                Rule::requiredIf(fn (): bool => $this->isBankInstrument()),
                'nullable',
                'string',
                'max:50',
            ],
            'checkStatus' => [
                Rule::requiredIf(fn (): bool => $this->isBankInstrument()),
                'nullable',
                Rule::enum(CheckStatus::class),
            ],
            'bankDepositDate' => ['nullable', 'date'],
            // La réception PRÉCÈDE la remise en banque : on ne dépose pas un
            // titre qu'on n'a pas encore reçu. Comparaison seulement si les
            // deux dates sont là — chacune reste facultative.
            'receivedDate' => ['nullable', 'date', 'before_or_equal:bankDepositDate'],

            // Le scan n'a de sens que sur un titre. `mimes` s'appuie sur le
            // contenu réel du fichier et non sur son extension (§10) ; 8 Mo
            // couvre un scan A4 en 300 dpi sans ouvrir la porte à un dépôt de
            // fichiers déguisé.
            'scan' => [
                'nullable',
                Rule::prohibitedIf(fn (): bool => ! $this->isBankInstrument()),
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:8192',
            ],
        ];
    }

    /** Le mode transmis est-il un chèque ou une LCN ? */
    protected function isBankInstrument(): bool
    {
        $method = PaymentMethod::tryFrom((string) $this->input('method'));

        return $method !== null && $method->isBankInstrument();
    }

    /**
     * Charge utile prête pour le service, en `snake_case`.
     *
     * La traduction se fait ICI et non dans le contrôleur : celui-ci orchestre,
     * il ne connaît pas les colonnes (§6).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'amount_cents' => $validated['amountCents'],
            'paid_on' => $validated['paidOn'],
            'method' => $validated['method'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'check_number' => $validated['checkNumber'] ?? null,
            'check_status' => $validated['checkStatus'] ?? null,
            'bank_deposit_date' => $validated['bankDepositDate'] ?? null,
            'received_date' => $validated['receivedDate'] ?? null,
        ];
    }
}
