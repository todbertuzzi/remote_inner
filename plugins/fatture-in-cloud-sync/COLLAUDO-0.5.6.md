# Fase 1 — affidabilità, versione 0.5.6

Preparata il **21 ottobre 2026**. Successivamente l'utente ha confermato
l'installazione e il confronto PayPal sull'ordine 56: il registro 0.5.6 delle
14:23 italiane conferma HTTP 200 e lo stesso incasso con entrambe le forme
delle date. Cron, nuovi ritentativi e recupero FIC restano da verificare.
Nessun addebito, rimborso,
documento FIC, invio email o invio SDI è stato effettuato durante lo sviluppo.

## Cosa cambia

- Quando manca un elenco utilizzabile nella risposta PayPal, il recupero in
  coda attende e riprova: massimo sei elaborazioni, con attese di 60, 120, 300,
  600 e 1800 secondi dopo la prima. Sono tempi minimi: dipendono dal cron.
  Liste con più pagine, incassi ambigui, rimborsi, importi/valute incoerenti e
  stati sconosciuti restano bloccati. Nessun dato incompleto autorizza una fattura.
- Nella sezione 5, **Confronta letture PayPal (sola lettura)** legge un ordine
  esistente tramite SELECT, poi l'abbonamento e due volte le transazioni.
  Le due letture usano la stessa finestra, un'ora prima/dopo la creazione
  dell'abbonamento, con date `Z` e `+00:00`. Funziona anche per l'ordine 56 già
  fatturato. Non usa il costruttore `MemberOrder`, non associa ID e non salva ordini.
- **Programma prova cron senza fatture** programma un evento dedicato dopo
  un minuto e registra il suo orario di esecuzione. Non richiama il worker FIC.
- Il recupero FIC mantiene il limite di cinque pagine da 100 documenti, ma
  ora rifiuta anche una singola corrispondenza se la scansione è incompleta.
  Tiene conto delle pagine successive dichiarate dall'API; pagine incoerenti,
  righe non valide e ID ripetuti richiedono un'altra verifica. Nessun esito
  negativo libera il journal o autorizza un nuovo POST.

Restano conservati configurazione, autorizzazione della coda, copie fiscali,
associazioni e marker precedenti. Non occorre riattivare la coda se non si
cambiano le impostazioni. Le novità dell'oggetto della 0.5.5 sono incluse.

## Primo collaudo: nessun nuovo acquisto

1. Installare l'intero ZIP **fatture-in-cloud-sync-0.5.6.zip** sostituendo la
   versione precedente. Verificare **0.5.6** nell'elenco Plugin. Non cambiare
   azienda FIC, token, conto, gateway o piani per questa prova.
2. In **Impostazioni → PMPro Fatture in Cloud → sezione 5**, attivare la
   diagnostica per 60 minuti. Se il registro da 1 MB è pieno, conservarne una
   copia e archiviarlo tramite l'accesso ai file prima di riattivarla.
3. Inserire **56** nel nuovo campo ID ordine della sezione 5 e premere
   **Confronta letture PayPal (sola lettura)**. Non usare i pulsanti di creazione
   o recupero dell'associazione della sezione 3 per questo test.
4. Atteso: due letture con stessi istanti e suffissi diversi, stato ordine,
   stato abbonamento e presenza ID incasso separati. Se il pagamento storico è
   ancora disponibile e invariato, dovrebbe comparire `4VX44692N0631661Y`,
   `841.80 EUR`, `COMPLETED`. Un risultato diverso è un dato da analizzare,
   non un motivo per rifare il pagamento. L'ordine 56 e l'associazione al
   documento **560063390** devono restare invariati.
5. Scaricare il registro diagnostico e disattivare la diagnostica. Il riepilogo
   contiene solo campi selezionati; non condividere esportazioni PayPal grezze
   o credenziali. Annotare anche versioni WordPress, PMPro e addon PayPal.

Il confronto usa il risultato già decodificato dal gateway: non attribuisce
un HTTP 200 a tale risultato. Il log passivo, se attivo, osserva separatamente
gli esiti HTTP effettivi con contesto `diagnostic_comparison`. OAuth può
rinnovare il token in cache tramite POST di autenticazione: nessuna chiamata
di addebito, rimborso, modifica o creazione dell'abbonamento è prevista.

Le letture sono successive, non simultanee. Se coincidono oggi, non dimostrano
la causa della risposta incompleta del 14 ottobre; se differiscono, vanno
confrontati tempi, struttura e risposte di trasporto. L'API ammette entrambi
i formati. Per una diagnosi storica conclusiva servono le osservazioni del
momento dell'anomalia o una nuova ricorrenza spontanea, senza provocare addebiti.

## Cron: avvio OVH documentato, versione PHP da allineare

La configurazione locale indica **SFTP OVH, porta 22**, percorso relativo
`www/wp-content/` e caricamento automatico disattivato. Sono presenti campi
di autenticazione, non riprodotti nella documentazione. Questo non prova
accesso SSH a una shell, accesso al Manager OVH o una connessione riuscita.
In questa sessione non sono stati letti il database, `wp-config.php` remoto,
la configurazione cron o i file del server tramite una sessione autenticata.

L'utente ha confermato il piano OVH **Performance** il 21 ottobre 2026.
Ha inizialmente trovato vuota la scheda **Cron**; successivamente ha confermato
di aver creato il cron OVH seguendo le istruzioni. Ha poi eseguito la prova
dedicata e riportato: scadenza **2026-10-21 13:37:37 UTC**, esecuzione
**13:38:25 UTC** (15:38:25 italiane), con **48 secondi** di ritardo sulla
scadenza. Il refresh è avvenuto, secondo l'utente, dopo circa venti minuti:
l'orario registrato precede quindi la riapertura riferita.
È confermata l'esecuzione dell'evento WordPress, ma non il suo avvio da OVH:
il valore di `DISABLE_WP_CRON` non è stato letto e altre visite/richieste
potrebbero aver attivato WP-Cron. Servono le righe di avvio/fine del log Cron
OVH per `www/wp-cron.php` intorno alle 13:38 UTC, controllando il fuso del log.

Successivamente l'utente ha fornito un estratto misto di log HTTP e Cron.
Le righe `## OVH ## START` e `## OVH ## END` documentano un avvio alle
**16:00:09.012800** e una fine alle **16:00:10.902167**, nel tempo riportato
dal registro, con **exitcode 0**. L'interprete è però
**`/usr/local/php8.5/bin/php`**, diverso da PHP 8.3 usato dal sito e indicato
nella configurazione proposta. Va modificato il campo Lingua del task
esistente in PHP 8.3 e verificato il successivo START.
Il codice 0 conferma la conclusione del processo, non l'esecuzione di tutti
i lavori applicativi. Questa partenza delle 16:00 non documenta l'origine
della prova delle 15:38 italiane. La chiamata HTTP a `wp-cron.php` delle
16:03:38 +0200, con user agent WordPress, è compatibile con un avvio HTTP
interno a WordPress e va distinta dal processo pianificato OVH.
Non vengono conservate le righe HTTP grezze, contenenti cookie di sessione.
L'utente ha poi ripetuto la prova e riportato scadenza **2026-10-21 14:10:47
UTC** ed esecuzione **14:22:50 UTC**, cioè **16:22:50 italiane**: ritardo
**12 minuti e 3 secondi**. Anche questo evento risulta eseguito, ma il messaggio
non indica il processo che lo ha avviato né la versione PHP. Non è stato
comunicato l'orario del refresh di questa seconda prova. L'allineamento del
task a PHP 8.3 resta da verificare nel nuovo START; non lo si deduce dal solo
«ho riprovato». Prossimo controllo: leggere le righe START/END OVH intorno
alle 16:22:50 italiane, verificando il fuso del registro. Non serve
riprogrammare subito un'altra prova per analizzare questo esito.
L'utente ha confermato **PHP 8.3** e, nella scheda ora denominata **I miei siti**,
la riga **Website-www**, cartella radice **`./www`**, con sette domini,
come quella associata a Inplaycetools. L'altra riga `./public-html` non è
quella interessata. L'utente ha verificato via FTP la presenza di `wp-cron.php`
in `www`. Restano da verificare eventuali altri scheduler esterni e il valore
di `DISABLE_WP_CRON`.
Non disabilitare il cron da visite prima di aver verificato il sostituto.

### Passaggio a cron-job.org — 22 ottobre 2026

L'utente, non ritenendo affidabile il comportamento osservato di OVH, ha
iniziato la configurazione di cron-job.org per richiamare con GET
`https://inplaycetools.com/wp-cron.php`, con frequenza proposta ogni due minuti.
Non è stata dimostrata un'assenza generale di esecuzioni OVH: resta valida
l'evidenza START/END delle 16:00 del 21 ottobre.

Gli header forniti per la richiesta del **22 ottobre, 07:04:08 GMT**
(09:04:08 italiane) riportano **PHP/8.3** e direttive no-cache, ma non
contengono il codice HTTP. Non provano da soli il completamento della coda.
La successiva prova del plugin ha scadenza **07:16:29 UTC** ed esecuzione
**07:18:02 UTC** (09:18:02 italiane): ritardo **93 secondi**, compatibile
con la frequenza proposta. L'esecuzione dell'evento è confermata dal messaggio
riferito dall'utente; origine esterna e regolarità non sono ancora certificate.

La nuova prova ha scadenza **07:36:35 UTC** ed esecuzione **07:40:21 UTC**:
ritardo **3 minuti e 46 secondi**. L'utente conferma **HTTP 200** su
cron-job.org con header Date **07:40:17 GMT**, quattro secondi prima
dell'esecuzione registrata dal plugin, e PHP 8.3. La corrispondenza è
coerente con l'avvio esterno, ma non ne dimostra l'esclusività. L'header Date
non misura da solo l'inizio o la durata della richiesta. L'utente ha poi
chiarito di aver impostato l'intervallo a **5 minuti**: il ritardo osservato
è compatibile con questa configurazione. Non era quindi necessariamente
prevista una chiamata alle 07:38 UTC; non emerge da questa prova un salto
di esecuzione. Per l'obiettivo iniziale, riportare l'intervallo a due minuti.

Il 22 ottobre l'utente ha riferito esito positivo anche della prova successiva
alle istruzioni per isolare lo scheduler e ha infine confermato completata
la configurazione: **cron-job.org ogni due minuti**, notifiche degli errori
abilitate, **`DISABLE_WP_CRON=true`** e **task OVH sospeso**. La verifica
operativa del cron è chiusa sulla base di questa conferma dell'utente;
l'assistente non ha modificato direttamente il sito o i pannelli dei servizi.
Non sono stati forniti nuovi timestamp o log della prova finale e non si
attribuisce retroattivamente l'intervallo di due minuti alla prova precedente.
Resta da monitorare la regolarità nel tempo, che un singolo test non dimostra.

### Se è hosting Web OVH condiviso

1. Manager OVH → **Web Cloud → Hosting → hosting del sito → Altro → Cron**.
   Controllare prima l'elenco delle operazioni esistenti, evitando doppioni.
2. La documentazione OVH prevede al massimo una partenza all'ora e non
   permette di scegliere il minuto. È indipendente dalle visite, ma non
   garantisce il completamento della coda in circa due minuti.
3. Per l'opzione oraria, verificare via SFTP la presenza di `wp-cron.php`
   nella cartella `www`, confermata dall'utente come radice del sito.
   Dopo questa verifica usare **`www/wp-cron.php`** nel campo **Ordine da
   eseguire**, **PHP 8.3** nel campo **Lingua**, attivazione abilitata e
   descrizione **WordPress — coda Inplaycetools**. Frequenza: ogni ora,
   tutti i giorni e tutti i mesi; conservare i log Cron. Non inserire un URL
   o un comando curl nel campo percorso del cron OVH.
   La presenza del file e la creazione del cron sono ora confermate dall'utente;
   i parametri salvati non sono stati letti direttamente dall'assistente.
   Mantenere per ora la configurazione del cron da visite:
   questa prima pianificazione oraria non equivale allo scheduler ogni due minuti.
4. Per l'obiettivo di 1–2 minuti, usare uno scheduler HTTPS disponibile su
   un servizio/server sempre acceso, oppure un cron di sistema se il piano
   lo permette. Occorre individuare il servizio effettivo prima di attivarlo.

### Se è disponibile uno scheduler HTTPS o un server con cron di sistema

Configurazione da applicare al servizio scelto:

| Campo | Valore |
| --- | --- |
| Metodo | GET |
| URL WordPress da verificare | `https://inplaycetools.com/wp-cron.php` |
| Frequenza | Ogni 2 minuti |
| TLS | Verifica certificato attiva |
| Redirect | Non seguire redirect inattesi a login o altri host |
| Monitoraggio | Conservare orario, HTTP status ed errori; segnalare fallimenti ripetuti |

Esempio **non eseguito**, solo dopo aver verificato `command -v curl` sul
server che ospita lo scheduler:

```cron
*/2 * * * * curl --fail --silent --show-error --connect-timeout 10 --max-time 55 --output /dev/null https://inplaycetools.com/wp-cron.php
```

L'URL va verificato rispetto alla radice WordPress reale. L'esecuzione di
`wp-cron.php` può elaborare **tutti i lavori scaduti di WordPress**, inclusi
quelli FIC già autorizzati: soltanto l'evento diagnostico è privo di fatture.
Non creare endpoint PHP personalizzati pubblici e non passare token FIC/PayPal
allo scheduler. Se si usa WP-CLI invece di `wp-cron.php`, va verificato il
contesto `DOING_CRON`: il worker attuale lo richiede per l'esecuzione anonima.

### Verifica senza visite

1. Dopo aver attivato il servizio, in sezione 5 premere **Programma prova cron
   senza fatture**. Annotare la scadenza UTC mostrata.
2. Chiudere tutte le pagine del sito. Attendere almeno due intervalli dello
   scheduler: almeno quattro minuti per la frequenza di due minuti; oltre
   due ore per quella oraria.
3. Leggere i log del servizio prima di riaprire il sito, poi annotare l'ora
   della prima riapertura. Il pannello deve riportare **Eseguita UTC** con
   orario precedente alla riapertura e coerente con i log. Il 21 ottobre
   2026 Europe/Rome è UTC+2.
4. HTTP 200 da solo non prova l'elaborazione. Se l'evento parte soltanto
   alla riapertura, l'indipendenza dalle visite non è dimostrata; anche
   traffico di terzi può attivare WP-Cron. Solo dopo la prima verifica,
   configurare una singola definizione `define('DISABLE_WP_CRON', true);`
   nel `wp-config.php` effettivo prima del caricamento di WordPress e ripetere
   la prova, controllando i log esterni. In caso di problemi, ripristinare
   il precedente valore e verificare il servizio.
5. Ripetere la prova in un secondo intervallo e monitorare gli errori dello
   scheduler: un singolo evento riuscito non dimostra la regolarità futura.

## Limite di recupero FIC e passi ancora aperti

Il limite di 500 documenti non è stato eliminato aumentando indefinitamente
le chiamate. Se la quinta pagina è piena e non certifica la fine dell'elenco,
il plugin restituisce `recovery_scan_limit` anche se ha trovato un marker.
I metadati di paginazione permettono invece di riconoscere una quinta pagina
finale. Una scansione senza corrispondenze conserva il blocco.

Per un tentativo incerto oltre il limite: annotare ordine e marker del
journal, cercare in FIC il riferimento esatto nelle note o nel vecchio oggetto,
confrontare azienda, totale e duplicati. Non cancellare lock/journal per
forzare la creazione. L'associazione assistita per documenti fuori dalla
scansione e una ricerca paginata riprendibile sono ancora da implementare;
richiedono controllo delle pagine che cambiano durante la scansione e dei
limiti API. Non è stata introdotta una ricerca per descrizione del piano.

Nota del 22 ottobre: la successiva [0.6.0](COLLAUDO-0.6.0.md), preparata solo
localmente, introduce il vincolo SQL per incasso e la verifica degli ID già
presenti. I limiti seguenti descrivono la 0.5.6 installata sul sito.

Prima dei rinnovi restano inoltre: collaudo reale della concorrenza su
WordPress/MySQL, monitoraggio dei lavori persi/interrotti oltre gli ultimi
20 mostrati, verifica degli incassi anche quando l'ID è già presente e una
chiave persistente univoca per **incasso**, oltre al lock attuale per ordine.
I test simulati non provano una garanzia globale fra processi MySQL reali.
Questa release non gestisce rinnovi, rimborsi, note di credito, area fatture,
email di disponibilità o invio SDI; le successive fasi restano ordinate come
richiesto. L'inventario email esistente resta il riferimento per la fase 4.

## Registro di questa fase

| Controllo | Esito |
| --- | --- |
| Acquisti Gold/Professional, documenti 8/TEST e 9/TEST, 21 ottobre | Confermati dall'utente prima di questa sessione; non ripetuti |
| ID PMPro/FIC dei due acquisti | Non comunicati |
| Installazione 0.5.5 | Non confermata |
| Preparazione e test locali 0.5.6 | Completati; dettagli nel README |
| Installazione 0.5.6 | Confermata dall'utente; diagnostica 0.5.6 in esecuzione nel registro |
| Conservazione completa impostazioni e associazione FIC | Non verificabile dal solo registro PayPal |
| Confronto di sola lettura sull'ordine 56 | Confermato il 21 ottobre, 14:23: entrambe le forme restituiscono HTTP 200, incasso 4VX44692N0631661Y, COMPLETED, 841,80 EUR |
| Stato locale ordine 56 | success, ID incasso presente; abbonamento remoto ACTIVE |
| Nuovi ritentativi e recupero FIC con scansione incompleta | Test locali superati; non collaudati sul sito da questo confronto |
| Disattivazione diagnostica | Non confermata; scadenza dell'attivazione registrata alle 15:23:15 italiane, salvo riattivazioni |
| Configurazione cron esterno | Passaggio a cron-job.org completato e confermato dall'utente il 22 ottobre; regolarità nel tempo da monitorare |
| Prova cron del plugin | Eseguita il 21 ottobre alle 13:38:25 UTC, scadenza 13:37:37 UTC; esito riferito dall'utente dopo circa venti minuti |
| Seconda prova cron del plugin | Eseguita il 21 ottobre alle 14:22:50 UTC, scadenza 14:10:47 UTC; ritardo 12 minuti e 3 secondi, origine e versione PHP da correlare ai log OVH |
| Prove dopo configurazione cron-job.org | 22 ottobre: 07:16:29 → 07:18:02 UTC; poi 07:36:35 → 07:40:21 UTC con intervallo impostato a cinque minuti e HTTP 200, Date 07:40:17 GMT |
| Avvio esclusivo da cron-job.org | Configurazione e prova positiva confermate dall'utente: DISABLE_WP_CRON=true, OVH sospeso, intervallo finale due minuti e notifiche attive; nuovi timestamp non forniti |
| Cron nel Manager OVH Performance | Inizialmente vuoto; utente conferma creazione dopo le istruzioni per la frequenza oraria |
| PHP e radice sito | PHP 8.3; Website-www → ./www, sette domini, confermati dall'utente |
| PHP del task OVH | Il primo START fornito usa PHP 8.5; richiesto allineamento a PHP 8.3, non ancora confermato |
| File e percorso cron | Presenza di www/wp-cron.php confermata dall'utente tramite FTP |
| Causa storica della risposta PayPal incompleta | Non accertata |

## Riferimenti ufficiali consultati

- [PayPal: transazioni dell'abbonamento e formati data](https://developer.paypal.com/api/subscriptions/v1/subscriptions-transactions).
- [Gateway PMPro PayPal: configurazione e webhook](https://www.paidmembershipspro.com/add-ons/pmpro-paypal/).
- [Client API PMPro, ramo dev consultato](https://github.com/strangerstudios/pmpro-paypal/blob/dev/classes/class-paypal-api.php),
  confrontato con il tag 1.1.4: i due metodi usati effettuano GET; il rinnovo
  del token usa OAuth. Il ramo dev non identifica la versione sul sito.
- [Handler PMPro PayPal consultato](https://github.com/strangerstudios/pmpro-paypal/blob/dev/includes/webhook-handler.php):
  l'attivazione può ancora completare il checkout senza ID incasso;
  `PAYMENT.SALE.COMPLETED` delega al gestore ricorrente PMPro.
- [WordPress: evento singolo](https://developer.wordpress.org/reference/functions/wp_schedule_single_event/)
  e [scheduler di sistema](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).
- [OVH: configurazione e limiti del cron Web Hosting](https://docs.ovhcloud.com/it/guides/web-cloud/web-hosting/cron-tasks).
- [FIC OpenAPI: elenco documenti e paginazione](https://github.com/fattureincloud/openapi-fattureincloud/blob/master/openapi.yaml).
