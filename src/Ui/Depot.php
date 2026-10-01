<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Fabrique;
use Ged\Ingestion\Travaux;
use Ged\Jonctions\SourceApi;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Dépôt de fichiers par l'interface (écran « Déposer » du mobile, étape 1).
 *
 * Le fichier reçu (typiquement une photo prise à l'appareil) entre dans la MÊME chaîne d'ingestion que
 * le dépôt de bureau : `Ged\Jonctions\SourceApi` écrit la copie de travail dans la zone d'ingestion puis
 * `Travaux::recevoir` enregistre un travail idempotent — exactement ce que fait la route d'entrée
 * `POST /api/v1/depots` (destination `ingestion`). La porte est ici la session et la permission
 * `documents:traiter`, pas un jeton d'application.
 *
 * Aucune suppression : un doublon est mis de côté, jamais perdu. Une panne d'un fichier n'empêche pas
 * les autres d'entrer, et l'erreur est nommée pour l'écran.
 */
final class Depot
{
    public function __construct(private readonly Config $config)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)));
    }

    /**
     * @param list<UploadedFileInterface> $fichiers
     * @return list<array{nom:string, travail:?int, erreur:?string}>
     */
    public function recevoir(array $fichiers, string $auteur): array
    {
        if (!class_exists(SourceApi::class)) {
            throw new \RuntimeException('chaîne d’ingestion indisponible (secteur Jonctions absent)');
        }
        $pdo = Base::pdo($this->config);
        $source = new SourceApi(Fabrique::zoneTravail($this->config) . '/api', new Travaux($pdo), $pdo);

        $resultats = [];
        foreach ($fichiers as $fichier) {
            $nom = $fichier->getClientFilename() ?? 'depot';
            try {
                if ($fichier->getError() !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('transfert incomplet (code ' . $fichier->getError() . ')');
                }
                if (SourceApi::extensionAutorisee($nom) === null) {
                    throw new \RuntimeException(sprintf('type refusé : « %s » (types acceptés : %s)', $nom,
                        implode(', ', SourceApi::EXTENSIONS)));
                }
                $reception = $source->recevoir($fichier, $nom, ['source' => 'interface', 'auteur' => $auteur], 'web');
                $resultats[] = ['nom' => $nom, 'travail' => (int) $reception['travail'], 'erreur' => null];
            } catch (Throwable $e) {
                $resultats[] = ['nom' => $nom, 'travail' => null, 'erreur' => $e->getMessage()];
            }
        }

        return $resultats;
    }
}
