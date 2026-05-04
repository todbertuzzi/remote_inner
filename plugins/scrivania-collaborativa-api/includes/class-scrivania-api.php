<?php

/**
 * Classe principale per Scrivania Collaborativa API
 *
 * @package Scrivania_Collaborativa_API
 */

class Scrivania_Collaborativa_API
{
    /**
     * Istanza di Pusher
     *
     * @var Pusher\Pusher|null
     */
    private $pusher = null;

    /**
     * Cache colonne tabella inviti per request.
     * @var array<string>
     */
    private $invites_columns = null;

    private function get_invites_columns() {
        if (is_array($this->invites_columns)) {
            return $this->invites_columns;
        }

        global $wpdb;
        $inviti_table = $wpdb->prefix . 'scrivania_invitati';
        $cols = $wpdb->get_col("DESC {$inviti_table}", 0);
        $this->invites_columns = is_array($cols) ? $cols : array();
        return $this->invites_columns;
    }

    /**
     * Recupera l'ultima riga invito per sessione+utente (user_id o email).
     * Ritorna ARRAY_A oppure null.
     */
    private function get_invite_row_for_user($session_id, $user_id, $user_email) {
        global $wpdb;
        $inviti_table = $wpdb->prefix . 'scrivania_invitati';
        $cols = $this->get_invites_columns();
        $has_user_id = in_array('invitato_user_id', $cols, true);

        if ($has_user_id) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$inviti_table} WHERE sessione_id = %d AND (\n" .
                    " (invitato_user_id IS NOT NULL AND invitato_user_id = %d)\n" .
                    " OR\n" .
                    " (LOWER(invitato_email) = LOWER(%s))\n" .
                    ") ORDER BY id DESC LIMIT 1",
                    intval($session_id),
                    intval($user_id),
                    $user_email
                ),
                ARRAY_A
            );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$inviti_table} WHERE sessione_id = %d AND LOWER(invitato_email) = LOWER(%s) ORDER BY id DESC LIMIT 1",
                intval($session_id),
                $user_email
            ),
            ARRAY_A
        );
    }

    /**
     * Determina se un invito è attivo (non revocato, e verificato/claimato/consumato se lo schema lo supporta).
     */
    private function invite_is_active($invite_row) {
        if (empty($invite_row) || !is_array($invite_row)) {
            return false;
        }

        $cols = $this->get_invites_columns();
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_verified_at = in_array('verified_at', $cols, true);
        $has_claimed_at = in_array('claimed_at', $cols, true);
        $has_consumed_at = in_array('consumed_at', $cols, true);
        $has_status = in_array('status', $cols, true);

        // Revoca
        if ($has_revoked_at && !empty($invite_row['revoked_at'])) {
            return false;
        }
        if ($has_status && isset($invite_row['status']) && $invite_row['status'] === 'revoked') {
            return false;
        }

        // Schema legacy: se non esistono segnali di attivazione, considera valido (compat).
        if (!$has_verified_at && !$has_claimed_at && !$has_consumed_at && !$has_status) {
            return true;
        }

        // Attivazione
        if ($has_verified_at && !empty($invite_row['verified_at'])) {
            return true;
        }
        if ($has_claimed_at && !empty($invite_row['claimed_at'])) {
            return true;
        }
        if ($has_consumed_at && !empty($invite_row['consumed_at'])) {
            return true;
        }
        if ($has_status && isset($invite_row['status']) && in_array($invite_row['status'], array('verified', 'consumed'), true)) {
            return true;
        }

        // Se arriviamo qui, l'invito esiste e non risulta revocato.
        // In alcuni ambienti (schema legacy/misto o cache) i campi verified/claimed/consumed/status
        // possono non essere valorizzati come previsto: per evitare blocchi (403) permettiamo l'accesso.
        return true;
    }

    /**
     * Costruisce SQL aggiuntivo per validare invito (revoca + verifica/claim/consume).
     * Usa solo colonne esistenti per compatibilità schema legacy.
     */
    private function build_invite_access_sql() {
        $cols = $this->get_invites_columns();
        // Nota: questa funzione deve essere sicura anche con schema legacy (colonne mancanti).
        $has_revoked_at = in_array('revoked_at', $cols, true);
        $has_verified_at = in_array('verified_at', $cols, true);
        $has_claimed_at = in_array('claimed_at', $cols, true);
        $has_consumed_at = in_array('consumed_at', $cols, true);
        $has_status = in_array('status', $cols, true);

        $conditions = array();
        if ($has_revoked_at) {
            $conditions[] = 'revoked_at IS NULL';
        } elseif ($has_status) {
            $conditions[] = "status <> 'revoked'";
        }

        $activation = array();
        if ($has_verified_at) {
            $activation[] = 'verified_at IS NOT NULL';
        }
        if ($has_claimed_at) {
            $activation[] = 'claimed_at IS NOT NULL';
        }
        if ($has_consumed_at) {
            $activation[] = 'consumed_at IS NOT NULL';
        }
        if ($has_status) {
            $activation[] = "status IN ('verified','consumed')";
        }
        if (!empty($activation)) {
            $conditions[] = '(' . implode(' OR ', $activation) . ')';
        }

        if (empty($conditions)) {
            return '';
        }

        return ' AND ' . implode(' AND ', $conditions);
    }

    /**
     * Costruttore
     */
    public function __construct()
    {
        // Inizializza Pusher con le credenziali (se disponibile)
        $this->init_pusher();

        // Registra l'endpoint REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Aggiungi le impostazioni nella pagina di amministrazione
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Aggiungi lo script front-end con le chiavi Pusher
        add_action('wp_enqueue_scripts', array($this, 'enqueue_pusher_config'));
    }

    /**
     * Inizializza l'istanza di Pusher
     */
    private function init_pusher()
    {
        $app_key = get_option('scrivania_pusher_app_key', '');
        $app_secret = get_option('scrivania_pusher_app_secret', '');
        $app_id = get_option('scrivania_pusher_app_id', '');
        $cluster = get_option('scrivania_pusher_cluster', 'eu');

        // DEBUG: Log delle credenziali (rimuovi dopo il test)
/*         var_dump('Pusher credentials - ID: ' . $app_id . ', Key: ' . $app_key . ', Secret: ' . (!empty($app_secret) ? 'SET' : 'EMPTY'));
        die(); */

        if (empty($app_key) || empty($app_secret) || empty($app_id)) {
            return;
        }

        // Check if Pusher class exists before instantiating
        if (class_exists('\\Pusher\\Pusher')) {
            try {
                $this->pusher = new \Pusher\Pusher(
                    $app_key,
                    $app_secret,
                    $app_id,
                    array(
                        'cluster' => $cluster,
                        'useTLS' => true
                    )
                );
            } catch (Exception $e) {
                // Log error or handle the exception
                error_log('Pusher initialization error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Registra gli endpoint dell'API REST
     */
    public function register_rest_routes()
    {
        // Endpoint per ottenere i dati di sessione
        register_rest_route('scrivania/v1', '/get-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_session_data'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        // Endpoint per salvare lo stato della sessione
        register_rest_route('scrivania/v1', '/save-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'save_session_data'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        // Endpoint per l'autenticazione Pusher
        register_rest_route('scrivania/v1', '/pusher-auth', array(
            'methods' => 'POST',
            'callback' => array($this, 'authenticate_pusher_channel'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        // Snapshot endpoints (V1)
        register_rest_route('scrivania/v1', '/session/(?P<session_id>\d+)/snapshot', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_snapshot'),
                'permission_callback' => function () {
                    return is_user_logged_in();
                }
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'save_snapshot'),
                'permission_callback' => function () {
                    return is_user_logged_in();
                }
            )
        ));

        // Admin: aggiorna ruolo membro (viewer/editor) o rimuove accesso
        register_rest_route('scrivania/v1', '/session/(?P<session_id>\d+)/members/(?P<user_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_member_role'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        // Admin: lista membri e ruoli (per gestione permessi)
        register_rest_route('scrivania/v1', '/session/(?P<session_id>\d+)/members', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_session_members'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));

        // Endpoint per creare una nuova sessione
        register_rest_route('scrivania/v1', '/create-session', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_session'),
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ));
    }

    /**
     * Gestisce l'autenticazione per i canali privati/presence di Pusher
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return mixed Risposta di autenticazione
     */
    public function authenticate_pusher_channel($request) {
        $params = $request->get_params();
        $socket_id = sanitize_text_field($params['socket_id'] ?? '');
        $channel_name = sanitize_text_field($params['channel_name'] ?? '');
        $user_id = get_current_user_id();
        $user_info = get_userdata($user_id);

        if (empty($socket_id) || empty($channel_name)) {
            return new WP_Error('bad_request', 'Parametri mancanti', array('status' => 400));
        }

        // Accetta solo canali presence del tool
        $prefix = 'presence-scrivania-';
        if (strpos($channel_name, $prefix) !== 0) {
            return new WP_Error('not_allowed', 'Canale non consentito', array('status' => 403));
        }

        $session_id = intval(substr($channel_name, strlen($prefix)));
        if ($session_id <= 0) {
            return new WP_Error('bad_request', 'Sessione non valida', array('status' => 400));
        }

        // Verifica membership per la sessione
        if (!$this->user_can_read_session($session_id, $user_id, $user_info ? $user_info->user_email : '')) {
            return new WP_Error('not_authorized', 'Non autorizzato', array('status' => 403));
        }
        
        if (!$this->pusher) {
            // Prova a reinizializzare
            $this->init_pusher();
            
            if (!$this->pusher) {
                if (get_option('scrivania_pusher_debug', '0') === '1') {
                    error_log('Pusher auth: pusher non inizializzato (credenziali mancanti o libreria non caricata)');
                }
                return new WP_Error('pusher_not_initialized', 'Pusher non è configurato', array('status' => 503));
            }
        }
        
        try {
            // Se è un canale presence, include i dati utente
            if (strpos($channel_name, 'presence-') === 0) {
                $presence_data = array(
                    'id' => $user_id,
                    'name' => $user_info->display_name,
                    'avatar_url' => get_avatar_url($user_id)
                );
                
                $auth = $this->pusher->presenceAuth($channel_name, $socket_id, (string)$user_id, $presence_data);
            } else {
                $auth = $this->pusher->socketAuth($channel_name, $socket_id);
            }

            // IMPORTANT: $auth è una stringa JSON. Se la restituiamo così com'è,
            // WP REST la serializza di nuovo (stringa JSON quotata) e pusher-js
            // considera la risposta invalida. Decodifichiamo e restituiamo dati.
            $decoded = json_decode($auth, true);
            if (is_array($decoded)) {
                return rest_ensure_response($decoded);
            }

            return new WP_Error('pusher_auth_bad_response', 'Risposta auth non valida', array('status' => 500));
        } catch (Exception $e) {
            if (get_option('scrivania_pusher_debug', '0') === '1') {
                error_log('Pusher auth error: ' . $e->getMessage());
            }
            return new WP_Error('pusher_auth_error', $e->getMessage(), array(
                'status' => 500
            ));
        }
    }

    /**
     * Verifica accesso in lettura alla sessione (creator o invitato verificato e non revocato).
     */
    private function user_can_read_session($session_id, $user_id, $user_email) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $session = $wpdb->get_row($wpdb->prepare("SELECT id, creatore_id FROM {$table_sessions} WHERE id = %d", intval($session_id)), ARRAY_A);
        if (!$session) {
            return false;
        }

        if (intval($session['creatore_id']) === intval($user_id)) {
            return true;
        }

        $invite_row = $this->get_invite_row_for_user($session_id, $user_id, $user_email);
        return $this->invite_is_active($invite_row);
    }

    /**
     * Ritorna role e permissions per una sessione.
     */
    private function get_role_permissions($session, $invite_row, $user_id) {
        $role = 'viewer';
        if (intval($session['creatore_id']) === intval($user_id)) {
            $role = 'admin';
        } elseif (!empty($invite_row) && !empty($invite_row['role'])) {
            $role = $invite_row['role'];
        }

        $permissions = array(
            'canRead' => in_array($role, array('admin', 'editor', 'viewer'), true),
            'canWrite' => in_array($role, array('admin', 'editor'), true),
            'canSpawn' => ($role === 'admin'),
            'canManageMembers' => ($role === 'admin'),
        );

        return array($role, $permissions);
    }

    /**
     * Ottiene i dati di sessione in base al token
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Dati della sessione o errore
     */
    public function get_session_data($request)
    {
        $params = $request->get_params();
        $token = sanitize_text_field($params['token'] ?? '');

        if (empty($token)) {
            return new WP_Error('token_missing', 'Token mancante', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        // Recupera la sessione dal database
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE token = %s",
            $token
        ), ARRAY_A);

        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata!', array('status' => 404));
        }

        $user_id = get_current_user_id();
        $user_data = get_userdata($user_id);

        // Membership: creator oppure invitato verificato e non revocato
        $is_admin = intval($session['creatore_id']) === $user_id;
        $invite_row = null;
        if (!$is_admin) {
            $invite_row = $this->get_invite_row_for_user($session['id'], $user_id, $user_data ? $user_data->user_email : '');
            if (!$this->invite_is_active($invite_row)) {
                return new WP_Error('not_authorized', 'Non sei autorizzato a partecipare a questa sessione', array('status' => 403));
            }
        }

        list($role, $permissions) = $this->get_role_permissions($session, $invite_row, $user_id);

        // Decodifica le impostazioni della sessione
        $sessione = json_decode($session['impostazioni'] ?? '{}', true);

        // Decodifica le carte se presenti
        $carte = array();
        if (!empty($session['carte'])) {
            $carte = json_decode($session['carte'], true) ?: array();
        }

        $state_version = isset($session['state_version']) ? intval($session['state_version']) : 1;

        $snapshot = array(
            'carte' => $carte,
            'planciaZoom' => isset($sessione['planciaZoom']) ? floatval($sessione['planciaZoom']) : 1,
            'planciaPosition' => isset($sessione['planciaPosition']) ? $sessione['planciaPosition'] : array('x' => 0, 'y' => 0),
        );

        return array(
            'success' => true,
            'session_id' => $session['id'],
            'user_id' => $user_id,
            'user_name' => $user_data->display_name,
            'role' => $role,
            'permissions' => $permissions,
            'state_version' => $state_version,
            'snapshot' => $snapshot,
            // compat legacy
            'is_admin' => $is_admin,
            'sessione' => $sessione,
            'carte' => $carte
        );
    }

    /**
     * Salva lo stato della sessione
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Risposta o errore
     */
    public function save_session_data($request)
    {
        $params = $request->get_json_params();
        $session_id = intval($params['session_id'] ?? 0);
        $sessione = $params['sessione'] ?? array();
        if (!is_array($sessione)) {
            $sessione = array();
        }
        $carte = $params['carte'] ?? array();

        if (!$session_id) {
            return new WP_Error('session_id_missing', 'ID sessione mancante', array('status' => 400));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        // Recupera la sessione dal database
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $session_id
        ));

        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata!!', array('status' => 404));
        }

        $stored_sessione = json_decode($session->impostazioni ?? '{}', true);
        if (!is_array($stored_sessione)) {
            $stored_sessione = array();
        }

        $sessione = array_merge($stored_sessione, $sessione);

        // V1: supporto snapshot unico. Aggiorna solo i campi di plancia preservando le altre impostazioni.
        if (isset($params['snapshot']) && is_array($params['snapshot'])) {
            $snapshot = $params['snapshot'];
            if (isset($snapshot['carte'])) {
                $carte = $snapshot['carte'];
            }
            if (isset($snapshot['planciaZoom'])) {
                $sessione['planciaZoom'] = $snapshot['planciaZoom'];
            }
            if (isset($snapshot['planciaPosition'])) {
                $sessione['planciaPosition'] = $snapshot['planciaPosition'];
            }
        }

        // Verifica che l'utente corrente sia l'amministratore
        $user_id = get_current_user_id();

        $user_data = get_userdata($user_id);
        $is_admin = (intval($session->creatore_id) === $user_id);
        $invite_role = 'viewer';
        if (!$is_admin) {
            $invite_row = $this->get_invite_row_for_user($session_id, $user_id, $user_data ? $user_data->user_email : '');
            if (!$this->invite_is_active($invite_row)) {
                return new WP_Error('not_authorized', 'Non sei autorizzato a modificare questa sessione', array('status' => 403));
            }
            if (!empty($invite_row['role'])) {
                $invite_role = $invite_row['role'];
            }
        }

        $role = $is_admin ? 'admin' : $invite_role;
        $can_write = in_array($role, array('admin', 'editor'), true);
        if (!$can_write) {
            return new WP_Error('not_authorized', 'Non sei autorizzato a modificare questa sessione', array('status' => 403));
        }

        // Versioning
        $current_version = property_exists($session, 'state_version') ? intval($session->state_version) : 1;
        if ($current_version <= 0) {
            $current_version = 1;
        }
        $base_version = intval($params['base_version'] ?? $current_version);
        if ($base_version !== $current_version) {
            return new WP_Error('version_conflict', 'Conflitto di versione', array(
                'status' => 409,
                'current_version' => $current_version
            ));
        }

        $new_version = $current_version + 1;

        // Aggiorna i dati della sessione
        $wpdb->update(
            $table,
            array(
                'impostazioni' => wp_json_encode($sessione),
                'carte' => wp_json_encode($carte),
                'modificato_il' => current_time('mysql'),
                'state_version' => $new_version,
            ),
            array('id' => $session_id),
            array('%s', '%s', '%s', '%d'),
            array('%d')
        );

        // Emetti un evento Pusher per aggiornare tutti i client
        if ($this->pusher) {
            try {
                $channel = 'presence-scrivania-' . $session_id;
                // V1: notifica leggera, client refetch snapshot
                $this->pusher->trigger($channel, 'state-updated', array(
                    'session_id' => $session_id,
                    'state_version' => $new_version,
                    'updated_by' => $user_id,
                ));

                // Compat legacy
                $this->pusher->trigger($channel, 'session-updated', array(
                    'sessione' => $sessione,
                    'carte' => $carte
                ));
            } catch (Exception $e) {
                // Log the error but don't fail the request
                error_log('Pusher trigger error: ' . $e->getMessage());
            }
        }

        return array(
            'success' => true,
            'message' => 'Sessione aggiornata con successo',
            'state_version' => $new_version,
        );
    }

    /**
     * GET snapshot (refetch) per sessione.
     */
    public function get_snapshot($request) {
        $session_id = intval($request['session_id'] ?? 0);
        if ($session_id <= 0) {
            return new WP_Error('session_id_missing', 'ID sessione mancante', array('status' => 400));
        }

        $user_id = get_current_user_id();
        $user_data = get_userdata($user_id);
        if (!$this->user_can_read_session($session_id, $user_id, $user_data ? $user_data->user_email : '')) {
            return new WP_Error('not_authorized', 'Non autorizzato', array('status' => 403));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $session_id), ARRAY_A);
        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata', array('status' => 404));
        }

        $sessione = json_decode($session['impostazioni'] ?? '{}', true);
        $carte = array();
        if (!empty($session['carte'])) {
            $carte = json_decode($session['carte'], true) ?: array();
        }

        $state_version = isset($session['state_version']) ? intval($session['state_version']) : 1;
        $snapshot = array(
            'carte' => $carte,
            'planciaZoom' => isset($sessione['planciaZoom']) ? floatval($sessione['planciaZoom']) : 1,
            'planciaPosition' => isset($sessione['planciaPosition']) ? $sessione['planciaPosition'] : array('x' => 0, 'y' => 0),
        );

        return array(
            'success' => true,
            'session_id' => intval($session_id),
            'state_version' => $state_version,
            'snapshot' => $snapshot,
            'updated_at' => $session['modificato_il'] ?? null,
        );
    }

    /**
     * POST snapshot: wrapper su save-session con versioning.
     */
    public function save_snapshot($request) {
        $session_id = intval($request['session_id'] ?? 0);
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = array();
        }
        $body['session_id'] = $session_id;
        // Usa lo stesso handler di save-session
        $proxy = new WP_REST_Request('POST', '/scrivania/v1/save-session');
        $proxy->set_body(wp_json_encode($body));
        $proxy->set_header('content-type', 'application/json');
        return $this->save_session_data($proxy);
    }

    /**
     * Admin: aggiorna ruolo membro o rimuove.
     */
    public function update_member_role($request) {
        $session_id = intval($request['session_id'] ?? 0);
        $target_user_id = intval($request['user_id'] ?? 0);
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        if ($session_id <= 0 || $target_user_id <= 0) {
            return new WP_Error('bad_request', 'Parametri mancanti', array('status' => 400));
        }

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $session = $wpdb->get_row($wpdb->prepare("SELECT id, creatore_id FROM {$table_sessions} WHERE id = %d", $session_id), ARRAY_A);
        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata', array('status' => 404));
        }

        $current_user_id = get_current_user_id();
        if (intval($session['creatore_id']) !== intval($current_user_id)) {
            return new WP_Error('not_authorized', 'Solo l\'admin può gestire i permessi', array('status' => 403));
        }

        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        $new_role = isset($params['role']) ? sanitize_text_field($params['role']) : '';
        if ($action !== 'remove' && !in_array($new_role, array('viewer', 'editor'), true)) {
            return new WP_Error('bad_request', 'Ruolo non valido', array('status' => 400));
        }

        $inviti_table = $wpdb->prefix . 'scrivania_invitati';

        if ($action === 'remove') {
            $wpdb->update(
                $inviti_table,
                array('revoked_at' => current_time('mysql'), 'status' => 'revoked'),
                array('sessione_id' => $session_id, 'invitato_user_id' => $target_user_id),
                array('%s', '%s'),
                array('%d', '%d')
            );
        } else {
            $wpdb->update(
                $inviti_table,
                array('role' => $new_role),
                array('sessione_id' => $session_id, 'invitato_user_id' => $target_user_id),
                array('%s'),
                array('%d', '%d')
            );
        }

        // Notifica realtime (opzionale)
        if ($this->pusher) {
            try {
                $channel = 'presence-scrivania-' . $session_id;
                $this->pusher->trigger($channel, 'member-role-updated', array(
                    'session_id' => $session_id,
                    'user_id' => $target_user_id,
                    'role' => ($action === 'remove') ? 'removed' : $new_role,
                ));
            } catch (Exception $e) {
                error_log('Pusher trigger error: ' . $e->getMessage());
            }
        }

        return array('success' => true);
    }

    /**
     * Admin: lista membri di una sessione (creator + invitati con user_id).
     */
    public function list_session_members($request) {
        $session_id = intval($request['session_id'] ?? 0);
        if ($session_id <= 0) {
            return new WP_Error('bad_request', 'Sessione non valida', array('status' => 400));
        }

        global $wpdb;
        $table_sessions = $wpdb->prefix . 'scrivania_sessioni';
        $session = $wpdb->get_row($wpdb->prepare("SELECT id, creatore_id FROM {$table_sessions} WHERE id = %d", $session_id), ARRAY_A);
        if (!$session) {
            return new WP_Error('session_not_found', 'Sessione non trovata', array('status' => 404));
        }

        $current_user_id = get_current_user_id();
        if (intval($session['creatore_id']) !== intval($current_user_id)) {
            return new WP_Error('not_authorized', 'Solo l\'admin può vedere i permessi dei membri', array('status' => 403));
        }

        $members = array();

        // Creator
        $creator_user_id = intval($session['creatore_id']);
        $creator = get_userdata($creator_user_id);
        $members[] = array(
            'user_id' => $creator_user_id,
            'role' => 'admin',
            'status' => 'active',
            'name' => $creator ? $creator->display_name : ('User ' . $creator_user_id),
            'avatar_url' => get_avatar_url($creator_user_id, array('size' => 64)),
        );

        // Invitati con user_id
        $inviti_table = $wpdb->prefix . 'scrivania_invitati';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT invitato_user_id, role, status, verified_at, revoked_at FROM {$inviti_table} WHERE sessione_id = %d AND invitato_user_id IS NOT NULL AND invitato_user_id <> 0",
                $session_id
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $uid = intval($row['invitato_user_id']);
            if ($uid <= 0 || $uid === $creator_user_id) {
                continue;
            }

            $user = get_userdata($uid);
            $members[] = array(
                'user_id' => $uid,
                'role' => !empty($row['role']) ? sanitize_text_field($row['role']) : 'viewer',
                'status' => !empty($row['status']) ? sanitize_text_field($row['status']) : 'active',
                'verified_at' => $row['verified_at'] ?? null,
                'revoked_at' => $row['revoked_at'] ?? null,
                'name' => $user ? $user->display_name : ('User ' . $uid),
                'avatar_url' => get_avatar_url($uid, array('size' => 64)),
            );
        }

        return array(
            'session_id' => $session_id,
            'members' => $members,
        );
    }

    /**
     * Crea una nuova sessione
     *
     * @param WP_REST_Request $request Richiesta REST
     * @return array|WP_Error Risposta o errore
     */
    public function create_session($request)
    {
        $params = $request->get_params();
        $nome = sanitize_text_field($params['nome'] ?? 'Nuova Sessione');
        $raw_deck_id = $params['mazzo_id'] ?? ($params['mazzoId'] ?? 0);
        if (function_exists('gim_normalize_scrivania_deck_id')) {
            $deck_id = gim_normalize_scrivania_deck_id($raw_deck_id);
        } else {
            $deck_id = intval($raw_deck_id);
            if (!in_array($deck_id, array(0, 1), true)) {
                $deck_id = 0;
            }
        }

        $user_id = get_current_user_id();

        // Genera un token unico
        $token = wp_generate_password(24, false);

        // Impostazioni iniziali
        $impostazioni = array(
            'attiva' => false,
            'iniziata' => null,
            'mazzoId' => $deck_id,
            'sfondo' => null
        );

        global $wpdb;
        $table = $wpdb->prefix . 'scrivania_sessioni';

        // Inserisci la nuova sessione
        $result = $wpdb->insert(
            $table,
            array(
                'token' => $token,
                'creatore_id' => $user_id,
                'nome' => $nome,
                'impostazioni' => wp_json_encode($impostazioni),
                'creato_il' => current_time('mysql'),
                'modificato_il' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            return new WP_Error('db_error', 'Errore nella creazione della sessione', array('status' => 500));
        }

        $session_id = $wpdb->insert_id;

        return array(
            'success' => true,
            'session_id' => $session_id,
            'token' => $token,
            'message' => 'Sessione creata con successo'
        );
    }

    /**
     * Aggiunge il menu di amministrazione per le impostazioni
     */
    public function add_admin_menu()
    {
        add_options_page(
            'Impostazioni Scrivania Collaborativa',
            'Scrivania Collaborativa',
            'manage_options',
            'scrivania-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Registra le impostazioni per Pusher
     */
    public function register_settings()
    {
        register_setting('scrivania_settings', 'scrivania_pusher_app_id');
        register_setting('scrivania_settings', 'scrivania_pusher_app_key');
        register_setting('scrivania_settings', 'scrivania_pusher_app_secret');
        register_setting('scrivania_settings', 'scrivania_pusher_cluster');
        register_setting('scrivania_settings', 'scrivania_pusher_debug');

        add_settings_section(
            'scrivania_pusher_section',
            'Impostazioni Pusher',
            array($this, 'section_info'),
            'scrivania-settings'
        );

        add_settings_field(
            'scrivania_pusher_app_id',
            'App ID',
            array($this, 'app_id_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );

        add_settings_field(
            'scrivania_pusher_app_key',
            'App Key',
            array($this, 'app_key_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );

        add_settings_field(
            'scrivania_pusher_app_secret',
            'App Secret',
            array($this, 'app_secret_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );

        add_settings_field(
            'scrivania_pusher_cluster',
            'Cluster',
            array($this, 'cluster_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );

        add_settings_field(
            'scrivania_pusher_debug',
            'Debug Mode',
            array($this, 'debug_callback'),
            'scrivania-settings',
            'scrivania_pusher_section'
        );
    }

    /**
     * Renderizza la pagina delle impostazioni
     */
    public function settings_page()
    {
?>
        <div class="wrap">
            <h1>Impostazioni Scrivania Collaborativa</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('scrivania_settings');
                do_settings_sections('scrivania-settings');
                submit_button();
                ?>
            </form>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2>Informazioni sul plugin</h2>
                <p>Questo plugin integra il Tool Scrivania Collaborativa con Pusher per consentire interazioni in tempo reale tra più utenti.</p>
                <p>Per utilizzare correttamente il plugin, è necessario:</p>
                <ol>
                    <li>Creare un account gratuito su <a href="https://pusher.com/" target="_blank">Pusher.com</a></li>
                    <li>Creare una nuova app Pusher e inserire le credenziali nelle impostazioni sopra</li>
                    <li>Nella dashboard di Pusher, abilitare "Client Events" e "Authorized Connections"</li>
                </ol>
                <p>Per assistenza, contatta il supporto tecnico.</p>
            </div>
        </div>
    <?php
    }

    /**
     * Informazioni sulla sezione
     */
    public function section_info()
    {
        echo '<p>Inserisci le credenziali Pusher per abilitare le funzionalità collaborative della Scrivania. È necessario avere un account su <a href="https://pusher.com/" target="_blank">Pusher.com</a>.</p>';
    }

    /**
     * Callback per App ID
     */
    public function app_id_callback()
    {
        $value = get_option('scrivania_pusher_app_id', '');
        echo '<input type="text" name="scrivania_pusher_app_id" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    /**
     * Callback per App Key
     */
    public function app_key_callback()
    {
        $value = get_option('scrivania_pusher_app_key', '');
        echo '<input type="text" name="scrivania_pusher_app_key" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    /**
     * Callback per App Secret
     */
    public function app_secret_callback()
    {
        $value = get_option('scrivania_pusher_app_secret', '');
        echo '<input type="text" name="scrivania_pusher_app_secret" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    /**
     * Callback per Cluster
     */
    public function cluster_callback()
    {
        $value = get_option('scrivania_pusher_cluster', 'eu');
    ?>
        <select name="scrivania_pusher_cluster">
            <option value="us1" <?php selected($value, 'us1'); ?>>us1</option>
            <option value="us2" <?php selected($value, 'us2'); ?>>us2</option>
            <option value="eu" <?php selected($value, 'eu'); ?>>eu</option>
            <option value="ap1" <?php selected($value, 'ap1'); ?>>ap1</option>
            <option value="ap2" <?php selected($value, 'ap2'); ?>>ap2</option>
            <option value="ap3" <?php selected($value, 'ap3'); ?>>ap3</option>
            <option value="ap4" <?php selected($value, 'ap4'); ?>>ap4</option>
            <option value="mt1" <?php selected($value, 'mt1'); ?>>mt1</option>
            <option value="sa1" <?php selected($value, 'sa1'); ?>>sa1</option>
        </select>
<?php
    }

    /**
     * Callback per Debug Mode
     */
    public function debug_callback()
    {
        $value = get_option('scrivania_pusher_debug', '0');
        echo '<input type="checkbox" name="scrivania_pusher_debug" value="1" ' . checked('1', $value, false) . ' /> ';
        echo 'Abilita modalità debug (solo per sviluppo)';
    }

    /**
     * Aggiunge lo script con le configurazioni Pusher
     */
    public function enqueue_pusher_config()
    {
        // Nota: le pagine tool/invito caricano già bundle+CSS dal template del tema
        // (themes/hello-theme-child-master/tool-scrivania.php) con versioning via filemtime.
        // Qui evitiamo di enqueueare script legacy (pusher-config.js, wp-api, doppio bundle)
        // che in produzione può causare conflitti ed errori JS.
        return;
    }
}
