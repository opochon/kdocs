<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Ui\Maquette;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

/**
 * Mon profil (section 1 v2 du contrat CMM) : adresse mail VÉRIFIÉE par un lien, bouton « Relier
 * ClearMyMails », appairages en attente (code de vérification affiché) et appareils reliés avec Révoquer.
 * Chaque élément actionnable porte son `data-testid`.
 */
final class EcranProfil
{
    public function __construct(
        private readonly Vue $vue,
        private readonly Profil $profil,
        private readonly Appairage $appairage,
        private readonly Jetons $jetons,
    ) {
    }

    public function afficher(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->rendre($requete, $reponse, $this->donnees($requete));
    }

    public function definirMail(Requete $requete, Reponse $reponse): Reponse
    {
        $session = $this->session($requete);
        $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
        $adresse = trim((string) ($corps['adresse'] ?? ''));

        try {
            $this->profil->definirAdresse($this->compteId($session), (string) ($session['utilisateur'] ?? ''), $adresse);
        } catch (\InvalidArgumentException $e) {
            return $this->rendre($requete, $reponse, $this->donnees($requete, $e->getMessage()), 400);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 jonctions] POST /profil/mail : %s (%s:%d)', $e->getMessage(), $e->getFile(), $e->getLine()));

            return $this->rendre($requete, $reponse, $this->donnees($requete, 'le courriel de vérification n’a pas pu être envoyé'), 502);
        }

        return $reponse->withHeader('Location', '/profil?etat=mail-envoye')->withStatus(303);
    }

    public function verifierMail(Requete $requete, Reponse $reponse): Reponse
    {
        $jeton = (string) ($requete->getQueryParams()['jeton'] ?? '');
        try {
            $this->profil->verifier($jeton);
        } catch (ErreurApi $e) {
            return $this->rendre($requete, $reponse, $this->donnees($requete, $e->cause), $e->statut);
        }

        return $reponse->withHeader('Location', '/profil?etat=mail-verifie')->withStatus(303);
    }

    public function relier(Requete $requete, Reponse $reponse): Reponse
    {
        $session = $this->session($requete);
        try {
            $this->appairage->ouvrir($this->compteId($session), (string) ($session['utilisateur'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return $this->rendre($requete, $reponse, $this->donnees($requete, $e->getMessage()), 400);
        } catch (ErreurApi $e) {
            return $this->rendre($requete, $reponse, $this->donnees($requete, $e->cause), $e->statut);
        }

        return $reponse->withHeader('Location', '/profil?etat=appairage-ouvert')->withStatus(303);
    }

    /** @return array<string, mixed> */
    private function donnees(Requete $requete, ?string $erreur = null): array
    {
        $session = $this->session($requete);
        $compteId = $this->compteId($session);
        $identifiant = (string) ($session['utilisateur'] ?? '');
        $etats = [
            'mail-envoye' => 'Un courriel de vérification a été envoyé à cette adresse : ouvrez le lien qu’il contient.',
            'mail-verifie' => 'Adresse vérifiée : vous pouvez relier ClearMyMails.',
            'appairage-ouvert' => 'ClearMyMails est prévenu : comparez le code de vérification affiché ci-dessous avec celui du courriel reçu.',
        ];
        $etat = (string) ($requete->getQueryParams()['etat'] ?? '');

        return $this->commun('profil') + [
            'compte' => $identifiant,
            'profil' => $this->profil->etat($compteId, $identifiant),
            'en_attente' => $this->appairage->enAttente($compteId, $identifiant),
            'appareils' => $this->jetons->listerAppareils($compteId, $identifiant),
            'message' => $etats[$etat] ?? null,
            'erreur' => $erreur,
            'csrf' => $this->csrf($requete),
        ];
    }

    /** @param array<string, mixed> $donnees */
    private function rendre(Requete $requete, Reponse $reponse, array $donnees, int $statut = 200): Reponse
    {
        return $this->vue->rendre($reponse, 'ecrans/profil.twig', $donnees, $statut);
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
