<?php
if (!defined('ABSPATH')) { exit; }

/**
 * HITL (Human in the Loop) backend.
 *
 * Endpoints:
 * - POST /wp-json/grgrowth/v1/cc/push
 * - GET  /wp-json/grgrowth/v1/cc/conversations/{id}/updates?after=123
 * - POST /wp-json/grgrowth/v1/cc/conversations/{id}/hitl/submit
 */

// -----------------------------------------------------------------------------
// Options / settings helpers
// -----------------------------------------------------------------------------

function grgw_cc_hitl_db_target_version() : string {
    return '1.2.0';
}

function grgw_cc_hitl_timeout_sec() : int {
    $v = (int) get_option('grgw_cc_hitl_timeout_sec', 3600);
    if ($v <= 0) $v = 3600;
    // clamp: 1 minuto .. 24 ore
    if ($v < 60) $v = 60;
    if ($v > 86400) $v = 86400;
    return $v;
}

function grgw_cc_hitl_poll_interval_ms() : int {
    $v = (int) get_option('grgw_cc_hitl_poll_interval_ms', 1000);
    if ($v < 250) $v = 250;
    if ($v > 10000) $v = 10000;
    return $v;
}

function grgw_cc_hitl_resume_url() : string {
    $v = (string) get_option('grgw_cc_hitl_resume_url', '');
    $v = trim($v);
    return esc_url_raw($v);
}

function grgw_cc_hitl_tables() : array {
    global $wpdb;

    if (function_exists('grgw_cc_tables')) {
        $t = grgw_cc_tables();
        $t['hitl_idempotency'] = $t['hitl_idempotency'] ?? ($wpdb->prefix . 'grgw_cc_hitl_idempotency');
        $t['hitl_outbox']      = $t['hitl_outbox'] ?? ($wpdb->prefix . 'grgw_cc_hitl_outbox');
        return $t;
    }

    return [
        'conversations' => $wpdb->prefix . 'grgw_cc_conversations',
        'messages' => $wpdb->prefix . 'grgw_cc_messages',
        'hitl_idempotency' => $wpdb->prefix . 'grgw_cc_hitl_idempotency',
        'hitl_outbox' => $wpdb->prefix . 'grgw_cc_hitl_outbox',
    ];
}

// -----------------------------------------------------------------------------
// Locking (anti race-condition)
// -----------------------------------------------------------------------------

/**
 * Chiave lock corta e deterministica (limite MySQL: 64 chars).
 */
function grgw_cc_hitl_lock_key(string $conversation_id) : string {
    return 'grgw_cc_hitl_' . substr(md5($conversation_id), 0, 32);
}

/**
 * Prova a prendere un lock per conversazione.
 * - Preferito: MySQL GET_LOCK (se disponibile)
 * - Fallback: add_option con TTL (best-effort)
 */
function grgw_cc_hitl_acquire_lock(string $conversation_id, int $timeout_sec = 1) : array {
    global $wpdb;
    $timeout_sec = max(0, min(10, $timeout_sec));

    $key = grgw_cc_hitl_lock_key($conversation_id);

    // 1) MySQL/MariaDB GET_LOCK
    try {
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $key, $timeout_sec));
        if ((string) $got === '1') {
            return ['type' => 'mysql', 'key' => $key];
        }
    } catch (Throwable $e) {
        // Ignora: fallback sotto
    }

    // 2) Fallback: option-lock (best effort)
    $opt_key = $wpdb->prefix . 'grgw_cc_lock_' . $key;
    $now = time();
    $ttl = max(2, $timeout_sec + 2);
    $exp = (string) ($now + $ttl);

    if (add_option($opt_key, $exp, '', 'no')) {
        return ['type' => 'option', 'key' => $opt_key];
    }

    $curr = (int) get_option($opt_key, 0);
    if ($curr > 0 && $curr < $now) {
        // Scaduto: tentiamo takeover (non perfetto ma migliora molto).
        update_option($opt_key, $exp, false);
        return ['type' => 'option', 'key' => $opt_key];
    }

    return ['type' => 'none', 'key' => $key];
}

function grgw_cc_hitl_release_lock(array $ctx) : void {
    global $wpdb;
    $type = $ctx['type'] ?? 'none';
    $key  = $ctx['key'] ?? '';
    if ($type === 'mysql' && $key !== '') {
        try {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        } catch (Throwable $e) {
            // best effort
        }
        return;
    }
    if ($type === 'option' && $key !== '') {
        delete_option($key);
        return;
    }
}

/**
 * Esegue $fn proteggendo la sezione critica per conversazione.
 */
function grgw_cc_hitl_with_lock(string $conversation_id, callable $fn, int $timeout_sec = 1) {
    $ctx = grgw_cc_hitl_acquire_lock($conversation_id, $timeout_sec);
    try {
        return $fn();
    } finally {
        grgw_cc_hitl_release_lock($ctx);
    }
}

function grgw_cc_hitl_get_secret() : string {
    if (function_exists('grgw_cc_get_secret')) {
        return (string) grgw_cc_get_secret();
    }
    if (defined('GRGW_N8N_SECRET') && GRGW_N8N_SECRET) {
        return (string) GRGW_N8N_SECRET;
    }
    return (string) get_option('grgw_cc_secret', '');
}

function grgw_cc_hitl_now_mysql() : string {
    return (string) current_time('mysql');
}

function grgw_cc_hitl_now_ts() : int {
    return (int) current_time('timestamp');
}

function grgw_cc_hitl_ts_to_mysql(int $ts) : string {
    // Converte timestamp UNIX → stringa MySQL nel timezone di WordPress (gestisce anche DST).
    // Evita gmt_offset (che può essere sballato con l'ora legale).
    try {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        // Fallback conservativo
        return gmdate('Y-m-d H:i:s', $ts);
    }
}

function grgw_cc_hitl_mysql_to_ts(string $mysql) : int {
    $mysql = trim($mysql);
    if ($mysql === '') return 0;
    try {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->getTimestamp();
        }
    } catch (Exception $e) {
        // ignore
    }
    // Fallback: usa strtotime (dipende dal timezone di PHP, ma meglio di niente)
    $ts = strtotime($mysql);
    return $ts ? (int) $ts : 0;
}

// -----------------------------------------------------------------------------
// Admin settings (aggiunte nella pagina Impostazioni → GRGW Chat Gateway)
// -----------------------------------------------------------------------------

add_action('admin_init', function() {
    // Modalità trasporto HITL:
    // - pull: WP NON chiama n8n. Salva l'input utente in outbox; n8n lo recupera con /outbox/pop.
    // - webhook: WP fa POST al resume URL (comportamento legacy).
    register_setting('grgw_cc_settings', 'grgw_cc_hitl_transport', [
        'type' => 'string',
        'sanitize_callback' => function($v){
            $v = strtolower(trim((string)$v));
            return in_array($v, ['pull','webhook'], true) ? $v : 'pull';
        },
        'default' => 'pull',
    ]);

    register_setting('grgw_cc_settings', 'grgw_cc_hitl_resume_url', [
        'type' => 'string',
        'sanitize_callback' => function($v){ return esc_url_raw(trim((string)$v)); },
        'default' => '',
    ]);

    register_setting('grgw_cc_settings', 'grgw_cc_hitl_timeout_sec', [
        'type' => 'integer',
        'sanitize_callback' => function($v){
            $iv = (int) $v;
            if ($iv <= 0) return 3600;
            if ($iv < 60) return 60;
            if ($iv > 86400) return 86400;
            return $iv;
        },
        'default' => 3600,
    ]);

    register_setting('grgw_cc_settings', 'grgw_cc_hitl_poll_interval_ms', [
        'type' => 'integer',
        'sanitize_callback' => function($v){
            $iv = (int) $v;
            if ($iv <= 0) return 1000;
            if ($iv < 250) return 250;
            if ($iv > 10000) return 10000;
            return $iv;
        },
        'default' => 1000,
    ]);

    add_settings_section(
        'grgw_cc_hitl',
        'HITL (Human in the Loop)',
        function(){
            echo '<p>Impostazioni per la modalità approvazione/ri-lavorazione (HITL). Il frontend userà questi valori quando attiverai il polling.</p>';
        },
        'grgw-chat-gateway'
    );

    add_settings_field(
        'grgw_cc_hitl_transport',
        'Trasporto HITL',
        function(){
            $val = (string) get_option('grgw_cc_hitl_transport', 'pull');
            $val = in_array($val, ['pull','webhook'], true) ? $val : 'pull';
            echo '<label style="display:block;margin-bottom:6px">';
            echo '<input type="radio" name="grgw_cc_hitl_transport" value="pull" ' . checked($val, 'pull', false) . ' /> ';
            echo '<strong>Pull (consigliato)</strong> — WP salva la risposta e <em>n8n la legge</em> (niente chiamate dal plugin verso n8n).';
            echo '</label>';
            echo '<label style="display:block">';
            echo '<input type="radio" name="grgw_cc_hitl_transport" value="webhook" ' . checked($val, 'webhook', false) . ' /> ';
            echo '<strong>Webhook (legacy)</strong> — WP invia la risposta al <code>resume URL</code>.';
            echo '</label>';
            echo '<p class="description">Se scegli <strong>Pull</strong>, n8n deve chiamare <code>/cc/conversations/{id}/outbox/pop</code> per recuperare l’input utente.</p>';
        },
        'grgw-chat-gateway',
        'grgw_cc_hitl'
    );

    add_settings_field(
        'grgw_cc_hitl_resume_url',
        'n8n HITL resume URL',
        function(){
            $val = esc_attr((string) get_option('grgw_cc_hitl_resume_url', ''));
            echo '<input type="url" name="grgw_cc_hitl_resume_url" value="' . $val . '" class="regular-text" placeholder="https://.../webhook/hitl-resume" />';
            echo '<p class="description">Usato solo se <strong>Trasporto HITL = Webhook</strong>. In modalità Pull puoi lasciarlo vuoto.</p>';
        },
        'grgw-chat-gateway',
        'grgw_cc_hitl'
    );

    add_settings_field(
        'grgw_cc_hitl_timeout_sec',
        'Timeout HITL (secondi)',
        function(){
            $val = (int) get_option('grgw_cc_hitl_timeout_sec', 3600);
            echo '<input type="number" name="grgw_cc_hitl_timeout_sec" value="' . esc_attr((string)$val) . '" min="60" max="86400" style="width:120px" />';
            echo '<p class="description">Default: 3600 (1 ora). Se scade, WP spegne HITL e inserisce un messaggio <code>poll_stop</code>.</p>';
        },
        'grgw-chat-gateway',
        'grgw_cc_hitl'
    );

    add_settings_field(
        'grgw_cc_hitl_poll_interval_ms',
        'Polling interval HITL (ms)',
        function(){
            $val = (int) get_option('grgw_cc_hitl_poll_interval_ms', 1000);
            echo '<input type="number" name="grgw_cc_hitl_poll_interval_ms" value="' . esc_attr((string)$val) . '" min="250" max="10000" style="width:120px" />';
            echo '<p class="description">Default: 1000ms. Fuori HITL il polling resta spento.</p>';
        },
        'grgw-chat-gateway',
        'grgw_cc_hitl'
    );
});

// -----------------------------------------------------------------------------
// DB upgrade
// -----------------------------------------------------------------------------

function grgw_cc_hitl_maybe_upgrade_db() : void {
    $current = (string) get_option('grgw_cc_db_version', '0.0.0');
    $target  = grgw_cc_hitl_db_target_version();

    if (version_compare($current, $target, '>=')) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $tables = grgw_cc_hitl_tables();
    $charset_collate = $wpdb->get_charset_collate();

    $sql_conversations = "CREATE TABLE {$tables['conversations']} (
        id char(36) NOT NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        title varchar(255) NOT NULL DEFAULT 'Nuova chat',
        is_title_custom tinyint(1) NOT NULL DEFAULT 0,
        provider varchar(50) NOT NULL DEFAULT 'google',
        model varchar(100) NOT NULL DEFAULT 'gemini-2.0-flash',
        thread_id varchar(120) NOT NULL,

        hitl_active tinyint(1) NOT NULL DEFAULT 0,
        hitl_id varchar(64) NULL,
        hitl_started_at datetime NULL,
        hitl_expires_at datetime NULL,

        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY user_updated (user_id, updated_at),
        KEY hitl_active (hitl_active)
    ) $charset_collate;";

    $sql_messages = "CREATE TABLE {$tables['messages']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        conversation_id char(36) NOT NULL,
        role varchar(20) NOT NULL,
        content longtext NOT NULL,
        meta longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY conv_id (conversation_id, id)
    ) $charset_collate;";

    $sql_idem = "CREATE TABLE {$tables['hitl_idempotency']} (
        conversation_id char(36) NOT NULL,
        hitl_id varchar(64) NOT NULL,
        submit_id varchar(64) NOT NULL,
        response_json longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (conversation_id, hitl_id, submit_id),
        KEY created_at (created_at)
    ) $charset_collate;";

    // Outbox: contiene al massimo 1 messaggio "in attesa" per conversazione.
    // Serve quando HITL lavora in modalità pull: n8n legge con /outbox/pop e poi il record viene cancellato.
    $sql_outbox = "CREATE TABLE {$tables['hitl_outbox']} (
        conversation_id char(36) NOT NULL,
        payload_json longtext NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (conversation_id),
        KEY updated_at (updated_at)
    ) $charset_collate;";

    dbDelta($sql_conversations);
    dbDelta($sql_messages);
    dbDelta($sql_idem);
    dbDelta($sql_outbox);

    update_option('grgw_cc_db_version', $target);
}

// NB: Lo schema DB viene gestito da includes/db.php (grgw_cc_maybe_upgrade_db + grgw_cc_install).
// Evitiamo doppie chiamate dbDelta qui (che possono rallentare e creare comportamenti incoerenti).

// -----------------------------------------------------------------------------
// REST endpoints
// -----------------------------------------------------------------------------

function grgw_cc_hitl_rest_can_use($request) {
    if (function_exists('grgw_cc_rest_can_use')) {
        return grgw_cc_rest_can_use($request);
    }
    return current_user_can('read');
}

function grgw_cc_hitl_rest_can_push($request) {
    $secret = grgw_cc_hitl_get_secret();
    if ($secret === '') {
        return new WP_Error('grgw_cc_no_secret', 'Secret non configurato.', ['status' => 500]);
    }

    $hdr = (string) $request->get_header('X-GRGW-SECRET');
    if ($hdr === '') {
        $hdr = (string) $request->get_header('x-grgw-secret');
    }

    if (!hash_equals($secret, (string)$hdr)) {
        return new WP_Error('grgw_cc_forbidden', 'Forbidden.', ['status' => 403]);
    }

    return true;
}

/**
 * Build a JSON REST response.
 *
 * NOTE: For the /cc/push endpoint we intentionally keep HTTP 200 also on
 * validation errors, so external callers (e.g. n8n) don't have to rely on
 * "Never Error" to read a JSON body.
 */
function grgw_cc_hitl_json_response(array $payload, int $status = 200) : WP_REST_Response {
    return new WP_REST_Response($payload, $status);
}

function grgw_cc_hitl_json_error(string $code, string $message, array $extra = [], int $status = 200) : WP_REST_Response {
    $payload = array_merge([
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], $extra);
    return grgw_cc_hitl_json_response($payload, $status);
}

add_action('rest_api_init', function() {
    register_rest_route('grgrowth/v1', '/cc/push', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => 'grgw_cc_hitl_rest_push',
        'args' => [],
    ]);

    // Debug endpoint (requires secret): returns the received payload.
    register_rest_route('grgrowth/v1', '/cc/push-echo', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => function($request) {
            $params = $request->get_json_params();
            if (!is_array($params)) {
                $params = [];
            }
            return grgw_cc_hitl_json_response([
                'ok' => true,
                'received' => $params,
                'server_time' => gmdate('c'),
            ], 200);
        },
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/updates', [
        'methods' => 'GET',
        'permission_callback' => 'grgw_cc_hitl_rest_can_use',
        'callback' => 'grgw_cc_hitl_rest_updates',
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/hitl/submit', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_hitl_rest_can_use',
        'callback' => 'grgw_cc_hitl_rest_submit',
        'args' => [],
    ]);

    // Outbox (server-to-server, richiede secret): n8n recupera qui l'input utente quando HITL è in modalità Pull.
    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/outbox/pop', [
        'methods' => 'GET',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => 'grgw_cc_hitl_rest_outbox_pop',
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/outbox/peek', [
        'methods' => 'GET',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => 'grgw_cc_hitl_rest_outbox_peek',
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/outbox/clear', [
        'methods' => 'DELETE',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => 'grgw_cc_hitl_rest_outbox_clear',
        'args' => [],
    ]);
});

// -----------------------------------------------------------------------------
// Core HITL logic
// -----------------------------------------------------------------------------

function grgw_cc_hitl_compute_etag(string $conversation_id, int $last_message_id, string $updated_at) : string {
    $key = $conversation_id . ':' . $last_message_id . ':' . $updated_at;
    return 'W/"' . rawurlencode($key) . '"';
}

function grgw_cc_hitl_insert_message(string $conversation_id, string $role, string $content, $meta = null) : int {
    if (function_exists('grgw_cc_insert_message')) {
        return (int) grgw_cc_insert_message($conversation_id, $role, $content, $meta);
    }

    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    $meta_str = null;
    if ($meta !== null) {
        $meta_str = wp_json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $wpdb->insert(
        $tables['messages'],
        [
            'conversation_id' => $conversation_id,
            'role' => $role,
            'content' => $content,
            'meta' => $meta_str,
            'created_at' => grgw_cc_hitl_now_mysql(),
        ],
        ['%s','%s','%s','%s','%s']
    );

    return (int) $wpdb->insert_id;
}

function grgw_cc_hitl_touch_conversation(string $conversation_id) : void {
    if (function_exists('grgw_cc_touch_conversation_any')) {
        grgw_cc_touch_conversation_any($conversation_id);
        return;
    }

    global $wpdb;
    $tables = grgw_cc_hitl_tables();
    $wpdb->update(
        $tables['conversations'],
        ['updated_at' => grgw_cc_hitl_now_mysql()],
        ['id' => $conversation_id],
        ['%s'],
        ['%s']
    );
}

function grgw_cc_hitl_set_state_any(string $conversation_id, bool $active, ?string $hitl_id = null) : void {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    if (!$active) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['conversations']} SET hitl_active = 0, hitl_id = NULL, hitl_started_at = NULL, hitl_expires_at = NULL, updated_at = %s WHERE id = %s",
            grgw_cc_hitl_now_mysql(),
            $conversation_id
        ));
        return;
    }

    $started_at = grgw_cc_hitl_now_mysql();
    $expires_at = grgw_cc_hitl_ts_to_mysql(grgw_cc_hitl_now_ts() + grgw_cc_hitl_timeout_sec());

    $wpdb->update(
        $tables['conversations'],
        [
            'hitl_active' => 1,
            'hitl_id' => $hitl_id,
            'hitl_started_at' => $started_at,
            'hitl_expires_at' => $expires_at,
            'updated_at' => grgw_cc_hitl_now_mysql(),
        ],
        ['id' => $conversation_id],
        ['%d','%s','%s','%s','%s'],
        ['%s']
    );
}

function grgw_cc_hitl_maybe_expire(string $conversation_id) : void {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

	// Protezione anti race: una sola richiesta per conversazione gestisce lo scadere.
	$runner = function() use ($wpdb, $tables, $conversation_id) {
		$conv = $wpdb->get_row($wpdb->prepare(
			"SELECT id, hitl_active, hitl_id, hitl_expires_at FROM {$tables['conversations']} WHERE id = %s",
			$conversation_id
		), ARRAY_A);

		if (!$conv) return;

		$active = (int) ($conv['hitl_active'] ?? 0);
		$expires_at = (string) ($conv['hitl_expires_at'] ?? '');

		if ($active !== 1) return;
		if ($expires_at === '') return;

		$now = grgw_cc_hitl_now_ts();
		$exp_ts = grgw_cc_hitl_mysql_to_ts($expires_at);

		if ($exp_ts > 0 && $now > $exp_ts) {
			// Spegni HITL e inserisci un messaggio persistente di stop.
			$hitl_id = (string) ($conv['hitl_id'] ?? '');
			grgw_cc_hitl_set_state_any($conversation_id, false, null);

			grgw_cc_hitl_insert_message(
				$conversation_id,
				'assistant',
				'Sessione di approvazione scaduta (timeout).',
				[
					'hitl' => true,
					'hitl_id' => $hitl_id,
					'type' => 'poll_stop',
					'reason' => 'timeout',
				]
			);

			grgw_cc_hitl_touch_conversation($conversation_id);
		}
	};

	if (function_exists('grgw_cc_hitl_with_lock')) {
		grgw_cc_hitl_with_lock($conversation_id, $runner, 1);
	} else {
		$runner();
	}
}

// -----------------------------------------------------------------------------
// Idempotency helpers
// -----------------------------------------------------------------------------

// NB: queste funzioni possono essere già definite in includes/db.php (versioni precedenti).
// Qui le definiamo solo se non esistono, per evitare fatal error "Cannot redeclare".
if (!function_exists('grgw_cc_hitl_idem_get')) {
function grgw_cc_hitl_idem_get(string $conversation_id, string $hitl_id, string $submit_id) : ?array {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    $hitl_id = sanitize_text_field($hitl_id);
    $submit_id = sanitize_text_field($submit_id);
    if ($hitl_id === '' || $submit_id === '') return null;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT response_json FROM {$tables['hitl_idempotency']} WHERE conversation_id = %s AND hitl_id = %s AND submit_id = %s",
        $conversation_id,
        $hitl_id,
        $submit_id
    ), ARRAY_A);

    if (!$row) return null;

    $decoded = json_decode((string) $row['response_json'], true);
    return is_array($decoded) ? $decoded : null;
}
}

if (!function_exists('grgw_cc_hitl_idem_put')) {
function grgw_cc_hitl_idem_put(string $conversation_id, string $hitl_id, string $submit_id, array $response) : void {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    $hitl_id = sanitize_text_field($hitl_id);
    $submit_id = sanitize_text_field($submit_id);
    if ($hitl_id === '' || $submit_id === '') return;

    if (strlen($submit_id) > 64) {
        $submit_id = substr(hash('sha256', $submit_id), 0, 64);
    }

    $json = wp_json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$tables['hitl_idempotency']} (conversation_id, hitl_id, submit_id, response_json, created_at)
         VALUES (%s, %s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE response_json = VALUES(response_json)",
        $conversation_id,
        $hitl_id,
        $submit_id,
        $json,
        grgw_cc_hitl_now_mysql()
    ));
}
}

// -----------------------------------------------------------------------------
// Outbox helpers (HITL pull-mode)
// -----------------------------------------------------------------------------

if (!function_exists('grgw_cc_hitl_outbox_has')) {
function grgw_cc_hitl_outbox_has(string $conversation_id) : bool {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();
    $table = $tables['hitl_outbox'] ?? null;
    if (!$table) return false;
    $row = $wpdb->get_var($wpdb->prepare(
        "SELECT conversation_id FROM {$table} WHERE conversation_id = %s LIMIT 1",
        $conversation_id
    ));
    return !empty($row);
}
}

if (!function_exists('grgw_cc_hitl_outbox_put')) {
function grgw_cc_hitl_outbox_put(string $conversation_id, array $payload) : bool {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();
    $table = $tables['hitl_outbox'] ?? null;
    if (!$table) return false;
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return false;
    $now = grgw_cc_hitl_now_mysql();
    // 1 slot: se già presente, non sovrascriviamo.
    $result = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table} (conversation_id, payload_json, created_at, updated_at) VALUES (%s, %s, %s, %s)",
        $conversation_id,
        $json,
        $now,
        $now
    ));
    return (int) $result === 1;
}
}

if (!function_exists('grgw_cc_hitl_outbox_peek')) {
function grgw_cc_hitl_outbox_peek(string $conversation_id) : ?array {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();
    $table = $tables['hitl_outbox'] ?? null;
    if (!$table) return null;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT payload_json, created_at, updated_at FROM {$table} WHERE conversation_id = %s LIMIT 1",
        $conversation_id
    ), ARRAY_A);
    if (!$row) return null;
    $decoded = json_decode((string) $row['payload_json'], true);
    if (!is_array($decoded)) $decoded = null;
    return [
        'payload' => $decoded,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}
}

if (!function_exists('grgw_cc_hitl_outbox_clear')) {
function grgw_cc_hitl_outbox_clear(string $conversation_id) : void {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();
    $table = $tables['hitl_outbox'] ?? null;
    if (!$table) return;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE conversation_id = %s",
        $conversation_id
    ));
}
}

if (!function_exists('grgw_cc_hitl_outbox_pop')) {
function grgw_cc_hitl_outbox_pop(string $conversation_id) : ?array {
    $peek = grgw_cc_hitl_outbox_peek($conversation_id);
    if (!$peek || !is_array($peek['payload'] ?? null)) return null;
    grgw_cc_hitl_outbox_clear($conversation_id);
    return $peek;
}
}

// -----------------------------------------------------------------------------
// REST handlers
// -----------------------------------------------------------------------------

function grgw_cc_hitl_rest_push(WP_REST_Request $request) {
    // Prefer JSON. If WP can't parse it for some reason, fallback to raw body.
    $params = $request->get_json_params();
    if (!is_array($params) || empty($params)) {
        $raw = trim((string) $request->get_body());
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $params = is_array($decoded) ? $decoded : [];
    }

    $conversation_id = sanitize_text_field((string) ($params['conversation_id'] ?? ''));
    if ($conversation_id === '' || !preg_match('/^[a-f0-9\-]{36}$/', $conversation_id)) {
        return grgw_cc_hitl_json_error(
            'grgw_cc_bad_conversation',
            'conversation_id non valido.',
            [ 'conversation_id' => $conversation_id ]
        );
    }

    // Validate that the conversation actually exists (server-to-server).
    if (!function_exists('grgw_cc_get_conversation_any') || !grgw_cc_get_conversation_any($conversation_id)) {
        return grgw_cc_hitl_json_error(
            'grgw_cc_bad_conversation',
            'conversation_id non valido.',
            [ 'conversation_id' => $conversation_id ]
        );
    }

    $message = $params['message'] ?? null;
    if (!is_array($message)) {
        return grgw_cc_hitl_json_error(
            'grgw_cc_bad_message',
            'message non valido.',
            [ 'conversation_id' => $conversation_id ]
        );
    }

    $role = sanitize_text_field((string) ($message['role'] ?? 'assistant'));
    if ($role === '') $role = 'assistant';

    $content = (string) ($message['content'] ?? '');
    $content = trim($content);
    if ($content === '') $content = '(messaggio vuoto)';

    $meta = $message['meta'] ?? null;
    if ($meta !== null && !is_array($meta)) {
        $meta = null;
    }

    // Gestione HITL state (accendi/spegni) su push
    $meta_type = is_array($meta) ? (string) ($meta['type'] ?? '') : '';
    $meta_hitl = is_array($meta) ? (bool) ($meta['hitl'] ?? false) : false;
    $meta_hitl_id = is_array($meta) ? (string) ($meta['hitl_id'] ?? '') : '';

    if ($meta_hitl && $meta_type === 'hitl_request') {
        $hid = sanitize_text_field($meta_hitl_id);
        if ($hid === '') {
            // fallback: hitl_<random>
            $hid = 'hitl_' . substr(wp_generate_password(16, false, false), 0, 16);
        }
        grgw_cc_hitl_set_state_any($conversation_id, true, $hid);
    }

    if ($meta_type === 'poll_stop') {
        grgw_cc_hitl_set_state_any($conversation_id, false, null);
    }

    $message_id = grgw_cc_hitl_insert_message($conversation_id, $role, $content, $meta);
    if ((int) $message_id <= 0) {
        return grgw_cc_hitl_json_error(
            'grgw_cc_push_failed',
            'Impossibile inserire messaggio.',
            [ 'conversation_id' => $conversation_id ]
        );
    }
    grgw_cc_hitl_touch_conversation($conversation_id);

    return rest_ensure_response([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'message_id' => (int) $message_id,
    ]);
}

// Outbox endpoints (server-to-server)
function grgw_cc_hitl_rest_outbox_peek(WP_REST_Request $request) {
    $conversation_id = (string) $request['id'];
    if ($conversation_id === '' || !preg_match('/^[a-f0-9\-]{36}$/', $conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    // Verifica conversazione esiste
    if (!function_exists('grgw_cc_get_conversation_any') || !grgw_cc_get_conversation_any($conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    $row = grgw_cc_hitl_outbox_peek($conversation_id);
    return rest_ensure_response([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'empty' => $row ? false : true,
        'item' => $row ? $row['payload'] : null,
        'meta' => $row ? $row['meta'] : null,
    ]);
}

function grgw_cc_hitl_rest_outbox_pop(WP_REST_Request $request) {
    $conversation_id = (string) $request['id'];
    if ($conversation_id === '' || !preg_match('/^[a-f0-9\-]{36}$/', $conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    if (!function_exists('grgw_cc_get_conversation_any') || !grgw_cc_get_conversation_any($conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    $row = grgw_cc_hitl_outbox_pop($conversation_id);
    return rest_ensure_response([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'empty' => $row ? false : true,
        'item' => $row ? $row['payload'] : null,
        'meta' => $row ? $row['meta'] : null,
    ]);
}

function grgw_cc_hitl_rest_outbox_clear(WP_REST_Request $request) {
    $conversation_id = (string) $request['id'];
    if ($conversation_id === '' || !preg_match('/^[a-f0-9\-]{36}$/', $conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    if (!function_exists('grgw_cc_get_conversation_any') || !grgw_cc_get_conversation_any($conversation_id)) {
        return grgw_cc_hitl_json_error('grgw_cc_bad_conversation', 'conversation_id non valido.', [ 'conversation_id' => $conversation_id ]);
    }

    grgw_cc_hitl_outbox_clear($conversation_id);
    return rest_ensure_response([
        'ok' => true,
        'conversation_id' => $conversation_id,
        'cleared' => true,
    ]);
}

function grgw_cc_hitl_rest_updates(WP_REST_Request $request) {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    $conversation_id = (string) $request['id'];
    $after = (int) $request->get_param('after');
    if ($after < 0) $after = 0;

	$user_id = (int) get_current_user_id();
	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && function_exists('grgw_cc_public_enabled') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	if (function_exists('grgw_cc_get_conversation_scoped')) {
		$conv = grgw_cc_get_conversation_scoped($conversation_id, $user_id, $thread_prefix);
	} elseif (function_exists('grgw_cc_get_conversation')) {
		$conv = grgw_cc_get_conversation($conversation_id, $user_id);
	} else {
		if ($thread_prefix) {
			$conv = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
				$conversation_id,
				$user_id,
				$thread_prefix . '%'
			), ARRAY_A);
	    	} else {
	    	    if ($thread_prefix) {
	    	        $conv = $wpdb->get_row($wpdb->prepare(
	    	            "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
	    	            $conversation_id,
	    	            $user_id,
	    	            $thread_prefix . '%'
	    	        ), ARRAY_A);
	    	    } else {
	    	        $conv = $wpdb->get_row($wpdb->prepare(
	    	            "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d",
	    	            $conversation_id,
	    	            $user_id
	    	        ), ARRAY_A);
	    	    }
	    	}
	}

    if (!$conv) {
        return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
    }

    // timeout HITL
    grgw_cc_hitl_maybe_expire($conversation_id);

    // refresh conv after expiry check
	if ($thread_prefix) {
		$conv = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
			$conversation_id,
			$user_id,
			$thread_prefix . '%'
		), ARRAY_A);
	} else {
		$conv = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d",
			$conversation_id,
			$user_id
		), ARRAY_A);
	}

    $updated_at = (string) ($conv['updated_at'] ?? '');
    $last_message_id = 0;

    if (function_exists('grgw_cc_get_last_message_id')) {
        $last_message_id = (int) grgw_cc_get_last_message_id($conversation_id);
    } else {
        $last_message_id = (int) ($wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM {$tables['messages']} WHERE conversation_id = %s",
            $conversation_id
        )) ?: 0);
    }

    $etag = grgw_cc_hitl_compute_etag($conversation_id, $last_message_id, $updated_at);
    $if_none_match = (string) $request->get_header('If-None-Match');

    // Preferenza: alcuni client (es. n8n) NON gestiscono bene 304/empty-body con Content-Type: application/json.
    // Il frontend del plugin invia X-GRGW-PREFER-304: 1 e gestisce 304 correttamente.
    // Se la preferenza NON è presente, rispondiamo sempre con JSON 200 (not_modified=true) invece di 304.
    $prefer_304 = (string) $request->get_header('X-GRGW-PREFER-304');
    $prefer_304 = in_array(strtolower(trim($prefer_304)), ['1','true','yes','on'], true);

    // Se non ci sono nuovi messaggi rispetto a after, e l'ETag combacia
    if ($last_message_id <= $after && $if_none_match !== '' && trim($if_none_match) === $etag) {
        if ($prefer_304) {
            $res = new WP_REST_Response(null, 304);
            $res->header('ETag', $etag);
            return $res;
        }

        $payload = [
            'ok' => true,
            'not_modified' => true,
            'messages' => [],
            'conversation' => [
                'id' => (string) $conversation_id,
                'hitl' => [
                    'active' => (int) ($conv['hitl_active'] ?? 0) === 1,
                    'hitl_id' => (string) ($conv['hitl_id'] ?? ''),
                    'started_at' => (string) ($conv['hitl_started_at'] ?? ''),
                    'expires_at' => (string) ($conv['hitl_expires_at'] ?? ''),
                ],
            ],
        ];

        $res = rest_ensure_response($payload);
        $res->header('ETag', $etag);
        return $res;
    }

    // Nessun messaggio nuovo (anche senza If-None-Match): ritorna sempre JSON per evitare body vuoti.
    if ($last_message_id <= $after) {
        $payload = [
            'ok' => true,
            'not_modified' => true,
            'messages' => [],
            'conversation' => [
                'id' => (string) $conversation_id,
                'hitl' => [
                    'active' => (int) ($conv['hitl_active'] ?? 0) === 1,
                    'hitl_id' => (string) ($conv['hitl_id'] ?? ''),
                    'started_at' => (string) ($conv['hitl_started_at'] ?? ''),
                    'expires_at' => (string) ($conv['hitl_expires_at'] ?? ''),
                ],
            ],
        ];
        $res = rest_ensure_response($payload);
        $res->header('ETag', $etag);
        return $res;
    }

    // Prendi nuovi messaggi
    if (function_exists('grgw_cc_get_messages_after')) {
        $rows = grgw_cc_get_messages_after($conversation_id, $after, 200);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tables['messages']} WHERE conversation_id = %s AND id > %d ORDER BY id ASC LIMIT 200",
            $conversation_id,
            $after
        ), ARRAY_A);
    }

    // Normalizza meta
    for ($i = 0; $i < count($rows); $i++) {
        $rows[$i]['id'] = (int) $rows[$i]['id'];
        $rows[$i]['meta'] = $rows[$i]['meta'] ? json_decode((string)$rows[$i]['meta'], true) : null;
    }

    $payload = [
        'ok' => true,
        'not_modified' => false,
        'messages' => $rows,
        'conversation' => [
            'id' => (string) $conversation_id,
            'hitl' => [
                'active' => (int) ($conv['hitl_active'] ?? 0) === 1,
                'hitl_id' => (string) ($conv['hitl_id'] ?? ''),
                'started_at' => (string) ($conv['hitl_started_at'] ?? ''),
                'expires_at' => (string) ($conv['hitl_expires_at'] ?? ''),
            ],
        ],
    ];

    $res = rest_ensure_response($payload);
    $res->header('ETag', $etag);
    return $res;
}

function grgw_cc_hitl_parse_submit_input(WP_REST_Request $request) : array {
    $content_type = (string) $request->get_header('content-type');

    $out = [
        'submit_id' => '',
        'hitl_id' => '',
        'type' => 'text',
        'button_id' => '',
        'text' => '',
        'attachments' => [],
    ];

    if (stripos($content_type, 'multipart/form-data') !== false) {
        $out['submit_id'] = (string) $request->get_param('submit_id');
        $out['hitl_id'] = (string) $request->get_param('hitl_id');
        $out['type'] = (string) $request->get_param('type');
        $out['button_id'] = (string) $request->get_param('button_id');
        $out['text'] = (string) $request->get_param('text');

        if (function_exists('grgw_cc_handle_single_upload')) {
            $out['attachments'] = grgw_cc_handle_single_upload($request);
        }

        return $out;
    }

    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];

    $out['submit_id'] = (string) ($params['submit_id'] ?? '');
    $out['hitl_id'] = (string) ($params['hitl_id'] ?? '');

    $ui = $params['user_input'] ?? null;
    if (is_array($ui)) {
        $out['type'] = (string) ($ui['type'] ?? 'text');
        $out['button_id'] = (string) ($ui['button_id'] ?? '');
        $out['text'] = (string) ($ui['text'] ?? '');
        $out['attachments'] = is_array($ui['attachments'] ?? null) ? (array) $ui['attachments'] : [];
    } else {
        $out['type'] = (string) ($params['type'] ?? 'text');
        $out['button_id'] = (string) ($params['button_id'] ?? '');
        $out['text'] = (string) ($params['text'] ?? '');
        $out['attachments'] = is_array($params['attachments'] ?? null) ? (array) $params['attachments'] : [];
    }

    return $out;
}

function grgw_cc_hitl_rest_submit(WP_REST_Request $request) {
    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    $conversation_id = (string) $request['id'];
    $user_id = (int) get_current_user_id();

    // Lock per evitare race condition (doppie submit / doppio timeout / concorrenza).
    $lock_ctx = null;
    if ($conversation_id && function_exists('grgw_cc_hitl_acquire_lock')) {
        $lock_ctx = grgw_cc_hitl_acquire_lock($conversation_id, 2);
    }

    try {
        // Public scoping: separa conversazioni guest via thread_id prefix.
        $thread_prefix = null;
        if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
            $thread_prefix = grgw_cc_public_thread_prefix();
        }

        if (function_exists('grgw_cc_get_conversation_scoped')) {
            $conv = grgw_cc_get_conversation_scoped($conversation_id, $user_id, $thread_prefix);
        } elseif (function_exists('grgw_cc_get_conversation')) {
            $conv = grgw_cc_get_conversation($conversation_id, $user_id);
        } else {
            if ($thread_prefix) {
                $conv = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
                    $conversation_id,
                    $user_id,
                    $thread_prefix . '%'
                ), ARRAY_A);
            } else {
                $conv = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d",
                    $conversation_id,
                    $user_id
                ), ARRAY_A);
            }
        }

    if (!$conv) {
        return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
    }

    // timeout HITL
    grgw_cc_hitl_maybe_expire($conversation_id);

    // refresh conv
    if (function_exists('grgw_cc_get_conversation_scoped')) {
        $conv = grgw_cc_get_conversation_scoped($conversation_id, $user_id, $thread_prefix);
    } else {
        if ($thread_prefix) {
            $conv = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
                $conversation_id,
                $user_id,
                $thread_prefix . '%'
            ), ARRAY_A);
        } else {
            $conv = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d",
                $conversation_id,
                $user_id
            ), ARRAY_A);
        }
    }

    if ((int) ($conv['hitl_active'] ?? 0) !== 1) {
        return new WP_Error('grgw_cc_hitl_inactive', 'HITL non attiva per questa conversazione.', ['status' => 409]);
    }

    $input = grgw_cc_hitl_parse_submit_input($request);

    $hitl_id = sanitize_text_field((string) ($input['hitl_id'] ?? ''));
    $conv_hitl_id = sanitize_text_field((string) ($conv['hitl_id'] ?? ''));

    if ($hitl_id === '') {
        $hitl_id = $conv_hitl_id;
    }

    if ($conv_hitl_id !== '' && $hitl_id !== '' && $hitl_id !== $conv_hitl_id) {
        return new WP_Error('grgw_cc_hitl_id_mismatch', 'hitl_id non corrisponde alla conversazione.', ['status' => 409]);
    }

    $submit_id = sanitize_text_field((string) ($input['submit_id'] ?? ''));
    if ($submit_id === '') {
        $submit_id = wp_generate_uuid4();
    }

    // Idempotenza
    if ($submit_id !== '' && $hitl_id !== '') {
        $cached = grgw_cc_hitl_idem_get($conversation_id, $hitl_id, $submit_id);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }
    }

    $type = sanitize_text_field((string) ($input['type'] ?? 'text'));
    if ($type === '') $type = 'text';

    $button_id = sanitize_text_field((string) ($input['button_id'] ?? ''));
    $text = trim((string) ($input['text'] ?? ''));
    $attachments = is_array($input['attachments'] ?? null) ? (array) $input['attachments'] : [];

    // Contenuto messaggio user da salvare in WP
    $user_content = $text;
    if ($user_content === '' && $button_id !== '') {
        $user_content = '[HITL] ' . $button_id;
    }
    if ($user_content === '') {
        $user_content = '[HITL] input';
    }

    $meta = [
        'hitl' => true,
        'hitl_id' => $hitl_id,
        'type' => 'hitl_submit',
        'submit_id' => $submit_id,
        'user_input' => [
            'type' => $type,
            'button_id' => $button_id,
            'text' => $text,
            'attachments' => $attachments,
        ],
    ];

    // ---------------------------------------------------------------------
    // Trasporto HITL
    // pull: WP salva in outbox e NON chiama n8n (n8n...)
    // webhook: WP fa POST al resume URL (legacy)
    // ---------------------------------------------------------------------

    $transport = get_option('grgw_cc_hitl_transport', 'pull');
    $transport = is_string($transport) ? strtolower(trim($transport)) : 'pull';
    if (!in_array($transport, ['pull', 'webhook'], true)) {
        $transport = 'pull';
    }

    // In pull-mode accettiamo 1 solo messaggio in attesa per conversazione
    if ($transport === 'pull' && grgw_cc_hitl_outbox_has($conversation_id)) {
        return new WP_Error('grgw_cc_outbox_full', 'Hai già una risposta in coda. Attendi che n8n la legga.', ['status' => 409]);
    }

    $saved_message_id = grgw_cc_hitl_insert_message($conversation_id, 'user', $user_content, $meta);
    grgw_cc_hitl_touch_conversation($conversation_id);

    $hitl_payload = [
        'hitl' => true,
        'hitl_id' => $hitl_id,
        'submit_id' => $submit_id,
        'conversation_id' => (string) $conversation_id,
        'thread_id' => (string) ($conv['thread_id'] ?? ''),
        'polling' => [
            'enabled' => true,
            'interval_ms' => grgw_cc_hitl_poll_interval_ms(),
            'timeout_sec' => grgw_cc_hitl_timeout_sec(),
        ],
        'user_input' => [
            'type' => $type,
            'button_id' => $button_id,
            'text' => $text,
            'attachments' => $attachments,
        ],
    ];

    $queued = false;
    $forwarded = false;
    $forward_http = 0;
    $forward_error = '';

    if ($transport === 'pull') {
        $webhook_like = [
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
                'x-grgw-secret-present' => true,
            ],
            'params' => new stdClass(),
            'query' => new stdClass(),
            'body' => $hitl_payload,
            'meta' => [
                'source' => 'grgw_cc_outbox',
                'queued_at' => gmdate('c'),
                'saved_message_id' => (int) $saved_message_id,
            ],
        ];

        $queued = grgw_cc_hitl_outbox_put($conversation_id, $webhook_like);
        if (!$queued) {
            // Best effort rollback: elimino il messaggio appena inserito per non lasciare sporco.
            global $wpdb;
            $tables = grgw_cc_hitl_tables();
            $wpdb->delete($tables['messages'], ['id' => (int) $saved_message_id], ['%d']);
            return new WP_Error('grgw_cc_outbox_put_failed', 'Impossibile mettere in coda la risposta. Riprova.', ['status' => 500]);
        }
    } else {
        // Forward a n8n (se configurato)
        $resume_url = grgw_cc_hitl_resume_url();
        $secret = grgw_cc_hitl_get_secret();

        if ($resume_url !== '' && $secret !== '') {
            $args = [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'X-GRGW-SECRET' => $secret,
                ],
                'timeout' => 20,
                'body' => wp_json_encode($hitl_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];

            $res = wp_remote_post($resume_url, $args);

            if (is_wp_error($res)) {
                $forward_error = $res->get_error_message();
            } else {
                $forward_http = (int) wp_remote_retrieve_response_code($res);
                $forwarded = $forward_http >= 200 && $forward_http < 300;
                if (!$forwarded) {
                    $forward_error = (string) wp_remote_retrieve_body($res);
                    $forward_error = substr(trim($forward_error), 0, 800);
                }
            }
        } else {
            if ($resume_url === '') $forward_error = 'resume_url_not_configured';
            if ($secret === '') $forward_error = 'secret_not_configured';
        }
    }

    $response = [
        'ok' => true,
        'conversation_id' => (string) $conversation_id,
        'hitl_id' => $hitl_id,
        'submit_id' => $submit_id,
        'saved_message_id' => (int) $saved_message_id,
        'transport' => $transport,
        'queued' => (bool) $queued,
        'forwarded' => (bool) $forwarded,
        'forward_http' => (int) $forward_http,
        'forward_error' => (string) $forward_error,
        'outbox' => [
            'pop_url' => rest_url('grgrowth/v1/cc/conversations/' . $conversation_id . '/outbox/pop'),
            'peek_url' => rest_url('grgrowth/v1/cc/conversations/' . $conversation_id . '/outbox/peek'),
            'clear_url' => rest_url('grgrowth/v1/cc/conversations/' . $conversation_id . '/outbox/clear'),
        ],
    ];

    if ($submit_id !== '' && $hitl_id !== '') {
        grgw_cc_hitl_idem_put($conversation_id, $hitl_id, $submit_id, $response);
    }

    return rest_ensure_response($response);
    } finally {
        if ($lock_ctx && function_exists('grgw_cc_hitl_release_lock')) {
            grgw_cc_hitl_release_lock($lock_ctx);
        }
    }
}

// -----------------------------------------------------------------------------
// Hook per aggiornare lo stato HITL quando n8n risponde al messaggio "normale"
// -----------------------------------------------------------------------------

function grgw_cc_hitl_apply_from_n8n_extra(string $conversation_id, int $user_id, $extra) : void {
    if (!is_array($extra)) return;

    $hitl = $extra['hitl'] ?? null;
    $polling = $extra['polling'] ?? null;

    $active = null;
    $hitl_id = '';

    if (is_array($hitl) && array_key_exists('active', $hitl)) {
        $active = (bool) $hitl['active'];
        $hitl_id = sanitize_text_field((string) ($hitl['hitl_id'] ?? ($hitl['id'] ?? '')));
    }

    if ($active === null && is_array($polling) && array_key_exists('enabled', $polling)) {
        if ((bool) $polling['enabled'] === false) {
            $active = false;
        }
    }

    if ($active === null) return;

    global $wpdb;
    $tables = grgw_cc_hitl_tables();

    if ($active) {
        if ($hitl_id === '') {
            $hitl_id = 'hitl_' . substr(wp_generate_password(16, false, false), 0, 16);
        }

        $started_at = grgw_cc_hitl_now_mysql();
        $expires_at = grgw_cc_hitl_ts_to_mysql(grgw_cc_hitl_now_ts() + grgw_cc_hitl_timeout_sec());

        $wpdb->update(
            $tables['conversations'],
            [
                'hitl_active' => 1,
                'hitl_id' => $hitl_id,
                'hitl_started_at' => $started_at,
                'hitl_expires_at' => $expires_at,
                'updated_at' => grgw_cc_hitl_now_mysql(),
            ],
            ['id' => $conversation_id, 'user_id' => $user_id],
            ['%d','%s','%s','%s','%s'],
            ['%s','%d']
        );
    } else {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['conversations']} SET hitl_active = 0, hitl_id = NULL, hitl_started_at = NULL, hitl_expires_at = NULL, updated_at = %s WHERE id = %s AND user_id = %d",
            grgw_cc_hitl_now_mysql(),
            $conversation_id,
            $user_id
        ));
    }
}
