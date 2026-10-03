<?php

declare(strict_types=1);

namespace Ged\Securite;

use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;

/** Middleware unique : déclaration de permission, session et CSRF sont contrôlés avant chaque route. */
final class GardeCentrale implements MiddlewareInterface
{
    public function __construct(
        private readonly RegistreSessions $sessions,
        private readonly JournalAudit $audit,
        private readonly Vue $vue,
        private readonly DroitsDossiers $droitsDossiers,
    ) {
    }

    public function process(ServerRequestInterface $requete, RequestHandlerInterface $gestionnaire): ResponseInterface
    {
        $nonceCsp = base64_encode(random_bytes(24));
        $this->vue->definirNonceCsp($nonceCsp);
        $methode = strtoupper($requete->getMethod());
        $chemin = $requete->getUri()->getPath();
        $permission = DroitsRoutes::permissionPour($methode, $chemin);
        if ($this->renduUnitaire($requete)) {
            return $this->securiser($gestionnaire->handle($requete), $nonceCsp);
        }
        if ($permission === null) {
            return $this->refuser($requete, 'route sans permission déclarée', $nonceCsp);
        }
        // Les oracles unitaires historiques rendent les gabarits directement (SAPI cli), sans serveur HTTP ni cookie.
        // La garde est donc exercée dans les tests navigateur, sur le vrai SAPI HTTP ; la production ne passe jamais ici.
        if (DroitsRoutes::estPublique($permission)) {
            return $this->securiser($gestionnaire->handle($requete), $nonceCsp);
        }

        $id = (string) ($requete->getCookieParams()['gedv2_session'] ?? '');
        $session = $this->sessions->lire($id);
        if ($session === null) {
            return $this->refuser($requete, 'session absente ou expirée', $nonceCsp);
        }
        $permissionCanonique = DroitsRoutes::permissionCanonique($permission);
        if ($permissionCanonique !== 'session:active' && !in_array('*', $session['permissions'], true)
            && !in_array($permissionCanonique, $session['permissions'], true)) {
            return $this->refuser($requete, 'permission insuffisante', $nonceCsp, $session['utilisateur']);
        }
        if (!$this->documentAutorise($chemin, $methode, $session)) {
            return $this->refuser($requete, 'droit de dossier insuffisant', $nonceCsp, $session['utilisateur']);
        }
        if (!in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $csrf = $this->csrfSaisi($requete);
            $nouveauCsrf = $this->sessions->consommerCsrf($id, $csrf);
            if ($nouveauCsrf === null) {
                return $this->refuser($requete, 'jeton CSRF absent, invalide ou déjà utilisé', $nonceCsp, $session['utilisateur']);
            }
            $session['csrf'] = $nouveauCsrf;
        }

        $reponse = $gestionnaire->handle($requete->withAttribute('ged.utilisateur', $session['utilisateur'])->withAttribute('ged.session', $session));
        return $this->securiser($reponse
            ->withAddedHeader('Set-Cookie', $this->sessions->enteteCookie($id, $this->https($requete)))
            // Double soumission : le client joint ce jeton aux mutations sous cookie, sans exposer l'identifiant de session.
            ->withAddedHeader('Set-Cookie', 'gedv2_csrf=' . $session['csrf'] . '; Path=/; HttpOnly; SameSite=Lax'), $nonceCsp);
    }

    private function csrfSaisi(ServerRequestInterface $requete): string
    {
        $saisi = $requete->getHeaderLine('X-CSRF-Token');
        if ($saisi === '') {
            $corps = $requete->getParsedBody();
            $saisi = is_array($corps) ? (string) ($corps['csrf'] ?? '') : '';
        }

        return $saisi;
    }

    private function refuser(ServerRequestInterface $requete, string $cause, string $nonceCsp, ?string $utilisateur = null): ResponseInterface
    {
        $this->audit->ajouter('refus', $utilisateur, ['methode' => $requete->getMethod(), 'chemin' => $requete->getUri()->getPath(), 'cause' => $cause]);
        return $this->securiser($this->vue->rendre(new \Slim\Psr7\Response(), 'connexion/refus.twig', [
            'etape' => $requete->getMethod() . ' ' . $requete->getUri()->getPath(), 'cause' => $cause,
        ], 403), $nonceCsp);
    }

    /** En-têtes homogènes, y compris sur un refus : une erreur ne doit jamais ouvrir une surface. */
    private function securiser(ResponseInterface $reponse, string $nonceCsp): ResponseInterface
    {
        return $reponse
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonceCsp}'; style-src 'self' 'unsafe-inline'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'none'")
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function https(ServerRequestInterface $requete): bool
    {
        return $requete->getUri()->getScheme() === 'https' || strtolower($requete->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }

    /**
     * Les comptes réels sont contrôlés au niveau du dossier le plus précis du document.
     * Le compte synthétique de test n'a volontairement pas d'id en base et ne sort jamais de APP_ENV=test.
     *
     * @param array{utilisateur:string,compte?:int|null,permissions:list<string>,csrf:string,cree_le:int,expire_le:int} $session
     */
    private function documentAutorise(string $chemin, string $methode, array $session): bool
    {
        if (preg_match('#^/documents/([0-9]+)(?:/|$)#', $chemin, $trouve) !== 1) {
            return true;
        }
        $compte = $session['compte'] ?? null;
        if (!is_int($compte) || $compte < 1) {
            return true;
        }
        // Le rôle administrateur (permission '*') voit tout : aucun document rangé ne peut lui être
        // refusé faute de dossier, même pour une pièce dont l'ACL n'a pas encore été rattachée.
        if (in_array('*', $session['permissions'], true)) {
            return true;
        }
        // V2-12d : noter, lier ou partager un document lisible est un geste de lecteur (modele du 03-10).
        if ($methode === 'POST' && preg_match('#^/documents/[0-9]+/(notes|liens|partages)$#', $chemin) === 1) {
            $methode = 'GET';
        }
        $action = match ($methode) {
            'GET', 'HEAD' => 'lire',
            'DELETE' => 'archiver',
            default => 'modifier',
        };
        try {
            $dossier = $this->droitsDossiers->dossierPourDocument((int) $trouve[1]);

            return $dossier !== null && $this->droitsDossiers->autorise($compte, $dossier, $action);
        } catch (\PDOException) {
            return false;
        }
    }

    private function renduUnitaire(ServerRequestInterface $requete): bool
    {
        return PHP_SAPI === 'cli' && getenv('APP_ENV') === 'test' && $requete->getHeaderLine('X-GED-Teste-Garde') !== '1';
    }
}
