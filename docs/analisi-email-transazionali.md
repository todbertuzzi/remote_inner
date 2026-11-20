# Analisi delle email transazionali della piattaforma

Data: **13 ottobre 2026**, aggiornata il **6 novembre 2026** per l'email di benvenuto agli invitati (Inviti Manager 1.2.1). Analisi del codice disponibile nel workspace, senza
invio di email e senza modifiche al comportamento della piattaforma.

## 1. Risultato e perimetro

Nel codice applicativo personalizzato sono presenti **6 chiamate di produzione a
`wp_mail()`**, distribuite in tre file PHP. Corrispondono a **quattro famiglie di
messaggi**: invito al gioco, invito alla Scrivania, riepilogo della Scrivania per il
creatore e benvenuto dopo la registrazione tramite invito. L'invito alla Scrivania ha implementazioni distinte per primo invio,
reinvio e percorso di fallback.

Il repository contiene i plugin personalizzati, il tema child e un solo file
di integrazione Tutor Pro. Non contiene WordPress core, il plugin completo PMPro,
Tutor LMS completo, il plugin SMTP, il database o i log delle email. La presenza
di un punto di invio dimostra che il codice può generare il messaggio, non che
quel messaggio sia stato consegnato in uno specifico momento sul sito.

Per i componenti mancanti ho consultato documentazione e sorgenti ufficiali:
le sezioni corrispondenti sono un inventario dei percorsi standard da verificare
rispetto alla versione e alle impostazioni effettivamente installate. Non sono
una lettura dei template salvati nel database del sito.

**Conferma fornita dall'utente durante l'analisi:** l'addon **Email di Tutor LMS
Pro non è attivo**. Le notifiche didattiche di quell'addon sono quindi escluse
dal flusso attivo attuale. Eventuali email del commercio nativo Tutor vanno
verificate separatamente; non coincidono con gli ordini PMPro.

Ho controllato anche il codice sorgente dell'app React `scrivania-app`: non sono
emersi servizi di invio email. Pusher e i messaggi AJAX/REST presenti sono
notifiche in pagina o realtime, non email.

## 2. Invii personalizzati: inventario completo del codice presente

| ID | Evento | Destinatario | Oggetto attuale | Formato dichiarato | Punto di invio |
| --- | --- | --- | --- | --- | --- |
| E01 | Creazione di un invito a un gioco dalla dashboard | Il singolo contatto scelto | `Sei stato invitato a giocare` | Testo semplice UTF-8 | `innerplay-inviti-manager.php:806` |
| E02 | Creazione di una sessione Scrivania con inviti | Ogni destinatario valido, una email per contatto | `Invito al Tool Scrivania` | HTML UTF-8 | `class-scrivania-ajax.php:904` |
| E03 | Pulsante di reinvio / reinvito della Scrivania | Il destinatario dell'invito selezionato | `Invito al Tool Scrivania` | HTML UTF-8 | `class-scrivania-ajax.php:289`, chiamato a riga 735 |
| E04 | Riepilogo dopo la creazione della Scrivania | L'utente creatore/invitante | `Link sessione Tool Scrivania (solo creatore)` | HTML UTF-8 | `class-scrivania-ajax.php:925` |
| E05 | Invito Scrivania tramite il gestore alternativo | Ogni contatto passato al gestore | `Invito al Tool Scrivania` | HTML UTF-8 | `innerplay-inviti-manager.php:674` |
| E06 | Registrazione riuscita tramite invito gioco o Scrivania | Il nuovo utente invitato | `Benvenuto su Inplayce Tools` | HTML UTF-8 | `includes/invite-registration.php`, funzione `gim_send_invited_welcome_email()` |

File che contengono i testi:

- [Innerplay Inviti Manager](../plugins/innerplay-inviti-manager/innerplay-inviti-manager.php):
  funzione `gim_attiva_gioco()` da riga 720; template E01 a righe 803–811;
  funzione `gim_attiva_scrivania()` da riga 553; template E05 a righe 665–674.
- [Scrivania AJAX](../plugins/scrivania-collaborativa-api/includes/class-scrivania-ajax.php):
  `attiva_scrivania()` da riga 767; template E02 da riga 895 e E04 da riga 911;
  `send_scrivania_invite_email()` da riga 277 per E03;
  `dashboard_resend_invite()` da riga 607 per il relativo evento di invio.

Le righe si riferiscono allo stato dei file alla data dell'analisi e possono
cambiare con modifiche successive.

### E01 — Invito a un gioco

**Quando parte:** la dashboard invia l'azione AJAX `attiva_gioco`. Il server
verifica login, nonce, gioco pubblicato e accesso previsto dal piano. Accetta
un solo contatto per sessione. Dopo l'inserimento della sessione e della relativa
riga invito invia l'email; se l'inserimento dell'invito fallisce, non la invia.

Contenuto attuale, con il valore variabile rappresentato tra parentesi:

```text
Oggetto: Sei stato invitato a giocare

Clicca per entrare in partita: [URL_PERSONALE_PARTITA]
```

Il link ha percorso `/gioca?invito=[UUID_INVITO]`. La sessione è creata con durata
di 24 ore, ma **la scadenza non viene indicata nell'email**. Non compaiono nome
dell'invitante, titolo del gioco, logo, firma, istruzioni per chi non ha un
account o contatto di assistenza. Non viene mandata una copia al creatore.

L'esito di `wp_mail()` è utilizzato nella risposta JSON della dashboard: `sent`
vale 1 o 0, con destinatario in `failed` se il tentativo non riesce. Questo non
equivale a una conferma di consegna nella casella del destinatario.

### E02 — Primo invito alla Scrivania

**Quando parte:** azione AJAX `attiva_scrivania` del plugin Scrivania Collaborativa
API, dopo verifica del piano Professional/Gold, nonce, data/ora e mazzo. Una nuova
email viene tentata per ogni destinatario dopo il salvataggio della sua riga invito.
Non è un invio differito all'ora della sessione: l'invio avviene durante la richiesta
di creazione, mentre data/ora sono riportate nel messaggio.

```text
Oggetto: Invito al Tool Scrivania

Hai ricevuto un invito al Tool Scrivania!
Quando: [DATA_GG/MM/AAAA] alle [ORA_HH:MM]
Clicca qui per partecipare
```

L'ultimo testo è un link HTML a `/invito-scrivania/?token=[TOKEN_PERSONALE]`.
Il destinatario è l'email del contatto, anche quando non ha ancora un account.
Se l'account esiste, l'invito viene già associato al suo ID utente.

Non compaiono nome dell'invitante, nome della sessione, mazzo, ruolo viewer/editor,
fuso orario, logo, firma o assistenza. Il server conta separatamente i tentativi
riusciti e falliti e li restituisce alla dashboard.

### E03 — Reinvio e reinvito alla Scrivania

Il pulsante nella gestione inviti chiama `scrivania_dashboard_resend_invite`.
Il server verifica il possesso della sessione. Per un invito non revocato usa
lo stesso token; per un invito revocato crea una nuova riga e un nuovo token,
lasciando revocata quella originale.

Oggetto e contenuto sono quelli dell'invito E02. Non viene scritto “promemoria”,
“invito aggiornato” o “nuovo invito”, e non parte il riepilogo E04 al creatore.
Questa versione del template usa l'helper `format_invite_schedule_html()` a riga
165: interpreta data/ora nel fuso di WordPress e le formatta con `wp_date()`.
Il primo invio E02 e il riepilogo E04 formattano invece la data direttamente
con `date_i18n()` e `strtotime()`. Conviene uniformare il rendering della data
quando si riorganizzano i template.

### E04 — Riepilogo per il creatore della Scrivania

**Quando parte:** al termine dei tentativi di invio E02, se sono disponibili
l'email del creatore e il link diretto alla sessione. Può partire anche se alcuni
o tutti gli inviti ai partecipanti hanno fallito. Il codice non usa l'esito di
questo `wp_mail()` per aggiornare il risultato mostrato nella dashboard.

```text
Oggetto: Link sessione Tool Scrivania (solo creatore)

Hai creato una sessione del Tool Scrivania.
Quando: [DATA] alle [ORA]
Dettagli tecnici: sessione ID [ID] · creatore ID [ID] · invitante ID [ID]
Link per accedere alla tua sessione (solo creatore):
[URL_PERSONALE_CREATORE]
Nota: gli invitati devono entrare dal link /invito-scrivania/?token=...
ricevuto nella loro email (non da questo link).
Inviti inviati: [NUMERO_TENTATIVI_RIUSCITI]
```

Il link del creatore usa `/tool-scrivania/?token=[TOKEN_SESSIONE]`, distinto dai
link personali dei partecipanti. Non va sostituito con un unico link comune.
Non compaiono i nomi dei partecipanti o l'elenco dei destinatari falliti.
“Inviti inviati” conta i ritorni positivi di `wp_mail()`, non le consegne finali.

### E05 — Percorso alternativo Scrivania

Il gestore `gim_attiva_scrivania()` costruisce un'altra copia del template.
L'azione viene però **rimossa** a `plugins_loaded`, priorità 20, se esiste la
classe `Scrivania_Ajax` (Inviti Manager, righe 683–689). In una installazione
coerente con entrambi i plugin attivi, E02/E04 sono il percorso principale:
E05 non è una seconda email che parte normalmente insieme a E02.

Nel fallback l'esito di `wp_mail()` non viene verificato: il contatore aumenta
comunque. Anche il risultato dell'inserimento dell'invito non viene controllato
prima dell'invio. Questa differenza va considerata se si intende mantenere il
fallback come percorso supportato.

### File che attivano il flusso ma non contengono i template email

| File locale | Ruolo |
| --- | --- |
| [dashboard-invites.js](../themes/hello-theme-child-master/dashboard-invites.js), righe 160–178 | Compone la richiesta per invito gioco o Scrivania dalla dashboard. |
| [page-dashboard-utente.php](../themes/hello-theme-child-master/page-dashboard-utente.php), da riga 768 | Pulsante di reinvio Scrivania; chiama l'endpoint E03. |
| [scrivania-collaborativa-api.php](../plugins/scrivania-collaborativa-api/scrivania-collaborativa-api.php), riga 167 | Carica la classe AJAX; questa registra gli handler in fondo al proprio file. |
| [page-gioca.php](../themes/hello-theme-child-master/page-gioca.php) | Pagina di arrivo dell'invito gioco. Non è un template email. |
| [page-invito-scrivania.php](../themes/hello-theme-child-master/page-invito-scrivania.php) | Conferma dell'invito e accesso/registrazione del destinatario. Non è un template email. |
| [tool-scrivania.php](../themes/hello-theme-child-master/tool-scrivania.php) | Pagina di arrivo del creatore / partecipante autorizzato. |

## 3. Eventi senza email personalizzata nel codice analizzato

Non ho trovato invii applicativi per:

- conferma che un partecipante abbia accettato o aperto l'invito;
- cambio ruolo viewer/editor;
- revoca di un invito o archiviazione di una sessione;
- inizio/fine sessione e promemoria prima della data programmata;
- aggiunta/rimozione di un contatto in rubrica;
- punteggio, classifica o completamento di un gioco;
- creazione del documento Fatture in Cloud, invio del PDF, errori di fatturazione.

Dal 6 novembre 2026 il modulo invitati usa `wp_insert_user()` in
[invite-registration.php](../plugins/innerplay-inviti-manager/includes/invite-registration.php)
e, solo in caso di creazione riuscita, invia il benvenuto E06 prima di autenticare
e reindirizzare. Il messaggio contiene nome utente, link all'invito e alla dashboard
invitato, senza password. Non viene inviato per login o account esistenti né
retroattivamente. Un errore del trasporto non blocca l'accesso e viene registrato
nel metadato `_gim_welcome_email_status` (`sent`/`failed`, senza retry automatico).
Non chiama la notifica nuovi utenti di WordPress. `wp_insert_user()` espone comunque
`user_register`: altri plugin possono avere notifiche indipendenti. L'esito
positivo di `wp_mail()` non certifica la consegna effettiva.
[Riferimento WordPress](https://developer.wordpress.org/reference/functions/wp_insert_user/).

Il plugin [Fatture in Cloud](../plugins/fatture-in-cloud-sync/README.md) è in
collaudo manuale e non contiene chiamate di produzione a `wp_mail()` o al servizio
di invio documenti via email di Fatture in Cloud. La funzione `wp_mail()` presente
nei suoi test è un sostituto che impedisce l'invio, non un invio reale.

## 4. Email PMPro: abbonamenti, ordini e pagamenti

PMPro è utilizzato dalla piattaforma, ma non è incluso nel repository. L'email
di ricevuta inviata manualmente è stata effettivamente osservata dall'utente.
Gli altri template standard sotto sono **da confrontare con le impostazioni del
sito**, non certificati come tutti attivi.

Il punto di gestione consigliato per i testi è **Abbonamenti / Memberships →
Impostazioni → Email Templates**. Qui PMPro consente modifica e disattivazione
dei singoli template. [Documentazione dell'editor](https://www.paidmembershipspro.com/documentation/initial-plugin-setup/email-template-settings/).

### Catalogo dei template standard

I nomi tecnici permettono di identificarli senza dipendere dalla traduzione
del pannello. Quando compare `_admin`, è una variante indirizzata agli
amministratori secondo le impostazioni PMPro, non una seconda email al cliente.

| Template / slug | Evento e destinatario | Applicabilità alla piattaforma |
| --- | --- | --- |
| `checkout_free`, `checkout_free_admin` | Checkout gratuito, membro e amministratore | Piano Welcome; non coincide con registrazione da invito. |
| `checkout_paid`, `checkout_paid_admin` | Checkout con pagamento, membro e amministratore | Professional/Gold; controllare attivazione e testo. |
| `invoice` | Ricevuta rinnovo al membro; riutilizzata nell'invio manuale ordine | Manuale già osservato; rinnovi se previsti. |
| `billing_failure`, `billing_failure_admin` | Pagamento fallito, membro e amministratore | Dipende dagli eventi del gateway e dagli abbonamenti. |
| `membership_recurring` | Avviso prima del rinnovo, membro | Se il piano rinnova e lo scheduler/versione lo supportano. |
| `membership_expiring` | Avviso prima della scadenza, membro | Se il livello ha una data di scadenza. |
| `membership_expired` | Scadenza avvenuta, membro | Se il livello ha una data di scadenza. |
| `cancel`, `cancel_admin` | Cancellazione del piano, membro e amministratore | Percorsi di annullamento manuali o da gateway. |
| `cancel_on_next_payment_date`, `cancel_on_next_payment_date_admin` | Stop al rinnovo con accesso fino alla data prevista | Se supportato/configurato per il piano. |
| `admin_change`, `admin_change_admin` | Modifica del piano da amministrazione | Invio condizionato anche dall'azione amministrativa. |
| `refund`, `refund_admin` | Rimborso, cliente e amministratore | Dipende dal percorso/gateway; non è una nota di credito FIC. |
| `billing`, `billing_admin` | Aggiornamento dei dati di pagamento | Non identifica automaticamente una modifica dei nostri dati fiscali. |
| `checkout_check`, `checkout_check_admin` | Checkout con pagamento manuale | Non pertinente al solo PayPal, salvo attivazione di quel metodo. |
| `payment_action`, `payment_action_admin` | Azione aggiuntiva sul pagamento | Nel riferimento attuale riguarda Stripe, non il PayPal configurato. |
| `credit_card_expiring` | Vecchio avviso scadenza carta | Legacy: nel riferimento attuale non inviato di default. |

Catalogo e condizioni generali: [template PMPro](https://www.paidmembershipspro.com/documentation/member-emails/templates/).
Il catalogo del riferimento ufficiale esaminato contiene 25 classi specifiche,
incluse varianti admin e legacy. Non è il numero di email attive del sito.

### File standard da cercare nell'installazione PMPro del server

Tutti i percorsi in questa tabella sono relativi a
`wp-content/plugins/paid-memberships-pro/` e **non esistono nel workspace attuale**.

| File / directory | Responsabilità |
| --- | --- |
| `classes/class.pmproemail.php` | Invio, mittente, composizione, filtri e scelta del template. |
| `classes/email-templates/class-pmpro-email-template.php` | Classe comune dei template nelle versioni recenti. |
| `classes/email-templates/class-pmpro-email-template-checkout-free.php` e `…-checkout-free-admin.php` | Contenuto predefinito Welcome / checkout gratuito. |
| `classes/email-templates/class-pmpro-email-template-checkout-paid.php` e `…-checkout-paid-admin.php` | Contenuto predefinito checkout a pagamento. |
| `classes/email-templates/class-pmpro-email-template-invoice.php` | Ricevuta ordine / rinnovo. |
| Altri `classes/email-templates/class-pmpro-email-template-*.php` | Una classe per ciascun template del catalogo sopra. Gli slug con `_` diventano segmenti con `-` nei nomi file; `membership_recurring` usa `…-membership-recurring.php`. |
| `includes/checkout.php` e `preheaders/checkout.php` | Completamento checkout e invii di conferma; eventuale notifica nuovo utente WP. |
| `adminpages/functions.php`, `adminpages/orders.php`, `adminpages/templates/orders-email.php` | Invio manuale di un ordine dal backend. |
| `services/ipnhandler.php`, `includes/gateway-request-handlers.php` | Eventi PayPal, rinnovi, fallimenti, cancellazioni e rimborsi. |
| `classes/gateways/class.pmprogateway_paypalexpress.php` | Gestione PayPal Express e invii relativi ai rimborsi / casi particolari. |
| `preheaders/cancel.php`, `preheaders/billing.php` | Annullamento del piano e aggiornamento del pagamento dal frontend. |
| `includes/profile.php`, `adminpages/member-edit/pmpro-class-member-edit-panel-memberships.php` | Modifiche amministrative ai piani. |
| `classes/class-pmpro-recurring-actions.php` | Promemoria/scadenze e report schedulati nelle versioni recenti. |
| `includes/email.php`, `includes/email-templates.php` | Impostazioni, header/footer, registrazione e caricamento dei template. |
| `adminpages/emailsettings.php`, `adminpages/emailtemplates.php`, `adminpages/emailtemplates-edit.php` | Interfacce di configurazione. |

### La ricevuta “Total Billed” e l'ordine rimasto `token`

Nel sorgente ufficiale, `pmpro_add_email_order_modal()` in
`adminpages/functions.php` chiama `PMProEmail::sendInvoiceEmail()`, che costruisce
il template `invoice`. Quel percorso non controlla che l'ordine sia `success`.
Il contenuto predefinito contiene l'importo dell'ordine e non un controllo
indipendente dell'incasso PayPal. Questo è coerente con l'email riportata
dall'utente per l'ordine incompleto da 841,80 €.

Prima di rivedere il testo, decidere se:

1. l'invio della ricevuta deve essere permesso soltanto per pagamenti confermati;
2. gli ordini incompleti devono avere un messaggio distinto, esplicito sullo stato;
3. ricevuta pagamento, conferma di abbonamento e documento FIC devono restare tre
   comunicazioni riconoscibili.

Non ho modificato questo flusso. Fonti del percorso:
[azione amministrativa](https://github.com/strangerstudios/paid-memberships-pro/blob/d95654dd3b2a45c42f5bb976271061eb41a304f8/adminpages/functions.php),
[classe di invio](https://github.com/strangerstudios/paid-memberships-pro/blob/d95654dd3b2a45c42f5bb976271061eb41a304f8/classes/class.pmproemail.php),
[template invoice](https://github.com/strangerstudios/paid-memberships-pro/blob/d95654dd3b2a45c42f5bb976271061eb41a304f8/classes/email-templates/class-pmpro-email-template-invoice.php).

### Dati fiscali del checkout e dati presenti nelle email PMPro

I nuovi campi di fatturazione sono salvati dal nostro plugin in
`_pmpro_fic_billing_snapshot` sull'ordine e in `_pmpro_fic_billing_profile` per
precompilare acquisti futuri. Il plugin non aggiunge filtri alle email PMPro e
non trasferisce questi campi nelle tradizionali proprietà `$order->billing`.

Il template PMPro `invoice` legge nome/email dell'account e l'indirizzo da
`$order->billing`, mentre il documento FIC legge la copia fiscale del nostro
plugin. **La raccolta di codice fiscale, partita IVA ed email di fatturazione
non li rende automaticamente disponibili nel testo della ricevuta PMPro.**
Anche il destinatario standard della conferma/ricevuta è l'email dell'account,
che può essere diversa dall'email di fatturazione.

Se si desidera riportare i dati fiscali nelle email, occorre aggiungere variabili
dedicate che leggano la copia di quell'ordine, poi usarle nel template. Per i
rinnovi questa scelta andrà collegata alla gestione delle copie fiscali dei
nuovi ordini. Fonti: [modulo locale](../plugins/fatture-in-cloud-sync/includes/class-pmpro-fic-billing.php)
e [variabili del template invoice](https://github.com/strangerstudios/paid-memberships-pro/blob/d95654dd3b2a45c42f5bb976271061eb41a304f8/classes/email-templates/class-pmpro-email-template-invoice.php).

### Dove possono essere salvati testi e layout PMPro

Le personalizzazioni dell'editor vengono salvate nelle opzioni WordPress:
`pmpro_email_[slug]_subject`, `pmpro_email_[slug]_body`,
`pmpro_email_[slug]_disabled`; nelle versioni recenti anche destinatari/CC/BCC.
Quindi cambiare il file predefinito del plugin non garantisce che cambi il testo
effettivo: un valore nel database può prevalere.

Sono previsti anche override nel tema, ad esempio
`paid-memberships-pro/email/[slug].html`, varianti localizzate e nomi legacy
`membership-email-[slug].html`. **Nessuno di questi file è presente nel tema
child locale**. Non sono presenti nemmeno `email_header.html` / `email_footer.html`.

Nel riferimento corrente, `includes/email.php` registra anche un filtro globale
del formato email e un hook `phpmailer_init`. Il mittente PMPro può essere
applicato a tutte le email WP oppure solo a quelle PMPro secondo
`pmpro_only_filter_pmpro_emails`. Il formato e gli eventuali wrapper devono
quindi essere verificati nell'installazione: l'email E01 è dichiarata `text/plain`
nel nostro codice, ma il messaggio finale potrebbe essere trasformato da PMPro
o da altri filtri. Il nostro codice non definisce un layout comune globale.
[Sorgente dei filtri](https://github.com/strangerstudios/paid-memberships-pro/blob/d95654dd3b2a45c42f5bb976271061eb41a304f8/includes/email.php).

Per un layout comune PMPro, il pannello distingue **Email Header** e **Email
Footer**. Vanno verificati insieme al corpo specifico, senza presumere che
modifichino anche i template degli inviti o Tutor.

### Ulteriori email PMPro amministrative / particolari

| Messaggio | File standard | Condizione |
| --- | --- | --- |
| Report attività PMPro (`admin_activity_email`) | `classes/class-pmpro-admin-activity-email.php`, scheduler in `class-pmpro-recurring-actions.php` | Frequenza giorno/settimana/mese; disattivabile con frequenza `never`. |
| Avviso tecnico PayPal (`profile_start_date_limit_check`) | `classes/gateways/class.pmprogateway_paypalexpress.php` | Caso particolare di data futura del profilo ricorrente oltre il limite PayPal; corpo generato in PHP. |
| Log IPN via email | `services/ipnhandler.php` | Solo quando è attiva la configurazione di debug `PMPRO_IPN_DEBUG`. |
| Template generico `default` | `includes/email-templates.php` / `classes/class.pmproemail.php` | Messaggi generici di plugin/estensioni; non è un evento cliente specifico. |
| Vecchio messaggio `trial_ending` | `classes/class.pmproemail.php` | Metodo legacy deprecato; nessuna chiamata applicativa trovata. Non considerarlo un invio attivo senza verificarne il chiamante. |

Un eventuale elenco completo di Add Ons PMPro sul server potrebbe aggiungere
altri messaggi: approvazioni, recupero checkout, automazioni, notifiche dedicate.
Il repository non consente di escluderli né di confermarli.

## 5. WordPress: account, accesso e messaggi di servizio

WordPress core non è incluso nel repository. I punti standard da controllare
per i percorsi account della piattaforma sono questi:

| Email | Destinatario | File e funzione standard |
| --- | --- | --- |
| Richiesta recupero password | Utente che richiede il recupero | `wp-includes/user.php` → `retrieve_password()`; accesso dal normale flusso login. |
| Notifica nuovo account / impostazione password | Nuovo utente, se il chiamante richiede la notifica | `wp-includes/pluggable.php` → `wp_new_user_notification()`. |
| Notifica amministrativa nuovo account | Amministratore, se prevista dal chiamante | Stessa funzione, variante admin. |
| Avviso password cambiata dal profilo | Utente | `wp-includes/user.php` → `wp_update_user()`. |
| Avviso amministrativo dopo reset password | Amministratore | `wp-includes/pluggable.php` → `wp_password_change_notification()`, hook `after_password_reset`. |
| Conferma richiesta di cambio email dal profilo | Nuovo indirizzo richiesto | `wp-includes/user.php` → `send_confirmation_on_profile_email()`, quando il flusso la usa. |
| Avviso di email cambiata | Vecchio indirizzo dell'utente | `wp-includes/user.php` → `wp_update_user()`. |

I testi core sono costruiti in PHP e passano attraverso filtri dedicati, tra cui
`retrieve_password_title`, `retrieve_password_message`,
`retrieve_password_notification_email`, `wp_new_user_notification_email`,
`wp_new_user_notification_email_admin`, `password_change_email`,
`email_change_email`, `new_user_email_content` e
`wp_password_change_notification_email`. Non ho trovato override di questi
contenuti nel codice personalizzato.

La notifica nuovo utente **non è automaticamente identica in tutti i percorsi**:
la registrazione standard WP e quella amministrativa la possono attivare;
PMPro ha un'opzione dedicata `pmpro_email_member_notification` e il proprio
chiamante; il nostro modulo invitati non la chiama esplicitamente.
Fonti: [nuovo utente](https://developer.wordpress.org/reference/functions/wp_new_user_notification/),
[recupero password](https://developer.wordpress.org/reference/functions/retrieve_password/),
[aggiornamento profilo](https://developer.wordpress.org/reference/functions/wp_update_user/),
[conferma nuova email](https://developer.wordpress.org/reference/functions/send_confirmation_on_profile_email/).

### Email operative da non confondere con il percorso cliente

Potrebbero inoltre esserci email WordPress di aggiornamento core/plugin/tema
(`wp-admin/includes/class-wp-automatic-updater.php`), errore critico e recovery
(`wp-includes/class-wp-recovery-mode-email-service.php`), commenti da moderare o
pubblicati (`wp-includes/pluggable.php`), cambio indirizzo amministrativo
(`wp-admin/includes/misc.php` per la richiesta, `wp-includes/functions.php` per
l'avviso al vecchio indirizzo), richieste/esiti di esportazione o cancellazione
dei dati personali
(`wp-includes/user.php`, funzioni privacy; completamento esportazione in
`wp-admin/includes/privacy-tools.php`). Sono invii condizionati dalle funzioni e
impostazioni core, non invii personalizzati trovati nella piattaforma.

Vanno censiti separatamente se l'obiettivo è includere anche tutta la posta
amministrativa. Fonti: [aggiornamenti](https://developer.wordpress.org/reference/files/wp-admin/includes/class-wp-automatic-updater.php/),
[recovery](https://developer.wordpress.org/reference/classes/wp_recovery_mode_email_service/),
[core user.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/user.php),
[esportazione dati](https://developer.wordpress.org/reference/functions/wp_privacy_send_personal_data_export_email/),
[cambio email amministrativa](https://developer.wordpress.org/reference/functions/update_option_new_admin_email/).

## 6. Tutor LMS: addon Email disattivato

**Stato confermato dall'utente: non attivo.** Le email didattiche dell'addon non
sono quindi da considerare notifiche attualmente attive. Se verrà abilitato,
andranno selezionati i singoli eventi e impostati testi/layout nel pannello Tutor.

Il catalogo documentato comprende, tra gli altri: benvenuto, iscrizione e
completamento corso, quiz e compiti, annunci, risposte Q&A, nuove lezioni,
scadenza iscrizione, notifiche ai docenti e notifiche amministrative.
[Documentazione addon Email](https://docs.themeum.com/tutor-lms/addons/email-notifications/).

Logo, colori, mittente, footer e testi si gestiscono nelle impostazioni Email.
Il solo file locale
[pmpro-content-settings.php](../plugins/tutor-pro/addons/pmpro/views/pmpro-content-settings.php)
gestisce l'integrazione dei contenuti con PMPro e non definisce email.
Non è presente il codice dell'addon Email: non posso indicare nomi esatti dei
suoi file o leggere i suoi template personalizzati senza la versione installata.
[Impostazioni ufficiali](https://docs.themeum.com/tutor-lms/settings/email/).

### Eccezione da verificare: email del commercio nativo Tutor

Nel sorgente ufficiale del plugin base recente esiste
`wp-content/plugins/tutor/ecommerce/EmailController.php`, con eventi
`tutor_order_placed` e `tutor_order_payment_status_changed`. Il codice può inviare
email per nuovo ordine e cambio stato a studenti, docenti e amministratori,
secondo le proprie opzioni. Non richiede nel costruttore che sia attivo l'addon
Email Pro: disattivare l'addon non è una prova universale di assenza di qualsiasi
email Tutor.

I file base associati sono `templates/email/email_header.php`,
`email_footer.php`, `email_styles.php` e `order_new_email_to_*.php` /
`order_updated_email_to_*.php`. Testi personalizzati possono essere nell'opzione
`email_template_data`; i valori del mittente sono nelle opzioni Tutor.

**Applicabilità non accertata:** la piattaforma usa checkout PMPro. Non ho
trovato nel codice personalizzato una creazione di ordini Tutor nativi: queste
email non vanno contate come invii attivi PMPro. Occorre verificare la versione
Tutor e il motore di monetizzazione configurato prima di includerle.
[Sorgente ufficiale del controller](https://github.com/themeum/tutor/blob/f9e28f2d7a0b1ba4bb678c68b6c7ad3e823964ec/ecommerce/EmailController.php).

## 7. Mittente, SMTP e template grafico

Gli invii personalizzati passano da `wp_mail()`. Nessuno specifica nei propri
header `From`, `Reply-To`, CC/BCC o allegati: usa soltanto il Content-Type.
Il mittente finale deriva da WordPress, PMPro, SMTP o altri filtri installati.
La descrizione di Inviti Manager cita “FluentSMTP/Elastic”, ma **è un commento
del plugin, non una verifica della configurazione attuale**.

Il codice locale non definisce un logo/header/footer condiviso per gli inviti.
I corpi HTML Scrivania sono frammenti di paragrafi e link; il gioco parte da
testo semplice. Un futuro template comune va applicato con attenzione ai diversi
motori, evitando due header/footer se PMPro o un altro plugin stanno già avvolgendo
il messaggio. Le pagine Elementor e il CSS della dashboard non costituiscono il
layout delle email.

`wp_mail()` che restituisce `true` indica che il tentativo è stato accettato dal
mailer, non garantisce la consegna alla casella. Per confermare destinatari e
layout finali servono i log applicativi/SMTP e, dove disponibile, l'esito del
provider. [Documentazione WordPress](https://developer.wordpress.org/reference/functions/wp_mail/).

Eventuali ricevute inviate direttamente da PayPal sono comunicazioni del
provider e non hanno il template in questi file. La loro configurazione va
analizzata separatamente dalle email generate da WordPress.

## 8. Decisioni sul contenuto e sui template

Priorità proposte per la revisione, senza aver implementato modifiche:

1. **Invito gioco:** aggiungere identità dell'invitante, titolo del gioco,
   scadenza effettiva e istruzioni chiare per login/registrazione.
2. **Invito Scrivania e reinvio:** stesso rendering condiviso, nome dell'invitante,
   data/ora con fuso, ruolo utile al partecipante e CTA coerente. Decidere se
   distinguere nel testo un reinvio da un nuovo invito.
3. **Riepilogo creatore:** sostituire il peso degli ID tecnici con informazioni
   utili alla sessione, mantenendo distinto il link riservato al creatore; valutare
   se mostrare contatti riusciti/falliti.
4. **PMPro:** rivedere separatamente Welcome, conferma acquisto e ricevuta;
   affrontare lo stato del pagamento prima di rendere più convincente il testo.
   La distinzione ricevuta/ordine/documento FIC deve essere chiara.
5. **Account:** recupero password, cambio email e notifica nuovo account, con
   un tono coerente e messaggi di sicurezza comprensibili.
6. **Layout:** concordare nome del mittente, lingua, logo, colori, firma, assistenza,
   struttura del pulsante e comportamento su mobile. Uniformare i motori dopo
   aver identificato wrapper e impostazioni effettivi.

Un punto naturale per estrarre i template degli inviti è un servizio PHP comune
che riceva il tipo di messaggio e i dati dell'evento. Le tre copie del corpo
Scrivania possono così essere sostituite da una sola definizione, mantenendo
separati invio iniziale, reinvio e fallback. È una proposta futura, non codice
già introdotto.

## 9. Dati necessari per completare la verifica sul sito

Per trasformare questo inventario del codice in un elenco certificato degli
invii effettivamente attivi, servono letture dal backend, senza inviare email:

- versioni WordPress, PMPro e Tutor, elenco plugin attivi e Add Ons PMPro;
- template PMPro abilitati/disabilitati, corpi/oggetti salvati, header/footer,
  mittente e opzione notifica nuovo utente;
- motore di monetizzazione Tutor, con addon Email già confermato disattivato;
- configurazione mittente/Reply-To e log SMTP, senza esportare credenziali;
- eventuali moduli Elementor o altri plugin di registrazione/supporto che
  attivino email da configurazione nel database;
- un esempio già ricevuto per ogni famiglia di messaggi da revisionare.

Non sono stati letti i log live, modificati template sul sito o eseguiti invii
di prova. Le evidenze di consegna già note provengono dai riscontri dell'utente.

## 10. Riferimenti e ripetibilità dell'analisi

- Codice applicativo del workspace; escluse dai conteggi le dipendenze vendor e
  le funzioni finte dei test.
- PMPro: riferimento ufficiale `master` risolto al commit
  `d95654dd3b2a45c42f5bb976271061eb41a304f8`. È il riferimento analizzato,
  **non una versione verificata del plugin installato sul sito**.
- Tutor base: riferimento ufficiale al commit
  `f9e28f2d7a0b1ba4bb678c68b6c7ad3e823964ec`, con la stessa distinzione rispetto
  alla versione installata.
- WordPress: documentazione delle funzioni e sorgenti ufficiali `trunk` come
  riferimento dei percorsi core, non identificazione della versione live.

È disponibile anche [l'anteprima dei contenuti personalizzati](email-transazionali-anteprima.html),
con dati fittizi e senza wrapper PMPro/SMTP. Serve a confrontare il contenuto
presente nei file, non a certificare il rendering finale dei client email.
