<?php
/**
 * Tabela {$prefix}rekapp_carts — o estado de cada carrinho rastreado.
 *
 * Uma linha por carrinho (cart_token), não por evento: o polling do Rekapp lê
 * o estado atual, e histórico de mudanças não recupera venda nenhuma.
 * Timestamps em GMT porque é o fuso que o backend usa no cursor incremental.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Carts_Table {

	const SCHEMA_VERSION        = '1';
	const SCHEMA_VERSION_OPTION = 'rekapp_wc_schema_version';

	const STATUS_ACTIVE    = 'active';
	const STATUS_CONVERTED = 'converted';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'rekapp_carts';
	}

	/**
	 * Activation hook: cria a tabela e agenda a purga.
	 */
	public static function install(): void {
		self::create_table();
		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );

		if ( ! wp_next_scheduled( 'rekapp_purge_carts' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'rekapp_purge_carts' );
		}
	}

	/**
	 * Upgrade em atualização de versão do plugin (activation hook não roda em update).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::SCHEMA_VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
	}

	private static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta é exigente com o formato (dois espaços após PRIMARY KEY,
		// uma coluna por linha) — manter assim para upgrades funcionarem.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			cart_token char(32) NOT NULL,
			session_key varchar(191) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NULL,
			email varchar(255) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			cart_total decimal(15,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			line_items longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			order_id bigint(20) unsigned NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cart_token (cart_token),
			KEY session_key (session_key),
			KEY status_updated (status, updated_at_gmt),
			KEY updated_at_gmt (updated_at_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
