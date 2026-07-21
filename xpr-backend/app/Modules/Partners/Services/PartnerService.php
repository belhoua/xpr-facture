<?php

declare(strict_types=1);

namespace App\Modules\Partners\Services;

use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecture et écriture du répertoire des tiers.
 *
 * Le `company_id` n'est jamais manipulé ici : BelongsToCompany le renseigne à
 * la création et cloisonne toutes les requêtes (§5).
 */
final class PartnerService
{
    /**
     * @param  array{type?: ?string, search?: ?string, active?: ?bool, perPage?: ?int}  $filters
     * @return LengthAwarePaginator<int, Partner>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Partner::query();

        if (($type = $filters['type'] ?? null) !== null) {
            $query->ofType(PartnerType::from($type));
        }

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($active = $filters['active'] ?? null) !== null) {
            $query->where('is_active', $active);
        }

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $query
            ->orderBy('legal_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Résout un tiers de la SOCIÉTÉ ACTIVE.
     *
     * Volontairement pas un binding implicite de route : SubstituteBindings
     * s'exécute avant le middleware `tenant`, donc avant que le scope ne soit
     * armé — la résolution se ferait alors sans filtre de société. En passant
     * par le modèle ici, le global scope s'applique et une fiche d'une autre
     * société est introuvable, donc 404.
     */
    public function findForCompany(string $id): Partner
    {
        $partner = Partner::query()->find($id);

        if (! $partner instanceof Partner) {
            throw (new ModelNotFoundException)->setModel(Partner::class, [$id]);
        }

        return $partner;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Partner
    {
        $partner = Partner::query()->create($this->toColumns($data));

        // Recharge les colonnes dont la valeur par défaut est posée par
        // PostgreSQL (is_active, country, currency, payment_terms_days) :
        // l'instance issue de create() ne les connaît pas et la réponse JSON
        // les rendrait à null.
        return $partner->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(Partner $partner, array $data): Partner
    {
        $partner->update($this->toColumns($data));

        return $partner->refresh();
    }

    /**
     * Archivage (soft delete) et non suppression : un tiers est référencé par
     * des documents commerciaux que la loi impose de conserver. Effacer la
     * fiche rendrait ces documents illisibles.
     */
    public function archive(Partner $partner): void
    {
        $partner->delete();
    }

    /**
     * Traduit le payload camelCase de l'API vers les colonnes snake_case.
     * Les clés absentes ne sont pas écrites : une mise à jour partielle ne doit
     * pas réinitialiser les champs non transmis.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        $map = [
            'type' => 'type',
            'code' => 'code',
            'legalName' => 'legal_name',
            'tradeName' => 'trade_name',
            'legalForm' => 'legal_form',
            'ice' => 'ice',
            'ifNumber' => 'if_number',
            'rcNumber' => 'rc_number',
            'rcCity' => 'rc_city',
            'patente' => 'patente',
            'contactName' => 'contact_name',
            'email' => 'email',
            'phone' => 'phone',
            'address' => 'address',
            'city' => 'city',
            'country' => 'country',
            'currency' => 'currency',
            'paymentTermsDays' => 'payment_terms_days',
            'notes' => 'notes',
            'isActive' => 'is_active',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        return $columns;
    }
}
