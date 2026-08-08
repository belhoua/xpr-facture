<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;

use function Pest\Laravel\actingAs;

/**
 * Cycle de vie d'un document. La règle centrale testée ici est l'IMMUABILITÉ
 * fiscale (§3) : seul un brouillon se modifie ou se supprime ; un document émis
 * ne peut qu'être annulé.
 *
 * `workspaceAccount()` est défini dans tests/Pest.php et sème 7 factures (dont
 * 1 brouillon) numérotées 0001..0006 sur l'exercice courant, plus 1 devis.
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

it('crée un document en brouillon, sans numéro', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null)
        // Sans taux de TVA, la ligne est à 0 % : HT = TTC.
        ->assertJsonPath('subtotalCents', 250_000)
        ->assertJsonPath('totalCents', 250_000);
});

it('ignore un statut ou un numéro envoyés par le client', function (): void {
    [$user] = workspaceAccount();

    // Créer directement en « payé », ou choisir son numéro, contournerait la
    // séquence fiscale. Les champs ne sont tout simplement pas acceptés.
    actingAs($user)
        ->postJson('/api/v1/documents', documentPayload([
            'status' => 'paid',
            'number' => 'FAC-2026-9999',
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('number', null);
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

it('attribue le numéro suivant à l émission, sans trou', function (): void {
    [$user] = workspaceAccount();

    // La démo va jusqu'à 0006 → la suivante prend 0007 (§3). Millésime tiré de
    // l'exercice courant : pas d'année en dur, qui ferait échouer ce test au
    // 1er janvier.
    $year = now()->format('Y');
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)
        ->postJson("/api/v1/documents/{$id}/issue")
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('number', "FAC-{$year}-0007");
});

it('refuse d émettre un document sans ligne', function (): void {
    [$user] = workspaceAccount();

    // Un document vide consommerait un numéro de la séquence pour n'attester
    // de rien : le trou serait définitif.
    $id = actingAs($user)
        ->postJson('/api/v1/documents', documentPayload(['items' => []]))
        ->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertStatus(409);
});

it('refuse de réémettre un document déjà émis', function (): void {
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertOk();
    actingAs($user)->postJson("/api/v1/documents/{$id}/issue")->assertStatus(409);
});

it('modifie un brouillon', function (): void {
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

// Le gel de l'AVOIR a été levé le 2026-08-07, à la demande de l'exploitant.
// C'est la levée la plus lourde — l'avoir est l'instrument par lequel le §3
// fait corriger une facture, et plus aucune pièce commerciale n'est désormais
// figée après émission. Les deux tests qui suivent bornent la levée : ce
// qu'elle permet, et ce qu'elle NE touche PAS — la séquence `AV-`, qui reste
// continue parce que la suppression obéit à `deletableOnceIssued()`.
it('modifie un avoir émis (gel levé sur décision de l’exploitant)', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'sent')
        ->firstOrFail();

    $creditNoteId = actingAs($user)
        ->postJson("/api/v1/documents/{$invoice->id}/credit-note")
        ->assertCreated()
        ->json('id');

    // L'avoir naît BROUILLON : il faut l'émettre pour éprouver le gel.
    actingAs($user)
        ->postJson("/api/v1/documents/{$creditNoteId}/issue")
        ->assertOk();

    $number = Document::query()->findOrFail($creditNoteId)->number;

    actingAs($user)
        ->patchJson("/api/v1/documents/{$creditNoteId}", [
            'notes' => 'Avoir ramené au montant réellement dû.',
            'items' => [[
                'label' => 'Remise commerciale corrigée',
                'quantity' => 1,
                'unitPriceCents' => 200_000,
                'discountPercent' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('notes', 'Avoir ramené au montant réellement dû.')
        ->assertJsonPath('subtotalCents', 200_000);

    // Le numéro `AV-` déjà attribué ne bouge pas : la modification corrige le
    // contenu, elle ne reconsomme pas la séquence.
    expect(Document::query()->findOrFail($creditNoteId)->number)->toBe($number);
});

// Levée du 2026-08-07, demandée après que le coût a été exposé : la séquence
// `AV-` peut désormais présenter des trous, sur une pièce qui EST fiscale.
it('supprime un avoir émis (levée du 2026-08-07)', function (): void {
    [$user] = workspaceAccount();
    $invoice = Document::query()
        ->where('type', 'invoice')
        ->where('status', 'sent')
        ->firstOrFail();

    $creditNoteId = actingAs($user)
        ->postJson("/api/v1/documents/{$invoice->id}/credit-note")
        ->json('id');

    actingAs($user)->postJson("/api/v1/documents/{$creditNoteId}/issue")->assertOk();

    actingAs($user)->deleteJson("/api/v1/documents/{$creditNoteId}")->assertNoContent();

    // Soft delete : la ligne reste en base avec son `deleted_at`, hors de
    // portée de l'application. C'est la seule atténuation du trou de séquence.
    expect(Document::query()->find($creditNoteId))->toBeNull();
    expect(Document::query()->withTrashed()->find($creditNoteId))->not->toBeNull();
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
    [$user] = workspaceAccount();
    $id = actingAs($user)->postJson('/api/v1/documents', documentPayload())->json('id');

    actingAs($user)->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

    expect(Document::query()->find($id))->toBeNull();
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
    actingAs($user)->postJson("/api/v1/documents/{$next}/issue")->assertOk();

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

// La borne qui SUBSISTE, et le seul endroit où édition et suppression divergent
// désormais : un devis converti se MODIFIE mais ne se SUPPRIME pas. L'effacer
// couperait le lien de parenté, et sa facture perdrait la trace de ce dont elle
// découle — question qu'on pose précisément en litige.
it('refuse de supprimer un devis converti (la facture perdrait sa parenté)', function (): void {
    [$user] = workspaceAccount();
    $quote = Document::query()
        ->where('type', 'quote')
        ->whereNotNull('number')
        ->firstOrFail();

    actingAs($user)->postJson("/api/v1/documents/{$quote->id}/convert")->assertCreated();

    actingAs($user)->deleteJson("/api/v1/documents/{$quote->id}")->assertStatus(409);

    expect(Document::query()->find($quote->id))->not->toBeNull();
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
    [$user] = workspaceAccount();
    $draft = Document::query()->where('status', 'draft')->firstOrFail();

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
    [$user] = workspaceAccount();
    $draft = Document::query()->where('status', 'draft')->firstOrFail();

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
        ->where('status', 'draft')
        ->firstOrFail();

    // La société A ne doit même pas voir le document de B : 404, pas 403 (§5).
    actingAs($userA)
        ->patchJson("/api/v1/documents/{$foreignDraft->id}", ['clientName' => 'Intrusion'])
        ->assertNotFound();
});
