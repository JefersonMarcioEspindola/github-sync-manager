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
 * Class GSM_Admin
 *
 * Handles administrative interface rendering, styling enqueues, and secure AJAX endpoints.
 */
class GSM_Admin {

	/**
	 * Init admin hooks.
	 */
	public static function init() {
		// Add menu page
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );

		// Enqueue scripts and styles
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_gsm_connect_account', array( __CLASS__, 'ajax_connect_account' ) );
		add_action( 'wp_ajax_gsm_disconnect_account', array( __CLASS__, 'ajax_disconnect_account' ) );
		add_action( 'wp_ajax_gsm_add_plugin', array( __CLASS__, 'ajax_add_plugin' ) );
		add_action( 'wp_ajax_gsm_verify_repo', array( __CLASS__, 'ajax_verify_repo' ) );
		add_action( 'wp_ajax_gsm_remove_plugin', array( __CLASS__, 'ajax_remove_plugin' ) );
		add_action( 'wp_ajax_gsm_check_updates', array( __CLASS__, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_gsm_save_locale', array( __CLASS__, 'ajax_save_locale' ) );
	}

	/**
	 * Register the WordPress admin menu item.
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'GitHub Sync', 'github-sync-manager' ),
			__( 'GitHub Sync', 'github-sync-manager' ),
			'manage_options',
			'github-sync-manager',
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
		if ( 'toplevel_page_github-sync-manager' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'gsm-admin-style',
			plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ),
			array(),
			defined( 'GSM_VERSION' ) ? GSM_VERSION : '1.0.0'
		);

		wp_enqueue_script(
			'gsm-admin-script',
			plugins_url( 'assets/js/admin.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			defined( 'GSM_VERSION' ) ? GSM_VERSION : '1.0.0',
			true
		);

		// Localize parameters for use in JS file
		wp_localize_script( 'gsm-admin-script', 'gsm_ajax', array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'gsm_admin_nonce' ),
			'texts'  => array(
				'confirm_stop'          => __( 'O plugin continuará instalado, mas deixará de receber atualizações automáticas. Deseja continuar?', 'github-sync-manager' ),
				'installing'            => __( 'Baixando e instalando...', 'github-sync-manager' ),
				'searching'             => __( 'Pesquisando repositórios...', 'github-sync-manager' ),
				'comm_fail'             => __( 'Falha na comunicação.', 'github-sync-manager' ),
				'confirm_install'       => __( 'Deseja baixar e instalar o plugin do repositório %s?', 'github-sync-manager' ),
				'install_error'         => __( 'Erro na Instalação: %s', 'github-sync-manager' ),
				'install_fail'          => __( 'Falha na comunicação de rede ao tentar instalar o plugin.', 'github-sync-manager' ),
				'remove_error'          => __( 'Erro ao excluir gerenciamento.', 'github-sync-manager' ),
				'scan_fail'             => __( 'Falha na comunicação de rede durante o escaneamento.', 'github-sync-manager' ),
				'prompt_copied'         => __( 'Prompt de IA copiado para a área de transferência com sucesso!', 'github-sync-manager' ),
				'prompt_copy_fail'      => __( 'Não foi possível copiar o prompt automaticamente. Por favor, copie manualmente.', 'github-sync-manager' ),
				'save_locale_error'     => __( 'Erro ao salvar o idioma.', 'github-sync-manager' ),
				'loading_repos'         => __( 'Carregando seus repositórios do GitHub...', 'github-sync-manager' ),
				'repos_load_error'      => __( 'Erro de conexão ao buscar repositórios.', 'github-sync-manager' ),
				'no_repos_found'        => __( 'Nenhum repositório encontrado na sua conta do GitHub.', 'github-sync-manager' ),
				'already_managed'       => __( 'Já Gerenciado', 'github-sync-manager' ),
				'install_btn'           => __( 'Instalar Plugin', 'github-sync-manager' ),
				'no_desc'               => __( 'Sem descrição no repositório.', 'github-sync-manager' ),
				'updated_lbl'           => __( 'Atualizado: %s', 'github-sync-manager' ),
				'private_lbl'           => __( 'Privado', 'github-sync-manager' ),
				'public_lbl'            => __( 'Público', 'github-sync-manager' ),
				'no_managed'            => __( 'Nenhum plugin gerenciado ainda. Acesse a aba "Adicionar Plugin" para começar.', 'github-sync-manager' ),
				'confirm_disconnect'    => __( 'Tem certeza de que deseja desconectar sua conta GitHub? Os plugins continuarão instalados, mas não receberão notificações de atualização.', 'github-sync-manager' ),
				'confirm_prompt'        => __( 'Aja como um desenvolvedor experiente em WordPress e Git. Meu repositório do plugin \'%s\' não possui releases publicadas no GitHub. Crie um guia passo a passo conciso em Markdown para eu publicar a release \'v%s\' desse plugin, explicando como gerar o arquivo ZIP correto (apenas a pasta do plugin, sem os arquivos de versionamento do Git) e como criar a Release no GitHub usando a interface web ou GitHub CLI. Inclua boas práticas de versionamento SemVer.', 'github-sync-manager' ),
				'req_failed'            => __( 'Falha na requisição. Verifique sua conexão de rede.', 'github-sync-manager' ),
				'install_success_title' => __( '✅ Plugin Instalado com Sucesso!', 'github-sync-manager' ),
				'install_success_msg'   => __( 'O plugin <strong>%1$s</strong> (Versão %2$s) foi baixado e gravado localmente.', 'github-sync-manager' ),
				'activate_btn'          => __( 'Ativar Plugin Agora', 'github-sync-manager' ),
				'scan_error'            => __( 'Erro ao verificar: %s', 'github-sync-manager' ),
				'checking_repo'         => __( 'Verificando estrutura do repositório...', 'github-sync-manager' ),
				'plugin_detected'       => __( 'Plugin <strong>%1$s</strong> (Versão %2$s) detectado automaticamente.', 'github-sync-manager' ),
				'plugin_not_detected'   => __( 'Nenhum plugin WordPress válido foi encontrado automaticamente. Selecione a pasta base e a origem abaixo para instalar.', 'github-sync-manager' ),
				'advanced_options'      => __( 'Opções Avançadas', 'github-sync-manager' ),
				'select_source'         => __( 'Origem (Release ou Ramo):', 'github-sync-manager' ),
				'select_folder'         => __( 'Pasta Base do Plugin:', 'github-sync-manager' ),
				'select_folder_desc'    => __( 'Indique a subpasta do repositório onde os arquivos do plugin de fato residem (a pasta que contém o arquivo PHP principal). O gerenciador extrairá apenas essa pasta, descartando arquivos externos. Isso permite sincronizar diretamente o código-fonte, eliminando a necessidade de gerar arquivos ZIP ou criar releases manuais no GitHub para atualizar o plugin!', 'github-sync-manager' ),
				'root_folder'           => __( 'Pasta Raiz', 'github-sync-manager' ),
				'close_btn'             => __( 'Fechar', 'github-sync-manager' ),
			),
		) );
	}

	/**
	 * Render the HTML dashboard admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para acessar esta página.', 'github-sync-manager' ) );
		}

		$connected_user  = get_option( GSM_Manager::OPTION_USER );
		$token_exists    = ! empty( get_option( GSM_Manager::OPTION_TOKEN ) );
		$security_status = GSM_Encryption::check_security_keys();
		$security_error  = is_wp_error( $security_status ) ? $security_status->get_error_message() : '';

		?>
		<div class="wrap gsm-wrap">
			<div class="gsm-header-panel">
				<h1 class="gsm-title">
					<span class="dashicons dashicons-update-alt"></span>
					<?php esc_html_e( 'GitHub Sync Manager', 'github-sync-manager' ); ?>
				</h1>
				
				<?php if ( $token_exists && is_array( $connected_user ) ) : ?>
					<div class="gsm-user-badge">
						<img src="<?php echo esc_url( $connected_user['avatar_url'] ); ?>" alt="Avatar" class="gsm-user-avatar" />
						<div class="gsm-user-info">
							<span class="gsm-username">@<?php echo esc_html( $connected_user['username'] ); ?></span>
							<span class="gsm-pulse-badge">
								<span class="gsm-pulse"></span>
								<?php esc_html_e( 'Conectado', 'github-sync-manager' ); ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $security_error ) ) : ?>
				<div class="notice notice-error gsm-notice-blocking">
					<p><strong><?php esc_html_e( 'Erro de Segurança:', 'github-sync-manager' ); ?></strong> <?php echo esc_html( $security_error ); ?></p>
				</div>
			<?php else : ?>

				<?php if ( ! $token_exists ) : ?>
					<!-- Activation screen -->
					<div class="gsm-card gsm-login-card">
						<h2><?php esc_html_e( 'Conectar Conta GitHub', 'github-sync-manager' ); ?></h2>
						<p><?php esc_html_e( 'Para começar a gerenciar seus plugins hospedados no GitHub, conecte uma conta utilizando um Personal Access Token (PAT) com as devidas permissões.', 'github-sync-manager' ); ?></p>
						
						<div class="gsm-help-box">
							<p><strong><?php esc_html_e( 'Qual tipo de token criar?', 'github-sync-manager' ); ?></strong></p>
							<ul>
								<li><strong>Classic PAT:</strong> <?php esc_html_e( 'Crie um token com o escopo ', 'github-sync-manager' ); ?><code>repo</code> (<?php esc_html_e( 'para repositórios privados e públicos', 'github-sync-manager' ); ?>) <?php esc_html_e( 'ou ', 'github-sync-manager' ); ?><code>public_repo</code> (<?php esc_html_e( 'somente para públicos', 'github-sync-manager' ); ?>).</li>
								<li><strong>Fine-Grained PAT (Novo):</strong> <?php esc_html_e( 'Selecione permissão de leitura e gravação para "Contents" e "Metadata" nos repositórios que deseja gerenciar.', 'github-sync-manager' ); ?></li>
							</ul>
							<p>👉 <a href="https://github.com/settings/tokens" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Clique aqui para criar seu Token no GitHub', 'github-sync-manager' ); ?></a></p>
						</div>

						<form id="gsm-connect-form">
							<div class="gsm-form-group">
								<label for="gsm_pat_token"><strong><?php esc_html_e( 'GitHub Personal Access Token (PAT)', 'github-sync-manager' ); ?></strong></label>
								<input type="password" id="gsm_pat_token" name="gsm_pat_token" class="regular-text" required placeholder="github_pat_..." autocomplete="off" />
							</div>
							<div class="gsm-submit-btn-row">
								<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Conectar Conta', 'github-sync-manager' ); ?></button>
								<span class="spinner gsm-spinner"></span>
							</div>
							<div class="gsm-error-message" style="display:none;"></div>
						</form>
					</div>
				<?php else : ?>
					<!-- Admin core view -->
					<h2 class="nav-tab-wrapper gsm-tabs-nav">
						<a href="#gsm-tab-plugins" class="nav-tab nav-tab-active"><?php esc_html_e( 'Plugins Gerenciados', 'github-sync-manager' ); ?></a>
						<a href="#gsm-tab-add" class="nav-tab" id="gsm-trigger-add-tab"><?php esc_html_e( 'Adicionar Plugin', 'github-sync-manager' ); ?></a>
						<a href="#gsm-tab-logs" class="nav-tab"><?php esc_html_e( 'Histórico de Logs', 'github-sync-manager' ); ?></a>
						<a href="#gsm-tab-config" class="nav-tab"><?php esc_html_e( 'Configurações', 'github-sync-manager' ); ?></a>
					</h2>

					<!-- Tab content: Plugins -->
					<div id="gsm-tab-plugins" class="gsm-tab-content gsm-tab-active">
						<div class="gsm-action-bar">
							<button type="button" class="button button-primary" id="gsm-btn-scan-now">
								<span class="dashicons dashicons-update"></span>
								<?php esc_html_e( 'Verificar atualizações agora', 'github-sync-manager' ); ?>
							</button>
							<span class="spinner gsm-spinner" id="gsm-scan-spinner"></span>
						</div>

						<div class="gsm-plugins-cards" id="gsm-plugins-cards">
							<?php self::render_plugins_cards(); ?>
						</div>
					</div>

					<!-- Tab content: Add Plugin -->
					<div id="gsm-tab-add" class="gsm-tab-content">
						<div class="gsm-info-notice" style="margin-bottom: 20px; margin-top: 0;">
							<span class="dashicons dashicons-info"></span>
							<p><?php esc_html_e( 'Exibindo apenas repositórios com código PHP principal ou com nome/descrição relacionados a plugins WordPress.', 'github-sync-manager' ); ?></p>
						</div>

						<div class="gsm-filter-bar">
							<input type="text" id="gsm-repo-search" placeholder="<?php esc_attr_e( 'Buscar repositório por nome...', 'github-sync-manager' ); ?>" autocomplete="off" />
							<button type="button" class="button" id="gsm-btn-reload-repos">
								<span class="dashicons dashicons-image-rotate"></span>
								<?php esc_html_e( 'Recarregar Repositórios', 'github-sync-manager' ); ?>
							</button>
							<span class="spinner gsm-spinner" id="gsm-repos-spinner"></span>
						</div>

						<div class="gsm-repos-grid" id="gsm-repos-container">
							<!-- Populated via JS/AJAX -->
						</div>
					</div>

					<!-- Tab content: Logs -->
					<div id="gsm-tab-logs" class="gsm-tab-content">
						<div class="gsm-card gsm-table-card">
							<div id="gsm-logs-table-wrapper">
								<?php self::render_logs_table(); ?>
							</div>
						</div>
					</div>

					<!-- Tab content: Settings/Config -->
					<div id="gsm-tab-config" class="gsm-tab-content">
						<div class="gsm-card gsm-settings-card">
							<h2><?php esc_html_e( 'Configurações do GitHub Sync', 'github-sync-manager' ); ?></h2>
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Conta Conectada', 'github-sync-manager' ); ?></th>
										<td>
											<div class="gsm-profile-detail">
												<img src="<?php echo esc_url( $connected_user['avatar_url'] ); ?>" class="gsm-profile-avatar" alt="Avatar" />
												<div>
													<strong>@<?php echo esc_html( $connected_user['username'] ); ?></strong>
													<p class="description"><?php printf( esc_html__( 'Tipo de Token: %s', 'github-sync-manager' ), esc_html( ucfirst( $connected_user['token_type'] ) ) ); ?></p>
												</div>
											</div>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Token Armazenado', 'github-sync-manager' ); ?></th>
										<td>
											<code><?php echo esc_html( GSM_Encryption::mask_token( GSM_Encryption::decrypt( get_option( GSM_Manager::OPTION_TOKEN ) ) ) ); ?></code>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Idioma do Plugin', 'github-sync-manager' ); ?></th>
										<td>
											<?php $selected_locale = get_option( 'gsm_locale', 'pt_BR' ); ?>
											<select id="gsm_locale" name="gsm_locale" style="min-width: 200px;">
												<option value="pt_BR" <?php selected( $selected_locale, 'pt_BR' ); ?>><?php esc_html_e( 'Português (Brasil)', 'github-sync-manager' ); ?></option>
												<option value="en_US" <?php selected( $selected_locale, 'en_US' ); ?>><?php esc_html_e( 'English (US)', 'github-sync-manager' ); ?></option>
												<option value="es_ES" <?php selected( $selected_locale, 'es_ES' ); ?>><?php esc_html_e( 'Español', 'github-sync-manager' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Selecione o idioma da interface do GitHub Sync Manager.', 'github-sync-manager' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Agendamento Automático', 'github-sync-manager' ); ?></th>
										<td>
											<p><?php esc_html_e( 'O sistema verifica se há atualizações disponíveis para os plugins de forma automática duas vezes ao dia.', 'github-sync-manager' ); ?></p>
											
											<div class="gsm-info-notice">
												<span class="dashicons dashicons-info"></span>
												<p><em>“<?php esc_html_e( 'As verificações automáticas dependem de tráfego no site. Para sites de produção, recomenda-se desabilitar o WP-Cron no wp-config.php e agendar uma tarefa cron real no servidor chamando wp-cron.php.', 'github-sync-manager' ); ?>”</em></p>
											</div>
										</td>
									</tr>
								</tbody>
							</table>

							<div class="gsm-settings-actions">
								<button type="button" class="button button-link-delete" id="gsm-btn-disconnect">
									<?php esc_html_e( 'Desconectar conta GitHub', 'github-sync-manager' ); ?>
								</button>
								<span class="spinner gsm-spinner" id="gsm-disconnect-spinner"></span>
							</div>
						</div>
					</div>

				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Modal de Instalação e Configuração -->
		<div id="gsm-install-modal" class="gsm-modal-wrapper" style="display: none;">
			<div class="gsm-modal-backdrop"></div>
			<div class="gsm-modal-container">
				<div class="gsm-modal-header">
					<h3 class="gsm-modal-title"><?php esc_html_e( 'Instalar Plugin', 'github-sync-manager' ); ?></h3>
					<button type="button" class="gsm-modal-close" aria-label="<?php esc_attr_e( 'Fechar', 'github-sync-manager' ); ?>">&times;</button>
				</div>
				<div class="gsm-modal-body">
					<!-- Conteúdo dinâmico via JS -->
				</div>
				<div class="gsm-modal-footer">
					<button type="button" class="button gsm-modal-btn-cancel"><?php esc_html_e( 'Cancelar', 'github-sync-manager' ); ?></button>
					<button type="button" class="button button-primary gsm-modal-btn-install" disabled><?php esc_html_e( 'Instalar', 'github-sync-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper to render plugins table body rows.
	 */
	public static function render_plugins_cards() {
		$managed = get_option( GSM_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed ) || ! is_array( $managed ) ) {
			?>
			<p class="gsm-no-plugins-msg"><?php esc_html_e( 'Nenhum plugin gerenciado ainda. Acesse a aba "Adicionar Plugin" para começar.', 'github-sync-manager' ); ?></p>
			<?php
			return;
		}

		$all_logs = get_option( GSM_Manager::OPTION_LOGS, array() );

		foreach ( $managed as $repo => $data ) {
			$plugin_file = isset( $data['plugin_file'] ) ? $data['plugin_file'] : '';
			$plugin_name = dirname( $plugin_file );

			$installed_version = '0.0.0';
			if ( ! empty( $plugin_file ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$file_data = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array(
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				) );
				$installed_version = $file_data['Version'];
				$plugin_name       = $file_data['Name'];
			}

			$status         = isset( $data['status'] ) ? $data['status'] : 'atualizado';
			$latest_version = isset( $data['latest_version'] ) ? $data['latest_version'] : $installed_version;
			$error_message  = isset( $data['error_message'] ) ? $data['error_message'] : '';

			$status_label = '';
			$status_class = '';

			switch ( $status ) {
				case 'atualizado':
					$status_label = __( 'Atualizado', 'github-sync-manager' );
					$status_class = 'gsm-status-updated';
					break;
				case 'atualizacao_disponivel':
					$status_label = __( 'Atualização disponível', 'github-sync-manager' );
					$status_class = 'gsm-status-update';
					break;
				case 'indisponivel':
					$status_label = __( 'Indisponível', 'github-sync-manager' );
					$status_class = 'gsm-status-unavailable';
					break;
				case 'erro':
				default:
					$status_label = __( 'Erro', 'github-sync-manager' );
					$status_class = 'gsm-status-error';
					break;
			}

			// Build activity dots from last 8 log entries for this repo
			$repo_logs = array_filter( $all_logs, function( $l ) use ( $repo ) {
				return isset( $l['repo'] ) && $l['repo'] === $repo;
			} );
			$repo_logs = array_slice( array_values( array_reverse( $repo_logs ) ), 0, 8 );

			?>
			<div class="gsm-plugin-card" data-repo="<?php echo esc_attr( $repo ); ?>">
				<div class="gsm-plugin-card-header">
					<div>
						<h3 class="gsm-plugin-card-title"><?php echo esc_html( $plugin_name ); ?></h3>
						<a class="gsm-plugin-card-repo" href="<?php echo esc_url( 'https://github.com/' . $repo ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-external"></span><?php echo esc_html( $repo ); ?>
						</a>
					</div>
					<span class="gsm-status-badge <?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( $status_label ); ?>
						<?php if ( 'erro' === $status && ! empty( $error_message ) ) : ?>
							<span class="dashicons dashicons-editor-help gsm-tooltip-trigger" title="<?php echo esc_attr( $error_message ); ?>"></span>
						<?php endif; ?>
					</span>
				</div>

				<div class="gsm-plugin-versions">
					<span><?php esc_html_e( 'Instalado:', 'github-sync-manager' ); ?> <code><?php echo esc_html( $installed_version ); ?></code></span>
					<?php if ( $latest_version !== $installed_version ) : ?>
						<span class="gsm-versions-arrow">→</span>
						<span><?php esc_html_e( 'Disponível:', 'github-sync-manager' ); ?> <code><?php echo esc_html( $latest_version ); ?></code></span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $data['is_branch'] ) || ! empty( $data['subfolder'] ) ) : ?>
				<div class="gsm-plugin-card-tags">
					<?php if ( ! empty( $data['is_branch'] ) ) : ?>
						<span class="gsm-branch-label" title="<?php esc_attr_e( 'Instalado diretamente de uma branch, sem releases no GitHub.', 'github-sync-manager' ); ?>">
							<?php printf( esc_html__( 'Ramo: %s', 'github-sync-manager' ), esc_html( $data['branch_name'] ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $data['subfolder'] ) ) : ?>
						<span class="gsm-subfolder-label" title="<?php esc_attr_e( 'Pasta base configurada para este plugin.', 'github-sync-manager' ); ?>">
							<?php printf( esc_html__( 'Pasta: %s', 'github-sync-manager' ), esc_html( $data['subfolder'] ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $repo_logs ) ) : ?>
				<div class="gsm-activity-dots" title="<?php esc_attr_e( 'Atividade recente (mais recente à esquerda)', 'github-sync-manager' ); ?>">
					<?php foreach ( $repo_logs as $log ) :
						$dot_class = ( 'sucesso' === $log['result'] ) ? 'gsm-dot-sucesso' : 'gsm-dot-erro';
						$dot_title = isset( $log['timestamp'] ) ? esc_attr( date_i18n( 'd/m/Y H:i', strtotime( $log['timestamp'] ) ) ) : '';
					?>
						<span class="gsm-activity-dot <?php echo esc_attr( $dot_class ); ?>" title="<?php echo $dot_title; ?>"></span>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="gsm-plugin-card-actions">
					<?php if ( ! empty( $data['is_branch'] ) ) : ?>
						<button type="button" class="button button-small gsm-btn-copy-prompt" data-repo="<?php echo esc_attr( $repo ); ?>" data-version="<?php echo esc_attr( $installed_version ); ?>" title="<?php esc_attr_e( 'Copiar prompt de IA para criar releases', 'github-sync-manager' ); ?>">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Prompt Release', 'github-sync-manager' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="button button-link-delete gsm-btn-remove" data-repo="<?php echo esc_attr( $repo ); ?>">
						<?php esc_html_e( 'Parar de gerenciar', 'github-sync-manager' ); ?>
					</button>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Helper to render the activity logs table.
	 */
	public static function render_logs_table() {
		$logs = get_option( GSM_Manager::OPTION_LOGS, array() );
		if ( empty( $logs ) || ! is_array( $logs ) ) {
			?>
			<p class="gsm-no-logs-msg"><?php esc_html_e( 'Nenhuma atividade registrada ainda.', 'github-sync-manager' ); ?></p>
			<?php
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped table-view-list gsm-logs-table">
			<thead>
				<tr>
					<th style="width: 160px;"><?php esc_html_e( 'Data/Hora', 'github-sync-manager' ); ?></th>
					<th style="width: 200px;"><?php esc_html_e( 'Repositório', 'github-sync-manager' ); ?></th>
					<th style="width: 140px;"><?php esc_html_e( 'Ação', 'github-sync-manager' ); ?></th>
					<th style="width: 110px;"><?php esc_html_e( 'Resultado', 'github-sync-manager' ); ?></th>
					<th><?php esc_html_e( 'Mensagem', 'github-sync-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) :
					$res_class = ( 'sucesso' === $log['result'] ) ? 'gsm-log-success' : 'gsm-log-error';
					
					// Translate result label
					$result_label = $log['result'];
					if ( 'sucesso' === $log['result'] ) {
						$result_label = __( 'Sucesso', 'github-sync-manager' );
					} elseif ( 'erro' === $log['result'] ) {
						$result_label = __( 'Erro', 'github-sync-manager' );
					}

					// Translate action label
					$action_label = strtoupper( $log['action'] );
					switch ( $log['action'] ) {
						case 'ativacao':
							$action_label = __( 'Ativação', 'github-sync-manager' );
							break;
						case 'conexao':
							$action_label = __( 'Conexão', 'github-sync-manager' );
							break;
						case 'desconexao':
							$action_label = __( 'Desconexão', 'github-sync-manager' );
							break;
						case 'instalacao':
							$action_label = __( 'Instalação', 'github-sync-manager' );
							break;
						case 'atualizacao':
							$action_label = __( 'Atualização', 'github-sync-manager' );
							break;
						case 'cron_check':
							$action_label = __( 'Cron Check', 'github-sync-manager' );
							break;
					}
					?>
					<tr>
						<td><code><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $log['timestamp'] ) ) ); ?></code></td>
						<td><strong><?php echo esc_html( $log['repo'] ); ?></strong></td>
						<td><span class="gsm-log-action-tag"><?php echo esc_html( mb_strtoupper( $action_label, 'UTF-8' ) ); ?></span></td>
						<td><span class="gsm-status-badge <?php echo esc_attr( $res_class ); ?>"><?php echo esc_html( $result_label ); ?></span></td>
						<td class="gsm-log-msg-cell"><?php echo esc_html( $log['message'] ); ?></td>
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
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( empty( $raw_token ) ) {
			wp_send_json_error( array( 'message' => __( 'O token está vazio.', 'github-sync-manager' ) ) );
		}

		// Encrypt and test connection
		$encrypted = GSM_Encryption::encrypt( $raw_token );
		if ( is_wp_error( $encrypted ) ) {
			wp_send_json_error( array( 'message' => $encrypted->get_error_message() ) );
		}

		$api = new GSM_GitHub_API( $raw_token );
		$validation = $api->validate_token();

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
		}

		// Success: Save details to options
		update_option( GSM_Manager::OPTION_TOKEN, $encrypted );
		update_option( GSM_Manager::OPTION_USER, $validation ); // Autoload = yes for general connected status is fine since it's small

		GSM_Manager::log( 'sistema', 'conexao', 'sucesso', sprintf( __( 'Conta conectada com sucesso (@%s).', 'github-sync-manager' ), $validation['username'] ) );

		wp_send_json_success( array(
			'message'    => __( 'Conectado com sucesso!', 'github-sync-manager' ),
			'username'   => $validation['username'],
			'avatar_url' => $validation['avatar_url'],
		) );
	}

	/**
	 * AJAX endpoint: Disconnect account.
	 */
	public static function ajax_disconnect_account() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		// Delete authentication options
		delete_option( GSM_Manager::OPTION_TOKEN );
		delete_option( GSM_Manager::OPTION_USER );

		GSM_Manager::log( 'sistema', 'desconexao', 'sucesso', __( 'Conta GitHub desconectada. Os plugins não receberão atualizações até que uma nova conta seja reconectada.', 'github-sync-manager' ) );

		wp_send_json_success( array( 'message' => __( 'Conta desconectada com sucesso!', 'github-sync-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Retrieve repos list.
	 */
	public static function ajax_add_plugin() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		// Connect API
		$token = get_option( GSM_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'github-sync-manager' ) ) );
		}

		$decrypted = GSM_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		// Fetch action: Get list of user repositories OR install repository
		$action_type = isset( $_POST['action_type'] ) ? sanitize_text_field( wp_unslash( $_POST['action_type'] ) ) : '';

		if ( 'list' === $action_type ) {
			$api   = new GSM_GitHub_API( $decrypted );
			$repos = $api->get_repositories();

			if ( is_wp_error( $repos ) ) {
				wp_send_json_error( array( 'message' => $repos->get_error_message() ) );
			}

			$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
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
				wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'github-sync-manager' ) ) );
			}

			$selected_ref       = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
			$selected_subfolder = isset( $_POST['subfolder'] ) ? sanitize_text_field( wp_unslash( $_POST['subfolder'] ) ) : '';

			// Self block validation
			$valid_repo = GSM_Manager::validate_repository_before_add( $repo_slug );
			if ( is_wp_error( $valid_repo ) ) {
				wp_send_json_error( array( 'message' => $valid_repo->get_error_message() ) );
			}

			$managed_plugins = get_option( GSM_Manager::OPTION_PLUGINS, array() );
			if ( ! is_array( $managed_plugins ) ) {
				$managed_plugins = array();
			}

			if ( isset( $managed_plugins[ $repo_slug ] ) ) {
				wp_send_json_error( array( 'message' => __( 'Este repositório já está sendo gerenciado.', 'github-sync-manager' ) ) );
			}

			// Explode owner and repo
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) !== 2 ) {
				wp_send_json_error( array( 'message' => __( 'Slug de repositório inválido.', 'github-sync-manager' ) ) );
			}
			$owner = $parts[0];
			$repo  = $parts[1];

			$api = new GSM_GitHub_API( $decrypted );

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
						'zipball_url' => sprintf( '%s/repos/%s/%s/zipball/%s', GSM_GitHub_API::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $selected_ref ) ),
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
				wp_send_json_error( array( 'message' => __( 'Nenhuma release ou branch disponível encontrada.', 'github-sync-manager' ) ) );
			}

			$package_url = '';
			if ( ! empty( $target_release['assets'] ) ) {
				$package_url = $target_release['assets'][0]['url'];
			} elseif ( ! empty( $target_release['zipball_url'] ) ) {
				$package_url = $target_release['zipball_url'];
			}

			if ( empty( $package_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Nenhum ZIP de pacote de download encontrado.', 'github-sync-manager' ) ) );
			}

			// Native programmatic installation via Plugin_Upgrader
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			// Set the dynamic global variable so the upgrader filters recognize and resolve the canonical slug
			GSM_Updater::$currently_installing_repo      = $repo_slug;
			GSM_Updater::$currently_installing_subfolder = $selected_subfolder;

			// Force direct filesystem access — required for AJAX-based plugin installation
			$gsm_fs_filter = function() { return 'direct'; };
			add_filter( 'filesystem_method', $gsm_fs_filter, PHP_INT_MAX );

			// Use silent/automatic skin
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package_url, array( 'overwrite_package' => true ) );

			remove_filter( 'filesystem_method', $gsm_fs_filter, PHP_INT_MAX );

			// Re-initialize files and flush cache
			wp_clean_plugins_cache();

			// Clear dynamic variables
			GSM_Updater::$currently_installing_repo      = '';
			GSM_Updater::$currently_installing_subfolder = '';

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}


			if ( ! $result ) {
				$skin_messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
				$detail        = ! empty( $skin_messages ) ? implode( ' ', array_slice( $skin_messages, -3 ) ) : '';
				error_log( 'GSM Install Failed. Repo: ' . $repo_slug . ' | Skin: ' . implode( ' | ', $skin_messages ) );
				wp_send_json_error( array(
					'message' => __( 'A instalação do plugin falhou. Se o problema persistir, adicione define(\'FS_METHOD\', \'direct\'); ao wp-config.php antes do require_once ABSPATH.', 'github-sync-manager' ) . ( $detail ? ' ' . $detail : '' ),
				) );
			}


			// Locate the newly installed plugin directory and file using slug resolution code
			$plugins = get_plugins();
			$installed_plugin_file = '';
			$latest_version        = ltrim( $target_release['tag_name'], 'vV' );

			// Search for matching metadata
			foreach ( $plugins as $file => $meta ) {
				$parts_file = explode( '/', $file );
				$folder     = $parts_file[0];
				$domain = isset( $meta['TextDomain'] ) ? trim( $meta['TextDomain'] ) : '';
				if ( ! empty( $domain ) && ( sanitize_title( $domain ) === $folder ) ) {
					$installed_plugin_file = $file;
					break;
				}
			}

			// Fallback: search by name/version
			if ( empty( $installed_plugin_file ) ) {
				foreach ( $plugins as $file => $meta ) {
					if ( basename( dirname( $file ) ) === sanitize_title( $repo ) ) {
						$installed_plugin_file = $file;
						break;
					}
				}
			}

			// Ultimate fallback: get the last modified plugin folder inside wp-content/plugins
			if ( empty( $installed_plugin_file ) ) {
				$folders_dir = glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR );
				$latest_time = 0;
				$latest_folder = '';
				foreach ( $folders_dir as $f ) {
					$mtime = filemtime( $f );
					if ( $mtime > $latest_time && basename( $f ) !== 'github-sync-manager' ) {
						$latest_time = $mtime;
						$latest_folder = basename( $f );
					}
				}

				if ( ! empty( $latest_folder ) ) {
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
				wp_send_json_error( array( 'message' => __( 'O plugin foi extraído, mas o WordPress não conseguiu indexar o arquivo principal. Ative-o manualmente no painel Plugins.', 'github-sync-manager' ) ) );
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

			GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed_plugins );
			GSM_Manager::log(
				$repo_slug,
				'instalacao',
				'sucesso',
				sprintf( __( 'Plugin instalado com sucesso (Versão %s).', 'github-sync-manager' ), $latest_version )
			);

			$activate_url = wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . urlencode( $installed_plugin_file ), 'activate-plugin_' . $installed_plugin_file );

			wp_send_json_success( array(
				'message'      => __( 'Plugin instalado com sucesso!', 'github-sync-manager' ),
				'plugin_name'  => get_plugin_data( WP_PLUGIN_DIR . '/' . $installed_plugin_file )['Name'],
				'version'      => $latest_version,
				'activate_url' => admin_url( $activate_url ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Ação inválida.', 'github-sync-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Remove plugin from managed list.
	 */
	public static function ajax_remove_plugin() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'github-sync-manager' ) ) );
		}

		$managed = get_option( GSM_Manager::OPTION_PLUGINS, array() );
		if ( ! is_array( $managed ) ) {
			$managed = array();
		}

		if ( isset( $managed[ $repo_slug ] ) ) {
			unset( $managed[ $repo_slug ] );
			GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed );

			// Clear release cache transient immediately to prevent database orphan records
			$parts = explode( '/', $repo_slug );
			if ( count( $parts ) === 2 ) {
				GSM_GitHub_API::delete_releases_cache( $parts[0], $parts[1] );
			}

			GSM_Manager::log( $repo_slug, 'parar_gerenciar', 'sucesso', __( 'Parou de gerenciar o repositório. O plugin continua instalado no WordPress.', 'github-sync-manager' ) );

			wp_send_json_success( array( 'message' => __( 'Gerenciamento removido com sucesso!', 'github-sync-manager' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Repositório não encontrado.', 'github-sync-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Check updates now manually.
	 */
	public static function ajax_check_updates() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		$token = get_option( GSM_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'github-sync-manager' ) ) );
		}

		$decrypted = GSM_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		$managed = get_option( GSM_Manager::OPTION_PLUGINS, array() );
		if ( empty( $managed ) || ! is_array( $managed ) ) {
			wp_send_json_success( array( 'message' => __( 'Nenhum plugin gerenciado para verificar.', 'github-sync-manager' ) ) );
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
				$managed[ $repo ]['error_message'] = __( 'Diretório ou arquivo principal do plugin não encontrado localmente.', 'github-sync-manager' );
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
				$managed[ $repo ]['error_message'] = __( 'Repositório não possui releases publicadas.', 'github-sync-manager' );
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
		GSM_Manager::update_option_no_autoload( GSM_Manager::OPTION_PLUGINS, $managed );

		// Clear core update cache transient to force WordPress to recognize updates immediately in standard screen
		delete_site_transient( 'update_plugins' );

		GSM_Manager::log( 'sistema', 'verificacao_manual', 'sucesso', __( 'Verificação manual executada para todos os plugins gerenciados.', 'github-sync-manager' ) );

		// Capture rendered HTML of table body and logs
		ob_start();
		self::render_plugins_cards();
		$table_html = ob_get_clean();

		ob_start();
		self::render_logs_table();
		$logs_html = ob_get_clean();

		wp_send_json_success( array(
			'message'    => __( 'Verificação concluída!', 'github-sync-manager' ),
			'table_html' => $table_html,
			'logs_html'  => $logs_html,
		) );
	}

	/**
	 * Save configured locale via AJAX.
	 */
	public static function ajax_save_locale() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Você não tem permissão para realizar esta ação.', 'github-sync-manager' ) ) );
		}

		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( $_POST['locale'] ) : 'pt_BR';

		if ( ! in_array( $locale, array( 'pt_BR', 'en_US', 'es_ES' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Idioma inválido.', 'github-sync-manager' ) ) );
		}

		update_option( 'gsm_locale', $locale );

		// Clear core update cache transient to reload options
		delete_site_transient( 'update_plugins' );

		wp_send_json_success( array( 'message' => __( 'Idioma atualizado com sucesso!', 'github-sync-manager' ) ) );
	}

	/**
	 * AJAX endpoint: Verify repository structure, releases, branches, and directories.
	 */
	public static function ajax_verify_repo() {
		check_ajax_referer( 'gsm_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sem permissões adequadas.', 'github-sync-manager' ) ) );
		}

		$token = get_option( GSM_Manager::OPTION_TOKEN );
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Token ausente.', 'github-sync-manager' ) ) );
		}

		$decrypted = GSM_Encryption::decrypt( $token );
		if ( is_wp_error( $decrypted ) ) {
			wp_send_json_error( array( 'message' => $decrypted->get_error_message() ) );
		}

		$repo_slug = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		if ( empty( $repo_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'Repositório não especificado.', 'github-sync-manager' ) ) );
		}

		// Explode owner and repo
		$parts = explode( '/', $repo_slug );
		if ( count( $parts ) !== 2 ) {
			wp_send_json_error( array( 'message' => __( 'Slug de repositório inválido.', 'github-sync-manager' ) ) );
		}
		$owner = $parts[0];
		$repo  = $parts[1];

		$api = new GSM_GitHub_API( $decrypted );

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
				'name'        => sprintf( __( 'Ramo: %s', 'github-sync-manager' ), $default_branch ),
				'ref'         => $default_branch,
				'is_branch'   => true,
				'zipball_url' => sprintf( '%s/repos/%s/%s/zipball/%s', GSM_GitHub_API::API_URL, rawurlencode( $owner ), rawurlencode( $repo ), rawurlencode( $default_branch ) ),
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
