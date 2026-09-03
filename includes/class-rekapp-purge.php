<?php
/**
 * Purga diária de carrinhos velhos.
 *
 * Um carrinho parado há mais de 30 dias não volta — e dado de contato guardado
 * sem propósito é passivo de LGPD, não ativo de recuperação. O cron apaga
 * qualquer linha (ativa ou convertida) sem atualização dentro da janela.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class Rekapp_Purge {

	const DEFAULT_RETENTION_DAYS = 30;

	public static function init(): void {
		add_action( 'rekapp_purge_carts', array( __CLASS__, 'purge' ) );
	}

	public static function purge(): void {
		/**
		 * Dias de retenção de carrinhos (mínimo 1).
		 *
		 * @param int $days
		 */
		$days   = max( 1, (int) apply_filters( 'rekapp_carts_retention_days', self::DEFAULT_RETENTION_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		global $wpdb;
		$table = Rekapp_Carts_Table::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE updated_at_gmt < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
