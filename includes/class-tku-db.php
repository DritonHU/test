<?php
if (!defined('ABSPATH')) exit;

class TKU_DB {
    public static function init() {
        // Ensure new options exist on upgrades
        add_option('tku_retention_enabled', 0);
        add_option('tku_retention_action', 'anonymize');
        add_option('tku_retention_days', 180);
        add_option('tku_retention_statuses', ['closed']);

        // Ensure cron exists (even after updates)
        if (!wp_next_scheduled('tku_retention_cron')) {
            wp_schedule_event(time() + 300, 'daily', 'tku_retention_cron');
        }

        // Best-effort DB upgrade (adds anonymized_at column on older installs)
        self::maybe_upgrade_schema();
    }

    public static function table_cases() {
        global $wpdb;
        return $wpdb->prefix . 'tku_cases';
    }
    public static function table_events() {
        global $wpdb;
        return $wpdb->prefix . 'tku_case_events';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $cases = self::table_cases();
        $events = self::table_events();

        $sql1 = "CREATE TABLE $cases (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id VARCHAR(32) NOT NULL,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NOT NULL,
            deceased_name VARCHAR(190) NULL,
            relative_name VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            token_hash VARCHAR(64) NULL,
            token_expires_at DATETIME NULL,
            step1 LONGTEXT NULL,
            step2 LONGTEXT NULL,
            step3 LONGTEXT NULL,
            submitted_at DATETIME NULL,
            anonymized_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY case_id (case_id),
            KEY email (email),
            KEY status (status),
            KEY deceased_name (deceased_name),
            KEY relative_name (relative_name)
        ) $charset;";

        $sql2 = "CREATE TABLE $events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id VARCHAR(32) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            message LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY case_id (case_id),
            KEY event_type (event_type)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);

        update_option('tku_db_version', defined('TKU_VERSION') ? TKU_VERSION : '0.6.0');

        // Create default Continue page if not exists
        $opt = get_option('tku_continue_page_id');
        if (!$opt || !get_post($opt)) {
            $existing = get_page_by_path('ugyintezes-folytatasa');
            if (!$existing) {
                $page_id = wp_insert_post([
                    'post_title'   => 'Ügyintézés folytatása',
                    'post_name'    => 'ugyintezes-folytatasa',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '[tku_case_continue]'
                ]);
            } else {
                $page_id = $existing->ID;
                if (strpos((string) $existing->post_content, '[tku_case_continue]') === false) {
                    wp_update_post([
                        'ID' => $page_id,
                        'post_content' => $existing->post_content . "\n\n[tku_case_continue]\n"
                    ]);
                }
            }
            if (!is_wp_error($page_id) && $page_id) {
                update_option('tku_continue_page_id', (int) $page_id);
            }
        }

        // Defaults
        add_option('tku_token_days', 30);
        add_option('tku_rl_start_per_hour', 5);
        add_option('tku_rl_status_per_hour', 20);

        add_option('tku_turnstile_enabled', 0);
        add_option('tku_turnstile_site_key', '');
        add_option('tku_turnstile_secret_key', '');

        add_option('tku_notify_enabled', 0);
        add_option('tku_notify_statuses', ['in_progress','need_more','processing','closed']);
        add_option('tku_notify_subject', 'Ügyintézés státusz frissült: {case_id}');
        add_option('tku_notify_body', '<p>Kedves {name}!</p><p>Az ügyed státusza frissült: <strong>{status_label}</strong></p>{admin_message_block}<p>Ügyazonosító: <strong>{case_id}</strong></p>');

        add_option('tku_status_require_phone_last4', 0);

        // GDPR retention / anonymization
        add_option('tku_retention_enabled', 0);
        add_option('tku_retention_action', 'anonymize'); // anonymize|delete
        add_option('tku_retention_days', 180);
        add_option('tku_retention_statuses', ['closed']);

        // Schedule daily retention job
        if (!wp_next_scheduled('tku_retention_cron')) {
            wp_schedule_event(time() + 300, 'daily', 'tku_retention_cron');
        }


    }

    public static function deactivate() {
        // Unschedule cron
        wp_clear_scheduled_hook('tku_retention_cron');
    }


    private static function maybe_upgrade_schema() {
        global $wpdb;
        $cases = self::table_cases();

        // Only if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cases));
        if (!$table_exists) return;

        $missing = false;
        foreach (['anonymized_at','deceased_name','relative_name'] as $col) {
            $has = $wpdb->get_var("SHOW COLUMNS FROM $cases LIKE '" . esc_sql($col) . "'");
            if (!$has) { $missing = true; break; }
        }
        if (!$missing) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $events = self::table_events();

        $sql1 = "CREATE TABLE $cases (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id VARCHAR(32) NOT NULL,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NOT NULL,
            deceased_name VARCHAR(190) NULL,
            relative_name VARCHAR(190) NULL,
            phone VARCHAR(50) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            token_hash VARCHAR(64) NULL,
            token_expires_at DATETIME NULL,
            step1 LONGTEXT NULL,
            step2 LONGTEXT NULL,
            step3 LONGTEXT NULL,
            submitted_at DATETIME NULL,
            anonymized_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY case_id (case_id),
            KEY email (email),
            KEY status (status),
            KEY deceased_name (deceased_name),
            KEY relative_name (relative_name)
        ) $charset;";

        $sql2 = "CREATE TABLE $events (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id VARCHAR(32) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            message LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY case_id (case_id),
            KEY event_type (event_type)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);

        update_option('tku_db_version', defined('TKU_VERSION') ? TKU_VERSION : '0.6.0');
    }


    public static function now_mysql() {
        return current_time('mysql');
    }

    public static function create_case($name, $email, $phone = '', $initial_step1 = []) {
        global $wpdb;
        $cases = self::table_cases();

        // Generate a unique case_id (best-effort retry)
        $case_id = '';
        for ($i = 0; $i < 5; $i++) {
            $try = self::generate_case_id();
            if (!self::get_case_by_case_id($try)) { $case_id = $try; break; }
        }
        if (!$case_id) $case_id = self::generate_case_id();
        $token = self::generate_token();
        $token_hash = hash('sha256', $token);

        $days = (int) get_option('tku_token_days', 30);
        $expires = date_i18n('Y-m-d H:i:s', current_time('timestamp') + max(1, $days) * DAY_IN_SECONDS, false);

        $wpdb->insert($cases, [
            'case_id' => $case_id,
            'email' => $email,
            'name' => $name,
            'deceased_name' => !empty($initial_step1['deceased_name']) ? sanitize_text_field($initial_step1['deceased_name']) : null,
            'phone' => $phone ?: null,
            'status' => 'new',
            'created_at' => self::now_mysql(),
            'updated_at' => self::now_mysql(),
            'token_hash' => $token_hash,
            'token_expires_at' => $expires,
            'step1' => (!empty($initial_step1) ? wp_json_encode($initial_step1, JSON_UNESCAPED_UNICODE) : null),
        ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);

        self::log_event($case_id, 'case_created', 'Ügy létrehozva, email kiküldés előtt.');

        return [
            'case_id' => $case_id,
            'token' => $token,
            'expires_at' => $expires,
        ];
    }

    public static function generate_case_id() {
        // TK-YYYY-XXXXX
        $year = date('Y');
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        return "TK-$year-$rand";
    }

    public static function generate_token() {
        return bin2hex(random_bytes(24)); // 48 chars
    }

    public static function get_case_by_case_id($case_id) {
        global $wpdb;
        $cases = self::table_cases();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $cases WHERE case_id=%s", $case_id), ARRAY_A);
    }

    public static function verify_continue_token($case_id, $token) {
        $row = self::get_case_by_case_id($case_id);
        if (!$row) return [false, null, 'Az ügy nem található.'];
        if (empty($row['token_hash']) || empty($row['token_expires_at'])) return [false, null, 'A folytatás link érvénytelen.'];

        $calc = hash('sha256', (string) $token);
        // Timing-safe compare
        if (!hash_equals((string) $row['token_hash'], (string) $calc)) return [false, null, 'A folytatás link érvénytelen.'];

        $expires_ts = strtotime((string) $row['token_expires_at']);
        if ($expires_ts && $expires_ts < current_time('timestamp')) return [false, null, 'A folytatás link lejárt.'];

        return [true, $row, ''];
    }

    public static function regenerate_continue_token($case_id) {
        global $wpdb;
        $cases = self::table_cases();

        $case_id = sanitize_text_field($case_id);
        if (!$case_id) return false;

        $token = self::generate_token();
        $token_hash = hash('sha256', $token);

        $days = (int) get_option('tku_token_days', 30);
        $expires = date_i18n('Y-m-d H:i:s', current_time('timestamp') + max(1, $days) * DAY_IN_SECONDS, false);

        $updated = $wpdb->update($cases, [
            'token_hash' => $token_hash,
            'token_expires_at' => $expires,
            'updated_at' => self::now_mysql(),
        ], ['case_id' => $case_id], ['%s','%s','%s'], ['%s']);

        if ($updated === false) return false;

        self::log_event($case_id, 'token_regenerated', 'Új folytatás token generálva.');
        return ['token' => $token, 'expires_at' => $expires];
    }

    public static function update_steps($case_id, $step, $data) {
        global $wpdb;
        $cases = self::table_cases();

        $col = $step === 1 ? 'step1' : ($step === 2 ? 'step2' : 'step3');
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

        $update = [
            $col => $json,
            'updated_at' => self::now_mysql(),
            'status' => 'in_progress',
        ];
        $formats = ['%s','%s','%s'];

        // Keep commonly used fields in dedicated columns (for admin list/search)
        if ((int)$step === 1 && isset($data['deceased_name'])) {
            $update['deceased_name'] = sanitize_text_field($data['deceased_name']);
            $formats[] = '%s';
        }
        if ((int)$step === 3 && isset($data['relative_name'])) {
            $update['relative_name'] = sanitize_text_field($data['relative_name']);
            $formats[] = '%s';
        }

        $res = $wpdb->update($cases, $update, ['case_id' => $case_id], $formats, ['%s']);

        if ($res === false) {
            self::log_event($case_id, 'db_error', 'update_steps hiba: ' . (string) $wpdb->last_error);
            return false;
        }

        self::log_event($case_id, 'step_saved', "Step $step mentve.");
        return true;
    }

    public static function set_status($case_id, $status, $admin_message = null, $actor = 'admin') {
        global $wpdb;
        $cases = self::table_cases();

        $allowed = ['new','in_progress','submitted','processing','need_more','closed'];
        if (!in_array($status, $allowed, true)) {
            // Do not allow setting anonymized here; use anonymize_case()
            return false;
        }

        $row = self::get_case_by_case_id($case_id);
        if (!$row) return false;

        $now = self::now_mysql();

        $data = [
            'status'     => $status,
            'updated_at' => $now,
        ];
        $format = ['%s','%s'];

        // If admin sets to submitted, stamp submitted_at (only once)
        if ($status === 'submitted' && empty($row['submitted_at'])) {
            $data['submitted_at'] = $now;
            $format[] = '%s';
        }

        $wpdb->update($cases, $data, ['case_id' => $case_id], $format, ['%s']);

        $msg = $admin_message ? ("Státusz: $status | Megjegyzés: " . $admin_message) : ("Státusz: $status");
        self::log_event($case_id, 'status_changed', $msg);

        return true;
    }

    public static function finalize_case($case_id) {
        global $wpdb;
        $cases = self::table_cases();

        $res = $wpdb->update($cases, [
            'status' => 'submitted',
            'submitted_at' => self::now_mysql(),
            'updated_at' => self::now_mysql(),
        ], ['case_id' => $case_id], ['%s','%s','%s'], ['%s']);

        if ($res === false) {
            self::log_event($case_id, 'db_error', 'finalize_case hiba: ' . (string) $wpdb->last_error);
            return false;
        }

        self::log_event($case_id, 'submitted', 'Űrlap véglegesítve.');
        return true;
    }

    public static function log_event($case_id, $type, $message = '') {
        global $wpdb;
        $events = self::table_events();
        $wpdb->insert($events, [
            'case_id' => $case_id,
            'event_type' => $type,
            'message' => $message,
            'created_at' => self::now_mysql(),
        ], ['%s','%s','%s','%s']);
    }

    public static function list_cases($args = []) {
        global $wpdb;
        $cases = self::table_cases();

        $where = "WHERE 1=1";
        $params = [];

        if (!empty($args['status'])) {
            $where .= " AND status=%s";
            $params[] = $args['status'];
        }

        if (!empty($args['s'])) {
            $like = '%' . $wpdb->esc_like($args['s']) . '%';
            $where .= " AND (case_id LIKE %s OR email LIKE %s OR name LIKE %s OR deceased_name LIKE %s OR relative_name LIKE %s)";
            $params = array_merge($params, [$like, $like, $like, $like, $like]);
        }

        $limit = isset($args['limit']) ? (int)$args['limit'] : 200;
        $limit = max(1, min(500, $limit));

        $allowed_orderby = ['created_at','updated_at'];
        $orderby = isset($args['orderby']) ? (string)$args['orderby'] : 'created_at';
        if (!in_array($orderby, $allowed_orderby, true)) $orderby = 'created_at';

        $order = strtoupper((string)($args['order'] ?? 'DESC'));
        if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

        // NOTE: $orderby/$order are whitelisted above (safe to interpolate)
        $sql = "SELECT * FROM $cases $where ORDER BY $orderby $order LIMIT $limit";
        if ($params) $sql = $wpdb->prepare($sql, $params);

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function get_events($case_id, $limit = 100) {
        global $wpdb;
        $events = self::table_events();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $events WHERE case_id=%s ORDER BY created_at DESC LIMIT %d", $case_id, (int)$limit), ARRAY_A);
    }


    public static function anonymize_case($case_id, $reason = 'GDPR anonimizálás') {
        global $wpdb;
        $cases = self::table_cases();

        // NOTE: Keep format array in sync with data keys to avoid notices and ensure consistent casting.
        $wpdb->update($cases, [
            'name' => 'Anonimizált',
            'email' => '',
            'phone' => null,
            'deceased_name' => null,
            'relative_name' => null,
            'token_hash' => null,
            'token_expires_at' => null,
            'step1' => null,
            'step2' => null,
            'step3' => null,
            'status' => 'anonymized',
            'anonymized_at' => self::now_mysql(),
            'updated_at' => self::now_mysql(),
        ], ['case_id' => $case_id],
        // 13 fields above
        ['%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'],
        ['%s']);

        self::log_event($case_id, 'anonymized', $reason);
    }

    public static function delete_case($case_id, $reason = 'GDPR törlés') {
        global $wpdb;
        $cases = self::table_cases();
        $events = self::table_events();

        // log before delete (best-effort)
        self::log_event($case_id, 'deleted', $reason);

        $wpdb->delete($events, ['case_id' => $case_id], ['%s']);
        $wpdb->delete($cases, ['case_id' => $case_id], ['%s']);
    }

    public static function run_retention_cron() {
        self::run_retention(false);
    }

    public static function run_retention($dry_run = false) {
        global $wpdb;
        $cases = self::table_cases();

        $enabled = (int) get_option('tku_retention_enabled', 0);
        if (!$enabled) return 0;

        $action = (string) get_option('tku_retention_action', 'anonymize');
        $days = max(1, (int) get_option('tku_retention_days', 180));
        $statuses = (array) get_option('tku_retention_statuses', ['closed']);

        $allowed_statuses = ['new','in_progress','submitted','processing','need_more','closed','anonymized'];
        $statuses = array_values(array_intersect($statuses, $allowed_statuses));
        if (!$statuses) return 0;

        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);

        $in_placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT case_id, status, anonymized_at, COALESCE(submitted_at, updated_at, created_at) AS basis_date
                FROM $cases
                WHERE status IN ($in_placeholders)
                  AND COALESCE(submitted_at, updated_at, created_at) < %s
                ORDER BY basis_date ASC
                LIMIT 200";

        $params = array_merge($statuses, [$threshold]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!$rows) return 0;

        $count = 0;
        foreach ($rows as $r) {
            $cid = $r['case_id'];
            if ($action === 'delete') {
                if (!$dry_run) self::delete_case($cid, "Automatikus törlés: $days napnál régebbi ügy.");
                $count++;
            } else {
                // anonymize
                if ($r['status'] === 'anonymized') continue;
                if (!$dry_run) self::anonymize_case($cid, "Automatikus anonimizálás: $days napnál régebbi ügy.");
                $count++;
            }
        }
        return $count;
    }

}
