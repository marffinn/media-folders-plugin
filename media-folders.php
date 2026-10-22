<?php
/**
 * Plugin Name: Media Folders
 * Plugin URI: https://example.com/
 * Description: Organize Media Library attachments into folders that are real directories, so file URLs reflect the folder path.
 * Version: 2.0.0
 * Author: Manus
 * License: GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: media-folders
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Media_Folders_Plugin {
    const TAXONOMY = 'mf_media_folder';
    const NONCE_ACTION = 'mf_save_media_folder';
    const PAGE_SLUG = 'media-folders';
    const OPTION_LOCATION = 'mf_base_location';

    public function __construct() {
        add_action( 'init', array( $this, 'register_folder_taxonomy' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_library_assets' ) );
        add_action( 'admin_post_mf_create_folder', array( $this, 'handle_create_folder' ) );
        add_action( 'admin_post_mf_delete_folder', array( $this, 'handle_delete_folder' ) );
        add_action( 'admin_post_mf_assign_media', array( $this, 'handle_assign_media' ) );
        add_action( 'wp_ajax_mf_assign_attachment', array( $this, 'ajax_assign_attachment' ) );
        add_action( 'add_meta_boxes_attachment', array( $this, 'register_attachment_meta_box' ) );
        add_action( 'save_post_attachment', array( $this, 'save_attachment_folder' ), 10, 2 );
        add_filter( 'manage_media_columns', array( $this, 'add_media_folder_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_media_folder_column' ), 10, 2 );
        add_action( 'restrict_manage_posts', array( $this, 'render_media_folder_filter' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_media_library_query' ) );
        add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_folder_actions' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_folder_action' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
        add_action( 'admin_post_mf_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'template_redirect', array( $this, 'maybe_redirect_moved_file' ), 1 );
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 1 );
    }

    public static function activate() {
        $plugin = new self();
        $plugin->register_folder_taxonomy();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function register_folder_taxonomy() {
        $labels = array(
            'name'          => __( 'Media Folders', 'media-folders' ),
            'singular_name' => __( 'Media Folder', 'media-folders' ),
        );

        register_taxonomy(
            self::TAXONOMY,
            array( 'attachment' ),
            array(
                'labels'            => $labels,
                'public'            => false,
                'show_ui'           => false,
                'show_in_nav_menus' => false,
                'show_admin_column' => false,
                'hierarchical'      => true,
                'rewrite'           => false,
                'query_var'         => false,
                'capabilities'      => array(
                    'manage_terms' => 'manage_options',
                    'edit_terms'   => 'manage_options',
                    'delete_terms' => 'manage_options',
                    'assign_terms' => 'manage_options',
                ),
            )
        );
    }

    public function register_admin_menu() {
        add_media_page(
            __( 'Media Folders', 'media-folders' ),
            __( 'Folders', 'media-folders' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_folders_page' )
        );
    }

    private function get_folder_attachment_count( $folder_id ) {
        $attachment_ids = get_posts(
            array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query'              => array(
                    array(
                        'taxonomy' => self::TAXONOMY,
                        'field'    => 'term_id',
                        'terms'    => absint( $folder_id ),
                    ),
                ),
            )
        );

        return count( $attachment_ids );
    }

    public function enqueue_media_library_assets( $hook_suffix ) {
        if ( 'upload.php' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_enqueue_style(
            'media-folders-library',
            plugin_dir_url( __FILE__ ) . 'assets/media-folders-library.css',
            array(),
            '2.0.0'
        );
        wp_enqueue_script(
            'media-folders-library',
            plugin_dir_url( __FILE__ ) . 'assets/media-folders-library.js',
            array( 'jquery' ),
            '2.0.0',
            true
        );

        $folders = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        wp_localize_script(
            'media-folders-library',
            'MediaFoldersLibrary',
            array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'mf_assign_attachment' ),
                'folders'       => is_wp_error( $folders ) ? array() : array_map(
                    function ( $folder ) {
                        return array(
                            'id'    => (int) $folder->term_id,
                            'name'  => $folder->name,
                            'count' => $this->get_folder_attachment_count( $folder->term_id ),
                        );
                    },
                    $folders
                ),
                'foldersUrl'    => admin_url( 'upload.php?page=' . self::PAGE_SLUG ),
                'assigningText' => __( 'Assigning…', 'media-folders' ),
                'successText'   => __( 'File moved to the folder.', 'media-folders' ),
                'errorText'     => __( 'The file could not be moved.', 'media-folders' ),
            )
        );
    }

    public function ajax_assign_attachment() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-folders' ) ), 403 );
        }

        check_ajax_referer( 'mf_assign_attachment', 'nonce' );
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $folder_id = isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0;

        $result = $this->assign_attachment( $attachment_id, $folder_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success(
            array(
                'message' => 0 === $folder_id ? __( 'File moved out of its folder.', 'media-folders' ) : __( 'File moved to the folder.', 'media-folders' ),
                'count'   => $folder_id > 0 ? $this->get_folder_attachment_count( $folder_id ) : 0,
                'url'     => wp_get_attachment_url( $attachment_id ),
            )
        );
    }

    public function render_folders_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage media folders.', 'media-folders' ) );
        }

        $folders = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Media Folders', 'media-folders' ); ?></h1>
            <p><?php esc_html_e( 'Folders are real directories. Assigning a file to a folder physically moves it, so its URL contains the folder name.', 'media-folders' ); ?></p>

            <div class="card" style="max-width: 700px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e( 'Create a folder', 'media-folders' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="mf_create_folder">
                    <?php wp_nonce_field( 'mf_create_folder', 'mf_create_folder_nonce' ); ?>
                    <label for="mf-folder-name"><strong><?php esc_html_e( 'Folder name', 'media-folders' ); ?></strong></label>
                    <p>
                        <input type="text" id="mf-folder-name" name="folder_name" class="regular-text" required maxlength="200">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Create folder', 'media-folders' ); ?></button>
                    </p>
                </form>
            </div>

            <?php $this->render_settings_panel(); ?>

            <?php $this->render_assign_media_panel( $folders ); ?>

            <h2><?php esc_html_e( 'Existing folders', 'media-folders' ); ?></h2>
            <?php if ( is_wp_error( $folders ) || empty( $folders ) ) : ?>
                <p><?php esc_html_e( 'No folders have been created yet.', 'media-folders' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'media-folders' ); ?></th>
                            <th><?php esc_html_e( 'Public URL', 'media-folders' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'media-folders' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'media-folders' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $folders as $folder ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $folder->name ); ?></strong></td>
                            <td><code><?php echo esc_html( $this->get_folder_url( $folder ) . '/' ); ?></code></td>
                            <td><?php echo esc_html( number_format_i18n( $this->get_folder_attachment_count( $folder->term_id ) ) ); ?></td>
                            <td>
                                <a class="button" href="<?php echo esc_url( admin_url( 'upload.php?post_type=attachment&mf_folder=' . $folder->term_id ) ); ?>">
                                    <?php esc_html_e( 'View files', 'media-folders' ); ?>
                                </a>
                                <a class="button-link-delete" style="margin-left: 12px;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mf_delete_folder&term_id=' . $folder->term_id ), 'mf_delete_folder_' . $folder->term_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this folder? Its files will be moved back to the default uploads location and will remain in the Media Library.', 'media-folders' ) ); ?>');">
                                    <?php esc_html_e( 'Delete', 'media-folders' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_assign_media_panel( $folders ) {
        $media_query = new WP_Query(
            array(
                'post_type'              => 'attachment',
                'post_status'            => array( 'inherit', 'private' ),
                'posts_per_page'         => -1,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
            )
        );
        ?>
        <style>
            .mf-assign-list-wrap { max-height: 560px; overflow: auto; border: 1px solid #c3c4c7; }
            .mf-assign-list { border: 0; margin: 0; }
            .mf-assign-list th { position: sticky; top: 0; background: #fff; z-index: 1; box-shadow: 0 1px 0 #c3c4c7; }
            .mf-assign-list td, .mf-assign-list th { padding: 4px 10px; vertical-align: middle; line-height: 1.3; }
            .mf-assign-list .mf-file-name { font-weight: 600; }
            .mf-assign-list .mf-file-base { color: #646970; margin-left: 8px; font-size: 12px; }
            .mf-assign-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
        </style>
        <div class="card" style="max-width: 1100px; padding: 20px; margin-top: 20px;">
            <h2><?php esc_html_e( 'Assign files to a folder', 'media-folders' ); ?></h2>
            <p><?php esc_html_e( 'Select files below, choose a destination folder, and click Assign selected files. Files are physically moved into the folder.', 'media-folders' ); ?></p>
            <?php if ( empty( $folders ) ) : ?>
                <p><?php esc_html_e( 'Create a folder above before assigning files.', 'media-folders' ); ?></p>
            <?php elseif ( ! $media_query->have_posts() ) : ?>
                <p><?php esc_html_e( 'No media files are available yet.', 'media-folders' ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="mf_assign_media">
                    <?php wp_nonce_field( 'mf_assign_media', 'mf_assign_media_nonce' ); ?>
                    <div class="mf-assign-toolbar">
                        <label for="mf-bulk-folder"><strong><?php esc_html_e( 'Destination folder', 'media-folders' ); ?></strong></label>
                        <select name="folder_id" id="mf-bulk-folder" required>
                            <option value=""><?php esc_html_e( 'Choose a folder', 'media-folders' ); ?></option>
                            <?php foreach ( $folders as $folder ) : ?>
                                <option value="<?php echo esc_attr( $folder->term_id ); ?>"><?php echo esc_html( $folder->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Assign selected files', 'media-folders' ); ?></button>
                        <span style="margin-left: 16px;"></span>
                        <input type="search" id="mf-filter-text" placeholder="<?php esc_attr_e( 'Search file name…', 'media-folders' ); ?>" style="min-width: 220px;">
                        <select id="mf-filter-folder">
                            <option value=""><?php esc_html_e( 'All files', 'media-folders' ); ?></option>
                            <option value="__none__"><?php esc_html_e( 'No folder', 'media-folders' ); ?></option>
                            <?php foreach ( $folders as $folder ) : ?>
                                <option value="<?php echo esc_attr( $folder->name ); ?>"><?php echo esc_html( $folder->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="mf-count" class="description"></span>
                    </div>
                    <div class="mf-assign-list-wrap">
                    <table class="widefat striped mf-assign-list">
                        <thead>
                            <tr>
                                <th style="width: 32px;"><input type="checkbox" id="mf-select-all" aria-label="<?php esc_attr_e( 'Select all visible files', 'media-folders' ); ?>"></th>
                                <th><?php esc_html_e( 'File', 'media-folders' ); ?></th>
                                <th style="width: 180px;"><?php esc_html_e( 'Current folder', 'media-folders' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ( $media_query->have_posts() ) : $media_query->the_post(); ?>
                            <?php
                            $attachment_id = get_the_ID();
                            $assigned_terms = get_the_terms( $attachment_id, self::TAXONOMY );
                            $has_folder = ! empty( $assigned_terms ) && ! is_wp_error( $assigned_terms );
                            $assigned_names = $has_folder ? implode( ', ', wp_list_pluck( $assigned_terms, 'name' ) ) : '';
                            $title = get_the_title( $attachment_id );
                            $basename = wp_basename( (string) get_attached_file( $attachment_id ) );
                            ?>
                            <tr class="mf-row" data-search="<?php echo esc_attr( strtolower( $title . ' ' . $basename ) ); ?>" data-folder="<?php echo esc_attr( $has_folder ? $assigned_names : '__none__' ); ?>">
                                <td><input type="checkbox" class="mf-media-checkbox" name="attachment_ids[]" value="<?php echo esc_attr( $attachment_id ); ?>"></td>
                                <td>
                                    <span class="mf-file-name"><?php echo esc_html( $title ); ?></span>
                                    <?php if ( $basename && $basename !== $title && $basename !== $title . '.' . pathinfo( $basename, PATHINFO_EXTENSION ) ) : ?>
                                        <span class="mf-file-base"><?php echo esc_html( $basename ); ?></span>
                                    <?php else : ?>
                                        <span class="mf-file-base"><?php echo esc_html( $basename ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $has_folder ? esc_html( $assigned_names ) : '<span class="description">' . esc_html__( 'No folder', 'media-folders' ) . '</span>'; ?></td>
                            </tr>
                        <?php endwhile; wp_reset_postdata(); ?>
                        </tbody>
                    </table>
                    </div>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Assign selected files', 'media-folders' ); ?></button></p>
                </form>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var selectAll = document.getElementById('mf-select-all');
                    var text = document.getElementById('mf-filter-text');
                    var folder = document.getElementById('mf-filter-folder');
                    var counter = document.getElementById('mf-count');
                    var rows = Array.prototype.slice.call(document.querySelectorAll('.mf-row'));
                    if (!selectAll) { return; }

                    function visibleRows() {
                        return rows.filter(function (r) { return r.style.display !== 'none'; });
                    }
                    function updateCount() {
                        var checked = rows.filter(function (r) { return r.querySelector('.mf-media-checkbox').checked; }).length;
                        counter.textContent = visibleRows().length + ' / ' + rows.length + ' files, ' + checked + ' selected';
                    }
                    function applyFilter() {
                        var q = text.value.trim().toLowerCase();
                        var f = folder.value;
                        rows.forEach(function (r) {
                            var okText = !q || r.getAttribute('data-search').indexOf(q) !== -1;
                            var okFolder = !f || r.getAttribute('data-folder') === f;
                            r.style.display = (okText && okFolder) ? '' : 'none';
                        });
                        selectAll.checked = false;
                        updateCount();
                    }
                    selectAll.addEventListener('change', function () {
                        visibleRows().forEach(function (r) {
                            r.querySelector('.mf-media-checkbox').checked = selectAll.checked;
                        });
                        updateCount();
                    });
                    rows.forEach(function (r) {
                        r.querySelector('.mf-media-checkbox').addEventListener('change', updateCount);
                    });
                    text.addEventListener('input', applyFilter);
                    folder.addEventListener('change', applyFilter);
                    updateCount();
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_create_folder() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to create media folders.', 'media-folders' ) );
        }

        check_admin_referer( 'mf_create_folder', 'mf_create_folder_nonce' );
        $folder_name = isset( $_POST['folder_name'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_name'] ) ) : '';
        $back = admin_url( 'upload.php?page=' . self::PAGE_SLUG );

        if ( '' === $folder_name ) {
            wp_safe_redirect( add_query_arg( 'mf_message', 'empty_folder', $back ) );
            exit;
        }

        $slug = sanitize_title( $folder_name );
        $problem = $this->validate_folder_slug( $slug );
        if ( is_wp_error( $problem ) ) {
            wp_safe_redirect( add_query_arg( array( 'mf_message' => 'custom_error', 'mf_error' => rawurlencode( $problem->get_error_message() ) ), $back ) );
            exit;
        }

        $result = wp_insert_term( $folder_name, self::TAXONOMY, array( 'slug' => $slug ) );
        $message = is_wp_error( $result ) ? 'folder_error' : 'folder_created';
        wp_safe_redirect( add_query_arg( 'mf_message', $message, $back ) );
        exit;
    }

    public function handle_delete_folder() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to delete media folders.', 'media-folders' ) );
        }

        $term_id = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
        check_admin_referer( 'mf_delete_folder_' . $term_id );
        $back = admin_url( 'upload.php?page=' . self::PAGE_SLUG );

        $term = $term_id > 0 ? get_term( $term_id, self::TAXONOMY ) : false;
        if ( ! $term || is_wp_error( $term ) ) {
            wp_safe_redirect( add_query_arg( 'mf_message', 'folder_deleted', $back ) );
            exit;
        }

        // Move every file back to the default uploads location before removing the folder.
        $ids = get_objects_in_term( $term_id, self::TAXONOMY );
        $failed = 0;
        if ( ! is_wp_error( $ids ) ) {
            foreach ( $ids as $attachment_id ) {
                if ( is_wp_error( $this->assign_attachment( (int) $attachment_id, 0 ) ) ) {
                    $failed++;
                }
            }
        }

        if ( $failed > 0 ) {
            wp_safe_redirect( add_query_arg( array( 'mf_message' => 'delete_failed', 'updated' => $failed ), $back ) );
            exit;
        }

        $dir = $this->get_folder_dir( $term );
        if ( is_dir( $dir ) ) {
            $index = $dir . '/index.php';
            if ( file_exists( $index ) && 1 === count( (array) glob( $dir . '/*' ) ) ) {
                @unlink( $index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only succeeds when empty.
        }

        wp_delete_term( $term_id, self::TAXONOMY );
        wp_safe_redirect( add_query_arg( 'mf_message', 'folder_deleted', $back ) );
        exit;
    }

    public function handle_assign_media() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to assign media folders.', 'media-folders' ) );
        }

        check_admin_referer( 'mf_assign_media', 'mf_assign_media_nonce' );
        $folder_id = isset( $_POST['folder_id'] ) ? absint( $_POST['folder_id'] ) : 0;
        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['attachment_ids'] ) ) : array();
        $term = $folder_id > 0 ? get_term( $folder_id, self::TAXONOMY ) : false;

        if ( ! $term || is_wp_error( $term ) || empty( $attachment_ids ) ) {
            wp_safe_redirect( add_query_arg( 'mf_message', 'assignment_error', admin_url( 'upload.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }

        $updated_count = 0;
        $failed_count = 0;
        foreach ( $attachment_ids as $attachment_id ) {
            if ( is_wp_error( $this->assign_attachment( $attachment_id, $folder_id ) ) ) {
                $failed_count++;
                continue;
            }
            $updated_count++;
        }

        wp_safe_redirect( add_query_arg( array( 'mf_message' => 'media_assigned', 'updated' => $updated_count, 'failed' => $failed_count ), admin_url( 'upload.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    public function register_attachment_meta_box() {
        add_meta_box(
            'mf-attachment-folder',
            __( 'Media Folder', 'media-folders' ),
            array( $this, 'render_attachment_meta_box' ),
            'attachment',
            'side',
            'default'
        );
    }

    public function render_attachment_meta_box( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<p>' . esc_html__( 'Only administrators can change media folders.', 'media-folders' ) . '</p>';
            return;
        }

        wp_nonce_field( self::NONCE_ACTION, 'mf_media_folder_nonce' );
        $assigned_terms = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
        $assigned_term_id = ( ! is_wp_error( $assigned_terms ) && ! empty( $assigned_terms ) ) ? (int) $assigned_terms[0] : 0;
        $folders = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        ?>
        <p>
            <label for="mf-attachment-folder-select">
                <span class="screen-reader-text"><?php esc_html_e( 'Media folder', 'media-folders' ); ?></span>
                <select name="mf_folder_id" id="mf-attachment-folder-select" style="width: 100%;">
                    <option value="0"><?php esc_html_e( 'No folder', 'media-folders' ); ?></option>
                    <?php foreach ( $folders as $folder ) : ?>
                        <option value="<?php echo esc_attr( $folder->term_id ); ?>" <?php selected( $assigned_term_id, $folder->term_id ); ?>>
                            <?php echo esc_html( $folder->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p class="description"><?php esc_html_e( 'A file can be assigned to one folder. Use the Media Library filter to view a folder.', 'media-folders' ); ?></p>
        <?php
    }

    public function save_attachment_folder( $post_id, $post ) {
        if ( ! current_user_can( 'manage_options' ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST['mf_media_folder_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mf_media_folder_nonce'] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        $folder_id = isset( $_POST['mf_folder_id'] ) ? absint( $_POST['mf_folder_id'] ) : 0;
        $this->assign_attachment( $post_id, $folder_id );
    }

    public function add_media_folder_column( $columns ) {
        $columns['mf_media_folder'] = __( 'Folder', 'media-folders' );
        return $columns;
    }

    public function render_media_folder_column( $column_name, $post_id ) {
        if ( 'mf_media_folder' !== $column_name ) {
            return;
        }

        $terms = get_the_terms( $post_id, self::TAXONOMY );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            esc_html_e( 'No folder', 'media-folders' );
            return;
        }

        $names = wp_list_pluck( $terms, 'name' );
        echo esc_html( implode( ', ', $names ) );
    }

    public function render_media_folder_filter( $post_type ) {
        if ( 'attachment' !== $post_type || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $selected_folder = isset( $_GET['mf_folder'] ) ? absint( $_GET['mf_folder'] ) : 0;
        $folders = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        ?>
        <select name="mf_folder">
            <option value="0"><?php esc_html_e( 'All media folders', 'media-folders' ); ?></option>
            <?php foreach ( $folders as $folder ) : ?>
                <option value="<?php echo esc_attr( $folder->term_id ); ?>" <?php selected( $selected_folder, $folder->term_id ); ?>>
                    <?php echo esc_html( $folder->name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function filter_media_library_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
            return;
        }

        $folder_id = isset( $_GET['mf_folder'] ) ? absint( $_GET['mf_folder'] ) : 0;
        if ( $folder_id > 0 && current_user_can( 'manage_options' ) ) {
            $query->set(
                'tax_query',
                array(
                    array(
                        'taxonomy' => self::TAXONOMY,
                        'field'    => 'term_id',
                        'terms'    => $folder_id,
                    ),
                )
            );
        }
    }

    public function add_bulk_folder_actions( $actions ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        $folders = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );
        foreach ( $folders as $folder ) {
            $actions[ 'mf_assign_' . $folder->term_id ] = sprintf(
                __( 'Move to folder: %s', 'media-folders' ),
                $folder->name
            );
        }
        $actions['mf_remove_folder'] = __( 'Remove from folder', 'media-folders' );
        return $actions;
    }

    public function handle_bulk_folder_action( $redirect_to, $action, $post_ids ) {
        if ( 0 !== strpos( $action, 'mf_' ) || ! current_user_can( 'manage_options' ) ) {
            return $redirect_to;
        }

        $term_id = 0;
        if ( 0 === strpos( $action, 'mf_assign_' ) ) {
            $term_id = absint( str_replace( 'mf_assign_', '', $action ) );
            $term = get_term( $term_id, self::TAXONOMY );
            if ( ! $term || is_wp_error( $term ) ) {
                return $redirect_to;
            }
        } elseif ( 'mf_remove_folder' !== $action ) {
            return $redirect_to;
        }

        $ok = 0;
        $failed = 0;
        foreach ( $post_ids as $post_id ) {
            if ( 'attachment' !== get_post_type( $post_id ) ) {
                continue;
            }
            if ( is_wp_error( $this->assign_attachment( $post_id, $term_id ) ) ) {
                $failed++;
            } else {
                $ok++;
            }
        }

        return add_query_arg( array( 'mf_bulk_updated' => $ok, 'mf_bulk_failed' => $failed ), $redirect_to );
    }

    /* ------------------------------------------------------------------
     * Physical file handling
     * ---------------------------------------------------------------- */

    private function get_base_location() {
        return 'root' === get_option( self::OPTION_LOCATION, 'uploads' ) ? 'root' : 'uploads';
    }

    /**
     * Base directory and URL under which folder directories are created.
     * "uploads": wp-content/uploads/<folder>/file   "root": <site root>/<folder>/file
     */
    private function get_base_paths() {
        if ( 'root' === $this->get_base_location() ) {
            return array(
                'dir' => untrailingslashit( wp_normalize_path( ABSPATH ) ),
                'url' => untrailingslashit( site_url() ),
            );
        }
        $uploads = wp_get_upload_dir();
        return array(
            'dir' => untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ),
            'url' => untrailingslashit( $uploads['baseurl'] ),
        );
    }

    private function get_folder_dir( $term ) {
        $base = $this->get_base_paths();
        return $base['dir'] . '/' . $term->slug;
    }

    private function get_folder_url( $term ) {
        $base = $this->get_base_paths();
        return $base['url'] . '/' . rawurlencode( $term->slug );
    }

    private function any_files_assigned() {
        $terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids' ) );
        if ( is_wp_error( $terms ) ) {
            return false;
        }
        foreach ( $terms as $term_id ) {
            $ids = get_objects_in_term( $term_id, self::TAXONOMY );
            if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
                return true;
            }
        }
        return false;
    }

    private function validate_folder_slug( $slug ) {
        if ( '' === $slug || false !== strpos( $slug, '%' ) ) {
            return new WP_Error( 'mf_slug', __( 'The folder name must contain letters or numbers that can be used in a URL.', 'media-folders' ) );
        }

        $reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'cgi-bin', 'index', 'feed' );
        if ( in_array( $slug, $reserved, true ) ) {
            return new WP_Error( 'mf_slug', __( 'That folder name is reserved by WordPress.', 'media-folders' ) );
        }

        if ( 'root' === $this->get_base_location() ) {
            $base = $this->get_base_paths();
            if ( file_exists( $base['dir'] . '/' . $slug ) ) {
                return new WP_Error( 'mf_slug', __( 'A file or directory with that name already exists in the site root.', 'media-folders' ) );
            }
            if ( get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) ) {
                return new WP_Error( 'mf_slug', __( 'A page or post already uses that URL; a real directory would block it.', 'media-folders' ) );
            }
            if ( ! wp_is_writable( $base['dir'] ) ) {
                return new WP_Error( 'mf_slug', __( 'The site root directory is not writable by the web server.', 'media-folders' ) );
            }
        } elseif ( preg_match( '/^\d{4}$/', $slug ) || preg_match( '/^\d{2}$/', $slug ) ) {
            return new WP_Error( 'mf_slug', __( 'Purely numeric names clash with the year/month upload directories.', 'media-folders' ) );
        }

        return true;
    }

    /**
     * Assign an attachment to a folder (0 = no folder) and physically move its files.
     *
     * @return true|WP_Error
     */
    public function assign_attachment( $attachment_id, $folder_id ) {
        $attachment_id = absint( $attachment_id );
        $folder_id = absint( $folder_id );

        if ( 'attachment' !== get_post_type( $attachment_id ) ) {
            return new WP_Error( 'mf_invalid', __( 'Invalid media file.', 'media-folders' ) );
        }

        $old_terms = wp_get_object_terms( $attachment_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
        $old_terms = is_wp_error( $old_terms ) ? array() : $old_terms;

        if ( $folder_id > 0 ) {
            $term = get_term( $folder_id, self::TAXONOMY );
            if ( ! $term || is_wp_error( $term ) ) {
                return new WP_Error( 'mf_invalid', __( 'Invalid media folder.', 'media-folders' ) );
            }
            $target_dir = $this->get_folder_dir( $term );
            if ( ! wp_mkdir_p( $target_dir ) ) {
                return new WP_Error( 'mf_mkdir', __( 'The folder directory could not be created (check permissions).', 'media-folders' ) );
            }
            if ( ! file_exists( $target_dir . '/index.php' ) ) {
                @file_put_contents( $target_dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        } else {
            if ( empty( $old_terms ) ) {
                return true; // Already unassigned; leave the file where it is.
            }
            $uploads = wp_upload_dir( get_post( $attachment_id )->post_date );
            $target_dir = $uploads['path'];
        }

        $moved = $this->move_attachment_files( $attachment_id, $target_dir );
        if ( is_wp_error( $moved ) ) {
            return $moved;
        }

        $result = wp_set_object_terms( $attachment_id, $folder_id > 0 ? array( $folder_id ) : array(), self::TAXONOMY, false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $recount = $old_terms;
        if ( $folder_id > 0 ) {
            $recount[] = $folder_id;
        }
        if ( ! empty( $recount ) ) {
            wp_update_term_count_now( array_unique( array_map( 'absint', $recount ) ), self::TAXONOMY );
            clean_term_cache( $recount, self::TAXONOMY );
        }

        return true;
    }

    /**
     * Move the original file, all generated sizes, the pre-scaling original and
     * edit backups into $target_dir, then update WordPress records.
     */
    private function move_attachment_files( $attachment_id, $target_dir ) {
        $file = get_attached_file( $attachment_id, true );
        if ( ! $file || ! file_exists( $file ) ) {
            return new WP_Error( 'mf_missing', __( 'The original file is missing on disk.', 'media-folders' ) );
        }

        $source_dir = untrailingslashit( wp_normalize_path( dirname( $file ) ) );
        $target_dir = untrailingslashit( wp_normalize_path( $target_dir ) );
        if ( $source_dir === $target_dir ) {
            return true;
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        $backup = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

        $names = array( wp_basename( $file ) );
        if ( is_array( $meta ) ) {
            if ( ! empty( $meta['original_image'] ) ) {
                $names[] = $meta['original_image'];
            }
            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $names[] = $size['file'];
                    }
                }
            }
        }
        if ( is_array( $backup ) ) {
            foreach ( $backup as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $names[] = $size['file'];
                }
            }
        }
        $names = array_values( array_unique( array_map( 'wp_basename', $names ) ) );

        // Never overwrite anything: abort if any name already exists in the destination.
        foreach ( $names as $name ) {
            if ( file_exists( $source_dir . '/' . $name ) && file_exists( $target_dir . '/' . $name ) ) {
                return new WP_Error(
                    'mf_clash',
                    sprintf( __( 'A file named "%s" already exists in the destination.', 'media-folders' ), $name )
                );
            }
        }

        // Move files, rolling back on failure.
        $done = array();
        foreach ( $names as $name ) {
            $from = $source_dir . '/' . $name;
            if ( ! file_exists( $from ) ) {
                continue; // A size listed in metadata but absent on disk.
            }
            $to = $target_dir . '/' . $name;
            if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                if ( @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    @unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                } else {
                    foreach ( array_reverse( $done ) as $moved_name ) {
                        @rename( $target_dir . '/' . $moved_name, $source_dir . '/' . $moved_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                    }
                    return new WP_Error( 'mf_move', sprintf( __( 'Could not move "%s" (check file permissions).', 'media-folders' ), $name ) );
                }
            }
            $done[] = $name;
        }

        // Update the stored path (relative to the uploads directory).
        $uploads = wp_get_upload_dir();
        $rel_dir = $this->relative_path( $uploads['basedir'], $target_dir );
        $new_attached = ( '' === $rel_dir ? '' : $rel_dir . '/' ) . wp_basename( $file );
        update_post_meta( $attachment_id, '_wp_attached_file', $new_attached );
        if ( is_array( $meta ) ) {
            $meta['file'] = $new_attached;
            wp_update_attachment_metadata( $attachment_id, $meta );
        }

        // Rewrite old URLs in post content and keep redirects for the old paths.
        $old_url_dir = $this->path_to_url( $source_dir );
        $new_url_dir = $this->path_to_url( $target_dir );
        if ( $old_url_dir && $new_url_dir ) {
            $this->update_references_and_redirects( $attachment_id, $names, $old_url_dir, $new_url_dir );
        }

        clean_post_cache( $attachment_id );
        return true;
    }

    private function update_references_and_redirects( $attachment_id, $names, $old_url_dir, $new_url_dir ) {
        global $wpdb;

        $map = get_post_meta( $attachment_id, '_mf_redirects', true );
        $map = is_array( $map ) ? $map : array();

        foreach ( $names as $name ) {
            $old_url = $old_url_dir . '/' . $name;
            $new_url = $new_url_dir . '/' . $name;
            $old_path = rawurldecode( (string) wp_parse_url( $old_url, PHP_URL_PATH ) );
            $new_path = rawurldecode( (string) wp_parse_url( $new_url, PHP_URL_PATH ) );

            // Point earlier redirects at the newest location (no redirect chains).
            foreach ( $map as $from => $to ) {
                if ( $to === $old_path ) {
                    $map[ $from ] = $new_path;
                }
            }
            if ( $old_path !== $new_path ) {
                $map[ $old_path ] = $new_path;
            }
            unset( $map[ $new_path ] );

            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                    $old_url,
                    $new_url,
                    '%' . $wpdb->esc_like( $old_url ) . '%'
                )
            );
        }

        update_post_meta( $attachment_id, '_mf_redirects', $map );
        delete_post_meta( $attachment_id, '_mf_old_path' );
        foreach ( array_keys( $map ) as $old_path ) {
            add_post_meta( $attachment_id, '_mf_old_path', $old_path, false );
        }
    }

    /** 301-redirect requests for a file's previous location to its new one. */
    public function maybe_redirect_moved_file() {
        global $wpdb;

        if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $path = rawurldecode( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) );
        if ( '' === $path || false === strpos( wp_basename( $path ), '.' ) ) {
            return;
        }

        $post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mf_old_path' AND meta_value = %s LIMIT 1", $path )
        );
        if ( ! $post_id ) {
            return;
        }

        $map = get_post_meta( (int) $post_id, '_mf_redirects', true );
        if ( is_array( $map ) && ! empty( $map[ $path ] ) ) {
            wp_safe_redirect( implode( '/', array_map( 'rawurlencode', explode( '/', $map[ $path ] ) ) ), 301 );
            exit;
        }
    }

    /** Clean "../" segments out of attachment URLs when files live outside the uploads dir. */
    public function filter_attachment_url( $url, $attachment_id ) {
        $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $file && 0 === strpos( $file, '../' ) ) {
            $uploads = wp_get_upload_dir();
            $abs = $this->normalize_dots( wp_normalize_path( $uploads['basedir'] ) . '/' . $file );
            $clean = $this->path_to_url( dirname( $abs ) );
            if ( $clean ) {
                return $clean . '/' . rawurlencode( wp_basename( $abs ) );
            }
        }
        return $url;
    }

    public function filter_image_srcset( $sources ) {
        if ( is_array( $sources ) ) {
            foreach ( $sources as $width => $source ) {
                if ( isset( $source['url'] ) && false !== strpos( $source['url'], '/../' ) ) {
                    $url = $source['url'];
                    while ( preg_match( '#/[^/]+/\.\./#', $url ) ) {
                        $url = preg_replace( '#/[^/]+/\.\./#', '/', $url, 1 );
                    }
                    $sources[ $width ]['url'] = $url;
                }
            }
        }
        return $sources;
    }

    /* ---- path helpers ---- */

    private function relative_path( $from, $to ) {
        $from = array_values( array_filter( explode( '/', wp_normalize_path( $from ) ), 'strlen' ) );
        $to = array_values( array_filter( explode( '/', wp_normalize_path( $to ) ), 'strlen' ) );
        while ( $from && $to && $from[0] === $to[0] ) {
            array_shift( $from );
            array_shift( $to );
        }
        return implode( '/', array_merge( array_fill( 0, count( $from ), '..' ), $to ) );
    }

    private function normalize_dots( $path ) {
        $out = array();
        foreach ( explode( '/', wp_normalize_path( $path ) ) as $i => $segment ) {
            if ( '..' === $segment ) {
                array_pop( $out );
            } elseif ( '.' !== $segment && ( '' !== $segment || 0 === $i ) ) {
                $out[] = $segment;
            }
        }
        return implode( '/', $out );
    }

    private function path_to_url( $dir ) {
        $dir = untrailingslashit( wp_normalize_path( $dir ) );
        $uploads = wp_get_upload_dir();
        $basedir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
        if ( $dir === $basedir || 0 === strpos( $dir . '/', $basedir . '/' ) ) {
            return untrailingslashit( $uploads['baseurl'] ) . substr( $dir, strlen( $basedir ) );
        }
        $root = untrailingslashit( wp_normalize_path( ABSPATH ) );
        if ( $dir === $root || 0 === strpos( $dir . '/', $root . '/' ) ) {
            return untrailingslashit( site_url() ) . substr( $dir, strlen( $root ) );
        }
        return false;
    }

    /* ---- settings ---- */

    private function render_settings_panel() {
        $location = $this->get_base_location();
        $locked = $this->any_files_assigned();
        $root = untrailingslashit( site_url() );
        $uploads = wp_get_upload_dir();
        ?>
        <div class="card" style="max-width: 700px; padding: 20px; margin-top: 20px;">
            <h2><?php esc_html_e( 'Where folders live', 'media-folders' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mf_save_settings">
                <?php wp_nonce_field( 'mf_save_settings', 'mf_save_settings_nonce' ); ?>
                <p>
                    <label>
                        <input type="radio" name="base_location" value="uploads" <?php checked( $location, 'uploads' ); ?> <?php disabled( $locked ); ?>>
                        <strong><?php esc_html_e( 'Inside the uploads directory (recommended)', 'media-folders' ); ?></strong><br>
                        <code><?php echo esc_html( untrailingslashit( $uploads['baseurl'] ) ); ?>/<?php esc_html_e( '[folder]', 'media-folders' ); ?>/file.pdf</code>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="radio" name="base_location" value="root" <?php checked( $location, 'root' ); ?> <?php disabled( $locked ); ?>>
                        <strong><?php esc_html_e( 'In the site root', 'media-folders' ); ?></strong><br>
                        <code><?php echo esc_html( $root ); ?>/<?php esc_html_e( '[folder]', 'media-folders' ); ?>/file.pdf</code>
                    </label>
                </p>
                <?php if ( 'root' === $location && ! wp_is_writable( ABSPATH ) ) : ?>
                    <p class="notice notice-warning inline"><?php esc_html_e( 'The site root is not writable by the web server, so folders cannot be created there.', 'media-folders' ); ?></p>
                <?php endif; ?>
                <?php if ( $locked ) : ?>
                    <p class="description"><?php esc_html_e( 'The location is locked while files are assigned to folders. Move them out (or delete the folders) to change it.', 'media-folders' ); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Site-root folders become real top-level directories next to wp-admin and wp-content. Names that match existing files, pages or WordPress directories are refused.', 'media-folders' ); ?></p>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save location', 'media-folders' ); ?></button></p>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to change this setting.', 'media-folders' ) );
        }
        check_admin_referer( 'mf_save_settings', 'mf_save_settings_nonce' );

        if ( ! $this->any_files_assigned() ) {
            $value = ( isset( $_POST['base_location'] ) && 'root' === $_POST['base_location'] ) ? 'root' : 'uploads';
            update_option( self::OPTION_LOCATION, $value );
        }

        wp_safe_redirect( add_query_arg( 'mf_message', 'settings_saved', admin_url( 'upload.php?page=' . self::PAGE_SLUG ) ) );
        exit;
    }

    public function render_admin_notices() {
        if ( ! isset( $_GET['mf_message'] ) && ! isset( $_GET['mf_bulk_updated'] ) ) {
            return;
        }

        $message = '';
        $type = 'updated';
        if ( isset( $_GET['mf_bulk_updated'] ) ) {
            $message = sprintf( __( '%d media item(s) updated.', 'media-folders' ), absint( $_GET['mf_bulk_updated'] ) );
            if ( ! empty( $_GET['mf_bulk_failed'] ) ) {
                $message .= ' ' . sprintf( __( '%d could not be moved (missing file, name clash or permissions).', 'media-folders' ), absint( $_GET['mf_bulk_failed'] ) );
                $type = 'warning';
            }
        } else {
            $messages = array(
                'folder_created' => __( 'Folder created.', 'media-folders' ),
                'folder_deleted' => __( 'Folder deleted. Files were not deleted.', 'media-folders' ),
                'empty_folder'   => __( 'Please enter a folder name.', 'media-folders' ),
                'folder_error'   => __( 'The folder could not be created. It may already exist.', 'media-folders' ),
                'media_assigned' => sprintf( __( '%d media item(s) moved to the folder.', 'media-folders' ), isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0 ) . ( ! empty( $_GET['failed'] ) ? ' ' . sprintf( __( '%d could not be moved (missing file, name clash or permissions).', 'media-folders' ), absint( $_GET['failed'] ) ) : '' ),
                'delete_failed'  => sprintf( __( 'Folder not deleted: %d file(s) could not be moved back.', 'media-folders' ), isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0 ),
                'settings_saved' => __( 'Settings saved.', 'media-folders' ),
                'custom_error'   => isset( $_GET['mf_error'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mf_error'] ) ) ) : '',
                'assignment_error' => __( 'Choose a folder and select at least one media file.', 'media-folders' ),
            );
            $key = sanitize_key( wp_unslash( $_GET['mf_message'] ) );
            $message = isset( $messages[ $key ] ) ? $messages[ $key ] : '';
            $type = in_array( $key, array( 'folder_error', 'assignment_error', 'custom_error', 'delete_failed' ), true ) ? 'error' : ( ( 'media_assigned' === $key && ! empty( $_GET['failed'] ) ) ? 'warning' : 'updated' );
        }

        if ( '' !== $message ) {
            printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
        }
    }
}

register_activation_hook( __FILE__, array( 'Media_Folders_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Media_Folders_Plugin', 'deactivate' ) );

new Media_Folders_Plugin();
