<?php
/**
 * K-Docs - Interface AIService
 * Contrat pour les services d'IA/LLM
 */

namespace KDocs\Contracts;

interface AIServiceInterface
{
    /**
     * Vérifie si le service est configuré et disponible
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Envoie un message au service IA
     *
     * @param string $prompt Le prompt utilisateur
     * @param string|null $systemPrompt Le prompt système (optionnel)
     * @return array|null Réponse du service ou null si erreur
     */
    public function sendMessage(string $prompt, ?string $systemPrompt = null): ?array;

    /**
     * Extrait le texte d'une réponse IA
     *
     * @param array $response Réponse brute du service
     * @return string Texte extrait
     */
    public function extractText(array $response): string;
}
