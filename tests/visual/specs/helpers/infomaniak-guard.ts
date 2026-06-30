import type { APIRequestContext } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const PREFLIGHT_TIMEOUT_MS = 12_000;

/**
 * Préflight : vérifie qu'Infomaniak répond vite, en sondant l'API directement depuis Node
 * (sans impliquer le serveur PHP mono-processus, que la retry-loop de complete() sinon
 * bloque pendant ~50s sur 429). Lit la config dans le .env du projet (même source que
 * InfomaniakAIService) et fait une completion minuscule sur l'endpoint v2 OpenAI-compatible.
 *
 * La logique IA (cascade, count-all, JSON-not-HTML) est couverte hermétiquement par PHPUnit
 * (AiCascadeInfomaniakTest, NaturalLanguageQueryCountTest). Les specs Playwright qui
 * dépendent de l'IA live (bugs-D2, pipeline-ui) s'adaptent quand Infomaniak est lent ou
 * ratelimited, au lieu de tomber en rouge sur timeout. Gate honnête.
 */
export async function infomaniakReady(_api?: APIRequestContext): Promise<boolean> {
  try {
    const envPath = path.resolve(__dirname, '..', '..', '..', '..', '.env');
    const raw = fs.readFileSync(envPath, 'utf8');
    const env: Record<string, string> = {};
    for (const line of raw.split(/\r?\n/)) {
      const m = line.match(/^([A-Z_]+)=(.*)$/);
      if (m) env[m[1]] = m[2].trim();
    }
    const apiKey = env.INFOMANIAK_AI_API_KEY ?? env.INFOMANIAK_API_TOKEN ?? '';
    const productId = env.INFOMANIAK_AI_API_SECRET ?? env.INFOMANIAK_AI_PRODUCT_ID ?? env.INFOMANIAK_PRODUCT_ID ?? '';
    const model = env.INFOMANIAK_AI_MODEL ?? 'swiss-ai/Apertus-70B-Instruct-2509';
    const enabled = String(env.INFOMANIAK_AI_ENABLED ?? 'false').toLowerCase() === 'true';
    if (!enabled || !apiKey || !productId) return false;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), PREFLIGHT_TIMEOUT_MS);
    try {
      const res = await fetch(
        `https://api.infomaniak.com/2/ai/${encodeURIComponent(productId)}/openai/v1/chat/completions`,
        {
          method: 'POST',
          signal: controller.signal,
          headers: {
            Authorization: `Bearer ${apiKey}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            model,
            max_tokens: 10,
            temperature: 0.1,
            messages: [{ role: 'user', content: "Réponds juste: OK" }],
          }),
        },
      );
      if (!res.ok) return false;
      const json: any = await res.json();
      const text = json?.choices?.[0]?.message?.content ?? '';
      return typeof text === 'string' && text.trim() !== '';
    } finally {
      clearTimeout(timer);
    }
  } catch {
    return false;
  }
}
