# Proposta di interfaccia — Fatturazione PMPro / Fatture in Cloud

Aprire `index.html` in Chrome, Safari o Firefox. È un file autonomo: non richiede
un server, un login o una connessione a Internet. Per condividerlo è sufficiente
inviare il file HTML; CSS, icone e JavaScript sono incorporati.

Si tratta di un prototipo navigabile, non di una nuova versione installabile del
plugin. Non contatta WordPress, PayPal o Fatture in Cloud. Le modifiche dimostrative
restano in memoria e si azzerano ricaricando la pagina. Non inserire credenziali
reali nei campi di esempio.

## Organizzazione proposta

| Sezione | Scopo |
| --- | --- |
| Panoramica | Capire lo stato dei documenti e la prossima attività |
| Documenti | Cercare un ordine e leggere il percorso fino al documento |
| Rinnovi | Verificare e gestire l'autorizzazione alla fatturazione dei futuri incassi |
| Impostazioni | Configurare collegamento, aliquote, descrizioni e conto d'incasso |
| Assistenza | Consultare eventi tecnici, confrontare letture e recuperare anomalie |

La barra superiore con la scelta dello scenario appartiene soltanto al prototipo;
non è prevista nella pagina operativa. Il menu laterale rappresenta la navigazione
interna del plugin, da integrare nella cornice amministrativa WordPress.

## Percorsi da provare

1. Panoramica → Dettagli dell'ordine 71 → cronologia e riferimenti tecnici.
2. Documenti → ricerca `71` oppure `561915448`.
3. Selettore Scenario → Un ordine da verificare → Documenti → filtro Da verificare.
4. Rinnovi → Verifica e autorizza → selezionare le due conferme → Conferma nella demo.
5. Impostazioni → Verifica collegamento e aliquote → Carica conti disponibili.
6. Assistenza → eventi leggibili e codici tecnici → esportazione del riepilogo demo.

Le autorizzazioni e le sospensioni simulate non modificano abbonamenti o documenti.
Alcuni strumenti specialistici aprono una spiegazione del percorso previsto,
anziché simulare una risposta di un servizio esterno.

## Provenienza dei dati

Gli ordini 68–71, gli ID dei documenti e gli eventi `bound` / `token_retry` derivano
dagli screenshot forniti il 22 ottobre 2026. Gli orari degli eventi sono presentati
in Europe/Rome (UTC +02:00 per quella data). Il campione non rappresenta lo storico
completo del sito. Non sono inclusi nomi o indirizzi email degli acquirenti.

Lo scenario dell'ordine 72 è inventato e contrassegnato come dimostrativo. Stato
dei rinnovi, configurazione fiscale, connessione FIC e prova cron non sono stati
letti dal sito e sono indicati come esempi o dati da verificare. L'ordine 67 resta
un riferimento al collaudo in corso, senza attribuirgli un rinnovo già eseguito.

## Integrazione nel plugin

La proposta riguarda presentazione e navigazione. Nel passaggio al backend PHP:

- Conservare le azioni, i nonce e il permesso `manage_options` esistenti.
- Usare viste server per ogni sezione, con ritorno alla sezione corretta dopo un
  invio e messaggi di esito visibili. Nessuna dipendenza obbligatoria da JavaScript
  per completare le operazioni amministrative.
- Leggere le configurazioni già salvate: un aggiornamento della UI non deve
  riattivare la coda, riscrivere autorizzazioni o cambiare le copie fiscali.
- Mostrare separatamente attivazione configurata ed effettiva disponibilità;
  una verifica di collegamento scaduta non va presentata come errore certo.
- Nei contatori dichiarare il perimetro: oggi la coda espone gli ultimi 20 ordini,
  non uno storico completo. Non mostrare zero quando la lettura fallisce.
- Distinguere pagamento, documento e invio elettronico: “Documento creato” non
  equivale a “Inviato allo SDI”. Conservare l'indicazione dell'ambiente di prova.
- Espandere i dettagli tecnici su richiesta. Presentare `bound` come “Pagamento
  associato” e `token_retry` come “Checkout in corso: nuovo tentativo richiesto”.
- Il recupero degli audit deve poter raggiungere anche quelli più vecchi degli
  ultimi 100 registri; resta il difetto individuato nella review 0.7.3.
- Le schede dei rinnovi richiedono una lettura affidabile delle autorizzazioni
  esistenti. Nessuna data di rinnovo deve essere dedotta dal timestamp di acquisto:
  va verificata presso PayPal o indicata come non disponibile.
- Le credenziali restano nell'area di configurazione; il riepilogo per il cliente
  non deve contenerle. Non si introducono nuovi ruoli o accessi pubblici.

Non è stato modificato alcun file del plugin per costruire questa proposta.
