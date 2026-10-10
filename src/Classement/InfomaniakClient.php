<?php

declare(strict_types=1);

namespace Ged\Classement;

use Closure;
use Ged\Config;

/**
 * Adaptateur Infomaniak AI Tools pour le classement (repris proprement de GEDv1).
 *
 * - modèle de classement : INFOMANIAK_AI_MODEL_CLASSIFY, défaut `google/gemma-4-31B-it` (banc du 28-09 :
 *   50/51 types justes, 51/51 émetteurs) ;
 * - délai borné : INFOMANIAK_AI_TIMEOUT_CLASSIFY, défaut 20 s ; une pièce ne déclenche qu'UNE requête,
 *   pour que le budget de l'ingestion reste prévisible sous charge ;
 * - repli sans blocage : toute panne rend null, le classeur se rabat sur les règles.
 *
 * Le transport HTTP est injectable : les tests ne touchent jamais le réseau.
 */
final class InfomaniakClient implements ClientIa
{
    private const CHEMIN_V2 = 'https://api.infomaniak.com/2/ai/%s/openai/v1/chat/completions';
    private const DELAI_DEFAUT = 20;
    private const MODELE_DEFAUT = 'google/gemma-4-31B-it';

    private readonly ?Closure $transport;
    private bool $dernierExpire = false;
    private int $appels = 0;
    private float $tempsAppelsS = 0.0;

    public function __construct(private readonly Config $config, ?callable $transport = null)
    {
        $this->transport = $transport !== null ? Closure::fromCallable($transport) : null;
    }

    public function disponible(): bool
    {
        return filter_var($this->config->get('INFOMANIAK_AI_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)
            && $this->config->get('INFOMANIAK_AI_API_KEY') !== ''
            && $this->config->get('INFOMANIAK_AI_API_SECRET') !== '';
    }

    public function modele(): string
    {
        $modele = trim($this->config->get('INFOMANIAK_AI_MODEL_CLASSIFY', self::MODELE_DEFAUT));

        return $modele !== '' ? $modele : self::MODELE_DEFAUT;
    }

    public function aExpire(): bool
    {
        return $this->dernierExpire;
    }

    public function classer(string $texte, string $nomFichier, array $typesConnus): ?ReponseIa
    {
        $texte = trim($texte);
        if (!$this->disponible() || $texte === '') {
            return null;
        }

        $modele = $this->modele();
        $delai = max(5, (int) $this->config->get('INFOMANIAK_AI_TIMEOUT_CLASSIFY', (string) self::DELAI_DEFAUT));
        $corps = (string) json_encode([
            'model' => $modele,
            'max_tokens' => 1500,
            'temperature' => 0.1,
            'messages' => [
                ['role' => 'system', 'content' => 'Tu analyses des documents pour une GED fiduciaire suisse. Réponds UNIQUEMENT en JSON valide.'],
                ['role' => 'user', 'content' => $this->invite($texte, $nomFichier, $typesConnus)],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entetes = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config->get('INFOMANIAK_AI_API_KEY'),
            'Content-Type: application/json',
        ];
        $produit = rawurlencode($this->config->get('INFOMANIAK_AI_API_SECRET'));

        $contenu = $this->appeler(sprintf(self::CHEMIN_V2, $produit), $entetes, $corps, $delai);
        if ($contenu === null) {
            return null;
        }
        $parsed = self::extraireJson($contenu);
        if ($parsed === null) {
            return null; // réponse illisible : on ne devine pas
        }

        return $this->depuisReponse($parsed, $typesConnus, $modele);
    }

    /** @return array{appels:int, temps_s:float} Mesure cumulée, exploitable par les bancs. */
    public function statistiques(): array
    {
        return ['appels' => $this->appels, 'temps_s' => round($this->tempsAppelsS, 3)];
    }

    /** @return array<string, mixed>|null */
    public static function extraireJson(string $texte): ?array
    {
        $texte = trim($texte);
        if (str_starts_with($texte, '```')) {
            $texte = (string) preg_replace('/^```(?:json)?\s*/i', '', $texte);
            $texte = (string) preg_replace('/\s*```$/', '', $texte);
        }
        if (preg_match('/\{[\s\S]*\}/m', $texte, $m)) {
            $texte = $m[0];
        }
        $donnees = json_decode($texte, true);

        return is_array($donnees) && json_last_error() === JSON_ERROR_NONE ? $donnees : null;
    }

    /**
     * Appelle un point d'entrée et rend le contenu texte du modèle, ou null. Met à jour l'état d'expiration.
     *
     * @param list<string> $entetes
     */
    private function appeler(string $url, array $entetes, string $corps, int $delai): ?string
    {
        $this->dernierExpire = false;
        $debut = microtime(true);
        $this->appels++;
        $reponse = $this->transport !== null
            ? ($this->transport)($url, $entetes, $corps, $delai)
            : $this->curl($url, $entetes, $corps, $delai);
        $this->tempsAppelsS += microtime(true) - $debut;

        if (($reponse['expire'] ?? false) === true) {
            $this->dernierExpire = true;

            return null;
        }
        $code = (int) ($reponse['code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $donnees = json_decode((string) ($reponse['corps'] ?? ''), true);
        $contenu = $donnees['choices'][0]['message']['content'] ?? null;

        return is_string($contenu) && trim($contenu) !== '' ? trim($contenu) : null;
    }

    /** @param array<string, mixed> $parsed
     *  @param list<string>          $typesConnus */
    private function depuisReponse(array $parsed, array $typesConnus, string $modele): ReponseIa
    {
        $typeBrut = $parsed['document_type_name'] ?? $parsed['type'] ?? null;
        $type = null;
        if (is_string($typeBrut) && trim($typeBrut) !== '') {
            foreach ($typesConnus as $connu) {
                if (Regles::memeType($connu, $typeBrut)) {
                    $type = $connu;
                    break;
                }
            }
        }
        $date = is_string($parsed['document_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['document_date']) === 1
            ? $parsed['document_date'] : null;
        $brut = $parsed['new_tags'] ?? $parsed['tags'] ?? [];
        $etiquettes = [];
        if (is_array($brut)) {
            foreach ($brut as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $etiquettes[] = $t;
                }
            }
        }

        return new ReponseIa(
            type: $type,
            emetteur: is_string($parsed['correspondent_name'] ?? null) && trim($parsed['correspondent_name']) !== ''
                ? trim($parsed['correspondent_name']) : null,
            titre: is_string($parsed['title'] ?? null) && trim($parsed['title']) !== '' ? trim($parsed['title']) : null,
            date: $date,
            montant: is_numeric($parsed['amount'] ?? null) ? (float) $parsed['amount'] : null,
            etiquettes: $etiquettes,
            confiance: min(0.99, max(0.0, (float) ($parsed['confidence'] ?? 0.75))),
            modele: $modele,
        );
    }

    /** @param list<string> $typesConnus */
    private function invite(string $texte, string $nomFichier, array $typesConnus): string
    {
        $liste = implode(', ', $typesConnus);
        $organisation = trim($this->config->get('GED_ORGANISATION', ''));
        $proprietaire = $organisation !== ''
            ? "- « {$organisation} » est le propriétaire de la GED (destinataire) : jamais correspondant.\n" : '';
        $apercu = mb_substr($texte, 0, 3000);
        $nomFichier = $nomFichier !== '' ? $nomFichier : 'piece';

        return <<<PROMPT
        Analyse ce document et propose son classement.

        Fichier : {$nomFichier}
        Contenu :
        {$apercu}

        Types disponibles : {$liste}

        Règles :
        - Le correspondant est l'ÉMETTEUR du document (qui l'a écrit ou envoyé), jamais le destinataire.
          Ignore les lignes OCR illisibles, adresses et phrases de corps ; cherche aussi dans le logo ou pied de page.
        {$proprietaire}- document_type_name DOIT être un des types disponibles ; si aucun ne convient vraiment, null.
        - document_date est la date D'ÉMISSION du document, jamais une date de naissance, une période de
          prestation, une échéance ancienne ou une date seulement citée dans le corps. Si elle n'est pas sûre, null.
        - confidence : ta probabilité réelle d'avoir juste sur le TYPE ; baisse-la en cas de doute.

        Réponds UNIQUEMENT en JSON valide avec ce format :
        {
          "document_type_name": "<un des types disponibles ou null>",
          "correspondent_name": "<nom de l'ÉMETTEUR si absent de la liste>",
          "title": "<titre court suggéré>",
          "document_date": "<YYYY-MM-DD ou null>",
          "amount": <nombre ou null>,
          "new_tags": ["<étiquette>", "..."],
          "confidence": <0.0-1.0>
        }
        PROMPT;
    }

    /**
     * Transport HTTP par défaut (curl). Rendu sous la même forme que le transport injecté.
     *
     * @param list<string> $entetes
     *
     * @return array{code:int, corps:string, expire:bool}
     */
    private function curl(string $url, array $entetes, string $corps, int $delai): array
    {
        if (!function_exists('curl_init')) {
            return ['code' => 0, 'corps' => '', 'expire' => false];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['code' => 0, 'corps' => '', 'expire' => false];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $delai,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_POSTFIELDS => $corps,
        ]);
        $brut = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $expire = $brut === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT;
        curl_close($ch);

        return ['code' => $code, 'corps' => is_string($brut) ? $brut : '', 'expire' => $expire];
    }
}
