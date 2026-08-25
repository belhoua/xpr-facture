<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fait pointer `projects.service_id` vers le CATALOGUE (`products`) plutôt que
 * vers la table `services`.
 *
 * ── Pourquoi ────────────────────────────────────────────────────────────
 *
 * Le produit nommait « service » DEUX entités distinctes :
 *
 *  - l'écran `/services`, qui alimente `products` avec `type = 'service'`
 *    (prix, unité, TVA, catégorie) ;
 *  - la table `services`, un référentiel de classement ne portant qu'un nom,
 *    alimenté nulle part ailleurs que par le déroulant du dialogue projet.
 *
 * Conséquence observée : une prestation créée dans `/services` n'apparaissait
 * JAMAIS dans le champ du projet, qui interrogeait l'autre table. Le champ
 * annonçait « aucun service enregistré » à quelqu'un qui venait d'en créer.
 * Deux référentiels portant le même mot ne se rejoignent pas d'eux-mêmes : on
 * en garde UN, celui que l'utilisateur alimente réellement (décision du
 * 2026-08-26).
 *
 * ── Aucun classement n'est perdu ────────────────────────────────────────
 *
 * Chaque entrée de `services` est reprise dans le catalogue avant le
 * remappage. Les entrées ARCHIVÉES le sont aussi, et c'est indispensable : un
 * projet peut référencer un service soft-deleted, et la nouvelle clé étrangère
 * refuserait une valeur sans correspondance. Elles arrivent archivées dans le
 * catalogue, ce qui préserve exactement le comportement d'écran — le
 * classement demeure, le libellé n'est plus rendu.
 *
 * Le rapprochement par NOM (insensible à la casse et aux espaces de bord) évite
 * de créer un doublon quand la prestation existe déjà des deux côtés — le cas
 * nominal ici, l'utilisateur ayant saisi les mêmes intitulés sur les deux
 * écrans. Il ne s'applique qu'aux services ACTIFS : rapprocher un service
 * archivé d'un article actif de même nom ferait réapparaître un libellé qu'on
 * avait retiré.
 *
 * ── Ce que cette migration NE fait PAS ──────────────────────────────────
 *
 * Elle ne supprime ni la table `services`, ni son module, ni ses permissions.
 * L'endpoint `/api/v1/services` reste debout mais n'a plus de consommateur :
 * vider la pièce est une décision distincte de fermer la porte, et la table
 * garde la trace de ce qui a été repris.
 *
 * Le prix des articles créés ici est de ZÉRO : un référentiel de classement
 * n'en portait pas, et en inventer un le rendrait facturable par mégarde. Il
 * se corrige depuis `/services` comme n'importe quel article.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mapping = $this->importServicesIntoCatalog();

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
        });

        // Remappage APRÈS la levée de l'ancienne contrainte et AVANT la pose de
        // la nouvelle : entre les deux, la colonne porte des identifiants qui
        // n'appartiennent à aucune des deux tables. C'est la seule fenêtre où
        // l'écriture est possible.
        foreach ($mapping as $serviceId => $productId) {
            DB::table('projects')
                ->where('service_id', $serviceId)
                ->update(['service_id' => $productId]);
        }

        // Ceinture et bretelles : un projet dont le service n'a pas été repris
        // — la ligne aurait disparu de `services` par un effacement dur, que
        // `nullOnDelete` n'a pas propagé — bloquerait la pose de la contrainte.
        // On le déclasse plutôt que de faire échouer la migration : le projet
        // vaut mieux que son étiquette.
        DB::table('projects')
            ->whereNotNull('service_id')
            ->whereNotExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('products')
                ->whereColumn('products.id', 'projects.service_id'))
            ->update(['service_id' => null]);

        Schema::table('projects', function (Blueprint $table): void {
            // `nullOnDelete` comme avant : un article effacé pour de bon retire
            // le classement, il n'emporte pas le projet. Le cas reste rare, le
            // catalogue étant en soft delete (archivage).
            $table->foreign('service_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    /**
     * Reprend `services` dans `products` et rend la correspondance.
     *
     * @return array<string, string> identifiant de service → identifiant d'article
     */
    private function importServicesIntoCatalog(): array
    {
        if (! Schema::hasTable('services')) {
            return [];
        }

        $mapping = [];

        foreach (DB::table('services')->orderBy('created_at')->get() as $service) {
            // La forme de la ligne est déclarée ICI, au seul endroit qui lit la
            // table : `DB::table()` rend des `stdClass` sans forme connue, et
            // l'analyse statique refuse l'accès à leurs propriétés. Tout ce qui
            // suit ne manipule que des scalaires.
            /** @var object{id: string, company_id: string, name: string, created_at: string|null, updated_at: string|null, deleted_at: string|null} $service */
            $mapping[$service->id] = $this->importOne(
                companyId: $service->company_id,
                name: $service->name,
                createdAt: $service->created_at,
                updatedAt: $service->updated_at,
                archivedAt: $service->deleted_at,
            );
        }

        return $mapping;
    }

    /**
     * L'article de catalogue correspondant à UNE entrée du référentiel.
     *
     * Le rapprochement par nom ne joue que sur les entrées ACTIVES : reprendre
     * un service archivé sur un article actif de même nom ferait réapparaître
     * un libellé qu'on avait retiré. Une entrée archivée crée donc toujours son
     * propre article, archivé lui aussi.
     *
     * @return string identifiant de l'article
     */
    private function importOne(
        string $companyId,
        string $name,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $archivedAt,
    ): string {
        if ($archivedAt === null) {
            $existing = DB::table('products')
                ->where('company_id', $companyId)
                ->where('type', 'service')
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(BTRIM(name)) = LOWER(BTRIM(?))', [$name])
                ->value('id');

            if ($existing !== null) {
                return (string) $existing;
            }
        }

        return (string) DB::table('products')->insertGetId([
            'company_id' => $companyId,
            'type' => 'service',
            'name' => $name,
            // Les colonnes restantes prennent les défauts du schéma : prix à 0,
            // devise MAD, unité « unité », actif, hors stock. Aucune n'est
            // devinable depuis un référentiel qui ne portait qu'un libellé.
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'deleted_at' => $archivedAt,
        ], 'id');
    }

    /**
     * Repose la contrainte vers `services`, en remappant par le NOM.
     *
     * Le retour n'est pas parfait et ne peut pas l'être : rien n'enregistre
     * quel article venait de quel service, et les prestations créées au
     * catalogue depuis n'ont aucun équivalent en face. Celles-là sont
     * déclassées — le projet survit à la perte de son étiquette, l'inverse
     * n'est pas vrai.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
        });

        DB::statement(<<<'SQL'
            UPDATE projects
               SET service_id = (
                   SELECT s.id
                     FROM services s
                     JOIN products p ON p.id = projects.service_id
                    WHERE s.company_id = projects.company_id
                      AND LOWER(BTRIM(s.name)) = LOWER(BTRIM(p.name))
                    LIMIT 1
               )
             WHERE service_id IS NOT NULL
        SQL);

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });
    }
};
