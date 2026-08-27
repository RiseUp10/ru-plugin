<?php

// CPT for storing audit reports
add_action('init', function () {
    register_post_type('seo_report', [
        'label' => 'SEO Reports',
        'public' => false,
        'show_ui' => true,
        'has_archive' => false,
        'rewrite' => false,
        'supports' => ['title', 'custom-fields'], // ✅ this enables meta fields
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-chart-bar',
    ]);
});

// CPT per l'applicazione al monoprodotto (sito 1€) — Etapa A: screening
// con verifica identità (email + SMS) prima delle domande, salvataggio
// progressivo per risposta. Vedi ru-plugin/RU-SUBSCRIPTION-SYSTEM-PLAN.md.
function riseup_register_application_cpt() {
    register_post_type('ru_application', [
        'label'           => 'Applicazioni',
        'public'          => false,
        'show_ui'         => true,
        'has_archive'     => false,
        'rewrite'         => false,
        'supports'        => ['title', 'custom-fields'],
        'capability_type' => 'post',
        'menu_icon'       => 'dashicons-forms',
        'menu_position'   => 27,
    ]);
}
add_action('init', 'riseup_register_application_cpt');

// CPT per salvare i lead da form opt-in (guide, strumenti, ecc.)
function riseup_register_lead_optins_cpt() {
    $labels = [
        'name'               => 'Lead Opt-ins',
        'singular_name'      => 'Lead Opt-in',
        'menu_name'          => 'Lead Opt-ins',
        'add_new'            => 'Aggiungi nuovo',
        'add_new_item'       => 'Aggiungi nuovo Lead',
        'edit_item'          => 'Modifica Lead',
        'view_item'          => 'Visualizza Lead',
        'all_items'          => 'Tutti i Lead',
        'search_items'       => 'Cerca Lead',
        'not_found'          => 'Nessun lead trovato',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'menu_icon'          => 'dashicons-email-alt2',
        'supports'           => ['title'],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'menu_position'      => 26
    ];

    register_post_type('lead_optins', $args);
}
add_action('init', 'riseup_register_lead_optins_cpt');

// CPT + tassonomia per l'Accademia (blog/risorse educative)
function riseup_register_accademia_cpt() {
    $labels = [
        'name'                  => 'Accademia',
        'singular_name'         => 'Articolo Accademia',
        'menu_name'             => 'Accademia',
        'name_admin_bar'        => 'Articolo Accademia',
        'add_new'               => 'Aggiungi nuovo',
        'add_new_item'          => 'Aggiungi nuovo articolo',
        'new_item'              => 'Nuovo articolo',
        'edit_item'             => 'Modifica articolo',
        'view_item'             => 'Vedi articolo',
        'all_items'             => 'Tutti gli articoli',
        'search_items'          => 'Cerca articoli',
        'not_found'             => 'Nessun articolo trovato',
        'not_found_in_trash'    => 'Nessun articolo nel cestino',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,
        'menu_position'      => 22,
        'menu_icon'          => 'dashicons-welcome-learn-more',
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
        'hierarchical'       => false,
        'supports'           => ['title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions'],
        'has_archive'        => false,
        'rewrite'            => ['slug' => 'accademia'],
        'query_var'          => true,
    ];

    register_post_type('accademia', $args);

    // Pilastri Accademia
    register_taxonomy('academy_pillar', ['accademia'], [
        'label'                 => 'Pilastri',
        'hierarchical'          => true,
        'public'                => true,
        'publicly_queryable'    => true,
        'show_ui'               => true,
        'show_admin_column'     => true,
        'show_in_rest'          => true,
        'rewrite'               => ['slug' => 'pilastro'],
        'labels'                => [
            'name'              => 'Pilastri',
            'singular_name'     => 'Pilastro',
            'plural_name'       => 'Pilastri',
            'search_items'      => 'Cerca Pilastri',
            'all_items'         => 'Tutti i Pilastri',
            'parent_item'       => 'Pilastro genitore',
            'parent_item_colon' => 'Pilastro genitore:',
            'edit_item'         => 'Modifica Pilastro',
            'update_item'       => 'Aggiorna Pilastro',
            'add_new_item'      => 'Aggiungi nuovo Pilastro',
            'new_item_name'     => 'Nuovo nome Pilastro',
            'menu_name'         => 'Pilastri',
        ],
    ]);
}
add_action('init', 'riseup_register_accademia_cpt');