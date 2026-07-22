<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Requests;

use App\Modules\Catalog\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'une catégorie. L'autorisation est portée par le middleware
 * `permission:catalog.create` sur la route ; ce FormRequest ne valide que la
 * forme des données.
 */
class CategoryStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:500'],
            // Même forme que la contrainte CHECK en base.
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Unicité du nom dans la société active, INSENSIBLE À LA CASSE.
     *
     * Une closure et non `Rule::unique` : la règle native compare avec `=`,
     * alors que l'index `categories_company_name_unique` porte sur
     * `lower(name)`. « Impression » passerait la validation puis heurterait
     * l'index, et l'utilisateur recevrait une erreur serveur au lieu d'un
     * message rattaché au champ.
     *
     * Le cloisonnement par société est assuré par le global scope de
     * BelongsToCompany : il n'y a pas de company_id à passer ici (§5.3).
     */
    protected function uniqueName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $exists = Category::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->when($this->ignoredId() !== null, fn ($query) => $query->whereKeyNot($this->ignoredId()))
                ->exists();

            if ($exists) {
                $fail(__('validation.unique', ['attribute' => $attribute]));
            }
        };
    }

    /** Identifiant à exclure du contrôle d'unicité — aucun à la création. */
    protected function ignoredId(): ?string
    {
        return null;
    }
}
