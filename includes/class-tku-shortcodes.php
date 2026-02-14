<?php
if (!defined('ABSPATH')) exit;

class TKU_Shortcodes {
    public static function init() {
        add_shortcode('tku_case_start', [__CLASS__, 'sc_start']);
        add_shortcode('tku_case_continue', [__CLASS__, 'sc_continue']);
        add_shortcode('tku_case_status', [__CLASS__, 'sc_status']);
    }

    public static function status_label($status) {
        $map = [
            'new' => 'Új',
            'in_progress' => 'Folyamatban',
            'submitted' => 'Beküldve',
            'processing' => 'Feldolgozás alatt',
            'need_more' => 'Hiánypótlás szükséges',
            'closed' => 'Lezárva',
            'anonymized' => 'Anonimizált',
        ];
        return $map[$status] ?? $status;
    }

    private static function status_help_text($status) {
        $map = [
            'new' => 'Az ügy rögzítve lett, hamarosan feldolgozásra kerül.',
            'in_progress' => 'Az űrlap még szerkeszthető, bármikor folytatható a mentett linkkel.',
            'submitted' => 'Az ügyet sikeresen véglegesítetted, hamarosan megkezdjük a feldolgozást.',
            'processing' => 'Az ügy aktív feldolgozás alatt áll.',
            'need_more' => 'További adatra vagy dokumentumra van szükség. Kérjük ellenőrizd az emailjeidet.',
            'closed' => 'Az ügy lezárásra került.',
            'anonymized' => 'Az ügy adatai anonimizálásra kerültek az adatmegőrzési szabályok szerint.',
        ];
        return $map[$status] ?? '';
    }

    private static function format_case_datetime($value) {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00 00:00:00') return '';

        $ts = strtotime($value);
        if (!$ts) return '';

        return wp_date('Y.m.d. H:i', $ts);
    }


    private static function render_status_result($status, $updated, $show_status_help) {
        $status = sanitize_key((string)$status);
        $status_label = self::status_label($status);

        $out = '<div class="tku-success tku-status-panel">';
        $out .= '<div class="tku-status-row">';
        $out .= '<span class="tku-status-label">Státusz</span>';
        $out .= '<span class="tku-status-badge tku-status-' . esc_attr($status) . '">' . esc_html($status_label) . '</span>';
        $out .= '</div>';

        if ($updated) {
            $out .= '<div class="tku-status-meta">Utoljára frissítve: ' . esc_html($updated) . '</div>';
        }

        if ($show_status_help) {
            $help = self::status_help_text($status);
            if ($help) {
                $out .= '<div class="tku-status-help">' . esc_html($help) . '</div>';
            }
        }

        $out .= '</div>';
        return $out;
    }

    private static function get_ip() {
        // Best-effort
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = explode(',', $_SERVER[$k])[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    private static function rate_limit_hit($action, $max_per_hour) {
        $ip = self::get_ip();
        $key = 'tku_rl_' . $action . '_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= $max_per_hour) return true;
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return false;
    }

    private static function verify_turnstile($token) {
        if (!get_option('tku_turnstile_enabled', 0)) return [true, ''];
        $secret = (string) get_option('tku_turnstile_secret_key', '');
        if (!$secret) return [false, 'Turnstile secret key nincs beállítva.'];

        $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => self::get_ip(),
            ]
        ]);
        if (is_wp_error($resp)) return [false, 'Turnstile ellenőrzés sikertelen.'];
        $body = wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['success'])) return [true, ''];
        return [false, 'Kérjük igazold, hogy nem vagy robot.'];
    }

    private static function sanitize_theme($theme) {
        $theme = strtolower(trim((string)$theme));
        if (in_array($theme, ['auto','light','dark'], true)) return $theme;
        return 'auto';
    }

    private static function sanitize_css_size($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        // Allow simple, safe CSS sizes (used only in a CSS variable)
        if (preg_match('/^\d+(?:\.\d+)?\s*(px|%|rem|em|vw)$/i', $value)) {
            return preg_replace('/\s+/', '', $value);
        }
        return '';
    }

    private static function sanitize_hex($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        $hex = sanitize_hex_color($value);
        return $hex ? $hex : '';
    }

    private static function sanitize_bool($value) {
        $v = strtolower(trim((string)$value));
        return ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on');
    }

    private static function sanitize_opacity($value, $default = 0.0) {
        $v = trim((string)$value);
        if ($v === '') return (float)$default;
        // allow 0..1 or 0..100
        if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
            $n = (float)$v;
            if ($n > 1.0) $n = $n / 100.0;
            if ($n < 0.0) $n = 0.0;
            if ($n > 1.0) $n = 1.0;
            return $n;
        }
        return (float)$default;
    }

    private static function sanitize_image_url($value) {
        $url = trim((string)$value);
        if ($url === '') return '';
        $url = esc_url_raw($url);
        if (!$url) return '';
        // only http(s)
        if (!preg_match('/^https?:\/\//i', $url)) return '';
        return $url;
    }

    private static function parse_ui_atts($atts) {
        $ui = [];
        $ui['fullbleed'] = self::sanitize_bool($atts['fullbleed'] ?? '0');
        $ui['anim'] = self::sanitize_bool($atts['anim'] ?? '1');
        $ui['show_header'] = self::sanitize_bool($atts['show_header'] ?? '0');
        $ui['subtitle'] = sanitize_text_field((string)($atts['subtitle'] ?? ''));
        $ui['bg_mode'] = strtolower(trim((string)($atts['bg_mode'] ?? 'none')));
        if (!in_array($ui['bg_mode'], ['none','soft','color','image'], true)) $ui['bg_mode'] = 'none';
        $ui['bg_color'] = self::sanitize_hex($atts['bg_color'] ?? '');
        $ui['bg_image'] = self::sanitize_image_url($atts['bg_image'] ?? '');
        $ui['overlay_color'] = self::sanitize_hex($atts['overlay_color'] ?? '');
        $ui['overlay_opacity'] = self::sanitize_opacity($atts['overlay_opacity'] ?? '', 0.0);
        $ui['pad_x'] = self::sanitize_css_size($atts['pad_x'] ?? '');
        $ui['pad_y'] = self::sanitize_css_size($atts['pad_y'] ?? '');
        $ui['logo'] = self::sanitize_image_url($atts['logo'] ?? '');
        $ui['logo_size'] = self::sanitize_css_size($atts['logo_size'] ?? '');
        $ui['hero_image'] = self::sanitize_image_url($atts['hero_image'] ?? '');
        $ui['watermark'] = self::sanitize_image_url($atts['watermark'] ?? '');
        $ui['watermark_auto'] = self::sanitize_bool($atts['watermark_auto'] ?? '0');
        $ui['watermark_opacity'] = self::sanitize_opacity($atts['watermark_opacity'] ?? '', 0.08);
        $ui['watermark_size'] = self::sanitize_css_size($atts['watermark_size'] ?? '');
        $ui['card_style'] = strtolower(trim((string)($atts['card_style'] ?? 'solid')));
        if (!in_array($ui['card_style'], ['solid','glass'], true)) $ui['card_style'] = 'solid';

        if (!$ui['watermark'] && $ui['watermark_auto'] && $ui['logo']) {
            $ui['watermark'] = $ui['logo'];
        }
        return $ui;
    }

    private static function render_logo($logo_url) {
        if ($logo_url) {
            return '<img src="' . esc_url($logo_url) . '" alt="" loading="lazy" decoding="async" />';
        }
        // simple, calm inline SVG fallback
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 2c4.4 0 8 3.6 8 8 0 5.2-4.3 10.2-7.3 12.6-.4.3-1 .3-1.4 0C8.3 20.2 4 15.2 4 10c0-4.4 3.6-8 8-8Z" stroke="currentColor" stroke-width="1.6" opacity="0.85"/><path d="M9.2 10.2c1.2 1.7 2.8 2.8 5.6 3.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" opacity="0.85"/></svg>';
    }

    private static function render_hero_media($hero_image_url) {
        if ($hero_image_url) {
            return '<img src="' . esc_url($hero_image_url) . '" alt="" loading="lazy" decoding="async" />';
        }
        // friendly abstract blob
        return '<svg viewBox="0 0 420 280" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs><linearGradient id="g" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="rgba(59,130,246,0.25)"/><stop offset="1" stop-color="rgba(16,185,129,0.22)"/></linearGradient></defs><path d="M72 152c-10-68 40-120 120-132 74-10 160 20 200 80 46 68 4 156-78 184-78 27-226 24-242-132Z" fill="url(#g)"/><path d="M120 170c22 30 54 48 96 52 64 6 120-22 146-70" fill="none" stroke="rgba(255,255,255,0.55)" stroke-width="8" stroke-linecap="round"/></svg>';
    }

    private static function wrap_open($theme, $max_width, $accent = '', $accent_text = '', $ui = [], $header_title = '', $header_subtitle = '') {
        $theme = self::sanitize_theme($theme);
        $max_width = self::sanitize_css_size($max_width);
        $accent = self::sanitize_hex($accent);
        $accent_text = self::sanitize_hex($accent_text);

        $ui = is_array($ui) ? $ui : [];

        $cls = 'tku-wrap tku-section tku-theme-' . $theme;
        if (!empty($ui['fullbleed'])) $cls .= ' tku-fullbleed';
        if (!empty($ui['anim'])) $cls .= ' tku-anim';
        $bg_mode = $ui['bg_mode'] ?? 'none';
        if ($bg_mode === 'soft') $cls .= ' tku-bg-soft';
        if ($bg_mode === 'color') $cls .= ' tku-bg-color';
        if ($bg_mode === 'image') $cls .= ' tku-bg-image';

        $vars = [];
        if ($max_width) $vars[] = '--tku-max-width:' . $max_width;
        if ($accent) $vars[] = '--tku-accent:' . $accent;
        if ($accent_text) $vars[] = '--tku-accent-text:' . $accent_text;
        if (!empty($ui['pad_x'])) $vars[] = '--tku-pad-x:' . $ui['pad_x'];
        if (!empty($ui['pad_y'])) $vars[] = '--tku-pad-y:' . $ui['pad_y'];
        if (!empty($ui['bg_color'])) $vars[] = '--tku-bg-color:' . $ui['bg_color'];
        if (!empty($ui['bg_image'])) $vars[] = "--tku-bg-image:url('" . esc_url($ui['bg_image']) . "')";
        if (!empty($ui['overlay_color'])) $vars[] = '--tku-overlay-color:' . $ui['overlay_color'];
        if (!empty($ui['overlay_opacity'])) $vars[] = '--tku-overlay-opacity:' . ((float)$ui['overlay_opacity']);
        if (!empty($ui['logo_size'])) $vars[] = '--tku-logo-size:' . $ui['logo_size'];

        if (!empty($ui['watermark'])) $vars[] = "--tku-watermark-image:url('" . esc_url($ui['watermark']) . "')";
        if (isset($ui['watermark_opacity'])) $vars[] = '--tku-watermark-opacity:' . ((float)$ui['watermark_opacity']);
        if (!empty($ui['watermark_size'])) $vars[] = '--tku-watermark-size:' . $ui['watermark_size'];

        $style = $vars ? ' style="' . esc_attr(implode(';', $vars) . ';') . '"' : '';

        $out = '<section class="' . esc_attr($cls) . '"' . $style . '><div class="tku-container">';

        if (!empty($ui['watermark'])) {
            $out .= '<div class="tku-watermark" aria-hidden="true"></div>';
        }

        if (!empty($ui['show_header'])) {
            $title = sanitize_text_field($header_title);
            $subtitle = sanitize_text_field($header_subtitle);
            $out .= '<div class="tku-hero tku-reveal">'
                 .  '<div class="tku-hero-left">'
                 .    '<div class="tku-logo" aria-hidden="true">' . self::render_logo($ui['logo'] ?? '') . '</div>'
                 .    '<div class="tku-hero-copy">'
                 .      '<h2 class="tku-hero-title">' . esc_html($title) . '</h2>'
                 .      ($subtitle ? '<p class="tku-hero-subtitle">' . esc_html($subtitle) . '</p>' : '')
                 .    '</div>'
                 .  '</div>'
                 .  '<div class="tku-hero-right">'
                 .    '<div class="tku-hero-media" aria-hidden="true">' . self::render_hero_media($ui['hero_image'] ?? '') . '</div>'
                 .  '</div>'
                 . '</div>';
        }

        return $out;
    }

    private static function wrap_close() {
        return '</div></section>';
    }

    public static function sc_start($atts = []) {
        wp_enqueue_style('tku-styles');
        wp_enqueue_script('tku-forms');

        $atts = shortcode_atts([
            'title' => 'Ügy indítása',
            'button' => 'Ügy indítása',
            'max_width' => '',
            'theme' => 'auto',
            'accent' => '',
            'accent_text' => '',
            // UI options (optional)
            'fullbleed' => '0',
            'bg_mode' => 'none',
            'bg_color' => '',
            'bg_image' => '',
            'overlay_color' => '',
            'overlay_opacity' => '',
            'pad_x' => '',
            'pad_y' => '',
            'show_header' => '0',
            'subtitle' => '',
            'logo' => '',
            'logo_size' => '',
            'hero_image' => '',
            'watermark' => '',
            'watermark_auto' => '0',
            'watermark_opacity' => '',
            'watermark_size' => '',
            'card_style' => 'solid',
            'anim' => '1',
        ], $atts, 'tku_case_start');

        $title = sanitize_text_field($atts['title']);
        $button_label = sanitize_text_field($atts['button']);
        $theme = self::sanitize_theme($atts['theme']);
        $max_width = self::sanitize_css_size($atts['max_width']);
        $accent = self::sanitize_hex($atts['accent']);
        $accent_text = self::sanitize_hex($atts['accent_text']);

        $ui = self::parse_ui_atts($atts);
        wp_enqueue_script('tku-ui');

        $out = '';
        $errors = [];
        $success = '';
        $redirect_url = '';

        // Preserve entered values (better UX on validation errors)
        $old_name  = sanitize_text_field(wp_unslash($_POST['tku_name'] ?? ''));
        $old_email = sanitize_email(wp_unslash($_POST['tku_email'] ?? ''));
        $old_phone = sanitize_text_field(wp_unslash($_POST['tku_phone'] ?? ''));
        $old_deceased_name = sanitize_text_field(wp_unslash($_POST['tku_deceased_name'] ?? ''));

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['tku_form'] ?? '') === 'start') && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'tku_start')) {
            $max = (int) get_option('tku_rl_start_per_hour', 5);
            if (self::rate_limit_hit('start', max(1, $max))) {
                $errors[] = 'Túl sok próbálkozás. Kérjük próbáld később.';
            } else if (!empty($_POST['tku_hp'])) {
                $errors[] = 'Hiba történt. Kérjük próbáld újra.';
            } else {
                $name = $old_name;
                $email = $old_email;
                $phone = $old_phone;

                if (!$name) $errors[] = 'A név megadása kötelező.';
                if (!$old_deceased_name) $errors[] = 'Az elhunyt neve kötelező.';
                if (!$email || !is_email($email)) $errors[] = 'Érvényes email cím szükséges.';

                if (!$errors) {
                    $turnstile_token = sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'] ?? ''));
                    [$ok, $msg] = self::verify_turnstile($turnstile_token);
                    if (!$ok) $errors[] = $msg;
                }

                if (!$errors) {
                    $created = TKU_DB::create_case($name, $email, $phone, ['deceased_name' => $old_deceased_name]);
                    $case_id = $created['case_id'];
                    $token = $created['token'];

                    $continue_page_id = (int) get_option('tku_continue_page_id');
                    $continue_url = $continue_page_id ? get_permalink($continue_page_id) : home_url('/');
                    $continue_url = add_query_arg(['case_id' => $case_id, 'token' => $token], $continue_url);

                    $sent = TKU_Mail::send_start_email($email, $name, $case_id, $continue_url, $old_deceased_name);
                    TKU_DB::log_event($case_id, 'start_email_sent', $sent ? 'Email kiküldve.' : 'Email küldés sikertelen.');

                    // Always show a clear on-page result (and a fallback continue link)
                    $success = 'Sikeres mentés! <strong>Ügyazonosító:</strong> ' . esc_html($case_id)
                             . '<br><a href="' . esc_url($continue_url) . '">Folytatás most</a>'
                             . '<div class="tku-small">Ha nem érkezik meg az email pár percen belül, ellenőrizd a Spam mappát, vagy állíts be SMTP-t (pl. WP Mail SMTP).</div>';

                    if (!$sent) {
                        $errors[] = 'Az email küldése nem sikerült. Az ügy ettől függetlenül elmentve; a folytatáshoz használd a fenti linket.';
                    }
                }
            }
        }

        $site_key = (string) get_option('tku_turnstile_site_key', '');
        $turnstile_enabled = (int) get_option('tku_turnstile_enabled', 0);

        $out .= self::wrap_open($theme, $max_width, $accent, $accent_text, $ui, $title, $ui['subtitle'] ?? '');
        $card_cls = 'tku-card tku-reveal';
        if (($ui['card_style'] ?? 'solid') === 'glass') $card_cls .= ' tku-card-glass';
        $out .= '<div class="' . esc_attr($card_cls) . '">';
        if (empty($ui['show_header'])) {
            $out .= '<h3>' . esc_html($title) . '</h3>';
        }
        if ($success) {
            $out .= '<div class="tku-success">' . wp_kses_post($success);
            if ($redirect_url) {
                $out .= ' <a href="' . esc_url($redirect_url) . '">Ha nem lép tovább automatikusan, kattints ide.</a>';
            }
            $out .= '</div>';
        }
        if ($redirect_url) {
            // JS redirect (PRG) to avoid form resubmission and header-related issues
            $out .= '<script>(function(){try{window.location.replace(' . wp_json_encode($redirect_url) . ');}catch(e){}})();</script>';
        }
        if ($errors) {
            $out .= '<div class="tku-error"><ul>';
            foreach ($errors as $e) $out .= '<li>' . esc_html($e) . '</li>';
            $out .= '</ul></div>';
        }

        $out .= '<form method="post" class="tku-form" novalidate>'; // keep browser UX, but avoid native tooltip spam
        $out .= wp_nonce_field('tku_start', '_wpnonce', true, false);
        $out .= '<input type="hidden" name="tku_form" value="start" />';
        $out .= '<div class="tku-field">'
              . '<label for="tku_name">Név <span class="tku-required">*</span></label>'
              . '<input id="tku_name" type="text" name="tku_name" value="' . esc_attr($old_name) . '" autocomplete="name" required />'
              . '</div>';
        $out .= '<div class="tku-field">'
              . '<label for="tku_deceased_name">Elhunyt neve <span class="tku-required">*</span></label>'
              . '<input id="tku_deceased_name" type="text" name="tku_deceased_name" value="' . esc_attr($old_deceased_name) . '" required />'
              . '<div class="tku-help">Az anyakönyvvezetői ügyintézéshez szükséges adatlaphoz.</div>'
              . '</div>';
        $out .= '<div class="tku-field">'
              . '<label for="tku_email">Email <span class="tku-required">*</span></label>'
              . '<input id="tku_email" type="email" name="tku_email" value="' . esc_attr($old_email) . '" autocomplete="email" required />'
              . '<div class="tku-help">Ide küldjük az ügyazonosítót és a folytatás linket.</div>'
              . '</div>';
        $out .= '<div class="tku-field">'
              . '<label for="tku_phone">Telefon (opcionális)</label>'
              . '<input id="tku_phone" type="tel" name="tku_phone" value="' . esc_attr($old_phone) . '" autocomplete="tel" />'
              . '</div>';
        // honeypot
        $out .= '<input type="text" name="tku_hp" class="tku-hp" tabindex="-1" autocomplete="off" />';

        if ($turnstile_enabled && $site_key) {
            $out .= '<div class="tku-turnstile"><div class="cf-turnstile" data-sitekey="' . esc_attr($site_key) . '"></div></div>';
            // load turnstile script
            $out .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }

        $out .= '<div class="tku-actions">'
              . '<button type="submit" name="tku_start_submit" value="1">' . esc_html($button_label) . '</button>'
              . '</div>';
        $out .= '</form></div>' . self::wrap_close();

        return $out;
    }
    public static function sc_continue($atts = []) {
        wp_enqueue_style('tku-styles');
        wp_enqueue_script('tku-forms');

        $atts = shortcode_atts([
            'title' => 'Ügyintézés folytatása',
            'label_back' => 'Vissza',
            'label_save' => 'Mentés',
            'label_next' => 'Tovább',
            'label_finalize' => 'Véglegesítés',
            'max_width' => '',
            'theme' => 'auto',
            'accent' => '',
            'accent_text' => '',
            // UI options (optional)
            'fullbleed' => '0',
            'bg_mode' => 'none',
            'bg_color' => '',
            'bg_image' => '',
            'overlay_color' => '',
            'overlay_opacity' => '',
            'pad_x' => '',
            'pad_y' => '',
            'show_header' => '0',
            'subtitle' => '',
            'logo' => '',
            'logo_size' => '',
            'hero_image' => '',
            'watermark' => '',
            'watermark_auto' => '0',
            'watermark_opacity' => '',
            'watermark_size' => '',
            'card_style' => 'solid',
            'anim' => '1',
        ], $atts, 'tku_case_continue');

        $title = sanitize_text_field($atts['title']);
        $label_back = sanitize_text_field($atts['label_back']);
        $label_save = sanitize_text_field($atts['label_save']);
        $label_next = sanitize_text_field($atts['label_next']);
        $label_finalize = sanitize_text_field($atts['label_finalize']);
        $theme = self::sanitize_theme($atts['theme']);
        $max_width = self::sanitize_css_size($atts['max_width']);
        $accent = self::sanitize_hex($atts['accent']);
        $accent_text = self::sanitize_hex($atts['accent_text']);
        $ui = self::parse_ui_atts($atts);
        wp_enqueue_script('tku-ui');
        $wrap_open = self::wrap_open($theme, $max_width, $accent, $accent_text, $ui, $title, $ui['subtitle'] ?? '');
        $wrap_close = self::wrap_close();

        // Accept identifiers from GET (normal) or POST (fallback if query string is stripped)
        $case_id = sanitize_text_field(wp_unslash($_GET['case_id'] ?? ($_POST['case_id'] ?? '')));
        $token   = sanitize_text_field(wp_unslash($_GET['token'] ?? ($_POST['token'] ?? '')));
        $step    = (int) (wp_unslash($_GET['step'] ?? ($_POST['step'] ?? 1)));
        if ($step < 1 || $step > 3) $step = 1;

        $card_cls = 'tku-card tku-reveal';
        if (($ui['card_style'] ?? 'solid') === 'glass') $card_cls .= ' tku-card-glass';
        $out = $wrap_open . '<div class="' . esc_attr($card_cls) . '">';
        if (empty($ui['show_header'])) {
            $out .= '<h3>' . esc_html($title) . '</h3>';
        }

        if (!$case_id || !$token) {
            $out .= '<div class="tku-error">Hiányzó azonosító vagy token. Kérjük az emailben kapott linket használd.</div></div>' . $wrap_close;
            return $out;
        }

        [$ok, $case, $msg] = TKU_DB::verify_continue_token($case_id, $token);
        if (!$ok) {
            $out .= '<div class="tku-error">' . esc_html($msg) . '</div></div>' . $wrap_close;
            return $out;
        }

        // decode steps
        $s1 = $case['step1'] ? json_decode($case['step1'], true) : [];
        $s2 = $case['step2'] ? json_decode($case['step2'], true) : [];
        $s3 = $case['step3'] ? json_decode($case['step3'], true) : [];
        if (!is_array($s1)) $s1 = [];
        if (!is_array($s2)) $s2 = [];
        if (!is_array($s3)) $s3 = [];

        $errors = [];
        $success = '';
        $redirect_url = '';

        $posted_continue = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && (($_POST['tku_form'] ?? '') === 'continue');

        // handle save/next/back/finalize
        if ($posted_continue) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'tku_continue')) {
                $errors[] = 'Biztonsági ellenőrzés sikertelen. Kérjük frissítsd az oldalt és próbáld újra.';
            } else {
                $action = sanitize_text_field(wp_unslash($_POST['tku_action'] ?? 'save'));

                // collect step data
                if ($step === 1) {
                    $data = [
                        'deceased_name'    => sanitize_text_field(wp_unslash($_POST['deceased_name'] ?? '')),
                        'deceased_address' => sanitize_text_field(wp_unslash($_POST['deceased_address'] ?? '')),
                        'mother_name'      => sanitize_text_field(wp_unslash($_POST['mother_name'] ?? '')),
                        'father_name'      => sanitize_text_field(wp_unslash($_POST['father_name'] ?? '')),
                        'birth_name'       => sanitize_text_field(wp_unslash($_POST['birth_name'] ?? '')),
                        'birth_place'      => sanitize_text_field(wp_unslash($_POST['birth_place'] ?? '')),
                        'birth_date'       => sanitize_text_field(wp_unslash($_POST['birth_date'] ?? '')),
                        'death_place'      => sanitize_text_field(wp_unslash($_POST['death_place'] ?? '')),
                        'death_date'       => sanitize_text_field(wp_unslash($_POST['death_date'] ?? '')),
                        'burial_place'     => sanitize_textarea_field(wp_unslash($_POST['burial_place'] ?? '')),
                    ];

                    if (!$data['deceased_name']) $errors[] = 'Az elhunyt neve kötelező.';
                    if (!$data['deceased_address']) $errors[] = 'Az elhunyt címe kötelező.';
                    if (!$data['mother_name']) $errors[] = 'Anyja neve kötelező.';
                    if (!$data['father_name']) $errors[] = 'Apja neve kötelező.';
                    if (!$data['birth_name']) $errors[] = 'Születési név kötelező.';
                    if (!$data['birth_place']) $errors[] = 'Születési hely kötelező.';
                    if (!$data['birth_date']) $errors[] = 'Születési idő (dátum) kötelező.';
                    if (!$data['death_place']) $errors[] = 'Elhalálozás helye kötelező.';
                    if (!$data['death_date']) $errors[] = 'Elhalálozás ideje (dátum) kötelező.';
                    if (!$data['burial_place']) $errors[] = 'Temetés / urna elhelyezésének helye kötelező.';

                    if (!$errors && !TKU_DB::update_steps($case_id, 1, $data)) $errors[] = 'Mentés közben hiba történt. Kérjük próbáld újra.';
                } elseif ($step === 2) {
                    $children_count = wp_unslash($_POST['children_count'] ?? '');
                    $children_count = (string) preg_replace('/[^\d]/', '', (string)$children_count);

                    $data = [
                        'spouse_name'       => sanitize_text_field(wp_unslash($_POST['spouse_name'] ?? '')),
                        'marriage_place'    => sanitize_text_field(wp_unslash($_POST['marriage_place'] ?? '')),
                        'marriage_date'     => sanitize_text_field(wp_unslash($_POST['marriage_date'] ?? '')),
                        'family_status'     => sanitize_text_field(wp_unslash($_POST['family_status'] ?? '')),
                        'citizenship'       => sanitize_text_field(wp_unslash($_POST['citizenship'] ?? '')),
                        'economic_activity' => sanitize_text_field(wp_unslash($_POST['economic_activity'] ?? '')),
                        'last_occupation'   => sanitize_text_field(wp_unslash($_POST['last_occupation'] ?? '')),
                        'education'         => sanitize_text_field(wp_unslash($_POST['education'] ?? '')),
                        'children_count'    => $children_count,
                    ];

                    if (!$data['family_status']) $errors[] = 'Családi állapot megadása kötelező.';
                    if (!$data['citizenship']) $errors[] = 'Állampolgárság megadása kötelező.';
                    if (!$data['economic_activity']) $errors[] = 'Gazdasági aktivitás megadása kötelező.';
                    if (!$data['education']) $errors[] = 'Iskolai végzettség megadása kötelező.';
                    if ($data['children_count'] == '') $errors[] = 'Gyermekei száma kötelező (0 is lehet).';

                    if (!$errors && !TKU_DB::update_steps($case_id, 2, $data)) $errors[] = 'Mentés közben hiba történt. Kérjük próbáld újra.';
                } else {
                    $yn = function($v){
                        $v = sanitize_text_field(wp_unslash($v ?? ''));
                        return in_array($v, ['yes','no'], true) ? $v : '';
                    };

                    $data = [
                        'relative_name'       => sanitize_text_field(wp_unslash($_POST['relative_name'] ?? '')),
                        'relative_quality'    => sanitize_text_field(wp_unslash($_POST['relative_quality'] ?? '')),
                        'relative_address'    => sanitize_text_field(wp_unslash($_POST['relative_address'] ?? '')),

                        'id_return_request'   => $yn($_POST['id_return_request'] ?? ''),
                        'has_address_card'    => $yn($_POST['has_address_card'] ?? ''),
                        'address_card_number' => sanitize_text_field(wp_unslash($_POST['address_card_number'] ?? '')),

                        'has_driver_license'     => $yn($_POST['has_driver_license'] ?? ''),
                        'driver_license_number'  => sanitize_text_field(wp_unslash($_POST['driver_license_number'] ?? '')),

                        'has_passport'        => $yn($_POST['has_passport'] ?? ''),
                        'passport_number'     => sanitize_text_field(wp_unslash($_POST['passport_number'] ?? '')),
                    ];

                    if (!$data['relative_name']) $errors[] = 'Hozzátartozó neve kötelező.';
                    if (!$data['relative_quality']) $errors[] = 'Hozzátartozó minősége kötelező.';
                    if (!$data['relative_address']) $errors[] = 'Hozzátartozó címe kötelező.';

                    if (!$data['id_return_request']) $errors[] = 'Válaszd ki: a személyazonosító igazolványt visszakéred-e (igen/nem).';
                    if (!$data['has_address_card']) $errors[] = 'Válaszd ki: rendelkezik-e lakcímkártyával (igen/nem).';
                    if ($data['has_address_card'] === 'yes' && !$data['address_card_number']) $errors[] = 'Lakcímkártya száma kötelező, ha „igen”.';

                    if (!$data['has_driver_license']) $errors[] = 'Válaszd ki: rendelkezik-e vezetői engedéllyel (igen/nem).';
                    if ($data['has_driver_license'] === 'yes' && !$data['driver_license_number']) $errors[] = 'Vezetői engedély száma kötelező, ha „igen”.';

                    if (!$data['has_passport']) $errors[] = 'Válaszd ki: rendelkezik-e érvényes magyar útlevéllel (igen/nem).';
                    if ($data['has_passport'] === 'yes' && !$data['passport_number']) $errors[] = 'Útlevél száma kötelező, ha „igen”.';

                    if (!$errors && !TKU_DB::update_steps($case_id, 3, $data)) $errors[] = 'Mentés közben hiba történt. Kérjük próbáld újra.';
                }

                if (!$errors) {
                    // Refresh case after save
                    $fresh_case = TKU_DB::get_case_by_case_id($case_id);
                    if (is_array($fresh_case)) {
                        $case = $fresh_case;
                    } else {
                        $errors[] = 'Az ügy nem található. Kérjük frissítsd az oldalt és próbáld újra.';
                    }
                    $s1 = $case['step1'] ? json_decode($case['step1'], true) : [];
                    $s2 = $case['step2'] ? json_decode($case['step2'], true) : [];
                    $s3 = $case['step3'] ? json_decode($case['step3'], true) : [];
                    if (!is_array($s1)) $s1 = [];
                    if (!is_array($s2)) $s2 = [];
                    if (!is_array($s3)) $s3 = [];

                    if ($action === 'back' || $action === 'next') {
                        $new_step = ($action === 'back') ? max(1, $step - 1) : min(3, $step + 1);

                        // Client-side PRG (avoids "white screen" issues in some builders/themes).
                        // We still render the next step immediately as a no-JS fallback.
                        $redirect_url = add_query_arg(['case_id'=>$case_id,'token'=>$token,'step'=>$new_step], self::current_url_base());
                        $step = $new_step;
                        $success = 'Mentve, továbblépés... <a href="' . esc_url($redirect_url) . '">Ha nem lép tovább automatikusan, kattints ide.</a>';
                    } elseif ($action === 'finalize') {
                        // Avoid duplicate finalization on refresh
                        if (($case['status'] ?? '') === 'submitted' && !empty($case['submitted_at'])) {
                            $success = 'Az adatlap már korábban véglegesítve lett. Köszönjük!';
                        } else {
                            $missing = self::missing_required_fields($s1, $s2, $s3);
                            if ($missing) {
                                $errors[] = 'Még hiányoznak kötelező adatok:';
                                foreach ($missing as $m) $errors[] = $m;
                            } else {
                                if (!TKU_DB::finalize_case($case_id)) {
                                    $errors[] = 'Véglegesítés közben hiba történt. Kérjük próbáld újra.';
                                } else {
                                    // Refresh case after finalize for correct status display + email variables
                                    $fresh_case = TKU_DB::get_case_by_case_id($case_id);
                                    if (is_array($fresh_case)) {
                                        $case = $fresh_case;
                                        $sent_admin = TKU_Mail::send_admin_submission_email($case);
                                        TKU_DB::log_event($case_id, 'admin_submit_notify', $sent_admin ? 'Admin értesítő email elküldve.' : 'Admin értesítő email nem ment ki (lehet tiltva).');
                                    }
                                    $success = 'Köszönjük! Az adatlapot véglegesítettük. Hamarosan jelentkezünk.';
                                }
                            }
                        }
                    } else {
                        $success = 'Mentve.';
                    }
                }
            }
        }

        // progress header
        $labels = [1 => 'Elhunyt adatai', 2 => 'Családi adatok', 3 => 'Hozzátartozó & okmányok'];
        $out .= '<div class="tku-progress" aria-label="Kitöltés lépései">';
        for ($i=1;$i<=3;$i++){
            $cls = $i === $step ? 'active' : ($i < $step ? 'done' : '');
            $out .= '<span class="tku-step ' . esc_attr($cls) . '">' .
                    '<span class="tku-step-num">' . (int)$i . '</span>' .
                    '<span class="tku-step-text">' . esc_html($labels[$i]) . '</span>' .
                    '</span>';
        }
        $out .= '</div>';

        $out .= '<div class="tku-small">Ügyazonosító: <strong>' . esc_html($case_id) . '</strong> | Státusz: <strong>' . esc_html(self::status_label($case['status'] ?? '')) . '</strong></div>';

        if ($success) $out .= '<div class="tku-success">' . wp_kses_post($success) . '</div>';
        if ($errors) {
            $out .= '<div class="tku-error"><ul>';
            foreach ($errors as $e) $out .= '<li>' . esc_html($e) . '</li>';
            $out .= '</ul></div>';
        }

        // render form for step
        $form_action = add_query_arg(['case_id'=>$case_id,'token'=>$token,'step'=>$step], self::current_url_base());
        $out .= '<form method="post" action="' . esc_url($form_action) . '" class="tku-form" novalidate>';
        $out .= '<input type="hidden" name="case_id" value="' . esc_attr($case_id) . '" />';
        $out .= '<input type="hidden" name="token" value="' . esc_attr($token) . '" />';
        $out .= '<input type="hidden" name="step" value="' . (int)$step . '" />';
        $out .= wp_nonce_field('tku_continue', '_wpnonce', true, false);
        $out .= '<input type="hidden" name="tku_form" value="continue" />';

        if ($step === 1) {
            $out .= '<div class="tku-grid tku-grid-2">';
            $out .= '<div class="tku-field"><label for="deceased_name">Az elhunyt neve <span class="tku-required">*</span></label>'
                  . '<input id="deceased_name" type="text" name="deceased_name" value="' . esc_attr($s1['deceased_name'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="deceased_address">Az elhunyt címe <span class="tku-required">*</span></label>'
                  . '<input id="deceased_address" type="text" name="deceased_address" value="' . esc_attr($s1['deceased_address'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="mother_name">Anyja neve <span class="tku-required">*</span></label>'
                  . '<input id="mother_name" type="text" name="mother_name" value="' . esc_attr($s1['mother_name'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="father_name">Apja neve <span class="tku-required">*</span></label>'
                  . '<input id="father_name" type="text" name="father_name" value="' . esc_attr($s1['father_name'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="birth_name">Születési neve <span class="tku-required">*</span></label>'
                  . '<input id="birth_name" type="text" name="birth_name" value="' . esc_attr($s1['birth_name'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="birth_place">Születési helye <span class="tku-required">*</span></label>'
                  . '<input id="birth_place" type="text" name="birth_place" value="' . esc_attr($s1['birth_place'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="birth_date">Születési ideje <span class="tku-required">*</span></label>'
                  . '<input id="birth_date" type="date" name="birth_date" value="' . esc_attr($s1['birth_date'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="death_place">Elhalálozás helye <span class="tku-required">*</span></label>'
                  . '<input id="death_place" type="text" name="death_place" value="' . esc_attr($s1['death_place'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="death_date">Elhalálozás ideje <span class="tku-required">*</span></label>'
                  . '<input id="death_date" type="date" name="death_date" value="' . esc_attr($s1['death_date'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field tku-span-2"><label for="burial_place">Temetés / urna elhelyezésének helye <span class="tku-required">*</span></label>'
                  . '<textarea id="burial_place" name="burial_place" rows="4" required>' . esc_textarea($s1['burial_place'] ?? '') . '</textarea>'
                  . '<div class="tku-help">A hozzátartozó nyilatkozata alapján.</div>'
                  . '</div>';

            $out .= '</div>';
        } elseif ($step === 2) {
            $out .= '<div class="tku-grid tku-grid-2">';

            $out .= '<div class="tku-field"><label for="spouse_name">Házastárs neve</label>'
                  . '<input id="spouse_name" type="text" name="spouse_name" value="' . esc_attr($s2['spouse_name'] ?? '') . '" /></div>';

            $out .= '<div class="tku-field"><label for="family_status">Családi állapota <span class="tku-required">*</span></label>'
                  . '<input id="family_status" type="text" name="family_status" value="' . esc_attr($s2['family_status'] ?? '') . '" placeholder="pl. nőtlen/hajadon, házas, elvált, özvegy" required /></div>';

            $out .= '<div class="tku-field"><label for="marriage_place">Házasságkötés helye</label>'
                  . '<input id="marriage_place" type="text" name="marriage_place" value="' . esc_attr($s2['marriage_place'] ?? '') . '" /></div>';

            $out .= '<div class="tku-field"><label for="marriage_date">Házasságkötés ideje</label>'
                  . '<input id="marriage_date" type="date" name="marriage_date" value="' . esc_attr($s2['marriage_date'] ?? '') . '" /></div>';

            $out .= '<div class="tku-field"><label for="citizenship">Állampolgársága <span class="tku-required">*</span></label>'
                  . '<input id="citizenship" type="text" name="citizenship" value="' . esc_attr($s2['citizenship'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="economic_activity">Gazdasági aktivitása <span class="tku-required">*</span></label>'
                  . '<input id="economic_activity" type="text" name="economic_activity" value="' . esc_attr($s2['economic_activity'] ?? '') . '" placeholder="pl. aktív, nyugdíjas, munkanélküli, tanuló" required /></div>';

            $out .= '<div class="tku-field"><label for="last_occupation">Utolsó foglalkozása</label>'
                  . '<input id="last_occupation" type="text" name="last_occupation" value="' . esc_attr($s2['last_occupation'] ?? '') . '" /></div>';

            $out .= '<div class="tku-field"><label for="education">Iskolai végzettsége <span class="tku-required">*</span></label>'
                  . '<input id="education" type="text" name="education" value="' . esc_attr($s2['education'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="children_count">Gyermekei száma <span class="tku-required">*</span></label>'
                  . '<input id="children_count" type="number" min="0" step="1" name="children_count" value="' . esc_attr($s2['children_count'] ?? '') . '" required /></div>';

            $out .= '</div>';
        } else {
            // Step 3
            $radio = function($name, $current, $id_yes, $id_no, $label, $help_yes = '') {
                $out = '<div class="tku-field tku-span-2"><label>' . esc_html($label) . ' <span class="tku-required">*</span></label>';
                $out .= '<div class="tku-radio">';
                $out .= '<label for="' . esc_attr($id_yes) . '"><input id="' . esc_attr($id_yes) . '" type="radio" name="' . esc_attr($name) . '" value="yes" ' . checked($current, 'yes', false) . ' required /> igen</label>';
                $out .= '<label for="' . esc_attr($id_no) . '"><input id="' . esc_attr($id_no) . '" type="radio" name="' . esc_attr($name) . '" value="no" ' . checked($current, 'no', false) . ' required /> nem</label>';
                $out .= '</div>';
                if ($help_yes) $out .= '<div class="tku-help">' . esc_html($help_yes) . '</div>';
                $out .= '</div>';
                return $out;
            };

            $out .= '<div class="tku-grid tku-grid-2">';

            $out .= '<div class="tku-field"><label for="relative_name">Hozzátartozó neve <span class="tku-required">*</span></label>'
                  . '<input id="relative_name" type="text" name="relative_name" value="' . esc_attr($s3['relative_name'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-field"><label for="relative_quality">Hozzátartozói minősége <span class="tku-required">*</span></label>'
                  . '<input id="relative_quality" type="text" name="relative_quality" value="' . esc_attr($s3['relative_quality'] ?? '') . '" placeholder="pl. házastárs, gyermek, testvér" required /></div>';

            $out .= '<div class="tku-field tku-span-2"><label for="relative_address">Hozzátartozó címe <span class="tku-required">*</span></label>'
                  . '<input id="relative_address" type="text" name="relative_address" value="' . esc_attr($s3['relative_address'] ?? '') . '" required /></div>';

            $out .= '<div class="tku-divider tku-span-2"></div>';

            $out .= $radio('id_return_request', $s3['id_return_request'] ?? '', 'id_return_yes', 'id_return_no', 'A személyazonosító igazolványt visszakérem');

            $out .= $radio('has_address_card', $s3['has_address_card'] ?? '', 'addr_yes', 'addr_no',
                'Az elhunyt rendelkezik-e lakcímigazolvánnyal?',
                'Igen esetén szíveskedjen a lakcímkártyát az ügyintézéshez átadni.'
            );
            $out .= '<div class="tku-field tku-conditional tku-span-2" data-cond="has_address_card" data-show="yes" data-required="1">'
                  . '<label for="address_card_number">Lakcímkártya száma <span class="tku-required">*</span></label>'
                  . '<input id="address_card_number" type="text" name="address_card_number" value="' . esc_attr($s3['address_card_number'] ?? '') . '" />'
                  . '</div>';

            $out .= $radio('has_driver_license', $s3['has_driver_license'] ?? '', 'dl_yes', 'dl_no',
                'Az elhunyt rendelkezik-e kártya formátumú vezetői engedéllyel?',
                'Igen esetén szíveskedjen a vezetői engedélyt az ügyintézéshez átadni.'
            );
            $out .= '<div class="tku-field tku-conditional tku-span-2" data-cond="has_driver_license" data-show="yes" data-required="1">'
                  . '<label for="driver_license_number">Vezetői engedély száma <span class="tku-required">*</span></label>'
                  . '<input id="driver_license_number" type="text" name="driver_license_number" value="' . esc_attr($s3['driver_license_number'] ?? '') . '" />'
                  . '</div>';

            $out .= $radio('has_passport', $s3['has_passport'] ?? '', 'pass_yes', 'pass_no',
                'Az elhunyt rendelkezik-e érvényes magyar útlevéllel?',
                'Igen esetén szíveskedjen az útlevelet az ügyintézéshez átadni.'
            );
            $out .= '<div class="tku-field tku-conditional tku-span-2" data-cond="has_passport" data-show="yes" data-required="1">'
                  . '<label for="passport_number">Útlevél száma <span class="tku-required">*</span></label>'
                  . '<input id="passport_number" type="text" name="passport_number" value="' . esc_attr($s3['passport_number'] ?? '') . '" />'
                  . '</div>';

            // Missing summary to help the user
            $missing = self::missing_required_fields($s1, $s2, $s3);
            if ($missing) {
                $out .= '<div class="tku-hint tku-span-2"><strong>Hiányzó kötelező mezők a véglegesítéshez:</strong><ul>';
                foreach ($missing as $m) $out .= '<li>' . esc_html($m) . '</li>';
                $out .= '</ul></div>';
            } else {
                $out .= '<div class="tku-hint tku-span-2"><strong>Minden kötelező mező kitöltve.</strong> Véglegesítheted az adatlapot.</div>';
            }

            $out .= '</div>';
        }

        $out .= '<div class="tku-actions">';
        if ($step > 1) {
            $out .= '<button type="submit" name="tku_action" value="back" class="secondary">' . esc_html($label_back) . '</button>';
        }
        if ($step < 3) {
            $out .= '<button type="submit" name="tku_action" value="save" class="secondary">' . esc_html($label_save) . '</button>';
            $out .= '<button type="submit" name="tku_action" value="next">' . esc_html($label_next) . '</button>';
        } else {
            $out .= '<button type="submit" name="tku_action" value="save" class="secondary">' . esc_html($label_save) . '</button>';
            $out .= '<button type="submit" name="tku_action" value="finalize">' . esc_html($label_finalize) . '</button>';
        }
        $out .= '</div>';

        $out .= '</form></div>';

        if (!empty($redirect_url)) {
            $out .= '<script>(function(){try{window.location.replace(' . wp_json_encode($redirect_url) . ');}catch(e){}})();</script>';
        }

        $out .= $wrap_close;

        return $out;
    }
    public static function build_continue_url($case_id, $token, $step = 1) {
        $continue_page_id = (int) get_option('tku_continue_page_id');
        $base = $continue_page_id ? get_permalink($continue_page_id) : home_url('/');
        $args = ['case_id' => $case_id, 'token' => $token];
        $step = (int) $step;
        if ($step > 0) $args['step'] = $step;
        return add_query_arg($args, $base);
    }

    private static function current_url_base() {
        // Prefer the configured "continue" page permalink (most stable)
        $continue_page_id = (int) get_option('tku_continue_page_id');
        if ($continue_page_id) {
            $p = get_permalink($continue_page_id);
            if ($p) return $p;
        }

        // Fallback: current post permalink
        global $post;
        if (!empty($post) && isset($post->ID)) {
            $p = get_permalink($post->ID);
            if ($p) return $p;
        }

        // Fallback: current request URI (path only)
        $req = $_SERVER['REQUEST_URI'] ?? '';
        if ($req && is_string($req)) {
            $path = strtok($req, '?');
            if ($path) return home_url($path);
        }

        return home_url('/');
    }
    private static function missing_required_fields($s1, $s2, $s3) {
        $missing = [];
        if (!is_array($s1)) $s1 = [];
        if (!is_array($s2)) $s2 = [];
        if (!is_array($s3)) $s3 = [];

        // Step 1 core fields
        if (empty($s1['deceased_name'])) $missing[] = 'Az elhunyt neve (Lépés 1)';
        if (empty($s1['deceased_address'])) $missing[] = 'Az elhunyt címe (Lépés 1)';
        if (empty($s1['mother_name'])) $missing[] = 'Anyja neve (Lépés 1)';
        if (empty($s1['father_name'])) $missing[] = 'Apja neve (Lépés 1)';
        if (empty($s1['birth_name'])) $missing[] = 'Születési név (Lépés 1)';
        if (empty($s1['birth_place'])) $missing[] = 'Születési hely (Lépés 1)';
        if (empty($s1['birth_date'])) $missing[] = 'Születési idő (Lépés 1)';
        if (empty($s1['death_place'])) $missing[] = 'Elhalálozás helye (Lépés 1)';
        if (empty($s1['death_date'])) $missing[] = 'Elhalálozás ideje (Lépés 1)';
        if (empty($s1['burial_place'])) $missing[] = 'Temetés / urna elhelyezésének helye (Lépés 1)';

        // Step 2 key fields
        if (empty($s2['family_status'])) $missing[] = 'Családi állapot (Lépés 2)';
        if (empty($s2['citizenship'])) $missing[] = 'Állampolgárság (Lépés 2)';
        if (empty($s2['economic_activity'])) $missing[] = 'Gazdasági aktivitás (Lépés 2)';
        if (empty($s2['education'])) $missing[] = 'Iskolai végzettség (Lépés 2)';
        if (!isset($s2['children_count']) || $s2['children_count'] === '') $missing[] = 'Gyermekei száma (Lépés 2)';

        // Step 3 relative
        if (empty($s3['relative_name'])) $missing[] = 'Hozzátartozó neve (Lépés 3)';
        if (empty($s3['relative_quality'])) $missing[] = 'Hozzátartozói minősége (Lépés 3)';
        if (empty($s3['relative_address'])) $missing[] = 'Hozzátartozó címe (Lépés 3)';

        // yes/no required fields
        $yn = function($v){ return in_array($v, ['yes','no'], true); };

        if (!$yn($s3['id_return_request'] ?? '')) $missing[] = 'Személyi igazolvány visszakérése (igen/nem) (Lépés 3)';

        if (!$yn($s3['has_address_card'] ?? '')) $missing[] = 'Lakcímkártya (igen/nem) (Lépés 3)';
        if (($s3['has_address_card'] ?? '') === 'yes' && empty($s3['address_card_number'])) $missing[] = 'Lakcímkártya száma (Lépés 3)';

        if (!$yn($s3['has_driver_license'] ?? '')) $missing[] = 'Vezetői engedély (igen/nem) (Lépés 3)';
        if (($s3['has_driver_license'] ?? '') === 'yes' && empty($s3['driver_license_number'])) $missing[] = 'Vezetői engedély száma (Lépés 3)';

        if (!$yn($s3['has_passport'] ?? '')) $missing[] = 'Útlevél (igen/nem) (Lépés 3)';
        if (($s3['has_passport'] ?? '') === 'yes' && empty($s3['passport_number'])) $missing[] = 'Útlevél száma (Lépés 3)';

        return $missing;
    }

    public static function sc_status($atts = []) {
        wp_enqueue_style('tku-styles');

        $atts = shortcode_atts([
            'title' => 'Ügy státusz lekérdezése',
            'button' => 'Lekérdezés',
            'show_status_help' => '1',
            'max_width' => '',
            'theme' => 'auto',
            'accent' => '',
            'accent_text' => '',
            // UI options (optional)
            'fullbleed' => '0',
            'bg_mode' => 'none',
            'bg_color' => '',
            'bg_image' => '',
            'overlay_color' => '',
            'overlay_opacity' => '',
            'pad_x' => '',
            'pad_y' => '',
            'show_header' => '0',
            'subtitle' => '',
            'logo' => '',
            'logo_size' => '',
            'hero_image' => '',
            'watermark' => '',
            'watermark_auto' => '0',
            'watermark_opacity' => '',
            'watermark_size' => '',
            'card_style' => 'solid',
            'anim' => '1',
        ], $atts, 'tku_case_status');

        $title = sanitize_text_field($atts['title']);
        $button_label = sanitize_text_field($atts['button']);
        $show_status_help = self::sanitize_bool($atts['show_status_help']);
        $theme = self::sanitize_theme($atts['theme']);
        $max_width = self::sanitize_css_size($atts['max_width']);
        $accent = self::sanitize_hex($atts['accent']);
        $accent_text = self::sanitize_hex($atts['accent_text']);
        $ui = self::parse_ui_atts($atts);
        wp_enqueue_script('tku-ui');
        $wrap_open = self::wrap_open($theme, $max_width, $accent, $accent_text, $ui, $title, $ui['subtitle'] ?? '');
        $wrap_close = self::wrap_close();

        $card_cls = 'tku-card tku-reveal';
        if (($ui['card_style'] ?? 'solid') === 'glass') $card_cls .= ' tku-card-glass';
        $out = $wrap_open . '<div class="' . esc_attr($card_cls) . '">';
        if (empty($ui['show_header'])) {
            $out .= '<h3>' . esc_html($title) . '</h3>';
        }
        $errors = [];
        $result = '';

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['tku_form'] ?? '') === 'status') && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'tku_status')) {
            $max = (int) get_option('tku_rl_status_per_hour', 20);
            if (self::rate_limit_hit('status', max(1, $max))) {
                $errors[] = 'Túl sok próbálkozás. Kérjük próbáld később.';
            } else {
                $case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
                $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
                $last4 = sanitize_text_field(wp_unslash($_POST['phone_last4'] ?? ''));

                if (!$case_id) $errors[] = 'Ügyazonosító megadása kötelező.';
                if (!$email || !is_email($email)) $errors[] = 'Érvényes email cím szükséges.';

                if (!$errors) {
                    $case = TKU_DB::get_case_by_case_id($case_id);
                    if (!$case) {
                        $errors[] = 'Nincs ilyen ügy.';
                    } else {
                        if (strtolower($case['email']) !== strtolower($email)) {
                            $errors[] = 'A megadott adatok nem egyeznek.';
                        } else {
                            $require_last4 = (int) get_option('tku_status_require_phone_last4', 0);
                            $stored_phone = preg_replace('/\D+/', '', (string)($case['phone'] ?? ''));
                            $need_check = $require_last4 && !empty($stored_phone);

                            if ($need_check) {
                                $last4_clean = preg_replace('/\D+/', '', (string)$last4);
                                if (strlen($last4_clean) < 4) {
                                    $errors[] = 'Add meg a telefonszám utolsó 4 számjegyét.';
                                } else {
                                    $stored_last4 = substr($stored_phone, -4);
                                    if ($stored_last4 !== substr($last4_clean, -4)) {
                                        $errors[] = 'A megadott adatok nem egyeznek.';
                                    }
                                }
                            }

                            if (!$errors) {
                                $updated = self::format_case_datetime($case['updated_at'] ?? '');
                                $result = self::render_status_result($case['status'] ?? '', $updated, $show_status_help);
                            }
                        }
                    }
                }
            }
        }

        if ($errors) {
            $out .= '<div class="tku-error"><ul>';
            foreach ($errors as $e) $out .= '<li>' . esc_html($e) . '</li>';
            $out .= '</ul></div>';
        }
        if ($result) $out .= $result;

        $require_last4 = (int) get_option('tku_status_require_phone_last4', 0);

        $old_case_id = sanitize_text_field(wp_unslash($_POST['case_id'] ?? ''));
        $old_email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $old_last4   = sanitize_text_field(wp_unslash($_POST['phone_last4'] ?? ''));

        $out .= '<form method="post" class="tku-form" novalidate>';
        $out .= wp_nonce_field('tku_status', '_wpnonce', true, false);
        $out .= '<input type="hidden" name="tku_form" value="status" />';
        $out .= '<div class="tku-field"><label for="case_id">Ügyazonosító <span class="tku-required">*</span></label>'
              . '<input id="case_id" type="text" name="case_id" value="' . esc_attr($old_case_id) . '" autocomplete="off" required />'
              . '<div class="tku-help">Az ügyazonosítót az emailben találod.</div>'
              . '</div>';
        $out .= '<div class="tku-field"><label for="email">Email <span class="tku-required">*</span></label>'
              . '<input id="email" type="email" name="email" value="' . esc_attr($old_email) . '" autocomplete="email" required /></div>';
        $out .= '<div class="tku-field"><label for="phone_last4">Telefon utolsó 4 számjegye' . ($require_last4 ? ' (ha van telefonszám megadva)' : ' (opcionális)') . '</label>'
              . '<input id="phone_last4" type="text" name="phone_last4" value="' . esc_attr($old_last4) . '" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="1234" />'
              . '</div>';
        $out .= '<div class="tku-actions">'
              . '<button type="submit" name="tku_status_submit" value="1">' . esc_html($button_label) . '</button>'
              . '</div>';
        $out .= '</form></div>' . $wrap_close;

        return $out;
    }
}
