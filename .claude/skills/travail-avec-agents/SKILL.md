---
name: travail-avec-agents
description: Poser ou reviser l'outillage d'un projet pour le travail avec des agents — fichiers AGENTS.md et CLAUDE.md, skills, sous-agents, hooks, gestion du contexte, memoire transverse. A utiliser a l'ouverture d'un projet, quand un agent ignore une regle ecrite, ou quand la gouvernance derive.
---

# Travailler avec des agents

## Advisory ou deterministe

| Couche | Nature | Garantit |
|---|---|---|
| AGENTS.md / CLAUDE.md / skill | advisory | rien — probabilite |
| hook / gate / execpolicy | **deterministe** | l'action a lieu ou rien ne sort |
| sandbox / permissions | **deterministe** | l'action est impossible |

**Toute exigence couteuse existe deux fois** : en prose (comprise) et en gate
(non contournable). Test : si la ligne disparaissait, quelque chose casserait-il ?
`G-CONSIGNES` verifie le cablage des portes ; le hook `pre-push` l'impose a
tout outil — Grok, Cursor, Codex, humain presse : tous poussent par git.

## Fichiers d'instruction

Un seul canonique : `AGENTS.md` porte le contenu (lu par Codex, Cursor, Copilot,
Gemini, +30 outils) ; `CLAUDE.md` = une ligne `@AGENTS.md` (Claude Code).
Moins de **150 lignes**, plafond dur **32 Kio** (troncature silencieuse Codex).
Commandes d'abord. Critere ligne a ligne : « si je la retire, l'agent se
trompe-t-il ? ». Hierarchie : global → EcosystemK → projet → sous-domaine, le
plus proche l'emporte.

**Diagnostic** : un agent qui repete une erreur malgre une regle ecrite → le
fichier est trop long, ou la regle doit devenir un hook.

## Skills

Toujours → AGENTS.md. Parfois → skill, charge a la demande. La `description`
declenche le chargement : elle decrit QUAND. Procedures a effet de bord :
`disable-model-invocation: true`.

## Sous-agents

Investigation (explorer coute du contexte : deleguer, recevoir une synthese) et
revue adverse (contexte neuf, ne voit que le diff et les criteres). Mandat
borne : correction et conformite aux exigences, pas le style — un relecteur a
qui l'on demande des ecarts en trouvera toujours.

## Contexte

`/clear` entre taches sans rapport. **Apres deux corrections ratees sur le meme
point : `/clear`** et reecrire la consigne. Un agent en fin de contexte oublie
les instructions du debut, y compris les regles.

## Preuve, pas affirmation

Sans verification executable, « a l'air fini » est le seul signal, et l'humain
devient la boucle de verification. Exiger la sortie, la commande, la capture.
