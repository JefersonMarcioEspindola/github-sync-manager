<?php
/**
 * Admin UI Rendering
 *
 * Handles the admin menu, asset enqueueing, and HTML rendering for the
 * CodeSync dashboard, plugin cards, and activity logs table.
 *
 * @package GitHubSyncManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CODESYNC_Admin_UI {

	/**
	 * Init UI hooks (menu + assets).
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

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
				'confirm_stop'          => __( 'The package will remain installed, but will no longer receive automatic updates. Do you wish to continue?', 'codesync-manager-for-github' ),
				'installing'            => __( 'Downloading and installing...', 'codesync-manager-for-github' ),
				'searching'             => __( 'Searching repositories...', 'codesync-manager-for-github' ),
				'comm_fail'             => __( 'Communication failure.', 'codesync-manager-for-github' ),
				/* translators: %s: repository name */
				'confirm_install'       => __( 'Do you want to download and install the package from the %s repository?', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'install_error'         => __( 'Installation Error: %s', 'codesync-manager-for-github' ),
				'install_fail'          => __( 'Network communication failure while trying to install the package.', 'codesync-manager-for-github' ),
				'remove_error'          => __( 'Error removing management.', 'codesync-manager-for-github' ),
				'scan_fail'             => __( 'Network communication failure during scan.', 'codesync-manager-for-github' ),
				'prompt_copied'         => __( 'AI Prompt copied to clipboard successfully!', 'codesync-manager-for-github' ),
				'prompt_copy_fail'      => __( 'Could not copy the prompt automatically. Please copy it manually.', 'codesync-manager-for-github' ),
				'save_locale_error'     => __( 'Error saving language.', 'codesync-manager-for-github' ),
				'loading_repos'         => __( 'Loading your GitHub repositories...', 'codesync-manager-for-github' ),
				'repos_load_error'      => __( 'Connection error fetching repositories.', 'codesync-manager-for-github' ),
				'no_repos_found'        => __( 'No repositories found in your GitHub account.', 'codesync-manager-for-github' ),
				'already_managed'       => __( 'Already Managed', 'codesync-manager-for-github' ),
				'install_btn'           => __( 'Install', 'codesync-manager-for-github' ),
				'no_desc'               => __( 'No description in the repository.', 'codesync-manager-for-github' ),
				/* translators: %s: date and time */
				'updated_lbl'           => __( 'Updated: %s', 'codesync-manager-for-github' ),
				'private_lbl'           => __( 'Private', 'codesync-manager-for-github' ),
				'public_lbl'            => __( 'Public', 'codesync-manager-for-github' ),
				'no_managed'            => __( 'No managed packages yet. Access the "Add Package" tab to get started.', 'codesync-manager-for-github' ),
				'confirm_disconnect'    => __( 'Are you sure you want to disconnect your GitHub account? Packages will remain installed, but will not receive update notifications.', 'codesync-manager-for-github' ),
				/* translators: 1: repository name, 2: version number */
				'confirm_prompt'        => __( 'Act as an experienced WordPress and Git developer. My package repository \'%1$s\' has no published releases on GitHub. Create a concise step-by-step guide in Markdown for me to publish release \'v%2$s\' of this package, explaining how to generate the correct ZIP file and how to create the Release on GitHub using the web interface or GitHub CLI. Include SemVer best practices.', 'codesync-manager-for-github' ),
				'req_failed'            => __( 'Request failed. Check your network connection.', 'codesync-manager-for-github' ),
				/* translators: %s: repository name */
				'force_update_confirm'  => __( 'This will download and reinstall the latest version from the %s repository, overwriting the current version. Continue?', 'codesync-manager-for-github' ),
				/* translators: %s: version number */
				'force_update_ok'       => __( 'Package reinstalled successfully! (Version %s)', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'force_update_err'      => __( 'Error reinstalling: %s', 'codesync-manager-for-github' ),
				'force_update_fail'     => __( 'Communication failure while trying to reinstall.', 'codesync-manager-for-github' ),
				'force_update_btn'      => __( 'Update', 'codesync-manager-for-github' ),
				'force_updating'        => __( 'Reinstalling...', 'codesync-manager-for-github' ),
				'sync_success_title'    => __( '&#x2705; Package Synchronized Successfully!', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'sync_success_msg'      => __( 'The package <strong>%1$s</strong> was already installed on your WordPress. The code has been updated and is now synchronized and being managed (Version %2$s).', 'codesync-manager-for-github' ),
				'install_success_title' => __( '&#x2705; Package Installed Successfully!', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'install_success_msg'   => __( 'The package <strong>%1$s</strong> (Version %2$s) was downloaded and saved on your WordPress.', 'codesync-manager-for-github' ),
				'activation_required'   => __( 'WordPress does not allow a plugin to activate another plugin. Click the button below to activate it — auto-sync will start right after.', 'codesync-manager-for-github' ),
				'activate_btn'          => __( 'Activate Now', 'codesync-manager-for-github' ),
				/* translators: %s: error message */
				'scan_error'            => __( 'Error scanning: %s', 'codesync-manager-for-github' ),
				'checking_repo'         => __( 'Checking repository structure...', 'codesync-manager-for-github' ),
				/* translators: 1: plugin name, 2: version number */
				'plugin_detected'       => __( 'Package <strong>%1$s</strong> (Version %2$s) automatically detected.', 'codesync-manager-for-github' ),
				'plugin_not_detected'   => __( 'No valid package was automatically found. Select the base folder and source below to install.', 'codesync-manager-for-github' ),
				'advanced_options'      => __( 'Advanced Options', 'codesync-manager-for-github' ),
				'select_source'         => __( 'Source (Release or Branch):', 'codesync-manager-for-github' ),
				'source_branch_desc'    => __( 'Sincronização via Ramo (Branch): As atualizações do site ocorrerão automaticamente a cada novo commit/push na branch selecionada (via Webhook), sem precisar incrementar a versão ou criar novas releases no GitHub.', 'codesync-manager-for-github' ),
				'source_release_desc'   => __( 'Sincronização via Release: Atualizações dependem de novas versões estáveis. O site só receberá notificações de atualização após você criar e publicar uma nova Release com tag de versão superior no GitHub.', 'codesync-manager-for-github' ),
				'select_folder'         => __( 'Package Base Folder:', 'codesync-manager-for-github' ),
				'select_folder_desc'    => __( 'Indicate the subfolder of the repository where the package files actually reside (the folder containing the main PHP or style.css file). The manager will extract only this folder, discarding external files. This allows directly syncing the source code without generating ZIPs or manual releases!', 'codesync-manager-for-github' ),
				'root_folder'           => __( 'Root Folder', 'codesync-manager-for-github' ),
				'close_btn'             => __( 'Close', 'codesync-manager-for-github' ),
				'plugin_validation'     => __( 'Plugin Validation', 'codesync-manager-for-github' ),
				'update_package'        => __( 'Update Package', 'codesync-manager-for-github' ),
				'check_plugin_optional' => __( 'Check Plugin (Optional)', 'codesync-manager-for-github' ),
				'checking'              => __( 'Checking...', 'codesync-manager-for-github' ),
				'confirm_force_install' => __( 'This package presented critical failures in the validation. Installing anyway is not recommended and might break your site. Are you sure?', 'codesync-manager-for-github' ),
				'webhook_active_title'  => __( 'Webhook Active', 'codesync-manager-for-github' ),
				'webhook_config_title'  => __( 'Webhook Configuration', 'codesync-manager-for-github' ),
				'verify_webhook_btn'    => __( 'Verify Webhook', 'codesync-manager-for-github' ),
				'disconnecting'         => __( 'Disconnecting...', 'codesync-manager-for-github' ),
				'disconnect_label'      => __( 'Disconnect', 'codesync-manager-for-github' ),
				/* translators: %s: date and time */
				'connected_since_fmt'   => __( 'Connected since %s · Auto-sync active', 'codesync-manager-for-github' ),
				/* translators: %s: repository name */
				'confirm_webhook_disc'  => __( 'Are you sure you want to disconnect the webhook for %s? The webhook on GitHub will remain, but the connection status will be reset here.', 'codesync-manager-for-github' ),
				'comm_error_retry'      => __( 'Communication error. Try again.', 'codesync-manager-for-github' ),
				'report_copied'         => __( 'Report copied to clipboard!', 'codesync-manager-for-github' ),
				'no_logs_yet'           => __( 'No activity recorded yet for this repository.', 'codesync-manager-for-github' ),
				'logs_load_fail'        => __( 'Failed to load logs.', 'codesync-manager-for-github' ),
				'logs_cap_notice'       => __( 'Showing 20 most recent entries — older logs not displayed.', 'codesync-manager-for-github' ),
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
								<?php esc_html_e( 'Connected', 'codesync-manager-for-github' ); ?>
							</span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $security_error ) ) : ?>
				<div class="notice notice-error codesync-notice-blocking">
					<p><strong><?php esc_html_e( 'Security Error:', 'codesync-manager-for-github' ); ?></strong> <?php echo esc_html( $security_error ); ?></p>
				</div>
			<?php else : ?>

				<?php if ( ! $token_exists ) : ?>
					<!-- Activation screen -->
					<div class="codesync-card codesync-login-card">
						<h2><?php esc_html_e( 'Connect GitHub Account', 'codesync-manager-for-github' ); ?></h2>
						<p><?php esc_html_e( 'To start managing your GitHub hosted packages, connect an account using a Personal Access Token (PAT) with the proper permissions.', 'codesync-manager-for-github' ); ?></p>
						
						<div class="codesync-help-box">
							<p><strong><?php esc_html_e( 'Which type of token to create?', 'codesync-manager-for-github' ); ?></strong></p>
							<ul>
								<li><strong><?php esc_html_e( 'Classic PAT:', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Create a token with the scope ', 'codesync-manager-for-github' ); ?><code>repo</code> (<?php esc_html_e( 'for private and public repositories', 'codesync-manager-for-github' ); ?>) <?php esc_html_e( 'or ', 'codesync-manager-for-github' ); ?><code>public_repo</code> (<?php esc_html_e( 'only for public ones', 'codesync-manager-for-github' ); ?>).</li>
								<li><strong><?php esc_html_e( 'Fine-Grained PAT (Novo):', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Select Read and Write access for "Contents" and "Metadata" on the repositories you want to manage.', 'codesync-manager-for-github' ); ?></li>
							</ul>
							<p>👉 <a href="https://github.com/settings/tokens" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Click here to create your GitHub Token', 'codesync-manager-for-github' ); ?></a></p>
						</div>

						<form id="codesync-connect-form">
							<div class="codesync-form-group">
								<label for="codesync_pat_token"><strong><?php esc_html_e( 'GitHub Personal Access Token (PAT)', 'codesync-manager-for-github' ); ?></strong></label>
								<input type="password" id="codesync_pat_token" name="codesync_pat_token" class="regular-text" required placeholder="github_pat_..." autocomplete="off" />
							</div>
							<div class="codesync-submit-btn-row">
								<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Connect Account', 'codesync-manager-for-github' ); ?></button>
								<span class="spinner codesync-spinner"></span>
							</div>
							<div class="codesync-error-message" style="display:none;"></div>
						</form>
					</div>
				<?php else : ?>
					<!-- Admin core view -->
					<h2 class="nav-tab-wrapper codesync-tabs-nav">
						<a href="#codesync-tab-plugins" class="nav-tab nav-tab-active"><?php esc_html_e( 'Managed Packages', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-add" class="nav-tab" id="codesync-trigger-add-tab"><?php esc_html_e( 'Add Package', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-logs" class="nav-tab"><?php esc_html_e( 'Logs History', 'codesync-manager-for-github' ); ?></a>
						<a href="#codesync-tab-config" class="nav-tab"><?php esc_html_e( 'Settings', 'codesync-manager-for-github' ); ?></a>
					</h2>

					<!-- Tab content: Plugins -->
					<div id="codesync-tab-plugins" class="codesync-tab-content codesync-tab-active">
						<div class="codesync-action-bar">
							<button type="button" class="button button-primary" id="codesync-btn-scan-now">
								<i data-lucide="search" class="codesync-icon"></i>
								<?php esc_html_e( 'Check for updates now', 'codesync-manager-for-github' ); ?>
							</button>
							<button type="button" class="button" id="codesync-btn-update-all" style="margin-left: 10px; display: none;">
								<i data-lucide="layers" class="codesync-icon"></i>
								<?php esc_html_e( 'Update All', 'codesync-manager-for-github' ); ?>
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
							<p><?php esc_html_e( 'Displaying GitHub repositories. You can install both Plugins and Themes.', 'codesync-manager-for-github' ); ?></p>
						</div>

						<div class="codesync-filter-bar">
							<input type="text" id="codesync-repo-search" placeholder="<?php esc_attr_e( 'Buscar repositório por nome...', 'codesync-manager-for-github' ); ?>" autocomplete="off" />
							<button type="button" class="button" id="codesync-btn-reload-repos">
								<i data-lucide="refresh-cw" class="codesync-icon"></i>
								<?php esc_html_e( 'Reload Repositories', 'codesync-manager-for-github' ); ?>
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
							<h2><?php esc_html_e( 'Sync Manager Settings', 'codesync-manager-for-github' ); ?></h2>
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'Connected Account', 'codesync-manager-for-github' ); ?></th>
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
										<th scope="row"><?php esc_html_e( 'Stored Token', 'codesync-manager-for-github' ); ?></th>
										<td>
											<code><?php echo esc_html( CODESYNC_Encryption::mask_token( CODESYNC_Encryption::decrypt( get_option( CODESYNC_Manager::OPTION_TOKEN ) ) ) ); ?></code>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Plugin Language', 'codesync-manager-for-github' ); ?></th>
										<td>
											<?php $selected_locale = get_option( 'codesync_locale', 'pt_BR' ); ?>
											<select id="codesync_locale" name="codesync_locale" style="min-width: 200px;">
												<option value="pt_BR" <?php selected( $selected_locale, 'pt_BR' ); ?>><?php esc_html_e( 'Portuguese (Brazil)', 'codesync-manager-for-github' ); ?></option>
												<option value="en_US" <?php selected( $selected_locale, 'en_US' ); ?>><?php esc_html_e( 'English (US)', 'codesync-manager-for-github' ); ?></option>
												<option value="es_ES" <?php selected( $selected_locale, 'es_ES' ); ?>><?php esc_html_e( 'Spanish', 'codesync-manager-for-github' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Select the interface language for CodeSync Manager for GitHub.', 'codesync-manager-for-github' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Automatic Scheduling', 'codesync-manager-for-github' ); ?></th>
										<td>
											<p><?php esc_html_e( 'Update checks occur automatically twice daily via native WP-Cron.', 'codesync-manager-for-github' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Plugin Version', 'codesync-manager-for-github' ); ?></th>
										<td>
											<code><?php echo esc_html( defined( 'CODESYNC_VERSION' ) ? CODESYNC_VERSION : '' ); ?></code>
										</td>
									</tr>
								</tbody>
							</table>

							<div class="codesync-settings-actions">
								<button type="button" class="button button-link-delete" id="codesync-btn-disconnect">
									<?php esc_html_e( 'Disconnect GitHub account', 'codesync-manager-for-github' ); ?>
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
					<h3 class="codesync-modal-title"><?php esc_html_e( 'Install Package', 'codesync-manager-for-github' ); ?></h3>
					<button type="button" class="codesync-modal-close" aria-label="<?php esc_attr_e( 'Close', 'codesync-manager-for-github' ); ?>">&times;</button>
				</div>
				<div class="codesync-modal-body">
					<!-- Conteúdo dinâmico via JS -->
				</div>
				<div class="codesync-modal-footer" style="display:flex; justify-content:space-between; flex-wrap: wrap; gap: 10px; align-items:center;">
					<div class="codesync-modal-footer-left" style="display:flex; gap:8px; align-items:center;">
						<button type="button" class="button codesync-modal-btn-cancel"><?php esc_html_e( 'Cancel', 'codesync-manager-for-github' ); ?></button>
						<button type="button" class="button codesync-btn-copy-md" style="display:none;"><i data-lucide="copy" class="codesync-icon" style="width:14px;height:14px;"></i> <?php esc_html_e( 'Copy Markdown', 'codesync-manager-for-github' ); ?></button>
					</div>
					<div class="codesync-modal-footer-right" style="display:flex; gap:8px; align-items:center;">
						<button type="button" class="button button-primary codesync-modal-btn-install codesync-btn-confirm-install" style="display:none;"><?php esc_html_e( 'Confirm Installation', 'codesync-manager-for-github' ); ?></button>
						<button type="button" class="button button-primary codesync-modal-btn-install codesync-btn-force-install" style="display:none; background:#ef4444; border-color:#dc2626; text-shadow:none;"><?php esc_html_e( 'Install Anyway', 'codesync-manager-for-github' ); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Modal de Webhook -->
		<div id="codesync-webhook-modal" class="codesync-modal-wrapper" style="display: none;">
			<div class="codesync-modal-backdrop"></div>
			<div class="codesync-modal-container">
				<div class="codesync-modal-header">
					<h3 class="codesync-modal-title" id="codesync-webhook-modal-title"><?php esc_html_e( 'Webhook Configuration', 'codesync-manager-for-github' ); ?></h3>
					<button type="button" class="codesync-modal-close" aria-label="<?php esc_attr_e( 'Close', 'codesync-manager-for-github' ); ?>">&times;</button>
				</div>
				<div class="codesync-modal-body">

					<!-- View: Webhook Active (shown when ping already received) -->
					<div id="codesync-webhook-active-view" style="display:none;">
						<!-- Header: connection info -->
						<div style="display:flex; align-items:center; gap:12px; padding: 4px 0 16px; border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
							<div style="flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:9999px; background:#ecfdf5;">
								<i data-lucide="radio" style="width:22px;height:22px;color:#059669;"></i>
							</div>
							<div>
								<p style="margin:0; font-weight:700; color:#059669; font-size:14px;"><?php esc_html_e( 'Webhook Active', 'codesync-manager-for-github' ); ?></p>
								<p style="margin:0; font-size:12px; color:#64748b;" id="codesync-webhook-ping-info"><?php esc_html_e( 'Loading connection info...', 'codesync-manager-for-github' ); ?></p>
							</div>
						</div>

						<!-- Logs list -->
						<p style="margin:0 0 8px; font-size:12px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:.04em;"><?php esc_html_e( 'Recent Activity', 'codesync-manager-for-github' ); ?></p>
						<div id="codesync-webhook-logs-list" style="max-height:240px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; background:#f8fafc;">
							<div style="padding:20px; text-align:center; color:#94a3b8; font-size:13px;">
								<i data-lucide="loader-2" class="codesync-icon codesync-spin" style="width:16px;height:16px;"></i>
							</div>
						</div>

						<!-- Show setup details link -->
						<p style="text-align:center; margin:14px 0 0;">
							<a href="#" id="codesync-webhook-show-details" style="font-size:12px; color:#94a3b8; text-decoration:underline;"><?php esc_html_e( 'View webhook configuration details', 'codesync-manager-for-github' ); ?></a>
						</p>
					</div>

					<!-- View: Webhook Setup (shown when not yet configured / verify button clicked) -->
					<div id="codesync-webhook-setup-view">
						<p><?php esc_html_e( 'Follow these steps to configure a Webhook and receive updates instantly:', 'codesync-manager-for-github' ); ?></p>
						
						<div style="margin-bottom: 15px;">
							<strong><?php esc_html_e( 'Step 1:', 'codesync-manager-for-github' ); ?></strong> 👉 <a href="https://github.com" id="codesync-webhook-direct-link" target="_blank" rel="noopener noreferrer" style="font-weight:600; text-decoration:underline;"><?php esc_html_e( 'Go to Webhooks settings on GitHub', 'codesync-manager-for-github' ); ?></a> <?php esc_html_e( 'and click "Add webhook".', 'codesync-manager-for-github' ); ?>
						</div>

						<div style="margin-bottom: 15px; padding-left: 10px; border-left: 2px solid #e2e8f0;">
							<div class="codesync-form-group" style="margin-bottom: 10px;">
								<label><strong><?php esc_html_e( 'Payload URL:', 'codesync-manager-for-github' ); ?></strong></label>
								<div style="display:flex;gap:10px;">
									<input type="text" readonly value="<?php echo esc_url( get_rest_url( null, 'codesync/v1/webhook' ) ); ?>" id="codesync-webhook-url" />
									<button type="button" class="button codesync-btn-copy" data-target="#codesync-webhook-url"><i data-lucide="copy" class="codesync-icon"></i></button>
								</div>
							</div>

							<p style="margin-bottom: 10px;"><strong><?php esc_html_e( 'Content type:', 'codesync-manager-for-github' ); ?></strong> <code style="padding: 3px 6px; background:#f1f5f9;">application/json</code></p>

							<div class="codesync-form-group" style="margin-bottom: 10px;">
								<label><strong><?php esc_html_e( 'Secret:', 'codesync-manager-for-github' ); ?></strong></label>
								<div style="display:flex;gap:10px;">
									<input type="password" readonly value="<?php echo esc_attr( class_exists('CODESYNC_Webhook') ? CODESYNC_Webhook::get_or_create_secret() : '' ); ?>" id="codesync-webhook-secret" />
									<button type="button" class="button codesync-btn-toggle-visibility" data-target="#codesync-webhook-secret"><i data-lucide="eye" class="codesync-icon"></i></button>
									<button type="button" class="button codesync-btn-copy" data-target="#codesync-webhook-secret"><i data-lucide="copy" class="codesync-icon"></i></button>
								</div>
							</div>
						</div>

						<div style="margin-bottom: 15px;">
							<strong><?php esc_html_e( 'Step 2:', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Under "Which events would you like to trigger this webhook?", select:', 'codesync-manager-for-github' ); ?>
							<ul style="list-style-type: disc; margin-left: 20px; margin-top: 5px; color: #475569;">
								<li><em><?php esc_html_e( '"Let me select individual events."', 'codesync-manager-for-github' ); ?></em></li>
								<li><?php esc_html_e( 'Check', 'codesync-manager-for-github' ); ?> <strong>Pushes</strong> <?php esc_html_e( 'and', 'codesync-manager-for-github' ); ?> <strong>Releases</strong>.</li>
								<li><?php esc_html_e( 'Uncheck everything else.', 'codesync-manager-for-github' ); ?></li>
							</ul>
						</div>

						<div style="margin-bottom: 15px;">
							<strong><?php esc_html_e( 'Step 3:', 'codesync-manager-for-github' ); ?></strong> <?php esc_html_e( 'Click "Add webhook" at the bottom of the page.', 'codesync-manager-for-github' ); ?>
						</div>
					</div>
				</div>
				<div class="codesync-modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
					<button type="button" class="button codesync-modal-btn-cancel"><?php esc_html_e( 'Close', 'codesync-manager-for-github' ); ?></button>
					<button type="button" id="codesync-btn-disconnect-webhook" class="button" style="display:none; color:#ef4444; border-color:#fca5a5; align-items:center; gap:5px;">
						<i data-lucide="unplug" style="width:13px;height:13px;"></i>
						<?php esc_html_e( 'Disconnect Webhook', 'codesync-manager-for-github' ); ?>
					</button>
					<button type="button" class="button button-primary" id="codesync-btn-verify-webhook" disabled><?php esc_html_e( 'Verify Webhook', 'codesync-manager-for-github' ); ?></button>
				</div>
			</div>
		</div>

		<!-- Template CodeSync Checker UI -->
		<script type="text/template" id="tmpl-codesync-checker-ui">
			<div class="codesync-checker-container" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
				<div class="codesync-checker-intro" style="padding: 15px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
					<h4 style="margin:0 0 5px 0; font-size: 15px;"><i data-lucide="shield-check" class="codesync-icon" style="vertical-align: middle;"></i> <?php esc_html_e( 'Security & Structure Validation', 'codesync-manager-for-github' ); ?></h4>
					<p style="margin:0; color: #64748b; font-size: 13px;"><?php esc_html_e( 'Analyzing repository code before installation...', 'codesync-manager-for-github' ); ?></p>
				</div>
				
				<ul id="codesync-checker-steps" style="list-style: none; margin: 0; padding: 0;">
					<!-- Passo 1 -->
					<li class="codesync-checker-step" data-step="download">
						<div class="codesync-checker-step-header" style="padding: 15px 20px; display: flex; align-items: center; cursor: pointer; border-bottom: 1px solid #e2e8f0; background: #fff;">
							<span class="codesync-checker-step-icon" style="margin-right: 15px; color: #cbd5e1;"><i data-lucide="circle-dashed" class="codesync-icon"></i></span>
							<strong style="flex: 1;"><?php esc_html_e( 'Download & Base Structure', 'codesync-manager-for-github' ); ?></strong>
							<i data-lucide="chevron-down" class="codesync-icon" style="color:#94a3b8;"></i>
						</div>
						<div class="codesync-checker-step-body" style="display: none; padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #475569;"></div>
					</li>
					<!-- Passo 2 -->
					<li class="codesync-checker-step" data-step="headers">
						<div class="codesync-checker-step-header" style="padding: 15px 20px; display: flex; align-items: center; cursor: pointer; border-bottom: 1px solid #e2e8f0; background: #fff;">
							<span class="codesync-checker-step-icon" style="margin-right: 15px; color: #cbd5e1;"><i data-lucide="circle-dashed" class="codesync-icon"></i></span>
							<strong style="flex: 1;"><?php esc_html_e( 'Syntax & Compatibility', 'codesync-manager-for-github' ); ?></strong>
							<i data-lucide="chevron-down" class="codesync-icon" style="color:#94a3b8;"></i>
						</div>
						<div class="codesync-checker-step-body" style="display: none; padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #475569;"></div>
					</li>
					<!-- Passo 3 -->
					<li class="codesync-checker-step" data-step="security">
						<div class="codesync-checker-step-header" style="padding: 15px 20px; display: flex; align-items: center; cursor: pointer; border-bottom: 1px solid #e2e8f0; background: #fff;">
							<span class="codesync-checker-step-icon" style="margin-right: 15px; color: #cbd5e1;"><i data-lucide="circle-dashed" class="codesync-icon"></i></span>
							<strong style="flex: 1;"><?php esc_html_e( 'Security & SQL', 'codesync-manager-for-github' ); ?></strong>
							<i data-lucide="chevron-down" class="codesync-icon" style="color:#94a3b8;"></i>
						</div>
						<div class="codesync-checker-step-body" style="display: none; padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #475569;"></div>
					</li>
					<!-- Passo 4 -->
					<li class="codesync-checker-step" data-step="deprecated">
						<div class="codesync-checker-step-header" style="padding: 15px 20px; display: flex; align-items: center; cursor: pointer; border-bottom: 1px solid #e2e8f0; background: #fff;">
							<span class="codesync-checker-step-icon" style="margin-right: 15px; color: #cbd5e1;"><i data-lucide="circle-dashed" class="codesync-icon"></i></span>
							<strong style="flex: 1;"><?php esc_html_e( 'Performance & Deprecated', 'codesync-manager-for-github' ); ?></strong>
							<i data-lucide="chevron-down" class="codesync-icon" style="color:#94a3b8;"></i>
						</div>
						<div class="codesync-checker-step-body" style="display: none; padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #475569;"></div>
					</li>
				</ul>
			</div>
		</script>
		<?php
	}

	/**
	 * Helper to render plugins table body rows.
	 */
	public static function render_plugins_cards() {
		$managed_plugins = get_option( CODESYNC_Manager::OPTION_PLUGINS, array() );
		$managed_themes  = get_option( CODESYNC_Manager::OPTION_THEMES, array() );
		$managed = array_merge( $managed_plugins, $managed_themes );
		if ( empty( $managed ) || ! is_array( $managed ) ) {
			?>
			<p class="codesync-no-plugins-msg"><?php esc_html_e( 'No packages managed yet. Go to the "Add Package" tab to get started.', 'codesync-manager-for-github' ); ?></p>
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
					<div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
						<span class="codesync-status-badge <?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( $status_label ); ?>
							<?php if ( 'erro' === $status && ! empty( $error_message ) ) : ?>
								<i data-lucide="help-circle" class="codesync-icon codesync-tooltip-trigger" title="<?php echo esc_attr( $error_message ); ?>"></i>
							<?php endif; ?>
						</span>
						<?php if ( get_option( 'codesync_webhook_ping_' . $repo ) ) : ?>
							<span style="display:inline-flex; align-items:center; background:#e5f6e8; color:#00a32a; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600;" title="<?php esc_attr_e( 'Receiving real-time updates from GitHub', 'codesync-manager-for-github' ); ?>">
								<i data-lucide="radio" style="width:12px;height:12px;margin-right:4px;"></i> <?php esc_html_e( 'Webhook Active', 'codesync-manager-for-github' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="codesync-plugin-versions">
					<span><?php esc_html_e( 'Installed:', 'codesync-manager-for-github' ); ?> <code><?php echo esc_html( $installed_version ); ?></code></span>
					<?php if ( $latest_version !== $installed_version ) : ?>
						<span class="codesync-versions-arrow">→</span>
						<span><?php esc_html_e( 'Available:', 'codesync-manager-for-github' ); ?> <code><?php echo esc_html( $latest_version ); ?></code></span>
					<?php endif; ?>
				</div>

				<div class="codesync-plugin-card-tags">
					<?php if ( isset( $data['theme_folder'] ) ) : ?>
						<span class="codesync-branch-label" style="background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;">
							<i data-lucide="layout-template" class="codesync-icon" style="width:12px;height:12px;margin-right:3px;"></i>
							<?php esc_html_e( 'Theme', 'codesync-manager-for-github' ); ?>
						</span>
					<?php else : ?>
						<span class="codesync-branch-label" style="background:#f3e8ff; color:#7e22ce; border:1px solid #e9d5ff;">
							<i data-lucide="plug" class="codesync-icon" style="width:12px;height:12px;margin-right:3px;"></i>
							<?php esc_html_e( 'Plugin', 'codesync-manager-for-github' ); ?>
						</span>
					<?php endif; ?>

					<?php if ( ! empty( $data['is_branch'] ) ) : ?>
						<span class="codesync-branch-label" title="<?php esc_attr_e( 'Instalado diretamente de uma branch, sem releases no GitHub.', 'codesync-manager-for-github' ); ?>">
							<?php
							/* translators: %s: branch name */
							printf( esc_html__( 'Ramo: %s', 'codesync-manager-for-github' ), esc_html( $data['branch_name'] ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $data['subfolder'] ) ) : ?>
						<span class="codesync-subfolder-label" title="<?php esc_attr_e( 'Pasta base configurada para este pacote.', 'codesync-manager-for-github' ); ?>">
							<?php
							/* translators: %s: subfolder path */
							printf( esc_html__( 'Pasta: %s', 'codesync-manager-for-github' ), esc_html( $data['subfolder'] ) );
							?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $last_checked ) ) : ?>
				<p class="codesync-plugin-last-checked">
					<i data-lucide="clock" class="codesync-icon"></i>
					<?php
					/* translators: %s: date and time */
					printf( esc_html__( 'Última verificação: %s', 'codesync-manager-for-github' ), esc_html( date_i18n( 'd/m/Y H:i', strtotime( $last_checked ) ) ) );
					?>
				</p>
				<?php endif; ?>

				<?php
				// Check if rollback is available
				$has_rollback = false;
				$backup_dir = CODESYNC_Manager::get_secure_directory( 'codesync-backups' );
				if ( ! is_wp_error( $backup_dir ) && is_dir( $backup_dir ) ) {
					$folder_to_check = dirname( $plugin_file );
					if ( '.' !== $folder_to_check && ! empty( $folder_to_check ) ) {
						$files = new DirectoryIterator( $backup_dir );
						foreach ( $files as $file ) {
							if ( $file->isDir() && preg_match( '/^' . preg_quote( $folder_to_check, '/' ) . '-\d{10}$/', $file->getFilename() ) ) {
								$has_rollback = true;
								break;
							}
						}
					}
				}
				?>
				<div class="codesync-plugin-card-actions">
					<button type="button" class="button button-primary codesync-btn-force-update" data-repo="<?php echo esc_attr( $repo ); ?>" <?php disabled( $status !== 'atualizacao_disponivel' ); ?>>
						<i data-lucide="cloud-upload" class="codesync-icon"></i>
						<?php esc_html_e( 'Update', 'codesync-manager-for-github' ); ?>
					</button>

					<button type="button" class="button codesync-btn-webhook-info" data-repo="<?php echo esc_attr( $repo ); ?>" data-active="<?php echo get_option( 'codesync_webhook_ping_' . $repo ) ? '1' : '0'; ?>">
						<i data-lucide="zap" class="codesync-icon"></i>
						Webhook
					</button>

					<?php if ( $has_rollback ) : ?>
					<button type="button" class="button codesync-btn-rollback" data-repo="<?php echo esc_attr( $repo ); ?>">
						<i data-lucide="rotate-ccw" class="codesync-icon"></i>
						<?php esc_html_e( 'Rollback', 'codesync-manager-for-github' ); ?>
					</button>
					<?php endif; ?>
					<button type="button" class="button codesync-btn-remove" data-repo="<?php echo esc_attr( $repo ); ?>">
						<i data-lucide="trash-2" class="codesync-icon"></i>
						<?php esc_html_e( 'Stop managing', 'codesync-manager-for-github' ); ?>
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
			<p class="codesync-no-logs-msg"><?php esc_html_e( 'No activity registered yet.', 'codesync-manager-for-github' ); ?></p>
			<?php
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped table-view-list codesync-logs-table">
			<thead>
				<tr>
					<th style="width: 160px;"><?php esc_html_e( 'Date/Time', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 200px;"><?php esc_html_e( 'Repository', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 140px;"><?php esc_html_e( 'Action', 'codesync-manager-for-github' ); ?></th>
					<th style="width: 110px;"><?php esc_html_e( 'Result', 'codesync-manager-for-github' ); ?></th>
					<th><?php esc_html_e( 'Message', 'codesync-manager-for-github' ); ?></th>
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
}
