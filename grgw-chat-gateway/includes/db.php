<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Tabelle WP (con prefisso).
 */
function grgw_cc_tables() : array {
	global $wpdb;
	return [
		'conversations'    => $wpdb->prefix . 'grgw_cc_conversations',
		'messages'         => $wpdb->prefix . 'grgw_cc_messages',
		'hitl_idempotency' => $wpdb->prefix . 'grgw_cc_hitl_idempotency',
		'hitl_outbox'      => $wpdb->prefix . 'grgw_cc_hitl_outbox',
	];
}

// Public mode helpers

function grgw_cc_public_enabled() : bool {
    return (bool) get_option('grgw_cc_public_enabled', false);
}

function grgw_cc_get_secret() : string {
    if (defined('GRGW_N8N_SECRET') && GRGW_N8N_SECRET) {
        return (string) GRGW_N8N_SECRET;
    }
    return (string) get_option('grgw_cc_secret', '');
}

function grgw_cc_public_get_or_create_key() : string {
    static $cached = null;
    if (is_string($cached) && $cached !== '') return $cached;

    $k = isset($_COOKIE['grgw_cc_pubk']) ? (string) $_COOKIE['grgw_cc_pubk'] : '';
    $k = preg_replace('/[^a-z0-9]/i', '', $k);
    if (strlen($k) < 16) {
        $k = substr(wp_generate_password(32, false, false), 0, 24);

        $secure = is_ssl();
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        $path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
        @setcookie('grgw_cc_pubk', $k, time() + 31536000, $path, $domain, $secure, true);
        $_COOKIE['grgw_cc_pubk'] = $k;
    }

    $cached = $k;
    return $k;
}

function grgw_cc_public_thread_prefix() : string {
    return 'pub-' . grgw_cc_public_get_or_create_key() . '-';
}

/**
 * Ritorna prefix thread_id se siamo guest in modalità pubblica; altrimenti null.
 */
function grgw_cc_current_thread_prefix(int $user_id) : ?string {
    $public_enabled = (bool) get_option('grgw_cc_public_enabled', 0);
    if ($user_id === 0 && $public_enabled) {
        return grgw_cc_public_thread_prefix();
    }
    return null;
}

/**
 * Versione schema DB (incrementare quando cambia lo schema).
 */
function grgw_cc_db_target_version() : string {
	return '1.2.0';
}

/**
 * Upgrade schema se necessario.
 * Nota: viene chiamato a runtime dal file principale.
 */
function grgw_cc_maybe_upgrade_db() : void {
	$current = (string) get_option('grgw_cc_db_version', '0.0.0');
	$target  = grgw_cc_db_target_version();
	if (version_compare($current, $target, '<')) {
		grgw_cc_install();
	}
}

/**
 * Installazione / upgrade.
 * Usa dbDelta: crea tabelle e aggiunge colonne/indici mancanti.
 */
function grgw_cc_install() : void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$tables = grgw_cc_tables();
	$charset_collate = $wpdb->get_charset_collate();

	$sql_conversations = "CREATE TABLE {$tables['conversations']} (
		id char(36) NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		title varchar(255) NOT NULL DEFAULT 'Nuova chat',
		is_title_custom tinyint(1) NOT NULL DEFAULT 0,
		provider varchar(50) NOT NULL DEFAULT 'google',
		model varchar(100) NOT NULL DEFAULT 'gemini-2.0-flash',
		thread_id varchar(120) NOT NULL,

		-- HITL state
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

	$sql_hitl_idem = "CREATE TABLE {$tables['hitl_idempotency']} (
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
	$sql_hitl_outbox = "CREATE TABLE {$tables['hitl_outbox']} (
		conversation_id char(36) NOT NULL,
		payload_json longtext NOT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (conversation_id),
		KEY updated_at (updated_at)
	) $charset_collate;";

	dbDelta($sql_conversations);
	dbDelta($sql_messages);
	dbDelta($sql_hitl_idem);
	dbDelta($sql_hitl_outbox);

	update_option('grgw_cc_db_version', grgw_cc_db_target_version());
}

/**
 * Options helper.
 */
function grgw_cc_get_option(string $key, $default = null) {
	$val = get_option($key, null);
	if ($val === null || $val === '') return $default;
	return $val;
}

/**
 * Fetch conversation by id for current user.
 */
function grgw_cc_get_conversation(string $id, int $user_id) : ?array {
	global $wpdb;
	$tables = grgw_cc_tables();
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d",
		$id, $user_id
	), ARRAY_A);

	return $row ? $row : null;
}

/**
 * Fetch conversation by id con scope aggiuntivo (es. modalità pubblica).
 * Se $thread_prefix è valorizzato, richiede thread_id LIKE "{$thread_prefix}%".
 */
function grgw_cc_get_conversation_scoped(string $id, int $user_id, ?string $thread_prefix = null) : ?array {
    global $wpdb;
    $tables = grgw_cc_tables();

    if ($thread_prefix) {
        $like = $wpdb->esc_like($thread_prefix) . '%';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tables['conversations']} WHERE id = %s AND user_id = %d AND thread_id LIKE %s",
            $id, $user_id, $like
        ), ARRAY_A);
        return $row ? $row : null;
    }

    return grgw_cc_get_conversation($id, $user_id);
}

/**
 * Fetch conversation by id (senza check user) - solo per server-to-server.
 */
function grgw_cc_get_conversation_any(string $id) : ?array {
	global $wpdb;
	$tables = grgw_cc_tables();
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT * FROM {$tables['conversations']} WHERE id = %s",
		$id
	), ARRAY_A);

	return $row ? $row : null;
}

/**
 * Touch updated_at (user scoped).
 */
function grgw_cc_touch_conversation(string $id, int $user_id) : void {
	global $wpdb;
	$tables = grgw_cc_tables();
	$wpdb->update(
		$tables['conversations'],
		['updated_at' => current_time('mysql')],
		['id' => $id, 'user_id' => $user_id],
		['%s'],
		['%s','%d']
	);
}

/**
 * Touch updated_at (no user check).
 */
function grgw_cc_touch_conversation_any(string $id) : void {
	global $wpdb;
	$tables = grgw_cc_tables();
	$wpdb->update(
		$tables['conversations'],
		['updated_at' => current_time('mysql')],
		['id' => $id],
		['%s'],
		['%s']
	);
}

/**
 * Set HITL state (no user check).
 */
function grgw_cc_set_hitl_state_any(string $conversation_id, bool $active, ?string $hitl_id = null, ?string $started_at = null, ?string $expires_at = null) : void {
	global $wpdb;
	$tables = grgw_cc_tables();

	if (!$active) {
		$wpdb->query($wpdb->prepare(
			"UPDATE {$tables['conversations']}
			 SET hitl_active = 0, hitl_id = NULL, hitl_started_at = NULL, hitl_expires_at = NULL, updated_at = %s
			 WHERE id = %s",
			current_time('mysql'),
			$conversation_id
		));
		return;
	}

	$data = [
		'hitl_active' => 1,
		'updated_at'  => current_time('mysql'),
	];
	$formats = ['%d','%s'];

	if ($hitl_id !== null) { $data['hitl_id'] = $hitl_id; $formats[] = '%s'; }
	if ($started_at !== null) { $data['hitl_started_at'] = $started_at; $formats[] = '%s'; }
	if ($expires_at !== null) { $data['hitl_expires_at'] = $expires_at; $formats[] = '%s'; }

	$wpdb->update(
		$tables['conversations'],
		$data,
		['id' => $conversation_id],
		$formats,
		['%s']
	);
}

/**
 * Insert message.
 */
function grgw_cc_insert_message(string $conversation_id, string $role, string $content, $meta = null) : int {
	global $wpdb;
	$tables = grgw_cc_tables();

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
			'created_at' => current_time('mysql'),
		],
		['%s','%s','%s','%s','%s']
	);

	return (int) $wpdb->insert_id;
}

/**
 * Get last N messages for conversation (ordered asc).
 */
function grgw_cc_get_messages(string $conversation_id, int $limit = 200) : array {
	global $wpdb;
	$tables = grgw_cc_tables();

	$limit = max(1, min(500, $limit));
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$tables['messages']}
		 WHERE conversation_id = %s
		 ORDER BY id DESC
		 LIMIT %d",
		$conversation_id, $limit
	), ARRAY_A);

	$rows = array_reverse($rows);

	foreach ($rows as &$r) {
		$r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
	}

	return $rows;
}

/**
 * Get messages after id (ordered asc).
 */
function grgw_cc_get_messages_after(string $conversation_id, int $after_id = 0, int $limit = 200) : array {
	global $wpdb;
	$tables = grgw_cc_tables();

	$after_id = max(0, (int) $after_id);
	$limit = max(1, min(500, $limit));

	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$tables['messages']}
		 WHERE conversation_id = %s AND id > %d
		 ORDER BY id ASC
		 LIMIT %d",
		$conversation_id, $after_id, $limit
	), ARRAY_A);

	foreach ($rows as &$r) {
		$r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : null;
	}

	return $rows;
}

/**
 * Last message id (0 se vuota).
 */
function grgw_cc_get_last_message_id(string $conversation_id) : int {
	global $wpdb;
	$tables = grgw_cc_tables();
	$last = $wpdb->get_var($wpdb->prepare(
		"SELECT MAX(id) FROM {$tables['messages']} WHERE conversation_id = %s",
		$conversation_id
	));
	return (int) ($last ?: 0);
}

/**
 * HITL Idempotency: get stored response for submit_id.
 */
function grgw_cc_hitl_idem_get(string $conversation_id, string $hitl_id, string $submit_id) : ?array {
	global $wpdb;
	$tables = grgw_cc_tables();

	$hitl_id = sanitize_text_field($hitl_id);
	$submit_id = sanitize_text_field($submit_id);
	if ($hitl_id === '' || $submit_id === '') return null;

	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT response_json FROM {$tables['hitl_idempotency']}
		 WHERE conversation_id = %s AND hitl_id = %s AND submit_id = %s",
		$conversation_id, $hitl_id, $submit_id
	), ARRAY_A);

	if (!$row) return null;

	$decoded = json_decode((string)$row['response_json'], true);
	return is_array($decoded) ? $decoded : null;
}

/**
 * HITL Idempotency: store response for submit_id.
 */
function grgw_cc_hitl_idem_put(string $conversation_id, string $hitl_id, string $submit_id, array $response) : void {
	global $wpdb;
	$tables = grgw_cc_tables();

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
		current_time('mysql')
	));
}
