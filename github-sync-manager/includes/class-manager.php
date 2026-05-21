<?php
/**
 * Core Manager and Logger
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GSM_Manager
 *
 * Manages database options, handles activity logs (max 100 entries, autoload=no),
 * creates and secures temporary/backup directories, and validates plugin rules.
 */
class GSM_Manager {

	/**
	 * Options names.
	 */
	const OPTION_TOKEN      = 'gsm_encrypted_token';
	const OPTION_USER       = 'gsm_connected_user';
	const OPTION_PLUGINS    = 'gsm_managed_plugins';
	const OPTION_LOGS       = 'gsm_activity_logs';

	/**
	 * Logs a message to the database log system.
	 * Max 100 entries, prepended, saved with autoload = no.
	 *
	 * @param string $repo    Repository slug (e.g. owner/repo) or 'system'.
	 * @param string $action  Action name.
	 * @param string $result  Result: 'sucesso' or 'erro'.
	 * @param string $message Detailed description.
	 */
	public static function log( $repo, $action, $result, $message ) {
		// Verify capabilities or run contexts - logging is system wide
		$logs = get_option( self::OPTION_LOGS, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$new_entry = array(
			'timestamp' => current_time( 'mysql' ),
			'repo'      => sanitize_text_field( $repo ),
			'action'    => sanitize_text_field( $action ),
			'result'    => sanitize_text_field( $result ),
			'message'   => sanitize_text_field( $message ),
		);

		array_unshift( $logs, $new_entry );

		// Keep only the last 100 logs
		if ( count( $logs ) > 100 ) {
			$logs = array_slice( $logs, 0, 100 );
		}

		// Save option with autoload = no
		self::update_option_no_autoload( self::OPTION_LOGS, $logs );
	}

	/**
	 * Helper to update option with autoload = no.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return bool True on success, false on failure.
	 */
	public static function update_option_no_autoload( $option, $value ) {
		// In WordPress 6.4+, we can pass a third parameter to update_option to set autoload.
		// However, to be fully compatible with older/all WP versions, we first try to add the option
		// with autoload = 'no'. If it already exists, we call update_option which retains the original autoload flag.
		if ( false === get_option( $option ) ) {
			return add_option( $option, $value, '', 'no' );
		}
		
		// If WP version supports 3rd parameter in update_option (deprecated argument warning in some versions, but 6.4+ uses it):
		if ( function_exists( 'wp_updates_option_autoload_supported' ) || version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ) {
			return update_option( $option, $value, 'no' );
		}

		return update_option( $option, $value );
	}

	/**
	 * Creates and secures a directory in wp-content/uploads.
	 * Adds .htaccess (Deny from all) and index.php (Silence is golden) to prevent direct web access.
	 *
	 * @param string $subfolder Directory name inside uploads (e.g. 'gsm-temp').
	 * @return string|WP_Error Absolute path of the secure directory, or WP_Error on failure.
	 */
	public static function get_secure_directory( $subfolder ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'gsm_uploads_error', $uploads['error'] );
		}

		$dir_path = $uploads['basedir'] . '/' . sanitize_file_name( $subfolder );

		if ( ! file_exists( $dir_path ) ) {
			if ( ! wp_mkdir_p( $dir_path ) ) {
				return new WP_Error(
					'gsm_dir_creation_failed',
					/* translators: %s: directory name */
					sprintf( __( 'Falha ao criar o diretório seguro: %s.', 'github-sync-manager' ), $subfolder )
				);
			}
		}

		// Security: Create .htaccess file
		$htaccess_file = $dir_path . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = "Deny from all\n";
			@file_put_contents( $htaccess_file, $htaccess_content );
		}

		// Security: Create empty index.php file
		$index_file = $dir_path . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			$index_content = "<?php\n// Silence is golden.\n";
			@file_put_contents( $index_file, $index_content );
		}

		return $dir_path;
	}

	/**
	 * Helper to empty and clean a secure directory.
	 *
	 * @param string $dir_path Absolute path to the directory.
	 */
	public static function clean_directory( $dir_path ) {
		if ( ! is_dir( $dir_path ) || strpos( $dir_path, 'uploads' ) === false ) {
			return;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $fileinfo ) {
			$path = $fileinfo->getRealPath();
			if ( basename( $path ) === '.htaccess' || basename( $path ) === 'index.php' ) {
				continue; // Keep the security files
			}
			if ( $fileinfo->isDir() ) {
				$wp_filesystem->rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Blocks self-management of this plugin to prevent update loop deadlocks.
	 *
	 * @param string $repo_slug Repository name (e.g. 'owner/repo').
	 * @return bool|WP_Error True if safe, WP_Error if blocked.
	 */
	public static function validate_repository_before_add( $repo_slug ) {
		// Prevent adding this plugin itself
		$repo_slug = strtolower( trim( $repo_slug ) );

		if ( false !== strpos( $repo_slug, 'github-sync-manager' ) ) {
			return new WP_Error(
				'gsm_blocked_self_management',
				__( 'Não é permitido gerenciar este próprio plugin através do GitHub Sync Manager para evitar conflitos de atualização.', 'github-sync-manager' )
			);
		}

		return true;
	}

	/**
	 * Deletes all plugin temporary files and backups older than 24 hours.
	 */
	public static function run_garbage_collector() {
		$dirs = array( 'gsm-temp', 'gsm-backups' );
		foreach ( $dirs as $dir_name ) {
			$dir_path = self::get_secure_directory( $dir_name );
			if ( is_wp_error( $dir_path ) || ! is_dir( $dir_path ) ) {
				continue;
			}

			$now = time();
			$files = new DirectoryIterator( $dir_path );
			foreach ( $files as $file ) {
				if ( $file->isDot() || $file->getFilename() === '.htaccess' || $file->getFilename() === 'index.php' ) {
					continue;
				}

				// If file/folder is older than 24 hours, delete it
				if ( $now - $file->getMTime() > DAY_IN_SECONDS ) {
					$path = $file->getRealPath();
					if ( $file->isDir() ) {
						self::delete_directory_recursive( $path );
					} else {
						wp_delete_file( $path );
					}
				}
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	public static function delete_directory_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $fileinfo ) {
			$path = $fileinfo->getRealPath();
			if ( $fileinfo->isDir() ) {
				$wp_filesystem->rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		$wp_filesystem->rmdir( $dir );
	}
}
