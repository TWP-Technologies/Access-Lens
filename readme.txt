=== Access Lens - Protected Media Links ===
Contributors: twptechnologies
Donate link: https://twp.tech/
Tags: media, security, protection, files, downloads, media library, access control, SEO, search engine
Requires at least: 5.9
Tested up to: 6.8.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Protect your media files while maintaining SEO. Control who can access your premium content, private documents, and digital products.

== Description ==

**Access Lens** solves a critical challenge for content creators: **how to protect valuable downloadable assets for lead generation or members-only access, without hiding them from search engines like Google.**

This plugin gives you full control over who can access your premium content, private documents, and digital products. It protects your media uploads from unauthorized direct access and hotlinking, ensuring that only authenticated users, verified search engine bots, or visitors with a special access token can download your files.

= Core Features =

* **SEO Friendly:** A key differentiator! Allows verified search engine crawlers (Googlebot, Bingbot, etc.) to access and index your protected files, so your content remains discoverable.
* **Secure File Protection:** Automatically secures the WordPress uploads directory to prevent direct URL access via `.htaccess` or Nginx rules.
* **Tokenized, Expiring Links:** Generate unique, secure links for your media files that automatically expire after a set time or a specific number of downloads. Perfect for lead-generation forms.
* **Granular Access Control:** A powerful, multi-layered rules engine to define exactly who can access your files.
  * **Global Rules:** Set site-wide allow/deny lists for specific users or entire user roles.
  * **Per-File Rules:** Override global settings for individual files to grant or revoke access.
* **Customizable Redirects:** If a user is denied access, you can redirect them to a global default URL or a custom URL specific to that file.
* **Seamless User Integration:**
  * **Media Library:** Generate protected links with one click.
  * **Gutenberg Block:** A dedicated "Protected File" block to easily embed protected files.
  * **Users Page:** Quickly add or remove users from global allow/deny lists directly from the main Users screen.
* **Reliable Bot Detection:** Uses a combination of User-Agent strings and advanced DNS lookups (rDNS/fDNS) to reliably identify legitimate search engine crawlers and prevent spoofing.

= What This Plugin IS =

* A tool to **protect direct URL access** to files in the WordPress Media Library.
* A solution for **balancing SEO with lead generation** by allowing bots to crawl gated content.
* A flexible **access control system** based on users, roles, and temporary tokens.
* A system that **integrates deeply** into the WordPress admin interface.

= What This Plugin IS NOT =

* **A full Digital Rights Management (DRM) system.** It does not encrypt files or prevent a user from sharing a file *after* they have downloaded it.
* **A membership plugin.** It does not handle subscriptions, payments, or content dripping, but it can work alongside plugins that do by leveraging user roles.
* **A solution for externally hosted files** (e.g., Amazon S3, Dropbox). It is designed only for files hosted on the same server as WordPress.
* **A tool for on-the-fly file modification,** such as PDF watermarking.

== Installation ==

1. Download the plugin `.zip` file and upload it through the **Plugins > Add New** menu in WordPress.
2. Alternatively, upload the `protected-media-links` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in your WordPress dashboard.
4. Navigate to **Settings > Access Lens** to configure the default settings.
5. The plugin will attempt to automatically update your server configuration. If it cannot, it will provide you with the necessary code to add manually. See the FAQ for server-specific instructions.

== Frequently Asked Questions ==

= How does the access control logic work? =

The plugin checks for access permissions in a strict, prioritized order. The first rule that matches a user grants or denies access, and processing stops. This ensures predictable behavior. The priority is:

1. **User-Specific Rules:** Is the user on a global or per-file `Allow` or `Deny` list? User-specific rules are checked first.
2. **Role-Based Rules:** Does the user's role appear on a global or per-file `Allow` or `Deny` list?
3. **Bot Check:** Is the visitor a verified search engine bot?
4. **Token Check:** Does the visitor have a valid, unexpired access token?
5. **Default:** If none of the above grant access, the request is denied and the user is redirected.

= How do I configure my server? (Apache/Nginx) =

For file protection, requests to `/wp-content/uploads/` must reach WordPress. Access Lens now writes these rewrite rules automatically and regenerates them for each directory that contains protected files. You can view the current snippets on the **Settings > Access Lens** page. If you need to add them manually, use the following examples.

**Apache (`.htaccess` file in your WordPress root)**

Place these rules *before* the main WordPress block:
```
# BEGIN Access Lens
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} -f
    # Rule generated for each protected directory
    RewriteCond %{REQUEST_URI} ^/wp-content/uploads/<directory>/.+\.(<exts>)$ [NC]
    RewriteRule ^wp-content/uploads/<directory>/(.*)$ wp-content/plugins/protected-media-links/pml-handler.php?pml_media_request=$1 [QSA,L]
    # Additional directories have similar rules
</IfModule>
# END Access Lens
```

**Nginx (`nginx.conf` file)**

Add this `location` block inside your `server` block. It should come before the general `location /` block.
```
location ~ ^/wp-content/uploads/<directory>/.+\.(<exts>)$ {
    if (!-f $request_filename) {
        return 404;
    }
    rewrite ^/wp-content/uploads/<directory>/(.*)$ /wp-content/plugins/protected-media-links/pml-handler.php?pml_media_request=$1 last;
    # Additional location blocks are generated automatically
}
```

If you have an Nginx `internal` location or a LiteSpeed equivalent, define a constant in `wp-config.php` so the handler can offload file delivery:

```php
define( 'PML_INTERNAL_REDIRECT_PREFIX', '/pml-secure-files/' );
```

Set the value to your internal location path. The handler will then emit `X-Accel-Redirect` or `X-LiteSpeed-Location` headers.

== Bundled Libraries ==

The following JavaScript libraries are included locally in the `vendor/` directory so the plugin works without third-party CDNs:

* Select2 v4.0.13 — MIT License (see `vendor/select2/4.0.13/LICENSE`).
* Flatpickr v4.6.13 — MIT License (see `vendor/flatpickr/4.6.13/LICENSE`).

== Screenshots ==

1. Media Library integration - Easily protect files and generate secure links
2. Gutenberg Block - Embed protected files with custom settings
3. Settings page - Configure global protection rules
4. Access control - Set permissions by user or role

== Changelog ==

= 1.2.1 =
## What's Changed
* fix: ensure wp_kses is loaded during deprecated file notices and errors by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/42

**Full Changelog**: https://github.com/TWP-Technologies/Access-Lens/commits/v1.2.1

= 1.2.0 =
## What's Changed
* refactor: enhance caching and sanitization logic by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/41
* refactor: replace CDN dependencies with bundled assets by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/40
* refactor: enhance HTML output in token meta box by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/39
* refactor: improve input sanitization and logging by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/38
* refactor: simplify cookie authentication retrieval logic by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/37
* feat: add CLI execution restriction to class map generator by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/36
* Enhance output with ABSPATH check by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/35
* refactor: remove deprecated constants and clean up markup by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/34
* refactor: replace PML_TEXT_DOMAIN with full text domain by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/33
* refactor: update license and text domain in plugin by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/32

**Full Changelog**: https://github.com/TWP-Technologies/Access-Lens/commits/v1.2.1 (v1.2.0 was not officially tagged)

= 1.1.0 =
## What's Changed
* Update to latest by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/1
* Add headless helpers by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/2
* Add headless session token validation by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/3
* Implement headless request handler by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/4
* Inject database objects into headless helpers by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/5
* Implement headless caching for bot detector by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/6
* Update htaccess rule to use headless handler by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/7
* Remove deprecated request setup by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/8
* Remove legacy file handler by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/9
* Update handler references in settings by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/10
* Fix handler bootstrap issues by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/11
* Dynamic htaccess rule generation by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/12
* Show dynamic server rules by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/13
* Update htaccess on protection status change by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/14
* Update install process for htaccess management by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/15
* Fix rule regeneration methods by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/16
* Fix dynamic Nginx rule display by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/17
* Fix rewrite rule generation by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/18
* Update server config docs by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/19
* Update plugin activation to set cookie constants by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/21
* Sanitize redirect URL in handler by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/22
* Add sanitization test by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/23
* Headless php handler by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/20
* Rebrand "Protected Media Links" to "Access Lens" - Partial by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/24
* refactor: rename "Protected Media Links" to "Access Lens" by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/25
* feat: add TWP live chat contact widget to admin pages by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/26
* feat: add translator comments for all user-facing strings by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/28
* refactor: improve i18n comments and use `wp_is_writable` by @KnotFalse in https://github.com/TWP-Technologies/Access-Lens/pull/29

**Full Changelog**: https://github.com/TWP-Technologies/Access-Lens/commits/v1.1.0

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.1.0 =
This update improves bot detection, adds per-file custom redirects, and fixes several compatibility issues. Upgrade recommended for all users.

== How to Use ==

= From the Media Library =
1. Navigate to **Media > Library**.
2. In **List View**, hover over a file and click **"Get Protected Link"**.
3. In **Grid View**, click on a file and find the **"Get Protected Link"** button in the attachment details pane.

= Using the Gutenberg Block Editor =
1. Add a new block and search for **"Protected File"**.
2. Use the block's controls to search for and select the file you want to protect.
3. Customize the link text, expiration, and download limit in the block sidebar.

= Using the Shortcode =
Use the `[protected_media]` shortcode in the Classic editor, widgets, or theme files.

```
[protected_media id="{attachment_id}" text="Download Your File"]
```

= Shortcode Attributes =
* `id` - The ID of the media attachment (Required)
* `text` - The clickable link text that the user will see (Default: file's title)
* `expires` - The lifespan of the link using `strtotime()` compatible strings (Default: from settings)
* `limit` - The number of times the link can be used (Default: from settings)
* `user_id` - The ID of the user this link is restricted to (Default: available to all)
