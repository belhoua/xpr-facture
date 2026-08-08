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

    case CashView = 'cash.view';
    case CashManage = 'cash.manage';

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
