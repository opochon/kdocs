<?php

declare(strict_types=1);

namespace Ged\Edition;

use Ged\Config;
use Ged\Jonctions\ErreurApi;
use Ged\Jonctions\Jetons;
use Ged\Securite\DroitsDossiers;
use PDO;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Porte HTTP de l'édition depuis le poste (F13). L'API est authentifiée par le JETON D'APPAREIL de
 * `Jonctions` (`Authorization: Bearer …`), jamais par la session web : elle ne dépend donc pas du CSRF
 * et ne lit aucun cookie. Le jeton est lié à un compte réel ; les droits de dossier sont vérifiés ici,
 * document par document, avec la même décision que la garde web.
 *
 *   GET    /api/v1/edition/documents/{id}            état : {document, nom, mime, taille, empreinte, version}
 *   GET    /api/v1/edition/documents/{id}/fichier    le fichier courant + X-Ged-Empreinte / X-Ged-Version
 *   POST   /api/v1/edition/documents/{id}/versions   multipart « fichier » + « version_base »
 *   POST   /api/v1/edition/documents/{id}/ouverture  signal d'ouverture (l'agent s'attache au document)
 *   POST   /api/v1/edition/documents/{id}/signal     prolonge l'ouverture
 *   POST   /api/v1/edition/documents/{id}/fermeture  lève l'ouverture informative
 *   POST   /api/v1/edition/documents/{id}/secours    copie de secours (jamais une version)
 *   GET    /api/v1/edition/documents/{id}/secours    copies disponibles pour ce document
 *
 * Et la route de SESSION « Récupérer une copie de secours » de la fiche :
 *   POST   /documents/{id}/secours/recuperer         (CSRF et droit `modifier` vérifiés par la garde)
 */
final class Api
{
    public function __construct(
        private readonly Config $config,
        private readonly Jetons $jetons,
        private readonly DroitsDossiers $droits,
    ) {
    }

    /** @param array<string, string> $args */
    public function document(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/edition/documents/' . $id;
        try {
            $jeton = $this->authentifier($requete, $etape);
            $editeur = new Editeur($this->config);
            $doc = $this->exigerDocument($editeur, $id, $etape);
            $this->exigerDroit($jeton, $id, 'lire', $etape);

            // Contrat de l'état, sans exposer le chemin interne du fonds.
            return $this->json($reponse, 200, [
                'document' => $doc['id'], 'nom' => $doc['nom'], 'mime' => $doc['mime'],
                'taille' => $doc['taille'], 'empreinte' => $doc['empreinte'], 'version' => $doc['version'],
            ]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "l’état du document $id n’a pas pu être lu", $etape));
        }
    }

    /** @param array<string, string> $args */
    public function fichier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/edition/documents/' . $id . '/fichier';
        try {
            $jeton = $this->authentifier($requete, $etape);
            $editeur = new Editeur($this->config);
            $doc = $this->exigerDocument($editeur, $id, $etape);
            $this->exigerDroit($jeton, $id, 'lire', $etape);
            $chemin = $editeur->chemin($id);
            if ($chemin === null || !is_file($chemin)) {
                throw new ErreurApi(404, "le fichier du document $id a disparu du fonds", $etape);
            }
            $contenu = (string) file_get_contents($chemin);

            $reponse->getBody()->write($contenu);

            return $reponse
                ->withHeader('Content-Type', $doc['mime'])
                ->withHeader('Content-Disposition', 'attachment; filename="' . rawurlencode((string) $doc['nom']) . '"')
                ->withHeader('X-Ged-Empreinte', (string) $doc['empreinte'])
                ->withHeader('X-Ged-Version', (string) $doc['version'])
                ->withStatus(200);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "le fichier du document $id n’a pas pu être servi", $etape));
        }
    }

    /** @param array<string, string> $args */
    public function version(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/edition/documents/' . $id . '/versions';
        $temporaire = null;
        try {
            $jeton = $this->authentifier($requete, $etape);
            $editeur = new Editeur($this->config);
            $this->exigerDocument($editeur, $id, $etape);
            $this->exigerDroit($jeton, $id, 'modifier', $etape);

            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $brut = $corps['version_base'] ?? '';
            if (!is_string($brut) || trim($brut) === '' || !ctype_digit(trim($brut)) || (int) $brut < 1) {
                throw new ErreurApi(400, 'version de base requise (entier ≥ 1)', $etape);
            }
            $versionBase = (int) trim($brut);
            $fichier = $requete->getUploadedFiles()['fichier'] ?? null;
            $temporaire = $this->preparerFichier($fichier, $etape);

            $resultat = $editeur->deposerVersion($id, $temporaire, (string) $jeton['compte_identifiant'], $versionBase);

            return $this->json($reponse, 200, $resultat);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "la nouvelle version du document $id n’a pas pu être enregistrée", $etape));
        } finally {
            if (is_string($temporaire) && is_file($temporaire)) {
                @unlink($temporaire);
            }
        }
    }

    /** @param array<string, string> $args */
    public function ouverture(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/edition/documents/' . $id . '/ouverture';
        try {
            $jeton = $this->authentifier($requete, $etape);
            $this->exigerDocument(new Editeur($this->config), $id, $etape);
            $this->exigerDroit($jeton, $id, 'modifier', $etape);
            $ouverture = (new Ouvertures($this->pdo()))->ouvrir($id, (string) $jeton['compte_identifiant'], (string) $jeton['appareil']);

            return $this->json($reponse, 200, ['ouverture' => $ouverture]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "l’ouverture du document $id n’a pas pu être signalée", $etape));
        }
    }

    /** @param array<string, string> $args */
    public function signal(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/edition/documents/' . $id . '/signal';
        try {
            $jeton = $this->authentifier($requete, $etape);
            $this->exigerDroit($jeton, $id, 'modifier', $etape);
            $prolonge = (new Ouvertures($this->pdo()))->signal($id, (string) $jeton['compte_identifiant'], (string) $jeton['appareil']);

            return $this->json($reponse, 200, ['prolonge' => $prolonge]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "le signal du document $id n’a pas pu être enregistré", $etape));
        }
    }

    /** @param array<string, string> $args */
    public function fermeture(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/edition/documents/' . $id . '/fermeture';
        try {
            $jeton = $this->authentifier($requete, $etape);
            $this->exigerDroit($jeton, $id, 'modifier', $etape);
            $ferme = (new Ouvertures($this->pdo()))->fermer($id, (string) $jeton['compte_identifiant'], (string) $jeton['appareil']);

            return $this->json($reponse, 200, ['ferme' => $ferme]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "la fermeture du document $id n’a pas pu être signalée", $etape));
        }
    }

    /** @param array<string, string> $args */
    public function secours(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/edition/documents/' . $id . '/secours';
        $temporaire = null;
        try {
            $jeton = $this->authentifier($requete, $etape);
            $this->exigerDocument(new Editeur($this->config), $id, $etape);
            $this->exigerDroit($jeton, $id, 'modifier', $etape);
            $fichier = $requete->getUploadedFiles()['fichier'] ?? null;
            $temporaire = $this->preparerFichier($fichier, $etape);
            $meta = (new Secours($this->config))->deposer($id, (string) $jeton['appareil'], $temporaire);

            return $this->json($reponse, 200, $meta);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "la copie de secours du document $id n’a pas pu être déposée", $etape));
        } finally {
            if (is_string($temporaire) && is_file($temporaire)) {
                @unlink($temporaire);
            }
        }
    }

    /** @param array<string, string> $args */
    public function secoursListe(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/edition/documents/' . $id . '/secours';
        try {
            $jeton = $this->authentifier($requete, $etape);
            $this->exigerDocument(new Editeur($this->config), $id, $etape);
            $this->exigerDroit($jeton, $id, 'lire', $etape);

            return $this->json($reponse, 200, ['copies' => (new Secours($this->config))->lister($id)]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            $this->journaliser($etape, $e);

            return $this->erreur($reponse, new ErreurApi(500, "les copies de secours du document $id n’ont pas pu être listées", $etape));
        }
    }

    /**
     * Fiche : récupère une copie de secours en nouvelle version. Route de SESSION (CSRF et droit
     * `documents:modifier` déjà vérifiés par la garde centrale).
     *
     * @param array<string, string> $args
     */
    public function recupererSecours(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
        $session = $requete->getAttribute('ged.session');
        $auteur = is_array($session) ? (string) ($session['utilisateur'] ?? 'inconnu') : 'inconnu';
        try {
            $version = (new Secours($this->config))->recuperer($id, (string) ($corps['copie'] ?? ''), $auteur);

            return $reponse->withHeader('Location', '/documents/' . $id . '?secours_succes=' . $version)->withStatus(303);
        } catch (Throwable $e) {
            $this->journaliser('POST /documents/' . $id . '/secours/recuperer', $e);

            return $reponse->withHeader('Location', '/documents/' . $id . '?secours_erreur=' . rawurlencode($e->getMessage()))->withStatus(303);
        }
    }

    /**
     * Vérifie le jeton d'appareil et nomme le refus. Le jeton n'est jamais journalisé.
     *
     * @return array{id:int, application:string, portee:string, compte_id:?int, compte_identifiant:?string, appareil:?string}
     * @throws ErreurApi
     */
    private function authentifier(Requete $requete, string $etape): array
    {
        $entete = trim($requete->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $entete, $trouve) !== 1) {
            throw new ErreurApi(401, 'jeton absent (en-tête Authorization: Bearer attendu)', $etape);
        }
        $ligne = $this->jetons->resoudre($trouve[1]);
        if ($ligne === null) {
            throw new ErreurApi(401, 'jeton inconnu', $etape);
        }
        if ($ligne['revoque']) {
            throw new ErreurApi(401, 'jeton révoqué', $etape);
        }
        if ($ligne['appareil'] === null || $ligne['compte_identifiant'] === null) {
            throw new ErreurApi(403, 'jeton non lié à un compte appairé', $etape);
        }
        if ($ligne['portee'] !== Jetons::PORTEE_DEPOT && $ligne['portee'] !== '*') {
            throw new ErreurApi(403, 'portée insuffisante : « depot » requise', $etape);
        }
        $this->jetons->marquerUtilisation($ligne['id']);

        return $ligne;
    }

    /**
     * Le compte du jeton doit pouvoir agir sur CE document, au dossier le plus précis (même décision
     * que la garde web). Le document est d'abord résolu : inconnu = 404, hors droits = 403.
     *
     * @param array<string, mixed> $jeton ligne résolue par `authentifier`
     * @throws ErreurApi
     */
    private function exigerDroit(array $jeton, int $document, string $niveau, string $etape): void
    {
        $compte = is_int($jeton['compte_id'] ?? null) ? (int) $jeton['compte_id'] : null;
        if ($compte === null) {
            throw new ErreurApi(403, 'le jeton n’est lié à aucun compte', $etape);
        }
        $dossier = $this->droits->dossierPourDocument($document);
        if ($dossier === null || !$this->droits->autorise($compte, $dossier, $niveau)) {
            throw new ErreurApi(403, sprintf('droit %s insuffisant sur le document %d', $niveau, $document), $etape);
        }
    }

    /**
     * @return array{id:int, nom:string, chemin:string, mime:string, taille:int, empreinte:string, version:int}
     * @throws ErreurApi
     */
    private function exigerDocument(Editeur $editeur, int $document, string $etape): array
    {
        $etat = $editeur->document($document);
        if ($etat === null) {
            throw new ErreurApi(404, "document introuvable : $document", $etape);
        }

        return $etat;
    }

    /**
     * Le fichier téléversé est déplacé vers un temporaire qui GARDE l'extension d'origine : le Socle
     * décide du type par l'extension, et changer de format sous la même extension est refusé.
     *
     * @throws ErreurApi
     */
    private function preparerFichier(?UploadedFileInterface $fichier, string $etape): string
    {
        if (!$fichier instanceof UploadedFileInterface) {
            throw new ErreurApi(400, 'le fichier est requis (champ multipart « fichier »)', $etape);
        }
        if ($fichier->getError() !== UPLOAD_ERR_OK) {
            throw new ErreurApi(400, 'transfert du fichier incomplet (code ' . $fichier->getError() . ')', $etape);
        }
        $extension = strtolower((string) pathinfo((string) $fichier->getClientFilename(), PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
            $extension = '';
        }
        $temporaire = sys_get_temp_dir() . '/gedv2-edition-' . bin2hex(random_bytes(8))
            . ($extension !== '' ? '.' . $extension : '');
        $fichier->moveTo($temporaire);

        return $temporaire;
    }

    private function pdo(): PDO
    {
        return \Ged\Base::pdo($this->config);
    }

    private function journaliser(string $etape, Throwable $e): void
    {
        error_log(sprintf('[GEDv2 edition] %s : %s (%s:%d)', $etape, $e->getMessage(), $e->getFile(), $e->getLine()));
    }

    /** @param array<mixed> $donnees */
    private function json(Reponse $reponse, int $code, array $donnees): Reponse
    {
        $reponse->getBody()->write((string) json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $reponse->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($code);
    }

    private function erreur(Reponse $reponse, ErreurApi $erreur): Reponse
    {
        $reference = substr(hash('sha256', $erreur->etape . $erreur->cause . microtime()), 0, 10);

        return $this->json($reponse, $erreur->statut, [
            'erreur' => ['etape' => $erreur->etape, 'cause' => $erreur->cause, 'reference' => $reference],
        ]);
    }
}
