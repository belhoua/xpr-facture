/**
 * Identité de marque, FIXÉE EN DUR — décision produit du 2026-08-05.
 *
 * L'application est multi-tenant (§5) : le nom qui s'affiche devrait venir de la
 * société active. Le client a tranché l'inverse pour cette installation, qui
 * n'a qu'un seul exploitant : c'est BCAT partout, en-tête des documents
 * imprimés comprise, quelles que soient les valeurs en base.
 *
 * Tout est réuni ici, dans un seul module, pour deux raisons :
 *  - une chaîne écrite en dur dans quinze composants ne se retire jamais ;
 *  - revenir à l'identité de la société active demandera de remplacer les
 *    lectures de `BRAND` par `active_company`, et cette liste est la carte de
 *    ce qu'il faudra reconnecter.
 *
 * Les MENTIONS LÉGALES (ICE, IF, RC, patente, CNSS, RIB, adresse) ne sont PAS
 * ici : elles restent lues en base. Les figer serait d'une autre nature —
 * imprimer un ICE erroné sur un document commercial est une faute, alors qu'un
 * nom commercial est une affaire de présentation.
 */
export const BRAND = {
  /** Raison commerciale affichée. Ni traduite, ni déclinée. */
  name: "BCAT",

  /** Baseline imprimée sous le logo, sur les documents. */
  tagline: "Bureau de Contrôle & Assistance Technique",

  /**
   * Personne physique qui engage le bureau de contrôle dans un contrat de
   * convention (« Agissant au nom et pour le compte de BCAT »).
   *
   * Ici et non en base parce que le schéma n'a pas de notion de REPRÉSENTANT
   * LÉGAL : `companies` porte l'identité de la personne morale, pas celle du
   * gérant. L'y ajouter serait le bon geste le jour où plusieurs personnes
   * signent — c'est à ce moment-là qu'il faudra une colonne, et un choix à la
   * rédaction du contrat. Pour un exploitant unique, la constante dit la vérité
   * et se corrige en un endroit.
   */
  representative: "EL KIRAT ANAS",

  /**
   * Établissement bancaire du compte de règlement, cité à l'article 10.
   *
   * Le RIB, lui, n'est PAS ici : il est lu sur la société active
   * (`bank_rib`), comme toutes les mentions dont une erreur serait une faute et
   * non un détail de présentation. Seul le NOM de la banque manque au schéma —
   * un libellé d'affichage, que le RIB détermine de toute façon.
   */
  bankName: "BANK OF AFRICA",

  /**
   * Le logo, en DEUX fichiers : un par thème. Servis depuis `public/`, avec
   * leurs dimensions intrinsèques.
   *
   * Deux fichiers et non un seul filtré en CSS : les deux visuels sont opaques
   * et dessinent chacun leur fond (blanc / noir). Inverser le clair en CSS
   * retournerait aussi la couleur d'accent du glyphe, et poser le fichier clair
   * sur le thème sombre imprimait un rectangle blanc autour de la marque.
   *
   * `light` est DÉTOURÉ : la marge blanche du visuel d'origine
   * (`xpr-backend/public/logo_v.jpeg`, 1567×1004, dont le glyphe n'occupait
   * qu'un tiers de la hauteur) a été retirée, puis une respiration régulière
   * réintroduite. Sans ce détourage, toute contrainte de taille porte sur du
   * blanc et le logo s'affiche trois fois trop petit pour la mesure demandée.
   *
   * Les deux sont des BANDES, mais de ratios DIFFÉRENTS (≈ 3,6 : 1 contre
   * ≈ 2,6 : 1) : c'est la largeur qui les dimensionne, et chacun déduit sa
   * hauteur de son propre ratio — voir `BrandMark`. Les confondre écraserait
   * l'un des deux.
   *
   * Le document IMPRIMÉ ne consulte pas cette bascule : il tire toujours
   * `light`, le papier est blanc (cf. `Letterhead`).
   */
  logo: {
    light: {
      src: "/logo_v.jpeg",
      width: 1317,
      height: 367,
    },
    dark: {
      src: "/icone_sombre.png",
      width: 1712,
      height: 660,
    },
  },

  /**
   * L'emblème seul (crochet de levage), carré, sur fond sombre.
   *
   * Distinct du logo et non extrait de lui : il tient là où la bande ne tient
   * pas — l'onglet du navigateur et la sidebar repliée. Le fond fait partie du
   * fichier, d'où la pastille sombre de `BrandIcon` ; l'y poser sur le fond du
   * thème découperait un carré noir aux angles vifs en thème clair.
   *
   * Le favicon est le MÊME visuel, servi par la convention `app/icon.png` de
   * Next — deux fichiers parce que ce sont deux canaux (l'un passe par
   * `next/image`, l'autre par les métadonnées du document), pas deux identités.
   */
  icon: {
    src: "/icon.jpeg",
    size: 256,
  },

  /**
   * Ville d'établissement par défaut des documents (« RABAT, le … »).
   *
   * C'est un REPLI et non une constante : chaque devis porte sa propre ville,
   * saisie au formulaire (`documents.issue_city`). Le repli sert aux documents
   * antérieurs à ce champ et à ceux qu'on ne prend pas la peine de renseigner.
   */
  defaultCity: "RABAT",
} as const;
