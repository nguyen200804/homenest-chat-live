<?php
/**
 * Plugin Name: HomeNest Live Chat (Basic)
 * Description: Basic live chat widget with admin inbox (polling).
 * Version: 1.0.0
 * Author: HomeNest
 */

if (!defined('ABSPATH')) exit;

class HomeNest_Live_Chat_Basic {
    const VERSION = '1.0.0';
    const COOKIE_CONTACT = 'hn_chat_contact'; // lưu email/sđt phía client

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_action('wp_footer', [$this, 'render_widget']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

        // AJAX (guest + logged-in)
        add_action('wp_ajax_hn_chat_save_contact', [$this, 'ajax_save_contact']);
        add_action('wp_ajax_nopriv_hn_chat_save_contact', [$this, 'ajax_save_contact']);

        add_action('wp_ajax_hn_chat_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_nopriv_hn_chat_send_message', [$this, 'ajax_send_message']);

        add_action('wp_ajax_hn_chat_fetch_messages', [$this, 'ajax_fetch_messages']);
        add_action('wp_ajax_nopriv_hn_chat_fetch_messages', [$this, 'ajax_fetch_messages']);

        // Admin side
        add_action('wp_ajax_hn_chat_admin_list_conversations', [$this, 'ajax_admin_list_conversations']);
        add_action('wp_ajax_hn_chat_admin_get_conversation', [$this, 'ajax_admin_get_conversation']);
        add_action('wp_ajax_hn_chat_admin_send_message', [$this, 'ajax_admin_send_message']);
    }

    private function tables() {
        global $wpdb;
        return [
            'conversations' => $wpdb->prefix . 'hn_chat_conversations',
            'messages'      => $wpdb->prefix . 'hn_chat_messages',
        ];
    }

    public function on_activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $t = $this->tables();

        $sql1 = "CREATE TABLE {$t['conversations']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact VARCHAR(120) NOT NULL,
            contact_type VARCHAR(20) NOT NULL,
            visitor_token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY visitor_token (visitor_token),
            KEY last_message_at (last_message_at)
        ) $charset;";

        $sql2 = "CREATE TABLE {$t['messages']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_role VARCHAR(20) NOT NULL, /* guest|admin */
            sender_name VARCHAR(120) NULL,
            message LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
    }

    public function enqueue_front() {
        if (is_admin()) return;

        wp_enqueue_style(
            'hn-chat-css',
            plugin_dir_url(__FILE__) . 'assets/hn-chat.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'hn-chat-js',
            plugin_dir_url(__FILE__) . 'assets/hn-chat.js',
            ['jquery'],
            self::VERSION,
            true
        );

        $data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hn_chat_nonce'),
            'poll_ms' => 3000,
            'cookie_contact' => self::COOKIE_CONTACT,
        ];
        wp_localize_script('hn-chat-js', 'HNChat', $data);
    }

    public function render_widget() {
        if (is_admin()) return;

        // Bạn có thể tắt ở checkout/cart nếu muốn:
        // if (function_exists('is_cart') && is_cart()) return;
        // if (function_exists('is_checkout') && is_checkout()) return;

        ?>
        <div id="hn-chat-widget" class="hn-chat">
            <button class="hn-chat__fab" type="button" aria-label="Open chat">
                💬 <span class="hn-chat__badge" style="display:none;">1</span>
            </button>

            <div class="hn-chat__panel" style="display:none;">
                <div class="hn-chat__header">
                    <div class="hn-chat__title">HomeNest Chat</div>
                    <button class="hn-chat__close" type="button" aria-label="Close">✕</button>
                </div>

                <div class="hn-chat__body">
                    <div class="hn-chat__contactGate">
                        <div class="hn-chat__gateTitle">Nhập SĐT hoặc Email để bắt đầu</div>
                        <input class="hn-chat__contactInput" type="text" placeholder="SĐT hoặc Email" />
                        <button class="hn-chat__contactBtn" type="button">Bắt đầu chat</button>
                        <div class="hn-chat__gateHint">Thông tin này giúp chúng tôi liên hệ lại khi cần.</div>
                    </div>

                    <div class="hn-chat__messages" style="display:none;"></div>
                </div>

                <div class="hn-chat__footer" style="display:none;">
                    <input class="hn-chat__input" type="text" placeholder="Nhập tin nhắn..." />
                    <button class="hn-chat__send" type="button">Gửi</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function json_ok($data = []) {
        wp_send_json(['ok' => true, 'data' => $data]);
    }
    private function json_err($msg, $code = 400) {
        wp_send_json(['ok' => false, 'error' => $msg], $code);
    }

    private function get_visitor_token() {
        // token ổn định theo cookie WP (giản lược): dựa vào cookie login hoặc cookie PHPSESSID hoặc IP+UA
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return hash('sha256', $ip . '|' . $ua . '|' . wp_salt('nonce'));
    }

    private function detect_contact_type($contact) {
        $contact = trim($contact);
        if (is_email($contact)) return 'email';
        // phone: chỉ giữ số + dấu +
        $digits = preg_replace('/[^0-9\+]/', '', $contact);
        // heuristic
        if (strlen(preg_replace('/\D/', '', $digits)) >= 8) return 'phone';
        return 'unknown';
    }

    private function find_or_create_conversation($contact) {
        global $wpdb;
        $t = $this->tables();
        $token = $this->get_visitor_token();
        $now = current_time('mysql');

        // tìm conversation theo visitor_token (mỗi visitor 1 conversation mở)
        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['conversations']} WHERE visitor_token = %s AND status = 'open' ORDER BY id DESC LIMIT 1",
            $token
        ));

        if ($conv) {
            // cập nhật contact nếu trước đó rỗng/khác
            $contact_type = $this->detect_contact_type($contact);
            $wpdb->update($t['conversations'], [
                'contact' => $contact,
                'contact_type' => $contact_type,
            ], ['id' => (int)$conv->id]);
            return (int)$conv->id;
        }

        $contact_type = $this->detect_contact_type($contact);
        $wpdb->insert($t['conversations'], [
            'contact' => $contact,
            'contact_type' => $contact_type,
            'visitor_token' => $token,
            'status' => 'open',
            'last_message_at' => $now,
            'created_at' => $now,
        ]);

        return (int)$wpdb->insert_id;
    }

    public function ajax_save_contact() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        if (!$contact) $this->json_err('Thiếu SĐT/Email.');

        $type = $this->detect_contact_type($contact);
        if ($type === 'unknown') $this->json_err('SĐT/Email không hợp lệ.');

        $conversation_id = $this->find_or_create_conversation($contact);
        $this->json_ok(['conversation_id' => $conversation_id, 'contact_type' => $type]);
    }

    public function ajax_send_message() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';

        $message = trim($message);
        if (!$contact) $this->json_err('Thiếu contact.');
        if ($message === '') $this->json_err('Tin nhắn rỗng.');

        global $wpdb;
        $t = $this->tables();
        $conversation_id = $this->find_or_create_conversation($contact);
        $now = current_time('mysql');

        $wpdb->insert($t['messages'], [
            'conversation_id' => $conversation_id,
            'sender_role' => 'guest',
            'sender_name' => null,
            'message' => $message,
            'created_at' => $now,
        ]);

        $wpdb->update($t['conversations'], [
            'last_message_at' => $now
        ], ['id' => $conversation_id]);

        $this->json_ok(['conversation_id' => $conversation_id]);
    }

    public function ajax_fetch_messages() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $after_id = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;

        if (!$contact) $this->json_err('Thiếu contact.');

        global $wpdb;
        $t = $this->tables();
        $token = $this->get_visitor_token();

        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t['conversations']} WHERE visitor_token=%s AND status='open' ORDER BY id DESC LIMIT 1",
            $token
        ));
        if (!$conv) $this->json_ok(['messages' => []]);

        $conv_id = (int)$conv->id;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, message, created_at
             FROM {$t['messages']}
             WHERE conversation_id=%d AND id > %d
             ORDER BY id ASC
             LIMIT 200",
             $conv_id, $after_id
        ), ARRAY_A);

        $this->json_ok(['messages' => $rows, 'conversation_id' => $conv_id]);
    }

    /** ================= ADMIN ================= */

    public function admin_menu() {
        add_menu_page(
            'HomeNest Chat Live',
            'HomeNest Chat Live',
            'manage_options',
            'homenest-chat-live',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            58
        );
    }

    public function enqueue_admin($hook) {
        if ($hook !== 'toplevel_page_homenest-chat-live') return;

        wp_enqueue_style(
            'hn-chat-admin-css',
            plugin_dir_url(__FILE__) . 'admin/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'hn-chat-admin-js',
            plugin_dir_url(__FILE__) . 'admin/admin.js',
            ['jquery'],
            self::VERSION,
            true
        );

        wp_localize_script('hn-chat-admin-js', 'HNChatAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hn_chat_admin_nonce'),
        ]);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;

        echo '<div class="wrap"><h1>HomeNest Chat Live</h1>';
        echo '<div id="hn-admin-chat" class="hn-admin-chat">
                <div class="hn-admin-left">
                    <div class="hn-admin-search">
                        <input type="text" class="hn-admin-q" placeholder="Tìm theo SĐT/Email..." />
                    </div>
                    <div class="hn-admin-convList"></div>
                </div>
                <div class="hn-admin-right">
                    <div class="hn-admin-convHeader">
                        <div class="hn-admin-convTitle">Chọn một cuộc hội thoại</div>
                    </div>
                    <div class="hn-admin-messages"></div>
                    <div class="hn-admin-reply">
                        <input type="text" class="hn-admin-input" placeholder="Nhập tin nhắn trả lời..." disabled />
                        <button class="button button-primary hn-admin-send" disabled>Gửi</button>
                    </div>
                </div>
            </div>';
        echo '</div>';
    }

    private function admin_check() {
        if (!current_user_can('manage_options')) $this->json_err('No permission', 403);
        check_ajax_referer('hn_chat_admin_nonce', 'nonce');
    }

    public function ajax_admin_list_conversations() {
        $this->admin_check();

        global $wpdb;
        $t = $this->tables();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        if ($q) {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, contact, contact_type, status, last_message_at, created_at
                 FROM {$t['conversations']}
                 WHERE contact LIKE %s
                 ORDER BY COALESCE(last_message_at, created_at) DESC
                 LIMIT 200",
                $like
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT id, contact, contact_type, status, last_message_at, created_at
                 FROM {$t['conversations']}
                 ORDER BY COALESCE(last_message_at, created_at) DESC
                 LIMIT 200",
                ARRAY_A
            );
        }

        $this->json_ok(['conversations' => $rows]);
    }

    public function ajax_admin_get_conversation() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        if (!$conv_id) $this->json_err('Missing conversation_id.');

        global $wpdb;
        $t = $this->tables();

        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT id, contact, contact_type, status, last_message_at, created_at
             FROM {$t['conversations']} WHERE id=%d LIMIT 1",
            $conv_id
        ), ARRAY_A);

        if (!$conv) $this->json_err('Conversation not found.', 404);

        $msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, sender_name, message, created_at
             FROM {$t['messages']}
             WHERE conversation_id=%d
             ORDER BY id ASC
             LIMIT 500",
            $conv_id
        ), ARRAY_A);

        $this->json_ok(['conversation' => $conv, 'messages' => $msgs]);
    }

    public function ajax_admin_send_message() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
        $message = trim($message);

        if (!$conv_id) $this->json_err('Missing conversation_id.');
        if ($message === '') $this->json_err('Tin nhắn rỗng.');

        global $wpdb;
        $t = $this->tables();
        $now = current_time('mysql');

        $wpdb->insert($t['messages'], [
            'conversation_id' => $conv_id,
            'sender_role' => 'admin',
            'sender_name' => wp_get_current_user()->display_name,
            'message' => $message,
            'created_at' => $now,
        ]);

        $wpdb->update($t['conversations'], ['last_message_at' => $now], ['id' => $conv_id]);

        $this->json_ok(['message_id' => (int)$wpdb->insert_id]);
    }
}

new HomeNest_Live_Chat_Basic();
