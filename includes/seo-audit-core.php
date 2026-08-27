<?php
// SEO & Schema Audit Tool — core AJAX handlers, PSI, admin columns.
// Requires: cpt-register.php, email-helpers.php, email-manager.php,
// email-report.php, schema-email-report.php, elementor-integration.php
// (all loaded by ru-plugin.php before this file).

//JS file Load
function seo_audit_tool_enqueue_scripts() {

    $script_path = plugin_dir_path(__FILE__) . '../js/seo-audit.js';
    $script_url  = plugin_dir_url(__FILE__) . '../js/seo-audit.js';

    wp_enqueue_script(
        'seo-audit-handler',
        $script_url,
        ['jquery'],
        file_exists($script_path) ? filemtime($script_path) : null,
        true
    );

    // 👇 Define ajaxurl globally for the JS script
    wp_localize_script('seo-audit-handler', 'seoAuditData', [
        'ajaxurl' => admin_url('admin-ajax.php'),
    ]);
}

add_action('wp_enqueue_scripts', 'seo_audit_tool_enqueue_scripts');

// AJAX handler
add_action('wp_ajax_run_seo_audit', 'seo_audit_run');
add_action('wp_ajax_nopriv_run_seo_audit', 'seo_audit_run');

// Init Schema Audit (doble opt-in, como SEO)
add_action('wp_ajax_init_schema_audit', 'init_schema_audit');
add_action('wp_ajax_nopriv_init_schema_audit', 'init_schema_audit');

function seo_audit_run() {
    $url   = filter_var($_POST['site_url'], FILTER_VALIDATE_URL);
    $email = sanitize_email($_POST['email']);

    if (!$url || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json(['success' => false, 'message' => 'Dati non validi.']);
    }

    $site_domain = parse_url($url, PHP_URL_HOST);

    // Honeypot: campo oculto inyectado por JS que solo un bot llena. Si viene
    // con valor, respondemos éxito falso (para no delatar el filtro) y cortamos.
    if (!empty($_POST['hp_field'])) {
        wp_send_json(['success' => true, 'message' => 'Analisi in corso. Il report completo ti arriverà via email a breve.']);
    }

    // Rate limit por IP: máx 3 audit al día, corta abuso manual sin pedir cuenta a nadie.
    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip) {
        $rl_key = 'ru_audit_rl_' . md5($ip);
        $count  = (int) get_transient($rl_key);
        if ($count >= 3) {
            wp_send_json(['success' => false, 'message' => 'Hai già richiesto un audit di recente. Controlla la tua email o riprova più tardi.']);
        }
        set_transient($rl_key, $count + 1, DAY_IN_SECONDS);
    }

    // Crea el CPT "vacío" y agenda el trabajo completo (scrape + PSI + email) en background.
    $post_id = wp_insert_post([
        'post_type'   => 'seo_report',
        'post_title'  => 'Audit per ' . $site_domain . ' - ' . current_time('mysql'),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($post_id) || !$post_id) {
        wp_send_json(['success' => false, 'message' => 'Errore durante il salvataggio del report.']);
    }

    add_post_meta($post_id, 'email', sanitize_email($email));
    update_post_meta($post_id, 'site_url', esc_url_raw($url));
    update_post_meta($post_id, 'audit-status', 'pending_verification');

    // Doble opt-in (ver includes/verification.php): no se corre nada pesado
    // todavía. El audit real arranca en ru_verified_seo_audit, cuando el
    // dueño del email confirma.
    register_shutdown_function(function () use ($post_id, $email) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ru_send_verification_email($post_id, $email, 'seo_audit');
    });

    wp_send_json([
        'success'      => true,
        'message'      => 'Controlla la tua email e conferma l’indirizzo per ricevere il report.',
        'email_status' => 'pending_verification',
    ]);
}

add_action('ru_verified_seo_audit', function ($post_id) {
    update_post_meta($post_id, 'audit-status', 'queued');
    update_post_meta($post_id, 'psi-retry-status', 'pending');
    do_action('seo_audit_full_job', $post_id);
});

add_action('seo_audit_full_job', function($post_id){
    $status = get_post_meta($post_id, 'audit-status', true);
    if ($status === 'processing' || $status === 'completed') return;
    update_post_meta($post_id, 'audit-status', 'processing');
    
    $url    = get_post_meta($post_id, 'site_url', true);
    $emails = array_unique(array_map('sanitize_email', (array)get_post_meta($post_id, 'email')));

    if (empty($url)) return;

    // ---------- FETCH HTML (timeouts cortos) ----------
    $html = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'RiseUpAudit/1.0', CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
    }
    if (empty($html)) {
        $html = wp_remote_retrieve_body(wp_remote_get($url, [
            'timeout'=>10,'redirection'=>3,'user-agent'=>'RiseUpAudit/1.0'
        ]));
    }

    if (!empty($html)) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $page_size = strlen($html);
        $is_https  = (parse_url($url, PHP_URL_SCHEME) === 'https') ? 'Sì' : 'No';

        $viewport = '';
        foreach ($doc->getElementsByTagName('meta') as $m) {
            if (strtolower($m->getAttribute('name')) === 'viewport') { $viewport = $m->getAttribute('content'); break; }
        }
        $html_tag = $doc->getElementsByTagName('html')->item(0);
        $lang     = $html_tag ? $html_tag->getAttribute('lang') : '';

        $schema_present = 'Assente';
        foreach ($doc->getElementsByTagName('script') as $s) {
            if (strtolower($s->getAttribute('type')) === 'application/ld+json') { $schema_present = 'Presente (JSON-LD)'; break; }
        }
        if ($schema_present === 'Assente') {
            $has_micro = $xpath->query('//*[@itemscope or @itemtype or @itemprop]');
            if ($has_micro->length > 0) $schema_present = 'Presente (Microdata)';
        }

        $title = $doc->getElementsByTagName('title')->item(0)?->textContent ?? 'Non trovato';

        $meta_desc = '';
        foreach ($doc->getElementsByTagName('meta') as $m) {
            if (strtolower($m->getAttribute('name')) === 'description') { $meta_desc = $m->getAttribute('content'); break; }
        }

        $h1 = $xpath->query('//h1')->length > 0 ? 'Presente' : 'Assente';

        $robots = '';
        foreach ($doc->getElementsByTagName('meta') as $m) {
            if (strtolower($m->getAttribute('name')) === 'robots') { $robots = $m->getAttribute('content'); break; }
        }

        $canonical = '';
        foreach ($doc->getElementsByTagName('link') as $l) {
            if (strtolower($l->getAttribute('rel')) === 'canonical') { $canonical = $l->getAttribute('href'); break; }
        }

        $results = [
            'Titolo'           => $title,
            'Meta Description' => $meta_desc ?: 'Non trovata',
            'H1'               => $h1,
            'Meta Robots'      => $robots ?: 'Non trovata',
            'Canonical'        => $canonical ?: 'Non trovato',
            'Schema Markup'    => $schema_present,
            'HTTPS'            => $is_https,
            'Viewport'         => $viewport ?: 'Non trovato',
            'Lingua'           => $lang ?: 'Non specificata',
            'Dimensione HTML'  => round($page_size / 1024, 1) . ' KB',
        ];
        foreach ($results as $k=>$v) {
            update_post_meta($post_id, sanitize_title_with_dashes($k), sanitize_text_field($v));
        }
    }

    // ---------- PSI ----------
    $psi = run_pagespeed_audit($url);
    update_post_meta($post_id, 'psi-raw-json', wp_json_encode($psi));
    foreach ($psi as $k=>$v) {
        update_post_meta($post_id, 'psi-'.sanitize_title_with_dashes($k), sanitize_text_field($v));
    }

    // Si PSI falla, igual mandamos el mail — el usuario no queda colgado.
    // Marcamos que PSI tuvo problema pero el audit igual se completa con datos on-page + IA.
    $psi_failed = isset($psi['PSI error']);
    if ($psi_failed) {
        update_post_meta($post_id, 'psi-retry-status', 'failed');
    } else {
        update_post_meta($post_id, 'psi-retry-status', 'completed');
    }

    update_post_meta($post_id, 'audit-status', 'completed');

    // Recomendaciones vía LLM, en reemplazo de las "opportunities" genéricas
    // de PSI que se comentaron más arriba (ver includes/ai-helpers.php).
    $ai_recommendations = ru_generate_seo_recommendations($post_id);
    if ($ai_recommendations) {
        update_post_meta($post_id, 'ai-recommendations', $ai_recommendations);
    }

    // Manda el email con lo que tenemos — no bloquea si PSI falló.
    foreach ($emails as $to) {
        if (!empty($to)) send_seo_audit_email($post_id, $to);
    }
}, 10, 1);

function ru_generate_seo_recommendations($post_id) {
    $get = fn($k) => get_post_meta($post_id, $k, true) ?: '-';

    $prompt = "Dati raccolti per l'audit SEO di {$get('site_url')}:\n\n"
        . "On-page:\n"
        . "- Titolo: {$get('titolo')}\n"
        . "- Meta Description: {$get('meta-description')}\n"
        . "- H1: {$get('h1')}\n"
        . "- Schema Markup: {$get('schema-markup')}\n"
        . "- HTTPS: {$get('https')}\n\n"
        . "PageSpeed:\n"
        . "- Performance Score: {$get('psi-performance-score')}\n"
        . "- LCP: {$get('psi-lcp')}\n"
        . "- CLS: {$get('psi-cls')}\n"
        . "- INP: {$get('psi-inp')}\n"
        . "- TBT: {$get('psi-tbt')}\n";

    return ru_ai_complete($prompt, [
        'system' => 'Sei un consulente SEO che scrive per Rise Up, un\'agenzia italiana. '
            . 'Analizza i dati e scrivi 3-4 raccomandazioni prioritizzate, concrete e in '
            . 'linguaggio semplice (non tecnico-burocratico). Ogni raccomandazione: una frase, '
            . 'inizia con il problema principale che risolve. Rispondi SOLO con un elenco '
            . 'puntato in italiano, senza introduzioni né conclusioni.',
        'max_tokens' => 500,
    ]);
}


//PSI API logic
add_action('edit_form_after_title', function ($post) {
    if ($post->post_type !== 'seo_report') return;

    $meta = get_post_meta($post->ID);
    echo '<div style="background:#fff3cd; border:1px solid #ffeeba; padding:10px; margin:20px 0;"><strong>DEBUG – Meta salvati:</strong><br><pre>';
    print_r($meta);
    echo '</pre></div>';
});

function run_pagespeed_audit($url) {
    // API oficial de Google PageSpeed Insights
    $api_key = defined('GOOGLE_PSI_API_KEY') ? GOOGLE_PSI_API_KEY : '';
    if (empty($api_key)) {
        error_log('PSI CONFIG ERROR: GOOGLE_PSI_API_KEY no definida en wp-config.php');
        return ['PSI error' => 'Configurazione mancante (API key).'];
    }

    $psi_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . urlencode($url) . '&key=' . urlencode($api_key) . '&category=performance';

    // Reintentos síncronos: 3 intentos antes de fallar.
    // Timeout de 20s — API oficial es más confiable que proxy, puede esperar un poco.
    $max_attempts = 3;
    $response = null;

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        $response = wp_remote_get($psi_url, ['timeout' => 20]);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Si la respuesta es válida, corta.
            if (isset($data['lighthouseResult']['audits'])) {
                break;
            }
        }

        // Si no es el último intento, espera un poco antes de reintentar.
        if ($attempt < $max_attempts) {
            sleep(2);
        }
    }

    if (is_wp_error($response)) {
        error_log('PSI HTTP ERROR (attempt ' . $attempt . '): ' . $response->get_error_message());
        return ['PSI error' => 'Errore nella chiamata a PageSpeed API.'];
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['lighthouseResult']['audits'])) {
        error_log('PSI RESPONSE INVALID: ' . print_r($data, true));
        return ['PSI error' => 'Risposta non valida da Google PageSpeed API.'];
    }
    
    $audits = $data['lighthouseResult']['audits']; //Last line

    $metrics = $data['lighthouseResult']['audits'];
    $score = $data['lighthouseResult']['categories']['performance']['score'] ?? 0;

    $results = [];

    $interpret = function($metric, $value) {
        switch ($metric) {
            case 'LCP':
                return $value < 2.5 ? 'Veloce' : ($value < 4 ? 'Da migliorare' : 'Lento');
            case 'CLS':
                return $value < 0.1 ? 'Stabile' : ($value < 0.25 ? 'Da migliorare' : 'Instabile');
            case 'INP':
                return $value < 200 ? 'Veloce' : ($value < 500 ? 'Da migliorare' : 'Lento');
            case 'FCP':
                return $value < 1.8 ? 'Veloce' : ($value < 3 ? 'Da migliorare' : 'Lento');
            case 'Speed Index':
                return $value < 3.4 ? 'Veloce' : ($value < 5.8 ? 'Da migliorare' : 'Lento');
            case 'TBT':
                return $value < 200 ? 'Veloce' : ($value < 600 ? 'Da migliorare' : 'Lento');
            case 'Performance Score':
                return $value > 89 ? 'Ottimo' : ($value > 49 ? 'Accettabile' : 'Scarso');
            default:
                return '';
        }
    };

    if (isset($metrics['largest-contentful-paint']['numericValue'])) {
        $v = round($metrics['largest-contentful-paint']['numericValue'] / 1000, 2);
        $results['LCP'] = $v . 's (' . $interpret('LCP', $v) . ')';
    }

    if (isset($metrics['cumulative-layout-shift']['numericValue'])) {
        $v = $metrics['cumulative-layout-shift']['numericValue'];
        $results['CLS'] = $v . ' (' . $interpret('CLS', $v) . ')';
    }

    if (isset($metrics['interactive']['numericValue'])) {
        $v = round($metrics['interactive']['numericValue'], 2);
        $results['INP'] = $v . 'ms (' . $interpret('INP', $v) . ')';
    }

    if (isset($metrics['first-contentful-paint']['numericValue'])) {
        $v = round($metrics['first-contentful-paint']['numericValue'] / 1000, 2);
        $results['FCP'] = $v . 's (' . $interpret('FCP', $v) . ')';
    }

    if (isset($metrics['speed-index']['numericValue'])) {
        $v = round($metrics['speed-index']['numericValue'] / 1000, 2);
        $results['Speed Index'] = $v . 's (' . $interpret('Speed Index', $v) . ')';
    }

    if (isset($metrics['total-blocking-time']['numericValue'])) {
        $v = round($metrics['total-blocking-time']['numericValue'], 2);
        $results['TBT'] = $v . 'ms (' . $interpret('TBT', $v) . ')';
    }

    $final_score = round($score * 100);
    $results['Performance Score'] = $final_score . '/100 (' . $interpret('Performance Score', $final_score) . ')';
    
    // Comentado a pedido: las "opportunities" de PSI son boilerplate genérico
    // de Google (Lighthouse), no análisis real. Se reemplazan por recomendaciones
    // vía LLM. Dejado acá por si hace falta volver atrás.
    /*
    $opportunities = array_filter($audits, function ($audit) {
        return isset($audit['details']['type']) && $audit['details']['type'] === 'opportunity';
    });

    usort($opportunities, function ($a, $b) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });

    $topOps = array_slice($opportunities, 0, 3);
    foreach ($topOps as $i => $item) {
        $title = $item['title'] ?? 'Senza titolo';
        $desc = strip_tags($item['description'] ?? '');
        $results['Opportunity ' . ($i + 1)] = "$title – $desc";
    }
    */

    //Analyze responsiveness
    if (isset($audits['uses-responsive-images'])) {
        $score = $audits['uses-responsive-images']['score'];
        $status = $score === null ? 'N/A' : (
            $score >= 0.9 ? 'OK' : ($score >= 0.5 ? 'Da migliorare' : 'Problema')
        );
        $results['Responsive'] = $status;
    }

    return $results;
}

add_action('edit_form_after_title', function ($post) {
    if ($post->post_type !== 'seo_report') return;

    $raw_json = get_post_meta($post->ID, 'psi-raw-json', true);
    if (!$raw_json) return;

    $data = json_decode($raw_json, true);
    if (!isset($data['lighthouseResult']['audits'])) return;

    $audits = $data['lighthouseResult']['audits'];

    echo "<pre>\n";
    echo "Diagnostica tecnica PSI\n";
    echo "========================\n";

    $keys = [
        'uses-responsive-images',
        'uses-text-compression',
        'unminified-css',
        'unminified-javascript',
        'uses-rel-preconnect',
        'unused-css-rules',
        'unused-javascript',
        'render-blocking-resources',
    ];

    foreach ($keys as $key) {
        if (!isset($audits[$key])) continue;

        $item  = $audits[$key];
        $title = $item['title'] ?? $key;
        $desc  = strip_tags($item['description'] ?? '');
        $score = $item['score'];

        if ($score === null) {
            $status = 'N/A';
        } elseif ($score >= 0.9) {
            $status = 'OK';
        } elseif ($score >= 0.5) {
            $status = 'Attenzione';
        } else {
            $status = 'Problema';
        }

        echo "- $title: $status\n";
        echo "  $desc\n\n";
    }
    echo "</pre>\n";
});


//Event cron for pending retries
if (!wp_next_scheduled('seo_audit_retry_event')) {
    wp_schedule_event(time(), 'hourly', 'seo_audit_retry_event');
}

add_action('seo_audit_retry_event', 'check_and_retry_psi_reports');

function check_and_retry_psi_reports() {
    $pending = get_posts([
        'post_type'   => 'seo_report',
        'meta_key'    => 'psi-retry-status',
        'meta_value'  => 'pending',
        'numberposts' => 5
    ]);

    foreach ($pending as $report) {
        do_action('seo_audit_full_job', $report->ID);
    }
}


// 🔁 Job singolo per completare PSI in background
/*add_action('seo_audit_single_retry', function($post_id) {
    $url    = get_post_meta($post_id, 'site_url', true);
    $emails = get_post_meta($post_id, 'email'); // array de emails guardados

    if (empty($url)) return;

    // Esegui PSI
    $psi_data = run_pagespeed_audit($url);
    update_post_meta($post_id, 'psi-raw-json', wp_json_encode($psi_data));
    foreach ($psi_data as $key => $value) {
        $meta_key = 'psi-' . sanitize_title_with_dashes($key);
        update_post_meta($post_id, $meta_key, sanitize_text_field($value));
    }

    // Se PSI ancora in errore → resta "pending" e ci penserà il cron orario
    if (isset($psi_data['PSI error'])) {
        update_post_meta($post_id, 'psi-retry-status', 'pending');
        return;
    }

    // Completato con successo
    update_post_meta($post_id, 'psi-retry-status', 'completed');

    // Invia email a tutti gli indirizzi salvati
    $emails = array_unique(array_map('sanitize_email', (array)$emails));
    foreach ($emails as $to) {
        if (!empty($to)) {
            send_seo_audit_email($post_id, $to);
        }
    }
}, 10, 1);*/


// Add custom column PSI status
add_filter('manage_seo_report_posts_columns', function ($columns) {
    // elimina posibles duplicados previos
    unset($columns['psi_status'], $columns['email']);
    // agrega slugs únicos
    $columns['riseup_psi_status'] = 'PSI Status';
    $columns['riseup_email']      = 'Email Utente';
    return $columns;
});

add_action('manage_seo_report_posts_custom_column', function ($column, $post_id) {
    if ($column === 'riseup_psi_status') {
        $status = get_post_meta($post_id, 'psi-retry-status', true);
        if ($status === 'completed') {
            echo '<span class="seo-status ok">✅ OK</span>';
        } elseif ($status === 'pending') {
            echo '<span class="seo-status pending">⏳ In attesa</span>';
        } elseif ($status === 'failed') {
            echo '<span class="seo-status failed">❌ Failed</span>';
        } else {
            echo '<span class="seo-status unknown">–</span>';
        }
    }

    if ($column === 'riseup_email') {
        $emails = get_post_meta($post_id, 'email');
        if (!empty($emails)) {
            $emails = array_unique(array_map('sanitize_email', (array)$emails));
            echo '<span class="seo-email">' . esc_html(implode(', ', $emails)) . '</span>';
        } else {
            echo '<span class="seo-email empty">–</span>';
        }
    }
}, 10, 2);



// Load Schema Audit JS
add_action('wp_enqueue_scripts', function () {
    if (is_page()) { // puoi raffinare il controllo se vuoi
        wp_enqueue_script(
            'schema-audit-js',
            plugin_dir_url(__FILE__) . '../js/schema-audit.js',
            ['jquery'],
            filemtime(plugin_dir_path(__FILE__) . '../js/schema-audit.js'),
            true
        );

        wp_localize_script('schema-audit-js', 'schemaAuditData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }
});

// ✅ Enqueue stili per colonne admin
add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style(
        'seo-admin-columns',
        plugin_dir_url(__FILE__) . '../assets/css/admin-columns.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin-columns.css')
    );
});

// Código viejo, pre-rediseño (corría el check al toque, sin doble opt-in).
// Ya no lo llama el JS del front (ver js/schema-audit.js) — reemplazado por
// init_schema_audit() + schema_audit_full_job() más abajo. Comentado (no
// borrado) por unos días por si hace falta volver atrás rápido — después
// se puede borrar.
/*
add_action('wp_ajax_run_schema_audit', 'schema_audit_run');
add_action('wp_ajax_nopriv_run_schema_audit', 'schema_audit_run');

function schema_audit_run() {
    $site_url = esc_url_raw($_POST['site_url'] ?? '');
    error_log("[SchemaAudit] START site_url='{$site_url}'");

    if (empty($site_url)) {
        error_log("[SchemaAudit] FAIL: empty URL");
        wp_send_json_error(['message' => 'URL mancante.']);
    }

    $resp = wp_remote_get($site_url, [
        'timeout'     => 10,
        'redirection' => 3,
        'user-agent'  => 'RiseUpAudit/1.0'
    ]);

    if (is_wp_error($resp)) {
        error_log("[SchemaAudit] HTTP ERROR: " . $resp->get_error_message());
        wp_send_json_error(['message' => 'Impossibile recuperare il contenuto del sito.']);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $html = wp_remote_retrieve_body($resp);
    error_log("[SchemaAudit] HTTP $code, body_len=" . strlen((string)$html));

    if (empty($html)) {
        error_log("[SchemaAudit] FAIL: empty HTML body");
        wp_send_json_error(['message' => 'Impossibile recuperare il contenuto del sito.']);
    }

    preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches);
    $schemas_raw   = array_map('trim', $matches[1] ?? []);
    $schemas_valid = [];
    error_log("[SchemaAudit] JSON-LD blocks found=" . count($schemas_raw));

    foreach ($schemas_raw as $i => $code) {
        $json = json_decode($code, true);
        if ($json && (is_array($json) || is_object($json))) {
            $schemas_valid[] = $json;
        } else {
            // log primi 80 char per debug
            error_log("[SchemaAudit] JSON parse FAIL block#$i: " . substr($code, 0, 80));
        }
    }

    if (empty($schemas_raw)) {
        $status  = 'not_found';
        $message = '❌ Schema Markup: Non trovato';
    } elseif (empty($schemas_valid)) {
        $status  = 'needs_optimization';
        $message = '⚠️ Schema Markup: Trovato, ma non valido o ottimizzabile';
    } else {
        $status  = 'ok';
        $message = '✅ Schema Markup: Trovato e valido';
    }
    error_log("[SchemaAudit] STATUS='$status'");

    // Publica en el mismo CPT para verlo en el listado
    $post_arr = [
        'post_type'   => 'seo_report',
        'post_title'  => 'Schema Audit – ' . $site_url . ' – ' . current_time('mysql'),
        'post_status' => 'publish',
        'post_content'=> $message,
    ];
    error_log("[SchemaAudit] INSERT about to run…");
    $post_id = wp_insert_post($post_arr, true);

    if (is_wp_error($post_id) || !$post_id) {
        $err = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
        error_log("[SchemaAudit] INSERT ERROR: $err");
        wp_send_json_error(['message' => 'Errore durante il salvataggio del report.']);
    }
    error_log("[SchemaAudit] INSERT OK id=$post_id");

    update_post_meta($post_id, 'site_url', $site_url);
    update_post_meta($post_id, 'schema_status', $status);
    update_post_meta($post_id, 'schema_raw', implode("\n\n", $schemas_raw));
    update_post_meta($post_id, 'schema_valid', json_encode($schemas_valid));
    update_post_meta($post_id, 'report_type', 'schema');

    error_log("[SchemaAudit] DONE id=$post_id");

    wp_send_json_success([
        'message' => $message,
        'status'  => $status,
        'post_id' => $post_id,
    ]);
}
*/

// Init Schema Audit: crea CPT + pide confirmación (doble opt-in, como SEO)
function init_schema_audit() {
    $url   = filter_var($_POST['site_url'] ?? '', FILTER_VALIDATE_URL);
    $email = sanitize_email($_POST['email'] ?? '');

    if (!$url || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        wp_send_json_error(['message' => 'Dati non validi.']);
    }

    // Crea el CPT "vacío" (el análisis ocurre después de confirmar email)
    $post_id = wp_insert_post([
        'post_type'   => 'seo_report',
        'post_title'  => 'Schema Audit – ' . parse_url($url, PHP_URL_HOST) . ' - ' . current_time('mysql'),
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        wp_send_json_error(['message' => 'Errore durante il salvataggio del report.']);
    }

    add_post_meta($post_id, 'email', sanitize_email($email));
    update_post_meta($post_id, 'site_url', esc_url_raw($url));
    update_post_meta($post_id, 'audit-status', 'pending_verification');
    update_post_meta($post_id, 'report_type', 'schema');

    // Doble opt-in: no se corre análisis todavía
    register_shutdown_function(function () use ($post_id, $email) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ru_send_verification_email($post_id, $email, 'schema_audit');
    });

    wp_send_json([
        'success'      => true,
        'message'      => 'Controlla la tua email e conferma l\'indirizzo per ricevere l\'analisi.',
        'email_status' => 'pending_verification',
    ]);
}

// Cuando se confirma el email de Schema, dispara el análisis en background
add_action('ru_verified_schema_audit', function ($post_id) {
    update_post_meta($post_id, 'audit-status', 'queued');
    do_action('schema_audit_full_job', $post_id);
});

// El análisis real de Schema (ocurre en background después de confirmar email)
add_action('schema_audit_full_job', function ($post_id) {
    $status = get_post_meta($post_id, 'audit-status', true);
    if ($status === 'processing' || $status === 'completed') return;

    update_post_meta($post_id, 'audit-status', 'processing');

    $site_url = get_post_meta($post_id, 'site_url', true);
    $emails = array_unique(array_map('sanitize_email', (array)get_post_meta($post_id, 'email')));

    if (empty($site_url)) return;

    // ---- FETCH HTML ----
    $resp = wp_remote_get($site_url, ['timeout' => 10, 'redirection' => 3, 'user-agent' => 'RiseUpAudit/1.0']);
    if (is_wp_error($resp)) {
        update_post_meta($post_id, 'audit-status', 'completed');
        return;
    }

    $html = wp_remote_retrieve_body($resp);
    if (empty($html)) {
        update_post_meta($post_id, 'audit-status', 'completed');
        return;
    }

    // ---- DETECTA SCHEMA ----
    preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches);
    $schemas_raw   = array_map('trim', $matches[1] ?? []);
    $schemas_valid = [];

    foreach ($schemas_raw as $code) {
        $json = json_decode($code, true);
        if ($json && (is_array($json) || is_object($json))) {
            $schemas_valid[] = $json;
        }
    }

    // ---- DETERMINA STATUS ----
    if (empty($schemas_raw)) {
        $status = 'not_found';
        $message = '❌ Schema Markup: Non trovato';
    } elseif (empty($schemas_valid)) {
        $status = 'needs_optimization';
        $message = '⚠️ Schema Markup: Trovato, ma non valido o ottimizzabile';
    } else {
        $status = 'ok';
        $message = '✅ Schema Markup: Trovato e valido';
    }

    // ---- GUARDA DATOS ----
    // wp_slash() en schema_raw: update_post_meta() llama internamente a wp_unslash(),
    // que come cualquier "\" del string. El JSON-LD crudo trae escapes tipo ó
    // (acentos) — sin este wp_slash() quedan corruptos (ej. "u00f3" en vez de "ó").
    // schema_valid se guarda como array nativo (no json_encode) para evitar el mismo
    // problema: al decodificar arriba ya quedaron caracteres UTF-8 reales, sin "\".
    update_post_meta($post_id, 'schema_status', $status);
    update_post_meta($post_id, 'schema_raw', wp_slash(implode("\n\n", $schemas_raw)));
    update_post_meta($post_id, 'schema_valid', $schemas_valid);
    update_post_meta($post_id, 'audit-status', 'completed');

    // ---- MANDA EMAIL ----
    foreach ($emails as $to) {
        if (!empty($to)) send_schema_audit_email($post_id, $to);
    }
});