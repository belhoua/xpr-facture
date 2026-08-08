/**
 * Corps CONTRACTUEL de la convention de contrôle et suivi, repris mot pour mot
 * du modèle client (`docs/Contrat de convention modele.docx`).
 *
 * ── Pourquoi ce texte n'est PAS dans `messages/*.json` ──────────────────────
 *
 * Ce n'est pas de l'interface, c'est un ACTE. Les articles ci-dessous sont ce
 * que les deux parties signent : leur formulation engage juridiquement BCAT et
 * le maître d'ouvrage. Trois conséquences, qui vont toutes dans le même sens :
 *
 *  1. **Pas de traduction automatique.** Verser ces articles dans le catalogue
 *     i18n obligerait à en produire une version arabe et une version anglaise,
 *     que personne ici n'est en mesure de faire valider par un juriste. Un
 *     contrat mal traduit ne se lit pas de travers : il s'oppose de travers.
 *     Une version arabe est parfaitement souhaitable — elle relève d'une
 *     rédaction juridique, pas d'une tâche de localisation.
 *  2. **Pas d'interpolation dispersée.** Les seules variables du contrat sont
 *     les données de la convention (parties, projet, honoraires), et elles
 *     restent dans le composant. Les articles, eux, sont fixes.
 *  3. **Un seul endroit à relire.** Le jour où le client fait évoluer sa
 *     convention type, c'est ce fichier qu'on ouvre, et lui seul.
 *
 * L'INTERFACE autour du contrat (boutons, titres d'écran) reste traduite, elle :
 * seul le corps de l'acte est en français, comme le document signé.
 */

/** Un article : son numéro, son intitulé, et son contenu. */
export interface ContractArticle {
  /** « Article 1 », « Article 2 »… tel qu'il est numéroté dans l'acte. */
  heading: string;
  /** Chapeau de l'article. Vide quand l'article n'est qu'une liste. */
  body?: string;
  /** Énumération à puces, quand l'article en porte une. */
  items?: readonly string[];
  /** Alinéas qui suivent l'énumération. */
  after?: readonly string[];
}

/**
 * Identité du bureau de contrôle telle qu'elle est RÉCITÉE dans le préambule.
 *
 * Les valeurs numériques (RC, CNSS, patente, RIB, adresse) ne sont PAS ici :
 * elles sont lues sur la société active, comme le pied de page des documents
 * commerciaux (cf. `Letterhead`). Imprimer un RIB figé dans le code sur un acte
 * signé serait une faute d'une autre nature qu'une coquille de présentation.
 */
export const CONTRACT_PARTY_LABELS = {
  actingFor: "Agissant au nom et pour le compte de",
  registeredAt: "Inscrit au registre de commerce de",
  cnss: "Affilié à la C.N.S.S sous le n°",
  patente: "Patente n°",
  account: "Titulaire du compte postal, bancaire ou TGR n°",
  domicile:
    "En vertu des pouvoirs qui lui sont délégués, faisant élection de domicile à :",
  designation: "Désigné ci-après par le Bureau de Contrôle (BCT)",
  firstParty: "D'une part",
  secondParty: "D'autre part",
} as const;

/**
 * Articles 1 à 10 de la convention type.
 *
 * L'article 10 (honoraires) n'y figure QUE par son chapeau : son contenu — le
 * forfait en chiffres et en lettres, l'échéancier, le compte bancaire — est
 * composé depuis les données de la convention, et c'est précisément ce que
 * l'application apporte par rapport à un document Word rempli à la main.
 */
export const CONTRACT_ARTICLES: readonly ContractArticle[] = [
  {
    heading: "Article 1",
    body: "Cette convention a pour objet : contrôle des plans et contrôle des travaux des lots suivants :",
    // Les lots sont ceux de la convention, pas une liste figée : le composant
    // les injecte ici.
  },
  {
    heading: "Article 2",
    body: "Le maître d'ouvrage s'engage à confier les travaux d'exécution des gros œuvres au bureau d'études et bureau de contrôle BCAT jusqu'à la réception du chantier. Ces travaux sont :",
    items: [
      "A – La réception des ferraillages et coffrage avant coulage de béton.",
      "B – La réception de l'acier et les éléments de fixation avant l'assemblage de la structure métallique.",
      "C – Les visites de chantier qui seront à la demande du maître d'ouvrage et en présence de ce dernier et de l'entrepreneur.",
      "D – Aucun travail ne doit être exécuté sans le bon de coulage délivré par le B.E.T.",
    ],
  },
  {
    heading: "Article 3",
    body: "Le maître d'ouvrage s'engage à conserver la conception architecturale et à ne modifier aucun élément de la structure sans l'avis écrit de l'ingénieur de béton armé.",
  },
  {
    heading: "Article 4",
    body: "Le bureau de contrôle BCAT dégage toute responsabilité pour un béton coulé sans bon de coulage.",
  },
  {
    heading: "Article 5",
    body: "Le maître d'ouvrage s'engage à confier l'exécution de son projet à un entrepreneur qualifié, compétent et respectant les règles de l'art.",
  },
  {
    heading: "Article 6",
    body: "Ce contrat ne prendra effet qu'à compter du jour de la notification de l'ordre de service, adressée par le maître d'ouvrage au bureau de contrôle BCAT, prescrivant le commencement des travaux.",
  },
  {
    heading: "Article 7",
    body: "Limite de la prestation du bureau de contrôle BCAT. Ne font pas partie des prestations BCAT :",
    items: [
      "Les travaux topographiques de toute nature.",
      "L'étude géotechnique des sols de fondation de l'ouvrage.",
      "Les études et essais de béton, du ressort d'un laboratoire spécialisé.",
    ],
  },
  {
    heading: "Article 8",
    body: "Par ailleurs, le maître d'ouvrage s'engage à :",
    items: [
      "Fournir au BCT tous les plans du bureau d'études, renseignements, justificatifs, notes de calcul, ainsi que sa décision à chaque stade de l'élaboration du projet.",
      "Fournir au BCT tous les documents techniques utiles à l'accomplissement de sa mission, ainsi que toute pièce modificative.",
      "Lui donner librement accès au chantier.",
      "Le prévenir, en temps utile, des dates de commencement des travaux de chaque corps d'état et des phases essentielles de leur exécution.",
      "Apporter au bureau de contrôle tout son appui pour lui permettre de remplir sa mission, notamment en ce qui concerne les relations avec les entreprises.",
      "Se conformer aux délais prévus dans les plannings d'études et d'exécution pour ses propres interventions, notamment en matière d'approbation des dossiers.",
    ],
  },
  {
    heading: "Article 9",
    body: "Le délai d'exécution de la prestation :",
    // Le délai est une donnée de la convention (« Néant » dans le modèle) :
    // le composant l'insère ici.
  },
  {
    heading: "Article 10",
    body: "Les honoraires de suivi et contrôle.",
    // Le forfait, son montant en toutes lettres, l'échéancier et le compte
    // bancaire sont composés depuis la convention.
  },
] as const;

/** Phrases de l'article 10, autour des montants calculés. */
export const CONTRACT_FEES = {
  intro:
    "Le bureau de contrôle sera rémunéré pour la mission objet de la présente convention sur la base du prix forfaitaire, ferme et non révisable d'une somme de :",
  detail:
    "détaillé sur devis, en respectant le mode de paiement ci-dessous.",
  advance: "Avance : {percent} % du montant total de la prestation.",
  visa: "Contrôle et visa des plans : {percent} % du montant total de la prestation.",
  completion:
    "Après la réalisation des travaux : {percent} % du montant total de la prestation.",
  payment:
    "Les sommes dues au bureau de contrôle au titre de la présente convention seront payées sur présentation des notes d'honoraires validées par {owner}, par virement au compte bancaire suivant :",
  bank: "Banque :",
  account: "Compte n° :",
} as const;

/** Bloc de signature, en pied d'acte. */
export const CONTRACT_SIGNATURES = {
  client: "Lu et accepté par le client :",
  provider: "Lu et accepté par le bureau de contrôle :",
} as const;

/** Intitulés du préambule, au-dessus des articles. */
export const CONTRACT_HEADINGS = {
  title: "Contrat de convention de contrôle et suivi",
  dossier: "Dossier N° :",
  project: "PROJET :",
  owner: "MAÎTRE D'OUVRAGE :",
  titleDeed: "TF :",
  between: "ET",
  representative: "Monsieur / Madame",
} as const;
