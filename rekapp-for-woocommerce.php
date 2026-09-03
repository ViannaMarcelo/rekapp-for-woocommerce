<?php
/**
 * Plugin Name: Rekapp for WooCommerce
 * Plugin URI: https://rekapp.com.br
 * Description: Captura carrinhos abandonados (com contato preenchido no checkout) e os expõe ao Rekapp para recuperação por WhatsApp. Funciona com o checkout clássico e com o checkout em blocos.
 * Version: 1.0.0
 * Author: Rekapp
 * Author URI: https://rekapp.com.br
 * Text Domain: rekapp-for-woocommerce
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * O plugin não envia nada para fora da loja: ele só guarda o carrinho numa
 * tabela própria e responde ao Rekapp quando o backend consulta a REST API da
 * loja autenticado com as mesmas consumer keys do WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

define( 'REKAPP_WC_VERSION', '1.0.0' );
define( 'REKAPP_WC_PLUGIN_FILE', __FILE__ );
define( 'REKAPP_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REKAPP_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Compatibilidade declarada cedo (antes do plugins_loaded do WooCommerce):
// HPOS (só lemos o ID do pedido via API de CRUD, nunca a tabela de posts) e
// checkout em blocos (a captura e a conversão têm caminho próprio para ele).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-carts-table.php';

register_activation_hook( __FILE__, array( 'Rekapp_Carts_Table', 'install' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'rekapp_purge_carts' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Rekapp for WooCommerce precisa do WooCommerce ativo para funcionar.', 'rekapp-for-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-cart-tracker.php';
		require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-contact-capture.php';
		require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-cart-restorer.php';
		require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-rest-api.php';
		require_once REKAPP_WC_PLUGIN_DIR . 'includes/class-rekapp-purge.php';

		Rekapp_Carts_Table::maybe_upgrade();
		Rekapp_Cart_Tracker::init();
		Rekapp_Contact_Capture::init();
		Rekapp_Cart_Restorer::init();
		Rekapp_Rest_Api::init();
		Rekapp_Purge::init();
	}
);
