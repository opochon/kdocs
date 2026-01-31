# K-Mail

Client de messagerie leger avec recherche semantique.

## Contraintes

- **PAS DE DOCKER**
- PHP IMAP extension uniquement
- Qdrant en binaire natif (pas conteneur)
- Doit demarrer en < 2 secondes

## Stack technique

| Composant | Solution |
|-----------|----------|
| IMAP/SMTP | `php-imap` extension native |
| CalDAV | Sabre/DAV (PHP pur) |
| Vectorisation | Qdrant binaire + API HTTP |
| Embeddings | Ollama local OU API externe |
| Cache | SQLite local |
| UI | PHP + Tailwind (SSR) |

## Structure

```
mail/
├── Controllers/
│   ├── MailboxController.php
│   ├── MessageController.php
│   └── CalendarController.php
├── Models/
│   ├── Message.php
│   ├── Folder.php
│   └── Event.php
├── Services/
│   ├── ImapService.php
│   ├── SmtpService.php
│   └── CalDavService.php
├── templates/
│   ├── inbox.php
│   └── compose.php
├── migrations/
├── routes.php
├── config.php
└── README.md
```

## Fonctionnalites prevues

### Phase 1 - MVP (leger)
- [ ] Connexion IMAP
- [ ] Liste mails
- [ ] Lecture mail
- [ ] Envoi simple
- [ ] Recherche full-text (SQLite FTS5)

### Phase 2 - Semantique
- [ ] Indexation vectorielle (Qdrant bin)
- [ ] Recherche par sens
- [ ] Suggestions

### Phase 3 - Agenda
- [ ] CalDAV sync
- [ ] Types de RDV
- [ ] Champs metier

## Statut

**A faire** - Phase de conception

---
*K-Mail - Application K-Docs*
