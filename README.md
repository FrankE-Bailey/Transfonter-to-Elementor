# Transfonter to Elementor

A WordPress plugin that auto-registers [Transfonter](https://transfonter.org) font kits as Elementor custom fonts. Upload a Transfonter `.zip` and all weights and styles appear instantly in Elementor's font picker — no manual entry required.

## Features

- **One-click upload** — drag and drop a Transfonter `.zip` directly in the WordPress admin
- **Full variant support** — all weights (100–900) and styles (normal, italic) are registered automatically
- **Elementor font picker integration** — fonts appear under a dedicated "Custom" group
- **Frontend & editor enqueue** — `@font-face` CSS is injected on the frontend, in the Elementor editor, and in the preview iframe
- **Manual install support** — drop extracted Transfonter files into `wp-content/fonts/{font-name}/` and the plugin picks them up without a zip
- **Stylesheet-less fallback** — if no `stylesheet.css` is present, weight and style are inferred from font filenames
- **Per-font deletion** — remove any installed font and its files from the admin UI
- **Transient caching** — font data is cached for 24 hours and invalidated on upload or delete
- **Format ordering** — `@font-face` `src` stacks are output in optimal order: `woff2 → woff → truetype → opentype → eot → svg`

## Requirements

- WordPress 5.0 or later
- PHP 7.4 or later
- [Elementor](https://elementor.com) (free or Pro)

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   wp-content/plugins/transfonter-to-elementor/
   ```
2. Activate the plugin from **Plugins → Installed Plugins** in the WordPress admin.
3. Navigate to **Settings → TF → Elementor**.

## Usage

### Upload a Transfonter zip

1. Go to **Settings → TF → Elementor**.
2. Drag and drop a Transfonter `.zip` onto the upload zone (or click **browse**).
3. Click **Upload & Install**.
4. Go to **Elementor → Tools → Regenerate CSS** to apply the new fonts.

The zip must be a standard [Transfonter](https://transfonter.org) export — it needs to contain a `stylesheet.css` and the corresponding font files (`woff2`, `woff`, `ttf`, etc.).

### Manual install

Extract a Transfonter zip and place the folder under `wp-content/fonts/`:

```
wp-content/
  fonts/
    my-font/
      stylesheet.css
      MyFont-Regular.woff2
      MyFont-Regular.woff
      MyFont-Bold.woff2
      MyFont-Bold.woff
```

The plugin scans this directory automatically on every page load (results are cached).

### Deleting a font

On the **Settings → TF → Elementor** admin page, click the **Delete** button next to any installed font. This removes the font files from disk and invalidates the cache.

## How it works

1. **Zip upload** — the plugin reads `stylesheet.css` from the zip to extract the `font-family` name, creates a sanitized folder under `wp-content/fonts/`, and extracts only font files and the stylesheet.
2. **Font parsing** — each font's `stylesheet.css` is parsed with regex to extract `@font-face` blocks: weight, style, display, and file references.
3. **Elementor registration** — parsed family names are injected into Elementor's font system via the `elementor/fonts/additional_fonts` and `elementor/fonts/groups` filters.
4. **CSS generation** — `@font-face` declarations are assembled and injected as inline CSS via `wp_add_inline_style`.

## File structure

```
transfonter-to-elementor/
  transfonter-to-elementor.php   Main plugin file (single-file plugin)
```

Fonts are stored outside the plugin, in `wp-content/fonts/`, so they survive plugin updates.

## License

GPL v2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

---

Built by [Studio256](https://256.co.za)
