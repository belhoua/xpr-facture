<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Requests;

use App\Modules\Conventions\Enums\ConventionStatus;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Création d'un contrat de convention.
 *
 * L'autorisation est portée par le middleware `permission:conventions.create`
 * sur la route ; ce FormRequest ne valide que la forme des données.
 *
 * Le total est reçu en CENTIMES (§7) : l'API ne connaît pas les décimales, la
 * conversion depuis les MAD saisis a lieu à la frontière du formulaire.
 */
class ConventionStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->requireId();

        return [
            // Le tiers et le document source doivent appartenir à la SOCIÉTÉ
            // ACTIVE : sans ce filtre, un identifiant deviné rattacherait la
            // convention aux données d'une autre société (§5.3).
            'partnerId' => [
                'nullable',
                'uuid',
                Rule::exists('partners', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'sourceDocumentId' => [
                'nullable',
                'uuid',
                Rule::exists('documents', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],

            'dossierNumber' => ['nullable', 'string', 'max:40', $this->uniqueDossierNumber()],
            'status' => ['nullable', Rule::enum(ConventionStatus::class)],
            'issueCity' => ['nullable', 'string', 'max:100'],
            'issuedAt' => ['nullable', 'date'],

            'ownerName' => [...$this->requiredOnCreate(), 'string', 'min:2', 'max:255'],
            // 15 chiffres exactement, comme la contrainte CHECK en base (§3).
            'ownerIce' => ['nullable', 'string', 'regex:/^[0-9]{15}$/'],
            'ownerRc' => ['nullable', 'string', 'max:40'],
            'ownerAddress' => ['nullable', 'string', 'max:1000'],

            // Le projet est ce que la convention a d'irréductible : sans lui,
            // le contrat imprimé n'a pas d'objet.
            'projectDescription' => [...$this->requiredOnCreate(), 'string', 'min:3', 'max:2000'],
            'projectAddress' => ['nullable', 'string', 'max:1000'],
            'projectTitleDeed' => ['nullable', 'string', 'max:60'],

            // Lots contrôlés (article 1). Liste de libellés libres : la
            // nomenclature des corps d'état varie d'un projet à l'autre, et un
            // référentiel figé se ferait contourner dès le premier lot atypique.
            'lots' => ['nullable', 'array', 'max:30'],
            'lots.*' => ['required', 'string', 'min:2', 'max:255'],

            'executionDelay' => ['nullable', 'string', 'max:255'],

            'totalCents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],

            // Échéancier de l'article 10, en pourcentages du forfait. Les trois
            // parts vont ENSEMBLE (`required_with` croisé) : n'en transmettre
            // qu'une obligerait le serveur à supposer les deux autres, et il
            // supposerait tôt ou tard autre chose que ce que porte la ligne en
            // base — donc à valider une somme qui n'est pas celle qu'on écrit.
            'advancePercent' => ['nullable', 'required_with:visaPercent,completionPercent', 'integer', 'min:0', 'max:100'],
            'visaPercent' => ['nullable', 'required_with:advancePercent,completionPercent', 'integer', 'min:0', 'max:100'],
            'completionPercent' => ['nullable', 'required_with:advancePercent,visaPercent', 'integer', 'min:0', 'max:100'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * L'échéancier doit couvrir EXACTEMENT le forfait.
     *
     * Vérifié ici en plus de la contrainte CHECK `conventions_schedule_check` :
     * la base garantit l'intégrité, mais une violation en sortirait sous forme
     * d'erreur serveur illisible. La validation produit un message rattaché à un
     * champ, exploitable par le formulaire.
     *
     * La somme n'est contrôlée que si les trois parts sont là — une création qui
     * n'en parle pas hérite des 25/25/50 de la base, et le `required_with`
     * croisé ci-dessus a déjà refusé les transmissions partielles.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $keys = ['advancePercent', 'visaPercent', 'completionPercent'];

            foreach ($keys as $key) {
                if (! $this->has($key)) {
                    return;
                }
            }

            // Les erreurs de forme (une part à 120, une chaîne) ont déjà été
            // relevées : additionner par-dessus produirait un second message
            // pour la même faute.
            if ($validator->errors()->hasAny($keys)) {
                return;
            }

            $total = array_sum(array_map(
                fn (string $key): int => (int) $this->input($key),
                $keys,
            ));

            if ($total !== 100) {
                $validator->errors()->add(
                    'advancePercent',
                    __('The payment schedule must add up to 100% (currently :total%).', ['total' => $total]),
                );
            }
        });
    }

    /**
     * Unicité du n° de dossier dans le périmètre de la SOCIÉTÉ ACTIVE, jamais
     * globale : deux bureaux de contrôle distincts peuvent parfaitement suivre
     * des dossiers homonymes. Le company_id vient du contexte tenant (§5.3), et
     * les conventions archivées ne bloquent pas leur numéro — comme dans l'index
     * partiel en base.
     */
    /**
     * Marque les deux champs sans lesquels une convention n'a pas d'objet — le
     * maître d'ouvrage et le projet.
     *
     * Surchargé par la mise à jour, où ils deviennent `sometimes|required` :
     * corriger le seul titre foncier ne doit pas obliger à retransmettre une
     * identité qui n'a pas changé. Un point de bascule plutôt qu'un tableau de
     * règles recopié dans la sous-classe, qui divergerait au premier
     * ajustement.
     *
     * @return list<string>
     */
    protected function requiredOnCreate(): array
    {
        return ['required'];
    }

    protected function uniqueDossierNumber(): Unique
    {
        return Rule::unique('conventions', 'dossier_number')
            ->where('company_id', app(TenantContext::class)->requireId())
            ->whereNull('deleted_at');
    }
}
