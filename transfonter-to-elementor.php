<?php
/**
 * Plugin Name: Transfonter to Elementor
 * Plugin URI: https://256.co.za
 * Description: Auto-registers Transfonter font kits as Elementor custom fonts. Upload a Transfonter zip or drop extracted files into wp-content/fonts/ — all weights and styles appear in Elementor's font picker.
 * Version: 2.0
 * Author: Frank Bailey
 * Author URI: https://256.co.za
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: transfonter-to-elementor
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * Developed by Studio256 — https://256.co.za
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Transfonter_To_Elementor {

    const VERSION   = '2.0';
    const CACHE_KEY = 'tfe_parsed_fonts';
    const CACHE_TTL = 86400;
    const MENU_SLUG = 'transfonter-to-elementor';
    const NONCE_KEY = 'tfe_nonce';

    private $fonts_dir;
    private $fonts_url;

    public function __construct() {
        $this->fonts_dir = WP_CONTENT_DIR . '/fonts';
        $this->fonts_url = content_url( '/fonts' );

        // Elementor integration
        add_filter( 'elementor/fonts/groups',           [ $this, 'register_font_group' ] );
        add_filter( 'elementor/fonts/additional_fonts', [ $this, 'register_fonts' ] );

        // Enqueue @font-face CSS
        add_action( 'wp_enqueue_scripts',                          [ $this, 'enqueue_fonts' ] );
        add_action( 'elementor/editor/after_enqueue_scripts',      [ $this, 'enqueue_fonts' ] );
        add_action( 'elementor/preview/enqueue_styles',            [ $this, 'enqueue_fonts' ] );

        // Admin
        add_action( 'admin_menu',    [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init',    [ $this, 'handle_admin_actions' ] );
        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
    }

    // =========================================================================
    // ELEMENTOR INTEGRATION
    // =========================================================================

    public function register_font_group( $font_groups ) {
        return array_merge( [ 'custom' => __( 'Custom', 'transfonter-to-elementor' ) ], $font_groups );
    }

    public function register_fonts( $additional_fonts ) {
        foreach ( $this->get_font_families() as $family_name => $data ) {
            $additional_fonts[ $family_name ] = 'custom';
        }
        return $additional_fonts;
    }

    public function enqueue_fonts() {
        $css = $this->generate_font_face_css();
        if ( ! empty( $css ) ) {
            wp_register_style( 'tfe-custom-fonts', false, [], self::VERSION );
            wp_enqueue_style( 'tfe-custom-fonts' );
            wp_add_inline_style( 'tfe-custom-fonts', $css );
        }
    }

    // =========================================================================
    // ADMIN MENU & PAGE
    // =========================================================================

    public function add_admin_menu() {
        add_options_page(
            __( 'Transfonter to Elementor', 'transfonter-to-elementor' ),
            __( 'TF → Elementor', 'transfonter-to-elementor' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        $families = $this->get_font_families( true ); // bypass cache for admin
        ?>
        <div class="wrap tfe-wrap">
            <h1 class="tfe-page-title">
                <span class="tfe-logo">Tf</span>
                <?php _e( 'Transfonter to Elementor', 'transfonter-to-elementor' ); ?>
                <span class="tfe-version">v<?php echo self::VERSION; ?></span>
            </h1>
            <p class="tfe-subtitle"><?php _e( 'Upload a Transfonter zip and it appears in Elementor\'s font picker. All weights and styles, no clicking.', 'transfonter-to-elementor' ); ?></p>

            <!-- Upload Form -->
            <div class="tfe-card tfe-upload-card">
                <h2><?php _e( 'Add New Font', 'transfonter-to-elementor' ); ?></h2>
                <form method="post" enctype="multipart/form-data" class="tfe-upload-form">
                    <?php wp_nonce_field( 'tfe_upload', self::NONCE_KEY ); ?>
                    <input type="hidden" name="tfe_action" value="upload" />
                    <div class="tfe-upload-zone" id="tfe-drop-zone">
                        <div class="tfe-upload-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                        </div>
                        <p class="tfe-upload-text">
                            <?php _e( 'Drop a Transfonter .zip here or', 'transfonter-to-elementor' ); ?>
                            <label for="tfe-file-input" class="tfe-upload-link"><?php _e( 'browse', 'transfonter-to-elementor' ); ?></label>
                        </p>
                        <p class="tfe-upload-hint"><?php _e( 'The zip must contain a stylesheet.css and font files from Transfonter', 'transfonter-to-elementor' ); ?></p>
                        <input type="file" name="tfe_zip" id="tfe-file-input" accept=".zip" class="tfe-file-input" />
                    </div>
                    <div class="tfe-upload-actions">
                        <span class="tfe-file-name" id="tfe-file-name"></span>
                        <?php submit_button( __( 'Upload & Install', 'transfonter-to-elementor' ), 'primary', 'submit', false ); ?>
                    </div>
                </form>
            </div>

            <!-- Installed Fonts -->
            <div class="tfe-card">
                <h2><?php _e( 'Installed Fonts', 'transfonter-to-elementor' ); ?>
                    <span class="tfe-count"><?php echo count( $families ); ?></span>
                </h2>

                <?php if ( empty( $families ) ) : ?>
                    <div class="tfe-empty">
                        <p><?php _e( 'No fonts installed yet. Upload a Transfonter zip above to get started.', 'transfonter-to-elementor' ); ?></p>
                        <p class="tfe-hint"><?php printf(
                            __( 'You can also manually place extracted Transfonter files in %s', 'transfonter-to-elementor' ),
                            '<code>wp-content/fonts/{font-name}/</code>'
                        ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $families as $family_name => $data ) :
                        $faces  = $data['faces'];
                        $folder = $data['folder'];
                        $weights = [];
                        $has_italic = false;
                        foreach ( $faces as $face ) {
                            $weights[] = $face['weight'];
                            if ( $face['style'] === 'italic' ) {
                                $has_italic = true;
                            }
                        }
                        $weights = array_unique( $weights );
                        sort( $weights, SORT_NUMERIC );
                        $formats = [];
                        foreach ( $faces as $face ) {
                            $formats = array_merge( $formats, array_keys( $face['files'] ) );
                        }
                        $formats = array_unique( $formats );
                        sort( $formats );
                    ?>
                    <div class="tfe-font-row">
                        <div class="tfe-font-preview" style="font-family: '<?php echo esc_attr( $family_name ); ?>', sans-serif;">
                            <span class="tfe-font-sample"><?php echo esc_html( $family_name ); ?></span>
                        </div>
                        <div class="tfe-font-info">
                            <h3 class="tfe-font-name"><?php echo esc_html( $family_name ); ?></h3>
                            <div class="tfe-font-meta">
                                <span class="tfe-meta-item" title="<?php esc_attr_e( 'Font variants', 'transfonter-to-elementor' ); ?>">
                                    <strong><?php echo count( $faces ); ?></strong> <?php echo _n( 'variant', 'variants', count( $faces ), 'transfonter-to-elementor' ); ?>
                                </span>
                                <span class="tfe-meta-sep">&middot;</span>
                                <span class="tfe-meta-item" title="<?php esc_attr_e( 'Available weights', 'transfonter-to-elementor' ); ?>">
                                    <?php echo implode( ', ', $weights ); ?>
                                </span>
                                <?php if ( $has_italic ) : ?>
                                    <span class="tfe-meta-sep">&middot;</span>
                                    <span class="tfe-meta-item tfe-has-italic"><?php _e( '+ italic', 'transfonter-to-elementor' ); ?></span>
                                <?php endif; ?>
                                <span class="tfe-meta-sep">&middot;</span>
                                <span class="tfe-meta-item tfe-formats" title="<?php esc_attr_e( 'File formats', 'transfonter-to-elementor' ); ?>">
                                    <?php echo implode( ', ', $formats ); ?>
                                </span>
                            </div>
                            <!-- Variant detail table -->
                            <details class="tfe-variant-details">
                                <summary><?php _e( 'Show all variants', 'transfonter-to-elementor' ); ?></summary>
                                <table class="tfe-variant-table widefat striped">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Weight', 'transfonter-to-elementor' ); ?></th>
                                            <th><?php _e( 'Style', 'transfonter-to-elementor' ); ?></th>
                                            <th><?php _e( 'Preview', 'transfonter-to-elementor' ); ?></th>
                                            <th><?php _e( 'Files', 'transfonter-to-elementor' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ( $faces as $face ) : ?>
                                        <tr>
                                            <td><code><?php echo esc_html( $face['weight'] ); ?></code></td>
                                            <td><?php echo esc_html( $face['style'] ); ?></td>
                                            <td>
                                                <span style="font-family: '<?php echo esc_attr( $family_name ); ?>', sans-serif; font-weight: <?php echo esc_attr( $face['weight'] ); ?>; font-style: <?php echo esc_attr( $face['style'] ); ?>;">
                                                    <?php _e( 'The quick brown fox jumps over the lazy dog', 'transfonter-to-elementor' ); ?>
                                                </span>
                                            </td>
                                            <td class="tfe-file-formats"><?php echo implode( ', ', array_keys( $face['files'] ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        </div>
                        <div class="tfe-font-actions">
                            <form method="post" class="tfe-delete-form" onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Delete %s and all its font files?', 'transfonter-to-elementor' ), $family_name ) ); ?>');">
                                <?php wp_nonce_field( 'tfe_delete_' . $folder, self::NONCE_KEY ); ?>
                                <input type="hidden" name="tfe_action" value="delete" />
                                <input type="hidden" name="tfe_folder" value="<?php echo esc_attr( $folder ); ?>" />
                                <button type="submit" class="button tfe-delete-btn" title="<?php esc_attr_e( 'Delete font', 'transfonter-to-elementor' ); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    </svg>
                                    <?php _e( 'Delete', 'transfonter-to-elementor' ); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="tfe-footer">
                <p>
                    <?php printf(
                        __( 'Transfonter to Elementor v%s — built by %s', 'transfonter-to-elementor' ),
                        self::VERSION,
                        '<a href="https://256.co.za" target="_blank">Studio256</a>'
                    ); ?>
                    <span class="tfe-meta-sep">&middot;</span>
                    <?php printf(
                        __( 'Fonts directory: %s', 'transfonter-to-elementor' ),
                        '<code>' . esc_html( str_replace( ABSPATH, '', $this->fonts_dir ) ) . '/</code>'
                    ); ?>
                </p>
            </div>
        </div>

        <style>
            /* -- Layout -- */
            .tfe-wrap { max-width: 960px; }
            .tfe-page-title { display: flex; align-items: center; gap: 10px; font-size: 23px; font-weight: 400; }
            .tfe-logo {
                display: inline-flex; align-items: center; justify-content: center;
                width: 36px; height: 36px; border-radius: 8px;
                background: #1d2327; color: #fff; font-weight: 700; font-size: 15px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .tfe-version { font-size: 12px; color: #787c82; background: #f0f0f1; padding: 2px 8px; border-radius: 10px; }
            .tfe-subtitle { color: #50575e; margin-top: -5px; }

            /* -- Cards -- */
            .tfe-card {
                background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                padding: 20px 24px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .tfe-card h2 { display: flex; align-items: center; gap: 8px; margin-top: 0; font-size: 15px; }
            .tfe-count {
                display: inline-flex; align-items: center; justify-content: center;
                min-width: 22px; height: 22px; padding: 0 7px; border-radius: 11px;
                background: #f0f0f1; color: #50575e; font-size: 12px; font-weight: 600;
            }

            /* -- Upload Zone -- */
            .tfe-upload-zone {
                border: 2px dashed #c3c4c7; border-radius: 8px; padding: 40px 20px;
                text-align: center; cursor: pointer; transition: all .15s ease;
                background: #f6f7f7;
            }
            .tfe-upload-zone:hover,
            .tfe-upload-zone.tfe-drag-over { border-color: #2271b1; background: #f0f7fc; }
            .tfe-upload-zone.tfe-drag-over .tfe-upload-icon { color: #2271b1; transform: translateY(-3px); }
            .tfe-upload-icon { color: #787c82; margin-bottom: 12px; transition: all .15s ease; }
            .tfe-upload-text { font-size: 14px; color: #1d2327; margin: 0 0 6px; }
            .tfe-upload-link { color: #2271b1; cursor: pointer; text-decoration: underline; }
            .tfe-upload-link:hover { color: #135e96; }
            .tfe-upload-hint { font-size: 12px; color: #787c82; margin: 0; }
            .tfe-file-input { display: none; }
            .tfe-upload-actions {
                display: flex; align-items: center; justify-content: space-between;
                margin-top: 12px; min-height: 36px;
            }
            .tfe-file-name { font-size: 13px; color: #50575e; font-style: italic; }

            /* -- Font Rows -- */
            .tfe-font-row {
                display: flex; align-items: flex-start; gap: 20px;
                padding: 18px 0; border-bottom: 1px solid #f0f0f1;
            }
            .tfe-font-row:last-child { border-bottom: none; padding-bottom: 0; }
            .tfe-font-row:first-child { padding-top: 8px; }
            .tfe-font-preview {
                flex: 0 0 160px; font-size: 28px; font-weight: 400;
                color: #1d2327; line-height: 1.2; padding-top: 2px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            }
            .tfe-font-info { flex: 1; min-width: 0; }
            .tfe-font-name { margin: 0 0 4px; font-size: 14px; font-weight: 600; }
            .tfe-font-meta { font-size: 12px; color: #787c82; display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
            .tfe-meta-sep { color: #c3c4c7; }
            .tfe-has-italic { font-style: italic; }
            .tfe-formats { text-transform: uppercase; font-size: 11px; letter-spacing: .3px; }
            .tfe-font-actions { flex: 0 0 auto; padding-top: 2px; }
            .tfe-delete-btn {
                display: inline-flex; align-items: center; gap: 4px;
                color: #b32d2e !important; border-color: #b32d2e !important;
            }
            .tfe-delete-btn:hover { background: #b32d2e !important; color: #fff !important; }
            .tfe-delete-btn:hover svg { stroke: #fff; }
            .tfe-delete-btn svg { stroke: #b32d2e; width: 14px; height: 14px; }

            /* -- Variant Details -- */
            .tfe-variant-details { margin-top: 10px; }
            .tfe-variant-details summary {
                cursor: pointer; font-size: 12px; color: #2271b1;
                user-select: none; outline: none;
            }
            .tfe-variant-details summary:hover { color: #135e96; }
            .tfe-variant-table { margin-top: 8px; font-size: 13px; }
            .tfe-variant-table th { font-size: 12px; text-transform: uppercase; letter-spacing: .3px; }
            .tfe-variant-table .tfe-file-formats { font-size: 11px; text-transform: uppercase; color: #787c82; }

            /* -- Empty State -- */
            .tfe-empty { text-align: center; padding: 30px 20px; color: #787c82; }
            .tfe-empty p { margin: 0 0 8px; }
            .tfe-hint { font-size: 12px; }

            /* -- Footer -- */
            .tfe-footer { font-size: 12px; color: #787c82; margin-top: 10px; }
            .tfe-footer a { color: #2271b1; text-decoration: none; }
            .tfe-footer a:hover { text-decoration: underline; }

            /* -- Responsive -- */
            @media (max-width: 782px) {
                .tfe-font-row { flex-direction: column; gap: 10px; }
                .tfe-font-preview { flex: none; font-size: 22px; }
            }
        </style>

        <script>
        (function() {
            var zone  = document.getElementById('tfe-drop-zone');
            var input = document.getElementById('tfe-file-input');
            var label = document.getElementById('tfe-file-name');

            if ( ! zone || ! input ) return;

            // Click zone to trigger file input
            zone.addEventListener('click', function(e) {
                if ( e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT' ) {
                    input.click();
                }
            });

            // Show selected filename
            input.addEventListener('change', function() {
                label.textContent = this.files.length ? this.files[0].name : '';
            });

            // Drag & drop
            ['dragenter','dragover'].forEach(function(ev) {
                zone.addEventListener(ev, function(e) { e.preventDefault(); zone.classList.add('tfe-drag-over'); });
            });
            ['dragleave','drop'].forEach(function(ev) {
                zone.addEventListener(ev, function(e) { e.preventDefault(); zone.classList.remove('tfe-drag-over'); });
            });
            zone.addEventListener('drop', function(e) {
                if ( e.dataTransfer.files.length ) {
                    input.files = e.dataTransfer.files;
                    label.textContent = e.dataTransfer.files[0].name;
                }
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // ADMIN ACTIONS (Upload / Delete)
    // =========================================================================

    public function handle_admin_actions() {
        if ( ! isset( $_POST['tfe_action'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['tfe_action'] );

        if ( $action === 'upload' ) {
            $this->handle_upload();
        } elseif ( $action === 'delete' ) {
            $this->handle_delete();
        }
    }

    private function handle_upload() {
        if ( ! wp_verify_nonce( $_POST[ self::NONCE_KEY ] ?? '', 'tfe_upload' ) ) {
            $this->add_notice( 'error', __( 'Security check failed. Please try again.', 'transfonter-to-elementor' ) );
            return;
        }

        if ( empty( $_FILES['tfe_zip']['tmp_name'] ) || $_FILES['tfe_zip']['error'] !== UPLOAD_ERR_OK ) {
            $this->add_notice( 'error', __( 'No file uploaded or upload error occurred.', 'transfonter-to-elementor' ) );
            return;
        }

        $file = $_FILES['tfe_zip'];

        // Validate it's a zip
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'zip' ) {
            $this->add_notice( 'error', __( 'Please upload a .zip file.', 'transfonter-to-elementor' ) );
            return;
        }

        // Open and validate zip contents
        $zip = new ZipArchive();
        if ( $zip->open( $file['tmp_name'] ) !== true ) {
            $this->add_notice( 'error', __( 'Could not open zip file.', 'transfonter-to-elementor' ) );
            return;
        }

        // Look for stylesheet.css in the zip (may be at root or in a subfolder)
        $stylesheet_content = false;
        $stylesheet_prefix  = '';

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( basename( $name ) === 'stylesheet.css' ) {
                $stylesheet_content = $zip->getFromIndex( $i );
                $stylesheet_prefix  = dirname( $name );
                if ( $stylesheet_prefix === '.' ) {
                    $stylesheet_prefix = '';
                }
                break;
            }
        }

        if ( $stylesheet_content === false ) {
            $zip->close();
            $this->add_notice( 'error', __( 'No stylesheet.css found in the zip. Is this a Transfonter export?', 'transfonter-to-elementor' ) );
            return;
        }

        // Extract font-family name from the stylesheet
        $family_name = '';
        if ( preg_match( "/font-family:\s*['\"]([^'\"]+)['\"]/i", $stylesheet_content, $fm ) ) {
            $family_name = $fm[1];
        }

        if ( empty( $family_name ) ) {
            $zip->close();
            $this->add_notice( 'error', __( 'Could not determine font-family name from stylesheet.css.', 'transfonter-to-elementor' ) );
            return;
        }

        // Create folder name from family name
        $folder_name = sanitize_file_name( strtolower( str_replace( ' ', '-', $family_name ) ) );

        // Ensure fonts directory exists
        if ( ! is_dir( $this->fonts_dir ) ) {
            if ( ! wp_mkdir_p( $this->fonts_dir ) ) {
                $zip->close();
                $this->add_notice( 'error', sprintf(
                    __( 'Could not create fonts directory: %s', 'transfonter-to-elementor' ),
                    $this->fonts_dir
                ) );
                return;
            }
        }

        $target_dir = $this->fonts_dir . '/' . $folder_name;

        // Check if font already exists — remove to allow re-upload / update
        if ( is_dir( $target_dir ) ) {
            $this->delete_directory( $target_dir );
        }

        if ( ! wp_mkdir_p( $target_dir ) ) {
            $zip->close();
            $this->add_notice( 'error', sprintf(
                __( 'Could not create font directory: %s', 'transfonter-to-elementor' ),
                $target_dir
            ) );
            return;
        }

        // Extract font files and stylesheet (skip demo.html and other non-font files)
        $allowed_ext = [ 'css', 'woff2', 'woff', 'ttf', 'eot', 'otf', 'svg' ];
        $extracted   = 0;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $name     = $zip->getNameIndex( $i );
            $basename = basename( $name );
            $ext_file = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

            // Skip directories and non-font files
            if ( substr( $name, -1 ) === '/' ) continue;
            if ( ! in_array( $ext_file, $allowed_ext, true ) ) continue;

            // If files are in a subfolder, only extract those under the stylesheet's prefix
            if ( $stylesheet_prefix !== '' && strpos( $name, $stylesheet_prefix . '/' ) !== 0 ) continue;

            $content = $zip->getFromIndex( $i );
            if ( $content !== false ) {
                file_put_contents( $target_dir . '/' . $basename, $content );
                $extracted++;
            }
        }

        $zip->close();

        // Clear cache
        delete_transient( self::CACHE_KEY );

        // Count variants
        $faces = $this->parse_stylesheet( $target_dir . '/stylesheet.css', $folder_name );
        $variant_count = count( $faces );

        $this->add_notice( 'success', sprintf(
            __( '"%s" installed successfully — %d variants, %d files. Remember to regenerate CSS in Elementor → Tools.', 'transfonter-to-elementor' ),
            $family_name,
            $variant_count,
            $extracted
        ) );

        // Redirect to avoid form resubmission
        wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tfe_notice=1' ) );
        exit;
    }

    private function handle_delete() {
        $folder = sanitize_file_name( $_POST['tfe_folder'] ?? '' );

        if ( empty( $folder ) ) {
            $this->add_notice( 'error', __( 'Invalid font folder.', 'transfonter-to-elementor' ) );
            return;
        }

        if ( ! wp_verify_nonce( $_POST[ self::NONCE_KEY ] ?? '', 'tfe_delete_' . $folder ) ) {
            $this->add_notice( 'error', __( 'Security check failed.', 'transfonter-to-elementor' ) );
            return;
        }

        $target_dir = $this->fonts_dir . '/' . $folder;

        // Safety: ensure the target is actually inside the fonts directory
        $real_fonts  = realpath( $this->fonts_dir );
        $real_target = realpath( $target_dir );

        if ( $real_target === false || strpos( $real_target, $real_fonts ) !== 0 || $real_target === $real_fonts ) {
            $this->add_notice( 'error', __( 'Invalid font directory.', 'transfonter-to-elementor' ) );
            return;
        }

        if ( $this->delete_directory( $target_dir ) ) {
            delete_transient( self::CACHE_KEY );
            $this->add_notice( 'success', sprintf(
                __( 'Font "%s" deleted.', 'transfonter-to-elementor' ),
                $folder
            ) );
        } else {
            $this->add_notice( 'error', sprintf(
                __( 'Could not delete font directory: %s', 'transfonter-to-elementor' ),
                $target_dir
            ) );
        }

        wp_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tfe_notice=1' ) );
        exit;
    }

    // =========================================================================
    // ADMIN NOTICES (persisted via transient for post-redirect-get)
    // =========================================================================

    private function add_notice( $type, $message ) {
        $notices   = get_transient( 'tfe_admin_notices' ) ?: [];
        $notices[] = [ 'type' => $type, 'message' => $message ];
        set_transient( 'tfe_admin_notices', $notices, 60 );
    }

    public function show_admin_notices() {
        $notices = get_transient( 'tfe_admin_notices' );
        if ( empty( $notices ) ) return;

        foreach ( $notices as $notice ) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p><strong>Transfonter to Elementor:</strong> %s</p></div>',
                esc_attr( $notice['type'] ),
                wp_kses_post( $notice['message'] )
            );
        }

        delete_transient( 'tfe_admin_notices' );
    }

    // =========================================================================
    // FONT PARSING & CSS GENERATION
    // =========================================================================

    /**
     * Get all font families with their parsed face data.
     *
     * @param bool $bypass_cache Force re-scan (used on admin page)
     * @return array [ 'Family Name' => [ 'faces' => [...], 'folder' => '...' ] ]
     */
    private function get_font_families( $bypass_cache = false ) {
        if ( ! $bypass_cache ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $families = [];

        if ( ! is_dir( $this->fonts_dir ) ) {
            return $families;
        }

        $dirs = glob( $this->fonts_dir . '/*', GLOB_ONLYDIR );
        if ( empty( $dirs ) ) {
            return $families;
        }

        foreach ( $dirs as $dir ) {
            $folder_name = basename( $dir );
            $family_name = $this->folder_to_family_name( $folder_name );
            $stylesheet  = $dir . '/stylesheet.css';

            if ( file_exists( $stylesheet ) ) {
                $css_content = file_get_contents( $stylesheet );
                if ( preg_match( "/font-family:\s*['\"]([^'\"]+)['\"]/i", $css_content, $fm ) ) {
                    $family_name = $fm[1];
                }
                $faces = $this->parse_stylesheet( $stylesheet, $folder_name );
            } else {
                $faces = $this->detect_faces_from_files( $dir, $folder_name );
            }

            if ( ! empty( $faces ) ) {
                $families[ $family_name ] = [
                    'faces'  => $faces,
                    'folder' => $folder_name,
                ];
            }
        }

        if ( ! $bypass_cache ) {
            set_transient( self::CACHE_KEY, $families, self::CACHE_TTL );
        }

        return $families;
    }

    private function folder_to_family_name( $folder ) {
        return ucwords( str_replace( [ '-', '_' ], ' ', $folder ) );
    }

    private function parse_stylesheet( $stylesheet_path, $folder_name ) {
        $css   = file_get_contents( $stylesheet_path );
        $faces = [];

        preg_match_all( '/@font-face\s*\{([^}]+)\}/s', $css, $blocks );
        if ( empty( $blocks[1] ) ) return $faces;

        foreach ( $blocks[1] as $block ) {
            $face = [
                'weight'  => '400',
                'style'   => 'normal',
                'files'   => [],
                'display' => 'swap',
            ];

            if ( preg_match( '/font-weight:\s*(\d+|normal|bold|lighter|bolder)/i', $block, $m ) ) {
                $face['weight'] = $this->normalize_weight( $m[1] );
            }
            if ( preg_match( '/font-style:\s*(normal|italic|oblique)/i', $block, $m ) ) {
                $face['style'] = strtolower( $m[1] );
            }
            if ( preg_match( '/font-display:\s*(\w+)/i', $block, $m ) ) {
                $face['display'] = strtolower( $m[1] );
            }

            // Match url('file') format('fmt') pairs
            preg_match_all( "/url\(['\"]?([^'\")\s]+)['\"]?\)\s*format\(['\"]?([^'\")\s]+)['\"]?\)/i", $block, $srcs, PREG_SET_ORDER );

            foreach ( $srcs as $src ) {
                $filename = basename( preg_replace( '/[?#].*$/', '', $src[1] ) );
                $format   = strtolower( $src[2] );
                $face['files'][ $format ] = $filename;
            }

            // Catch standalone eot without format()
            if ( ! isset( $face['files']['embedded-opentype'] ) ) {
                if ( preg_match( "/url\(['\"]?([^'\")\s]+\.eot)(\?[^'\")\s]*)?['\"]?\)/i", $block, $eot ) ) {
                    $filename = basename( preg_replace( '/[?#].*$/', '', $eot[1] ) );
                    $face['files']['embedded-opentype'] = $filename;
                }
            }

            if ( ! empty( $face['files'] ) ) {
                $face['folder'] = $folder_name;
                $faces[] = $face;
            }
        }

        return $faces;
    }

    private function detect_faces_from_files( $dir, $folder_name ) {
        $extensions = [ 'woff2', 'woff', 'ttf', 'otf', 'eot', 'svg' ];
        $files = [];

        foreach ( $extensions as $ext ) {
            $found = glob( $dir . '/*.' . $ext );
            if ( $found ) {
                foreach ( $found as $f ) {
                    $files[ $ext ][] = basename( $f );
                }
            }
        }

        if ( empty( $files ) ) return [];

        $groups = [];
        foreach ( $files as $ext => $filenames ) {
            foreach ( $filenames as $filename ) {
                $base = preg_replace( '/\.' . preg_quote( $ext, '/' ) . '$/i', '', $filename );
                $groups[ $base ][ $this->extension_to_format( $ext ) ] = $filename;
            }
        }

        $faces = [];
        foreach ( $groups as $base => $formats ) {
            $faces[] = [
                'weight'  => $this->guess_weight_from_filename( $base ),
                'style'   => $this->guess_style_from_filename( $base ),
                'files'   => $formats,
                'display' => 'swap',
                'folder'  => $folder_name,
            ];
        }

        return $faces;
    }

    private function generate_font_face_css() {
        $families = $this->get_font_families();
        $css = '';
        $format_order = [ 'woff2', 'woff', 'truetype', 'opentype', 'embedded-opentype', 'svg' ];

        foreach ( $families as $family_name => $data ) {
            $faces = $data['faces'];
            foreach ( $faces as $face ) {
                $src_parts = [];
                $folder    = $face['folder'];

                foreach ( $format_order as $format ) {
                    if ( isset( $face['files'][ $format ] ) ) {
                        $url = $this->fonts_url . '/' . $folder . '/' . $face['files'][ $format ];
                        $src_parts[] = "url('{$url}') format('{$format}')";
                    }
                }

                if ( empty( $src_parts ) ) continue;

                $src = implode( ",\n       ", $src_parts );
                $css .= "@font-face {\n";
                $css .= "  font-family: '{$family_name}';\n";
                $css .= "  src: {$src};\n";
                $css .= "  font-weight: {$face['weight']};\n";
                $css .= "  font-style: {$face['style']};\n";
                $css .= "  font-display: {$face['display']};\n";
                $css .= "}\n\n";
            }
        }

        return $css;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function normalize_weight( $weight ) {
        $map = [ 'normal' => '400', 'bold' => '700', 'lighter' => '300', 'bolder' => '800' ];
        return $map[ strtolower( $weight ) ] ?? $weight;
    }

    private function extension_to_format( $ext ) {
        $map = [ 'woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype', 'eot' => 'embedded-opentype', 'svg' => 'svg' ];
        return $map[ $ext ] ?? $ext;
    }

    private function guess_weight_from_filename( $filename ) {
        $lower = strtolower( $filename );
        $weights = [
            'thin' => '100', 'hairline' => '100', 'extralight' => '200', 'ultralight' => '200',
            'extra-light' => '200', 'light' => '300', 'regular' => '400', 'normal' => '400',
            'medium' => '500', 'semibold' => '600', 'semi-bold' => '600', 'demibold' => '600',
            'bold' => '700', 'extrabold' => '800', 'extra-bold' => '800', 'ultrabold' => '800',
            'black' => '900', 'heavy' => '900',
        ];
        foreach ( $weights as $keyword => $value ) {
            if ( strpos( $lower, $keyword ) !== false ) return $value;
        }
        return '400';
    }

    private function guess_style_from_filename( $filename ) {
        $lower = strtolower( $filename );
        return ( strpos( $lower, 'italic' ) !== false || strpos( $lower, 'oblique' ) !== false ) ? 'italic' : 'normal';
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function delete_directory( $dir ) {
        if ( ! is_dir( $dir ) ) return false;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getRealPath() );
            } else {
                unlink( $item->getRealPath() );
            }
        }

        return rmdir( $dir );
    }
}

// Initialize
new Transfonter_To_Elementor();
