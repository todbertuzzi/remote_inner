# 0.7.4 — integrazione UI

Versione preparata localmente il 26 ottobre 2026. Nessun caricamento sul sito,
acquisto, addebito, email o documento reale eseguito durante questo sviluppo.

## Aggiornamento dalla 0.7.3

Caricare `fatture-in-cloud-sync-0.7.4.zip` dal pannello Plugin e scegliere la
sostituzione della versione già installata. Non disinstallare il plugin per
aggiornarlo. In alternativa sostituire i file nella stessa cartella via FTP,
includendo la nuova classe `includes/class-pmpro-fic-admin.php` e gli asset
`assets/admin.css` e `assets/admin.js`.

Aprire **Impostazioni → PMPro Fatture in Cloud**. La nuova sidebar appartiene al
contenuto del plugin; il menu WordPress resta visibile. In caso di stili vecchi,
ricaricare la pagina senza cache. La versione degli asset è 0.7.4.

Non occorre risalvare token, aliquota, conto o autorizzazioni se già corretti.
L’aggiornamento non cambia lo scheduler esterno o `DISABLE_WP_CRON`.

## Controllo iniziale con gli ordini esistenti

1. **Panoramica:** verificare lo stato dell’automazione e la distinzione fra
   attivazione salvata e configurazione effettivamente valida. I contatori
   riguardano gli ultimi 20 ordini in coda; i checkout riconciliati sono indicati
   separatamente. La prova cron è una registrazione storica, non un heartbeat.
2. **Documenti:** cercare un rinnovo già verificato usando il suo nuovo ID ordine
   o l’ID documento FIC. Aprire Dettagli e confrontare i riferimenti. Un ordine
   fuori dai 20 recenti può essere controllato con “Controlla ordine in coda”.
   La ricerca filtra il campione visualizzato, non interroga tutti gli ordini.
3. **Rinnovi:** riconoscere le due autorizzazioni esistenti, i rispettivi ordini
   iniziali e gli abbonamenti. Non premere nuovamente Autorizza per ripristinarli.
   Se l’elenco non è disponibile viene indicato un errore; resta il controllo
   per ID. La sospensione della fatturazione non cancella l’abbonamento PayPal.
4. **Impostazioni:** verificare visivamente i valori già salvati senza modificarli.
   Una verifica di collegamento scaduta non equivale a un errore certo: il worker
   mantiene la sua procedura preesistente di aggiornamento della verifica.
   Se si decide di modificare i dati, verificare il collegamento prima di salvare
   e scegliere nuovamente l’aliquota proposta dal servizio.
5. **Assistenza:** controllare cron e registri. Facoltativamente ripetere
   “Programma prova cron senza fatture”, attendere il normale passaggio del cron
   esterno e ricaricare. Per il test del primo rinnovo successivo all’aggiornamento
   usare un abbonamento già autorizzato e verificare un solo documento per incasso.

Questi controlli iniziali non richiedono nuovi acquisti o una nuova creazione
manuale di fatture per ordini già completati.

## Dove sono finiti i vecchi strumenti

| Vecchia posizione | Nuova posizione |
| --- | --- |
| Collegamento, campi fiscali, aliquote | Impostazioni |
| Sezione 1: verifica collegamento | Impostazioni |
| Sezione 2: simulazione locale | Assistenza → Simulazione locale |
| Sezione 3: fattura manuale, esito remoto, coppia iniziale | Documenti → Operazioni manuali e recupero anomalie |
| Sezione 4: attivazione e conto | Impostazioni → Automazione dei documenti |
| Sezione 4: coda e ritentativi | Documenti |
| Sezione 4: autorizzazioni e acquisizione rinnovi | Rinnovi |
| Sezione 5: diagnostica, cron, registro | Assistenza |
| Guardia webhook e ripresa audit | Assistenza |

## Validazione locale e limiti

Le suite PHP coprono i processi esistenti e aggiungono verifiche per routing POST,
nonce e permessi, GET senza API o scritture, escaping, segnalazione di dati
incompleti e lettura paginata delle autorizzazioni canoniche su SQLite.

Il test browser usa le viste PHP effettive, dati sintetici e una cornice WordPress
simulata. Controlla le cinque pagine a 320, 390, 768, 1024 e 1440 pixel, ricerca,
filtri e accessibilità delle operazioni senza JavaScript. Gli screenshot in
`artifacts/fic-ui-integrata` non rappresentano il sito online.

Esecuzione locale: PHP CLI 8.4.1 e Chrome headless; WordPress/API simulati, SQL su
SQLite. La compatibilità visiva con gli altri plugin, gli avvisi amministrativi
del sito e l’ambiente reale PHP 8.3/MySQL resta da verificare dopo il caricamento.
Il recupero audit mantiene il limite della 0.7.3 (ultimi 100 registri più eventi
recenti); la UI lo espone senza presentarlo come controllo dell’intero storico.

Per rigenerare le anteprime e ripetere le verifiche della UI:

```sh
php plugins/fatture-in-cloud-sync/tests/admin-ui.php --preview
node plugins/fatture-in-cloud-sync/tests/admin-ui-browser.mjs
```

Il secondo comando richiede Chrome locale avviato con `--remote-debugging-port=9222`.
