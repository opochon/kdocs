# Contrat ClearMyMails → GED (plugin « Ged ») — v1, 30-09-2026

Décisions d'Olivier qui le cadrent :
- 19-08 : « si pas de GED liée, pas de rangement ; si la GED est appairée, là ça peut fonctionner ». L'appairage porte
  l'**identité de l'utilisateur** ; les droits sont décidés par la GED, jamais par CMM (CMM n'a pas de modèle de droits).
- 30-09 : un plugin « Ged » dans CMM pour lier et envoyer **soit à l'ingesteur, soit dans un dossier**, et **soit le
  message, soit une ou des pièces jointes**.

## 1. Appairage (une fois par utilisateur CMM et par GED)

1. Dans CMM, Réglages › GED : l'utilisateur saisit l'adresse de sa GED et clique « Relier ».
2. CMM ouvre `GET {ged}/appairage?application=cmm&nom=<poste>&retour=<url de retour CMM>&etat=<aléa>`.
3. La GED exige la connexion de l'utilisateur (sa session normale), affiche « ClearMyMails demande à déposer des
   documents en votre nom » (application, poste, portée `depot`), l'utilisateur accepte.
4. La GED crée un **jeton d'appairage lié à ce compte** (portée `depot`, révocable, haché en base, jamais en clair
   après la remise) et redirige vers `retour?etat=<aléa>&code=<code à usage unique, 5 min>`.
5. CMM échange le code : `POST {ged}/api/v1/appairage/jeton` `{code}` → `{jeton, compte, ged_nom, expire_le|null}`.
   CMM stocke le jeton chiffré, par profil. Un code déjà utilisé ou expiré → 400 nommé.
6. `GET {ged}/api/v1/appairage/moi` (Bearer) → `{compte, droits: {ingestion: bool}, dossiers_ecriture: int}` : sert
   au statut « Relié à GED de <compte> » et au test de connexion. Jeton révoqué → 401 nommé ; CMM repasse « non relié ».
7. Révocation : côté GED (écran Application › Appareils reliés) ou côté CMM (« Délier » → `DELETE /api/v1/appairage/moi`).

Pas de GED reliée → CMM n'affiche **pas** l'action « Envoyer à la GED » en grisé : il affiche « Relier une GED ».

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

- Réglages › GED : adresse, Relier / Délier, statut, test de connexion.
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
