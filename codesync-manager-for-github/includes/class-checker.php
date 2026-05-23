<?php
/**
 * CodeSync Checker - Static Analysis Engine
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CODESYNC_Checker {

	/**
	 * Temporary folder prefix for inspections.
	 */
	const TEMP_PREFIX = 'codesync_inspect_';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_codesync_checker_download', array( __CLASS__, 'ajax_step_download' ) );
		add_action( 'wp_ajax_codesync_checker_headers', array( __CLASS__, 'ajax_step_headers' ) );
		add_action( 'wp_ajax_codesync_checker_security', array( __CLASS__, 'ajax_step_security' ) );
		add_action( 'wp_ajax_codesync_checker_deprecated', array( __CLASS__, 'ajax_step_deprecated' ) );
		add_action( 'wp_ajax_codesync_checker_cleanup', array( __CLASS__, 'ajax_step_cleanup' ) );
	}

	/**
	 * Authenticate and return the GitHub API instance.
	 *
	 * @return CODESYNC_GitHub_API|WP_Error
	 */
	private static function get_api() {
		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return new WP_Error( 'no_token', __( 'GitHub Token missing.', 'codesync-manager-for-github' ) );
		}
		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			return $decrypted;
		}
		return new CODESYNC_GitHub_API( $decrypted );
	}

	/**
	 * Retrieve a temporary directory path securely.
	 *
	 * @param string $session_id Session ID for this inspection.
	 * @return string|WP_Error
	 */
	private static function get_inspect_dir( $session_id ) {
		$base_dir = CODESYNC_Manager::get_secure_directory( 'codesync-inspect' );
		if ( is_wp_error( $base_dir ) ) {
			return $base_dir;
		}
		$target_dir = $base_dir . '/' . sanitize_file_name( self::TEMP_PREFIX . $session_id );
		return $target_dir;
	}

	/**
	 * Format an inspection result payload.
	 *
	 * @param array $passed Array of strings (passed checks).
	 * @param array $warnings Array of strings (warnings).
	 * @param array $errors Array of strings (errors/blockers).
	 * @return array
	 */
	private static function format_result( $passed = array(), $warnings = array(), $errors = array() ) {
		$status = 'success';
		if ( ! empty( $errors ) ) {
			$status = 'error';
		} elseif ( ! empty( $warnings ) ) {
			$status = 'warning';
		}
		return array(
			'status'   => $status,
			'passed'   => $passed,
			'warnings' => $warnings,
			'errors'   => $errors,
		);
	}

	/**
	 * Helper: Recursively get all files of a specific extension.
	 */
	private static function get_files_by_ext( $dir, $ext = 'php' ) {
		$results = array();
		if ( ! is_dir( $dir ) ) {
			return $results;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isDir() && strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) ) === $ext ) {
				$results[] = $file->getPathname();
			}
		}
		return $results;
	}

	/**
	 * Step 1: Download & Extract
	 */
	public static function ajax_step_download() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sem permissão.' ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => 'Repositório não fornecido.' ) );
		}

		$api = self::get_api();
		if ( is_wp_error( $api ) ) {
			wp_send_json_error( array( 'message' => $api->get_error_message() ) );
		}

		$parts = explode( '/', $repo_slug );
		if ( count( $parts ) !== 2 ) {
			wp_send_json_error( array( 'message' => 'Slug inválido.' ) );
		}

		// Create a unique session ID
		$session_id = md5( $repo_slug . time() . wp_rand() );
		$inspect_dir = self::get_inspect_dir( $session_id );

		// Fetch zipball for selected ref or default branch
		$ref = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		if ( empty( $ref ) ) {
			$ref = $api->get_default_branch( $parts[0], $parts[1] );
			if ( is_wp_error( $ref ) ) {
				wp_send_json_error( array( 'message' => $ref->get_error_message() ) );
			}
		}

		$zip_url = sprintf( '%s/repos/%s/%s/zipball/%s', CODESYNC_GitHub_API::API_URL, rawurlencode( $parts[0] ), rawurlencode( $parts[1] ), rawurlencode( $ref ) );


		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		WP_Filesystem();
		global $wp_filesystem;

		$download_file = $api->download_package( $zip_url );

		if ( is_wp_error( $download_file ) ) {
			wp_send_json_error( array( 'message' => 'Erro ao baixar repositório: ' . $download_file->get_error_message() ) );
		}

		if ( ! is_dir( $inspect_dir ) ) {
			wp_mkdir_p( $inspect_dir );
		}

		$unzip_result = unzip_file( $download_file, $inspect_dir );
		unlink( $download_file );

		if ( is_wp_error( $unzip_result ) ) {
			wp_send_json_error( array( 'message' => 'Erro ao extrair pacote: ' . $unzip_result->get_error_message() ) );
		}

		// GitHub wraps everything in a root folder. Find it and move contents up.
		$contents = $wp_filesystem->dirlist( $inspect_dir );
		if ( is_array( $contents ) && count( $contents ) === 1 ) {
			$root_folder = array_keys( $contents )[0];
			$full_root = $inspect_dir . '/' . $root_folder;
			if ( is_dir( $full_root ) ) {
				// We don't move it up, just tell subsequent steps to use this as base
				$inspect_dir = $full_root;
			}
		}

		wp_send_json_success( array(
			'session_id' => $session_id,
			'base_path'  => $inspect_dir,
			'result'     => self::format_result(
				array( __( 'Repository download completed.', 'codesync-manager-for-github' ), __( 'ZIP package extracted in isolated environment.', 'codesync-manager-for-github' ) )
			)
		) );
	}

	/**
	 * Step 2: Headers & Structure
	 */
	public static function ajax_step_headers() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		$base_path = isset( $_POST['base_path'] ) ? sanitize_text_field( wp_unslash( $_POST['base_path'] ) ) : '';
		if ( empty( $base_path ) || ! is_dir( $base_path ) ) {
			wp_send_json_error( array( 'message' => 'Caminho base inválido.' ) );
		}

		$passed = array();
		$warnings = array();
		$errors = array();

		// Detect if Plugin or Theme
		$type = 'unknown';
		$main_file = '';
		$headers = array();

		// Check for Theme (style.css)
		if ( file_exists( $base_path . '/style.css' ) ) {
			$data = get_file_data( $base_path . '/style.css', array( 'ThemeName' => 'Theme Name', 'RequiresPHP' => 'Requires PHP' ) );
			if ( ! empty( $data['ThemeName'] ) ) {
				$type = 'theme';
				$headers = $data;
			}
		}

		// Check for Plugin (any .php in root)
		if ( 'unknown' === $type ) {
			$php_files = glob( $base_path . '/*.php' );
			if ( is_array( $php_files ) ) {
				foreach ( $php_files as $file ) {
					$data = get_file_data( $file, array( 'PluginName' => 'Plugin Name', 'RequiresPHP' => 'Requires PHP', 'TextDomain' => 'Text Domain' ) );
					if ( ! empty( $data['PluginName'] ) ) {
						$type = 'plugin';
						$main_file = $file;
						$headers = $data;
						break;
					}
				}
			}
		}

		if ( 'unknown' === $type ) {
			$errors[] = __( 'Could not identify the package as Plugin or Theme (missing valid headers).', 'codesync-manager-for-github' );
		} else {
			$passed[] = sprintf( __( 'Identified as a valid %s ("%s").', 'codesync-manager-for-github' ), ucfirst( $type ), ( 'theme' === $type ? $headers['ThemeName'] : $headers['PluginName'] ) );
			
			if ( empty( $headers['RequiresPHP'] ) ) {
				$warnings[] = __( '"Requires PHP" header is missing. It is a good practice to define it.', 'codesync-manager-for-github' );
			} else {
				$passed[] = sprintf( __( 'PHP requirement defined: %s', 'codesync-manager-for-github' ), $headers['RequiresPHP'] );
			}

			if ( 'plugin' === $type && empty( $headers['TextDomain'] ) ) {
				$warnings[] = __( '"Text Domain" header missing. Translations may fail.', 'codesync-manager-for-github' );
			} elseif ( 'plugin' === $type ) {
				$passed[] = __( 'Text Domain defined.', 'codesync-manager-for-github' );
			}
		}

		wp_send_json_success( array(
			'result' => self::format_result( $passed, $warnings, $errors )
		) );
	}

	/**
	 * Step 3: Security & Escaping
	 */
	public static function ajax_step_security() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		$base_path = isset( $_POST['base_path'] ) ? sanitize_text_field( wp_unslash( $_POST['base_path'] ) ) : '';
		if ( empty( $base_path ) || ! is_dir( $base_path ) ) {
			wp_send_json_error( array( 'message' => 'Caminho base inválido.' ) );
		}

		$passed = array();
		$warnings = array();
		$errors = array();

		$php_files = self::get_files_by_ext( $base_path, 'php' );
		
		$found_eval = false;
		$found_shell = false;
		$found_base64 = false;
		$found_unprepared_sql = false;

		foreach ( $php_files as $file ) {
			$content = file_get_contents( $file );
			$rel_path = str_replace( $base_path, '', $file );

			if ( preg_match( '/\beval\s*\(/i', $content ) ) {
				$errors[] = sprintf( __( 'Use of eval() function detected in %s. This is a severe security risk.', 'codesync-manager-for-github' ), $rel_path );
				$found_eval = true;
			}
			if ( preg_match( '/\b(shell_exec|system|exec|passthru)\s*\(/i', $content ) ) {
				$errors[] = sprintf( __( 'Use of operating system functions detected in %s.', 'codesync-manager-for-github' ), $rel_path );
				$found_shell = true;
			}
			// basic unprepared SQL check (not perfect, but catches obvious mistakes)
			if ( preg_match( '/\$wpdb->query\s*\(\s*["\'][^"\']*(\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*|{\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*})[^"\']*["\']\s*\)/i', $content ) ) {
				$warnings[] = sprintf( __( 'Possible direct query without $wpdb->prepare detected in %s. SQL Injection risk.', 'codesync-manager-for-github' ), $rel_path );
				$found_unprepared_sql = true;
			}
			// raw global accesses
			if ( preg_match( '/echo\s+(?:\$_POST|\$_GET|\$_REQUEST)/i', $content ) ) {
				$errors[] = sprintf( __( 'Output (echo) of superglobal variables directly without escaping detected in %s (XSS risk).', 'codesync-manager-for-github' ), $rel_path );
			}
		}

		if ( ! $found_eval && ! $found_shell ) {
			$passed[] = __( 'No remote execution function (eval, shell_exec) detected.', 'codesync-manager-for-github' );
		}
		if ( ! $found_unprepared_sql ) {
			$passed[] = __( 'No obvious use of SQL query without prepare detected.', 'codesync-manager-for-github' );
		}
		$passed[] = __( 'Basic security analysis finished.', 'codesync-manager-for-github' );

		wp_send_json_success( array(
			'result' => self::format_result( $passed, $warnings, $errors )
		) );
	}

	/**
	 * Step 4: Deprecated & Assets
	 */
	public static function ajax_step_deprecated() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		$base_path = isset( $_POST['base_path'] ) ? sanitize_text_field( wp_unslash( $_POST['base_path'] ) ) : '';
		if ( empty( $base_path ) || ! is_dir( $base_path ) ) {
			wp_send_json_error( array( 'message' => 'Caminho base inválido.' ) );
		}

		$passed = array();
		$warnings = array();
		$errors = array();

		// Check large files
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_path ) );
		$has_large_file = false;
		foreach ( $iterator as $file ) {
			if ( ! $file->isDir() ) {
				$size_mb = $file->getSize() / 1048576; // bytes to MB
				if ( $size_mb > 10 ) {
					$rel_path = str_replace( $base_path, '', $file->getPathname() );
					$warnings[] = sprintf( __( 'The file "%s" is too large (%.2f MB). Consider optimizing assets.', 'codesync-manager-for-github' ), $rel_path, $size_mb );
					$has_large_file = true;
				}
			}
		}
		if ( ! $has_large_file ) {
			$passed[] = __( 'The repository does not contain massive files (>10MB).', 'codesync-manager-for-github' );
		}

		// Check deprecated functions in PHP
		$php_files = self::get_files_by_ext( $base_path, 'php' );
		$deprecated_found = false;
		foreach ( $php_files as $file ) {
			$content = file_get_contents( $file );
			$rel_path = str_replace( $base_path, '', $file );

			if ( preg_match( '/\b(mysql_connect|mysql_query|create_function|wp_reset_query)\s*\(/i', $content, $matches ) ) {
				$warnings[] = sprintf( __( 'Use of deprecated function "%s" detected in %s.', 'codesync-manager-for-github' ), $matches[1], $rel_path );
				$deprecated_found = true;
			}
		}

		if ( ! $deprecated_found ) {
			$passed[] = __( 'No highly deprecated PHP/WordPress functions detected.', 'codesync-manager-for-github' );
		}

		wp_send_json_success( array(
			'result' => self::format_result( $passed, $warnings, $errors )
		) );
	}

	/**
	 * Step 5: Clean-up
	 */
	public static function ajax_step_cleanup() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( empty( $session_id ) ) {
			wp_send_json_error( array( 'message' => 'Session ID ausente.' ) );
		}

		$inspect_dir = self::get_inspect_dir( $session_id );
		if ( is_dir( $inspect_dir ) ) {
			CODESYNC_Manager::delete_directory_recursive( $inspect_dir );
		}

		wp_send_json_success( array(
			'result' => self::format_result( array( __( 'Temporary files safely removed from server.', 'codesync-manager-for-github' ) ) )
		) );
	}
}
