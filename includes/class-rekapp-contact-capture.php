<?php
/**
 * Captura precoce de contato no checkout.
 *
 * Convidado que fecha a aba antes de submeter não deixa rastro nenhum no
 * WooCommerce — e carrinho sem e-mail/telefone não é recuperável. O JS grava
 * os campos de contato assim que a pessoa os preenche (blur/pausa de digitação),
 * via admin-ajax com nonce, e só isso: o resto do carrinho já é espelhado pelo
 * tracker no lado do servidor.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Contact_Capture {

	const AJAX_ACTION = 'rekapp_capture_contact';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function enqueue_script(): void {
		// Só onde há campo de contato para capturar — carregar em toda página
		// seria peso morto (mesma razão do capture do AutomateWoo).
		if ( ! is_checkout() && ! is_cart() ) {
			return;
		}
		if ( ! apply_filters( 'rekapp_cart_tracking_enabled', true ) ) {
			return;
		}

		wp_enqueue_script(
			'rekapp-capture',
			REKAPP_WC_PLUGIN_URL . 'assets/js/rekapp-capture.js',
			array(),
			REKAPP_WC_VERSION,
			true
		);
		wp_localize_script(
			'rekapp-capture',
			'rekappCapture',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			)
		);
	}

	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! function_exists( 'WC' ) || null === WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_success( array( 'tracked' => false ) );
		}

		// Garante a linha do carrinho antes de anexar o contato: o blur do
		// e-mail pode chegar antes de qualquer save do carrinho nesta sessão.
		Rekapp_Cart_Tracker::sync_cart();
		$token = Rekapp_Cart_Tracker::current_token( false );
		if ( null === $token ) {
			wp_send_json_success( array( 'tracked' => false ) );
		}

		$fields = array();

		if ( isset( $_POST['email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ) );
			if ( '' !== $email && is_email( $email ) ) {
				$fields['email'] = $email;
			}
		}
		if ( isset( $_POST['phone'] ) ) {
			$phone = preg_replace( '/[^0-9()+\-\s]/', '', (string) wp_unslash( $_POST['phone'] ) );
			$phone = trim( (string) $phone );
			if ( strlen( preg_replace( '/\D/', '', $phone ) ) >= 8 ) {
				$fields['phone'] = substr( $phone, 0, 50 );
			}
		}
		if ( isset( $_POST['first_name'] ) ) {
			$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
			if ( '' !== $first ) {
				$fields['first_name'] = substr( $first, 0, 100 );
			}
		}
		if ( isset( $_POST['last_name'] ) ) {
			$last = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
			if ( '' !== $last ) {
				$fields['last_name'] = substr( $last, 0, 100 );
			}
		}

		if ( empty( $fields ) ) {
			wp_send_json_success( array( 'tracked' => true, 'updated' => false ) );
		}

		$fields['updated_at_gmt'] = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			Rekapp_Carts_Table::table_name(),
			$fields,
			array( 'cart_token' => $token )
		);

		wp_send_json_success( array( 'tracked' => true, 'updated' => true ) );
	}
}
