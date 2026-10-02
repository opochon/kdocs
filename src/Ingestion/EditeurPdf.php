<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Config;

/**
 * Frontière sûre vers l'éditeur PDF de l'ingestion. Chaque geste écrit un NOUVEAU PDF ; l'appelant
 * décide ensuite comment versionner ou ranger ce résultat. Ainsi le PDF source du lot reste intact.
 */
final class EditeurPdf
{
    public const MOTEUR_ABSENT = 'La retouche de page est indisponible : moteur PDF absent';

    public function __construct(private readonly string $python = 'python', ?string $script = null)
    {
        $this->script = $script ?? dirname(__DIR__, 2) . '/agent/editer_pdf.py';
    }

    private readonly string $script;

    public static function depuisConfig(Config $config): self
    {
        return new self($config->get('CMD4_PYTHON', 'python'));
    }

    public static function compterPages(string $source): int
    {
        return self::configure()->entier(['action' => 'count', 'source' => $source], 'pages');
    }

    public static function rotation(string $source, int $page): int
    {
        return self::configure()->entier(['action' => 'rotation', 'source' => $source, 'page' => $page], 'rotation');
    }

    public static function tourner(string $source, string $sortie, int $page, int $degres): void
    {
        self::configure()->executer(['action' => 'turn', 'source' => $source, 'output' => $sortie, 'page' => $page, 'degrees' => $degres]);
    }

    public static function scinder(string $source, string $gauche, string $droite, int $apres): void
    {
        self::configure()->executer(['action' => 'split', 'source' => $source, 'left' => $gauche, 'right' => $droite, 'after' => $apres]);
    }

    /** @param list<string> $sources */
    public static function fusionner(array $sources, string $sortie): void
    {
        self::configure()->executer(['action' => 'merge', 'sources' => $sources, 'output' => $sortie]);
    }

    public static function retirer(string $source, string $sortie, string $retiree, int $page): void
    {
        self::configure()->executer(['action' => 'remove', 'source' => $source, 'output' => $sortie, 'removed' => $retiree, 'page' => $page]);
    }

    /** @return array{disponible:bool, message:string, moteur?:string, version?:string} */
    public function diagnostic(): array
    {
        try {
            $resultat = $this->appel(['action' => 'diagnostic']);
            if (($resultat['moteur'] ?? null) !== 'PyMuPDF' || !is_string($resultat['version'] ?? null)) {
                throw new \RuntimeException('réponse de diagnostic PDF invalide');
            }

            return ['disponible' => true, 'message' => 'moteur PDF disponible', 'moteur' => 'PyMuPDF', 'version' => $resultat['version']];
        } catch (\Throwable) {
            return ['disponible' => false, 'message' => self::MOTEUR_ABSENT];
        }
    }

    /** La cause technique reste dans le journal ; aucun stderr Python n'est une cause affichable. */
    public static function messageUtilisateur(\Throwable $erreur): string
    {
        $message = $erreur->getMessage();
        if (preg_match('/ModuleNotFoundError|No module named|moteur Python (?:in)?disponible|moteur Python introuvable/i', $message) === 1) {
            return self::MOTEUR_ABSENT;
        }
        if (preg_match('/^(édition|scission|fusion|retrait|annulation) impossible\s*:/u', $message) === 1) {
            return $message;
        }

        return 'La retouche de page a échoué.';
    }

    /** @param array<string, mixed> $commande @return array<string, mixed> */
    public function executer(array $commande): array
    {
        return $this->appel($commande);
    }

    private static function configure(): self
    {
        return self::depuisConfig(new Config(dirname(__DIR__, 2)));
    }

    /** @param array<string, mixed> $commande */
    private function entier(array $commande, string $cle): int
    {
        $resultat = $this->appel($commande);
        if (!isset($resultat[$cle]) || !is_numeric($resultat[$cle])) {
            throw new \RuntimeException('édition PDF : réponse du moteur invalide');
        }

        return (int) $resultat[$cle];
    }

    /** @param array<string, mixed> $commande @return array<string, mixed> */
    private function appel(array $commande): array
    {
        if (!is_file($this->script)) {
            throw new \RuntimeException('édition PDF : moteur Python introuvable');
        }
        $processus = @proc_open([$this->python, $this->script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($processus)) {
            throw new \RuntimeException('édition PDF : moteur Python indisponible');
        }
        fwrite($pipes[0], json_encode($commande, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        fclose($pipes[0]);
        $sortie = stream_get_contents($pipes[1]);
        $erreur = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($processus) !== 0) {
            throw new \RuntimeException($erreur !== '' ? $erreur : 'édition PDF : le moteur a échoué');
        }
        if (trim($sortie) === '') {
            return [];
        }
        $resultat = json_decode($sortie, true);
        if (!is_array($resultat)) {
            throw new \RuntimeException('édition PDF : réponse du moteur invalide');
        }

        return $resultat;
    }
}
