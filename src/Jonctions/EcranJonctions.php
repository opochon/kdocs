<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;
use Ged\Ui\Maquette;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Écrans du secteur Jonctions : la demande d'appairage (session normale) et « Application › Appareils
 * reliés ». Chaque élément actionnable porte son `data-testid`.
 */
final class EcranJonctions
{
    public function __construct(
        private readonly Vue $vue,
        private readonly Appairage $appairage,
        private readonly Jetons $jetons,
        private readonly Config $config,
    ) {
    }

    public function appairage(Requete $requete, Reponse $reponse): Reponse
    {
        $parametres = $requete->getQueryParams();
        $application = trim((string) ($parametres['application'] ?? ''));
        $appareil = trim((string) ($parametres['nom'] ?? ''));
        $retour = trim((string) ($parametres['retour'] ?? ''));
        $etat = (string) ($parametres['etat'] ?? '');

        $erreur = null;
        if ($application === '') {
            $erreur = 'demande incomplète : application manquante';
        } elseif (!Appairage::estRetourValide($retour)) {
            $erreur = 'demande incomplète : adresse de retour invalide (URL absolue http(s) attendue)';
        }

        return $this->vue->rendre($reponse, 'ecrans/appairage.twig', $this->commun('application') + [
            'application' => $application, 'appareil' => $appareil, 'retour' => $retour, 'etat' => $etat,
            'csrf' => $this->csrf($requete), 'erreur' => $erreur,
        ]);
    }

    public function accepter(Requete $requete, Reponse $reponse): Reponse
    {
        $session = $this->session($requete);
        $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
        $application = trim((string) ($corps['application'] ?? ''));
        $appareil = trim((string) ($corps['nom'] ?? ''));
        $retour = trim((string) ($corps['retour'] ?? ''));
        $etat = (string) ($corps['etat'] ?? '');

        try {
            $url = $this->appairage->ouvrir($application, $appareil, $retour, $etat, $this->compteId($session), (string) ($session['utilisateur'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $this->vue->rendre($reponse, 'ecrans/appairage.twig', $this->commun('application') + [
                'application' => $application, 'appareil' => $appareil, 'retour' => $retour, 'etat' => $etat,
                'csrf' => $this->csrf($requete), 'erreur' => $e->getMessage(),
            ], 400);
        }

        return $reponse->withHeader('Location', $url)->withStatus(303);
    }

    public function appareils(Requete $requete, Reponse $reponse): Reponse
    {
        $session = $this->session($requete);

        return $this->vue->rendre($reponse, 'ecrans/appareils.twig', $this->commun('application') + [
            'appareils' => $this->jetons->listerAppareils($this->compteId($session), (string) ($session['utilisateur'] ?? '')),
            'compte' => (string) ($session['utilisateur'] ?? ''),
            'csrf' => $this->csrf($requete),
        ]);
    }

    /** @param array<string, string> $args */
    public function revoquerAppareil(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $session = $this->session($requete);
        $this->jetons->revoquerAppareil((int) ($args['id'] ?? 0), $this->compteId($session), (string) ($session['utilisateur'] ?? ''));

        return $reponse->withHeader('Location', '/application/appareils')->withStatus(303);
    }

    /** @return array<string, mixed> */
    private function session(Requete $requete): array
    {
        $session = $requete->getAttribute('ged.session');

        return is_array($session) ? $session : ['utilisateur' => '', 'compte' => null];
    }

    /** @param array<string, mixed> $session */
    private function compteId(array $session): ?int
    {
        $compte = $session['compte'] ?? null;

        return is_int($compte) && $compte > 0 ? $compte : null;
    }

    private function csrf(Requete $requete): string
    {
        return (string) ($requete->getCookieParams()['gedv2_csrf'] ?? '');
    }

    /** @return array<string, mixed> */
    private function commun(string $ecran): array
    {
        return ['ecran' => $ecran, 'bilan' => Maquette::bilan(), 'types' => Maquette::TYPES];
    }
}
