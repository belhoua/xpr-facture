<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Cycle de vie d'un document. La règle centrale testée ici est l'IMMUABILITÉ
 * fiscale (§3) : seul un brouillon se modifie ou se supprime ; un document émis
 * ne peut qu'être annulé.
 *
 * `workspaceAccount()` est défini dans tests/Pest.php et sème 7 factures
 * numérotées 0001..0007 sur l'exercice courant, plus 1 devis DEV-…-0001.
 *
 * Depuis le 2026-08-14, la démonstration ne contient plus AUCUN brouillon : la
 * facture et le devis naissent numérotés, et des données de démonstration
 * doivent montrer ce que le produit sait produire. Les tests qui ont besoin
 * d'un brouillon — ils restent légitimes, les bases antérieures à la bascule en
 * contiennent — le fabriquent eux-mêmes avec `Document::factory()->draft()`.
 */

/**
 * Payload minimal d'un document à une ligne.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function documentPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'invoice',
        'clientName' => 'Nouvelle Cliente SARL',
        'items' => [
            ['label' => 'Prestation de conseil', 'quantity' => '2', 'unitPriceCents' => 125_000],
        ],
    ], $overrides);
}

it('crée une facture numérotée et envoyée', function (): void {
    [$user] = workspaceAccount();

    // Décision de l'exploitant du 2026-08-14 : la facture et le devis ne
    // passent plus par une étape d'émission. Le numéro est consommé à
    // l'enregistrement, l'état est `sent` d'emblée. La démo va jusqu'à 0007 →
    // celle-ci prend 0008. Millésime tiré de l'exercice, pas d'année en dur.
    $year = now()->format('Y');

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', "FAC-{$year}-0008")
        ->assertJsonPath('issuedAt', now()->toDateString())
        // Sans taux de TVA, la ligne est à 0 % : HT = TTC.
        ->assertJsonPath('subtotalCents', 250_000)
        ->assertJsonPath('totalCents', 250_000);
});

it('crée un devis numéroté dans sa propre séquence', function (): void {
    [$user] = workspaceAccount();
    $year = now()->format('Y');

    // La démo sème un devis (DEV-…-0001) : celui-ci prend 0002. Le devis suit
    // la même règle que la facture depuis le 2026-08-14, dans SA séquence.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['type' => 'quote']))
        ->assertCreated()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', "DEV-{$year}-0002");
});

it('ignore un statut envoyé par le client', function (): void {
    [$user] = workspaceAccount();
    $year = now()->format('Y');

    // Se déclarer « payé » à la création contournerait le suivi
    // d'encaissement : le champ est ignoré, l'état reste déduit.
    //
    // Le `number`, lui, n'est plus ignoré depuis le 2026-08-14 — mais seulement
    // s'il est une suite de chiffres. Le format des séquences reste hors de
    // portée du client, comme le montre le jeu de cas rejetés plus bas.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['status' => 'paid']))
        ->assertCreated()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', "FAC-{$year}-0008");
});

it('recalcule les totaux et ignore ceux transmis par le client', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'totalCents' => 1,
            'subtotalCents' => 1,
        ]))
        ->assertCreated()
        ->assertJsonPath('totalCents', 250_000);
});

it('attribue les numéros sans trou, dans l ordre de création', function (): void {
    [$user] = workspaceAccount();
    $year = now()->format('Y');

    // La séquence ne saute pas : deux créations successives prennent deux
    // numéros consécutifs (§3). C'est la garantie que la bascule du 2026-08-14
    // ne devait PAS coûter — elle déplace le moment de la consommation, elle
    // n'autorise pas les trous.
    $first = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('number');
    $second = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('number');

    expect($first)->toBe("FAC-{$year}-0008")
        ->and($second)->toBe("FAC-{$year}-0009");
});

// ── Numéro saisi à la main (2026-08-14) ───────────────────────────────────
//
// Ouverture demandée par l'exploitant. Les tests qui suivent bornent ce qu'elle
// permet et ce qu'elle ne doit PAS emporter : l'unicité par société, et la
// numérotation automatique de ceux qui ne saisissent rien.

it('accepte un numéro saisi à la main', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '4242']))
        ->assertCreated()
        ->assertJsonPath('number', '4242')
        ->assertJsonPath('status', 'sent');
});

it('conserve les zéros initiaux du numéro saisi', function (): void {
    [$user] = workspaceAccount();

    // « 007 » est un numéro que l'utilisateur a écrit, pas un entier à
    // normaliser : le caster le transformerait en « 7 » sous ses doigts.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '007']))
        ->assertCreated()
        ->assertJsonPath('number', '007');
});

it('n avance pas la séquence quand le numéro est saisi', function (): void {
    [$user] = workspaceAccount();
    $year = now()->format('Y');

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '900']))
        ->assertCreated();

    // Le compteur automatique reste où il était : la pièce suivante prend 0008,
    // comme si la saisie manuelle n'avait pas eu lieu. C'est le coût assumé de
    // l'ouverture — les deux numérotations coexistent sans se connaître.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload())
        ->assertCreated()
        ->assertJsonPath('number', "FAC-{$year}-0008");
});

it('refuse un numéro déjà utilisé dans la société', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)->postJson('/api/v1/documents', documentPayload(['number' => '55']))->assertCreated();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '55']))
        ->assertStatus(422)
        ->assertJsonPath('errors.number.0', fn (string $m): bool => $m !== '');
});

it('laisse deux sociétés utiliser le même numéro', function (): void {
    [$userA] = workspaceAccount();
    [$userB] = workspaceAccount();

    // L'unicité est PAR SOCIÉTÉ : deux entreprises distinctes émettent chacune
    // leur pièce n° 77 sans se gêner (§5).
    actingAs($userA)->postJson('/api/v1/documents', documentPayload(['number' => '77']))->assertCreated();
    actingAs($userB)->postJson('/api/v1/documents', documentPayload(['number' => '77']))->assertCreated();
});

it('refuse un numéro qui n est pas une suite de chiffres', function (string $number): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => $number]))
        ->assertStatus(422)
        ->assertJsonPath('errors.number.0', fn (string $m): bool => $m !== '');
})->with(['FAC-2026-0042', '-1', '12.5', '1e3', 'AB12', '4 2']);

it('reprend la séquence quand le champ numéro est vide', function (): void {
    [$user] = workspaceAccount();
    $year = now()->format('Y');

    // Chaîne vide et non absence de clé : c'est ce qu'un formulaire HTML envoie
    // quand l'utilisateur ne remplit pas le champ.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '']))
        ->assertCreated()
        ->assertJsonPath('number', "FAC-{$year}-0008");
});

it('renumérote un document par un PATCH', function (): void {
    [$user] = workspaceAccount();
    $created = actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['number' => '311']))
        ->assertCreated()
        ->json();

    // Ce cas affirmait l'inverse jusqu'au 2026-08-18 : la saisie du numéro
    // valait à la CRÉATION seule. L'exploitant a demandé la levée pour la
    // facture et le devis ; ce qu'elle coûte est écrit dans
    // `DocumentType::allowsNumberEdit()`, et le périmètre exact est éprouvé
    // par `DocumentRenumberingTest`.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$created['id']}", ['number' => '999'])
        ->assertOk()
        ->assertJsonPath('number', '999');
});

it('refuse de créer une facture sans ligne', function (): void {
    [$user] = workspaceAccount();

    // Un document vide consommerait un numéro de la séquence pour n'attester de
    // rien : le trou serait définitif. La garde était portée par l'émission ;
    // elle a suivi le numéro jusqu'à la création (2026-08-14).
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['items' => []]))
        ->assertStatus(422)
        ->assertJsonPath('errors.items.0', fn (string $m): bool => $m !== '');
});

it('refuse d émettre une facture déjà numérotée', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    // L'endpoint d'émission survit pour les types qui ont encore une étape
    // d'émission (achats, expédition) et pour les brouillons antérieurs à la
    // bascule. Sur une facture née numérotée, il refuse : la renuméroter
    // brûlerait un second numéro pour la même pièce.
    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertStatus(409);
});

it('modifie une facture qu on vient de créer', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", [
            'clientName' => 'Brouillon Corrigé',
            'items' => [
                ['label' => 'Autre prestation', 'quantity' => '1', 'unitPriceCents' => 300_000],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('clientName', 'Brouillon Corrigé')
        ->assertJsonPath('totalCents', 300_000)
        ->assertJsonCount(1, 'items');
});

it('ne vide pas les lignes quand le PATCH ne les transmet pas', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['notes' => 'Merci de régler sous 30 jours.'])
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('totalCents', 250_000);
});

// Le gel des factures a été LEVÉ le 2026-08-06 sur décision de l'exploitant
// (cf. DocumentType::freezesOnIssue(), qui documente ce que la levée coûte).
// Les deux tests qui suivent encadrent la nouvelle frontière : ce qui est
// désormais permis, et ce qui reste interdit. Ils sont le garde-fou contre une
// extension silencieuse de la brèche aux autres types.
it('modifie une facture émise (gel levé sur décision de l’exploitant)', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'sent')
        ->firstOrFail();

    // `notes` et non `clientName` : les documents de démonstration portent un
    // tiers, et l'identité vient alors de SA fiche — un `clientName` transmis
    // est ignoré, ce qui masquerait ici l'effet réel de l'écriture.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$sent->id}", ['notes' => 'Corrigée après émission.'])
        ->assertOk()
        ->assertJsonPath('notes', 'Corrigée après émission.');

    // Le numéro déjà attribué ne bouge pas : la modification corrige le
    // contenu, elle ne reconsomme pas la séquence.
    expect($sent->refresh()->number)->not->toBeNull();
});

// Le gel du DEVIS a été levé le 2026-08-06, à la demande de l'exploitant :
// renégocier une proposition est le cours normal des affaires, et refaire un
// devis à chaque ajustement brûlerait un numéro par échange. Les trois tests
// qui suivent bornent la levée — ce qu'elle permet, et les deux choses qu'elle
// ne touche pas : les états terminaux, et la suppression.
it('modifie un devis émis, lignes comprises (gel levé)', function (): void {
    [$user] = workspaceAccount();
    // Le devis émis de la démonstration est ACCEPTÉ : un état ouvert, comme
    // `sent`. Seuls les états terminaux ferment le document.
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    $number = $quote->number;

    actingAs($user)
        ->patchJson("/api/v1/documents/{$quote->id}", [
            'notes' => 'Prix renégocié le 06/08.',
            'items' => [[
                'label' => 'Prestation renégociée',
                'quantity' => 2,
                'unitPriceCents' => 150_000,
                'discountPercent' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('notes', 'Prix renégocié le 06/08.')
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('subtotalCents', 300_000);

    // Le numéro déjà attribué ne bouge pas : la modification corrige le
    // contenu, elle ne reconsomme pas la séquence.
    expect($quote->refresh()->number)->toBe($number);
});

// Le 2026-08-07, l'exploitant a demandé que le devis reste modifiable MÊME en
// état terminal. Ce test disait l'inverse jusque-là ; il est retourné, pas
// supprimé — c'est lui qui documente ce que la levée permet.
it('modifie un devis converti (réouverture demandée le 2026-08-07)', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/convert")->assertCreated();

    // Le devis a produit une facture. Le rouvrir le fait DIVERGER d'elle sans
    // que rien ne le signale : les deux pièces portent toujours le lien de
    // parenté, plus les mêmes lignes. Coût assumé et documenté dans
    // DocumentWriteService::reopensWhenTerminal().
    actingAs($user)
        ->patchJson("/api/v1/documents/{$quote->id}", ['notes' => 'Corrigé après conversion.'])
        ->assertOk()
        ->assertJsonPath('notes', 'Corrigé après conversion.');

    // L'état terminal ne bouge PAS : le devis reste « converti ». La levée
    // porte sur le contenu, elle ne ressuscite pas le cycle de vie.
    expect($quote->refresh()->status->value)->toBe('converted');
});

it('refuse toujours de modifier un devis ANNULÉ', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/cancel")->assertOk();

    // L'annulation est le seul état terminal issu d'un acte délibéré, avec son
    // endpoint et sa permission propres. La rouvrir effacerait la trace de
    // l'annulation elle-même — cette borne survit à toutes les levées.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$quote->id}", ['notes' => 'Tentative Interdite'])
        ->assertStatus(409);

    expect($quote->refresh()->notes)->not->toBe('Tentative Interdite');
});

it('refuse de modifier une facture annulée (état terminal)', function (): void {
    [$user] = workspaceAccount();
    $cancelled = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'cancelled')
        ->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/documents/{$cancelled->id}", ['clientName' => 'Tentative Interdite'])
        ->assertStatus(409);
});

it('supprime un brouillon', function (): void {
    [$user, $company] = workspaceAccount();

    // Fabriqué directement : l'API ne crée plus de brouillon de facture depuis
    // le 2026-08-14. La règle testée, elle, reste vraie — un document sans
    // numéro se jette sans rien trouer, et les bases antérieures à la bascule
    // en contiennent encore.
    app(TenantContext::class)->activateCompany($company->id);
    $draft = Document::factory()->draft()->create(['company_id' => $company->id]);

    actingAs($user)->deleteJson("/api/v1/documents/{$draft->id}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);
    expect(Document::query()->find($draft->id))->toBeNull();
});

it('supprime une facture émise et troue la séquence', function (): void {
    [$user] = workspaceAccount();
    $paid = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'paid')
        ->firstOrFail();

    $number = $paid->number;

    actingAs($user)->deleteJson("/api/v1/documents/{$paid->id}")->assertNoContent();

    // Le scope par défaut ne la voit plus…
    expect(Document::query()->find($paid->id))->toBeNull();

    // …mais la ligne SUBSISTE : c'est un soft delete. La distinction compte —
    // la pièce reste reconstituable, et c'est tout ce qui reste de la trace
    // comptable une fois le gel levé.
    $trashed = Document::withTrashed()->findOrFail($paid->id);
    expect($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->number)->toBe($number);

    // Le numéro n'est PAS réattribué : la séquence ne recule jamais. Le
    // document suivant en prendra un nouveau, laissant le trou visible.
    $next = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    // `where(...)->firstOrFail()` et non `findOrFail($id)` : la seconde forme
    // accepte aussi un tableau d'identifiants, son type de retour couvre donc
    // une Collection et l'analyse statique refuse l'accès à `->number`.
    $issued = Document::query()->where('id', $next)->firstOrFail();

    expect($issued->number)->not->toBe($number);
});

// Levée du 2026-08-07 : la séquence `DEV-` peut désormais présenter des trous.
// C'est la moins lourde des deux suppressions ouvertes ce jour-là — un devis
// n'atteste rien auprès de la DGI, un trou dans `DEV-` n'est opposable à
// personne.
it('supprime un devis émis (levée du 2026-08-07)', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    actingAs($user)->deleteJson("/api/v1/documents/{$quote->id}")->assertNoContent();

    expect(Document::query()->find($quote->id))->toBeNull();
    expect(Document::query()->withTrashed()->find($quote->id))->not->toBeNull();
});

// Levée du 2026-08-24 : un devis CONVERTI se supprime, sur demande expresse de
// l'exploitant. L'objection qui tenait la borne fermée — la facture perdrait la
// trace de ce dont elle découle — est levée par `Document::parent()`, qui résout
// désormais un parent supprimé. Les deux moitiés sont éprouvées ensemble : la
// suppression seule, sans la parenté conservée, serait la régression que la
// borne empêchait.
it('supprime un devis converti (levée du 2026-08-24)', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    $invoiceId = actingAs($user)
        ->postJson("/api/v1/documents/{$quote->id}/convert")
        ->assertCreated()
        ->json('id');

    actingAs($user)->deleteJson("/api/v1/documents/{$quote->id}")->assertNoContent();

    // Soft delete : la ligne reste en base, hors de portée de l'application.
    expect(Document::query()->find($quote->id))->toBeNull();
    expect(Document::query()->withTrashed()->find($quote->id))->not->toBeNull();

    // Et la facture continue de nommer le devis dont elle découle.
    actingAs($user)
        ->getJson("/api/v1/documents/{$invoiceId}")
        ->assertOk()
        ->assertJsonPath('parentDocumentId', $quote->id)
        ->assertJsonPath('parentNumber', $quote->number);
});

// Les deux autres états terminaux restent FERMÉS, le devis compris. L'annulation
// surtout : c'est le seul état terminal issu d'un acte volontaire, et supprimer
// la pièce effacerait la trace de l'acte lui-même.
it('refuse encore de supprimer un devis ANNULÉ', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/cancel")->assertOk();

    actingAs($user)->deleteJson("/api/v1/documents/{$quote->id}")->assertStatus(409);

    expect(Document::query()->find($quote->id))->not->toBeNull();
});

// La levée porte sur le DEVIS et sur lui seul : une facture convertie en avoir,
// ou tout autre type parvenu à un état terminal, reste fermée. Sans ce test, la
// prochaine ouverture d'un type hériterait de l'exception par simple effet de
// bord.
it('refuse de supprimer une pièce ANNULÉE d’un autre type', function (): void {
    [$user, $company] = workspaceAccount();
    // Facture émise SANS règlement : une facture déjà encaissée refuserait
    // l'annulation elle-même, et le test échouerait avant d'avoir rien prouvé.
    $invoice = payableInvoice($company->id);

    actingAs($user)->postJson("/api/v1/documents/{$invoice->id}/cancel")->assertOk();

    actingAs($user)->deleteJson("/api/v1/documents/{$invoice->id}")->assertStatus(409);
});

it('annule un document émis', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()->where('status', 'sent')->firstOrFail();

    actingAs($user)
        ->postJson("/api/v1/documents/{$sent->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');
});

it('refuse d annuler un brouillon — il se supprime', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $draft = Document::factory()->draft()->create(['company_id' => $company->id]);

    actingAs($user)->postJson("/api/v1/documents/{$draft->id}/cancel")->assertStatus(409);
});

it('refuse une transition d état impossible pour le type', function (): void {
    [$user] = workspaceAccount();
    $sent = Document::query()->where('type', 'invoice')->where('status', 'sent')->firstOrFail();

    // `accepted` appartient au cycle du DEVIS, pas à celui de la facture.
    actingAs($user)
        ->postJson("/api/v1/documents/{$sent->id}/status", ['status' => 'accepted'])
        ->assertStatus(409);
});

it('refuse de faire passer un brouillon directement à payé', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $draft = Document::factory()->draft()->create(['company_id' => $company->id]);

    // Ce serait une créance réglée qui n'a jamais été facturée.
    actingAs($user)
        ->postJson("/api/v1/documents/{$draft->id}/status", ['status' => 'paid'])
        ->assertStatus(409);
});

it('enregistre la ville d établissement et la renvoie telle quelle', function (): void {
    [$user] = workspaceAccount();

    // La ville s'imprime en tête du devis (« RABAT, le … »). Elle est portée par
    // le DOCUMENT et non par la société : un bureau de contrôle établit ses
    // devis là où se trouve le chantier, pas à son siège.
    $response = actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'type' => 'quote',
            'issueCity' => 'Rabat',
        ]))
        ->assertCreated()
        // Renvoyée sans transformation : la mise en capitales est une décision
        // d'affichage, la casse saisie reste celle de l'utilisateur.
        ->assertJsonPath('issueCity', 'Rabat');

    $id = $response->json('id');

    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['issueCity' => 'Casablanca'])
        ->assertOk()
        ->assertJsonPath('issueCity', 'Casablanca');

    // Vide, elle redevient nulle : c'est l'impression qui applique son repli,
    // pas la base — sinon « RABAT » serait indistinguable d'une saisie réelle.
    actingAs($user)
        ->patchJson("/api/v1/documents/{$id}", ['issueCity' => null])
        ->assertOk()
        ->assertJsonPath('issueCity', null);
});

it('refuse une ville d établissement trop longue', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'issueCity' => str_repeat('a', 101),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.issueCity.0', fn (string $message): bool => $message !== '');
});

it('isole les écritures de documents entre deux sociétés', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $foreignDraft = Document::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->firstOrFail();

    // La société A ne doit même pas voir le document de B : 404, pas 403 (§5).
    actingAs($userA)
        ->patchJson("/api/v1/documents/{$foreignDraft->id}", ['clientName' => 'Intrusion'])
        ->assertNotFound();
});
