<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Accounting\Models\Sequence;
use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vide les données d'exploitation en conservant le compte, la société et tout
 * le paramétrage — la remise à blanc d'un environnement qui a servi d'essai.
 *
 * Le pendant destructeur de `CreateAdminCommand` : celle-ci amorce un espace de
 * travail, celle-là le remet à zéro. Enregistrées au même endroit pour la même
 * raison — un module n'a pas besoin d'un provider par classe.
 *
 * ── Ce qui part, ce qui reste ─────────────────────────────────────────────
 *
 * Part : les pièces commerciales et leurs lignes, les règlements, les
 * conventions et leurs dépôts, les projets et leurs livrables, les notes, les
 * mouvements de caisse, les tiers.
 *
 * Reste, et ce n'est pas un oubli :
 *  - les COMPTES, sociétés, rattachements et rôles — la purge ne supprime
 *    aucun utilisateur, quel qu'il soit ;
 *  - le PARAMÉTRAGE : exercices, taux de TVA, devises, réglages, et les
 *    séquences, dont seuls les compteurs sont remis à 1 ;
 *  - le CATALOGUE (catégories, articles, services) : il n'a pas été demandé,
 *    et il est provisionné à l'inscription — le vider laisserait une société
 *    dans un état qu'aucune inscription ne produit ;
 *  - `audit_logs` : c'est le journal, et un journal qui s'efface avec ce qu'il
 *    relate ne journalise rien.
 *
 * ── Pourquoi AUCUNE contrainte n'est désactivée ───────────────────────────
 *
 * `SET CONSTRAINTS ALL DEFERRED` ne peut rien ici : PostgreSQL ne diffère que
 * les contraintes déclarées DEFERRABLE, et aucune de celles du schéma ne l'est
 * (Laravel ne les crée pas ainsi). Quant à couper les FK — `ALTER TABLE …
 * DISABLE TRIGGER ALL`, ou `session_replication_role = replica` — cela
 * demanderait un superuser et supprimerait précisément le filet qui signale
 * qu'on a oublié une table : la purge « réussirait » en laissant des lignes
 * orphelines dont plus rien ne dit à quoi elles se rattachaient.
 *
 * L'ordre de `PURGE_ORDER` suit donc les dépendances réelles, des enfants vers
 * les parents. Une contrainte qui refuse est une information : elle dit qu'une
 * table manque à la liste.
 *
 * ── La garde qui compte vraiment ──────────────────────────────────────────
 *
 * Le rôle applicatif `xpr_app` n'a pas `BYPASSRLS` (§5). Sous ce rôle, un
 * `DELETE` sans contexte tenant ne voit AUCUNE ligne : il réussit, ne supprime
 * rien, et ne dit rien. La commande recompte donc chaque table après coup et
 * ANNULE la transaction si quoi que ce soit subsiste — sans ce recomptage, la
 * purge la plus dangereuse serait celle qui ne fait rien en annonçant le
 * contraire.
 */
final class PurgeDataCommand extends Command
{
    use ConfirmableTrait;

    /**
     * Tables vidées, DES ENFANTS VERS LES PARENTS.
     *
     * L'ordre n'est pas cosmétique, il est dicté par les clés étrangères du
     * schéma : `documents.partner_id` et `projects.partner_id` sont en
     * RESTRICT, les tiers ne peuvent donc partir qu'en dernier. Réordonner
     * cette liste sans relire les contraintes fera échouer la commande — ce
     * qui est le comportement voulu.
     *
     * @var list<string>
     */
    private const PURGE_ORDER = [
        'document_items',
        // AVANT `payments` depuis le miroir du 2026-08-25 : un mouvement de
        // caisse peut être la copie d'un règlement (`payment_id`). La clé est
        // en `nullOnDelete`, l'ordre inverse ne lèverait donc pas — il
        // laisserait seulement une table se vider en passant d'abord par un
        // UPDATE inutile de toutes ses lignes. L'ordre annoncé est celui des
        // enfants vers les parents ; il doit le rester pour se relire.
        'cash_movements',
        'payments',
        'file_deposits',
        'conventions',
        'deliverables',
        'documents',
        'projects',
        'admin_notes',
        'partners',
    ];

    protected $signature = 'xpr:purge-data
        {--keep= : E-mail du compte dont l\'intégrité est vérifiée avant ET après (obligatoire)}
        {--dry-run : Inventorie ce qui serait supprimé, sans rien écrire}
        {--force : Passe la confirmation — indispensable en shell non interactif}';

    protected $description = 'Vide les données d\'exploitation en conservant comptes, société et paramétrage';

    public function handle(PermissionRegistrar $permissions): int
    {
        $email = $this->keptEmail();

        if ($email === null) {
            $this->error('--keep est obligatoire : la commande refuse de purger sans compte à vérifier.');

            return self::FAILURE;
        }

        // Vérifié AVANT d'écrire quoi que ce soit. Une purge lancée avec un
        // e-mail mal orthographié partirait sans que personne ne s'aperçoive
        // que la garde ne gardait rien.
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error("Compte introuvable : {$email}");
            $this->line('Aucune donnée n\'a été touchée.');

            return self::FAILURE;
        }

        $inventory = $this->inventory();
        $total = array_sum($inventory);

        $this->table(
            ['Table', 'Lignes'],
            array_map(
                static fn (string $table, int $count): array => [$table, (string) $count],
                array_keys($inventory),
                $inventory,
            ),
        );

        if ($this->option('dry-run') === true) {
            $this->info("Simulation : {$total} ligne(s) seraient supprimée(s). Rien n'a été écrit.");

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('Rien à supprimer. Les compteurs de numérotation sont tout de même remis à 1.');
        }

        // `confirmToProceed` refuse en production sans `--force`, et pose la
        // question ailleurs. La purge est irréversible : les lignes partent
        // pour de bon, soft delete compris.
        if (! $this->confirmToProceed('Purge IRRÉVERSIBLE de '.$total.' ligne(s)')) {
            return self::FAILURE;
        }

        try {
            $purged = DB::transaction(fn (): array => $this->purge());
        } catch (\Throwable $exception) {
            $this->error('Échec : '.$exception->getMessage());
            $this->line('La transaction a été annulée — la base est dans son état d\'origine.');

            return self::FAILURE;
        }

        $this->table(
            ['Table', 'Supprimées'],
            array_map(
                static fn (string $table, int $count): array => [$table, (string) $count],
                array_keys($purged),
                $purged,
            ),
        );

        return $this->reportAccount($permissions, $email);
    }

    /**
     * Compte ce que la purge emporterait, table par table.
     *
     * `DB::table()` et non les modèles Eloquent : ceux-ci portent le global
     * scope tenant et le soft delete, qui masqueraient une partie des lignes —
     * un inventaire qui annonce moins que ce qu'il supprime est pire que pas
     * d'inventaire.
     *
     * @return array<string, int>
     */
    private function inventory(): array
    {
        $counts = [];

        foreach (self::PURGE_ORDER as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Vide les tables, puis remet les compteurs à 1.
     *
     * @return array<string, int>
     *
     * @throws RuntimeException si une table n'est pas vide en fin d'opération,
     *                          ce qui annule la transaction entière.
     */
    private function purge(): array
    {
        // Dénoue l'auto-référence AVANT toute suppression. `parent_document_id`
        // est en RESTRICT : PostgreSQL vérifie cette contrainte ligne à ligne,
        // sans attendre la fin de l'instruction, et refuserait donc de
        // supprimer un devis dont la facture part pourtant dans le même
        // `DELETE`. Mettre le lien à NULL d'abord évite d'avoir à deviner un
        // ordre de suppression à l'intérieur d'une même table.
        DB::table('documents')->update(['parent_document_id' => null]);

        $purged = [];

        foreach (self::PURGE_ORDER as $table) {
            $purged[$table] = DB::table($table)->delete();
        }

        // Le RECOMPTAGE est la garde décrite dans le docblock de classe : sous
        // un rôle soumis à la RLS, les `delete()` ci-dessus renvoient 0 sans
        // erreur. L'exception annule la transaction et fait échouer la commande
        // — un demi-nettoyage silencieux serait bien pire qu'un échec.
        foreach (self::PURGE_ORDER as $table) {
            $remaining = DB::table($table)->count();

            if ($remaining > 0) {
                throw new RuntimeException(
                    "La table {$table} contient encore {$remaining} ligne(s) après suppression. "
                    .'Connexion soumise à la RLS sans contexte tenant, ou droits insuffisants.',
                );
            }
        }

        // Les compteurs repartent à 1, le FORMAT est conservé : « réinitialiser
        // la numérotation » ne veut pas dire « oublier le paramétrage ».
        // Vider la table produirait le même premier numéro, mais ferait
        // recréer les lignes au format par défaut et perdrait un format
        // personnalisé. Le millésime, lui, vient de l'exercice et non de la
        // date du jour (§15) : DEV-2026-0001 tant que l'exercice est 2026.
        Sequence::query()->update(['next_number' => 1, 'updated_at' => now()]);

        return $purged;
    }

    /**
     * Vérifie que le compte préservé est toujours opérationnel, et le dit.
     *
     * Ce n'est pas une formalité : un compte survit à la purge mais devient
     * inutilisable si sa société a disparu, si son rattachement est tombé ou si
     * son rôle n'est plus lisible. Les quatre points sont donc vérifiés
     * séparément — « l'utilisateur existe encore » ne répond pas à la question.
     */
    private function reportAccount(PermissionRegistrar $permissions, string $email): int
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || $user->trashed()) {
            $this->error("Le compte {$email} n'est plus actif après la purge.");

            return self::FAILURE;
        }

        $company = $user->companies()->first();

        if (! $company instanceof Company) {
            $this->error("Le compte {$email} n'est plus rattaché à aucune société.");

            return self::FAILURE;
        }

        // Le rôle est scopé à la société (mode teams de Spatie) : sans
        // `setPermissionsTeamId`, le registre interroge le périmètre `null` et
        // répondrait « aucun rôle » sur un compte parfaitement sain (§15).
        $previousTeamId = $permissions->getPermissionsTeamId();
        $permissions->setPermissionsTeamId($company->id);

        try {
            $roles = $user->getRoleNames()->implode(', ');
        } finally {
            $permissions->setPermissionsTeamId($previousTeamId);
        }

        if ($roles === '') {
            $this->error("Le compte {$email} n'a plus aucun rôle dans {$company->legal_name}.");

            return self::FAILURE;
        }

        $this->info('✅ Purge terminée. Compte vérifié :');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['E-mail', $user->email],
                // Littéral et non recalculé : la garde ci-dessus a déjà fait
                // échouer la commande si le compte était supprimé. La ligne
                // reste affichée parce que c'est la question que se pose
                // l'opérateur en lisant ce tableau.
                ['Actif', 'oui'],
                ['E-mail vérifié', $user->email_verified_at !== null ? 'oui' : 'non'],
                ['Société', $company->legal_name],
                ['Rôle(s)', $roles],
                ['Prochain devis', $this->preview('quote')],
                ['Prochaine facture', $this->preview('invoice')],
            ],
        );

        return self::SUCCESS;
    }

    /** Numéro que prendra la prochaine pièce du type, tel qu'il sera imprimé. */
    private function preview(string $type): string
    {
        $sequence = Sequence::query()->where('document_type', $type)->first();

        if (! $sequence instanceof Sequence) {
            return 'séquence absente — créée à la première pièce';
        }

        $fiscalYear = $sequence->fiscalYear;

        return $fiscalYear === null
            ? $sequence->format
            : $sequence->formatNumber($sequence->next_number, $fiscalYear);
    }

    /** Les options d'artisan sont typées `mixed` : on n'en accepte qu'une chaîne non vide. */
    private function keptEmail(): ?string
    {
        $value = $this->option('keep');

        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }
}
