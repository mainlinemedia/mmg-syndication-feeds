<?php
/**
 * Skip log — the debugging surface for silent partner-side rejections (D7).
 * Entries carry a STABLE reason code (machine-matchable, drives dedupe and the
 * Fix 11 editor panel) plus a freetext detail string (dimensions, URLs,
 * counts). Rolling option, newest first, 200-cap, 7-day retention, never
 * autoloaded.
 *
 * Codes in use: no_featured_image, image_below_minimum, image_over_size_limit,
 * image_dimensions_unknown, image_format_unsupported, no_syndication_rights,
 * inline_image_below_minimum, inline_image_unresolvable,
 * affiliate_domain_matched, affiliate_path_matched, body_below_word_floor,
 * body_empty, title_empty, title_too_short, title_too_long_no_short_title,
 * description_below_word_floor, pubdate_future, pubdate_too_old, link_missing,
 * guid_missing, embed_review.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGSF_SKIP_LOG_OPTION = 'mmgsf_skip_log';
const MMGSF_SKIP_LOG_CAP    = 200;
const MMGSF_SKIP_LOG_TTL    = 604800; // 7 days

/**
 * @param string $code   Stable reason code (snake_case, from the list above).
 * @param string $detail Human detail: dimensions, matched pattern, word count.
 * @param string $level  'skip' (item withheld) or 'warn' (shipped, needs review)
 */
function mmgsf_skip_log_add( $network, $post_id, $post_title, $code, $detail = '', $level = 'skip' ) {
    $log = get_option( MMGSF_SKIP_LOG_OPTION, [] );
    if ( ! is_array( $log ) ) {
        $log = [];
    }
    // Dedupe: feeds are polled every few minutes and re-log the same condition
    // each render — without this, one skipped post fills the cap in hours.
    foreach ( $log as $i => $e ) {
        if ( (int) ( $e['post_id'] ?? 0 ) === (int) $post_id
            && ( $e['network'] ?? '' ) === $network
            && ( $e['code'] ?? '' ) === $code ) {
            if ( ( $e['ts'] ?? 0 ) >= time() - 3600
                && ( $e['detail'] ?? '' ) === $detail
                && ( $e['level'] ?? 'skip' ) === $level ) {
                return; // fresh duplicate — no DB write on every poll
            }
            unset( $log[ $i ] );
            break;
        }
    }
    array_unshift( $log, [
        'ts'         => time(),
        'network'    => $network,
        'post_id'    => $post_id,
        'post_title' => $post_title,
        'code'       => $code,
        'detail'     => $detail,
        'level'      => $level,
    ] );
    $log = array_slice( array_values( $log ), 0, MMGSF_SKIP_LOG_CAP );
    update_option( MMGSF_SKIP_LOG_OPTION, $log, 'no' ); // never autoload 200 entries on every page view
}

function mmgsf_skip_log_get() {
    $log = get_option( MMGSF_SKIP_LOG_OPTION, [] );
    if ( ! is_array( $log ) ) {
        return [];
    }
    $cutoff = time() - MMGSF_SKIP_LOG_TTL;
    return array_values( array_filter( $log, fn( $e ) => ( $e['ts'] ?? 0 ) >= $cutoff ) );
}

/**
 * Entries visible to a user: admins see everything; everyone else sees only
 * entries for posts they authored — the person who needs to see a dropped
 * image is the writer, not the operator.
 */
function mmgsf_skip_log_visible_entries( $user_id, $can_manage_options ) {
    $log = mmgsf_skip_log_get();
    if ( $can_manage_options ) {
        return $log;
    }
    return array_values( array_filter( $log, function( $e ) use ( $user_id ) {
        $post = get_post( $e['post_id'] ?? 0 );
        return $post && (int) $post->post_author === (int) $user_id;
    } ) );
}

function mmgsf_skip_log_clear() {
    delete_option( MMGSF_SKIP_LOG_OPTION );
}
