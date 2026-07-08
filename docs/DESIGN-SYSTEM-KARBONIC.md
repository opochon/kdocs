# Signature visuelle Karbonic — design system

**But.** Une seule identité visuelle (claire **et** sombre) pour toutes les apps Karbonic :
HTMLEditor v3, ClearMyMails / K-Mail, K-Docs (GED), ClearMyDocs, Cockpit… Ce document est la
**source de vérité**. On le copie dans chaque app et on s'y tient.

**Esprit.** Neutralité « Notion » : un chrome qui s'efface pour laisser respirer le contenu.
Monochrome, calme, sans esbroufe. La couleur sert le contenu et les états — jamais la décoration.

> **Statut v2 — réconciliation inter-apps (2026-07-08).** Ce doc gouverne **GED (K-Docs)** ET
> **K-Time**, qui divergeaient (GED = monochrome littéral ; K-Time = redesign Figma bleu). Décision :
> un seul modèle réconcilié, matérialisé par le fichier maître **`karbonic-tokens.css`** (copié à
> l'identique dans les deux repos). Direction actée :
> - **Chrome monochrome** (héritage GED) : nav/header neutres, action primaire **anthracite**, bleu
>   réservé focus/liens/sélection.
> - **Contenu enrichi** (héritage K-Time) : **système de statuts colorés** (§9) pour les données.
> - **Police** pile système · **Icônes** SVG inline (heroicons/lucide) · **Rayon** 8px base.
> - **Composants & zones** : on adopte le set K-Time (`action-bar`, `list-table`, `page-header`,
>   `empty-state`, `form-field`, `button`, `badge`) comme **contrat commun** (§9–12). GED l'importe.
> - **Nommage** : canoniques non-préfixés ; chaque app garde ses alias legacy (`--kt-*` inclus) →
>   churn quasi nul. Voir `karbonic-tokens.css` §4–5.
>
> **Reste à faire (lots) :** L1 K-Time (alias + chrome bleu→neutre + icônes) · L2 promotion du set
> composants + `toolbar` (recherche globale) · L3 GED adopte le set · L4 lint garde-fou. Ne pas
> restyler l'UI au détour d'un autre lot sans s'y référer.

---

## 1. Principes

1. **Chrome monochrome.** Blanc / gris / anthracite. Aucun aplat coloré décoratif dans l'interface.
2. **Accent calmé.** Bleu doux `#3f6fb0`, réservé au focus, aux liens et aux sélections actives.
   L'**action primaire est anthracite**, pas bleue.
3. **Surface de contenu stable.** En clair, le « papier » reste blanc invariant ; c'est le chrome
   qui se teinte. En sombre, le papier suit l'app (voir §2).
4. **Zéro chaleur parasite.** Pas de beige, pas de crème. Gris neutres uniquement.
5. **Densité progressive.** Tout le chrome se décline en Déployé / Compact / Zen (voir §6).
6. **Picto obligatoire.** Chaque action porte une icône ; le texte est optionnel et se replie selon
   la densité. Plus jamais de bouton texte-seul (source de chevauchement à la réduction).
7. **Une seule grammaire de surfaces.** Rails, panneaux latéraux, colonnes, inspecteur, barre de
   statut, modales : mêmes tokens, mêmes hover, mêmes en-têtes de section.

---

## 2. Tokens

**Valeurs de référence = `karbonic-tokens.css` (fichier maître).** Le bloc ci-dessous est
l'intention ; en cas de doute, le fichier fait foi. Toutes les couleurs dérivent de ces variables —
on ne code jamais une couleur en dur. v2 a assombri `--dim`/`--accent` (WCAG AA) et porté le rayon
à 8px ; ajouté le système de statuts (§9).

### Clair (`:root` ou `.theme`)

```css
:root {
  /* fonds */
  --app-bg:#f7f7f8;        /* fond applicatif general */
  --paper:#ffffff;         /* surface de contenu (document, corps de mail, apercu) */
  --chrome:#ffffff;        /* barres : header, ruban, barre de statut */
  --rail:#f7f7f8;          /* panneaux lateraux, colonnes */
  --surface:#ffffff;       /* cartes, champs, boutons ghost, popovers */
  /* interactions */
  --hover:#ededee;         /* survol (lignes, items, boutons) */
  --active:#e9e9eb;        /* selection / onglet actif */
  /* lignes */
  --border:#e9e9e9;        /* bordures structurantes */
  --border-soft:#f0f0f0;   /* separateurs internes (lignes de tableau, sous-sections) */
  /* texte */
  --ink:#2f3033;           /* texte principal */
  --ink-soft:#5b5d61;      /* texte secondaire */
  --dim:#686a6e;           /* labels, meta — v2 : assombri WCAG AA */
  --muted:#a6a8ac;         /* texte tres discret, placeholders */
  /* accent & primaire */
  --accent:#3a66a8;        /* focus, liens, selection active — v2 : assombri WCAG AA */
  --accent-soft:#eaf0f8;   /* fond d'accent tres leger */
  --primary:#37352f;       /* action primaire (anthracite) */
  --primary-ink:#ffffff;   /* texte sur primaire */
  /* etats (chips succes/alerte/erreur generiques ; +*-ink pour contraste) */
  --green:#4a8a5c;
  --amber:#b8893f;
  --red:#c0544b;
  /* tooltip / hint */
  --tip:#2f3033;
  --tip-ink:#ffffff;
  /* forme — v2 : 8px base */
  --radius:8px;
  --radius-sm:6px;
  --radius-lg:12px;
  --shadow:0 1px 2px rgba(15,15,15,.04);
  --shadow-pop:0 8px 30px rgba(15,15,15,.14);
}
```

### Sombre (`.dark`)

```css
.dark {
  --app-bg:#1f1f20;
  --paper:#ffffff;         /* voir note ci-dessous */
  --chrome:#252526;
  --rail:#202021;
  --surface:#2a2a2c;
  --hover:#2f2f31;
  --active:#343437;
  --border:#3a3a3c;
  --border-soft:#2e2e30;
  --ink:#e6e6e8;
  --ink-soft:#bcbcc0;
  --dim:#8e9094;
  --muted:#6a6c70;
  --accent:#6f9fe0;
  --accent-soft:#23304a;
  --primary:#e6e6e8;       /* en sombre, le primaire s'inverse (clair sur fond sombre) */
  --primary-ink:#1f1f20;
  --green:#5fae72;
  --amber:#d2a35a;
  --red:#df6f66;
  --tip:#0d0d0e;
  --tip-ink:#f0f0f2;
  --shadow-pop:0 10px 32px rgba(0,0,0,.55);
}
```

> **`--paper` en sombre — décision par app.**
> - **HTMLEditor** garde `--paper:#ffffff` même en sombre : fidélité au rendu du document imprimé.
> - **Mail / GED / listes** (où le « contenu » n'est pas une page blanche) doivent **override**
>   `--paper` vers une surface sombre, p.ex. `--paper:#1f1f20` ou `#2a2a2c`.
> C'est le **seul** token dont la valeur sombre dépend de l'app.

Bascule : appliquer/retirer la classe `.dark` sur le conteneur racine. Option « Système » = suivre
`prefers-color-scheme`.

---

## 3. Typographie

- **Famille :** pile système — `-apple-system, "Segoe UI", Inter, Roboto, system-ui, sans-serif`.
- **Mono** (chemins, code, identifiants) : `"SF Mono", Menlo, Consolas, monospace`.
- **Échelle :** corps 12,5–13 px · titres de section 15 px / 650 · en-têtes de colonne et labels
  10,5 px en MAJUSCULES, `letter-spacing:.05em`, couleur `--dim`.
- **Poids :** 650 pour les titres, 600 pour les actions, 500–550 pour le corps appuyé, 400 sinon.
- **Lissage :** `-webkit-font-smoothing:antialiased`.

---

## 4. Surfaces & layout

- **Barres** (header, ruban, statut) : fond `--chrome`, bordure `--border`.
- **Panneaux latéraux / colonnes / rails / inspecteur** : fond `--rail`, séparés par `--border`.
- **Cartes, champs, popovers, boutons ghost** : fond `--surface`.
- **En-tête de section** (dans un rail/panneau) : label MAJUSCULES 10,5 px `--dim`, padding
  `13px 14px 5px`. Le même partout — c'est la signature des panneaux.
- **Rayon :** `--radius` (6 px) pour boutons/cartes/champs, `--radius-sm` (4 px) pour micro-éléments.
- **Profondeur :** ombre quasi nulle au repos (`--shadow`) ; `--shadow-pop` réservé aux éléments
  flottants (modales, popovers, menus).

---

## 5. Composants

**Bouton primaire** — fond `--primary` (anthracite), texte `--primary-ink`, 600, radius 7 px,
picto + label. Une action primaire par contexte.

**Bouton ghost** — fond `--surface`, bordure `--border`, texte `--ink-soft` ; hover → bordure
plus marquée. Pour les actions secondaires.

**Bouton picto** — icône 14–16 px, hover `--hover`, texte `--ink-soft`→`--ink`. Voir §6 pour la
gestion du label selon la densité.

**Onglets** (ruban, rail, inspecteur) — actif = fond `--active` + trait sombre discret
(`box-shadow:inset 0 -2px 0 var(--ink)`), **jamais** de bleu. Inactif = `--dim`, hover `--hover`.

**Listes / items** — padding `7–8px 10px`, radius 6 px ; hover `--hover` ; sélection `--active`
avec texte `--ink`. Item = un titre `--ink` (550) + une méta `--dim` optionnelle.

**Tables** — en-têtes MAJUSCULES 10,5 px `--muted`, lignes séparées par `--border-soft`, hover de
ligne `--hover`, ligne sélectionnée `--active`. Pas de bordures verticales.

**Champs / inputs** — fond `--app-bg` (ou `--surface`), bordure `--border`, radius 7 px, texte
`--ink`, placeholder `--muted` ; focus → bordure `--accent`.

**Sélecteurs (dropdown)** — comme un champ, avec chevron `--muted`. Affichent une **valeur**, pas un
label → ne se replient jamais en picto-seul.

**Modales** — fond `--surface`, bordure `--border`, radius 12 px, `--shadow-pop`. Structure :
en-tête (titre 15/650 + sous-titre `--dim`) · corps scrollable · pied avec actions
(ghost « Fermer/Annuler » + primaire à droite).

**Chips** — radius 12 px, bordure `--border`, fond `--app-bg`. Variantes : inclus = `--green` sur
fond vert très léger ; exclu = `--dim` barré.

**Toggle** — piste 34×20, pastille blanche ; activé = `--green`.

**Popover** — `--surface`, bordure `--border`, radius 9 px, `--shadow-pop`, petite flèche.
Déclenché au survol d'une **puce de statut** ou au clic d'un déclencheur. Contenu : lignes
`pastille + libellé + valeur`. Toujours **simple**.

**Tooltip / hint** — fond `--tip`, texte `--tip-ink`, 11 px, radius 6 px, petite flèche. **Jamais**
le tooltip natif du navigateur. N'apparaît que lorsqu'un label est masqué (densité Compact/Zen).

**Puce de statut** — pastille ronde (état) + libellé court, en bas à gauche. Survol → popover
ci-dessus. Pastilles : `--green` (OK/connecté), `--accent` (utilisateurs), `--amber` (synchro).

---

## 6. Pictos & densité

Le mécanisme qui empêche le texte de se chevaucher quand on réduit.

| Mode | Rendu | Usage |
|------|-------|-------|
| **Déployé** | picto **+ texte** | découvrabilité maximale |
| **Compact** | picto seul, label en **hint** au survol | mode habituel |
| **Zen** | **petit** picto, hint au survol | expert / écran dense |

Règles :
- En Compact/Zen, le label passe en **hint** (tooltip `--tip`), jamais le tooltip natif.
- Les **héros** — valeur affichée (profil/sélecteur actif) et **action principale** — gardent leur
  libellé même en Compact. On ne masque jamais une valeur ni l'action première.
- Les **en-têtes de groupe** disparaissent en Compact/Zen (gain de hauteur).
- La densité est une classe sur le conteneur (`d-deployed` / `d-compact` / `d-zen`).

---

## 7. Règles d'or

1. Aucune couleur en dur : tout passe par les tokens.
2. Picto sur chaque action ; label repliable, jamais texte-seul.
3. Onglet/sélection actifs = `--active` + trait sombre. Le bleu ne sert qu'au focus/liens.
4. Action primaire = anthracite, une seule par contexte.
5. Tous les panneaux partagent le même en-tête de section et les mêmes hover/active.
6. Profondeur minimale au repos ; ombre réservée au flottant.
7. Clair **et** sombre traités d'emblée — jamais l'un sans l'autre.

---

## 8. Appliquer à une nouvelle app (mail, GED, …)

1. **Coller les deux blocs de tokens** (§2) dans la feuille de base. Décider la valeur sombre de
   `--paper` selon que le contenu est un « document blanc » ou non.
2. **Mapper les zones** : barres → `--chrome` ; listes/volets → `--rail` ; cartes/champs →
   `--surface` ; contenu → `--paper`.
3. **Reconstruire les actions** avec la grammaire boutons + picto/densité (§5–6). Constituer le set
   d'icônes de l'app (une icône par action).
4. **Vérifier les états** : hover `--hover`, sélection `--active`, focus `--accent`.
5. **Tester clair + sombre** sur chaque écran avant de livrer.

Résultat attendu : un client de messagerie, une GED et un éditeur qui se ressemblent à l'œil — même
calme, même grammaire — tout en gardant chacun sa structure propre.

---

## 9. Statuts (couleur de contenu)

Le chrome est monochrome ; **les données portent la couleur d'état**. Sept statuts canoniques,
définis comme teintes dans `karbonic-tokens.css` §2. Le fond doux et le texte lisible se **dérivent**
(color-mix sur `--surface`) dans la couche composants → bascule clair/sombre automatique.

| Token | Sens | Teinte (clair) |
|-------|------|----------------|
| `--status-planned`   | planifié / à venir | bleu `#3a66a8` |
| `--status-active`    | en cours           | cyan `#2f7d8a` |
| `--status-validated` | validé / OK        | vert `#4a8a5c` |
| `--status-pending`   | en attente         | ambre `#b8893f` |
| `--status-danger`    | bloqué / erreur    | rouge `#c0544b` |
| `--status-neutral`   | neutre / brouillon | gris `#686a6e` |
| `--status-invoiced`  | facturé            | indigo `#5a5aa8` |

Rendu = **chip** (`.ds-chip--<statut>` / `.kt-badge--<statut>`) : fond `color-mix(teinte 18%, surface)`,
texte teinte assombrie. Jamais d'aplat coloré plein pour un statut dans une liste dense.

---

## 10. Listings — contrat commun (tri · recherche · filtres)

**Règle d'or : même type de liste ⇒ mêmes fonctions, au même endroit, d'un écran à l'autre.**
Composant unique piloté par descripteur (réf. K-Time `list-table.php` + `ListQueryApplier`).

- **Tri** — chaque colonne triable a un en-tête cliquable (lien GET `?sort=<champ>&dir=asc|desc`),
  bascule asc↔desc, chevron ▲/▼, classe `sort-asc|sort-desc` sur le `th`.
- **Filtre par colonne** — ligne de filtres sous les en-têtes, 4 types : `text` (recherche),
  `ref` (référentiel/select), `bool` (Oui/Non), `date`. Nom de champ `f_<colonne>`, valeur en GET.
- **Recherche globale** — boîte plein-texte **dans la zone toolbar** (§12), à gauche, distincte des
  filtres colonne. Paramètre GET `q`. Composant livré : GED `partials/toolbar.php` (`.ds-toolbar`),
  K-Time `components/toolbar.php` (`.kt-toolbar`).
- **État vide** — toujours `empty-state` (icône + titre + message), jamais une ligne blanche.
- **Densité** — la liste hérite de `d-deployed|d-compact|d-zen` (§6).

Colonne = `['field','label','sortable'?,'align'?,'filter'?=>['kind'=>'text|ref|bool|date','ref'?]]`.

---

## 11. Barre d'actions — ordre figé

Réf. K-Time `action-bar.php`. **Ordre garanti, identique partout :**

> `back  →  stats  →  secondary[]  →  primary  →  danger`

- **Une seule** action primaire par contexte (anthracite). Le destructif (`danger`) est **isolé à
  droite**. Tous les boutons délèguent au composant `button` (point de style unique).
- Trois modes d'action : `href` (lien), `submitFor` (submit d'un `<form>` distant via `form=`),
  `post` (mini-form POST + `confirm` optionnel).
- **Miroir en pied de formulaire** (`--footer`) : l'action primaire d'un formulaire long est répétée
  en haut **et** en bas, alignée à droite avec filet supérieur.
- Lexique + icônes des actions courantes = **contrat gelé** (Enregistrer, Retour, Supprimer…).

---

## 12. Zones — gabarit de page

Toute page de liste/gestion se compose des mêmes zones, dans cet ordre :

```
page-header   : titre + sous-titre   |  action-bar (haut, ordre figé)
toolbar       : [recherche globale]     [filtres transverses]   [densité]
list-table    : en-têtes triables · ligne de filtres · lignes (actions de ligne à droite)
                (empty-state si vide)
action-bar    : miroir pied (formulaires longs)
pagination
```

Chaque zone = **un composant** (`page-header`, `toolbar`, `action-bar`, `list-table`,
`empty-state`, `pagination`). On ne réécrit pas une zone à la main : on paramètre le composant.

---

## 13. Distribution & nommage (deux repos)

- **Source de vérité** = `karbonic-tokens.css` (fichier maître) + ce doc. **Copié à l'identique**
  dans chaque repo (pas de package partagé tant qu'il n'y a pas de monorepo).
- **Noms canoniques non-préfixés** (`--app-bg`, `--ink`, `--accent`, `--primary`, `--status-*`).
  Chaque app garde sa couche d'**alias legacy** locale → churn quasi nul :
  - GED : `theme.css` / `app.css` déjà aliasés ; `design-system.css` porte le chrome `.ds-*`.
  - K-Time : bloc `--kt-*` → canonique (voir `karbonic-tokens.css` §5), y compris la **bascule du
    chrome bleu vers neutre/anthracite**.
- **Composants** : set commun issu de K-Time. Classes `kt-*` conservées au départ (K-Time est le plus
  avancé) ; GED importe tel quel ; un renommage vers un préfixe neutre est un vernis ultérieur.
- **Garde-fou (L4)** : lint (hook/CI) dans les deux repos — refuse CDN de style, couleurs en dur
  (`#hex`/`rgb()` hors tokens), `<table>` hors composant, préfixes de classe hors charte.
