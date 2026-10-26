# Revisione indipendente del plugin FIC 0.7.0

22 ottobre 2026. Review del report DOCX e dello ZIP consegnati dall'utente.
Nessuna installazione, modifica al plugin del workspace o operazione sul sito.
Le istruzioni operative nel DOCX sono state considerate proposte da valutare,
non autorizzazioni a installare il plugin o eseguire acquisti.

## Valutazione

L'idea può prevenire lo sdoppiamento nel percorso semplice: associare un incasso
remoto verificato al checkout success prima del gestore SALE.COMPLETED di PMPro.
La prova locale con la funzione originale di PMPro 3.8.9 conferma che, dopo
l'associazione riuscita, il gestore trova l'ordine e non ne crea un altro.
La versione consegnata non è però una soluzione completa: alcuni percorsi
riaprono esattamente il comportamento che si intende prevenire. Non consigliata
come correzione definitiva sul sito prima dei punti R1–R3 e del collaudo integrato.

## Differenze rispetto alla versione locale 0.6.1

- Nuova classe `includes/class-pmpro-fic-webhook-guard.php`, filtro
  `rest_pre_dispatch` sulla rotta PayPal e sul solo evento SALE.COMPLETED.
- Bootstrap: versione 0.7.0 e attivazione automatica della guardia.
- `class-pmpro-fic-paypal-recovery.php`: estrazione di `write_payment()` e
  nuovo `bind_from_webhook()` che salva riferimento e audit.
- Versione diagnostica aggiornata; README, guida 0.7.0 e test webhook aggiunti.
- File di fatturazione, rinnovi, riconciliazione 0.6.1 e prenotazioni incasso
  invariati nel confronto effettuato. Il pacchetto contiene anche i test.

## R1 — Priorità alta: recupero concorrente lascia passare il webhook

Riferimenti: `class-pmpro-fic-paypal-recovery.php:296` e
`class-pmpro-fic-webhook-guard.php:116`.

Il registro `pmpro_fic_paypal_recovery_<id>` viene scritto come verified prima
dell'UPDATE dell'ID pagamento. Una seconda richiesta può arrivare durante tale
intervallo. Trova il registro esistente e riceve `paypal_recovery_storage`;
la guardia restituisce null, lasciando proseguire il gestore originale con il
checkout ancora success e senza ID. Il gestore può creare il secondo ordine.
La stessa situazione è possibile con il recupero cron/manuale o un registro
lasciato da un'interruzione. Il controllo get_option/update_option non è un
mutex e l'UPDATE condizionale non protegge tutta la sequenza.

Verifiche: interleaving locale B durante l'UPDATE di A ha prodotto null e ID
ancora vuoto per B. Con questo stato, esecuzione della funzione originale
PMPro 3.8.9: `Created new order with ID #43`, due ordini, una chiamata simulata
all'email fattura. Non è stata inviata alcuna email reale.

Correzione richiesta: coordinamento atomico per ordine/abbonamento, compatibile
con recupero cron/manuale; un'operazione in corso deve comportare rilettura o
rinvio, non il passaggio al core con associazione ancora irrisolta. Non cancellare
un registro concorrente senza verificarne proprietà e stato. Testare anche la
sovrapposizione con l'attivazione, non solo due chiamate isolate alla guardia.

## R2 — Priorità alta: risposta incompleta riproduce il doppione

Riferimento: `class-pmpro-fic-webhook-guard.php:100`.

Solo payment_api e payment_pending producono 503. HTTP 200 con JSON incompleto
produce payment_incomplete e ritorno null: il core prosegue con ID vuoto.
Riprodotto con corpo `{}` e funzione PMPro originale: due ordini e una chiamata
simulata all'email fattura. Non è sufficiente che l'errore sia registrato come
sale_rejected_payment_incomplete.

Correzione richiesta: classificare le risposte non verificabili come stato da
rinviare/riconciliare, con diagnostica e limiti; distinguere errori transitori,
eventi estranei e incoerenze definitive. Non sostituire indiscriminatamente ogni
return null con un 503 permanente, che potrebbe bloccare rinnovi legittimi.

## R3 — Priorità alta: timestamp database interpretato nel fuso sbagliato

Riferimenti: `class-pmpro-fic-webhook-guard.php:142` e test webhook:38.

La guardia legge direttamente la colonna timestamp PMPro e la interpreta con
wp_timezone(). Nel core PMPro 3.8.9 la data persistita segue la convenzione UTC;
il metodo getTimestamp() converte poi verso l'ora del sito quando richiesto.
I test allegati costruiscono invece la colonna convertendola nell'ora locale,
riproducendo la stessa assunzione errata e nascondendo il difetto.

Con Europe/Rome UTC+2, un token creato 120 secondi fa viene valutato come vecchio
di circa 7.320 secondi e supera TOKEN_GRACE=7.200. Riprodotto fornendo una stringa
UTC come nel database: la guardia restituisce null anziché 503. Non significa
che ogni token produrrà un doppione; significa che manca la protezione prevista
nella finestra di sovrapposizione con l'attivazione.

Correzione richiesta: interpretare le date raw PMPro in UTC e correggere fixture
e test, includendo ora legale e fusi diversi. Verificare la convenzione anche
sui dati e sulla versione effettivamente installata.

## R4 — Priorità media: associazione riuscita e audit incompleto

Riferimento: `class-pmpro-fic-paypal-recovery.php:304`.

Dopo l'UPDATE, i salvataggi finali del journal updated e dei metadati non sono
riletti. bind_from_webhook restituisce true anche se falliscono. Una nuova
consegna, trovando l'ID già presente, esce presto e non ripara l'audit.

Riprodotto interrompendo le scritture dell'audit dopo l'UPDATE riuscito: ID
presente, journal ancora verified, anteprima FIC bloccata con
payment_recovery_data. In questa simulazione fallisce anche la scrittura
OUTCOME, quindi non si può assumere che l'ultimo esito riporti bound.

Correzione richiesta: rilettura dei dati persistiti e recupero idempotente
dell'audit parziale, senza rimuovere l'ID verificato o creare ordini nuovi.

## Cosa confermano e cosa non confermano i test

Eseguite tutte le suite presenti, inclusa automation --access-only:
1.654 controlli passati, di cui 96 della nuova guardia. Nessun test allegato
esegue l'intero flusso reale WordPress/addon/core con due webhook concorrenti.
Il controllo 'nessun secondo ordine/email' del nuovo test verifica una lookup
MemberOrder; non esegue il callback ufficiale completo. I 16 processi della
suite concorrenza verificano le prenotazioni delle fatture, non la nuova guardia.

Per questa review sono state aggiunte prove isolate in /tmp/fic-review-070:
- reproduce-review.php: token UTC, risposta incompleta, interleaving, audit.
- reproduce-core.php: funzione originale del core 3.8.9, invariata nella logica,
  eseguita in namespace isolato con dipendenze PMPro/WordPress simulate.

Risultati del secondo script:

| Caso | Risposta della funzione core | Ordini | Email simulate |
| --- | --- | --- | --- |
| Binding riuscito | Already processed, ordine 42 | 1 | 0 |
| HTTP 200 incompleto | Created new order 43 | 2 | 1 |
| Registro verified preesistente, ID vuoto | Created new order 43 | 2 | 1 |

Queste prove non sono un collaudo MySQL, non simulano tutta la rete PayPal e non
certificano la compatibilità con l'addon effettivamente installato sul sito.

## Altre osservazioni

- Buono il GET della sale per verificare importo, stato e abbonamento: il payload
  ricevuto da solo non viene considerato prova sufficiente per il binding.
- La guardia è attiva automaticamente per PayPal Sandbox all'installazione,
  anche se l'automazione FIC è disattivata; l'interruttore previsto è un filtro PHP.
  Meglio un'attivazione esplicita e uno stato visibile nel backend per il collaudo.
- L'ultimo esito in un'unica opzione non ricostruisce la cronologia: può essere
  sovrascritto e alcuni ritorni anticipati non aggiornano nulla. Servono eventi
  correlabili con ID webhook e stati prima/dopo, senza payload sensibili.
- PayPal documenta retry per risposte non 2xx, fino a 25 tentativi in 3 giorni.
  Il rinvio 503 è quindi fondato; non è documentata qui una garanzia che il
  destinatario rispetti esattamente Retry-After: 60.
- La correzione è solo Sandbox: nessuna garanzia implicita per il passaggio live.
- Il report rende troppo categorica la frase 'serve ACTIVATED prima di SALE'.
  Il core conferma che la creazione automatica della sottoscrizione da un ordine
  richiede success, ma richieste sovrapposte e una sottoscrizione già presente
  richiedono prove specifiche. I webhook storici non sono stati forniti.
- Un solo nuovo acquisto riuscito non chiude un bug intermittente: già 62 e 67
  erano riusciti prima della modifica. Occorre dimostrare il ramo bound e
  riprodurre in ambiente controllato ritardi, ripetizioni e concorrenza.

## Fonti verificate

- https://github.com/strangerstudios/paid-memberships-pro/blob/3.8.9/classes/class.memberorder.php
- https://github.com/strangerstudios/paid-memberships-pro/blob/3.8.9/includes/gateway-request-handlers.php
- https://github.com/strangerstudios/pmpro-paypal/blob/dev/pmpro-paypal.php
- https://developer.paypal.com/api/rest/webhooks/rest/

Versione core 3.8.9 indicata dal report, non rilevata sul server da questa review.
Versione addon installata ancora da verificare. Il report stesso distingue
l'addon sul sito dal sorgente GitHub 1.1.4.

SHA-256 ZIP esaminato: `2527ac5959ee24356941d06ef4f77cdc98c1c9ce2f477eb538e33765a72459c2`.
