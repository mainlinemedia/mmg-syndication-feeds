<?php
/**
 * Plugin Name: MMG Syndication Feeds
 * Plugin URI:  https://mainlinemediagroup.com
 * Description: Generates platform-compliant RSS feeds for Yahoo News and MSN syndication. Supports category/tag filters and multiple tag-based sub-feeds per platform. v2: Scoreline Feeds engine (sanitizer, validation gates, rendition ladder, skip log) with the original feed URLs and shape preserved.
 * Version:     2.1.4
 * Author:      Mainline Media Group
 * Update URI:  https://github.com/mainlinemedia/mmg-syndication-feeds
 * Author URI:  https://mainlinemediagroup.com
 * License:     GPL2
 * Text Domain: mmg-syndication-feeds
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MMGSF_VERSION', '2.1.4' );
const MMGSF_MODIFIED_JITTER = 60; // modified must exceed published by this before an update element is emitted

require_once __DIR__ . '/includes/emitter.php';
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/sanitizer.php';
require_once __DIR__ . '/includes/validator.php';
require_once __DIR__ . '/includes/updater.php';

// ─────────────────────────────────────────────────────────────────
// 1. OPTIONS / DEFAULTS
// ─────────────────────────────────────────────────────────────────

function mmgsf_get_options() {
    $defaults = [
        // Shared
        'feed_title'       => get_bloginfo( 'name' ),
        'feed_description' => get_bloginfo( 'description' ),
        'feed_link'        => home_url(),
        'post_count'       => 30,
        'image_size'       => 'large',
        'categories'       => '',
        'exclude_cats'     => '',
        'tags'             => '',
        'exclude_tags'     => '',
        'utm_source'       => '',
        'utm_medium'       => '',
        // Yahoo
        'yahoo_enabled'    => 1,
        'yahoo_slug'       => 'yahoo',
        'yahoo_category'   => '',
        // MSN
        'msn_enabled'      => 1,
        'msn_slug'         => 'msn',
        // Affiliate/commerce guard (Yahoo prohibits affiliate links absent an
        // agreement). Domain list: operator-owned, unambiguous → skip item.
        // Path list: href substrings, weaker signal → unwrap the link.
        'affiliate_domains' => 'draftkings.com, fanduel.com, bet365.com, bovada.lv, caesars.com, sportsbook.fanatics.com, betmgm.com, espnbet.com',
        'affiliate_paths'   => 'sportsbook, promo-code, promocode, /betting/, /aff/, ?tag=, utm_campaign=affiliate, /affiliate/, ?affiliate=',
    ];
    $saved = get_option( 'mmgsf_options', [] );
    return wp_parse_args( $saved, $defaults );
}

function mmgsf_get_tag_feeds() {
    return get_option( 'mmgsf_tag_feeds', [] );
}

function mmgsf_feed_url( $slug ) {
    return home_url( '/?feed=' . rawurlencode( $slug ) );
}

// ─────────────────────────────────────────────────────────────────
// 2. REGISTER ALL FEEDS via add_feed()
// ─────────────────────────────────────────────────────────────────

add_action( 'init', 'mmgsf_register_all_feeds' );
function mmgsf_register_all_feeds() {
    $opts      = mmgsf_get_options();
    $tag_feeds = mmgsf_get_tag_feeds();

    // ── Yahoo main feed ──
    if ( ! empty( $opts['yahoo_enabled'] ) ) {
        $yahoo_slug = $opts['yahoo_slug'] . '-feed';
        add_feed( $yahoo_slug, function() use ( $opts ) {
            mmgsf_output_yahoo( $opts, [
                'categories'   => $opts['categories'],
                'exclude_cats' => $opts['exclude_cats'],
                'tags'         => $opts['tags'],
                'exclude_tags' => $opts['exclude_tags'],
            ] );
        } );
    }

    // ── MSN main feed ──
    if ( ! empty( $opts['msn_enabled'] ) ) {
        $msn_slug = $opts['msn_slug'] . '-feed';
        add_feed( $msn_slug, function() use ( $opts ) {
            mmgsf_output_msn( $opts, [
                'categories'   => $opts['categories'],
                'exclude_cats' => $opts['exclude_cats'],
                'tags'         => $opts['tags'],
                'exclude_tags' => $opts['exclude_tags'],
            ] );
        } );
    }

    // ── Tag feeds (per-platform) ──
    foreach ( $tag_feeds as $tf ) {
        if ( empty( $tf['slug'] ) ) continue;

        $merged = array_merge( $opts, [
            'feed_title' => ! empty( $tf['title'] )      ? $tf['title']      : $opts['feed_title'],
            'post_count' => ! empty( $tf['post_count'] )  ? $tf['post_count'] : $opts['post_count'],
            'image_size' => ! empty( $tf['image_size'] )  ? $tf['image_size'] : $opts['image_size'],
            'utm_source' => ! empty( $tf['utm_source'] )  ? $tf['utm_source'] : $opts['utm_source'],
            'utm_medium' => ! empty( $tf['utm_medium'] )  ? $tf['utm_medium'] : $opts['utm_medium'],
        ] );
        $tag_filter = [
            'categories'   => '',
            'exclude_cats' => '',
            'tags'         => $tf['tags'] ?? '',
            'exclude_tags' => '',
        ];

        $platforms = ! empty( $tf['platforms'] ) ? (array) $tf['platforms'] : [ 'yahoo', 'msn' ];

        if ( in_array( 'yahoo', $platforms, true ) && ! empty( $opts['yahoo_enabled'] ) ) {
            $tf_yahoo_slug = $tf['slug'] . '-yahoo-feed';
            add_feed( $tf_yahoo_slug, function() use ( $merged, $tag_filter ) {
                mmgsf_output_yahoo( $merged, $tag_filter );
            } );
        }
        if ( in_array( 'msn', $platforms, true ) && ! empty( $opts['msn_enabled'] ) ) {
            $tf_msn_slug = $tf['slug'] . '-msn-feed';
            add_feed( $tf_msn_slug, function() use ( $merged, $tag_filter ) {
                mmgsf_output_msn( $merged, $tag_filter );
            } );
        }
    }

    if ( get_transient( 'mmgsf_flush_rewrites' ) ) {
        flush_rewrite_rules( false );
        delete_transient( 'mmgsf_flush_rewrites' );
    }
}

// ─────────────────────────────────────────────────────────────────
// 3. ADMIN MENU
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'mmgsf_admin_menu' );
function mmgsf_admin_menu() {
    add_options_page(
        'MMG Syndication Feeds',
        'MMG Syndication',
        'manage_options',
        'mmg-syndication-feeds',
        'mmgsf_settings_page'
    );
}

// ─────────────────────────────────────────────────────────────────
// 4. SETTINGS REGISTRATION
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_init', 'mmgsf_register_settings' );
function mmgsf_register_settings() {
    register_setting( 'mmgsf_options_group', 'mmgsf_options', [
        'sanitize_callback' => 'mmgsf_sanitize_options',
    ] );

    add_settings_section( 'mmgsf_general', 'General Settings',    '__return_false', 'mmg-syndication-feeds' );
    add_settings_section( 'mmgsf_yahoo',   'Yahoo Feed Settings', '__return_false', 'mmg-syndication-feeds' );
    add_settings_section( 'mmgsf_msn',     'MSN Feed Settings',   '__return_false', 'mmg-syndication-feeds' );
    add_settings_section( 'mmgsf_filter',  'Content Filters',     '__return_false', 'mmg-syndication-feeds' );

    // General
    $general = [
        'feed_title'       => [ 'Feed Title',        'Channel <title> for both feeds.' ],
        'feed_description' => [ 'Feed Description',   'Channel <description>.' ],
        'feed_link'        => [ 'Site Link',           'Canonical homepage URL.' ],
        'post_count'       => [ 'Number of Posts',     'Recent posts per feed (Yahoo & MSN both recommend ≤30).' ],
        'image_size'       => [ 'Image Size',          'WP image size for thumbnails (large, medium, full, etc.).' ],
        'utm_source'       => [ 'UTM Source',          'Optional — appended to article links.' ],
        'utm_medium'       => [ 'UTM Medium',          'Optional — appended to article links.' ],
    ];
    foreach ( $general as $key => [ $label, $desc ] ) {
        add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_field_cb', 'mmg-syndication-feeds', 'mmgsf_general', [ 'key' => $key, 'desc' => $desc ] );
    }

    // Yahoo
    $yahoo = [
        'yahoo_enabled'  => [ 'Enable Yahoo Feed',   'Generate the Yahoo-format RSS feed.' ],
        'yahoo_slug'     => [ 'Yahoo Feed Slug',      'Generates: /?feed={slug}-feed' ],
        'yahoo_category' => [ 'Default Category',     'Yahoo requires one category per article. Leave blank to use the first WP category.' ],
    ];
    foreach ( $yahoo as $key => [ $label, $desc ] ) {
        if ( $key === 'yahoo_enabled' ) {
            add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_checkbox_cb', 'mmg-syndication-feeds', 'mmgsf_yahoo', [ 'key' => $key, 'desc' => $desc ] );
        } else {
            add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_field_cb', 'mmg-syndication-feeds', 'mmgsf_yahoo', [ 'key' => $key, 'desc' => $desc ] );
        }
    }

    // MSN
    $msn = [
        'msn_enabled' => [ 'Enable MSN Feed', 'Generate the MSN-format RSS feed.' ],
        'msn_slug'    => [ 'MSN Feed Slug',    'Generates: /?feed={slug}-feed' ],
    ];
    foreach ( $msn as $key => [ $label, $desc ] ) {
        if ( $key === 'msn_enabled' ) {
            add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_checkbox_cb', 'mmg-syndication-feeds', 'mmgsf_msn', [ 'key' => $key, 'desc' => $desc ] );
        } else {
            add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_field_cb', 'mmg-syndication-feeds', 'mmgsf_msn', [ 'key' => $key, 'desc' => $desc ] );
        }
    }

    // Filters
    $filters = [
        'categories'        => [ 'Include Categories', 'Comma-separated category slugs. Leave blank for all.' ],
        'exclude_cats'      => [ 'Exclude Categories', 'Comma-separated category slugs to exclude.' ],
        'tags'              => [ 'Include Tags',        'Comma-separated tag slugs. Posts must have ANY of these tags.' ],
        'exclude_tags'      => [ 'Exclude Tags',        'Comma-separated tag slugs. Posts with ANY of these tags are excluded.' ],
        'affiliate_domains' => [ 'Affiliate Domain Blocklist', 'Operator domains (href host match). A match withholds the whole item from Yahoo.' ],
        'affiliate_paths'   => [ 'Affiliate Path Blocklist',   'URL substrings (href match only, never body text). A match removes the link but ships the article.' ],
    ];
    foreach ( $filters as $key => [ $label, $desc ] ) {
        add_settings_field( 'mmgsf_' . $key, $label, 'mmgsf_field_cb', 'mmg-syndication-feeds', 'mmgsf_filter', [ 'key' => $key, 'desc' => $desc ] );
    }
}

function mmgsf_sanitize_options( $input ) {
    $clean = [];
    $clean['feed_title']       = sanitize_text_field( $input['feed_title']       ?? get_bloginfo( 'name' ) );
    $clean['feed_description'] = sanitize_text_field( $input['feed_description'] ?? get_bloginfo( 'description' ) );
    $clean['feed_link']        = esc_url_raw( $input['feed_link']        ?? home_url() );
    $clean['post_count']       = absint( $input['post_count']       ?? 30 );
    $clean['image_size']       = sanitize_key( $input['image_size']       ?? 'large' );
    $clean['utm_source']       = sanitize_key( $input['utm_source']       ?? '' );
    $clean['utm_medium']       = sanitize_key( $input['utm_medium']       ?? '' );
    $clean['yahoo_enabled']    = ! empty( $input['yahoo_enabled'] ) ? 1 : 0;
    $clean['yahoo_slug']       = sanitize_title( $input['yahoo_slug']       ?? 'yahoo' );
    $clean['yahoo_category']   = sanitize_text_field( $input['yahoo_category']   ?? '' );
    $clean['msn_enabled']      = ! empty( $input['msn_enabled'] ) ? 1 : 0;
    $clean['msn_slug']         = sanitize_title( $input['msn_slug']         ?? 'msn' );
    $clean['categories']       = sanitize_text_field( $input['categories']       ?? '' );
    $clean['exclude_cats']     = sanitize_text_field( $input['exclude_cats']     ?? '' );
    $clean['tags']             = sanitize_text_field( $input['tags']             ?? '' );
    $clean['exclude_tags']     = sanitize_text_field( $input['exclude_tags']     ?? '' );
    $defaults                  = mmgsf_get_options();
    $clean['affiliate_domains'] = sanitize_text_field( $input['affiliate_domains'] ?? $defaults['affiliate_domains'] );
    $clean['affiliate_paths']   = sanitize_text_field( $input['affiliate_paths']   ?? $defaults['affiliate_paths'] );
    set_transient( 'mmgsf_flush_rewrites', 1, 30 );
    mmgsf_touch_config();
    return $clean;
}

function mmgsf_field_cb( $args ) {
    $opts  = mmgsf_get_options();
    $key   = $args['key'];
    $value = esc_attr( $opts[ $key ] ?? '' );
    $desc  = esc_html( $args['desc'] );
    echo "<input type='text' name='mmgsf_options[{$key}]' value='{$value}' class='regular-text' />";
    echo "<p class='description'>{$desc}</p>";
}

function mmgsf_checkbox_cb( $args ) {
    $opts    = mmgsf_get_options();
    $key     = $args['key'];
    $checked = ! empty( $opts[ $key ] ) ? 'checked' : '';
    $desc    = esc_html( $args['desc'] );
    echo "<label><input type='checkbox' name='mmgsf_options[{$key}]' value='1' {$checked} /> {$desc}</label>";
}

// ─────────────────────────────────────────────────────────────────
// 5. TAG FEED MANAGER ACTIONS
// ─────────────────────────────────────────────────────────────────

add_action( 'admin_post_mmgsf_save_tag_feed', 'mmgsf_handle_save_tag_feed' );
function mmgsf_handle_save_tag_feed() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgsf_tag_feed_nonce' );

    $feeds      = mmgsf_get_tag_feeds();
    $edit_index = ( isset( $_POST['edit_index'] ) && $_POST['edit_index'] !== '' ) ? (int) $_POST['edit_index'] : null;

    $platforms = [];
    if ( ! empty( $_POST['tf_platform_yahoo'] ) ) $platforms[] = 'yahoo';
    if ( ! empty( $_POST['tf_platform_msn'] ) )   $platforms[] = 'msn';
    if ( empty( $platforms ) ) $platforms = [ 'yahoo', 'msn' ];

    $entry = [
        'slug'       => sanitize_title( $_POST['tf_slug']       ?? '' ),
        'title'      => sanitize_text_field( $_POST['tf_title']      ?? '' ),
        'tags'       => sanitize_text_field( $_POST['tf_tags']       ?? '' ),
        'post_count' => absint( $_POST['tf_post_count'] ?? 30 ),
        'utm_source' => sanitize_key( $_POST['tf_utm_source'] ?? '' ),
        'utm_medium' => sanitize_key( $_POST['tf_utm_medium'] ?? '' ),
        'image_size' => sanitize_key( $_POST['tf_image_size'] ?? 'large' ),
        'platforms'  => $platforms,
    ];

    if ( empty( $entry['slug'] ) || empty( $entry['tags'] ) ) {
        wp_redirect( admin_url( 'options-general.php?page=mmg-syndication-feeds&mmgsf_error=missing_fields#tag-feeds' ) );
        exit;
    }

    if ( $edit_index !== null && isset( $feeds[ $edit_index ] ) ) {
        $feeds[ $edit_index ] = $entry;
    } else {
        $feeds[] = $entry;
    }

    update_option( 'mmgsf_tag_feeds', $feeds );
    set_transient( 'mmgsf_flush_rewrites', 1, 30 );
    wp_redirect( admin_url( 'options-general.php?page=mmg-syndication-feeds&mmgsf_saved=1#tag-feeds' ) );
    exit;
}

add_action( 'admin_post_mmgsf_clear_skip_log', 'mmgsf_handle_clear_skip_log' );
function mmgsf_handle_clear_skip_log() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgsf_clear_skip_log' );
    mmgsf_skip_log_clear();
    wp_redirect( admin_url( 'options-general.php?page=mmg-syndication-feeds#skip-log' ) );
    exit;
}

add_action( 'admin_post_mmgsf_delete_tag_feed', 'mmgsf_handle_delete_tag_feed' );
function mmgsf_handle_delete_tag_feed() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
    check_admin_referer( 'mmgsf_delete_tag_feed' );

    $index = (int) ( $_GET['index'] ?? -1 );
    $feeds = mmgsf_get_tag_feeds();
    if ( isset( $feeds[ $index ] ) ) {
        array_splice( $feeds, $index, 1 );
        update_option( 'mmgsf_tag_feeds', $feeds );
        set_transient( 'mmgsf_flush_rewrites', 1, 30 );
    }
    wp_redirect( admin_url( 'options-general.php?page=mmg-syndication-feeds&mmgsf_deleted=1#tag-feeds' ) );
    exit;
}

// ─────────────────────────────────────────────────────────────────
// 6. SETTINGS PAGE UI
// ─────────────────────────────────────────────────────────────────

function mmgsf_settings_page() {
    $opts      = mmgsf_get_options();
    $tag_feeds = mmgsf_get_tag_feeds();

    $yahoo_url = ! empty( $opts['yahoo_enabled'] ) ? mmgsf_feed_url( $opts['yahoo_slug'] . '-feed' ) : '';
    $msn_url   = ! empty( $opts['msn_enabled'] )   ? mmgsf_feed_url( $opts['msn_slug'] . '-feed' )   : '';

    $edit_index = ( isset( $_GET['edit_tag'] ) && $_GET['edit_tag'] !== '' ) ? (int) $_GET['edit_tag'] : null;
    $editing    = ( $edit_index !== null && isset( $tag_feeds[ $edit_index ] ) ) ? $tag_feeds[ $edit_index ] : null;
    ?>
    <div class="wrap">
        <h1>MMG Syndication Feeds <span style="font-size:13px;color:#888;font-weight:normal;">v<?php echo esc_html( MMGSF_VERSION ); ?></span></h1>

        <?php if ( isset( $_GET['mmgsf_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Tag feed saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgsf_deleted'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Tag feed deleted.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['mmgsf_error'] ) ) : ?>
            <div class="notice notice-error is-dismissible"><p>Slug and Tags are required.</p></div>
        <?php endif; ?>

        <h2>Live Feed URLs</h2>
        <?php if ( $yahoo_url ) : ?>
            <p><strong>Yahoo:</strong> <a href="<?php echo esc_url( $yahoo_url ); ?>" target="_blank"><?php echo esc_html( $yahoo_url ); ?></a></p>
        <?php else : ?>
            <p><strong>Yahoo:</strong> <em>Disabled</em></p>
        <?php endif; ?>
        <?php if ( $msn_url ) : ?>
            <p><strong>MSN:</strong> <a href="<?php echo esc_url( $msn_url ); ?>" target="_blank"><?php echo esc_html( $msn_url ); ?></a></p>
        <?php else : ?>
            <p><strong>MSN:</strong> <em>Disabled</em></p>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'mmgsf_options_group' );
            do_settings_sections( 'mmg-syndication-feeds' );
            submit_button( 'Save Settings' );
            ?>
        </form>

        <hr id="skip-log">
        <h2>Skip Log</h2>
        <p>Items or images withheld by a platform's validation gate in the last 7 days, and why. Both Yahoo and MSN reject silently on their side — this is the local answer to "why isn't this article on the feed".</p>
        <?php $log = mmgsf_skip_log_get(); ?>
        <?php if ( $log ) : ?>
            <table class="widefat striped" style="max-width:1100px;">
                <thead><tr><th>When (UTC)</th><th>Platform</th><th>Level</th><th>Post</th><th>Code</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice( $log, 0, 100 ) as $e ) : ?>
                    <tr>
                        <td><?php echo esc_html( gmdate( 'Y-m-d H:i', $e['ts'] ) ); ?></td>
                        <td><?php echo esc_html( $e['network'] ); ?></td>
                        <td><?php echo esc_html( $e['level'] ?? 'skip' ); ?></td>
                        <td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $e['post_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $e['post_title'] ); ?></a></td>
                        <td><code><?php echo esc_html( $e['code'] ?? '' ); ?></code></td>
                        <td><?php echo esc_html( $e['detail'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="mmgsf_clear_skip_log">
                <?php wp_nonce_field( 'mmgsf_clear_skip_log' ); ?>
                <?php submit_button( 'Clear Log', 'delete small', 'submit', false ); ?>
            </form>
        <?php else : ?>
            <p style="color:#888;font-style:italic;">Nothing withheld in the last 7 days.</p>
        <?php endif; ?>

        <hr id="tag-feeds">

        <h2>Tag Feed Manager</h2>
        <p>Create separate feed URLs filtered by specific WordPress tags. Each tag feed generates platform-specific URLs.</p>

        <?php if ( ! empty( $tag_feeds ) ) : ?>
        <table class="widefat striped" style="margin-bottom:20px;">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Title</th>
                    <th>Tags</th>
                    <th>Platforms</th>
                    <th>Feed URLs</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $tag_feeds as $i => $tf ) :
                $tf_platforms = ! empty( $tf['platforms'] ) ? (array) $tf['platforms'] : [ 'yahoo', 'msn' ];
            ?>
                <tr>
                    <td><code><?php echo esc_html( $tf['slug'] ); ?></code></td>
                    <td><?php echo esc_html( $tf['title'] ); ?></td>
                    <td><code><?php echo esc_html( $tf['tags'] ); ?></code></td>
                    <td><?php echo esc_html( implode( ', ', array_map( 'strtoupper', $tf_platforms ) ) ); ?></td>
                    <td style="font-size:12px;">
                        <?php if ( in_array( 'yahoo', $tf_platforms, true ) ) : ?>
                            <a href="<?php echo esc_url( mmgsf_feed_url( $tf['slug'] . '-yahoo-feed' ) ); ?>" target="_blank">Yahoo</a>
                        <?php endif; ?>
                        <?php if ( in_array( 'msn', $tf_platforms, true ) ) : ?>
                            <a href="<?php echo esc_url( mmgsf_feed_url( $tf['slug'] . '-msn-feed' ) ); ?>" target="_blank">MSN</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'options-general.php?page=mmg-syndication-feeds&edit_tag=' . $i . '#tag-feeds' ) ); ?>">Edit</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mmgsf_delete_tag_feed&index=' . $i ), 'mmgsf_delete_tag_feed' ) ); ?>"
                           onclick="return confirm('Delete this tag feed?');"
                           style="color:red;">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p style="color:#888;font-style:italic;">No tag feeds created yet.</p>
        <?php endif; ?>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;max-width:700px;border-radius:4px;">
            <h3><?php echo $editing ? 'Edit Tag Feed' : 'Add New Tag Feed'; ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="mmgsf_save_tag_feed">
                <?php wp_nonce_field( 'mmgsf_tag_feed_nonce' ); ?>
                <?php if ( $edit_index !== null ) : ?>
                    <input type="hidden" name="edit_index" value="<?php echo (int) $edit_index; ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="tf_slug">Feed Slug <span style="color:red">*</span></label></th>
                        <td>
                            <input type="text" id="tf_slug" name="tf_slug" class="regular-text"
                                   value="<?php echo esc_attr( $editing['slug'] ?? '' ); ?>"
                                   placeholder="e.g. nfl, politics, nascar">
                            <p class="description">Generates: <code>/?feed={slug}-yahoo-feed</code> and <code>/?feed={slug}-msn-feed</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tf_title">Feed Title</label></th>
                        <td>
                            <input type="text" id="tf_title" name="tf_title" class="regular-text"
                                   value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tf_tags">Tags <span style="color:red">*</span></label></th>
                        <td>
                            <input type="text" id="tf_tags" name="tf_tags" class="regular-text"
                                   value="<?php echo esc_attr( $editing['tags'] ?? '' ); ?>"
                                   placeholder="e.g. nascar, cup-series, daytona">
                            <p class="description">Comma-separated tag <strong>slugs</strong>. Posts must have at least one.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Platforms</th>
                        <td>
                            <?php $edit_platforms = ! empty( $editing['platforms'] ) ? (array) $editing['platforms'] : [ 'yahoo', 'msn' ]; ?>
                            <label><input type="checkbox" name="tf_platform_yahoo" value="1" <?php checked( in_array( 'yahoo', $edit_platforms, true ) ); ?>> Yahoo</label>
                            &nbsp;&nbsp;
                            <label><input type="checkbox" name="tf_platform_msn" value="1" <?php checked( in_array( 'msn', $edit_platforms, true ) ); ?>> MSN</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tf_post_count">Number of Posts</label></th>
                        <td><input type="number" id="tf_post_count" name="tf_post_count" min="1" max="200" value="<?php echo esc_attr( $editing['post_count'] ?? 30 ); ?>" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_utm_source">UTM Source</label></th>
                        <td><input type="text" id="tf_utm_source" name="tf_utm_source" class="regular-text" value="<?php echo esc_attr( $editing['utm_source'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_utm_medium">UTM Medium</label></th>
                        <td><input type="text" id="tf_utm_medium" name="tf_utm_medium" class="regular-text" value="<?php echo esc_attr( $editing['utm_medium'] ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tf_image_size">Image Size</label></th>
                        <td><input type="text" id="tf_image_size" name="tf_image_size" class="regular-text" value="<?php echo esc_attr( $editing['image_size'] ?? 'large' ); ?>" placeholder="large"></td>
                    </tr>
                </table>

                <?php submit_button( $editing ? 'Update Tag Feed' : 'Create Tag Feed', 'primary', 'submit', false ); ?>
                <?php if ( $editing ) : ?>
                    &nbsp;<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mmg-syndication-feeds#tag-feeds' ) ); ?>" class="button">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <hr>
        <p style="color:#888;font-size:12px;">After adding or editing feeds, go to <strong>Settings &rarr; Permalinks</strong> and click Save once to register the new feed slugs.</p>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────
// 7. SHARED HELPERS
// ─────────────────────────────────────────────────────────────────

/**
 * Decode WordPress title/text to plain UTF-8 for use inside CDATA.
 *
 * get_the_title() returns HTML entities from wptexturize (e.g. &#8217;
 * for smart quotes). Inside CDATA, XML parsers do NOT interpret entities,
 * so they would display literally. This helper decodes them.
 */
function mmgsf_plain( $text ) {
    return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

// mmgsf_cdata() now lives in includes/emitter.php (same breakout-safe impl).

function mmgsf_build_link( $post_link, $opts ) {
    $source = $opts['utm_source'] ?? '';
    $medium = $opts['utm_medium'] ?? '';
    if ( empty( $source ) && empty( $medium ) ) return $post_link;
    $sep   = ( strpos( $post_link, '?' ) !== false ) ? '&' : '?';
    $parts = [];
    if ( $source ) $parts[] = 'utm_source=' . rawurlencode( $source );
    if ( $medium ) $parts[] = 'utm_medium=' . rawurlencode( $medium );
    return $post_link . $sep . implode( '&', $parts );
}

/**
 * Lead image for a syndication item: 'full' rendition + ladder, gated on the
 * platform floor. Returns the picked image, or null with a $note explaining
 * why (for the skip log). Never upscales; never ships unverifiable assets.
 */
function mmgsf_resolve_lead_image( $post_id, $min_w, $min_h, $max_bytes, &$note = null ) {
    $note     = null;
    $thumb_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumb_id ) {
        $note = [ 'code' => 'no_featured_image', 'detail' => '' ];
        return null;
    }
    $img = mmgsf_attachment_image_data( $thumb_id, 'full' );
    if ( ! $img ) {
        $note = [ 'code' => 'no_featured_image', 'detail' => 'attachment ' . $thumb_id . ' unresolvable' ];
        return null;
    }
    $picked = mmgsf_pick_image_fit( $img, $min_w, $min_h, $max_bytes );
    if ( ! $picked ) {
        if ( ! empty( $img['file_missing'] ) ) {
            $note = [ 'code' => 'image_file_missing', 'detail' => 'attachment ' . $thumb_id . ' — no rendition file exists on disk (media-library edit?); re-upload or re-save the image' ];
        } elseif ( ! empty( $img['dims_unknown'] ) ) {
            $note = [ 'code' => 'image_dimensions_unknown', 'detail' => 'attachment ' . $thumb_id . ' at ' . $img['file'] . ' — dimensions unverifiable' ];
        } else {
            $note = [ 'code' => 'image_below_minimum', 'detail' => (int) $img['width'] . 'x' . (int) $img['height'] . " under {$min_w}x{$min_h} floor — shipped without image" ];
        }
    } elseif ( ! empty( $picked['file_note'] ) ) {
        $note = [ 'code' => 'image_file_missing', 'detail' => $picked['file_note'] ];
    }
    return $picked;
}

/** Yahoo sanitizer ruleset (allowlist, style strip, h1 demotion). */
function mmgsf_yahoo_rules() {
    return [
        'allowed_tags'       => [ 'a', 'b', 'blockquote', 'br', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'img', 'li', 'ol', 'ul', 'p', 'strong', 'iframe', 'embed', 'sub', 'sup', 'pre', 'figure', 'figcaption', 'section', 'span', 'table', 'tr', 'th', 'td' ],
        'allowed_attributes' => [
            'a'      => [ 'href', 'title' ],
            'img'    => [ 'src', 'alt', 'width', 'height' ],
            'iframe' => [ 'src', 'width', 'height', 'allowfullscreen' ],
            'embed'  => [ 'src', 'type', 'width', 'height' ],
            'th'     => [ 'colspan', 'rowspan', 'scope' ],
            'td'     => [ 'colspan', 'rowspan' ],
        ],
        'strip_style_attr'   => true,
        'href_schemes'       => [ 'https', 'http', 'mailto' ],
        'rename_tags'        => [ 'h1' => 'h2' ], // body h1 duplicates the item title on Yahoo's render
        'base_url'           => home_url(),
    ];
}

/** MSN sanitizer ruleset (verified tag set; YouTube embeds rejected at ingestion). */
function mmgsf_msn_rules() {
    return [
        'allowed_tags'         => [ 'b', 'i', 'em', 'strong', 'sub', 'sup', 'small', 'h1', 'h2', 'h3', 'h4', 'h5', 'a', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'col', 'caption', 'colgroup', 'ul', 'ol', 'li', 'p', 'div', 'span', 'br', 'blockquote', 'iframe', 'figure', 'figcaption' ],
        'allowed_attributes'   => [
            'a'      => [ 'href', 'title' ],
            'img'    => [ 'src', 'alt', 'width', 'height', 'title' ],
            'iframe' => [ 'src', 'width', 'height', 'allowfullscreen', 'frameborder' ],
            'td'     => [ 'colspan', 'rowspan' ],
            'th'     => [ 'colspan', 'rowspan', 'scope' ],
            'col'    => [ 'span' ],
        ],
        'strip_style_attr'     => true,
        'href_schemes'         => [ 'https', 'http', 'mailto' ],
        'iframe_hosts'         => [ 'x.com', 'twitter.com', 'facebook.com', 'instagram.com', 'pinterest.com', 'spotify.com', 'infogram.com', 'maps.google.com', 'google.com/maps', 'giphy.com', 'flourish.studio', 'flo.uri.sh', 'reddit.com', 'redditmedia.com', 'tiktok.com' ],
        'blocked_iframe_hosts' => [ 'youtube.com', 'youtube-nocookie.com', 'youtu.be' ],
        'base_url'             => home_url(),
    ];
}

/** MSN promo-card short title: only when over 54 chars, word-boundary cut. */
function mmgsf_short_title( $title ) {
    if ( mb_strlen( $title ) <= 54 ) {
        return '';
    }
    $cut = mb_substr( $title, 0, 53 );
    $sp  = mb_strrpos( $cut, ' ' );
    if ( $sp !== false && $sp > 20 ) {
        $cut = mb_substr( $cut, 0, $sp );
    }
    return rtrim( $cut, " ,;:-–—" ) . '…';
}

function mmgsf_affiliate_lists( $opts ) {
    return [
        array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $opts['affiliate_domains'] ?? '' ) ) ) ) ),
        array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $opts['affiliate_paths'] ?? '' ) ) ) ) ),
    ];
}

/**
 * 60s server-side cache for feed output, keyed on lastpostmodified so a new
 * publish busts it instantly (Yahoo polls every ~5 minutes).
 */
function mmgsf_cached_feed( $kind, $opts, $filters, $renderer ) {
    $key    = 'mmgsf_feed_' . md5( $kind . '|' . wp_json_encode( $filters ) . '|' . mmgsf_feed_lastmod() . '|' . MMGSF_VERSION );
    $cached = get_transient( $key );
    if ( $cached !== false ) {
        return $cached;
    }
    $xml = $renderer( $opts, $filters );
    set_transient( $key, $xml, 60 );
    return $xml;
}

function mmgsf_tag_slugs_to_ids( $slugs_string ) {
    if ( empty( trim( $slugs_string ) ) ) return [];
    $slugs = array_filter( array_map( 'trim', explode( ',', $slugs_string ) ) );
    $ids   = [];
    foreach ( $slugs as $slug ) {
        $term = get_term_by( 'slug', $slug, 'post_tag' );
        if ( $term ) $ids[] = $term->term_id;
    }
    return $ids;
}

function mmgsf_cat_slugs_to_ids( $slugs_string ) {
    if ( empty( trim( $slugs_string ) ) ) return [];
    $slugs = array_filter( array_map( 'trim', explode( ',', $slugs_string ) ) );
    $ids   = [];
    foreach ( $slugs as $slug ) {
        $term = get_term_by( 'slug', $slug, 'category' );
        if ( $term ) $ids[] = $term->term_id;
    }
    return $ids;
}

function mmgsf_build_query( $opts, $filters ) {
    $query_args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => max( 1, (int) $opts['post_count'] ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $cat_in  = mmgsf_cat_slugs_to_ids( $filters['categories'] ?? '' );
    $cat_out = mmgsf_cat_slugs_to_ids( $filters['exclude_cats'] ?? '' );
    if ( $cat_in )  $query_args['category__in']     = $cat_in;
    if ( $cat_out ) $query_args['category__not_in'] = $cat_out;

    $tag_in  = mmgsf_tag_slugs_to_ids( $filters['tags'] ?? '' );
    $tag_out = mmgsf_tag_slugs_to_ids( $filters['exclude_tags'] ?? '' );
    if ( $tag_in )  $query_args['tag__in']     = $tag_in;
    if ( $tag_out ) $query_args['tag__not_in'] = $tag_out;

    return new WP_Query( $query_args );
}

function mmgsf_no_cache_headers() {
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: Thu, 01 Jan 1970 00:00:00 GMT' );
}

/** Shared conditional-GET handling. Returns true when a 304 was served. */
function mmgsf_maybe_304() {
    $lastmod = mmgsf_feed_lastmod();
    if ( ! $lastmod ) {
        return false;
    }
    header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $lastmod ) . ' GMT' );
    if ( mmgsf_feed_not_modified( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '', $lastmod ) ) {
        status_header( 304 );
        return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────────
// 8. YAHOO FEED OUTPUT
//    Spec: https://publishers.yahooinc.com/docs/article-ingestion
//    Required: <title>, <description> (plain text, >3 words),
//              <guid>, <pubDate> (must not change), <updated>,
//              <link>, <content:encoded> (>150 words),
//              <media:thumbnail> (16:9, 1080 or 720px height),
//              <category> (one per item),
//              <img src> first inside content:encoded in figure/figcaption
// ─────────────────────────────────────────────────────────────────

function mmgsf_output_yahoo( $opts, $filters ) {
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );
    mmgsf_no_cache_headers();
    if ( mmgsf_maybe_304() ) {
        return;
    }
    echo mmgsf_cached_feed( 'yahoo', $opts, $filters, 'mmgsf_render_yahoo' );
}

function mmgsf_render_yahoo( $opts, $filters ) {
    $posts      = mmgsf_build_query( $opts, $filters );
    $last_build = mmgsf_rfc822( time() );
    $feed_url   = mmgsf_feed_url( ( $opts['yahoo_slug'] ?? 'yahoo' ) . '-feed' );
    $desc       = trim( (string) $opts['feed_description'] ) !== '' ? $opts['feed_description'] : ( trim( (string) get_bloginfo( 'description' ) ) !== '' ? get_bloginfo( 'description' ) : get_bloginfo( 'name' ) );
    [ $aff_domains, $aff_paths ] = mmgsf_affiliate_lists( $opts );

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0"' . "\n";
    $xml .= '     xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
    $xml .= '     xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
    $xml .= '     xmlns:atom="http://www.w3.org/2005/Atom"' . "\n";
    $xml .= '     xmlns:media="http://search.yahoo.com/mrss/"' . "\n";
    $xml .= '     xmlns:sy="http://purl.org/rss/1.0/modules/syndication/">' . "\n";
    $xml .= "  <channel>\n";
    $xml .= '    ' . mmgsf_el( 'title', $opts['feed_title'] ) . "\n";
    $xml .= '    <atom:link href="' . mmgsf_xml( $feed_url ) . '" rel="self" type="application/rss+xml"/>' . "\n";
    $xml .= '    ' . mmgsf_el( 'link', $opts['feed_link'] ) . "\n";
    $xml .= '    ' . mmgsf_el( 'description', $desc ) . "\n";
    // lastBuildDate, not channel pubDate/updated — <updated> is invalid at
    // channel level and was flagged in the production Yahoo audits.
    $xml .= '    ' . mmgsf_el( 'lastBuildDate', $last_build ) . "\n";
    $xml .= '    ' . mmgsf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
    $xml .= "    <sy:updatePeriod>hourly</sy:updatePeriod>\n";
    $xml .= "    <sy:updateFrequency>1</sy:updateFrequency>\n";
    $xml .= '    <generator>MMG Syndication Feeds ' . mmgsf_xml( MMGSF_VERSION ) . "</generator>\n";

    if ( $posts->have_posts() ) {
        while ( $posts->have_posts() ) {
            $posts->the_post();
            global $post;
            $post_id     = get_the_ID();
            $permalink   = get_permalink( $post_id );
            $pub_ts      = (int) get_post_time( 'U', true, $post_id );
            $mod_ts      = (int) get_post_modified_time( 'U', true, $post_id );
            $title_plain = mmgsf_plain( get_the_title( $post_id ) );
            $content     = apply_filters( 'the_content', get_the_content( null, false, $post_id ) );

            // ── Sanitize → affiliate guard → inline image gate → prune ──
            $san = mmgsf_sanitize( $content, mmgsf_yahoo_rules() );

            $guard = mmgsf_guard_affiliate_links( $san['html'], $aff_domains, $aff_paths, 'skip_item', 'unwrap' );
            foreach ( $guard['events'] as $ev ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, $ev['code'], $ev['detail'], 'warn' );
            }
            if ( $guard['skip'] ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, $guard['skip']['code'], $guard['skip']['detail'] );
                continue;
            }

            $gated = mmgsf_gate_inline_images( $guard['html'], 1280, 720, 5242880 );
            foreach ( $gated['events'] as $ev ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, $ev['code'], $ev['detail'], 'warn' );
            }
            $body_html = mmgsf_prune_empty_nodes( $gated['html'] );

            // ── Validation gates (Yahoo silently auto-blocks; skip + log) ──
            $excerpt    = has_excerpt( $post_id )
                ? wp_strip_all_tags( get_the_excerpt( $post_id ) )
                : wp_trim_words( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $content ) ) ) ), 55, '…' );
            $desc_plain = mmgsf_plain( $excerpt );
            $wc_text    = trim( preg_replace( '/\s+/u', ' ', strip_tags( str_replace( '<', ' <', $body_html ) ) ) );
            $wc         = count( preg_split( '/\s+/u', $wc_text, -1, PREG_SPLIT_NO_EMPTY ) );

            if ( trim( $title_plain ) === '' ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, '(untitled)', 'title_empty' );
                continue;
            }
            if ( count( preg_split( '/\s+/u', trim( $desc_plain ), -1, PREG_SPLIT_NO_EMPTY ) ) < 3 ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, 'description_below_word_floor' );
                continue;
            }
            if ( $wc < 150 ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, 'body_below_word_floor', $wc . ' words post-sanitization (floor 150)' );
                continue;
            }
            if ( $pub_ts > time() ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, 'pubdate_future', gmdate( 'c', $pub_ts ) );
                continue;
            }

            // ── Lead image: 'full' + rendition ladder, 1280×720/5MB gate ──
            $img = mmgsf_resolve_lead_image( $post_id, 1280, 720, 5242880, $img_note );
            if ( $img_note && $img_note['code'] !== 'no_featured_image' ) {
                mmgsf_skip_log_add( 'yahoo', $post_id, $title_plain, $img_note['code'], $img_note['detail'], 'warn' );
            }

            // One <category> per item (Yahoo requirement)
            $categories = get_the_category( $post_id );
            $yahoo_cat  = $opts['yahoo_category'] ?: ( ! empty( $categories ) ? $categories[0]->name : 'General' );

            // Lead figure first — unless the body already leads with this asset.
            $body = $body_html;
            $same = $img && (
                ( $gated['first_image_attachment'] ?? 0 ) === ( $img['attachment_id'] ?? -1 )
                || ( $san['first_image_src'] ?? null ) === $img['url']
            );
            if ( $img && ! $same ) {
                $fig_alt     = $img['alt'] !== '' ? $img['alt'] : $title_plain;
                $fig_caption = $img['caption'] !== '' ? $img['caption'] : $img['alt'];
                $figure  = '<figure><img src="' . mmgsf_xml( $img['url'] ) . '" alt="' . mmgsf_xml( $fig_alt ) . '">';
                if ( $fig_caption !== '' ) {
                    $figure .= '<figcaption>' . mmgsf_xml( $fig_caption ) . '</figcaption>';
                }
                $figure .= '</figure>';
                $body    = $figure . "\n" . $body;
            }

            $xml .= "    <item>\n";
            $xml .= '      ' . mmgsf_el( 'title', $title_plain, [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'link', mmgsf_build_link( $permalink, $opts ) ) . "\n";
            $xml .= '      ' . mmgsf_el( 'pubDate', mmgsf_rfc822( $pub_ts ) ) . "\n";
            if ( $mod_ts > $pub_ts + MMGSF_MODIFIED_JITTER ) {
                $xml .= '      ' . mmgsf_el( 'updated', mmgsf_rfc822( $mod_ts ) ) . "\n";
            }
            $xml .= '      <guid isPermaLink="true">' . mmgsf_xml( $permalink ) . "</guid>\n";
            $xml .= '      ' . mmgsf_el( 'dc:creator', mmgsf_plain( get_the_author_meta( 'display_name', $post->post_author ) ), [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'description', $desc_plain, [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'content:encoded', $body, [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'category', mmgsf_plain( $yahoo_cat ) ) . "\n";
            // Media elements are STRICTLY a fallback for imageless bodies.
            // Emitting them beside the inline lead figure made Yahoo flag
            // every item "Duplicate photos" (observed live on easysportz,
            // 2026-09-01) — the same photo declared twice.
            if ( $img && stripos( $body, '<img' ) === false ) {
                $xml .= '      <media:thumbnail url="' . mmgsf_xml( $img['url'] ) . '" height="' . (int) $img['height'] . '" width="' . (int) $img['width'] . '"/>' . "\n";
                $xml .= '      <media:content url="' . mmgsf_xml( $img['url'] ) . '" type="' . mmgsf_xml( $img['type'] ) . '" medium="image" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '">' . "\n";
                $credit = $img['caption'] !== '' ? $img['caption'] : $img['alt'];
                if ( $credit !== '' ) {
                    $xml .= '        <media:description>' . mmgsf_xml( mmgsf_plain( $credit ) ) . "</media:description>\n";
                }
                $xml .= "      </media:content>\n";
            }
            $xml .= "    </item>\n";
        }
        wp_reset_postdata();
    }

    $xml .= "  </channel>\n</rss>\n";
    return $xml;
}

// ─────────────────────────────────────────────────────────────────
// 9. MSN FEED OUTPUT
//    Spec: https://support.microsoft.com/en-us/msn/partner-hub/
//          msn-feed-item-metadata-requirements
//    Required: guid (unique, non-changing), title (>20 chars, <150),
//              content:encoded (body), pubDate (RFC 822), link
//    Optional: description (plain text), dcterms:modified,
//              mi:expirationDate, mi:shortTitle (≤54 chars),
//              dc:creator, media:thumbnail, media:content,
//              media:keywords, category
//    Namespaces: mi (Microsoft Ingestion Services),
//                dcterms (Dublin Core Terms), media (Media RSS)
// ─────────────────────────────────────────────────────────────────

function mmgsf_output_msn( $opts, $filters ) {
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );
    mmgsf_no_cache_headers();
    if ( mmgsf_maybe_304() ) {
        return;
    }
    echo mmgsf_cached_feed( 'msn', $opts, $filters, 'mmgsf_render_msn' );
}

function mmgsf_render_msn( $opts, $filters ) {
    $posts    = mmgsf_build_query( $opts, $filters );
    $feed_url = mmgsf_feed_url( ( $opts['msn_slug'] ?? 'msn' ) . '-feed' );
    $desc     = trim( (string) $opts['feed_description'] ) !== '' ? $opts['feed_description'] : ( trim( (string) get_bloginfo( 'description' ) ) !== '' ? get_bloginfo( 'description' ) : get_bloginfo( 'name' ) );

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0"' . "\n";
    $xml .= '     xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
    $xml .= '     xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
    $xml .= '     xmlns:dcterms="http://purl.org/dc/terms/"' . "\n";
    $xml .= '     xmlns:atom="http://www.w3.org/2005/Atom"' . "\n";
    $xml .= '     xmlns:media="http://search.yahoo.com/mrss/"' . "\n";
    $xml .= '     xmlns:mi="http://schemas.ingestion.microsoft.com/common/"' . "\n";
    $xml .= '     xmlns:sy="http://purl.org/rss/1.0/modules/syndication/">' . "\n";
    $xml .= "  <channel>\n";
    $xml .= '    ' . mmgsf_el( 'title', $opts['feed_title'] ) . "\n";
    $xml .= '    <atom:link href="' . mmgsf_xml( $feed_url ) . '" rel="self" type="application/rss+xml"/>' . "\n";
    $xml .= '    ' . mmgsf_el( 'link', $opts['feed_link'] ) . "\n";
    $xml .= '    ' . mmgsf_el( 'description', $desc ) . "\n";
    $xml .= '    ' . mmgsf_el( 'lastBuildDate', mmgsf_rfc822( time() ) ) . "\n";
    $xml .= '    ' . mmgsf_el( 'language', get_bloginfo( 'language' ) ) . "\n";
    $xml .= "    <sy:updatePeriod>hourly</sy:updatePeriod>\n";
    $xml .= "    <sy:updateFrequency>1</sy:updateFrequency>\n";
    $xml .= '    <generator>MMG Syndication Feeds ' . mmgsf_xml( MMGSF_VERSION ) . "</generator>\n";

    if ( $posts->have_posts() ) {
        while ( $posts->have_posts() ) {
            $posts->the_post();
            global $post;
            $post_id     = get_the_ID();
            $permalink   = get_permalink( $post_id );
            $pub_ts      = (int) get_post_time( 'U', true, $post_id );
            $mod_ts      = (int) get_post_modified_time( 'U', true, $post_id );
            $title_plain = mmgsf_plain( get_the_title( $post_id ) );
            $content     = apply_filters( 'the_content', get_the_content( null, false, $post_id ) );

            // MSN sanitizer (verified tag set; YouTube embeds rejected at
            // ingestion → stripped) + pruning.
            $san       = mmgsf_sanitize( $content, mmgsf_msn_rules() );
            $body_html = mmgsf_prune_empty_nodes( $san['html'] );

            // ── MSN validation gates ─────────────────────────────
            $title_len = mb_strlen( trim( $title_plain ) );
            $now       = time();
            if ( $title_len < 21 ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, 'title_too_short', "{$title_len} chars; MSN requires more than 20" );
                continue;
            }
            if ( $pub_ts > $now ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, 'pubdate_future', gmdate( 'c', $pub_ts ) );
                continue;
            }
            if ( $pub_ts < $now - 365 * 86400 ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, 'pubdate_too_old', gmdate( 'c', $pub_ts ) . ' (MSN limit: 365 days)' );
                continue;
            }
            if ( trim( $body_html ) === '' ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, 'body_empty' );
                continue;
            }
            // MSN 640×360 hard floor / 2MB, via the same rendition ladder.
            $img = mmgsf_resolve_lead_image( $post_id, 640, 360, 2097152, $img_note );
            if ( ! $img ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, $img_note['code'] ?? 'no_featured_image', $img_note['detail'] ?? 'MSN will not auto-publish imageless articles' );
                continue;
            }
            if ( $img_note ) {
                mmgsf_skip_log_add( 'msn', $post_id, $title_plain, $img_note['code'], $img_note['detail'], 'warn' );
            }

            $excerpt = has_excerpt( $post_id )
                ? wp_strip_all_tags( get_the_excerpt( $post_id ) )
                : wp_trim_words( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $content ) ) ) ), 55, '…' );
            $desc_plain  = mmgsf_plain( $excerpt );
            $short_title = mmgsf_short_title( $title_plain );
            $keywords    = [];
            $post_tags   = get_the_tags( $post_id );
            if ( ! empty( $post_tags ) && is_array( $post_tags ) ) {
                foreach ( $post_tags as $tag ) {
                    $keywords[] = $tag->name;
                }
            }
            $img_desc = $img['caption'] !== '' ? $img['caption'] : $img['alt'];

            $xml .= "    <item>\n";
            $xml .= '      ' . mmgsf_el( 'title', $title_plain, [], true ) . "\n";
            if ( $short_title !== '' ) {
                $xml .= '      ' . mmgsf_el( 'mi:shortTitle', $short_title, [], true ) . "\n";
            }
            $xml .= '      ' . mmgsf_el( 'link', mmgsf_build_link( $permalink, $opts ) ) . "\n";
            $xml .= '      ' . mmgsf_el( 'pubDate', mmgsf_rfc822( $pub_ts ) ) . "\n";
            if ( $mod_ts > $pub_ts + MMGSF_MODIFIED_JITTER ) {
                $xml .= '      ' . mmgsf_el( 'dcterms:modified', gmdate( 'Y-m-d\TH:i:s\Z', $mod_ts ) ) . "\n";
            }
            $xml .= '      <guid isPermaLink="true">' . mmgsf_xml( $permalink ) . "</guid>\n";
            $xml .= '      ' . mmgsf_el( 'dc:creator', mmgsf_plain( get_the_author_meta( 'display_name', $post->post_author ) ), [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'description', $desc_plain, [], true ) . "\n";
            $xml .= '      ' . mmgsf_el( 'content:encoded', $body_html, [], true ) . "\n";
            foreach ( get_the_category( $post_id ) ?: [] as $cat ) {
                $xml .= '      ' . mmgsf_el( 'category', $cat->name, [], true ) . "\n";
            }
            if ( $keywords ) {
                $xml .= '      ' . mmgsf_el( 'media:keywords', implode( ', ', $keywords ) ) . "\n";
            }
            // One nested media object (thumbnail inside content) — never a
            // sibling pair listing the same URL twice (audit4-C; matches the
            // Scoreline MSN shape that audits clean in Partner Hub).
            $xml .= '      <media:content url="' . mmgsf_xml( $img['url'] ) . '" type="' . mmgsf_xml( $img['type'] ) . '" medium="image" width="' . (int) $img['width'] . '" height="' . (int) $img['height'] . '">' . "\n";
            $xml .= '        <media:thumbnail url="' . mmgsf_xml( $img['url'] ) . '"/>' . "\n";
            if ( $img_desc !== '' ) {
                $xml .= '        <media:description type="plain">' . mmgsf_cdata( mmgsf_plain( $img_desc ) ) . "</media:description>\n";
            }
            $xml .= "      </media:content>\n";
            $xml .= "    </item>\n";
        }
        wp_reset_postdata();
    }

    $xml .= "  </channel>\n</rss>\n";
    return $xml;
}

// ─────────────────────────────────────────────────────────────────
// 10. ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, function() {
    mmgsf_register_all_feeds();
    flush_rewrite_rules( false );
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules( false );
} );
