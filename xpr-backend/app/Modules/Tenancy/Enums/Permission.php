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

    case InvoicesView = 'invoices.view';
    case InvoicesCreate = 'invoices.create';
    case InvoicesUpdate = 'invoices.update';
    case InvoicesDelete = 'invoices.delete';
    /** Annuler une facture VALIDÉE : acte fiscal, plus sensible qu'une édition. */
    case InvoicesCancel = 'invoices.cancel';

    case PartnersView = 'partners.view';
    case PartnersCreate = 'partners.create';
    case PartnersUpdate = 'partners.update';
    /** Archivage d'un tiers : il disparaît des listes sans perdre son historique. */
    case PartnersDelete = 'partners.delete';

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
