=== CodeSync Manager for GitHub ===
Contributors: JefersonMarcioEspindola, jefersonespindola
Tags: github, plugin updater, private plugins, github releases, self-hosted
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 2.0.008
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install and keep WordPress plugins updated directly from GitHub releases — supports both public and private repositories.

== Description ==

**GitHub Sync Manager** lets you manage WordPress plugins hosted on GitHub repositories. Instead of manually downloading ZIP files and re-uploading them, this plugin connects to the GitHub API and handles everything for you — including detecting new releases and triggering updates in one click.

**Key Features:**

* Connect your GitHub account via a Personal Access Token (PAT)
* Browse your GitHub repositories and install plugins directly from releases or branches
* Automatic background checks for new releases (via WP-Cron, twice daily)
* Manual "Check for Updates" button to trigger an immediate scan
* Force reinstall any plugin at any time, regardless of version comparison
* Supports public and private GitHub repositories
* Supports subfolder-based plugins (where the plugin lives inside a subdirectory of the repository)
* Activity log for every install, update, and check
* Token encrypted at rest using AES-256-GCM with keys derived from your `wp-config.php`

**How it works:**

1. Connect your GitHub account with a PAT (fine-grained or classic token)
2. Browse your repositories in the "Add Plugin" tab
3. Install any plugin — the plugin reads the release ZIP or branch archive from GitHub and installs it natively via WordPress's own upgrade infrastructure
4. From that point on, every new release published on GitHub will trigger an update notification in WordPress

**Use case:**

This plugin is designed for developers distributing custom or client plugins via GitHub. Once set up, a new GitHub release automatically makes the update available to all WordPress sites running the plugin — no manual ZIP uploads needed.

== Installation ==

1. Download the latest release ZIP from the plugin's GitHub page.
2. In your WordPress dashboard, go to **Plugins > Add New > Upload Plugin** and upload the ZIP file.
3. Activate the plugin.
4. Go to the new **GitHub Sync** menu item in your WordPress admin sidebar.
5. Enter a GitHub Personal Access Token (PAT) to connect your account.

== Frequently Asked Questions ==

= Does it work with private repositories? =
Yes. When you provide a Personal Access Token with the appropriate scopes (`repo` for classic tokens, or repository read access for fine-grained tokens), the plugin can access and install from private repositories.

= How are versions compared? =
The plugin compares the tag name of the latest GitHub release (e.g. `v1.2.3`) against the `Version` header declared in the plugin's main PHP file using PHP's `version_compare()`.

= Is my GitHub token stored securely? =
Yes. The token is encrypted using AES-256-GCM with a key derived from your site's `AUTH_KEY` and `SECURE_AUTH_KEY` constants defined in `wp-config.php`. It is never stored in plain text.

= What if my plugin is inside a subfolder of the repository? =
Supported. When installing, you can select the subfolder where the plugin's main PHP file lives. The plugin will extract only that subfolder during installation.

= Can I install from a branch instead of a release? =
Yes. If a repository has no releases, or you prefer to track a specific branch, you can select a branch as the installation source.

= Does this plugin send any data externally? =
The only external communication is with the GitHub API (`api.github.com`) using your own access token. No data is sent to any other service.

== External Services ==

This plugin uses the GitHub API (api.github.com) as an external service to fetch information about repositories, releases, and branches. 

When you connect your GitHub Personal Access Token (PAT), the plugin communicates directly with the GitHub API to authenticate your requests. This is necessary to access both your public and private repositories, and to download the plugin release assets directly to your WordPress installation.

No personal data from your WordPress site users is sent. The only data transmitted is your configured GitHub Personal Access Token, the repository names, and standard WordPress HTTP request headers.

This service is provided by GitHub, Inc.
- [Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
- [Privacy Policy](https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement)

== Screenshots ==

1. Main dashboard showing managed plugins as cards with version info and last-check date.
2. "Add Plugin" tab with repository browser filtered to WordPress-related projects.
3. Activity log showing install and update history.

== Changelog ==

= 1.1.4 =
* Hotfix: Added missing English and Spanish translations for the new synchronization modal texts.

= 1.1.3 =
* Enhancement: Improved UI/UX of the installation modal by repositioning the activation button to the footer.
* Enhancement: Added a dynamic success message that informs the user when a plugin is synchronized rather than freshly installed.

= 1.1.2 =
* Hotfix: Synchronized all HTML classes and IDs across PHP and CSS with the new `codesync-` prefix layout structure.

= 1.1.1 =
* Hotfix: Updated javascript and css assets with the new CODESYNC_ prefixes to fix broken admin interactions.

= 1.1.0 =
* Hotfix: Corrected autoloader string offset parsing for the new CODESYNC_ prefix.
* Fix: Removed discouraged `load_plugin_textdomain()` function call based on WP Plugin Check.

= 1.0.6 =
* Renamed plugin to CodeSync Manager for GitHub and implemented comprehensive WP.org compliance fixes (prefixing, external services disclosure).

= 1.0.5 =
* Fixed translation issues for all missing UI strings including the subdirectory/folder notice popup in English and Spanish.
* Added official translation guidelines for developers.

= 1.0.4 =
* Fixed translation issue for Classic PAT and Fine-Grained PAT labels when running in English locale.

= 1.0.3 =
* Centered the text on the "Activate Plugin Now" button.
* Enforced custom primary button colors inside the plugin pages and modals to prevent external page builders (like Bricks) from overriding the button styling.

= 1.0.2 =
* Fixed plugin installation folder detection logic to prevent capturing unrelated plugins.
* Improved plugin name lookup to use the resolved canonical slug, with sanitization and repository fallback.

= 1.0.1 =
* Fixed language/translation autoloading issue by calling load_plugin_textdomain() explicitly.
* Enforced consistent 8px border-radius on all plugin buttons for a cohesive, modern UI.
* Fixed repository filtering logic to support plugins with non-PHP main languages (e.g., CSS styling plugins) if they contain PHP code.

= 1.0.0 =
* First stable release.
* Renamed plugin to "CodeSync Manager for GitHub" for WordPress.org compliance.
* Renamed plugin slug to codesync-manager-for-github.
* Added plugin logo to admin header.
* Fixed plugin name/version detection for subfolder-based plugins — now self-heals wrong stored references.
* Removed Prompt Release button from plugin cards.
* Updated plugin description to English, developer-focused copy.
* Full WordPress.org Plugin Check compliance (zero errors).

= 0.0.16 =
* Removed hooks into WordPress update transients to comply with WordPress.org plugin guidelines.
* Replaced all direct PHP filesystem calls (`rename`, `@unlink`, `@rmdir`) with WordPress Filesystem API equivalents.
* Added missing `/* translators: */` comments to all i18n strings containing placeholders.
* Fixed unordered sprintf placeholders (`%s, %s` → `%1$s, %2$s`) across all files.
* Fixed missing `wp_unslash()` on `$_POST['locale']` in the locale-save AJAX handler.
* Wrapped `error_log()` call inside a `WP_DEBUG` check.
* Removed deprecated `load_plugin_textdomain()` call (auto-loaded since WordPress 4.6).

= 0.0.15 =
* Bundled Lucide icon library locally to comply with WordPress.org guidelines (no external CDN).

= 0.0.14 =
* Replaced all Dashicons with Lucide icons for a cleaner, modern UI.
* Added animated chevron transitions for dropdown and toggle elements.

= 0.0.13 =
* Plugin cards now stack full-width instead of a grid layout.
* Replaced activity dots with "Last checked" date meta line.
* Changed button icons for better semantics (search, refresh-cw).

= 0.0.12 =
* Replaced Plugins table with responsive card grid.
* Added per-card activity dots from real log data (green = success, red = error).
* Fixed button icon vertical alignment and color inheritance.
* Fixed logs page double border and date column wrapping.
* Public visibility badge now uses brand blue color.
* Removed PHP language badge from repository cards.

= 0.0.11 =
* Fixed fatal error: `Automatic_Upgrader_Skin::get_errors()` undefined in PHP 8.3.
* Fixed `preg_match(): Unknown modifier '*'` from bad regex delimiters in version detection.

= 0.0.10 =
* Fixed dropdown folder picker flickering and scroll issue in the install modal.
* Fixed plugin installation failing with filesystem permissions error in AJAX context.

= 0.0.9 =
* Replaced folder selector with a custom visual tree component with hierarchy lines and selection indicator.
* Fixed vertical alignment of text and emoji inside the source selector field.
* Applied full brand color palette to the admin dashboard.

= 0.0.8 =
* Applied official brand color palette to the admin panel.
* Fixed alignment of the modal close button.
* Install button now uses primary brand color with hover and shadow effects.

= 0.0.7 =
* Fixed verification error blocking the install modal for repositories without releases.
* Implemented automatic fallback to version 0.0.0 on the default branch when plugin detection fails.

= 0.0.6 =
* Added verification and installation modal.
* Implemented manual branch/version (ref) and subfolder selection.
* Added subfolder badge on the managed plugins list.
* Added translations in Portuguese, Spanish, and English.

= 0.0.5 =
* Fixed support for paths with spaces and special characters using `rawurlencode`.
* Fixed unstable sort with `usort` on PHP 8+.
* Increased scanned subfolder limit from 3 to 5 directories.
* Added detailed API error logs to the activity log tab.

= 0.0.4 =
* Added branch-based installation as fallback when no GitHub releases are published.
* Added visual branch indicator on managed plugin cards.
* Added button to copy a structured AI prompt for creating GitHub releases.

= 0.0.3 =
* Automatic repository filtering in the Add Plugin tab — shows only PHP projects or WordPress plugin-related repositories.
* Added informational banner about the filtering.
* Added language badge on repository cards.

= 0.0.2 =
* Added GPLv2 or later license headers for WordPress.org compliance.
* Created official readme.txt and LICENSE files.
* Organized repository structure for directory submission.

= 0.0.1 =
* Initial release with AES-256-GCM encryption, GitHub API manager, automatic WP-Cron checker, and admin dashboard.

== Upgrade Notice ==

= 1.0.5 =
Translation fixes and subdirectory/folder notice UI localization.

= 1.0.4 =
Fixed translation issue for Classic PAT and Fine-Grained PAT labels in English mode.

= 1.0.1 =
Minor fix version that resolves language translation loading, button border-radius visual consistency, and repository language filtering logic.

= 1.0.0 =
First stable release. Renamed plugin slug to codesync-manager-for-github — please reinstall if upgrading from a pre-1.0 version.

= 0.0.16 =
WordPress.org compliance release. Removes native update-transient injection (updates are still tracked in the GitHub Sync dashboard). All filesystem operations now use the WordPress Filesystem API.

= 0.0.15 =
Bundled Lucide icons locally — no behavior changes, just a compliance fix.
