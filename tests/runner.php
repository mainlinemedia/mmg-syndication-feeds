<?php
/** Micro test runner: register tests with t(), assert with expect_*(), summary + exit code. */

$GLOBALS['mmgrf_runner'] = [ 'tests' => [], 'pass' => 0, 'fail' => 0, 'failures' => [] ];

function t( $name, callable $fn ) {
    $GLOBALS['mmgrf_runner']['tests'][] = [ $name, $fn ];
}

class MMGRF_AssertFail extends Exception {}

function expect_eq( $actual, $expected, $label = '' ) {
    if ( $actual !== $expected ) {
        throw new MMGRF_AssertFail(
            ( $label ? "$label: " : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
        );
    }
}
function expect_true( $cond, $label = '' ) {
    if ( ! $cond ) throw new MMGRF_AssertFail( ( $label ?: 'condition' ) . ' expected true' );
}
function expect_false( $cond, $label = '' ) {
    if ( $cond ) throw new MMGRF_AssertFail( ( $label ?: 'condition' ) . ' expected false' );
}
function expect_contains( $haystack, $needle, $label = '' ) {
    if ( strpos( (string) $haystack, (string) $needle ) === false ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected to contain ' . var_export( $needle, true ) . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}
function expect_not_contains( $haystack, $needle, $label = '' ) {
    if ( strpos( (string) $haystack, (string) $needle ) !== false ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected NOT to contain ' . var_export( $needle, true ) . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}
function expect_match( $haystack, $pattern, $label = '' ) {
    if ( ! preg_match( $pattern, (string) $haystack ) ) {
        throw new MMGRF_AssertFail( ( $label ? "$label: " : '' ) . 'expected to match ' . $pattern . "\n--- in ---\n" . mb_substr( (string) $haystack, 0, 2000 ) );
    }
}

function run_all_tests() {
    $r = &$GLOBALS['mmgrf_runner'];
    foreach ( $r['tests'] as [ $name, $fn ] ) {
        mmgrf_test_reset();
        try {
            $fn();
            $r['pass']++;
            fwrite( STDOUT, "PASS  $name\n" );
        } catch ( MMGRF_AssertFail $e ) {
            $r['fail']++;
            $r['failures'][] = [ $name, $e->getMessage() ];
            fwrite( STDOUT, "FAIL  $name\n      " . str_replace( "\n", "\n      ", $e->getMessage() ) . "\n" );
        } catch ( Throwable $e ) {
            $r['fail']++;
            $r['failures'][] = [ $name, get_class( $e ) . ': ' . $e->getMessage() ];
            fwrite( STDOUT, "ERROR $name\n      " . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" );
        }
    }
    fwrite( STDOUT, "\n" . $r['pass'] . ' passed, ' . $r['fail'] . " failed\n" );
    exit( $r['fail'] > 0 ? 1 : 0 );
}
