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
 * Class CODESYNC_Updater
 *
 * Integrates with WordPress update transients, intercepts downloads, validates zip packages,
 * resolves canonical plugin slugs dynamically, and manages recursive folder backups/restoration.
 */
class CODESYNC_Updater {

	/**
	 * Tracks the repository currently being installed via the admin panel.
	 *
	 * @var string
	 */
	public static $currently_installing_repo = '';
	public static $currently_installing_type = 'plugin';
	public static $currently_installing_canonical_slug = '';

	/**
	 * Flag to bypass PHP version requirement check on manual forced updates.
	 *
	 * @var bool
	 */
	public static $ignore_php_check = false;

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
	 * Checks for available updates and updates managed plugin statuses.
	 * NOTE: This method is no longer hooked into WordPress update transients
	 * to comply with WordPress.org plugin guidelines.
	 *
	 * @param object $transient Update transient object.
	 * @return object Modified transient object.
	 */
	public static function check_updates( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return $transient;
		}

		$security_check = CODESYNC_Encryption::check_security_keys();
		if ( is_wp_error( $security_check ) ) {
			return $transient; // Cannot decrypt
		}

		$decrypted_token = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted_token ) ) {
			return $transient;
		}

		$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed_plugins ) || ! is_array( $managed_plugins ) ) {
			return $transient;
		}

		$api = new CODESYNC_GitHub_API( $decrypted_token );

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
				CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
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
					CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
				}
			} else {
				// Update managed status to updated
				if ( 'erro' === $data['status'] || 'atualizacao_disponivel' === $data['status'] ) {
					$managed_plugins[ $repo_slug ]['status']        = 'atualizado';
					$managed_plugins[ $repo_slug ]['latest_version'] = $latest_version;
					$managed_plugins[ $repo_slug ]['error_message'] = '';
					CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
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
		$package_type = 'plugin';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			foreach ( $managed_plugins as $slug => $data ) {
				if ( $data['plugin_file'] === $hook_extra['plugin'] ) {
					$is_gsm = true;
					break;
				}
			}
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$managed_themes = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
			foreach ( $managed_themes as $slug => $data ) {
				// Themes don't have a main file, they just have a folder
				if ( isset( $data['theme_folder'] ) && $data['theme_folder'] === $hook_extra['theme'] ) {
					$is_gsm = true;
					$package_type = 'theme';
					break;
				}
			}
		}

		if ( ! $is_gsm && ! empty( self::$currently_installing_repo ) ) {
			$is_gsm = true;
			if ( ! empty( self::$currently_installing_type ) ) {
				$package_type = self::$currently_installing_type;
			}
		}

		// Also check domain as fallback
		if ( ! $is_gsm && ( strpos( $package, 'api.github.com' ) !== false || strpos( $package, 'codeload.github.com' ) !== false ) ) {
			$is_gsm = true;
		}

		if ( ! $is_gsm ) {
			return $reply;
		}

		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return new WP_Error( 'codesync_missing_token', __( 'Não foi possível baixar o plugin: Token GitHub ausente.', 'codesync-manager-for-github' ) );
		}

		$decrypted_token = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted_token ) ) {
			return $decrypted_token;
		}

		$api = new CODESYNC_GitHub_API( $decrypted_token );

		// Secure download
		$tmp_file = $api->download_package( $package );
		if ( is_wp_error( $tmp_file ) ) {
			CODESYNC_Manager::log(
				! empty( self::$currently_installing_repo ) ? self::$currently_installing_repo : 'sistema',
				'download',
				'erro',
				/* translators: %s: error message */
				sprintf( __( 'Falha ao baixar o pacote do GitHub: %s', 'codesync-manager-for-github' ), $tmp_file->get_error_message() )
			);
			return $tmp_file;
		}

		// Validate ZIP archive
		$validation = self::validate_package_zip( $tmp_file, $package_type );
		if ( is_wp_error( $validation ) ) {
			wp_delete_file( $tmp_file );
			CODESYNC_Manager::log(
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
	 * Validates if the downloaded ZIP is a valid WordPress Plugin or Theme.
	 *
	 * @param string $zip_path Absolute path to the local ZIP file.
	 * @param string $type     'plugin' or 'theme'.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public static function validate_package_zip( $zip_path, $type = 'plugin' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// Skip validation to prevent error if ZipArchive is missing on host, but log a warning.
			return true;
		}

		$zip = new ZipArchive();
		if ( true === $zip->open( $zip_path ) ) {
			$is_valid = false;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$filename = $zip->getNameIndex( $i );

				if ( 'plugin' === $type ) {
					if ( preg_match( '/\.php$/i', $filename ) ) {
						$content = $zip->getFromIndex( $i, 8192 );
						if ( false !== $content && false !== stripos( $content, 'Plugin Name:' ) ) {
							$is_valid = true;
							break;
						}
					}
				} elseif ( 'theme' === $type ) {
					if ( preg_match( '/style\.css$/i', $filename ) ) {
						$content = $zip->getFromIndex( $i, 8192 );
						if ( false !== $content && false !== stripos( $content, 'Theme Name:' ) ) {
							$is_valid = true;
							break;
						}
					}
				}
			}
			$zip->close();

			if ( ! $is_valid ) {
				$msg = 'plugin' === $type 
					? __( 'O ZIP baixado não contém um plugin WordPress válido (cabeçalho "Plugin Name:" ausente).', 'codesync-manager-for-github' )
					: __( 'O ZIP baixado não contém um tema WordPress válido (cabeçalho "Theme Name:" ausente no style.css).', 'codesync-manager-for-github' );
				return new WP_Error(
					'codesync_invalid_package_zip',
					$msg
				);
			}
		} else {
			return new WP_Error(
				'codesync_zip_open_failed',
				__( 'Não foi possível abrir o ZIP baixado.', 'codesync-manager-for-github' )
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
		$package_type = 'plugin';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			foreach ( $managed_plugins as $slug => $data ) {
				if ( $data['plugin_file'] === $hook_extra['plugin'] ) {
					$is_gsm = true;
					break;
				}
			}
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$managed_themes = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
			foreach ( $managed_themes as $slug => $data ) {
				if ( isset( $data['theme_folder'] ) && $data['theme_folder'] === $hook_extra['theme'] ) {
					$is_gsm = true;
					$package_type = 'theme';
					break;
				}
			}
		}

		if ( ! $is_gsm && ! empty( self::$currently_installing_repo ) ) {
			$is_gsm = true;
			if ( ! empty( self::$currently_installing_type ) ) {
				$package_type = self::$currently_installing_type;
			}
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
			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
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
					'codesync_subfolder_not_found',
					/* translators: %s: subfolder path */
					sprintf( __( 'O subdiretório especificado "%s" não foi encontrado no repositório.', 'codesync-manager-for-github' ), $subfolder )
				);
			}
			$search_dir = trailingslashit( $search_dir );
		}

		// Search recursively for PHP or CSS files depending on type
		$dir_iterator = new RecursiveDirectoryIterator( $search_dir );
		$iterator     = new RecursiveIteratorIterator( $dir_iterator );
		$regex_pattern = ( 'theme' === $package_type ) ? '/^.+style\.css$/i' : '/^.+\.php$/i';
		$regex        = new RegexIterator( $iterator, $regex_pattern, RecursiveRegexIterator::GET_MATCH );

		foreach ( $regex as $file_path => $object ) {
			if ( is_dir( $file_path ) ) {
				continue;
			}

			if ( 'theme' === $package_type ) {
				$data = get_file_data( $file_path, array(
					'ThemeName'  => 'Theme Name',
					'TextDomain' => 'Text Domain',
					'RequiresPHP'=> 'Requires PHP',
				) );

				if ( ! empty( $data['ThemeName'] ) ) {
					$main_file     = $file_path;
					$text_domain   = trim( $data['TextDomain'] );
					$fallback_name = sanitize_title( trim( $data['ThemeName'] ) );
					$requires_php  = trim( $data['RequiresPHP'] );
					break;
				}
			} else {
				$data = get_file_data( $file_path, array(
					'PluginName' => 'Plugin Name',
					'TextDomain' => 'Text Domain',
					'RequiresPHP'=> 'Requires PHP',
				) );

				if ( ! empty( $data['PluginName'] ) ) {
					$main_file     = $file_path;
					$text_domain   = trim( $data['TextDomain'] );
					$fallback_name = sanitize_title( basename( $file_path, '.php' ) );
					$requires_php  = trim( $data['RequiresPHP'] );
					break;
				}
			}
		}

		if ( empty( $main_file ) ) {
			$error_msg = ( 'theme' === $package_type ) 
				? __( 'Não foi possível encontrar um arquivo style.css de tema válido dentro do ZIP extraído.', 'codesync-manager-for-github' )
				: __( 'Não foi possível encontrar um arquivo PHP de plugin válido dentro do ZIP extraído.', 'codesync-manager-for-github' );
			return new WP_Error(
				'codesync_slug_resolution_failed',
				$error_msg
			);
		}

		// Determine the canonical folder slug: prefer Text Domain, fallback to main filename
		$canonical_slug = ! empty( $text_domain ) ? sanitize_title( $text_domain ) : $fallback_name;

		self::$currently_installing_canonical_slug = $canonical_slug;

		if ( empty( $canonical_slug ) ) {
			return new WP_Error(
				'codesync_invalid_slug',
				__( 'Falha ao resolver um slug válido para o plugin.', 'codesync-manager-for-github' )
			);
		}

		// FEATURE 1.3: Pre-flight Checks (Validação de PHP)
		if ( ! empty( $requires_php ) && version_compare( phpversion(), $requires_php, '<' ) ) {
			$repo_to_log = ! empty( self::$currently_installing_repo ) ? self::$currently_installing_repo : 'sistema';
			if ( wp_doing_cron() ) {
				return new WP_Error(
					'codesync_php_version_mismatch',
					/* translators: 1: Required PHP, 2: Current PHP */
					sprintf( __( 'Este plugin requer PHP versão %1$s ou superior. Sua versão atual é %2$s.', 'codesync-manager-for-github' ), $requires_php, phpversion() )
				);
			} elseif ( ! self::$ignore_php_check ) {
				// Block manual update to prompt confirmation
				return new WP_Error(
					'codesync_php_version_mismatch_manual',
					/* translators: 1: Required PHP, 2: Current PHP */
					sprintf( __( 'O pacote requer PHP %1$s e o servidor possui %2$s. Deseja forçar a instalação por sua conta e risco?', 'codesync-manager-for-github' ), $requires_php, phpversion() )
				);
			} else {
				// Manual update: User confirmed, just warn
				CODESYNC_Manager::log(
					$repo_to_log,
					'atualizacao',
					'aviso',
					/* translators: 1: Required PHP, 2: Current PHP */
					sprintf( __( 'Aviso: Pacote atualizado manualmente (ignorado verificação de PHP), mas requer PHP %1$s e o servidor roda %2$s.', 'codesync-manager-for-github' ), $requires_php, phpversion() )
				);
			}
		}

		// Normalize paths for comparison
		$source_path      = rtrim( wp_normalize_path( $source ), '/' );
		$plugin_root_dir  = rtrim( wp_normalize_path( dirname( $main_file ) ), '/' );
		$corrected_source = rtrim( wp_normalize_path( trailingslashit( dirname( $source_path ) ) . $canonical_slug ), '/' );

		if ( file_exists( $corrected_source ) ) {
			// Delete existing destination folder in temp to avoid collision
			CODESYNC_Manager::delete_directory_recursive( $corrected_source );
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
			
			// FEATURE 1.1: Clean unwanted development folders before moving
			self::clean_unwanted_development_folders( $plugin_root_dir );

			if ( ! $wp_filesystem->move( $plugin_root_dir, $corrected_source ) ) {
				return new WP_Error(
					'codesync_rename_nested_failed',
					__( 'Falha ao renomear o subdiretório do plugin para o slug canônico.', 'codesync-manager-for-github' )
				);
			}
		} else {
			// Plugin is at the root. Rename the root source folder.
			
			// FEATURE 1.1: Clean unwanted development folders before moving
			self::clean_unwanted_development_folders( $source_path );

			if ( $source_path !== $corrected_source ) {
				if ( ! $wp_filesystem->move( $source_path, $corrected_source ) ) {
					return new WP_Error(
						'codesync_rename_failed',
						__( 'Falha ao renomear o diretório temporário do plugin para o slug canônico.', 'codesync-manager-for-github' )
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

		$is_gsm = false;
		$repo_slug = '';
		$target_dir_name = '';
		$target_dir_path = '';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			foreach ( $managed_plugins as $slug => $data ) {
				if ( $data['plugin_file'] === $hook_extra['plugin'] ) {
					$is_gsm = true;
					$repo_slug = $slug;
					$target_dir_name = dirname( $hook_extra['plugin'] );
					$target_dir_path = WP_PLUGIN_DIR . '/' . $target_dir_name;
					break;
				}
			}
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$managed_themes = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
			foreach ( $managed_themes as $slug => $data ) {
				if ( isset( $data['theme_folder'] ) && $data['theme_folder'] === $hook_extra['theme'] ) {
					$is_gsm = true;
					$repo_slug = $slug;
					$target_dir_name = $hook_extra['theme'];
					$target_dir_path = get_theme_root() . '/' . $target_dir_name;
					break;
				}
			}
		}

		if ( ! $is_gsm && ! empty( self::$currently_installing_repo ) ) {
			$is_gsm = true;
			$repo_slug = self::$currently_installing_repo;
			if ( self::$currently_installing_type === 'theme' ) {
				$managed_themes = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
				if ( isset( $managed_themes[ $repo_slug ] ) ) {
					$target_dir_name = $managed_themes[ $repo_slug ]['theme_folder'];
					$target_dir_path = get_theme_root() . '/' . $target_dir_name;
				}
			} else {
				$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
				if ( isset( $managed_plugins[ $repo_slug ] ) ) {
					$target_dir_name = dirname( $managed_plugins[ $repo_slug ]['plugin_file'] );
					$target_dir_path = WP_PLUGIN_DIR . '/' . $target_dir_name;
				}
			}
		}

		if ( ! $is_gsm ) {
			return $reply;
		}

		// Create backup
		if ( '.' === $target_dir_name || empty( $target_dir_name ) ) {
			return $reply; // Single-file plugins (not inside a folder) are not backed up.
		}

		if ( ! is_dir( $target_dir_path ) ) {
			return $reply;
		}

		$backup_root = CODESYNC_Manager::get_secure_directory( 'codesync-backups' );
		if ( is_wp_error( $backup_root ) ) {
			return $backup_root;
		}

		$backup_dir_name = $target_dir_name . '-' . time();
		$backup_path = $backup_root . '/' . $backup_dir_name;

		// Copy folder recursively
		$copy_status = self::copy_directory( $target_dir_path, $backup_path );

		if ( ! $copy_status ) {
			return new WP_Error(
				'codesync_backup_failed',
				__( 'Falha ao criar cópia de segurança do plugin/tema existente. Atualização cancelada por segurança.', 'codesync-manager-for-github' )
			);
		}

		// Save backup details
		self::$backup_info = array(
			'repo'          => $repo_slug,
			'folder'        => $target_dir_name,
			'src_path'      => $target_dir_path,
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
					CODESYNC_Manager::delete_directory_recursive( $backup['src_path'] );
				}

				// Restore from backup path
				$restore = self::copy_directory( $backup['backup_path'], $backup['src_path'] );
				CODESYNC_Manager::delete_directory_recursive( $backup['backup_path'] );

				if ( $restore ) {
					CODESYNC_Manager::log(
						$backup['repo'],
						'restauracao',
						'sucesso',
						__( 'Atualização falhou. Backup restaurado com sucesso.', 'codesync-manager-for-github' )
					);
				} else {
					CODESYNC_Manager::log(
						$backup['repo'],
						'restauracao',
						'erro',
						__( 'Atualização falhou e houve erro ao restaurar o backup. O pacote pode ter sido removido.', 'codesync-manager-for-github' )
					);
				}
			}
		} else {
			// SUCCESS - Keep backup directory to allow manual rollback later
			CODESYNC_Manager::log(
				$backup['repo'],
				'atualizacao',
				'sucesso',
				__( 'Pacote atualizado com sucesso usando o fluxo nativo. Backup salvo para rollback.', 'codesync-manager-for-github' )
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

	/**
	 * Cleans unwanted development folders from the extracted source before moving it.
	 *
	 * @param string $dir Path to the plugin directory being extracted.
	 */
	public static function clean_unwanted_development_folders( $dir ) {
		$unwanted = array(
			'node_modules',
			'.github',
			'tests',
			'test',
			'.git',
			'.circleci',
			'Gruntfile.js',
			'gulpfile.js',
			'webpack.config.js'
		);

		foreach ( $unwanted as $item ) {
			$path = rtrim( $dir, '/' ) . '/' . $item;
			if ( file_exists( $path ) ) {
				if ( is_dir( $path ) ) {
					CODESYNC_Manager::delete_directory_recursive( $path );
				} else {
					wp_delete_file( $path );
				}
			}
		}
	}

	/**
	 * Perform a package update (install/reinstall) for a managed plugin or theme.
	 *
	 * Used by both the AJAX force-update endpoint and the webhook auto-installer.
	 *
	 * @param string $repo_slug         Full repository slug (owner/repo).
	 * @param bool   $ignore_php_check  Whether to bypass the PHP version requirement check.
	 * @param bool   $force_reinstall   If true, reinstall even when already on the latest version.
	 * @return array|WP_Error On success returns array with 'version' and 'plugin_name', on failure WP_Error.
	 */
	public static function perform_update( $repo_slug, $ignore_php_check = false, $force_reinstall = false ) {
		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			return new WP_Error( 'codesync_missing_token', __( 'Token do GitHub não configurado.', 'codesync-manager-for-github' ) );
		}

		$security_check = CODESYNC_Encryption::check_security_keys();
		if ( is_wp_error( $security_check ) ) {
			return $security_check;
		}

		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			return $decrypted;
		}

		// Determine if this is a plugin or theme
		$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		$managed_themes  = get_option( CODESYNC_Manager::OPTION_THEMES, array() );

		$is_plugin = isset( $managed_plugins[ $repo_slug ] );
		$is_theme  = isset( $managed_themes[ $repo_slug ] );

		if ( ! $is_plugin && ! $is_theme ) {
			return new WP_Error( 'codesync_not_managed', __( 'Repositório não encontrado na lista gerenciada.', 'codesync-manager-for-github' ) );
		}

		$package_type_wp = $is_theme ? 'theme' : 'plugin';
		$plugin_data     = $is_theme ? $managed_themes[ $repo_slug ] : $managed_plugins[ $repo_slug ];

		$selected_subfolder = isset( $plugin_data['subfolder'] ) ? $plugin_data['subfolder'] : '';
		$stored_branch      = isset( $plugin_data['branch_name'] ) ? $plugin_data['branch_name'] : '';

		$parts = explode( '/', $repo_slug );
		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'codesync_invalid_slug', __( 'Slug de repositório inválido.', 'codesync-manager-for-github' ) );
		}
		$owner = $parts[0];
		$repo  = $parts[1];

		$api = new CODESYNC_GitHub_API( $decrypted );

		// Determine the target release or branch
		$target_release = null;
		if ( ! empty( $plugin_data['is_branch'] ) && ! empty( $stored_branch ) ) {
			$target_release = array(
				'tag_name'    => $stored_branch,
				'zipball_url' => 'https://api.github.com/repos/' . $repo_slug . '/zipball/' . $stored_branch,
				'assets'      => array(),
				'is_branch'   => true,
				'branch_name' => $stored_branch,
			);
		} else {
			$releases = $api->get_releases( $owner, $repo, true );
			if ( is_wp_error( $releases ) ) {
				return $releases;
			}
			if ( empty( $releases ) ) {
				return new WP_Error( 'codesync_no_releases', __( 'Nenhuma release encontrada no repositório.', 'codesync-manager-for-github' ) );
			}
			$target_release = $releases[0];

			$latest_version = ltrim( $target_release['tag_name'], 'vV' );

			// Block re-installation of a version that was explicitly rolled back.
			$blocked_version = isset( $plugin_data['rollback_blocked_version'] ) ? $plugin_data['rollback_blocked_version'] : '';
			if ( ! empty( $blocked_version ) && version_compare( $latest_version, $blocked_version, '==' ) ) {
				return new WP_Error(
					'codesync_rollback_blocked',
					/* translators: %s: version number */
					sprintf( __( 'Versão %s foi revertida manualmente. O webhook não irá reinstalá-la.', 'codesync-manager-for-github' ), $latest_version )
				);
			}

			// If a different version is coming in, clear the rollback block.
			if ( ! empty( $blocked_version ) ) {
				$managed_ref = $is_theme
					? get_option( CODESYNC_Manager::OPTION_THEMES, array() )
					: get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
				if ( isset( $managed_ref[ $repo_slug ] ) ) {
					unset( $managed_ref[ $repo_slug ]['rollback_blocked_version'] );
					CODESYNC_Manager::update_option_no_autoload(
						$is_theme ? CODESYNC_Manager::OPTION_THEMES : CODESYNC_Manager::OPTION_PLUGINS,
						$managed_ref
					);
				}
			}

			// If not force reinstall, check whether the latest version is actually newer.
			if ( ! $force_reinstall && $is_plugin ) {
				$plugin_file = isset( $plugin_data['plugin_file'] ) ? $plugin_data['plugin_file'] : '';
				if ( ! empty( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
					$file_data         = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array( 'Version' => 'Version' ) );
					$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';
					if ( version_compare( $latest_version, $installed_version, '<=' ) ) {
						return new WP_Error(
							'codesync_already_updated',
							/* translators: %s: version number */
							sprintf( __( 'O pacote já está na versão mais recente (%s).', 'codesync-manager-for-github' ), $installed_version )
						);
					}
				}
			}
		}

		$package_url = '';
		if ( ! empty( $target_release['assets'] ) ) {
			$package_url = $target_release['assets'][0]['url'];
		} elseif ( ! empty( $target_release['zipball_url'] ) ) {
			$package_url = $target_release['zipball_url'];
		}

		if ( empty( $package_url ) ) {
			return new WP_Error( 'codesync_no_package', __( 'Nenhum pacote ZIP encontrado para download.', 'codesync-manager-for-github' ) );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			include_once ABSPATH . 'wp-admin/includes/file.php';
		}
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		self::$currently_installing_repo      = $repo_slug;
		self::$currently_installing_subfolder = $selected_subfolder;
		self::$currently_installing_type      = $package_type_wp;
		self::$ignore_php_check               = $ignore_php_check;

		$codesync_fs_filter = function() { return 'direct'; };
		add_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );

		$skin = new Automatic_Upgrader_Skin();
		if ( 'theme' === $package_type_wp ) {
			include_once ABSPATH . 'wp-admin/includes/theme.php';
			$upgrader = new Theme_Upgrader( $skin );
		} else {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			$upgrader = new Plugin_Upgrader( $skin );
		}

		$result = $upgrader->install( $package_url, array( 'overwrite_package' => true ) );

		remove_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );
		wp_clean_plugins_cache();

		self::$currently_installing_repo      = '';
		self::$currently_installing_subfolder = '';
		self::$currently_installing_type      = '';

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'codesync_install_failed', __( 'A reinstalação falhou. Verifique as permissões do servidor.', 'codesync-manager-for-github' ) );
		}

		$latest_version = ltrim( $target_release['tag_name'], 'vV' );

		// Update managed record
		if ( $is_plugin ) {
			$managed_plugins[ $repo_slug ]['status']         = 'atualizado';
			$managed_plugins[ $repo_slug ]['latest_version'] = $latest_version;
			$managed_plugins[ $repo_slug ]['last_checked']   = current_time( 'mysql' );
			$managed_plugins[ $repo_slug ]['error_message']  = '';
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
			$plugin_name = isset( $plugin_data['plugin_file'] ) ? dirname( $plugin_data['plugin_file'] ) : $repo_slug;
		} else {
			$managed_themes[ $repo_slug ]['status']         = 'atualizado';
			$managed_themes[ $repo_slug ]['latest_version'] = $latest_version;
			$managed_themes[ $repo_slug ]['last_checked']   = current_time( 'mysql' );
			$managed_themes[ $repo_slug ]['error_message']  = '';
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_THEMES, $managed_themes );
			$plugin_name = isset( $plugin_data['theme_folder'] ) ? $plugin_data['theme_folder'] : $repo_slug;
		}

		return array(
			'version'     => $latest_version,
			'plugin_name' => $plugin_name,
		);
	}
}
