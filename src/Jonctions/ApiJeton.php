<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Porte commune des routes `/api/v1` sous JETON (`Authorization: Bearer …`), **hors session** : aucune
 * lecture de cookie, aucun CSRF. Le jeton doit être LIÉ À UN COMPTE et porter la portée
 * `documents_lecture` (ou `documents_ecriture`, ou `*`) ; le compte est rendu au service, qui applique les
 * droits de dossier.
 *
 * Extrait de `DocumentsApi` (lot ged-parite-api-2) pour que le nouveau contrôleur de parité partage
 * EXACTEMENT la même porte : un seul endroit décide d'un refus (401 jeton, 403 portée, 429 débit).
 */
final class ApiJeton
{
    public function __construct(
        private readonly Config $config,
        private readonly Jetons $jetons,
        private readonly DroitsDossiers $droits,
    ) {
    }

    /**
     * Vérifie le jeton et nomme précisément un refus : absent/inconnu/révoqué → 401 ; sans compte ou portée
     * insuffisante → 403 ; débit dépassé → 429. Le jeton n'est jamais journalisé.
     *
     * @return array{compte:int, identifiant:string, application:string, administrateur:bool}
     * @throws ErreurApi
     */
    public function authentifier(Requete $requete, string $etape, bool $ecriture): array
    {
        $valeur = self::jetonEntete($requete);
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
        if (!self::porteeAutorisee($ligne['portee'], $ecriture)) {
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

    /** @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session */
    public function catalogue(array $session): Catalogue
    {
        return new Catalogue($this->config, $session['compte'], $session['administrateur']);
    }

    public static function porteeAutorisee(string $portee, bool $ecriture): bool
    {
        if ($portee === '*') {
            return true;
        }

        return $ecriture
            ? $portee === Jetons::PORTEE_ECRITURE
            : in_array($portee, [Jetons::PORTEE_LECTURE, Jetons::PORTEE_ECRITURE], true);
    }

    public static function jetonEntete(Requete $requete): string
    {
        $entete = trim($requete->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $entete, $trouve) !== 1) {
            return '';
        }

        return $trouve[1];
    }
}
