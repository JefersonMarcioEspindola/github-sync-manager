/**
 * GitHub Sync Manager Administration Logic
 */
jQuery(document).ready(function($) {

	// Cached DOM elements
	var $connectForm        = $('#gsm-connect-form');
	var $connectSpinner     = $connectForm.find('.gsm-spinner');
	var $connectError       = $connectForm.find('.gsm-error-message');
	
	var $scanBtn            = $('#gsm-btn-scan-now');
	var $scanSpinner        = $('#gsm-scan-spinner');
	var $pluginsCards       = $('#gsm-plugins-cards');
	
	var $reposContainer     = $('#gsm-repos-container');
	var $reposSpinner       = $('#gsm-repos-spinner');
	var $searchField        = $('#gsm-repo-search');
	var $reloadReposBtn     = $('#gsm-btn-reload-repos');
	
	var $disconnectBtn      = $('#gsm-btn-disconnect');
	var $disconnectSpinner  = $('#gsm-disconnect-spinner');

	var cachedRepos         = []; // Store repositories lists for live filtering

	/* ---------------------------------------------------- */
	/* 1. Admin Tab Navigation                              */
	/* ---------------------------------------------------- */
	$('.gsm-tabs-nav a').on('click', function(e) {
		e.preventDefault();
		
		var targetTab = $(this).attr('href');
		
		// Nav active state
		$('.gsm-tabs-nav a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		
		// Tab content active state
		$('.gsm-tab-content').removeClass('gsm-tab-active');
		$(targetTab).addClass('gsm-tab-active');

		// If clicking the Add Tab, pull fresh list of repos
		if (targetTab === '#gsm-tab-add' && cachedRepos.length === 0) {
			loadGitHubRepositories();
		}
	});

	/* ---------------------------------------------------- */
	/* 2. Connect Account via PAT                           */
	/* ---------------------------------------------------- */
	$connectForm.on('submit', function(e) {
		e.preventDefault();
		
		var token = $('#gsm_pat_token').val().trim();
		if (!token) return;

		$connectSpinner.addClass('is-active');
		$connectError.hide().text('');
		$connectForm.find('button[type="submit"]').prop('disabled', true);

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_connect_account',
				nonce: gsm_ajax.nonce,
				token: token
			},
			success: function(response) {
				$connectSpinner.removeClass('is-active');
				if (response.success) {
					// Connection success: refresh page to render dashboard
					window.location.reload();
				} else {
					$connectError.text(response.data.message).fadeIn();
					$connectForm.find('button[type="submit"]').prop('disabled', false);
				}
			},
			error: function() {
				$connectSpinner.removeClass('is-active');
				$connectError.text(gsm_ajax.texts.req_failed).fadeIn();
				$connectForm.find('button[type="submit"]').prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 3. Disconnect Account                                */
	/* ---------------------------------------------------- */
	$disconnectBtn.on('click', function(e) {
		e.preventDefault();

		if (!confirm(gsm_ajax.texts.confirm_disconnect)) {
			return;
		}

		$disconnectSpinner.addClass('is-active');
		$disconnectBtn.prop('disabled', true);

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_disconnect_account',
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				$disconnectSpinner.removeClass('is-active');
				if (response.success) {
					window.location.reload();
				} else {
					alert(response.data.message);
					$disconnectBtn.prop('disabled', false);
				}
			},
			error: function() {
				$disconnectSpinner.removeClass('is-active');
				alert(gsm_ajax.texts.comm_fail);
				$disconnectBtn.prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 4. Load GitHub Repositories                          */
	/* ---------------------------------------------------- */
	function loadGitHubRepositories() {
		$reposSpinner.addClass('is-active');
		$reposContainer.html('<p class="description">' + gsm_ajax.texts.loading_repos + '</p>');
		$searchField.val('').prop('disabled', true);

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_add_plugin',
				action_type: 'list',
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				$reposSpinner.removeClass('is-active');
				$searchField.prop('disabled', false);

				if (response.success) {
					cachedRepos = response.data.repos;
					renderRepositories(cachedRepos);
				} else {
					$reposContainer.html('<p class="gsm-error-message">' + response.data.message + '</p>');
				}
			},
			error: function() {
				$reposSpinner.removeClass('is-active');
				$searchField.prop('disabled', false);
				$reposContainer.html('<p class="gsm-error-message">' + gsm_ajax.texts.repos_load_error + '</p>');
			}
		});
	}

	$reloadReposBtn.on('click', function(e) {
		e.preventDefault();
		loadGitHubRepositories();
	});

	// Render the list/grid of repositories
	function renderRepositories(repos) {
		if (repos.length === 0) {
			$reposContainer.html('<p class="description">' + gsm_ajax.texts.no_repos_found + '</p>');
			return;
		}

		var html = '';
		$.each(repos, function(idx, repo) {
			var visibilityClass = repo.private ? 'gsm-private' : 'gsm-public';
			var visibilityLabel = repo.private ? gsm_ajax.texts.private_lbl : gsm_ajax.texts.public_lbl;
			var lastUpdated = new Date(repo.updated_at).toLocaleDateString();

			var btnLabel = repo.is_managed ? gsm_ajax.texts.already_managed : gsm_ajax.texts.install_btn;
			var btnClass = repo.is_managed ? 'button-disabled' : 'button-primary gsm-btn-install';
			var btnAttr  = repo.is_managed ? 'disabled' : 'data-repo="' + repo.full_name + '"';

			var desc = repo.description ? repo.description : gsm_ajax.texts.no_desc;
			html += '<div class="gsm-repo-card" data-name="' + repo.name.toLowerCase() + '" data-fullname="' + repo.full_name.toLowerCase() + '">';
			html += '  <div class="gsm-repo-card-header">';
			html += '    <h3 class="gsm-repo-card-title">' + repo.name + '</h3>';
			html += '    <div class="gsm-repo-badges">';
			html += '      <span class="gsm-visibility-badge ' + visibilityClass + '">' + visibilityLabel + '</span>';
			html += '    </div>';
			html += '  </div>';
			html += '  <p class="gsm-repo-desc" title="' + desc + '">' + desc + '</p>';
			html += '  <div class="gsm-repo-footer">';
			html += '    <span class="gsm-repo-date">' + gsm_ajax.texts.updated_lbl.replace('%s', lastUpdated) + '</span>';
			html += '    <button type="button" class="button button-small ' + btnClass + '" ' + btnAttr + '>' + btnLabel + '</button>';
			html += '  </div>';
			html += '</div>';
		});

		$reposContainer.html(html);
	}

	/* ---------------------------------------------------- */
	/* 5. Live Search Filter                                */
	/* ---------------------------------------------------- */
	$searchField.on('input', function() {
		var query = $(this).val().toLowerCase().trim();
		if (!query) {
			$('.gsm-repo-card').show();
			return;
		}

		$('.gsm-repo-card').each(function() {
			var name     = $(this).data('name');
			var fullname = $(this).data('fullname');
			var desc     = $(this).find('.gsm-repo-desc').text().toLowerCase();

			if (name.indexOf(query) !== -1 || fullname.indexOf(query) !== -1 || desc.indexOf(query) !== -1) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 6. Programmatic Installation                         */
	/* ---------------------------------------------------- */
	var $modal = $('#gsm-install-modal');
	var $modalBody = $modal.find('.gsm-modal-body');
	var $modalFooter = $modal.find('.gsm-modal-footer');
	var installRepo = '';
	var installIsDone = false;

	function hideModal() {
		$modal.removeClass('gsm-modal-open');
		setTimeout(function() {
			$modal.hide();
			if (installIsDone) {
				window.location.reload();
			}
		}, 250);
	}

	$modal.find('.gsm-modal-close').on('click', function(e) {
		e.preventDefault();
		hideModal();
	});

	$modal.find('.gsm-modal-backdrop').on('click', function() {
		hideModal();
	});

	$modal.on('click', '.gsm-modal-btn-cancel', function(e) {
		e.preventDefault();
		hideModal();
	});

	$modal.on('click', '.gsm-modal-btn-close-done', function(e) {
		e.preventDefault();
		hideModal();
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape' && $modal.hasClass('gsm-modal-open')) {
			hideModal();
		}
	});

	function verifyRepo(repo, ref) {
		var $btnInstall = $modal.find('.gsm-modal-btn-install');
		$btnInstall.prop('disabled', true);
		
		var isSwitching = !!ref;
		if (isSwitching) {
			$modalBody.find('select, input, button').prop('disabled', true);
			$modalBody.find('.gsm-modal-options').css('opacity', '0.5');
		} else {
			$modalBody.html(
				'<div class="gsm-modal-loading">' +
				'  <div class="gsm-modal-spinner"></div>' +
				'  <p>' + gsm_ajax.texts.checking_repo + '</p>' +
				'</div>'
			);
		}

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_verify_repo',
				repo: repo,
				ref: ref || '',
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					var data = response.data;
					var bodyHtml = '';

					if (data.found) {
						var bannerText = gsm_ajax.texts.plugin_detected
							.replace('%1$s', data.plugin_name)
							.replace('%2$s', data.version);
						bodyHtml += '<div class="gsm-modal-success-banner">';
						bodyHtml += '  <span class="dashicons dashicons-yes-alt"></span>';
						bodyHtml += '  <p>' + bannerText + '</p>';
						bodyHtml += '</div>';
					} else {
						bodyHtml += '<div class="gsm-modal-warning-banner">';
						bodyHtml += '  <span class="dashicons dashicons-warning"></span>';
						bodyHtml += '  <p>' + gsm_ajax.texts.plugin_not_detected + '</p>';
						bodyHtml += '</div>';
					}

					var optionsVisible = !data.found;
					if (isSwitching) {
						optionsVisible = $modalBody.find('.gsm-modal-options').is(':visible');
					}

					bodyHtml += '<div class="gsm-modal-advanced-toggle">';
					bodyHtml += '  <a href="#" class="gsm-toggle-advanced-link">';
					bodyHtml += '    ' + gsm_ajax.texts.advanced_options + ' ';
					bodyHtml += '    <span class="dashicons dashicons-arrow-' + (optionsVisible ? 'up' : 'down') + '-alt2"></span>';
					bodyHtml += '  </a>';
					bodyHtml += '</div>';

					bodyHtml += '<div class="gsm-modal-options" style="' + (optionsVisible ? '' : 'display: none;') + '">';
					
					// ── Source select (origin) ──────────────────────────────
					bodyHtml += '  <div class="gsm-modal-field">';
					bodyHtml += '    <label for="gsm-modal-ref">' + gsm_ajax.texts.select_source + '</label>';
					bodyHtml += '    <select id="gsm-modal-ref">';
					if (data.sources && data.sources.length > 0) {
						$.each(data.sources, function(idx, src) {
							var isSelected = (ref && src.ref === ref) || (!ref && (src.ref === data.default_branch || idx === 0));
							var selectedAttr = isSelected ? ' selected' : '';
							var prefix = src.is_branch ? '🌿 ' : '🏷️ ';
							bodyHtml += '<option value="' + src.ref + '"' + selectedAttr + '>' + prefix + src.name + '</option>';
						});
					} else {
						bodyHtml += '<option value="' + data.default_branch + '" selected>🌿 ' + data.default_branch + '</option>';
					}
					bodyHtml += '    </select>';
					bodyHtml += '  </div>';

					// ── Custom Folder Tree Picker ────────────────────────────
					var folders   = data.folders || [];
					var defPath   = data.default_path || '';

					bodyHtml += '  <div class="gsm-modal-field">';
					bodyHtml += '    <label>' + gsm_ajax.texts.select_folder + '</label>';
					bodyHtml += '    <input type="hidden" id="gsm-modal-subfolder" value="' + defPath + '">';

					// Trigger button (shows current selection)
					var triggerLabel = defPath ? ('📁 ' + defPath) : ('📁 ' + gsm_ajax.texts.root_folder);
					bodyHtml += '    <div class="gsm-folder-picker">';
					bodyHtml += '      <button type="button" class="gsm-folder-trigger" aria-expanded="false">';
					bodyHtml += '        <span class="gsm-folder-trigger-label">' + triggerLabel + '</span>';
					bodyHtml += '        <span class="gsm-folder-trigger-chevron dashicons dashicons-arrow-down-alt2"></span>';
					bodyHtml += '      </button>';

					var treeItemsHtml = '';

					// Root option
					var rootSel = (defPath === '') ? ' gsm-folder-item--selected' : '';
					treeItemsHtml += '<li class="gsm-folder-item gsm-folder-item--root' + rootSel + '" data-value="">';
					treeItemsHtml += '  <span class="gsm-fi-icon">📂</span>';
					treeItemsHtml += '  <span class="gsm-fi-name">' + gsm_ajax.texts.root_folder + '</span>';
					if (defPath === '') { treeItemsHtml += '<span class="gsm-fi-check dashicons dashicons-yes"></span>'; }
					treeItemsHtml += '</li>';

					// Build tree from flat paths
					var tree = {};
					$.each(folders, function(i, path) {
						var parts = path.split('/');
						var node = tree;
						$.each(parts, function(j, part) {
							if (!node[part]) { node[part] = { __path: parts.slice(0, j+1).join('/'), __children: {} }; }
							node = node[part].__children;
						});
					});

					function renderTree(node, depth) {
						var html = '';
						$.each(node, function(name, obj) {
							var path = obj.__path;
							var indent = depth;
							var isSel = (defPath === path) ? ' gsm-folder-item--selected' : '';
							var checkIcon = (defPath === path) ? '<span class="gsm-fi-check dashicons dashicons-yes"></span>' : '';
							html += '<li class="gsm-folder-item' + isSel + '" data-value="' + path + '" data-depth="' + indent + '">';
							for (var d = 0; d < indent; d++) {
								html += '<span class="gsm-fi-indent"></span>';
							}
							html += '<span class="gsm-fi-connector"></span>';
							html += '<span class="gsm-fi-icon">📁</span>';
							html += '<span class="gsm-fi-name">' + name + '</span>';
							html += checkIcon;
							html += '</li>';
							html += renderTree(obj.__children, depth + 1);
						});
						return html;
					}

					treeItemsHtml += renderTree(tree, 0);

					bodyHtml += '      <div class="gsm-folder-dropdown" style="display:none;">';
					bodyHtml += '        <ul class="gsm-folder-tree">' + treeItemsHtml + '</ul>';
					bodyHtml += '      </div>';
					bodyHtml += '    </div>'; // .gsm-folder-picker


					// Info box
					bodyHtml += '    <div class="gsm-info-box">';
					bodyHtml += '      <span class="dashicons dashicons-info"></span>';
					bodyHtml += '      <p class="gsm-field-description">' + gsm_ajax.texts.select_folder_desc + '</p>';
					bodyHtml += '    </div>';
					bodyHtml += '  </div>';

					bodyHtml += '</div>'; // .gsm-modal-options

					$modalBody.html(bodyHtml);
					$modalFooter.find('.gsm-modal-btn-install').prop('disabled', false);

					// ── Toggle advanced panel ──────────────────────────────
					$modalBody.find('.gsm-toggle-advanced-link').on('click', function(e) {
						e.preventDefault();
						var $link = $(this);
						var $optionsPanel = $modalBody.find('.gsm-modal-options');
						var $icon = $link.find('.dashicons');

						$optionsPanel.slideToggle(200, function() {
							if ($optionsPanel.is(':visible')) {
								$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
							} else {
								$icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
							}
						});
					});

					// ── Origin select change → reload verify ───────────────
					$modalBody.find('#gsm-modal-ref').on('change', function() {
						var selectedRef = $(this).val();
						$('.gsm-folder-dropdown').hide();
						$('.gsm-folder-trigger').attr('aria-expanded', 'false')
							.find('.gsm-folder-trigger-chevron').removeClass('gsm-chevron-up');
						verifyRepo(repo, selectedRef);
					});

				} else {
					$modalBody.html('<div class="gsm-error-message">' + response.data.message + '</div>');
				}
			},
			error: function() {
				$modalBody.html('<div class="gsm-error-message">' + gsm_ajax.texts.scan_fail + '</div>');
			}
		});
	}

	// Close inline dropdown when clicking outside the picker
	$(document).on('click.gsmFolderClose', function(e) {
		if (!$(e.target).closest('.gsm-folder-picker').length) {
			$('.gsm-folder-dropdown').hide();
			$('.gsm-folder-trigger').attr('aria-expanded', 'false')
				.find('.gsm-folder-trigger-chevron').removeClass('gsm-chevron-up');
		}
	});

	// Toggle inline dropdown on trigger click
	$modal.on('click', '.gsm-folder-trigger', function(e) {
		e.stopPropagation();
		var $trigger  = $(this);
		var $picker   = $trigger.closest('.gsm-folder-picker');
		var $dropdown = $picker.find('.gsm-folder-dropdown');
		var isOpen    = $trigger.attr('aria-expanded') === 'true';

		if (isOpen) {
			$dropdown.hide();
			$trigger.attr('aria-expanded', 'false')
				.find('.gsm-folder-trigger-chevron').removeClass('gsm-chevron-up');
		} else {
			$dropdown.show();
			$trigger.attr('aria-expanded', 'true')
				.find('.gsm-folder-trigger-chevron').addClass('gsm-chevron-up');
		}
	});

	// Select folder item from inline dropdown
	$modal.on('click', '.gsm-folder-item', function(e) {
		e.stopPropagation();
		var $item    = $(this);
		var newValue = $item.data('value');
		var $picker  = $item.closest('.gsm-folder-picker');
		var $trigger = $picker.find('.gsm-folder-trigger');

		$modalBody.find('#gsm-modal-subfolder').val(newValue);

		$picker.find('.gsm-folder-item').removeClass('gsm-folder-item--selected')
			.find('.gsm-fi-check').remove();
		$item.addClass('gsm-folder-item--selected')
			.append('<span class="gsm-fi-check dashicons dashicons-yes"></span>');

		var label = newValue ? ('📁 ' + newValue) : ('📁 ' + gsm_ajax.texts.root_folder);
		$trigger.find('.gsm-folder-trigger-label').text(label);

		$picker.find('.gsm-folder-dropdown').hide();
		$trigger.attr('aria-expanded', 'false')
			.find('.gsm-folder-trigger-chevron').removeClass('gsm-chevron-up');
	});

	// Close dropdown when modal closes
	$modal.on('click', '.gsm-modal-close, .gsm-modal-btn-cancel, .gsm-modal-btn-close-done', function() {
		$('.gsm-folder-dropdown').hide();
		$('.gsm-folder-trigger').attr('aria-expanded', 'false')
			.find('.gsm-folder-trigger-chevron').removeClass('gsm-chevron-up');
	});


	$reposContainer.on('click', '.gsm-btn-install', function(e) {
		e.preventDefault();
		
		var repo = $(this).data('repo');
		if (!repo) return;

		installRepo = repo;
		installIsDone = false;

		$modalFooter.html(
			'<button type="button" class="button gsm-modal-btn-cancel">' + gsm_ajax.texts.close_btn + '</button>' +
			'<button type="button" class="button button-primary gsm-modal-btn-install" disabled>' + gsm_ajax.texts.install_btn + '</button>'
		);

		$modal.show();
		$modal[0].offsetHeight; // Trigger reflow for transition
		$modal.addClass('gsm-modal-open');

		verifyRepo(repo);
	});

	$modal.on('click', '.gsm-modal-btn-install', function(e) {
		e.preventDefault();
		
		var $btn = $(this);
		$btn.prop('disabled', true);
		$modal.find('.gsm-modal-btn-cancel').prop('disabled', true);
		$modal.find('.gsm-modal-close').css('pointer-events', 'none');

		var selectedRef = $('#gsm-modal-ref').val() || '';
		var selectedSubfolder = $('#gsm-modal-subfolder').val() || '';

		$modalBody.html(
			'<div class="gsm-modal-installing">' +
			'  <div class="gsm-modal-spinner"></div>' +
			'  <p>' + gsm_ajax.texts.installing + '</p>' +
			'</div>'
		);

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_add_plugin',
				action_type: 'install',
				repo: installRepo,
				ref: selectedRef,
				subfolder: selectedSubfolder,
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				$modal.find('.gsm-modal-close').css('pointer-events', 'auto');
				if (response.success) {
					installIsDone = true;
					cachedRepos = [];

					var doneHtml = '';
					doneHtml += '<div class="gsm-modal-done">';
					doneHtml += '  <div class="gsm-modal-done-icon">✓</div>';
					doneHtml += '  <h4>' + gsm_ajax.texts.install_success_title + '</h4>';
					doneHtml += '  <p>' + gsm_ajax.texts.install_success_msg.replace('%1$s', response.data.plugin_name).replace('%2$s', response.data.version) + '</p>';
					doneHtml += '  <p><a href="' + response.data.activate_url + '" class="button button-primary button-hero gsm-modal-btn-activate">' + gsm_ajax.texts.activate_btn + '</a></p>';
					doneHtml += '</div>';

					$modalBody.html(doneHtml);
					$modalFooter.html('<button type="button" class="button gsm-modal-btn-close-done">' + gsm_ajax.texts.close_btn + '</button>');
				} else {
					$modalBody.html('<div class="gsm-error-message">' + gsm_ajax.texts.install_error.replace('%s', response.data.message) + '</div>');
					
					$modalFooter.html(
						'<button type="button" class="button gsm-modal-btn-cancel">' + gsm_ajax.texts.close_btn + '</button>' +
						'<button type="button" class="button button-primary gsm-modal-btn-install">' + gsm_ajax.texts.install_btn + '</button>'
					);
				}
			},
			error: function() {
				$modal.find('.gsm-modal-close').css('pointer-events', 'auto');
				$modalBody.html('<div class="gsm-error-message">' + gsm_ajax.texts.install_fail + '</div>');
				
				$modalFooter.html(
					'<button type="button" class="button gsm-modal-btn-cancel">' + gsm_ajax.texts.close_btn + '</button>' +
					'<button type="button" class="button button-primary gsm-modal-btn-install">' + gsm_ajax.texts.install_btn + '</button>'
				);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 7. Stop Managing Plugin                             */
	/* ---------------------------------------------------- */
	$pluginsCards.on('click', '.gsm-btn-remove', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var repo = $btn.data('repo');
		if (!repo) return;

		if (!confirm(gsm_ajax.texts.confirm_stop)) {
			return;
		}

		$btn.prop('disabled', true);
		var $card = $btn.closest('.gsm-plugin-card');

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_remove_plugin',
				repo: repo,
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					$card.fadeOut(300, function() {
						$(this).remove();
						if ($pluginsCards.find('.gsm-plugin-card').length === 0) {
							$pluginsCards.html('<p class="gsm-no-plugins-msg">' + gsm_ajax.texts.no_managed + '</p>');
						}
					});

					// Clear repo cache list
					cachedRepos = [];
				} else {
					alert(response.data.message);
					$btn.prop('disabled', false);
				}
			},
			error: function() {
				alert(gsm_ajax.texts.remove_error);
				$btn.prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 8. Manual Scan for Updates                           */
	/* ---------------------------------------------------- */
	$scanBtn.on('click', function(e) {
		e.preventDefault();

		$scanBtn.prop('disabled', true).addClass('updating');
		$scanSpinner.addClass('is-active');

		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_check_updates',
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				$scanBtn.prop('disabled', false).removeClass('updating');
				$scanSpinner.removeClass('is-active');

				if (response.success) {
					// Update plugins cards HTML
					if (response.data.table_html) {
						$pluginsCards.html(response.data.table_html);
					}
					// Update logs tab content
					if (response.data.logs_html) {
						$('#gsm-logs-table-wrapper').html(response.data.logs_html);
					}
					
					alert(response.data.message);
				} else {
					alert(gsm_ajax.texts.scan_error.replace('%s', response.data.message));
				}
			},
			error: function() {
				$scanBtn.prop('disabled', false).removeClass('updating');
				$scanSpinner.removeClass('is-active');
				alert(gsm_ajax.texts.scan_fail);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 9. Copy AI Release Prompt                            */
	/* ---------------------------------------------------- */
	$(document).on('click', '.gsm-btn-copy-prompt', function(e) {
		e.preventDefault();

		var repo = $(this).data('repo');
		var version = $(this).data('version') || '1.0.0';
		if (!repo) return;

		var promptText = gsm_ajax.texts.confirm_prompt.replace('%s', repo).replace('%s', version);

		// Copy to clipboard
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(promptText).then(function() {
				alert(gsm_ajax.texts.prompt_copied);
			}, function() {
				fallbackCopyTextToClipboard(promptText);
			});
		} else {
			fallbackCopyTextToClipboard(promptText);
		}
	});

	/* ---------------------------------------------------- */
	/* 10. Save Language Setting                            */
	/* ---------------------------------------------------- */
	$(document).on('change', '#gsm_locale', function() {
		var selectedLocale = $(this).val();
		var $select = $(this);
		$select.prop('disabled', true);
		
		$.ajax({
			url: gsm_ajax.url,
			type: 'POST',
			data: {
				action: 'gsm_save_locale',
				locale: selectedLocale,
				nonce: gsm_ajax.nonce
			},
			success: function(response) {
				$select.prop('disabled', false);
				if (response.success) {
					window.location.reload();
				} else {
					alert(response.data.message);
				}
			},
			error: function() {
				$select.prop('disabled', false);
				alert(gsm_ajax.texts.save_locale_error);
			}
		});
	});

	function fallbackCopyTextToClipboard(text) {
		var textArea = document.createElement("textarea");
		textArea.value = text;
		textArea.style.position = "fixed"; // Avoid scrolling to bottom
		document.body.appendChild(textArea);
		textArea.focus();
		textArea.select();

		try {
			var successful = document.execCommand('copy');
			if (successful) {
				alert(gsm_ajax.texts.prompt_copied);
			} else {
				alert(gsm_ajax.texts.prompt_copy_fail);
			}
		} catch (err) {
			alert(gsm_ajax.texts.prompt_copy_fail);
		}

		document.body.removeChild(textArea);
	}
});
