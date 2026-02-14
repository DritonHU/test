Temetkezési Ügyintézés (Elementor) v1.3.1

- Elementor widgetek: Ügy indítása, Ügy folytatása, Státusz lekérdezés
- Multi-step folytatás (3 lépés) + hiányzó kötelező mezők összegző
- Admin: ügy lista + részletek + státusz váltás + eseménynapló
- Admin lista: kitöltöttség jelzés (Step1/2/3) + szűrők
- Opcionális Turnstile botvédelem, rate limit, email értesítés státuszváltáskor
- Opcionális admin értesítés véglegesítéskor (email)
- GDPR: adatmegőrzés (napok után) + automatikus anonimizálás/törlés + kézi gombok adminban
- Export: ügy adatainak letöltése CSV-be + nyomtatható nézet (Mentés PDF-be böngészőből)

UI / Elementor extra:
- Widget beállításokból állítható címek, gombszövegek, max szélesség, és téma (auto/világos/sötét)
- Full-width háttér + középre igazított tartalom (a háttér mehet teljes szélességre, a content nem tapad a szélekre)
- Hero fejléc (logó, alcím, opcionális hero kép)
- Vízjel (auto a logóból vagy külön kép)
- Lágy beúszó animációk (prefers-reduced-motion támogatással)
- Háttér: soft / szín / kép + overlay

Shortcode-ok:
[tku_case_start]
[tku_case_continue]
[tku_case_status]

Shortcode attribútumok (opcionális):
- start: title, button, max_width, theme
- continue: title, label_back, label_save, label_next, label_finalize, max_width, theme
- status: title, button, show_status_help, max_width, theme

Új UI attribútumok (mindhárom shortcode-nál opcionális):
fullbleed, bg_mode (none|soft|color|image), bg_color, bg_image, overlay_color, overlay_opacity,
pad_x, pad_y, show_header, subtitle, logo, logo_size, hero_image,
watermark, watermark_auto, watermark_opacity, watermark_size, card_style (solid|glass), anim


Új 1.3.0:
- Rendezett plugin struktúra (`includes/`, `includes/elementor/widgets/`, `assets/`)
- Státusz lekérdezésnél opcionális állapotmagyarázat + „utoljára frissítve” dátum


Új 1.3.1:
- Frontend finomítás: státusz lekérdezés eredmény megújult badge-es blokkban jelenik meg
- Finomabb kártya vizuális kiemelés (felső gradient sáv) a modernebb megjelenésért
