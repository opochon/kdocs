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
        $racine = dirname($dossierGabarits);
        $this->twig->addFunction(new TwigFunction('rail', static fn (): array => Rail::depuisRacine($racine)->donnees()));
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
