<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Sanitize: lista modelli abilitati in UX.
 * Formato: array di stringhe "provider|model".
 * Se la lista rimane vuota, in frontend si interpreta come "mostra tutto".
 */
function grgw_cc_sanitize_models_enabled($value) {
	$items = is_array($value) ? $value : [];
	$out = [];

	// Build allowed set from defaults.
	// Supporta sia vecchio formato (provider/id) sia nuovo (key/value).
	$allowed = [];
	if (function_exists('grgw_cc_default_providers_models')) {
		foreach ((array) grgw_cc_default_providers_models() as $p) {
			$prov = '';
			if (isset($p['key'])) {
				$prov = (string) $p['key'];
			} elseif (isset($p['provider'])) {
				$prov = (string) $p['provider']; // backward-compat
			}
			$prov = sanitize_key($prov);
			if (!$prov) continue;
			$models = isset($p['models']) && is_array($p['models']) ? $p['models'] : [];
			foreach ($models as $m) {
				$id = '';
				if (isset($m['value'])) {
					$id = (string) $m['value'];
				} elseif (isset($m['id'])) {
					$id = (string) $m['id']; // backward-compat
				}
				$id = trim($id);
				if (!$id) continue;
				$allowed[$prov . '|' . $id] = true;
			}
		}
	}

	foreach ($items as $it) {
		$s = trim((string) $it);
		if ($s === '') continue;
		if (!empty($allowed) && !isset($allowed[$s])) continue;
		$out[] = $s;
	}

	// unique + stable order
	$out = array_values(array_unique($out));
	return $out;
}

add_action('admin_menu', function() {
	add_options_page(
		'GRGW Chat Gateway',
		'GRGW Chat Gateway',
		'manage_options',
		'grgw-chat-gateway',
		'grgw_cc_render_settings_page'
	);

	// Pagine utility (archivio conversazioni + dettaglio).
	add_submenu_page(
		'options-general.php',
		'Archivio Chat — GRGW',
		'Archivio Chat (GRGW)',
		'manage_options',
		'grgw-chat-gateway-archive',
		'grgw_cc_render_archive_page'
	);
	// Pagina "nascosta" (niente voce menu): dettaglio conversazione.
	add_submenu_page(
		null,
		'Conversazione — GRGW',
		'Conversazione — GRGW',
		'manage_options',
		'grgw-chat-gateway-conversation',
		'grgw_cc_render_conversation_page'
	);
});

add_action('admin_init', function() {
	// Legacy single webhook (manteniamo per retrocompatibilità)
	register_setting('grgw_cc_settings', 'grgw_cc_webhook_url', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return esc_url_raw(trim((string)$v)); },
		'default' => '',
	]);

	// Nuovi webhook multipli
	register_setting('grgw_cc_settings', 'grgw_cc_webhooks', [
		'type' => 'array',
		'sanitize_callback' => function($v){
			if (!is_array($v)) return [];
			$out = [];
			foreach ($v as $item) {
				if (!is_array($item)) continue;
				$url = isset($item['url']) ? esc_url_raw(trim((string)$item['url'])) : '';
				$desc = isset($item['desc']) ? sanitize_text_field((string)$item['desc']) : '';
				$label = isset($item['label']) ? sanitize_text_field((string)$item['label']) : '';
				if ($url === '') continue;
				$out[] = ['url' => $url, 'desc' => $desc, 'label' => $label];
			}
			return $out;
		},
		'default' => [],
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_webhook_active', [
		'type' => 'integer',
		'sanitize_callback' => function($v){ return max(0, (int)$v); },
		'default' => 0,
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_secret', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return trim((string)$v); },
		'default' => '',
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_timeout', [
		'type' => 'integer',
		// Range richiesto: 0–20000.
		// Nota: 0 = "illimitato" (lato request verrà tradotto in 20000).
		'sanitize_callback' => function($v){
			$iv = (int) $v;
			if ($iv <= 0) return 0;
			return max(1, min(20000, $iv));
		},
		'default' => 1000,
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_rate_per_minute', [
	'type' => 'integer',
	// Rate limit locale (anti-danni): max 20 richieste/minuto.
	// 0 = disabilitato.
	'sanitize_callback' => function($v){
		$iv = (int) $v;
		if ($iv <= 0) return 0;
		return max(1, min(20, $iv));
	},
	'default' => 20,
]);

	register_setting('grgw_cc_settings', 'grgw_cc_rate_per_day', [
	'type' => 'integer',
	// Rate limit giornaliero: max 1000 richieste/giorno.
	// 0 = disabilitato.
	'sanitize_callback' => function($v){
		$iv = (int) $v;
		if ($iv <= 0) return 0;
		return max(1, min(1000, $iv));
	},
	'default' => 1000,
]);

	register_setting('grgw_cc_settings', 'grgw_cc_brand_name', [
		'type' => 'string',
		'sanitize_callback' => function($v){
			$v = wp_strip_all_tags((string)$v);
			$v = preg_replace('/\s+/', ' ', trim($v));
			return $v;
		},
		'default' => 'GRGW Chat Gateway',
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_brand_logo_url', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return esc_url_raw(trim((string)$v)); },
		'default' => '',
	]);

	// Link per icona "?" in UI (istruzioni / documentazione cliente).
	register_setting('grgw_cc_settings', 'grgw_cc_help_url', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return esc_url_raw(trim((string)$v)); },
		'default' => '',
	]);

	// Scenario A (manuale): URL della pagina/punto dove è inserito lo shortcode.
	register_setting('grgw_cc_settings', 'grgw_cc_chat_page_url', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return esc_url_raw(trim((string)$v)); },
		'default' => '',
	]);

	// Selezione modelli visibili in UX. Formato: array di stringhe "provider|model".
	register_setting('grgw_cc_settings', 'grgw_cc_models_enabled', [
		'type' => 'array',
		'sanitize_callback' => function($v){
			$in = is_array($v) ? $v : [];
			$out = [];
			$allowed = [];
			if (function_exists('grgw_cc_default_providers_models')) {
				$providers = grgw_cc_default_providers_models();
				foreach ($providers as $p) {
					$pk = isset($p['key']) ? (string)$p['key'] : '';
					if ($pk === '' || empty($p['models']) || !is_array($p['models'])) continue;
					foreach ($p['models'] as $m) {
						$mv = (is_array($m) && isset($m['value'])) ? (string)$m['value'] : '';
						if ($mv === '') continue;
						$allowed[$pk . '|' . $mv] = true;
					}
				}
			}

			foreach ($in as $k) {
				$k = trim((string)$k);
				if ($k === '') continue;
				if (!empty($allowed) && !isset($allowed[$k])) continue;
				$out[] = $k;
			}
			$out = array_values(array_unique($out));
			return $out;
		},
		'default' => [],
	]);

	// "Password" (solo barriera UX) per attivare modalità pubblica nella pagina impostazioni.
	register_setting('grgw_cc_settings', 'grgw_cc_public_guard_password', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return trim((string)$v); },
		'default' => 'manut_post_grgw,',
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_max_upload_mb', [
		'type' => 'integer',
		'sanitize_callback' => function($v){ return max(1, min(1024, (int)$v)); },
		'default' => 20,
	]);
		register_setting('grgw_cc_settings', 'grgw_cc_debug_enabled', [
			'type' => 'boolean',
			'sanitize_callback' => function($v){ return ($v ? 1 : 0); },
			'default' => 0,
		]);


	register_setting('grgw_cc_settings', 'grgw_cc_public_enabled', [
		'type' => 'boolean',
		'sanitize_callback' => function($v){
			$enabled = ($v ? 1 : 0);
			if ($enabled && !get_option('grgw_cc_public_token')){
				update_option('grgw_cc_public_token', wp_generate_password(32, false, false));
			}
			return $enabled;
		},
		'default' => 0,
	]);

	register_setting('grgw_cc_settings', 'grgw_cc_public_token', [
		'type' => 'string',
		'sanitize_callback' => function($v){ return trim((string)$v); },
		'default' => '',
	]);

	add_settings_section(
		'grgw_cc_main',
		'Impostazioni principali',
		function(){
			echo '<p>Qui configuri il gateway WordPress → n8n. Il browser <strong>non</strong> vedrà mai il secret: lo usa solo il backend.</p>';
		},
		'grgw-chat-gateway'
	);

	add_settings_section(
		'grgw_cc_ai',
		'AI — modelli visibili in UX',
		function(){
			echo '<p>Se selezioni uno o piu modelli, nella UI della chat l\'utente potra scegliere <strong>solo</strong> quelli. Se lasci vuoto, vengono mostrati <strong>tutti</strong> i modelli disponibili.</p>';
		},
		'grgw-chat-gateway'
	);

	add_settings_section(
		'grgw_cc_links',
		'Link utili',
		function(){
			echo '<p>Scorciatoie per debug e gestione.</p>';
		},
		'grgw-chat-gateway'
	);

	add_settings_field(
		'grgw_cc_webhooks',
		'Webhook n8n (multipli)',
		function(){
			// Soft migration: se esiste webhook legacy e la lista è vuota, importa
			$legacy = (string) get_option('grgw_cc_webhook_url', '');
			$webhooks = get_option('grgw_cc_webhooks', []);
			if (!is_array($webhooks)) $webhooks = [];

			if ($legacy !== '' && empty($webhooks)) {
				$webhooks = [['url' => $legacy, 'desc' => 'Importato', 'label' => 'Default']];
			}

			$active = (int) get_option('grgw_cc_webhook_active', 0);
			?>
			<div id="grgw-cc-webhooks-container" style="margin-bottom:12px;">
				<p class="description" style="margin-bottom:10px;">
					Configura uno o più webhook n8n. Il webhook "attivo" viene usato di default; l'utente può sceglierne un altro dal dropdown nella UI.
				</p>
				<table class="widefat" style="max-width:100%; background:#fff;" id="grgw-cc-webhooks-table">
					<thead>
						<tr>
							<th style="width:60px; text-align:center;">Attivo</th>
							<th style="width:35%;">URL Webhook</th>
							<th style="width:25%;">Descrizione (Admin)</th>
							<th style="width:20%;">Nome Menu (UX)</th>
							<th style="width:80px; text-align:center;">Azioni</th>
						</tr>
					</thead>
					<tbody id="grgw-cc-webhooks-tbody">
						<?php if (empty($webhooks)): ?>
							<tr class="grgw-cc-webhook-row">
								<td style="text-align:center;">
									<input type="radio" name="grgw_cc_webhook_active" value="0" checked class="grgw-cc-webhook-radio" />
								</td>
								<td><input type="url" name="grgw_cc_webhooks[0][url]" value="" class="regular-text" placeholder="https://..." /></td>
								<td><input type="text" name="grgw_cc_webhooks[0][desc]" value="" class="regular-text" placeholder="Descrizione" /></td>
								<td><input type="text" name="grgw_cc_webhooks[0][label]" value="" class="regular-text" placeholder="Nome menu" /></td>
								<td style="text-align:center;"><button type="button" class="button grgw-cc-webhook-remove">−</button></td>
							</tr>
						<?php else: ?>
							<?php foreach ($webhooks as $idx => $wh): ?>
								<tr class="grgw-cc-webhook-row">
									<td style="text-align:center;">
										<input type="radio" name="grgw_cc_webhook_active" value="<?php echo esc_attr((string)$idx); ?>" <?php checked($active, $idx); ?> class="grgw-cc-webhook-radio" />
									</td>
									<td><input type="url" name="grgw_cc_webhooks[<?php echo esc_attr((string)$idx); ?>][url]" value="<?php echo esc_attr($wh['url']); ?>" class="regular-text" placeholder="https://..." /></td>
									<td><input type="text" name="grgw_cc_webhooks[<?php echo esc_attr((string)$idx); ?>][desc]" value="<?php echo esc_attr($wh['desc']); ?>" class="regular-text" placeholder="Descrizione" /></td>
									<td><input type="text" name="grgw_cc_webhooks[<?php echo esc_attr((string)$idx); ?>][label]" value="<?php echo esc_attr($wh['label']); ?>" class="regular-text" placeholder="Nome menu" /></td>
									<td style="text-align:center;"><button type="button" class="button grgw-cc-webhook-remove">−</button></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<button type="button" class="button" id="grgw-cc-webhook-add" style="margin-top:8px;">+ Aggiungi Webhook</button>
			</div>

			<script>
			(function(){
				const tbody = document.getElementById('grgw-cc-webhooks-tbody');
				const addBtn = document.getElementById('grgw-cc-webhook-add');
				if (!tbody || !addBtn) return;

				let nextIndex = tbody.querySelectorAll('tr').length;

				addBtn.addEventListener('click', function(){
					const row = document.createElement('tr');
					row.className = 'grgw-cc-webhook-row';
					row.innerHTML = '<td style="text-align:center;"><input type="radio" name="grgw_cc_webhook_active" value="'+nextIndex+'" class="grgw-cc-webhook-radio" /></td>'
						+ '<td><input type="url" name="grgw_cc_webhooks['+nextIndex+'][url]" value="" class="regular-text" placeholder="https://..." /></td>'
						+ '<td><input type="text" name="grgw_cc_webhooks['+nextIndex+'][desc]" value="" class="regular-text" placeholder="Descrizione" /></td>'
						+ '<td><input type="text" name="grgw_cc_webhooks['+nextIndex+'][label]" value="" class="regular-text" placeholder="Nome menu" /></td>'
						+ '<td style="text-align:center;"><button type="button" class="button grgw-cc-webhook-remove">−</button></td>';
					tbody.appendChild(row);
					nextIndex++;
				});

				tbody.addEventListener('click', function(e){
					if (!e.target.classList.contains('grgw-cc-webhook-remove')) return;
					const row = e.target.closest('tr');
					if (!row) return;
					row.remove();
					// Se rimane solo una riga, assicurati che sia checked
					const rows = tbody.querySelectorAll('tr');
					if (rows.length === 1) {
						const radio = rows[0].querySelector('input[type="radio"]');
						if (radio) radio.checked = true;
					}
				});
			})();
			</script>
			<?php
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_secret',
		'Secret (X-GRGW-SECRET)',
		function(){
			$has_constant = defined('GRGW_N8N_SECRET') && GRGW_N8N_SECRET;
			$disabled = $has_constant ? 'disabled' : '';
			$val = $has_constant ? '******** (da wp-config.php)' : esc_attr(get_option('grgw_cc_secret', ''));
			echo '<input type="password" name="grgw_cc_secret" value="'.$val.'" class="regular-text" autocomplete="new-password" '.$disabled.' />';
			if ($has_constant) {
				echo '<p class="description">Stai usando <code>GRGW_N8N_SECRET</code> da <code>wp-config.php</code>. Questo campo è ignorato.</p>';
			} else {
				echo '<p class="description">Inserisci lo stesso secret atteso dal tuo webhook n8n (header <code>X-GRGW-SECRET</code>).</p>';
			}
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_brand_name',
		'Nome mostrato (header chat)',
		function(){
			$val = (string) get_option('grgw_cc_brand_name', 'GRGW Chat Gateway');
			echo '<input type="text" class="regular-text" name="grgw_cc_brand_name" value="'.esc_attr($val).'" />';
			echo '<p class="description">Testo visibile in alto a sinistra sopra la lista chat.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_public_enabled',
		'Pubblica plugin (debug)',
		function(){
			$enabled = (bool) get_option('grgw_cc_public_enabled', 0);
			echo '<label style="display:flex; align-items:center; gap:8px;">';
			echo '<input type="checkbox" id="grgw_cc_public_enabled" name="grgw_cc_public_enabled" value="1" '.checked($enabled, true, false).' />';
			echo '<span>Rende la chat utilizzabile anche senza login. Usa <strong>solo</strong> su pagina protetta (es. password) per debug.</span>';
			echo '</label>';
			echo '<p class="description">Quando attivi questa opzione, il plugin accetta richieste REST anche da visitatori non autenticati, usando un token interno.
				</p>';
			// Prompt password anti-click-mis (solo barriera UX). La password è configurabile nelle impostazioni.
			$guard = (string) get_option('grgw_cc_public_guard_password', 'manut_post_grgw,');
			$guard_js = wp_json_encode($guard);
			echo '<script>(function(){document.addEventListener("DOMContentLoaded",function(){var cb=document.getElementById("grgw_cc_public_enabled");if(!cb)return;cb.addEventListener("change",function(){if(!cb.checked)return;var g='.$guard_js.';if(!g)return;var p=prompt("Inserire password per la pubblicazione");if(p!==g){alert("Password errata");cb.checked=false;}});});})();</script>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_public_guard_password',
		'Password sblocco modalità pubblica',
		function(){
			$val = (string) get_option('grgw_cc_public_guard_password', 'manut_post_grgw,');
			echo '<input type="text" class="regular-text" name="grgw_cc_public_guard_password" value="'.esc_attr($val).'" />';
			echo '<p class="description">Solo barriera UX: quando spunti "Pubblica plugin", viene chiesta questa password per evitare click accidentali.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_brand_logo_url',
		'Logo (header chat)',
		function(){
			$val = (string) get_option('grgw_cc_brand_logo_url', '');
			$has = $val !== '';
			echo '<div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">';
			echo '<div style="width:52px; height:52px; border:1px solid #ccd0d4; border-radius:10px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden;">';
			echo '<img id="grgw-cc-logo-preview" src="'.esc_url($val).'" alt="logo" style="max-width:100%; max-height:100%; '.($has ? '' : 'display:none;').'" />';
			echo '<span id="grgw-cc-logo-empty" style="color:#666; font-size:12px; '.($has ? 'display:none;' : '').'">(vuoto)</span>';
			echo '</div>';
			echo '<div style="display:flex; flex-direction:column; gap:8px;">';
			echo '<input type="url" class="regular-text" name="grgw_cc_brand_logo_url" value="'.esc_attr($val).'" placeholder="https://.../logo.png" />';
			echo '<div style="display:flex; gap:8px;">';
			echo '<button type="button" class="button" id="grgw-cc-logo-pick">Scegli da Media</button>';
			echo '<button type="button" class="button" id="grgw-cc-logo-clear">Rimuovi</button>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
			echo '<p class="description">Il logo viene mostrato in alto a sinistra sopra la lista chat.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_help_url',
		'URL guida (icona ? in chat)',
		function(){
			$val = esc_attr((string) get_option('grgw_cc_help_url', ''));
			echo '<input type="url" name="grgw_cc_help_url" value="'.$val.'" class="regular-text" placeholder="https://tuosito.it/guida-chat" />';
			echo '<p class="description">Se vuoto, l\'icona <strong>?</strong> non viene mostrata.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_chat_page_url',
		'URL pagina Chat (redirect post-login)',
		function(){
			$val = esc_attr((string) get_option('grgw_cc_chat_page_url', ''));
			echo '<input type="url" name="grgw_cc_chat_page_url" value="'.$val.'" class="regular-text" placeholder="https://tuodominio.it/chat" />';
			echo '<p class="description">Imposta qui l\'URL della pagina che contiene lo shortcode <code>[grgw_chat]</code>. Se un utente apre la chat da non loggato, verra mostrato il login e dopo l\'accesso verra reindirizzato a questo URL.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_shortcode_info',
		'Shortcode',
		function(){
			$code1 = '[grgw_chat]';
			$code2 = '[grgw_chat fullpage="1"]';
			echo '<div style="margin-bottom:8px;">';
			echo '<strong>Modalità Embed:</strong> <code style="font-size:13px; background:#f0f0f1; padding:4px 8px; border-radius:4px;">'.esc_html($code1).'</code>';
			echo '</div>';
			echo '<div style="margin-bottom:8px;">';
			echo '<strong>Modalità Fullpage:</strong> <code style="font-size:13px; background:#f0f0f1; padding:4px 8px; border-radius:4px;">'.esc_html($code2).'</code>';
			echo '</div>';
			echo '<p class="description">Incolla uno di questi shortcode in una pagina/post. La modalità <em>fullpage</em> occupa tutto lo schermo (ideale con Elementor Canvas).</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);


	add_settings_field(
		'grgw_cc_timeout',
		'Timeout richiesta verso n8n (secondi)',
		function(){
			$val = (int) get_option('grgw_cc_timeout', 1000);
			echo '<input type="number" min="0" max="20000" step="1" name="grgw_cc_timeout" value="'.esc_attr((string)$val).'" />';
			echo '<p class="description">Range: 0–20000. Default: 1000. Valore 0 = "illimitato" (in pratica 20000). Nota: PHP/Proxy possono comunque tagliare richieste troppo lunghe.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_rate_per_minute',
		'Rate limit (richieste/minuto)',
		function(){
			$val = (int) get_option('grgw_cc_rate_per_minute', 20);
			echo '<input type="number" min="0" max="20" step="1" name="grgw_cc_rate_per_minute" value="'.esc_attr((string)$val).'" />';
			echo '<p class="description">Range: 0–20. Default: 20. Valore 0 = disabilitato. Consiglio: tienilo basso per evitare spam/danni.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_rate_per_day',
		'Rate limit (richieste/giorno)',
		function(){
			$val = (int) get_option('grgw_cc_rate_per_day', 1000);
			echo '<input type="number" min="0" max="1000" step="1" name="grgw_cc_rate_per_day" value="'.esc_attr((string)$val).'" />';
			echo '<p class="description">Range: 0–1000. Default: 1000. Valore 0 = disabilitato.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

	add_settings_field(
		'grgw_cc_max_upload_mb',
		'Max upload per messaggio (MB)',
		function(){
			$val = (int) get_option('grgw_cc_max_upload_mb', 20);
			echo '<input type="number" min="1" max="1024" name="grgw_cc_max_upload_mb" value="'.esc_attr((string)$val).'" />';
			echo '<p class="description">Nota: anche il server ha i suoi limiti (upload_max_filesize/post_max_size). Qui imponiamo solo un limite "logico" lato plugin.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_main'
	);

		add_settings_field(
			'grgw_cc_debug_enabled',
			'Modalità debug UI',
			function(){
				$val = (int) get_option('grgw_cc_debug_enabled', 0);
				echo '<label><input type="checkbox" name="grgw_cc_debug_enabled" value="1" ' . checked(1, $val, false) . ' /> Abilita pannello debug nella chat (solo admin)</label>';
				echo '<p class="description">Mostra un riquadro "Debug" nella UI della chat con configurazione, stato polling e ultimi eventi di rete. Utile quando qualcosa smette di funzionare e vuoi capire <em>cosa</em> e <em>dove</em> si rompe.</p>';
			},
			'grgw-chat-gateway',
			'grgw_cc_main'
		);

	add_settings_field(
		'grgw_cc_models_enabled',
		'Provider/Modelli mostrati in UX',
		function(){
			$providers = function_exists('grgw_cc_default_providers_models') ? grgw_cc_default_providers_models() : [];
			$selected = get_option('grgw_cc_models_enabled', []);
			if (!is_array($selected)) $selected = [];
			$sel = array_fill_keys($selected, true);

			echo '<p class="description">Se non selezioni nulla, la UI mostra <strong>tutti</strong> i modelli di default. Se selezioni almeno un modello, la UI mostrerà <strong>solo</strong> quelli scelti.</p>';
			echo '<div style="padding:8px 12px; background:#fff; border:1px solid #ccd0d4; max-width:920px">';

			foreach ($providers as $p) {
				// Supporta sia vecchio formato (provider/id) sia nuovo (key/value).
				$prov = '';
				if (isset($p['key'])) {
					$prov = (string) $p['key'];
				} elseif (isset($p['provider'])) {
					$prov = (string) $p['provider']; // backward-compat
				}
				$prov = sanitize_key($prov);
				$label = isset($p['label']) ? (string)$p['label'] : $prov;
				$models = isset($p['models']) && is_array($p['models']) ? $p['models'] : [];
				if (!$prov || empty($models)) continue;

				echo '<div style="margin:10px 0 14px 0; padding:10px; border:1px solid #e5e5e5; border-radius:6px">';
				echo '<div style="font-weight:700; margin-bottom:8px">'.esc_html($label).' <span style="opacity:.6">('.esc_html($prov).')</span></div>';
				echo '<div style="display:flex; flex-wrap:wrap; gap:10px 16px">';

				foreach ($models as $m) {
					$id = '';
					if (isset($m['value'])) {
						$id = (string) $m['value'];
					} elseif (isset($m['id'])) {
						$id = (string) $m['id']; // backward-compat
					}
					$id = trim($id);
					$ml = isset($m['label']) ? (string)$m['label'] : $id;
					if (!$id) continue;
					$key = $prov.'|'.$id;
					$checked = isset($sel[$key]) ? 'checked' : '';
					echo '<label style="display:inline-flex; gap:6px; align-items:center">'
						.'<input type="checkbox" name="grgw_cc_models_enabled[]" value="'.esc_attr($key).'" '.$checked.' />'
						.'<span>'.esc_html($ml).'</span>'
					.'</label>';
				}

				echo '</div>';
				echo '</div>';
			}

			echo '</div>';
		},
		'grgw-chat-gateway',
		'grgw_cc_ai'
	);

	add_settings_field(
		'grgw_cc_links',
		'Link utili',
		function(){
			$archive = admin_url('options-general.php?page=grgw-chat-gateway-archive');
			$media = admin_url('upload.php');
			$media_new = admin_url('media-new.php');
			$debug = rest_url('grgrowth/v1/cc/debug');
			$uploads = wp_upload_dir();
			$baseurl = isset($uploads['baseurl']) ? (string)$uploads['baseurl'] : '';
			$basedir = isset($uploads['basedir']) ? (string)$uploads['basedir'] : '';

			echo '<ul style="margin:6px 0 0 20px; list-style:disc">';
			echo '<li><a href="'.esc_url($archive).'">Apri archivio conversazioni</a></li>';
			echo '<li><a href="'.esc_url($media).'">Apri Media Library</a> (caricamenti) — <a href="'.esc_url($media_new).'">aggiungi nuovo</a></li>';
			if ($baseurl) {
				echo '<li>Uploads (base URL): <a href="'.esc_url($baseurl).'" target="_blank" rel="noopener">'.esc_html($baseurl).'</a></li>';
			}
			if ($basedir) {
				echo '<li>Uploads (path server): <code>'.esc_html($basedir).'</code></li>';
			}
			echo '<li>Debug endpoint (JSON): <a href="'.esc_url($debug).'" target="_blank" rel="noopener">'.esc_html($debug).'</a></li>';
			echo '</ul>';

			echo '<p class="description">Nota: l\'archivio chat mostra le conversazioni e i messaggi salvati nelle tabelle del plugin.</p>';
		},
		'grgw-chat-gateway',
		'grgw_cc_links'
	);

});

function grgw_cc_render_settings_page() : void {
	if (!current_user_can('manage_options')) return;
	// Serve per aprire la Media Library dal pulsante "Scegli da Media".
	wp_enqueue_media();
	?>
	<div class="wrap">
		<h1>GRGW Chat Gateway</h1>
		<form method="post" action="options.php">
			<?php
				settings_fields('grgw_cc_settings');
				submit_button('Salva impostazioni', 'primary', 'submit', false);
				echo '<hr style="margin:20px 0;" />';
				do_settings_sections('grgw-chat-gateway');
				submit_button('Salva impostazioni');
			?>
		</form>

		<hr/>
		<h2>Tipi file consentiti</h2>
		<p>Consentiti: <code>pdf, doc, docx, txt, csv, xlsx, png, jpg, jpeg, webp</code>. Max 1 file per messaggio (come hai richiesto).</p>

		<h2>Debug rapido</h2>
		<p>Se "non parte" nulla:</p>
		<ul style="list-style:disc; margin-left: 20px;">
			<li>Controlla che il Webhook URL sia corretto e raggiungibile.</li>
			<li>Controlla il secret (deve combaciare con quello su n8n).</li>
			<li>Apri Console del browser (F12) e guarda le chiamate a <code>/wp-json/grgrowth/v1/...</code>.</li>
		</ul>

		<script>
		(function(){
			if (!window.wp || !wp.media) return;
			const pickBtn = document.getElementById('grgw-cc-logo-pick');
			const clearBtn = document.getElementById('grgw-cc-logo-clear');
			const input = document.querySelector('input[name="grgw_cc_brand_logo_url"]');
			const preview = document.getElementById('grgw-cc-logo-preview');
			const empty = document.getElementById('grgw-cc-logo-empty');

			if (!pickBtn || !clearBtn || !input) return;

			let frame = null;
			pickBtn.addEventListener('click', function(e){
				e.preventDefault();
				if (frame){ frame.open(); return; }
				frame = wp.media({
					title: 'Seleziona il logo',
					button: { text: 'Usa questo logo' },
					multiple: false
				});
				frame.on('select', function(){
					const attachment = frame.state().get('selection').first().toJSON();
					const url = attachment.url || '';
					input.value = url;
					if (preview){ preview.src = url; preview.style.display = url ? '' : 'none'; }
					if (empty){ empty.style.display = url ? 'none' : ''; }
				});
				frame.open();
			});

			clearBtn.addEventListener('click', function(e){
				e.preventDefault();
				input.value = '';
				if (preview){ preview.src = ''; preview.style.display = 'none'; }
				if (empty){ empty.style.display = ''; }
			});
		})();
		</script>
	</div>
	<?php
}

/**
 * Archivio conversazioni (admin).
 */
function grgw_cc_render_archive_page() : void {
	if (!current_user_can('manage_options')) { return; }
	global $wpdb;

	$tables = function_exists('grgw_cc_tables') ? grgw_cc_tables() : [
		'conversations' => $wpdb->prefix . 'grgw_cc_conversations',
		'messages' => $wpdb->prefix . 'grgw_cc_messages',
	];
	$conv_table = $tables['conversations'];
	$msg_table = $tables['messages'];

	$per_page = 25;
	$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
	$offset = ($paged - 1) * $per_page;

	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$conv_table}");
	$rows = $wpdb->get_results($wpdb->prepare(
		"SELECT c.id, c.title, c.user_id, c.provider, c.model, c.hitl_active, c.updated_at,
			(SELECT COUNT(*) FROM {$msg_table} m WHERE m.conversation_id = c.id) AS message_count
		 FROM {$conv_table} c
		 ORDER BY c.updated_at DESC
		 LIMIT %d OFFSET %d",
		$per_page,
		$offset
	));

	$base_url = admin_url('options-general.php?page=grgw-chat-gateway-archive');
	$total_pages = $per_page ? (int) ceil($total / $per_page) : 1;

	?>
	<div class="wrap">
		<h1>Archivio Chat — GRGW</h1>
		<p class="description">Lista delle conversazioni salvate dal plugin (tabella <code><?php echo esc_html($conv_table); ?></code>).</p>

		<table class="widefat fixed striped">
			<thead>
			<tr>
				<th style="width:260px;">Conversation ID</th>
				<th>Titolo</th>
				<th style="width:80px;">User</th>
				<th style="width:140px;">Provider</th>
				<th style="width:180px;">Model</th>
				<th style="width:90px;">Msg</th>
				<th style="width:90px;">HITL</th>
				<th style="width:180px;">Ultimo update</th>
			</tr>
			</thead>
			<tbody>
			<?php if (!$rows) : ?>
				<tr><td colspan="8">Nessuna conversazione trovata.</td></tr>
			<?php else : foreach ($rows as $r) :
				$view_url = admin_url('options-general.php?page=grgw-chat-gateway-conversation&conversation_id=' . rawurlencode($r->id));
			?>
				<tr>
					<td><a href="<?php echo esc_url($view_url); ?>"><code><?php echo esc_html($r->id); ?></code></a></td>
					<td><?php echo esc_html($r->title ?: '—'); ?></td>
					<td><?php echo esc_html((string)$r->user_id); ?></td>
					<td><?php echo esc_html($r->provider ?: '—'); ?></td>
					<td><?php echo esc_html($r->model ?: '—'); ?></td>
					<td><?php echo esc_html((string)($r->message_count ?? 0)); ?></td>
					<td><?php echo $r->hitl_active ? '✅' : '—'; ?></td>
					<td><?php echo esc_html((string)$r->updated_at); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<?php if ($total_pages > 1) : ?>
			<div style="margin-top:12px;">
				<?php
				echo paginate_links([
					'base' => esc_url_raw(add_query_arg('paged', '%#%', $base_url)),
					'format' => '',
					'prev_text' => '«',
					'next_text' => '»',
					'current' => $paged,
					'total' => $total_pages,
				]);
				?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Dettaglio conversazione (admin).
 */
function grgw_cc_render_conversation_page() : void {
	if (!current_user_can('manage_options')) { return; }
	global $wpdb;

	$conversation_id = isset($_GET['conversation_id']) ? sanitize_text_field((string)$_GET['conversation_id']) : '';
	if (!$conversation_id) {
		echo '<div class="wrap"><h1>Conversazione</h1><p>conversation_id mancante.</p></div>';
		return;
	}

	$tables = function_exists('grgw_cc_tables') ? grgw_cc_tables() : [
		'conversations' => $wpdb->prefix . 'grgw_cc_conversations',
		'messages' => $wpdb->prefix . 'grgw_cc_messages',
	];
	$conv_table = $tables['conversations'];
	$msg_table = $tables['messages'];

	$conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$conv_table} WHERE id = %s", $conversation_id));
	$messages = $wpdb->get_results($wpdb->prepare(
		"SELECT id, role, content, meta, created_at FROM {$msg_table} WHERE conversation_id = %s ORDER BY id ASC LIMIT 300",
		$conversation_id
	));

	$back_url = admin_url('options-general.php?page=grgw-chat-gateway-archive');
	?>
	<div class="wrap">
		<h1>Conversazione — <code><?php echo esc_html($conversation_id); ?></code></h1>
		<p><a href="<?php echo esc_url($back_url); ?>">&larr; Torna all'archivio</a></p>

		<?php if ($conv) : ?>
			<div style="margin: 10px 0; padding: 12px; background: #fff; border: 1px solid #dcdcde;">
				<strong>Titolo:</strong> <?php echo esc_html($conv->title ?: '—'); ?><br/>
				<strong>User:</strong> <?php echo esc_html((string)$conv->user_id); ?><br/>
				<strong>Provider/Model:</strong> <?php echo esc_html(($conv->provider ?: '—') . ' / ' . ($conv->model ?: '—')); ?><br/>
				<strong>HITL attivo:</strong> <?php echo $conv->hitl_active ? 'Sì' : 'No'; ?><br/>
				<strong>Aggiornata:</strong> <?php echo esc_html((string)$conv->updated_at); ?><br/>
			</div>
		<?php endif; ?>

		<table class="widefat fixed striped">
			<thead>
			<tr>
				<th style="width:70px;">ID</th>
				<th style="width:110px;">Role</th>
				<th>Content</th>
				<th style="width:180px;">Created</th>
			</tr>
			</thead>
			<tbody>
			<?php if (!$messages) : ?>
				<tr><td colspan="4">Nessun messaggio.</td></tr>
			<?php else : foreach ($messages as $m) : ?>
				<tr>
					<td><?php echo esc_html((string)$m->id); ?></td>
					<td><?php echo esc_html((string)$m->role); ?></td>
					<td style="white-space:pre-wrap;"><?php echo esc_html((string)$m->content); ?></td>
					<td><?php echo esc_html((string)$m->created_at); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
