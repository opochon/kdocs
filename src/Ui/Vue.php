<?php

declare(strict_types=1);

namespace Ged\Ui;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Rendu des écrans. Twig échappe tout par défaut : aucune donnée n'entre dans le HTML sans échappement.
 */
final class Vue
{
    private Environment $twig;
    /** Compte du rendu courant, pour le badge « À traiter » du rail ; posé par la garde avant chaque rendu. */
    private ?int $compteRail = null;
    private bool $administrateurRail = false;
    /** @var list<string> Permissions du rendu courant : le rail n'affiche que les sections autorisées. */
    private array $permissionsRail = [];

    public function __construct(string $dossierGabarits, ?string $cache = null)
    {
        $this->twig = new Environment(new FilesystemLoader($dossierGabarits), [
            'autoescape' => 'html',
            'strict_variables' => true,   // une variable absente est une erreur, pas un trou silencieux
            'cache' => $cache ?? false,
        ]);
        // La garde remplace cette valeur avant chaque rendu HTTP protégé.
        $this->twig->addGlobal('csp_nonce', '');

        // Rail de navigation : le compteur « À traiter » et les vues enregistrées sont calculés à UN endroit
        // (Ged\Ui\Rail), pour TOUS les gabarits qui étendent layout.twig, sans dépendre du secteur appelant.
        // Le compte du lecteur est posé par la garde (`definirContexteRail`) : le badge ne compte jamais un
        // document hors de ses dossiers (F10).
        $racine = dirname($dossierGabarits);
        $this->twig->addFunction(new TwigFunction('rail', function () use ($racine): array {
            return Rail::depuisRacine($racine, $this->compteRail, $this->administrateurRail, $this->permissionsRail)->donnees();
        }));
    }

    /**
     * Le compte et les permissions du rendu courant : le rail (menu) filtre ses compteurs comme les
     * listes, et ne montre la section Administration qu'aux rôles qui y ont droit.
     *
     * @param array<array-key, string> $permissions
     */
    public function definirContexteRail(?int $compte, bool $administrateur, array $permissions = []): void
    {
        $this->compteRail = $compte !== null && $compte > 0 ? $compte : null;
        $this->administrateurRail = $administrateur;
        $this->permissionsRail = array_values(array_map('strval', $permissions));
    }

    /** Expose le nonce CSP courant à tous les gabarits, y compris ceux qui étendent layout.twig. */
    public function definirNonceCsp(string $nonce): void
    {
        $this->twig->addGlobal('csp_nonce', $nonce);
    }

    /** @param array<string, mixed> $donnees */
    public function rendre(ResponseInterface $reponse, string $gabarit, array $donnees = [], int $statut = 200): ResponseInterface
    {
        $reponse->getBody()->write($this->twig->render($gabarit, $donnees));

        return $reponse->withStatus($statut)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string, mixed> $donnees */
    public function html(string $gabarit, array $donnees = []): string
    {
        return $this->twig->render($gabarit, $donnees);
    }
}
