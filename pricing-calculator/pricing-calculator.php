<?php
/**
 * Plugin Name: Pricing Calculator
 * Description: Dynamiczny kalkulator wycen (Alpine.js)
 * Version: 2.3.0
 * Author: Gryczan.eu
 */

if (!defined('ABSPATH')) exit;

define('PC3_URL', plugin_dir_url(__FILE__));
define('PC3_PATH', plugin_dir_path(__FILE__));

/* =========================
 * FRONTEND ASSETS - REJESTRACJA
 * ========================= */
add_action('wp_enqueue_scripts', function () {
    // Rejestrujemy Alpine (nie ładujemy go jeszcze)
    wp_register_script(
        'alpinejs',
        'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
        [],
        null,
        false
    );

    // Rejestrujemy główny skrypt kalkulatora
    wp_register_script(
        'pc3-frontend',
        PC3_URL . 'assets/js/frontend.js',
        [], 
        '4.1', // Zmieniamy wersję, aby odświeżyć cache
        false 
    );

    // Rejestrujemy bibliotekę PDF
    wp_register_script(
        'html2pdf',
        'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js',
        [],
        null,
        true
    );

    // Rejestrujemy style
    wp_register_style(
        'pc3-style',
        PC3_URL . 'assets/css/style.css',
        [],
        '4.1'
    );

    // Lokalizacja danych (nadal przypisana do zarejestrowanego skryptu)
    global $wpdb;
    $logo_url = $wpdb->get_var("SELECT setting_value FROM {$wpdb->prefix}pc3_settings WHERE setting_key = 'admin_logo'");

    wp_localize_script('pc3-frontend', 'PC3_AJAX', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pc3_nonce'),
        'logo'  => $logo_url
    ]);
});

// Defer dla Alpine
add_filter('script_loader_tag', function ($tag, $handle) {
    if ('alpinejs' !== $handle) return $tag;
    return str_replace(' src', ' defer src', $tag);
}, 10, 2);

// Admin Assets
add_action('admin_enqueue_scripts', function ($hook) {
    wp_enqueue_media(); // Ładuje skrypty biblioteki mediów WP

    // Alpine dla edycji kalkulatora
    global $post;
    if ($post && $post->post_type === 'pricing_calc') {
        wp_enqueue_script('alpinejs-admin', 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', [], null, true);
    }

    // CodeMirror dla zakładki Ustawienia
    if (strpos($hook, 'pc3_settings') !== false) {
        $settings = wp_enqueue_code_editor(['type' => 'text/css']);
        wp_add_inline_script('code-editor', sprintf('jQuery(function() { wp.codeEditor.initialize("pc3_css_editor", %s); });', wp_json_encode($settings)));
    }
});

/* ==========================================================================
   2. REJESTRACJA CPT I MENU
   ========================================================================== */

add_action('init', function () {
    register_post_type('pricing_calc', [
        'label' => 'Kalkulatory wycen',
        'public' => false,
        'show_ui' => true,
        'menu_icon' => 'dashicons-calculator',
        'supports' => ['title'],
    ]);
});

add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=pricing_calc', 'Leady', 'Leady', 'manage_options', 'pc3_leads', 'pc3_render_leads');
    add_submenu_page('edit.php?post_type=pricing_calc', 'Ustawienia', 'Ustawienia', 'manage_options', 'pc3_settings', 'pc3_render_settings');
});

/* ==========================================================================
   3. BAZA DANYCH & AKTYWACJA
   ========================================================================== */

register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE {$wpdb->prefix}pc3_leads (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        email varchar(100) NOT NULL,
        price varchar(20) NOT NULL,
        summary text NOT NULL,
        is_read tinyint(1) DEFAULT 0 NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    $sql2 = "CREATE TABLE {$wpdb->prefix}pc3_settings (
        setting_key varchar(50) NOT NULL,
        setting_value text NOT NULL,
        PRIMARY KEY  (setting_key)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
});

/* ==========================================================================
   4. OBSŁUGA FRONTENDU (AJAX & SHORTCODE)
   ========================================================================== */

add_shortcode('pricing_calculator', function ($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);
    $post = get_post($atts['id']);
    if (!$post) return '<p>Brak kalkulatora.</p>';

    // Ładujemy zarejestrowane assety TYLKO gdy shortcode jest wywołany
    wp_enqueue_script('alpinejs');
    wp_enqueue_script('pc3-frontend');
    wp_enqueue_script('html2pdf');
    wp_enqueue_style('pc3-style');
    // -----------------------

    $sections = get_post_meta($post->ID, '_pc_sections', true);
    if (!is_array($sections)) $sections = [];

    ob_start();
    include PC3_PATH . 'templates/calculator-view.php';
    return ob_get_clean();
});

add_action('wp_ajax_pc3_send', 'pc3_send_mail');
add_action('wp_ajax_nopriv_pc3_send', 'pc3_send_mail');

function pc3_send_mail() {
    check_ajax_referer('pc3_nonce', 'nonce');
    global $wpdb;

    // 1. Filtr antyspamowy
    if (!empty($_POST['hp_field'])) wp_send_json_error('Bot detected');

    $email   = sanitize_email($_POST['email'] ?? '');
    $price   = sanitize_text_field($_POST['price'] ?? '');
    $summary = stripslashes($_POST['summary'] ?? ''); // stripslashes usuwa zbędne ukośniki z tekstu

    if (!is_email($email)) wp_send_json_error('Niepoprawny email');

    // 2. Pobierz email administratora z Twojej nowej zakładki Ustawienia
    $admin_target = $wpdb->get_var("SELECT setting_value FROM {$wpdb->prefix}pc3_settings WHERE setting_key = 'admin_email'");
    if (!$admin_target) $admin_target = get_option('admin_email');

    // 3. Zapis do bazy danych (Punkt 5 Twojej listy - Leady)
    $wpdb->insert("{$wpdb->prefix}pc3_leads", [
        'email' => $email,
        'price' => $price,
        'summary' => sanitize_textarea_field($summary),
        'is_read' => 0
    ]);

    // 4. Przygotowanie nagłówków (Dodajemy 'From', żeby serwery nie odrzucały maili)
    $sitename = get_bloginfo('name');
    $admin_email = get_option('admin_email');
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $sitename . ' <' . $admin_email . '>'
    ];

    $body = "<h2>Dziękujemy za skorzystanie z kalkulatora</h2>
             <p>Oto podsumowanie Twojej wyceny:</p>
             <hr>
             <pre>{$summary}</pre>
             <p><strong>Łączna kwota: {$price} zł</strong></p>";

    // 5. Wysyłka do obu adresatów
    // Wysyłamy do klienta
    wp_mail($email, 'Twoja wycena - ' . $sitename, $body, $headers);
    
    // Wysyłamy kopię do admina (zmieniamy lekko treść, żeby admin wiedział od kogo to)
    $admin_body = "<h2>Nowa wycena od: $email</h2>" . $body;
    wp_mail($admin_target, 'Nowa wycena (Kopia dla Admina)', $admin_body, $headers);

    wp_send_json_success();
}

/* ==========================================================================
   5. PANEL: LEADY
   ========================================================================== */

function pc3_render_leads() {
    global $wpdb;
    
    // Obsługa akcji (Usuń / Przeczytaj)
    if (isset($_GET['action']) && isset($_GET['id'])) {
        $lead_id = intval($_GET['id']);
        if ($_GET['action'] === 'delete') {
            $wpdb->delete("{$wpdb->prefix}pc3_leads", ['id' => $lead_id]);
        } elseif ($_GET['action'] === 'mark_read') {
            $wpdb->update("{$wpdb->prefix}pc3_leads", ['is_read' => 1], ['id' => $lead_id]);
        }
    }

    $leads = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pc3_leads ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1>Leady z kalkulatora</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="150">Data</th>
                    <th>Email</th>
                    <th>Cena</th>
                    <th>Podsumowanie</th>
                    <th width="150">Status / Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                    <tr style="<?php echo $lead->is_read ? 'opacity:0.6' : 'font-weight:bold'; ?>">
                        <td><?php echo $lead->created_at; ?></td>
                        <td><?php echo esc_html($lead->email); ?></td>
                        <td><?php echo esc_html($lead->price); ?> zł</td>
                        <td><small><?php echo nl2br(esc_html($lead->summary)); ?></small></td>
                        <td>
                            <?php if (!$lead->is_read): ?>
                                <a href="?post_type=pricing_calc&page=pc3_leads&action=mark_read&id=<?php echo $lead->id; ?>" class="button button-small">Przeczytane</a>
                            <?php endif; ?>
                            <a href="?post_type=pricing_calc&page=pc3_leads&action=delete&id=<?php echo $lead->id; ?>" class="button button-small" onclick="return confirm('Usunąć?')">Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ────────────────────────────────────────────────
// Export / Import kalkulatorów
// ────────────────────────────────────────────────

add_action('admin_menu', function() {
    // Zakładam, że masz już submenu 'pc3_settings'
    // Jeśli nie – dodaj to samo miejsce gdzie masz istniejące ustawienia
    add_submenu_page(
        'edit.php?post_type=pricing_calc',     // parent slug (lista kalkulatorów)
        'Import / Eksport kalkulatorów',
        'Import / Eksport',
        'manage_options',
        'pc3_import_export',
        'pc3_render_import_export_page'
    );
}, 20); // 20 żeby było niżej niż inne podmenu

function pc3_render_import_export_page() {
    ?>
    <div class="wrap">
        <h1>Import / Eksport kalkulatorów</h1>

        <h2>Eksportuj wszystkie kalkulatory</h2>
        <p>Tworzy plik JSON zawierający wszystkie kalkulatory (tytuły, opisy, sekcje i opcje).</p>
        <form method="post" action="">
            <?php wp_nonce_field('pc3_export_calculators', 'pc3_export_nonce'); ?>
            <input type="hidden" name="pc3_action" value="export">
            <p><input type="submit" class="button button-primary" value="Pobierz eksport (.json)"></p>
        </form>

        <hr>

        <h2>Importuj kalkulatory z pliku JSON</h2>
        <p>Wgraj plik wcześniej wyeksportowany z tego panelu.</p>
        <p class="description" style="color:#c00;">Uwaga: istniejące kalkulatory o tej samej nazwie (slug) zostaną nadpisane!</p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('pc3_import_calculators', 'pc3_import_nonce'); ?>
            <input type="hidden" name="pc3_action" value="import">
            <p>
                <input type="file" name="pc3_import_file" accept=".json" required>
            </p>
            <p>
                <input type="submit" class="button button-primary" value="Importuj teraz">
            </p>
        </form>

        <?php
        // Obsługa akcji po przesłaniu
        if (isset($_POST['pc3_action'])) {
            if ($_POST['pc3_action'] === 'export' && check_admin_referer('pc3_export_calculators', 'pc3_export_nonce')) {
                pc3_handle_export();
            } elseif ($_POST['pc3_action'] === 'import' && check_admin_referer('pc3_import_calculators', 'pc3_import_nonce')) {
                pc3_handle_import();
            }
        }
        ?>
    </div>
    <?php
}

function pc3_handle_export() {
    $calculators = get_posts([
        'post_type'      => 'pricing_calc',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $data = [];

    foreach ($calculators as $calc) {
        $sections = get_post_meta($calc->ID, '_pc_sections', true);
        if (!is_array($sections)) $sections = [];

        $data[] = [
            'title'       => $calc->post_title,
            'slug'        => $calc->post_name,
            'content'     => $calc->post_content,           // jeśli używasz opisu
            'status'      => $calc->post_status,
            'sections'    => $sections,
            // możesz dodać więcej pól jeśli używasz (np. custom logo per kalkulator itd.)
        ];
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $filename = 'pricing-calculators-export-' . date('Y-m-d-His') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $json;
    exit;
}

function pc3_handle_import() {
    if (empty($_FILES['pc3_import_file']['tmp_name']) || $_FILES['pc3_import_file']['error'] !== UPLOAD_ERR_OK) {
        echo '<div class="notice notice-error"><p>Błąd wgrywania pliku.</p></div>';
        return;
    }

    $file = $_FILES['pc3_import_file']['tmp_name'];
    $content = file_get_contents($file);

    $data = json_decode($content, true);

    if (!is_array($data) || empty($data)) {
        echo '<div class="notice notice-error"><p>Nieprawidłowy format pliku JSON.</p></div>';
        return;
    }

    $imported = 0;
    $updated  = 0;

    foreach ($data as $item) {
        if (empty($item['title']) || empty($item['slug'])) continue;

        // Szukamy czy istnieje już kalkulator o takim slug
        $existing = get_posts([
            'post_type'      => 'pricing_calc',
            'post_status'    => ['publish', 'draft'],
            'name'           => $item['slug'],
            'posts_per_page' => 1,
        ]);

        $post_data = [
            'post_title'   => sanitize_text_field($item['title']),
            'post_name'    => sanitize_title($item['slug']),
            'post_content' => wp_kses_post($item['content'] ?? ''),
            'post_status'  => $item['status'] ?? 'publish',
            'post_type'    => 'pricing_calc',
        ];

        if (!empty($existing)) {
            // Aktualizacja
            $post_data['ID'] = $existing[0]->ID;
            $post_id = wp_update_post($post_data);
            $updated++;
        } else {
            // Nowy
            $post_id = wp_insert_post($post_data);
            $imported++;
        }

        if (is_wp_error($post_id)) continue;

        // Zapisujemy sekcje
        if (!empty($item['sections']) && is_array($item['sections'])) {
            // Tu możesz dodać walidację struktury jeśli chcesz być bardziej restrykcyjny
            update_post_meta($post_id, '_pc_sections', $item['sections']);
        }
    }

    echo '<div class="notice notice-success"><p>Import zakończony sukcesem.<br>'
        . 'Utworzono nowych: ' . $imported . '<br>'
        . 'Zaktualizowano istniejących: ' . $updated . '</p></div>';
}

/* ==========================================================================
   6. PANEL: USTAWIENIA & CSS
   ========================================================================== */

function pc3_render_settings() {
    global $wpdb;
    $css_path = PC3_PATH . 'assets/css/style.css';

    // Zapisywanie ustawień
    if (isset($_POST['pc3_save_settings'])) {
        check_admin_referer('pc3_settings_action');
        
        // 1. Zapis Email
        $new_email = sanitize_email($_POST['admin_email']);
        $wpdb->replace("{$wpdb->prefix}pc3_settings", ['setting_key' => 'admin_email', 'setting_value' => $new_email]);

        // 2. Zapis CSS do pliku
        if (isset($_POST['custom_css'])) {
            file_put_contents($css_path, stripslashes($_POST['custom_css']));
        }
        echo '<div class="updated"><p>Ustawienia zapisane.</p></div>';

        $wpdb->replace("{$wpdb->prefix}pc3_settings", ['setting_key' => 'admin_logo', 'setting_value' => esc_url_raw($_POST['admin_logo'])]);
    }
    
    // Pobieranie wartości:
    $current_logo = $wpdb->get_var("SELECT setting_value FROM {$wpdb->prefix}pc3_settings WHERE setting_key = 'admin_logo'");

    $current_email = $wpdb->get_var("SELECT setting_value FROM {$wpdb->prefix}pc3_settings WHERE setting_key = 'admin_email'");
    $current_css = file_exists($css_path) ? file_get_contents($css_path) : '';
    ?>
    <div class="wrap">
        <h1>Ustawienia Kalkulatora</h1>
        <form method="post">
            <?php wp_nonce_field('pc3_settings_action'); ?>

            <tr>
                <th>Logo w PDF:</th>
                <td>
                    <input type="text" name="admin_logo" id="pc3_logo_url" value="<?php echo esc_attr($current_logo); ?>" class="regular-text">
                    <button type="button" class="button" id="pc3_upload_logo_btn">Wybierz z biblioteki</button>
                    <div id="pc3_logo_preview" style="margin-top:10px;"><img src="<?php echo esc_attr($current_logo); ?>" style="max-height:50px;"></div>
                </td>
            </tr>

            <script>
            jQuery(document).ready(function($){
                $('#pc3_upload_logo_btn').click(function(e) {
                    e.preventDefault();
                    var image = wp.media({ title: 'Wybierz Logo', multiple: false }).open()
                    .on('select', function(e){
                        var uploaded_image = image.state().get('selection').first();
                        var image_url = uploaded_image.toJSON().url;
                        $('#pc3_logo_url').val(image_url);
                        $('#pc3_logo_preview img').attr('src', image_url);
                    });
                });
            });
            </script>

        
            <table class="form-table">
                <tr>
                    <th>Email do kopii wycen:</th>
                    <td><input type="email" name="admin_email" value="<?php echo esc_attr($current_email); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th>Edytuj style (assets/css/style.css):</th>
                    <td>
                        <textarea name="custom_css" id="pc3_css_editor" style="width:100%; height:400px;"><?php echo esc_textarea($current_css); ?></textarea>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="pc3_save_settings" class="button button-primary" value="Zapisz zmiany"></p>
        </form>
    </div>
    <?php
}

/* ==========================================================================
   7. ADMIN META BOX (EDYCJA KALKULATORA)
   ========================================================================== */

add_action('add_meta_boxes', function () {
    add_meta_box('pc3_sections', 'Struktura Kalkulatora', 'pc3_sections_render', 'pricing_calc', 'normal', 'high');
});

function pc3_sections_render($post) {
    wp_nonce_field('pc3_save', 'pc3_nonce_field');
    $sections = get_post_meta($post->ID, '_pc_sections', true) ?: [];
    ?>
    <div id="pc3-admin" x-data='pc3Admin(<?php echo wp_json_encode($sections); ?>)'>
        <template x-for="(section, s) in sections" :key="section.id">
            <div style="border:1px solid #ccc; padding:15px; margin-bottom:15px;">
                <div style="display:flex; gap:10px; margin-bottom:10px;">
                    <input type="text" x-model="section.label" placeholder="Nazwa sekcji (np. Wybierz kolor)">
                    <select x-model="section.type">
                        <option value="checkbox">Wielokrotny wybór (Checkbox)</option>
                        <option value="radio">Pojedynczy wybór (Radio)</option>
                    </select>
                    <label><input type="checkbox" x-model="section.required"> Wymagana</label>
                    <button type="button" class="button" @click="addOption(s)">+ Dodaj Opcję</button>
                    <button type="button" class="button" style="color:red" @click="sections.splice(s,1)">Usuń Sekcję</button>
                </div>

                <template x-for="(opt, o) in section.options" :key="opt.id">
                    <div style="margin-bottom:15px; padding-left:20px;">

                        <div style="margin-bottom:5px;">
                            <input type="text" x-model="opt.label" placeholder="Nazwa opcji">
                            <input type="number" x-model="opt.price" placeholder="Cena">
                            <button type="button" @click="section.options.splice(o,1)">×</button>
                        </div>

                        <!-- NOWY PRZEŁĄCZNIK -->
                        <div style="margin-top:10px;">
                            <label>
                                <input type="checkbox" x-model="opt.tooltip_enabled">
                                Włącz Tooltip
                            </label>
                        </div>

                        <!-- TEXTAREA POKAZUJE SIĘ TYLKO JEŚLI WŁĄCZONO TOOLTIP -->
                        <div style="margin-top:10px;" x-show="opt.tooltip_enabled">
                            <label><strong>Adnotacja (tooltip):</strong></label>
                            <textarea x-model="opt.note" style="width:100%; min-height:60px;"></textarea>
                        </div>

                    </div>
                </template>

                <div style="margin-top:15px;"> <label> <input type="checkbox" x-model="section.note_enabled"> Włącz uwagę (opis pod opcjami) </label> </div>
                <div style="margin-top:10px;" x-show="section.note_enabled"> <label><strong>Uwaga:</strong></label> <textarea x-model="section.note" style="width:100%; min-height:80px;"></textarea> </div>
                <!-- <div style="margin-top:15px;"> <label><strong>Uwaga (opis pod opcjami):</strong></label> <textarea x-model="section.note" style="width:100%; min-height:80px;"></textarea> </div> -->
            </div>
        </template>
        <button type="button" class="button button-primary" @click="addSection()">+ Nowa Sekcja</button>
        <input type="hidden" name="pc3_sections" :value="JSON.stringify(sections)">
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('pc3Admin', (data) => ({
            sections: data || [],
            addSection() {
                this.sections.push({ id: 'sec_'+Date.now(), label: '', type: 'checkbox', required: false, note_enabled: false, note: '', options: [] });
            },
            addOption(s) {
                this.sections[s].options.push({ id: 'opt_'+Date.now(), label: '', price: 0, tooltip_enabled: false, note: '' });
            }
        }));
    });
    </script>
    <?php
}

add_action('save_post_pricing_calc', function ($post_id) {
    if (!isset($_POST['pc3_nonce_field']) || !wp_verify_nonce($_POST['pc3_nonce_field'], 'pc3_save')) return;
    if (isset($_POST['pc3_sections'])) {
        $data = json_decode(stripslashes($_POST['pc3_sections']), true);
        update_post_meta($post_id, '_pc_sections', $data);
    }
});