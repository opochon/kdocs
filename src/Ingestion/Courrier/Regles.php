<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Règles de courrier, rangées dans `var/courrier/regles.json` (le dossier fait foi). Chaque écriture valide la
 * règle (paramètre d'action, âge, nom) : une règle invalide n'est jamais persistée. Aucune suppression : une
 * règle retirée est MARQUÉE inactive.
 */
final class Regles
{
    public function __construct(private readonly string $dossier)
    {
    }

    private function fichier(): string
    {
        return rtrim(str_replace('\\', '/', $this->dossier), '/') . '/regles.json';
    }

    /** @return list<Regle> */
    public function tous(): array
    {
        $regles = [];
        foreach ($this->lire() as $ligne) {
            $regles[] = Regle::depuisTableau($ligne);
        }
        usort($regles, static fn (Regle $a, Regle $b): int => [$a->ordre, $a->id] <=> [$b->ordre, $b->id]);

        return $regles;
    }

    /** @return list<Regle> */
    public function parCompte(string $compte): array
    {
        return array_values(array_filter($this->tous(), static fn (Regle $r): bool => $r->compte === $compte));
    }

    public function parId(int $id): ?Regle
    {
        foreach ($this->tous() as $regle) {
            if ($regle->id === $id) {
                return $regle;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $donnees
     * @throws ErreurCourrier
     */
    public function creer(array $donnees): int
    {
        $lignes = $this->lire();
        $id = ($lignes === [] ? 0 : max(array_map(static fn (array $l): int => (int) ($l['id'] ?? 0), $lignes))) + 1;
        $regle = Regle::depuisTableau($this->normaliser($donnees, $id));
        $regle->valider();
        $lignes[] = $regle->pourTableau();
        $this->ecrire($lignes);

        return $id;
    }

    /**
     * @param array<string, mixed> $donnees
     * @throws ErreurCourrier
     */
    public function mettreAJour(int $id, array $donnees): void
    {
        $lignes = $this->lire();
        foreach ($lignes as $index => $ligne) {
            if ((int) ($ligne['id'] ?? 0) !== $id) {
                continue;
            }
            $regle = Regle::depuisTableau($this->normaliser($donnees, $id, $ligne));
            $regle->valider();
            $lignes[$index] = $regle->pourTableau();
            $this->ecrire($lignes);

            return;
        }

        throw new ErreurCourrier("règle $id introuvable");
    }

    public function basculer(int $id, bool $actif): void
    {
        $lignes = $this->lire();
        foreach ($lignes as $index => $ligne) {
            if ((int) ($ligne['id'] ?? 0) === $id) {
                $lignes[$index]['actif'] = $actif;
                $this->ecrire($lignes);

                return;
            }
        }

        throw new ErreurCourrier("règle $id introuvable");
    }

    public function retirer(int $id): void
    {
        $this->basculer($id, false);
    }

    /**
     * @param array<string, mixed> $donnees
     * @param array<string, mixed> $precedent
     * @return array<string, mixed>
     */
    private function normaliser(array $donnees, int $id, array $precedent = []): array
    {
        $etiquettes = [];
        foreach ((array) ($donnees['etiquettes'] ?? $precedent['etiquettes'] ?? []) as $etiquette) {
            if (is_string($etiquette) && trim($etiquette) !== '') {
                $etiquettes[] = trim($etiquette);
            }
        }

        return [
            'id' => $id,
            'nom' => trim((string) ($donnees['nom'] ?? $precedent['nom'] ?? '')),
            'ordre' => (int) ($donnees['ordre'] ?? $precedent['ordre'] ?? 0),
            'compte' => (string) ($donnees['compte'] ?? $precedent['compte'] ?? ''),
            'actif' => (bool) ($donnees['actif'] ?? $precedent['actif'] ?? true),
            'dossier' => (string) ($donnees['dossier'] ?? $precedent['dossier'] ?? 'INBOX'),
            'filtre_de' => (string) ($donnees['filtre_de'] ?? $precedent['filtre_de'] ?? ''),
            'filtre_a' => (string) ($donnees['filtre_a'] ?? $precedent['filtre_a'] ?? ''),
            'filtre_objet' => (string) ($donnees['filtre_objet'] ?? $precedent['filtre_objet'] ?? ''),
            'filtre_corps' => (string) ($donnees['filtre_corps'] ?? $precedent['filtre_corps'] ?? ''),
            'filtre_fichiers_inclure' => (string) ($donnees['filtre_fichiers_inclure'] ?? $precedent['filtre_fichiers_inclure'] ?? ''),
            'filtre_fichiers_exclure' => (string) ($donnees['filtre_fichiers_exclure'] ?? $precedent['filtre_fichiers_exclure'] ?? ''),
            'age_max' => (int) ($donnees['age_max'] ?? $precedent['age_max'] ?? 30),
            'type_piece' => (int) ($donnees['type_piece'] ?? $precedent['type_piece'] ?? Regle::PIECES_JOINTES),
            'portee' => (int) ($donnees['portee'] ?? $precedent['portee'] ?? Regle::PORTEE_JOINTES),
            'mise_en_page_pdf' => (int) ($donnees['mise_en_page_pdf'] ?? $precedent['mise_en_page_pdf'] ?? Regle::PDF_DEFAUT),
            'action' => (int) ($donnees['action'] ?? $precedent['action'] ?? Regle::ACTION_MARQUER_LU),
            'parametre_action' => (string) ($donnees['parametre_action'] ?? $precedent['parametre_action'] ?? ''),
            'titre_depuis' => (int) ($donnees['titre_depuis'] ?? $precedent['titre_depuis'] ?? Regle::TITRE_OBJET),
            'etiquettes' => $etiquettes,
            'type_document' => $donnees['type_document'] ?? $precedent['type_document'] ?? null,
            'emetteur_depuis' => (int) ($donnees['emetteur_depuis'] ?? $precedent['emetteur_depuis'] ?? Regle::EMETTEUR_RIEN),
            'emetteur_choisi' => (string) ($donnees['emetteur_choisi'] ?? $precedent['emetteur_choisi'] ?? ''),
            'proprietaire_depuis_regle' => (bool) ($donnees['proprietaire_depuis_regle'] ?? $precedent['proprietaire_depuis_regle'] ?? true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lire(): array
    {
        $fichier = $this->fichier();
        if (!is_file($fichier)) {
            return [];
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);
        if (!is_array($donnees)) {
            throw new ErreurCourrier('règles : fichier illisible');
        }

        return array_values(array_filter($donnees, 'is_array'));
    }

    /** @param list<array<string, mixed>> $lignes */
    private function ecrire(array $lignes): void
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new ErreurCourrier('règles : dossier impossible ' . $this->dossier);
        }
        $tmp = $this->fichier() . '.partiel';
        $json = json_encode($lignes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->fichier())) {
            throw new ErreurCourrier('règles : écriture impossible');
        }
    }
}
