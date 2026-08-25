
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
