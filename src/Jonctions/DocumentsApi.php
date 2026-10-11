<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Porte HTTP de l'API REST des documents (lot ged-api-documents). Authentification par le JETON de
 * `Jonctions` (`Authorization: Bearer …`), **hors session** : aucune lecture de cookie, aucun CSRF. Le
 * jeton doit être LIÉ À UN COMPTE et porter la portée `documents_lecture` (ou `documents_ecriture`, ou
 * `*`) ; le compte est passé explicitement au service, qui applique les droits de dossier.
 *
 *   GET  /api/v1/documents                              liste + filtres + recherche + pagination
 *   POST /api/v1/documents                              dépôt (vers l'ingestion, ou dans un dossier)
 *   GET  /api/v1/documents/{id}                         fiche
 *   POST /api/v1/documents/{id}                         modification des métadonnées
 *   GET  /api/v1/documents/{id}/fichier                 fichier courant
 *   GET  /api/v1/documents/{id}/versions/{n}/fichier    version archivée
 *   GET  /api/v1/types | /etiquettes | /emetteurs | /vues   référentiels
 *
 * Toute erreur est un JSON `{"erreur":{"etape","cause","reference"}}` : une erreur se nomme, jamais un 500 muet.
 */
final class DocumentsApi
{
    /** Champs de métadonnées acceptés en formulaire de dépôt (sinon champ « metadonnees » au format JSON). */
    private const CHAMPS_META_DEPOT = ['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes'];

    public function __construct(
        private readonly Config $config,
        private readonly Jetons $jetons,
        private readonly SourceApi $source,
        private readonly DepotDossier $depotDossier,
        private readonly DroitsDossiers $droits,
        private readonly int $tailleMax = DepotApi::TAILLE_MAX_DEFAUT,
    ) {
    }

    public function lister(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/documents';
        try {
            $session = $this->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->catalogue($session)->lister($requete->getQueryParams(), $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'la liste des documents n’a pas pu être lue');
        }
    }

    /** @param array<string, string> $args */
    public function lire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/documents/' . $id;
        try {
            $session = $this->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $this->catalogue($session)->fiche($id, $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, "la fiche du document $id n’a pas pu être lue");
        }
    }

    /** @param array<string, string> $args */
    public function fichier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/documents/' . $id . '/fichier';
        try {
            $session = $this->authentifier($requete, $etape, false);

            return $this->servirFichier($reponse, $this->catalogue($session)->fichier($id, null, $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, "le fichier du document $id n’a pas pu être servi");
        }
    }

    /** @param array<string, string> $args */
    public function fichierVersion(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $numero = (int) ($args['numero'] ?? 0);
        $etape = 'GET /api/v1/documents/' . $id . '/versions/' . $numero . '/fichier';
        try {
            $session = $this->authentifier($requete, $etape, false);

            return $this->servirFichier($reponse, $this->catalogue($session)->fichier($id, $numero, $etape));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, "la version $numero du document $id n’a pas pu être servie");
        }
    }

    /** @param array<string, string> $args */
    public function modifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'POST /api/v1/documents/' . $id;
        try {
            $session = $this->authentifier($requete, $etape, true);
            $corps = $requete->getParsedBody();

            return $this->json($reponse, 200, $this->catalogue($session)->modifier(
                $id, is_array($corps) ? $corps : [], (string) $session['identifiant'], $etape,
            ));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, "les métadonnées du document $id n’ont pas pu être modifiées");
        }
    }

    public function deposer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/documents';
        try {
            $session = $this->authentifier($requete, $etape, true);
            $fichier = $this->fichierDepot($requete, $etape);
            $nom = $fichier->getClientFilename() ?? 'depot';
            if (SourceApi::extensionAutorisee($nom) === null) {
                throw new ErreurApi(415, sprintf('type refusé : « %s » (types acceptés : %s)', $nom, implode(', ', SourceApi::EXTENSIONS)), $etape);
            }
            $taille = $fichier->getSize();
            if ($taille !== null && $taille > $this->tailleMax) {
                throw new ErreurApi(413, sprintf('fichier trop gros : %d octets (maximum %d)', $taille, $this->tailleMax), $etape);
            }
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $metadonnees = $this->metadonneesDepot($corps);
            $dossier = trim(str_replace('\\', '/', (string) ($corps['dossier'] ?? '')), '/');

            if ($dossier !== '') {
                $idDossier = $this->droits->dossierPourChemin($dossier);
                if (str_contains($dossier, '..') || $idDossier === null || !$this->droits->autorise($session['compte'], $idDossier, 'deposer')) {
                    throw new ErreurApi(403, sprintf('droit d’écriture insuffisant sur le dossier « %s »', $dossier), $etape);
                }
                $resultat = $this->depotDossier->deposer($fichier, $nom, $metadonnees, $dossier, $etape);

                return $this->json($reponse, 202, ['document' => $resultat['document'], 'empreinte' => $resultat['empreinte'], 'etat' => 'a_controler']);
            }

            $resultat = $this->source->recevoir($fichier, $nom, $metadonnees, (string) $session['application']);
            $id = $resultat['travail'];

            return $this->json($reponse, 202, [
                'travail' => $id, 'empreinte' => $resultat['empreinte'], 'etat' => 'recu', 'suivi' => '/api/v1/depots/' . $id,
            ]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'le dépôt n’a pas pu être enregistré');
        }
    }

    public function types(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->referentiel($requete, $reponse, 'GET /api/v1/types', static fn (Catalogue $c): array => $c->types());
    }

    public function etiquettes(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->referentiel($requete, $reponse, 'GET /api/v1/etiquettes', static fn (Catalogue $c): array => $c->etiquettes());
    }

    public function emetteurs(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->referentiel($requete, $reponse, 'GET /api/v1/emetteurs', static fn (Catalogue $c): array => $c->emetteurs());
    }

    public function vues(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->referentiel($requete, $reponse, 'GET /api/v1/vues', static fn (Catalogue $c): array => $c->vues());
    }

    /**
     * Vérifie le jeton et nomme précisément un refus : absent/Inconnu/révoqué → 401 ; sans compte ou portée
     * insuffisante → 403 ; débit dépassé → 429. Le jeton n'est jamais journalisé.
     *
     * @return array{compte:int, identifiant:string, application:string, administrateur:bool}
     * @throws ErreurApi
     */
    private function authentifier(Requete $requete, string $etape, bool $ecriture): array
    {
        $valeur = $this->jetonEntete($requete);
        if ($valeur === '') {
            throw new ErreurApi(401, 'jeton absent (en-tête Authorization: Bearer attendu)', $etape);
        }
        $ligne = $this->jetons->resoudre($valeur);
        if ($ligne === null) {
            throw new ErreurApi(401, 'jeton inconnu', $etape);
        }
        if ($ligne['revoque']) {
            throw new ErreurApi(401, 'jeton révoqué', $etape);
        }
        if ($ligne['compte_id'] === null || $ligne['compte_identifiant'] === null) {
            throw new ErreurApi(403, 'le jeton n’est lié à aucun compte : les droits ne peuvent pas être appliqués', $etape);
        }
        if (!$this->porteeAutorisee($ligne['portee'], $ecriture)) {
            $requise = $ecriture ? 'documents_ecriture' : 'documents_lecture';
            throw new ErreurApi(403, "portée insuffisante : « $requise » requise", $etape);
        }
        if (!(new Debit($this->config))->autoriser('documents:jeton:' . $ligne['id'])) {
            throw new ErreurApi(429, 'débit dépassé pour ce jeton (réessayer dans une minute)', $etape);
        }
        $this->jetons->marquerUtilisation($ligne['id']);

        return [
            'compte' => $ligne['compte_id'], 'identifiant' => $ligne['compte_identifiant'],
            'application' => $ligne['application'],
            'administrateur' => in_array('*', $this->droits->permissionsPourCompte($ligne['compte_id']), true),
        ];
    }

    private function porteeAutorisee(string $portee, bool $ecriture): bool
    {
        if ($portee === '*') {
            return true;
        }

        return $ecriture
            ? $portee === Jetons::PORTEE_ECRITURE
            : in_array($portee, [Jetons::PORTEE_LECTURE, Jetons::PORTEE_ECRITURE], true);
    }

    private function jetonEntete(Requete $requete): string
    {
        $entete = trim($requete->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $entete, $trouve) !== 1) {
            return '';
        }

        return $trouve[1];
    }

    /** @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session */
    private function catalogue(array $session): Catalogue
    {
        return new Catalogue($this->config, $session['compte'], $session['administrateur']);
    }

    /** @throws ErreurApi */
    private function fichierDepot(Requete $requete, string $etape): UploadedFileInterface
    {
        $fichier = $requete->getUploadedFiles()['fichier'] ?? null;
        if (!$fichier instanceof UploadedFileInterface) {
            throw new ErreurApi(400, 'le fichier est requis (champ multipart « fichier »)', $etape);
        }
        if ($fichier->getError() !== UPLOAD_ERR_OK) {
            $trop = in_array($fichier->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new ErreurApi($trop ? 413 : 400, 'transfert du fichier incomplet (code ' . $fichier->getError() . ')', $etape);
        }

        return $fichier;
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private function metadonneesDepot(array $corps): array
    {
        if (isset($corps['metadonnees']) && is_string($corps['metadonnees']) && $corps['metadonnees'] !== '') {
            $decode = json_decode($corps['metadonnees'], true);

            return is_array($decode) ? $decode : [];
        }
        $meta = [];
        foreach (self::CHAMPS_META_DEPOT as $champ) {
            if (isset($corps[$champ]) && $corps[$champ] !== '') {
                $meta[$champ] = $corps[$champ];
            }
        }

        return $meta;
    }

    /** @param callable(Catalogue): array<mixed> $lecture */
    private function referentiel(Requete $requete, Reponse $reponse, string $etape, callable $lecture): Reponse
    {
        try {
            $session = $this->authentifier($requete, $etape, false);

            return $this->json($reponse, 200, $lecture($this->catalogue($session)));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'le référentiel n’a pas pu être lu');
        }
    }

    /** @param array{document:int, chemin:string, nom:string, mime:string, empreinte:string, version:int} $fichier */
    private function servirFichier(Reponse $reponse, array $fichier): Reponse
    {
        $contenu = file_get_contents($fichier['chemin']);
        if ($contenu === false) {
            throw new ErreurApi(404, 'le fichier a disparu du fonds', 'GET /api/v1/documents/fichier');
        }
        $reponse->getBody()->write($contenu);

        return $reponse
            ->withHeader('Content-Type', $fichier['mime'] !== '' ? $fichier['mime'] : 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($fichier['nom']) . '"')
            ->withHeader('X-Ged-Empreinte', $fichier['empreinte'])
            ->withHeader('X-Ged-Version', (string) $fichier['version'])
            ->withStatus(200);
    }

    private function echec(Reponse $reponse, string $etape, Throwable $e, string $cause): Reponse
    {
        error_log(sprintf('[GEDv2 documents] %s : %s (%s:%d)', $etape, $e->getMessage(), $e->getFile(), $e->getLine()));

        return $this->erreur($reponse, new ErreurApi(500, $cause, $etape, $e));
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
