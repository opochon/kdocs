<?php

declare(strict_types=1);

namespace Ged\Ui;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
