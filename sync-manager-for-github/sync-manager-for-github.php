<?php
/**
 * Plugin Name: Sync Manager for GitHub
 * Plugin URI: https://github.com/JefersonMarcioEspindola/sync-manager-for-github
 * Description: A developer tool to manage, install, and auto-update custom WordPress plugins hosted on GitHub. Connect via a Personal Access Token and use GitHub releases as the source of truth for versioning — no manual ZIP uploads needed.
 * Version: 1.0.1
 * Author: Jeferson Espindola
 * Author URI: https://github.com/JefersonMarcioEspindola
 * Text Domain: sync-manager-for-github
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
define( 'GSM_VERSION', '1.0.1' );
define( 'GSM_PATH', plugin_dir_path( __FILE__ ) );
define( 'GSM_FILE', __FILE__ );

/**
 * Simple Autoloader
 */
spl_autoload_register( function( $class_name ) {
	// Only load our classes
	if ( 0 !== strpos( $class_name, 'GSM_' ) ) {
		return;
	}

	$file_name = 'class-' . strtolower( str_replace( '_', '-', substr( $class_name, 4 ) ) ) . '.php';
	$file_path = GSM_PATH . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

/**
 * Core Initialization Function
 */
function gsm_init() {
	// Load translations
	load_plugin_textdomain( 'sync-manager-for-github', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Initialize core components
	GSM_Updater::init();

	if ( is_admin() ) {
		GSM_Admin::init();
	}

	// Hook into scheduled cron event
	add_action( 'gsm_cron_check_updates', 'gsm_cron_check_updates_callback' );
}
add_action( 'plugins_loaded', 'gsm_init' );

/**
 * Force plugin locale to the configured one if saved
 */
add_filter( 'plugin_locale', 'gsm_force_plugin_locale', 10, 2 );
function gsm_force_plugin_locale( $locale, $domain ) {
	if ( 'sync-manager-for-github' === $domain ) {
		$selected = get_option( 'gsm_locale', '' );
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
register_activation_hook( GSM_FILE, 'gsm_activate' );
function gsm_activate() {
	// Ensure secure directories are initialized
	GSM_Manager::get_secure_directory( 'gsm-temp' );
	GSM_Manager::get_secure_directory( 'gsm-backups' );

	// Schedule the update checking task twicedaily
	if ( ! wp_next_scheduled( 'gsm_cron_check_updates' ) ) {
		wp_schedule_event( time(), 'twicedaily', 'gsm_cron_check_updates' );
	}

	GSM_Manager::log( 'sistema', 'ativacao', 'sucesso', __( 'Plugin ativado. Diretórios temporários protegidos e WP-Cron agendado com sucesso.', 'sync-manager-for-github' ) );
}

/**
 * Deactivation Hook.
 * Cleans scheduled cron schedules and removes residual temporary directories.
 */
register_deactivation_hook( GSM_FILE, 'gsm_deactivate' );
function gsm_deactivate() {
	// Clear the cron schedule
	wp_clear_scheduled_hook( 'gsm_cron_check_updates' );

	// Wipe temp directories completely
	$temp_dir = GSM_Manager::get_secure_directory( 'gsm-temp' );
	if ( ! is_wp_error( $temp_dir ) && is_dir( $temp_dir ) ) {
		GSM_Manager::delete_directory_recursive( $temp_dir );
	}

	$backup_dir = GSM_Manager::get_secure_directory( 'gsm-backups' );
	if ( ! is_wp_error( $backup_dir ) && is_dir( $backup_dir ) ) {
		GSM_Manager::delete_directory_recursive( $backup_dir );
	}
}

/**
 * Cron Callback Function.
 * Runs in the background to identify new releases and sweep secure directories.
 */
function gsm_cron_check_updates_callback() {
	// 1. Run garbage collection to clean temporary files older than 24 hours
	GSM_Manager::run_garbage_collector();

	// 2. Perform periodic update checks
	$token = get_option( GSM_Manager::OPTION_TOKEN );
	if ( empty( $token ) ) {
		return;
	}

	$security_check = GSM_Encryption::check_security_keys();
	if ( is_wp_error( $security_check ) ) {
		return;
	}

	$decrypted = GSM_Encryption::decrypt( $token );
	if ( is_wp_error( $decrypted ) ) {
		return;
	}

	$managed = get_option( GSM_Manager::OPTION_PLUGINS, array() );
	if ( empty( $managed ) || ! is_array( $managed ) ) {
		return;
	}

	$api = new GSM_GitHub_API( $decrypted );

	foreach ( $managed as $repo => $data ) {
		$plugin_file = isset( $data['plugin_file'] ) ? $data['plugin_file'] : '';
		if ( empty( $plugin_file ) ) {
			continue;
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			$managed[ $repo ]['status']        = 'indisponivel';
			$managed[ $repo ]['error_message'] = __( 'Arquivo principal do plugin não encontrado localmente.', 'sync-manager-for-github' );
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
			$managed[ $repo ]['error_message'] = __( 'Repositório não tem releases publicadas.', 'sync-manager-for-github' );
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

	GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed );

	// Delete native plugins update transient to force refresh
	delete_site_transient( 'update_plugins' );

	GSM_Manager::log( 'sistema', 'cron_check', 'sucesso', __( 'Cron automático executou a verificação periódica de atualizações e limpeza.', 'sync-manager-for-github' ) );
}
