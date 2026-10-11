# Lot pdfa-rattrapage-1 — relancer une copie PDF/A en échec, sans perdre le document

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/pdfa-rattrapage-1`

## Cause (constat du juge ged-pdfa-1)

`CopieArchivage::pour()` rend l'échec déjà persisté tant que l'empreinte d'origine ne change pas. Un document
dont la copie PDF/A a échoué pour une **cause d'environnement** (Ghostscript absent au moment de l'ingestion,
profil ICC ou `PDFA_def.ps` introuvable, publication refusée…, un futur délai dépassé) **reste donc en échec
pour toujours** : installer Ghostscript après coup, ou simplement relancer, ne change rien. Seule une
modification du fichier le débloque — ce qui est contraire au besoin « réparer sans toucher à la pièce qui
fait foi ».

## Doc officielle lue (Ghostscript 10.02.1 installé)

- `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 14.7 « Creating a PDF/A document », p. 315-316**
  (pages physiques 319-320) : options `-dPDFA=2`, `-sColorConversionStrategy=RGB`, fichier de définition
  PDF/A portant l'`ICCProfile` de l'OutputIntent, et `PDFACompatibilityPolicy` (0 = sortie non conforme
  silencieuse, **1 = l'opération non conforme est ignorée, la sortie reste conforme** — celui du lot
  ged-pdfa-1, 2 = abandon) → le binaire seul ne distingue pas « document en cause » et « outil absent ».
- `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 8.4 « Return codes », p. 155** : les fonctions
  `gsapi_*` rendent un entier — `0` = aucune erreur, `gs_error_Quit` (un `quit` n'est PAS une erreur),
  `< 0` = erreur, `≤ gs_error_Fatal` = erreur fatale. Le processus `gswin64c` suit ces codes ; ils ne
  disent donc rien de la **nature** de l'échec (outil vs document). D'où un classement explicite ci-dessous,
  à partir du diagnostic lu, pas du seul code de sortie (déjà l'esprit de `ConversionPdfA::executer()`).
- `docs/secteurs/ingestion.md`, journal « ged-pdfa-1 — copie d'archivage PDF/A-2b » : convention `.pdfa/`,
  état `pdfa` dans le `.meta.json` (ou flanc pour un scellé), échec `statut: echec` + `raison`, aucune copie
  partielle, `RattrapagePdfA` reprenable et écarté.

## Classement des échecs (décision, à tenir par test)

| Cause d'échec | Nature | Rejouée automatiquement par le rattrapage ? |
| --- | --- | --- |
| Ghostscript absent | environnement | **oui** (`reprise: auto`) |
| Profil ICC `srgb.icc` introuvable | environnement | **oui** |
| `PDFA_def.ps` introuvable | environnement | **oui** |
| Ghostscript n'a pas pu démarrer | environnement | **oui** |
| Dossier de destination non créé | environnement | **oui** |
| Publication du fichier refusée | environnement | **oui** |
| Aucune sortie exploitable | environnement | **oui** |
| Fichier source introuvable | environnement | **oui** |
| PDF chiffré (mot de passe requis) | document | **non** (`reprise: jamais`) |
| PDF corrompu / illisible | document | **non** |
| Format non pris en charge | document | **non** |
| Autre refus nommé de Ghostscript | document | **non** (défaut prudent) |

La relance **explicite** (`pour(..., forcer: true)`) ne rejoue que les causes marquées `auto` : un échec
définitif n'est jamais rejoué par le produit, ni automatiquement ni à la main. Le champ `reprise`
(`auto` | `jamais`) est persisté avec l'échec ; un ancien échec sans ce champ est classé à la lecture
depuis sa `raison`, donc les documents déjà bloqués redeviennent réparables.

## État du run

- [x] Lecture doc Ghostscript (§14.7 p.315-316, §8.4 p.155) et note de secteur ; fiche ouverte.
- [ ] Oracle rouge (relance après « installation », définitif non relancé, idempotence, original intact).
- [ ] Correction et tests verts ; PHPUnit complet, PHPStan 0, baseline vide.

ETAT: en cours
