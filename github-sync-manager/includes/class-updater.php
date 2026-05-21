<?php
/**
 * Core Updater and Native WordPress Integration
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GSM_Updater
 *
 * Integrates with WordPress update transients, intercepts downloads, validates zip packages,
 * resolves canonical plugin slugs dynamically, and manages recursive folder backups/restoration.
 */
class GSM_Updater {

	/**
	 * Tracks the repository currently being installed via the admin panel.
	 *
	 * @var string
	 */
	public static $currently_installing_repo = '';

	/**
	 * Tracks the subfolder of the repository currently being installed via the admin panel.
	 *
	 * @var string
	 */
	public static $currently_installing_subfolder = '';

	/**
	 * Temporary backup information for rollback during updates.
	 *
	 * @var array
	 */
	private static $backup_info = array();

	/**
	 * Register update hooks.
	 */
	public static function init() {
		// Intercept ZIP download
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'upgrader_pre_download' ), 10, 4 );

		// Resolve canonical slug name during unzipping
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'upgrader_source_selection' ), 10, 4 );

		// Backup before install
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'upgrader_pre_install' ), 10, 2 );

		// Rollback or cleanup after install
		add_filter( 'upgrader_post_install', array( __CLASS__, 'upgrader_post_install' ), 10, 3 );
	}

	/**
	 * Hooks into site_transient_update_plugins to inject available updates.
	 *
	 * @param object $transient Update transient object.
	 * @return object Modified transient object.
	 */
	public static function check_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$token = get_option( GSM_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return $transient;
		}

		$security_check = GSM_Encryption::check_security_keys();
		if ( is_wp_error( $security_check ) ) {
			return $transient; // Cannot decrypt
		}

		$decrypted_token = GSM_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted_token ) ) {
			return $transient;
		}

		$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed_plugins ) || ! is_array( $managed_plugins ) ) {
			return $transient;
		}

		$api = new GSM_GitHub_API( $decrypted_token );

		foreach ( $managed_plugins as $repo_slug => $data ) {
			$plugin_file = isset( $data['plugin_file'] ) ? $data['plugin_file'] : '';
			if ( empty( $plugin_file ) ) {
				continue;
			}

			$plugin_abs_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! file_exists( $plugin_abs_path ) ) {
				// Mark as unavailable or remove? We will handle status check elsewhere.
				continue;
			}

			// Get installed version and Text Domain using native get_file_data
			$file_data = get_file_data( $plugin_abs_path, array(
				'Version'    => 'Version',
				'TextDomain' => 'Text Domain',
			) );

			$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';

			// Get releases from API (cached for 1 hour)
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$owner = $parts[0];
			$repo  = $parts[1];

			$releases = $api->get_releases( $owner, $repo );
			if ( is_wp_error( $releases ) ) {
				// Record error status but do not break page load
				$managed_plugins[ $repo_slug ]['status']        = 'erro';
				$managed_plugins[ $repo_slug ]['error_message'] = $releases->get_error_message();
				GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed_plugins );
				continue;
			}

			if ( empty( $releases ) ) {
				continue;
			}

			$latest_release = $releases[0];
			$latest_version = ltrim( $latest_release['tag_name'], 'vV' );

			// Check if newer version is available
			if ( version_compare( $latest_version, $installed_version, '>' ) ) {
				// Determine package URL
				$package_url = '';
				// 1. Check if there is an asset ZIP
				if ( ! empty( $latest_release['assets'] ) ) {
					$package_url = $latest_release['assets'][0]['url'];
				} elseif ( ! empty( $latest_release['zipball_url'] ) ) {
					// 2. Fall back to standard zipball
					$package_url = $latest_release['zipball_url'];
				}

				if ( empty($package_url) ) {
					continue;
				}

				$response_item = (object) array(
					'id'            => 'gsm/' . $repo_slug,
					'slug'          => dirname( $plugin_file ),
					'plugin'        => $plugin_file,
					'new_version'   => $latest_version,
					'url'           => 'https://github.com/' . $repo_slug,
					'package'       => $package_url,
					'tested'        => '',
					'requires'      => '',
					'requires_php'  => '',
				);

				$transient->response[ $plugin_file ] = $response_item;
				
				// Update managed status to update available
				if ( 'erro' === $data['status'] || 'atualizado' === $data['status'] || 'atualizacao_disponivel' === $data['status'] ) {
					$managed_plugins[ $repo_slug ]['status']        = 'atualizacao_disponivel';
					$managed_plugins[ $repo_slug ]['latest_version'] = $latest_version;
					$managed_plugins[ $repo_slug ]['error_message'] = '';
					GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed_plugins );
				}
			} else {
				// Update managed status to updated
				if ( 'erro' === $data['status'] || 'atualizacao_disponivel' === $data['status'] ) {
					$managed_plugins[ $repo_slug ]['status']        = 'atualizado';
					$managed_plugins[ $repo_slug ]['latest_version'] = $latest_version;
					$managed_plugins[ $repo_slug ]['error_message'] = '';
					GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed_plugins );
				}
			}
		}

		return $transient;
	}

	/**
	 * Intercepts WordPress package downloads to use our authenticated GitHub API Client.
	 *
	 * @param bool|WP_Error $reply     The current reply (false to let WP download).
	 * @param string        $package   The URL/package being downloaded.
	 * @param WP_Upgrader   $upgrader  The current WP_Upgrader instance.
	 * @param array         $hook_extra Additional arguments.
	 * @return string|WP_Error Absolute path to the temporary downloaded ZIP, or WP_Error.
	 */
	public static function upgrader_pre_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		// Identify if this package is a GitHub URL downloaded via our plugin
		$is_gsm = false;
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
			foreach ( $managed_plugins as $slug => $data ) {
				if ( $data['plugin_file'] === $hook_extra['plugin'] ) {
					$is_gsm = true;
					break;
				}
			}
		}

		if ( ! $is_gsm && ! empty( self::$currently_installing_repo ) ) {
			$is_gsm = true;
		}

		// Also check domain as fallback
		if ( ! $is_gsm && ( strpos( $package, 'api.github.com' ) !== false || strpos( $package, 'codeload.github.com' ) !== false ) ) {
			$is_gsm = true;
		}

		if ( ! $is_gsm ) {
			return $reply;
		}

		$token = get_option( GSM_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return new WP_Error( 'gsm_missing_token', __( 'Não foi possível baixar o plugin: Token GitHub ausente.', 'github-sync-manager' ) );
		}

		$decrypted_token = GSM_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted_token ) ) {
			return $decrypted_token;
		}

		$api = new GSM_GitHub_API( $decrypted_token );

		// Secure download
		$tmp_file = $api->download_package( $package );
		if ( is_wp_error( $tmp_file ) ) {
			GSM_Manager::log(
				! empty( self::$currently_installing_repo ) ? self::$currently_installing_repo : 'sistema',
				'download',
				'erro',
				/* translators: %s: error message */
				sprintf( __( 'Falha ao baixar o pacote do GitHub: %s', 'github-sync-manager' ), $tmp_file->get_error_message() )
			);
			return $tmp_file;
		}

		// Validate ZIP archive
		$validation = self::validate_plugin_zip( $tmp_file );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $tmp_file );
			GSM_Manager::log(
				! empty( self::$currently_installing_repo ) ? self::$currently_installing_repo : 'sistema',
				'validacao_zip',
				'erro',
				$validation->get_error_message()
			);
			return $validation;
		}

		return $tmp_file;
	}

	/**
	 * Validates if the downloaded ZIP is a valid WordPress Plugin.
	 * It must contain at least one PHP file with a 'Plugin Name:' header.
	 *
	 * @param string $zip_path Absolute path to the local ZIP file.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public static function validate_plugin_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Skip validation to prevent error if ZipArchive is missing on host, but log a warning.
			return true;
		}

		$zip = new ZipArchive();
		if ( true === $zip->open( $zip_path ) ) {
			$has_plugin_header = false;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$filename = $zip->getNameIndex( $i );
				// Check only PHP files, ignore nested vendor files or files inside folders that are not plugin roots
				if ( preg_match( '/\.php$/i', $filename ) ) {
					$content = $zip->getFromIndex( $i, 8192 ); // Read first 8KB of file content
					if ( false !== $content && false !== stripos( $content, 'Plugin Name:' ) ) {
						$has_plugin_header = true;
						break;
					}
				}
			}
			$zip->close();

			if ( ! $has_plugin_header ) {
				return new WP_Error(
					'gsm_invalid_plugin_zip',
					__( 'O ZIP baixado não contém um plugin WordPress válido (cabeçalho "Plugin Name:" ausente).', 'github-sync-manager' )
				);
			}
		} else {
			return new WP_Error(
				'gsm_zip_open_failed',
				__( 'Não foi possível abrir o ZIP baixado.', 'github-sync-manager' )
			);
		}

		return true;
	}

	/**
	 * Hooks into upgrader_source_selection to rename the extracted temporary folder to the canonical plugin slug.
	 * Resolves deactivation problems where GitHub adds tags or branch names to directory names.
	 *
	 * @param string      $source        Full path to unzipped directory.
	 * @param string      $remote_source Full path to remote source.
	 * @param WP_Upgrader $upgrader      Current upgrader instance.
	 * @param array       $hook_extra    Extra hook details.
	 * @return string|WP_Error Corrected unzipped directory path, or WP_Error.
	 */
	public static function upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		// Verify if it's one of our plugins
		$is_gsm = false;
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
			foreach ( $managed_plugins as $slug => $data ) {
				if ( $data['plugin_file'] === $hook_extra['plugin'] ) {
					$is_gsm = true;
					break;
				}
			}
		}

		if ( ! $is_gsm && ! empty( self::$currently_installing_repo ) ) {
			$is_gsm = true;
		}

		if ( ! $is_gsm ) {
			return $source;
		}

		// Search for the main PHP file inside the unzipped contents to extract Text Domain or name
		$source_dir   = trailingslashit( $source );
		$main_file    = '';
		$text_domain  = '';
		$fallback_name= '';

		// Identify subfolder if specified
		$subfolder = '';
		if ( ! empty( self::$currently_installing_subfolder ) ) {
			$subfolder = self::$currently_installing_subfolder;
		} else {
			$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
			if ( is_array( $managed_plugins ) ) {
				foreach ( $managed_plugins as $slug => $data ) {
					if ( isset( $data['plugin_file'] ) && ! empty( $hook_extra['plugin'] ) && $data['plugin_file'] === $hook_extra['plugin'] ) {
						if ( ! empty( $data['subfolder'] ) ) {
							$subfolder = $data['subfolder'];
						}
						break;
					}
				}
			}
		}

		$search_dir = $source_dir;
		if ( ! empty( $subfolder ) ) {
			$search_dir = $source_dir . trim( $subfolder, '/' );
			if ( ! is_dir( $search_dir ) ) {
				return new WP_Error(
					'gsm_subfolder_not_found',
					/* translators: %s: subfolder path */
					sprintf( __( 'O subdiretório especificado "%s" não foi encontrado no repositório.', 'github-sync-manager' ), $subfolder )
				);
			}
			$search_dir = trailingslashit( $search_dir );
		}

		// Search recursively for PHP files
		$dir_iterator = new RecursiveDirectoryIterator( $search_dir );
		$iterator     = new RecursiveIteratorIterator( $dir_iterator );
		$regex        = new RegexIterator( $iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH );

		foreach ( $regex as $file_path => $object ) {
			// Skip directories
			if ( is_dir( $file_path ) ) {
				continue;
			}
			$data = get_file_data( $file_path, array(
				'PluginName' => 'Plugin Name',
				'TextDomain' => 'Text Domain',
			) );

			if ( ! empty( $data['PluginName'] ) ) {
				$main_file     = $file_path;
				$text_domain   = trim( $data['TextDomain'] );
				$fallback_name = sanitize_title( basename( $file_path, '.php' ) );
				break;
			}
		}

		if ( empty( $main_file ) ) {
			return new WP_Error(
				'gsm_slug_resolution_failed',
				__( 'Não foi possível encontrar um arquivo PHP de plugin válido dentro do ZIP extraído.', 'github-sync-manager' )
			);
		}

		// Determine the canonical folder slug: prefer Text Domain, fallback to main filename
		$canonical_slug = ! empty( $text_domain ) ? sanitize_title( $text_domain ) : $fallback_name;

		if ( empty( $canonical_slug ) ) {
			return new WP_Error(
				'gsm_invalid_slug',
				__( 'Falha ao resolver um slug válido para o plugin.', 'github-sync-manager' )
			);
		}

		// Normalize paths for comparison
		$source_path      = rtrim( wp_normalize_path( $source ), '/' );
		$plugin_root_dir  = rtrim( wp_normalize_path( dirname( $main_file ) ), '/' );
		$corrected_source = rtrim( wp_normalize_path( trailingslashit( dirname( $source_path ) ) . $canonical_slug ), '/' );

		if ( file_exists( $corrected_source ) ) {
			// Delete existing destination folder in temp to avoid collision
			GSM_Manager::delete_directory_recursive( $corrected_source );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		if ( $plugin_root_dir !== $source_path ) {
			// Plugin is nested inside a subdirectory. Move the subdirectory to the target destination.
			if ( ! $wp_filesystem->move( $plugin_root_dir, $corrected_source ) ) {
				return new WP_Error(
					'gsm_rename_nested_failed',
					__( 'Falha ao renomear o subdiretório do plugin para o slug canônico.', 'github-sync-manager' )
				);
			}
		} else {
			// Plugin is at the root. Rename the root source folder.
			if ( $source_path !== $corrected_source ) {
				if ( ! $wp_filesystem->move( $source_path, $corrected_source ) ) {
					return new WP_Error(
						'gsm_rename_failed',
						__( 'Falha ao renomear o diretório temporário do plugin para o slug canônico.', 'github-sync-manager' )
					);
				}
			}
		}

		return trailingslashit( $corrected_source );
	}

	/**
	 * Pre-install hook. Creates a direct folder backup of the existing plugin.
	 *
	 * @param bool  $reply      Default reply.
	 * @param array $hook_extra Extra upgrader context.
	 * @return bool|WP_Error
	 */
	public static function upgrader_pre_install( $reply, $hook_extra ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		if ( empty( $hook_extra['plugin'] ) ) {
			return $reply;
		}

		$plugin_file = $hook_extra['plugin'];
		$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );

		$is_gsm = false;
		$repo_slug = '';
		foreach ( $managed_plugins as $slug => $data ) {
			if ( $data['plugin_file'] === $plugin_file ) {
				$is_gsm = true;
				$repo_slug = $slug;
				break;
			}
		}

		if ( ! $is_gsm ) {
			return $reply;
		}

		// Create backup
		$plugin_folder = dirname( $plugin_file );
		if ( '.' === $plugin_folder || empty( $plugin_folder ) ) {
			return $reply; // Single-file plugins (not inside a folder) are not backed up.
		}

		$plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_folder;
		if ( ! is_dir( $plugin_dir_path ) ) {
			return $reply;
		}

		$backup_root = GSM_Manager::get_secure_directory( 'gsm-backups' );
		if ( is_wp_error( $backup_root ) ) {
			return $backup_root;
		}

		$backup_path = $backup_root . '/' . $plugin_folder;

		// Remove any existing stale backup folder
		if ( file_exists( $backup_path ) ) {
			GSM_Manager::delete_directory_recursive( $backup_path );
		}

		// Copy folder recursively
		$copy_status = self::copy_directory( $plugin_dir_path, $backup_path );

		if ( ! $copy_status ) {
			return new WP_Error(
				'gsm_backup_failed',
				__( 'Falha ao criar cópia de segurança do plugin existente. Atualização cancelada por segurança.', 'github-sync-manager' )
			);
		}

		// Save backup details
		self::$backup_info = array(
			'repo'          => $repo_slug,
			'plugin_folder' => $plugin_folder,
			'src_path'      => $plugin_dir_path,
			'backup_path'   => $backup_path,
		);

		return $reply;
	}

	/**
	 * Post-install hook. Restores backup if failed, or deletes backup folder if successful.
	 *
	 * @param bool  $reply      Default reply.
	 * @param array $hook_extra Upgrader args.
	 * @param array $result     Installation results.
	 * @return bool|array|WP_Error Corrected results or WP_Error.
	 */
	public static function upgrader_post_install( $reply, $hook_extra, $result ) {
		if ( empty( self::$backup_info ) ) {
			return $result;
		}

		$backup = self::$backup_info;
		self::$backup_info = array(); // Clear state

		if ( is_wp_error( $result ) || false === $result ) {
			// RESTORE BACKUP
			if ( is_dir( $backup['backup_path'] ) ) {
				// Delete corrupted folder in plugins
				if ( is_dir( $backup['src_path'] ) ) {
					GSM_Manager::delete_directory_recursive( $backup['src_path'] );
				}

				// Restore from backup path
				$restore = self::copy_directory( $backup['backup_path'], $backup['src_path'] );
				GSM_Manager::delete_directory_recursive( $backup['backup_path'] );

				if ( $restore ) {
					GSM_Manager::log(
						$backup['repo'],
						'restauracao',
						'sucesso',
						__( 'Atualização falhou. Backup restaurado com sucesso.', 'github-sync-manager' )
					);
				} else {
					GSM_Manager::log(
						$backup['repo'],
						'restauracao',
						'erro',
						__( 'Atualização falhou e houve erro ao restaurar o backup. O plugin pode ter sido removido.', 'github-sync-manager' )
					);
				}
			}
		} else {
			// SUCCESS - Delete backup directory to conserve space
			if ( is_dir( $backup['backup_path'] ) ) {
				GSM_Manager::delete_directory_recursive( $backup['backup_path'] );
			}
			GSM_Manager::log(
				$backup['repo'],
				'atualizacao',
				'sucesso',
				__( 'Plugin atualizado com sucesso usando o fluxo nativo.', 'github-sync-manager' )
			);
		}

		return $result;
	}

	/**
	 * Utility to copy a directory recursively.
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 * @return bool True on success, false on failure.
	 */
	public static function copy_directory( $source, $destination ) {
		if ( ! is_dir( $source ) ) {
			return false;
		}

		if ( ! file_exists( $destination ) ) {
			if ( ! wp_mkdir_p( $destination ) ) {
				return false;
			}
		}

		$dir = @opendir( $source );
		if ( ! $dir ) {
			return false;
		}

		$success = true;
		while ( ( $file = readdir( $dir ) ) !== false ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}

			$src_path  = $source . '/' . $file;
			$dest_path = $destination . '/' . $file;

			if ( is_dir( $src_path ) ) {
				if ( ! self::copy_directory( $src_path, $dest_path ) ) {
					$success = false;
				}
			} else {
				if ( ! @copy( $src_path, $dest_path ) ) {
					$success = false;
				}
			}
		}
		closedir( $dir );

		return $success;
	}
}
