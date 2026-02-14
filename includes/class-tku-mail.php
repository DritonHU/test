<?php
if (!defined('ABSPATH')) exit;

class TKU_Mail {
    public static function init(){}

    private static function headers() {
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from_name = sanitize_text_field((string) get_option('tku_mail_from_name', get_bloginfo('name')));
        $from_email = sanitize_email((string) get_option('tku_mail_from_email', get_option('admin_email')));
        if (!$from_email || !is_email($from_email)) {
            $from_email = sanitize_email((string) get_option('admin_email'));
        }

        if ($from_email) {
            $from = $from_name ? ($from_name . ' <' . $from_email . '>') : $from_email;
            $headers[] = 'From: ' . $from;
            $headers[] = 'Reply-To: ' . $from;
        }

        return $headers;
    }

    public static function send_start_email($email, $name, $case_id, $continue_url, $deceased_name = '') {
        $email = sanitize_email((string) $email);
        if (!$email || !is_email($email)) return false;

        $subject = wp_strip_all_tags(sprintf('Ügyintézés megkezdve: %s', (string) $case_id));

        $body = '<p>Kedves ' . esc_html((string) $name) . '!</p>'
              . '<p>Köszönjük! Az ügyintézést elkezdted.</p>'
              . '<p><strong>Ügyazonosító:</strong> ' . esc_html((string) $case_id) . '</p>'
              . (!empty($deceased_name) ? '<p><strong>Az elhunyt neve:</strong> ' . esc_html((string) $deceased_name) . '</p>' : '')
              . '<p>A folytatáshoz kattints ide:</p>'
              . '<p><a href="' . esc_url($continue_url) . '">' . esc_html($continue_url) . '</a></p>'
              . '<p>Ha nem te kezdeményezted, hagyd figyelmen kívül ezt az emailt.</p>';

        return wp_mail($email, $subject, $body, self::headers());
    }

    public static function send_continue_email($email, $name, $case_id, $continue_url, $deceased_name = '') {
        $email = sanitize_email((string) $email);
        if (!$email || !is_email($email)) return false;

        $subject = wp_strip_all_tags(sprintf('Ügyintézés folytatása: %s', (string) $case_id));

        $body = '<p>Kedves ' . esc_html((string) $name) . '!</p>'
              . '<p>Itt találod az ügyintézés folytatásához szükséges linket.</p>'
              . '<p><strong>Ügyazonosító:</strong> ' . esc_html((string) $case_id) . '</p>'
              . (!empty($deceased_name) ? '<p><strong>Az elhunyt neve:</strong> ' . esc_html((string) $deceased_name) . '</p>' : '')
              . '<p><a href="' . esc_url($continue_url) . '">' . esc_html($continue_url) . '</a></p>'
              . '<p>Ha nem te kérted, hagyd figyelmen kívül ezt az emailt.</p>';

        return wp_mail($email, $subject, $body, self::headers());
    }

    public static function send_status_notification($case, $new_status, $admin_message = '') {
        if (!get_option('tku_notify_enabled', 0)) return false;

        $statuses = (array) get_option('tku_notify_statuses', []);
        if (!in_array($new_status, $statuses, true)) return false;

        $email = sanitize_email((string) ($case['email'] ?? ''));
        if (!$email || !is_email($email)) return false;

        $name_plain = sanitize_text_field((string) ($case['name'] ?? ''));
        $case_id_plain = sanitize_text_field((string) ($case['case_id'] ?? ''));
        $admin_message_plain = sanitize_text_field((string) $admin_message);

        $vars_plain = [
            '{name}' => $name_plain,
            '{case_id}' => $case_id_plain,
            '{status_label}' => wp_strip_all_tags(TKU_Shortcodes::status_label($new_status)),
            '{admin_message}' => $admin_message_plain,
            '{site_name}' => sanitize_text_field((string) get_bloginfo('name')),
        ];

        $vars_html = [
            '{name}' => esc_html($name_plain),
            '{case_id}' => esc_html($case_id_plain),
            '{status_label}' => esc_html(TKU_Shortcodes::status_label($new_status)),
            '{admin_message}' => esc_html($admin_message_plain),
            '{admin_message_block}' => $admin_message_plain ? ('<p><strong>Megjegyzés:</strong> ' . esc_html($admin_message_plain) . '</p>') : '',
            '{site_name}' => esc_html((string) get_bloginfo('name')),
        ];

        $subject_tpl = (string) get_option('tku_notify_subject', 'Ügyintézés státusz frissült: {case_id}');
        $body_tpl = (string) get_option('tku_notify_body', '<p>Státusz: {status_label}</p>');

        $subject = wp_strip_all_tags(strtr($subject_tpl, $vars_plain));
        $body = strtr($body_tpl, $vars_html);

        return wp_mail($email, $subject, $body, self::headers());
    }

    public static function send_admin_submission_email($case) {
        if (!get_option('tku_admin_submit_notify_enabled', 0)) return false;

        $to = sanitize_email((string) get_option('tku_admin_submit_notify_email', get_option('admin_email')));
        if (!$to || !is_email($to)) return false;

        $case_id_plain = sanitize_text_field((string) ($case['case_id'] ?? ''));
        $name_plain = sanitize_text_field((string) ($case['name'] ?? ''));
        $email_plain = sanitize_email((string) ($case['email'] ?? ''));
        $deceased_plain = sanitize_text_field((string) ($case['deceased_name'] ?? ''));
        $relative_plain = sanitize_text_field((string) ($case['relative_name'] ?? ''));

        $admin_url = admin_url('admin.php?page=tku_cases&view=' . urlencode($case_id_plain));

        $vars_plain = [
            '{case_id}' => $case_id_plain,
            '{name}' => $name_plain,
            '{email}' => $email_plain,
            '{deceased_name}' => $deceased_plain,
            '{relative_name}' => $relative_plain,
            '{admin_url}' => $admin_url,
            '{site_name}' => sanitize_text_field((string) get_bloginfo('name')),
        ];

        $vars_html = [
            '{case_id}' => esc_html($case_id_plain),
            '{name}' => esc_html($name_plain),
            '{email}' => esc_html($email_plain),
            '{deceased_name}' => esc_html($deceased_plain),
            '{relative_name}' => esc_html($relative_plain),
            '{admin_url}' => esc_url($admin_url),
            '{site_name}' => esc_html((string) get_bloginfo('name')),
        ];

        $subject_tpl = (string) get_option('tku_admin_submit_notify_subject', 'Új véglegesített adatlap: {case_id}');
        $body_tpl = (string) get_option('tku_admin_submit_notify_body', '<p>Új véglegesített adatlap érkezett.</p><p><strong>Ügyazonosító:</strong> {case_id}</p><p><strong>Kapcsolattartó:</strong> {name} ({email})</p><p><strong>Elhunyt:</strong> {deceased_name}</p><p><a href="{admin_url}">Megnyitás az admin felületen</a></p>');

        $subject = wp_strip_all_tags(strtr($subject_tpl, $vars_plain));
        $body = strtr($body_tpl, $vars_html);

        return wp_mail($to, $subject, $body, self::headers());
    }
}
