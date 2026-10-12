<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Comptes de courrier, rangés dans le dossier de configuration (`var/courrier/comptes.json` — le dossier fait
 * foi). Le mot de passe est CHIFFRÉ par le coffre avant écriture : le fichier ne contient jamais le clair.
 * Aucune suppression : un compte retiré est MARQUÉ inactif.
 */
final class Comptes
{
    public function __construct(private readonly Coffre $coffre, private readonly string $dossier)
    {
    }

    private function fichier(): string
    {
        return rtrim(str_replace('\\', '/', $this->dossier), '/') . '/comptes.json';
    }

    /** @return list<Compte> */
    public function tous(): array
    {
        $comptes = [];
        foreach ($this->lire() as $ligne) {
            $comptes[] = Compte::depuisTableau($ligne);
        }
        usort($comptes, static fn (Compte $a, Compte $b): int => $a->id <=> $b->id);

        return $comptes;
    }

    public function parId(int $id): ?Compte
    {
        foreach ($this->tous() as $compte) {
            if ($compte->id === $id) {
                return $compte;
            }
        }

        return null;
    }

    /**
     * Crée un compte. `mot_de_passe` (clair) est obligatoire à la création et n'est jamais écrit tel quel.
     *
     * @param array<string, mixed> $donnees
     * @throws ErreurCourrier
     */
    public function creer(array $donnees): int
    {
        $nom = trim((string) ($donnees['nom'] ?? ''));
        if ($nom === '') {
            throw new ErreurCourrier('le nom du compte est requis');
        }
        $lignes = $this->lire();
        foreach ($lignes as $ligne) {
            if (strcasecmp((string) ($ligne['nom'] ?? ''), $nom) === 0) {
                throw new ErreurCourrier("un compte nommé « $nom » existe déjà");
            }
        }
        $id = ($lignes === [] ? 0 : max(array_map(static fn (array $l): int => (int) ($l['id'] ?? 0), $lignes))) + 1;
        $secret = $this->secretDepuis($donnees);
        $lignes[] = $this->normaliser($donnees, $id, $secret);
        $this->ecrire($lignes);

        return $id;
    }

    /**
     * Met à jour un compte. Un mot de passe laissé au masque (`**********`) conserve le secret existant.
     *
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
            $existant = (string) ($ligne['secret_chiffre'] ?? '');
            $secret = $this->secretDepuis($donnees) ?: $existant;
            $lignes[$index] = $this->normaliser($donnees, $id, $secret, $ligne);
            $this->ecrire($lignes);

            return;
        }

        throw new ErreurCourrier("compte $id introuvable");
    }

    /** Active / met en veille un compte sans rien perdre. */
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

        throw new ErreurCourrier("compte $id introuvable");
    }

    /** Retire un compte : MARQUÉ inactif, jamais effacé. */
    public function retirer(int $id): void
    {
        $this->basculer($id, false);
    }

    /** @param array<string, mixed> $donnees */
    private function secretDepuis(array $donnees): string
    {
        $clair = (string) ($donnees['mot_de_passe'] ?? '');
        if ($clair === '' || str_replace('*', '', $clair) === '') {
            return '';
        }

        return $this->coffre->chiffrer($clair);
    }

    /**
     * @param array<string, mixed> $donnees
     * @param array<string, mixed> $precedent
     * @return array<string, mixed>
     */
    private function normaliser(array $donnees, int $id, string $secret, array $precedent = []): array
    {
        return [
            'id' => $id,
            'nom' => trim((string) ($donnees['nom'] ?? $precedent['nom'] ?? '')),
            'serveur' => (string) ($donnees['serveur'] ?? $precedent['serveur'] ?? ''),
            'port' => isset($donnees['port']) && $donnees['port'] !== '' ? (int) $donnees['port'] : ($precedent['port'] ?? null),
            'securite' => (int) ($donnees['securite'] ?? $precedent['securite'] ?? Compte::SECURITE_SSL),
            'utilisateur' => (string) ($donnees['utilisateur'] ?? $precedent['utilisateur'] ?? ''),
            'secret_chiffre' => $secret,
            'jeton' => (bool) ($donnees['jeton'] ?? $precedent['jeton'] ?? false),
            'jeu_caracteres' => (string) ($donnees['jeu_caracteres'] ?? $precedent['jeu_caracteres'] ?? 'UTF-8'),
            'type_compte' => (int) ($donnees['type_compte'] ?? $precedent['type_compte'] ?? Compte::TYPE_IMAP),
            'jeton_rafraichissement' => $donnees['jeton_rafraichissement'] ?? $precedent['jeton_rafraichissement'] ?? null,
            'expiration' => $donnees['expiration'] ?? $precedent['expiration'] ?? null,
            'proprietaire' => (string) ($donnees['proprietaire'] ?? $precedent['proprietaire'] ?? ''),
            'actif' => (bool) ($donnees['actif'] ?? $precedent['actif'] ?? true),
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
            throw new ErreurCourrier('comptes : fichier illisible');
        }

        return array_values(array_filter($donnees, 'is_array'));
    }

    /** @param list<array<string, mixed>> $lignes */
    private function ecrire(array $lignes): void
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new ErreurCourrier('comptes : dossier impossible ' . $this->dossier);
        }
        $tmp = $this->fichier() . '.partiel';
        $json = json_encode($lignes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $this->fichier())) {
            throw new ErreurCourrier('comptes : écriture impossible');
        }
    }
}
