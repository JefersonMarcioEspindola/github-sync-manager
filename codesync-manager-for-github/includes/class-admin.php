<?php
/**
 * Core Admin Area and AJAX Handlers
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CODESYNC_Admin
 *
 * Handles administrative interface rendering, styling enqueues, and secure AJAX endpoints.
 */
class CODESYNC_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		// Add menu page
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );

		// Enqueue scripts and styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_codesync_connect_account', array( __CLASS__, 'ajax_connect_account' ) );
		add_action( 'wp_ajax_codesync_disconnect_account', array( __CLASS__, 'ajax_disconnect_account' ) );
		add_action( 'wp_ajax_codesync_add_plugin', array( __CLASS__, 'ajax_add_plugin' ) );
		add_action( 'wp_ajax_codesync_verify_repo', array( __CLASS__, 'ajax_verify_repo' ) );
		add_action( 'wp_ajax_codesync_remove_plugin', array( __CLASS__, 'ajax_remove_plugin' ) );
		add_action( 'wp_ajax_codesync_check_updates', array( __CLASS__, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_codesync_save_locale', array( __CLASS__, 'ajax_save_locale' ) );
		add_action( 'wp_ajax_codesync_force_update', array( __CLASS__, 'ajax_force_update' ) );
	}

	/**
	 * Register the WordPress admin menu item.
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'Sync Manager', 'codesync-manager-for-github' ),
			__( 'Sync Manager', 'codesync-manager-for-github' ),
			'manage_options',
			'codesync-manager-for-github',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-update-alt',
			100
		);
	}

	/**
	 * Enqueue stylesheet and script files.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_codesync-manager-for-github' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'codesync-admin-style',
			plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ),
			array(),
			defined( 'CODESYNC_VERSION' ) ? CODESYNC_VERSION : '1.0.0'
		);

		wp_enqueue_script(
			'lucide',
			plugins_url( 'assets/js/lucide.min.js', dirname( __FILE__ ) ),
			array(),
			'1.16.0',
			true
		);

		wp_enqueue_script(
			'codesync-admin-script',
			plugins_url( 'assets/js/admin.js', dirname( __FILE__ ) ),
			array( 'jquery', 'lucide' ),
			defined( 'CODESYNC_VERSION' ) ? CODESYNC_VERSION : '1.0.0',
			true
		);

		// Localize parameters for use in JS file
		wp_localize_script( 'codesync-admin-script', 'codesync_ajax', array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'codesync_admin_nonce' ),
			'texts'  => array(
				'confirm_stop'          => __( 'O plugin continuará instalado, mas deixará de receber atualizações automáticas. Deseja continuar?', 'codesync-manager-for-github' ),
				'installing'            => __( 'Baixando e instalando...', 'codesync-manager-for-github' ),
				'searching'             => __( 'Pesquisando repositórios...', 'codesync-manager-for-github' ),
				'comm_fail'             => __( 'Falha na comunicação.', 'codesync-manager-for-github' ),
				/* translators: %s: repository name */
				'confirm_install'       => __( 'Deseja baixar e instalar o plugin do repositório %s?', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'install_error'         => __( 'Erro na Instalação: %s', 'codesync-manager-for-github' ),
				'install_fail'          => __( 'Falha na comunicação de rede ao tentar instalar o plugin.', 'codesync-manager-for-github' ),
				'remove_error'          => __( 'Erro ao excluir gerenciamento.', 'codesync-manager-for-github' ),
				'scan_fail'             => __( 'Falha na comunicação de rede durante o escaneamento.', 'codesync-manager-for-github' ),
				'prompt_copied'         => __( 'Prompt de IA copiado para a área de transferência com sucesso!', 'codesync-manager-for-github' ),
				'prompt_copy_fail'      => __( 'Não foi possível copiar o prompt automaticamente. Por favor, copie manualmente.', 'codesync-manager-for-github' ),
				'save_locale_error'     => __( 'Erro ao salvar o idioma.', 'codesync-manager-for-github' ),
				'loading_repos'         => __( 'Carregando seus repositórios do GitHub...', 'codesync-manager-for-github' ),
				'repos_load_error'      => __( 'Erro de conexão ao buscar repositórios.', 'codesync-manager-for-github' ),
				'no_repos_found'        => __( 'Nenhum repositório encontrado na sua conta do GitHub.', 'codesync-manager-for-github' ),
				'already_managed'       => __( 'Já Gerenciado', 'codesync-manager-for-github' ),
				'install_btn'           => __( 'Instalar Plugin', 'codesync-manager-for-github' ),
				'no_desc'               => __( 'Sem descrição no repositório.', 'codesync-manager-for-github' ),
				/* translators: %s: date and time */
				'updated_lbl'           => __( 'Atualizado: %s', 'codesync-manager-for-github' ),
				'private_lbl'           => __( 'Privado', 'codesync-manager-for-github' ),
				'public_lbl'            => __( 'Público', 'codesync-manager-for-github' ),
				'no_managed'            => __( 'Nenhum plugin gerenciado ainda. Acesse a aba "Adicionar Plugin" para começar.', 'codesync-manager-for-github' ),
				'confirm_disconnect'    => __( 'Tem certeza de que deseja desconectar sua conta GitHub? Os plugins continuarão instalados, mas não receberão notificações de atualização.', 'codesync-manager-for-github' ),
				/* translators: 1: repository name, 2: version number */
				'confirm_prompt'        => __( 'Aja como um desenvolvedor experiente em WordPress e Git. Meu repositório do plugin \'%1$s\' não possui releases publicadas no GitHub. Crie um guia passo a passo conciso em Markdown para eu publicar a release \'v%2$s\' desse plugin, explicando como gerar o arquivo ZIP correto (apenas a pasta do plugin, sem os arquivos de versionamento do Git) e como criar a Release no GitHub usando a interface web ou GitHub CLI. Inclua boas práticas de versionamento SemVer.', 'codesync-manager-for-github' ),
				'req_failed'            => __( 'Falha na requisição. Verifique sua conexão de rede.', 'codesync-manager-for-github' ),
				/* translators: %s: repository name */
				'force_update_confirm'  => __( 'Isso irá baixar e reinstalar a última versão do repositório %s, sobrescrevendo a versão atual. Continuar?', 'codesync-manager-for-github' ),
				/* translators: %s: version number */
				'force_update_ok'       => __( 'Plugin reinstalado com sucesso! (Versão %s)', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'force_update_err'      => __( 'Erro ao reinstalar: %s', 'codesync-manager-for-github' ),
				'force_update_fail'     => __( 'Falha na comunicação ao tentar reinstalar.', 'codesync-manager-for-github' ),
				'force_update_btn'      => __( 'Atualizar', 'codesync-manager-for-github' ),
				'force_updating'        => __( 'Reinstalando...', 'codesync-manager-for-github' ),
				'sync_success_title'    => __( '&#x2705; Plugin Sincronizado com Sucesso!', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'sync_success_msg'      => __( 'O plugin <strong>%1$s</strong> já estava instalado no seu WordPress. O código foi atualizado e agora está sincronizado e sendo gerenciado (Versão %2$s).', 'codesync-manager-for-github' ),
				'install_success_title' => __( '&#x2705; Plugin Instalado com Sucesso!', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'install_success_msg'   => __( 'O plugin <strong>%1$s</strong> (Versão %2$s) foi baixado e gravado localmente.', 'codesync-manager-for-github' ),
				'activate_btn'          => __( 'Ativar Plugin Agora', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'scan_error'            => __( 'Erro ao verificar: %s', 'codesync-manager-for-github' ),
				'checking_repo'         => __( 'Verificando estrutura do repositório...', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'plugin_detected'       => __( 'Plugin <strong>%1$s</strong> (Versão %2$s) detectado automaticamente.', 'codesync-manager-for-github' ),
				'plugin_not_detected'   => __( 'Nenhum plugin WordPress válido foi encontrado automaticamente. Selecione a pasta base e a origem abaixo para instalar.', 'codesync-manager-for-github' ),
				'advanced_options'      => __( 'Opções Avançadas', 'codesync-manager-for-github' ),
				'select_source'         => __( 'Origem (Release ou Ramo):', 'codesync-manager-for-github' ),
				'select_folder'         => __( 'Pasta Base do Plugin:', 'codesync-manager-for-github' ),
				'select_folder_desc'    => __( 'Indique a subpasta do repositório onde os arquivos do plugin de fato residem (a pasta que contém o arquivo PHP principal). O gerenciador extrairá apenas essa pasta, descartando arquivos externos. Isso permite sincronizar diretamente o código-fonte, eliminando a necessidade de gerar arquivos ZIP ou criar releases manuais no GitHub para atualizar o plugin!', 'codesync-manager-for-github' ),
				'root_folder'           => __( 'Pasta Raiz', 'codesync-manager-for-github' ),
				'close_btn'             => __( 'Fechar', 'codesync-manager-for-github' ),
			),
		) );
	}

	/**
	 * Render the HTML dashboard admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'codesync-manager-for-github' ) );
		}

		$connected_user  = get_option( CODESYNC_Manager::OPTION_USER );
		$token_exists    = ! empty( get_option( CODESYNC_Manager::OPTION_TOKEN ) );
		$security_status = CODESYNC_Encryption::check_security_keys();
		$security_error  = is_wp_error( $security_status ) ? $security_status->get_error_message() : '';

		?>
		<div class="wrap codesync-wrap">
			<div class="codesync-header-panel">
				<h1 class="codesync-title">
					<img src="<?php echo esc_url( plugins_url( 'assets/icon.png', dirname( __FILE__ ) ) ); ?>" alt="<?php esc_attr_e( 'CodeSync Manager for GitHub', 'codesync-manager-for-github' ); ?>" class="codesync-header-logo" />
					<?php esc_html_e( 'CodeSync Manager for GitHub', 'codesync-manager-for-github' ); ?>
				</h1>
				
				<?php if ( $token_exists && is_array( $connected_user ) ) : ?>
					<div class="codesync-user-badge">
						<img src="<?php echo esc_url( $connected_user['avatar_url'] ); ?>" alt="Avatar" class="codesync-user-avatar" />
						<div class="codesync-user-info">
							<span class="codesync-username">@<?php echo esc_html( $connected_user['username'] ); ?></span>
							<span class="codesync-pulse-badge">
								<span class="codesync-pulse"></span>
								<?php esc_html_e( 'Conectado', 'codesync-manager-for-github' ); ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $security_error ) ) : ?>
				<div class="notice notice-error codesync-notice-blocking">
					<p><strong><?php esc_html_e( 'Erro de Segurança:', 'codesync-manager-for-github' ); ?></strong> <?php echo esc_html( $security_error ); ?></p>
				</div>
			<?php else : ?>

				<?php if ( ! $token_exists ) : ?>
					<!-- Activation screen -->
					<div class="codesync-card codesync-login-card">
						<h2><?php esc_html_e( 'Conectar Conta GitHub', 'codesync-manager-for-github' ); ?></h2>
						<p><?php esc_html_e( 'Para começar a gerenciar seus plugins hospedados no GitHub, conecte uma conta utilizando um Personal Access Token (PAT) com as devidas permissões.', 'codesync-manager-for-github' ); ?></p>
						
						<div class="codesync-help-box">
							<p><strong><?php esc_html_e( 'Qual tipo de token criar?', 'codesync-manager-for-github' ); ?></strong></p>
							<ul>
								<li><strong><?php esc_html_e( 'Classic PAT:', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Crie um token com o escopo ', 'codesync-manager-for-github' ); ?><code>repo</code> (<?php esc_html_e( 'para repositórios privados e públicos', 'codesync-manager-for-github' ); ?>) <?php esc_html_e( 'ou ', 'codesync-manager-for-github' ); ?><code>public_repo</code> (<?php esc_html_e( 'somente para públicos', 'codesync-manager-for-github' ); ?>).</li>
								<li><strong><?php esc_html_e( 'Fine-Grained PAT (Novo):', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Selecione permissão de leitura e gravação para "Contents" e "Metadata" nos repositórios que deseja gerenciar.', 'codesync-manager-for-github' ); ?></li>
							</ul>
							<p>👉 <a href="https://github.com/settings/tokens" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Clique aqui para criar seu Token no GitHub', 'codesync-manager-for-github' ); ?></a></p>
						</div>

						<form id="codesync-connect-form">
							<div class="codesync-form-group">
								<label for="codesync_pat_token"><strong><?php esc_html_e( 'GitHub Personal Access Token (PAT)', 'codesync-manager-for-github' ); ?></strong></label>
								<input type="password" id="codesync_pat_token" name="codesync_pat_token" class="regular-text" required placeholder="github_pat_..." autocomplete="off" />
							</div>
							<div class="codesync-submit-btn-row">
								<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Conectar Conta', 'codesync-manager-for-github' ); ?></button>
								<span class="spinner codesync-spinner"></span>
							</div>
							<div class="codesync-error-message" style="display:none;"></div>
						</form>
					</div>
				<?php else : ?>
					<!-- Admin core view -->
					<h2 class="nav-tab-wrapper codesync-tabs-nav">
						<a href="#codesync-tab-plugins" class="nav-tab nav-tab-active"><?php esc_html_e( 'Plugins Gerenciados', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-add" class="nav-tab" id="codesync-trigger-add-tab"><?php esc_html_e( 'Adicionar Plugin', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-logs" class="nav-tab"><?php esc_html_e( 'Histórico de Logs', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-config" class="nav-tab"><?php esc_html_e( 'Configurações', 'codesync-manager-for-github' ); ?></a>
					</h2>

					<!-- Tab content: Plugins -->
					<div id="codesync-tab-plugins" class="codesync-tab-content codesync-tab-active">
						<div class="codesync-action-bar">
							<button type="button" class="button button-primary" id="codesync-btn-scan-now">
								<i data-lucide="search" class="codesync-icon"></i>
								<?php esc_html_e( 'Verificar atualizações agora', 'codesync-manager-for-github' ); ?>
							</button>
							<span class="spinner codesync-spinner" id="codesync-scan-spinner"></span>
						</div>

						<div class="codesync-plugins-cards" id="codesync-plugins-cards">
							<?php self::render_plugins_cards(); ?>
						</div>
					</div>

					<!-- Tab content: Add Plugin -->
					<div id="codesync-tab-add" class="codesync-tab-content">
						<div class="codesync-info-notice" style="margin-bottom: 20px; margin-top: 0;">
							<i data-lucide="info" class="codesync-icon"></i>
							<p><?php esc_html_e( 'Exibindo apenas repositórios com código PHP principal ou com nome/descrição relacionados a plugins WordPress.', 'codesync-manager-for-github' ); ?></p>
						</div>

						<div class="codesync-filter-bar">
							<input type="text" id="codesync-repo-search" placeholder="<?php esc_attr_e( 'Buscar repositório por nome...', 'codesync-manager-for-github' ); ?>" autocomplete="off" />
							<button type="button" class="button" id="codesync-btn-reload-repos">
								<i data-lucide="refresh-cw" class="codesync-icon"></i>
								<?php esc_html_e( 'Recarregar Repositórios', 'codesync-manager-for-github' ); ?>
							</button>
							<span class="spinner codesync-spinner" id="codesync-repos-spinner"></span>
						</div>

						<div class="codesync-repos-grid" id="codesync-repos-container">
							<!-- Populated via JS/AJAX -->
						</div>
					</div>

					<!-- Tab content: Logs -->
					<div id="codesync-tab-logs" class="codesync-tab-content">
						<div class="codesync-card codesync-table-card">
							<div id="codesync-logs-table-wrapper">
								<?php self::render_logs_table(); ?>
							</div>
						</div>
					</div>

					<!-- Tab content: Settings/Config -->
					<div id="codesync-tab-config" class="codesync-tab-content">
						<div class="codesync-card codesync-settings-card">
							<h2><?php esc_html_e( 'Configurações do Sync Manager', 'codesync-manager-for-github' ); ?></h2>
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Conta Conectada', 'codesync-manager-for-github' ); ?></th>
										<td>
											<div class="codesync-profile-detail">
												<img src="<?php echo esc_url( $connected_user['avatar_url'] ); ?>" class="codesync-profile-avatar" alt="Avatar" />
												<div>
													<strong>@<?php echo esc_html( $connected_user['username'] ); ?></strong>
													<?php /* translators: %s: token type */ ?>
					<p class="description"><?php printf( esc_html__( 'Tipo de Token: %s', 'codesync-manager-for-github' ), esc_html( ucfirst( $connected_user['token_type'] ) ) ); ?></p>
												</div>
											</div>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Token Armazenado', 'codesync-manager-for-github' ); ?></th>
										<td>
											<code><?php echo esc_html( CODESYNC_Encryption::mask_token( CODESYNC_Encryption::decrypt( get_option( CODESYNC_Manager::OPTION_TOKEN ) ) ) ); ?></code>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Idioma do Plugin', 'codesync-manager-for-github' ); ?></th>
										<td>
											<?php $selected_locale = get_option( 'codesync_locale', 'pt_BR' ); ?>
											<select id="codesync_locale" name="codesync_locale" style="min-width: 200px;">
												<option value="pt_BR" <?php selected( $selected_locale, 'pt_BR' ); ?>><?php esc_html_e( 'Português (Brasil)', 'codesync-manager-for-github' ); ?></option>
												<option value="en_US" <?php selected( $selected_locale, 'en_US' ); ?>><?php esc_html_e( 'English (US)', 'codesync-manager-for-github' ); ?></option>
												<option value="es_ES" <?php selected( $selected_locale, 'es_ES' ); ?>><?php esc_html_e( 'Español', 'codesync-manager-for-github' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Selecione o idioma da interface do CodeSync Manager for GitHub.', 'codesync-manager-for-github' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Agendamento Automático', 'codesync-manager-for-github' ); ?></th>
										<td>
											<p><?php esc_html_e( 'O sistema verifica se há atualizações disponíveis para os plugins de forma automática duas vezes ao dia.', 'codesync-manager-for-github' ); ?></p>
											
											<div class="codesync-info-notice">
												<i data-lucide="info" class="codesync-icon"></i>
												<p><em>“<?php esc_html_e( 'As verificações automáticas dependem de tráfego no site. Para sites de produção, recomenda-se desabilitar o WP-Cron no wp-config.php e agendar uma tarefa cron real no servidor chamando wp-cron.php.', 'codesync-manager-for-github' ); ?>”</em></p>
											</div>
										</td>
									</tr>
								</tbody>
							</table>

							<div class="codesync-settings-actions">
								<button type="button" class="button button-link-delete" id="codesync-btn-disconnect">
									<?php esc_html_e( 'Desconectar conta GitHub', 'codesync-manager-for-github' ); ?>
								</button>
								<span class="spinner codesync-spinner" id="codesync-disconnect-spinner"></span>
							</div>
						</div>
					</div>

				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Modal de Instalação e Configuração -->
		<div id="codesync-install-modal" class="codesync-modal-wrapper" style="display: none;">
			<div class="codesync-modal-backdrop"></div>
			<div class="codesync-modal-container">
				<div class="codesync-modal-header">
					<h3 class="codesync-modal-title"><?php esc_html_e( 'Instalar Plugin', 'codesync-manager-for-github' ); ?></h3>
					<button type="button" class="codesync-modal-close" aria-label="<?php esc_attr_e( 'Fechar', 'codesync-manager-for-github' ); ?>">&times;</button>
				</div>
				<div class="codesync-modal-body">
					<!-- Conteúdo dinâmico via JS -->
				</div>
				<div class="codesync-modal-footer">
					<button type="button" class="button codesync-modal-btn-cancel"><?php esc_html_e( 'Cancelar', 'codesync-manager-for-github' ); ?></button>
					<button type="button" class="button button-primary codesync-modal-btn-install" disabled><?php esc_html_e( 'Instalar', 'codesync-manager-for-github' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper to render plugins table body rows.
	 */
	public static function render_plugins_cards() {
		$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed ) || ! is_array( $managed ) ) {
			?>
			<p class="codesync-no-plugins-msg"><?php esc_html_e( 'Nenhum plugin gerenciado ainda. Acesse a aba "Adicionar Plugin" para começar.', 'codesync-manager-for-github' ); ?></p>
			<?php
			return;
		}

		$managed_changed = false;
		foreach ( $managed as $repo => $data ) {
			$plugin_file = isset( $data['plugin_file'] ) ? $data['plugin_file'] : '';
			$plugin_name = dirname( $plugin_file );
			if ( '.' === $plugin_name || empty( $plugin_name ) ) {
				$repo_parts  = explode( '/', $repo );
				$plugin_name = end( $repo_parts );
			}

			$installed_version = '0.0.0';

			// If subfolder is set, scan that folder directly to get accurate name/version
			// and self-heal a wrong stored plugin_file reference.
			if ( ! empty( $data['subfolder'] ) ) {
				$subfolder_name = basename( trim( $data['subfolder'], '/' ) );
				$subfolder_path = WP_PLUGIN_DIR . '/' . $subfolder_name;
				if ( is_dir( $subfolder_path ) ) {
					$php_files = glob( $subfolder_path . '/*.php' );
					if ( $php_files ) {
						foreach ( $php_files as $php_file ) {
							$fd = get_file_data( $php_file, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
							if ( ! empty( $fd['Name'] ) ) {
								$plugin_name       = $fd['Name'];
								$installed_version = ! empty( $fd['Version'] ) ? $fd['Version'] : '0.0.0';
								$corrected_file    = $subfolder_name . '/' . basename( $php_file );
								if ( $corrected_file !== $plugin_file ) {
									$plugin_file                        = $corrected_file;
									$managed[ $repo ]['plugin_file']    = $corrected_file;
									$managed_changed                    = true;
								}
								break;
							}
						}
					}
				}
			}

			// Fallback: use stored plugin_file if subfolder scan did not resolve it
			if ( '0.0.0' === $installed_version && ! empty( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$file_data         = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array(
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				) );
				$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';
				$plugin_name       = ! empty( $file_data['Name'] ) ? $file_data['Name'] : $plugin_name;
			}

			$status         = isset( $data['status'] ) ? $data['status'] : 'atualizado';
			$latest_version = isset( $data['latest_version'] ) ? $data['latest_version'] : $installed_version;
			$last_checked   = isset( $data['last_checked'] ) ? $data['last_checked'] : '';
			$error_message  = isset( $data['error_message'] ) ? $data['error_message'] : '';

			$status_label = '';
			$status_class = '';

			switch ( $status ) {
				case 'atualizado':
					$status_label = __( 'Atualizado', 'codesync-manager-for-github' );
					$status_class = 'codesync-status-updated';
					break;
				case 'atualizacao_disponivel':
					$status_label = __( 'Atualização disponível', 'codesync-manager-for-github' );
					$status_class = 'codesync-status-update';
					break;
				case 'indisponivel':
					$status_label = __( 'Indisponível', 'codesync-manager-for-github' );
					$status_class = 'codesync-status-unavailable';
					break;
				case 'erro':
				default:
					$status_label = __( 'Erro', 'codesync-manager-for-github' );
					$status_class = 'codesync-status-error';
					break;
			}

			?>
			<div class="codesync-plugin-card" data-repo="<?php echo esc_attr( $repo ); ?>">
				<div class="codesync-plugin-card-header">
					<div>
						<h3 class="codesync-plugin-card-title"><?php echo esc_html( $plugin_name ); ?></h3>
						<a class="codesync-plugin-card-repo" href="<?php echo esc_url( 'https://github.com/' . $repo ); ?>" target="_blank" rel="noopener noreferrer">
							<i data-lucide="external-link" class="codesync-icon"></i><?php echo esc_html( $repo ); ?>
						</a>
					</div>
					<span class="codesync-status-badge <?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( $status_label ); ?>
						<?php if ( 'erro' === $status && ! empty( $error_message ) ) : ?>
							<i data-lucide="help-circle" class="codesync-icon codesync-tooltip-trigger" title="<?php echo esc_attr( $error_message ); ?>"></i>
						<?php endif; ?>
					</span>
				</div>

				<div class="codesync-plugin-versions">
					<span><?php esc_html_e( 'Instalado:', 'codesync-manager-for-github' ); ?> <code><?php echo esc_html( $installed_version ); ?></code></span>
					<?php if ( $latest_version !== $installed_version ) : ?>
						<span class="codesync-versions-arrow">→</span>
						<span><?php esc_html_e( 'Disponível:', 'codesync-manager-for-github' ); ?> <code><?php echo esc_html( $latest_version ); ?></code></span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $data['is_branch'] ) || ! empty( $data['subfolder'] ) ) : ?>
				<div class="codesync-plugin-card-tags">
					<?php if ( ! empty( $data['is_branch'] ) ) : ?>
						<span class="codesync-branch-label" title="<?php esc_attr_e( 'Instalado diretamente de uma branch, sem releases no GitHub.', 'codesync-manager-for-github' ); ?>">
							<?php
							/* translators: %s: branch name */
							printf( esc_html__( 'Ramo: %s', 'codesync-manager-for-github' ), esc_html( $data['branch_name'] ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $data['subfolder'] ) ) : ?>
						<span class="codesync-subfolder-label" title="<?php esc_attr_e( 'Pasta base configurada para este plugin.', 'codesync-manager-for-github' ); ?>">
							<?php
							/* translators: %s: subfolder path */
							printf( esc_html__( 'Pasta: %s', 'codesync-manager-for-github' ), esc_html( $data['subfolder'] ) );
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $last_checked ) ) : ?>
				<p class="codesync-plugin-last-checked">
					<i data-lucide="clock" class="codesync-icon"></i>
					<?php
					/* translators: %s: date and time */
					printf( esc_html__( 'Última verificação: %s', 'codesync-manager-for-github' ), esc_html( date_i18n( 'd/m/Y H:i', strtotime( $last_checked ) ) ) );
					?>
				</p>
				<?php endif; ?>

				<div class="codesync-plugin-card-actions">
					<button type="button" class="button button-primary codesync-btn-force-update" data-repo="<?php echo esc_attr( $repo ); ?>">
						<i data-lucide="cloud-upload" class="codesync-icon"></i>
						<?php esc_html_e( 'Atualizar', 'codesync-manager-for-github' ); ?>
					</button>
					<button type="button" class="button button-link-delete codesync-btn-remove" data-repo="<?php echo esc_attr( $repo ); ?>">
						<?php esc_html_e( 'Parar de gerenciar', 'codesync-manager-for-github' ); ?>
					</button>
				</div>
			</div>
			<?php
		}

		// Persist any self-healed plugin_file corrections
		if ( $managed_changed ) {
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );
		}
	}

	/**
	 * Helper to render the activity logs table.
	 */
	public static function render_logs_table() {
		$logs = get_option( CODESYNC_Manager::OPTION_LOGS, array() );
		if ( empty( $logs ) || ! is_array( $logs ) ) {
			?>
			<p class="codesync-no-logs-msg"><?php esc_html_e( 'Nenhuma atividade registrada ainda.', 'codesync-manager-for-github' ); ?></p>
			<?php
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped table-view-list codesync-logs-table">
			<thead>
				<tr>
					<th style="width: 160px;"><?php esc_html_e( 'Data/Hora', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 200px;"><?php esc_html_e( 'Repositório', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 140px;"><?php esc_html_e( 'Ação', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 110px;"><?php esc_html_e( 'Resultado', 'codesync-manager-for-github' ); ?></th>
					<th><?php esc_html_e( 'Mensagem', 'codesync-manager-for-github' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) :
					$res_class = ( 'sucesso' === $log['result'] ) ? 'codesync-log-success' : 'codesync-log-error';
					
					// Translate result label
					$result_label = $log['result'];
					if ( 'sucesso' === $log['result'] ) {
						$result_label = __( 'Sucesso', 'codesync-manager-for-github' );
					} elseif ( 'erro' === $log['result'] ) {
						$result_label = __( 'Erro', 'codesync-manager-for-github' );
					}

					// Translate action label
					$action_label = strtoupper( $log['action'] );
					switch ( $log['action'] ) {
						case 'ativacao':
							$action_label = __( 'Ativação', 'codesync-manager-for-github' );
							break;
						case 'conexao':
							$action_label = __( 'Conexão', 'codesync-manager-for-github' );
							break;
						case 'desconexao':
							$action_label = __( 'Desconexão', 'codesync-manager-for-github' );
							break;
						case 'instalacao':
							$action_label = __( 'Instalação', 'codesync-manager-for-github' );
							break;
						case 'atualizacao':
							$action_label = __( 'Atualização', 'codesync-manager-for-github' );
							break;
						case 'cron_check':
							$action_label = __( 'Cron Check', 'codesync-manager-for-github' );
							break;
					}
					?>
					<tr>
						<td><code><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $log['timestamp'] ) ) ); ?></code></td>
						<td><strong><?php echo esc_html( $log['repo'] ); ?></strong></td>
						<td><span class="codesync-log-action-tag"><?php echo esc_html( mb_strtoupper( $action_label, 'UTF-8' ) ); ?></span></td>
						<td><span class="codesync-status-badge <?php echo esc_attr( $res_class ); ?>"><?php echo esc_html( $result_label ); ?></span></td>
						<td class="codesync-log-msg-cell"><?php echo esc_html( $log['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * AJAX endpoint: Connect GitHub PAT.
	 */
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
			$repos = $api->get_repositories();

			if ( is_wp_error( $repos ) ) {
				wp_send_json_error( array( 'message' => $repos->get_error_message() ) );
			}

			$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
			if ( ! is_array( $managed_plugins ) ) {
				$managed_plugins = array();
			}

			// Add is_managed flag
			foreach ( $repos as &$r ) {
				$r['is_managed'] = isset( $managed_plugins[ $r['full_name'] ] );
			}

			wp_send_json_success( array( 'repos' => $repos ) );
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

			$package_url = '';
			if ( ! empty( $target_release['assets'] ) ) {
				$package_url = $target_release['assets'][0]['url'];
			} elseif ( ! empty( $target_release['zipball_url'] ) ) {
				$package_url = $target_release['zipball_url'];
			}

			if ( empty( $package_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhum ZIP de pacote de download encontrado.', 'codesync-manager-for-github' ) ) );
			}

			// Native programmatic installation via Plugin_Upgrader
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			// Set the dynamic global variable so the upgrader filters recognize and resolve the canonical slug
			CODESYNC_Updater::$currently_installing_repo           = $repo_slug;
			CODESYNC_Updater::$currently_installing_subfolder      = $selected_subfolder;
			CODESYNC_Updater::$currently_installing_canonical_slug = '';

			// Force direct filesystem access — required for AJAX-based plugin installation
			$codesync_fs_filter = function() { return 'direct'; };
			add_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );

			// Use silent/automatic skin
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package_url, array( 'overwrite_package' => true ) );

			remove_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );

			// Re-initialize files and flush cache
			wp_clean_plugins_cache();

			// Capture the resolved canonical slug
			$resolved_canonical_slug = CODESYNC_Updater::$currently_installing_canonical_slug;

			// Clear dynamic variables
			CODESYNC_Updater::$currently_installing_repo           = '';
			CODESYNC_Updater::$currently_installing_subfolder      = '';
			CODESYNC_Updater::$currently_installing_canonical_slug = '';

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}


			if ( ! $result ) {
				$skin_messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
				$detail        = ! empty( $skin_messages ) ? implode( ' ', array_slice( $skin_messages, -3 ) ) : '';
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'GSM Install Failed. Repo: ' . $repo_slug . ' | Skin: ' . implode( ' | ', $skin_messages ) );
				}
				wp_send_json_error( array(
					'message' => __( 'A instalação do plugin falhou. Se o problema persistir, adicione define(\'FS_METHOD\', \'direct\'); ao wp-config.php antes do require_once ABSPATH.', 'codesync-manager-for-github' ) . ( $detail ? ' ' . $detail : '' ),
				) );
			}


			// Locate the newly installed plugin directory and file using slug resolution code
			$plugins = get_plugins();
			$installed_plugin_file = '';
			$latest_version        = ltrim( $target_release['tag_name'], 'vV' );

			// Strategy 1: Search by the resolved canonical slug from the upgrader source selection
			if ( ! empty( $resolved_canonical_slug ) ) {
				foreach ( $plugins as $file => $meta ) {
					$parts_file = explode( '/', $file );
					if ( $parts_file[0] === $resolved_canonical_slug ) {
						$installed_plugin_file = $file;
						break;
					}
				}
			}

			// Strategy 2: Fallback to searching by sanitized repository name
			if ( empty( $installed_plugin_file ) ) {
				$repo_slug_sanitized = sanitize_title( $repo );
				foreach ( $plugins as $file => $meta ) {
					$parts_file = explode( '/', $file );
					if ( count( $parts_file ) > 1 && $parts_file[0] === $repo_slug_sanitized ) {
						$installed_plugin_file = $file;
						break;
					}
				}
			}

			// Strategy 3: Ultimate fallback - get the last modified plugin folder in WP_PLUGIN_DIR (within last 5 minutes)
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
				wp_send_json_error( array( 'message' => __( 'O plugin foi extraído, mas o WordPress não conseguiu indexar o arquivo principal. Ative-o manualmente no painel Plugins.', 'codesync-manager-for-github' ) ) );
			}

			$is_branch   = ! empty( $target_release['is_branch'] );
			$branch_name = isset( $target_release['branch_name'] ) ? $target_release['branch_name'] : '';

			// Add to managed list
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
			CODESYNC_Manager::log(
				$repo_slug,
				'instalacao',
				'sucesso',
				/* translators: %s: version number */
				sprintf( __( 'Plugin instalado com sucesso (Versão %s).', 'codesync-manager-for-github' ), $latest_version )
			);

			$activate_url = wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . urlencode( $installed_plugin_file ), 'activate-plugin_' . $installed_plugin_file );

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $installed_plugin_file );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $repo;

			wp_send_json_success( array(
				'message'      => __( 'Plugin instalado com sucesso!', 'codesync-manager-for-github' ),
				'plugin_name'  => $plugin_name,
				'version'      => $latest_version,
				'activate_url' => admin_url( $activate_url ),
			) );
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

		$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( ! is_array( $managed ) ) {
			$managed = array();
		}

		if ( isset( $managed[ $repo_slug ] ) ) {
			unset( $managed[ $repo_slug ] );
			CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );

			// Clear release cache transient immediately to prevent database orphan records
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) === 2 ) {
				CODESYNC_GitHub_API::delete_releases_cache( $parts[0], $parts[1] );
			}

			CODESYNC_Manager::log( $repo_slug, 'parar_gerenciar', 'sucesso', __( 'Parou de gerenciar o repositório. O plugin continua instalado no WordPress.', 'codesync-manager-for-github' ) );

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

		$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed ) || ! is_array( $managed ) ) {
			wp_send_json_success( array( 'message' => __( 'Nenhum plugin gerenciado para verificar.', 'codesync-manager-for-github' ) ) );
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
				$managed[ $repo ]['error_message'] = __( 'Diretório ou arquivo principal do plugin não encontrado localmente.', 'codesync-manager-for-github' );
				continue;
			}

			// Resolve version
			$file_data = get_file_data( $plugin_path, array( 'Version' => 'Version' ) );
			$installed_version = ! empty( $file_data['Version'] ) ? $file_data['Version'] : '0.0.0';

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

		// Save updated states to DB
		CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );

		// Clear core update cache transient to force WordPress to recognize updates immediately in standard screen
		delete_site_transient( 'update_plugins' );

		CODESYNC_Manager::log( 'sistema', 'verificacao_manual', 'sucesso', __( 'Verificação manual executada para todos os plugins gerenciados.', 'codesync-manager-for-github' ) );

		// Capture rendered HTML of table body and logs
		ob_start();
		self::render_plugins_cards();
		$table_html = ob_get_clean();

		ob_start();
		self::render_logs_table();
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

		$token = get_option( CODESYNC_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token do GitHub não configurado.', 'codesync-manager-for-github' ) ) );
		}

		$security_check = CODESYNC_Encryption::check_security_keys();
		if ( is_wp_error( $security_check ) ) {
			wp_send_json_error( array( 'message' => $security_check->get_error_message() ) );
		}

		$decrypted = CODESYNC_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		$managed = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		if ( ! isset( $managed[ $repo_slug ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin não encontrado na lista gerenciada.', 'codesync-manager-for-github' ) ) );
		}

		$plugin_data        = $managed[ $repo_slug ];
		$selected_subfolder = isset( $plugin_data['subfolder'] ) ? $plugin_data['subfolder'] : '';
		$stored_branch      = isset( $plugin_data['branch_name'] ) ? $plugin_data['branch_name'] : '';

		$parts = explode( '/', $repo_slug );
		if ( count( $parts ) !== 2 ) {
			wp_send_json_error( array( 'message' => __( 'Slug de repositório inválido.', 'codesync-manager-for-github' ) ) );
		}
		$owner = $parts[0];
		$repo  = $parts[1];

		$api = new CODESYNC_GitHub_API( $decrypted );

		// Use branch if plugin was installed from branch, otherwise use latest release
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
			$releases = $api->get_releases( $owner, $repo );
			if ( is_wp_error( $releases ) ) {
				wp_send_json_error( array( 'message' => $releases->get_error_message() ) );
			}
			if ( empty( $releases ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhuma release encontrada no repositório.', 'codesync-manager-for-github' ) ) );
			}
			$target_release = $releases[0];
		}

		$package_url = '';
		if ( ! empty( $target_release['assets'] ) ) {
			$package_url = $target_release['assets'][0]['url'];
		} elseif ( ! empty( $target_release['zipball_url'] ) ) {
			$package_url = $target_release['zipball_url'];
		}

		if ( empty( $package_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Nenhum pacote ZIP encontrado para download.', 'codesync-manager-for-github' ) ) );
		}

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/plugin.php';

		CODESYNC_Updater::$currently_installing_repo      = $repo_slug;
		CODESYNC_Updater::$currently_installing_subfolder = $selected_subfolder;

		$codesync_fs_filter = function() { return 'direct'; };
		add_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package_url, array( 'overwrite_package' => true ) );

		remove_filter( 'filesystem_method', $codesync_fs_filter, PHP_INT_MAX );
		wp_clean_plugins_cache();

		CODESYNC_Updater::$currently_installing_repo      = '';
		CODESYNC_Updater::$currently_installing_subfolder = '';

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'A reinstalação falhou. Tente novamente ou verifique as permissões do servidor.', 'codesync-manager-for-github' ) ) );
		}

		$latest_version = ltrim( $target_release['tag_name'], 'vV' );

		$managed[ $repo_slug ]['latest_version'] = $latest_version;
		$managed[ $repo_slug ]['status']         = 'atualizado';
		$managed[ $repo_slug ]['last_checked']   = current_time( 'mysql' );
		$managed[ $repo_slug ]['error_message']  = '';

		CODESYNC_Manager::update_option_no_autoload( CODESYNC_Manager::OPTION_PLUGINS, $managed );
		CODESYNC_Manager::log(
			$repo_slug,
			'atualizacao',
			'sucesso',
			/* translators: %s: version number */
			sprintf( __( 'Plugin reinstalado com sucesso via força (Versão %s).', 'codesync-manager-for-github' ), $latest_version )
		);

		ob_start();
		self::render_plugins_cards();
		$cards_html = ob_get_clean();

		ob_start();
		self::render_logs_table();
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
}
