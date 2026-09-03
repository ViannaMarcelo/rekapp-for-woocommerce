<?php
/**
 * Desinstalação: remove TUDO que o plugin criou.
 *
 * Desinstalar é o lojista dizendo "não quero mais" — deixar tabela com e-mail
 * e telefone de clientes para trás seria exatamente o dado órfão que a LGPD
 * proíbe.
 *
 * @package Rekapp_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rekapp_carts" );

delete_option( 'rekapp_wc_schema_version' );
wp_clear_scheduled_hook( 'rekapp_purge_carts' );
