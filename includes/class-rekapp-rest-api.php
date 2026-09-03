<?php
/**
 * REST API que o backend do Rekapp consome por polling.
 *
 * Autenticação: as MESMAS consumer keys do WooCommerce que o lojista já
 * entregou no /wc-auth — nenhuma credencial nova. O WooCommerce só aplica a
 * autenticação por chave a namespaces que ele reconhece como "dele"; o filtro
 * `woocommerce_rest_is_request_to_rest_api` inclui o `rekapp/v1` nesse grupo.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Rest_Api {

	const REST_NAMESPACE = 'rekapp/v1';

	public static function init(): void {
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( __CLASS__, 'opt_in_wc_key_auth' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Faz a autenticação por consumer key do WooCommerce valer para rekapp/v1.
	 *
	 * @param bool $is_wc_request
	 * @return bool
	 */
	public static function opt_in_wc_key_auth( $is_wc_request ) {
		if ( $is_wc_request ) {
			return true;
		}
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		return false !== strpos( $request_uri, $rest_prefix . self::REST_NAMESPACE );
	}

	/**
	 * Chave autenticada pelo WooCommerce mapeia para o usuário dono dela — o
	 * admin que aprovou o /wc-auth. Sem chave (ou chave revogada) não há
	 * usuário, e o 401/403 daqui é o sinal que o Rekapp usa para avisar o
	 * lojista de integração caída.
	 */
	public static function permission_check(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/carts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_carts' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'modified_after'        => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'                => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => Rekapp_Carts_Table::STATUS_ACTIVE,
						'enum'              => array( Rekapp_Carts_Table::STATUS_ACTIVE, Rekapp_Carts_Table::STATUS_CONVERTED, 'all' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'include_uncontactable' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
					'page'                  => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'              => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public static function ping(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'plugin'      => 'rekapp-for-woocommerce',
				'version'     => REKAPP_WC_VERSION,
				'woocommerce' => function_exists( 'WC' ) ? WC()->version : null,
			),
			200
		);
	}

	public static function list_carts( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();

		$where  = array( '1=1' );
		$params = array();

		$status = (string) $request['status'];
		if ( 'all' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$modified_after = self::parse_gmt( (string) $request['modified_after'] );
		if ( null !== $modified_after ) {
			$where[]  = 'updated_at_gmt >= %s';
			$params[] = $modified_after;
		}

		if ( ! $request['include_uncontactable'] ) {
			// Carrinho sem contato não é recuperável — não vale a transferência.
			// Convertidos passam mesmo sem contato: o backend precisa deles para
			// fechar o caso como convertido.
			$where[] = "(email <> '' OR phone <> '' OR status = 'converted')";
		}

		$per_page = (int) $request['per_page'];
		$offset   = ( max( 1, (int) $request['page'] ) - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				: "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at_gmt ASC, id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$list_params
			),
			ARRAY_A
		);

		$carts = array_map( array( __CLASS__, 'format_cart' ), is_array( $rows ) ? $rows : array() );

		$response = new WP_REST_Response( $carts, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );
		return $response;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function format_cart( array $row ): array {
		$line_items = json_decode( (string) $row['line_items'], true );

		return array(
			'cart_token'     => (string) $row['cart_token'],
			'status'         => (string) $row['status'],
			'contactable'    => '' !== (string) $row['email'] || '' !== (string) $row['phone'],
			'email'          => (string) $row['email'],
			'phone'          => (string) $row['phone'],
			'first_name'     => (string) $row['first_name'],
			'last_name'      => (string) $row['last_name'],
			'cart_total'     => (string) $row['cart_total'],
			'currency'       => (string) $row['currency'],
			'line_items'     => is_array( $line_items ) ? $line_items : array(),
			'order_id'       => null !== $row['order_id'] ? (int) $row['order_id'] : null,
			'restore_url'    => Rekapp_Cart_Restorer::restore_url( (string) $row['cart_token'] ),
			'created_at_gmt' => self::to_iso( (string) $row['created_at_gmt'] ),
			'updated_at_gmt' => self::to_iso( (string) $row['updated_at_gmt'] ),
		);
	}

	private static function parse_gmt( string $value ): ?string {
		if ( '' === trim( $value ) ) {
			return null;
		}
		$timestamp = strtotime( trim( $value ) );
		if ( false === $timestamp ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function to_iso( string $mysql_gmt ): ?string {
		$timestamp = strtotime( $mysql_gmt . ' UTC' );
		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s', $timestamp );
	}
}
