<?php
/**
 * Restauração do carrinho: o link que a mensagem de WhatsApp carrega.
 *
 * `{loja}/?rekapp_restore_cart={token}` recompõe o carrinho daquela linha e
 * leva direto ao checkout, com o contato pré-preenchido. O token é capacidade
 * suficiente: 32 hex aleatórios, só circula na mensagem enviada ao próprio
 * dono do carrinho, e restaurar um carrinho não expõe dado nenhum na tela além
 * do que a pessoa mesma pôs nele.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Cart_Restorer {

	const QUERY_PARAM = 'rekapp_restore_cart';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore' ) );
	}

	public static function restore_url( string $cart_token ): string {
		return add_query_arg( self::QUERY_PARAM, rawurlencode( $cart_token ), home_url( '/' ) );
	}

	public static function maybe_restore(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- link de entrada, sem estado prévio para um nonce.
		$token = isset( $_GET[ self::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) ?: home_url( '/' ) );
			exit;
		}
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE cart_token = %s", $token ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( null === $row ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) ?: home_url( '/' ) );
			exit;
		}

		if ( Rekapp_Carts_Table::STATUS_CONVERTED === $row['status'] ) {
			// Já virou pedido — mandar ao checkout de novo cobraria duas vezes.
			wc_add_notice( __( 'Este carrinho já virou um pedido. Obrigado pela compra!', 'rekapp-for-woocommerce' ), 'notice' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) ?: home_url( '/' ) );
			exit;
		}

		$items = json_decode( (string) $row['line_items'], true );
		$items = is_array( $items ) ? $items : array();

		// A sessão atual passa a SER este carrinho: mesmo token, para o pedido
		// que sair daqui fechar a linha certa como convertida.
		WC()->cart->empty_cart();
		WC()->session->set( Rekapp_Cart_Tracker::SESSION_TOKEN_KEY, $token );

		$restored = 0;
		$skipped  = 0;
		foreach ( $items as $item ) {
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$quantity     = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$variation    = isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array();

			if ( $product_id <= 0 ) {
				continue;
			}

			$added = false;
			try {
				$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
			} catch ( Exception $e ) {
				$added = false;
			}
			if ( $added ) {
				$restored++;
			} else {
				// Produto removido/esgotado desde o abandono. Segue com o resto:
				// levar a pessoa ao checkout com 2 de 3 itens ainda recupera venda.
				$skipped++;
			}
		}

		if ( 0 === $restored ) {
			wc_add_notice( __( 'Os itens deste carrinho não estão mais disponíveis.', 'rekapp-for-woocommerce' ), 'error' );
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) ?: home_url( '/' ) );
			exit;
		}

		self::prefill_customer( $row );

		if ( $skipped > 0 ) {
			wc_add_notice( __( 'Alguns itens do seu carrinho não estão mais disponíveis e ficaram de fora.', 'rekapp-for-woocommerce' ), 'notice' );
		}
		wc_add_notice( __( 'Seu carrinho foi recuperado. Finalize a compra quando quiser!', 'rekapp-for-woocommerce' ), 'success' );

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Pré-preenche o contato no checkout — a pessoa não deve redigitar o que
	 * já digitou antes de abandonar.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function prefill_customer( array $row ): void {
		$customer = WC()->customer;
		if ( null === $customer || is_user_logged_in() ) {
			return;
		}

		if ( ! empty( $row['email'] ) && '' === $customer->get_billing_email() ) {
			$customer->set_billing_email( (string) $row['email'] );
		}
		if ( ! empty( $row['phone'] ) && '' === $customer->get_billing_phone() ) {
			$customer->set_billing_phone( (string) $row['phone'] );
		}
		if ( ! empty( $row['first_name'] ) && '' === $customer->get_billing_first_name() ) {
			$customer->set_billing_first_name( (string) $row['first_name'] );
		}
		if ( ! empty( $row['last_name'] ) && '' === $customer->get_billing_last_name() ) {
			$customer->set_billing_last_name( (string) $row['last_name'] );
		}
		$customer->save();
	}
}
