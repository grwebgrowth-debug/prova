<?php
/**
 * Plugin Name: GRGW Chat Gateway
 * Description: Chat stile ChatGPT in WordPress che fa da gateway sicuro verso n8n (persistenza conversazioni + scelta provider/modello + allegati).
 * Version: 1.0.0-alpha
 * Author: GR Growth
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

define('GRGW_CC_VERSION', '1.0.0-alpha');
define('GRGW_CC_PLUGIN_FILE', __FILE__);
define('GRGW_CC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GRGW_CC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GRGW_CC_PLUGIN_DIR . 'includes/db.php';
require_once GRGW_CC_PLUGIN_DIR . 'includes/hitl.php';
require_once GRGW_CC_PLUGIN_DIR . 'includes/rest.php';
require_once GRGW_CC_PLUGIN_DIR . 'includes/admin.php';

// Upgrade DB schema a runtime (oltre che in activation hook).
add_action('plugins_loaded', function(){
	if (function_exists('grgw_cc_maybe_upgrade_db')) {
		grgw_cc_maybe_upgrade_db();
	}
});

// Nasconde admin bar sulla pagina chat configurata
add_action('wp', function(){
	$chat_page_url = trim((string) get_option('grgw_cc_chat_page_url', ''));
	if ($chat_page_url === '') return;

	$current_url = '';
	if (isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI'])) {
		$scheme = is_ssl() ? 'https' : 'http';
		$current_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	// Rimuovi parametri query per il confronto
	$chat_page_clean = strtok($chat_page_url, '?');
	$current_clean = strtok($current_url, '?');

	if ($current_clean === $chat_page_clean || rtrim($current_clean, '/') === rtrim($chat_page_clean, '/')) {
		show_admin_bar(false);
		// Rimuovi anche il margine top aggiunto dall'admin bar
		add_action('wp_head', function(){
			echo '<style>html { margin-top: 0 !important; }</style>';
		}, 999);
	}
});

/**
 * Garantisce che, se la modalità pubblica è attiva, esista un public token valido.
 * In alcuni setup (cache/rollback/import opzioni) può risultare vuoto: in quel caso
 * lo rigeneriamo e lo salviamo, così la REST non va in 401 per i guest.
 */
function grgw_cc_ensure_public_token() {
  $enabled = (bool) get_option('grgw_cc_public_enabled', false);
  if (!$enabled) return '';

  $tok = (string) get_option('grgw_cc_public_token', '');
  if ($tok !== '') return $tok;

  // Token deterministico (non "random a ogni page load") per evitare disallineamenti.
  $tok = hash('sha256', wp_salt('auth') . 'grgw_cc_public');
  update_option('grgw_cc_public_token', $tok);
  return $tok;
}

/**
 * Default provider/models list (ordine come richiesto).
 * Nota: puoi filtrare via PHP con `grgw_cc_providers_models`.
 */
function grgw_cc_default_providers_models() : array {
	$providers = [
		[
			'key' => 'google',
			'label' => 'Google (Gemini)',
			'models' => [
				['value' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash-Lite'],
				['value' => 'gemini-2.0-flash',      'label' => 'Gemini 2.0 Flash'],
				['value' => 'gemini-2.5-flash-lite', 'label' => 'Gemini 2.5 Flash-Lite'],
				['value' => 'gemini-2.5-flash',      'label' => 'Gemini 2.5 Flash'],
				['value' => 'gemini-2.5-pro',        'label' => 'Gemini 2.5 Pro'],
				['value' => 'gemini-3-pro-preview',  'label' => 'Anteprima di Gemini 3 Pro'],
			],
		],
		[
			'key' => 'openai',
			'label' => 'ChatGPT (OpenAI)',
			'models' => [
				['value' => 'gpt-5-nano',  'label' => 'gpt-5-nano'],
				['value' => 'gpt-5.2',     'label' => 'gpt-5.2'],
				['value' => 'gpt-5',       'label' => 'gpt-5'],
				['value' => 'gpt-5.2-pro', 'label' => 'gpt-5.2-pro'],
				['value' => 'gpt-4o',      'label' => 'gpt-4o'],
				['value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini'],
			],
		],
		[
			'key' => 'anthropic',
			'label' => 'Claude (Anthropic)',
			'models' => [
				['value' => 'claude-haiku-4.5',  'label' => 'Haiku 4.5'],
				['value' => 'claude-sonnet-4.5', 'label' => 'Sonnet 4.5'],
				['value' => 'claude-opus-4.5',   'label' => 'Opus 4.5'],
			],
		],
	];

	return apply_filters('grgw_cc_providers_models', $providers);
}

/**
 * Shortcode: [grgw_chat]
 */

/**
 * URL pagina chat (per redirect dopo login e link rapidi).
 *
 * Priorità:
 * 1) Impostazione plugin (grgw_cc_chat_page_url)
 * 2) Permalink della pagina/post corrente (se disponibile)
 * 3) URL corrente ricostruito da REQUEST_URI
 */
function grgw_cc_get_chat_page_url() : string {
	$opt = trim((string) get_option('grgw_cc_chat_page_url', ''));
	if ($opt !== '') {
		return esc_url_raw($opt);
	}

	// 2) Permalink della pagina corrente (se siamo in un contesto con post).
	global $post;
	if (isset($post) && is_object($post) && !empty($post->ID)) {
		$perma = get_permalink((int) $post->ID);
		if (is_string($perma) && $perma !== '') {
			return esc_url_raw($perma);
		}
	}

	// 3) Fallback: ricostruisci l'URL dalla request.
	$host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
	$uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
	if ($host !== '' && $uri !== '') {
		$scheme = is_ssl() ? 'https' : 'http';
		return esc_url_raw($scheme . '://' . $host . $uri);
	}

	return esc_url_raw(home_url('/'));
}
function grgw_cc_shortcode($atts = []) : string {
	$public_enabled = (bool) get_option('grgw_cc_public_enabled', 0);

	// La chat è una UI dinamica: meglio evitare cache aggressiva sulla pagina.
	if (!headers_sent()) {
		nocache_headers();
	}

	// Se non sei loggato (e non siamo in modalità pubblica), mostra la schermata login
	// e imposta il redirect post-login alla pagina chat configurata.
	if (!$public_enabled && !is_user_logged_in()) {
		$atts = shortcode_atts([
			'fullpage' => '0',
		], (array)$atts, 'grgw_chat');
		$fullpage = in_array(strtolower((string)$atts['fullpage']), ['1','true','yes','on'], true);

		$redirect = grgw_cc_get_chat_page_url();
		$login_url = wp_login_url($redirect);
		$lost_url  = wp_lostpassword_url($redirect);

		// Solo stile (niente JS) per rendere la schermata coerente.
		wp_enqueue_style('grgw-cc-style', GRGW_CC_PLUGIN_URL . 'assets/css/style.css', [], GRGW_CC_VERSION);

		$form = '';
		if (function_exists('wp_login_form')) {
			$form = wp_login_form([
				'echo' => false,
				'redirect' => $redirect,
				'form_id' => 'grgw-cc-loginform',
				'label_username' => 'Username o email',
				'label_password' => 'Password',
				'label_remember' => 'Ricordami',
				'label_log_in' => 'Accedi',
				'remember' => true,
			]);
		}

		ob_start();
		?>
		<div class="grgw-cc-login-wrap <?php echo $fullpage ? 'grgw-cc-login-fullpage' : ''; ?>">
			<div class="grgw-cc-login-box">
				<div class="grgw-cc-login-title">Accesso richiesto</div>
				<div class="grgw-cc-login-sub">Per usare la chat devi autenticarti. Dopo il login torni qui automaticamente.</div>

				<?php if (!empty($form)) : ?>
					<div class="grgw-cc-login-form">
						<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php else : ?>
					<p><a class="grgw-cc-login-btn" href="<?php echo esc_url($login_url); ?>">Vai al login</a></p>
				<?php endif; ?>

				<div class="grgw-cc-login-links">
					<a href="<?php echo esc_url($login_url); ?>">Apri pagina login</a>
					<span aria-hidden="true">•</span>
					<a href="<?php echo esc_url($lost_url); ?>">Password dimenticata?</a>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// Se la chat è abilitata per guest, garantiamo un token non vuoto (serve anche al REST).
	$public_token = $public_enabled ? grgw_cc_ensure_public_token() : '';

	$atts = shortcode_atts([
		// Se 1/true/on -> wrapper a schermo intero (utile con Elementor quando vuoi la pagina "solo chat").
		'fullpage' => '0',
	], (array)$atts, 'grgw_chat');
	$fullpage = in_array(strtolower((string)$atts['fullpage']), ['1','true','yes','on'], true);

	// Enqueue assets solo dove serve.
	wp_enqueue_style('grgw-cc-style', GRGW_CC_PLUGIN_URL . 'assets/css/style.css', [], GRGW_CC_VERSION);
	wp_enqueue_script('grgw-cc-app', GRGW_CC_PLUGIN_URL . 'assets/js/app.js', [], GRGW_CC_VERSION, true);

	$user = wp_get_current_user();
	$providers_all = grgw_cc_default_providers_models();

	// Filtra i modelli mostrati in UX (se impostati in backend).
	$allowed_models = get_option('grgw_cc_models_enabled', []);
	$providers = $providers_all;
	if (is_array($allowed_models) && !empty($allowed_models)) {
		$allowed_set = [];
		foreach ($allowed_models as $k) {
			$k = (string) $k;
			if ($k !== '') $allowed_set[$k] = true;
		}

		$filtered = [];
		foreach ($providers_all as $p) {
			if (!is_array($p) || empty($p['key']) || empty($p['models']) || !is_array($p['models'])) continue;
			$pk = (string) $p['key'];
			$models = [];
			foreach ($p['models'] as $m) {
				$mv = is_array($m) && isset($m['value']) ? (string)$m['value'] : '';
				if ($mv === '') continue;
				$compound = $pk . '|' . $mv;
				if (isset($allowed_set[$compound])) $models[] = $m;
			}
			if (!empty($models)) {
				$p['models'] = $models;
				$filtered[] = $p;
			}
		}

		// Se l'utente ha selezionato "niente" (o configurazione incoerente), non rompiamo la UX.
		if (!empty($filtered)) {
			$providers = $filtered;
		}
	}

	// Prepara lista webhooks per il frontend
	$webhooks = get_option('grgw_cc_webhooks', []);
	if (!is_array($webhooks)) $webhooks = [];
	$webhook_list = [];
	foreach ($webhooks as $idx => $wh) {
		if (!empty($wh['url'])) {
			$webhook_list[] = [
				'index' => $idx,
				'label' => isset($wh['label']) ? $wh['label'] : 'Webhook ' . $idx,
			];
		}
	}
	$active_webhook = (int) get_option('grgw_cc_webhook_active', 0);

	$settings = [
		'restBase' => esc_url_raw(rest_url('grgrowth/v1')),
		'nonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
		'user' => [
			'id' => (int) $user->ID,
			'login' => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'site' => (string) get_site_url(),
		],
		'ui' => [
			'limitConversations' => 50,
			'maxFiles' => 1,
			'brandName' => (string) get_option('grgw_cc_brand_name', 'GRGW Chat Gateway'),
			'brandLogoUrl' => (string) get_option('grgw_cc_brand_logo_url', ''),
			'helpUrl' => (string) get_option('grgw_cc_help_url', ''),
			'chatPageUrl' => (string) grgw_cc_get_chat_page_url(),
		],
		'public' => [
			'enabled' => $public_enabled,
			'token' => $public_token,
		],
		'webhooks' => [
			'list' => $webhook_list,
			'active' => $active_webhook,
		],
		'hitl' => [
			'poll_interval_ms' => function_exists('grgw_cc_hitl_poll_interval_ms') ? grgw_cc_hitl_poll_interval_ms() : 1000,
			'timeout_sec' => function_exists('grgw_cc_hitl_timeout_sec') ? grgw_cc_hitl_timeout_sec() : 3600,
		],
		'debug' => [
			'enabled' => (bool) ((int) get_option('grgw_cc_debug_enabled', 0) === 1 && current_user_can('manage_options')),
			'is_admin' => (bool) current_user_can('manage_options'),
			'plugin_version' => defined('GRGW_CC_VERSION') ? GRGW_CC_VERSION : '',
		],
		'providers' => $providers,
	];

	wp_localize_script('grgw-cc-app', 'GRGW_CC', $settings);

	ob_start();
	?>
	<div id="grgw-cc-root" class="grgw-cc-root <?php echo $fullpage ? 'grgw-cc-fullpage' : ''; ?>" aria-label="GRGW Chat"></div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode('grgw_chat', 'grgw_cc_shortcode');

/**
 * Install tables on activation.
 */
register_activation_hook(__FILE__, 'grgw_cc_install');

/**
 * Tiny helper: plugin info in Settings list (comodo).
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
	$settings_url = admin_url('options-general.php?page=grgw-chat-gateway');
	$links[] = '<a href="'.esc_url($settings_url).'">Impostazioni</a>';
	return $links;
});