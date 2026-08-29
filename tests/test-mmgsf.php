<?php
// MMG Syndication Feeds v2.0 — integration tests. Frozen contract: slugs
// {yahoo_slug}-feed / {msn_slug}-feed, channel identity, item element order.

function sf_post( $over = [], $img = 'both' ) {
    $id = mmgrf_test_add_post( array_merge( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ], $over ) );
    if ( $img ) {
        $att  = 8000 + $id;
        $base = "https://easysportz.test/wp-content/uploads/2026/08/wire$id";
        $sizes = [ 'large' => [ "$base-1024x683.jpg", 1024, 683 ] ];
        if ( $img === 'both' ) {
            $sizes['full'] = [ "$base-scaled.jpg", 2560, 1707 ];
        } else {
            $sizes['full'] = $sizes['large']; // only a small original exists
        }
        mmgrf_test_add_attachment( $att, $sizes, 'image/jpeg', [ 'alt' => 'Wire alt ' . $id, 'caption' => 'Imagn Images' ] );
        $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = $att;
    }
    return $id;
}

function sf_yahoo_xml() {
    $opts = mmgsf_get_options();
    ob_start();
    mmgsf_output_yahoo( $opts, [] );
    return ob_get_clean();
}

function sf_msn_xml() {
    $opts = mmgsf_get_options();
    ob_start();
    mmgsf_output_msn( $opts, [] );
    return ob_get_clean();
}

// ── Frozen contract ──────────────────────────────────────────────

t( 'contract: registered slugs stay {slug}-feed for both platforms', function() {
    mmgsf_register_all_feeds();
    $feeds = array_keys( $GLOBALS['mmgrf_test']['feeds'] );
    expect_true( in_array( 'yahoo-feed', $feeds, true ), 'yahoo-feed route' );
    expect_true( in_array( 'msn-feed', $feeds, true ), 'msn-feed route' );
} );

t( 'contract: yahoo item element order preserved', function() {
    sf_post();
    $xml = sf_yahoo_xml();
    $pos = 0;
    foreach ( [ '<item>', '<title><![CDATA[', '<link>', '<pubDate>', '<guid isPermaLink="true">', '<dc:creator>', '<description><![CDATA[', '<content:encoded><![CDATA[', '<category>', '<media:thumbnail', '</item>' ] as $needle ) {
        $found = strpos( $xml, $needle, $pos );
        expect_true( $found !== false, "in order: $needle" );
        $pos = $found;
    }
    expect_true( simplexml_load_string( $xml ) !== false, 'well-formed' );
} );

// ── Channel fixes ────────────────────────────────────────────────

t( 'channel: lastBuildDate replaces the invalid channel-level updated/pubDate', function() {
    sf_post();
    $xml = sf_yahoo_xml();
    $channel_head = substr( $xml, 0, strpos( $xml, '<item>' ) );
    expect_contains( $channel_head, '<lastBuildDate>' );
    expect_not_contains( $channel_head, '<updated>' );
    expect_not_contains( $channel_head, '<pubDate>' );
} );

t( 'channel: empty tagline falls back to site name', function() {
    $GLOBALS['mmgrf_test']['blog']['description'] = '';
    sf_post();
    expect_contains( sf_yahoo_xml(), '<description>Example Brand</description>' );
} );

// ── Image upgrade (the systemic live defect: 30/30 at 1024px) ────

t( 'yahoo: lead image and thumbnail use the full 2560 rendition, not large', function() {
    $id = sf_post();
    $xml = sf_yahoo_xml();
    expect_contains( $xml, "wire$id-scaled.jpg", 'full rendition used' );
    expect_not_contains( $xml, '1024x683.jpg' );
    expect_contains( $xml, 'width="2560"' );
    expect_contains( $xml, '<media:content', 'media:content with dimensions added' );
} );

t( 'yahoo: sub-floor-only original drops the image, ships the item, logs warn', function() {
    sf_post( [], 'small' );
    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 1 );
    expect_not_contains( $xml, '<media:thumbnail' );
    $log = mmgsf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_below_minimum' );
    expect_eq( $log[0]['level'], 'warn' );
} );

t( 'images: dimensions come from metadata, not image_downsize (content_width lesson)', function() {
    mmgrf_test_add_attachment( 8500, [ 'large' => [ 'https://x.test/u/pic-1024x683.jpg', 1024, 683 ], 'full' => [ 'https://x.test/u/pic.jpg', 1600, 1067 ] ] );
    // Theme $content_width constrains reported dims to 696x464:
    $GLOBALS['mmgrf_test']['attachments'][8500]['reported'] = [ 'large' => [ 696, 464 ] ];
    $data = mmgsf_attachment_image_data( 8500, 'large' );
    expect_eq( $data['width'], 1024, 'metadata dims win over constrained report' );
    expect_eq( $data['height'], 683 );
} );

// ── Gates + skip log ─────────────────────────────────────────────

t( 'yahoo: thin body skipped with code; healthy item unaffected', function() {
    sf_post();
    sf_post( [ 'post_title' => 'Another Headline Comfortably Above Twenty Chars', 'post_content' => '<p>only nine words of body text right here now</p>' ] );
    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 1, 'thin item withheld' );
    $log = mmgsf_skip_log_get();
    expect_eq( $log[0]['code'], 'body_below_word_floor' );
} );

t( 'yahoo: future-dated item skipped', function() {
    sf_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 ) ] );
    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgsf_skip_log_get()[0]['code'], 'pubdate_future' );
} );

// ── Sanitizer pipeline ───────────────────────────────────────────

t( 'yahoo: style stripped, script deleted, h1 demoted, trailing empty p pruned', function() {
    $body = '<h1>Dupe Headline</h1><p style="color:red">' . implode( ' ', array_fill( 0, 200, 'w' ) ) . '</p><script>evil()</script><p><br></p>';
    sf_post( [ 'post_content' => $body ] );
    $xml = sf_yahoo_xml();
    expect_not_contains( $xml, 'style=' );
    expect_not_contains( $xml, 'evil()' );
    expect_not_contains( $xml, '<h1' );
    expect_contains( $xml, '<h2>Dupe Headline</h2>' );
    expect_not_contains( $xml, '<p><br></p>' );
} );

t( 'yahoo: undersized inline body image rewritten via the ladder', function() {
    $base = 'https://easysportz.test/wp-content/uploads/2026/08/inline';
    mmgrf_test_add_attachment( 8600, [ 'large' => [ "$base-1024x683.jpg", 1024, 683 ], 'full' => [ "$base.jpg", 1920, 1280 ] ] );
    $GLOBALS['mmgrf_test']['url_to_attachment'][ "$base.jpg" ] = 8600;
    sf_post( [ 'post_content' => '<figure><img src="' . $base . '-1024x683.jpg" width="1024" height="683"></figure><p>' . implode( ' ', array_fill( 0, 200, 'w' ) ) . '</p>' ] );
    $xml = sf_yahoo_xml();
    expect_not_contains( $xml, 'inline-1024x683.jpg' );
    expect_contains( $xml, 'inline.jpg' );
} );

// ── Affiliate guard ──────────────────────────────────────────────

t( 'yahoo: operator domain skips item; promo path unwraps link', function() {
    sf_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 160, 'w' ) ) . ' <a href="https://www.draftkings.com/promo">bet</a></p>' ] );
    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0, 'domain match withholds item' );
    expect_eq( mmgsf_skip_log_get()[0]['code'], 'affiliate_domain_matched' );

    mmgrf_test_reset();
    sf_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 160, 'w' ) ) . ' <a href="https://nypost.com/betting/promo-code-x/">deal text</a></p>' ] );
    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 1, 'path match ships item' );
    expect_contains( $xml, 'deal text' );
    expect_not_contains( $xml, 'promo-code-x' );
} );

// ── Update semantics ─────────────────────────────────────────────

t( 'yahoo: item updated only when genuinely modified', function() {
    sf_post();
    $xml = sf_yahoo_xml();
    expect_not_contains( $xml, '<updated>', 'unmodified: no updated anywhere (channel or item)' );

    mmgrf_test_reset();
    sf_post( [ 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ] );
    expect_contains( sf_yahoo_xml(), '<updated>' );
} );

t( 'entities: stored &#8217; decoded to real characters inside CDATA', function() {
    sf_post( [ 'post_title' => 'Yamaha&#8217;s Big Comfortable Headline Above Twenty' ] );
    $xml = sf_yahoo_xml();
    expect_contains( $xml, 'Yamaha’s' );
    expect_not_contains( $xml, '&#8217;' );
} );

// ── MSN ──────────────────────────────────────────────────────────

t( 'msn: youtube iframe stripped, tiktok kept; short title only when needed', function() {
    sf_post( [ 'post_content' => '<p>' . implode( ' ', array_fill( 0, 40, 'w' ) ) . '</p><iframe src="https://www.youtube.com/embed/x"></iframe><iframe src="https://www.tiktok.com/embed/v2/1"></iframe>' ] );
    $xml = sf_msn_xml();
    expect_not_contains( $xml, 'youtube.com' );
    expect_contains( $xml, 'tiktok.com' );
    expect_not_contains( $xml, '<mi:shortTitle>', 'title under 54 chars needs no short title' );

    mmgrf_test_reset();
    sf_post( [ 'post_title' => 'An Extremely Long Headline That Definitely Exceeds The Fifty-Four Character Promo Card Limit' ] );
    $xml = sf_msn_xml();
    expect_contains( $xml, '<mi:shortTitle>' );
    preg_match( '/<mi:shortTitle><!\[CDATA\[(.*?)\]\]>/', $xml, $m );
    expect_true( mb_strlen( $m[1] ) <= 54, 'short title within 54 chars' );
    expect_not_contains( $m[1], '  ', 'clean truncation' );
} );

t( 'msn: short title and imageless gates enforce MSN minimums', function() {
    sf_post( [ 'post_title' => 'Too short a title' ] ); // 17 chars
    $xml = sf_msn_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0 );
    expect_eq( mmgsf_skip_log_get()[0]['code'], 'title_too_short' );

    mmgrf_test_reset();
    sf_post( [], false ); // no image at all
    $xml = sf_msn_xml();
    expect_eq( substr_count( $xml, '<item>' ), 0, 'MSN will not auto-publish imageless' );
} );

t( 'msn: dcterms:modified only when genuinely modified', function() {
    sf_post();
    expect_not_contains( sf_msn_xml(), '<dcterms:modified>' );
} );

// ── Dead-file hardening (Yahoo "Image didn't download", golferinsight) ──

t( 'images: missing original promotes to an existing rendition; dead URL never advertised', function() {
    $dir = sys_get_temp_dir() . '/mmgsf-img-' . getmypid() . '-' . mt_rand();
    mkdir( $dir );
    file_put_contents( "$dir/bag-e123-2048x1365.jpg", 'x' ); // rendition exists, original does not
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ] );
    mmgrf_test_add_attachment( 8700, [
        'full'      => [ 'https://easysportz.test/up/bag-e123.jpg', 2560, 1707 ],
        '2048x2048' => [ 'https://easysportz.test/up/bag-e123-2048x1365.jpg', 2048, 1365 ],
    ], 'image/jpeg', [ 'file' => "$dir/bag-e123.jpg" ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 8700;

    $xml = sf_yahoo_xml();
    expect_contains( $xml, 'bag-e123-2048x1365.jpg', 'existing rendition shipped' );
    expect_not_contains( $xml, 'up/bag-e123.jpg"', 'dead URL not advertised' );
    $log = mmgsf_skip_log_get();
    expect_eq( $log[0]['code'], 'image_file_missing' );
    expect_eq( $log[0]['level'], 'warn' );
} );

t( 'images: no file at all on disk drops the image with image_file_missing', function() {
    $dir = sys_get_temp_dir() . '/mmgsf-img-' . getmypid() . '-' . mt_rand();
    mkdir( $dir ); // dir exists, no files
    $id = mmgrf_test_add_post( [
        'post_title'    => 'A Headline Comfortably Above Twenty Characters Long',
        'post_content'  => '<p>' . implode( ' ', array_fill( 0, 200, 'word' ) ) . '</p>',
        'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
    ] );
    mmgrf_test_add_attachment( 8701, [ 'full' => [ 'https://easysportz.test/up/gone.jpg', 2560, 1707 ] ], 'image/jpeg', [ 'file' => "$dir/gone.jpg" ] );
    $GLOBALS['mmgrf_test']['posts'][ $id ]->thumbnail_id = 8701;

    $xml = sf_yahoo_xml();
    expect_eq( substr_count( $xml, '<item>' ), 1, 'item ships imageless on yahoo' );
    expect_not_contains( $xml, 'gone.jpg' );
    $codes = array_column( mmgsf_skip_log_get(), 'code' );
    expect_true( in_array( 'image_file_missing', $codes, true ) );
} );

// ── Round 2: generator, 304, updater ─────────────────────────────

t( 'r2: both channels carry a generator tag with the plugin version', function() {
    sf_post();
    expect_contains( sf_yahoo_xml(), '<generator>MMG Syndication Feeds ' . MMGSF_VERSION . '</generator>' );
    expect_contains( sf_msn_xml(), '<generator>MMG Syndication Feeds ' . MMGSF_VERSION . '</generator>' );
} );

t( 'r2: not-modified decision mirrors scoreline behavior', function() {
    $lm = strtotime( '2026-08-29 10:00:00 UTC' );
    expect_true( mmgsf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $lm + 60 ) . ' GMT', $lm ) );
    expect_false( mmgsf_feed_not_modified( gmdate( 'D, d M Y H:i:s', $lm - 60 ) . ' GMT', $lm ) );
    expect_false( mmgsf_feed_not_modified( '', $lm ) );
} );

t( 'r2: updater offers newer github release, refuses foreign package hosts', function() {
    $GLOBALS['mmgrf_test']['remote'][ MMGSF_UPDATE_MANIFEST ] = [ 'body' => json_encode( [ 'version' => '99.0.0', 'package' => 'https://github.com/mainlinemedia/mmg-syndication-feeds/releases/download/v99.0.0/x.zip' ] ) ];
    delete_transient( 'mmgsf_update_manifest' );
    $u = mmgsf_github_update_check( false, [], 'mmg-syndication-feeds/mmg-syndication-feeds.php' );
    expect_true( is_array( $u ) );
    expect_eq( $u['version'], '99.0.0' );

    $GLOBALS['mmgrf_test']['remote'][ MMGSF_UPDATE_MANIFEST ] = [ 'body' => json_encode( [ 'version' => '99.0.0', 'package' => 'https://evil.example.com/x.zip' ] ) ];
    delete_transient( 'mmgsf_update_manifest' );
    expect_false( is_array( mmgsf_github_update_check( false, [], 'mmg-syndication-feeds/mmg-syndication-feeds.php' ) ) );
} );

// ── Cache ────────────────────────────────────────────────────────

t( 'cache: 60s transient serves repeat requests; publish busts it', function() {
    $id = sf_post();
    $first = sf_yahoo_xml();
    $GLOBALS['mmgrf_test']['posts'][ $id ]->post_title = 'Changed Behind The Cache Not Visible';
    expect_eq( sf_yahoo_xml(), $first, 'cached' );
    sf_post( [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 ) ] );
    expect_contains( sf_yahoo_xml(), 'Changed Behind The Cache', 'new publish rotates the key' );
} );
