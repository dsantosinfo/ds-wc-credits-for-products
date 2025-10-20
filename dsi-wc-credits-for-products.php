<?php
/**
 * Plugin Name:         DS - Créditos por Produtos para TeraWallet
 * Plugin URI:          https://dsantosinfo.com.br
 * Description:         Concede créditos na carteira TeraWallet e auto-completa pedidos virtuais que concedem créditos. Compatível com HPOS.
 * Version:             2.0.0
 * Author:              DSantos Info
 * Author URI:          https://backgamon.dsantosinfo.com.br
 * License:             GPLv2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         dsi-wc-credits
 * Domain Path:         /languages
 * WC requires at least: 5.0
 * WC tested up to:      8.2
 */
// Se o arquivo for acessado diretamente, aborte.
if (!defined('ABSPATH')) {
    exit;
}

// Declaração de compatibilidade com High-Performance Order Storage (HPOS).
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

final class DSI_WC_Credits_For_Products_Manager {

    private static $instance;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ações executadas apenas no painel de administração
        if (is_admin()) {
            add_action('plugins_loaded', [$this, 'check_dependencies']);
            add_action('woocommerce_product_options_general_product_data', [$this, 'add_credits_custom_field']);
            add_action('woocommerce_process_product_meta', [$this, 'save_credits_custom_field']);
        }

        // Ações executadas no frontend e em processos de fundo (webhooks, etc)
        add_action('woocommerce_order_status_completed', [$this, 'award_credits_on_order_completion'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'auto_complete_eligible_virtual_orders'], 20, 1);
    }

    public function check_dependencies() {
        if (!class_exists('WooCommerce') || !function_exists('woo_wallet')) {
            add_action('admin_notices', [$this, 'dependencies_missing_notice']);
        }
    }

    public function dependencies_missing_notice() {
        echo '<div class="error"><p>';
        echo esc_html__('O plugin "DSI - Créditos por Produtos" requer que o WooCommerce e o TeraWallet - WooCommerce Wallet estejam instalados e ativos.', 'dsi-wc-credits');
        echo '</p></div>';
    }

    public function add_credits_custom_field() {
        echo '<div class="options_group">';
        woocommerce_wp_text_input([
            'id'                => '_dsi_terawallet_credits_amount',
            'label'             => __('Créditos TeraWallet a conceder', 'dsi-wc-credits'),
            'placeholder'       => 'Ex: 10.50',
            'description'       => __('Insira a quantidade de créditos que este produto concede ao cliente. Condição para auto-complete: valor >= 1.', 'dsi-wc-credits'),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => [ 'step' => 'any', 'min' => '0' ],
        ]);
        echo '</div>';
    }

    public function save_credits_custom_field($product_id) {
        $credits_amount = isset($_POST['_dsi_terawallet_credits_amount']) ? wc_clean(wp_unslash($_POST['_dsi_terawallet_credits_amount'])) : '';
        if (is_numeric($credits_amount)) {
            update_post_meta($product_id, '_dsi_terawallet_credits_amount', $credits_amount);
        } else {
            delete_post_meta($product_id, '_dsi_terawallet_credits_amount');
        }
    }

    public function auto_complete_eligible_virtual_orders($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->has_status('completed')) {
            return;
        }

        $all_items_are_eligible = true;
        if (count($order->get_items()) > 0) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) continue;
                $credits = (float) $product->get_meta('_dsi_terawallet_credits_amount');

                if (!$product->is_virtual() || $credits < 1) {
                    $all_items_are_eligible = false;
                    break;
                }
            }
        } else {
            $all_items_are_eligible = false;
        }

        if ($all_items_are_eligible) {
            $order->update_status('completed', 'Pedido com produtos virtuais elegíveis completado automaticamente.');
        }
    }

    public function award_credits_on_order_completion($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_dsi_credits_awarded')) {
            return;
        }

        $user_id = $order->get_customer_id();
        if ($user_id === 0) {
            return;
        }
        
        $total_credits_to_add = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $quantity = $item->get_quantity();
            $credits_per_product = (float) $product->get_meta('_dsi_terawallet_credits_amount');

            if ($credits_per_product > 0) {
                $total_credits_to_add += $credits_per_product * $quantity;
            }
        }

        if ($total_credits_to_add > 0) {
            if (function_exists('woo_wallet')) {
                $note = sprintf(__('Créditos recebidos pela compra de produtos no pedido #%s', 'dsi-wc-credits'), $order->get_order_number());
                
                woo_wallet()->wallet->credit($user_id, $total_credits_to_add, $note);
                
                $order->add_order_note(
                    sprintf(__('%s créditos foram adicionados à carteira do cliente.', 'dsi-wc-credits'), $total_credits_to_add)
                );

                $order->update_meta_data('_dsi_credits_awarded', 'yes');
                $order->save();
            }
        }
    }
}

// Garante que o plugin seja carregado apenas uma vez.
function dsi_wc_credits_for_products_init() {
    DSI_WC_Credits_For_Products_Manager::get_instance();
}
add_action('plugins_loaded', 'dsi_wc_credits_for_products_init', 11);