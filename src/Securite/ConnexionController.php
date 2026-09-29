<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;

final class ConnexionController
{
    public function __construct(
        private readonly Authentification $authentification,
        private readonly RegistreSessions $sessions,
        private readonly ProtectionBruteForce $protection,
        private readonly JournalAudit $audit,
        private readonly Vue $vue,
    ) {
    }

    public function formulaire(Requete $requete, Reponse $reponse): Reponse
    {
        $csrf = $this->sessions->ouvrirJetonConnexion();
        return $this->vue->rendre($reponse, 'connexion/connexion.twig', ['erreur' => null, 'csrf' => $csrf])
            ->withAddedHeader('Set-Cookie', $this->cookieCsrf($csrf, $this->https($requete)));
    }

    public function connecter(Requete $requete, Reponse $reponse): Reponse
    {
        $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
        $identifiant = trim((string) ($corps['identifiant'] ?? ''));
        $csrf = (string) ($corps['csrf'] ?? '');
        $attendu = (string) ($requete->getCookieParams()['gedv2_csrf'] ?? '');
        if ($csrf === '' || $attendu === '' || !hash_equals($attendu, $csrf) || !$this->sessions->consommerJetonConnexion($csrf)) {
            $this->audit->ajouter('refus', null, ['methode' => 'POST', 'chemin' => '/connexion', 'cause' => 'jeton CSRF absent ou invalide']);
            return $this->formulaireErreur(
                $requete,
                $reponse,
                'La connexion a été refusée : le jeton de sécurité est invalide.',
                'jeton CSRF absent ou invalide',
            );
        }
        if ($this->protection->attenteRestante($identifiant) > 0) {
            $this->audit->ajouter('refus', $identifiant !== '' ? $identifiant : null, ['methode' => 'POST', 'chemin' => '/connexion', 'cause' => 'attente anti-force brute']);
            return $this->formulaireErreur(
                $requete,
                $reponse,
                'La connexion est temporairement bloquée après plusieurs essais.',
                'attente anti-force brute',
            );
        }
        $utilisateur = $this->authentification->verifier($identifiant, (string) ($corps['mot_de_passe'] ?? ''));
        if ($utilisateur === null) {
            $attente = $this->protection->echec($identifiant);
            $this->audit->ajouter('echec_connexion', $identifiant !== '' ? $identifiant : null, ['attente_secondes' => $attente]);
            return $this->formulaireErreur(
                $requete,
                $reponse,
                'Identifiant ou mot de passe incorrect.',
                'identifiant ou mot de passe incorrect',
            );
        }

        $this->protection->succes($identifiant);
        // Un nouvel identifiant cryptographiquement aléatoire neutralise toute fixation de session.
        $session = $this->sessions->ouvrir($utilisateur['identifiant'], $utilisateur['permissions'], null, $utilisateur['id']);
        $this->audit->ajouter('connexion', $utilisateur['identifiant']);

        return $reponse->withHeader('Location', '/a-traiter')->withStatus(303)
            ->withAddedHeader('Set-Cookie', $this->sessions->enteteCookie($session['id'], $this->https($requete)));
    }

    public function deconnecter(Requete $requete, Reponse $reponse): Reponse
    {
        $id = (string) ($requete->getCookieParams()['gedv2_session'] ?? '');
        $session = $requete->getAttribute('ged.session');
        $this->sessions->fermer($id);
        $this->audit->ajouter('deconnexion', is_array($session) ? ($session['utilisateur'] ?? null) : null);

        return $reponse->withHeader('Location', '/connexion')->withStatus(303)
            ->withAddedHeader('Set-Cookie', 'gedv2_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0');
    }

    private function formulaireErreur(Requete $requete, Reponse $reponse, string $erreur, string $cause): Reponse
    {
        $csrf = $this->sessions->ouvrirJetonConnexion();
        return $this->vue->rendre($reponse, 'connexion/connexion.twig', [
            'erreur' => $erreur,
            'etape' => 'connexion',
            'cause' => $cause,
            'csrf' => $csrf,
        ], 403)
            ->withAddedHeader('Set-Cookie', $this->cookieCsrf($csrf, $this->https($requete)));
    }

    private function cookieCsrf(string $jeton, bool $securise): string
    {
        return sprintf('gedv2_csrf=%s; Path=/; HttpOnly; SameSite=Lax%s', $jeton, $securise ? '; Secure' : '');
    }

    private function https(Requete $requete): bool
    {
        return $requete->getUri()->getScheme() === 'https' || strtolower($requete->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
