# Collaudo 0.7.3 — un solo ordine per il primo incasso

Sostituisce le guide precedenti dopo tre revisioni indipendenti (R1–R4, N1–N5, P1–P3). Preparata e verificata
localmente il 22 ottobre 2026. **Non ancora installata sul sito.** Verificata in simulazione, non con un
acquisto reale né su MySQL: non installare in produzione prima del collaudo integrato qui sotto.

## Cosa fa la guardia (e cosa non è)

Sul solo evento `PAYMENT.SALE.COMPLETED` (firma valida, PayPal Sandbox), prima del gestore PMPro:

1. l'incasso non è già associato a un ordine; l'abbonamento ha **un solo** ordine `success` senza ID incasso;
2. GET diretto della sale in PayPal: completata, stesso abbonamento, EUR, importo uguale al checkout;
3. la sale è del primo incasso (entro 1 ora dalla creazione dell'abbonamento), il checkout è coevo;
4. `UPDATE` atomico del solo ID vuoto, rilettura, audit riletto.

Poi il gestore ufficiale trova l'ordine e risponde «già elaborato».

**Non è passiva**: scrive l'ID incasso sull'ordine, può rispondere HTTP 503 e quindi cambia il momento in cui
PayPal considera consegnata la notifica. Non crea né cancella ordini e non cambia stati. Si attiva da sola per
PayPal Sandbox; interruttore da codice: `add_filter('pmpro_fic_webhook_guard_enabled', '__return_false');`.
Per il collaudo si consiglia un interruttore esplicito nel backend (non ancora implementato); se serva un'attivazione esplicita (opt-in) è una scelta operativa ancora aperta.

## Regola di decisione

| Situazione | Risposta |
| --- | --- |
| Dati ben formati ma diversi (importo, abbonamento, stato), non è il primo incasso, checkout incoerente | comportamento ufficiale |
| **Qualunque altro esito** (rete, JSON vuoto o malformato, data o importo illeggibili, 403, archivio o collegamenti non leggibili, associazione non riuscita) **e** sale e checkout distano meno di 24 ore | **HTTP 503**: PayPal riconsegna; mai il passaggio al core |
| Come sopra ma distanti 24 ore o più | comportamento ufficiale |
| Checkout ancora `token`, sale coeva | 503 |
| Associazione riuscita ma audit non confermato | 503 + lavoro locale programmato; l'ID resta associato |

**Insieme di casi protetti** (stessi estremi per associare e per rinviare): |checkout − incasso| **< 24 ore**, stretto, e per associare anche incasso entro 1 ora dalla creazione dell'abbonamento. Per associare conta l'orario dell'incasso verificato in PayPal; per il rinvio quello della notifica (non autenticato). Fuori da questo insieme (prova gratuita, primo incasso oltre 24 ore dal checkout) la guardia non interviene: vale il comportamento ufficiale e il doppione resta possibile. Il ciclo minimo giornaliero di PayPal è un'**ipotesi**: un rinnovo dovrebbe cadere a 24 ore o più dal checkout, da confermare con un rinnovo reale in Sandbox, comprese consegne ritardate.

## Correzioni

- **P1**: senza credenziali PayPal, o con un'eccezione interna dopo aver individuato il candidato, e con sale coeva, la guardia risponde **503** (esiti `gateway_unavailable`, `guard_exception`) invece di passare al core. Senza candidato, o con sale lontana, vale il comportamento ufficiale: non ogni eccezione REST diventa un 503.
- **P2**: associazione e rinvio usano lo stesso confronto checkout ↔ incasso (< 24 ore, stretto); testati ai bordi (23h59m59s, 24h esatte, 24h20m) con risposta completa e incompleta.
- **P3**: l'accodamento del lavoro locale è controllato (`repair_not_scheduled` se non risulta salvato; il cron di WordPress vive nelle opzioni). Un'eccezione nel lavoro lo riprogramma. Dal backend, nella pagina impostazioni, compare «Audit incompleti» con il pulsante «Riprova gli audit incompleti» (solo `manage_options`, con nonce): ripara i registri della guardia anche con registro eventi non scrivibile, senza creare fatture né toccare l'ID.

- **R1–R4** (0.7.1): fuso UTC, nessun blocco sul registro, rilettura dopo la corsa persa, audit riletto.
- **N1**: un errore di lettura dei collegamenti 0.6.1 (archivio non leggibile o record corrotto) non è più
  scambiato per «collegamento esistente»: con sale coeva produce 503.
- **N2**: gli errori di struttura (data, importo o valuta mancanti o illeggibili) sono «risposta incompleta»
  (`payment_incomplete`), non rifiuti certi. Sono definitivi solo identità, stato e importo ben formato ma diverso.
  Questo cambia anche la coda fatture: un importo o una data malformati ora riprovano invece di bloccare.
- **N3**: se l'audit non si completa, la guardia risponde 503 (la consegna non viene chiusa) e programma un lavoro
  locale (`pmpro_fic_guard_repair`, fino a 5 tentativi) che lo completa da solo. La riparazione non dipende più
  dal numero di ordini: funziona anche dopo un rinnovo. Riguarda solo registri creati dalla guardia, non crea
  fatture e non cambia l'ID. Un pagamento poi rimborsato non viene riparato.
- **N4**: finestra unica coerente (vedi sopra); un checkout 3 ore prima dell'abbonamento è protetto sia con risposta
  completa sia incompleta.
- **N5**: il pannello mostra lo stato effettivo (gateway, ambiente, filtro) e avverte che l'elenco vuoto non prova
  che non siano arrivati webhook, perché le uscite anticipate non vengono registrate.

## Collaudo integrato (prima di fidarsi)

1. Backup del database; installare lo ZIP su Sandbox, non su dati reali. `define('PMPRO_PAYPAL_WEBHOOK_DEBUG', 'log');`.
2. Eseguire **più** acquisti con utenti diversi (il difetto è intermittente: 62 e 67 erano riusciti senza guardia).
3. Per ciascuno: una sola riga `success` con ID incasso, nessuna seconda email fattura, tabella della guardia
   nelle impostazioni coerente (`bound`, `token_retry`, `sale_unverifiable_*`…). Per provare il ramo `bound` serve
   almeno un caso in cui l'attivazione chiude il checkout senza ID.
4. Ritardare l'attivazione o rinviare un webhook dal pannello PayPal Sandbox e osservare il comportamento.
5. Se compaiono ancora due righe: fermarsi, conservare ordini, registro della guardia e registro webhook.

## Limiti dichiarati

- 1.773 controlli (215 della guardia) con il gestore reale di PMPro 3.8.9 (`tests/fixtures`, estratto dal tag);
  WordPress, PayPal e il livello dati PMPro sono simulati, SQL su SQLite. Nessuna vera concorrenza MySQL.
- Il controllo del proprio registro prima di cancellarlo (`get_option` poi `delete_option`) non è un
  compare-and-delete atomico: nei test non produce doppioni, ma non è provato su database reale.
- Un'eccezione prima di individuare il candidato lascia il comportamento ufficiale. Con le credenziali mancanti il 503 si ripete finché PayPal riprova (fino a 3 giorni): senza credenziali il problema è di configurazione. Se anche il cron non è salvabile, il recupero locale non è garantito: restano la riconsegna PayPal e la ripresa dal backend.
- La sequenza «attivazione senza ID, poi incasso» è l'ipotesi più coerente, non una ricostruzione dei webhook
  storici. Il 503 si basa sul retry documentato di PayPal; `Retry-After` non è garantito. Versione dell'addon
  sul sito ancora da rilevare. Solo Sandbox. Non corregge ordini già doppi (63/65: 0.6.1) né i report PMPro.
