<?php
/**
 * Plugin Name: HomeNest Chat Live
 * Description: Live chat widget (AJAX polling) for WordPress. Shortcode: [homenest_chat_live]
 * Version: 1.0.0
 * Author: HomeNest
 * Text Domain: homenest-chat-live
 */

if (!defined('ABSPATH')) exit;

class HomeNest_Chat_Live {
    const VERSION = '1.0.0';
    const TABLE_SUFFIX = 'homenest_chat_messages';
    const COOKIE_CHAT_ID = 'hn_chat_id';
    const COOKIE_CHAT_CONTACT = 'hn_chat_contact';


    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'maybe_set_chat_cookie']);

        add_action('wp_footer', [$this, 'render_widget']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('admin_menu', [$this, 'register_admin_menu']);

        // AJAX handlers
        add_action('wp_ajax_hn_chat_send', [$this, 'ajax_send']);
        add_action('wp_ajax_nopriv_hn_chat_send', [$this, 'ajax_send']);

        add_action('wp_ajax_hn_chat_fetch', [$this, 'ajax_fetch']);
        add_action('wp_ajax_nopriv_hn_chat_fetch', [$this, 'ajax_fetch']);

        add_action('wp_ajax_hn_chat_set_contact', [$this, 'ajax_set_contact']);
        add_action('wp_ajax_nopriv_hn_chat_set_contact', [$this, 'ajax_set_contact']);

        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);

        add_action('wp_ajax_hn_admin_list_conversations', [$this, 'ajax_admin_list_conversations']);
        add_action('wp_ajax_hn_admin_fetch_messages', [$this, 'ajax_admin_fetch_messages']);
        add_action('wp_ajax_hn_admin_send_message', [$this, 'ajax_admin_send_message']);



    }

    public function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public function activate() {
        global $wpdb;
        $table = $this->table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,

            contact VARCHAR(191) NULL,
            contact_type VARCHAR(20) NULL,

            sender_type VARCHAR(20) NOT NULL,
            sender_id BIGINT UNSIGNED NULL,
            sender_name VARCHAR(191) NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY created_at (created_at)
        ) $charset_collate;";


        dbDelta($sql);
    }

    public function maybe_set_chat_cookie() {
        if (is_admin()) return;

        if (empty($_COOKIE[self::COOKIE_CHAT_ID])) {
            $chat_id = $this->generate_chat_id();
            // cookie 30 ngày
            setcookie(self::COOKIE_CHAT_ID, $chat_id, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE[self::COOKIE_CHAT_ID] = $chat_id;
        }

        if (!isset($_COOKIE[self::COOKIE_CHAT_CONTACT])) {
            // không set gì cũng được, chỉ đảm bảo tồn tại key khi localize
        }

    }

    private function generate_chat_id() {
        // uuid-ish (không cần tuyệt đối chuẩn)
        $bytes = bin2hex(random_bytes(16));
        return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-' . substr($bytes, 12, 4) . '-' . substr($bytes, 16, 4) . '-' . substr($bytes, 20);
    }

    public function enqueue_assets() {
        if (is_admin()) return;

        $base = plugin_dir_url(__FILE__) . 'assets/';

        wp_enqueue_style(
            'homenest-chat-live',
            $base . 'chat.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'homenest-chat-live',
            $base . 'chat.js',
            [],
            self::VERSION,
            true
        );

        $user = wp_get_current_user();
        $display_name = $user && $user->exists() ? $user->display_name : __('Guest', 'homenest-chat-live');

        wp_localize_script('homenest-chat-live', 'HN_CHAT', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hn_chat_nonce'),
            'chatId'  => isset($_COOKIE[self::COOKIE_CHAT_ID]) ? sanitize_text_field($_COOKIE[self::COOKIE_CHAT_ID]) : '',
            'meName'  => $display_name,
            'pollMs'  => 2000,
            'contact' => isset($_COOKIE[self::COOKIE_CHAT_CONTACT]) ? sanitize_text_field($_COOKIE[self::COOKIE_CHAT_CONTACT]) : '',
        ]);
    }


    private function page_has_shortcode($tag) {
        if (!is_singular()) return false;
        global $post;
        if (!$post || empty($post->post_content)) return false;
        return has_shortcode($post->post_content, $tag);
    }

    public function shortcode() {
        // Widget container
        ob_start(); ?>
        <div id="hn-chat-root" class="hn-chat-root" aria-live="polite">
            <button type="button" class="hn-chat-toggle" aria-expanded="false">
                <span class="hn-chat-toggle-dot"></span>
                <span class="hn-chat-toggle-text">Chat</span>
            </button>

            <div class="hn-chat-panel" hidden>
                <div class="hn-chat-header">
                    <div class="hn-chat-title">HomeNest Chat Live</div>
                    <button type="button" class="hn-chat-close" aria-label="Close">×</button>
                </div>

                <div class="hn-chat-gate" hidden>
                    <div class="hn-chat-gate-title">Vui lòng nhập SĐT hoặc Email để tiếp tục thao tác này.</div>

                    <form class="hn-chat-gate-form">
                        <input class="hn-chat-gate-input" type="text" placeholder="Số điện thoại hoặc Email" maxlength="100" />
                        <button class="hn-chat-gate-submit" type="submit">Gửi</button>
                    </form>

                    <div class="hn-chat-gate-error" hidden></div>
                </div>


                <div class="hn-chat-messages" role="log"></div>

                <form class="hn-chat-form" autocomplete="off">
                    <input class="hn-chat-input" type="text" placeholder="Nhập tin nhắn..." maxlength="500" />
                    <button class="hn-chat-send" type="submit">Gửi</button>
                </form>
                <div class="hn-chat-hint">Reatime theo kiểu polling (2s/lần)</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }


    public function render_widget() {
        if (is_admin()) return;

        // (tuỳ chọn) Ẩn chat ở cart / checkout
        // if (is_cart() || is_checkout()) return;

        echo $this->shortcode();
    }



    public function register_admin_menu() {
        add_menu_page(
            'HomeNest Chat Live',
            'HomeNest Chat Live',
            'manage_options',
            'homenest-chat-live',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            56
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die('No permission');

        echo '<div class="wrap hn-admin-wrap">';
        echo '<h1>HomeNest Chat Live</h1>';

        echo '
        <div class="hn-admin-messenger" id="hn-admin-messenger">
        <div class="hn-admin-left">
            <div class="hn-admin-search">
            <input type="text" id="hnAdminSearch" placeholder="Search..." />
            </div>
            <div class="hn-admin-conv-list" id="hnAdminConvList"></div>
        </div>

        <div class="hn-admin-right">
            <div class="hn-admin-chat-head">
            <div>
                <div class="hn-admin-chat-title" id="hnAdminChatTitle">Chọn một cuộc chat</div>
                <div class="hn-admin-chat-sub" id="hnAdminChatSub"></div>
            </div>
            </div>

            <div class="hn-admin-chat-body" id="hnAdminChatBody"></div>

            <form class="hn-admin-chat-form" id="hnAdminChatForm">
            <input type="text" id="hnAdminChatInput" placeholder="Type a message..." maxlength="500" disabled />
            <button class="button button-primary" type="submit" disabled>Send</button>
            </form>
        </div>
        </div>
        ';

        echo '</div>';
    }





    public function ajax_send() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact_cookie = isset($_COOKIE[self::COOKIE_CHAT_CONTACT]) ? sanitize_text_field($_COOKIE[self::COOKIE_CHAT_CONTACT]) : '';
        if ($contact_cookie === '') {
            wp_send_json_error(['message' => 'Bạn cần nhập SĐT hoặc Email trước khi chat'], 403);
        }


        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if ($chat_id === '' || $message === '') {
            wp_send_json_error(['message' => 'Thiếu chat_id hoặc message'], 400);
        }

        $user = wp_get_current_user();
        $sender_type = ($user && $user->exists()) ? 'user' : 'guest';
        $sender_id   = ($user && $user->exists()) ? (int)$user->ID : null;
        $sender_name = ($user && $user->exists()) ? $user->display_name : 'Guest';

        global $wpdb;
        $table = $this->table_name();

        $ok = $wpdb->insert($table, [
            'chat_id'      => $chat_id,
            'sender_type'  => $sender_type,
            'sender_id'    => $sender_id,
            'sender_name'  => $sender_name,
            'message'      => $message,
            'created_at'   => current_time('mysql'),
        ], [
            '%s','%s','%d','%s','%s','%s'
        ]);

        if (!$ok) {
            wp_send_json_error(['message' => 'Không lưu được tin nhắn'], 500);
        }

        wp_send_json_success([
            'id' => (int)$wpdb->insert_id
        ]);
    }

    public function ajax_fetch() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        $after_id = isset($_POST['after_id']) ? (int)$_POST['after_id'] : 0;

        if ($chat_id === '') {
            wp_send_json_error(['message' => 'Thiếu chat_id'], 400);
        }

        global $wpdb;
        $table = $this->table_name();

        // Lấy tin nhắn mới hơn after_id
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_type, sender_name, message, created_at
                 FROM $table
                 WHERE chat_id = %s AND id > %d
                 ORDER BY id ASC
                 LIMIT 100",
                $chat_id,
                $after_id
            ),
            ARRAY_A
        );

        wp_send_json_success([
            'messages' => $rows ?: []
        ]);
    }


    public function ajax_set_contact() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        $contact = isset($_POST['contact']) ? sanitize_text_field($_POST['contact']) : '';

        if ($chat_id === '' || $contact === '') {
            wp_send_json_error(['message' => 'Thiếu chat_id hoặc contact'], 400);
        }

        $contact = trim($contact);

        // Check email
        if (is_email($contact)) {
            $type = 'email';
            $normalized = strtolower($contact);
        } else {
            // Check phone
            $digits = preg_replace('/\D+/', '', $contact);
            if (strlen($digits) < 9 || strlen($digits) > 15) {
                wp_send_json_error(['message' => 'Vui lòng nhập đúng SĐT hoặc Email'], 400);
            }
            $type = 'phone';
            $normalized = $digits;
        }

        // Lưu cookie 30 ngày
        setcookie(
            self::COOKIE_CHAT_CONTACT,
            $normalized,
            time() + 30 * DAY_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN
        );
        $_COOKIE[self::COOKIE_CHAT_CONTACT] = $normalized;

        global $wpdb;
        $table = $this->table_name();

        // Update contact cho chat_id (MVP)
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET contact=%s, contact_type=%s WHERE chat_id=%s",
                $normalized,
                $type,
                $chat_id
            )
        );

        wp_send_json_success([
            'contact' => $normalized,
            'type'    => $type
        ]);
    }



    private function admin_guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No permission'], 403);
        }
    }

    public function ajax_admin_list_conversations() {
        $this->admin_guard();
        check_ajax_referer('hn_chat_admin_nonce', 'nonce');

        global $wpdb;
        $table = $this->table_name();

        // Lấy danh sách hội thoại + last message + last time
        $rows = $wpdb->get_results(
            "SELECT chat_id,
                    MAX(id) AS last_id,
                    MAX(created_at) AS last_time,
                    MAX(COALESCE(contact,'')) AS contact_any
            FROM $table
            GROUP BY chat_id
            ORDER BY last_time DESC
            LIMIT 200",
            ARRAY_A
        );

        // Lấy preview last message cho từng chat (nhanh gọn: query phụ theo last_id)
        $previews = [];
        if (!empty($rows)) {
            $lastIds = array_map(fn($r) => (int)$r['last_id'], $rows);
            $lastIds = array_filter($lastIds);
            if ($lastIds) {
                $in = implode(',', array_map('intval', $lastIds));
                $msgs = $wpdb->get_results("SELECT id, chat_id, message, sender_type FROM $table WHERE id IN ($in)", ARRAY_A);
                foreach ($msgs as $m) $previews[(int)$m['id']] = $m;
            }
        }

        foreach ($rows as &$r) {
            $p = $previews[(int)$r['last_id']] ?? null;
            $r['last_message'] = $p ? $p['message'] : '';
            $r['last_sender_type'] = $p ? $p['sender_type'] : '';
        }

        wp_send_json_success(['conversations' => $rows ?: []]);
    }

    public function ajax_admin_fetch_messages() {
        $this->admin_guard();
        check_ajax_referer('hn_chat_admin_nonce', 'nonce');

        $chat_id  = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        $after_id = isset($_POST['after_id']) ? (int)$_POST['after_id'] : 0;

        if ($chat_id === '') wp_send_json_error(['message' => 'Missing chat_id'], 400);

        global $wpdb;
        $table = $this->table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, sender_type, sender_name, message, created_at, contact, contact_type
                FROM $table
                WHERE chat_id=%s AND id>%d
                ORDER BY id ASC
                LIMIT 200",
                $chat_id, $after_id
            ),
            ARRAY_A
        );

        wp_send_json_success(['messages' => $rows ?: []]);
    }

    public function ajax_admin_send_message() {
        $this->admin_guard();
        check_ajax_referer('hn_chat_admin_nonce', 'nonce');

        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field($_POST['chat_id']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';

        if ($chat_id === '' || $message === '') {
            wp_send_json_error(['message' => 'Missing chat_id/message'], 400);
        }

        global $wpdb;
        $table = $this->table_name();

        $user = wp_get_current_user();
        $admin_name = $user && $user->exists() ? $user->display_name : 'Admin';

        $ok = $wpdb->insert($table, [
            'chat_id'     => $chat_id,
            'sender_type' => 'admin',
            'sender_id'   => $user && $user->exists() ? (int)$user->ID : null,
            'sender_name' => $admin_name,
            'message'     => $message,
            'created_at'  => current_time('mysql'),
        ], ['%s','%s','%d','%s','%s','%s']);

        if (!$ok) wp_send_json_error(['message' => 'Insert failed'], 500);

        wp_send_json_success(['id' => (int)$wpdb->insert_id]);
    }


}

new HomeNest_Chat_Live();
