<?php
/**
 * Shared HTML sanitizer engine (Layer 2 support). DOMDocument-based — regex
 * against block-editor HTML is not survivable (spec §4.4).
 *
 * $rules:
 *   allowed_tags          array of tag names, or null for permissive mode
 *   allowed_attributes    [tag => [attr,...]] allowlist (only enforced when allowed_tags is set)
 *   strip_style_attr      bool  — drop style="" everywhere (Yahoo/MSN)
 *   strip_attr_patterns   [names...] — dropped in ANY mode; 'data-*' matches prefix
 *   iframe_hosts          null = any host allowed; array = only these host suffixes survive
 *   blocked_iframe_hosts  host suffixes always deleted (MSN: YouTube)
 *   href_schemes          allowed <a href> schemes; anchor is unwrapped otherwise
 *   base_url              absolute-URL base for relative src/href
 *
 * Returns: ['html','word_count','has_embeds','body_has_image','first_image_src']
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Tags whose subtree is executable/meaningless for syndication — always deleted. */
function mmgsf_sanitize_delete_tags() {
    return [ 'script', 'style', 'noscript', 'meta', 'param', 'applet', 'form', 'button', 'input', 'select', 'textarea' ];
}

function mmgsf_sanitize( $html, $rules ) {
    $result = [
        'html'            => '',
        'word_count'      => 0,
        'has_embeds'      => false,
        'body_has_image'  => false,
        'first_image_src' => null,
    ];
    $html = (string) $html;
    if ( trim( $html ) === '' ) {
        return $result;
    }

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    // The xml PI forces UTF-8 interpretation; the wrapper div gives fragments a single root.
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="mmgrf-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $root = null;
    foreach ( $doc->childNodes as $node ) {
        if ( $node instanceof DOMElement && $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break;
        }
    }
    if ( ! $root ) {
        return $result;
    }

    $allowed     = isset( $rules['allowed_tags'] ) && is_array( $rules['allowed_tags'] )
        ? array_fill_keys( array_map( 'strtolower', $rules['allowed_tags'] ), true )
        : null;
    $delete_tags = array_fill_keys( mmgsf_sanitize_delete_tags(), true );
    if ( $allowed === null || ! isset( $allowed['object'] ) ) {
        $delete_tags['object'] = true;
    }
    if ( $allowed === null || ! isset( $allowed['embed'] ) ) {
        $delete_tags['embed'] = true;
    }

    // Snapshot the tree depth-first so structural edits don't upset iteration.
    $elements = [];
    $collect  = function( $node ) use ( &$collect, &$elements ) {
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $collect( $child );
                $elements[] = $child;
            } elseif ( $child instanceof DOMComment ) {
                $elements[] = $child;
            }
        }
    };
    $collect( $root );

    foreach ( $elements as $el ) {
        if ( $el instanceof DOMComment ) {
            $el->parentNode->removeChild( $el );
            continue;
        }
        $tag = strtolower( $el->tagName );

        if ( isset( $delete_tags[ $tag ] ) ) {
            $el->parentNode->removeChild( $el );
            continue;
        }

        if ( $tag === 'iframe' ) {
            $src  = $el->getAttribute( 'src' );
            $host = strtolower( (string) parse_url( $src, PHP_URL_HOST ) );
            $path = (string) parse_url( $src, PHP_URL_PATH );
            // Entries are host suffixes; "host/path" entries additionally
            // require the path prefix (e.g. "google.com/maps" — Maps embeds
            // allowed, other google.com iframes not).
            $host_matches = function( $suffixes ) use ( $host, $path ) {
                foreach ( $suffixes as $s ) {
                    $s         = strtolower( $s );
                    $rule_path = '';
                    if ( strpos( $s, '/' ) !== false ) {
                        [ $s, $rule_path ] = explode( '/', $s, 2 );
                        $rule_path = '/' . $rule_path;
                    }
                    $host_ok = ( $host === $s || substr( $host, -strlen( ".$s" ) ) === ".$s" );
                    if ( $host_ok && ( $rule_path === '' || strpos( $path, $rule_path ) === 0 ) ) {
                        return true;
                    }
                }
                return false;
            };
            $blocked = ! empty( $rules['blocked_iframe_hosts'] ) && $host_matches( $rules['blocked_iframe_hosts'] );
            $unlisted = isset( $rules['iframe_hosts'] ) && is_array( $rules['iframe_hosts'] )
                && ! $host_matches( $rules['iframe_hosts'] );
            if ( $blocked || $unlisted || $host === '' ) {
                $el->parentNode->removeChild( $el );
                continue;
            }
        }

        if ( $tag === 'a' && ! empty( $rules['href_schemes'] ) ) {
            $href   = trim( $el->getAttribute( 'href' ) );
            $scheme = strtolower( (string) parse_url( $href, PHP_URL_SCHEME ) );
            $is_relative = $href !== '' && $scheme === '' && $href[0] === '/';
            if ( ! $is_relative && ! in_array( $scheme, $rules['href_schemes'], true ) ) {
                mmgsf_sanitize_unwrap( $el );
                continue;
            }
        }

        if ( $allowed !== null && ! isset( $allowed[ $tag ] ) ) {
            mmgsf_sanitize_unwrap( $el );
            continue;
        }

        // Fix 10: rename_tags => ['h1' => 'h2'] — a body h1 duplicates the
        // item title on the partner page. Children and attributes preserved.
        if ( ! empty( $rules['rename_tags'][ $tag ] ) ) {
            $el = mmgsf_sanitize_rename( $doc, $el, $rules['rename_tags'][ $tag ] );
            $tag = strtolower( $el->tagName );
        }

        mmgsf_sanitize_attributes( $el, $tag, $rules, $allowed );

        // Absolutize relative URLs.
        foreach ( [ 'src', 'href' ] as $urlattr ) {
            if ( $el->hasAttribute( $urlattr ) && ! empty( $rules['base_url'] ) ) {
                $v = $el->getAttribute( $urlattr );
                if ( $v !== '' && $v[0] === '/' ) {
                    $base = rtrim( $rules['base_url'], '/' );
                    $el->setAttribute( $urlattr, substr( $v, 0, 2 ) === '//' ? 'https:' . $v : $base . $v );
                }
            }
        }
    }

    // Serialize surviving children of the wrapper.
    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    $out = trim( $out );

    // Count words from the sanitized output; a space per tag boundary keeps
    // adjacent block elements ("...three</p><div>four...") from merging words.
    $text  = trim( preg_replace( '/\s+/u', ' ', strip_tags( str_replace( '<', ' <', $out ) ) ) );
    $words = $text === '' ? [] : preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

    $first_img = null;
    $imgs      = $root->getElementsByTagName( 'img' );
    if ( $imgs->length > 0 ) {
        $first_img = $imgs->item( 0 )->getAttribute( 'src' );
    }

    $result['html']            = $out;
    $result['word_count']      = count( $words );
    $result['has_embeds']      = $root->getElementsByTagName( 'iframe' )->length > 0;
    $result['body_has_image']  = $imgs->length > 0;
    $result['first_image_src'] = $first_img ?: null;
    return $result;
}

/**
 * Fix 9 — dimension gate for inline body images (Yahoo). Walks every <img> in
 * already-sanitized HTML:
 *   resolvable + qualifying rendition exists → src/width/height rewritten
 *   resolvable + nothing qualifies          → <img> removed (whole <figure>
 *                                             incl. figcaption if it was its
 *                                             only image); logged
 *   unresolvable (external/hotlinked)       → left alone; logged
 *
 * Returns ['html','events'=>[['code','detail'],…],'first_image_attachment'].
 */
function mmgsf_gate_inline_images( $html, $min_w, $min_h, $max_bytes ) {
    $result = [ 'html' => $html, 'events' => [], 'first_image_attachment' => 0 ];
    if ( trim( (string) $html ) === '' || stripos( $html, '<img' ) === false ) {
        return $result;
    }

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="mmgrf-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $root = null;
    foreach ( $doc->childNodes as $node ) {
        if ( $node instanceof DOMElement && $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break;
        }
    }
    if ( ! $root ) {
        return $result;
    }

    $imgs = [];
    foreach ( $root->getElementsByTagName( 'img' ) as $img ) {
        $imgs[] = $img; // snapshot — removals upset live lists
    }

    foreach ( $imgs as $img ) {
        $src = $img->getAttribute( 'src' );
        if ( $src === '' ) {
            continue;
        }
        $att_id = mmgsf_url_to_attachment( mmgsf_strip_size_suffix( $src ) );
        if ( ! $att_id ) {
            $att_id = mmgsf_url_to_attachment( $src );
        }
        if ( ! $att_id ) {
            $result['events'][] = [ 'code' => 'inline_image_unresolvable', 'detail' => $src . ' — external or hotlinked, size unverifiable' ];
            continue;
        }
        if ( ! $result['first_image_attachment'] ) {
            $result['first_image_attachment'] = $att_id;
        }
        $data   = mmgsf_attachment_image_data( $att_id, 'full' );
        $picked = mmgsf_pick_image_fit( $data, $min_w, $min_h, $max_bytes );
        if ( $picked ) {
            if ( $picked['url'] !== $src ) {
                $orig = $img->getAttribute( 'width' ) . 'x' . $img->getAttribute( 'height' );
                $img->setAttribute( 'src', $picked['url'] );
                $img->setAttribute( 'width', (string) $picked['width'] );
                $img->setAttribute( 'height', (string) $picked['height'] );
                $result['events'][] = [ 'code' => 'inline_image_rewritten', 'detail' => "attachment $att_id: $orig rendition → {$picked['width']}x{$picked['height']}" ];
            }
            continue;
        }
        // Nothing qualifies — remove the image, and its whole figure if the
        // figure carried no other image (never leave an orphaned caption).
        $detail  = 'attachment ' . $att_id . ': ' . (int) ( $data['width'] ?? 0 ) . 'x' . (int) ( $data['height'] ?? 0 ) . ' with no qualifying rendition — removed from feed body';
        $victim  = $img;
        $ancestor = $img->parentNode;
        while ( $ancestor instanceof DOMElement && $ancestor !== $root ) {
            if ( strtolower( $ancestor->tagName ) === 'figure' ) {
                if ( $ancestor->getElementsByTagName( 'img' )->length === 1 ) {
                    $victim = $ancestor;
                }
                break;
            }
            $ancestor = $ancestor->parentNode;
        }
        $victim->parentNode->removeChild( $victim );
        $result['events'][] = [ 'code' => 'inline_image_below_minimum', 'detail' => $detail ];
    }

    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    $result['html'] = trim( $out );
    return $result;
}

/**
 * Fix 4 — prune empty and whitespace-only nodes. Runs on already-sanitized
 * HTML (after the Fix 9 inline gate and Fix 10 demotion), iterating until
 * stable (max 5 passes — removing an inner element can empty its parent).
 *
 * NO content-based text blocklist here by design: artifacts like
 * <span>ap</span> contain text and must survive — they're upstream content
 * garbage, and suppressing them at the feed layer would hide the generator bug.
 */
function mmgsf_prune_empty_nodes( $html ) {
    if ( trim( (string) $html ) === '' ) {
        return $html;
    }
    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="mmgrf-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $root = null;
    foreach ( $doc->childNodes as $node ) {
        if ( $node instanceof DOMElement && $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break;
        }
    }
    if ( ! $root ) {
        return $html;
    }

    $prunable = array_fill_keys( [ 'p', 'span', 'em', 'strong', 'b', 'i', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li' ], true );
    $is_blank = fn( $text ) => preg_replace( '/[\s\x{00A0}]+/u', '', $text ) === '';
    $meaningful_prev = function( $node ) {
        for ( $s = $node->previousSibling; $s; $s = $s->previousSibling ) {
            if ( $s instanceof DOMElement ) return $s;
            if ( $s instanceof DOMText && trim( $s->textContent ) !== '' ) return $s;
        }
        return null;
    };
    $meaningful_last_child = function( $el ) {
        for ( $s = $el->lastChild; $s; $s = $s->previousSibling ) {
            if ( $s instanceof DOMElement ) return $s;
            if ( $s instanceof DOMText && trim( $s->textContent ) !== '' ) return $s;
        }
        return null;
    };

    for ( $pass = 0; $pass < 5; $pass++ ) {
        $changed = false;

        $all = [];
        foreach ( $root->getElementsByTagName( '*' ) as $el ) {
            $all[] = $el;
        }

        // 4. Drop attributes whose trimmed value is empty.
        foreach ( $all as $el ) {
            $drop = [];
            foreach ( $el->attributes as $attr ) {
                if ( trim( $attr->value ) === '' ) {
                    $drop[] = $attr->name;
                }
            }
            foreach ( $drop as $name ) {
                $el->removeAttribute( $name );
                $changed = true;
            }
        }

        // 3. Collapse runs of consecutive <br> (whitespace between counts as adjacent).
        foreach ( $all as $el ) {
            if ( $el->parentNode && strtolower( $el->tagName ) === 'br' ) {
                $prev_el = $meaningful_prev( $el );
                if ( $prev_el instanceof DOMElement && strtolower( $prev_el->tagName ) === 'br' ) {
                    $el->parentNode->removeChild( $el );
                    $changed = true;
                }
            }
        }

        // 2. Remove trailing <br> elements that end their parent.
        foreach ( $all as $el ) {
            if ( ! $el->parentNode ) continue;
            while ( ( $last = $meaningful_last_child( $el ) ) instanceof DOMElement && strtolower( $last->tagName ) === 'br' ) {
                $el->removeChild( $last );
                $changed = true;
            }
        }

        // 1. Remove blank prunable elements with no media descendant.
        // 5. Remove lists left with no <li>.
        foreach ( array_reverse( $all ) as $el ) { // deepest-ish first
            if ( ! $el->parentNode ) continue;
            $tag = strtolower( $el->tagName );
            if ( isset( $prunable[ $tag ] ) ) {
                $has_media = $el->getElementsByTagName( 'img' )->length
                    || $el->getElementsByTagName( 'iframe' )->length
                    || $el->getElementsByTagName( 'embed' )->length;
                if ( ! $has_media && $is_blank( $el->textContent ) ) {
                    $el->parentNode->removeChild( $el );
                    $changed = true;
                }
            } elseif ( ( $tag === 'ul' || $tag === 'ol' ) && $el->getElementsByTagName( 'li' )->length === 0 ) {
                $el->parentNode->removeChild( $el );
                $changed = true;
            }
        }

        if ( ! $changed ) {
            break;
        }
    }

    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    return trim( $out );
}

/**
 * Fix 5 — affiliate/commerce link guard. Matches HREF VALUES ONLY, never body
 * text (prose like "FanDuel Sportsbook" must never trip it).
 *   domain list: exact host or subdomain — unambiguous operator links
 *   path list:   case-insensitive substring of the full URL — weaker signal
 * Actions: 'skip_item' (flag the item for the validator), 'unwrap' (drop the
 * <a>, keep inner text), 'strip_paragraph' (remove the containing <p>).
 * Every match is logged regardless of action.
 *
 * Returns ['html','events'=>[…],'skip'=>null|['code','detail']].
 */
function mmgsf_guard_affiliate_links( $html, $domains, $paths, $domain_action = 'skip_item', $path_action = 'unwrap' ) {
    $result = [ 'html' => $html, 'events' => [], 'skip' => null ];
    if ( trim( (string) $html ) === '' || stripos( $html, '<a' ) === false || ( ! $domains && ! $paths ) ) {
        return $result;
    }

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument( '1.0', 'UTF-8' );
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="mmgrf-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $root = null;
    foreach ( $doc->childNodes as $node ) {
        if ( $node instanceof DOMElement && $node->getAttribute( 'id' ) === 'mmgrf-root' ) {
            $root = $node;
            break;
        }
    }
    if ( ! $root ) {
        return $result;
    }

    $anchors = [];
    foreach ( $root->getElementsByTagName( 'a' ) as $a ) {
        $anchors[] = $a;
    }

    $apply = function( $a, $action ) use ( $root ) {
        if ( $action === 'strip_paragraph' ) {
            $p = $a->parentNode;
            while ( $p instanceof DOMElement && $p !== $root && strtolower( $p->tagName ) !== 'p' ) {
                $p = $p->parentNode;
            }
            $victim = ( $p instanceof DOMElement && $p !== $root ) ? $p : $a;
            $victim->parentNode->removeChild( $victim );
        } else { // unwrap
            mmgsf_sanitize_unwrap( $a );
        }
    };

    foreach ( $anchors as $a ) {
        if ( ! $a->parentNode ) {
            continue; // removed with an earlier paragraph
        }
        $href = trim( $a->getAttribute( 'href' ) );
        if ( $href === '' ) {
            continue;
        }
        $host = strtolower( (string) parse_url( $href, PHP_URL_HOST ) );

        $matched = null;
        foreach ( $domains as $d ) {
            $d = strtolower( trim( $d ) );
            if ( $d !== '' && ( $host === $d || substr( $host, -strlen( ".$d" ) ) === ".$d" ) ) {
                $matched = [ 'code' => 'affiliate_domain_matched', 'pattern' => $d, 'action' => $domain_action ];
                break;
            }
        }
        if ( ! $matched ) {
            foreach ( $paths as $p ) {
                $p = trim( $p );
                if ( $p !== '' && stripos( $href, $p ) !== false ) {
                    $matched = [ 'code' => 'affiliate_path_matched', 'pattern' => $p, 'action' => $path_action ];
                    break;
                }
            }
        }
        if ( ! $matched ) {
            continue;
        }

        $detail = 'pattern "' . $matched['pattern'] . '" in ' . $href . ' — action: ' . $matched['action'];
        $result['events'][] = [ 'code' => $matched['code'], 'detail' => $detail ];
        if ( $matched['action'] === 'skip_item' ) {
            $result['skip'] = [ 'code' => $matched['code'], 'detail' => $detail ];
            // Item is being withheld entirely; no need to rewrite its body.
            return $result;
        }
        $apply( $a, $matched['action'] );
    }

    $out = '';
    foreach ( $root->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    $result['html'] = trim( $out );
    return $result;
}

/** Rename an element in place, keeping attributes and children. */
function mmgsf_sanitize_rename( DOMDocument $doc, DOMElement $el, $new_tag ) {
    $new = $doc->createElement( $new_tag );
    foreach ( $el->attributes as $attr ) {
        $new->setAttribute( $attr->name, $attr->value );
    }
    while ( $el->firstChild ) {
        $new->appendChild( $el->firstChild );
    }
    $el->parentNode->replaceChild( $new, $el );
    return $new;
}

/** Replace an element with its children (preserve content, drop the tag). */
function mmgsf_sanitize_unwrap( DOMElement $el ) {
    $parent = $el->parentNode;
    while ( $el->firstChild ) {
        $parent->insertBefore( $el->firstChild, $el );
    }
    $parent->removeChild( $el );
}

function mmgsf_sanitize_attributes( DOMElement $el, $tag, $rules, $allowed ) {
    $attr_allow = null;
    if ( $allowed !== null ) {
        $map        = $rules['allowed_attributes'] ?? [];
        $attr_allow = array_fill_keys(
            array_merge( $map[ $tag ] ?? [], $map['*'] ?? [] ),
            true
        );
    }
    $patterns = $rules['strip_attr_patterns'] ?? [];

    $to_remove = [];
    foreach ( $el->attributes as $attr ) {
        $name = strtolower( $attr->name );
        if ( ! empty( $rules['strip_style_attr'] ) && $name === 'style' ) {
            $to_remove[] = $attr->name;
            continue;
        }
        if ( strpos( $name, 'on' ) === 0 ) { // onclick, onerror, ... never legitimate in a feed
            $to_remove[] = $attr->name;
            continue;
        }
        foreach ( $patterns as $p ) {
            if ( $p === $name || ( substr( $p, -1 ) === '*' && strpos( $name, rtrim( $p, '*' ) ) === 0 ) ) {
                $to_remove[] = $attr->name;
                continue 2;
            }
        }
        if ( $attr_allow !== null && ! isset( $attr_allow[ $name ] ) ) {
            $to_remove[] = $attr->name;
        }
    }
    foreach ( $to_remove as $name ) {
        $el->removeAttribute( $name );
    }
}
