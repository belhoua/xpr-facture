<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Permissions fines de l'application. Source de vérité UNIQUE : le seeder les
 * crée à partir d'ici, les Policies et les middlewares de route les consomment
 * par leur constante — aucune chaîne magique dans le code métier.
 *
 * Nommage `<domaine>.<action>`. Une permission ajoutée ici doit être rattachée
 * à au moins un rôle dans Role::permissions(), sinon personne ne la détient.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    // Documents commerciaux — devis, factures, avoirs et les types dérivés.
    // Nommés `documents.*` et non `invoices.*` depuis que les 8 types partagent
    // un moteur unique : un droit qui autorise à créer un devis ne peut pas
    // s'appeler « invoices.create » sans tromper la lecture d'un audit.
    case DocumentsView = 'documents.view';
    case DocumentsCreate = 'documents.create';
    case DocumentsUpdate = 'documents.update';
    case DocumentsDelete = 'documents.delete';
    /** Émettre : attribue le numéro fiscal et gèle le document. Acte engageant. */
    case DocumentsIssue = 'documents.issue';
    /** Annuler un document VALIDÉ : acte fiscal, plus sensible qu'une édition. */
    case DocumentsCancel = 'documents.cancel';

    case CatalogView = 'catalog.view';
    case CatalogCreate = 'catalog.create';
    case CatalogUpdate = 'catalog.update';
    /** Archivage d'un article : il quitte les listes, les documents le gardent. */
    case CatalogDelete = 'catalog.delete';

    case PartnersView = 'partners.view';
    case PartnersCreate = 'partners.create';
    case PartnersUpdate = 'partners.update';
    /** Archivage d'un tiers : il disparaît des listes sans perdre son historique. */
    case PartnersDelete = 'partners.delete';

    // Contrats de convention de contrôle et suivi. Famille distincte des
    // documents commerciaux : une convention n'est pas une pièce fiscale, elle
    // ENGAGE une mission — c'est un acte de direction, pas de facturation, et
    // le rôle qui rédige un devis n'est pas nécessairement celui qui signe.
    case ConventionsView = 'conventions.view';
    case ConventionsCreate = 'conventions.create';
    case ConventionsUpdate = 'conventions.update';
    case ConventionsDelete = 'conventions.delete';

    /**
     * Dépôts de dossier. Deux permissions seulement là où les conventions en ont
     * quatre : le dépôt est un SUIVI administratif, pas un engagement. Rien ne
     * distingue le risque d'en corriger un de celui d'en supprimer un — un
     * découpage plus fin n'aurait servi qu'à faire nombre.
     */
    case DepositsView = 'deposits.view';
    case DepositsManage = 'deposits.manage';

    /**
     * Avancement de projet et livrables remis au client.
     *
     * Quatre permissions, comme les conventions : un projet se supprime — il
     * n'est opposable à personne, aucune règle d'immuabilité ne le retient —
     * et effacer le suivi d'une mission n'engage pas la même responsabilité
     * que d'en corriger le pourcentage.
     *
     * Les LIVRABLES n'ont pas de permission propre : ajouter une remise à un
     * projet, c'est mettre à jour son avancement, et un droit distinct aurait
     * créé un rôle capable de voir un projet sans voir ce qu'on en a livré.
     */
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';

    /**
     * Référentiel des SERVICES (`Services\Models\Service`), qui classe les
     * projets — à ne pas confondre avec l'article de catalogue de type
     * « service », couvert par `catalog.*`.
     */
    case ServicesView = 'services.view';
    case ServicesManage = 'services.manage';

    case CashView = 'cash.view';
    case CashManage = 'cash.manage';

    /**
     * Règlements reçus sur les factures.
     *
     * Deux permissions et non quatre, comme les dépôts : enregistrer un
     * encaissement et le retirer engagent le même niveau de responsabilité —
     * les deux déplacent le solde d'une facture et changent son statut. Un
     * découpage plus fin n'aurait servi qu'à faire nombre.
     *
     * DISTINCTES de `cash.*` : la caisse suit les flux d'espèces d'un point de
     * vente, les règlements soldent une créance nominative. Un commercial peut
     * avoir à encaisser sans voir la caisse de l'entreprise.
     */
    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';

    case UsersView = 'users.view';
    case UsersInvite = 'users.invite';

    case AdminNotesView = 'admin-notes.view';
    case AdminNotesCreate = 'admin-notes.create';

    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
