<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * Scan du dépôt : empreinte sha256 de TOUS les fichiers, à un instant donné. Un scan ne modifie
 * rien ; c'est la Veille qui compare, versionne et garantit.
 *
 * Portable Windows/Linux : le parcours normalise les séparateurs, l'empreinte est identique.
 */
final class Scanner
{
    public function __construct(private readonly string $racine)
    {
    }

    public function racine(): string
    {
        return rtrim(str_replace('\\', '/', $this->racine), '/');
    }

    /**
     * @return array<string, array{absolu:string, taille:int, empreinte:string}>
     */
    public function scan(): array
    {
        $fichiers = Arborescence::parcourir($this->racine());
        foreach ($fichiers as $relatif => $info) {
            $fichiers[$relatif]['empreinte'] = Arborescence::empreinte($info['absolu']);
        }

        return $fichiers;
    }
}
