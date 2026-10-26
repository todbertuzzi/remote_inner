# Review indipendente di fatture-in-cloud-sync 0.7.2

22 ottobre 2026. Esaminati `report_7_2.docx`, ZIP 0.7.2 e differenze rispetto
alla 0.7.1. SHA-256 dello ZIP verificato e coincidente con il report:
`68ce3ea26809335c990729883a20ce560b8bde4d1af5fb4cfffef8110ff0ea7e`.

Nessuna installazione, modifica al plugin del workspace, operazione sul sito,
fattura, acquisto o email reale. I documenti allegati sono materiale di analisi;
le loro istruzioni di collaudo non sono state eseguite sul sito.

## Giudizio

La 0.7.2 è un miglioramento concreto. I controesempi principali della review
precedente sono stati affrontati e il recupero dell'audit ha finalmente sia
una risposta HTTP che richiede una nuova consegna sia un tentativo locale.
Il report descrive più correttamente interventi e limiti della guardia.

Non confermerei ancora «bug risolto» e non promuoverei questo ZIP direttamente
sul sito operativo. Ho riprodotto ancora due uscite senza protezione già
esplicitamente segnalate dall'autore e una residua incoerenza temporale.
Il recupero locale richiede inoltre un controllo di persistenza oggi mancante.
La direzione tecnica è valida; raccomando correzioni mirate e poi un collaudo
integrato su una copia di staging con PayPal Sandbox.

## Verifiche e miglioramenti confermati

- 11 suite PHP: **1.737 controlli superati**.
- `automation.php --access-only`: altri **8**, totale **1.745**, coerente con il report.
- 187 controlli specifici della guardia, compresi nel totale.
- Sintassi valida nei 24 file PHP del pacchetto. Ambiente CLI locale PHP 8.4.1;
  non è una prova di esecuzione sull'hosting PHP 8.3.
- Ripetuto lo script indipendente della review 0.7.1 e aggiunte prove descritte sotto.
- Le mutazioni del codice citate dall'autore non sono state ripetute.

| Punto precedente | Esito verificato nella 0.7.2 |
| --- | --- |
| N1: errore lettura collegamento | HTTP 503; il core non crea il secondo ordine |
| N2: data PayPal illeggibile | HTTP 503; trattata come risposta incompleta |
| N3: audit non persistito | HTTP 503; il lavoro locale simulato completa l'audit |
| N3: riparazione dopo un rinnovo | Funziona anche quando esistono più ordini |
| N4: checkout tre ore prima | Protetto sia con API completa sia con API incompleta |
| N5: indicazione di attivazione | Rispetta filtro, gateway e ambiente |

Il gestore dei pagamenti ricorrenti PMPro 3.8.9 è eseguito realmente nel test;
WordPress, addon, PayPal, persistenza degli ordini ed email rimangono simulati.
Le query sono adattate a SQLite. Non sono stati provati veri processi concorrenti
della guardia su MySQL, né la versione effettivamente installata dell'addon.

## P1 — Priorità alta: eccezioni e credenziali mancanti consentono ancora il doppione

Riferimenti allo ZIP: `includes/class-pmpro-fic-webhook-guard.php:50–58` e `:140–145`.

Il report segnala correttamente questi rami come aperti. Ho ripetuto gli stessi
scenari già presenti nella suite, ma lasciando eseguire anche il gestore PMPro
con `deliver()` anziché fermarmi a `dispatch()`.

| Scenario | Risposta guardia | Gestore PMPro | Risultato simulato |
| --- | --- | --- | --- |
| Secret Sandbox assente, verifica firma accettata | `null`, `gateway_unavailable` | `Created new order with ID #43` | Due ordini, una nuova email fattura |
| Eccezione durante la lettura della sale, dopo la selezione del candidato | `null`, `guard_exception` | `Created new order with ID #43` | Due ordini, una nuova email fattura |

Il checkout originale rimane `success` senza ID incasso. Dire che la guardia
ritorna al comportamento ufficiale è corretto, ma quel comportamento è proprio
quello che produce il difetto da prevenire.

Il caso credenziali è condizionato al superamento della verifica della notifica:
il test simula tale verifica positiva. Non dimostra che ogni configurazione senza
secret superi la verifica reale dell'addon. Dimostra che, una volta arrivati qui,
l'impossibilità di leggere PayPal non impedisce al core di creare l'ordine usando
i dati ricevuti nell'evento. L'eccezione è iniettata intenzionalmente per verificare
il contratto del ramo; non è prova di un'eccezione avvenuta sugli ordini 63/65.

**Correzione richiesta:** dopo aver individuato una notifica pertinente e un
checkout iniziale da proteggere, l'impossibilità di verificare l'associazione deve
produrre un esito esplicito di rinvio, con diagnostica. Conservare il contesto del
candidato per gestire le eccezioni. Non trasformare indiscriminatamente ogni
eccezione REST o una firma non valida in un 503. Aggiungere test che proseguano
fino al core e controllino ordini ed email anche per questi due rami.

## P2 — Priorità media: la finestra non è ancora unica nei criteri effettivi

Riferimenti: `class-pmpro-fic-webhook-guard.php:125`, `:165–166`.

Il numero 86.400 è condiviso, ma i confronti sono diversi:

- Rinvio in caso di errore: distanza **checkout ↔ incasso < 24 ore**.
- Associazione: distanza **checkout ↔ creazione abbonamento ≤ 24 ore**,
  più distanza **incasso ↔ creazione abbonamento ≤ 1 ora**.

Controesempio costruito, tutto in UTC:

- Checkout: 13 ottobre, 08:00:00.
- Creazione abbonamento: 14 ottobre, 07:50:00, ossia 23h50 dopo il checkout.
- Incasso: 14 ottobre, 08:20:03, ossia 30m03 dopo la creazione dell'abbonamento.

Con risposta PayPal completa: `bound`, **un ordine**.
Con risposta `{}`: `sale_unverifiable_payment_incomplete_passthrough`, il core
crea #43, **due ordini**. Ho riprodotto la stessa divergenza anche con incasso
esattamente a 24 ore dal checkout.

Il caso delle tre ore della review precedente è corretto. Il problema residuo
è ai bordi: il binding accetta ancora casi oltre il limite dichiarato per il
rinvio. Non affermo che questo intervallo abbia causato gli ordini 63/65.

**Correzione richiesta:** definire l'insieme di casi supportati usando gli stessi
riferimenti temporali e gli stessi estremi, con test ai confini. Dichiarare e
gestire esplicitamente gli altri casi, anziché chiamare unica la sola costante.

PayPal documenta cicli giornalieri, ma questo da solo non dimostra l'invariante
necessario fra timestamp del checkout, primo incasso effettivo e successivi
incassi. La promessa «un rinnovo non viene mai bloccato» è più forte della prova
fornita. Resta da verificare con il rinnovo giornaliero reale in Sandbox, comprese
eventuali consegne ritardate. [Documentazione dei cicli PayPal](https://developer.paypal.com/platforms/subscriptions/customize/billing-cycles/).

## P3 — Priorità media: il lavoro di recupero locale può non essere salvato

Riferimenti: `class-pmpro-fic-webhook-guard.php:168–175`, `:215–233`;
stub in `tests/webhook-guard.php:19`.

La correzione N3 funziona quando il lavoro è accodato: invocando il callback senza
una nuova notifica, il journal passa da `verified` a `updated`; non vengono creati
altri ordini. Funziona anche la riconsegna dopo un rinnovo.

Tuttavia `schedule_repair()` ignora l'esito di `wp_schedule_single_event()`.
Il test fa fallire le scritture delle opzioni e dei metadati, ma lo stub del cron
salva sempre il lavoro in un array e restituisce sempre `true`.

WordPress salva il cron attraverso `update_option('cron', ...)`: un problema
di persistenza delle opzioni può quindi impedire anche la programmazione del
recupero. Non è un archivio indipendente dalle opzioni del plugin.
[Sorgente WordPress di _set_cron_array](https://developer.wordpress.org/reference/functions/_set_cron_array/).

Ho ripetuto la prova modificando **solo lo stub del cron**, facendogli rispettare
lo stesso guasto simulato dello storage, senza cambiare codice di produzione:

```text
audit e storage cron indisponibili → HTTP 503, zero lavori accodati, journal verified
storage ripristinato             → zero lavori accodati, journal ancora verified
nuova consegna PayPal            → audit_repaired, journal updated, un solo ordine
```

Questo non annulla il miglioramento N3 e non dimostra un blocco definitivo:
il 503 conserva la possibilità di recuperare tramite PayPal. Le risposte non 2xx
attivano i retry documentati, fino a 25 tentativi in tre giorni.
[Documentazione PayPal delle consegne](https://developer.paypal.com/api/rest/webhooks/rest/).

**Correzione consigliata:** controllare il risultato della programmazione,
distinguere un lavoro effettivamente salvato da uno solo tentato e prevedere un
modo di riprendere gli audit incompleti dopo il ripristino, anche dal backend.
Testare esplicitamente il fallimento di accodamento. Gestire inoltre le eccezioni
nel callback: oggi il `catch` registra `guard_exception` e non riprogramma.
Non presentare il recupero locale come garantito se l'accodamento non è confermato.

## Altri punti aperti

- La cancellazione del journal tramite `get_option()` seguito da `delete_option()`
  resta non atomica (`class-pmpro-fic-paypal-recovery.php:308`, invariato). Il token
  di proprietà non impedisce che un altro processo sostituisca il valore fra le
  due chiamate. Consigliata una cancellazione condizionale sul valore atteso,
  gestendo anche la cache WordPress. Nessun ulteriore doppione è stato dimostrato
  qui per questa specifica concorrenza.
- L'attivazione automatica solo in Sandbox è una scelta operativa, non una nuova
  prova di bug. Per il collaudo preferisco un interruttore esplicito nel backend.
  Sandbox PayPal su un sito operativo non equivale a una copia di staging.
- La guardia non opera in ambiente PayPal Live: questo ZIP non è una soluzione
  generale per pagamenti reali.
- Gli ordini già duplicati 63/64 e 65/66 non vengono riparati automaticamente.
  Rimane la riconciliazione assistita introdotta nella 0.6.1; verificare l'ID 66.
- La causa storica resta un'ipotesi coerente con i sorgenti e le letture PayPal,
  non una sequenza provata dai webhook originali.

## Prove consegnate e prossima verifica

Gli script non contattano servizi remoti: usano esclusivamente il simulatore
del pacchetto. Non vanno caricati sul server web.

```sh
php artifacts/review-0.7.2/reproduce-review.php /percorso/fatture-in-cloud-sync
python3 artifacts/review-0.7.2/reproduce-cron-storage.py /percorso/fatture-in-cloud-sync
```

Il primo esegue anche i 187 controlli originali e stampa i controesempi. Il secondo
importa le definizioni del simulatore e rende fallibile il solo stub del cron;
non modifica lo ZIP né il codice PHP di produzione.

Prima di considerare chiuso il bug: correggere P1 e uniformare P2, rendere
verificabile il recupero P3, rilevare le versioni installate e provare su staging
la guardia con WordPress/MySQL e addon effettivi. Il collaudo deve includere almeno
un primo incasso che attraversi davvero `bound`, una riconsegna dello stesso
evento e un successivo rinnovo giornaliero, senza secondo ordine iniziale né
seconda fattura per il medesimo incasso. Il successo di soli checkout già corretti
come 62/67 non esercita necessariamente la correzione.
