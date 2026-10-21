# PMPro → Fatture in Cloud: collaudo con ordini PayPal Sandbox

La versione 0.5.5 comprende la verifica del collegamento, la simulazione locale
e la creazione **manuale** di un documento da un ordine PayPal Sandbox pagato.
Raccoglie inoltre i dati fiscali nei checkout a pagamento e ne conserva una
copia per ordine, con separazione tra privati e aziende/professionisti.
È disponibile anche un recupero manuale verificato dell'ID del primo incasso
per un ordine del nuovo gateway PayPal Sandbox già completato con ID mancante.
La 0.4.3 ha corretto il confronto del totale testuale PMPro durante il salvataggio
dell'ID e distingue errori SQL, mancato aggiornamento e rilettura incoerente.
La 0.5.0 introduce la **coda automatica opt-in** per i nuovi primi acquisti di
abbonamenti PayPal Sandbox, con copie fiscali, totali verificati, monitoraggio
e ritentativi limitati. L'automazione è disattivata finché non viene configurata
esplicitamente nella sezione 4.
La 0.5.1 aggiunge una seconda opzione esplicita per verificare e recuperare via
API PayPal l'ID mancante del primo incasso di un ordine già pagato. Il recupero
automatico parte disattivato, anche aggiornando dalla 0.5.0.
La 0.5.2 aggiunge nella sezione 5 una **diagnostica passiva opt-in**, limitata a
60 minuti e alle API del nuovo gateway PayPal Sandbox. Permette di confrontare
la lettura degli incassi durante l'attivazione con il successivo recupero in
coda, senza cambiare richieste, risposte, stato ordini o comportamento del gateway.
La 0.5.3 corregge la lettura diagnostica dello stato ordine per evitare il
costruttore PMPro (che può sincronizzare sottoscrizioni), distingue gli hook di
salvataggio dal worker e registra la struttura della risposta incompleta.
La 0.5.4 aggiunge **descrizioni complete configurabili per ciascun piano PMPro**,
con lo stesso testo nel flusso manuale e nella coda automatica.
La 0.5.5 usa la descrizione del piano anche come **oggetto nell'elenco fatture
FIC**: il riferimento tecnico resta nelle note e nel registro locale per il
recupero degli invii incerti.

## Collaudo Professional e Gold — 21 ottobre 2026

L'utente ha effettuato due nuovi acquisti Sandbox, uno Gold e uno Professional.
In entrambi i casi, dopo circa due minuti dal completamento, la coda ha mostrato
**Documento associato — Documento /TEST creato e associato all'ordine**. Gli
identificativi FIC associati risultano corretti e l'utente ha controllato tutti
i campi in Fatture in Cloud, confermandone la correttezza.

Nello screenshot sono visibili i documenti **8/TEST** e **9/TEST**, del 21 ottobre,
entrambi saldati. Non sono stati comunicati gli ID numerici PMPro e FIC dei due
nuovi test, né l'abbinamento esplicito di ciascun numero al piano. I valori
visualizzati sono **701,50 € + IVA** e **457,50 € + IVA**: non vanno confusi con
i totali lordi dei precedenti ordini di ottobre.

L'unico rilievo dell'utente riguarda l'oggetto dell'elenco FIC, ancora popolato
dal codice `innerplay-test-<uuid>`: corretto nella 0.5.5 come descritto sotto.
Sono confermati sul sito il percorso automatico dei nuovi acquisti e i campi
dei documenti per entrambi i piani. La causa delle risposte PayPal incomplete
osservate il 14 ottobre resta una verifica distinta; questi due esiti non
documentano se sia stato necessario recuperare l'ID incasso.

## Oggetto leggibile nell'elenco FIC — 0.5.5

Il campo API `subject`, mostrato nella colonna **Oggetto** dell'elenco FIC,
usa ora lo stesso testo completo della riga servizio, scelto dal piano dell'ordine:

- **Abbonamento Inplaycetools Professional** per Professional.
- **Abbonamento Inplaycetools Gold** per Gold.

Il testo continua a provenire dalle impostazioni per piano, non è codificato
nel plugin. Per un piano non configurato si conserva il testo generico con
numero livello. Il campo `visible_subject` del PDF mantiene l'intestazione di
collaudo `TEST — ordine PMPro #ID`. L'oggetto è mostrato anche nell'anteprima
della sezione 3, prima di creare un documento.

Il marker UUID non è stato eliminato: era già nelle note del documento e resta
nel registro locale. Il recupero richiede ora anche `notes` nella lista API e
riconosce il marker completo nelle note oppure nell'oggetto tecnico delle
versioni precedenti. Non associa documenti per la sola descrizione del piano.
Se il riferimento è assente, non valido o presente in più documenti distinti,
il blocco viene mantenuto e non viene autorizzato un altro invio. La scansione
resta limitata agli ultimi 500 documenti dell'azienda di prova.

Dopo l'aggiornamento dell'intero plugin alla **0.5.5**, i nuovi documenti manuali
e automatici useranno l'oggetto leggibile. Le impostazioni e la configurazione
della coda restano valide se non vengono modificate. Le anteprime precedenti
devono essere rigenerate; i tentativi già inviati restano recuperabili.
Non è stata eseguita alcuna modifica remota dei documenti 8/TEST e 9/TEST:
conservano l'oggetto originale finché non viene modificato direttamente in FIC.
Conservare il riferimento nelle note per le eventuali verifiche di recupero.

**Prossimo controllo:** verificare **Oggetto nell'elenco Fatture in Cloud** in una
nuova anteprima senza creare un documento; alla successiva creazione controllare
la colonna Oggetto in FIC. Non ricreare un documento per ordini già associati.

## Descrizioni diverse per piano — 0.5.4

Richiesta dell'utente: sostituire la dicitura `Abbonamento — livello #5` con
un testo diverso per ogni piano. L'utente ha confermato i testi definitivi:

| Piano | Descrizione completa da salvare |
| --- | --- |
| Professional (ID 4) | Abbonamento Inplaycetools Professional |
| Gold (ID 5) | Abbonamento Inplaycetools Gold |

I campi personalizzati della 0.5.4 sono inizialmente vuoti: inserire questi testi
nelle impostazioni sul sito e salvarli. La configurazione del sito non è stata
modificata dalla registrazione dei testi nella guida.

In **Impostazioni → PMPro Fatture in Cloud**, la nuova voce **Descrizioni in
fattura per piano** mostra un campo per ogni piano PMPro, inclusi quelli
nascosti. Per i piani attuali sono disponibili Welcome, Professional e Gold;
i gratuiti restano esclusi dalla creazione documenti. Eventuali testi di piani
rimossi restano visibili per non perdere la configurazione degli ordini storici.

Il testo compilato è la descrizione completa della riga fattura: non vengono
aggiunti `livello #`, nome del piano, durata o altro. Si applica sia alla sezione
3 sia alla creazione automatica della sezione 4. Il collegamento avviene tramite
l'ID del piano presente nell'ordine; cambiare il nome del piano non sposta il
testo su un altro piano. Sono ammessi testi semplici fino a 1000 caratteri;
HTML viene rimosso e gli a capo vengono convertiti in spazi.

Se il campo del piano è vuoto, si conserva il comportamento precedente:
descrizione generica seguita da `— livello #ID`. L'aggiornamento con tutti i
campi ancora vuoti conserva gli ambiti e i lavori della coda precedente.
La simulazione della sezione 2 continua a usare la descrizione generica.

Procedura dopo l'installazione dell'intero ZIP 0.5.4:

1. Compilare il testo desiderato per **Professional** e **Gold**, poi premere
   **Salva impostazioni**, lasciando vuoto il token per conservarlo.
2. Se i testi sono cambiati, l'automazione viene messa in pausa. Nella sezione 4
   premere **Carica conti per automazione**, selezionare il conto di collaudo e
   riconfermare il recupero automatico e il collaudo, quindi attivare l'automazione.
3. Per verificare il testo senza creare un nuovo documento, usare un ordine
   Sandbox pagato di quel piano ancora senza documento associato e preparare
   l'anteprima della sezione 3. Per verificare entrambi i piani servono ordini dei
   rispettivi piani; non cambiare il piano di un ordine già pagato.
4. Verificare descrizione completa, importo e aliquota nell'anteprima. Rigenerare
   le anteprime precedenti al cambio di testo: vengono invalidate dalla modifica.

Le fatture già associate restano consultabili con la loro descrizione originale.
Questa impostazione non modifica documenti esistenti in FIC. Eventuali lavori
ancora in coda con la vecchia configurazione richiedono controllo e **Riprova**
dopo la riattivazione; i lavori già conclusi non vanno rilanciati.

## Collaudo precedente — ordine 56, 14 ottobre 2026

L'utente ha atteso e aggiornato la pagina, senza riferire elaborazioni manuali:
la coda mostra **Documento associato**, ordine **56**, documento FIC **560063390**,
**2 tentativi**, ID incasso recuperato **4VX44692N0631661Y**.
Il registro 0.5.2 conferma l'abbonamento **I-GLLXG97MF516** e un unico incasso
completato da **841,80 EUR**, verificato due volte prima dell'associazione.
Successivamente l'utente ha confermato che il documento è presente anche in
Fatture in Cloud. Sono quindi confermati recupero, associazione locale e
presenza remota del documento per questo nuovo acquisto.

Alle **13:21:20** (ora italiana) il webhook del pagamento aveva restituito
`local_reference_not_found`. Alle **13:21:22** la lettura degli incassi durante
l'attivazione ha risposto HTTP 200 con JSON leggibile, ma senza un campo
`transactions` di tipo array. Alle **13:21:23** l'ordine risultava `success` con
ID pagamento vuoto. Alle **13:22:43** il recupero in coda ha ottenuto la
transazione completata, con lo stesso importo e la valuta EUR.

È quindi accertato il motivo immediato dell'ID mancante: nessun elenco
utilizzabile nella lettura iniziale. La causa della risposta incompleta PayPal
resta da accertare; non si deve chiamarla semplicemente una lista vuota o
considerare dimostrato un ritardo delle API. Le firme sono valide e, nelle
richieste registrate, non compaiono errori HTTP di autorizzazione o rete.
Il refresh può contribuire ad avviare WP-Cron, senza creare un nuovo pagamento.

Analisi e limiti: [registro dell'ordine 56](../../docs/analisi-paypal-ordine-56.md).
**Prossimo controllo:** nel documento FIC **560063390**, verificare
intestatario, dati fiscali, incasso sul conto di prova, assenza di duplicati,
totale **841,80 EUR** e, con IVA 22%, imponibile **690,00 EUR** / IVA **151,80 EUR**.
La presenza in FIC è confermata; non è ancora stata fornita una conferma
specifica di questi dettagli e dell'assenza di duplicati per l'ordine 56.

La sezione 3 crea realmente un documento non elettronico nell'azienda Fatture in
Cloud configurata, nel sezionale `/TEST`, e registra un incasso di prova. Non
invia email né documenti allo SDI. La sezione 4 usa lo stesso flusso di creazione
e lo stesso registro dei documenti. Il vecchio `enabled` del prototipo non
abilita la nuova automazione. Gli hook degli ordini salvano dati e lavori in
coda; le chiamate FIC avvengono nel worker WP-Cron o con **Elabora ora**.
Non è una versione pronta
per la fatturazione in produzione: usare l'azienda separata di collaudo.

## Stato del collaudo — 13 ottobre 2026

La piattaforma venderà a privati e aziende/professionisti, inizialmente in Italia.
L'apertura all'estero è prevista in una fase successiva. Il progetto è ancora in
sviluppo: i pagamenti usati finora sono PayPal Sandbox e l'azienda Fatture in Cloud
è un account Standard di prova separato.

### Già verificato

- Collegamento all'azienda Fatture in Cloud, visualizzazione del nome e caricamento
  delle aliquote funzionanti sul sito.
- Simulazione di 122,00 € al 22% corretta: imponibile 100,00 €, IVA 22,00 €.
- Creazione manuale riuscita per l'ordine PMPro **47**: documento Fatture in Cloud
  **559904028**, numero **1/TEST**. L'utente ha verificato dati cliente, importi,
  incasso sul conto **PayPal Test** e protezione contro la creazione di duplicati.
  Questo test precede l'introduzione dei dati fiscali acquisiti dal nuovo checkout.
- Nel checkout il cambio Privato / Azienda modifica correttamente i campi mostrati,
  come verificato dall'utente sul sito.
- Dopo un primo tentativo rimasto in stato `token`, l'utente ha ripetuto il checkout
  come privato e ha confermato che il nuovo tentativo ha funzionato. Non sono ancora
  stati comunicati l'ID numerico del nuovo ordine, il suo stato finale e l'ID della
  transazione: verificarli prima della creazione del documento.
- Superati **282 controlli locali simulati**: collegamento e simulazione (80),
  documenti manuali (134), dati fiscali del checkout (68). Non sostituiscono il
  collaudo completo sul sito con il nuovo ordine.

La versione **0.4.1** è stata preparata con ritorno automatico al risultato della
sezione 3 dopo ogni azione e messaggi di errore nella stessa area. Lo ZIP aggiornato
è stato consegnato; resta da confermare il comportamento sul sito dopo l'installazione.

### Osservazioni da conservare

Il primo tentativo da 841,80 € risultava `token` e l'utente non trovava la
transazione nell'account PayPal Sandbox dell'acquirente. L'invio manuale tramite
**Invia ordine via email** produceva comunque un testo di ricevuta con “Total
Billed”: l'email non dimostra che il pagamento sia avvenuto e non è un documento
creato dal nostro plugin in Fatture in Cloud. Non è stata accertata la causa del
checkout interrotto; il successivo tentativo è stato riferito come riuscito.
Lasciare invariato il vecchio ordine, senza impostarlo manualmente a `success`.

### Prossimo test da eseguire: documento dal nuovo checkout privato

Obiettivo: verificare l'intero percorso **checkout privato → pagamento Sandbox
confermato → dati fiscali salvati nell'ordine → documento /TEST**, senza
reinserire manualmente i dati cliente.

1. In **Abbonamenti → Ordini**, individuare il nuovo ordine dell'ultimo checkout
   privato. Annotare l'ID numerico (non il codice alfanumerico), controllare stato
   `success`, ambiente Sandbox, ID transazione pagamento e totale. Se il pagamento
   non è confermato, verificare prima PayPal senza ripetere subito l'acquisto.
2. In **Impostazioni → PMPro Fatture in Cloud**, rinnovare la verifica del
   collegamento se scaduta: dura un'ora. Controllare il nome dell'azienda di prova,
   selezionare l'aliquota del collaudo (finora 22%) e salvare. Lasciare vuoto il campo
   token per conservare quello già configurato.
3. Nella **sezione 3**, inserire il nuovo ID e premere **Carica ordine e conti
   d'incasso**. Con la 0.4.1 verificare anche che la pagina si posizioni sul risultato.
4. Controllare che compaia **Dati acquisiti dal checkout** e che nome, codice
   fiscale, email e indirizzo corrispondano ai dati inseriti nell'acquisto, già
   compilati e non modificabili. Se mancano o sono diversi, fermare questo test
   prima della creazione e verificare il salvataggio dei dati nell'ordine.
5. Scegliere **PayPal Test**, confermare l'uso per collaudo e premere **Prepara
   anteprima**. Verificare intestatario, azienda destinataria, imponibile, IVA,
   totale e incasso rispetto all'ordine. L'anteprima non crea il documento.
6. Se i dati sono corretti, premere **Crea documento nell'azienda di prova**.
   Annotare il nuovo ID Fatture in Cloud e il numero `/TEST`. Aprire il documento
   in Fatture in Cloud e verificare dati fiscali, importi e incasso saldato.
7. Ricaricare lo stesso ordine: deve comparire l'associazione al documento già
   creato, senza un secondo documento.

Registrare qui l'esito del prossimo collaudo:

| Voce | Valore / esito |
| --- | --- |
| ID numerico nuovo ordine privato | Da annotare |
| Stato PMPro e presenza ID transazione | Da verificare |
| Totale ordine | Da verificare |
| Dati acquisiti dal checkout corretti | Da verificare |
| ID documento Fatture in Cloud e numero /TEST | Da annotare dopo la creazione |
| Totali e incasso corretti in Fatture in Cloud | Da verificare |
| Ricaricamento senza duplicati | Da verificare |
| Posizionamento automatico sezione 3 (0.4.1) | Da verificare sul sito |

### Passi successivi concordati come proposta

1. Ripetere il percorso completo come **azienda/professionista**, controllando
   partita IVA, ragione sociale e trasferimento di codice destinatario / PEC.
2. Collaudare checkout interrotti, dati errati, errori API, richieste ripetute e
   modifiche del profilo successive all'acquisto. Migliorare anche i messaggi email
   che possono far sembrare pagato un ordine incompleto.
3. Implementare la creazione automatica nell'azienda di prova dopo la conferma
   del pagamento, con coda, gestione degli errori e pannello di monitoraggio.
   Proposta iniziale: controllo amministrativo prima dell'invio elettronico.
4. Gestire rinnovi, copie fiscali per ogni nuovo pagamento, rimborsi e note di credito.
5. Preparare la produzione italiana: regole IVA e prezzi confermati con il
   commercialista, fatturazione elettronica, verifica `dry_run`, stati SDI e
   documenti nell'area cliente. Affrontare l'estero in una fase dedicata.

Questa proposta è stata salvata durante il collaudo manuale. La 0.5.0 implementa
ora il punto 3, limitatamente ai nuovi primi acquisti di abbonamenti Sandbox.
Non trasmette allo SDI e non pubblica documenti nell'area cliente.

### Aggiornamento del 14 ottobre 2026 — ordine azienda #51

L'utente ha eseguito un nuovo checkout come azienda e riferisce che l'ordine
PMPro **51** risulta pagato nel backend. Il caricamento nella sezione 3 viene
bloccato dal controllo `transaction_missing`: il campo `payment_transaction_id`
letto dal plugin risulta vuoto. Non è stato creato un documento per questo ordine
tramite il tentativo descritto.

Diagnosi ancora aperta: verificare gateway, totale, ID transazione pagamento e
ID transazione abbonamento nella pagina dell'ordine, poi confrontare il pagamento
con l'attività dell'account venditore PayPal Sandbox. Un ID di abbonamento non è
da inserire al posto dell'ID dell'incasso per superare il controllo. Il codice
personalizzato salva i dati fiscali ma non scrive gli ID transazione PayPal né
modifica lo stato dell'ordine. Non è stata rimossa la verifica dell'ID pagamento.

Ulteriore riscontro dell'utente: la lista ordini mostra gateway **PayPal (test)**,
totale **549,00 €** e nella colonna ID transazione soltanto la voce
**Abbonamento**, con un identificativo PayPal `I-…` collegato all'abbonamento
PMPro **21**. Resta da verificare se l'account venditore PayPal Sandbox registra
un incasso completato da 549,00 € e, in quel caso, perché manca il relativo ID
pagamento nell'ordine 51. L'ID dell'abbonamento da solo non risolve questo controllo.

L'utente ha poi confermato nell'account venditore Sandbox un **pagamento automatico
ricevuto da 549,00 €, con stato completato**. Il controllo successivo riguarda
gli ordini associati all'abbonamento PMPro 21: verificare se il riferimento al
singolo incasso sia stato salvato in un altro ordine, prima di diagnosticare
un evento mancato o non elaborato. Verificare anche il collegamento di quel
movimento allo stesso abbonamento; l'importo da solo non basta per riconciliarlo.

Lettura pubblica del sito durante la diagnosi: l'indice REST espone il namespace
`pmpro-paypal/v1` e la route `/pmpro-paypal/v1/webhook`, coerenti con il **nuovo
gateway PMPro PayPal**, che usa webhook. L'endpoint è
`https://inplaycetools.com/wp-json/pmpro-paypal/v1/webhook`. La sua presenza non
certifica che gli eventi siano stati ricevuti o elaborati. Gli asset pubblici
PMPro riportano versione 3.8.9. Non sono stati letti ordini o log privati, né
modificate le impostazioni gateway. La configurazione IPN dei vecchi PayPal
Express/Standard non è il primo percorso diagnostico per questo gateway.
[Documentazione del nuovo gateway](https://www.paidmembershipspro.com/add-ons/pmpro-paypal/).

L'utente conferma inoltre che nell'abbonamento 21 non compare un altro ordine
per questo pagamento. Come confronto, il checkout privato della sera precedente
(abbonamento PMPro 20) mostra sia un ID pagamento sia l'ID abbonamento. Il nostro
codice non applica regole diverse agli identificativi PayPal per privati e aziende.

Analisi del sorgente ufficiale **PMPro PayPal 1.1.4** (versione dell'addon live
ancora da verificare): l'handler `BILLING.SUBSCRIPTION.ACTIVATED` tenta di leggere
le transazioni del primo incasso e poi completa il checkout anche se la lettura
non restituisce un ID. È quindi possibile ottenere un ordine `success` con
`payment_transaction_id` vuoto. L'handler `PAYMENT.SALE.COMPLETED` elabora invece
la transazione del singolo pagamento. Non è ancora accertato quale evento,
risposta API o ordine di arrivo abbia prodotto il caso 51.

Prossimo controllo: nell'app PayPal Sandbox usata per PMPro, aprire **Webhook
Events** e cercare il pagamento dell'abbonamento 21, tipo
`PAYMENT.SALE.COMPLETED`, confrontando l'ID abbonamento e l'ID del movimento.
Verificare stato di consegna e URL destinatario. Se consegnato, leggere anche
il risultato applicativo / log: l'handler può rispondere HTTP 200 con un messaggio
di elaborazione non riuscita, quindi la sola consegna non prova che l'ID sia stato
salvato. Non è stata ripetuta una transazione né modificato l'ordine 51.

Fonti:
- [Handler ufficiale PayPal 1.1.4](https://github.com/strangerstudios/pmpro-paypal/blob/1.1.4/includes/webhook-handler.php)
- [Eventi degli abbonamenti PayPal](https://developer.paypal.com/subscriptions/webhooks/)
- [Dashboard eventi webhook Sandbox](https://developer.paypal.com/dashboard/webhooks/sandbox)

Screenshot fornito dall'utente alle 10:44 del 14 ottobre: gli eventi delle 10:20
mostrano `BILLING.SUBSCRIPTION.ACTIVATED` per **I-6GWNANENG9PK** e
`PAYMENT.SALE.COMPLETED` da **549 EUR** per la risorsa **8LH19777VD3170157**,
entrambi con stato **Success** nel pannello PayPal. Sono Resource ID di oggetti
diversi: abbonamento e incasso; gli identificativi `WH-…` sono invece gli ID degli
eventi webhook. Verificare nel dettaglio del pagamento il campo
`resource.billing_agreement_id` e l'URL destinatario per confermare il legame
con quell'abbonamento e la consegna al webhook PMPro. Il solo stato Success
non dimostra che PMPro abbia salvato correttamente l'ID pagamento.

L'utente ha verificato nel dettaglio del pagamento
`billing_agreement_id = I-6GWNANENG9PK` e l'URL destinatario del webhook PMPro.
È quindi confermato il collegamento del movimento all'abbonamento 21 e al sito.
È stata preparata la **0.4.2** con verifica API e associazione esplicita del primo
incasso all'ordine esistente; non è ancora stata eseguita sul sito. La causa
originaria dell'ID mancante resta da confermare dai log del gateway: il recupero
manuale non corregge automaticamente l'elaborazione futura dei webhook.

Verifica locale della 0.4.2: **436 controlli superati** nelle quattro suite
(80 collegamento/simulazione, 134 ordini, 68 checkout fiscale, 154 recupero
PayPal), sintassi PHP/JavaScript valida. PayPal, WordPress e HTTP sono simulati;
l'aggiornamento condizionato è verificato su SQLite, non sul database del sito.
Nessun pagamento, ordine remoto o documento è stato modificato da questi test.

### Esito della prova 0.4.2 e correzione 0.4.3 — 14 ottobre

L'utente conferma il passaggio da PayPal Express al nuovo gateway PayPal il
13 ottobre. I campi Client ID/Secret, Webhook ID e URL sono coerenti con il
nuovo addon: usa REST/webhook e non le credenziali NVP/IPN di Express.
La verifica API della 0.4.2 per l'ordine 51 è riuscita: pagamento
**8LH19777VD3170157**, abbonamento **I-6GWNANENG9PK**, **549,00 EUR**,
data **2026-10-14T08:20:44Z**. La conferma ha invece restituito il generico
errore «ID non aggiornato».

È stato trovato e riprodotto un difetto del recupero 0.4.2: il totale nella
tabella ordini PMPro è `varchar(16)`; il confronto SQL usava sempre `549.00`,
mentre il dato originale può essere `549` o `549.0`. Sono importi numericamente
identici ma testi diversi. Il test precedente usava una colonna numerica e
nascondeva questa incompatibilità. La 0.4.3 confronta il valore originale letto
dall'ordine, mantenendo i controlli di importo/valuta al centesimo e senza
riscrivere il totale. Il test ora usa una colonna testuale e copre le tre forme,
le modifiche concorrenti e la ripetizione dopo un tentativo non riuscito.
La correzione è compatibile con il sintomo osservato, ma il valore grezzo e
l'esito SQL del database live non sono stati letti direttamente.

Gli errori di esecuzione SQL riportano `PAYPAL-DB-QUERY`; una rilettura incoerente
dopo un aggiornamento segnala `PAYPAL-DB-READBACK`. Un aggiornamento di zero righe
è indicato separatamente come mancata corrispondenza delle condizioni. Non
vengono mostrate query, credenziali o errori grezzi del database.

Screenshot dell'app Sandbox: **Subscriptions** risulta deselezionato.
È un'impostazione da allineare all'uso dei piani ricorrenti, ma non è provata
come causa dell'ID mancante e non spiega il difetto del confronto SQL: il primo
incasso esiste e la lettura delle transazioni è già riuscita. L'opzione
**Transaction search** non è il metodo usato da questo recupero, che legge
`/v1/billing/subscriptions/{id}/transactions` tramite l'API del gateway.

Verifica locale 0.4.3: **452 controlli superati** (80 collegamento, 134 ordini,
68 checkout, 170 recupero), con sintassi PHP/JavaScript valida.
L'utente ha successivamente confermato che i passaggi della 0.4.3 sono andati a
buon fine sul sito: verifica PayPal e associazione dell'ID all'ordine **51**.
Il recupero manuale è quindi collaudato per questo ordine. Non è ancora
confermata la creazione del documento aziendale; il recupero non risolve la
causa originaria dell'elaborazione webhook, ancora da accertare dai log.

Fonti:
- [Configurazione e migrazione del nuovo gateway PMPro PayPal](https://www.paidmembershipspro.com/add-ons/pmpro-paypal/)
- [Schema ufficiale PMPro: totale ordine testuale](https://github.com/strangerstudios/paid-memberships-pro/blob/master/includes/upgradecheck.php)

### Nuovo ordine con riepilogo aperto — esito confermato il 14 ottobre

L'utente ha completato un nuovo acquisto PayPal Sandbox mantenendo aperto il
riepilogo fino al passaggio da pending a completed. Nel backend PMPro erano
già presenti entrambe le voci **Pagamento** e **Abbonamento**, prima di
qualsiasi operazione nel plugin FIC. L'ID pagamento è quindi stato registrato
automaticamente per questo nuovo ordine: non è stato necessario il recupero
manuale dell'ID.

L'utente ha inoltre confermato il percorso **Carica ordine e conti d'incasso →
anteprima → creazione documento in Fatture in Cloud**, con esito positivo.
ID numerico del nuovo ordine, tipologia cliente, ID/numero del documento e
controllo dei duplicati non sono stati comunicati; non attribuire questo
documento all'ordine 51 senza una conferma specifica.

Questo primo test conferma il flusso con browser aperto. La successiva prova
con chiusura della scheda è descritta sotto. La causa originaria dell'ID
mancante sull'ordine 51 resta da accertare.

### Chiusura della pagina durante il pending — esito confermato il 14 ottobre

L'utente ha confermato di aver completato anche il flusso chiudendo la scheda,
con lo stesso esito positivo della prova precedente. Secondo il riscontro
dell'utente, anche questa prova ha completato l'ordine con i riferimenti di
pagamento e abbonamento e il successivo flusso manuale FIC. Il completamento
ha quindi funzionato senza mantenere aperto il riepilogo in questo test.
Non è stata effettuata una lettura diretta dei log da parte dell'assistente;
ID ordine/documento e tempi precisi non sono stati comunicati.

Procedura seguita per il collaudo:

1. Attivare il log su file del nuovo gateway, se non già attivo, aggiungendo
   `define('PMPRO_PAYPAL_WEBHOOK_DEBUG', 'log');` in `wp-config.php` prima del
   caricamento di `wp-settings.php`. Se la costante esiste, modificare la riga
   esistente senza duplicarla.
2. Usare un nuovo utente della piattaforma e completare un acquisto Sandbox.
3. Al ritorno sul sito, chiudere la scheda del riepilogo mentre è ancora pending.
   Annotare l'ora; se è già completed, il test di chiusura durante il pending
   non è stato eseguito.
4. Dopo circa due minuti verificare dal backend, senza riaprire il riepilogo
   e prima di qualsiasi recupero manuale: ordine pagato, ID Pagamento e
   Abbonamento presenti, abbonamento PMPro attivo.
5. Annotare ID numerico, totale, stato ed esito dei riferimenti. Se resta pending
   o manca l'ID pagamento, leggere `paypal-webhook.txt` prima di rifare l'acquisto.
   La prova di refresh del riepilogo potrà essere eseguita separatamente.

### Associazione persistente — esito confermato il 14 ottobre

Ricaricando l'ordine, l'utente ha confermato la comparsa corretta del messaggio
**Documento associato all'ordine**. L'associazione salvata viene quindi
ritrovata dal plugin nel collaudo sul sito. Non sono ancora stati comunicati
l'ID numerico dell'ordine o l'ID/numero del documento. L'utente ha poi
confermato anche il controllo in Fatture in Cloud: **nessun duplicato**.

Controlli di tracciabilità e duplicati ancora da annotare:

1. Annotare ID numerico del nuovo ordine e ID/numero del documento FIC creato.
2. Ricaricare lo stesso ordine nella sezione **Documento da ordine PayPal
   Sandbox** tramite **Carica ordine e conti d'incasso**.
3. Verificare che venga mostrato **Documento associato all'ordine**, con lo
   stesso ID documento FIC, e controllare in Fatture in Cloud che non sia
   comparso un secondo documento per quell'ordine.
4. Registrare anche la tipologia cliente e i totali per completare la copertura
   dei collaudi privato/azienda. Resta distinta la prova di refresh durante il
   pending. L'automazione 0.5.0 è preparata ma deve ancora essere collaudata sul sito.

### Automazione 0.5.0 preparata — 14 ottobre

Il percorso manuale dei nuovi ordini è stato confermato dall'utente nei test
con riepilogo aperto e scheda chiusa, compresa la rilettura dell'associazione.
L'utente ha verificato l'assenza di duplicati in FIC e autorizzato il passaggio
all'automazione Sandbox. È stata implementata la sezione 4, inizialmente
disattivata, per elaborare i nuovi primi acquisti di abbonamenti dopo la
conferma del pagamento. La copia fiscale, i controlli dei totali, il journal e
il lock FIC sono condivisi con il flusso manuale. La coda attende gli ID mancanti
senza recuperarli automaticamente e non elabora i rinnovi. Nessun nuovo codice
è stato caricato sul sito dall'assistente e nessuna fattura è stata creata dai
test locali. Il primo test della coda sul sito è stato avviato: esito in attesa
descritto sotto.

### Primo test 0.5.0 sul sito — ID pagamento nuovamente mancante

L'utente ha completato un nuovo acquisto e nel backend vede soltanto
**Abbonamento I-8TG2LLTDSLSW** (abbonamento interno PMPro **25**), senza la voce
Pagamento. La coda mostra **In attesa** dopo **3 tentativi**. Lo screenshot
fornito alle 12:43 conferma ordine PMPro **55**, codice **D47964C9B4**, piano
**Gold**, totale **841,80 EUR**, gateway **PayPal (test)**, stato **Pagato**,
data **14 ottobre 2026 ore 12:37**, solo riferimento all'abbonamento.
L'utente ha poi confermato anche nel conto venditore PayPal Sandbox il relativo
pagamento **Completato** da **841,80 EUR**. Il recupero API della sezione 3
deve verificare il riferimento esatto all'abbonamento prima di associare l'ID;
l'associazione e la successiva creazione automatica FIC non sono ancora
confermate. Con IVA 22%, i totali attesi sono 690,00 EUR imponibile e 151,80 EUR IVA.

Nella 0.5.0 i tentativi in questo stato rileggono l'ordine PMPro: prima di
`success` e ID incasso presente, non vengono chiamati conti, preflight o
creazione documenti FIC. Il funzionamento del cron è quindi osservato, ma la
creazione automatica del documento non è ancora collaudata positivamente.
Non impostare manualmente l'ordine come pagato e non rifare l'acquisto per
risolvere questo caso.

Il sorgente dell'addon PayPal 1.1.4 permette il completamento del checkout
anche quando la prima lettura delle transazioni non restituisce un ID. Questo
è compatibile con il caso, ma la versione dell'addon e l'esito dei webhook live
vanno verificati prima di attribuirgli una causa certa. Occorre leggere
`paypal-webhook.txt` per questo abbonamento, confrontando gli eventi di
attivazione e di incasso completato e i messaggi di elaborazione PMPro.

Se l'ordine è `success`, la procedura di recupero verificato della sezione 3
può associare l'ID di un unico incasso Completato e corrispondente. Dopo
l'associazione, tornare alla sezione 4 e usare **Elabora ora** se il lavoro è
ancora In attesa; se ha esaurito i tentativi, usare **Riprova**, poi elaborare.
La creazione deve avvenire dalla coda, così da verificare separatamente questa
parte del flusso. Per la 0.5.0, il recupero dell'ID resta un'azione manuale
esplicita, non una riconciliazione automatica.

### Analisi del log ricevuto e correzione 0.5.1 — 14 ottobre

È stato letto il file `paypal-webhook.txt` allegato dall'utente (72 righe).
Per l'abbonamento **I-8TG2LLTDSLSW**, il log riporta:

| Ora registrata nel log | Evento | Esito PMPro |
| --- | --- | --- |
| 12:38:03 | BILLING.SUBSCRIPTION.CREATED | Unhandled event type |
| 12:38:30 | PAYMENT.SALE.COMPLETED, incasso **844753021M0826123** | ERROR: Could not find this subscription associated with a successful payment |
| 12:38:31 | BILLING.SUBSCRIPTION.ACTIVATED | Order #55 completed successfully |

È quindi accertato che il webhook dell'incasso è arrivato al sito e che
l'elaborazione ha fallito la ricerca dell'abbonamento locale. La successiva
attivazione ha completato l'ordine 55, che nello screenshot risulta pagato con
ID incasso vuoto. Il pagamento non è assente da PayPal: è il collegamento
iniziale in PMPro che non è stato registrato correttamente.

Nel log compare lo stesso errore di ricerca per l'abbonamento
**I-V0K2S2KDVVSJ**, alle 12:11:39, seguito alle 12:11:44 da completamento
dell'ordine **54**; il log non prova se l'ID dell'ordine 54 sia rimasto vuoto.
Per gli ordini 52 e 53 l'evento incasso risulta già elaborato. Gli orari sono
quelli di scrittura del log a fine handler, non misure dell'inizio di ogni
richiesta: gli handler possono essere concorrenti.

Il codice ufficiale dell'addon 1.1.4 delega l'incasso a PMPro, che cerca un
`PMPro_Subscription` locale. Il codice PMPro analizzato crea tale oggetto solo
quando l'ordine diventa `success`. L'attivazione tenta poi di leggere il primo
incasso, ma può completare il checkout anche senza un ID. Questo è coerente
con un problema dei tempi di inizializzazione delle notifiche. Non sono
disponibili nel log gli esiti delle letture API delle transazioni: la ragione
precisa del mancato recupero durante l'attivazione non è documentata.

`BILLING.SUBSCRIPTION.CREATED` non è gestito dal dispatcher analizzato:
**Unhandled event type** per questo evento non indica un pagamento fallito.
L'handler restituisce HTTP 200 anche per l'errore applicativo dell'incasso,
quindi la consegna **Success** nella dashboard PayPal non dimostra il corretto
salvataggio del riferimento in PMPro.

La **0.5.1** aggiunge alla coda una compensazione verificata, attivabile con
**Recupera automaticamente l'ID incasso mancante verificandolo in PayPal
Sandbox**. Non modifica l'addon ufficiale né reinvia webhook. Per ordini già
pagati e già acquisiti dalla coda legge l'abbonamento e le transazioni del
primo incasso, verifica un unico `COMPLETED` corrispondente ad abbonamento,
importo e EUR, poi associa soltanto l'ID vuoto al medesimo ordine.
Il codice riusa la verifica e l'aggiornamento atomico del recupero manuale;
non inserisce automaticamente nel database l'ID letto dal file di log.

Il recupero è ricontrollato prima dell'associazione, con guard su configurazione,
autorizzazione, identità e dati dell'ordine. Il meta di audit indica
`origin=automation` e l'utente cron può essere 0. Non viene marcato pagato un
ordine pending/token e non vengono eseguiti addebiti/catture/rimborsi, checkout
o email PMPro. Ambiguità, rimborsi, importi diversi, duplicati e liste parziali
bloccano la procedura. Dati non ancora disponibili ed errori di lettura sono
ritentati entro il budget esistente di 6 elaborazioni; esiti SQL dubbi richiedono
verifica. Se il gateway salva l'ID nel frattempo, questo viene conservato e la
coda lo rilegge al tentativo seguente.

Cambiare l'opzione genera una nuova autorizzazione: per un lavoro 0.5.0 già in
attesa, l'utente deve premere **Riprova**. Il pulsante appare anche per lavori
in coda/in attesa legati alla precedente attivazione. La sola installazione
non cambia l'autorizzazione dei lavori esistenti. Dopo il recupero, la coda
prosegue con totali e creazione FIC già collaudati localmente.
La correzione è stata successivamente caricata e il recupero dell'ordine 55
è stato confermato sul sito: esito dettagliato sotto.

Fonti:
- [Dispatcher e handler ufficiali PayPal 1.1.4](https://github.com/strangerstudios/pmpro-paypal/blob/1.1.4/includes/webhook-handler.php)
- [Handler incassi PMPro](https://github.com/strangerstudios/paid-memberships-pro/blob/master/includes/gateway-request-handlers.php)
- [Creazione dell'abbonamento locale dall'ordine PMPro](https://github.com/strangerstudios/paid-memberships-pro/blob/master/classes/class.memberorder.php)

### Recupero in coda dell'ordine 55 — esito confermato alle 13:03 del 14 ottobre

Lo screenshot fornito dall'utente mostra:

- Ordine PMPro **55**: **Documento associato**.
- Messaggio: **Documento /TEST creato e associato all'ordine**.
- Documento FIC **560058633**.
- ID incasso recuperato **844753021M0826123**.
- **1 tentativo** nel nuovo ciclo dopo la riprogrammazione.

Sono quindi confermati sul sito il recupero verificato dell'ID e la creazione
FIC dalla coda per questo ordine. Successivamente l'utente ha confermato anche
il controllo in Fatture in Cloud con esito positivo. Il numero documento non
è stato comunicato. Lo screenshot non distingue se il worker sia stato eseguito dal
cron o tramite **Elabora ora**: serve ancora un nuovo checkout con la 0.5.1
senza intervento manuale per verificare l'automazione completa tramite cron.

### Procedura seguita: riprendere l'ordine 55 con recupero verificato in coda

1. Aggiornare l'intero plugin alla **0.5.1**.
2. Nella sezione 4 caricare i conti, mantenere l'azienda/IVA di collaudo e il
   conto **PayPal Test**. Se serve, rinnovare prima il collegamento FIC.
3. Selezionare **Recupera automaticamente l'ID incasso mancante verificandolo
   in PayPal Sandbox**, confermare il collaudo e attivare l'automazione.
4. Per l'ordine **55** premere **Riprova**, poi **Elabora ora**, oppure attendere
   il cron. Non creare un nuovo acquisto né una fattura manuale per questa prova.
5. Verificare in PMPro l'ID pagamento **844753021M0826123** e in coda
   **Documento associato**, con indicazione dell'ID incasso recuperato.
6. In FIC verificare un solo documento /TEST, dati cliente, conto, totale
   **841,80 EUR** e, con IVA 22%, **690,00 EUR** imponibile / **151,80 EUR** IVA.
   Annotare ID/numero documento. Un errore di verifica va analizzato prima di
   qualsiasi nuovo invio.

### Prossimo controllo e test

Il controllo FIC dell'ordine 55 è concluso con esito positivo. Il prossimo
test è un **nuovo checkout con diagnostica 0.5.2**, come descritto qui sotto.
Non riutilizzare l'ordine 55: le risposte API della sua attivazione non sono
state registrate e non si possono ricostruire a posteriori.

### Diagnostica dell'ID incasso mancante — 0.5.2

Il log webhook ricevuto conferma un errore di ricerca della sottoscrizione locale
durante `PAYMENT.SALE.COMPLETED`, seguito dal completamento del checkout durante
`BILLING.SUBSCRIPTION.ACTIVATED`. Nel codice ufficiale PayPal analizzato (1.1.4),
il completamento è possibile anche se la lettura API degli incassi fallisce o
non restituisce il primo ID. La versione esatta del gateway sul sito resta da
annotare. La causa di quella specifica risposta API dell'ordine 55 è **ignota**:
non è dimostrato che fosse semplicemente un ritardo di disponibilità.

La nuova diagnostica osserva gli hook HTTP WordPress e il percorso REST del
webhook. Salva solo campi ammessi: tempi UTC e millisecondi, contesto della
chiamata (attivazione, polling checkout, recupero manuale o coda), ID tecnici,
codici HTTP, validità JSON, numero/stato delle transazioni e importi/valuta.
Registra anche l'esito OAuth e della verifica firma, senza token o contenuti
grezzi. Le risposte HTTP di trasporto e quelle dopo i filtri sono distinguibili;
sono riconoscibili anche risposte fornite da `pre_http_request` e errori di rete.
La ricezione di un webhook non implica che la firma sia già stata verificata:
il registro distingue ricezione, verifica API, elaborazione e risposta HTTP.

Il file **fic-paypal-api.php** è nella cartella riservata `logs` di PMPro. Si
scarica dal pannello con accesso amministratore; il prefisso PHP interrompe
l'esecuzione se viene richiesto direttamente attraverso il server PHP. Il
contenuto successivo è testo JSON, una riga per evento. Non vengono registrati
Authorization, secret, token, email, nomi, indirizzi, body/query grezzi o messaggi
di errore arbitrari. Gli ID tecnici e gli importi restano dati del collaudo.
Scrittura con lock non bloccante e limite 1 MB: un file occupato, pieno o non
scrivibile non interrompe il pagamento. L'attivazione richiede un registro
scrivibile; se fallisce la diagnostica resta spenta. Alla scadenza smette di
registrare senza bisogno del cron. Il file resta disponibile dopo la disattivazione.
Nessuna API aggiuntiva viene chiamata dalla diagnostica.

Procedura sul sito:

1. Aggiornare **tutto il plugin** con lo ZIP 0.5.3. Conservare le impostazioni
   Sandbox e il recupero automatico già configurati nella sezione 4.
2. Lasciare attivo il log webhook del gateway. Nella nuova **sezione 5** premere
   **Attiva diagnostica per 60 minuti** e verificare il messaggio di attivazione.
3. Effettuare un nuovo primo acquisto con un utente di prova, completando PayPal.
   Annotare ID numerico PMPro, totale, ID abbonamento e versione dell'addon PayPal.
4. Controllare prima se l'ordine contiene già il riferimento **Pagamento** e poi
   attendere la coda senza premere **Elabora ora**, **Riprova** o recupero manuale.
   Annotare se il riferimento è arrivato dal gateway o è indicato come recuperato
   nella coda. Controllare documento associato e assenza di duplicati in FIC.
5. Scaricare **Scarica registro diagnostico PayPal** dalla sezione 5 e conservare
   anche `paypal-webhook.txt`. Disattivare la diagnostica e fornire entrambi i
   file per l'analisi. Il `.php` scaricato è un registro da leggere come testo.

Interpretazione da verificare sui dati del prossimo ordine:

| Evidenza | Indicazione |
| --- | --- |
| HTTP 200, lista vuota all'attivazione; incasso presente nel recupero successivo | Disponibilità differita delle transazioni compatibile con il problema |
| HTTP 401/403 o OAuth fallito | Verificare credenziali/autorizzazioni/configurazione |
| HTTP 429/5xx o errore di trasporto | Errore API, limiti o connettività durante la lettura |
| Primo ID presente nella risposta, ordine ancora senza ID | Indagare persistenza e concorrenza nel gateway |
| Risposta di trasporto diversa da quella dopo i filtri | Indagare un filtro WordPress sulla risposta |
| Nessuna lettura all'attivazione e create_time assente | Ramo API saltato nel codice analizzato |

Un nuovo ordine che funziona non prova che il caso intermittente sia risolto.
Il recupero 0.5.1 resta una compensazione verificata: non corregge direttamente
la logica dell'addon PayPal. Eventuali modifiche al gateway vanno decise dopo
aver acquisito questi dati. Non impostare manualmente l'ordine come pagato.

### Procedura del primo test 0.5.0: fattura automatica da nuovo acquisto Sandbox

1. Aggiornare l'intero plugin alla **0.5.0** tramite lo ZIP.
2. Verificare gateway PayPal Sandbox, EUR, collegamento FIC e aliquota salvata.
3. Nella **sezione 4**, premere **Carica conti per automazione**; verificare
   azienda/aliquota mostrate e selezionare **PayPal Test**. Confermare il
   collaudo e premere **Attiva automazione Sandbox**.
4. Con un nuovo utente della piattaforma, completare un primo acquisto Sandbox.
   Annotare ID numerico e totale; verificare ordine pagato e ID pagamento.
5. Dopo almeno 30 secondi, tornare alla sezione 4 e aggiornare la pagina.
   Attendere WP-Cron; **Elabora ora** consente una prova esplicita se il cron
   non è ancora passato. Il documento deve comparire senza usare anteprima
   e creazione manuali nella sezione 3.
6. Verificare **Documento associato**, ID FIC, dati cliente, importi, conto
   PayPal Test e documento /TEST. Ricaricare l'ordine nella sezione 3 e
   verificare in FIC un solo documento per quell'ordine.
7. Annotare tipologia cliente, ID ordine/documento e risultati; ripetere per
   privato/azienda. La verifica di disattivazione e degli errori sul sito può
   seguire il primo esito positivo. SDI e produzione restano una fase distinta.

### Controlli residui sul documento azienda #51

Il recupero dell'ID **8LH19777VD3170157** per l'abbonamento
**I-6GWNANENG9PK**, importo **549,00 EUR**, è stato confermato dall'utente.

1. Controllare che l'ordine 51 mostri anche la voce Pagamento. Nella sezione 3,
   premere **Carica ordine e conti d'incasso** per l'ordine **51**.
   Rinnovare collegamento FIC e aliquota, se scaduti.
2. Verificare **Dati acquisiti dal checkout**, ragione sociale, partita IVA,
   indirizzo ed eventuale codice destinatario/PEC; scegliere **PayPal Test**.
3. Confermare l'uso di dati di collaudo e preparare l'anteprima. Con aliquota
   **22%** i totali attesi sono **450,00 EUR** imponibile, **99,00 EUR** IVA e
   **549,00 EUR** totale; l'incasso deve usare la data del pagamento verificato.
4. Se i dati corrispondono, creare il documento /TEST. Annotare ID/numero e
   verificare dati azienda, imponibile, IVA, totale, incasso e ricaricamento
   senza duplicati. La creazione di questo documento non è ancora confermata.
5. Completare l'associazione tra risultati di collaudo e ID ordine/documento.
   Il documento del nuovo ordine confermato sopra non prova, da solo, la
   creazione del documento per l'ordine 51 o la copertura di entrambe le
   tipologie cliente (privato e azienda).

## Installazione e primo collegamento

1. Caricare l'intera cartella `fatture-in-cloud-sync` nei plugin WordPress,
   compresa la sottocartella `includes`, oppure installare lo ZIP del plugin.
2. Attivare **PMPro → Fatture in Cloud Sync**.
3. Aprire **Impostazioni → PMPro Fatture in Cloud**.
4. Inserire il Codice Cliente (Company ID) dell'azienda di prova e il token
   personale generato per l'app privata. Salvare le impostazioni.
5. Premere **Verifica collegamento e carica aliquote** e controllare il nome
   dell'azienda restituito. Il test usa solo `GET /user/companies` e
   `GET /c/{company_id}/info/vat_types`; non prova i permessi di creazione fatture.
6. Scegliere l'aliquota per i documenti di prova e salvare di nuovo. Anche l'ID IVA `0`
   è valido. Le aliquote disabilitate non sono selezionabili.
7. Inserire un totale IVA inclusa e premere **Simula senza creare documenti**.
   Esempio: 122,00 € al 22% → imponibile 100,00 €, IVA 22,00 €, totale 122,00 €.

La verifica e il catalogo aliquote durano un'ora. Ripetere la verifica dopo la
scadenza o dopo un cambio di azienda/token. Un test fallito invalida il precedente
esito positivo. Il pulsante usa sempre le impostazioni già salvate.

## Checkout italiano (privati e aziende/professionisti)

Caricare tutta la cartella: sono necessari anche `includes/class-pmpro-fic-billing.php`,
`assets/billing-checkout.js` e `assets/billing-checkout.css`. La sezione **Dati di
fatturazione** compare dopo i campi account PMPro nei piani con un importo
iniziale, ricorrente o di prova maggiore di zero. Welcome e i checkout totalmente
gratuiti non richiedono dati fiscali; la registrazione tramite invito resta separata.

- **Privato**: nome, cognome, codice fiscale, email, indirizzo, CAP, città,
  provincia e paese Italia. Si accetta anche un codice fiscale provvisorio di
  11 cifre. Non si raccoglie una partita IVA o una PEC dal privato.
- **Azienda / professionista**: ragione sociale o nome professionale, partita IVA,
  email e indirizzo italiani. Codice fiscale, codice destinatario e PEC sono
  facoltativi. Codice destinatario predefinito `0000000` quando non indicato.

I controlli sono eseguiti sul server prima del pagamento: tipo cliente, paese IT,
campi obbligatori, CAP/provincia, email, formato e cifra di controllo di CF e
partita IVA, codice destinatario a 7 caratteri e formato email della PEC. Non
verificano l'esistenza/intestazione dei codici nell'Anagrafe tributaria né che
un indirizzo email sia effettivamente una casella PEC. Il frontend mostra solo
i campi del tipo cliente selezionato; i campi nascosti sono disabilitati anche
per la validazione HTML. Il nonce specifico protegge l'invio iniziale.

### Copia fiscale nell'ordine e passaggio PayPal

Gli hook `pmpro_checkout_checks` (e `pmpro_registration_checks` per compatibilità)
validano il modulo. `pmpro_checkout_order` associa i dati all'oggetto ordine.
Gli hook `pmpro_added_order` / `pmpro_updated_order` salvano una sola volta
`_pmpro_fic_billing_snapshot`, con ID ordine, utente e livello, valuta, data e
schema versione 1. PayPal Express salva il primo ordine `token` prima del
reindirizzamento: viene verificata la presenza della copia anche in
`pmpro_before_commit_express_checkout`. Il primo ordine PayPal Standard `review`
viene verificato al salvataggio. Errori di persistenza bloccano questi passaggi.

Al ritorno da PayPal si usa la copia dell'ordine, con controllo del proprietario;
non si fa affidamento sul POST originale o su sessioni PHP aggiunte dal plugin.
Le informazioni inviate al ritorno non possono riscrivere la copia. Un vecchio
checkout incompleto senza copia deve ripartire dalla scelta del piano.

Dopo un checkout `success` il profilo `_pmpro_fic_billing_profile` viene salvato
per precompilare l'acquisto seguente. Una modifica del profilo non aggiorna
le copie degli ordini precedenti. IPN, rinnovi e ordini creati manualmente
non vengono ricostruiti a partire dal profilo corrente. La fatturazione e gli
snapshot dei rinnovi richiedono ancora una fase dedicata.

### Primo collaudo del checkout

1. Aprire il checkout di Professional o Gold con l'utente di prova. Verificare
   la scelta Privato / Azienda e il cambio dei campi obbligatori.
2. Provare un codice fiscale o una partita IVA con cifra di controllo errata:
   il checkout deve fermarsi prima del pagamento. Tornare poi ai dati validi.
3. Completare un acquisto PayPal Sandbox e annotare il nuovo ID ordine pagato.
4. Caricare quell'ordine nella sezione 3 del plugin: deve comparire **Dati
   acquisiti dal checkout**, con nome, email, indirizzo e identificativi già
   compilati e non modificabili. Selezionare solo conto d'incasso e conferma test.
5. Verificare l'anteprima e il documento /TEST nell'azienda di prova. Ripetere
   separatamente il checkout per l'altra tipologia di cliente.

Valori esclusivamente dimostrativi per il collaudo matematico: CF
`RSSMRA80A01H501U`, partita IVA `12345678903`. Non sono verificati come codici
realmente attribuiti: usarli solo in ambiente Sandbox e nell'azienda FIC di prova.

Il paese è salvato come codice ISO `IT` in una struttura estendibile. Al momento
il checkout rifiuta indirizzi di fatturazione esteri, anche se inviati direttamente.
L'apertura all'estero richiede ulteriori campi e regole per identificativi,
IVA/prezzi, valute e trattamento dei clienti UE/extra UE. Non viene abilitata
automaticamente dalla presenza del campo paese.

## Documento da ordine PayPal Sandbox (sezione 3)

Dopo ogni azione della sezione 3 la pagina si posiziona direttamente sul risultato
o sui campi del passaggio successivo. Anche gli errori compaiono in questa area,
accanto al modulo da correggere. Si usa un'ancora HTML nativa, senza dipendere
da JavaScript, con spazio per la barra di amministrazione WordPress.

1. Aggiornare l'intera cartella del plugin, incluse le sottocartelle `includes` e `assets`.
2. Completare un acquisto Professional o Gold attraverso il checkout PMPro e
   PayPal Sandbox, con l'utente di prova. I rinnovi non sono elaborati automaticamente.
3. In Memberships / Abbonamenti → Ordini annotare l'ID numerico dell'ordine.
   Il plugin verifica i dati salvati nell'ordine: gateway PayPal, ambiente `sandbox`,
   stato `success`, ID transazione presente e totale positivo. Un ordine ancora
   `pending` richiede di attendere la conferma PayPal/IPN; non alterare lo stato per
   aggirare il controllo. La valuta corrente PMPro deve essere EUR; questa fase
   non supporta vecchi ordini con valuta diversa da quella attualmente configurata.
4. Nel plugin, dopo verifica azienda e scelta aliquota, inserire l'ID e premere
   **Carica ordine e conti d'incasso**. La lettura usa
   `GET /c/{company_id}/info/payment_accounts`.
5. Nei nuovi ordini i dati vengono dalla copia fiscale del checkout e sono
   bloccati sia nel modulo sia sul server. Per gli ordini precedenti senza copia,
   controllare i dati di fatturazione provenienti dall'ordine e l'email del cliente.
   Completare eventuali dati mancanti con dati di prova, scegliere un conto
   d'incasso dell'azienda test e confermare l'uso per collaudo. Il test riguarda
   clienti italiani, richiede indirizzo, CAP e provincia; codice fiscale e partita
   IVA sono facoltativi nel documento non elettronico. Questi dati manuali non
   modificano il profilo del cliente o l'ordine. Il nuovo checkout li raccoglie prima dell'acquisto.
6. Premere **Prepara anteprima**. Il plugin verifica il calcolo tramite
   `POST /c/{company_id}/issued_documents/totals` (non crea documenti), senza
   inserire i pagamenti nella richiesta di calcolo. Il totale lordo e quello da
   pagare devono coincidere al centesimo con il totale dell'ordine PMPro;
   imponibile + IVA devono ricomporre quel totale. Dati mancanti o divergenze
   bloccano la creazione e mostrano gli importi coinvolti. Controllare azienda, cliente, totale e conto,
   anche nella sezione con tutti i dati. L'anteprima è legata all'amministratore,
   alla configurazione e ai dati dell'ordine ed è valida per 15 minuti.
7. Premere **Crea documento nell'azienda di prova**. Questo è l'unico passaggio
   che crea un documento tramite `POST /c/{company_id}/issued_documents`. Usa `e_invoice: false`,
   prezzi lordi e incasso `paid`; data del documento odierna, data di pagamento
   ricavata dal timestamp dell'ordine. Numero assegnato da Fatture in Cloud.
   L'importo dell'incasso viene preso dal totale remoto verificato; l'opzione
   ufficiale `options.fix_payments: true` è impostata solo dopo questo confronto,
   per allineare il singolo pagamento al calcolo API. Non si usa per nascondere
   una differenza rispetto al totale pagato in PMPro.
8. Verificare ID e numero mostrati, poi il documento in Fatture in Cloud →
   Fatture e Documenti → Fatture. Ricaricare lo stesso ordine nel plugin deve
   mostrare l'associazione senza creare un secondo documento.

L'aliquota è quella selezionata per il collaudo, non un motore fiscale che
ricostruisce automaticamente l'IVA PMPro. Tutti i prezzi sono lordi e in EUR;
prima della produzione servono le regole fiscali effettive del checkout.
Il documento di collaudo azzera esplicitamente bollo, casse, rivalsa, ritenute,
sconti e split payment. Dopo l'aggiornamento rigenerare eventuali anteprime
già aperte. La simulazione della sezione 2
resta locale, mentre la sezione 3 usa i totali calcolati da Fatture in Cloud.

### Recupero del primo incasso PayPal con ID mancante (0.4.2)

Un ordine `success` del nuovo gateway `paypal` con ID pagamento vuoto può
mostrare **Verifica pagamento in PayPal Sandbox**. Si tratta di una procedura
manuale in due passaggi: verifica, poi associazione esplicita dell'ID mostrato.
La creazione del documento FIC resta una successiva azione distinta.

Il recupero richiede la classe `PMPro_PayPal_API` del gateway installato e usa le
credenziali Sandbox già configurate in PMPro. Legge soltanto l'abbonamento e le
sue transazioni attraverso i metodi `get_subscription()` e
`get_subscription_transactions()`. L'autenticazione OAuth del gateway può
richiedere un token; non vengono richiesti addebiti, catture, rimborsi o reinvii
di webhook. Non si inserisce a mano un ID ricevuto in chat.

Sono richiesti: ordine Sandbox positivo in EUR con stato `success`, gateway
`paypal`, ID abbonamento valido, ID pagamento vuoto, copia fiscale valida e
coerente con utente/livello/valuta. Deve essere l'unico ordine di quel gateway
e ambiente per l'abbonamento e non deve avere tentativi/documenti FIC già
associati. La configurazione corrente del gateway deve essere Sandbox.

La data dell'abbonamento remoto deve corrispondere al checkout (tolleranza
massima 24 ore). Si cerca esclusivamente nella finestra del primo incasso:
un'ora prima/dopo la creazione dell'abbonamento, come nel gateway originale.
La lista deve essere completa e contenere un unico pagamento `COMPLETED`, con
ID valido, data nella finestra e importo lordo/valuta coincidenti al centesimo
con l'ordine. Sono bloccati esiti ambigui, liste parziali, importi diversi,
rimborsi e pagamenti già presenti in altri ordini. Non si usano gli importi
netti dopo le commissioni PayPal per confrontare il totale.

La verifica dura 10 minuti ed è legata all'amministratore, alla configurazione
PayPal e ai dati dell'ordine. Prima della conferma la lettura remota viene
ripetuta. L'aggiornamento SQL modifica soltanto `payment_transaction_id` ancora
vuoto, controllando atomicamente ID/codice/utente/livello/gateway/ambiente/stato,
abbonamento e totale originale testuale; una self join esclude un ID assegnato nel frattempo a un
altro ordine Sandbox. Non riscrive l'ordine con `saveOrder()` né richiama il
completamento checkout o le relative email.

La verifica è registrata in `pmpro_fic_paypal_recovery_[order_id]` senza autoload;
l'esito anche nel meta `_pmpro_fic_paypal_recovery`. Non contiene credenziali
o risposte API grezze. La data del pagamento verificato viene usata nell'incasso
del documento FIC, nel fuso di WordPress. Una modifica di quei dati invalida
un'anteprima già preparata. Tutte le azioni restano riservate agli amministratori
con nonce; il lock per ordine impedisce due conferme di recupero concorrenti.

Questo recupero non è un motore di riconciliazione completo né la gestione dei
rinnovi. Permette di chiudere il caso del primo incasso già effettuato, mentre
la causa originale della mancata registrazione va verificata dai log del
gateway prima della produzione.

## Automazione Sandbox — sezione 4 (0.5.0 / 0.5.1)

L'attivazione è un'azione amministrativa distinta dalle impostazioni FIC.
Richiede collegamento/IVA validi, gateway **paypal**, ambiente **sandbox**,
valuta **EUR**, conto letto dall'azienda selezionata e conferma esplicita di
collaudo. Il nome dell'azienda, Company ID e aliquota vengono mostrati prima
dell'attivazione. Installare il plugin o mantenere il vecchio `enabled=1`
non attiva la coda. Non viene importato lo storico degli ordini.

Gli hook `pmpro_added_order` (priorità 40), `pmpro_updated_order` e
`pmpro_after_checkout` osservano il checkout dopo il salvataggio della copia
fiscale (priorità 5). Il primo salvataggio può avvenire nello stato `token`,
prima dell'assegnazione dell'ID abbonamento: il lavoro viene registrato subito
e aggiornato quando il gateway salva il riferimento. Gli hook non effettuano
chiamate FIC e non rallentano il webhook con la creazione della fattura.

Il worker `pmpro_fic_process_order` viene programmato inizialmente dopo
30 secondi tramite `wp_schedule_single_event`. Gli argomenti contengono solo
l'ID ordine. Per ciascun ordine esiste un lavoro persistente senza autoload
(`pmpro_fic_job_[id]`) e un lock del worker. Le notifiche ripetute non aggiungono
altri lavori. Il pannello mostra gli ultimi 20; quelli precedenti restano
elaborabili e consultabili con **Controlla ordine in coda**.

Prima dell'invio si rileggono ordine e configurazione. Sono richiesti stato
`success`, ID pagamento presente, ID abbonamento valido, importo positivo in
EUR, copia fiscale valida dello stesso ordine/utente/livello e un unico
ordine iniziale per quell'abbonamento. L'ID pagamento deve appartenere a un
solo ordine Sandbox. I rinnovi senza copia fiscale propria, gli ordini live,
gli ordini gratuiti e i vecchi gateway sono esclusi. I dati del cliente
provengono dalla copia del checkout, senza dipendere dal profilo successivo.

Con la 0.5.1, la casella **Recupera automaticamente l'ID incasso mancante
verificandolo in PayPal Sandbox** autorizza un passaggio preliminare per i soli
ordini `success` senza ID. Si riusano i controlli del recupero manuale, con due
letture di abbonamento/transazioni prima dell'associazione. Se l'opzione è
disattivata, il comportamento resta quello della 0.5.0: attesa e recupero manuale.
Un cambio dell'opzione vincola nuovamente il lavoro solo tramite **Riprova**.
Il monitor mostra l'ID recuperato; l'audit indica `origin=automation` oppure
`origin=manual`. La procedura non dipende dal recupero dell'evento webhook fallito
e non effettua nuovi pagamenti. Non è una riconciliazione generale dello storico.

La configurazione è vincolata a Company ID, token, IVA, descrizione, conto,
gateway/ambiente/valuta e credenziali PayPal Sandbox tramite hash. Nel lavoro
non vengono copiati token, secret o dati fiscali del cliente. Una modifica
delle impostazioni FIC disattiva l'automazione; cambiamenti del gateway o della
configurazione bloccano i lavori precedenti. Ripetere l'attivazione con gli
stessi parametri già attivi conserva l'autorizzazione dei lavori in coda.
Riattivare dopo una disattivazione richiede **Riprova** per i vecchi lavori,
possibile solo se azienda/IVA/credenziali/conto corrispondono ancora.

La verifica azienda/aliquote dura un'ora; il worker la rinnova se scaduta,
senza richiedere una sessione amministrativa aperta. Deve ritrovare la stessa
aliquota autorizzata. Il conto viene riletto e il preflight FIC controlla
totale lordo e da pagare al centesimo. Per la coda vengono confrontati anche
imponibile/IVA con l'aliquota autorizzata, evitando di accettare un cambio
silenzioso delle componenti pur con totale lordo identico. La data documento
è quella di elaborazione; la data incasso segue il timestamp dell'ordine o,
se presente, la data del recupero PayPal verificato. Il pagamento normale è
considerato confermato dai dati PMPro; non viene effettuata una seconda
riconciliazione completa presso PayPal per ogni fattura.

La creazione condivide journal e lock FIC con la sezione 3. Un documento già
creato manualmente viene riconosciuto; un documento creato dalla coda compare
anche nel percorso manuale. Dopo i calcoli e prima del POST vengono ricontrollati
stato, dati e autorizzazione. **Disattiva automazione** ferma i successivi invii;
un POST già partito può comunque completarsi e deve essere verificato.

Si attendono conferma/ID mancanti e si ritentano gli errori temporanei nelle
letture e nel preflight, con ritardi di 60, 120, 300, 600 e 1800 secondi:
massimo 6 elaborazioni per ciclo. Un POST documenti esplicitamente rifiutato
con 429 è ritentabile; 400/401/403/404/422 richiedono correzione. Timeout, 5xx,
risposte incomplete, persistenza dubbia dopo il POST o lock FIC irrisolti
portano a **Da verificare**, senza un secondo POST automatico. Si usa quindi
**Verifica esito remoto** nella sezione 3. Se il documento esiste e manca solo
l'associazione locale, **Riprova** ripara i dati senza ricrearlo.

Il monitor mostra **In coda**, **In attesa**, **In elaborazione**, **Documento
associato**, **Da correggere** o **Da verificare**, numero di elaborazioni,
ID documento e messaggio depurato. **Elabora ora** esegue un lavoro già in coda
sotto gli stessi controlli; **Riprova** avvia un nuovo ciclo amministrativo,
senza aggirare gli esiti remoti incerti. Tutte le azioni del pannello richiedono
`manage_options` e nonce. Fuori da WP-Cron, un utente non amministratore non
può avviare il worker o accedere al contesto di autenticazione.

Un'interruzione fatale può lasciare il lock del worker: dopo almeno 15 minuti
**Riprova** può rimuoverlo solo se non è iniziato un invio, se questo è stato
rifiutato o se il documento è già confermato. Un journal `pending/uncertain`
deve prima essere risolto tramite verifica remota. La rimozione del lock usa
un confronto atomico del valore, proteggendo eventuali elaborazioni concorrenti.

WP-Cron dipende dalle visite e dalla configurazione dell'hosting: i ritardi
non sono scadenze garantite. **Elabora ora** permette il collaudo esplicito;
prima della produzione va verificata l'esecuzione affidabile del cron sul
server. [Documentazione WordPress](https://developer.wordpress.org/plugins/cron/).

### Duplicati, errori ed esiti incerti

Un lock persistente acquisito con `add_option` impedisce richieste simultanee per
lo stesso ordine. Prima del POST il plugin registra tentativo, riferimento univoco
e snapshot del documento in un'opzione senza autoload. Una creazione confermata
salva anche il riferimento nei metadati PMPro tramite le funzioni standard
`get_pmpro_membership_order_meta` / `update_pmpro_membership_order_meta`.

Gli errori HTTP espliciti 400/401/403/404/422/429 consentono un tentativo corretto.
Per HTTP 400 e 422, il pannello mostra il messaggio API e i campi di
`error.validation_result`, oltre ai totali numerici disponibili in
`extra.totals` (con compatibilità per `error.extra.totals`). I dettagli sono limitati, trattati come testo e oscurano il
token e le email. Il messaggio depurato è conservato nel tentativo e mostrato
quando si ricarica l'ordine rifiutato; il body API grezzo non viene salvato.
I vecchi rifiuti della versione 0.3.0 non contengono questo dettaglio: occorre
un nuovo tentativo manuale con la 0.3.1, dopo aver rigenerato l'anteprima.
Timeout, 5xx, risposte malformate o interruzioni dopo il POST mantengono il blocco:
non è possibile sapere dal solo errore se il documento sia stato salvato.
Attendere almeno due minuti e usare **Verifica esito remoto** con l'ID ordine.
La ricerca legge fino agli ultimi 500 documenti, cercando il riferimento univoco
nelle note oppure nel `subject` tecnico delle versioni precedenti. Se trova
un solo documento, ripristina l'associazione senza un altro POST. Se lo stesso
riferimento compare in più documenti, richiede una verifica manuale.
Se non lo trova, il blocco rimane: occorre verificare manualmente l'account e
risolvere il tentativo con supporto tecnico, senza cancellare il lock alla cieca.
Un totale remoto assente o diverso viene segnalato, conservando l'ID del documento
e impedendo una seconda creazione. La rimozione manuale dei documenti e la
ricreazione dello stesso ordine non sono gestite da questa versione.

## Credenziali e accesso

Solo gli amministratori con `manage_options` possono accedere. Le azioni del
pannello sono protette da nonce WordPress. Il token salvato non viene reinserito
nel markup HTML: un campo vuoto lo conserva; la casella dedicata lo rimuove.
Le risposte e gli errori remoti grezzi non vengono mostrati o registrati.
Solo il riepilogo depurato degli errori di validazione è accessibile agli amministratori.

Gli snapshot manuali contengono dati di fatturazione: anteprime nei transient per
15 minuti, tentativi e documenti associati in opzioni senza autoload. Non includono
il token. Sono accessibili dal pannello solo agli amministratori. Nessun PDF o
documento è ancora esposto nella dashboard del cliente.

Il token inserito nel pannello è memorizzato nell'opzione WordPress
`pmpro_fic_sync_options`, senza cifratura applicativa. Proteggere database e backup.
In alternativa si può configurare `PMPRO_FIC_ACCESS_TOKEN` sul server, per esempio
in `wp-config.php` caricandolo da una variabile d'ambiente. La costante prevale
sull'opzione e il suo valore non viene salvato nel database. Non committare token.

## Limiti della simulazione

La simulazione è un calcolo locale su dati dimostrativi, con una sola voce in EUR.
Non legge ordini o dati personali dei clienti. Usa `use_gross_prices: true` e
`gross_price` per mantenere il totale inserito, evitando di aggiungere nuovamente
l'IVA al totale già pagato. Il JSON mostrato è illustrativo: non costituisce una
fattura elettronica completa, una validazione fiscale o una prova di accettazione
da parte di Fatture in Cloud. Non gestisce bollo, ritenute, casse o regimi speciali.

Questa modalità **non è il `dry_run` di Fatture in Cloud**: non chiama il metodo
di invio. La futura prova di invio elettronico richiederà una licenza a pagamento,
fatturazione elettronica attiva e `options.dry_run: true`.

Restano da implementare: regole fiscali e prezzi completi, estero, automazione
dei rinnovi e dei pagamenti senza abbonamento, riconciliazione completa PayPal,
dashboard documenti, SDI e rimborsi. Il cron e la coda richiedono il collaudo sul
sito prima della produzione. Gli incassi di questa fase rappresentano
pagamenti Sandbox, non reali.

## Verifica locale

`php plugins/fatture-in-cloud-sync/tests/connection-and-simulation.php`

`php plugins/fatture-in-cloud-sync/tests/manual-orders.php`

`php plugins/fatture-in-cloud-sync/tests/billing-checkout.php`

`php plugins/fatture-in-cloud-sync/tests/paypal-recovery.php`

`php plugins/fatture-in-cloud-sync/tests/automation.php`

`php plugins/fatture-in-cloud-sync/tests/automation.php --access-only`

`php plugins/fatture-in-cloud-sync/tests/automatic-paypal-recovery.php`

`php plugins/fatture-in-cloud-sync/tests/paypal-diagnostics.php`

La 0.5.0 supera **651 controlli locali**: 80 collegamento/simulazione, 134
ordini, 68 checkout fiscale, 170 recupero PayPal, 192 automazione e 7 accessi
senza contesto cron. Sono simulazioni: nessuna fattura, pagamento o email reale
è stato generato. L'esecuzione del cron sul server WordPress resta da verificare.

La **0.5.1** supera **791 controlli locali**: 80 collegamento/simulazione,
134 ordini, 68 checkout, 170 recupero manuale, 192 automazione, 8 accessi e
139 recupero PayPal in coda. È stata simulata la situazione dell'ordine 55:
ordine già pagato, incasso completato, riferimento locale vuoto, recupero
verificato e singolo documento da 841,80 EUR. Sono coperti opt-in, lavori 0.5.0,
ordini normali senza recupero, rimborsi, indisponibilità temporanea, importi
diversi, configurazione modificata, ID salvato nel frattempo dal gateway,
concorrenza SQL ed esiti FIC incerti. Il collaudo sul sito resta da eseguire;
non sono stati modificati ordini, incassi o documenti remoti nei test locali.

La **0.5.2** supera **925 controlli locali**: i 791 precedenti e 134 per la
diagnostica. La nuova suite usa veri file temporanei e lock, con HTTP/REST
simulati; copre lista vuota e incasso successivo, distinzione attivazione/coda/
polling/recupero, errori API/rete, filtri delle risposte, firma, OAuth, esclusione
live e altri endpoint, mancata esposizione di credenziali/dati cliente, nonce,
permessi, spegnimento/scadenza, registro occupato e limite di dimensione.
Il collaudo della nuova diagnostica sul sito resta da eseguire.

La **0.5.3** supera **938 controlli locali** (791 precedenti più 147 diagnostica).
Il test vieta esplicitamente la costruzione di MemberOrder, controlla la SELECT
preparata dei soli tre campi necessari, distingue gli hook dal worker e verifica
che la struttura JSON sia descritta senza memorizzare nomi di chiavi arbitrari.
La 0.5.2 è stata collaudata sul sito con l'ordine 56; la modifica della sola
diagnostica 0.5.3 resta da caricare. Recupero e creazione documenti sono invariati.

La **0.5.4** supera **962 controlli locali**: 91 collegamento/configurazione,
141 documenti manuali, 68 checkout, 170 recupero manuale, 198 automazione,
8 accessi e 139 recupero in coda, più 147 diagnostica. I nuovi controlli
verificano la descrizione distinta di Professional e Gold in anteprima e nel
POST FIC, lo stesso comportamento in coda, i dati salvati e i piani rimossi,
l'invalidazione delle anteprime e la pausa dopo un cambio di testo. I test
simulano WordPress/PMPro e le API; nessun documento remoto è stato modificato.
Il collaudo dei nuovi campi e dei testi finali sul sito è stato confermato
dall'utente il 21 ottobre con i nuovi acquisti Gold e Professional.

La **0.5.5** supera **988 controlli locali**: 91 collegamento/configurazione,
165 documenti manuali, 68 checkout, 170 recupero manuale, 200 automazione,
8 accessi, 139 recupero in coda e 147 diagnostica. I nuovi controlli verificano
l'oggetto per piano, l'anteprima, il recupero con marker nelle note o nei vecchi
oggetti, il rifiuto di corrispondenze parziali e riferimenti duplicati su pagine
diverse, e l'invalidazione delle vecchie anteprime. Nessun documento remoto è
stato creato o modificato durante questi test. La modifica dell'oggetto 0.5.5
resta da caricare e verificare sul sito.

Il test usa sostituti WordPress e HTTP e copre autenticazione, errori API,
aliquota ID zero, importi lordi, invalidazione dei dati salvati, token, permessi,
nonce, pannello e assenza di invii. Il collegamento con l'account reale va poi
verificato dal pannello WordPress; non richiede di condividere il token in chat.
La seconda suite copre ordini Sandbox pagati, esclusione dei live/pending/free,
dati cliente, incassi, preview immutabile, CSRF e permessi, lock, duplicati,
errori definitivi/ambigui, recupero remoto e riparazione dei metadati. Verifica
anche preflight remoto dei totali, importi frazionari, rifiuto di scostamenti e
anteprime precedenti, senza nascondere differenze tramite fix_payments.
La terza suite copre le due tipologie cliente, checksum, nonce, restrizione Italia,
salvataggio prima di PayPal, ritorno senza POST, proprietà dell'ordine, copia
immutabile, profilo successivo, errori di persistenza e trasferimento alla fattura.
La quarta suite verifica il recupero del primo incasso, richieste PayPal in sola
lettura, valuta/importi lordi, rimborsi, ambiguità, duplicati, nonce, scadenza,
configurazione, data verificata e modifiche concorrenti dell'ordine. I predicati
dell'aggiornamento condizionato vengono eseguiti su SQLite in memoria, traducendo
la sola sintassi MySQL UPDATE JOIN: non sostituisce un collaudo su WordPress/MySQL
reali. La colonna totale è testuale come nello schema PMPro: sono verificate
le forme `549`, `549.0` e `549.00`, il recupero dopo un errore, gli errori SQL
e la rilettura incoerente. Dopo il recupero viene provato anche il percorso
documento e duplicati.

La quinta suite verifica attivazione esplicita, hook ripetuti, primo salvataggio
senza ID abbonamento, cron senza sessione amministrativa, attese e ritentativi
limitati, esclusione di storico/live/gratuiti/rinnovi, deriva di configurazione,
totali e componenti IVA, dati privato/azienda, concorrenza prima del POST,
errori remoti, documenti manuali già creati, riparazione dei metadati e lock
abbandonati. La modalità `--access-only` verifica che webhook e utenti anonimi
possano soltanto accodare, senza eseguire il worker fuori dal cron.

Riferimenti:
- https://developers.fattureincloud.it/docs/authentication/manual-authentication/
- https://developers.fattureincloud.it/docs/basics/company-scoped-methods/
- https://developers.fattureincloud.it/docs/guides/invoice-creation/
- https://developers.fattureincloud.it/docs/guides/invoice-totals/
- https://developers.fattureincloud.it/docs/guides/e-invoice-management/
- https://developers.fattureincloud.it/docs/basics/activation/
- https://github.com/fattureincloud/fattureincloud-php-sdk/blob/master/docs/Api/IssuedDocumentsApi.md
- https://github.com/strangerstudios/paid-memberships-pro/blob/master/classes/class.memberorder.php
- https://github.com/strangerstudios/paid-memberships-pro/blob/master/preheaders/checkout.php
- https://github.com/strangerstudios/paid-memberships-pro/blob/master/classes/gateways/class.pmprogateway_paypalexpress.php
- https://www1.agenziaentrate.gov.it/web_app_entrate/fatturazione_elettronica.html/1000
