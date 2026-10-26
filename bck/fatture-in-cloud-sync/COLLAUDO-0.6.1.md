# Collaudo 0.6.1 — checkout e primo incasso su ordini distinti

Preparata e verificata localmente il 22 ottobre 2026. Non ancora installata sul sito.
La 0.6.0 è stata installata dall'utente: piano `TEST rinnovi giornalieri` creato,
documenti associati agli ordini 62 e 67; checkout 63 e 65 bloccati per più ordini.
Per questa verifica usare gli incassi esistenti, senza altri acquisti.

## Installazione e prima coppia

1. Conservare il backup del database e lo ZIP 0.6.0. Caricare
   `fatture-in-cloud-sync-0.6.1.zip` da WordPress → Plugin → Aggiungi plugin →
   Carica plugin e sostituire la versione esistente. Le impostazioni restano
   disponibili; il cron già collaudato non richiede modifiche.
2. Impostazioni → PMPro Fatture in Cloud → **sezione 3** →
   **Primo incasso presente su un altro ordine**.
3. Inserire **63** come **ID checkout originale** e premere
   **Analizza coppia di ordini (sola lettura)**.
4. Controllare i riferimenti attesi:

   | Campo | Valore già osservato |
   | --- | --- |
   | Checkout originale | 63, senza ID pagamento |
   | Ordine con incasso | 64 nello screenshot; deve essere confermato dall'analisi |
   | Abbonamento | `I-PNNJ5SY6HSUN` |
   | ID incasso | `3NS08370WB225042Y` |
   | Importo | 1,00 EUR |
   | Data incasso UTC | 2026-10-22 10:39:12 |

5. Se coincidono, premere **Conferma collegamento fiscale**. Il plugin ricontrolla
   i riferimenti e copia i dati fiscali originali sull'ordine con incasso,
   registrandone la provenienza. Non sposta ID pagamento, non elimina ordini,
   non cambia stati, non crea fatture e non abilita nuovi addebiti.
6. Il risultato mostra l'ordine da usare. Premere **Carica ordine e conti
   d’incasso**, selezionare il conto di prova, confermare i dati di collaudo e
   generare l'anteprima. Verificare cliente, totale di **1,00 EUR**, sezionale
   **/TEST** e azienda di prova, poi creare il documento con il normale pulsante.
7. Verificare il documento in Fatture in Cloud e annotare ID/numero. Ricaricare
   lo stesso ordine deve mostrare l'associazione esistente. Il checkout 63
   apparirà in coda come **Collegamento confermato** verso l'ordine con incasso;
   il vecchio registro dei tentativi rimane conservato. La riconciliazione non
   accoda automaticamente l'ordine destinazione: qui la creazione è manuale.

## Seconda coppia

Ripetere i passaggi con **65** come checkout originale. Attesi:

- Abbonamento `I-4TDYA5T419FL`.
- Incasso `55717247T10543359`, **1,00 EUR**.
- Data UTC **2026-10-22 10:43:18**.
- L'ID numerico dell'ordine con incasso viene individuato dal plugin: non è
  leggibile nello screenshot e non va presunto uguale a 66.

Non ripetere la creazione sugli ordini **62 e 67**, già associati a documenti.

## Rinnovi e casi da fermare

Dopo aver verificato il documento della coppia, la sezione 4 accetta come
**ID ordine iniziale** l'ordine **con incasso** indicato nel risultato (64 per
la prima coppia, se confermato). I rinnovi richiedono la consueta autorizzazione
esplicita per il singolo abbonamento e valgono solo per incassi successivi.
Seguire il capitolo 3 di [COLLAUDO-0.6.0.md](COLLAUDO-0.6.0.md); non ripetere
la creazione del piano o i primi acquisti già completati.

L'analisi richiede esattamente due ordini Sandbox dello stesso abbonamento,
stesso cliente, livello e totale, una copia fiscale originale e un unico
incasso iniziale verificato sia nell'elenco transazioni sia nel dettaglio sale.
Se sono già comparsi altri ordini di rinnovo, se ci sono fatture/tentativi FIC,
oppure se un riferimento non coincide, la riconciliazione si blocca: conservare
il messaggio e gli ID, senza cancellare ordini, copie fiscali o prenotazioni.
I rinnovi arrivati **dopo** un collegamento completato non lo invalidano.

Se il collegamento è confermato ma il salvataggio della copia fiscale viene
interrotto, ripetere analisi e conferma con lo stesso checkout. L'analisi non
ripara i dati implicitamente; la conferma riprende solo la copia mancante e
non sovrascrive una copia diversa. Non crea una fattura durante il recupero.

Le due righe «Pagato» in PMPro restano presenti, così come l'etichetta «Rinnovo»
del gateway. Questa release risolve l'associazione fiscale assistita; non
corregge ancora la creazione upstream delle righe né i totali dei report PMPro.
Non installa modifiche al gateway PayPal o al core PMPro.

## Verifiche locali e registro del collaudo remoto

PHP CLI 8.4.1: **1.558 controlli**, inclusi 207 sulla riconciliazione e 56 sulla
concorrenza delle prenotazioni incasso (16 processi PHP). Suite precedenti
superate: collegamento, checkout fiscale, documenti manuali, coda, recupero
PayPal, diagnostica e rinnovi. Verificati anche conferma ripetuta, nonce,
permessi, scadenza, cambiamenti dei riferimenti, rimborsi, copia interrotta,
blocco del checkout originale e un solo POST sul destinatario.

WordPress e API simulati; SQL eseguito su SQLite, non MySQL. Nessun acquisto,
operazione di pagamento, fattura remota, email o invio SDI effettuato dai test.
La verifica sul server PHP 8.3 e la riconciliazione reale restano da eseguire.

| Controllo sul sito | Esito |
| --- | --- |
| Installazione 0.6.1 | Da confermare |
| Analisi e conferma 63 → ordine con incasso | Da eseguire |
| Un documento /TEST sull'ordine con incasso | Da eseguire |
| Analisi e conferma 65 → ordine individuato | Da eseguire |
| Un documento /TEST sulla seconda coppia | Da eseguire |
| Rinnovo successivo autorizzato con incasso diverso | Da eseguire |
