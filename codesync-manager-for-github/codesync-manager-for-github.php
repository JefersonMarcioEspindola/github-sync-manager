<?php
/**
 * Plugin Name: CodeSync Manager for GitHub
 * Plugin URI: https://github.com/JefersonMarcioEspindola/codesync-manager-for-github
 * Description: A developer tool to manage, install, and auto-update custom WordPress plugins hosted on GitHub. Connect via a Personal Access Token and use GitHub releases as the source of truth for versioning — no manual ZIP uploads needed.
 * Version: 2.0.003
 * Author: Jeferson Espindola
 * Author URI: https://github.com/JefersonMarcioEspindola
 * Text Domain: codesync-manager-for-github
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Constant Definitions
 */
define( 'CODESYNC_VERSION', '2.0.003' );
define( 'CODESYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CODESYNC_FILE', __FILE__ );

/**
 * Simple Autoloader
 */
spl_autoload_register( function( $class_name ) {
	// Only load our classes
	if ( 0 !== strpos( $class_name, 'CODESYNC_' ) ) {
		return;
	}

	$file_name = 'class-' . strtolower( str_replace( '_', '-', substr( $class_name, 9 ) ) ) . '.php';
	$file_path = CODESYNC_PATH . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

/**
 * Core Initialization Function
 */
function codesync_init() {

	// Force language override if set
	unload_textdomain( 'codesync-manager-for-github' );
	load_plugin_textdomain( 'codesync-manager-for-github', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Initialize core components
	CODESYNC_Updater::init();
	CODESYNC_Webhook::init();
	CODESYNC_Checker::init();

	if ( is_admin() ) {
		CODESYNC_Admin_UI::init();
		CODESYNC_Admin_AJAX::init();
	}

	// Hook into scheduled cron event
	add_action( 'codesync_cron_check_updates', 'codesync_cron_check_updates_callback' );
}
add_action( 'plugins_loaded', 'codesync_init' );

/**
 * Force plugin locale to the configured one if saved
 */
add_filter( 'plugin_locale', 'codesync_force_plugin_locale', 10, 2 );
function codesync_force_plugin_locale( $locale, $domain ) {
	if ( 'codesync-manager-for-github' === $domain ) {
		$selected = get_option( 'codesync_locale', '' );
		if ( ! empty( $selected ) ) {
			return $selected;
		}
	}
	return $locale;
}

/**
 * Activation Hook.
 * Prepares secure directories, validates key setups, and registers cron checks.
 */
register_activation_hook( CODESYNC_FILE, 'codesync_activate' );
function codesync_activate() {
	// Ensure secure directories are initialized
	CODESYNC_Manager::get_secure_directory( 'codesync-temp' );
	CODESYNC_Manager::get_secure_directory( 'codesync-backups' );

	// Schedule the update checking task twicedaily
	if ( ! wp_next_scheduled( 'codesync_cron_check_updates' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'codesync_cron_check_updates' );
	}

	CODESYNC_Manager::log( 'sistema', 'ativacao', 'sucesso', __( 'Plugin ativado. Diretórios temporários protegidos e WP-Cron agendado com sucesso.', 'codesync-manager-for-github' ) );
}

/**
 * Deactivation Hook.
 * Cleans scheduled cron schedules and removes residual temporary directories.
 */
register_deactivation_hook( CODESYNC_FILE, 'codesync_deactivate' );
function codesync_deactivate() {
	// Clear the cron schedule
	wp_clear_scheduled_hook( 'codesync_cron_check_updates' );

	// Wipe temp directories completely
	$temp_dir = CODESYNC_Manager::get_secure_directory( 'codesync-temp' );
	if ( ! is_wp_error( $temp_dir ) && is_dir( $temp_dir ) ) {
		CODESYNC_Manager::delete_directory_recursive( $temp_dir );
	}

	$backup_dir = CODESYNC_Manager::get_secure_directory( 'codesync-backups' );
	if ( ! is_wp_error( $backup_dir ) && is_dir( $backup_dir ) ) {
		CODESYNC_Manager::delete_directory_recursive( $backup_dir );
	}
}

/**
 * Cron Callback Function.
 * Runs in the background to identify new releases and sweep secure directories.
 */
function codesync_cron_check_updates_callback() {
	// 1. Run garbage collection to clean temporary files older than 24 hours
	CODESYNC_Manager::run_garbage_collector();

	// 2. Perform periodic update checks
	$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
	if ( empty( $token ) ) {
		return;
	}

	$security_check = CODESYNC_Encryption::check_security_keys();
	if ( is_wp_error( $security_check ) ) {
		return;
	}

	$decrypted = CODESYNC_Encryption::decrypt( $token );
	if ( is_wp_error( $decrypted ) ) {
		return;
	}

	$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
	if ( empty( $managed ) || ! is_array( $managed ) ) {
		return;
	}

	$api = new CODESYNC_GitHub_API( $decrypted );

	foreach ( $managed as $repo => $data ) {
		$plugin_file = isset( $data['plugin_file'] ) ? $data['plugin_file'] : '';
		if ( empty( $plugin_file ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			$managed[ $repo ]['status']        = 'indisponivel';
			$managed[ $repo ]['error_message'] = __( 'Arquivo principal do plugin não encontrado localmente.', 'codesync-manager-for-github' );
			continue;
		}

		// Read installed version
		$file_data = get_file_data( $plugin_path, array( 'Version' => 'Version' ) );
		$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';

		$parts = explode( '/', $repo );
		if ( count( $parts ) !== 2 ) {
			continue;
		}

		// Retrieve latest release
		$releases = $api->get_releases( $parts[0], $parts[1] );
		$managed[ $repo ]['last_checked'] = current_time( 'mysql' );

		if ( is_wp_error( $releases ) ) {
			$managed[ $repo ]['status']        = 'erro';
			$managed[ $repo ]['error_message'] = $releases->get_error_message();
			continue;
		}

		if ( empty( $releases ) ) {
			$managed[ $repo ]['status']        = 'erro';
			$managed[ $repo ]['error_message'] = __( 'Repositório não tem releases publicadas.', 'codesync-manager-for-github' );
			continue;
		}

		$latest_release = $releases[0];
		$latest_version = ltrim( $latest_release['tag_name'], 'vV' );

		$managed[ $repo ]['latest_version'] = $latest_version;

		if ( version_compare( $latest_version, $installed_version, '>' ) ) {
			$managed[ $repo ]['status']        = 'atualizacao_disponivel';
			$managed[ $repo ]['error_message'] = '';
		} else {
			$managed[ $repo ]['status']        = 'atualizado';
			$managed[ $repo ]['error_message'] = '';
		}
	}

	CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );

	// Delete native plugins update transient to force refresh
	delete_site_transient( 'update_plugins' );

	CODESYNC_Manager::log( 'sistema', 'cron_check', 'sucesso', __( 'Cron automático executou a verificação periódica de atualizações e limpeza.', 'codesync-manager-for-github' ) );
}
