# Analisi Unity WebGL / Firefox — 4 novembre 2026

## Conclusione

Lo screenshot documenta un errore di memoria durante l'inizializzazione WebAssembly di Carosello Carte. Le verifiche sulla build attualmente pubblicata non confermano né header Brotli mancanti né una memoria iniziale di 512 MB / 1 GB. La causa specifica sul computer interessato resta da riprodurre: sono plausibili pressione di memoria, limiti del processo/browser o un problema specifico di Firefox con questa build. Non è possibile attribuire con certezza la responsabilità al cliente.

L'avviso giallo sugli header non costituisce da solo una diagnosi del server: il loader lo genera anche per errori di compilazione in streaming che non dipendono dagli header.

L'esame successivo della copia locale conferma che la build principale è identica a quella scaricata dal sito. Un controllo mirato nel Firefox locale ha completato compilazione e istanziazione WASM con entrambi i percorsi, streaming e ArrayBuffer. Questo non riproduce l'errore del collaboratore e non equivale a un collaudo dell'intero gioco.

## Ambito e metodo

- Letti `file_header_giochi/.htaccess` e `themes/hello-theme-child-master/single-gioco.php`.
- Prima analisi: scaricata la build pubblica indicata nello screenshot, `https://inplaycetools.com/wp-content/uploads/giochi/carosello_carte/`. Successivamente l'utente ha fornito la copia locale in `esempio_gioco_unity/carosello_carte/`, esaminata come descritto nell'integrazione qui sotto.
- Eseguite richieste HTTP HEAD e GET; scaricati loader, framework, WebAssembly e dati; verificata decompressione Brotli; letta la sezione memoria del binario WebAssembly.
- Nell'attività di analisi non sono state modificate le build o il server. In un intervento separato, richiesto dall'utente, è stato aggiornato `file_header_giochi/.htaccess`; le successive verifiche HTTP confermano ora i due header corretti sul sito. Non eseguita una partita né riprodotto l'errore sul Firefox del collaboratore. I risultati non certificano tutte le altre cartelle dei giochi o le risposte ricevute dal collaboratore al momento dell'errore.

## Risposte HTTP osservate

Controlli del 4 novembre 2026, circa 09:27–09:28 Europe/Rome. Prefisso risorse: `https://inplaycetools.com/wp-content/uploads/giochi/carosello_carte/Build/`.

| File | Stato | Content-Type | Content-Encoding | Byte trasferiti | Byte dopo Brotli |
| --- | --- | --- | --- | ---: | ---: |
| Carosello_Vert.loader.js | 200 | application/javascript | assente, file non precompresso | 26.454 | — |
| Carosello_Vert.framework.js.br | 200 | application/javascript | br | 78.169 | 464.934 |
| Carosello_Vert.wasm.br | 200 | application/wasm | br | 8.258.604 | 47.054.175 |
| Carosello_Vert.data.br | 200 | assente | br | 24.901.123 | 37.934.550 |

La decompressione di tutti e tre i file Brotli è riuscita. Il modulo WASM decompresso è stato anche compilato con successo tramite `WebAssembly.compile` nel Node locale: è una verifica del modulo in quel motore, non un collaudo Firefox o dell'intero gioco.

Gli header essenziali per decompressione e streaming WASM sono corretti. Il Content-Type mancante sul `.data.br` merita una normalizzazione a `application/octet-stream`, ma non dimostra la causa dell'errore di istanziazione WASM. Il `.data` viene letto come dati binari e non compilato come WebAssembly.

## Memoria e versione della build

- Versione ricavata dall'archivio `data.unity3d`: Unity **6000.0.49f1**.
- Memoria iniziale dichiarata nella sezione memoria WASM: **512 pagine da 65.536 byte = 32 MiB**.
- Massimo dichiarato: **32.767 pagine = 2.147.418.112 byte**, circa 2 GiB. È il limite di crescita della memoria lineare, non una misura della RAM fisica consumata all'avvio; il browser può riservare spazio di indirizzamento.
- Il framework contiene `wasmMemory.grow` e una crescita geometrica del 20%, con limite di sovra-allocazione di 96 MiB e tentativi con incrementi inferiori.
- Il codice WASM decompresso pesa circa **44,9 MiB**, il pacchetto dati circa **36,2 MiB**. Queste dimensioni non rappresentano la memoria totale del gioco: compilazione, istanziazione, asset caricati e grafica richiedono ulteriori risorse.

Non è giustificato consigliare di abbassare una presunta memoria iniziale di 512 MB o abilitare una crescita che è già attiva. Eventuali modifiche ai parametri richiedono misure sul caso riproducibile e sui dispositivi di destinazione.

## Significato dell'avviso e dello stack

Nel loader pubblicato, `printErr` intercetta il testo `wasm streaming compile failed`. Se l'errore menziona il MIME type, mostra un avviso sul Content-Type; negli altri casi mostra un avviso generico che cita Content-Encoding e configurazione server come possibili cause.

Nel framework, il fallimento di `WebAssembly.instantiateStreaming` provoca un secondo tentativo tramite `instantiateArrayBuffer`. Quest'ultima funzione chiama `WebAssembly.instantiate(binary, imports)` e inoltra gli errori ad `abort`.

La sequenza è compatibile con lo screenshot: fallimento del primo tentativo, avviso generico, secondo tentativo fallito per memoria. Non conosciamo però il primo errore completo: occorre la console del browser interessato. Il nome `instantiateArrayBuffer` non dimostra che sia fallita precisamente l'allocazione dell'heap iniziale; può fallire anche la compilazione/istanziazione del modulo.

L'affermazione «header sbagliati fanno consumare il doppio della memoria» non è dimostrata per questo caso. Non esiste un fattore di raddoppio ricavabile dallo screenshot o dai file esaminati.

## Valutazione di .htaccess e integrazione WordPress

Le direttive locali `AddEncoding br .br`, `AddType application/wasm .wasm.br` e `AddType application/javascript .js.br` sono già presenti. Il loro effetto previsto sulle risorse WASM e JavaScript è coerente con le risposte del server. Non occorre aggiungere alla cieca un secondo blocco equivalente di header.

Nella prima verifica era presente un riferimento al precedente dominio `https://innerplaytools-dev.it` in `Access-Control-Allow-Origin`, sia nel file locale sia nelle risposte pubbliche. Il riferimento è stato successivamente corretto a `https://inplaycetools.com`, come confermano le nuove risposte HTTP. Con pagina e risorse servite entrambe da `https://inplaycetools.com`, le richieste sono same-origin e il vecchio riferimento non spiega questo errore di memoria. Gli header CORS delle risorse statiche non configurano automaticamente le API WordPress e non sono necessari per `postMessage`.

Il template WordPress locale incorpora un solo iframe Unity dopo i controlli di sessione. Lo stack dello screenshot riguarda il motore Unity già in caricamento, non un rifiuto di login o permessi WordPress. Aumentare il `memory_limit` PHP non agisce sulla memoria WebAssembly del browser.

## Integrazione: esame della build locale

### Identità dei file e integrità

L'HTML principale e tutti e quattro i file `Carosello_Vert.*` coincidono byte per byte con le copie scaricate dal sito durante la prima analisi. Non emergono differenze fra la copia locale principale e quella pubblica esaminata.

Hash SHA-256 della build principale:

| File | SHA-256 |
| --- | --- |
| Carosello_Vert.loader.js | `4340fe99d609fed63311c9b4aeac6bfe57a73cdba3c05e4984cbf3da8a505689` |
| Carosello_Vert.framework.js.br | `6099f0802dcea92c225e5e97c76fddd6b04f75ec527af5df619dc89e4785f033` |
| Carosello_Vert.wasm.br | `26cb986d0d811adfc44ee52c5c9df81f4855fdfaf9e55f88fe6f087ec8889e00` |
| Carosello_Vert.data.br | `aa9f84c70ca231f97fff1fe847ce0277ff0610b1338ca12379ec1e7864eab330` |

`ProjectVersion.txt` conferma Unity `6000.0.49f1 (840e0a9776d9)`. La decompressione di tutti i file `.br` riesce per entrambe le build locali; entrambi i moduli WASM compilano nel Node locale. Il contenitore `.data` principale ha una directory interna coerente, con offset e dimensioni entro i limiti del file. Queste verifiche non certificano ogni asset o comportamento del gioco.

### Due build distinte nella stessa cartella

La struttura contiene due punti d'ingresso:

- `carosello_carte/index.html`: titolo **Inplayce Carosello Carte**, carica solo `Build/Carosello_Vert.*`.
- `carosello_carte/Build/index.html`: titolo **Nuovo_Carosello**, carica `Build/Build.*` relativamente alla propria posizione, quindi i file in `carosello_carte/Build/Build/`.

La seconda è una build diversa, non una semplice copia con nomi diversi. Entrambe dichiarano 32 MiB iniziali, massimo di circa 2 GiB e crescita geometrica. La build annidata ha WASM decompresso da 46.847.220 byte e dati dopo Brotli da 37.256.025 byte, valori solo leggermente inferiori alla principale.

Il punto d'ingresso principale crea una sola istanza Unity e non richiama la build annidata. La presenza di due build sul disco non raddoppia la memoria del browser. Lo screenshot identifica esplicitamente `Carosello_Vert`, quindi riguarda la principale. È utile separare le pubblicazioni per evitare di configurare per errore WordPress verso `carosello_carte/Build/index.html`, ma non è stata eliminata o spostata alcuna build.

### Configurazione locale e conferma sul server

Il file `carosello_carte/.htaccess` coincide con il modello corretto in `file_header_giochi/.htaccess`. Si trova nella radice del gioco, non dentro `Build`: normalmente Apache applica le regole anche alle sottocartelle; nella copia fornita non ci sono ulteriori `.htaccess` annidati che le sovrascrivano.

Nuove richieste HEAD del 4 novembre 2026, circa 09:50–09:52 Europe/Rome:

| Risorsa | Stato | Content-Type | Content-Encoding | Access-Control-Allow-Origin |
| --- | --- | --- | --- | --- |
| Carosello_Vert.data.br | 200 | application/octet-stream | br | https://inplaycetools.com |
| Carosello_Vert.wasm.br | 200 | application/wasm | br | https://inplaycetools.com |

Entrambi i dettagli individuati nella prima analisi risultano quindi corretti nelle risposte pubbliche osservate. La tabella precedente delle 09:27–09:28 resta la registrazione della situazione iniziale.

### Controllo mirato nel Firefox locale

Eseguito Firefox in modalità headless con profilo temporaneo separato. User agent rilevato: `Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:159.0) Gecko/20100101 Firefox/159.0`. Il sistema operativo indicato dallo user agent non è una misura indipendente della versione del sistema ospitante.

Il test ha servito il file WASM Brotli originale tramite HTTP locale con `Content-Type: application/wasm` e `Content-Encoding: br`, e ha provato entrambi i percorsi:

| Operazione | Risultato | Memoria lineare iniziale osservata |
| --- | --- | ---: |
| WebAssembly.instantiateStreaming | Riuscita | 33.554.432 byte / 32 MiB |
| WebAssembly.instantiate dopo response.arrayBuffer() | Riuscita | 33.554.432 byte / 32 MiB |

Le 550 funzioni importate sono state sostituite con stub per isolare compilazione, istanziazione e allocazione iniziale. Non sono stati avviati Unity main, grafica, scene, asset o richieste alle API WordPress. Non si è misurato il picco di memoria dell'intero gioco. Il risultato dimostra che questi passaggi riescono nel Firefox locale nelle condizioni del test; non esclude un problema con altre versioni, altri dispositivi o con il carico completo della pagina.

### Limiti dell'analisi dei file

La cartella contiene una build esportata, non il progetto Unity completo: mancano sorgenti C#, scene originali e `ProjectSettings/ProjectSettings.asset`. Il file `dependencies.txt` elenca pacchetti del progetto e non misura quanto codice di ciascun pacchetto sia effettivamente incluso. Per decidere interventi su texture, scene, stripping del codice o memoria servono il progetto sorgente e misure del carico reale. I 36,2 MiB del `.data` dopo Brotli non misurano la memoria degli asset una volta caricati: il pacchetto contiene anche un archivio UnityFS con ulteriore compressione.

## Verifiche successive, in ordine

1. Sul computer interessato: annotare sistema operativo, RAM, versione e architettura 32/64 bit di Firefox. Chiudere completamente Firefox, riaprirlo con una sola scheda del gioco e verificare se il problema si ripete.
2. Ripetere lo stesso accesso con Chrome o Edge sul medesimo computer. È un confronto diagnostico: un esito positivo non prova da solo un bug Firefox né esclude un carico eccessivo della build.
3. Aprire gli strumenti sviluppatore prima del caricamento, conservare il log e acquisire il **primo** errore precedente a `wasm streaming compile failed`, oltre al popup finale. Nel pannello Rete controllare stato, Content-Type e Content-Encoding del `.wasm.br` effettivamente ricevuto. Ripetere con cache disattivata per escludere una risposta memorizzata diversa da quella attuale.
4. Confrontare l'avvio della build diretta con quello nell'iframe WordPress: `https://inplaycetools.com/wp-content/uploads/giochi/carosello_carte/`. La build diretta può non ricevere credenziali/sessione dalla pagina WordPress: serve a confrontare l'avvio del motore, non il funzionamento completo della partita.
5. Se il problema è riproducibile, misurare il picco di memoria e valutare una build di confronto, l'ottimizzazione degli asset/codice e i parametri di memoria. Non disattivare l'accelerazione hardware come primo rimedio: lo stack non individua un problema grafico.

## Riferimenti

- [Unity: distribuzione Web e header HTTP](https://docs.unity.com/en-us/engine/6000.0/manual/platform-specific/webgl/building-distribution/deploying)
- [Unity: memoria delle applicazioni Web](https://docs.unity.com/en-us/engine/6000.0/manual/platform-specific/webgl/develop/memory)
- [Loader pubblico esaminato](https://inplaycetools.com/wp-content/uploads/giochi/carosello_carte/Build/Carosello_Vert.loader.js)
