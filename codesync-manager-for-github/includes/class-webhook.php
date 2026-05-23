<?php
/**
 * Webhook handler for GitHub Real-time updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CODESYNC_Webhook {

	/**
	 * Webhook Secret Option Key
	 */
	const OPTION_SECRET = 'codesync_webhook_secret';

	/**
	 * Init REST API.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the REST API route.
	 */
	public static function register_routes() {
		register_rest_route( 'codesync/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true', // Validation done inside via signature
		) );
	}

	/**
	 * Handle the incoming webhook.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public static function handle_webhook( $request ) {
		$secret = get_option( self::OPTION_SECRET );
		if ( empty( $secret ) ) {
			return new WP_REST_Response( array( 'message' => 'Webhook não configurado no WordPress.' ), 400 );
		}

		$signature = $request->get_header( 'X-Hub-Signature-256' );
		if ( empty( $signature ) ) {
			return new WP_REST_Response( array( 'message' => 'Assinatura ausente.' ), 401 );
		}

		$payload = $request->get_body();
		$hash    = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

		if ( ! hash_equals( $hash, $signature ) ) {
			return new WP_REST_Response( array( 'message' => 'Assinatura inválida.' ), 401 );
		}

		$data  = json_decode( $payload, true );
		$event = $request->get_header( 'X-GitHub-Event' );

		if ( 'ping' === $event ) {
			$repo_slug = '';
			if ( ! empty( $data['repository']['full_name'] ) ) {
				$repo_slug = $data['repository']['full_name'];
				update_option( 'codesync_webhook_ping_' . $repo_slug, time() );
			}
			return new WP_REST_Response( array( 'message' => 'Pong! Conexão estabelecida com sucesso.' ), 200 );
		}

		if ( 'release' === $event || 'push' === $event ) {
			$repo_slug = '';
			if ( ! empty( $data['repository']['full_name'] ) ) {
				$repo_slug = $data['repository']['full_name'];
			}

			if ( empty( $repo_slug ) ) {
				return new WP_REST_Response( array( 'message' => 'Repositório não identificado no payload.' ), 400 );
			}

			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			$managed_themes  = get_option( CODESYNC_Manager::OPTION_THEMES, array() );

			// Check both plugins and themes
			$is_managed_plugin = isset( $managed_plugins[ $repo_slug ] );
			$is_managed_theme  = isset( $managed_themes[ $repo_slug ] );

			if ( ! $is_managed_plugin && ! $is_managed_theme ) {
				return new WP_REST_Response( array( 'message' => 'Repositório ignorado pois não é gerenciado.' ), 200 );
			}

			$plugin_data = $is_managed_theme ? $managed_themes[ $repo_slug ] : $managed_plugins[ $repo_slug ];
			$is_branch   = ! empty( $plugin_data['is_branch'] );
			$branch_name = isset( $plugin_data['branch_name'] ) ? $plugin_data['branch_name'] : '';

			// For push events, verify the pushed ref matches the tracked branch/default branch.
			if ( 'push' === $event ) {
				$pushed_ref = isset( $data['ref'] ) ? $data['ref'] : '';
				$pushed_branch = str_replace( 'refs/heads/', '', $pushed_ref );

				if ( $is_branch ) {
					// Branch-mode: only update when the tracked branch is pushed.
					if ( ! empty( $branch_name ) && $pushed_branch !== $branch_name ) {
						return new WP_REST_Response( array( 'message' => 'Push em branch não rastreada. Ignorado.' ), 200 );
					}
				} else {
					// Release-mode: skip raw branch pushes — releases handle the update.
					// But if the user pushes to the default branch, we can still signal an update.
					// We do NOT auto-install for plain pushes in release-mode to avoid installing
					// unreleased code. Just clear caches and mark as needing a check.
					delete_site_transient( 'update_plugins' );
					$parts = explode( '/', $repo_slug );
					if ( count( $parts ) === 2 ) {
						CODESYNC_GitHub_API::delete_releases_cache( $parts[0], $parts[1] );
					}
					if ( $is_managed_plugin ) {
						$managed_plugins[ $repo_slug ]['status'] = 'atualizacao_disponivel';
						CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed_plugins );
					} else {
						$managed_themes[ $repo_slug ]['status'] = 'atualizacao_disponivel';
						CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_THEMES, $managed_themes );
					}
					CODESYNC_Manager::log(
						$repo_slug,
						'sistema',
						'sucesso',
						__( 'Webhook de push recebido. Cache limpo. Aguardando nova release para atualização automática.', 'codesync-manager-for-github' )
					);
					return new WP_REST_Response( array( 'message' => 'Push recebido. Cache limpo, aguardando release.' ), 200 );
				}
			}

			// Clear caches first.
			delete_site_transient( 'update_plugins' );
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) === 2 ) {
				CODESYNC_GitHub_API::delete_releases_cache( $parts[0], $parts[1] );
			}

			// Ensure updater class is loaded.
			if ( ! class_exists( 'CODESYNC_Updater' ) ) {
				require_once __DIR__ . '/class-updater.php';
			}

			// Perform the automatic update.
			$update_result = CODESYNC_Updater::perform_update( $repo_slug, false, $is_branch );

			if ( is_wp_error( $update_result ) ) {
				$error_code = $update_result->get_error_code();

				// "Already updated" is not a real error for webhooks.
				if ( 'codesync_already_updated' === $error_code ) {
					CODESYNC_Manager::log(
						$repo_slug,
						'sistema',
						'sucesso',
						__( 'Webhook recebido mas pacote já está na versão mais recente.', 'codesync-manager-for-github' )
					);
					return new WP_REST_Response( array( 'message' => 'Pacote já atualizado.' ), 200 );
				}

				// Version was manually rolled back — skip silently until a new version is released.
				if ( 'codesync_rollback_blocked' === $error_code ) {
					CODESYNC_Manager::log(
						$repo_slug,
						'sistema',
						'aviso',
						$update_result->get_error_message()
					);
					return new WP_REST_Response( array( 'message' => $update_result->get_error_message() ), 200 );
				}

				CODESYNC_Manager::log(
					$repo_slug,
					'sistema',
					'erro',
					/* translators: %s: error message */
					sprintf( __( 'Webhook recebido mas atualização automática falhou: %s', 'codesync-manager-for-github' ), $update_result->get_error_message() )
				);
				return new WP_REST_Response( array( 'message' => 'Falha na atualização automática: ' . $update_result->get_error_message() ), 200 );
			}

			CODESYNC_Manager::log(
				$repo_slug,
				'sistema',
				'sucesso',
				/* translators: %s: version number */
				sprintf( __( 'Webhook recebido. Pacote atualizado automaticamente para a versão %s.', 'codesync-manager-for-github' ), $update_result['version'] )
			);

			return new WP_REST_Response( array( 'message' => 'Webhook processado. Pacote atualizado para v' . $update_result['version'] . '.' ), 200 );
		}

		return new WP_REST_Response( array( 'message' => 'Evento ignorado.' ), 200 );
	}

	/**
	 * Generate a random secret if it doesn't exist.
	 *
	 * @return string
	 */
	public static function get_or_create_secret() {
		$secret = get_option( self::OPTION_SECRET );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 32, false );
			update_option( self::OPTION_SECRET, $secret );
		}
		return $secret;
	}
}
