<?php
/**
 * Plugin Name: HomeNest Live Chat Pro (Polling)
 * Description: Live chat widget + Admin/Agent inbox (assign, unread, tags, notes, close, upload, rate limit).
 * Version: 1.0.0
 * Author: HomeNest
 */

if (!defined('ABSPATH')) exit;

class HomeNest_Live_Chat_Pro {
    const VERSION = '1.0.0';
    const COOKIE_CONTACT = 'hn_chat_contact';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_action('wp_footer', [$this, 'render_widget']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);

        // Front AJAX
        add_action('wp_ajax_hn_chat_save_contact', [$this, 'ajax_save_contact']);
        add_action('wp_ajax_nopriv_hn_chat_save_contact', [$this, 'ajax_save_contact']);

        add_action('wp_ajax_hn_chat_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_nopriv_hn_chat_send_message', [$this, 'ajax_send_message']);

        add_action('wp_ajax_hn_chat_fetch_messages', [$this, 'ajax_fetch_messages']);
        add_action('wp_ajax_nopriv_hn_chat_fetch_messages', [$this, 'ajax_fetch_messages']);

        add_action('wp_ajax_hn_chat_upload', [$this, 'ajax_upload']);
        add_action('wp_ajax_nopriv_hn_chat_upload', [$this, 'ajax_upload']);

        // Admin/Agent AJAX
        add_action('wp_ajax_hn_chat_admin_list_conversations', [$this, 'ajax_admin_list_conversations']);
        add_action('wp_ajax_hn_chat_admin_get_conversation', [$this, 'ajax_admin_get_conversation']);
        add_action('wp_ajax_hn_chat_admin_send_message', [$this, 'ajax_admin_send_message']);
        add_action('wp_ajax_hn_chat_admin_mark_read', [$this, 'ajax_admin_mark_read']);
        add_action('wp_ajax_hn_chat_admin_set_status', [$this, 'ajax_admin_set_status']);
        add_action('wp_ajax_hn_chat_admin_assign', [$this, 'ajax_admin_assign']);
        add_action('wp_ajax_hn_chat_admin_set_note', [$this, 'ajax_admin_set_note']);
        add_action('wp_ajax_hn_chat_admin_set_tags', [$this, 'ajax_admin_set_tags']);
        add_action('wp_ajax_hn_chat_admin_list_agents', [$this, 'ajax_admin_list_agents']);

        add_action('wp_ajax_hn_chat_admin_upload', [$this, 'ajax_admin_upload']);
    }

    private function tables() {
        global $wpdb;
        return [
            'conversations' => $wpdb->prefix . 'hn_chat_conversations',
            'messages'      => $wpdb->prefix . 'hn_chat_messages',
        ];
    }

    public function on_activate() {
        // Role + caps
        add_role('hn_chat_agent', 'Chat Agent', [
            'read' => true,
            'manage_hn_chat' => true,
        ]);
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('manage_hn_chat')) {
            $admin->add_cap('manage_hn_chat');
        }

        // DB
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

            assigned_user_id BIGINT UNSIGNED NULL,
            unread_for_admin INT UNSIGNED NOT NULL DEFAULT 0,
            unread_for_guest INT UNSIGNED NOT NULL DEFAULT 0,
            last_read_msg_id_admin BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_msg_id_guest BIGINT UNSIGNED NOT NULL DEFAULT 0,

            tags LONGTEXT NULL,
            note LONGTEXT NULL,

            closed_at DATETIME NULL,
            closed_by BIGINT UNSIGNED NULL,

            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL,

            PRIMARY KEY (id),
            KEY visitor_token (visitor_token),
            KEY last_message_at (last_message_at),
            KEY assigned_user_id (assigned_user_id),
            KEY status (status)
        ) $charset;";

        $sql2 = "CREATE TABLE {$t['messages']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_role VARCHAR(20) NOT NULL, /* guest|agent|admin */
            sender_user_id BIGINT UNSIGNED NULL,
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

    /** ===================== Utilities ===================== */

    private function json_ok($data = []) { wp_send_json(['ok' => true, 'data' => $data]); }
    private function json_err($msg, $code = 400) { wp_send_json(['ok' => false, 'error' => $msg], $code); }

    private function rate_limit($key, $limit, $window_seconds) {
        $k = 'hn_rl_' . md5($key);
        $cur = (int) get_transient($k);
        if ($cur >= $limit) return false;
        set_transient($k, $cur + 1, $window_seconds);
        return true;
    }

    private function get_ip() {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
    }

    private function get_visitor_token() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $ip = $this->get_ip();
        return hash('sha256', $ip . '|' . $ua . '|' . wp_salt('nonce'));
    }

    private function detect_contact_type($contact) {
        $contact = trim($contact);
        if (is_email($contact)) return 'email';
        $digits = preg_replace('/[^0-9\+]/', '', $contact);
        $onlyNums = preg_replace('/\D/', '', $digits);
        if (strlen($onlyNums) >= 8) return 'phone';
        return 'unknown';
    }

    private function ensure_open_conversation($contact) {
        global $wpdb; $t = $this->tables();
        $token = $this->get_visitor_token();
        $now = current_time('mysql');

        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['conversations']}
             WHERE visitor_token=%s AND status='open'
             ORDER BY id DESC LIMIT 1",
            $token
        ));

        $contact_type = $this->detect_contact_type($contact);

        if ($conv) {
            // update contact
            $wpdb->update($t['conversations'], [
                'contact' => $contact,
                'contact_type' => $contact_type,
            ], ['id' => (int)$conv->id]);
            return (int)$conv->id;
        }

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

    private function admin_check() {
        if (!current_user_can('manage_hn_chat')) $this->json_err('No permission', 403);
        check_ajax_referer('hn_chat_admin_nonce', 'nonce');
    }

    private function can_access_conversation($conv_row) {
        // Admin sees all. Agent sees all by default (để team hỗ trợ), nhưng vẫn "ưu tiên Assigned to me" trong UI.
        // Nếu muốn agent chỉ thấy của mình: uncomment dưới.
        // if (current_user_can('manage_options')) return true;
        // return (int)$conv_row['assigned_user_id'] === get_current_user_id() || empty($conv_row['assigned_user_id']);

        return true;
    }

    /** ===================== Front (enqueue + widget) ===================== */

    public function enqueue_front() {
        if (is_admin()) return;

        wp_enqueue_style('hn-chat-css', plugin_dir_url(__FILE__) . 'assets/hn-chat.css', [], self::VERSION);
        wp_enqueue_script('hn-chat-js', plugin_dir_url(__FILE__) . 'assets/hn-chat.js', ['jquery'], self::VERSION, true);

        wp_localize_script('hn-chat-js', 'HNChat', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hn_chat_nonce'),
            'poll_ms' => 3000,
            'cookie_contact' => self::COOKIE_CONTACT,
            'max_upload_mb' => 5,
        ]);
    }

    public function render_widget() {
        if (is_admin()) return;
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
                    <label class="hn-chat__uploadBtn" title="Gửi file">
                        📎
                        <input type="file" class="hn-chat__file" hidden />
                    </label>
                    <input class="hn-chat__input" type="text" placeholder="Nhập tin nhắn..." />
                    <button class="hn-chat__send" type="button">Gửi</button>
                </div>
            </div>
        </div>
        <?php
    }

    /** ===================== Front AJAX ===================== */

    public function ajax_save_contact() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        if (!$contact) $this->json_err('Thiếu SĐT/Email.');

        $type = $this->detect_contact_type($contact);
        if ($type === 'unknown') $this->json_err('SĐT/Email không hợp lệ.');

        // Rate limit: contact save
        $ip = $this->get_ip();
        if (!$this->rate_limit("save_contact|$ip", 20, 300)) {
            $this->json_err('Bạn thao tác quá nhanh. Vui lòng thử lại sau.');
        }

        $conversation_id = $this->ensure_open_conversation($contact);
        $this->json_ok(['conversation_id' => $conversation_id, 'contact_type' => $type]);
    }

    public function ajax_send_message() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
        $message = trim($message);

        if (!$contact) $this->json_err('Thiếu contact.');
        if ($message === '') $this->json_err('Tin nhắn rỗng.');

        // Rate limit guest send
        $token = $this->get_visitor_token();
        $ip = $this->get_ip();
        if (!$this->rate_limit("guest_send|$ip|$token", 10, 30)) {
            $this->json_err('Bạn gửi quá nhanh. Vui lòng thử lại sau.');
        }

        global $wpdb; $t = $this->tables();
        $conversation_id = $this->ensure_open_conversation($contact);
        $now = current_time('mysql');

        $wpdb->insert($t['messages'], [
            'conversation_id' => $conversation_id,
            'sender_role' => 'guest',
            'sender_user_id' => null,
            'sender_name' => null,
            'message' => $message,
            'created_at' => $now,
        ]);
        $msg_id = (int)$wpdb->insert_id;

        // Update unread for admin (agent/admin)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t['conversations']}
             SET last_message_at=%s, unread_for_admin = unread_for_admin + 1
             WHERE id=%d",
            $now, $conversation_id
        ));

        $this->json_ok(['conversation_id' => $conversation_id, 'message_id' => $msg_id]);
    }

    public function ajax_fetch_messages() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';
        $after_id = isset($_POST['after_id']) ? absint($_POST['after_id']) : 0;
        if (!$contact) $this->json_err('Thiếu contact.');

        global $wpdb; $t = $this->tables();
        $token = $this->get_visitor_token();

        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t['conversations']}
             WHERE visitor_token=%s AND status='open'
             ORDER BY id DESC LIMIT 1",
            $token
        ), ARRAY_A);

        if (!$conv) $this->json_ok(['messages' => []]);

        $conv_id = (int)$conv['id'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, sender_name, message, created_at
             FROM {$t['messages']}
             WHERE conversation_id=%d AND id > %d
             ORDER BY id ASC
             LIMIT 200",
            $conv_id, $after_id
        ), ARRAY_A);

        // Mark read for guest (guest đọc hết tin admin/agent)
        $max_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM {$t['messages']} WHERE conversation_id=%d",
            $conv_id
        ));
        $wpdb->update($t['conversations'], [
            'unread_for_guest' => 0,
            'last_read_msg_id_guest' => $max_id,
        ], ['id' => $conv_id]);

        $this->json_ok(['messages' => $rows, 'conversation_id' => $conv_id]);
    }

    public function ajax_upload() {
        check_ajax_referer('hn_chat_nonce', 'nonce');

        if (empty($_FILES['file'])) $this->json_err('No file');

        // Rate limit upload
        $ip = $this->get_ip();
        if (!$this->rate_limit("upload|$ip", 10, 300)) {
            $this->json_err('Upload quá nhanh. Vui lòng thử lại sau.');
        }

        $file = $_FILES['file'];

        if (!empty($file['size']) && $file['size'] > 5 * 1024 * 1024) {
            $this->json_err('File quá lớn (tối đa 5MB).');
        }

        $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
        $type = $file['type'] ?? '';
        if (!in_array($type, $allowed, true)) {
            $this->json_err('Định dạng không hỗ trợ (jpg/png/webp/pdf).');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = ['test_form' => false];
        $uploaded = wp_handle_upload($file, $overrides);
        if (isset($uploaded['error'])) $this->json_err($uploaded['error']);

        $attachment = [
            'post_mime_type' => $uploaded['type'],
            'post_title'     => sanitize_file_name(basename($uploaded['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $uploaded['file']);
        $attach_data = wp_generate_attachment_metadata($attach_id, $uploaded['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        $url = wp_get_attachment_url($attach_id);
        $this->json_ok(['attachment_id' => (int)$attach_id, 'url' => $url, 'mime' => $uploaded['type']]);
    }

    /** ===================== Admin UI ===================== */

    public function admin_menu() {
        add_menu_page(
            'HomeNest Chat Live',
            'HomeNest Chat Live',
            'manage_hn_chat',
            'homenest-chat-live',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            58
        );
    }

    public function enqueue_admin($hook) {
        if ($hook !== 'toplevel_page_homenest-chat-live') return;

        wp_enqueue_style('hn-chat-admin-css', plugin_dir_url(__FILE__) . 'admin/admin.css', [], self::VERSION);
        wp_enqueue_script('hn-chat-admin-js', plugin_dir_url(__FILE__) . 'admin/admin.js', ['jquery'], self::VERSION, true);

        wp_localize_script('hn-chat-admin-js', 'HNChatAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hn_chat_admin_nonce'),
            'me' => get_current_user_id(),
            'is_admin' => current_user_can('manage_options') ? 1 : 0,
        ]);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_hn_chat')) return;

        echo '<div class="wrap"><h1>HomeNest Chat Live</h1>';

        echo '
        <div id="hn-admin-chat" class="hn-admin-chat">
            <div class="hn-admin-left">
                <div class="hn-admin-searchRow">
                    <input type="text" class="hn-admin-q" placeholder="Tìm theo SĐT/Email..." />
                </div>

                <div class="hn-admin-filters">
                    <button type="button" class="button hn-filter hn-filter--active" data-filter="all">Tất cả</button>
                    <button type="button" class="button hn-filter" data-filter="open">Đang mở</button>
                    <button type="button" class="button hn-filter" data-filter="closed">Đã đóng</button>
                    <button type="button" class="button hn-filter" data-filter="mine">Của tôi</button>
                </div>

                <div class="hn-admin-convList"></div>
            </div>

            <div class="hn-admin-right">
                <div class="hn-admin-topbar">
                    <div class="hn-admin-topbarLeft">
                        <div class="hn-admin-convTitle">Chọn một cuộc hội thoại</div>
                        <div class="hn-admin-convMeta"></div>
                    </div>

                    <div class="hn-admin-topbarRight">
                        <button type="button" class="button hn-btnAssignMe" disabled>Nhận hội thoại</button>
                        <select class="hn-admin-assignSelect" style="display:none;"></select>
                        <button type="button" class="button hn-btnToggleStatus" disabled>Đóng</button>
                    </div>
                </div>

                <div class="hn-admin-main">
                    <div class="hn-admin-messages"></div>

                    <div class="hn-admin-side">
                        <div class="hn-sideBlock">
                            <div class="hn-sideTitle">Tags (phân cách bằng dấu phẩy)</div>
                            <input type="text" class="hn-admin-tags" placeholder="vip, đơn hàng, báo giá..." disabled />
                            <button type="button" class="button hn-btnSaveTags" disabled>Lưu tags</button>
                        </div>

                        <div class="hn-sideBlock">
                            <div class="hn-sideTitle">Note nội bộ</div>
                            <textarea class="hn-admin-note" placeholder="Ghi chú nội bộ..." disabled></textarea>
                            <button type="button" class="button hn-btnSaveNote" disabled>Lưu note</button>
                        </div>
                    </div>
                </div>

                <div class="hn-admin-reply">
                    <label class="hn-admin-uploadBtn" title="Gửi file">
                        📎
                        <input type="file" class="hn-admin-file" hidden />
                    </label>
                    <input type="text" class="hn-admin-input" placeholder="Nhập tin nhắn trả lời..." disabled />
                    <button class="button button-primary hn-admin-send" disabled>Gửi</button>
                </div>
            </div>
        </div>';

        echo '</div>';
    }

    /** ===================== Admin AJAX ===================== */

    public function ajax_admin_list_agents() {
        $this->admin_check();

        // Admin: list all agents + admins
        // Agent: list only themselves (UI vẫn hiển thị assign me)
        if (!current_user_can('manage_options')) {
            $me = wp_get_current_user();
            $this->json_ok([
                'agents' => [[
                    'id' => (int)$me->ID,
                    'name' => $me->display_name,
                ]]
            ]);
        }

        $users = get_users([
            'role__in' => ['administrator', 'hn_chat_agent'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID','display_name'],
        ]);

        $agents = [];
        foreach ($users as $u) {
            $agents[] = ['id' => (int)$u->ID, 'name' => $u->display_name];
        }
        $this->json_ok(['agents' => $agents]);
    }

    public function ajax_admin_list_conversations() {
        $this->admin_check();

        global $wpdb; $t = $this->tables();
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $filter = isset($_POST['filter']) ? sanitize_text_field(wp_unslash($_POST['filter'])) : 'all';
        $me = get_current_user_id();

        $where = "1=1";
        $params = [];

        if ($q) {
            $where .= " AND contact LIKE %s";
            $params[] = '%' . $wpdb->esc_like($q) . '%';
        }

        if ($filter === 'open') {
            $where .= " AND status='open'";
        } elseif ($filter === 'closed') {
            $where .= " AND status='closed'";
        } elseif ($filter === 'mine') {
            $where .= " AND assigned_user_id=%d";
            $params[] = $me;
        }

        $sql = "SELECT id, contact, contact_type, status, assigned_user_id,
                       unread_for_admin, unread_for_guest,
                       last_message_at, created_at, tags
                FROM {$t['conversations']}
                WHERE {$where}
                ORDER BY COALESCE(last_message_at, created_at) DESC
                LIMIT 300";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
                        : $wpdb->get_results($sql, ARRAY_A);

        // no extra filtering in code, but keep capability hook if needed
        $this->json_ok(['conversations' => $rows]);
    }

    public function ajax_admin_get_conversation() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        if (!$conv_id) $this->json_err('Missing conversation_id.');

        global $wpdb; $t = $this->tables();

        $conv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['conversations']} WHERE id=%d LIMIT 1",
            $conv_id
        ), ARRAY_A);

        if (!$conv) $this->json_err('Conversation not found.', 404);
        if (!$this->can_access_conversation($conv)) $this->json_err('No permission', 403);

        $msgs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sender_role, sender_user_id, sender_name, message, created_at
             FROM {$t['messages']}
             WHERE conversation_id=%d
             ORDER BY id ASC
             LIMIT 1000",
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

        // Rate limit admin send
        $uid = get_current_user_id();
        if (!$this->rate_limit("admin_send|$uid", 30, 30)) {
            $this->json_err('Gửi quá nhanh.');
        }

        global $wpdb; $t = $this->tables();
        $now = current_time('mysql');

        // Determine role label
        $role = current_user_can('manage_options') ? 'admin' : 'agent';

        $wpdb->insert($t['messages'], [
            'conversation_id' => $conv_id,
            'sender_role' => $role,
            'sender_user_id' => $uid,
            'sender_name' => wp_get_current_user()->display_name,
            'message' => $message,
            'created_at' => $now,
        ]);
        $msg_id = (int)$wpdb->insert_id;

        // unread for guest ++
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t['conversations']}
             SET last_message_at=%s, unread_for_guest = unread_for_guest + 1
             WHERE id=%d",
            $now, $conv_id
        ));

        $this->json_ok(['message_id' => $msg_id]);
    }

    public function ajax_admin_mark_read() {
        $this->admin_check();
        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $last_msg_id = isset($_POST['last_msg_id']) ? absint($_POST['last_msg_id']) : 0;
        if (!$conv_id) $this->json_err('Missing conversation_id');

        global $wpdb; $t = $this->tables();
        $wpdb->update($t['conversations'], [
            'unread_for_admin' => 0,
            'last_read_msg_id_admin' => $last_msg_id
        ], ['id' => $conv_id]);

        $this->json_ok();
    }

    public function ajax_admin_set_status() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'open';

        if (!$conv_id) $this->json_err('Missing conversation_id');
        if (!in_array($status, ['open','closed'], true)) $this->json_err('Invalid status');

        global $wpdb; $t = $this->tables();

        $data = ['status' => $status];
        if ($status === 'closed') {
            $data['closed_at'] = current_time('mysql');
            $data['closed_by'] = get_current_user_id();
        } else {
            $data['closed_at'] = null;
            $data['closed_by'] = null;
        }

        $wpdb->update($t['conversations'], $data, ['id' => $conv_id]);
        $this->json_ok();
    }

    public function ajax_admin_assign() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$conv_id) $this->json_err('Missing conversation_id');
        if (!$user_id) $this->json_err('Missing user_id');

        // Agent chỉ được nhận cho chính mình
        if (!current_user_can('manage_options') && get_current_user_id() !== $user_id) {
            $this->json_err('Agents can only assign to themselves', 403);
        }

        global $wpdb; $t = $this->tables();
        $wpdb->update($t['conversations'], ['assigned_user_id' => $user_id], ['id' => $conv_id]);
        $this->json_ok(['assigned_user_id' => $user_id]);
    }

    public function ajax_admin_set_note() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $note = isset($_POST['note']) ? wp_kses_post(wp_unslash($_POST['note'])) : '';

        if (!$conv_id) $this->json_err('Missing conversation_id');

        global $wpdb; $t = $this->tables();
        $wpdb->update($t['conversations'], ['note' => $note], ['id' => $conv_id]);
        $this->json_ok();
    }

    public function ajax_admin_set_tags() {
        $this->admin_check();

        $conv_id = isset($_POST['conversation_id']) ? absint($_POST['conversation_id']) : 0;
        $tags = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';

        if (!$conv_id) $this->json_err('Missing conversation_id');

        // store as JSON array
        $arr = array_values(array_filter(array_map('trim', explode(',', $tags))));
        $json = wp_json_encode($arr);

        global $wpdb; $t = $this->tables();
        $wpdb->update($t['conversations'], ['tags' => $json], ['id' => $conv_id]);

        $this->json_ok(['tags' => $arr]);
    }

    public function ajax_admin_upload() {
    $this->admin_check();

    if (empty($_FILES['file'])) $this->json_err('No file');

    // Rate limit upload (admin)
    $uid = get_current_user_id();
    if (!$this->rate_limit("admin_upload|$uid", 20, 300)) {
        $this->json_err('Upload quá nhanh. Vui lòng thử lại sau.');
    }

    $file = $_FILES['file'];

    if (!empty($file['size']) && $file['size'] > 5 * 1024 * 1024) {
        $this->json_err('File quá lớn (tối đa 5MB).');
    }

    $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
    $type = $file['type'] ?? '';
    if (!in_array($type, $allowed, true)) {
        $this->json_err('Định dạng không hỗ trợ (jpg/png/webp/pdf).');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $overrides = ['test_form' => false];
    $uploaded = wp_handle_upload($file, $overrides);
    if (isset($uploaded['error'])) $this->json_err($uploaded['error']);

    $attachment = [
        'post_mime_type' => $uploaded['type'],
        'post_title'     => sanitize_file_name(basename($uploaded['file'])),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $uploaded['file']);
    $attach_data = wp_generate_attachment_metadata($attach_id, $uploaded['file']);
    wp_update_attachment_metadata($attach_id, $attach_data);

    $url = wp_get_attachment_url($attach_id);
    $this->json_ok(['attachment_id' => (int)$attach_id, 'url' => $url, 'mime' => $uploaded['type']]);
}

}

new HomeNest_Live_Chat_Pro();
