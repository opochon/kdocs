<?php

declare(strict_types=1);

namespace Ged\Archivage;

use Ged\Config;

/** Lecture compacte des justificatifs actifs pour l'écran Archives. Le dossier reste la source. */
final class EcranArchives
{
    public function __construct(private readonly Config $config, private readonly Horloge $horloge = new HorlogeSysteme())
    {
    }

    /** @return array{documents:list<array<string,mixed>>,a_echeance:list<array<string,mixed>>,types:list<string>,filtres:array<string,string>} */
    public function donnees(array $query = []): array
    {
        $racine = rtrim(str_replace('\\', '/', $this->config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
        $type = trim((string) ($query['type'] ?? ''));
        $echeance = (string) ($query['echeance'] ?? 'toutes');
        if (!in_array($echeance, ['toutes', 'proche', 'depassee'], true)) {
            $echeance = 'toutes';
        }
        $aujourdhui = $this->horloge->maintenant()->format('Y-m-d');
        $limite = $this->horloge->maintenant()->modify('+90 days')->format('Y-m-d');
        $retention = new Retention($racine, $this->horloge);
        $idsAEcheance = array_flip(array_map(static fn (array $ligne): int => (int) $ligne['document_id'], $retention->aEcheance(90)));
        $documents = [];

        foreach (Scellement::parcourir($racine) as $justificatif) {
            $scellement = Scellement::decoder((string) file_get_contents($justificatif));
            if (!Scellement::estActif($scellement)) {
                continue;
            }
            $typeDocument = (string) ($scellement['type'] ?? 'Sans type');
            $date = (string) ($scellement['echeance'] ?? '');
            if ($type !== '' && mb_strtolower($type) !== mb_strtolower($typeDocument)) {
                continue;
            }
            if (($echeance === 'proche' && ($date === '' || $date > $limite))
                || ($echeance === 'depassee' && ($date === '' || $date >= $aujourdhui))) {
                continue;
            }
            $relatif = (string) ($scellement['chemin_relatif'] ?? '');
            $meta = $relatif === '' ? [] : $this->meta($racine . '/' . $relatif);
            $documents[] = [
                'document_id' => (int) ($scellement['document_id'] ?? 0),
                'titre' => (string) ($meta['titre'] ?? $scellement['nom'] ?? basename($relatif)),
                'type' => $typeDocument,
                'chemin_relatif' => $relatif,
                'scelle_le' => (string) ($scellement['scelle_le'] ?? ''),
                'echeance' => $date,
                'tsa_jeton' => (string) ($scellement['tsa_jeton'] ?? ''),
                'a_echeance' => isset($idsAEcheance[(int) ($scellement['document_id'] ?? 0)]),
                'depassee' => $date !== '' && $date < $aujourdhui,
            ];
        }
        usort($documents, static fn (array $a, array $b): int => [$a['echeance'], $a['titre']] <=> [$b['echeance'], $b['titre']]);
        $tous = $documents;
        if ($type !== '' || $echeance !== 'toutes') {
            $tous = $this->donnees([])['documents'];
        }
        $types = array_values(array_unique(array_filter(array_map(static fn (array $d): string => $d['type'], $tous))));
        sort($types, SORT_NATURAL | SORT_FLAG_CASE);
        $aEcheance = array_values(array_filter($tous, static fn (array $d): bool => $d['a_echeance']));

        return ['documents' => $documents, 'a_echeance' => $aEcheance, 'types' => $types,
            'filtres' => ['type' => $type, 'echeance' => $echeance]];
    }

    /** @return array<string,mixed> */
    private function meta(string $absolu): array
    {
        $contenu = is_file($absolu . '.meta.json') ? file_get_contents($absolu . '.meta.json') : false;
        $meta = is_string($contenu) ? json_decode($contenu, true) : null;

        return is_array($meta) ? $meta : [];
    }
}
