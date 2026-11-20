# Collaudo 0.8.0 — automazione per piano

Release locale. Nessun acquisto, fattura, addebito, email o aggiornamento del sito
è stato effettuato per implementarla. Il plugin rimane limitato a PayPal Sandbox,
EUR e documenti /TEST senza invio SDI.

## Installazione e impostazione

1. Caricare lo ZIP 0.8.0 da WordPress e sostituire il plugin installato. Il pacchetto
   contiene anche la nuova classe `includes/class-pmpro-fic-plan-rules.php`.
2. Controllare che l’automazione generale risulti attiva. Non occorre risalvare
   token, conto, aliquota o autorizzazioni esistenti se già corretti. Il cron esterno
   continua a usare lo stesso URL e la stessa frequenza.
3. Aprire **Impostazioni → Automazione dei documenti → Fatturazione automatica per
   piano**. Spuntare **Primo acquisto** e **Rinnovi automatici** sul piano
   “TEST rinnovi giornalieri”. Controllare anche gli altri piani: quelli esclusi
   non genereranno nuovi documenti automatici dopo il salvataggio.
4. Spuntare la conferma sui nuovi acquisti Sandbox e sull’uso dei dati fiscali nei
   rinnovi, poi premere **Salva regole dei piani**. La conferma esprime la delega
   dell’amministratore: non va richiesta a ogni acquirente dal backend.

Prima del primo salvataggio, la tabella propone il primo acquisto selezionato e
il rinnovo non selezionato; il sistema conserva ancora la modalità precedente.
Il salvataggio attiva effettivamente la selezione per piano. Non è necessario
premere nuovamente “Attiva automazione Sandbox” se l’automazione è già attiva.

## Test principale

1. Fare **un nuovo acquisto Sandbox** sul piano selezionato, con dati fiscali validi.
   Gli ordini precedenti al salvataggio non vengono importati o autorizzati dalla
   nuova funzione, anche se un loro evento viene ripetuto.
2. Attendere la normale elaborazione del cron. In **Documenti** verificare:
   - un solo documento /TEST associato al nuovo ordine iniziale;
   - l’esito **Rinnovi autorizzati** per quell’ordine.
3. In **Rinnovi**, verificare che l’abbonamento compaia nell’elenco con
   **Fatturazione autorizzata** e **Abilitazione automatica dal piano #…**.
   **Non premere “Autorizza la fatturazione dei rinnovi” per questo test.**
4. Al rinnovo giornaliero verificare un nuovo ordine PMPro pagato, stesso
   abbonamento, nuovo ID incasso e un solo documento /TEST associato al nuovo ordine.
   Importo e dati fiscali devono corrispondere al checkout iniziale.

Gli abbonamenti già autorizzati manualmente continuano normalmente. Non occorre
migrarli; la loro origine rimane “Autorizzazione manuale”.

## Sospensione singola

In **Rinnovi → Controlli e interventi su un ordine**, inserire l’ID dell’ordine
iniziale e usare **Sospendi fatturazione rinnovi**. Il rinnovo successivo può
continuare a essere addebitato da PayPal e risultare pagato in PMPro, ma non deve
creare un nuovo documento automatico. La sospensione non viene annullata da nuovi
webhook, salvataggi delle regole o ritentativi della coda.

Il comando può sospendere anche l’abilitazione ancora in attesa di un nuovo
checkout, se esiste già un ID abbonamento. Non è introdotto un comando di ripresa
che sostituisca le copie fiscali: la sospensione resta intenzionalmente persistente.

## Errori e ritentativi

- **Primo documento da correggere:** verificare prima ordine, copia fiscale e
  documento attraverso Documenti / strumenti manuali o assistenza. La coda non
  prende automaticamente nuovi dati dal profilo utente.
- **Documento associato, abilitazione in attesa:** il documento esiste già; la
  verifica PayPal viene ripetuta automaticamente con tentativi limitati.
- **Abilitazione rinnovi da correggere:** correggere la causa e usare
  **Riprova solo abilitazione rinnovi**. Questo percorso non richiama le API FIC
  né ripete il documento iniziale. Un lock recente richiede di attendere; un lock
  abbandonato da oltre 15 minuti può essere recuperato dal ritentativo esplicito.
- **Associazione ambigua, copia cambiata o incasso diverso:** nessuna autorizzazione
  automatica. Resta necessaria una verifica manuale.

Le schede riepilogative espongono gli ultimi 20 lavori, non tutti gli errori dello
storico. Per un ordine più vecchio usare **Documenti → Controlla ordine in coda**:
la risposta include anche lo stato dell’abilitazione dei rinnovi.

Le autorizzazioni valgono per incassi successivi alla loro registrazione; questa
release non importa rinnovi storici se l’abilitazione è rimasta bloccata a lungo.

## Modifica delle regole

Le modifiche valgono per i futuri checkout. Un acquisto già acquisito nella coda
con una regola abilitata mantiene quella regola anche se il piano viene poi
escluso. Per fermare un singolo abbonamento usare la sua sospensione; per fermare
l’intera coda usare la disattivazione generale. Il sistema non modifica gli addebiti.

Le autorizzazioni già salvate non vengono riscritte. La disattivazione del piano
non equivale alla sospensione di tutti i suoi abbonamenti. Un nuovo piano PMPro
è escluso finché non si salva esplicitamente la sua abilitazione.

Non usare la 0.7.4 come rollback delle regole per piano: quella versione non le
interpreta e tornerebbe al comportamento globale dei primi acquisti. Se occorre
interrompere il collaudo, sospendere l’automazione mantenendo la versione corrente.

## Validazione tecnica

Test isolati per nuove regole, controllo nonce/permessi, decorrenza per ID ordine,
conflitti fra salvataggi, esclusione dei piani, acquisti già acquisiti, worker
anonimo, copie fiscali, primo documento e rinnovo automatico, sospensione prima e
durante l’abilitazione, dati modificati durante il GET PayPal, ritentativi limitati,
errori di archivio e ripresa senza un nuovo documento.

Le regole sono archiviate come JSON canonico e aggiornate con confronto atomico
della versione precedente. Le autorizzazioni conservano l’inserimento univoco
preesistente per abbonamento. Nessun costruttore ordine o API aggiunto agli hook
di acquisizione del checkout: le verifiche remote sono eseguite dal worker.

Esecuzione locale con PHP 8.4.1, API/WordPress simulati e SQL SQLite; verifica browser
su viste PHP e cornice WordPress simulata, da 320 a 1440 pixel. Restano da collaudare
il gateway Sandbox reale, PHP 8.3/MySQL e gli avvisi del backend del sito. Le immagini
in `artifacts/fic-ui-0.8.0` contengono dati sintetici.

Il precedente limite di 100 registri nella ripresa degli audit webhook non viene
modificato da questa release.
