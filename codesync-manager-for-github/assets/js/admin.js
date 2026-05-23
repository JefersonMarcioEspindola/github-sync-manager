/**
 * GitHub Sync Manager Administration Logic
 */
jQuery(document).ready(function($) {

	// Cached DOM elements
	var $connectForm        = $('#codesync-connect-form');
	var $connectSpinner     = $connectForm.find('.codesync-spinner');
	var $connectError       = $connectForm.find('.codesync-error-message');
	
	var $scanBtn            = $('#codesync-btn-scan-now');
	var $scanSpinner        = $('#codesync-scan-spinner');
	var $pluginsCards       = $('#codesync-plugins-cards');
	
	var $reposContainer     = $('#codesync-repos-container');
	var $reposSpinner       = $('#codesync-repos-spinner');
	var $searchField        = $('#codesync-repo-search');
	var $reloadReposBtn     = $('#codesync-btn-reload-repos');
	
	var $disconnectBtn      = $('#codesync-btn-disconnect');
	var $disconnectSpinner  = $('#codesync-disconnect-spinner');

	var cachedRepos         = []; // Store repositories lists for live filtering

	function toggleUpdateAllButton() {
		var updatesCount = $pluginsCards.find('.codesync-status-update').length;
		if (updatesCount > 1) {
			$('#codesync-btn-update-all').show();
		} else {
			$('#codesync-btn-update-all').hide();
		}
	}

	toggleUpdateAllButton();

	/* ---------------------------------------------------- */
	/* 1. Admin Tab Navigation                              */
	/* ---------------------------------------------------- */
	$('.codesync-tabs-nav a').on('click', function(e) {
		e.preventDefault();
		
		var targetTab = $(this).attr('href');
		
		// Nav active state
		$('.codesync-tabs-nav a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		
		// Tab content active state
		$('.codesync-tab-content').removeClass('codesync-tab-active');
		$(targetTab).addClass('codesync-tab-active');

		// If clicking the Add Tab, pull fresh list of repos
		if (targetTab === '#codesync-tab-add' && cachedRepos.length === 0) {
			loadGitHubRepositories();
		}
	});

	/* ---------------------------------------------------- */
	/* 2. Connect Account via PAT                           */
	/* ---------------------------------------------------- */
	$connectForm.on('submit', function(e) {
		e.preventDefault();
		
		var token = $('#codesync_pat_token').val().trim();
		if (!token) return;

		$connectSpinner.addClass('is-active');
		$connectError.hide().text('');
		$connectForm.find('button[type="submit"]').prop('disabled', true);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_connect_account',
				nonce: codesync_ajax.nonce,
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
				$connectError.text(codesync_ajax.texts.req_failed).fadeIn();
				$connectForm.find('button[type="submit"]').prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 3. Disconnect Account                                */
	/* ---------------------------------------------------- */
	$disconnectBtn.on('click', function(e) {
		e.preventDefault();

		if (!confirm(codesync_ajax.texts.confirm_disconnect)) {
			return;
		}

		$disconnectSpinner.addClass('is-active');
		$disconnectBtn.prop('disabled', true);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_disconnect_account',
				nonce: codesync_ajax.nonce
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
				alert(codesync_ajax.texts.comm_fail);
				$disconnectBtn.prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 4. Load GitHub Repositories                          */
	/* ---------------------------------------------------- */
	function loadGitHubRepositories(forceRefresh) {
		$reposSpinner.addClass('is-active');
		$reposContainer.html('<p class="description">' + codesync_ajax.texts.loading_repos + '</p>');
		$searchField.val('').prop('disabled', true);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_add_plugin',
				action_type: 'list',
				force_refresh: forceRefresh ? 1 : 0,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$reposSpinner.removeClass('is-active');
				$searchField.prop('disabled', false);

				if (response.success) {
					cachedRepos = response.data.repos;
					renderRepositories(cachedRepos);
					// If last updated is provided, we could show it, but for now we just cache
				} else {
					$reposContainer.html('<p class="codesync-error-message">' + response.data.message + '</p>');
				}
			},
			error: function() {
				$reposSpinner.removeClass('is-active');
				$searchField.prop('disabled', false);
				$reposContainer.html('<p class="codesync-error-message">' + codesync_ajax.texts.repos_load_error + '</p>');
			}
		});
	}

	$reloadReposBtn.on('click', function(e) {
		e.preventDefault();
		loadGitHubRepositories(true);
	});

	// Render the list/grid of repositories
	function renderRepositories(repos) {
		if (repos.length === 0) {
			$reposContainer.html('<p class="description">' + codesync_ajax.texts.no_repos_found + '</p>');
			return;
		}

		var html = '';
		$.each(repos, function(idx, repo) {
			var visibilityClass = repo.private ? 'codesync-private' : 'codesync-public';
			var visibilityLabel = repo.private ? codesync_ajax.texts.private_lbl : codesync_ajax.texts.public_lbl;
			var lastUpdated = new Date(repo.updated_at).toLocaleDateString();

			var btnLabel = repo.is_managed ? codesync_ajax.texts.already_managed : codesync_ajax.texts.install_btn;
			var btnClass = repo.is_managed ? 'button-disabled' : 'button-primary codesync-btn-install';
			var btnAttr  = repo.is_managed ? 'disabled' : 'data-repo="' + repo.full_name + '"';

			var desc = repo.description ? repo.description : codesync_ajax.texts.no_desc;
			html += '<div class="codesync-repo-card" data-name="' + repo.name.toLowerCase() + '" data-fullname="' + repo.full_name.toLowerCase() + '">';
			html += '  <div class="codesync-repo-card-header">';
			html += '    <h3 class="codesync-repo-card-title">' + repo.name + '</h3>';
			html += '    <div class="codesync-repo-badges">';
			html += '      <span class="codesync-visibility-badge ' + visibilityClass + '">' + visibilityLabel + '</span>';
			html += '    </div>';
			html += '  </div>';
			html += '  <p class="codesync-repo-desc" title="' + desc + '">' + desc + '</p>';
			html += '  <div class="codesync-repo-footer" style="display:flex; justify-content:space-between; align-items:center;">';
			html += '    <span class="codesync-repo-date">' + codesync_ajax.texts.updated_lbl.replace('%s', lastUpdated) + '</span>';
			html += '    <div>';
			html += '      <button type="button" class="button button-small ' + btnClass + '" ' + btnAttr + '>' + btnLabel + '</button>';
			html += '    </div>';
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
			$('.codesync-repo-card').show();
			return;
		}

		$('.codesync-repo-card').each(function() {
			var name     = $(this).data('name');
			var fullname = $(this).data('fullname');
			var desc     = $(this).find('.codesync-repo-desc').text().toLowerCase();

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
	var $modal = $('#codesync-install-modal');
	var $modalBody = $modal.find('.codesync-modal-body');
	var $modalFooter = $modal.find('.codesync-modal-footer');
	var installRepo = '';
	var installIsDone = false;
	var installIsUpdate = false;
	var originalFooterHtml = $modalFooter.html();

	function hideModal() {
		$modal.removeClass('codesync-modal-open');
		setTimeout(function() {
			$modal.hide();
			if (installIsDone) {
				window.location.reload();
			}
		}, 250);
	}

	$modal.find('.codesync-modal-close').on('click', function(e) {
		e.preventDefault();
		hideModal();
	});

	$modal.find('.codesync-modal-backdrop').on('click', function() {
		hideModal();
	});

	$modal.on('click', '.codesync-modal-btn-cancel', function(e) {
		e.preventDefault();
		hideModal();
	});

	$modal.on('click', '.codesync-modal-btn-close-done', function(e) {
		e.preventDefault();
		hideModal();
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape' && $modal.hasClass('codesync-modal-open')) {
			hideModal();
		}
	});

	function verifyRepo(repo, ref) {
		var $btnInstall = $modal.find('.codesync-modal-btn-install');
		$btnInstall.prop('disabled', true);
		
		var isSwitching = !!ref;
		if (isSwitching) {
			$modalBody.find('select, input, button').prop('disabled', true);
			$modalBody.find('.codesync-modal-options').css('opacity', '0.5');
		} else {
			$modalBody.html(
				'<div class="codesync-modal-loading">' +
				'  <div class="codesync-modal-spinner"></div>' +
				'  <p>' + codesync_ajax.texts.checking_repo + '</p>' +
				'</div>'
			);
		}

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_verify_repo',
				repo: repo,
				ref: ref || '',
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					var data = response.data;
					var bodyHtml = '';

					if (data.found) {
						var bannerText = codesync_ajax.texts.plugin_detected
							.replace('%1$s', data.plugin_name)
							.replace('%2$s', data.version);
						bodyHtml += '<div class="codesync-modal-success-banner">';
						bodyHtml += '  <i data-lucide="check-circle" class="codesync-icon"></i>';
						bodyHtml += '  <p>' + bannerText + '</p>';
						bodyHtml += '</div>';
					} else {
						bodyHtml += '<div class="codesync-modal-warning-banner">';
						bodyHtml += '  <i data-lucide="triangle-alert" class="codesync-icon"></i>';
						bodyHtml += '  <p>' + codesync_ajax.texts.plugin_not_detected + '</p>';
						bodyHtml += '</div>';
					}

					var optionsVisible = !data.found;
					installIsUpdate = data.found;
					if (isSwitching) {
						optionsVisible = $modalBody.find('.codesync-modal-options').is(':visible');
					}

					bodyHtml += '<div class="codesync-modal-advanced-toggle">';
					bodyHtml += '  <a href="#" class="codesync-toggle-advanced-link">';
					bodyHtml += '    ' + codesync_ajax.texts.advanced_options + ' ';
					bodyHtml += '    <i data-lucide="chevron-down" class="codesync-icon codesync-toggle-chevron' + (optionsVisible ? ' codesync-rotated' : '') + '"></i>';
					bodyHtml += '  </a>';
					bodyHtml += '</div>';

					bodyHtml += '<div class="codesync-modal-options" style="' + (optionsVisible ? '' : 'display: none;') + '">';
					
					var currentHasAssets = false;
					// ── Source select (origin) ──────────────────────────────
					bodyHtml += '  <div class="codesync-modal-field">';
					bodyHtml += '    <label for="codesync-modal-ref">' + codesync_ajax.texts.select_source + '</label>';
					bodyHtml += '    <select id="codesync-modal-ref">';
					if (data.sources && data.sources.length > 0) {
						$.each(data.sources, function(idx, src) {
							var isSelected = (ref && src.ref === ref) || (!ref && (src.ref === data.default_branch || idx === 0));
							if (isSelected) {
								currentHasAssets = src.has_assets;
							}
							var selectedAttr = isSelected ? ' selected' : '';
							var prefix = src.is_branch ? '🌿 ' : '🏷️ ';
							bodyHtml += '<option value="' + src.ref + '"' + selectedAttr + '>' + prefix + src.name + '</option>';
						});
					} else {
						bodyHtml += '<option value="' + data.default_branch + '" selected>🌿 ' + data.default_branch + '</option>';
					}
					bodyHtml += '    </select>';
					bodyHtml += '  </div>';

					// ── Package Type select ─────────────────────────────────
					if (currentHasAssets) {
						bodyHtml += '  <div class="codesync-modal-field">';
						bodyHtml += '    <label for="codesync-modal-package-type">' + (codesync_ajax.texts.package_type || 'Tipo de Pacote') + '</label>';
						bodyHtml += '    <select id="codesync-modal-package-type">';
						bodyHtml += '      <option value="asset">' + (codesync_ajax.texts.package_asset || 'Release Asset (Recomendado)') + '</option>';
						bodyHtml += '      <option value="source">' + (codesync_ajax.texts.package_source || 'Source Code (Código Puro)') + '</option>';
						bodyHtml += '    </select>';
						bodyHtml += '  </div>';
					} else {
						bodyHtml += '  <input type="hidden" id="codesync-modal-package-type" value="auto">';
					}

					// ── Custom Folder Tree Picker ────────────────────────────
					var folders   = data.folders || [];
					var defPath   = data.default_path || '';

					bodyHtml += '  <div class="codesync-modal-field">';
					bodyHtml += '    <label>' + codesync_ajax.texts.select_folder + '</label>';
					bodyHtml += '    <input type="hidden" id="codesync-modal-subfolder" value="' + defPath + '">';

					// Trigger button (shows current selection)
					var triggerLabel = defPath ? ('📁 ' + defPath) : ('📁 ' + codesync_ajax.texts.root_folder);
					bodyHtml += '    <div class="codesync-folder-picker">';
					bodyHtml += '      <button type="button" class="codesync-folder-trigger" aria-expanded="false">';
					bodyHtml += '        <span class="codesync-folder-trigger-label">' + triggerLabel + '</span>';
					bodyHtml += '        <i data-lucide="chevron-down" class="codesync-icon codesync-folder-trigger-chevron"></i>';
					bodyHtml += '      </button>';

					var treeItemsHtml = '';

					// Root option
					var rootSel = (defPath === '') ? ' codesync-folder-item--selected' : '';
					treeItemsHtml += '<li class="codesync-folder-item codesync-folder-item--root' + rootSel + '" data-value="">';
					treeItemsHtml += '  <span class="codesync-fi-icon">📂</span>';
					treeItemsHtml += '  <span class="codesync-fi-name">' + codesync_ajax.texts.root_folder + '</span>';
					if (defPath === '') { treeItemsHtml += '<i data-lucide="check" class="codesync-icon codesync-fi-check"></i>'; }
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
							var isSel = (defPath === path) ? ' codesync-folder-item--selected' : '';
							var checkIcon = (defPath === path) ? '<i data-lucide="check" class="codesync-icon codesync-fi-check"></i>' : '';
							html += '<li class="codesync-folder-item' + isSel + '" data-value="' + path + '" data-depth="' + indent + '">';
							for (var d = 0; d < indent; d++) {
								html += '<span class="codesync-fi-indent"></span>';
							}
							html += '<span class="codesync-fi-connector"></span>';
							html += '<span class="codesync-fi-icon">📁</span>';
							html += '<span class="codesync-fi-name">' + name + '</span>';
							html += checkIcon;
							html += '</li>';
							html += renderTree(obj.__children, depth + 1);
						});
						return html;
					}

					treeItemsHtml += renderTree(tree, 0);

					bodyHtml += '      <div class="codesync-folder-dropdown" style="display:none;">';
					bodyHtml += '        <ul class="codesync-folder-tree">' + treeItemsHtml + '</ul>';
					bodyHtml += '      </div>';
					bodyHtml += '    </div>'; // .codesync-folder-picker


					// Info box
					bodyHtml += '    <div class="codesync-info-box">';
					bodyHtml += '      <i data-lucide="info" class="codesync-icon"></i>';
					bodyHtml += '      <p class="codesync-field-description">' + codesync_ajax.texts.select_folder_desc + '</p>';
					bodyHtml += '    </div>';
					bodyHtml += '  </div>';

					bodyHtml += '</div>'; // .codesync-modal-options

					$modalBody.html(bodyHtml);
					lucide.createIcons({ nodes: [$modalBody[0]] });
					
					// ── CHECKER OPT-IN BUTTON ──────────────────────────────
					var checkerTmpl = $('#tmpl-codesync-checker-ui').html();
					if (checkerTmpl && data.found) {
						$modalFooter.find('.codesync-btn-confirm-install').show().prop('disabled', false);
						
						// Add a Check Plugin button if it doesn't exist
						if ($modalFooter.find('.codesync-btn-check-plugin').length === 0) {
							$('<button type="button" class="button codesync-btn-check-plugin">' + codesync_ajax.texts.check_plugin_optional + '</button>').appendTo($modalFooter.find('.codesync-modal-footer-left'));
						} else {
							$modalFooter.find('.codesync-btn-check-plugin').show();
						}

						// Bind click for the check plugin button
						$modalFooter.off('click', '.codesync-btn-check-plugin').on('click', '.codesync-btn-check-plugin', function() {
							$(this).hide(); // Hide the check button
							$modalFooter.find('.codesync-btn-confirm-install').hide(); // Hide install until check finishes
							$modal.find('.codesync-modal-title').text(codesync_ajax.texts.plugin_validation);
							$modalBody.children().hide();
							$modalBody.append(checkerTmpl);
							setTimeout(function() {
								runCheckerPipeline(repo);
							}, 100);
						});
					} else {
						// If plugin not found or no template, fallback to standard enable
						$modalFooter.find('.codesync-btn-check-plugin').hide();
						$modalFooter.find('.codesync-btn-confirm-install').show().prop('disabled', false);
					}

					// ── Toggle advanced panel ──────────────────────────────
					$modalBody.find('.codesync-toggle-advanced-link').on('click', function(e) {
						e.preventDefault();
						var $link = $(this);
						var $optionsPanel = $modalBody.find('.codesync-modal-options');
						var $icon = $link.find('.codesync-toggle-chevron');

						$optionsPanel.slideToggle(200, function() {
							$icon.toggleClass('codesync-rotated', $optionsPanel.is(':visible'));
						});
					});

					// ── Origin select change → reload verify ───────────────
					$modalBody.find('#codesync-modal-ref').on('change', function() {
						var selectedRef = $(this).val();
						$('.codesync-folder-dropdown').hide();
						$('.codesync-folder-trigger').attr('aria-expanded', 'false')
							.find('.codesync-folder-trigger-chevron').removeClass('codesync-chevron-up');
						verifyRepo(repo, selectedRef);
					});

				} else {
					$modalBody.html('<div class="codesync-error-message">' + response.data.message + '</div>');
				}
			},
			error: function() {
				$modalBody.html('<div class="codesync-error-message">' + codesync_ajax.texts.scan_fail + '</div>');
			}
		});
	}

	// Close inline dropdown when clicking outside the picker
	$(document).on('click.gsmFolderClose', function(e) {
		if (!$(e.target).closest('.codesync-folder-picker').length) {
			$('.codesync-folder-dropdown').hide();
			$('.codesync-folder-trigger').attr('aria-expanded', 'false')
				.find('.codesync-folder-trigger-chevron').removeClass('codesync-chevron-up');
		}
	});

	// Toggle inline dropdown on trigger click
	$modal.on('click', '.codesync-folder-trigger', function(e) {
		e.stopPropagation();
		var $trigger  = $(this);
		var $picker   = $trigger.closest('.codesync-folder-picker');
		var $dropdown = $picker.find('.codesync-folder-dropdown');
		var isOpen    = $trigger.attr('aria-expanded') === 'true';

		if (isOpen) {
			$dropdown.hide();
			$trigger.attr('aria-expanded', 'false')
				.find('.codesync-folder-trigger-chevron').removeClass('codesync-chevron-up');
		} else {
			$dropdown.show();
			$trigger.attr('aria-expanded', 'true')
				.find('.codesync-folder-trigger-chevron').addClass('codesync-chevron-up');
		}
	});

	// Select folder item from inline dropdown
	$modal.on('click', '.codesync-folder-item', function(e) {
		e.stopPropagation();
		var $item    = $(this);
		var newValue = $item.data('value');
		var $picker  = $item.closest('.codesync-folder-picker');
		var $trigger = $picker.find('.codesync-folder-trigger');

		$modalBody.find('#codesync-modal-subfolder').val(newValue);

		$picker.find('.codesync-folder-item').removeClass('codesync-folder-item--selected')
			.find('.codesync-fi-check').remove();
		$item.addClass('codesync-folder-item--selected')
			.append('<i data-lucide="check" class="codesync-icon codesync-fi-check"></i>');
		lucide.createIcons({ nodes: [$item[0]] });

		var label = newValue ? ('📁 ' + newValue) : ('📁 ' + codesync_ajax.texts.root_folder);
		$trigger.find('.codesync-folder-trigger-label').text(label);

		$picker.find('.codesync-folder-dropdown').hide();
		$trigger.attr('aria-expanded', 'false')
			.find('.codesync-folder-trigger-chevron').removeClass('codesync-chevron-up');
	});

	// Close dropdown when modal closes
	$modal.on('click', '.codesync-modal-close, .codesync-modal-btn-cancel, .codesync-modal-btn-close-done', function() {
		$('.codesync-folder-dropdown').hide();
		$('.codesync-folder-trigger').attr('aria-expanded', 'false')
			.find('.codesync-folder-trigger-chevron').removeClass('codesync-chevron-up');
	});


	$reposContainer.on('click', '.codesync-btn-install', function(e) {
		e.preventDefault();
		
		var repo = $(this).data('repo');
		if (!repo) return;

		installRepo = repo;
		installIsDone = false;

		$modalFooter.html(originalFooterHtml);
		$modal.find('.codesync-modal-title').text(codesync_ajax.texts.install_btn || 'Install Package');
		$modalFooter.find('.codesync-modal-btn-cancel').show().prop('disabled', false);
		$modalFooter.find('.codesync-btn-confirm-install, .codesync-btn-force-install, .codesync-btn-copy-md').hide();

		$modal.show();
		$modal[0].offsetHeight; // Trigger reflow for transition
		$modal.addClass('codesync-modal-open');

		verifyRepo(repo);
	});

	$modal.on('click', '.codesync-btn-confirm-install, .codesync-btn-force-install', function(e) {
		e.preventDefault();
		
		if ($(this).hasClass('codesync-btn-force-install')) {
			if (!confirm(codesync_ajax.texts.confirm_force_install)) {
				return;
			}
		}

		var $btn = $(this);
		$btn.prop('disabled', true);
		$modal.find('.codesync-modal-btn-cancel, .codesync-btn-force-install, .codesync-btn-confirm-install').prop('disabled', true);
		$modal.find('.codesync-modal-btn-cancel').prop('disabled', true);
		$modal.find('.codesync-modal-close').css('pointer-events', 'none');

		var selectedRef = $('#codesync-modal-ref').val() || '';
		var selectedSubfolder = $('#codesync-modal-subfolder').val() || '';
		var selectedPackageType = $('#codesync-modal-package-type').val() || 'auto';

		$modalBody.html(
			'<div class="codesync-modal-installing">' +
			'  <div class="codesync-modal-spinner"></div>' +
			'  <p>' + codesync_ajax.texts.installing + '</p>' +
			'</div>'
		);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_add_plugin',
				action_type: 'install',
				repo: installRepo,
				ref: selectedRef,
				subfolder: selectedSubfolder,
				package_type: selectedPackageType,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$modal.find('.codesync-modal-close').css('pointer-events', 'auto');
				if (response.success) {
					installIsDone = true;
					cachedRepos = [];

					var wasAlreadyInstalled = !!response.data.was_already_installed;
					var pkgName = response.data.plugin_name || '';
					var pkgVersion = response.data.version || '';

					var doneHtml = '';
					doneHtml += '<div class="codesync-modal-done">';
					doneHtml += '  <div class="codesync-modal-done-icon">✓</div>';
					if (wasAlreadyInstalled) {
						doneHtml += '  <h4>' + codesync_ajax.texts.sync_success_title + '</h4>';
						doneHtml += '  <p>' + codesync_ajax.texts.sync_success_msg.replace('%1$s', pkgName).replace('%2$s', pkgVersion) + '</p>';
					} else {
						doneHtml += '  <h4>' + codesync_ajax.texts.install_success_title + '</h4>';
						doneHtml += '  <p>' + codesync_ajax.texts.install_success_msg.replace('%1$s', pkgName).replace('%2$s', pkgVersion) + '</p>';
						doneHtml += '  <p style="margin-top:12px; padding:10px 14px; background:#fef3c7; border-left:3px solid #f59e0b; border-radius:4px; font-size:13px; color:#78350f;">' + codesync_ajax.texts.activation_required + '</p>';
					}
					doneHtml += '</div>';

					$modalBody.html(doneHtml);
					var footerHtml = '';
					if (response.data.activate_url) {
						footerHtml += '<a href="' + response.data.activate_url + '" class="button button-primary codesync-modal-btn-activate">' + codesync_ajax.texts.activate_btn + '</a>';
					}
					$modalFooter.html(footerHtml);
				} else {
					$modalBody.html('<div class="codesync-error-message">' + codesync_ajax.texts.install_error.replace('%s', response.data.message) + '</div>');
					
					$modalFooter.html(
						'<button type="button" class="button codesync-modal-btn-cancel">' + codesync_ajax.texts.close_btn + '</button>' +
						'<button type="button" class="button button-primary codesync-modal-btn-install">' + codesync_ajax.texts.install_btn + '</button>'
					);
				}
			},
			error: function() {
				$modal.find('.codesync-modal-close').css('pointer-events', 'auto');
				$modalBody.html('<div class="codesync-error-message">' + codesync_ajax.texts.install_fail + '</div>');
				
				$modalFooter.html(
					'<button type="button" class="button codesync-modal-btn-cancel">' + codesync_ajax.texts.close_btn + '</button>' +
					'<button type="button" class="button button-primary codesync-modal-btn-install">' + codesync_ajax.texts.install_btn + '</button>'
				);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 7. Stop Managing Plugin                             */
	/* ---------------------------------------------------- */
	$pluginsCards.on('click', '.codesync-btn-remove', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var repo = $btn.data('repo');
		if (!repo) return;

		if (!confirm(codesync_ajax.texts.confirm_stop)) {
			return;
		}

		$btn.prop('disabled', true);
		var $card = $btn.closest('.codesync-plugin-card');

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_remove_plugin',
				repo: repo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					$card.fadeOut(300, function() {
						$(this).remove();
						if ($pluginsCards.find('.codesync-plugin-card').length === 0) {
							$pluginsCards.html('<p class="codesync-no-plugins-msg">' + codesync_ajax.texts.no_managed + '</p>');
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
				alert(codesync_ajax.texts.remove_error);
				$btn.prop('disabled', false);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 7.5 Rollback Plugin                                  */
	/* ---------------------------------------------------- */
	$pluginsCards.on('click', '.codesync-btn-rollback', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var repo = $btn.data('repo');
		if (!repo) return;

		if (!confirm(codesync_ajax.texts.confirm_rollback || 'Tem certeza que deseja restaurar a versão anterior? O plugin será substituído.')) {
			return;
		}

		$btn.prop('disabled', true);
		var originalHtml = $btn.html();
		$btn.html('<i data-lucide="loader-2" class="codesync-icon codesync-spin"></i> ' + (codesync_ajax.texts.restoring || 'Restaurando...'));
		if (window.lucide) {
			window.lucide.createIcons();
		}

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_rollback',
				repo: repo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					alert(response.data.message);
					window.location.reload();
				} else {
					alert(response.data.message);
					$btn.prop('disabled', false).html(originalHtml);
					if (window.lucide) { window.lucide.createIcons(); }
				}
			},
			error: function() {
				alert(codesync_ajax.texts.error_generic || 'Erro ao realizar o rollback.');
				$btn.prop('disabled', false).html(originalHtml);
				if (window.lucide) { window.lucide.createIcons(); }
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 7.6 Bulk Update All Plugins                          */
	/* ---------------------------------------------------- */
	$('#codesync-btn-update-all').on('click', function(e) {
		e.preventDefault();
		
		// Find all update buttons in cards that have 'codesync-status-update' badge
		var $updates = $pluginsCards.find('.codesync-status-update').closest('.codesync-plugin-card').find('.codesync-btn-force-update');
		if ($updates.length === 0) {
			alert(codesync_ajax.texts.no_updates_available || 'Nenhuma atualização disponível no momento.');
			return;
		}

		if (!confirm((codesync_ajax.texts.confirm_update_all || 'Deseja atualizar %d plugin(s) agora?').replace('%d', $updates.length))) {
			return;
		}

		var $btnAll = $(this);
		$btnAll.prop('disabled', true);
		var originalHtml = $btnAll.html();
		$btnAll.html('<i data-lucide="loader-2" class="codesync-icon codesync-spin"></i> ' + (codesync_ajax.texts.updating_all || 'Atualizando todos...'));
		if (window.lucide) { window.lucide.createIcons(); }

		// Trigger sequentially
		var index = 0;
		function triggerNext() {
			if (index >= $updates.length) {
				$btnAll.html('<i data-lucide="check" class="codesync-icon"></i> ' + (codesync_ajax.texts.updates_finished || 'Concluído'));
				if (window.lucide) { window.lucide.createIcons(); }
				setTimeout(function() {
					window.location.reload();
				}, 1500);
				return;
			}
			var $btn = $($updates[index]);
			$btn.trigger('click');
			
			// Wait a bit, then proceed to the next (assuming the click handles its own AJAX async, we just space them out)
			// A better approach is to wait for the ajax request, but since we trigger a click, we just delay the next click.
			setTimeout(triggerNext, 3000);
			index++;
		}
		
		triggerNext();
	});

	/* ---------------------------------------------------- */
	/* 8. Force Update Plugin                               */
	/* ---------------------------------------------------- */
	$pluginsCards.on('click', '.codesync-btn-force-update', function(e) {
		e.preventDefault();

		var $btn  = $(this);
		var repo  = $btn.data('repo');
		if (!repo) return;

		function performSingleUpdate(repoSlug, ignorePhp) {
			$.ajax({
				url: codesync_ajax.url,
				type: 'POST',
				data: {
					action: 'codesync_force_update',
					repo: repoSlug,
					nonce: codesync_ajax.nonce,
					ignore_php_check: ignorePhp ? 1 : 0
				},
				success: function(response) {
					if (!response.success && response.data && response.data.code === 'codesync_php_version_mismatch_manual') {
						if (confirm(response.data.message)) {
							performSingleUpdate(repoSlug, true);
						} else {
							$btn.prop('disabled', false).html(origLabel);
						}
						return;
					}

					$btn.prop('disabled', false).html(origLabel);
					if (response.success) {
						if (response.data.table_html) {
							$pluginsCards.html(response.data.table_html);
							lucide.createIcons({ nodes: [$pluginsCards[0]] });
							toggleUpdateAllButton();
						}
						if (response.data.logs_html) {
							$('#codesync-logs-table-wrapper').html(response.data.logs_html);
						}
						alert(response.data.message);
					} else {
						alert(codesync_ajax.texts.force_update_err.replace('%s', response.data.message));
					}
				},
				error: function() {
					$btn.prop('disabled', false).html(origLabel);
					alert(codesync_ajax.texts.force_update_fail);
				}
			});
		}

		// If triggered programmatically (e.g. by Update All), bypass modal and check
		if (!e.originalEvent) {
			var origLabel = $btn.html();
			$btn.prop('disabled', true).html('<i data-lucide="loader-circle" class="codesync-icon codesync-spin"></i> ' + codesync_ajax.texts.force_updating);
			performSingleUpdate(repo, false);
			return;
		}

		// Otherwise, it's a manual click: open the modal
		installRepo = repo;
		installIsDone = false;

		$modalFooter.html(originalFooterHtml);
		$modal.find('.codesync-modal-title').text(codesync_ajax.texts.update_package);
		$modalFooter.find('.codesync-modal-btn-cancel').show().prop('disabled', false);
		$modalFooter.find('.codesync-btn-confirm-install, .codesync-btn-force-install, .codesync-btn-copy-md, .codesync-btn-check-plugin').hide();

		$modal.show();
		$modal[0].offsetHeight; // Trigger reflow for transition
		$modal.addClass('codesync-modal-open');

		verifyRepo(repo);
	});

	/* ---------------------------------------------------- */
	/* 9. Manual Scan for Updates                           */
	/* ---------------------------------------------------- */
	$scanBtn.on('click', function(e) {
		e.preventDefault();

		$scanBtn.prop('disabled', true).addClass('updating');
		$scanSpinner.addClass('is-active');

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_check_updates',
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$scanBtn.prop('disabled', false).removeClass('updating');
				$scanSpinner.removeClass('is-active');

				if (response.success) {
					// Update plugins cards HTML
					if (response.data.table_html) {
						$pluginsCards.html(response.data.table_html);
						lucide.createIcons({ nodes: [$pluginsCards[0]] });
						toggleUpdateAllButton();
					}
					// Update logs tab content
					if (response.data.logs_html) {
						$('#codesync-logs-table-wrapper').html(response.data.logs_html);
					}

					alert(response.data.message);
				} else {
					alert(codesync_ajax.texts.scan_error.replace('%s', response.data.message));
				}
			},
			error: function() {
				$scanBtn.prop('disabled', false).removeClass('updating');
				$scanSpinner.removeClass('is-active');
				alert(codesync_ajax.texts.scan_fail);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 9. Save Language Setting                            */
	/* ---------------------------------------------------- */
	$(document).on('change', '#codesync_locale', function() {
		var selectedLocale = $(this).val();
		var $select = $(this);
		$select.prop('disabled', true);
		
		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_save_locale',
				locale: selectedLocale,
				nonce: codesync_ajax.nonce
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
				alert(codesync_ajax.texts.save_locale_error);
			}
		});
	});

	/* ---------------------------------------------------- */
	/* 11. Webhook Modal & Utilities                        */
	/* ---------------------------------------------------- */
	var $webhookModal = $('#codesync-webhook-modal');
	var currentWebhookRepo = '';

	function hideWebhookModal() {
		$webhookModal.removeClass('codesync-modal-open');
		setTimeout(function() {
			$webhookModal.hide();
			$('#codesync-btn-verify-webhook').prop('disabled', true).text(codesync_ajax.texts.verify_webhook_btn).show().css({
				'background-color': '', 'border-color': '', 'color': ''
			});
		}, 250);
	}

	$pluginsCards.on('click', '.codesync-btn-webhook-info', function(e) {
		e.preventDefault();
		var repo = $(this).data('repo');
		var isActive = $(this).data('active') == '1'; // true if ping already received
		currentWebhookRepo = repo || '';

		if (repo) {
			$('#codesync-webhook-direct-link').attr('href', 'https://github.com/' + repo + '/settings/hooks');
		} else {
			$('#codesync-webhook-direct-link').attr('href', 'https://github.com/settings/hooks');
		}

		// Reset modal state
		$('#codesync-webhook-modal-title').text(isActive ? codesync_ajax.texts.webhook_active_title : codesync_ajax.texts.webhook_config_title);
		$('#codesync-btn-verify-webhook').prop('disabled', true).text(codesync_ajax.texts.verify_webhook_btn).css({
			'background-color': '', 'border-color': '', 'color': ''
		});

		if (isActive) {
			// Show active view, hide setup view
			$('#codesync-webhook-active-view').show();
			$('#codesync-webhook-setup-view').hide();
			$('#codesync-btn-verify-webhook').hide();
			$('#codesync-btn-disconnect-webhook').css('display', 'inline-flex');
			// Load logs
			loadWebhookLogs(repo);
		} else {
			// Show setup view, hide active view
			$('#codesync-webhook-active-view').hide();
			$('#codesync-webhook-setup-view').show();
			$('#codesync-btn-verify-webhook').show();
			$('#codesync-btn-disconnect-webhook').hide();
		}

		$webhookModal.show();
		setTimeout(function() {
			$webhookModal.addClass('codesync-modal-open');
		}, 10);
	});

	// Load webhook logs for current repo
	function loadWebhookLogs(repo) {
		var $list = $('#codesync-webhook-logs-list');
		var $pingInfo = $('#codesync-webhook-ping-info');
		$list.html('<div style="padding:20px; text-align:center; color:#94a3b8; font-size:13px;"><i data-lucide="loader-2" class="codesync-icon codesync-spin" style="width:16px;height:16px;"></i></div>');
		if (window.lucide) { window.lucide.createIcons(); }

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_get_webhook_logs',
				repo: repo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (!response.success) {
					$list.html('<p style="padding:16px; color:#94a3b8; font-size:13px; margin:0; text-align:center;">' + codesync_ajax.texts.no_logs_yet + '</p>');
					return;
				}
				var data = response.data;

				// Update ping info line
				if (data.ping_time) {
					$pingInfo.text(codesync_ajax.texts.connected_since_fmt.replace('%s', data.ping_time));
				}

				// Render logs
				if (!data.logs || data.logs.length === 0) {
					$list.html('<p style="padding:16px; color:#94a3b8; font-size:13px; margin:0; text-align:center;">' + codesync_ajax.texts.no_logs_yet + '</p>');
					return;
				}

				var html = '';
				$.each(data.logs, function(i, log) {
					var isSuccess = (log.result === 'sucesso');
					var dotColor  = isSuccess ? '#10b981' : '#ef4444';
					var resultLabel = isSuccess ? 'OK' : 'ERR';
					var borderTop = i > 0 ? 'border-top:1px solid #e2e8f0;' : '';

					// Translate action
					var actionMap = {
						'sistema': 'System', 'atualizacao': 'Update', 'instalacao': 'Install',
						'ativacao': 'Activation', 'cron_check': 'Cron', 'conexao': 'Connect',
						'desconexao': 'Disconnect', 'restauracao': 'Rollback'
					};
					var action = actionMap[log.action] || log.action.toUpperCase();

					html += '<div style="display:flex; align-items:flex-start; gap:10px; padding:10px 12px; ' + borderTop + '">';
					html += '  <span style="flex-shrink:0; margin-top:3px; width:8px; height:8px; border-radius:9999px; background:' + dotColor + '; display:inline-block;"></span>';
					html += '  <div style="flex:1; min-width:0;">';
					html += '    <p style="margin:0; font-size:12px; color:#1e293b; line-height:1.4; word-break:break-word;">' + $('<div>').text(log.message).html() + '</p>';
					html += '    <p style="margin:2px 0 0; font-size:11px; color:#94a3b8;">' + log.timestamp + ' &nbsp;·&nbsp; <strong>' + action + '</strong></p>';
					html += '  </div>';
					html += '  <span style="flex-shrink:0; font-size:10px; font-weight:700; padding:1px 6px; border-radius:4px; background:' + (isSuccess ? '#dcfce7' : '#fee2e2') + '; color:' + (isSuccess ? '#166534' : '#991b1b') + ';">' + resultLabel + '</span>';
					html += '</div>';
				});
				if (data.has_more) {
					html += '<p style="text-align:center; padding:8px; font-size:11px; color:#94a3b8; margin:0; border-top:1px solid #e2e8f0;">' + codesync_ajax.texts.logs_cap_notice + '</p>';
				}
				$list.html(html);
			},
			error: function() {
				$list.html('<p style="padding:16px; color:#ef4444; font-size:13px; margin:0; text-align:center;">' + codesync_ajax.texts.logs_load_fail + '</p>');
			}
		});
	}

	// "View configuration details" link inside active view
	$webhookModal.on('click', '#codesync-webhook-show-details', function(e) {
		e.preventDefault();
		$('#codesync-webhook-active-view').slideUp(200);
		$('#codesync-webhook-setup-view').slideDown(200);
		$('#codesync-webhook-modal-title').text(codesync_ajax.texts.webhook_config_title);
		$('#codesync-btn-verify-webhook').show().prop('disabled', false);
	});

	// Disconnect webhook button
	$webhookModal.on('click', '#codesync-btn-disconnect-webhook', function(e) {
		e.preventDefault();
		if (!currentWebhookRepo) return;
		if (!confirm(codesync_ajax.texts.confirm_webhook_disc.replace('%s', currentWebhookRepo))) {
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true).text(codesync_ajax.texts.disconnecting);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_disconnect_webhook',
				repo: currentWebhookRepo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					// Update the card: remove badge, reset data-active
					var $card = $pluginsCards.find('.codesync-plugin-card[data-repo="' + currentWebhookRepo + '"]');
					$card.find('.codesync-webhook-active-badge').remove();
					$card.find('.codesync-btn-webhook-info[data-repo="' + currentWebhookRepo + '"]').attr('data-active', '0');

					// Transition to setup view
					$('#codesync-webhook-active-view').slideUp(200, function() {
						$('#codesync-webhook-setup-view').slideDown(200);
					});
					$('#codesync-webhook-modal-title').text(codesync_ajax.texts.webhook_config_title);
					$('#codesync-btn-verify-webhook').show().prop('disabled', true);
					currentWebhookRepo = '';
				} else {
					alert(response.data.message);
					$btn.prop('disabled', false).html('<i data-lucide="unplug" style="width:13px;height:13px;"></i> ' + codesync_ajax.texts.disconnect_label);
					if (window.lucide) { window.lucide.createIcons({ nodes: [$btn[0]] }); }
				}
			},
			error: function() {
				alert(codesync_ajax.texts.comm_error_retry);
				$btn.prop('disabled', false).html('<i data-lucide="unplug" style="width:13px;height:13px;"></i> ' + codesync_ajax.texts.disconnect_label);
				if (window.lucide) { window.lucide.createIcons({ nodes: [$btn[0]] }); }
			}
		});
	});

	$webhookModal.find('.codesync-modal-close, .codesync-modal-backdrop, .codesync-modal-btn-cancel').on('click', function(e) {
		if ($(e.target).hasClass('codesync-modal-backdrop') || $(e.target).closest('button').length) {
			e.preventDefault();
			hideWebhookModal();
		}
	});

	$('.codesync-btn-copy').on('click', function() {
		var targetId = $(this).data('target');
		var $input = $(targetId);
		if ($input.length) {
			// Temporary change type to text to allow copy if it is password
			var wasPassword = $input.attr('type') === 'password';
			if (wasPassword) {
				$input.attr('type', 'text');
			}
			$input.select();
			document.execCommand('copy');
			if (wasPassword) {
				$input.attr('type', 'password');
			}
			
			// Visual feedback
			var $icon = $(this).find('i');
			var oldIcon = $icon.attr('data-lucide');
			$icon.attr('data-lucide', 'check').css('color', '#10b981');
			lucide.createIcons({ nodes: [this] });

			// Enable verify button when secret is copied
			if (targetId === '#codesync-webhook-secret') {
				$('#codesync-btn-verify-webhook').prop('disabled', false);
			}

			setTimeout(function() {
				$icon.attr('data-lucide', oldIcon).css('color', '');
				lucide.createIcons({ nodes: [this] });
			}.bind(this), 2000);
		}
	});

	// Verify Webhook Button
	$('#codesync-btn-verify-webhook').on('click', function() {
		if (!currentWebhookRepo) return;
		var $btn = $(this);
		
		$btn.prop('disabled', true).html('<i data-lucide="loader-2" class="codesync-icon codesync-spin"></i> ' + codesync_ajax.texts.checking);
		lucide.createIcons({ nodes: [$btn[0]] });

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_verify_webhook',
				repo: currentWebhookRepo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				if (response.success) {
					// Transition to active view
					$('#codesync-webhook-modal-title').text(codesync_ajax.texts.webhook_active_title);
					$('#codesync-webhook-setup-view').slideUp(300, function() {
						$('#codesync-webhook-active-view').slideDown(300);
					});
					$btn.hide();
					$('#codesync-btn-disconnect-webhook').css('display', 'inline-flex');

					// Update the webhook button on the card to mark as active
					$pluginsCards.find('.codesync-btn-webhook-info[data-repo="' + currentWebhookRepo + '"]').attr('data-active', '1');

					// Add "Webhook Active" badge to the card if not already present
					var $card = $pluginsCards.find('.codesync-plugin-card[data-repo="' + currentWebhookRepo + '"]');
					if ($card.length && !$card.find('.codesync-webhook-active-badge').length) {
						var badgeHtml = '<span class="codesync-webhook-active-badge" style="display:inline-flex; align-items:center; background:#e5f6e8; color:#00a32a; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600;">' +
							'<i data-lucide="radio" style="width:12px;height:12px;margin-right:4px;"></i> ' + codesync_ajax.texts.webhook_active_title +
							'</span>';
						$card.find('.codesync-status-badge').parent().append(badgeHtml);
						lucide.createIcons({ nodes: [$card[0]] });
					}
				} else {
					$btn.prop('disabled', false).html('<i data-lucide="alert-circle" class="codesync-icon"></i> ' + response.data.message).css({
						'background-color': '#f59e0b',
						'border-color': '#f59e0b',
						'color': '#fff'
					});
					lucide.createIcons({ nodes: [$btn[0]] });
					
					// Reset button style after a few seconds so they can try again
					setTimeout(function() {
						$btn.html(codesync_ajax.texts.verify_webhook_btn).css({
							'background-color': '',
							'border-color': '',
							'color': ''
						});
					}, 4000);
				}
			},
			error: function() {
				$btn.prop('disabled', false).html(codesync_ajax.texts.comm_error_retry);
				setTimeout(function() {
					$btn.html(codesync_ajax.texts.verify_webhook_btn);
				}, 3000);
			}
		});
	});

	$('.codesync-btn-toggle-visibility').on('click', function() {
		var targetId = $(this).data('target');
		var $input = $(targetId);
		if ($input.length) {
			var $icon = $(this).find('.codesync-icon');
			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$icon.attr('data-lucide', 'eye-off');
			} else {
				$input.attr('type', 'password');
				$icon.attr('data-lucide', 'eye');
			}
			lucide.createIcons();
		}
	});

	// Initialize all Lucide icons rendered by PHP on page load
	lucide.createIcons();

	/* ---------------------------------------------------- */
	/* 11. CodeSync Checker Modal                           */
	/* ---------------------------------------------------- */
	var currentSessionId = '';
	var currentBasePath = '';
	var checkerLogs = [];

	function appendStepResult($step, data) {
		var html = '';
		var status = data.status || 'success';
		var color = '#10b981'; // green
		var icon = 'check-circle-2';

		if (status === 'error') {
			color = '#ef4444'; // red
			icon = 'x-circle';
		} else if (status === 'warning') {
			color = '#f59e0b'; // yellow
			icon = 'alert-triangle';
		}

		$step.find('.codesync-checker-step-icon').html('<i data-lucide="' + icon + '" class="codesync-icon"></i>').css('color', color);
		$step.addClass('step-' + status);

		var mdLog = '## ' + $step.find('strong').text() + ' - ' + status.toUpperCase() + '\n';

		if (data.errors && data.errors.length) {
			html += '<ul style="color:#ef4444; margin-top:0;">';
			data.errors.forEach(function(e) { 
				html += '<li><i data-lucide="x" class="codesync-icon" style="width:12px;height:12px;"></i> ' + e + '</li>'; 
				mdLog += '- 🔴 ' + e + '\n';
			});
			html += '</ul>';
		}
		if (data.warnings && data.warnings.length) {
			html += '<ul style="color:#f59e0b; margin-top:0;">';
			data.warnings.forEach(function(e) { 
				html += '<li><i data-lucide="alert-triangle" class="codesync-icon" style="width:12px;height:12px;"></i> ' + e + '</li>'; 
				mdLog += '- 🟡 ' + e + '\n';
			});
			html += '</ul>';
		}
		if (data.passed && data.passed.length) {
			html += '<ul style="color:#10b981; margin-top:0; margin-bottom:0;">';
			data.passed.forEach(function(e) { 
				html += '<li><i data-lucide="check" class="codesync-icon" style="width:12px;height:12px;"></i> ' + e + '</li>'; 
				mdLog += '- 🟢 ' + e + '\n';
			});
			html += '</ul>';
		}

		checkerLogs.push(mdLog);
		$step.find('.codesync-checker-step-body').html(html); // Removed .slideDown() per user request
		if (window.lucide) { window.lucide.createIcons(); }
	}

	function runStep(action, payload, stepId, onSuccess) {
		var $step = $modalBody.find('.codesync-checker-step[data-step="' + stepId + '"]');
		$step.addClass('step-active');
		$step.find('.codesync-checker-step-icon').html('<i data-lucide="loader-2" class="codesync-icon codesync-spin"></i>').css('color', '#3b82f6');
		if (window.lucide) { window.lucide.createIcons(); }

		var data = $.extend({ action: action, nonce: codesync_ajax.nonce }, payload);
		var startTime = new Date().getTime();

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: data,
			success: function(response) {
				var delay = Math.max(0, 2000 - (new Date().getTime() - startTime));
				setTimeout(function() {
					$step.removeClass('step-active');
					if (response.success) {
						appendStepResult($step, response.data.result);
						onSuccess(response.data);
					} else {
						$step.find('.codesync-checker-step-icon').html('<i data-lucide="x-circle" class="codesync-icon"></i>').css('color', '#ef4444');
						$step.find('.codesync-checker-step-body').html('<p style="color:#ef4444;">Erro fatal: ' + response.data.message + '</p>').slideDown();
						if (window.lucide) { window.lucide.createIcons(); }
						// Run cleanup if we have session id
						if (currentSessionId) {
							$.post(codesync_ajax.url, { action: 'codesync_checker_cleanup', session_id: currentSessionId, nonce: codesync_ajax.nonce });
						}
						$modalFooter.find('.codesync-btn-copy-md, .codesync-btn-force-install').show();
					}
				}, delay);
			},
			error: function(jqXHR, textStatus, errorThrown) {
				var delay = Math.max(0, 2000 - (new Date().getTime() - startTime));
				setTimeout(function() {
					$step.removeClass('step-active').addClass('step-error');
					$step.find('.codesync-checker-step-icon').html('<i data-lucide="x-circle" class="codesync-icon"></i>').css('color', '#ef4444');
					$step.find('.codesync-checker-step-body').html('<p style="color:#ef4444;">AJAX Error: ' + textStatus + ' - ' + errorThrown + '</p>').slideDown();
					if (window.lucide) { window.lucide.createIcons(); }
					$modalFooter.find('.codesync-btn-copy-md, .codesync-btn-force-install').show();
				}, delay);
			}
		});
	}

	function runCheckerPipeline(repoSlug) {
		checkerLogs = [];
		checkerLogs.push('# CodeSync Checker Report: ' + repoSlug);
		checkerLogs.push('');

		// Use selected ref if available
		var selectedRef = $('#codesync-modal-ref').val() || '';

		// Step 1: Download
		runStep('codesync_checker_download', { repo: repoSlug, ref: selectedRef }, 'download', function(data) {
			currentSessionId = data.session_id;
			currentBasePath = data.base_path;
			
			// Step 2: Headers
			runStep('codesync_checker_headers', { base_path: currentBasePath }, 'headers', function() {
				
				// Step 3: Security
				runStep('codesync_checker_security', { base_path: currentBasePath }, 'security', function() {
					
					// Step 4: Deprecated
					runStep('codesync_checker_deprecated', { base_path: currentBasePath }, 'deprecated', function() {
						
						// Step 5: Cleanup (silent background)
						$.post(codesync_ajax.url, { action: 'codesync_checker_cleanup', session_id: currentSessionId, nonce: codesync_ajax.nonce });
						
						var hasError = $modalBody.find('.step-error').length > 0;
						if (hasError) {
							$modalFooter.find('.codesync-btn-copy-md, .codesync-btn-force-install').show();
						} else {
							$modalFooter.find('.codesync-btn-copy-md').show();
							$modalFooter.find('.codesync-btn-confirm-install').show().prop('disabled', false);
						}
					});
				});
			});
		});
	}

	// Close cleanup hook if closed early
	$modal.on('click', '.codesync-modal-close, .codesync-modal-btn-cancel', function() {
		if (currentSessionId) {
			$.post(codesync_ajax.url, { action: 'codesync_checker_cleanup', session_id: currentSessionId, nonce: codesync_ajax.nonce });
		}
	});

	$modal.on('click', '.codesync-checker-step-header', function() {
		var $step = $(this).closest('.codesync-checker-step');
		if ($step.hasClass('step-success') || $step.hasClass('step-warning') || $step.hasClass('step-error')) {
			$(this).siblings('.codesync-checker-step-body').slideToggle();
		}
	});

	$('.codesync-btn-copy-md').on('click', function(e) {
		e.preventDefault();
		var text = checkerLogs.join('\n');
		navigator.clipboard.writeText(text).then(function() {
			alert(codesync_ajax.texts.report_copied);
		});
	});

});
