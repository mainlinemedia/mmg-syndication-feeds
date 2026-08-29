<?php
/**
 * Self-updates from GitHub via WordPress's native Update URI mechanism
 * (WP 5.8+). See scoreline-feeds for the reference implementation. The
 * package URL is pinned to this repo's releases path — a tampered manifest
 * cannot point the site at a foreign host.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MMGSF_UPDATE_REPO       = 'https://github.com/mainlinemedia/mmg-syndication-feeds';
const MMGSF_UPDATE_MANIFEST   = 'https://raw.githubusercontent.com/mainlinemedia/mmg-syndication-feeds/main/manifest.json';
const MMGSF_UPDATE_PKG_PREFIX = 'https://github.com/mainlinemedia/mmg-syndication-feeds/releases/';

add_filter( 'update_plugins_github.com', 'mmgsf_github_update_check', 10, 3 );

function mmgsf_github_update_check( $update, $plugin_data, $plugin_file ) {
    if ( $plugin_file !== 'mmg-syndication-feeds/mmg-syndication-feeds.php' ) {
        return $update;
    }
    $info = mmgsf_fetch_update_manifest();
    if ( ! $info || ! version_compare( $info['version'], MMGSF_VERSION, '>' ) ) {
        return $update;
    }
    return [
        'id'      => 'github.com/mainlinemedia/mmg-syndication-feeds',
        'slug'    => 'mmg-syndication-feeds',
        'plugin'  => $plugin_file,
        'version' => $info['version'],
        'url'     => MMGSF_UPDATE_REPO,
        'package' => $info['package'],
    ];
}

function mmgsf_fetch_update_manifest() {
    $cached = get_transient( 'mmgsf_update_manifest' );
    if ( is_array( $cached ) ) {
        return $cached ?: null;
    }
    $resp = wp_remote_get( MMGSF_UPDATE_MANIFEST, [ 'timeout' => 10 ] );
    $info = null;
    if ( ! is_wp_error( $resp ) ) {
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( is_array( $data )
            && ! empty( $data['version'] ) && is_string( $data['version'] )
            && preg_match( '/^\d+(\.\d+)*$/', $data['version'] )
            && ! empty( $data['package'] ) && is_string( $data['package'] )
            && strpos( $data['package'], MMGSF_UPDATE_PKG_PREFIX ) === 0 ) {
            $info = [ 'version' => $data['version'], 'package' => $data['package'] ];
        }
    }
    set_transient( 'mmgsf_update_manifest', $info ?: [], 6 * 3600 );
    return $info;
}
