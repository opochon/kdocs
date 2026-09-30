# Contrat ClearMyMails → GED (plugin « Ged ») — v2, 30-09-2026

Décisions d'Olivier qui le cadrent :
- 19-08 : « si pas de GED liée, pas de rangement ; si la GED est appairée, là ça peut fonctionner ». L'appairage porte
  l'**identité de l'utilisateur** ; les droits sont décidés par la GED, jamais par CMM (CMM n'a pas de modèle de droits).
- 30-09 (matin) : la liaison part de la GED (profil + courriel d'auto-configuration), CMM la valide, la GED peut la révoquer ;
  la GED ne lit jamais la boîte (« je trouve la version GED peut tout lire intrusive ») : CMM pousse ce que l'utilisateur choisit.
- 30-09 : un plugin « Ged » dans CMM pour lier et envoyer **soit à l'ingesteur, soit dans un dossier**, et **soit le
  message, soit une ou des pièces jointes**.

## 1. Appairage — v2 (décision d'Olivier du 30-09 : « c'est la GED qui propose, CMM valide »)

Principe : la liaison part de la GED, vers l'adresse mail de l'utilisateur, et c'est CMM qui la valide. La GED ne lit
jamais la boîte : CMM **pousse** ce que l'utilisateur choisit (« j'ai reçu une facture, je l'envoie »), rien d'autre.

1. **GED › Mon profil** : l'utilisateur renseigne son adresse mail (vérifiée par un lien envoyé à cette adresse, comme
   toute adresse de profil) et clique « Relier ClearMyMails ».
2. La GED crée un appairage **en attente** (24 h) : un code d'échange à usage unique (aléa 256 bits, seule son empreinte
   est gardée) et un **code de vérification court** (6 caractères sans ambiguïté, ex. `K7M-4QX`) affiché à l'écran.
3. La GED envoie à l'adresse du profil un **courriel d'auto-configuration** : objet « Relier votre GED à ClearMyMails »,
   en-tête `X-GED-Appairage: v1`, texte lisible par un humain, et une pièce `ged-appairage.json`
   `{version:1, ged_url, ged_nom, compte, code, verification, expire_le}`. Aucun code exécutable n'est joint ni téléchargé :
   le plugin « Ged » fait partie de CMM ; le courriel l'**active et le configure**, il ne l'installe pas.
4. **CMM** reconnaît ce courriel (en-tête + pièce valide) et affiche un bandeau « Relier la GED <ged_nom> (<ged_url>) au
   compte <compte> ? » avec le **code de vérification** : l'utilisateur confirme qu'il est identique à celui affiché dans
   sa GED (un courriel forgé par un tiers ne peut pas connaître ce code ; CMM montre aussi son verdict d'expéditeur
   SPF/DKIM existant). Refuser = rien ne se passe.
5. Sur « Valider », CMM échange le code : `POST {ged_url}/api/v1/appairage/jeton` `{code, verification, poste}` →
   `{jeton, compte, ged_nom}`. Code inconnu, expiré, rejoué ou code de vérification faux → 400 nommé. CMM stocke le jeton
   chiffré par profil, active le plugin, et range le courriel d'auto-configuration (lu ; jamais supprimé).
6. La GED passe l'appairage à « relié » : **Mon profil › Appareils reliés** montre « ClearMyMails — poste — relié le … —
   dernier envoi le … » avec **Révoquer**. Jeton révoqué → 401 nommé au prochain envoi ; CMM repasse « non relié » et
   le dit à l'utilisateur. CMM peut aussi « Délier » (`DELETE /api/v1/appairage/moi`).
7. `GET /api/v1/appairage/moi` (Bearer) → `{compte, ged_nom, droits: {ingestion: bool}, dossiers_ecriture: int}` : statut.

Pas de GED reliée → CMM n'affiche pas « Envoyer à la GED » en grisé : il affiche « Relier une GED » (qui explique qu'on
relie depuis la GED, Mon profil).

## 2. Destinations (droits décidés par la GED, pour le compte du jeton)

- `GET /api/v1/dossiers?parent=<chemin>` → les dossiers du fonds où **ce compte** peut déposer
  `[{chemin, nom, enfants: bool}]` (refus par défaut : un dossier sans droit n'apparaît pas).
- Dépôt : `POST /api/v1/depots` (multipart), existant (V2-08a), étendu :
  - `destination=ingestion` (défaut) → la chaîne d'ingestion (découpe, lecture, classement, « À traiter ») ;
  - `destination=dossier&dossier=<chemin>` → rangé directement dans ce dossier (Socle `Depot::deposer`), sans découpe
    ni classement automatique, statut `a_controler` ; 403 nommé si le compte n'a pas le droit d'écriture sur ce dossier.
  - `origine` (JSON, facultatif) : `{application:"cmm", message_id, compte_mail, dossier_mail, de, a, objet, date}` —
    écrit dans le `.meta.json` du document (onglet « Liens » de la fiche : « reçu par courriel de … le … »).
  - Réponse inchangée : 202 `{travail|document, empreinte, etat}` ; même empreinte = même réponse (idempotent).

## 3. Quoi envoyer

- **Pièces jointes** : une requête par pièce choisie (le fichier tel quel).
- **Le message** : le message **original RFC 822** (`.eml`, type `message/rfc822`). La GED l'accepte comme document :
  texte indexé (en-têtes + corps texte), aperçu = rendu HTML **nettoyé** de l'en-tête et du corps (aucun script, aucune
  ressource distante), pièces jointes listées dans l'aperçu. Option CMM « avec ses pièces jointes » : le `.eml` + une
  requête par pièce, liées par le même `origine.message_id`.
- Types refusés et taille maximale : ceux de l'API (415 / 413 nommés) ; CMM les affiche tels quels.

## 4. Côté CMM (plugin « Ged »)

- Réglages › GED : statut (relié à <ged_nom>, compte), Délier, test de connexion ; bandeau de validation du courriel d'auto-configuration (§1).
- Dans la lecture d'un message et dans le bandeau des pièces jointes : « Envoyer à la GED » → une boîte :
  **Quoi** (le message / pièces cochées / le message avec ses pièces) · **Où** (À traiter dans la GED / un dossier,
  choisi dans l'arbre renvoyé par `GET /api/v1/dossiers`) · Envoyer.
- Après envoi : une marque sur le message (« envoyé à la GED le … », lien vers la GED), la réponse de la GED affichée
  telle quelle en cas de refus. Rien n'est supprimé ni déplacé dans la boîte mail.
- File d'envoi avec reprise : GED injoignable → l'envoi attend et repart, l'utilisateur le voit.

## 5. Oracles

GED : appairage (code unique, expiré, rejoué), jeton lié au compte (un compte sans droit sur un dossier → 403, le dossier
n'est pas listé), `destination=dossier` rangé et tracé, `.eml` accepté, indexé, aperçu sans script ni ressource distante,
idempotence. CMM : contre une GED simulée (serveur local qui suit ce contrat) puis contre GEDv2 réelle en recette :
relier, envoyer PJ → ingestion, envoyer message → dossier, refus 403 affiché, GED éteinte → reprise.
