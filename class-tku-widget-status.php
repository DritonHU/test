<?php
if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class TKU_Widget_Status extends Widget_Base {
    protected function register_controls() {
        $this->start_controls_section('tku_content', [
            'label' => 'Szövegek',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label' => 'Cím',
            'type' => Controls_Manager::TEXT,
            'default' => 'Ügy státusz lekérdezése',
        ]);

        $this->add_control('button_label', [
            'label' => 'Gomb szövege',
            'type' => Controls_Manager::TEXT,
            'default' => 'Lekérdezés',
        ]);

        $this->end_controls_section();

        $this->start_controls_section('tku_layout', [
            'label' => 'Megjelenés',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('fullbleed', [
            'label' => 'Teljes szélességű háttér',
            'type' => Controls_Manager::SWITCHER,
            'return_value' => '1',
            'default' => '1',
        ]);

        $this->add_control('bg_mode', [
            'label' => 'Háttér mód',
            'type' => Controls_Manager::SELECT,
            'default' => 'soft',
            'options' => [
                'none' => 'Nincs',
                'soft' => 'Lágy (ajánlott)',
                'color' => 'Egyszínű',
                'image' => 'Kép',
            ],
        ]);

        $this->add_control('bg_color', [
            'label' => 'Háttérszín',
            'type' => Controls_Manager::COLOR,
            'default' => '#f8fafc',
            'condition' => [ 'bg_mode' => 'color' ],
        ]);

        $this->add_control('bg_image', [
            'label' => 'Háttérkép',
            'type' => Controls_Manager::MEDIA,
            'condition' => [ 'bg_mode' => 'image' ],
        ]);

        $this->add_control('overlay_color', [
            'label' => 'Képfedés színe (overlay)',
            'type' => Controls_Manager::COLOR,
            'default' => '#0b1220',
            'condition' => [ 'bg_mode' => 'image' ],
        ]);

        $this->add_control('overlay_opacity', [
            'label' => 'Képfedés átlátszósága (%)',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['%'],
            'range' => [ '%' => ['min' => 0, 'max' => 80] ],
            'default' => [ 'size' => 18, 'unit' => '%' ],
            'condition' => [ 'bg_mode' => 'image' ],
        ]);

        $this->add_responsive_control('max_width', [
            'label' => 'Max szélesség',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px', '%', 'vw'],
            'range' => [
                'px' => ['min' => 280, 'max' => 1200],
                '%'  => ['min' => 40, 'max' => 100],
                'vw' => ['min' => 40, 'max' => 100],
            ],
            'default' => [
                'size' => 760,
                'unit' => 'px',
            ],
        ]);

        $this->add_responsive_control('pad_y', [
            'label' => 'Függőleges térköz (padding)',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => [ 'px' => ['min' => 12, 'max' => 120] ],
            'default' => [ 'size' => 44, 'unit' => 'px' ],
        ]);

        $this->add_responsive_control('pad_x', [
            'label' => 'Vízszintes térköz (padding)',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => [ 'px' => ['min' => 10, 'max' => 48] ],
            'default' => [ 'size' => 18, 'unit' => 'px' ],
        ]);

        $this->add_control('theme_mode', [
            'label' => 'Téma',
            'type' => Controls_Manager::SELECT,
            'default' => 'auto',
            'options' => [
                'auto' => 'Automatikus (rendszer szerint)',
                'light' => 'Világos',
                'dark' => 'Sötét',
            ],
        ]);

        $this->add_control('show_header', [
            'label' => 'Hero fejléc megjelenítése',
            'type' => Controls_Manager::SWITCHER,
            'return_value' => '1',
            'default' => '1',
            'separator' => 'before',
        ]);

        $this->add_control('subtitle', [
            'label' => 'Alcím',
            'type' => Controls_Manager::TEXTAREA,
            'rows' => 3,
            'default' => 'Add meg az emailben kapott ügyazonosítót és az email címedet a státusz megtekintéséhez.',
            'condition' => [ 'show_header' => '1' ],
        ]);

        $this->add_control('logo', [
            'label' => 'Logó',
            'type' => Controls_Manager::MEDIA,
            'condition' => [ 'show_header' => '1' ],
        ]);

        $this->add_control('logo_size', [
            'label' => 'Logó mérete',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => [ 'px' => ['min' => 28, 'max' => 88] ],
            'default' => [ 'size' => 48, 'unit' => 'px' ],
            'condition' => [ 'show_header' => '1' ],
        ]);

        $this->add_control('hero_image', [
            'label' => 'Hero kép (jobb oldal, asztali nézet)',
            'type' => Controls_Manager::MEDIA,
            'condition' => [ 'show_header' => '1' ],
        ]);

        $this->add_control('watermark_auto', [
            'label' => 'Vízjel automatikusan a logóból',
            'type' => Controls_Manager::SWITCHER,
            'return_value' => '1',
            'default' => '1',
        ]);

        $this->add_control('watermark', [
            'label' => 'Vízjel kép (felülírja az automatikusat)',
            'type' => Controls_Manager::MEDIA,
        ]);

        $this->add_control('watermark_opacity', [
            'label' => 'Vízjel átlátszóság (%)',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['%'],
            'range' => [ '%' => ['min' => 0, 'max' => 30] ],
            'default' => [ 'size' => 8, 'unit' => '%' ],
        ]);

        $this->add_control('watermark_size', [
            'label' => 'Vízjel mérete',
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => [ 'px' => ['min' => 120, 'max' => 520] ],
            'default' => [ 'size' => 260, 'unit' => 'px' ],
        ]);

        $this->add_control('card_style', [
            'label' => 'Kártya stílus',
            'type' => Controls_Manager::SELECT,
            'default' => 'glass',
            'options' => [
                'solid' => 'Normál',
                'glass' => 'Üveg (glass)',
            ],
        ]);

        $this->add_control('anim', [
            'label' => 'Finom animációk (beúszás)',
            'type' => Controls_Manager::SWITCHER,
            'return_value' => '1',
            'default' => '1',
        ]);

        
        $this->add_control('accent_color', [
            'label' => 'Kiemelő szín (accent)',
            'type' => Controls_Manager::COLOR,
            'default' => '',
            'description' => 'Ha megadod, a gombok/kiemelések ezt a színt használják.',
        ]);

        $this->add_control('accent_text_color', [
            'label' => 'Kiemelő szövegszín',
            'type' => Controls_Manager::COLOR,
            'default' => '',
            'description' => 'Pl. fehér (#ffffff) sötét accent esetén.',
        ]);
        $this->end_controls_section();
    }
    public function get_name() { return 'tku_status'; }
    public function get_title() { return 'Státusz lekérdezés (Temetkezés)'; }
    public function get_icon() { return 'eicon-search'; }
    public function get_categories() { return ['tku']; }

    protected function render() {
        $s = $this->get_settings_for_display();

        $atts = [];
        if (!empty($s['title'])) $atts['title'] = $s['title'];
        if (!empty($s['button_label'])) $atts['button'] = $s['button_label'];
        if (!empty($s['theme_mode'])) $atts['theme'] = $s['theme_mode'];
        if (!empty($s['max_width']['size']) && !empty($s['max_width']['unit'])) {
            $atts['max_width'] = $s['max_width']['size'] . $s['max_width']['unit'];
        }
        if (!empty($s['accent_color'])) $atts['accent'] = $s['accent_color'];
        if (!empty($s['accent_text_color'])) $atts['accent_text'] = $s['accent_text_color'];

        // UI props
        if (!empty($s['fullbleed'])) $atts['fullbleed'] = '1';
        if (!empty($s['bg_mode'])) $atts['bg_mode'] = $s['bg_mode'];
        if (!empty($s['bg_color'])) $atts['bg_color'] = $s['bg_color'];
        if (!empty($s['bg_image']['url'])) $atts['bg_image'] = $s['bg_image']['url'];
        if (!empty($s['overlay_color'])) $atts['overlay_color'] = $s['overlay_color'];
        // Allow 0% values (empty() would drop them).
        if (isset($s['overlay_opacity']['size'])) $atts['overlay_opacity'] = (string)$s['overlay_opacity']['size'];
        if (!empty($s['pad_x']['size']) && !empty($s['pad_x']['unit'])) $atts['pad_x'] = $s['pad_x']['size'] . $s['pad_x']['unit'];
        if (!empty($s['pad_y']['size']) && !empty($s['pad_y']['unit'])) $atts['pad_y'] = $s['pad_y']['size'] . $s['pad_y']['unit'];
        if (!empty($s['show_header'])) $atts['show_header'] = '1';
        if (!empty($s['subtitle'])) $atts['subtitle'] = $s['subtitle'];
        if (!empty($s['logo']['url'])) $atts['logo'] = $s['logo']['url'];
        if (!empty($s['logo_size']['size']) && !empty($s['logo_size']['unit'])) $atts['logo_size'] = $s['logo_size']['size'] . $s['logo_size']['unit'];
        if (!empty($s['hero_image']['url'])) $atts['hero_image'] = $s['hero_image']['url'];
        if (!empty($s['watermark_auto'])) $atts['watermark_auto'] = '1';
        if (!empty($s['watermark']['url'])) $atts['watermark'] = $s['watermark']['url'];
        // Allow 0% values (empty() would drop them).
        if (isset($s['watermark_opacity']['size'])) $atts['watermark_opacity'] = (string)$s['watermark_opacity']['size'];
        if (!empty($s['watermark_size']['size']) && !empty($s['watermark_size']['unit'])) $atts['watermark_size'] = $s['watermark_size']['size'] . $s['watermark_size']['unit'];
        if (!empty($s['card_style'])) $atts['card_style'] = $s['card_style'];
        if (!empty($s['anim'])) $atts['anim'] = '1';

        $sc = '[tku_case_status';
        foreach ($atts as $k => $v) {
            $sc .= ' ' . $k . '="' . esc_attr($v) . '"';
        }
        $sc .= ']';

        echo do_shortcode($sc);
    }
}
