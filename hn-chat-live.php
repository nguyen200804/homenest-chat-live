<?php
/**
 * Plugin Name: HN Chat Live
 * Description: Live chat plugin (simple polling) - HN Chat Live by Nguyên.
 * Version:     1.1.0
 * Author:      Nguyên
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class HN_Chat_Live {
  const VERSION = '1.1.0';
  const TABLE_MESSAGES = 'hn_chat_live_messages';
  const TABLE_SESSIONS = 'hn_chat_live_sessions';

  public function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
  }

  public function admin_rest_sessions(WP_REST_Request $req) {
  global $wpdb;
  $ts = $this->t_sessions();
  $tm = $this->t_messages();

  $q = trim((string)$req->get_param('q'));
  $q_like = '%' . $wpdb->esc_like($q) . '%';

  // Lấy list session + last message
  $sql = "
    SELECT 
      s.session_id,
      s.updated_at,
      s.name,
      s.phone,
      s.email,
      (SELECT m.message FROM {$tm} m WHERE m.session_id = s.session_id ORDER BY m.id DESC LIMIT 1) AS last_message,
      (SELECT m.created_at FROM {$tm} m WHERE m.session_id = s.session_id ORDER BY m.id DESC LIMIT 1) AS last_time
    FROM {$ts} s
  ";

  if ($q !== '') {
    $sql .= $wpdb->prepare(" WHERE s.name LIKE %s OR s.phone LIKE %s OR s.email LIKE %s ", $q_like, $q_like, $q_like);
  }

  $sql .= " ORDER BY COALESCE(last_time, s.updated_at) DESC LIMIT 200";

  $rows = $wpdb->get_results($sql, ARRAY_A);

  return new WP_REST_Response(['ok' => true, 'sessions' => $rows], 200);
}

public function admin_rest_messages(WP_REST_Request $req) {
  global $wpdb;
  $tm = $this->t_messages();

  $session_id = sanitize_text_field((string)$req->get_param('session_id'));
  $limit = absint($req->get_param('limit'));
  if ($limit <= 0 || $limit > 500) $limit = 200;

  if ($session_id === '') {
    return new WP_REST_Response(['ok' => false, 'error' => 'Missing session_id'], 400);
  }

  $rows = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT id, created_at, user_name, role, message
       FROM {$tm}
       WHERE session_id = %s
       ORDER BY id ASC
       LIMIT %d",
      $session_id,
      $limit
    ),
    ARRAY_A
  );

  return new WP_REST_Response(['ok' => true, 'messages' => $rows], 200);
}

public function admin_rest_reply(WP_REST_Request $req) {
  global $wpdb;
  $tm = $this->t_messages();
  $ts = $this->t_sessions();

  $params = $req->get_json_params();
  if (!is_array($params)) $params = [];

  $session_id = isset($params['session_id']) ? sanitize_text_field($params['session_id']) : '';
  $message = isset($params['message']) ? trim(wp_unslash($params['message'])) : '';

  if ($session_id === '' || $message === '') {
    return new WP_REST_Response(['ok' => false, 'error' => 'Thiếu session hoặc tin nhắn'], 400);
  }

  $message_clean = wp_strip_all_tags($message);
  $message_clean = preg_replace("/\r\n|\r/", "\n", $message_clean);

  $user = wp_get_current_user();
  $admin_name = $user->display_name ?: 'Admin';

  $ok = $wpdb->insert(
    $tm,
    [
      'created_at' => current_time('mysql'),
      'session_id' => $session_id,
      'user_id' => (int)$user->ID,
      'user_name' => $admin_name,
      'role' => 'admin',
      'message' => $message_clean,
    ],
    ['%s','%s','%d','%s','%s','%s']
  );

  // cập nhật updated_at của session
  $wpdb->update(
    $ts,
    ['updated_at' => current_time('mysql')],
    ['session_id' => $session_id],
    ['%s'],
    ['%s']
  );

  if (!$ok) return new WP_REST_Response(['ok' => false, 'error' => 'Không gửi được'], 500);

  return new WP_REST_Response(['ok' => true], 200);
}


  private function t_messages() {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_MESSAGES;
  }
  private function t_sessions() {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SESSIONS;
  }

  public function activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    $tm = $this->t_messages();
    $ts = $this->t_sessions();

    $sql_sessions = "CREATE TABLE {$ts} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      session_id VARCHAR(64) NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      user_id BIGINT(20) UNSIGNED NULL,
      name VARCHAR(190) NULL,
      phone VARCHAR(40) NULL,
      email VARCHAR(190) NULL,
      ip VARCHAR(45) NULL,
      user_agent VARCHAR(255) NULL,
      PRIMARY KEY (id),
      UNIQUE KEY session_id (session_id),
      KEY user_id (user_id),
      KEY updated_at (updated_at)
    ) {$charset_collate};";

    $sql_messages = "CREATE TABLE {$tm} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      session_id VARCHAR(64) NOT NULL,
      user_id BIGINT(20) UNSIGNED NULL,
      user_name VARCHAR(190) NULL,
      role VARCHAR(30) NOT NULL DEFAULT 'guest',
      message TEXT NOT NULL,
      PRIMARY KEY (id),
      KEY session_id (session_id),
      KEY created_at (created_at),
      KEY user_id (user_id)
    ) {$charset_collate};";

    dbDelta($sql_sessions);
    dbDelta($sql_messages);

    add_option('hn_chat_live_version', self::VERSION);
  }

  public function register_assets() {
    $url = plugin_dir_url(__FILE__);

    wp_register_style('hn-chat-live', $url . 'assets/chat.css', [], self::VERSION);

    wp_register_script('hn-chat-live', $url . 'assets/chat.js', [], self::VERSION, true);
  }

  public function register_shortcode() {
    add_shortcode('hn_chat_live', [$this, 'render_shortcode']);
  }

  private function get_session_id() {
    $cookie = 'hncl_session';
    if (!empty($_COOKIE[$cookie]) && preg_match('/^[a-f0-9]{32}$/', $_COOKIE[$cookie])) {
      return sanitize_text_field($_COOKIE[$cookie]);
    }
    $sid = md5(wp_generate_uuid4() . '|' . microtime(true));
    setcookie($cookie, $sid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE[$cookie] = $sid;
    return $sid;
  }

  private function ensure_session_row($session_id) {
    global $wpdb;
    $ts = $this->t_sessions();

    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$ts} WHERE session_id=%s", $session_id),
      ARRAY_A
    );
    if ($row) return $row;

    $user = wp_get_current_user();
    $user_id = is_user_logged_in() ? (int)$user->ID : null;

    $wpdb->insert(
      $ts,
      [
        'session_id' => $session_id,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'user_id' => $user_id,
        'name' => $user_id ? ($user->display_name ?: $user->user_login) : null,
        'email' => $user_id ? $user->user_email : null,
        'phone' => null,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '',
      ],
      ['%s','%s','%s','%d','%s','%s','%s','%s','%s']
    );

    return $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$ts} WHERE session_id=%s", $session_id),
      ARRAY_A
    );
  }

  public function render_shortcode($atts = []) {
    wp_enqueue_style('hn-chat-live');
    wp_enqueue_script('hn-chat-live');

    $session_id = $this->get_session_id();
    $session = $this->ensure_session_row($session_id);

    $has_lead = (!empty($session['name']) && !empty($session['phone']));

    $config = [
      'restUrl' => esc_url_raw(rest_url('hn-chat-live/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'sessionId' => $session_id,
      'pollIntervalMs' => 2000,
      'maxMessages' => 60,
      'hasLead' => $has_lead,
      'brandName' => 'HN Chat Live',
      'welcomeTitle' => 'Chào Bạn 👋',
      'welcomeSub' => 'Hãy hỏi Nguyên bất cứ điều gì ✨',
    ];
    wp_localize_script('hn-chat-live', 'HNChatLive', $config);

    ob_start();
    ?>
    <div class="hncl" data-hncl-root>
      <!-- (1) Launcher -->
      <button class="hncl-launcher" type="button" data-hncl-open>
        <span class="hncl-launcher__label">Trò chuyện 👋</span>
        <span class="hncl-launcher__bubble" aria-hidden="true">
          <span class="hncl-launcher__icon"></span>
        </span>
      </button>

      <!-- Panel -->
      <div class="hncl-panel" data-hncl-panel aria-hidden="true">
        <div class="hncl-panel__inner">
          <div class="hncl-topbar">
            <div class="hncl-topbar__title"><?php echo esc_html('Chào Bạn 👋'); ?></div>
            <button class="hncl-topbar__close" type="button" data-hncl-close aria-label="Close">×</button>
          </div>

          <!-- (2) Welcome screen -->
          <div class="hncl-screen hncl-screen--welcome" data-hncl-screen="welcome">
            <div class="hncl-welcome">
              <div class="hncl-welcome__title" data-hncl-welcome-title></div>
              <div class="hncl-welcome__sub" data-hncl-welcome-sub></div>

              <button class="hncl-btn hncl-btn--primary" type="button" data-hncl-start-chat>
                Trò chuyện
              </button>
              <div class="hncl-note">One House style UI demo (custom)</div>
            </div>
          </div>

          <!-- (3) Chat screen -->
          <div class="hncl-screen hncl-screen--chat" data-hncl-screen="chat" hidden>
            <div class="hncl-messages" data-hncl-messages></div>

            <div class="hncl-composer">
              <input class="hncl-input" type="text" placeholder="Nhập tin nhắn..." maxlength="800" data-hncl-input>
              <button class="hncl-send" type="button" data-hncl-send aria-label="Send">
                <span class="hncl-send__icon">➤</span>
              </button>
            </div>

            <div class="hncl-status" data-hncl-status>Online</div>
          </div>

          <!-- (4) Lead form overlay -->
          <div class="hncl-lead" data-hncl-lead hidden>
            <div class="hncl-lead__card">
              <div class="hncl-lead__title">Kết nối với Nguyên</div>

              <div class="hncl-lead__fields">
                <input class="hncl-input" type="text" placeholder="Tên của Bạn..." maxlength="60" data-hncl-lead-name>
                <input class="hncl-input" type="tel" placeholder="Số điện thoại..." maxlength="20" data-hncl-lead-phone>
              </div>

              <button class="hncl-btn hncl-btn--primary" type="button" data-hncl-lead-submit>
                Send
              </button>
              <div class="hncl-lead__hint">Điền xong để gửi tin nhắn của bạn.</div>
            </div>
          </div>

        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  public function register_rest_routes() {
    register_rest_route('hn-chat-live/v1', '/messages', [
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [$this, 'rest_get_messages'],
        'permission_callback' => '__return_true',
      ],
      [
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'rest_send_message'],
        'permission_callback' => '__return_true',
      ],
    ]);

    register_rest_route('hn-chat-live/v1', '/lead', [
      [
        'methods'  => WP_REST_Server::CREATABLE,
        'callback' => [$this, 'rest_save_lead'],
        'permission_callback' => '__return_true',
      ],
      [
        'methods'  => WP_REST_Server::READABLE,
        'callback' => [$this, 'rest_get_lead'],
        'permission_callback' => '__return_true',
      ],
    ]);

    register_rest_route('hn-chat-live/v1/admin', '/sessions', [
  'methods'  => WP_REST_Server::READABLE,
  'callback' => [$this, 'admin_rest_sessions'],
  'permission_callback' => function () { return current_user_can('manage_options'); },
]);

register_rest_route('hn-chat-live/v1/admin', '/messages', [
  'methods'  => WP_REST_Server::READABLE,
  'callback' => [$this, 'admin_rest_messages'],
  'permission_callback' => function () { return current_user_can('manage_options'); },
]);

register_rest_route('hn-chat-live/v1/admin', '/reply', [
  'methods'  => WP_REST_Server::CREATABLE,
  'callback' => [$this, 'admin_rest_reply'],
  'permission_callback' => function () { return current_user_can('manage_options'); },
]);

  }

  public function rest_get_messages(WP_REST_Request $req) {
    global $wpdb;
    $tm = $this->t_messages();

    $since_id = absint($req->get_param('since_id'));
    $limit = absint($req->get_param('limit'));
    if ($limit <= 0 || $limit > 200) $limit = 60;

    if ($since_id > 0) {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, created_at, user_name, role, message
           FROM {$tm}
           WHERE id > %d
           ORDER BY id ASC
           LIMIT %d",
          $since_id,
          $limit
        ),
        ARRAY_A
      );
    } else {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, created_at, user_name, role, message
           FROM {$tm}
           ORDER BY id DESC
           LIMIT %d",
          $limit
        ),
        ARRAY_A
      );
      $rows = array_reverse($rows);
    }

    return new WP_REST_Response(['ok' => true, 'messages' => $rows], 200);
  }

  public function rest_get_lead(WP_REST_Request $req) {
    $session_id = $this->get_session_id();
    $session = $this->ensure_session_row($session_id);
    $has_lead = (!empty($session['name']) && !empty($session['phone']));

    return new WP_REST_Response([
      'ok' => true,
      'hasLead' => $has_lead,
      'name' => $session['name'],
      'phone' => $session['phone'],
    ], 200);
  }

  public function rest_save_lead(WP_REST_Request $req) {
    global $wpdb;

    $session_id = $this->get_session_id();
    $this->ensure_session_row($session_id);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = [];

    $name = isset($params['name']) ? sanitize_text_field(wp_unslash($params['name'])) : '';
    $phone = isset($params['phone']) ? sanitize_text_field(wp_unslash($params['phone'])) : '';

    $name = trim($name);
    $phone = trim($phone);

    if ($name === '' || mb_strlen($name) > 60) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Vui lòng nhập tên hợp lệ.'], 400);
    }
    if ($phone === '' || mb_strlen($phone) > 20) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Vui lòng nhập SĐT hợp lệ.'], 400);
    }

    $ts = $this->t_sessions();

    $wpdb->update(
      $ts,
      [
        'name' => $name,
        'phone' => $phone,
        'updated_at' => current_time('mysql'),
      ],
      ['session_id' => $session_id],
      ['%s','%s','%s'],
      ['%s']
    );

    return new WP_REST_Response(['ok' => true, 'hasLead' => true], 200);
  }

  public function rest_send_message(WP_REST_Request $req) {
    global $wpdb;

    $params = $req->get_json_params();
    if (!is_array($params)) $params = [];

    $message = isset($params['message']) ? trim(wp_unslash($params['message'])) : '';
    if ($message === '' || mb_strlen($message) > 800) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Tin nhắn không hợp lệ.'], 400);
    }

    $session_id = $this->get_session_id();
    $session = $this->ensure_session_row($session_id);

    $user = wp_get_current_user();
    $user_id = is_user_logged_in() ? (int)$user->ID : null;

    // name: ưu tiên lead (nếu có), fallback user/guest
    $name = '';
    $role = 'guest';

    if (!empty($session['name'])) {
      $name = $session['name'];
      $role = $user_id ? 'user' : 'guest';
    } else if ($user_id) {
      $name = $user->display_name ?: $user->user_login;
      $role = 'user';
    } else {
      $name = 'Guest';
      $role = 'guest';
    }

    $tm = $this->t_messages();

    $message_clean = wp_strip_all_tags($message);
    $message_clean = preg_replace("/\r\n|\r/", "\n", $message_clean);

    $ok = $wpdb->insert(
      $tm,
      [
        'created_at' => current_time('mysql'),
        'session_id' => $session_id,
        'user_id' => $user_id,
        'user_name' => $name,
        'role' => $role,
        'message' => $message_clean,
      ],
      ['%s','%s','%d','%s','%s','%s']
    );

    if (!$ok) {
      return new WP_REST_Response(['ok' => false, 'error' => 'Không lưu được tin nhắn.'], 500);
    }

    $id = (int)$wpdb->insert_id;

    return new WP_REST_Response([
      'ok' => true,
      'message' => [
        'id' => $id,
        'created_at' => current_time('mysql'),
        'user_name' => $name,
        'role' => $role,
        'message' => $message_clean,
      ],
    ], 200);
  }

  public function admin_menu() {
    add_menu_page(
      'HN Chat Live',
      'HN Chat Live',
      'manage_options',
      'hn-chat-live',
      [$this, 'admin_page'],
      'dashicons-format-chat',
      58
    );
  }

public function admin_page() {
  if (!current_user_can('manage_options')) return;

  echo '<div class="wrap hncl-admin">';
  echo '<h1 class="hncl-admin__title">HN Chat Live</h1>';

  echo '
  <div class="hncl-wa">
    <div class="hncl-wa__left">
      <div class="hncl-wa__search">
        <input type="text" placeholder="Tìm tên / SĐT / email..." data-hncl-search>
      </div>
      <div class="hncl-wa__list" data-hncl-sessions></div>
    </div>

    <div class="hncl-wa__right">
      <div class="hncl-wa__header">
        <div>
          <div class="hncl-wa__name" data-hncl-active-name>Chọn một hội thoại</div>
          <div class="hncl-wa__meta" data-hncl-active-meta></div>
        </div>
      </div>

      <div class="hncl-wa__messages" data-hncl-messages></div>

      <div class="hncl-wa__composer">
        <textarea rows="1" placeholder="Nhập tin nhắn..." data-hncl-reply disabled></textarea>
        <button class="button button-primary" data-hncl-send disabled>Gửi</button>
      </div>
    </div>
  </div>';

  echo '</div>';
}


  public function admin_assets($hook) {
    // Chỉ load trên trang plugin của mình
    if ($hook !== 'toplevel_page_hn-chat-live') return;

    $url = plugin_dir_url(__FILE__);
    wp_enqueue_style('hncl-admin', $url . 'assets/admin.css', [], self::VERSION);
    wp_enqueue_script('hncl-admin', $url . 'assets/admin.js', [], self::VERSION, true);

    wp_localize_script('hncl-admin', 'HNCLAdmin', [
      'restUrl' => esc_url_raw(rest_url('hn-chat-live/v1/admin')),
      'nonce' => wp_create_nonce('wp_rest'),
    ]);
  }

}

new HN_Chat_Live();
