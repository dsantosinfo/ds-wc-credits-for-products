<?php
/**
 * Plugin Name:         DS - Créditos por Produtos para TeraWallet
 * Plugin URI:          https://dsantosinfo.com.br/
 * Description:         Concede créditos na carteira TeraWallet, auto-completa pedidos e envia notificações via WhatsApp. Compatível com HPOS.
 * Version:             4.2.0
 * Author:              DSantos Info
 * Author URI:          https://dsantosinfo.com.br/
 * License:             GPL v2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         dsi-wc-credits
 * Domain Path:         /languages
 * Requires PHP:        7.4
 * Requires at least:   5.0
 * Tested up to:        6.4
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
        if (is_admin()) {
            add_action('plugins_loaded', [$this, 'check_dependencies']);
            add_action('woocommerce_product_options_general_product_data', [$this, 'add_credits_custom_field']);
            add_action('woocommerce_process_product_meta', [$this, 'save_credits_custom_field']);
        }
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
            'description'       => 
            __('Insira a quantidade de créditos que este produto concede ao cliente. Condição para auto-complete: valor >= 1.', 'dsi-wc-credits'),
            'desc_tip'          => true,
            'type'              => 'number',
            'custom_attributes' => [ 'step' => 'any', 'min' => '0' ],
        ]);
        echo '</div>';
    }

    public function save_credits_custom_field($product_id) {
        $credits_amount = isset($_POST['_dsi_terawallet_credits_amount']) ?
        wc_clean(wp_unslash($_POST['_dsi_terawallet_credits_amount'])) : '';
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

        if ($total_credits_to_add > 0 && function_exists('woo_wallet')) {
            $note = sprintf(__('Créditos recebidos pela compra de produtos no pedido #%s', 'dsi-wc-credits'), $order->get_order_number());
            // Adiciona os créditos à carteira
            woo_wallet()->wallet->credit($user_id, $total_credits_to_add, $note);
            
            // --- INÍCIO DA LÓGICA DE NOTIFICAÇÃO VIA WHATSAPP ---
            // 1. Notifica o Usuário
            $this->send_credits_notification($user_id, $total_credits_to_add, $order->get_order_number());
            // 2. Notifica o Admin
            $this->send_admin_deposit_notification($user_id, $total_credits_to_add, $order->get_order_number());
            // --- FIM DA LÓGICA DE NOTIFICAÇÃO ---

            $order->add_order_note(
                sprintf(__('%s créditos foram adicionados à carteira do cliente.', 'dsi-wc-credits'), $total_credits_to_add)
            );
            $order->update_meta_data('_dsi_credits_awarded', 'yes');
            $order->save();
        }
    }

    /**
     * Envia a notificação para o USUÁRIO.
     */
    private function send_credits_notification($user_id, $credits_added, $order_number) {
        $phone = $this->get_user_phone($user_id);
        $user_data = get_userdata($user_id);
        if (!$phone || !$user_data) {
            return;
        }

        $new_total_balance = woo_wallet()->wallet->get_wallet_balance($user_id, 'numeric');
        $user_name = $user_data->first_name ?: $user_data->display_name;
        
        // Usar novo sistema de templates se disponível
        if (class_exists('WhatsApp_Message_Templates')) {
            WhatsApp_Message_Templates::send_deposit_notification(
                $phone, 
                $user_name, 
                $credits_added, 
                $new_total_balance
            );
            return;
        }
        
        // Fallback para método antigo
        if (!class_exists('\BG_Challonge\Whatsapp')) {
            return;
        }

        $message = sprintf(
            "Olá %s! Você ganhou *%s créditos* pela sua compra no pedido #%s.\nSeu novo saldo é de *%s créditos*.",
            $user_name,
            wc_format_decimal($credits_added, 2),
            $order_number,
            wc_format_decimal($new_total_balance, 2)
        );
        
        try {
            $whatsapp_sender = new \BG_Challonge\Whatsapp();
            $whatsapp_sender->send_message($phone, $message);
        } catch (\Exception $e) {
            error_log('DSI Credits Plugin: Falha ao enviar notificação (USUÁRIO) via WhatsApp. Erro: ' . $e->getMessage());
        }
    }

    /**
     * NOVO: Envia a notificação para o ADMIN.
     */
    private function send_admin_deposit_notification($user_id, $credits_added, $order_number) {
        if (!class_exists('\BG_Challonge\Whatsapp')) {
            return;
        }

        // Envia para o Admin (ID 1)
        $admin_phone = $this->get_user_phone(1);
        $user_data = get_userdata($user_id);

        if (!$admin_phone || !$user_data) {
            error_log('DSI Credits Plugin: Falha ao enviar notificação (ADMIN). Telefone do admin (ID 1) ou dados do usuário não encontrados.');
            return;
        }

        $message = sprintf(
            "🔔 *Notificação de Depósito:*\n\nO usuário *%s* (ID: %d) comprou *%s créditos*.\nPedido: #%s",
            $user_data->display_name,
            $user_id,
            wc_format_decimal($credits_added, 2),
            $order_number
        );

        try {
            $whatsapp_sender = new \BG_Challonge\Whatsapp();
            $whatsapp_sender->send_message($admin_phone, $message);
        } catch (\Exception $e) {
            error_log('DSI Credits Plugin: Falha ao enviar notificação (ADMIN) via WhatsApp. Erro: ' . $e->getMessage());
        }
    }

    /**
     * Helper para obter o número de telefone de um usuário.
     */
    private function get_user_phone($user_id) {
        // Busca o campo ACF 'user_whatsapp' primeiro (como no plugin de torneios)
        if (function_exists('get_field')) {
            $phone = get_field('user_whatsapp', 'user_' . $user_id);
            if (!empty($phone)) {
                return $this->normalize_phone($phone);
            }
        }
        
        // Fallback para campos meta padrão do WordPress/WooCommerce
        $phone = get_user_meta($user_id, 'whatsapp_number', true);
        if (empty($phone)) {
            $phone = get_user_meta($user_id, 'billing_phone', true);
        }
        return $this->normalize_phone($phone);
    }
    
    /**
     * NOVO: Helper robusto para normalizar o telefone (padrão '55').
     */
    private function normalize_phone( ?string $phone ): ?string {
        if ( empty($phone) ) return null;
        
        // Usar nova classe de formatação se disponível
        if (class_exists('WhatsApp_Phone_Formatter')) {
            return WhatsApp_Phone_Formatter::format_for_storage($phone);
        }
        
        // Fallback para método antigo
        $digits_only = preg_replace( '/\D/', '', $phone );
        if ( empty($digits_only) ) return null;
        
        if (strlen($digits_only) <= 11) {
            return '55' . $digits_only;
        }
        
        if (strlen($digits_only) > 11 && str_starts_with($digits_only, '55')) {
            return $digits_only;
        }
        
        return $digits_only;
    }
}

// Garante que o plugin seja carregado apenas uma vez.
function dsi_wc_credits_for_products_init() {
    DSI_WC_Credits_For_Products_Manager::get_instance();
}
add_action('plugins_loaded', 'dsi_wc_credits_for_products_init', 11);