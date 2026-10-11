# Lot ged-pdfa-1 — copie d'archivage PDF/A-2b à l'ingestion

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/ged-pdfa-1`

## But

Chaque document **PDF ou image** ingéré reçoit, **à côté de l'original** (jamais modifié, jamais remplacé), une
copie d'archivage **PDF/A-2b**, texte OCR compris quand il existe. C'est cette copie qui sert à la consultation
longue durée ; l'original reste la pièce qui fait foi (empreinte, scellement inchangés). Échec de conversion =
document ingéré quand même, statut d'échec visible, jamais de perte.

## Mécanisme natif retenu (Ghostscript 10.02.1 installé)

Doc officielle `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 14.7 « Creating a PDF/A document »,
p. 315-316** (page physique 319-320) :

- `-dPDFA=2` sélectionne PDF/A-2 (p. 316 : « Specify the `-dPDFA` option […] `-dPDFA=2` for PDF/A-2 ») ;
- `-sColorConversionStrategy=RGB` est requis par la doc pour un PDF/A (p. 316) ;
- un **PDF/A definition file** doit précéder le document et fournir l'**ICCProfile** de l'OutputIntent
  (p. 316) ; l'échantillon livré est `lib/PDFA_def.ps`, le profil sRGB est `iccprofiles/srgb.icc` ;
- `PDFACompatibilityPolicy` (p. 316) : **1** = « the feature or operation is ignored, the resulting PDF file
  will be PDF/A compliant » — retenu plutôt que 0 (sortie non conforme silencieuse) et que 2 (abandon) pour
  privilégier une copie conforme, les éléments écartés étant journalisés.

Commande retenue : `gs -dPDFA=2 -dBATCH -dNOPAUSE -dNOOUTERSAVE -sColorConversionStrategy=RGB
-sDEVICE=pdfwrite -dPDFACompatibilityPolicy=1 -sOutputFile=<copie> --permit-file-read=<srgb.icc>
-f <PDFA_def.ps modifié (ICC absolu)> <source>`.

## Fait

- (en cours) Reconnaissance et écriture de l'oracle.

## En cours

- Oracle rouge PHPUnit du service de copie.

## Reste

- Service de conversion + contrôle partiel PHP.
- Branchement ingestion (dossier + IMAP) et rattrapage du fonds (point de reprise).
- Écrans fiche document (état + téléchargement) et Application (avancement du rattrapage).
- Note Ghostscript et convention dans `docs/secteurs/ingestion.md`, budget/page mesuré.
- Porte qualité, harness `-SansBanc`, preuves.

## Mesures exactes

- (à compléter)

ETAT: en cours
