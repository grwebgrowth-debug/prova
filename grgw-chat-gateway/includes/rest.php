<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('grgw_cc_hitl_rest_can_use')) {
function grgw_cc_hitl_rest_can_use($request) {
    if (function_exists('grgw_cc_rest_can_use')) {
        return grgw_cc_rest_can_use($request);
    }
    return current_user_can('read');
}
}

if (!function_exists('grgw_cc_hitl_rest_can_push')) {
function grgw_cc_hitl_rest_can_push($request) {
    $secret = function_exists('grgw_cc_hitl_get_secret') ? grgw_cc_hitl_get_secret() : grgw_cc_get_secret();
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
}

if (!function_exists('grgw_cc_hitl_json_response')) {
function grgw_cc_hitl_json_response(array $payload, int $status = 200) : WP_REST_Response {
    return new WP_REST_Response($payload, $status);
}
}

if (!function_exists('grgw_cc_hitl_json_error')) {
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
}

if (!function_exists('grgw_cc_resolve_webhook')) {
function grgw_cc_resolve_webhook($webhook_index = null) {
    $webhooks = get_option('grgw_cc_webhooks', []);
    if (!is_array($webhooks)) $webhooks = [];

    if ($webhook_index !== null && is_numeric($webhook_index)) {
        $idx = (int)$webhook_index;
        if (isset($webhooks[$idx]) && !empty($webhooks[$idx]['url'])) {
            return [
                'url' => $webhooks[$idx]['url'],
                'index' => $idx,
                'label' => isset($webhooks[$idx]['label']) ? $webhooks[$idx]['label'] : 'Webhook ' . $idx,
            ];
        }
    }

    if (!empty($webhooks)) {
        $active = (int) get_option('grgw_cc_webhook_active', 0);
        if (isset($webhooks[$active]) && !empty($webhooks[$active]['url'])) {
            return [
                'url' => $webhooks[$active]['url'],
                'index' => $active,
                'label' => isset($webhooks[$active]['label']) ? $webhooks[$active]['label'] : 'Webhook ' . $active,
            ];
        }
        foreach ($webhooks as $idx => $wh) {
            if (!empty($wh['url'])) {
                return [
                    'url' => $wh['url'],
                    'index' => $idx,
                    'label' => isset($wh['label']) ? $wh['label'] : 'Webhook ' . $idx,
                ];
            }
        }
    }

    $legacy = (string) get_option('grgw_cc_webhook_url', '');
    if ($legacy !== '') {
        return [
            'url' => $legacy,
            'index' => -1,
            'label' => 'Legacy Webhook',
        ];
    }

    return null;
}
}

if (!function_exists('grgw_cc_rest_can_use')) {
function grgw_cc_rest_can_use($request) {
    $public_enabled = grgw_cc_public_enabled();

    if (is_user_logged_in()) {
        return current_user_can('read');
    }

    if ($public_enabled) {
        $expected = (string) get_option('grgw_cc_public_token', '');
        if ($expected === '') {
            return new WP_Error('grgw_cc_public_not_configured', 'Public mode not configured.', ['status' => 500]);
        }
        $hdr = (string) $request->get_header('X-GRGW-PUBLIC-TOKEN');
        if ($hdr === '') {
            $hdr = (string) $request->get_header('x-grgw-public-token');
        }
        if (hash_equals($expected, $hdr)) {
            return true;
        }
        return new WP_Error('grgw_cc_forbidden', 'Invalid public token.', ['status' => 403]);
    }

    return new WP_Error('grgw_cc_unauthorized', 'Unauthorized.', ['status' => 401]);
}
}

add_action('rest_api_init', function() {
    register_rest_route('grgrowth/v1', '/cc/conversations', [
        'methods' => 'GET',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $limit = (int) $request->get_param('limit');
            $offset = (int) $request->get_param('offset');
            if ($limit < 1 || $limit > 100) $limit = 50;
            if ($offset < 0) $offset = 0;

            global $wpdb;
            $tables = grgw_cc_tables();

            if ($thread_prefix) {
                $like = $wpdb->esc_like($thread_prefix) . '%';
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$tables['conversations']}
                     WHERE user_id = %d AND thread_id LIKE %s
                     ORDER BY updated_at DESC
                     LIMIT %d OFFSET %d",
                    $user_id, $like, $limit, $offset
                ), ARRAY_A);
            } else {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$tables['conversations']}
                     WHERE user_id = %d
                     ORDER BY updated_at DESC
                     LIMIT %d OFFSET %d",
                    $user_id, $limit, $offset
                ), ARRAY_A);
            }

            $response = rest_ensure_response(['ok' => true, 'conversations' => $rows ?: []]);
            $response->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');
            return $response;
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $params = $request->get_json_params();
            $provider = isset($params['provider']) ? sanitize_text_field($params['provider']) : 'google';
            $model = isset($params['model']) ? sanitize_text_field($params['model']) : 'gemini-2.0-flash';

            global $wpdb;
            $tables = grgw_cc_tables();

            $id = wp_generate_uuid4();
            $thread_id = ($thread_prefix ? $thread_prefix : '') . $id;
            $now = current_time('mysql');

            $wpdb->insert($tables['conversations'], [
                'id' => $id,
                'user_id' => $user_id,
                'title' => 'Nuova chat',
                'is_title_custom' => 0,
                'provider' => $provider,
                'model' => $model,
                'thread_id' => $thread_id,
                'hitl_active' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s','%d','%s','%d','%s','%s','%s','%d','%s','%s']);

            $conv = grgw_cc_get_conversation($id, $user_id);
            return new WP_REST_Response(['ok' => true, 'conversation' => $conv], 201);
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})', [
        'methods' => 'GET',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $id = $request->get_param('id');
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $conv = grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix);
            if (!$conv) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);
            }

            $limit = (int) $request->get_param('limit');
            if ($limit < 1 || $limit > 500) $limit = 200;

            $messages = grgw_cc_get_messages($id, $limit);

            $response = rest_ensure_response([
                'ok' => true,
                'conversation' => $conv,
                'messages' => $messages,
            ]);
            $response->header('Cache-Control', 'no-cache, must-revalidate, max-age=0');
            return $response;
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})', [
        'methods' => 'PATCH',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $id = $request->get_param('id');
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $conv = grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix);
            if (!$conv) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);
            }

            $params = $request->get_json_params();

            global $wpdb;
            $tables = grgw_cc_tables();
            $data = [];
            $formats = [];

            if (isset($params['title'])) {
                $data['title'] = sanitize_text_field($params['title']);
                $data['is_title_custom'] = 1;
                $formats[] = '%s';
                $formats[] = '%d';
            }
            if (isset($params['provider'])) {
                $data['provider'] = sanitize_text_field($params['provider']);
                $formats[] = '%s';
            }
            if (isset($params['model'])) {
                $data['model'] = sanitize_text_field($params['model']);
                $formats[] = '%s';
            }

            if (!empty($data)) {
                $data['updated_at'] = current_time('mysql');
                $formats[] = '%s';
                $wpdb->update($tables['conversations'], $data, ['id' => $id, 'user_id' => $user_id], $formats, ['%s', '%d']);
            }

            $conv = grgw_cc_get_conversation($id, $user_id);
            return new WP_REST_Response(['ok' => true, 'conversation' => $conv], 200);
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})', [
        'methods' => 'DELETE',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $id = $request->get_param('id');
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $conv = grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix);
            if (!$conv) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);
            }

            global $wpdb;
            $tables = grgw_cc_tables();

            $wpdb->delete($tables['messages'], ['conversation_id' => $id], ['%s']);
            $wpdb->delete($tables['conversations'], ['id' => $id, 'user_id' => $user_id], ['%s', '%d']);

            return new WP_REST_Response(['ok' => true], 200);
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/conversations/(?P<id>[a-f0-9\-]{36})/message', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_rest_can_use',
        'callback' => function($request) {
            $id = $request->get_param('id');
            $user_id = get_current_user_id();
            $thread_prefix = grgw_cc_current_thread_prefix($user_id);

            $conv = grgw_cc_get_conversation_scoped($id, $user_id, $thread_prefix);
            if (!$conv) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);
            }

            $content_type = $request->get_content_type();
            $is_multipart = isset($content_type['value']) && strpos($content_type['value'], 'multipart/form-data') !== false;

            $text = '';
            $provider = $conv['provider'];
            $model = $conv['model'];
            $webhook_index = null;
            $attachments = [];

            if ($is_multipart) {
                $text = sanitize_textarea_field($request->get_param('text') ?? '');
                $provider = sanitize_text_field($request->get_param('provider') ?? $conv['provider']);
                $model = sanitize_text_field($request->get_param('model') ?? $conv['model']);
                $webhook_index = $request->get_param('webhook_index');

                $files = $request->get_file_params();
                if (!empty($files['file'])) {
                    $file = $files['file'];
                    $max_mb = (int) get_option('grgw_cc_max_upload_mb', 20);
                    $max_bytes = $max_mb * 1024 * 1024;

                    if ($file['size'] > $max_bytes) {
                        return new WP_REST_Response(['ok' => false, 'error' => 'File troppo grande'], 400);
                    }

                    $allowed = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xlsx', 'png', 'jpg', 'jpeg', 'webp'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed, true)) {
                        return new WP_REST_Response(['ok' => false, 'error' => 'Tipo file non consentito'], 400);
                    }

                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    $upload = wp_handle_upload($file, ['test_form' => false]);

                    if (isset($upload['error'])) {
                        return new WP_REST_Response(['ok' => false, 'error' => $upload['error']], 400);
                    }

                    $attachments[] = [
                        'name' => basename($upload['file']),
                        'mime' => $upload['type'],
                        'size' => filesize($upload['file']),
                        'url' => $upload['url'],
                    ];
                }
            } else {
                $params = $request->get_json_params();
                $text = isset($params['text']) ? sanitize_textarea_field($params['text']) : '';
                $provider = isset($params['provider']) ? sanitize_text_field($params['provider']) : $conv['provider'];
                $model = isset($params['model']) ? sanitize_text_field($params['model']) : $conv['model'];
                $webhook_index = isset($params['webhook_index']) ? $params['webhook_index'] : null;
            }

            if ($text === '' && empty($attachments)) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Empty message'], 400);
            }

            grgw_cc_insert_message($id, 'user', $text, $attachments ? ['attachments' => $attachments] : null);
            grgw_cc_touch_conversation($id, $user_id);

            $webhook = grgw_cc_resolve_webhook($webhook_index);
            if (!$webhook) {
                grgw_cc_insert_message($id, 'assistant', 'Errore: nessun webhook configurato.');
                return new WP_REST_Response([
                    'ok' => true,
                    'reply' => 'Errore: nessun webhook configurato.',
                    'conversation' => grgw_cc_get_conversation($id, $user_id),
                ], 200);
            }

            $user_info = null;
            if ($user_id > 0) {
                $user = get_userdata($user_id);
                if ($user) {
                    $user_info = [
                        'user_id' => $user_id,
                        'user_login' => $user->user_login,
                        'display_name' => $user->display_name,
                        'site' => home_url(),
                    ];
                }
            }
            if (!$user_info) {
                $user_info = [
                    'user_id' => $user_id,
                    'user_login' => 'guest',
                    'display_name' => 'Guest',
                    'site' => home_url(),
                ];
            }

            $payload = [
                'client_id' => 'wp_' . $user_id,
                'message' => [
                    'text' => $text,
                    'attachments' => $attachments,
                ],
                'session' => [
                    'thread_id' => $conv['thread_id'],
                ],
                'ai' => [
                    'provider' => $provider,
                    'model' => $model,
                ],
                'context' => [
                    'conversation_id' => $id,
                    'conversation_title' => $conv['title'],
                    'wp' => $user_info,
                ],
            ];

            if ((int)$conv['hitl_active'] === 1 && !empty($conv['hitl_id'])) {
                $payload['hitl'] = [
                    'active' => true,
                    'hitl_id' => $conv['hitl_id'],
                    'started_at' => $conv['hitl_started_at'] ?? null,
                    'expires_at' => $conv['hitl_expires_at'] ?? null,
                ];
            }

            $secret = grgw_cc_get_secret();
            $headers = [
                'Content-Type' => 'application/json; charset=utf-8',
            ];
            if ($secret !== '') {
                $headers['X-GRGW-SECRET'] = $secret;
            }

            $timeout = (int) get_option('grgw_cc_timeout', 1000);
            if ($timeout <= 0) $timeout = 20000;
            $timeout = min(20000, $timeout);

            $response = wp_remote_post($webhook['url'], [
                'timeout' => $timeout,
                'headers' => $headers,
                'body' => wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            if (is_wp_error($response)) {
                $err_msg = 'Errore webhook: ' . $response->get_error_message();
                grgw_cc_insert_message($id, 'assistant', $err_msg);
                return new WP_REST_Response([
                    'ok' => true,
                    'reply' => $err_msg,
                    'conversation' => grgw_cc_get_conversation($id, $user_id),
                ], 200);
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            $reply = '';
            if (isset($data['reply'])) {
                $reply = (string) $data['reply'];
            } elseif (isset($data['response'])) {
                $reply = (string) $data['response'];
            } elseif (isset($data['text'])) {
                $reply = (string) $data['text'];
            } elseif (isset($data['message'])) {
                $reply = (string) $data['message'];
            } else {
                $reply = 'Risposta ricevuta senza testo.';
            }

            $meta = null;
            $extra = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : [];

            $should_activate_hitl = false;
            $hitl_id = null;

            if (isset($extra['hitl']) && is_array($extra['hitl'])) {
                $hitl_data = $extra['hitl'];
                if (isset($hitl_data['active']) && $hitl_data['active']) {
                    $should_activate_hitl = true;
                    $hitl_id = isset($hitl_data['hitl_id']) ? sanitize_text_field($hitl_data['hitl_id']) : wp_generate_uuid4();
                }
            }

            if (isset($extra['polling']) && is_array($extra['polling'])) {
                $polling_data = $extra['polling'];
                if (isset($polling_data['enabled']) && $polling_data['enabled']) {
                    $should_activate_hitl = true;
                    if (!$hitl_id) {
                        $hitl_id = wp_generate_uuid4();
                    }
                }
            }

            if (isset($data['hitl']) && $data['hitl']) {
                $should_activate_hitl = true;
                if (!$hitl_id && isset($data['hitl_id'])) {
                    $hitl_id = sanitize_text_field($data['hitl_id']);
                }
                if (!$hitl_id) {
                    $hitl_id = wp_generate_uuid4();
                }
            }

            if ($should_activate_hitl) {
                $meta = ['hitl' => true, 'hitl_id' => $hitl_id, 'type' => 'hitl_request'];

                if (isset($data['buttons']) && is_array($data['buttons'])) {
                    $meta['buttons'] = $data['buttons'];
                }

                global $wpdb;
                $tables = grgw_cc_tables();

                if (function_exists('grgw_cc_hitl_timeout_sec')) {
                    $timeout_sec = grgw_cc_hitl_timeout_sec();
                } else {
                    $timeout_sec = (int) get_option('grgw_cc_hitl_timeout_sec', 3600);
                    if ($timeout_sec <= 0) $timeout_sec = 3600;
                }

                $expires_at = date('Y-m-d H:i:s', time() + $timeout_sec);

                $wpdb->update($tables['conversations'], [
                    'hitl_active' => 1,
                    'hitl_id' => $hitl_id,
                    'hitl_started_at' => current_time('mysql'),
                    'hitl_expires_at' => $expires_at,
                    'updated_at' => current_time('mysql'),
                ], ['id' => $id], ['%d','%s','%s','%s','%s'], ['%s']);
            }

            if ($reply !== '') {
                grgw_cc_insert_message($id, 'assistant', $reply, $meta);
            }

            if (isset($data['title']) && !empty($data['title']) && (int)$conv['is_title_custom'] === 0) {
                global $wpdb;
                $tables = grgw_cc_tables();
                $wpdb->update($tables['conversations'], [
                    'title' => sanitize_text_field($data['title']),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $id], ['%s','%s'], ['%s']);
            }

            grgw_cc_touch_conversation($id, $user_id);

            return new WP_REST_Response([
                'ok' => true,
                'reply' => $reply,
                'conversation' => grgw_cc_get_conversation($id, $user_id),
            ], 200);
        },
    ]);

    register_rest_route('grgrowth/v1', '/cc/config', [
        'methods' => 'GET',
        'permission_callback' => function() { return true; },
        'callback' => function($request) {
            $webhooks = get_option('grgw_cc_webhooks', []);
            if (!is_array($webhooks)) $webhooks = [];

            $active = (int) get_option('grgw_cc_webhook_active', 0);
            $chat_page_url = (string) get_option('grgw_cc_chat_page_url', '');

            $webhook_list = [];
            foreach ($webhooks as $idx => $wh) {
                if (!empty($wh['url'])) {
                    $webhook_list[] = [
                        'index' => $idx,
                        'label' => isset($wh['label']) ? $wh['label'] : 'Webhook ' . $idx,
                    ];
                }
            }

            return new WP_REST_Response([
                'ok' => true,
                'config' => [
                    'webhooks' => $webhook_list,
                    'active_webhook_index' => $active,
                    'chat_page_url' => $chat_page_url,
                ],
            ], 200);
        },
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/debug', [
        'methods' => 'GET',
        'permission_callback' => function() { return true; },
        'callback' => function($request) {
            $is_admin = current_user_can('manage_options');

            $webhooks = get_option('grgw_cc_webhooks', []);
            if (!is_array($webhooks)) $webhooks = [];
            $active = (int) get_option('grgw_cc_webhook_active', 0);
            $legacy = (string) get_option('grgw_cc_webhook_url', '');

            $resolved = grgw_cc_resolve_webhook();

            $webhook_info = [
                'active' => $active,
                'legacy' => $legacy !== '' ? 'configured' : 'empty',
                'webhook_url_in_use' => $resolved ? $resolved['url'] : null,
                'webhook_active' => $resolved ? $resolved['index'] : null,
            ];

            if ($is_admin) {
                $webhook_info['webhooks'] = [];
                foreach ($webhooks as $idx => $wh) {
                    $webhook_info['webhooks'][] = [
                        'index' => $idx,
                        'url' => $wh['url'],
                        'desc' => isset($wh['desc']) ? $wh['desc'] : '',
                        'label' => isset($wh['label']) ? $wh['label'] : '',
                    ];
                }
            } else {
                $webhook_info['webhooks'] = [];
                foreach ($webhooks as $idx => $wh) {
                    if (!empty($wh['url'])) {
                        $webhook_info['webhooks'][] = [
                            'index' => $idx,
                            'label' => isset($wh['label']) ? $wh['label'] : 'Webhook ' . $idx,
                        ];
                    }
                }
            }

            return new WP_REST_Response([
                'ok' => true,
                'plugin' => [
                    'version' => defined('GRGW_CC_VERSION') ? GRGW_CC_VERSION : 'unknown',
                ],
                'webhook' => $webhook_info,
                'server_time' => gmdate('c'),
            ], 200);
        },
        'args' => [],
    ]);

    register_rest_route('grgrowth/v1', '/cc/push', [
        'methods' => 'POST',
        'permission_callback' => 'grgw_cc_hitl_rest_can_push',
        'callback' => 'grgw_cc_hitl_rest_push',
        'args' => [],
    ]);

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
