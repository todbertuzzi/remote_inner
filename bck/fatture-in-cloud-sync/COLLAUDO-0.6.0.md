# Collaudo 0.6.0 — rinnovi PayPal Sandbox

Aggiornamento del 22 ottobre 2026: **installazione 0.6.0 e piano dedicato
confermati dall’utente**. Documenti associati agli ordini 62 e 67; checkout
63 e 65 con incasso su un altro ordine: seguire [COLLAUDO-0.6.1.md](COLLAUDO-0.6.1.md).
Non ripetere i passaggi 1–2 già completati. I rinnovi restano da collaudare. Non ripetere gli acquisti
Gold/Professional già verificati né creare altri documenti sugli ordini associati.

## 1. Installazione

1. Conservare il backup del database e lo ZIP 0.5.6.
2. WordPress → Plugin → Aggiungi plugin → Carica plugin → selezionare
   `fatture-in-cloud-sync-0.6.0.zip` e sostituire la versione esistente.
3. Aprire Impostazioni → PMPro Fatture in Cloud. Verificare che azienda di
   prova, IVA, conto e automazione precedente siano conservati. Non cambiare
   cron-job.org, `DISABLE_WP_CRON=true` o il task OVH già sospeso.
4. La sezione 4 deve mostrare **Rinnovi di un abbonamento Sandbox**. Nessun
   abbonamento è automaticamente autorizzato dall'aggiornamento.

Esito da comunicare: versione installata, presenza della nuova sezione,
eventuali errori visualizzati. Non inviare token, cookie o log grezzi.

## 2. Piano dedicato e primo acquisto

1. In PMPro creare un **nuovo livello** chiamato `TEST rinnovi giornalieri`.
   Lasciare invariati Professional, Gold, relativi prezzi e abbonamenti.
   Non aggiungere il piano di prova ai menu o ai percorsi commerciali.
2. Usare gateway PayPal **Sandbox**, valuta EUR, pagamento iniziale **1,00 €**,
   pagamento ricorrente **1,00 € ogni 1 giorno**, senza trial. Conservare le
   impostazioni fiscali esistenti e annotare il **totale effettivo** del checkout:
   non assumere che il prezzo configurato equivalga al totale IVA inclusa.
3. Se desiderato, aggiungere la descrizione del nuovo livello nelle impostazioni
   FIC **prima dell'acquisto**. Cambiare le descrizioni invalida lo scope della
   coda: in tal caso ricaricare i conti e riattivare l'automazione, poi acquistare.
4. Completare un checkout con un utente dedicato e un acquirente PayPal Sandbox.
   Usare i dati fiscali di collaudo previsti per l'azienda FIC di prova.
5. Attendere la coda e verificare il documento iniziale /TEST. Registrare ID
   ordine, ID incasso, ID abbonamento, ID/numero documento e totale. Non sono
   stati assegnati o inventati ID in questa guida.

Il periodo breve documentato da PayPal è **giornaliero**, non ogni due minuti:
il cron elabora i lavori, non anticipa i pagamenti. Controllare la prossima
scadenza in PayPal Sandbox/PMPro. Il sorgente dell'addon esaminato usa
`total_cycles=0`: non fare affidamento su un limite locale per fermare gli
addebiti di prova; al termine annullare l'abbonamento Sandbox dedicato.

Se il primo documento resta in attesa per `Lettura incasso PayPal...`, annotare
solo il codice HTTP e il messaggio del plugin. La 0.6.0 verifica via GET anche
gli incassi con ID già presente: il vecchio successo del checkout non prova
la disponibilità di questa nuova lettura. Non cancellare lock o registri.

## 3. Autorizzazione e rinnovo riuscito

1. Controllare in FIC i dati fiscali del primo documento /TEST.
2. Sezione 4 → **ID ordine iniziale**: inserire l'ID del nuovo checkout.
   Spuntare la conferma di abbonamento di prova e dati fiscali validi anche per
   i rinnovi. Premere **Autorizza rinnovi di questo abbonamento**.
3. Atteso: messaggio con ID abbonamento, ordine sorgente e data UTC di inizio.
   Le autorizzazioni sono immutabili in questa versione: non premere di nuovo
   per modificarle. **Controlla autorizzazione** consente la consultazione.
4. Attendere il rinnovo giornaliero. Non usare chiamate di cattura per accelerarlo.
5. Atteso: nuovo ordine PMPro `success`, stesso abbonamento ma **diverso ID
   incasso**, riga **Rinnovo** in coda e un nuovo documento /TEST del totale
   effettivamente pagato. Dati fiscali dall'ordine iniziale confermato; copie
   e documenti precedenti invariati. Data di pagamento derivata da PayPal.

La fattura è preparata solo per incassi avvenuti dopo l'autorizzazione. Il
ritardo della coda dipende da scheduler, API e ritentativi; non è garantito
un completamento entro due minuti. L'ID incasso mancante in un rinnovo non
viene cercato nella finestra del primo acquisto.

## 4. Duplicati, ritardi e fallimenti

| Caso | Prova e risultato atteso |
| --- | --- |
| Notifica ripetuta | Dal registro eventi PayPal Sandbox usare la funzione di reinvio dello **stesso evento reale** `PAYMENT.SALE.COMPLETED`, se disponibile. Stesso incasso e nessun documento aggiuntivo. Un evento fittizio del simulatore non prova un incasso reale. |
| Evento tardivo | Su staging con un abbonamento di prova distinto, rinviare l'elaborazione di un evento reale e poi reinviarlo. Non disabilitare l'endpoint condiviso del sito. Un incasso successivo all'autorizzazione resta ammissibile; uno precedente viene bloccato. Conservare orari pagamento/ricezione/elaborazione. |
| Ordine pagato non acquisito | Se PMPro contiene già il rinnovo `success`, usare **Acquisisci un rinnovo già pagato** con il suo ID. Il worker verifica data, incasso e autorizzazione; questo comando non crea ordini e non modifica pagamenti. |
| Doppio ordine per stesso incasso | Entrambi vengono fermati se l'ambiguità è presente prima dell'invio. Se uno ha già creato un documento, l'altro non può acquisire l'incasso. Verificare gli ordini PMPro senza cancellare prenotazioni. |
| Pagamento fallito/pending | Nessuna fattura. Se il gateway registra in seguito un incasso riuscito, viene elaborato quel pagamento. Non falsificare lo stato in PMPro per simulare successo. Il caso remoto fallito richiede un evento Sandbox reale/staging; è per ora coperto dai test locali. |
| Esito FIC incerto | Stato **Da verificare**, nessun nuovo POST automatico. Usare **Verifica esito remoto** nella sezione 3; una ricerca negativa non sblocca la creazione. |
| Dati fiscali variati | Il profilo corrente non cambia la copia dell'abbonamento. Usare **Sospendi fatturazione rinnovi** e pianificare una revisione esplicita: questa release non modifica né versiona nuove autorizzazioni sullo stesso abbonamento. |

Le prove di ritardo, fallimento e concorrenza non vanno dichiarate riuscite
sul server in base ai soli test simulati. Non modificare gateway, webhook
comune o piani commerciali per indurre un errore. Il test sul server di due
worker concorrenti e indice univoco MySQL resta necessario prima della produzione.

## 5. Fine prova e problemi

- Sospendere la fatturazione del solo abbonamento di prova usando l'ID di un
  suo ordine. Questa operazione **non cancella gli addebiti PayPal**.
- Annullare separatamente il solo abbonamento Sandbox dedicato tramite il
  normale pannello di gestione. Nessun rimborso o annullamento è eseguito dal
  plugin FIC. Le conseguenze sull'accesso sono gestite dal gateway/PMPro.
- Non cancellare le opzioni `pmpro_fic_payment_*`, le autorizzazioni, le copie
  fiscali o i journal per forzare un nuovo invio. Un tentativo interrotto prima
  della scrittura del journal può richiedere verifica tecnica della prenotazione.
- Cambi di azienda, conto, credenziali o descrizioni invalidano la configurazione
  autorizzata; questa versione non migra tali autorizzazioni. Un ripristino alla
  0.5.6 richiede prima sospendere l'automazione: quella versione non comprende
  i rinnovi né le nuove prenotazioni SQL. Conservare i dati per la riconciliazione.

## Verifiche locali e registro remoto

PHP CLI 8.4.1, **1.351 controlli**: 91 collegamento, 182 documenti manuali,
68 checkout, 170 recupero PayPal, 200 automazione, 8 accessi, 177 recupero in
coda, 176 diagnostica, 223 rinnovi/incassi, 56 concorrenza. Due gruppi di otto
processi PHP operano sullo stesso database SQLite; un solo permesso per incasso.
Le query di prenotazione usano una vera tabella con indice UNIQUE, traducendo
solo `INSERT IGNORE` in `INSERT OR IGNORE`. Nessun MySQL locale disponibile.
WordPress, cron e HTTP restano simulati. Il server riferito dall'utente usa PHP 8.3.

| Controllo remoto 0.6.0 | Esito |
| --- | --- |
| Installazione e conservazione impostazioni | Installazione confermata; nessuna perdita segnalata |
| Accesso in lettura alla sale con le credenziali dell'app Sandbox | Compatibile con i documenti riusciti 62 e 67; nessuna traccia HTTP specifica fornita |
| Primo acquisto sul piano dedicato e documento /TEST | Ordini 62 e 67 associati; 63 e 65 richiedono riconciliazione |
| Autorizzazione dell'abbonamento di prova | Da eseguire |
| Rinnovo riuscito e documento distinto | Da eseguire |
| Notifica ripetuta, tardiva e pagamento fallito | Da eseguire |
| Concorrenza WordPress/MySQL e cache persistente | Da eseguire su staging |
| Cron esterno 0.5.6 senza visite | Già confermato dall'utente il 22 ottobre; non ripetuto qui |

## Riferimenti verificati

- [PMPro: hook pagamento ricorrente](https://www.paidmembershipspro.com/hook/pmpro_subscription_payment_completed/).
  Il sorgente [gateway-request-handlers.php](https://github.com/strangerstudios/paid-memberships-pro/blob/master/includes/gateway-request-handlers.php)
  letto il 22 ottobre salva l'ordine prima dell'hook; il testo descrittivo del
  catalogo hook non è sufficiente a determinare quell'ordine di esecuzione.
- [Addon PayPal: webhook](https://github.com/strangerstudios/pmpro-paypal/blob/dev/includes/webhook-handler.php)
  e [gateway/piani](https://github.com/strangerstudios/pmpro-paypal/blob/dev/classes/class-pmprogateway-paypal.php).
  Sono riferimenti ufficiali letti, non attestazioni della versione installata.
- [PayPal: dettaglio sale](https://developer.paypal.com/api/deprecated/payments/v1/sale-get).
  Endpoint documentato come deprecato, usato solo in GET per la risorsa sale
  degli abbonamenti; nessuna conversione automatica dell'ID in una capture v2.
- [PayPal: frequenze dei piani](https://developer.paypal.com/api/subscriptions/v1/plans-create).
- [WordPress: sorgente add_option](https://developer.wordpress.org/reference/functions/add_option/).
- [FIC: creazione](https://developers.fattureincloud.it/docs/guides/invoice-creation/)
  e [calcolo totali](https://developers.fattureincloud.it/docs/guides/invoice-totals/).
  Il payload non elettronico e i controlli dei totali della 0.5.6 sono mantenuti.
