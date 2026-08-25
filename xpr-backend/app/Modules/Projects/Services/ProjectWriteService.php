<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les projets et leurs livrables.
 *
 * Deux règles y sont tenues, qu'aucun contrôleur ne doit rejouer :
 *
 *  1. **Le client doit exister DANS LA SOCIÉTÉ ACTIVE.** La vérification passe
 *     par une requête scopée et non par une règle `exists` de validation :
 *     `Rule::exists('partners', 'id')` interroge la table sans le global scope
 *     et accepterait le client d'une autre société (§5.3).
 *  2. **Un projet ANNULÉ ne progresse plus.** Lui pousser un pourcentage
 *     décrirait un travail qui n'aura pas lieu ; les états « achevé » et
 *     « en suivi » restent ouverts, on corrige un chiffre après coup et on
 *     remet un livrable pendant la garantie.
 *
 * Pas de `DB::transaction()` ici : chaque écriture porte sur UNE ligne. En
 * ouvrir une n'ajouterait aucune garantie et masquerait, par mimétisme, les
 * endroits où elle est réellement indispensable.
 */
final class ProjectWriteService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Project
    {
        $partnerId = $this->requireCompanyPartner((string) $data['partnerId']);

        $project = Project::query()->create([
            'partner_id' => $partnerId,
            // Facultatif : absent ou null, le projet reste non classé. La
            // FormRequest a déjà vérifié qu'il appartient à la société.
            'service_id' => self::serviceId($data),
            'title' => trim((string) $data['title']),
            'status' => $this->statusOrDefault($data),
            'progress_percentage' => (int) ($data['progressPercentage'] ?? 0),
            'description' => $this->trimmedOrNull($data['description'] ?? null),
            'created_by' => Auth::id(),
        ]);

        // Recharge les colonnes dont la valeur par défaut est posée par
        // PostgreSQL : l'instance issue de create() ne les connaît pas et la
        // réponse JSON les rendrait nulles.
        return $project->refresh()->load(['partner', 'service', 'deliverables']);
    }

    /**
     * Le chantier de ce client portant cet intitulé — réutilisé, ou ouvert.
     *
     * Point d'entrée de l'ouverture AUTOMATIQUE d'un projet depuis un devis
     * (cf. `DocumentWriteService::withAutoProject()`, qui dit pourquoi la règle
     * existe et ce qu'elle coûte). Il vit ici et non dans le module Documents :
     * créer un projet est une écriture du module Projets, et la dupliquer
     * ailleurs ferait diverger les deux au premier champ ajouté.
     *
     * ── Réutiliser plutôt que créer ───────────────────────────────────────
     *
     * La recherche est insensible à la CASSE et aux espaces de bord. Deux devis
     * du même chantier écrits « Villa Anfa » et « villa anfa  » ouvriraient
     * sinon deux chantiers concurrents, et l'écran d'avancement afficherait
     * deux fois la même affaire. `LOWER(BTRIM(...))` des deux côtés : la
     * comparaison porte sur ce que l'œil lit, pas sur ce que la frappe a laissé.
     *
     * Aucun index ne couvre cette expression. C'est assumé : la requête est
     * déjà restreinte à un client par `partner_id`, qui est indexé
     * (`projects_company_id_partner_id_index`), et un client compte des projets
     * par dizaines, pas par milliers.
     *
     * Les projets SUPPRIMÉS ne sont pas réutilisés — le global scope de soft
     * delete les écarte. Un chantier qu'on a retiré de l'écran ne doit pas
     * ressusciter parce qu'un devis reprend son intitulé.
     *
     * Le projet naît « en cours » à 0 %, sans description ni livrable : il est
     * donc « à compléter » au sens de l'écran d'avancement, et c'est exact —
     * il n'a qu'un nom et un client (cf. `docs/modules/projects.md`).
     */
    public function openFor(string $partnerId, string $title): Project
    {
        $title = trim($title);

        $existing = Project::query()
            ->where('partner_id', $partnerId)
            ->whereRaw('LOWER(BTRIM(title)) = LOWER(?)', [$title])
            ->first();

        if ($existing instanceof Project) {
            return $existing;
        }

        return Project::query()->create([
            'partner_id' => $partnerId,
            'title' => $title,
            'status' => ProjectStatus::InProgress->value,
            'progress_percentage' => 0,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Correction d'un projet.
     *
     * PATCH partiel : seules les clés PRÉSENTES sont écrites. C'est ce qui
     * permet à l'écran de détail de ne pousser que l'avancement sans avoir à
     * renvoyer le titre et le client, qu'il n'a pas modifiés.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        if (array_key_exists('partnerId', $data)) {
            $project->partner_id = $this->requireCompanyPartner((string) $data['partnerId']);
        }

        if (array_key_exists('title', $data)) {
            $project->title = trim((string) $data['title']);
        }

        // La clé ABSENTE ne touche à rien — corriger un avancement ne doit pas
        // déclasser le projet ; la clé à null retire le classement.
        if (array_key_exists('serviceId', $data)) {
            $project->service_id = self::serviceId($data);
        }

        if (array_key_exists('description', $data)) {
            $project->description = $this->trimmedOrNull($data['description']);
        }

        if (array_key_exists('status', $data)) {
            $project->status = ProjectStatus::from((string) $data['status']);
        }

        if (array_key_exists('progressPercentage', $data)) {
            $this->assertProgressAllowed($project);

            $project->progress_percentage = (int) $data['progressPercentage'];
        }

        $project->save();

        return $project->refresh()->load(['partner', 'service', 'deliverables']);
    }

    /**
     * Service transmis, ramené à `null` quand il est vide.
     *
     * Le déroulant de l'écran rend une chaîne vide pour « Aucun » — Radix
     * n'acceptant pas la valeur nulle sur un `<SelectItem>` — et cette chaîne
     * ne doit jamais atteindre la colonne : la clé étrangère la refuserait.
     *
     * @param  array<string, mixed>  $data
     */
    private static function serviceId(array $data): ?string
    {
        $serviceId = $data['serviceId'] ?? null;

        return is_string($serviceId) && $serviceId !== '' ? $serviceId : null;
    }

    /**
     * Retrait d'un projet. Soft delete : ses livrables restent en base et
     * reviendraient avec lui, la cascade SQL ne se déclenchant qu'à
     * l'effacement dur.
     */
    public function delete(Project $project): void
    {
        $project->delete();
    }

    /**
     * Ajout d'un livrable remis.
     *
     * Le projet vient du CHEMIN de la route, jamais du corps de la requête : il
     * a déjà été résolu sous le scope tenant, alors qu'un `projectId` posté
     * permettrait d'accrocher une remise au projet d'une autre société (§5.3).
     *
     * @param  array<string, mixed>  $data
     */
    public function addDeliverable(Project $project, array $data): Deliverable
    {
        $deliverable = Deliverable::query()->create([
            'project_id' => $project->id,
            'title' => trim((string) $data['title']),
            'delivery_date' => $data['deliveryDate'],
            'notes' => $this->trimmedOrNull($data['notes'] ?? null),
            'created_by' => Auth::id(),
        ]);

        return $deliverable->refresh();
    }

    public function deleteDeliverable(Deliverable $deliverable): void
    {
        $deliverable->delete();
    }

    /**
     * @throws ConflictHttpException si le client n'appartient pas à la société
     *                               active — 409 et non 404 : le client existe
     *                               peut-être, mais pas ici.
     */
    private function requireCompanyPartner(string $partnerId): string
    {
        $partner = Partner::query()->find($partnerId);

        if (! $partner instanceof Partner) {
            throw new ConflictHttpException(__('The selected client does not belong to this company.'));
        }

        return $partner->id;
    }

    /** @throws ConflictHttpException */
    private function assertProgressAllowed(Project $project): void
    {
        if ($project->status->isTerminal()) {
            throw new ConflictHttpException(__('A cancelled project cannot progress.'));
        }
    }

    /** @param  array<string, mixed>  $data */
    private function statusOrDefault(array $data): string
    {
        $status = $data['status'] ?? null;

        return is_string($status)
            ? ProjectStatus::from($status)->value
            : ProjectStatus::InProgress->value;
    }

    /** Une chaîne vide est une absence de saisie, pas une valeur. */
    private function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
