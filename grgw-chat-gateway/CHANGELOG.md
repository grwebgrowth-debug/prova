# Changelog

## Unreleased
- HITL: sostituzione outbox con upsert per sovrascrivere invii multipli e salvataggio meta allegati nei messaggi utente.
- HITL: fallback per chiusura automatica in assenza di meta `poll_stop` e header no-cache per updates/outbox.
- REST: header no-cache per lista conversazioni e dettaglio con messaggi; salvataggio meta allegati nei messaggi utente.
- Front-end: fix campo file per upload, fetch immediato post-invio e auto-scroll più rispettoso su mobile.
