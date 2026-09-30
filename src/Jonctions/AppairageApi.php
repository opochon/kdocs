<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Porte CMM de l'appairage (section 1 du contrat) : échange du code, statut de la liaison et révocation.
 * Authentification par le jeton remis à l'appairage (`Authorization: Bearer …`), hors session.
 *
 *   POST   /api/v1/appairage/jeton   {code} → {jeton, compte, ged_nom, expire_le}
 *   GET    /api/v1/appairage/moi     statut de la liaison
 *   DELETE /api/v1/appairage/moi     révocation côté CMM
 */
final class AppairageApi
{
    public function __construct(private readonly Appairage $appairage, private readonly Jetons $jetons)
    {
    }

    public function echanger(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/appairage/jeton';
        try {
            $corps = $this->champs($requete);
            $code = trim((string) ($corps['code'] ?? ''));
            if ($code === '') {
                throw new ErreurApi(400, 'code d’appairage requis', $etape);
            }

            return $this->json($reponse, 200, $this->appairage->echanger($code));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] POST /api/v1/appairage/jeton : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, 'l’échange du code n’a pas pu être traité', $etape));
        }
    }

    public function moi(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'GET /api/v1/appairage/moi';
        try {
            $ligne = $this->authentifierAppareil($requete, $etape);

            return $this->json($reponse, 200, $this->appairage->moi($ligne));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] GET /api/v1/appairage/moi : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, 'l’état de la liaison n’a pas pu être lu', $etape));
        }
    }

    public function revoquer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'DELETE /api/v1/appairage/moi';
        try {
            $ligne = $this->authentifierAppareil($requete, $etape);
            $this->jetons->revoquer($ligne['id']);

            return $this->json($reponse, 200, ['revoque' => true, 'compte' => $ligne['compte_identifiant']]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] DELETE /api/v1/appairage/moi : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->erreur($reponse, new ErreurApi(500, 'la révocation n’a pas pu être enregistrée', $etape));
        }
    }

    /**
     * Jeton d'appairage valide et réellement lié à un compte (un jeton d'application en est dépourvu).
     *
     * @return array<string, mixed>
     * @throws ErreurApi
     */
    private function authentifierAppareil(Requete $requete, string $etape): array
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
        $this->jetons->marquerUtilisation($ligne['id']);

        return $ligne;
    }

    /** Corps de la requête : formulaire, multipart, ou JSON brut (CMM peut envoyer `{code}`). @return array<string, mixed> */
    private function champs(Requete $requete): array
    {
        $corps = $requete->getParsedBody();
        if (is_array($corps) && $corps !== []) {
            return $corps;
        }
        if (str_contains(strtolower($requete->getHeaderLine('Content-Type')), 'application/json')) {
            $decode = json_decode((string) $requete->getBody(), true);
            if (is_array($decode)) {
                return $decode;
            }
        }

        return is_array($corps) ? $corps : [];
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
