# Access Lens - Protected Media Links for WordPress
<div align="center">

**The ultimate tool to protect your media files while maintaining SEO.**

</div>

---

**Access Lens** solves a critical challenge for content creators: **how to protect valuable downloadable assets for lead generation or members-only access, without hiding them from search engines like Google.**

This plugin gives you full control over who can access your premium content, private documents, and digital products. It protects your media uploads from unauthorized direct access and hotlinking, ensuring that only authenticated users, verified search engine bots, or visitors with a special access token can download your files.

## 📖 Table of Contents

- [✨ Core Features](#-core-features)
- [🚀 Installation](#-installation)
- [💡 How to Use](#-how-to-use)
  - [From the Media Library](#1-from-the-media-library)
  - [Using the Gutenberg Block Editor](#2-using-the-gutenberg-block-editor)
  - [Using the Shortcode](#3-using-the-shortcode)
- [🧠 Advanced Details & FAQ](#-advanced-details--faq)
- [🔭 Scope: What This Plugin Is & Isn't](#-scope-what-this-plugin-is--isnt)
- [🤝 Contributing](#-contributing)


## ✨ Core Features

-   🔍 **SEO Friendly:** A key differentiator! Allows verified search engine crawlers (Googlebot, Bingbot, etc.) to access and index your protected files, so your content remains discoverable.
-   🛡️ **Secure File Protection:** Automatically secures the WordPress uploads directory to prevent direct URL access via `.htaccess` or Nginx rules.
-   🔗 **Tokenized, Expiring Links:** Generate unique, secure links for your media files that automatically expire after a set time or a specific number of downloads. Perfect for lead-generation forms.
-   👑 **Granular Access Control:** A powerful, multi-layered rules engine to define exactly who can access your files.
    -   **Global Rules:** Set site-wide allow/deny lists for specific users or entire user roles.
    -   **Per-File Rules:** Override global settings for individual files to grant or revoke access.
-   🔄 **Customizable Redirects:** If a user is denied access, you can redirect them to a global default URL or a custom URL specific to that file.
-   👤 **Seamless User Integration:**
    -   **Media Library:** Generate protected links with one click.
    -   **Gutenberg Block:** A dedicated "Protected File" block to easily embed protected files.
    -   **Users Page:** Quickly add or remove users from global allow/deny lists directly from the main Users screen.
-   🤖 **Reliable Bot Detection:** Uses a combination of User-Agent strings and advanced DNS lookups (rDNS/fDNS) to reliably identify legitimate search engine crawlers and prevent spoofing.

## 🚀 Installation

<details>
<summary>Click to view installation instructions</summary>

1.  Download the plugin `.zip` file and upload it through the **Plugins > Add New** menu in WordPress.
2.  Alternatively, upload the `protected-media-links` folder to the `/wp-content/plugins/` directory.
3.  Activate the plugin through the 'Plugins' menu in your WordPress dashboard.
4.  Navigate to **Settings > Access Lens** to configure the default settings.
5.  The plugin will attempt to automatically update your server configuration. If it cannot, it will provide you with the necessary code to add manually. See the FAQ for server-specific instructions.

</details>

## 💡 How to Use

### 1. From the Media Library
1.  Navigate to **Media > Library**.
2.  In **List View**, hover over a file and click **"Get Protected Link"**.
3.  In **Grid View**, click on a file and find the **"Get Protected Link"** button in the attachment details pane.

### 2. Using the Gutenberg Block Editor
1.  Add a new block and search for **"Protected File"**.
2.  Use the block's controls to search for and select the file you want to protect.
3.  Customize the link text, expiration, and download limit in the block sidebar.

### 3. Using the Shortcode
Use the `[protected_media]` shortcode in the Classic editor, widgets, or theme files.

```shortcode
[protected_media id="{attachment_id}" text="Download Your File"]
```

#### Shortcode Attributes
| Attribute | Description                                                                    | Required | Default                    | Example                    |
| :-------- | :----------------------------------------------------------------------------- | :------- | :------------------------- | :------------------------- |
| `id`      | The ID of the media attachment.                                                | **Yes** | `null`                     | `123`                      |
| `text`    | The clickable link text that the user will see.                                | No       | The file's title           | `"Download Our Brochure"`  |
| `expires` | The lifespan of the link. Uses `strtotime()` compatible strings.               | No       | Value from settings page   | `1 hour`, `2 days`, `1 week` |
| `limit`   | The number of times the link can be used for downloading.                      | No       | Value from settings page   | `5`                        |
| `user_id` | The ID of the user this link is restricted to.                                 | No       | `null` (available to all)  | `10`                       |


## 🧠 Advanced Details & FAQ

<details>
<summary><strong>How does the access control logic work?</strong></summary>

The plugin checks for access permissions in a strict, prioritized order. The first rule that matches a user grants or denies access, and processing stops. This ensures predictable behavior. The priority is:

1.  **User-Specific Rules:** Is the user on a global or per-file `Allow` or `Deny` list? User-specific rules are checked first.
2.  **Role-Based Rules:** Does the user's role appear on a global or per-file `Allow` or `Deny` list?
3.  **Bot Check:** Is the visitor a verified search engine bot?
4.  **Token Check:** Does the visitor have a valid, unexpired access token?
5.  **Default:** If none of the above grant access, the request is denied and the user is redirected.

</details>

<details>
<summary><strong>How do I configure my server? (Apache/Nginx)</strong></summary>

For file protection, requests to `/wp-content/uploads/` must reach WordPress. Access Lens now writes these rewrite rules automatically and regenerates them for each directory that contains protected files. You can view the current snippets on the **Settings > Access Lens** page. If you need to add them manually, use the following examples.

**Apache (`.htaccess` file in your WordPress root)**

Place these rules *before* the main WordPress block:
```htaccess
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
```nginx
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
</details>

## 🔭 Scope: What This Plugin Is & Isn't

It's important to understand the scope of this plugin to ensure it meets your needs.

#### ✔️ This Plugin IS:
- A tool to **protect direct URL access** to files in the WordPress Media Library.
- A solution for **balancing SEO with lead generation** by allowing bots to crawl gated content.
- A flexible **access control system** based on users, roles, and temporary tokens.
- A system that **integrates deeply** into the WordPress admin interface.

#### ❌ This Plugin IS NOT:
- **A full Digital Rights Management (DRM) system.** It does not encrypt files or prevent a user from sharing a file *after* they have downloaded it.
- **A membership plugin.** It does not handle subscriptions, payments, or content dripping, but it can work alongside plugins that do by leveraging user roles.
- **A solution for externally hosted files** (e.g., Amazon S3, Dropbox). It is designed only for files hosted on the same server as WordPress.
- **A tool for on-the-fly file modification,** such as PDF watermarking.

## 🤝 Contributing

We welcome contributions from the community! Whether it's reporting a bug, suggesting a feature, or submitting a pull request, your help is appreciated. Please adhere to the following guidelines:

-   **WordPress Coding Standards:** All code must follow the official WordPress standards.
-   **Prefix Everything:** All functions, classes, and hooks must be prefixed with `pml_` to prevent conflicts.
-   **Security First:** Use nonces, capability checks, and proper sanitization/escaping on all data.

Please open an issue or pull request on our [GitHub repository](https://github.com/TWP-Technologies/Access-Lens).

## 🛠️ Developer Notes

-   **Class map generator:** Run `php build/generate-class-map.php` from the command line whenever classes are added or files are renamed under `includes/`. The script must be executed via the CLI (it will exit early otherwise) and respects the optional `PML_PLUGIN_DIR_FOR_BUILD` constant if the plugin lives outside the default directory layout. A successful run regenerates `includes/pml-class-map.php` with paths relative to the includes directory.
-   **Release packaging:** WordPress distribution archives created with tools such as `wp dist-archive` automatically exclude the `build/` directory via `.distignore`, ensuring development-only assets are left out of release zips.
