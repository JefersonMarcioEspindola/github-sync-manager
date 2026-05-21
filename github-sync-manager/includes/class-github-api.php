<?php
/**
 * GitHub API Client Wrapper
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class GSM_GitHub_API
 *
 * Interacts with the GitHub API securely, managing authentication, repository fetching,
 * release validation, transient caching, and authenticated asset downloading.
 */
class GSM_GitHub_API {

	/**
	 * GitHub API base URL.
	 */
	const API_URL = 'https://api.github.com';

	/**
	 * Personal Access Token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor.
	 *
	 * @param string $token Decrypted Personal Access Token.
	 */
	public function __construct( $token ) {
		$this->token = trim( $token );
	}

	/**
	 * Helper to generate default HTTP request arguments.
	 *
	 * @param string $method HTTP method.
	 * @param string $accept Custom Accept header.
	 * @return array Request arguments.
	 */
	private function get_request_args( $method = 'GET', $accept = 'application/vnd.github+json' ) {
		return array(
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 5,
			'httpversion' => '1.1',
			'headers'     => array(
				'Authorization'   => 'Bearer ' . $this->token,
				'Accept'          => $accept,
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'      => 'WordPress-GitHub-Sync-Manager/' . ( defined( 'GSM_VERSION' ) ? GSM_VERSION : '1.0.0' ),
			),
		);
	}

	/**
	 * Validates the GitHub Personal Access Token and retrieves user information.
	 *
	 * @return array|WP_Error Array with user info and token type on success, WP_Error on failure.
	 */
	public function validate_token() {
		$url  = self::API_URL . '/user';
		$args = $this->get_request_args();

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'gsm_api_auth_failed',
				__( 'Token inválido ou sem permissões necessárias. Verifique se o token foi criado com o escopo repo e tente novamente.', 'github-sync-manager' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'gsm_api_invalid_json', __( 'Resposta inválida da API do GitHub.', 'github-sync-manager' ) );
		}

		// Read scopes from header (Classic PATs only)
		$scopes_header = wp_remote_retrieve_header( $response, 'x-oauth-scopes' );
		$scopes        = array();
		$token_type    = 'fine-grained';

		if ( ! empty( $scopes_header ) ) {
			$token_type = 'classic';
			$scopes     = array_map( 'trim', explode( ',', $scopes_header ) );

			// Check scopes
			$has_repo        = in_array( 'repo', $scopes, true );
			$has_public_repo = in_array( 'public_repo', $scopes, true );

			if ( ! $has_repo && ! $has_public_repo ) {
				return new WP_Error(
					'gsm_api_insufficient_scopes',
					__( 'Token inválido ou sem permissões necessárias. Verifique se o token foi criado com o escopo repo e tente novamente.', 'github-sync-manager' )
				);
			}
		}

		return array(
			'username'   => isset( $body['login'] ) ? $body['login'] : '',
			'avatar_url' => isset( $body['avatar_url'] ) ? $body['avatar_url'] : '',
			'token_type' => $token_type,
			'scopes'     => $scopes,
		);
	}

	/**
	 * Lists repositories of the connected GitHub account.
	 *
	 * @return array|WP_Error List of repositories or WP_Error on failure.
	 */
	public function get_repositories() {
		$url  = self::API_URL . '/user/repos?per_page=100&sort=updated';
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error( 'gsm_api_repos_failed', __( 'Falha ao buscar a lista de repositórios do GitHub.', 'github-sync-manager' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'gsm_api_invalid_response', __( 'Dados de repositórios inválidos.', 'github-sync-manager' ) );
		}

		$repos = array();
		foreach ( $body as $repo ) {
			$language = isset( $repo['language'] ) ? $repo['language'] : '';
			$name     = isset( $repo['name'] ) ? $repo['name'] : '';
			$desc     = isset( $repo['description'] ) ? $repo['description'] : '';

			$is_php = ( ! empty( $language ) && strcasecmp( $language, 'php' ) === 0 );

			// Detect plugin indicators
			$search_text = strtolower( $name . ' ' . $desc );
			$is_wordpress_related = ( strpos( $search_text, 'wordpress' ) !== false || strpos( $search_text, 'wp-plugin' ) !== false || strpos( $search_text, 'wp-' ) !== false );

			// If language is empty (often happens on GitHub for mixed/new repos),
			// we check if it has plugin or wordpress in the name/description.
			$is_potential_plugin = false;
			if ( empty( $language ) ) {
				if ( $is_wordpress_related || strpos( $search_text, 'plugin' ) !== false ) {
					$is_potential_plugin = true;
				}
			}

			if ( $is_php || $is_wordpress_related || $is_potential_plugin ) {
				$repos[] = array(
					'id'          => $repo['id'],
					'name'        => $repo['name'],
					'full_name'   => $repo['full_name'],
					'description' => $repo['description'],
					'private'     => (bool) $repo['private'],
					'updated_at'  => $repo['updated_at'],
					'html_url'    => $repo['html_url'],
					'owner'       => isset( $repo['owner']['login'] ) ? $repo['owner']['login'] : '',
					'language'    => $language,
				);
			}
		}

		return $repos;
	}

	/**
	 * Tests access to a specific repository.
	 * Critical for Fine-Grained PATs validation.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @return bool|WP_Error True if accessible, WP_Error otherwise.
	 */
	public function test_repo_access( $owner, $repo ) {
		$url  = sprintf( '%s/repos/%s/%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			if ( 404 === $status_code ) {
				return new WP_Error(
					'gsm_repo_not_found',
					__( 'Repositório não encontrado ou sem permissão de acesso. Verifique se o token tem acesso a este repositório específico.', 'github-sync-manager' )
				);
			}
			return new WP_Error(
				'gsm_repo_access_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Erro ao acessar o repositório (%d).', 'github-sync-manager' ), $status_code )
			);
		}

		return true;
	}

	/**
	 * Fetches releases of a specific repository with 1-hour transient caching.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param bool   $force_refresh Bypass the cache.
	 * @return array|WP_Error Array of releases or WP_Error on failure.
	 */
	public function get_releases( $owner, $repo, $force_refresh = false ) {
		$repo_slug = $owner . '/' . $repo;
		$cache_key = 'gsm_rel_' . md5( $repo_slug );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Perform specific access check first
		$access_check = $this->test_repo_access( $owner, $repo );
		if ( is_wp_error( $access_check ) ) {
			return $access_check;
		}

		$url  = sprintf( '%s/repos/%s/%s/releases?per_page=10', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'gsm_releases_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Falha ao buscar as releases do repositório (%d).', 'github-sync-manager' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'gsm_releases_invalid_json', __( 'Resposta inválida das releases.', 'github-sync-manager' ) );
		}

		$releases = array();
		foreach ( $body as $rel ) {
			// Skip drafts
			if ( ! empty( $rel['draft'] ) ) {
				continue;
			}

			$zipball_url = isset( $rel['zipball_url'] ) ? $rel['zipball_url'] : '';
			$assets      = array();

			if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
				foreach ( $rel['assets'] as $asset ) {
					if ( 'application/zip' === $asset['content_type'] || preg_match( '/\.zip$/i', $asset['name'] ) ) {
						$assets[] = array(
							'id'   => $asset['id'],
							'name' => $asset['name'],
							'url'  => $asset['url'], // GitHub API URL for the asset download
						);
					}
				}
			}

			$releases[] = array(
				'id'          => $rel['id'],
				'tag_name'    => $rel['tag_name'],
				'name'        => $rel['name'],
				'body'        => $rel['body'],
				'published_at'=> $rel['published_at'],
				'zipball_url' => $zipball_url,
				'assets'      => $assets,
			);
		}

		// Fallback if no releases found: check default branch
		if ( empty( $releases ) ) {
			$default_branch = $this->get_default_branch( $owner, $repo );
			if ( ! is_wp_error( $default_branch ) ) {
				$branch_version = $this->get_plugin_version_from_branch( $owner, $repo, $default_branch );
				$version = ! is_wp_error( $branch_version ) ? $branch_version : '0.0.0';

				$releases[] = array(
					'id'           => 'branch_' . $default_branch,
					'tag_name'     => $version,
					/* translators: %s: branch name */
				'name'         => sprintf( __( 'Ramo Principal (%s)', 'github-sync-manager' ), $default_branch ),
					'body'         => __( 'Instalado diretamente da branch principal do GitHub (sem releases).', 'github-sync-manager' ),
					'published_at' => current_time( 'mysql' ),
					'zipball_url'  => sprintf( '%s/repos/%s/%s/zipball/%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $default_branch ) ),
					'assets'       => array(),
					'is_branch'    => true,
					'branch_name'  => $default_branch,
				);
			} else {
				return $default_branch;
			}
		}

		// Store in transient cache for 1 hour (3600 seconds)
		set_transient( $cache_key, $releases, HOUR_IN_SECONDS );

		return $releases;
	}

	/**
	 * Fetches the default branch of a specific repository.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo  Repo name.
	 * @return string|WP_Error Default branch name (e.g. 'main', 'master') or WP_Error.
	 */
	public function get_default_branch( $owner, $repo ) {
		$url  = sprintf( '%s/repos/%s/%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'gsm_api_default_branch_failed', sprintf( __( 'Falha ao buscar detalhes da branch padrão (%d).', 'github-sync-manager' ), $status_code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['default_branch'] ) ) {
			return 'main'; // Fallback
		}

		return $body['default_branch'];
	}

	/**
	 * Scans the root or first-level subdirectories of the repository for a main PHP plugin file and returns its version.
	 *
	 * @param string $owner  Repo owner.
	 * @param string $repo   Repo name.
	 * @param string $branch Branch to scan (e.g. 'main').
	 * @return string|WP_Error Version string (e.g. '1.0.0') or WP_Error on failure.
	 */
	public function get_plugin_version_from_branch( $owner, $repo, $branch ) {
		$url  = sprintf( '%s/repos/%s/%s/contents?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $branch ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'buscar_conteudo_raiz',
				'erro',
				/* translators: 1: branch name, 2: error message */
				sprintf( __( 'Erro de comunicação ao listar raiz da branch %1$s: %2$s', 'github-sync-manager' ), $branch, $response->get_error_message() )
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'buscar_conteudo_raiz',
				'erro',
				/* translators: 1: HTTP status code, 2: branch name */
				sprintf( __( 'Falha HTTP %1$d ao listar raiz da branch %2$s.', 'github-sync-manager' ), $status_code, $branch )
			);
			/* translators: %d: HTTP status code */
			return new WP_Error( 'gsm_api_contents_failed', sprintf( __( 'Falha ao listar arquivos do repositório (%d).', 'github-sync-manager' ), $status_code ) );
		}

		$files = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $files ) ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'buscar_conteudo_raiz',
				'erro',
				/* translators: %s: branch name */
				sprintf( __( 'Resposta inválida retornada para a raiz da branch %s.', 'github-sync-manager' ), $branch )
			);
			return new WP_Error( 'gsm_api_invalid_contents', __( 'Estrutura de arquivos inválida no repositório.', 'github-sync-manager' ) );
		}

		// Look for PHP files and subdirectories in the root
		$php_files = array();
		$subdirs   = array();
		foreach ( $files as $file ) {
			if ( 'file' === $file['type'] && preg_match( '/\.php$/i', $file['name'] ) && 'index.php' !== strtolower( $file['name'] ) ) {
				$php_files[] = $file['path'];
			} elseif ( 'dir' === $file['type'] ) {
				$subdirs[] = $file['path'];
			}
		}

		// 1. Scan the contents of each PHP file in the root first
		foreach ( $php_files as $path ) {
			$version = $this->get_plugin_version_from_file_path( $owner, $repo, $path, $branch, $args );
			if ( $version && ! is_wp_error( $version ) ) {
				return $version;
			}
		}

		// 2. If no plugin found at the root, check first-level subdirectories
		if ( ! empty( $subdirs ) ) {
			// Ignored common directories that cannot contain the main plugin files
			$ignored_dirs = array(
				'assets', 'bin', 'tests', 'test', 'docs', 'doc', 'node_modules',
				'.git', '.github', 'vendor', 'includes', 'languages', 'lang',
				'versoes', 'versões', 'versions', 'scripts', 'css', 'js', 'images'
			);

			// Filter out ignored directories first
			$filtered_subdirs = array();
			foreach ( $subdirs as $subdir_path ) {
				$dirname = strtolower( basename( $subdir_path ) );
				if ( ! in_array( $dirname, $ignored_dirs, true ) ) {
					$filtered_subdirs[] = $subdir_path;
				}
			}

			// Sort remaining subdirectories to prioritize one matching the repository name exactly (case-insensitive)
			usort( $filtered_subdirs, function( $a, $b ) use ( $repo ) {
				$a_name = strtolower( basename( $a ) );
				$b_name = strtolower( basename( $b ) );
				$repo_name = strtolower( $repo );

				$score_a = ( $a_name === $repo_name ) ? 2 : 1;
				$score_b = ( $b_name === $repo_name ) ? 2 : 1;

				if ( $score_a !== $score_b ) {
					return $score_b - $score_a;
				}
				return strcmp( $a_name, $b_name );
			} );

			// Limit subdirectory scanning to maximum 5 folders to prevent API rate limiting
			$subdirs_to_scan = array_slice( $filtered_subdirs, 0, 5 );

			foreach ( $subdirs_to_scan as $subdir_path ) {
				$subdir_url = sprintf( '%s/repos/%s/%s/contents/%s?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), $this->encode_api_path( $subdir_path ), rawurlencode( $branch ) );
				$subdir_response = wp_remote_get( $subdir_url, $args );

				if ( is_wp_error( $subdir_response ) ) {
					GSM_Manager::log(
						$owner . '/' . $repo,
						'escanear_subpasta',
						'erro',
						/* translators: 1: subfolder path, 2: error message */
						sprintf( __( 'Erro de comunicação ao listar subpasta %1$s: %2$s', 'github-sync-manager' ), $subdir_path, $subdir_response->get_error_message() )
					);
					continue;
				}

				$subdir_status = wp_remote_retrieve_response_code( $subdir_response );
				if ( 200 !== $subdir_status ) {
					GSM_Manager::log(
						$owner . '/' . $repo,
						'escanear_subpasta',
						'erro',
						/* translators: 1: HTTP status code, 2: subfolder path */
						sprintf( __( 'Falha HTTP %1$d ao listar subpasta %2$s.', 'github-sync-manager' ), $subdir_status, $subdir_path )
					);
					continue;
				}

				$subdir_files = json_decode( wp_remote_retrieve_body( $subdir_response ), true );
				if ( ! is_array( $subdir_files ) ) {
					GSM_Manager::log(
						$owner . '/' . $repo,
						'escanear_subpasta',
						'erro',
						/* translators: %s: subfolder path */
						sprintf( __( 'Dados inválidos retornados para subpasta %s.', 'github-sync-manager' ), $subdir_path )
					);
					continue;
				}

				$subdir_php_files = array();
				foreach ( $subdir_files as $subfile ) {
					if ( 'file' === $subfile['type'] && preg_match( '/\.php$/i', $subfile['name'] ) && 'index.php' !== strtolower( $subfile['name'] ) ) {
						$subdir_php_files[] = $subfile['path'];
					}
				}

				foreach ( $subdir_php_files as $path ) {
					$version = $this->get_plugin_version_from_file_path( $owner, $repo, $path, $branch, $args );
					if ( $version && ! is_wp_error( $version ) ) {
						return $version;
					}
				}
			}
		}

		return new WP_Error( 'gsm_no_php_files', __( 'Nenhum arquivo PHP de plugin válido com cabeçalho "Plugin Name:" foi encontrado na raiz ou subpastas de primeiro nível do repositório.', 'github-sync-manager' ) );
	}

	/**
	 * Fetches a file's content and returns the version if it has a Plugin Name header.
	 *
	 * @param string $owner  Repo owner.
	 * @param string $repo   Repo name.
	 * @param string $path   File path.
	 * @param string $branch Branch.
	 * @param array  $args   API request arguments.
	 * @return string|false Version string or false if not a plugin file.
	 */
	private function get_plugin_version_from_file_path( $owner, $repo, $path, $branch, $args ) {
		$file_url = sprintf( '%s/repos/%s/%s/contents/%s?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), $this->encode_api_path( $path ), rawurlencode( $branch ) );
		$response = wp_remote_get( $file_url, $args );

		if ( is_wp_error( $response ) ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'obter_cabecalho_arquivo',
				'erro',
				/* translators: 1: file path, 2: error message */
				sprintf( __( 'Erro de comunicação ao obter arquivo %1$s: %2$s', 'github-sync-manager' ), $path, $response->get_error_message() )
			);
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'obter_cabecalho_arquivo',
				'erro',
				/* translators: 1: HTTP status code, 2: file path */
				sprintf( __( 'Falha HTTP %1$d ao obter arquivo %2$s.', 'github-sync-manager' ), $status_code, $path )
			);
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['content'] ) || 'base64' !== $body['encoding'] ) {
			GSM_Manager::log(
				$owner . '/' . $repo,
				'obter_cabecalho_arquivo',
				'erro',
				/* translators: %s: file path */
				sprintf( __( 'Conteúdo inválido ou formato inesperado para o arquivo %s.', 'github-sync-manager' ), $path )
			);
			return false;
		}

		$content = base64_decode( $body['content'] );

		// Check if it has 'Plugin Name:' and parse 'Version:'
		if ( false !== stripos( $content, 'Plugin Name:' ) ) {
			if ( preg_match( '~^[ \t/*#]*Version\s*:\s*([^$\r\n]+)~mi', $content, $matches ) ) {
				return trim( $matches[1] );
			}
		}

		return false;
	}

	/**
	 * Encodes a file or directory path segment by segment for use in URLs.
	 *
	 * @param string $path File or directory path.
	 * @return string Encoded path.
	 */
	private function encode_api_path( $path ) {
		$parts = explode( '/', $path );
		return implode( '/', array_map( 'rawurlencode', $parts ) );
	}

	/**
	 * Deletes the release transient cache for a repository.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 */
	public static function delete_releases_cache( $owner, $repo ) {
		$repo_slug = $owner . '/' . $repo;
		$cache_key = 'gsm_rel_' . md5( $repo_slug );
		delete_transient( $cache_key );
	}

	/**
	 * Downloads a package ZIP securely using the authorization header.
	 *
	 * @param string $url The ZIP URL (either API zipball or API asset URL).
	 * @return string|WP_Error Absolute path to the temporary downloaded ZIP, or WP_Error.
	 */
	public function download_package( $url ) {
		if ( empty( $url ) ) {
			return new WP_Error( 'gsm_download_empty_url', __( 'A URL de download está vazia.', 'github-sync-manager' ) );
		}

		// Define a local callback to inject the Authorization header securely
		$auth_callback = function( $args, $req_url ) use ( $url ) {
			// Make sure we only attach authorization to GitHub API or raw github download URLs.
			if ( strpos( $req_url, 'api.github.com' ) !== false || strpos( $req_url, 'codeload.github.com' ) !== false ) {
				$args['headers']['Authorization'] = 'Bearer ' . $this->token;
				$args['headers']['User-Agent']    = 'WordPress-GitHub-Sync-Manager';

				// If it's a release asset API URL, request it as application/octet-stream
				if ( strpos( $req_url, '/releases/assets/' ) !== false ) {
					$args['headers']['Accept'] = 'application/octet-stream';
				}
			}
			return $args;
		};

		// Attach the filter before starting download
		add_filter( 'http_request_args', $auth_callback, 10, 2 );

		// Use WordPress native download_url function. It streams the file to a secure local temp file.
		// We set a long timeout of 300 seconds for larger files.
		$tmp_file = download_url( $url, 300 );

		// Detach the filter immediately
		remove_filter( 'http_request_args', $auth_callback );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		return $tmp_file;
	}

	/**
	 * Fetches the entire directory tree of a specific repository.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo  Repo name.
	 * @param string $ref   Tag name, branch name, or commit SHA.
	 * @return array|WP_Error List of directory paths or WP_Error on failure.
	 */
	public function get_repo_directory_tree( $owner, $repo, $ref ) {
		$url  = sprintf( '%s/repos/%s/%s/git/trees/%s?recursive=1', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $ref ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'gsm_tree_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Falha ao buscar a estrutura de pastas do repositório (%d).', 'github-sync-manager' ), $status_code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tree'] ) ) {
			return array();
		}

		$folders = array();
		$ignored_folders = array( '.git', '.github', 'node_modules' );
		foreach ( $body['tree'] as $item ) {
			if ( isset( $item['type'] ) && 'tree' === $item['type'] ) {
				$path = $item['path'];
				$should_ignore = false;
				foreach ( $ignored_folders as $ignored ) {
					if ( strpos( $path, $ignored ) === 0 || strpos( $path, '/' . $ignored ) !== false ) {
						$should_ignore = true;
						break;
					}
				}
				if ( ! $should_ignore ) {
					$folders[] = $path;
				}
			}
		}

		natcasesort( $folders );
		return array_values( $folders );
	}

	/**
	 * Scans the root or first-level subdirectories of the repository for a main PHP plugin file
	 * and returns detailed metadata (Plugin Name, Version, Path, Subfolder).
	 *
	 * @param string $owner  Repo owner.
	 * @param string $repo   Repo name.
	 * @param string $branch Branch or tag to scan.
	 * @return array|WP_Error Array with metadata, or WP_Error on failure.
	 */
	public function detect_plugin_metadata( $owner, $repo, $branch ) {
		$url  = sprintf( '%s/repos/%s/%s/contents?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $branch ) );
		$args = $this->get_request_args();

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'gsm_api_contents_failed', sprintf( __( 'Falha ao listar arquivos do repositório (%d).', 'github-sync-manager' ), $status_code ) );
		}

		$files = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $files ) ) {
			return new WP_Error( 'gsm_api_invalid_contents', __( 'Estrutura de arquivos inválida no repositório.', 'github-sync-manager' ) );
		}

		$php_files = array();
		$subdirs   = array();
		foreach ( $files as $file ) {
			if ( 'file' === $file['type'] && preg_match( '/\.php$/i', $file['name'] ) && 'index.php' !== strtolower( $file['name'] ) ) {
				$php_files[] = $file['path'];
			} elseif ( 'dir' === $file['type'] ) {
				$subdirs[] = $file['path'];
			}
		}

		// 1. Scan root PHP files first
		foreach ( $php_files as $path ) {
			$meta = $this->get_plugin_metadata_from_file( $owner, $repo, $path, $branch, $args );
			if ( $meta ) {
				return $meta;
			}
		}

		// 2. Scan first-level subdirectories
		if ( ! empty( $subdirs ) ) {
			$ignored_dirs = array(
				'assets', 'bin', 'tests', 'test', 'docs', 'doc', 'node_modules',
				'.git', '.github', 'vendor', 'includes', 'languages', 'lang',
				'versoes', 'versões', 'versions', 'scripts', 'css', 'js', 'images'
			);

			$filtered_subdirs = array();
			foreach ( $subdirs as $subdir_path ) {
				$dirname = strtolower( basename( $subdir_path ) );
				if ( ! in_array( $dirname, $ignored_dirs, true ) ) {
					$filtered_subdirs[] = $subdir_path;
				}
			}

			// Sort subdirectories to prioritize repo name match
			usort( $filtered_subdirs, function( $a, $b ) use ( $repo ) {
				$a_name = strtolower( basename( $a ) );
				$b_name = strtolower( basename( $b ) );
				$repo_name = strtolower( $repo );

				$score_a = ( $a_name === $repo_name ) ? 2 : 1;
				$score_b = ( $b_name === $repo_name ) ? 2 : 1;

				if ( $score_a !== $score_b ) {
					return $score_b - $score_a;
				}
				return strcmp( $a_name, $b_name );
			} );

			$subdirs_to_scan = array_slice( $filtered_subdirs, 0, 5 );

			foreach ( $subdirs_to_scan as $subdir_path ) {
				$subdir_url = sprintf( '%s/repos/%s/%s/contents/%s?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), $this->encode_api_path( $subdir_path ), rawurlencode( $branch ) );
				$subdir_response = wp_remote_get( $subdir_url, $args );

				if ( is_wp_error( $subdir_response ) || 200 !== wp_remote_retrieve_response_code( $subdir_response ) ) {
					continue;
				}

				$subdir_files = json_decode( wp_remote_retrieve_body( $subdir_response ), true );
				if ( ! is_array( $subdir_files ) ) {
					continue;
				}

				$subdir_php_files = array();
				foreach ( $subdir_files as $subfile ) {
					if ( 'file' === $subfile['type'] && preg_match( '/\.php$/i', $subfile['name'] ) && 'index.php' !== strtolower( $subfile['name'] ) ) {
						$subdir_php_files[] = $subfile['path'];
					}
				}

				foreach ( $subdir_php_files as $path ) {
					$meta = $this->get_plugin_metadata_from_file( $owner, $repo, $path, $branch, $args );
					if ( $meta ) {
						return $meta;
					}
				}
			}
		}

		return new WP_Error( 'gsm_no_plugin_found', __( 'Nenhum plugin detectado automaticamente.', 'github-sync-manager' ) );
	}

	/**
	 * Helper to fetch file content and parse Plugin Name and Version.
	 */
	private function get_plugin_metadata_from_file( $owner, $repo, $path, $branch, $args ) {
		$file_url = sprintf( '%s/repos/%s/%s/contents/%s?ref=%s', self::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), $this->encode_api_path( $path ), rawurlencode( $branch ) );
		$response = wp_remote_get( $file_url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['content'] ) || 'base64' !== $body['encoding'] ) {
			return false;
		}

		$content = base64_decode( $body['content'] );

		if ( false !== stripos( $content, 'Plugin Name:' ) ) {
			$name = '';
			$version = '';
			if ( preg_match( '~^[ \t/*#]*Plugin Name\s*:\s*([^$\r\n]+)~mi', $content, $name_matches ) ) {
				$name = trim( $name_matches[1] );
			}
			if ( preg_match( '~^[ \t/*#]*Version\s*:\s*([^$\r\n]+)~mi', $content, $version_matches ) ) {
				$version = trim( $version_matches[1] );
			}

			if ( ! empty( $name ) ) {
				$subfolder = dirname( $path );
				if ( '.' === $subfolder ) {
					$subfolder = '';
				}
				return array(
					'name'      => $name,
					'version'   => $version ? $version : '0.0.0',
					'file_path' => $path,
					'subfolder' => $subfolder,
				);
			}
		}

		return false;
	}
}
