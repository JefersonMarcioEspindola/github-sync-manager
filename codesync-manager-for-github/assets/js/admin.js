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
	function loadGitHubRepositories() {
		$reposSpinner.addClass('is-active');
		$reposContainer.html('<p class="description">' + codesync_ajax.texts.loading_repos + '</p>');
		$searchField.val('').prop('disabled', true);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_add_plugin',
				action_type: 'list',
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$reposSpinner.removeClass('is-active');
				$searchField.prop('disabled', false);

				if (response.success) {
					cachedRepos = response.data.repos;
					renderRepositories(cachedRepos);
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
		loadGitHubRepositories();
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
			html += '  <div class="codesync-repo-footer">';
			html += '    <span class="codesync-repo-date">' + codesync_ajax.texts.updated_lbl.replace('%s', lastUpdated) + '</span>';
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
					
					// ── Source select (origin) ──────────────────────────────
					bodyHtml += '  <div class="codesync-modal-field">';
					bodyHtml += '    <label for="codesync-modal-ref">' + codesync_ajax.texts.select_source + '</label>';
					bodyHtml += '    <select id="codesync-modal-ref">';
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
					$modalFooter.find('.codesync-modal-btn-install').prop('disabled', false);

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

		$modalFooter.html(
			'<button type="button" class="button codesync-modal-btn-cancel">' + codesync_ajax.texts.close_btn + '</button>' +
			'<button type="button" class="button button-primary codesync-modal-btn-install" disabled>' + codesync_ajax.texts.install_btn + '</button>'
		);

		$modal.show();
		$modal[0].offsetHeight; // Trigger reflow for transition
		$modal.addClass('codesync-modal-open');

		verifyRepo(repo);
	});

	$modal.on('click', '.codesync-modal-btn-install', function(e) {
		e.preventDefault();
		
		var $btn = $(this);
		$btn.prop('disabled', true);
		$modal.find('.codesync-modal-btn-cancel').prop('disabled', true);
		$modal.find('.codesync-modal-close').css('pointer-events', 'none');

		var selectedRef = $('#codesync-modal-ref').val() || '';
		var selectedSubfolder = $('#codesync-modal-subfolder').val() || '';

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
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$modal.find('.codesync-modal-close').css('pointer-events', 'auto');
				if (response.success) {
					installIsDone = true;
					cachedRepos = [];

					var doneHtml = '';
					doneHtml += '<div class="codesync-modal-done">';
					doneHtml += '  <div class="codesync-modal-done-icon">✓</div>';
					doneHtml += '  <h4>' + codesync_ajax.texts.install_success_title + '</h4>';
					doneHtml += '  <p>' + codesync_ajax.texts.install_success_msg.replace('%1$s', response.data.plugin_name).replace('%2$s', response.data.version) + '</p>';
					doneHtml += '  <p><a href="' + response.data.activate_url + '" class="button button-primary button-hero codesync-modal-btn-activate">' + codesync_ajax.texts.activate_btn + '</a></p>';
					doneHtml += '</div>';

					$modalBody.html(doneHtml);
					$modalFooter.html('<button type="button" class="button codesync-modal-btn-close-done">' + codesync_ajax.texts.close_btn + '</button>');
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
	/* 8. Force Update Plugin                               */
	/* ---------------------------------------------------- */
	$pluginsCards.on('click', '.codesync-btn-force-update', function(e) {
		e.preventDefault();

		var $btn  = $(this);
		var repo  = $btn.data('repo');
		if (!repo) return;

		if (!confirm(codesync_ajax.texts.force_update_confirm.replace('%s', repo))) {
			return;
		}

		var origLabel = $btn.html();
		$btn.prop('disabled', true).html('<i data-lucide="loader-circle" class="codesync-icon codesync-spin"></i> ' + codesync_ajax.texts.force_updating);

		$.ajax({
			url: codesync_ajax.url,
			type: 'POST',
			data: {
				action: 'codesync_force_update',
				repo: repo,
				nonce: codesync_ajax.nonce
			},
			success: function(response) {
				$btn.prop('disabled', false).html(origLabel);
				if (response.success) {
					if (response.data.table_html) {
						$pluginsCards.html(response.data.table_html);
						lucide.createIcons({ nodes: [$pluginsCards[0]] });
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

	// Initialize all Lucide icons rendered by PHP on page load
	lucide.createIcons();
});
