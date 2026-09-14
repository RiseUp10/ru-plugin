<?php
/**
 * Plugin Name: RU Plugin
 * Description: Custom RiseUp Consulting functionality — SEO/Schema audit tool, PDF reports, lead capture.
 * Version: 1.0
 * Author: Rise Up
 */

if (!defined('ABSPATH')) {
    exit;
}

// --- Constantes de checkout: Stripe Payment Links directos (no WP Simple
// Pay — ver RU-SUBSCRIPTION-SYSTEM-PLAN.md sección 7.9). ?locale=it fuerza
// italiano en vez de depender del browser del cliente.
define('RU_CHECKOUT_BASE_MONTHLY_URL', 'https://buy.stripe.com/fZufZj9hk85N3vX2XIasg00?locale=it');
define('RU_CHECKOUT_BASE_YEARLY_URL', 'https://buy.stripe.com/5kQbJ3fFIadVaYpeGqasg03?locale=it');
define('RU_CHECKOUT_PLUS_MONTHLY_URL', 'https://buy.stripe.com/3cIdRbctw1HpeaBbueasg01?locale=it');
define('RU_CHECKOUT_PLUS_YEARLY_URL', 'https://buy.stripe.com/3cI28t3X00Dl7Md41Masg04?locale=it');
define('RU_CHECKOUT_PRO_MONTHLY_URL', 'https://buy.stripe.com/4gM9AVctw71JeaBgOyasg02?locale=it');
define('RU_CHECKOUT_PRO_YEARLY_URL', 'https://buy.stripe.com/8x24gB2SWeubaYp1TEasg05?locale=it');

// One-time, fijo y reusable para todos los clientes (caso simple, sin
// addons/diseño custom — ver plan sección 7.9).
define('RU_CHECKOUT_SITE_BASE_URL', 'https://buy.stripe.com/cNi28t3X071J3vXfKuasg06?locale=it');

// Fallback defensivo: evita un fatal error si STRIPE_WEBHOOK_SECRET falta
// en wp-config.php. Sigue siendo fail-closed — un secret vacío hace que
// ru_stripe_webhook_endpoint() devuelva 500 antes de intentar verificar
// nada (ver includes/billing.php).
if (!defined('STRIPE_WEBHOOK_SECRET')) {
    define('STRIPE_WEBHOOK_SECRET', '');
}

require_once plugin_dir_path(__FILE__) . 'includes/cpt-register.php';
require_once plugin_dir_path(__FILE__) . 'includes/ai-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/email-helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/email-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/verification.php';
require_once plugin_dir_path(__FILE__) . 'includes/email-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/schema-email-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/elementor-integration.php';
require_once plugin_dir_path(__FILE__) . 'includes/seo-audit-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/pdf-report.php';
require_once plugin_dir_path(__FILE__) . 'includes/application-core.php';
require_once plugin_dir_path(__FILE__) . 'includes/billing.php';

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'ru-plugin-style',
        plugin_dir_url(__FILE__) . 'assets/css/style.css',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/style.css')
    );
});
