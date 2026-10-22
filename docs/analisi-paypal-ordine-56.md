# PayPal Sandbox: ordine 56 e documento FIC — 14 ottobre 2026

## Confronto 0.5.6 sul sito — 21 ottobre, esito confermato

L'utente conferma installazione e confronto sull'ordine 56 e ha fornito
`fic-paypal-api (1).php`. Il file è stato letto esclusivamente come testo:
25 righe, con protezione PHP alla riga 1, storico 0.5.2 alle righe 2–20
e cinque nuove registrazioni 0.5.6 alle righe 21–25. Nessuna chiamata remota
è stata eseguita dall'assistente durante questa analisi.

Orari del 21 ottobre in **Europe/Rome (UTC+2)**:

| Ora | Riga | Esito |
| --- | --- | --- |
| 14:23:15 | 21 | Diagnostica 0.5.6 attivata per 60 minuti |
| 14:23:27.015 | 22 | GET abbonamento: HTTP 200, `ACTIVE`, creazione 14 ottobre 11:21:04 UTC |
| 14:23:28.007 | 23 | GET transazioni con date `Z`: HTTP 200, elenco di un incasso `COMPLETED` |
| 14:23:28.896 | 24 | GET transazioni con date `+00:00`: HTTP 200, stesso incasso |
| 14:23:28.897 | 25 | Riepilogo: ordine 56 `success`, ID pagamento presente, abbonamento `ACTIVE` |

Le due richieste selezionano gli stessi istanti: **14 ottobre 2026,
10:21:04–12:21:04 UTC**. Entrambe restituiscono l'incasso
**4VX44692N0631661Y**, **841,80 EUR**, `COMPLETED`, data
**2026-10-14T11:21:05.000Z**. Le letture durano rispettivamente 992 e 888 ms.
I riepiloghi selezionati della risposta di trasporto e dopo i filtri coincidono;
non risultano errori HTTP o di rete in queste tre letture.

È confermata l'esecuzione della diagnostica 0.5.6 sul sito e il suo confronto
in sola lettura. In questa prova entrambe le forme delle date funzionano:
non emerge una differenza che spieghi l'anomalia storica. Non si può però
ricostruire la struttura della risposta del 14 ottobre, né dimostrare un
ritardo interno PayPal dai risultati ottenuti una settimana dopo.

Il riepilogo attuale certifica la presenza dell'ID locale, non ne riporta il
valore. Il log PayPal non rilegge il documento FIC 560063390 e non prova una
nuova creazione, la conservazione di tutte le impostazioni o la regolarità
del cron. Restano da collaudare i nuovi ritentativi su un'anomalia effettiva,
il limite della ricerca FIC e lo scheduler indipendente dalle visite.
Disattivazione della diagnostica non confermata dall'utente; l'attivazione
registrata scade comunque alle **15:23:15 italiane**, salvo riattivazioni.

## Preparazione del 21 ottobre — fase 1, versione 0.5.6

È stato preparato un confronto amministrativo in sola lettura sull'ordine
esistente: SELECT dei riferimenti senza `MemberOrder`, GET abbonamento e due
GET transazioni con la stessa finestra nelle forme `Z` e `+00:00`.
Al momento della preparazione non era stato eseguito sull'ordine remoto 56;
il successivo esito è registrato sopra. Il confronto non modifica l'associazione
al documento 560063390 e non dimostra retroattivamente la causa della risposta
del 14 ottobre; eventuali differenze fra letture successive richiedono analisi.

La coda ora ritenta entro il budget esistente anche il caso specifico di
elenco PayPal assente/nullo/non utilizzabile. Prima veniva bloccato subito,
mentre un elenco vuoto valido era già ritentato. Sono preservati i blocchi
per dati ambigui e gli esiti FIC incerti. Aggiunti una prova cron innocua e il
blocco dell'associazione dopo una ricerca FIC incompleta al limite di 500.

L'utente ha confermato il piano OVH **Performance**, PHP 8.3, la presenza di
`www/wp-cron.php` via FTP e la creazione del cron OVH dopo le istruzioni per
la frequenza oraria. Il pannello era inizialmente vuoto. L'utente ha poi
riportato l'esito della prova: scadenza **13:37:37 UTC**, esecuzione
**13:38:25 UTC** del 21 ottobre, osservata con refresh dopo circa venti minuti.
L'evento è stato eseguito 48 secondi dopo la scadenza; per attribuirne l'avvio
a OVH anziché ad altre richieste del sito serve una corrispondenza nei log Cron.
Il successivo estratto fornito documenta START OVH alle 16:00:09 e END alle
16:00:10, exitcode 0, ma con **PHP 8.5** anziché PHP 8.3. È provato un avvio
OVH distinto dalla prova delle 15:38 italiane. Richiesti allineamento della
versione PHP del task e confronto di una nuova prova con il successivo avvio.
Le righe HTTP grezze, contenenti cookie, non sono state copiate nel repository.
L'utente ha poi ripetuto la prova cron: scadenza **14:10:47 UTC**, esecuzione
**14:22:50 UTC** del 21 ottobre, ritardo **12 minuti e 3 secondi**.
L'esecuzione è confermata dal messaggio riferito; origine dell'avvio e
allineamento a PHP 8.3 richiedono ancora il confronto con START/END OVH.
Installazione e confronto 0.5.6 sono ora confermati come descritto sopra;
lo scheduler resta da verificare sul sito.
Procedura e registro: [collaudo fase 1](../plugins/fatture-in-cloud-sync/COLLAUDO-0.5.6.md).

## Evidenze storiche dell'ordine 56

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
