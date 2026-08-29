<?php
/**
 * Minimal WordPress function stubs so the plugin can be exercised from PHP CLI
 * without a WordPress install. Fixture state lives in $GLOBALS['mmgrf_test'].
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mmgrf_test'] = [
    'options'     => [],
    'transients'  => [],
    'posts'       => [],   // id => stdClass post
    'meta'        => [],   // post_id => [key => value]
    'attachments' => [],   // id => ['sizes'=>['large'=>[url,w,h],'full'=>[...]], 'mime'=>..., 'file'=>..., 'alt'=>..., 'caption'=>...]
    'terms'       => [],   // taxonomy => slug => ['id'=>..,'name'=>..]
    'post_terms'  => [],   // post_id => ['category'=>[slugs], 'post_tag'=>[slugs]]
    'home_url'    => 'https://example-brand.com',
    'blog'        => [ 'name' => 'Example Brand', 'description' => 'Example description', 'language' => 'en-US', 'charset' => 'UTF-8' ],
    'filters'     => [],
    'actions'     => [],
    'feeds'       => [],   // slug => callback
    'current_post'=> null,
];

function mmgrf_test_reset() {
    $keep = $GLOBALS['mmgrf_test']['home_url'];
    $GLOBALS['mmgrf_test'] = array_merge( $GLOBALS['mmgrf_test'], [
        'options' => [], 'transients' => [], 'posts' => [], 'meta' => [],
        'attachments' => [], 'terms' => [], 'post_terms' => [], 'feeds' => [],
        'current_post' => null, 'file_dims' => [], 'url_to_attachment' => [], 'actions' => [], 'filters' => [],
    ] );
    $GLOBALS['mmgrf_test']['home_url'] = $keep;
    $GLOBALS['mmgrf_test']['blog'] = [ 'name' => 'Example Brand', 'description' => 'Example description', 'language' => 'en-US', 'charset' => 'UTF-8' ];
}

/** Create a fixture post. Returns post ID. */
function mmgrf_test_add_post( $args = [] ) {
    static $next_id = 100;
    $id = $args['ID'] ?? $next_id++;
    $post = (object) array_merge( [
        'ID'            => $id,
        'post_title'    => 'Test Post ' . $id,
        'post_content'  => '<p>Body content for post ' . $id . '.</p>',
        'post_excerpt'  => '',
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_author'   => 1,
        'post_date_gmt' => '2026-08-01 12:00:00',
        'post_modified_gmt' => '2026-08-01 12:00:00',
        'post_name'     => 'test-post-' . $id,
        'thumbnail_id'  => 0,
    ], $args );
    $GLOBALS['mmgrf_test']['posts'][ $id ] = $post;
    return $id;
}

function mmgrf_test_add_attachment( $id, $sizes, $mime = 'image/jpeg', $extra = [] ) {
    $GLOBALS['mmgrf_test']['attachments'][ $id ] = array_merge(
        [ 'sizes' => $sizes, 'mime' => $mime, 'file' => null, 'alt' => '', 'caption' => '' ],
        $extra
    );
    // Real WP stores alt text as attachment post meta.
    if ( ! empty( $extra['alt'] ) ) {
        $GLOBALS['mmgrf_test']['meta'][ $id ]['_wp_attachment_image_alt'] = $extra['alt'];
    }
}

// ── Escaping / sanitization ──────────────────────────────────────
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return str_replace( [ '"', "'", '<', '>', ' ' ], [ '%22', '%27', '%3C', '%3E', '%20' ], (string) $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9-]+/', '-', $s ); return trim( preg_replace( '/-+/', '-', $s ), '-' ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $text, $num = 55, $more = '&hellip;' ) {
    $words = preg_split( '/[\s]+/u', trim( (string) $text ), $num + 1, PREG_SPLIT_NO_EMPTY );
    if ( count( $words ) > $num ) { array_pop( $words ); return implode( ' ', $words ) . $more; }
    return implode( ' ', $words );
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }

// ── Options / transients ─────────────────────────────────────────
function get_option( $k, $default = false ) { return $GLOBALS['mmgrf_test']['options'][ $k ] ?? $default; }
function update_option( $k, $v ) { $GLOBALS['mmgrf_test']['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['mmgrf_test']['options'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['mmgrf_test']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['mmgrf_test']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['mmgrf_test']['transients'][ $k ] ); return true; }

// ── URLs / blog info ─────────────────────────────────────────────
function home_url( $path = '' ) { return rtrim( $GLOBALS['mmgrf_test']['home_url'], '/' ) . $path; }
function get_bloginfo( $k ) {
    if ( $k === 'charset' ) return $GLOBALS['mmgrf_test']['blog']['charset'];
    return $GLOBALS['mmgrf_test']['blog'][ $k ] ?? '';
}
function get_permalink( $post_id ) {
    $p = $GLOBALS['mmgrf_test']['posts'][ is_object( $post_id ) ? $post_id->ID : $post_id ] ?? null;
    return $p ? home_url( '/' . $p->post_name . '/' ) : '';
}

// ── Posts / meta / terms ─────────────────────────────────────────
function get_post( $id ) { return $GLOBALS['mmgrf_test']['posts'][ $id ] ?? null; }
function get_post_meta( $post_id, $key, $single = false ) {
    $v = $GLOBALS['mmgrf_test']['meta'][ $post_id ][ $key ] ?? '';
    return $single ? $v : ( $v === '' ? [] : [ $v ] );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['mmgrf_test']['meta'][ $post_id ][ $key ] = $value; return true; }
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['mmgrf_test']['meta'][ $post_id ][ $key ] ); return true; }
function get_the_title( $post_id ) { $p = get_post( is_object( $post_id ) ? $post_id->ID : $post_id ); return $p ? $p->post_title : ''; }
function get_the_content( $more_link = null, $strip_teaser = false, $post_id = 0 ) { $p = get_post( $post_id ); return $p ? $p->post_content : ''; }
function has_excerpt( $post_id ) { $p = get_post( $post_id ); return $p && $p->post_excerpt !== ''; }
function get_the_excerpt( $post_id ) { $p = get_post( is_object( $post_id ) ? $post_id->ID : $post_id ); return $p ? $p->post_excerpt : ''; }
function get_post_time( $format, $gmt = false, $post_id = null ) {
    $p = get_post( $post_id ?? $GLOBALS['mmgrf_test']['current_post'] );
    if ( ! $p ) return false;
    $ts = strtotime( $p->post_date_gmt . ' UTC' );
    return $format === 'U' ? $ts : gmdate( $format, $ts );
}
function get_post_modified_time( $format, $gmt = false, $post_id = null ) {
    $p = get_post( $post_id ?? $GLOBALS['mmgrf_test']['current_post'] );
    if ( ! $p ) return false;
    $ts = strtotime( $p->post_modified_gmt . ' UTC' );
    return $format === 'U' ? $ts : gmdate( $format, $ts );
}
function mysql2date( $format, $date, $translate = true ) {
    $ts = strtotime( $date . ' UTC' );
    return gmdate( $format, $ts );
}
function current_time( $type, $gmt = 0 ) { return $type === 'mysql' ? gmdate( 'Y-m-d H:i:s' ) : time(); }
function get_lastpostmodified( $tz = 'GMT' ) {
    $max = '';
    foreach ( $GLOBALS['mmgrf_test']['posts'] as $p ) {
        if ( $p->post_status === 'publish' && $p->post_modified_gmt > $max ) {
            $max = $p->post_modified_gmt;
        }
    }
    return $max ?: gmdate( 'Y-m-d H:i:s' );
}
function get_the_author_meta( $field, $user_id ) { return 'Author ' . $user_id; }

function mmgrf_test_set_terms( $post_id, $taxonomy, $names ) {
    foreach ( $names as $name ) {
        $slug = sanitize_title( $name );
        if ( ! isset( $GLOBALS['mmgrf_test']['terms'][ $taxonomy ][ $slug ] ) ) {
            static $tid = 500;
            $GLOBALS['mmgrf_test']['terms'][ $taxonomy ][ $slug ] = (object) [ 'term_id' => $tid++, 'name' => $name, 'slug' => $slug ];
        }
        $GLOBALS['mmgrf_test']['post_terms'][ $post_id ][ $taxonomy ][] = $slug;
    }
}
function get_the_category( $post_id ) {
    $slugs = $GLOBALS['mmgrf_test']['post_terms'][ $post_id ]['category'] ?? [];
    return array_values( array_map( fn( $s ) => $GLOBALS['mmgrf_test']['terms']['category'][ $s ], $slugs ) );
}
function get_the_tags( $post_id ) {
    $slugs = $GLOBALS['mmgrf_test']['post_terms'][ $post_id ]['post_tag'] ?? [];
    if ( ! $slugs ) return false;
    return array_values( array_map( fn( $s ) => $GLOBALS['mmgrf_test']['terms']['post_tag'][ $s ], $slugs ) );
}
function get_term_by( $field, $value, $taxonomy ) {
    return $GLOBALS['mmgrf_test']['terms'][ $taxonomy ][ $value ] ?? false;
}

// ── Attachments ──────────────────────────────────────────────────
function get_post_thumbnail_id( $post_id ) { $p = get_post( is_object( $post_id ) ? $post_id->ID : $post_id ); return $p ? ( $p->thumbnail_id ?: false ) : false; }
function has_post_thumbnail( $post_id ) { return (bool) get_post_thumbnail_id( $post_id ); }
function wp_get_attachment_image_src( $att_id, $size ) {
    $a = $GLOBALS['mmgrf_test']['attachments'][ $att_id ] ?? null;
    if ( ! $a ) return false;
    $s = $a['sizes'][ $size ] ?? ( $a['sizes']['full'] ?? null );
    if ( ! $s ) return false;
    // 'reported' emulates image_downsize's editor-constrained dims (theme
    // $content_width) diverging from the true rendition dims in metadata.
    $rep = $a['reported'][ $size ] ?? null;
    return [ $s[0], $rep[0] ?? $s[1], $rep[1] ?? $s[2], $size !== 'full' ];
}
function wp_get_attachment_image_url( $att_id, $size ) { $s = wp_get_attachment_image_src( $att_id, $size ); return $s ? $s[0] : false; }
function get_post_mime_type( $att_id ) { return $GLOBALS['mmgrf_test']['attachments'][ $att_id ]['mime'] ?? false; }
function get_attached_file( $att_id ) { return $GLOBALS['mmgrf_test']['attachments'][ $att_id ]['file'] ?? false; }
function wp_getimagesize( $path ) { return $GLOBALS['mmgrf_test']['file_dims'][ $path ] ?? false; }
function attachment_url_to_postid( $url ) { return $GLOBALS['mmgrf_test']['url_to_attachment'][ $url ] ?? 0; }
function wp_basename( $path ) { return basename( str_replace( '\\', '/', (string) $path ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function status_header( $code ) { $GLOBALS['mmgrf_test']['status_header'] = $code; }
function wp_remote_get( $url, $args = [] ) { return $GLOBALS['mmgrf_test']['remote'][ $url ] ?? new WP_Error( 'http', 'no fixture' ); }
function wp_remote_retrieve_body( $resp ) { return is_array( $resp ) ? ( $resp['body'] ?? '' ) : ''; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error { public $msg; public function __construct( $c = '', $m = '' ) { $this->msg = $m; } }
/**
 * Richer than the scoreline stub: derives width/height/file/sizes from the
 * fixture's sizes map, mirroring real WP metadata — needed to test the
 * metadata-sourced-dimensions behavior (the $content_width/696 lesson).
 * Fixtures can override reported src dims via a 'reported' map while metadata
 * keeps the true rendition dims.
 */
function wp_get_attachment_metadata( $att_id ) {
    $a = $GLOBALS['mmgrf_test']['attachments'][ $att_id ] ?? null;
    if ( ! $a ) return false;
    $meta = [];
    if ( isset( $a['fake_filesize'] ) ) $meta['filesize'] = $a['fake_filesize'];
    if ( isset( $a['sizes']['full'] ) ) {
        $meta['file']   = basename( $a['sizes']['full'][0] );
        $meta['width']  = $a['sizes']['full'][1];
        $meta['height'] = $a['sizes']['full'][2];
    }
    foreach ( $a['sizes'] as $name => $s ) {
        if ( $name === 'full' ) continue;
        $meta['sizes'][ $name ] = [ 'file' => basename( $s[0] ), 'width' => $s[1], 'height' => $s[2] ];
    }
    return $meta;
}
function wp_get_attachment_caption( $att_id ) { return $GLOBALS['mmgrf_test']['attachments'][ $att_id ]['caption'] ?? ''; }
function mmgrf_test_attachment_alt( $att_id ) { return $GLOBALS['mmgrf_test']['attachments'][ $att_id ]['alt'] ?? ''; }

function get_post_comments_feed_link( $post_id ) { return get_permalink( $post_id ) . 'feed/'; }
function get_comments_number( $post_id ) { $p = get_post( $post_id ); return $p->comment_count ?? 0; }

// ── Hooks (minimal) ──────────────────────────────────────────────
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['mmgrf_test']['actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['mmgrf_test']['filters'][ $hook ][ $prio ][] = $cb; }
function do_action( $hook, ...$args ) { foreach ( $GLOBALS['mmgrf_test']['actions'][ $hook ] ?? [] as $cb ) { $cb( ...$args ); } }
function apply_filters( $hook, $value, ...$args ) {
    $groups = $GLOBALS['mmgrf_test']['filters'][ $hook ] ?? [];
    ksort( $groups );
    foreach ( $groups as $cbs ) { foreach ( $cbs as $cb ) { $value = $cb( $value, ...$args ); } }
    return $value;
}
function remove_filter( $hook, $cb, $prio = 10 ) {
    if ( isset( $GLOBALS['mmgrf_test']['filters'][ $hook ][ $prio ] ) ) {
        $GLOBALS['mmgrf_test']['filters'][ $hook ][ $prio ] = array_filter(
            $GLOBALS['mmgrf_test']['filters'][ $hook ][ $prio ], fn( $c ) => $c !== $cb
        );
    }
    return true;
}
function add_feed( $slug, $cb ) { $GLOBALS['mmgrf_test']['feeds'][ $slug ] = $cb; }
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function flush_rewrite_rules( $hard = true ) {}
function feed_content_type( $type ) { return 'application/rss+xml'; }
function setup_postdata( $post ) { $GLOBALS['mmgrf_test']['current_post'] = is_object( $post ) ? $post->ID : $post; return true; }
function wp_reset_postdata() { $GLOBALS['mmgrf_test']['current_post'] = null; $GLOBALS['post'] = null; }
function get_the_ID() { return $GLOBALS['mmgrf_test']['current_post']; }
function is_admin() { return false; }
function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return $GLOBALS['mmgrf_test']['current_user_id'] ?? 1; }
function get_query_var( $var, $default = '' ) { return $GLOBALS['mmgrf_test']['query_vars'][ $var ] ?? $default; }
function is_feed() { return ( $GLOBALS['mmgrf_test']['query_vars']['feed'] ?? '' ) !== ''; }
function get_default_feed() { return 'rss2'; }
function add_management_page( $pt, $mt, $cap, $slug, $cb ) {}
function add_options_page( $pt, $mt, $cap, $slug, $cb ) {}
function wp_kses_post( $s ) { return $s; }
function checked( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? " checked='checked'" : ''; if ( $echo ) echo $r; return $r; }
function selected( $a, $b = true, $echo = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $echo ) echo $r; return $r; }

/** Simple WP_Query stub: filters fixture posts by the subset of args the plugin uses. */
class WP_Query {
    public $posts = [];
    private $idx = 0;
    public function __construct( $args = [] ) {
        $all = array_values( $GLOBALS['mmgrf_test']['posts'] );
        $all = array_filter( $all, fn( $p ) => $p->post_status === 'publish' && $p->post_type === ( $args['post_type'] ?? 'post' ) );
        $terms_of = function( $p, $tax ) {
            $slugs = $GLOBALS['mmgrf_test']['post_terms'][ $p->ID ][ $tax ] ?? [];
            return array_map( fn( $s ) => $GLOBALS['mmgrf_test']['terms'][ $tax ][ $s ]->term_id, $slugs );
        };
        foreach ( [ 'category__in' => 'category', 'tag__in' => 'post_tag' ] as $arg => $tax ) {
            if ( ! empty( $args[ $arg ] ) ) {
                $all = array_filter( $all, fn( $p ) => array_intersect( $terms_of( $p, $tax ), $args[ $arg ] ) );
            }
        }
        foreach ( [ 'category__not_in' => 'category', 'tag__not_in' => 'post_tag' ] as $arg => $tax ) {
            if ( ! empty( $args[ $arg ] ) ) {
                $all = array_filter( $all, fn( $p ) => ! array_intersect( $terms_of( $p, $tax ), $args[ $arg ] ) );
            }
        }
        usort( $all, fn( $a, $b ) => strcmp( $b->post_date_gmt, $a->post_date_gmt ) );
        $this->posts = array_slice( array_values( $all ), 0, $args['posts_per_page'] ?? 10 );
    }
    public function have_posts() { return $this->idx < count( $this->posts ); }
    public function the_post() { $GLOBALS['post'] = $this->posts[ $this->idx ]; setup_postdata( $this->posts[ $this->idx ] ); $this->idx++; }
}
