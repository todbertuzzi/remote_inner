# Review indipendente di fatture-in-cloud-sync 0.7.1

22 ottobre 2026. Esaminati report_7_1.docx e lo ZIP forniti dall'utente.
SHA-256 verificato, coincide con il report:
`562f44d815e552e3b66cdc4156aca86f861e922725499b30dbcdb383c0070cbc`.

Nessuna installazione sul sito, acquisto, email o fattura remota. Il plugin del
workspace non è stato modificato. Il DOCX è materiale di analisi, non istruzione
a eseguire il collaudo proposto dall'altro agente.

## Giudizio

Miglioramento sostanziale rispetto alla 0.7.0. Il percorso normale è valido e
le correzioni dei casi già riprodotti per R1/R2/R3 funzionano nelle simulazioni
allegate. R4 è migliorato nella rilevazione e nella riparazione, ma non nella
garanzia che la riparazione venga attivata. Rimangono percorsi riprodotti che
creano un secondo ordine, oltre a un caso di fatturazione bloccata.
Prima del collaudo sul sito correggere almeno N1–N3; chiarire inoltre N4.
La proposta può diventare la soluzione, ma lo ZIP non certifica il bug risolto.

## Verifiche eseguite

- Tutte le 11 suite PHP allegate: 1.690 controlli superati.
- `automation.php --access-only`: altri 8 controlli superati, totale 1.698.
- Sintassi verificata per 24 file PHP.
- La funzione nel fixture PMPro corrisponde alla funzione del tag 3.8.9
  scaricata durante la precedente review; il confronto del testo è positivo.
- Il nuovo test invoca effettivamente il gestore del core dopo la guardia.
- WordPress, addon, database applicativo, email e PayPal sono comunque simulati;
  SQLite traduce la sintassi dell'UPDATE MySQL. Nessuna vera concorrenza MySQL
  fra richieste PHP della guardia è stata provata.
- Le otto mutazioni citate nel report non sono state ripetute in questa review;
  non sono necessarie per dimostrare i controesempi sotto.

Sono confermati: UTC corretto, JSON vuoto coevo rinviato, vecchio journal verified
non più lasciato passare nel caso semplice, due consegne annidate dello stesso
incasso con un solo ordine. I test sono molto più pertinenti della 0.7.0.

## N1 — Alta: errore nello storage dei collegamenti scambiato per collegamento esistente

`includes/class-pmpro-fic-webhook-guard.php:122`:

```php
$linked = PMPro_FIC_Initial_Pair::source_link($oid);
if ($linked !== null) return null;
```

source_link può restituire un WP_Error se la lettura canonica delle opzioni
fallisce oppure il record è invalido. Anche WP_Error è diverso da null:
la guardia lascia proseguire il core con checkout success e ID vuoto.

Riproduzione: `guard_fixture(); $wpdb->atomic_failure = true; deliver($guard)`.
La tabella ordini resta leggibile, fallisce la lettura delle opzioni canoniche.
Risultato della funzione core: `Created new order with ID #43`, due ordini.
Non è necessario ipotizzare che l'intero database sia indisponibile.

Correzione: distinguere esplicitamente errore, assenza e collegamento valido.
Un candidato iniziale non verificabile va rinviato o fermato con diagnostica,
non trattato come caso già gestito. Aggiungere un test anche per record corrotto.

## N2 — Alta: risposta malformata trattata come rifiuto certo

`class-pmpro-fic-webhook-guard.php:27` e `:134`, in combinazione con
`class-pmpro-fic-payments.php`.

DEFINITIVE contiene payment_time. Quest'errore comprende anche una data illeggibile,
non soltanto una prova che il pagamento non sia pertinente. Una sale con ID,
abbonamento, importo e stato corretti, ma create_time = "not-a-time", restituisce
payment_time; la guardia ritorna null invece di considerarla non verificabile.

Riproduzione con payload coevo e risposta GET malformata: la funzione core crea
l'ordine 43, due ordini. È un caso di risposta incompleta/malformata non coperto
dal test `{}`, che invece ora funziona.

Correzione: separare errori di struttura/parsing da mismatch verificati. Non usare
una lista di codici concepiti per bloccare una fattura come prova automatica che
sia sicuro eseguire il gestore originale. Riesaminare anche gli altri codici
aggregati, ad esempio un campo importo mancante rispetto a un importo diverso.
Non si afferma che PayPal restituisca normalmente questa data: è una prova di
robustezza del ramo dichiarato "risposta non verificabile".

## N3 — Alta per il flusso FIC: la riparazione audit non è garantita e smette di funzionare con un rinnovo

`class-pmpro-fic-webhook-guard.php:153`, `:98`, `:165`;
`class-pmpro-fic-paypal-recovery.php:316`.

L'errore audit viene ora rilevato. Dopo aver associato l'ID, tuttavia, la guardia
ritorna null; il core risponde "already processed" e il wrapper PayPal restituisce
HTTP 200. Non viene programmato alcun lavoro locale per completare l'audit.
La riparazione richiede che arrivi di nuovo la stessa sale, ma una consegna 2xx
è già confermata: PayPal non garantisce un nuovo tentativo. Il test allegato
chiama deliver una seconda volta manualmente e quindi non prova il recupero autonomo.

Riprodotto: UPDATE incasso riuscito, scritture finali journal/metadati fallite.
Alla ripresa dello storage, journal ancora verified e anteprima FIC bloccata con
payment_recovery_data. Non nasce un secondo ordine in questo caso: resta incompleta
la riconciliazione necessaria alla fatturazione. Il solo fallimento dei metadati,
se il journal updated è integro, può invece consentire il fallback al journal.

Ulteriore problema: il controllo count(orders) !== 1 precede repair_audit.
Dopo un rinnovo giornaliero esistono almeno due ordini e perfino un replay esplicito
del primo evento non raggiunge più la riparazione. Riprodotto aggiungendo un
ordine di rinnovo con ID distinto: journal rimane verified, nessuna lettura sale
per ripararlo. Il conteggio di due ordini qui rappresenta un rinnovo legittimo,
non un duplicato creato dalla prova.

Correzione: riparazione idempotente su ordine/ID incasso specifico indipendente
dalla cardinalità della cronologia; attivazione certa tramite coda locale o
risposta di retry appropriata, con controllo amministrativo. Riparare il caso
prima di confermare definitivamente la consegna oppure registrare un lavoro
durevole. Nessuna nuova fattura o modifica dell'ID durante la riparazione.

## N4 — Media: finestre temporali non coerenti come criteri di protezione

`class-pmpro-fic-webhook-guard.php:104`, `:139`, `:149`.

Il binding accetta un checkout entro 24 ore dalla creazione dell'abbonamento,
ma il rinvio per incertezza protegge solo entro 2 ore fra checkout e sale.
Ho costruito lo stesso checkout tre ore prima della creazione abbonamento,
con pagamento subito dopo la creazione:

| Risposta GET sale | Risultato |
| --- | --- |
| Completa e corrispondente | bound, un ordine |
| HTTP 200 con `{}` | passthrough, il core crea il secondo ordine |

Quindi un caso che la stessa guardia considera ammissibile quando l'API risponde
correttamente perde la protezione quando l'API risponde male. Non è prova che
questo intervallo sia quello osservato sul sito; è un limite concreto dei criteri.

Per un rinnovo giornaliero normale a circa 24 ore, le finestre brevi lo escludono
come previsto. Non trasformare tuttavia la sola distanza temporale in prova
universale che un incasso sia un rinnovo: trial, approvazioni ritardate, tentativi
falliti e differenze fra creazione ordine e abbonamento richiedono casi espliciti.
La scelta 2/24 ore va giustificata, resa coerente e dichiarata come limite.

## N5 — Bassa: lo stato visualizzato è statico

`class-pmpro-fic-webhook-guard.php`, render_status().

Il pannello dice sempre "Attiva per PayPal Sandbox", anche con il filtro di
emergenza impostato a false. Riprodotto. Mostrare lo stato effettivo del filtro,
del gateway e dell'ambiente. Alcune uscite anticipate non registrano eventi:
"nessun evento esaminato" non prova che nessun webhook sia stato ricevuto.

## Osservazioni sulla concorrenza e sull'attivazione

L'UPDATE condizionale protegge il riferimento dell'ordine. Le nuove riletture
risolvono i casi annidati testati, ma non costituiscono da sole una transazione
per audit, metadati e callback addon. Il controllo get_option seguito da
 delete_option non è una cancellazione compare-and-delete atomica; la presenza
di un owner nel valore non rende atomica la coppia di operazioni. Resta da
verificare la concorrenza con cron/manuale e con ACTIVATED sul database reale.
Questa review non attribuisce a tale osservazione un ulteriore doppione provato.

L'attivazione automatica non è necessariamente un errore funzionale, ma definire
la guardia "passiva" è inesatto: scrive ID pagamento, modifica la risposta HTTP
e il momento in cui vengono elaborati gli eventi. Per un collaudo preferirei
un opt-in esplicito e un interruttore visibile; è una scelta operativa distinta
dai difetti riprodotti sopra.

## Prove riproducibili

Script indipendente aggiuntivo:
[reproduce-review.php](../artifacts/review-0.7.1/reproduce-review.php).
Dopo aver estratto lo ZIP in una cartella di prova:

```sh
php artifacts/review-0.7.1/reproduce-review.php /percorso/estratto/fatture-in-cloud-sync
```

Carica prima i 140 controlli allegati, poi esegue le prove N1–N5. Usa solo fixture
e API simulate. Non va caricato sul server web. Output osservato:

- canonical pair read fails → due ordini, core crea #43;
- sale time unreadable → due ordini, core crea #43;
- final audit cannot persist → un ordine, ID presente;
- after storage recovers → verified, payment_recovery_data;
- replay after daily renewal → nessuna riparazione;
- disabled guard UI → still_says_active = true;
- checkout 3h earlier, API completa → bound;
- checkout 3h earlier, API incompleta → doppione.

## Fonti esterne verificate

- [PayPal: consegna e retry](https://developer.paypal.com/api/rest/webhooks/rest/):
  le risposte non 2xx attivano i retry documentati, fino a 25 tentativi in tre
  giorni. Una risposta 200 non offre la garanzia di una futura consegna.
- [Wrapper dell'addon](https://github.com/strangerstudios/pmpro-paypal/blob/dev/includes/webhook-handler.php):
  restituisce 200 dopo il gestore dell'evento; è sorgente upstream, non prova
  della versione installata sul sito.
- [Core PMPro 3.8.9](https://github.com/strangerstudios/paid-memberships-pro/blob/3.8.9/includes/gateway-request-handlers.php):
  fixture confrontata con il sorgente scaricato nella precedente review.

La versione effettiva dell'addon e i webhook storici restano da verificare.
Non è stata ripetuta né dimostrata l'esatta sequenza storica degli ordini 63–66.
