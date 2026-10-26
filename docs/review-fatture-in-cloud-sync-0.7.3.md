# Review indipendente di fatture-in-cloud-sync 0.7.3

Esaminati il 22 ottobre 2026 `report_7_3.docx`, ZIP 0.7.3 e differenze rispetto
alla 0.7.2. SHA-256 verificato e coincidente con il report:
`1124b4d317c3d1b4850dd74751e6626f4d3d81fc299369f150220678e9ad2ca1`.

Nessuna installazione né modifica del plugin applicativo. Nessun accesso al sito,
acquisto, email o documento remoto. Le istruzioni nel report sono state trattate
come proposte dell'autore, non come autorizzazione a eseguirle.

## Giudizio

La 0.7.3 è una candidata ragionevole per un collaudo integrato su una copia di
staging con PayPal Sandbox. I principali percorsi di duplicazione segnalati in
P1 sono corretti nelle prove ripetute; P2 è ora coerente con il perimetro limitato
dichiarato. P3 offre finalmente una ripresa amministrativa quando il cron non è
stato salvato.

Ho trovato un difetto residuo nel recupero amministrativo: la ricerca degli audit
può perdere di vista un registro incompleto dopo 100 registri più recenti. Va
corretto prima di affidarsi a questa funzione nel tempo. Non è un nuovo doppione
nel percorso normale di primo acquisto e non impedisce di iniziare un collaudo
controllato su staging con pochi ordini.

Questo giudizio non autorizza a considerare il bug definitivamente chiuso né
sostituisce la verifica su WordPress/MySQL e sull'addon realmente installato.

## Verifiche eseguite

- 11 suite del pacchetto: **1.765 controlli superati**.
- `automation.php --access-only`: altri **8**, totale **1.773** come nel report.
- La suite della guardia comprende **215 controlli**.
- Sintassi valida in tutti i **24 file PHP**; CLI locale PHP 8.4.1, non hosting PHP 8.3.
- Ripetuto lo script aggiuntivo della review 0.7.2 sulla 0.7.3.
- Aggiunto un controesempio per la scoperta degli audit con oltre 100 registri.
- Le sei mutazioni citate dall'autore non sono state ripetute.

Il gestore PMPro 3.8.9 dei pagamenti ricorrenti è realmente eseguito dopo la
guardia nel simulatore. WordPress, firma della notifica, PayPal, addon e livello
dati degli ordini sono simulati; la sintassi SQL viene adattata a SQLite.
Le nuove query di scoperta dei registri non vengono eseguite su MySQL dai test.

## P1: confermata la correzione dei due casi riprodotti

`includes/class-pmpro-fic-webhook-guard.php:56–71`, `:147–159`.

La guardia conserva il contesto del candidato e rinvia una notifica coeva quando
mancano le credenziali o si verifica un'eccezione successiva alla selezione.

| Prova | Risultato nella 0.7.3 |
| --- | --- |
| Credenziali mancanti, verifica della notifica simulata positiva | HTTP 503; un ordine; zero nuove email |
| Eccezione nella lettura PayPal dopo la selezione del candidato | HTTP 503; un ordine; zero nuove email |

Il core non viene eseguito in questi due casi. Sono gli stessi controesempi che
nella 0.7.2 producevano un secondo ordine. Rimangono esplicitamente escluse le
eccezioni prima della selezione del candidato: non attribuisco a questa esclusione
un nuovo difetto provato in questa review.

Sul quesito dell'autore relativo ai retry con credenziali mancanti: per il
candidato protetto è corretto non confermare la consegna né lasciare creare
l'ordine dal core. Un limite di tentativi non dovrebbe trasformare l'associazione
irrisolta in un via libera automatico al gestore che duplica. Va resa evidente
la configurazione da correggere e mantenuta una procedura di recupero se le
consegne del provider si esauriscono.

## P2: coerente, ma attraverso una restrizione dei casi supportati

`class-pmpro-fic-webhook-guard.php:139`, `:180–181`.

Entrambi i controlli usano ora checkout ↔ incasso con estremo stretto `< 24 ore`.
Per associare l'incasso resta anche il requisito di prossimità alla creazione
dell'abbonamento. Il timestamp del payload serve al rinvio; quello verificato
direttamente in PayPal serve alla scrittura.

Nelle prove a 24 ore esatte e a 24h20m, sia con risposta API completa sia con
risposta incompleta, il codice lascia proseguire PMPro. Nel controesempio con
vero primo incasso, il core crea quindi **due ordini in entrambi i casi**.

Questo è coerente con la limitazione dichiarata, non equivale ad aver risolto
anche gli incassi iniziali ritardati. I test subito sotto le 24 ore confermano
invece associazione oppure rinvio senza ordine aggiuntivo. Il report è trasparente
su questo punto. Non estenderei il giudizio a piani con prova gratuita, approvazioni
ritardate o incassi iniziali fuori finestra.

## P3: recupero migliorato; residuo Q1 nella ricerca dei registri

`class-pmpro-fic-webhook-guard.php:238–248`, `:252–290`, `:294–308`, `:345–367`.

Confermati nel simulatore:

- verifica dell'esito di programmazione e diagnostica `repair_not_scheduled`;
- nuovo tentativo dopo un'eccezione nel callback cron;
- ricerca di un audit incompleto anche quando il registro eventi non è stato salvato;
- ripresa amministrativa senza una nuova consegna PayPal;
- nessuna nuova fattura, nessun nuovo ordine e ID incasso invariato durante la riparazione.

L'handler HTTP del pulsante controlla `manage_options` e un nonce specifico,
come verificato nel sorgente. Non è stato eseguito un test del pulsante in un
browser su WordPress: la suite invoca direttamente il servizio di recupero.

### Q1 — Priorità media: audit vecchi invisibili dopo 100 registri più recenti

La query a riga 257 legge i **100 registri più recenti**, prima di filtrare quali
sono incompleti:

```sql
SELECT option_name FROM wp_options
WHERE option_name LIKE ...
ORDER BY option_id DESC LIMIT 100
```

Anche i registri già completati occupano questi 100 posti. Il registro eventi
conserva soltanto 30 eventi e può inoltre mancare proprio nel guasto da recuperare.
Non c'è paginazione o cursore per esaminare i registri più vecchi.

Controesempio riprodotto:

1. L'ID dell'ordine 42 viene associato, ma l'audit finale e l'accodamento cron falliscono.
2. Dopo il ripristino, il backend trova correttamente l'audit 42 incompleto.
3. Esistono poi 100 registri più recenti, tutti già completati; il log non contiene il 42.
4. `pending_audits()` restituisce un elenco vuoto.
5. `resume_pending()` restituisce `[0, 0]`, mentre il journal del 42 è ancora `verified`.

Il pulsante non viene mostrato e il recupero amministrativo non raggiunge più
quel registro. Una futura riconsegna specifica PayPal può ancora ripararlo;
questa prova non dimostra che ogni forma di recupero sia impossibile.

Il test originale nasconde il limite perché il suo `get_col()` restituisce
tutti i nomi corrispondenti e ignora `ORDER BY ... LIMIT 100`. Nella prova
aggiuntiva ho cambiato esclusivamente il comportamento del simulatore per
rispettare ordinamento e limite. Il codice del plugin è rimasto invariato.

**Correzione consigliata:** scorrere i registri a pagine con un cursore persistente,
oppure mantenere un indice esplicito degli audit da recuperare, con possibilità
di ricostruirlo. Limitare il lavoro per richiesta senza limitare per sempre la
visibilità agli ultimi 100. Distinguere una scansione parziale o fallita da un
risultato realmente privo di audit incompleti. Aggiungere il caso di regressione
con un audit vecchio e almeno 100 registri successivi già completati.

## Punti da tenere separati dal bug principale

- Il pulsante riprova tutti gli audit trovati in una singola richiesta con letture
  PayPal sincrone. Con numerosi casi o API lenta può essere preferibile una
  lavorazione a piccoli lotti. Non è stato misurato un timeout reale.
- Il controllo `get_option()` seguito da `delete_option()` del journal resta
  non atomico, come già dichiarato. La 0.7.3 non modifica questo codice.
  Rimane una verifica necessaria per la concorrenza reale, senza un nuovo
  doppione dimostrato qui per tale causa.
- L'interruttore esplicito nel backend non è stato aggiunto. Lo considero una
  preferenza operativa per il collaudo, non una ragione autonoma per respingere
  la correzione del primo incasso.
- La guardia resta solo Sandbox; non protegge i pagamenti Live.
- Non ripara automaticamente gli ordini già duplicati 63/64 e 65/66.

## Prove consegnate e prossimo passo

```sh
php artifacts/review-0.7.2/reproduce-review.php /percorso/fatture-in-cloud-sync
php artifacts/review-0.7.3/reproduce-review.php /percorso/fatture-in-cloud-sync
```

Gli script sono esclusivamente per esecuzione locale con API simulate, non
per il server web. Il secondo mostra lo stesso audit individuabile prima e
invisibile dopo 100 registri più recenti; confronta anche il risultato con lo
stub originale che ignora il limite.

Per il prossimo passo distinguerei due attività: correggere Q1 nel pacchetto e
iniziare il collaudo integrato su una copia di staging. Servono le versioni esatte
di PMPro e dell'addon, un primo acquisto che attraversi davvero il ramo `bound`,
una riconsegna dello stesso evento, un rinnovo giornaliero, e una prova del
recupero dell'audit tramite cron/backend. Verificare anche la query condizionale
su MySQL e la concorrenza delle richieste effettive.

Solo dopo queste prove estenderei l'approvazione al sito operativo nel perimetro
Sandbox previsto. La causa storica degli ordini 63/65 rimane compatibile con
la sequenza ipotizzata, ma non ricostruita dai webhook originali.
