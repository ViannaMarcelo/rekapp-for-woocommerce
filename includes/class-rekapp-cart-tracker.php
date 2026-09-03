<?php
/**
 * Rastreio do carrinho: um token por sessão, uma linha por carrinho.
 *
 * O token vive na sessão do WooCommerce (não em cookie próprio): é a mesma
 * duração do carrinho que ele identifica, e morre junto. Depois da conversão o
 * token é rotacionado — o próximo carrinho da mesma pessoa é outro caso de
 * recuperação, não uma reabertura do anterior.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Cart_Tracker {

	const SESSION_TOKEN_KEY = 'rekapp_cart_token';

	/** Teto de itens gravados — espelho do teto que o Rekapp exibe no drawer. */
	const MAX_LINE_ITEMS = 30;

	public static function init(): void {
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'sync_cart' ) );

		// Conversão: o hook clássico NÃO dispara no checkout em blocos (Store
		// API) e vice-versa — os dois juntos cobrem os dois checkouts.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'mark_converted' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'mark_converted_from_order' ), 10, 1 );
	}

	/**
	 * Token do carrinho atual. Cria (e persiste na sessão) quando não existe.
	 */
	public static function current_token( bool $create = false ): ?string {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return null;
		}

		$token = WC()->session->get( self::SESSION_TOKEN_KEY );
		if ( is_string( $token ) && 32 === strlen( $token ) ) {
			return $token;
		}
		if ( ! $create ) {
			return null;
		}

		$token = bin2hex( random_bytes( 16 ) );
		WC()->session->set( self::SESSION_TOKEN_KEY, $token );
		return $token;
	}

	/**
	 * Espelha o carrinho da sessão na tabela. Roda a cada save do carrinho.
	 */
	public static function sync_cart(): void {
		if ( ! function_exists( 'WC' ) || null === WC()->cart || null === WC()->session ) {
			return;
		}

		/**
		 * Permite ao lojista desligar a captura (ex.: exigência de LGPD da
		 * assessoria dele) sem desativar o plugin inteiro.
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'rekapp_cart_tracking_enabled', true ) ) {
			return;
		}

		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			// Carrinho esvaziado pelo cliente não é abandono — é desistência
			// explícita. A linha ativa sai; uma convertida fica (é histórico).
			$token = self::current_token( false );
			if ( null !== $token ) {
				self::delete_active_row( $token );
			}
			return;
		}

		$token = self::current_token( true );
		if ( null === $token ) {
			return;
		}

		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();
		$now   = current_time( 'mysql', true );

		$data = array(
			'session_key'    => (string) WC()->session->get_customer_id(),
			'user_id'        => get_current_user_id() ?: null,
			'cart_total'     => (float) $cart->get_total( 'edit' ),
			'currency'       => get_woocommerce_currency(),
			'line_items'     => wp_json_encode( self::snapshot_line_items( $cart ) ),
			'status'         => Rekapp_Carts_Table::STATUS_ACTIVE,
			'updated_at_gmt' => $now,
		);

		// Contato conhecido sem depender do JS: cliente logado já tem billing.
		// Nunca sobrescreve com vazio — o que o capture gravou vale mais que
		// um perfil incompleto.
		$customer = WC()->customer;
		if ( $customer ) {
			foreach ( array(
				'email'      => $customer->get_billing_email() ?: $customer->get_email(),
				'phone'      => $customer->get_billing_phone(),
				'first_name' => $customer->get_billing_first_name() ?: $customer->get_first_name(),
				'last_name'  => $customer->get_billing_last_name() ?: $customer->get_last_name(),
			) as $field => $value ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$data[ $field ] = trim( $value );
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE cart_token = %s", $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $data, array( 'id' => (int) $existing_id ) );
			return;
		}

		$data['cart_token']     = $token;
		$data['created_at_gmt'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $data );
	}

	/**
	 * Checkout clássico: recebe o order_id.
	 *
	 * @param int|mixed $order_id
	 */
	public static function mark_converted( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			self::mark_converted_from_order( $order );
		}
	}

	/**
	 * Marca o carrinho da sessão atual como convertido e rotaciona o token.
	 *
	 * @param WC_Order $order
	 */
	public static function mark_converted_from_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$token = self::current_token( false );
		if ( null === $token ) {
			return;
		}

		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$table,
			array(
				'status'         => Rekapp_Carts_Table::STATUS_CONVERTED,
				'order_id'       => $order->get_id(),
				'updated_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'cart_token' => $token )
		);

		// O pedido fechou este caso. Se a pessoa voltar a comprar na mesma
		// sessão, o carrinho novo ganha token (e linha) próprios.
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			WC()->session->set( self::SESSION_TOKEN_KEY, null );
		}
	}

	private static function delete_active_row( string $token ): void {
		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			$table,
			array(
				'cart_token' => $token,
				'status'     => Rekapp_Carts_Table::STATUS_ACTIVE,
			)
		);
	}

	/**
	 * Itens do carrinho no formato que o Rekapp consome (name, quantity,
	 * unit_price, image_url + ids para a restauração).
	 *
	 * @param WC_Cart $cart
	 * @return array<int, array<string, mixed>>
	 */
	private static function snapshot_line_items( WC_Cart $cart ): array {
		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( count( $items ) >= self::MAX_LINE_ITEMS ) {
				break;
			}

			$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
				? $cart_item['data']
				: null;

			$image_url = null;
			if ( $product ) {
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$image_url = wp_get_attachment_image_url( (int) $image_id, 'woocommerce_thumbnail' ) ?: null;
				}
			}

			$items[] = array(
				'product_id'   => isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0,
				'variation_id' => isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
				'variation'    => isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] )
					? array_map( 'sanitize_text_field', $cart_item['variation'] )
					: array(),
				'name'         => $product ? $product->get_name() : '',
				'quantity'     => isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1,
				'unit_price'   => $product ? (string) wc_get_price_to_display( $product ) : '0',
				'image_url'    => $image_url,
			);
		}
		return $items;
	}
}
