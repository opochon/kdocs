<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Ancien emplacement « Application › Appareils reliés » (V2-08b). Depuis V2-08c, la liste des appareils
 * vit dans Mon profil : l'écran historique redirige, et la révocation reste servie ici pour ne pas casser
 * les liens existants. Chaque élément actionnable reste dans `templates/ecrans/profil.twig`.
 */
final class EcranJonctions
{
    public function __construct(private readonly Jetons $jetons)
    {
    }

    /** Application › Appareils reliés : déplacé dans Mon profil. */
    public function appareils(Requete $requete, Reponse $reponse): Reponse
    {
        return $reponse->withHeader('Location', '/profil')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function revoquerAppareil(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $session = $this->session($requete);
        $this->jetons->revoquerAppareil((int) ($args['id'] ?? 0), $this->compteId($session), (string) ($session['utilisateur'] ?? ''));

        return $reponse->withHeader('Location', '/profil')->withStatus(303);
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
}
