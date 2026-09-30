<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Porte HTTP de l'API d'entrée. Authentification par jeton (`Authorization: Bearer …`, portée `depot`) :
 * elle ne dépend pas de la session et ne passe donc pas par la garde CSRF. Le jeton est vérifié ici, dans
 * le secteur. Les réponses sont du JSON : l'identifiant du travail à la réception, son état au suivi.
 *
 *   POST /api/v1/depots          fichier (champ « fichier ») + métadonnées ; destination=ingestion|dossier
 *   GET  /api/v1/depots/{id}     état du travail (404 nommé si inconnu)
 *   GET  /api/v1/dossiers        dossiers où CE compte peut écrire (destination=dossier)
 */
final class DepotApi
{
    public const TAILLE_MAX_DEFAUT = 26214400;   // 25 Mio

    /** Champs de métadonnées acceptés en formulaire direct (sinon champ « metadonnees » au format JSON). */
    private const CHAMPS_META = ['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes'];

    public function __construct(
        private readonly Jetons $jetons,
        private readonly SourceApi $source,
        private readonly PDO $pdo,
        private readonly Dossiers $dossiers,
        private readonly DepotDossier $depotDossier,
        private readonly int $tailleMax = self::TAILLE_MAX_DEFAUT,
    ) {
    }

    public function deposer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/depots';
        try {
            $session = $this->authentifier($requete, $etape);
            $fichier = $this->fichier($requete, $etape);
            $nom = $fichier->getClientFilename() ?? 'depot';
            if (SourceApi::extensionAutorisee($nom) === null) {
                throw new ErreurApi(415, sprintf('type refusé : « %s » (types acceptés : %s)', $nom, implode(', ', SourceApi::EXTENSIONS)), $etape);
            }
            $taille = $fichier->getSize();
            if ($taille !== null && $taille > $this->tailleMax) {
                throw new ErreurApi(413, sprintf('fichier trop gros : %d octets (maximum %d)', $taille, $this->tailleMax), $etape);
            }

            $corps = $this->champs($requete);
            $metadonnees = $this->metadonnees($corps);
            $origine = $this->origine($corps, $etape);
            if ($origine !== null) {
                $metadonnees['origine'] = $origine;
            }
            $destination = trim((string) ($corps['destination'] ?? ''));
            $destination = $destination === '' ? 'ingestion' : $destination;

            if ($destination === 'dossier') {
                return $this->deposerDansDossier($reponse, $session, $fichier, $nom, $metadonnees, $corps, $etape);
            }
            if ($destination !== 'ingestion') {
                throw new ErreurApi(400, sprintf('destination inconnue : « %s » (ingestion | dossier)', $destination), $etape);
            }

            $resultat = $this->source->recevoir($fichier, $nom, $metadonnees, $session['application']);
            $id = $resultat['travail'];

            return $this->json($reponse, 202, [
                'travail' => $id,
                'empreinte' => $resultat['empreinte'],
                'etat' => $this->travail($id)['etat'] ?? 'recu',
                'suivi' => '/api/v1/depots/' . $id,
            ]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] POST /api/v1/depots : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, 'le dépôt n’a pas pu être enregistré', $etape));
        }
    }

    /** @param array<string, mixed> $args */
    public function etat(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = 'GET /api/v1/depots/' . $id;
        try {
            $this->authentifier($requete, $etape);
            $travail = $this->travail($id);
            if ($travail === null) {
                throw new ErreurApi(404, "travail introuvable : $id", $etape);
            }
            $accompagnement = $this->source->lireAccompagnement((string) $travail['chemin']);

            return $this->json($reponse, 200, [
                'travail' => $id,
                'source' => (string) $travail['source'],
                'nom_origine' => (string) $travail['nom_origine'],
                'etat' => (string) $travail['etat'],
                'essais' => (int) $travail['essais'],
                'erreur' => $travail['erreur'] === null ? null : (string) $travail['erreur'],
                'document_id' => $travail['document_id'] === null ? null : (int) $travail['document_id'],
                'cree_le' => (string) $travail['cree_le'],
                'maj_le' => (string) $travail['maj_le'],
                'application' => $accompagnement['application'],
                'metadonnees' => $accompagnement['metadonnees'],
                'suivi' => '/api/v1/depots/' . $id,
            ]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] GET /api/v1/depots/%d : %s (%s:%d)', $id, $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, "l’état du travail $id n’a pas pu être lu", $etape));
        }
    }

    /** Dossiers où le compte du jeton peut déposer (refus par défaut : un dossier sans droit n'apparaît pas). */
    public function dossiers(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/dossiers';
        try {
            $session = $this->authentifier($requete, $etape);
            if ($session['compte_id'] === null) {
                throw new ErreurApi(403, 'le jeton n’est lié à aucun compte', $etape);
            }
            $parent = (string) ($requete->getQueryParams()['parent'] ?? '');

            return $this->json($reponse, 200, $this->dossiers->lister($session['compte_id'], $parent));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] GET /api/v1/dossiers : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, 'la liste des dossiers n’a pas pu être lue', $etape));
        }
    }

    /**
     * @param array{application:string, portee:string, compte_id:?int} $session
     * @param array<string, mixed> $metadonnees
     * @param array<string, mixed> $corps
     */
    private function deposerDansDossier(Reponse $reponse, array $session, UploadedFileInterface $fichier, string $nom, array $metadonnees, array $corps, string $etape): Reponse
    {
        $dossier = $this->normaliserDossier((string) ($corps['dossier'] ?? ''));
        if ($dossier === '') {
            throw new ErreurApi(400, 'le dossier est requis (destination=dossier)', $etape);
        }
        if ($session['compte_id'] === null) {
            throw new ErreurApi(403, 'le jeton n’est lié à aucun compte', $etape);
        }
        if (!$this->dossiers->ecritureAutorisee($session['compte_id'], $dossier)) {
            throw new ErreurApi(403, sprintf('droit d’écriture insuffisant sur le dossier « %s »', $dossier), $etape);
        }

        $resultat = $this->depotDossier->deposer($fichier, $nom, $metadonnees, $dossier, $etape);

        return $this->json($reponse, 202, [
            'document' => $resultat['document'],
            'empreinte' => $resultat['empreinte'],
            'etat' => 'a_controler',
        ]);
    }

    /**
     * Vérifie le jeton et nomme précisément un refus. Le jeton n'est jamais journalisé.
     *
     * @return array{id:int, application:string, portee:string, compte_id:?int, compte_identifiant:?string, appareil:?string}
     * @throws ErreurApi
     */
    private function authentifier(Requete $requete, string $etape): array
    {
        $jeton = $this->jetonEntete($requete);
        if ($jeton === '') {
            throw new ErreurApi(401, 'jeton absent (en-tête Authorization: Bearer attendu)', $etape);
        }
        $ligne = $this->jetons->resoudre($jeton);
        if ($ligne === null) {
            throw new ErreurApi(401, 'jeton inconnu', $etape);
        }
        if ($ligne['revoque']) {
            throw new ErreurApi(401, 'jeton révoqué', $etape);
        }
        if ($ligne['portee'] !== Jetons::PORTEE_DEPOT && $ligne['portee'] !== '*') {
            throw new ErreurApi(403, 'portée insuffisante : « depot » requise', $etape);
        }
        $this->jetons->marquerUtilisation($ligne['id']);

        return [
            'id' => $ligne['id'], 'application' => $ligne['application'], 'portee' => $ligne['portee'],
            'compte_id' => $ligne['compte_id'], 'compte_identifiant' => $ligne['compte_identifiant'], 'appareil' => $ligne['appareil'],
        ];
    }

    private function jetonEntete(Requete $requete): string
    {
        $entete = trim($requete->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $entete, $trouve) !== 1) {
            return '';
        }

        return $trouve[1];
    }

    /** @throws ErreurApi */
    private function fichier(Requete $requete, string $etape): UploadedFileInterface
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

    /** @return array<string, mixed> corps de la requête (formulaire ou multipart) */
    private function champs(Requete $requete): array
    {
        $corps = $requete->getParsedBody();

        return is_array($corps) ? $corps : [];
    }

    /**
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private function metadonnees(array $corps): array
    {
        if (isset($corps['metadonnees']) && is_string($corps['metadonnees']) && $corps['metadonnees'] !== '') {
            $decode = json_decode($corps['metadonnees'], true);

            return is_array($decode) ? $decode : [];
        }
        $meta = [];
        foreach (self::CHAMPS_META as $champ) {
            if (isset($corps[$champ]) && $corps[$champ] !== '') {
                $meta[$champ] = $corps[$champ];
            }
        }

        return $meta;
    }

    /** @param array<string, mixed> $corps @return array<string, mixed>|null @throws ErreurApi */
    private function origine(array $corps, string $etape): ?array
    {
        $brut = trim((string) ($corps['origine'] ?? ''));
        if ($brut === '') {
            return null;
        }
        $decode = json_decode($brut, true);
        if (!is_array($decode)) {
            throw new ErreurApi(400, 'origine : JSON invalide (objet attendu)', $etape);
        }

        return $decode;
    }

    private function normaliserDossier(string $dossier): string
    {
        return trim(str_replace('\\', '/', trim($dossier)), '/');
    }

    /** @return array<string, mixed>|null travail d'ingestion créé par l'API uniquement */
    private function travail(int $id): ?array
    {
        $requete = $this->pdo->prepare("SELECT id, source, nom_origine, chemin, etat, essais, erreur, document_id, cree_le, maj_le
            FROM ingestion_travaux WHERE id = ? AND source = 'api' LIMIT 1");
        $requete->execute([$id]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $ligne;
    }

    /** @param array<string, mixed> $donnees */
    private function json(Reponse $reponse, int $code, array $donnees): Reponse
    {
        $reponse->getBody()->write(json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
