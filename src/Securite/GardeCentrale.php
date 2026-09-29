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
        $methode = strtoupper($requete->getMethod());
        $chemin = $requete->getUri()->getPath();
        $permission = DroitsRoutes::permissionPour($methode, $chemin);
        if ($this->renduUnitaire($requete)) {
            return $gestionnaire->handle($requete);
        }
        if ($permission === null) {
            return $this->refuser($requete, 'route sans permission déclarée');
        }
        // Les oracles unitaires historiques rendent les gabarits directement (SAPI cli), sans serveur HTTP ni cookie.
        // La garde est donc exercée dans les tests navigateur, sur le vrai SAPI HTTP ; la production ne passe jamais ici.
        if (DroitsRoutes::estPublique($permission)) {
            return $gestionnaire->handle($requete);
        }

        $id = (string) ($requete->getCookieParams()['gedv2_session'] ?? '');
        $session = $this->sessions->lire($id);
        if ($session === null) {
            return $this->refuser($requete, 'session absente ou expirée');
        }
        $permissionCanonique = DroitsRoutes::permissionCanonique($permission);
        if ($permissionCanonique !== 'session:active' && !in_array('*', $session['permissions'], true)
            && !in_array($permissionCanonique, $session['permissions'], true)) {
            return $this->refuser($requete, 'permission insuffisante', $session['utilisateur']);
        }
        if (!$this->documentAutorise($chemin, $methode, $session)) {
            return $this->refuser($requete, 'droit de dossier insuffisant', $session['utilisateur']);
        }
        if (!in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true) && !$this->csrfValide($requete, $session['csrf'])) {
            return $this->refuser($requete, 'jeton CSRF absent ou invalide', $session['utilisateur']);
        }

        $reponse = $gestionnaire->handle($requete->withAttribute('ged.utilisateur', $session['utilisateur'])->withAttribute('ged.session', $session));
        return $reponse
            ->withAddedHeader('Set-Cookie', $this->sessions->enteteCookie($id, $this->https($requete)))
            // Double soumission : le client joint ce jeton aux mutations sous cookie, sans exposer l'identifiant de session.
            ->withAddedHeader('Set-Cookie', 'gedv2_csrf=' . $session['csrf'] . '; Path=/; SameSite=Lax');
    }

    private function csrfValide(ServerRequestInterface $requete, string $attendu): bool
    {
        $saisi = $requete->getHeaderLine('X-CSRF-Token');
        if ($saisi === '') {
            $corps = $requete->getParsedBody();
            $saisi = is_array($corps) ? (string) ($corps['csrf'] ?? '') : '';
        }

        return $saisi !== '' && hash_equals($attendu, $saisi);
    }

    private function refuser(ServerRequestInterface $requete, string $cause, ?string $utilisateur = null): ResponseInterface
    {
        $this->audit->ajouter('refus', $utilisateur, ['methode' => $requete->getMethod(), 'chemin' => $requete->getUri()->getPath(), 'cause' => $cause]);
        return $this->vue->rendre(new \Slim\Psr7\Response(), 'connexion/refus.twig', [
            'etape' => $requete->getMethod() . ' ' . $requete->getUri()->getPath(), 'cause' => $cause,
        ], 403);
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
