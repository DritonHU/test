<?php
if (!defined('ABSPATH')) exit;

class TKU_Admin {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_tku_update_case', [__CLASS__, 'handle_update_case']);
        add_action('admin_post_tku_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_tku_anonymize_case', [__CLASS__, 'handle_anonymize_case']);
        add_action('admin_post_tku_delete_case', [__CLASS__, 'handle_delete_case']);
        add_action('admin_post_tku_run_retention', [__CLASS__, 'handle_run_retention']);
    
        add_action('admin_post_tku_export_case_csv', [__CLASS__, 'handle_export_case_csv']);
        add_action('admin_post_tku_export_case_print', [__CLASS__, 'handle_export_case_print']);
        add_action('admin_post_tku_resend_link', [__CLASS__, 'handle_resend_link']);
    }

    public static function menu() {
        add_menu_page('Ügyintézés', 'Ügyintézés', 'manage_options', 'tku_cases', [__CLASS__, 'page_cases'], 'dashicons-clipboard', 58);
        add_submenu_page('tku_cases', 'Ügyek', 'Ügyek', 'manage_options', 'tku_cases', [__CLASS__, 'page_cases']);
        add_submenu_page('tku_cases', 'Beállítások', 'Beállítások', 'manage_options', 'tku_settings', [__CLASS__, 'page_settings']);
    }

    public static function page_cases() {
        if (!current_user_can('manage_options')) return;

        // WordPress slashes superglobals; unslash before sanitizing.
        $status = sanitize_text_field(wp_unslash($_GET['status'] ?? ''));
        $s = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $view = sanitize_text_field(wp_unslash($_GET['view'] ?? ''));
        $progress = sanitize_text_field(wp_unslash($_GET['progress'] ?? ''));
        $orderby = sanitize_key(wp_unslash($_GET['orderby'] ?? 'created_at'));
        $order = strtoupper(sanitize_key(wp_unslash($_GET['order'] ?? 'desc')));
        if (!in_array($orderby, ['created_at','updated_at'], true)) $orderby = 'created_at';
        if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

        echo '<div class="wrap"><h1>Ügyek</h1>';

        // Small admin-only styles for progress badges
        echo '<style>
            .tku-progress-badges{display:inline-flex;gap:6px;align-items:center;}
            .tku-stepbadge{min-width:28px;height:22px;padding:0 6px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;
                font-weight:700;font-size:12px;line-height:1;border:1px solid #d0d7de;background:#f6f8fa;color:#57606a;}
            .tku-stepbadge.tku-ok{background:#00a32a;border-color:#00a32a;color:#fff;}
            .tku-stepbadge.tku-no{background:#f6f8fa;border-color:#d0d7de;color:#57606a;}
            .tku-stepbadge[title]{cursor:help;}
        </style>';

        if ($view) {
            self::render_case_detail($view);
            echo '</div>';
            return;
        }

        $cases = TKU_DB::list_cases(['status' => $status, 's' => $s, 'orderby' => $orderby, 'order' => $order, 'limit' => 200]);

                // Precompute progress flags + counts (for quick filters)
        $case_flags = [];
        $counts = [
            'all' => 0,
            'complete' => 0,
            'incomplete' => 0,
            's1_incomplete' => 0,
            's2_incomplete' => 0,
            's3_incomplete' => 0,
        ];

        foreach ((array)$cases as $c) {
            $flags = self::compute_step_flags($c);
            $case_flags[$c['case_id']] = $flags;
            $counts['all']++;
            foreach (['complete','incomplete','s1_incomplete','s2_incomplete','s3_incomplete'] as $pf) {
                if (self::progress_matches($pf, $flags)) $counts[$pf]++;
            }
        }

        // Quick filter links (like WP list tables)
        $base_args = [
            'page' => 'tku_cases',
        ];
        if ($s !== '') $base_args['s'] = $s;
        if ($status !== '') $base_args['status'] = $status;
        if ($orderby) $base_args['orderby'] = $orderby;
        if ($order) $base_args['order'] = $order;

        $tabs = [
            '' => ['Összes', 'all'],
            'complete' => ['Minden lépés kész', 'complete'],
            'incomplete' => ['Van hiányosság', 'incomplete'],
            's1_incomplete' => ['Hiányos: 1. lépés', 's1_incomplete'],
            's2_incomplete' => ['Hiányos: 2. lépés', 's2_incomplete'],
            's3_incomplete' => ['Hiányos: 3. lépés', 's3_incomplete'],
        ];

        echo '<ul class="subsubsub">';
        $i = 0;
        foreach ($tabs as $k => $arr) {
            [$label, $count_key] = $arr;
            $args = $base_args;
            if ($k !== '') $args['progress'] = $k;
            $url = add_query_arg($args, admin_url('admin.php'));
            $current = ($progress === $k) ? ' class="current"' : '';
            echo '<li><a' . $current . ' href="' . esc_url($url) . '">' . esc_html($label) . ' <span class="count">(' . (int)($counts[$count_key] ?? 0) . ')</span></a>';
            $i++;
            if ($i < count($tabs)) echo ' | ';
            echo '</li>';
        }
        echo '</ul><br class="clear" />';

        echo '<form method="get" style="margin:10px 0;">';
        echo '<input type="hidden" name="page" value="tku_cases" />';
        echo '<input type="hidden" name="orderby" value="' . esc_attr($orderby) . '" />';
        echo '<input type="hidden" name="order" value="' . esc_attr($order) . '" />';
        echo '<input type="text" name="s" value="' . esc_attr($s) . '" placeholder="Keresés: azonosító/email/név/elhunyt/hozzátartozó" style="min-width:340px;" /> ';
        echo '<select name="status"><option value="">Összes státusz</option>';
        foreach (['new','in_progress','submitted','processing','need_more','closed','anonymized'] as $st) {
            echo '<option value="' . esc_attr($st) . '" ' . selected($status, $st, false) . '>' . esc_html(TKU_Shortcodes::status_label($st)) . '</option>';
        }
        echo '</select> ';
        echo '<select name="progress"><option value="">Összes kitöltöttség</option>';
        $progress_options = [
            'incomplete' => 'Van hiányosság',
            'complete' => 'Minden lépés kész',
            's1_incomplete' => 'Hiányos: 1. lépés',
            's2_incomplete' => 'Hiányos: 2. lépés',
            's3_incomplete' => 'Hiányos: 3. lépés',
        ];
        foreach ($progress_options as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($progress, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        submit_button('Szűrés', 'secondary', '', false);
        echo '</form>';

        // Sorting links
        $sort_args_base = $base_args;
        if ($progress !== '') $sort_args_base['progress'] = $progress;

        $created_next = ($orderby === 'created_at' && $order === 'DESC') ? 'ASC' : 'DESC';
        $updated_next = ($orderby === 'updated_at' && $order === 'DESC') ? 'ASC' : 'DESC';

        $created_url = add_query_arg(array_merge($sort_args_base, ['orderby' => 'created_at', 'order' => $created_next]), admin_url('admin.php'));
        $updated_url = add_query_arg(array_merge($sort_args_base, ['orderby' => 'updated_at', 'order' => $updated_next]), admin_url('admin.php'));

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Ügyazonosító</th>';
        echo '<th>Elhunyt neve</th>';
        echo '<th>Hozzátartozó</th>';
        echo '<th>Kapcsolattartó</th>';
        echo '<th>Email</th>';
        echo '<th>Telefon</th>';
        echo '<th>Státusz</th>';
        echo '<th>Kitöltöttség</th>';
        echo '<th><a href="' . esc_url($created_url) . '">Létrehozva</a></th>';
        echo '<th><a href="' . esc_url($updated_url) . '">Frissítve</a></th>';
        echo '<th>Művelet</th>';
        echo '</tr></thead><tbody>';

        if (!$cases) {
            echo '<tr><td colspan="11">Nincs találat.</td></tr>';
        } else {
            $shown = 0;
            foreach ($cases as $c) {
                $flags = $case_flags[$c['case_id']] ?? [1=>false,2=>false,3=>false];

                if (!empty($progress) && !self::progress_matches($progress, $flags)) {
                    continue;
                }

                $shown++;
                $link = admin_url('admin.php?page=tku_cases&view=' . urlencode($c['case_id']));

                $s1 = self::decode_step_json($c['step1'] ?? '');
                $s3 = self::decode_step_json($c['step3'] ?? '');

                $deceased = $c['deceased_name'] ?? '';
                if (!$deceased && !empty($s1['deceased_name'])) $deceased = $s1['deceased_name'];

                $rel = $c['relative_name'] ?? '';
                if (!$rel && !empty($s3['relative_name'])) $rel = $s3['relative_name'];

                echo '<tr>';
                echo '<td><strong>' . esc_html($c['case_id']) . '</strong></td>';
                echo '<td>' . esc_html($deceased) . '</td>';
                echo '<td>' . esc_html($rel) . '</td>';
                echo '<td>' . esc_html($c['name']) . '</td>';
                echo '<td>' . esc_html($c['email']) . '</td>';
                echo '<td>' . esc_html($c['phone']) . '</td>';
                echo '<td>' . esc_html(TKU_Shortcodes::status_label($c['status'])) . '</td>';
                echo '<td>' . self::render_step_badges($flags) . '</td>';
                echo '<td>' . esc_html($c['created_at']) . '</td>';
                echo '<td>' . esc_html($c['updated_at']) . '</td>';

                $csv = wp_nonce_url(admin_url('admin-post.php?action=tku_export_case_csv&case_id=' . urlencode($c['case_id'])), 'tku_export_case_' . $c['case_id']);
                $print = wp_nonce_url(admin_url('admin-post.php?action=tku_export_case_print&case_id=' . urlencode($c['case_id'])), 'tku_export_case_' . $c['case_id']);

                echo '<td><a class="button button-small" href="' . esc_url($link) . '">Megnyitás</a> ';
                echo '<a class="button button-small" href="' . esc_url($csv) . '">CSV</a> ';
                echo '<a class="button button-small" target="_blank" href="' . esc_url($print) . '">PDF</a> ';

                if (!empty($c['email']) && ($c['status'] ?? '') !== 'anonymized') {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;margin-left:4px;">';
                    wp_nonce_field('tku_resend_link');
                    echo '<input type="hidden" name="action" value="tku_resend_link" />';
                    echo '<input type="hidden" name="case_id" value="' . esc_attr($c['case_id']) . '" />';
                    echo '<button type="submit" class="button button-small">Link újraküldése</button>';
                    echo '</form>';
                }

                echo '</td>';
                echo '</tr>';
            }
            if (!$shown) {
                echo '<tr><td colspan="11">Nincs találat.</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    private static function decode_step_json($raw) {
        if (empty($raw) || !is_string($raw)) return [];
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    private static function is_yes_no($v) {
        return in_array($v, ['yes', 'no'], true);
    }

    /**
     * Returns step completion flags as [1=>bool,2=>bool,3=>bool]
     */
    private static function compute_step_flags($case_row) {
        $flags = [1 => false, 2 => false, 3 => false];

        if (!is_array($case_row)) return $flags;
        if (($case_row['status'] ?? '') === 'anonymized') return $flags;

        $s1 = self::decode_step_json($case_row['step1'] ?? '');
        $s2 = self::decode_step_json($case_row['step2'] ?? '');
        $s3 = self::decode_step_json($case_row['step3'] ?? '');

        // Step 1 required fields
        $flags[1] = !empty($s1['deceased_name'])
            && !empty($s1['deceased_address'])
            && !empty($s1['mother_name'])
            && !empty($s1['father_name'])
            && !empty($s1['birth_name'])
            && !empty($s1['birth_place'])
            && !empty($s1['birth_date'])
            && !empty($s1['death_place'])
            && !empty($s1['death_date'])
            && !empty($s1['burial_place']);

        // Step 2 required fields
        $has_children_count = isset($s2['children_count']) && $s2['children_count'] !== '';
        $flags[2] = !empty($s2['family_status'])
            && !empty($s2['citizenship'])
            && !empty($s2['economic_activity'])
            && !empty($s2['education'])
            && $has_children_count;

        // Step 3 required fields (+ conditional numbers)
        $ok = !empty($s3['relative_name'])
            && !empty($s3['relative_quality'])
            && !empty($s3['relative_address']);

        $ok = $ok && self::is_yes_no($s3['id_return_request'] ?? '');

        $ok = $ok && self::is_yes_no($s3['has_address_card'] ?? '');
        if (($s3['has_address_card'] ?? '') === 'yes') {
            $ok = $ok && !empty($s3['address_card_number']);
        }

        $ok = $ok && self::is_yes_no($s3['has_driver_license'] ?? '');
        if (($s3['has_driver_license'] ?? '') === 'yes') {
            $ok = $ok && !empty($s3['driver_license_number']);
        }

        $ok = $ok && self::is_yes_no($s3['has_passport'] ?? '');
        if (($s3['has_passport'] ?? '') === 'yes') {
            $ok = $ok && !empty($s3['passport_number']);
        }

        $flags[3] = (bool) $ok;

        return $flags;
    }

    private static function render_step_badges($flags) {
        $flags = is_array($flags) ? $flags : [];
        $out = '<span class="tku-progress-badges" aria-label="Kitöltöttség">';
        for ($i = 1; $i <= 3; $i++) {
            $done = !empty($flags[$i]);
            $cls = $done ? 'tku-stepbadge tku-ok' : 'tku-stepbadge tku-no';
            $title = $done ? "Lépés $i kész" : "Lépés $i hiányos";
            $out .= '<span class="' . esc_attr($cls) . '" title="' . esc_attr($title) . '">' . (int)$i . ($done ? '✓' : '–') . '</span>';
        }
        $out .= '</span>';
        return $out;
    }


    /**
     * Filter helper for admin list: matches progress filter values against step flags.
     */
    private static function progress_matches($filter, $flags) {
        $flags = is_array($flags) ? $flags : [];
        $f1 = !empty($flags[1]);
        $f2 = !empty($flags[2]);
        $f3 = !empty($flags[3]);

        switch ((string)$filter) {
            case 'complete':
                return $f1 && $f2 && $f3;
            case 'incomplete':
                return !($f1 && $f2 && $f3);
            case 's1_incomplete':
                return !$f1;
            case 's2_incomplete':
                return $f1 && !$f2;
            case 's3_incomplete':
                return $f1 && $f2 && !$f3;
            default:
                return true;
        }
    }
    private static function render_case_detail($case_id) {
        $case = TKU_DB::get_case_by_case_id($case_id);
        if (!$case) {
            echo '<p>Nincs ilyen ügy.</p>';
            return;
        }

        $events = TKU_DB::get_events($case_id);
        $s1 = $case['step1'] ? json_decode($case['step1'], true) : [];
        $s2 = $case['step2'] ? json_decode($case['step2'], true) : [];
        $s3 = $case['step3'] ? json_decode($case['step3'], true) : [];

        echo '<a href="' . esc_url(admin_url('admin.php?page=tku_cases')) . '">&larr; vissza</a>';
        echo '<h2>Ügy: ' . esc_html($case['case_id']) . '</h2>';


        $csv_url = wp_nonce_url(admin_url('admin-post.php?action=tku_export_case_csv&case_id=' . urlencode($case['case_id'])), 'tku_export_case_' . $case['case_id']);
        $print_url = wp_nonce_url(admin_url('admin-post.php?action=tku_export_case_print&case_id=' . urlencode($case['case_id'])), 'tku_export_case_' . $case['case_id']);
        echo '<p style="margin:10px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
            . '<a class="button" href="' . esc_url($csv_url) . '">CSV letöltés</a>'
            . '<a class="button" target="_blank" href="' . esc_url($print_url) . '">Nyomtatás / PDF</a>'
            . '</p>';

        if (!empty($case['email']) && ($case['status'] ?? '') !== 'anonymized') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 12px 0;">';
            wp_nonce_field('tku_resend_link');
            echo '<input type="hidden" name="action" value="tku_resend_link" />';
            echo '<input type="hidden" name="case_id" value="' . esc_attr($case['case_id']) . '" />';
            submit_button('Folytatás link újraküldése', 'secondary', 'submit', false);
            echo '</form>';
        }


        $tku_msg = sanitize_text_field(wp_unslash($_GET['tku_msg'] ?? ''));
        if ($tku_msg === 'anonymized') {
            echo '<div class="notice notice-success"><p>Az ügy anonimizálva.</p></div>';
        } elseif ($tku_msg === 'deleted') {
            echo '<div class="notice notice-success"><p>Az ügy törölve.</p></div>';
        } elseif ($tku_msg === 'retention_ran') {
            $cnt = (int) (wp_unslash($_GET['tku_cnt'] ?? 0));
            echo '<div class="notice notice-success"><p>Adatmegőrzés futtatva. Kezelt ügyek: ' . esc_html($cnt) . '.</p></div>';
        } elseif ($tku_msg === 'resent') {
            echo '<div class="notice notice-success"><p>A folytatás link újragenerálva és elküldve.</p></div>';
        } elseif ($tku_msg === 'resent_fail') {
            echo '<div class="notice notice-error"><p>A folytatás linket nem sikerült elküldeni. Ellenőrizd a levelezést.</p></div>';
        } elseif ($tku_msg === 'error') {
            echo '<div class="notice notice-error"><p>Hiba történt.</p></div>';
        }

        echo '<p><strong>Név:</strong> ' . esc_html($case['name']) . '<br>';
        echo '<strong>Email:</strong> ' . esc_html($case['email']) . '<br>';
        echo '<strong>Telefon:</strong> ' . esc_html($case['phone']) . '<br>';
        echo '<strong>Státusz:</strong> ' . esc_html(TKU_Shortcodes::status_label($case['status'])) . '<br>';
        if (!empty($case['anonymized_at'])) {
            echo '<strong>Anonimizált:</strong> ' . esc_html($case['anonymized_at']) . '<br>';
        }
        echo '</p>';

        echo '<h3>Adatok</h3>';
        echo '<div style="display:flex;gap:20px;flex-wrap:wrap;">';
        echo '<div style="flex:1;min-width:320px;"><h4>Lépés 1</h4>' . self::render_step_table(1, $s1) . '</div>';
        echo '<div style="flex:1;min-width:320px;"><h4>Lépés 2</h4>' . self::render_step_table(2, $s2) . '</div>';
        echo '<div style="flex:1;min-width:320px;"><h4>Lépés 3</h4>' . self::render_step_table(3, $s3) . '</div>';
        echo '</div>';

        echo '<h3>Státusz módosítás</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tku_update_case');
        echo '<input type="hidden" name="action" value="tku_update_case" />';
        echo '<input type="hidden" name="case_id" value="' . esc_attr($case['case_id']) . '" />';
        echo '<select name="status">';
        foreach (['new','in_progress','submitted','processing','need_more','closed'] as $st) {
            echo '<option value="' . esc_attr($st) . '" ' . selected($case['status'], $st, false) . '>' . esc_html(TKU_Shortcodes::status_label($st)) . '</option>';
        }
        echo '</select> ';
        echo '<input type="text" name="admin_message" placeholder="Megjegyzés (opcionális)" style="min-width:420px;" /> ';
        submit_button('Mentés', 'primary', 'submit', false);
        echo '</form>';


        echo '<h3>GDPR műveletek</h3>';
        echo '<p class="description">Figyelem: az anonimizálás visszavonhatatlan (a személyes adatok törlésre kerülnek). A törlés az ügyet és az eseménynaplót is eltávolítja.</p>';
        echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';

        // Anonymize
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Biztosan anonimizálod ezt az ügyet?&quot;);" style="margin:0;">';
        wp_nonce_field('tku_anonymize_case');
        echo '<input type="hidden" name="action" value="tku_anonymize_case" />';
        echo '<input type="hidden" name="case_id" value="' . esc_attr($case['case_id']) . '" />';
        submit_button('Anonimizálás', 'secondary', 'submit', false);
        echo '</form>';

        // Delete
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;Biztosan TÖRÖLNI akarod ezt az ügyet? Ez nem visszavonható.&quot;);" style="margin:0;">';
        wp_nonce_field('tku_delete_case');
        echo '<input type="hidden" name="action" value="tku_delete_case" />';
        echo '<input type="hidden" name="case_id" value="' . esc_attr($case['case_id']) . '" />';
        submit_button('Törlés', 'delete', 'submit', false);
        echo '</form>';

        echo '</div>';


        echo '<h3>Eseménynapló</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Idő</th><th>Típus</th><th>Üzenet</th></tr></thead><tbody>';
        if (!$events) {
            echo '<tr><td colspan="3">Nincs esemény.</td></tr>';
        } else {
            foreach ($events as $e) {
                echo '<tr><td>' . esc_html($e['created_at']) . '</td><td>' . esc_html($e['event_type']) . '</td><td>' . esc_html($e['message']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }

    public static function handle_update_case() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_update_case');

        $case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? ''));
        $admin_message = sanitize_text_field(wp_unslash($_POST['admin_message'] ?? ''));

        $case = TKU_DB::get_case_by_case_id($case_id);
        if ($case) {
            TKU_DB::set_status($case_id, $status, $admin_message, 'admin');
            $sent = TKU_Mail::send_status_notification($case, $status, $admin_message);
            TKU_DB::log_event($case_id, 'notify_email', $sent ? 'Státusz értesítő email elküldve.' : 'Státusz értesítő email nem ment ki (lehet tiltva).');
        }

        wp_safe_redirect(admin_url('admin.php?page=tku_cases&view=' . urlencode($case_id)));
        exit;
    }



    public static function handle_resend_link() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_resend_link');

        $case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
        if (!$case_id) {
            wp_safe_redirect(admin_url('admin.php?page=tku_cases&tku_msg=error'));
            exit;
        }

        $case = TKU_DB::get_case_by_case_id($case_id);
        if (!$case || ($case['status'] ?? '') === 'anonymized') {
            wp_safe_redirect(admin_url('admin.php?page=tku_cases&tku_msg=error'));
            exit;
        }

        $regen = TKU_DB::regenerate_continue_token($case_id);
        if (!$regen || empty($regen['token'])) {
            TKU_DB::log_event($case_id, 'resend_link', 'Token újragenerálás sikertelen.');
            wp_safe_redirect(admin_url('admin.php?page=tku_cases&view=' . urlencode($case_id) . '&tku_msg=resent_fail'));
            exit;
        }

        // Use step 1 as default entry
        $continue_url = TKU_Shortcodes::build_continue_url($case_id, $regen['token'], 1);

        // Resolve deceased name for nicer email (fallback to step1)
        $deceased = (string)($case['deceased_name'] ?? '');
        if (!$deceased && !empty($case['step1'])) {
            $s1 = json_decode($case['step1'], true);
            if (is_array($s1) && !empty($s1['deceased_name'])) {
                $deceased = (string)$s1['deceased_name'];
            }
        }

        // Resend should use the dedicated "continue" email template/subject.
        $sent = TKU_Mail::send_continue_email(
            (string)($case['email'] ?? ''),
            (string)($case['name'] ?? ''),
            $case_id,
            $continue_url,
            $deceased
        );

        TKU_DB::log_event($case_id, 'resend_link', $sent ? 'Folytatás link újraküldve.' : 'Folytatás link újraküldése sikertelen.');

        wp_safe_redirect(admin_url('admin.php?page=tku_cases&view=' . urlencode($case_id) . '&tku_msg=' . ($sent ? 'resent' : 'resent_fail')));
        exit;
    }

    public static function page_settings() {
        if (!current_user_can('manage_options')) return;

        $continue_page_id = (int) get_option('tku_continue_page_id');
        $token_days = (int) get_option('tku_token_days', 30);
        $rl_start = (int) get_option('tku_rl_start_per_hour', 5);
        $rl_status = (int) get_option('tku_rl_status_per_hour', 20);

        $turnstile_enabled = (int) get_option('tku_turnstile_enabled', 0);
        $turnstile_site = (string) get_option('tku_turnstile_site_key', '');
        $turnstile_secret = (string) get_option('tku_turnstile_secret_key', '');

        $notify_enabled = (int) get_option('tku_notify_enabled', 0);
        $notify_statuses = (array) get_option('tku_notify_statuses', []);
        $notify_subject = (string) get_option('tku_notify_subject', '');
        $notify_body = (string) get_option('tku_notify_body', '');

        $admin_submit_enabled = (int) get_option('tku_admin_submit_notify_enabled', 0);
        $admin_submit_email = (string) get_option('tku_admin_submit_notify_email', get_option('admin_email'));
        $admin_submit_subject = (string) get_option('tku_admin_submit_notify_subject', 'Új véglegesített adatlap: {case_id}');
        $admin_submit_body = (string) get_option('tku_admin_submit_notify_body', '<p>Új véglegesített adatlap érkezett.</p><p><strong>Ügyazonosító:</strong> {case_id}</p><p><strong>Kapcsolattartó:</strong> {name} ({email})</p><p><strong>Elhunyt:</strong> {deceased_name}</p><p><a href="{admin_url}">Megnyitás az admin felületen</a></p>');

        $require_last4 = (int) get_option('tku_status_require_phone_last4', 0);

        $ret_enabled = (int) get_option('tku_retention_enabled', 0);
        $ret_action = (string) get_option('tku_retention_action', 'anonymize');
        $ret_days = (int) get_option('tku_retention_days', 180);
        $ret_statuses = (array) get_option('tku_retention_statuses', ['closed']);

        $from_name = (string) get_option('tku_mail_from_name', get_bloginfo('name'));
        $from_email = (string) get_option('tku_mail_from_email', get_option('admin_email'));

        echo '<div class="wrap"><h1>Beállítások</h1>';
        $tku_settings_msg = sanitize_text_field(wp_unslash($_GET['tku_msg'] ?? ''));
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success"><p>Beállítások mentve.</p></div>';
        }
        if ($tku_settings_msg === 'retention_ran') {
            $cnt = (int) (wp_unslash($_GET['tku_cnt'] ?? 0));
            echo '<div class="notice notice-success"><p>Adatmegőrzés futtatva. Kezelt ügyek: ' . esc_html($cnt) . '.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tku_save_settings');
        echo '<input type="hidden" name="action" value="tku_save_settings" />';

        echo '<h2>Alap beállítások</h2>';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row">Folytatás oldal</th><td>';
        wp_dropdown_pages([
            'name' => 'continue_page_id',
            'selected' => $continue_page_id,
            'show_option_none' => '— Válassz —',
        ]);
        echo '<p class="description">Az emailben szereplő folytatás link erre az oldalra mutat.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Token lejárat (nap)</th><td><input type="number" name="token_days" value="' . esc_attr($token_days) . '" min="1" /></td></tr>';
        echo '<tr><th scope="row">Rate limit - Ügyindítás (óra/IP)</th><td><input type="number" name="rl_start" value="' . esc_attr($rl_start) . '" min="1" /></td></tr>';
        echo '<tr><th scope="row">Rate limit - Státusz lekérdezés (óra/IP)</th><td><input type="number" name="rl_status" value="' . esc_attr($rl_status) . '" min="1" /></td></tr>';

        echo '</tbody></table>';

        echo '<h2>Email feladó</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Feladó név</th><td><input type="text" name="from_name" value="' . esc_attr($from_name) . '" /></td></tr>';
        echo '<tr><th scope="row">Feladó email</th><td><input type="email" name="from_email" value="' . esc_attr($from_email) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Botvédelem (Cloudflare Turnstile)</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Engedélyezés</th><td><label><input type="checkbox" name="turnstile_enabled" value="1" ' . checked($turnstile_enabled, 1, false) . ' /> Turnstile használata az ügyindítás űrlapon</label></td></tr>';
        echo '<tr><th scope="row">Site key</th><td><input type="text" name="turnstile_site" value="' . esc_attr($turnstile_site) . '" style="min-width:420px;" /></td></tr>';
        echo '<tr><th scope="row">Secret key</th><td><input type="text" name="turnstile_secret" value="' . esc_attr($turnstile_secret) . '" style="min-width:420px;" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Státusz lekérdezés</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Telefon ellenőrzés</th><td><label><input type="checkbox" name="require_last4" value="1" ' . checked($require_last4, 1, false) . ' /> Telefonszám utolsó 4 számjegye kötelező (ha van telefonszám rögzítve)</label></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Státusz értesítő email</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Engedélyezés</th><td><label><input type="checkbox" name="notify_enabled" value="1" ' . checked($notify_enabled, 1, false) . ' /> Automatikus email admin státuszváltáskor</label></td></tr>';
        echo '<tr><th scope="row">Értesítős státuszok</th><td>';
        foreach (['in_progress','submitted','processing','need_more','closed'] as $st) {
            echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="notify_statuses[]" value="' . esc_attr($st) . '" ' . checked(in_array($st, $notify_statuses, true), true, false) . ' /> ' . esc_html(TKU_Shortcodes::status_label($st)) . '</label>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row">Tárgy sablon</th><td><input type="text" name="notify_subject" value="' . esc_attr($notify_subject) . '" style="min-width:520px;" /></td></tr>';
        echo '<tr><th scope="row">Törzs sablon (HTML)</th><td><textarea name="notify_body" rows="8" style="min-width:520px;">' . esc_textarea($notify_body) . '</textarea><p class="description">Változók: {name}, {case_id}, {status_label}, {admin_message}, {admin_message_block}, {site_name}</p></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Admin értesítés beküldéskor</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Engedélyezés</th><td><label><input type="checkbox" name="admin_submit_enabled" value="1" ' . checked($admin_submit_enabled, 1, false) . ' /> Email küldése, amikor az ügyfél véglegesíti az adatlapot</label></td></tr>';
        echo '<tr><th scope="row">Címzett email</th><td><input type="email" name="admin_submit_email" value="' . esc_attr($admin_submit_email) . '" style="min-width:420px;" /></td></tr>';
        echo '<tr><th scope="row">Tárgy sablon</th><td><input type="text" name="admin_submit_subject" value="' . esc_attr($admin_submit_subject) . '" style="min-width:520px;" /></td></tr>';
        echo '<tr><th scope="row">Törzs sablon (HTML)</th><td><textarea name="admin_submit_body" rows="8" style="min-width:520px;">' . esc_textarea($admin_submit_body) . '</textarea><p class="description">Változók: {case_id}, {name}, {email}, {deceased_name}, {relative_name}, {admin_url}, {site_name}</p></td></tr>';
        echo '</tbody></table>';


        echo '<h2>Adatmegőrzés (GDPR)</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">Engedélyezés</th><td><label><input type="checkbox" name="ret_enabled" value="1" ' . checked($ret_enabled, 1, false) . ' /> Automatikus adatkezelés (napok után)</label></td></tr>';
        echo '<tr><th scope="row">Művelet</th><td><select name="ret_action">';
        echo '<option value="anonymize" ' . selected($ret_action, 'anonymize', false) . '>Anonimizálás</option>';
        echo '<option value="delete" ' . selected($ret_action, 'delete', false) . '>Törlés</option>';
        echo '</select></td></tr>';
        echo '<tr><th scope="row">Időtartam (nap)</th><td><input type="number" name="ret_days" value="' . esc_attr($ret_days) . '" min="1" /> <p class="description">Ennyi nap után: COALESCE(beküldés dátuma, utolsó módosítás, létrehozás) alapján.</p></td></tr>';
        echo '<tr><th scope="row">Érintett státuszok</th><td>';
        foreach (['submitted','need_more','closed'] as $st) {
            echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="ret_statuses[]" value="' . esc_attr($st) . '" ' . checked(in_array($st, $ret_statuses, true), true, false) . ' /> ' . esc_html(TKU_Shortcodes::status_label($st)) . '</label>';
        }
        echo '<p class="description">Csak a kijelölt státuszú ügyek kerülnek kezelésre.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<p>';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tku_run_retention'), 'tku_run_retention')) . '">Futtatás most</a>';
        echo ' <span class="description">Teszteléshez/karbantartáshoz.</span>';
        echo '</p>';

        submit_button('Mentés');
        echo '</form></div>';
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_save_settings');

        update_option('tku_continue_page_id', (int) ($_POST['continue_page_id'] ?? 0));
        update_option('tku_token_days', max(1, (int) ($_POST['token_days'] ?? 30)));
        update_option('tku_rl_start_per_hour', max(1, (int) ($_POST['rl_start'] ?? 5)));
        update_option('tku_rl_status_per_hour', max(1, (int) ($_POST['rl_status'] ?? 20)));

        update_option('tku_mail_from_name', sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')));
        update_option('tku_mail_from_email', sanitize_email(wp_unslash($_POST['from_email'] ?? '')));

        update_option('tku_turnstile_enabled', !empty($_POST['turnstile_enabled']) ? 1 : 0);
        update_option('tku_turnstile_site_key', sanitize_text_field(wp_unslash($_POST['turnstile_site'] ?? '')));
        update_option('tku_turnstile_secret_key', sanitize_text_field(wp_unslash($_POST['turnstile_secret'] ?? '')));

        update_option('tku_status_require_phone_last4', !empty($_POST['require_last4']) ? 1 : 0);

        update_option('tku_notify_enabled', !empty($_POST['notify_enabled']) ? 1 : 0);
        $statuses = array_values(array_intersect((array)($_POST['notify_statuses'] ?? []), ['in_progress','submitted','processing','need_more','closed']));
        update_option('tku_notify_statuses', $statuses);
        update_option('tku_notify_subject', sanitize_text_field(wp_unslash($_POST['notify_subject'] ?? '')));
        // allow basic html
        $allowed = [
            'a' => ['href'=>true,'title'=>true,'target'=>true,'rel'=>true],
            'p' => [],
            'strong' => [],
            'em' => [],
            'br' => [],
            'ul' => [],
            'ol' => [],
            'li' => [],
            'span' => [],
        ];
        $body = wp_kses(wp_unslash($_POST['notify_body'] ?? ''), $allowed);
        update_option('tku_notify_body', $body);

        // Admin értesítés beküldéskor
        update_option('tku_admin_submit_notify_enabled', !empty($_POST['admin_submit_enabled']) ? 1 : 0);
        update_option('tku_admin_submit_notify_email', sanitize_email(wp_unslash($_POST['admin_submit_email'] ?? '')));
        update_option('tku_admin_submit_notify_subject', sanitize_text_field(wp_unslash($_POST['admin_submit_subject'] ?? '')));
        $admin_body = wp_kses(wp_unslash($_POST['admin_submit_body'] ?? ''), $allowed);
        update_option('tku_admin_submit_notify_body', $admin_body);


        // Adatmegőrzés (GDPR)
        update_option('tku_retention_enabled', !empty($_POST['ret_enabled']) ? 1 : 0);
        $act = sanitize_text_field($_POST['ret_action'] ?? 'anonymize');
        $act = in_array($act, ['anonymize','delete'], true) ? $act : 'anonymize';
        update_option('tku_retention_action', $act);
        update_option('tku_retention_days', max(1, (int) ($_POST['ret_days'] ?? 180)));
        $ret_statuses = array_values(array_intersect((array)($_POST['ret_statuses'] ?? []), ['submitted','need_more','closed']));
        if (!$ret_statuses) $ret_statuses = ['closed'];
        update_option('tku_retention_statuses', $ret_statuses);

        wp_safe_redirect(admin_url('admin.php?page=tku_settings&saved=1'));
        exit;
    }


    public static function handle_anonymize_case() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_anonymize_case');

        $case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
        if ($case_id) {
            TKU_DB::anonymize_case($case_id, 'Admin manuális anonimizálás');
            wp_safe_redirect(admin_url('admin.php?page=tku_cases&view=' . urlencode($case_id) . '&tku_msg=anonymized'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=tku_cases&tku_msg=error'));
        exit;
    }

    public static function handle_delete_case() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_delete_case');

        $case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
        if ($case_id) {
            TKU_DB::delete_case($case_id, 'Admin manuális törlés');
            wp_safe_redirect(admin_url('admin.php?page=tku_cases&tku_msg=deleted'));
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=tku_cases&tku_msg=error'));
        exit;
    }

    public static function handle_run_retention() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');
        check_admin_referer('tku_run_retention');

        $cnt = TKU_DB::run_retention(false);
        wp_safe_redirect(admin_url('admin.php?page=tku_settings&tku_msg=retention_ran&tku_cnt=' . intval($cnt)));
        exit;
    }

    private static function field_labels() {
        return [
            // Step 1
            'deceased_name' => 'Az elhunyt neve',
            'deceased_address' => 'Az elhunyt címe',
            'mother_name' => 'Anyja neve',
            'father_name' => 'Apja neve',
            'birth_name' => 'Születési neve',
            'birth_place' => 'Születési helye',
            'birth_date' => 'Születési ideje',
            'death_place' => 'Elhalálozás helye',
            'death_date' => 'Elhalálozás ideje',
            'burial_place' => 'Temetés / urna elhelyezésének helye',

            // Step 2
            'spouse_name' => 'Házastárs neve',
            'marriage_place' => 'Házasságkötés helye',
            'marriage_date' => 'Házasságkötés ideje',
            'family_status' => 'Családi állapota',
            'citizenship' => 'Állampolgársága',
            'economic_activity' => 'Gazdasági aktivitása',
            'last_occupation' => 'Utolsó foglalkozása',
            'education' => 'Iskolai végzettsége',
            'children_count' => 'Gyermekei száma',

            // Step 3
            'relative_name' => 'Hozzátartozó neve',
            'relative_quality' => 'Hozzátartozói minősége',
            'relative_address' => 'Hozzátartozó címe',
            'id_return_request' => 'A személyazonosító igazolványt visszakérem',
            'has_address_card' => 'Az elhunyt rendelkezik-e lakcímigazolvánnyal?',
            'address_card_number' => 'Lakcímkártya száma',
            'has_driver_license' => 'Az elhunyt rendelkezik-e vezetői engedéllyel?',
            'driver_license_number' => 'Vezetői engedély száma',
            'has_passport' => 'Az elhunyt rendelkezik-e érvényes magyar útlevéllel?',
            'passport_number' => 'Útlevél száma',
        ];
    }

    private static function expected_order($step) {
        if ($step === 1) {
            return ['deceased_name','deceased_address','mother_name','father_name','birth_name','birth_place','birth_date','death_place','death_date','burial_place'];
        }
        if ($step === 2) {
            return ['spouse_name','marriage_place','marriage_date','family_status','citizenship','economic_activity','last_occupation','education','children_count'];
        }
        return ['relative_name','relative_quality','relative_address','id_return_request','has_address_card','address_card_number','has_driver_license','driver_license_number','has_passport','passport_number'];
    }

    private static function format_value($key, $value) {
        if (is_null($value)) $value = '';
        if (is_bool($value)) $value = $value ? '1' : '0';
        $v = (string) $value;

        if (in_array($key, ['id_return_request','has_address_card','has_driver_license','has_passport'], true)) {
            if ($v === 'yes') return 'igen';
            if ($v === 'no') return 'nem';
        }

        return $v;
    }

    public static function pairs_for_step($step, $arr) {
        if (!is_array($arr)) $arr = [];
        $labels = self::field_labels();
        $order = self::expected_order($step);

        $seen = [];
        $out = [];
        foreach ($order as $k) {
            $seen[$k] = true;
            $label = $labels[$k] ?? $k;
            $val = $arr[$k] ?? '';
            $out[] = [$label, self::format_value($k, $val)];
        }

        // Append any unknown keys (future-proof)
        foreach ($arr as $k => $v) {
            if (isset($seen[$k])) continue;
            if (is_array($v)) continue;
            $label = $labels[$k] ?? (string)$k;
            $out[] = [$label, self::format_value($k, $v)];
        }

        return $out;
    }

    public static function render_step_table($step, $arr) {
        $pairs = self::pairs_for_step($step, $arr);
        if (!$pairs) return '<p>Nincs adat.</p>';
        $html = '<table class="widefat striped" style="margin:0;">';
        $html .= '<tbody>';
        foreach ($pairs as $p) {
            $html .= '<tr><th style="width:35%;">' . esc_html($p[0]) . '</th><td>' . esc_html($p[1]) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }



    public static function flatten_pairs($arr, $prefix = '') {
        $out = [];
        if (!is_array($arr)) return $out;
        foreach ($arr as $k => $v) {
            $key = ($prefix !== '') ? ($prefix . '.' . $k) : (string)$k;
            if (is_array($v)) {
                $out = array_merge($out, self::flatten_pairs($v, $key));
            } else {
                if (is_bool($v)) $v = $v ? '1' : '0';
                if (is_null($v)) $v = '';
                $out[] = [$key, (string)$v];
            }
        }
        return $out;
    }

    public static function handle_export_case_csv() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');

        $case_id = sanitize_text_field(wp_unslash($_GET['case_id'] ?? ''));
        if (!$case_id) wp_die('Hiányzó ügyazonosító.');
        check_admin_referer('tku_export_case_' . $case_id);

        $case = TKU_DB::get_case_by_case_id($case_id);
        if (!$case) wp_die('Nincs ilyen ügy.');

        $events = TKU_DB::get_events($case_id);
        $s1 = $case['step1'] ? json_decode($case['step1'], true) : [];
        $s2 = $case['step2'] ? json_decode($case['step2'], true) : [];
        $s3 = $case['step3'] ? json_decode($case['step3'], true) : [];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ugy_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', $case_id) . '.csv"');

        // UTF-8 BOM Excel kompatibilitás
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Szekció', 'Kulcs', 'Érték']);

        // Általános
        $general = [
            'Ügyazonosító' => $case['case_id'],
            'Státusz' => TKU_Shortcodes::status_label($case['status']),
            'Név' => $case['name'],
            'Email' => $case['email'],
            'Telefon' => $case['phone'],
            'Létrehozva' => $case['created_at'],
            'Frissítve' => $case['updated_at'],
            'Beküldve' => $case['submitted_at'],
            'Anonimizálva' => $case['anonymized_at'],
        ];
        foreach ($general as $k => $v) {
            fputcsv($out, ['Általános', $k, (string)$v]);
        }

        // Lépések
        foreach (self::pairs_for_step(1, $s1) as $p) fputcsv($out, ['Lépés 1', $p[0], $p[1]]);
        foreach (self::pairs_for_step(2, $s2) as $p) fputcsv($out, ['Lépés 2', $p[0], $p[1]]);
        foreach (self::pairs_for_step(3, $s3) as $p) fputcsv($out, ['Lépés 3', $p[0], $p[1]]);

        // Események
        if (is_array($events)) {
            foreach ($events as $ev) {
                $msg = $ev['message'] ?? '';
                fputcsv($out, ['Esemény', ($ev['event_type'] ?? '') . ' @ ' . ($ev['created_at'] ?? ''), $msg]);
            }
        }

        fclose($out);
        exit;
    }

    public static function handle_export_case_print() {
        if (!current_user_can('manage_options')) wp_die('Nincs jogosultság.');

        $case_id = sanitize_text_field(wp_unslash($_GET['case_id'] ?? ''));
        if (!$case_id) wp_die('Hiányzó ügyazonosító.');
        check_admin_referer('tku_export_case_' . $case_id);

        $case = TKU_DB::get_case_by_case_id($case_id);
        if (!$case) wp_die('Nincs ilyen ügy.');

        $events = TKU_DB::get_events($case_id);
        $s1 = $case['step1'] ? json_decode($case['step1'], true) : [];
        $s2 = $case['step2'] ? json_decode($case['step2'], true) : [];
        $s3 = $case['step3'] ? json_decode($case['step3'], true) : [];

        header('Content-Type: text/html; charset=utf-8');

        $title = 'Ügy export - ' . esc_html($case['case_id']);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $title . '</title>';
        echo '<style>
            body{font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif; margin:24px; color:#111;}
            h1{font-size:22px;margin:0 0 10px;}
            h2{font-size:16px;margin:18px 0 8px; border-bottom:1px solid #ddd; padding-bottom:6px;}
            table{width:100%; border-collapse:collapse; margin:8px 0 14px;}
            th,td{border:1px solid #ddd; padding:8px; vertical-align:top; font-size:13px;}
            th{background:#f6f7f7; text-align:left; width:28%;}
            .meta{color:#555; font-size:13px; margin:0 0 14px;}
            .btn{display:inline-block; padding:8px 12px; border:1px solid #1d2327; border-radius:4px; text-decoration:none; color:#1d2327;}
            .no-print{margin:0 0 14px;}
            @media print{ .no-print{display:none;} body{margin:10mm;} }
        </style></head><body>';

        echo '<div class="no-print"><a class="btn" href="#" onclick="window.print();return false;">Nyomtatás / Mentés PDF-be</a></div>';

        echo '<h1>Ügy export: ' . esc_html($case['case_id']) . '</h1>';
        echo '<p class="meta"><strong>Státusz:</strong> ' . esc_html(TKU_Shortcodes::status_label($case['status'])) . ' &nbsp; | &nbsp; <strong>Létrehozva:</strong> ' . esc_html($case['created_at']) . ' &nbsp; | &nbsp; <strong>Frissítve:</strong> ' . esc_html($case['updated_at']) . '</p>';

        // General table
        echo '<h2>Általános adatok</h2><table>';
        $rows = [
            'Név' => $case['name'],
            'Email' => $case['email'],
            'Telefon' => $case['phone'],
            'Beküldve' => $case['submitted_at'],
            'Anonimizálva' => $case['anonymized_at'],
        ];
        foreach ($rows as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html((string)$v) . '</td></tr>';
        }
        echo '</table>';

        // Steps
        $render_step = function($title, $arr) {
            echo '<h2>' . esc_html($title) . '</h2>';
            if (!$arr || !is_array($arr)) { echo '<p>Nincs adat.</p>'; return; }
            echo '<table>';
            foreach (TKU_Admin::pairs_for_step((int) preg_replace('/[^0-9]/','', $title), $arr) as $p) {
                echo '<tr><th>' . esc_html($p[0]) . '</th><td>' . esc_html($p[1]) . '</td></tr>';
            }
            echo '</table>';
        };
        $render_step('Lépés 1', $s1);
        $render_step('Lépés 2', $s2);
        $render_step('Lépés 3', $s3);

        // Events
        echo '<h2>Eseménynapló</h2>';
        if (!$events) {
            echo '<p>Nincs esemény.</p>';
        } else {
            echo '<table><thead><tr><th>Időpont</th><th>Típus</th><th>Üzenet</th></tr></thead><tbody>';
            foreach ($events as $ev) {
                echo '<tr><td>' . esc_html($ev['created_at'] ?? '') . '</td><td>' . esc_html($ev['event_type'] ?? '') . '</td><td>' . esc_html($ev['message'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<p class="meta">Generálva: ' . esc_html(current_time('mysql')) . '</p>';

        echo '</body></html>';
        exit;
    }


}
