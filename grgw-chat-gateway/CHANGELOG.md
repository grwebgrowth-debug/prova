# Changelog

## Unreleased
- HITL: sovrascrittura dell'outbox su nuovi submit e salvataggio allegati nel meta dei messaggi utente.
- REST: header no-cache per endpoints GET critici (conversazioni, dettagli, updates, outbox).
- HITL: chiusura automatica della fase attiva su messaggi finali senza meta `poll_stop`.
- Front-end: upload file con campo corretto, fetch immediato post-invio e scroll anchoring mobile più stabile.
