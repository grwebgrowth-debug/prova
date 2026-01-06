<?php
if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function() {

	register_rest_route('grgrowth/v1', '/cc/models', [
		'methods' => 'GET',
		'callback' => 'grgw_cc_rest_get_models',
		'permission_callback' => 'grgw_cc_rest_can_use',
	]);

		register_rest_route('grgrowth/v1', '/cc/debug', [
			'methods' => 'GET',
			'callback' => 'grgw_cc_rest_debug',
			'permission_callback' => 'grgw_cc_rest_can_use',
		]);

	register_rest_route('grgrowth/v1', '/cc/conversations', [
		[
			'methods' => 'GET',
			'callback' => 'grgw_cc_rest_list_conversations',
			'permission_callback' => 'grgw_cc_rest_can_use',
			'args' => [
				'offset' => ['type' => 'integer', 'required' => false],
				'limit' => ['type' => 'integer', 'required' => false],
			],
		],
		[
			'methods' => 'POST',
			'callback' => 'grgw_cc_rest_create_conversation',
			'permission_callback' => 'grgw_cc_rest_can_use',
		],
	]);

	register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})', [
		[
			'methods' => 'GET',
			'callback' => 'grgw_cc_rest_get_conversation',
			'permission_callback' => 'grgw_cc_rest_can_use',
			'args' => [
				'limit' => ['type' => 'integer', 'required' => false],
			],
		],
		[
			'methods' => 'PATCH',
			'callback' => 'grgw_cc_rest_update_conversation',
			'permission_callback' => 'grgw_cc_rest_can_use',
		],
		[
			'methods' => 'DELETE',
			'callback' => 'grgw_cc_rest_delete_conversation',
			'permission_callback' => 'grgw_cc_rest_can_use',
		],
	]);

	register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/message', [
		'methods' => 'POST',
		'callback' => 'grgw_cc_rest_send_message',
		'permission_callback' => 'grgw_cc_rest_can_use',
	]);

});

/**
 * Permission check: logged-in + nonce ok.
 */
function grgw_cc_rest_can_use(WP_REST_Request $request) {
	$public_enabled = (bool) get_option('grgw_cc_public_enabled', 0);

	// --- Modalità pubblica (debug): consenti anche senza login, ma solo se arriva il token.
	if (!is_user_logged_in()) {
		if ($public_enabled) {
			$expected = (string) get_option('grgw_cc_public_token', '');
			// Se il token non esiste (opzione vuota), lo rigeneriamo per evitare blocchi
			// “misteriosi” in modalità pubblica.
			if ($expected === '' && function_exists('grgw_cc_ensure_public_token')) {
				$expected = (string) grgw_cc_ensure_public_token();
			}
			$provided = (string) $request->get_header('x-grgw-public-token');
			if ($expected && $provided && hash_equals($expected, $provided)) {
				// Se siamo in pubblico: assicura un namespace "device" stabile (cookie) per separare conversazioni.
				if (function_exists('grgw_cc_public_get_or_create_key')) {
					grgw_cc_public_get_or_create_key();
				}
				return true;
			}
		}
		return new WP_Error('grgw_cc_not_logged', 'Non autorizzato (login richiesto).', ['status' => 401]);
	}

	if (!current_user_can('read')) {
		return new WP_Error('grgw_cc_forbidden', 'Non hai i permessi per usare la chat.', ['status' => 403]);
	}

	$nonce = $request->get_header('X-WP-Nonce');
	if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) {
		return new WP_Error('grgw_cc_bad_nonce', 'Nonce non valido. Ricarica la pagina.', ['status' => 403]);
	}
	return true;
}


function grgw_cc_rest_get_models(WP_REST_Request $request) {
	// La funzione è definita nel file principale.
	if (function_exists('grgw_cc_default_providers_models')) {
		return rest_ensure_response([
			'ok' => true,
			'providers' => grgw_cc_default_providers_models(),
		]);
	}
	return rest_ensure_response(['ok' => true, 'providers' => []]);
}

function grgw_cc_rest_list_conversations(WP_REST_Request $request) {
	global $wpdb;
	$tables = grgw_cc_tables();
	$user_id = get_current_user_id();

	$offset = max(0, (int) $request->get_param('offset'));
	$limit = (int) $request->get_param('limit');
	$limit = $limit ? max(1, min(50, $limit)) : 50;

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}

	if ($thread_prefix) {
		$like = $wpdb->esc_like($thread_prefix) . '%';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, title, is_title_custom, provider, model, thread_id, created_at, updated_at
			 FROM {$tables['conversations']}
			 WHERE user_id = %d AND thread_id LIKE %s
			 ORDER BY updated_at DESC
			 LIMIT %d OFFSET %d",
			$user_id, $like, $limit, $offset
		), ARRAY_A);
	} else {
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, title, is_title_custom, provider, model, thread_id, created_at, updated_at
			 FROM {$tables['conversations']}
			 WHERE user_id = %d
			 ORDER BY updated_at DESC
			 LIMIT %d OFFSET %d",
			$user_id, $limit, $offset
		), ARRAY_A);
	}

	return rest_ensure_response([
		'ok' => true,
		'conversations' => $rows,
		'offset' => $offset,
		'limit' => $limit,
		'count' => count($rows),
	]);
}

function grgw_cc_rest_create_conversation(WP_REST_Request $request) {
	global $wpdb;
	$tables = grgw_cc_tables();
	$user_id = get_current_user_id();

	$provider = sanitize_text_field((string) $request->get_param('provider'));
	$model = sanitize_text_field((string) $request->get_param('model'));

	if (!$provider) $provider = 'google';
	if (!$model) $model = 'gemini-2.0-flash';

	$conversation_id = wp_generate_uuid4();
	$thread_id = 'wp-' . $user_id . '-' . $conversation_id;
	// In pubblico: separiamo conversazioni per "device" via prefix in thread_id.
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$prefix = grgw_cc_public_thread_prefix();
		if ($prefix) {
			$thread_id = $prefix . $conversation_id;
		}
	}

	$now = current_time('mysql');

	$wpdb->insert(
		$tables['conversations'],
		[
			'id' => $conversation_id,
			'user_id' => $user_id,
			'title' => 'Nuova chat',
			'is_title_custom' => 0,
			'provider' => $provider,
			'model' => $model,
			'thread_id' => $thread_id,
			'created_at' => $now,
			'updated_at' => $now,
		],
		['%s','%d','%s','%d','%s','%s','%s','%s','%s']
	);

	return rest_ensure_response([
		'ok' => true,
		'conversation' => [
			'id' => $conversation_id,
			'title' => 'Nuova chat',
			'is_title_custom' => 0,
			'provider' => $provider,
			'model' => $model,
			'thread_id' => $thread_id,
			'created_at' => $now,
			'updated_at' => $now,
		]
	]);
}

function grgw_cc_rest_get_conversation(WP_REST_Request $request) {
	$user_id = get_current_user_id();
	$id = (string) $request['id'];

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	$conv = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id);
	if (!$conv) {
		return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
	}

	$limit = (int) $request->get_param('limit');
	$limit = $limit ? $limit : 200;
	$messages = grgw_cc_get_messages($id, $limit);

	return rest_ensure_response([
		'ok' => true,
		'conversation' => $conv,
		'messages' => $messages,
	]);
}

function grgw_cc_rest_update_conversation(WP_REST_Request $request) {
	global $wpdb;
	$tables = grgw_cc_tables();
	$user_id = get_current_user_id();
	$id = (string) $request['id'];

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	$conv = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id);
	if (!$conv) {
		return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
	}

	$params = $request->get_json_params();
	if (!is_array($params)) $params = [];

	$update = [];
	$formats = [];

	if (isset($params['title'])) {
		$title = trim((string)$params['title']);
		$title = wp_strip_all_tags($title);
		$title = preg_replace('/\s+/', ' ', $title);
		if ($title === '') $title = 'Nuova chat';
		$update['title'] = $title;
		$formats[] = '%s';

		// Se rinomini manualmente, diventa custom.
		$update['is_title_custom'] = 1;
		$formats[] = '%d';
	}

	if (isset($params['provider'])) {
		$update['provider'] = sanitize_text_field((string)$params['provider']);
		$formats[] = '%s';
	}
	if (isset($params['model'])) {
		$update['model'] = sanitize_text_field((string)$params['model']);
		$formats[] = '%s';
	}

	if (empty($update)) {
		return rest_ensure_response(['ok' => true, 'conversation' => $conv]);
	}

	$update['updated_at'] = current_time('mysql');
	$formats[] = '%s';

	$wpdb->update(
		$tables['conversations'],
		$update,
		['id' => $id, 'user_id' => $user_id],
		$formats,
		['%s','%d']
	);

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	$conv2 = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id);
	return rest_ensure_response(['ok' => true, 'conversation' => $conv2]);
}

function grgw_cc_rest_delete_conversation(WP_REST_Request $request) {
	global $wpdb;
	$tables = grgw_cc_tables();
	$user_id = get_current_user_id();
	$id = (string) $request['id'];

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	$conv = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id);
	if (!$conv) {
		return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
	}

	$wpdb->delete($tables['messages'], ['conversation_id' => $id], ['%s']);
	$wpdb->delete($tables['conversations'], ['id' => $id, 'user_id' => $user_id], ['%s','%d']);

	return rest_ensure_response(['ok' => true]);
}

function grgw_cc_allowed_mimes() : array {
	return [
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
	];
}


/**
 * Rate limiting (lato WordPress): evita che uno si faccia venire idee strane
 * e ti bruci quota/modelli/n8n a forza di refresh o spam.
 *
 * - Limite/minuto: 0–20 (0 = disabilitato)
 * - Limite/giorno: 0–1000 (0 = disabilitato)
 *
 * Implementazione: transients, chiave per utente (o IP se non loggato).
 */
function grgw_cc_rl_limits() : array {
	$per_min = (int) get_option('grgw_cc_rate_per_minute', 20);
	$per_day = (int) get_option('grgw_cc_rate_per_day', 1000);

	// clamp hard (per sicurezza anche se qualcuno “manipola” l’option).
	if ($per_min < 0) $per_min = 0;
	if ($per_min > 20) $per_min = 20;

	if ($per_day < 0) $per_day = 0;
	if ($per_day > 1000) $per_day = 1000;

	return [$per_min, $per_day];
}

function grgw_cc_rl_key(int $user_id) : string {
	// Se in futuro permetti chat pubbliche, qui puoi passare a IP+UA o session id.
	if ($user_id > 0) return 'u' . $user_id;

	$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
	return 'ip' . md5($ip . '|' . $ua);
}

/**
 * Ritorna:
 * - null se ok
 * - WP_Error con status 429 se bloccato
 */
function grgw_cc_rl_enforce(int $user_id) {
	list($per_min, $per_day) = grgw_cc_rl_limits();
	if ($per_min === 0 && $per_day === 0) return null; // tutto disabilitato

	$now_dt = function_exists('current_datetime') ? current_datetime() : new DateTime('now', wp_timezone());
	$now = (int) $now_dt->getTimestamp();
	$today = $now_dt->format('Y-m-d');
	$tomorrow_dt = clone $now_dt;
	$tomorrow_dt->modify('tomorrow')->setTime(0,0,0);
	$tomorrow = (int) $tomorrow_dt->getTimestamp();

	$key = grgw_cc_rl_key($user_id);

	// --- Per minuto
	if ($per_min > 0) {
		$k = 'grgw_cc_rl_m_' . $key;
		$st = get_transient($k);
		if (!is_array($st) || empty($st['start']) || ($now - (int)$st['start']) >= 60) {
			$st = ['start' => $now, 'count' => 0];
		}
		$cnt = (int) ($st['count'] ?? 0);

		if ($cnt >= $per_min) {
			$retry = max(1, 60 - ($now - (int)$st['start']));
			$msg = "Rate limit superato: max {$per_min}/min. Riprova tra {$retry}s.";
			return new WP_Error('grgw_cc_rate_limited', $msg, [
				'status' => 429,
				'retry_after' => $retry,
				'limit_per_minute' => $per_min,
				'limit_per_day' => $per_day,
			]);
		}

		$st['count'] = $cnt + 1;
		set_transient($k, $st, 60);
	}

	// --- Per giorno
	if ($per_day > 0) {
		$k = 'grgw_cc_rl_d_' . $key;
		$st = get_transient($k);
		if (!is_array($st) || empty($st['date']) || $st['date'] !== $today) {
			$st = ['date' => $today, 'count' => 0];
		}

		$cnt = (int) ($st['count'] ?? 0);

		if ($cnt >= $per_day) {
			$retry = max(60, $tomorrow - $now);
			$msg = "Limite giornaliero superato: max {$per_day}/giorno. Riprova domani.";
			return new WP_Error('grgw_cc_rate_limited', $msg, [
				'status' => 429,
				'retry_after' => $retry,
				'limit_per_minute' => $per_min,
				'limit_per_day' => $per_day,
			]);
		}

		$st['count'] = $cnt + 1;
		$expires = max(60, $tomorrow - $now);
		set_transient($k, $st, $expires);
	}

	return null;
}


function grgw_cc_handle_single_upload(WP_REST_Request $request) : array {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$files = $request->get_file_params();
	if (!$files || empty($files['files'])) return [];

	$f = $files['files'];

	// Se arriva come array (files[]), prendiamo il primo
	if (is_array($f) && isset($f['name']) && is_array($f['name'])) {
		$f = [
			'name' => $f['name'][0] ?? '',
			'type' => $f['type'][0] ?? '',
			'tmp_name' => $f['tmp_name'][0] ?? '',
			'error' => $f['error'][0] ?? 0,
			'size' => $f['size'][0] ?? 0,
		];
	}

	if (!isset($f['tmp_name']) || empty($f['tmp_name'])) return [];

	// Limiti
	$max_mb = (int) get_option('grgw_cc_max_upload_mb', 20);
	$max_bytes = $max_mb * 1024 * 1024;

	$size = (int) ($f['size'] ?? 0);
	if ($size > $max_bytes) {
		throw new Exception("File troppo grande: max {$max_mb}MB.");
	}

	$allowed_mimes = grgw_cc_allowed_mimes();
	$overrides = [
		'test_form' => false,
		'mimes' => $allowed_mimes,
	];

	$upload = wp_handle_upload($f, $overrides);

	if (isset($upload['error'])) {
		throw new Exception('Upload fallito: ' . $upload['error']);
	}

	$url = (string) ($upload['url'] ?? '');
	$type = (string) ($upload['type'] ?? '');
	$path = (string) ($upload['file'] ?? '');

	$name = basename($path);
	if (isset($f['name']) && $f['name']) {
		$name = sanitize_file_name((string)$f['name']);
	}

	return [[
		'name' => $name,
		'mime' => $type,
		'size' => $size,
		'url'  => $url,
	]];
}

/**
 * Webhook URL: se non configurato nelle opzioni, usiamo SEMPRE quello fisso.
 * Così non ti blocchi mai con l'errore "Webhook URL non configurato".
 */
function grgw_cc_get_webhook_url() : string {
	$val = get_option('grgw_cc_webhook_url');
	$val = is_string($val) ? trim($val) : '';
	// Nessun default hardcoded: se non è configurato, restituiamo stringa vuota.
	// Così evitiamo che un sito “nuovo” mandi dati al dominio di un altro cliente.
	if ($val === '') return '';
	return esc_url_raw($val);
}

/**
 * Verifica se una coppia provider/model è:
 * 1) “conosciuta” (presente in grgw_cc_default_providers_models)
 * 2) (opzionale) abilitata nella whitelist grgw_cc_models_enabled
 */
function grgw_cc_is_provider_model_allowed(string $provider, string $model) : bool {
	$provider = sanitize_key($provider);
	$model = trim((string) $model);
	if ($provider === '' || $model === '') return false;

	$known = [];
	foreach ((array) grgw_cc_default_providers_models() as $p) {
		$prov = '';
		if (isset($p['key'])) {
			$prov = (string) $p['key'];
		} elseif (isset($p['provider'])) {
			$prov = (string) $p['provider'];
		}
		$prov = sanitize_key($prov);
		if (!$prov) continue;
		$models = isset($p['models']) && is_array($p['models']) ? $p['models'] : [];
		foreach ($models as $m) {
			$id = '';
			if (isset($m['value'])) {
				$id = (string) $m['value'];
			} elseif (isset($m['id'])) {
				$id = (string) $m['id'];
			}
			$id = trim($id);
			if (!$id) continue;
			$known[$prov.'|'.$id] = true;
		}
	}

	$key = $provider.'|'.$model;
	if (!isset($known[$key])) return false;

	$enabled = get_option('grgw_cc_models_enabled', []);
	if (!is_array($enabled) || empty($enabled)) {
		// Nessuna whitelist: tutto ciò che è “known” è ok.
		return true;
	}

	// Whitelist presente: deve esserci.
	$enabled = array_filter(array_map('strval', $enabled));
	return in_array($key, $enabled, true);
}

function grgw_cc_safe_autotitle(string $text) : string {
	$text = trim($text);
	$text = wp_strip_all_tags($text);
	$text = preg_replace('/\s+/', ' ', $text);
	if ($text === '') return 'Nuova chat';

	if (function_exists('mb_substr')) {
		$t = mb_substr($text, 0, 40, 'UTF-8');
	} else {
		$t = substr($text, 0, 40);
	}
	return $t;
}

function grgw_cc_get_secret() : string {
	if (defined('GRGW_N8N_SECRET') && GRGW_N8N_SECRET) {
		return (string) GRGW_N8N_SECRET;
	}
	return (string) get_option('grgw_cc_secret', '');
}

function grgw_cc_rest_send_message(WP_REST_Request $request) {
	global $wpdb;
	$tables = grgw_cc_tables();
	$user_id = get_current_user_id();
	$id = (string) $request['id'];

	$thread_prefix = null;
	if ($user_id === 0 && function_exists('grgw_cc_public_thread_prefix') && grgw_cc_public_enabled()) {
		$thread_prefix = grgw_cc_public_thread_prefix();
	}
	$conv = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id);
	if (!$conv) {
		return new WP_Error('grgw_cc_not_found', 'Conversazione non trovata.', ['status' => 404]);
	}

	$provider = '';
	$model = '';
	$text = '';
	$user_attachments = [];

	try {
		$content_type = (string) $request->get_header('content-type');

		// multipart/form-data
		if (stripos($content_type, 'multipart/form-data') !== false) {
			$text = (string) $request->get_param('text');
			$provider = (string) $request->get_param('provider');
			$model = (string) $request->get_param('model');
			$user_attachments = grgw_cc_handle_single_upload($request);
		} else {
			// JSON
			$params = $request->get_json_params();
			if (!is_array($params)) $params = [];
			$text = (string) ($params['text'] ?? '');
			$provider = (string) ($params['provider'] ?? '');
			$model = (string) ($params['model'] ?? '');
			$user_attachments = [];
		}
	} catch (Exception $e) {
		return new WP_Error('grgw_cc_upload_error', $e->getMessage(), ['status' => 400]);
	}

	$text = trim((string)$text);
	$provider = sanitize_text_field($provider ?: $conv['provider']);
	$model = sanitize_text_field($model ?: $conv['model']);

	// Rate limit (prima di salvare e prima di chiamare n8n)
	$rl = grgw_cc_rl_enforce((int)$user_id);
	if (is_wp_error($rl)) {
		return $rl;
	}

	// Salva messaggio user
	$user_message_id = grgw_cc_insert_message($id, 'user', $text, ['attachments' => $user_attachments]);

	// IMPORTANTE: niente auto-title.
	// Il titolo deve rimanere "Nuova chat" finché l'utente non lo rinomina manualmente.
	$wpdb->update(
		$tables['conversations'],
		[
			'provider' => $provider,
			'model' => $model,
			'updated_at' => current_time('mysql'),
		],
		['id' => $id, 'user_id' => $user_id],
		['%s','%s','%s'],
		['%s','%d']
	);

	$conv = function_exists('grgw_cc_get_conversation_scoped')
		? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
		: grgw_cc_get_conversation($id, $user_id); // refresh (per titolo)

	// Validazione: provider/model devono esistere (e, se impostata, rispettare la whitelist)
	if (!function_exists('grgw_cc_default_providers_models') || !grgw_cc_is_provider_model_allowed((string)$provider, (string)$model)) {
		grgw_cc_insert_message($id, 'assistant', 'Configurazione AI non valida (provider/model). Controlla le impostazioni del plugin.', [
			'type' => 'error',
			'provider' => (string)$provider,
			'model' => (string)$model,
		]);
		return new WP_Error('grgw_cc_invalid_model', 'Provider/Model non valido o non abilitato.', ['status' => 400]);
	}

	// Config n8n
	$webhook_url = grgw_cc_get_webhook_url();
	$secret = grgw_cc_get_secret();
	$timeout_opt = (int) get_option('grgw_cc_timeout', 1000);
	$timeout = $timeout_opt <= 0 ? 20000 : max(1, min(20000, $timeout_opt));

	if (!$webhook_url) {
		grgw_cc_insert_message($id, 'assistant', 'Webhook n8n non configurato. Vai in Impostazioni → GRGW Chat Gateway e inserisci l’URL del webhook.', [
			'type' => 'error',
		]);
		return new WP_Error('grgw_cc_no_webhook_url', 'Webhook URL non configurato.', ['status' => 500]);
	}
	if (!$secret) {
		grgw_cc_insert_message($id, 'assistant', 'Secret non configurato. Vai in Impostazioni → GRGW Chat Gateway e inserisci il secret.', [
			'type' => 'error',
		]);
		return new WP_Error('grgw_cc_no_secret', 'Secret non configurato. Vai in Impostazioni → GRGW Chat Gateway.', ['status' => 500]);
	}

	$user = wp_get_current_user();

	$payload = [
		'client_id' => 'wp_' . $user_id,
		'message' => [
			'text' => $text,
			'attachments' => $user_attachments,
		],
		'session' => [
			'thread_id' => (string) $conv['thread_id'],
		],
		'ai' => [
			'provider' => $provider,
			'model' => $model,
		],
		'context' => [
			'conversation_id' => (string) $conv['id'],
			'conversation_title' => (string) $conv['title'],
			'wp' => [
				'user_id' => (int) $user_id,
				'user_login' => (string) $user->user_login,
				'display_name' => (string) $user->display_name,
				'site' => (string) get_site_url(),
			],
		],
		'polling' => [
			'enabled' => (int) ($conv['hitl_active'] ?? 0) === 1,
			'interval_ms' => function_exists('grgw_cc_hitl_poll_interval_ms') ? grgw_cc_hitl_poll_interval_ms() : 1000,
			'timeout_sec' => function_exists('grgw_cc_hitl_timeout_sec') ? grgw_cc_hitl_timeout_sec() : 3600,
		],
	];

	$args = [
		'headers' => [
			'Content-Type' => 'application/json; charset=utf-8',
			'X-GRGW-SECRET' => $secret,
		],
		'timeout' => $timeout,
		'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
	];

	$res = wp_remote_post($webhook_url, $args);

	if (is_wp_error($res)) {
		$err = $res->get_error_message();
		grgw_cc_insert_message($id, 'assistant', 'Errore: n8n non risponde. (' . $err . ')', ['state' => 'error']);
		return new WP_Error('grgw_cc_n8n_error', 'n8n non risponde: ' . $err, ['status' => 502]);
	}

	$code = (int) wp_remote_retrieve_response_code($res);
	$body = (string) wp_remote_retrieve_body($res);
	$body_trim = trim($body);

	// n8n can legitimately respond with an empty body (e.g. when the workflow
	// answers via server-side push/polling). In that case we treat it as "no immediate reply".
	if ($body_trim === '') {
		$data = [];
	} else {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			grgw_cc_insert_message($id, 'assistant', 'Errore: risposta non valida da n8n.', ['state' => 'error', 'http_code' => $code]);
			return new WP_Error('grgw_cc_bad_n8n_json', 'Risposta non valida da n8n (JSON atteso).', ['status' => 502, 'http_code' => $code]);
		}
	}

	$reply = (string) ($data['reply'] ?? '');
	$assistant_attachments = $data['attachments'] ?? [];
	$extra = $data['extra'] ?? null;

	if ($reply === '' && isset($data['text'])) {
		// fallback (in caso n8n ritorni "text" invece di "reply")
		$reply = (string) $data['text'];
	}

	// Evita di salvare un messaggio “vuoto” quando n8n risponde 200 ma senza body.
	$should_store_assistant = (
		trim($reply) !== '' ||
		(is_array($assistant_attachments) && count($assistant_attachments) > 0) ||
		($extra !== null)
	);
	$assistant_message_id = 0;
	if ($should_store_assistant) {
		$assistant_message_id = grgw_cc_insert_message($id, 'assistant', $reply, [
			'attachments' => is_array($assistant_attachments) ? $assistant_attachments : [],
			'extra' => $extra,
			'http_code' => $code,
		]);
	}

	// HITL state (se n8n dichiara active=true/false in extra)
	if (function_exists('grgw_cc_hitl_apply_from_n8n_extra')) {
		grgw_cc_hitl_apply_from_n8n_extra($id, (int) $user_id, $extra);
		$conv = function_exists('grgw_cc_get_conversation_scoped')
			? grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix)
			: grgw_cc_get_conversation($id, $user_id); // refresh
	}

	// touch
	grgw_cc_touch_conversation($id, $user_id);

	return rest_ensure_response([
		'ok' => true,
		'reply' => $reply,
		'attachments' => is_array($assistant_attachments) ? $assistant_attachments : [],
		'user_attachments' => $user_attachments,
		'extra' => $extra,
		'message_ids' => [
			'user' => (int) $user_message_id,
			'assistant' => (int) $assistant_message_id,
		],
		'conversation' => [
			'id' => (string) $conv['id'],
			'title' => (string) $conv['title'],
			'is_title_custom' => (int) $conv['is_title_custom'],
			'provider' => (string) $conv['provider'],
			'model' => (string) $conv['model'],
			'thread_id' => (string) $conv['thread_id'],
			'created_at' => (string) $conv['created_at'],
			'updated_at' => (string) $conv['updated_at'],
		'hitl_active' => (int) ($conv['hitl_active'] ?? 0),
		'hitl_id' => (string) ($conv['hitl_id'] ?? ''),
		'hitl_started_at' => (string) ($conv['hitl_started_at'] ?? ''),
		'hitl_expires_at' => (string) ($conv['hitl_expires_at'] ?? ''),
		],
	]);
}

/**
 * Debug endpoint (solo info non sensibili / mascherate).
 * GET /wp-json/grgrowth/v1/cc/debug
 */
function grgw_cc_mask_secret_for_debug($s) {
	$s = (string) $s;
	if ($s === '') return '';
	$len = strlen($s);
	if ($len <= 8) return str_repeat('*', $len);
	return substr($s, 0, 6) . '…' . substr($s, -4) . ' (len ' . $len . ')';
}

function grgw_cc_rest_debug($request) {
	if (!current_user_can('manage_options')) {
		return new WP_Error('grgw_cc_forbidden', 'Debug endpoint is admin-only', ['status' => 403]);
	}
	$public_enabled = ((int) get_option('grgw_cc_public_enabled', 0) === 1);
	$public_token = (string) get_option('grgw_cc_public_token', '');
	$secret = (string) get_option('grgw_cc_secret', '');
	$webhook_url = (string) get_option('grgw_cc_webhook_url', '');
	$hitl_resume_url = (string) get_option('grgw_cc_hitl_resume_url', '');
	$max_upload_mb = (int) get_option('grgw_cc_max_upload_mb', 10);
	$max_upload_bytes = max(0, $max_upload_mb) * 1024 * 1024;

	$payload = [
		'ok' => true,
		'plugin' => [
			'name' => 'GRGW Chat Gateway',
			'version' => defined('GRGW_CC_VERSION') ? GRGW_CC_VERSION : 'unknown',
		],
		'wordpress' => [
			'version' => get_bloginfo('version'),
			'site_url' => get_site_url(),
			'home_url' => home_url(),
			'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
		],
		'php' => [
			'version' => PHP_VERSION,
		],
		'rest' => [
			'base' => rest_url('grgrowth/v1'),
		],
		'public' => [
			'enabled' => $public_enabled,
			'token_set' => ($public_token !== ''),
			'token' => ($public_token !== '') ? (substr($public_token, 0, 6) . '…' . substr($public_token, -4) . ' (len ' . strlen($public_token) . ')') : '',
		],
		'n8n' => [
			'webhook_url' => $webhook_url,
			'hitl_resume_url' => $hitl_resume_url,
		],
		'hitl' => [
			'poll_interval_ms' => function_exists('grgw_cc_hitl_poll_interval_ms') ? grgw_cc_hitl_poll_interval_ms() : 1000,
			'timeout_sec' => function_exists('grgw_cc_hitl_timeout_sec') ? grgw_cc_hitl_timeout_sec() : 3600,
		],
		'limits' => [
			'max_upload_mb' => $max_upload_mb,
			'max_upload_bytes' => $max_upload_bytes,
		],
		'debug' => [
			'enabled' => ((int) get_option('grgw_cc_debug_enabled', 0) === 1),
		],
		'user' => [
			'id' => get_current_user_id(),
			'logged_in' => is_user_logged_in(),
			'can_manage_options' => current_user_can('manage_options'),
		],
		'time' => [
			'server_local' => current_time('c'),
			'server_utc' => gmdate('c'),
		],
		'secrets' => [
			'secret' => grgw_cc_mask_secret_for_debug($secret),
		],
	];

	return rest_ensure_response($payload);
}
