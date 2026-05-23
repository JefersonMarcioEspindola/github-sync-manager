<?php
/**
 * Admin AJAX Handlers
 *
 * All secure AJAX endpoints for the CodeSync admin area: account
 * connect/disconnect, package install/remove/update/rollback, repo
 * verification, locale, and webhook actions.
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CODESYNC_Admin_AJAX {

	/**
	 * Init AJAX hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_codesync_connect_account', array( __CLASS__, 'ajax_connect_account' ) );
		add_action( 'wp_ajax_codesync_disconnect_account', array( __CLASS__, 'ajax_disconnect_account' ) );
		add_action( 'wp_ajax_codesync_add_plugin', array( __CLASS__, 'ajax_add_plugin' ) );
		add_action( 'wp_ajax_codesync_verify_repo', array( __CLASS__, 'ajax_verify_repo' ) );
		add_action( 'wp_ajax_codesync_remove_plugin', array( __CLASS__, 'ajax_remove_plugin' ) );
		add_action( 'wp_ajax_codesync_check_updates', array( __CLASS__, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_codesync_save_locale', array( __CLASS__, 'ajax_save_locale' ) );
		add_action( 'wp_ajax_codesync_force_update', array( __CLASS__, 'ajax_force_update' ) );
		add_action( 'wp_ajax_codesync_rollback', array( __CLASS__, 'ajax_rollback_plugin' ) );
		add_action( 'wp_ajax_codesync_verify_webhook', array( __CLASS__, 'ajax_verify_webhook' ) );
		add_action( 'wp_ajax_codesync_get_webhook_logs', array( __CLASS__, 'ajax_get_webhook_logs' ) );
		add_action( 'wp_ajax_codesync_disconnect_webhook', array( __CLASS__, 'ajax_disconnect_webhook' ) );
	}

	public static function ajax_connect_account() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( empty( $raw_token ) ) {
			wp_send_json_error( array( 'message' => __( 'O token está vazio.', 'codesync-manager-for-github' ) ) );
		}

		// Encrypt and test connection
		$encrypted = CODESYNC_Encryption::encrypt( $raw_token );
		if ( is_wp_error( $encrypted ) ) {
			wp_send_json_error( array( 'message' => $encrypted->get_error_message() ) );
		}

		$api = new CODESYNC_GitHub_API( $raw_token );
		$validation = $api->validate_token();

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
		}

		// Success: Save details to options
		update_option( CODESYNC_Manager::OPTION_TOKEN, $encrypted );
		update_option( CODESYNC_Manager::OPTION_USER, $validation ); // Autoload = yes for general connected status is fine since it's small

		/* translators: %s: GitHub username */
		CODESYNC_Manager::log( 'sistema', 'conexao', 'sucesso', sprintf( __( 'Conta conectada com sucesso (@%s).', 'codesync-manager-for-github' ), $validation['username'] ) );

		wp_send_json_success( array(
			'message'    => __( 'Conectado com sucesso!', 'codesync-manager-for-github' ),
			'username'   => $validation['username'],
			'avatar_url' => $validation['avatar_url'],
		) );
	}

	/**
	 * AJAX endpoint: Disconnect account.
	 */
	public static function ajax_disconnect_account() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		// Delete authentication options
		delete_option( CODESYNC_Manager::OPTION_TOKEN );
		delete_option( CODESYNC_Manager::OPTION_USER );

		CODESYNC_Manager::log( 'sistema', 'desconexao', 'sucesso', __( 'Conta GitHub desconectada. Os plugins não receberão atualizações até que uma nova conta seja reconectada.', 'codesync-manager-for-github' ) );

		wp_send_json_success( array( 'message' => __( 'Conta desconectada com sucesso!', 'codesync-manager-for-github' ) ) );
	}

	/**
	 * AJAX endpoint: Retrieve repos list.
	 */
	public static function ajax_add_plugin() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		// Connect API
		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'codesync-manager-for-github' ) ) );
		}

		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		// Fetch action: Get list of user repositories OR install repository
		$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

		if ( 'list' === $action_type ) {
			$api   = new CODESYNC_GitHub_API( $decrypted );
			$force_refresh = isset( $_POST['force_refresh'] ) && '1' === $_POST['force_refresh'];
			
			$repos_data = $api->get_repositories( $force_refresh );

			if ( is_wp_error( $repos_data ) ) {
				wp_send_json_error( array( 'message' => $repos_data->get_error_message() ) );
			}

			$repos        = isset( $repos_data['repos'] ) ? $repos_data['repos'] : array();
			$last_updated = isset( $repos_data['last_updated'] ) ? $repos_data['last_updated'] : '';

			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			if ( ! is_array( $managed_plugins ) ) {
				$managed_plugins = array();
			}

			// Add is_managed flag
			foreach ( $repos as &$r ) {
				$r['is_managed'] = isset( $managed_plugins[ $r['full_name'] ] );
			}

			wp_send_json_success( array( 'repos' => $repos, 'last_updated' => $last_updated ) );
		} elseif ( 'install' === $action_type ) {
			$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
			if ( empty( $repo_slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
			}

			$selected_ref       = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
			$selected_subfolder = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';

			// Self block validation
			$valid_repo = CODESYNC_Manager::validate_repository_before_add( $repo_slug );
			if ( is_wp_error( $valid_repo ) ) {
				wp_send_json_error( array( 'message' => $valid_repo->get_error_message() ) );
			}

			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			if ( ! is_array( $managed_plugins ) ) {
				$managed_plugins = array();
			}

			if ( isset( $managed_plugins[ $repo_slug ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Este repositório já está sendo gerenciado.', 'codesync-manager-for-github' ) ) );
			}

			// Explode owner and repo
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) !== 2 ) {
				wp_send_json_error( array( 'message' => __( 'Slug de repositório inválido.', 'codesync-manager-for-github' ) ) );
			}
			$owner = $parts[0];
			$repo  = $parts[1];

			$api = new CODESYNC_GitHub_API( $decrypted );

			// Check access directly for the specific repository (solves Fine-Grained access verification)
			$access = $api->test_repo_access( $owner, $repo );
			if ( is_wp_error( $access ) ) {
				wp_send_json_error( array( 'message' => $access->get_error_message() ) );
			}

			// Fetch releases
			$releases = $api->get_releases( $owner, $repo, true ); // Force reload bypass cache
			if ( is_wp_error( $releases ) ) {
				wp_send_json_error( array( 'message' => $releases->get_error_message() ) );
			}

			$target_release = null;
			if ( ! empty( $selected_ref ) ) {
				foreach ( $releases as $rel ) {
					if ( $rel['tag_name'] === $selected_ref ) {
						$target_release = $rel;
						break;
					}
				}
			}

			$default_branch = $api->get_default_branch( $owner, $repo );
			if ( is_wp_error( $default_branch ) ) {
				wp_send_json_error( array( 'message' => $default_branch->get_error_message() ) );
			}

			if ( ! $target_release && ( empty( $selected_ref ) || $selected_ref === $default_branch ) ) {
				foreach ( $releases as $rel ) {
					if ( ! empty( $rel['is_branch'] ) && $rel['branch_name'] === $default_branch ) {
						$target_release = $rel;
						break;
					}
				}
			}

			if ( ! $target_release ) {
				if ( ! empty( $selected_ref ) ) {
					$target_release = array(
						'tag_name'    => $selected_ref,
						'zipball_url' => sprintf( '%s/repos/%s/%s/zipball/%s', CODESYNC_GitHub_API::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $selected_ref ) ),
						'is_branch'   => true,
						'branch_name' => $selected_ref,
					);
				} else {
					if ( ! empty( $releases ) ) {
						$target_release = $releases[0];
					}
				}
			}

			if ( empty( $target_release ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhuma release ou branch disponível encontrada.', 'codesync-manager-for-github' ) ) );
			}

			$package_type = isset( $_POST['package_type'] ) ? sanitize_text_field( wp_unslash( $_POST['package_type'] ) ) : 'auto';

			$package_url = '';
			if ( 'asset' === $package_type || 'auto' === $package_type ) {
				if ( ! empty( $target_release['assets'] ) ) {
					$package_url = $target_release['assets'][0]['url'];
				} elseif ( ! empty( $target_release['zipball_url'] ) ) {
					$package_url = $target_release['zipball_url'];
				}
			} elseif ( 'source' === $package_type ) {
				if ( ! empty( $target_release['zipball_url'] ) ) {
					$package_url = $target_release['zipball_url'];
				}
			}

			if ( empty( $package_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhum ZIP de pacote de download encontrado.', 'codesync-manager-for-github' ) ) );
			}

			$package_type_wp = isset( $_POST['package_type_wp'] ) ? sanitize_text_field( wp_unslash( $_POST['package_type_wp'] ) ) : 'plugin';

			// Native programmatic installation via Upgrader
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			// Snapshot installed plugins/themes BEFORE upgrader to detect first-install vs sync
			$plugins_before = get_plugins();
			$themes_before  = wp_get_themes();

			// Set the dynamic global variable so the upgrader filters recognize and resolve the canonical slug
			CODESYNC_Updater::$currently_installing_repo           = $repo_slug;
			CODESYNC_Updater::$currently_installing_subfolder      = $selected_subfolder;
			CODESYNC_Updater::$currently_installing_type           = $package_type_wp;
			CODESYNC_Updater::$currently_installing_canonical_slug = '';

			// Force direct filesystem access — required for AJAX-based installation
			$codesync_fs_filter = function() { return 'direct'; };
			add_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );

			// Use silent/automatic skin
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

			// Re-initialize files and flush cache
			wp_clean_plugins_cache();

			// Capture the resolved canonical slug
			$resolved_canonical_slug = CODESYNC_Updater::$currently_installing_canonical_slug;

			// Clear dynamic variables
			CODESYNC_Updater::$currently_installing_repo           = '';
			CODESYNC_Updater::$currently_installing_subfolder      = '';
			CODESYNC_Updater::$currently_installing_type           = '';
			CODESYNC_Updater::$currently_installing_canonical_slug = '';

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( ! $result ) {
				$skin_messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
				$detail        = ! empty( $skin_messages ) ? implode( ' ', array_slice( $skin_messages, -3 ) ) : '';
				wp_send_json_error( array(
					'message' => __( 'A instalação do pacote falhou. Detalhes: ', 'codesync-manager-for-github' ) . $detail,
				) );
			}

			$latest_version = ltrim( $target_release['tag_name'], 'vV' );
			$is_branch   = ! empty( $target_release['is_branch'] );
			$branch_name = isset( $target_release['branch_name'] ) ? $target_release['branch_name'] : '';

			if ( 'theme' === $package_type_wp ) {
				$installed_theme_folder = $resolved_canonical_slug;
				if ( empty( $installed_theme_folder ) ) {
					$parts = explode( '/', $repo_slug );
					$installed_theme_folder = sanitize_title( end( $parts ) );
				}
				$managed_themes = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
				$managed_themes[ $repo_slug ] = array(
					'theme_folder'   => $installed_theme_folder,
					'latest_version' => $latest_version,
					'status'         => 'atualizado',
					'last_checked'   => current_time( 'mysql' ),
					'html_url'       => 'https://github.com/' . $repo_slug,
					'error_message'  => '',
					'is_branch'      => $is_branch,
					'branch_name'    => $branch_name,
				);
				CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_THEMES, $managed_themes );
				CODESYNC_Manager::log( $repo_slug, 'adicionar', 'sucesso', __( 'Tema gerenciado adicionado com sucesso.', 'codesync-manager-for-github' ) );

				$was_already_installed = isset( $themes_before[ $installed_theme_folder ] );
				$theme_obj             = wp_get_theme( $installed_theme_folder );
				$theme_name            = $theme_obj->exists() ? $theme_obj->get( 'Name' ) : $installed_theme_folder;

				wp_send_json_success( array(
					'message'               => __( 'Tema gerenciado com sucesso!', 'codesync-manager-for-github' ),
					'plugin_name'           => $theme_name,
					'version'               => $latest_version,
					'was_already_installed' => $was_already_installed,
					'activate_url'          => $was_already_installed ? '' : admin_url( 'themes.php' ),
					'package_type'          => 'theme',
				) );

			} else {
				// Plugin Fallback Strategy 3
				if ( empty( $installed_plugin_file ) ) {
					$folders_dir = glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR );
					$latest_time = 0;
					$latest_folder = '';
					foreach ( $folders_dir as $f ) {
						$mtime = filemtime( $f );
						if ( $mtime > $latest_time && basename( $f ) !== 'codesync-manager-for-github' ) {
							$latest_time = $mtime;
							$latest_folder = basename( $f );
						}
					}
					if ( ! empty( $latest_folder ) && ( time() - $latest_time ) < 300 ) {
						$nested_files = glob( WP_PLUGIN_DIR . '/' . $latest_folder . '/*.php' );
						foreach ( $nested_files as $nf ) {
							$data_f = get_file_data( $nf, array( 'PluginName' => 'Plugin Name' ) );
							if ( ! empty( $data_f['PluginName'] ) ) {
								$installed_plugin_file = $latest_folder . '/' . basename( $nf );
								break;
							}
						}
					}
				}

				if ( empty( $installed_plugin_file ) ) {
					wp_send_json_error( array( 'message' => __( 'Plugin instalado com sucesso, mas não foi possível localizar seu arquivo principal no WordPress.', 'codesync-manager-for-github' ) ) );
				}

				$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
				$managed_plugins[ $repo_slug ] = array(
					'plugin_file'    => $installed_plugin_file,
					'latest_version' => $latest_version,
					'status'         => 'atualizado',
					'last_checked'   => current_time( 'mysql' ),
					'html_url'       => 'https://github.com/' . $repo_slug,
					'error_message'  => '',
					'is_branch'      => $is_branch,
					'branch_name'    => $branch_name,
					'subfolder'      => $selected_subfolder,
				);
				CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
				CODESYNC_Manager::log( $repo_slug, 'adicionar', 'sucesso', __( 'Plugin gerenciado adicionado com sucesso.', 'codesync-manager-for-github' ) );

				$was_already_installed = isset( $plugins_before[ $installed_plugin_file ] );
				$plugin_data           = get_plugin_data( WP_PLUGIN_DIR . '/' . $installed_plugin_file, false, false );
				$plugin_name           = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $installed_plugin_file;
				$activate_url          = '';
				if ( ! $was_already_installed && ! is_plugin_active( $installed_plugin_file ) ) {
					$activate_url = wp_nonce_url(
						admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $installed_plugin_file ) ),
						'activate-plugin_' . $installed_plugin_file
					);
				}

				wp_send_json_success( array(
					'message'               => __( 'Plugin gerenciado com sucesso!', 'codesync-manager-for-github' ),
					'plugin_name'           => $plugin_name,
					'version'               => $latest_version,
					'was_already_installed' => $was_already_installed,
					'activate_url'          => $activate_url,
					'package_type'          => 'plugin',
				) );
			}
		}

		wp_send_json_error( array( 'message' => __( 'Ação inválida.', 'codesync-manager-for-github' ) ) );
	}

	/**
	 * AJAX endpoint: Remove plugin from managed list.
	 */
	public static function ajax_remove_plugin() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		$managed_themes  = get_option( CODESYNC_Manager::OPTION_THEMES, array() );

		if ( ! is_array( $managed_plugins ) ) $managed_plugins = array();
		if ( ! is_array( $managed_themes ) ) $managed_themes = array();

		$removed = false;
		if ( isset( $managed_plugins[ $repo_slug ] ) ) {
			unset( $managed_plugins[ $repo_slug ] );
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
			$removed = true;
		} elseif ( isset( $managed_themes[ $repo_slug ] ) ) {
			unset( $managed_themes[ $repo_slug ] );
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_THEMES, $managed_themes );
			$removed = true;
		}

		if ( $removed ) {
			// Clear release cache transient immediately to prevent database orphan records
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) === 2 ) {
				CODESYNC_GitHub_API::delete_releases_cache( $parts[0], $parts[1] );
			}

			CODESYNC_Manager::log( $repo_slug, 'parar_gerenciar', 'sucesso', __( 'Parou de gerenciar o repositório. O pacote continua instalado no WordPress.', 'codesync-manager-for-github' ) );
			wp_send_json_success( array( 'message' => __( 'Gerenciamento removido com sucesso!', 'codesync-manager-for-github' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Repositório não encontrado.', 'codesync-manager-for-github' ) ) );
	}

	/**
	 * AJAX endpoint: Check updates now manually.
	 */
	public static function ajax_check_updates() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'codesync-manager-for-github' ) ) );
		}

		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		$managed_themes  = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
		if ( ! is_array( $managed_plugins ) ) $managed_plugins = array();
		if ( ! is_array( $managed_themes ) ) $managed_themes = array();
		
		$managed = array_merge( $managed_plugins, $managed_themes );

		if ( empty( $managed ) ) {
			wp_send_json_success( array( 'message' => __( 'Nenhum pacote gerenciado para verificar.', 'codesync-manager-for-github' ) ) );
		}

		$api = new CODESYNC_GitHub_API( $decrypted );

		foreach ( $managed as $repo => $data ) {
			$is_theme = isset( $data['theme_folder'] );
			$package_file_or_folder = $is_theme ? $data['theme_folder'] : ( isset( $data['plugin_file'] ) ? $data['plugin_file'] : '' );
			
			if ( empty( $package_file_or_folder ) ) {
				continue;
			}

			if ( $is_theme ) {
				$package_path = get_theme_root() . '/' . $package_file_or_folder;
			} else {
				$package_path = WP_PLUGIN_DIR . '/' . $package_file_or_folder;
			}

			if ( ! file_exists( $package_path ) ) {
				if ( $is_theme ) {
					$managed_themes[ $repo ]['status']        = 'indisponivel';
					$managed_themes[ $repo ]['error_message'] = __( 'Diretório do tema não encontrado localmente.', 'codesync-manager-for-github' );
				} else {
					$managed_plugins[ $repo ]['status']        = 'indisponivel';
					$managed_plugins[ $repo ]['error_message'] = __( 'Diretório ou arquivo principal do plugin não encontrado localmente.', 'codesync-manager-for-github' );
				}
				continue;
			}

			// Resolve version
			$installed_version = '0.0.0';
			if ( $is_theme ) {
				$theme = wp_get_theme( $package_file_or_folder );
				if ( $theme->exists() ) {
					$installed_version = $theme->get('Version');
				}
			} else {
				$file_data = get_file_data( $package_path, array( 'Version' => 'Version' ) );
				$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';
			}

			$parts = explode( '/', $repo );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$owner = $parts[0];
			$repo_name = $parts[1];

			// Query releases directly bypassing 1-hour cache
			$releases = $api->get_releases( $owner, $repo_name, true );

			$managed[ $repo ]['last_checked'] = current_time( 'mysql' );

			if ( is_wp_error( $releases ) ) {
				$managed[ $repo ]['status']        = 'erro';
				$managed[ $repo ]['error_message'] = $releases->get_error_message();
				continue;
			}

			if ( empty( $releases ) ) {
				$managed[ $repo ]['status']        = 'erro';
				$managed[ $repo ]['error_message'] = __( 'Repositório não possui releases publicadas.', 'codesync-manager-for-github' );
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

		// Split updated states back to DB
		foreach ( $managed as $repo => $data ) {
			if ( isset( $data['theme_folder'] ) ) {
				$managed_themes[ $repo ] = $data;
			} else {
				$managed_plugins[ $repo ] = $data;
			}
		}

		CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
		CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_THEMES, $managed_themes );

		// Clear core update cache transient to force WordPress to recognize updates immediately in standard screen
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		CODESYNC_Manager::log( 'sistema', 'verificacao_manual', 'sucesso', __( 'Verificação manual executada para todos os plugins gerenciados.', 'codesync-manager-for-github' ) );

		// Capture rendered HTML of table body and logs
		ob_start();
		CODESYNC_Admin_UI::render_plugins_cards();
		$table_html = ob_get_clean();

		ob_start();
		CODESYNC_Admin_UI::render_logs_table();
		$logs_html = ob_get_clean();

		wp_send_json_success( array(
			'message'    => __( 'Verificação concluída!', 'codesync-manager-for-github' ),
			'table_html' => $table_html,
			'logs_html'  => $logs_html,
		) );
	}

	/**
	 * AJAX endpoint: Force reinstall a managed plugin from its latest GitHub release.
	 */
	public static function ajax_force_update() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		$ignore_php_check = ! empty( $_POST['ignore_php_check'] );
		CODESYNC_Updater::$ignore_php_check = $ignore_php_check;

		$result = CODESYNC_Updater::perform_update( $repo_slug, $ignore_php_check, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array(
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			) );
		}

		$latest_version = $result['version'];
		$plugin_name    = $result['plugin_name'];

		CODESYNC_Manager::log(
			$repo_slug,
			'atualizacao',
			'sucesso',
			/* translators: %s: version number */
			sprintf( __( 'Plugin reinstalado com sucesso via força (Versão %s).', 'codesync-manager-for-github' ), $latest_version )
		);

		ob_start();
		CODESYNC_Admin_UI::render_plugins_cards();
		$cards_html = ob_get_clean();

		ob_start();
		CODESYNC_Admin_UI::render_logs_table();
		$logs_html = ob_get_clean();

		wp_send_json_success( array(
			/* translators: %s: version number */
			'message'    => sprintf( __( 'Plugin reinstalado com sucesso! (Versão %s)', 'codesync-manager-for-github' ), $latest_version ),
			'table_html' => $cards_html,
			'logs_html'  => $logs_html,
		) );
	}

	/**
	 * Save configured locale via AJAX.
	 */
	public static function ajax_save_locale() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Você não tem permissão para realizar esta ação.', 'codesync-manager-for-github' ) ) );
		}

		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : 'pt_BR';

		if ( ! in_array( $locale, array( 'pt_BR', 'en_US', 'es_ES' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Idioma inválido.', 'codesync-manager-for-github' ) ) );
		}

		update_option( 'codesync_locale', $locale );

		// Clear core update cache transient to reload options
		delete_site_transient( 'update_plugins' );

		wp_send_json_success( array( 'message' => __( 'Idioma atualizado com sucesso!', 'codesync-manager-for-github' ) ) );
	}

	/**
	 * AJAX endpoint: Verify repository structure, releases, branches, and directories.
	 */
	public static function ajax_verify_repo() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'codesync-manager-for-github' ) ) );
		}

		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		// Explode owner and repo
		$parts = explode( '/', $repo_slug );
		if ( count( $parts ) !== 2 ) {
			wp_send_json_error( array( 'message' => __( 'Slug de repositório inválido.', 'codesync-manager-for-github' ) ) );
		}
		$owner = $parts[0];
		$repo  = $parts[1];

		$api = new CODESYNC_GitHub_API( $decrypted );

		// 1. Get default branch
		$default_branch = $api->get_default_branch( $owner, $repo );
		if ( is_wp_error( $default_branch ) ) {
			wp_send_json_error( array( 'message' => $default_branch->get_error_message() ) );
		}

		// 2. Fetch releases (bypass cache)
		$releases = $api->get_releases( $owner, $repo, true );
		if ( is_wp_error( $releases ) ) {
			wp_send_json_error( array( 'message' => $releases->get_error_message() ) );
		}

		// Determine selected ref
		$selected_ref = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
		if ( empty( $selected_ref ) ) {
			$selected_ref = $default_branch;
			if ( ! empty( $releases ) ) {
				$has_actual_releases = false;
				foreach ( $releases as $rel ) {
					if ( empty( $rel['is_branch'] ) ) {
						$has_actual_releases = true;
						break;
					}
				}

				if ( $has_actual_releases ) {
					$first_release = $releases[0];
					$selected_ref  = $first_release['tag_name'];
				}
			}
		}

		// Unified sources
		$sources = array();
		if ( ! empty( $releases ) ) {
			foreach ( $releases as $rel ) {
				$sources[] = array(
					'name'        => $rel['name'] ? $rel['name'] : $rel['tag_name'],
					'ref'         => $rel['tag_name'],
					'is_branch'   => ! empty( $rel['is_branch'] ),
					'zipball_url' => $rel['zipball_url'],
					'has_assets'  => ! empty( $rel['assets'] ),
				);
			}
		}

		$has_default_branch = false;
		foreach ( $sources as $src ) {
			if ( $src['ref'] === $default_branch ) {
				$has_default_branch = true;
				break;
			}
		}
		if ( ! $has_default_branch ) {
			$sources[] = array(
				/* translators: %s: branch name */
				'name'        => sprintf( __( 'Ramo: %s', 'codesync-manager-for-github' ), $default_branch ),
				'ref'         => $default_branch,
				'is_branch'   => true,
				'zipball_url' => sprintf( '%s/repos/%s/%s/zipball/%s', CODESYNC_GitHub_API::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $default_branch ) ),
			);
		}

		// 3. Get directory tree
		$folders = $api->get_repo_directory_tree( $owner, $repo, $selected_ref );
		if ( is_wp_error( $folders ) ) {
			$folders = array();
		}

		// 4. Try auto detection
		$auto_detected = false;
		$plugin_name   = '';
		$version       = '';
		$default_path  = '';

		$metadata = $api->detect_plugin_metadata( $owner, $repo, $selected_ref );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			$auto_detected = true;
			$plugin_name   = $metadata['name'];
			$version       = $metadata['version'];
			$default_path  = $metadata['subfolder'];
		}

		wp_send_json_success( array(
			'found'          => $auto_detected,
			'plugin_name'    => $plugin_name,
			'version'        => $version,
			'default_path'   => $default_path,
			'sources'        => $sources,
			'folders'        => $folders,
			'default_branch' => $default_branch,
		) );
	}

	/**
	 * AJAX endpoint: Rollback a plugin to its previous version.
	 */
	public static function ajax_rollback_plugin() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( ! isset( $managed[ $repo_slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin não encontrado na lista gerenciada.', 'codesync-manager-for-github' ) ) );
		}

		$plugin_file = isset( $managed[ $repo_slug ]['plugin_file'] ) ? $managed[ $repo_slug ]['plugin_file'] : '';
		if ( empty( $plugin_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Arquivo do plugin desconhecido.', 'codesync-manager-for-github' ) ) );
		}

		$plugin_folder = dirname( $plugin_file );
		if ( '.' === $plugin_folder || empty( $plugin_folder ) ) {
			wp_send_json_error( array( 'message' => __( 'Rollback não suportado para plugins de arquivo único.', 'codesync-manager-for-github' ) ) );
		}

		$backup_dir = CODESYNC_Manager::get_secure_directory( 'codesync-backups' );
		if ( is_wp_error( $backup_dir ) || ! is_dir( $backup_dir ) ) {
			wp_send_json_error( array( 'message' => __( 'Diretório de backup não encontrado.', 'codesync-manager-for-github' ) ) );
		}

		// Find the latest backup for this plugin
		$latest_backup_path = '';
		$latest_time = 0;
		$files = new DirectoryIterator( $backup_dir );
		foreach ( $files as $file ) {
			if ( $file->isDot() || ! $file->isDir() ) {
				continue;
			}
			$folder_name = $file->getFilename();
			if ( preg_match( '/^' . preg_quote( $plugin_folder, '/' ) . '-(\d{10})$/', $folder_name, $matches ) ) {
				$timestamp = (int) $matches[1];
				if ( $timestamp > $latest_time ) {
					$latest_time = $timestamp;
					$latest_backup_path = $file->getRealPath();
				}
			}
		}

		if ( empty( $latest_backup_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum backup recente encontrado para restauração.', 'codesync-manager-for-github' ) ) );
		}

		$plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_folder;

		// Capture the current installed version before overwriting it.
		$blocked_version = '';
		if ( ! empty( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			$current_data    = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array( 'Version' => 'Version' ) );
			$blocked_version = ! empty( $current_data['Version'] ) ? $current_data['Version'] : '';
		}

		// Perform restoration
		if ( is_dir( $plugin_dir_path ) ) {
			CODESYNC_Manager::delete_directory_recursive( $plugin_dir_path );
		}

		if ( ! class_exists( 'CODESYNC_Updater' ) ) {
			require_once __DIR__ . '/class-updater.php';
		}

		$restore = CODESYNC_Updater::copy_directory( $latest_backup_path, $plugin_dir_path );

		if ( $restore ) {
			// Delete the backup that was just restored
			CODESYNC_Manager::delete_directory_recursive( $latest_backup_path );

			CODESYNC_Manager::log(
				$repo_slug,
				'restauracao',
				'sucesso',
				__( 'Rollback realizado com sucesso.', 'codesync-manager-for-github' )
			);

			// Mark the rolled-back version so the webhook won't re-install it.
			$managed[ $repo_slug ]['rollback_blocked_version'] = $blocked_version;
			$managed[ $repo_slug ]['status'] = 'atualizacao_disponivel';
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );

			wp_send_json_success( array( 'message' => __( 'Rollback concluído com sucesso. A versão anterior foi restaurada.', 'codesync-manager-for-github' ) ) );
		} else {
			CODESYNC_Manager::log(
				$repo_slug,
				'restauracao',
				'erro',
				__( 'Falha ao realizar o rollback. O backup não pôde ser copiado.', 'codesync-manager-for-github' )
			);
			wp_send_json_error( array( 'message' => __( 'Erro ao restaurar os arquivos. Verifique as permissões do diretório.', 'codesync-manager-for-github' ) ) );
		}
	}

	/**
	 * AJAX endpoint: Verify webhook ping.
	 */
	public static function ajax_verify_webhook() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		$ping_time = get_option( 'codesync_webhook_ping_' . $repo_slug );
		if ( ! empty( $ping_time ) ) {
			// Webhook received successfully
			wp_send_json_success( array( 'message' => __( 'Webhook verificado com sucesso! Ping recebido.', 'codesync-manager-for-github' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Ping não recebido. Tente novamente.', 'codesync-manager-for-github' ) ) );
		}
	}

	/**
	 * AJAX endpoint: Get webhook-related logs for a specific repo (last 10).
	 */
	public static function ajax_get_webhook_logs() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		$all_logs = get_option( CODESYNC_Manager::OPTION_LOGS, array() );
		if ( ! is_array( $all_logs ) ) {
			$all_logs = array();
		}

		// Filter logs for this specific repo.
		$repo_logs = array_values( array_filter( $all_logs, function( $log ) use ( $repo_slug ) {
			return isset( $log['repo'] ) && $log['repo'] === $repo_slug;
		} ) );

		// Return up to 20 most recent.
		$recent   = array_slice( $repo_logs, 0, 20 );
		$has_more = count( $repo_logs ) > 20;

		$ping_time = get_option( 'codesync_webhook_ping_' . $repo_slug );

		wp_send_json_success( array(
			'logs'      => $recent,
			'ping_time' => $ping_time ? date_i18n( 'd/m/Y H:i', $ping_time ) : '',
			'has_more'  => $has_more,
		) );
	}

	/**
	 * AJAX endpoint: Disconnect (clear) webhook ping for a specific repo.
	 */
	public static function ajax_disconnect_webhook() {
		check_ajax_referer( 'codesync_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'codesync-manager-for-github' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'codesync-manager-for-github' ) ) );
		}

		delete_option( 'codesync_webhook_ping_' . $repo_slug );

		wp_send_json_success( array( 'message' => __( 'Webhook desconectado. O status será redefinido.', 'codesync-manager-for-github' ) ) );
	}
}
