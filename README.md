
Descrizione piattaforma Wordpress

Dal backend l’utente amministratore potrà creare corsi, giochi e mazzi di carte da utilizzare nel tool "scrivania".

Il contenuto dei corsi potrà essere costituito da video e quiz di valutazione a domanda chiusa.

La piattaforma prevederà la registrazione/abbonamento di 2 tipologie d’utente

Utente Cliente
Utente Abbonato

Il processo di registrazione nel caso degli abbonati prevederà anche la scelta dell’abbonamento fra 3 tipologie: welcome (free) , Professional e Gold.

I pagamenti nel caso degli abbonamenti Professional e Gold saranno possibile tramite PayPal Standard e PayPal Express


Una volta abbonati si avrà accesso ad una dashboard da cui si potrà  

1) cambiare i propri dati personali

2) cambiare il proprio abbonamento,  

3) gestire la lista contatti ,  

4) visualizzare una scheda dei corsi  a cui poter accedere

5) visualizzare una scheda dei giochi  a cui poter invitare utenti della lista contatti tramite email  (nel caso degli utenti con abbonamento welcome sarà possibile scegliere solo 1 gioco pre-impostato ),

6) accedere alla scheda del tool scrivania a cui poter invitare utenti della lista contatti tramite email ,

7) visualizzare una lista degli inviti effettuati

8) accedere ad una serie di pagine di servizio (supporto, faq, community, etc...)

9) nel caso di un abbonamento gold accedere ai contenuti extra (non definiti)


Una volta che un utente arriva sul sito tramite invito se è già registrato andrà direttamente su una pagina che ospiterà il contenuto legato al suo invito gioco  o tool.

Se non è registrato dovrà prima registrarsi e poi accederà alla pagina con il contenuto legato al suo invito gioco , tool o corso.

Ogni utente invitato e registrato avrà una sua dashboard da cui potrà:

1) visualizzare gli inviti che ha ricevuto

2) partecipare ai corsi , giochi a cui è stato invitato

3) vedere le classifiche dei giochi a cui a partecipato

4) modificare i suoi dati personali

I corsi verranno gestiti tramite il plugin Tutor LMS mentre gli abbonamenti tramite il plugin Paid Memberships pro

Il tool visualizzerà una scrivania, una barra laterale in cui vengono mostrati gli utenti collegati alla pagina in tempo reale e un riquadro posto immediatamente sotto alla scrivania.

Sarà possibile per il gestore dare inizio e fine alla sessione in qualsiasi momento. Una volta che la sessione è iniziata il gestore potrà inserire sulla scrivania delle carte scegliendole dal mazzo visualizzato nel riquadro in basso. Una volta posizionate le carte sulla scrivania il gestore potrà spostarle, ingrandire e ruotare. Ogni inserimento di carta o suo spostamento sulla scrivania verrà visualizzato da tutti gli utenti connessi alla pagina. In qualsiasi momento il gestore potrà scegliere di dare o levare il controllo della scrivania ad uno tra gli utenti che stanno partecipando alla sessione, a partire da quel momento lo spostamento, ingrandimento , rotazione delle carte sarà possibile anche all’utente selezionato.

I giochi verranno interfacciati al sito tramite api , si prevedono 3 funzionalità: integrazione dell’autenticazione, salvataggio punteggio, recupero classifiche; le api verranno dettagliate in un documento di specifiche apposito.


Gestione cumulativa accessi a corsi e giochi

La regola di accesso applicativa è centralizzata nel plugin `Innerplay - Controllo Accessi` (`plugins/innerplay-access-control`).

La corrispondenza corrente con Paid Memberships Pro è:

- Welcome: livello ID 3
- Professional: livello ID 4
- Gold: livello ID 5

Gli accessi sono cumulativi:

- Welcome può accedere ai contenuti Welcome;
- Professional può accedere ai contenuti Welcome e Professional;
- Gold può accedere ai contenuti Welcome, Professional e Gold;
- gli amministratori possono accedere a tutti i contenuti;
- utenti senza abbonamento attivo o con un livello non riconosciuto non possono accedere.

Il livello minimo di un corso è determinato dalla tassonomia Tutor LMS `course-category`; quello di un gioco dalla tassonomia `categoria_giochi`. Gli slug riconosciuti sono `welcome`, `professional` e `gold`.

Un contenuto senza uno di questi livelli, oppure con più livelli di accesso contemporaneamente, è considerato in lavorazione: non appare nella dashboard e può essere aperto soltanto dagli amministratori.

Per i giochi, il livello PMPro stabilisce quali giochi l'abbonato può vedere e per quali può creare un invito. Il destinatario non deve avere un abbonamento, ma può aprire soltanto la sessione associata alla propria email/utenza e al relativo UUID, se non revocata o scaduta.

Gli amministratori con permesso `manage_options` possono aprire direttamente il permalink di un gioco (`/giochi/nome-gioco/`) senza invito. Il template prepara una sessione `admin_preview` privata dell’amministratore, riutilizzata per un’ora, senza creare inviti o inviare email. Unity riceve un UUID reale, compatibile con l’endpoint `game/v1/user-profile`. La pagina indica la modalità anteprima e non viene memorizzata in cache. Il permesso amministrativo viene ricontrollato anche nelle API; gli inviti ordinari mantengono i controlli sul destinatario, sulla scadenza e sulla revoca.

La dashboard affianca `Anteprima` a `Invita`, con lo stesso stile. Anche gli abbonati possono aprire direttamente la pagina di un gioco pubblicato incluso nel loro piano: viene creata una sessione privata `member_preview`, riutilizzata per un’ora. La proprietà della sessione e l’accesso al gioco vengono ricontrollati nella pagina e nell’API Unity, anche dopo un cambio piano o una modifica alle categorie/pubblicazione del gioco. Le anteprime non inviano email e sono escluse dagli inviti ricevuti. Le pagine gioco, comprese le risposte di accesso negato, disabilitano la cache.

Il piano Welcome continua a mostrare in dashboard soltanto il gioco Welcome più recente. La regola di autorizzazione consente i giochi classificati Welcome: il limite di una voce nella lista non vincola l’accesso a un ID di gioco fisso.

Verifica locale dell’anteprima: `php plugins/innerplay-inviti-manager/tests/admin-game-preview.php`. I test usano sostituti WordPress/database e verificano helper, template, API e matrice dei piani. `php plugins/innerplay-inviti-manager/tests/game-preview-invites.php` verifica su SQLite in memoria che le anteprime siano escluse dagli inviti, anche con il limite di risultati. La build Unity completa e l’eventuale accesso diretto a `unity_build_url` vanno provati sul sito dopo la pubblicazione: la protezione della pagina WordPress non protegge da sola i file statici della build.

I corsi sono protetti sia sull'URL frontend sia negli endpoint REST del singolo corso. La collezione REST mostra soltanto i corsi consentiti all'utente corrente.

L'archivio pubblico `/Corsi` resta consultabile integralmente dai visitatori anonimi come catalogo. Per gli utenti autenticati, l'archivio e le altre liste frontend vengono filtrati con la matrice cumulativa del piano attivo; utenti autenticati senza un piano riconosciuto non vedono corsi disponibili.

Quando un utente autenticato prova ad aprire un contenuto non consentito, viene reindirizzato alla pagina Elementor pubblicata con slug `/accesso-riservato/`. La pagina viene servita con status HTTP 403, intestazione `noindex` e cache disabilitata. Se la pagina manca o non è pubblicata, il plugin mostra un messaggio di emergenza tramite WordPress.


Dashboard degli utenti invitati

Il plugin `Innerplay - Inviti Manager` espone lo shortcode:

`[innerplay_dashboard_invitato]`

Lo shortcode mostra gli inviti ricevuti per il Tool Scrivania e per i giochi, organizzati nelle tab `Attivi` e `Non più disponibili`. La classificazione dipende dalla disponibilità effettiva: un invito già utilizzato rimane tra gli attivi finché la relativa sessione può ancora essere aperta. Per ogni invito vengono indicati stato, mittente, date disponibili e pulsante di accesso; quelli conclusi, scaduti o revocati rimangono visibili come storico.

La corrispondenza con l'utente segue questa regola di sicurezza:

- se l'invito è già associato a un ID utente, può vederlo soltanto quell'utente;
- l'indirizzo email viene usato come fallback soltanto per gli inviti non ancora associati a un ID utente.

Configurazione della pagina con Elementor:

1. creare una pagina intitolata `Dashboard invitato` con slug esatto `dashboard-invitato`;
2. scegliere il layout `Elementor Full Width`;
3. nascondere il titolo standard della pagina, perché lo shortcode include già il proprio titolo;
4. inserire un widget Shortcode con `[innerplay_dashboard_invitato]`;
5. non applicare restrizioni Paid Memberships Pro alla pagina;
6. pubblicare la pagina ed escluderla da eventuali cache di pagina/CDN.

La pagina richiede comunque il login e imposta automaticamente intestazioni anti-cache. La logica di redirect dopo il login è:

- utenti con piano Welcome, Professional o Gold e amministratori: `/dashboard-utente/`;
- utenti senza piano ma con almeno un invito ricevuto: `/dashboard-invitato/`;
- utenti senza piano e senza inviti: pagina dei livelli PMPro.

I link diretti contenuti nelle email di invito hanno sempre la precedenza sul redirect predefinito. Anche un abbonato può aprire `I miei inviti` dal menu quando ha ricevuto inviti. Dopo la pubblicazione della nuova pagina, la vecchia pagina basata sul template `page-gestione-inviti.php` viene reindirizzata alla dashboard Elementor.


Account registrati tramite invito

Il plugin `Innerplay - Inviti Manager` registra il ruolo WordPress `invitato` (Utente Invitato), con il solo permesso `read`. La registrazione da invito per giochi e Scrivania assegna questo ruolo direttamente dal server; non assegna alcun piano PMPro, nemmeno Welcome. Il ruolo non sostituisce i controlli di identità, validità e permessi della singola sessione.

- `/gioca/?invito=...`: un destinatario senza account trova il modulo di registrazione; un destinatario già registrato trova il login. Dopo la registrazione viene autenticato e torna al proprio invito.
- `/invito-scrivania/?token=...`: mantiene la conferma esplicita dell’invito e usa lo stesso modulo senza piano. Un invito già utilizzato richiede l’accesso all’account associato.
- I vecchi link `/login/?redirect_to=...` diretti a queste due pagine vengono recuperati automaticamente. La normale pagina login e la scelta dei piani restano separate da questo percorso.

L’email è vincolata al destinatario salvato nel database. Il modulo verifica un nonce specifico dell’invito e rilegge disponibilità e destinatario prima di creare l’account. Inviti inesistenti, revocati, scaduti, anteprime private, giochi non pubblicati e Scrivanie archiviate non consentono la registrazione. Gli account esistenti non vengono ricreati o convertiti al momento del login.

Quando un invitato attiva un piano PMPro, passa al ruolo `subscriber` mantenendo lo stesso ID e i propri inviti. Se non rimangono piani attivi, un account nato da invito con il solo ruolo `subscriber` torna `invitato`. Eventuali ruoli aggiuntivi o amministrativi vengono conservati. Non viene effettuata una conversione massiva dei vecchi utenti `subscriber`.

Per pubblicare il flusso aggiornare insieme `innerplay-inviti-manager.php`, `includes/invite-registration.php`, `assets/invite-registration.css` e i file del tema child `functions.php`, `page-gioca.php`, `page-invito-scrivania.php`, `single-gioco.php`. Escludere le pagine degli inviti dalle cache esterne e non applicarvi restrizioni PMPro: il controllo avviene tramite l’invito. Non occorre modificare il blocco Elementor della pagina login.

Verifica locale: `php plugins/innerplay-inviti-manager/tests/invite-registration.php`. Esegue le funzioni reali e i template con sostituti di WordPress/database: registrazione, login, identità vincolata, nonce, password, permessi, cambio piano e recupero dei vecchi URL. Il collaudo finale con WordPress e PMPro reali va eseguito nell’ambiente integrato.


Gestione dei mazzi della Scrivania

Il catalogo sorgente dei mazzi si trova nel progetto React `scrivania-app/src/data/decks.json`. Il comando `npm run build:wp`, eseguito dalla cartella `scrivania-app`, valida le immagini, compila React e sincronizza nel plugin questo file:

`plugins/scrivania-collaborativa-api/config/decks.json`

La dashboard WordPress e l'app React derivano quindi le opzioni dalla stessa configurazione. Il mazzo viene scelto durante la creazione della sessione e salvato in `impostazioni.mazzoId`; non è modificabile in seguito.

La creazione della sessione è protetta sia via AJAX sia via REST con autenticazione e controllo del piano. Sono ammessi amministratori e utenti con piano Professional o Gold attivo. Welcome non vede la sezione Scrivania, la gestione degli inviti Scrivania o la relativa finestra nella dashboard. La stessa regola viene verificata per l’apertura e l’uso delle sessioni del creatore già esistenti (pagina, REST, autenticazione Pusher e gestione AJAX degli inviti), quindi un downgrade a Welcome o la perdita del piano blocca le successive richieste del creatore. Gli invitati mantengono l’accesso alla sessione secondo il proprio invito e ruolo, anche senza piano a pagamento. L'ID del mazzo deve corrispondere a una voce attiva del catalogo. Il creatore e gli editor invitati possono vedere il mazzo della sessione, aggiungere carte e modificarle. Gli editor possono anche rimuovere carte; la gestione dei partecipanti resta riservata al creatore. Il ritorno al ruolo viewer nasconde il mazzo e disabilita le modifiche in tempo reale.

Per aggiungere un mazzo non serve modificare il markup della dashboard o il codice PHP: si aggiungono gli asset `public/assets/mazzo_ID`, la voce nel catalogo React e si esegue `npm run build:wp`. Gli ID dei mazzi già pubblicati non devono essere riutilizzati o cambiati.

Il test dei permessi si esegue dalla radice di questo repository con `php plugins/scrivania-collaborativa-api/tests/editor-permissions.php`. Richiama gli handler reali dell'API usando sostituti locali di WordPress e del database: verifica aggiunta e salvataggio per gli editor, blocco per viewer e revocati, rimozione consentita a creatore ed editor, mazzo immutabile e conflitti di versione. La verifica finale con due account WordPress e Pusher va eseguita nell'ambiente integrato.

Verifica della visibilità e dell’accesso diretto alla Scrivania: `php plugins/scrivania-collaborativa-api/tests/membership-templates.php`. Il test `editor-permissions.php` verifica anche la nuova matrice dei piani e il blocco dei creatori non più abilitati sulle API e sulla gestione inviti.
