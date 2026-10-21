# PayPal Sandbox: ordine 56 e documento FIC — 14 ottobre 2026

Fonti: registro `fic-paypal-api.php` fornito dall'utente (20 righe, diagnostica
0.5.2) e screenshot della coda delle 13:25:41. Il registro è stato letto come
testo, senza eseguire il file PHP. Nessuna chiamata al sito, a PayPal o a FIC.

## Esito

- Ordine PMPro **56**, importo dell'incasso verificato **841,80 EUR**.
- Abbonamento **I-GLLXG97MF516**.
- Incasso **4VX44692N0631661Y**, stato remoto **COMPLETED**.
- Screenshot: **Documento associato**, documento FIC **560063390**, **2 tentativi**,
  con indicazione dell'ID incasso recuperato.
- L'utente riferisce di aver atteso e aggiornato la pagina. Non riferisce azioni
  di recupero, creazione manuale o elaborazione manuale per questo nuovo ordine.
- Successivamente l'utente ha confermato la presenza del documento anche in
  Fatture in Cloud. Resta da confermare specificamente il controllo di dati
  cliente, importi, conto di prova e assenza di duplicati.

## Sequenza accertata

Orari convertiti da UTC a **Europe/Rome (UTC+2)**.

| Ora | Riga del registro | Evidenza |
| --- | --- | --- |
| 13:21:01 | 3–6 | Evento CREATED ricevuto, firma valida, evento non gestito, risposta HTTP 200 |
| 13:21:20 | 7–10 | SALE.COMPLETED ricevuto, firma valida; risultato local_reference_not_found, risposta HTTP 200 |
| 13:21:21 | 11–12 | ACTIVATED ricevuto, firma valida |
| 13:21:22.266 | 13 | GET incassi durante l'attivazione: HTTP 200, JSON leggibile, transactions_array=false |
| 13:21:23.557 | 15 | Ordine 56 completato: status=success, payment_id_present=false |
| 13:22:43.015 | 18 | Recupero in coda: HTTP 200, una transazione COMPLETED, 841,80 EUR, ID 4VX44692N0631661Y |
| 13:22:43.935 | 20 | Seconda lettura prima dell'associazione: stesso incasso, importo, valuta, stato e data |
| 13:25:41 | Screenshot | Documento /TEST 560063390 associato all'ordine 56, due tentativi |

La transazione riporta la data del pagamento **13:21:05**; il recupero non ha
creato un nuovo incasso. Le due letture prima dell'associazione sono previste
dai controlli del recupero, non rappresentano due addebiti o due documenti.
La prima risposta agli incassi e quella valida successiva distano **80,749 s**.
Il registro non contiene i motivi dei singoli tentativi della coda: non si può
attribuire con certezza il primo tentativo a quella risposta incompleta.

## Conclusioni e limiti

È documentato il completamento del checkout senza un ID incasso, perché la
lettura iniziale non offriva un elenco utilizzabile. Non è una lista vuota
documentata: `transactions_array=false` significa campo assente, nullo o di
altro tipo; la 0.5.2 non registra queste tre forme separatamente. HTTP 200
non certifica la presenza o completezza dei dati richiesti.

Sono documentati anche l'errore di riferimento locale del webhook di pagamento
prima dell'attivazione e il successivo recupero verificato. Le firme sono valide;
per le richieste registrate non risultano errori HTTP 401/403/429/5xx o di rete.
Per la lettura degli incassi, i riepiloghi di trasporto e dopo i filtri coincidono:
non ci sono indizi di una modifica dei campi osservati da parte di un filtro.
I riepiloghi non consentono di escludere qualsiasi modifica al body completo.

La disponibilità differita o una risposta temporaneamente incompleta di PayPal
sono ipotesi compatibili. Non è dimostrata la causa interna della prima risposta.
Anche le richieste differiscono nella rappresentazione delle date (`+00:00`
nel gateway, `Z` nel recupero), pur selezionando gli stessi istanti e lo stesso
intervallo. La documentazione PayPal ammette entrambe le forme: non c'è prova
che questa differenza sia un errore. Per isolarla servirebbe un confronto in
sola lettura delle due forme a parità di abbonamento/intervallo e disponibilità.

L'esito conferma il percorso coda → recupero verificato → documento associato
per un nuovo checkout, con le azioni descritte dall'utente. Un refresh può
contribuire ad attivare WP-Cron; questo test non dimostra che il cron funzioni
con la stessa puntualità in assenza di traffico.

## Correzione della sola diagnostica — 0.5.3

Nel riesame del codice PMPro è emerso che `new MemberOrder(id)` non è una lettura
pura: il costruttore può creare una sottoscrizione locale e sincronizzarla con
il gateway se manca. Il logger 0.5.2 usava quel costruttore nel riepilogo finale.
La 0.5.3 lo sostituisce con una SELECT preparata di tre campi (id, status,
payment_transaction_id), senza costruttori o hook di dominio. Questa modifica
non altera le prove del log sull'assenza dell'elenco incassi: quella risposta
precede il riepilogo diagnostico finale.

Inoltre il vecchio contesto queue_recovery riconosceva qualunque frame della
classe automazione, inclusi gli hook di salvataggio dell'ordine. La lettura della
sottoscrizione alle 13:21:23 (riga 14), avvenuta nello stesso webhook, non basta
da sola a provare l'esecuzione del worker. La 0.5.3 distingue hook, worker e
recupero, dando priorità al percorso di attivazione quando presente. Il recupero
degli incassi alle 13:22:43 è coerente con la doppia verifica del worker.

La diagnostica registra ora anche struttura JSON, nomi di chiavi scelti da un
elenco fisso e tipo del campo transactions. Non registra valori arbitrari,
messaggi di errore grezzi, credenziali o dati cliente. Recupero e fatturazione
restano invariati. Il test impedisce esplicitamente di chiamare il costruttore
MemberOrder e controlla il carattere selettivo e preparato della lettura SQL.

Riferimenti primari:

- [PayPal: elenco transazioni di un abbonamento e formati delle date](https://developer.paypal.com/api/subscriptions/v1/subscriptions-transactions)
- [WordPress: WP-Cron e traffico delle pagine](https://developer.wordpress.org/plugins/cron/)
- [PMPro: classe MemberOrder](https://github.com/strangerstudios/paid-memberships-pro/blob/master/classes/class.memberorder.php)
