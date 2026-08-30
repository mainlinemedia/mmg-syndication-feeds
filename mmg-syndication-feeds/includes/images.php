<?php
/**
 * Image resolution: primary rendition + smaller fallback candidates +
 * dimension recovery, shared by the lead-image path and the inline gate.
 *
 * Dimensions are sourced from ATTACHMENT METADATA, not image_downsize()'s
 * return — themes that set $content_width make image_downsize report
 * editor-constrained dimensions (e.g. 696×464) against a 1024px rendition
 * URL, which violates the dimensions-match-the-asset requirement.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mmgsf_attachment_image_data( $att_id, $size ) {
    $src = wp_get_attachment_image_src( $att_id, $size );
    if ( ! $src ) {
        return null;
    }
    $meta  = wp_get_attachment_metadata( $att_id );
    $bytes = is_array( $meta ) && ! empty( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
    if ( ! $bytes ) {
        $file  = get_attached_file( $att_id );
        $bytes = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
    }
    [ $w, $h ] = mmgsf_real_dims( $meta, $src[0], (int) $src[1], (int) $src[2] );
    $img = [
        'attachment_id' => $att_id,
        'url'           => $src[0],
        'width'         => $w,
        'height'        => $h,
        'type'          => get_post_mime_type( $att_id ) ?: 'image/jpeg',
        'filesize'      => $bytes,
        'alt'           => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
        'caption'       => (string) wp_get_attachment_caption( $att_id ),
        'candidates'    => [],
    ];
    // Unknown dimensions (API side-loads that skipped resize): recover from
    // the file before gating; only if that fails is the image unverifiable.
    if ( ! $img['width'] || ! $img['height'] ) {
        $file = get_attached_file( $att_id );
        $real = $file ? wp_getimagesize( $file ) : false;
        if ( $real ) {
            $img['width']  = (int) $real[0];
            $img['height'] = (int) $real[1];
        } else {
            $img['dims_unknown'] = true;
            $img['file']         = $file ?: '(no local file)';
        }
    }
    // Smaller renditions, largest first — callers fall back to the biggest
    // one fitting their dimension/byte constraints (raw 8K wire uploads
    // exceed Yahoo's 5MB limit at full size).
    foreach ( [ '2048x2048', 'large' ] as $alt_size ) {
        $alt = wp_get_attachment_image_src( $att_id, $alt_size );
        if ( $alt && $alt[0] !== $img['url'] ) {
            [ $aw, $ah ] = mmgsf_real_dims( $meta, $alt[0], (int) $alt[1], (int) $alt[2] );
            $img['candidates'][] = [ 'url' => $alt[0], 'width' => $aw, 'height' => $ah ];
        }
    }

    // Never advertise a file that is missing from local disk — media-library
    // edits can orphan metadata (a -e{ts} original that no longer exists) and
    // the partner's fetch then 404s ("Image didn't download" at Yahoo).
    // Enforced only when the upload directory is local; offloaded media exempt.
    $file = get_attached_file( $att_id );
    if ( $file && is_dir( dirname( $file ) ) ) {
        $dir = dirname( $file );
        $img['candidates'] = array_values( array_filter( $img['candidates'], function( $c ) use ( $dir ) {
            return file_exists( $dir . '/' . basename( (string) parse_url( $c['url'], PHP_URL_PATH ) ) );
        } ) );
        if ( ! file_exists( $file ) ) {
            if ( $img['candidates'] ) {
                $c = array_shift( $img['candidates'] );
                $img['file_note'] = 'original file missing on disk (media-library edit?) — served ' . basename( $c['url'] );
                $img['url']       = $c['url'];
                $img['width']     = (int) $c['width'];
                $img['height']    = (int) $c['height'];
                $img['filesize']  = 0;
            } else {
                $img['file_missing'] = true;
            }
        }
    }
    return $img;
}

/**
 * True rendition dimensions for a URL, from attachment metadata when it can
 * be matched (by filename), falling back to the reported values otherwise.
 */
function mmgsf_real_dims( $meta, $url, $fallback_w, $fallback_h ) {
    if ( is_array( $meta ) ) {
        $base = wp_basename( (string) parse_url( $url, PHP_URL_PATH ) );
        if ( ! empty( $meta['file'] ) && wp_basename( $meta['file'] ) === $base
            && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            return [ (int) $meta['width'], (int) $meta['height'] ];
        }
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $s ) {
            if ( ! empty( $s['file'] ) && $s['file'] === $base && ! empty( $s['width'] ) && ! empty( $s['height'] ) ) {
                return [ (int) $s['width'], (int) $s['height'] ];
            }
        }
    }
    return [ $fallback_w, $fallback_h ];
}

/** Largest rendition satisfying the constraints; primary first, then candidates. */
function mmgsf_pick_image_fit( $img, $min_w, $min_h, $max_bytes = 0 ) {
    if ( ! $img ) {
        return null;
    }
    $fits = function( $w, $h, $bytes ) use ( $min_w, $min_h, $max_bytes ) {
        return $w >= $min_w && $h >= $min_h && ( ! $max_bytes || ! $bytes || $bytes <= $max_bytes );
    };
    if ( empty( $img['file_missing'] ) && $fits( (int) $img['width'], (int) $img['height'], (int) ( $img['filesize'] ?? 0 ) ) ) {
        return $img;
    }
    foreach ( $img['candidates'] ?? [] as $c ) {
        if ( $fits( (int) $c['width'], (int) $c['height'], 0 ) ) {
            return array_merge( $img, [ 'url' => $c['url'], 'width' => (int) $c['width'], 'height' => (int) $c['height'], 'filesize' => 0 ] );
        }
    }
    return null;
}

/** "…/img/wire-1024x683.jpg" → "…/img/wire.jpg" (WP rendition suffix). */
function mmgsf_strip_size_suffix( $url ) {
    return preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
}

/** Request-memoized attachment_url_to_postid (a DB query per distinct URL). */
function mmgsf_url_to_attachment( $url ) {
    static $memo = [];
    if ( ! array_key_exists( $url, $memo ) ) {
        $memo[ $url ] = (int) attachment_url_to_postid( $url );
    }
    return $memo[ $url ];
}

/** Latest publish OR latest settings save — a config change with no new post
 *  must still bust caches and defeat 304s. */
function mmgsf_feed_lastmod() {
    $posts = strtotime( get_lastpostmodified( 'GMT' ) . ' UTC' ) ?: 0;
    return max( $posts, (int) get_option( 'mmgsf_config_touched', 0 ) );
}

function mmgsf_touch_config() {
    update_option( 'mmgsf_config_touched', time(), 'no' );
}

/** Conditional-GET decision — 304 when the client is already current. */
function mmgsf_feed_not_modified( $if_modified_since, $lastmod_ts ) {
    if ( ! is_string( $if_modified_since ) || trim( $if_modified_since ) === '' ) {
        return false;
    }
    $since = strtotime( $if_modified_since );
    return $since !== false && $since >= (int) $lastmod_ts;
}
