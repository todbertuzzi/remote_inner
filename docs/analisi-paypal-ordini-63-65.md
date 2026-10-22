# Checkout 63 e 65 — primo incasso registrato su un altro ordine

Riscontri forniti dall'utente il 22 ottobre 2026 dopo l'installazione della
0.6.0 e la creazione del livello «TEST rinnovi giornalieri». Nessun accesso
remoto autenticato o modifica degli ordini eseguito durante questa analisi.

## Dati confermati

Entrambi i checkout risultano `success`, senza ID pagamento, con abbonamento
PayPal `ACTIVE`. Le letture in sezione 5, con date `Z` e `+00:00`, restituiscono
lo stesso unico pagamento `COMPLETED` nella finestra iniziale di due ore.

| Checkout | Abbonamento | Incasso | Totale | Data UTC | Ordine con incasso |
| --- | --- | --- | --- | --- | --- |
| 63 | I-PNNJ5SY6HSUN | 3NS08370WB225042Y | 1,00 EUR | 2026-10-22 10:39:12 | 64, visibile nello screenshot |
| 65 | I-4TDYA5T419FL | 55717247T10543359 | 1,00 EUR | 2026-10-22 10:43:18 | Presente nello screenshot; ID numerico non leggibile |

Finestre: 09:39:11–11:39:11 UTC e 09:43:18–11:43:18 UTC. Il risultato riguarda
queste finestre, non tutta la cronologia PayPal. Non dimostra un doppio addebito;
mostra invece checkout e riferimento dell'incasso su righe PMPro distinte.
L'utente riferisce documenti associati agli ordini 62 e 67. Numeri e ID FIC non
sono stati comunicati e non vengono inventati.

## Meccanismo compatibile con il codice ufficiale

L'handler di attivazione prova a leggere l'incasso iniziale e poi completa il
checkout anche quando non riesce a ricavarne l'ID. La notifica `SALE.COMPLETED`
passa al gestore dei pagamenti ricorrenti. [Addon PayPal](https://github.com/strangerstudios/pmpro-paypal/blob/dev/includes/webhook-handler.php).

Il gestore cerca prima l'ordine con quel preciso ID incasso, quindi un ordine
`pending` con ID vuoto; in assenza di entrambi crea un ordine. Un checkout già
`success` senza ID non viene riutilizzato da quel secondo controllo.
[Core PMPro](https://github.com/strangerstudios/paid-memberships-pro/blob/master/includes/gateway-request-handlers.php).

Questa sequenza spiega i dati osservati e perché il secondo ordine possa essere
trattato come rinnovo immediatamente dopo l'acquisto. Anche differenze di ordine
nell'arrivo delle notifiche possono influire. È una spiegazione compatibile con
il sorgente, **non la ricostruzione provata** dei webhook effettivamente ricevuti:
non disponiamo del loro ordine temporale né della risposta letta in attivazione
per questi due checkout. I riferimenti upstream letti non certificano la precisa
versione del core e dell'addon installati. Nessuna evidenza indica qui un errore
cron o una differenza di significato tra `Z` e `+00:00`.

## Correzione locale 0.6.1

[Guida operativa](../plugins/fatture-in-cloud-sync/COLLAUDO-0.6.1.md): analisi in
sola lettura e conferma separata del collegamento fiscale checkout → ordine
con incasso. Il primo pagamento viene verificato tramite elenco iniziale e
GET della singola sale; cliente, piano, importo, riferimenti e dati fiscali
vengono ricontrollati. Dati copiati dal checkout, mai dal profilo corrente.

Il vincolo canonico è persistente e inserito con `INSERT IGNORE`; non può essere
sostituito da una conferma concorrente. La copia fiscale può essere ripresa dopo
un'interruzione solo mediante conferma esplicita. Il checkout originale non può
più recuperare un ID pagamento. L'ordine destinatario usa la verifica PayPal e
le prenotazioni incasso già introdotte in 0.6.0 prima della fattura.

Restano entrambe le righe PMPro; nessun cambio di stato, cancellazione, trasferimento
di incasso, ripetizione di webhook, addebito o fattura durante il collegamento.
La creazione del documento /TEST avviene poi sul solo ordine con incasso, con il
normale flusso manuale. L'autorizzazione ai rinnovi può partire da quell'ordine
dopo la verifica del documento. La prevenzione delle righe duplicate nel gateway
e la rettifica dei report PMPro restano un intervento distinto, ancora da validare.
