<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comptes de l'écran « Avancement de projet » : total, en cours, à compléter,
 * terminés.
 *
 * Des NOMBRES DE PROJETS, jamais de montants. Un projet n'est pas une pièce
 * commerciale : il n'a ni total, ni TVA, ni règlement (cf. le schéma de la
 * table). Ce qu'un chantier a rapporté se lit sur les documents qui lui sont
 * rattachés — c'est la question de l'écran « situations par client », filtré par
 * projet, où les règlements sont connus.
 *
 * Endpoint séparé de la liste plutôt qu'un bloc `meta` sur celle-ci : la page 2
 * renverrait les mêmes comptes et les recalculerait pour rien, alors que les
 * cartes, elles, ne changent pas d'une page à l'autre.
 *
 * Accepte les mêmes filtres que la liste, à la pagination près — les chiffres
 * affichés doivent décrire exactement les lignes en dessous.
 */
final class ProjectSummaryController
{
    public function __construct(private readonly ProjectService $projects) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->projects->summary([
            'search' => $request->string('search')->toString() ?: null,
            // Chaîne vide ⇒ pas de filtre. « all » est un état de l'interface,
            // pas une valeur d'enum : le laisser passer ferait lever
            // ProjectStatus::from().
            'status' => $request->string('status')->toString() ?: null,
            'partnerId' => $request->string('partnerId')->toString() ?: null,
        ]));
    }
}
