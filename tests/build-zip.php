<?php
// WordPress-compliant plugin zip (forward-slash entry paths — never PS 5.1
// Compress-Archive, which writes backslashes and breaks WP's installer).
$root = dirname( __DIR__ );
$src  = $root . '/mmg-syndication-feeds';
preg_match( '/Version:\s+([\d.]+)/', file_get_contents( $src . '/mmg-syndication-feeds.php' ), $vm );
$out = $root . '/mmg-syndication-feeds-' . ( $vm[1] ?? 'dev' ) . '.zip';

@unlink( $out );
$zip = new ZipArchive();
if ( $zip->open( $out, ZipArchive::CREATE ) !== true ) {
    fwrite( STDERR, "cannot create zip\n" );
    exit( 1 );
}
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
$count = 0;
foreach ( $it as $file ) {
    if ( ! $file->isFile() ) continue;
    $rel = 'mmg-syndication-feeds/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src ) + 1 ) );
    $zip->addFile( $file->getPathname(), $rel );
    $count++;
}
$zip->close();

$check = new ZipArchive();
$check->open( $out );
$bad = 0;
for ( $i = 0; $i < $check->numFiles; $i++ ) {
    if ( strpos( $check->getNameIndex( $i ), '\\' ) !== false ) $bad++;
}
$has_main = $check->locateName( 'mmg-syndication-feeds/mmg-syndication-feeds.php' ) !== false;
$check->close();
echo basename( $out ) . ": {$count} files\n";
echo $bad === 0 ? "All entry paths use forward slashes.\n" : "ERROR: backslash entries!\n";
echo $has_main ? "Main plugin file present.\n" : "ERROR: main plugin file missing!\n";
exit( ( $bad === 0 && $has_main ) ? 0 : 1 );
