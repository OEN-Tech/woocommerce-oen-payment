<?php

declare( strict_types=1 );

/*
 * Checks on the repository itself rather than on plugin behaviour.
 *
 * They cover the parts of the release checklist in CLAUDE.md that a machine can verify, so a
 * release pull request that updates one place and forgets another fails here instead of
 * shipping wrong information to merchants. They also keep the source conventions the rest of
 * the code relies on.
 *
 * Plain file reads, plus git for the release archive: no WordPress, no network, no Docker.
 */

require_once __DIR__ . '/bootstrap.php';

function repo_root(): string {
    return dirname( __DIR__ );
}

function repo_read( string $relative_path ): string {
    $contents = file_get_contents( repo_root() . '/' . $relative_path );

    test_assert( false !== $contents, $relative_path . ' should be readable.' );

    return (string) $contents;
}

/**
 * First capture group of the first match, or an empty string when nothing matches.
 */
function repo_match( string $pattern, string $subject ): string {
    return 1 === preg_match( $pattern, $subject, $matches ) ? trim( $matches[1] ) : '';
}

/**
 * Value of a "Field: value" line from the plugin header comment or from readme.txt.
 */
function repo_header_field( string $contents, string $field ): string {
    return repo_match( '/^[ \t]*\*?[ \t]*' . preg_quote( $field, '/' ) . ':[ \t]*(\S.*?)[ \t]*$/mi', $contents );
}

function repo_plugin_version(): string {
    $version = repo_header_field( repo_read( 'woocommerce-oen-payment.php' ), 'Version' );

    test_assert(
        1 === preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version ),
        'The plugin header should declare a Version such as 1.2.3, found "' . $version . '".'
    );

    return $version;
}

function test_every_source_file_declares_strict_types(): void {
    $sources = glob( repo_root() . '/includes/*.php' );

    test_assert(
        is_array( $sources ) && count( $sources ) >= 2,
        'The plugin classes in includes/ should be found, otherwise this check proves nothing.'
    );

    $sources[] = repo_root() . '/woocommerce-oen-payment.php';

    foreach ( $sources as $source ) {
        test_assert(
            1 === preg_match( '/^declare\(\s*strict_types\s*=\s*1\s*\)\s*;/m', (string) file_get_contents( $source ) ),
            basename( $source ) . ' should declare strict_types=1.'
        );
    }
}

function test_plugin_version_is_the_same_everywhere(): void {
    $main     = repo_read( 'woocommerce-oen-payment.php' );
    $version  = repo_plugin_version();
    $constant = repo_match( "/define\(\s*'OEN_PAYMENT_VERSION'\s*,\s*'([^']+)'\s*\)/", $main );
    $tag      = repo_header_field( repo_read( 'readme.txt' ), 'Stable tag' );

    test_assert(
        $version === $constant,
        'OEN_PAYMENT_VERSION is "' . $constant . '" but the Version header is "' . $version . '".'
    );
    test_assert(
        $version === $tag,
        'Stable tag in readme.txt is "' . $tag . '" but the Version header is "' . $version . '".'
    );

    $plugin_name = repo_header_field( $main, 'Plugin Name' );
    $folder_name = 'woocommerce-oen-payment-' . $version;

    foreach ( [ 'README.md', 'docs/setup-guide.md' ] as $document ) {
        test_assert(
            str_contains( repo_read( $document ), $folder_name ),
            $document . ' should use the example folder name ' . $folder_name . '.'
        );
    }

    foreach ( [ 'languages/woocommerce-oen-payment.pot', 'languages/woocommerce-oen-payment-zh_TW.po' ] as $catalog ) {
        test_assert(
            str_contains( repo_read( $catalog ), '"Project-Id-Version: ' . $plugin_name . ' ' . $version . '\n"' ),
            'Project-Id-Version in ' . $catalog . ' should be "' . $plugin_name . ' ' . $version . '".'
        );
    }
}

function test_compatibility_fields_agree_between_plugin_header_and_readme_txt(): void {
    $main   = repo_read( 'woocommerce-oen-payment.php' );
    $readme = repo_read( 'readme.txt' );

    foreach ( [ 'Requires at least', 'Requires PHP', 'WC requires at least', 'WC tested up to' ] as $field ) {
        $in_header = repo_header_field( $main, $field );
        $in_readme = repo_header_field( $readme, $field );

        test_assert( '' !== $in_header, 'The plugin header should declare "' . $field . '".' );
        test_assert(
            $in_header === $in_readme,
            '"' . $field . '" is "' . $in_header . '" in the plugin header but "' . $in_readme . '" in readme.txt.'
        );
    }
}

function test_both_changelogs_have_an_entry_for_the_current_version(): void {
    $version = repo_plugin_version();
    $quoted  = preg_quote( $version, '/' );

    test_assert(
        1 === preg_match( '/^##[ \t]+' . $quoted . '[ \t]*$/m', repo_read( 'CHANGELOG.md' ) ),
        'CHANGELOG.md should have a "## ' . $version . '" entry.'
    );
    test_assert(
        1 === preg_match( '/^=[ \t]*' . $quoted . '[ \t]*=[ \t]*$/m', repo_read( 'readme.txt' ) ),
        'readme.txt should have a "= ' . $version . ' =" entry under == Changelog ==.'
    );
}

function test_release_archive_contains_only_what_the_plugin_needs(): void {
    $root = escapeshellarg( repo_root() );

    exec( 'git -C ' . $root . ' rev-parse --verify --quiet HEAD 2>/dev/null', $ignored, $status );

    if ( 0 !== $status ) {
        // Someone running the harness from a zip has no git history to archive. CI always
        // has one, so there a missing checkout is a failure and not a reason to skip.
        test_assert(
            false === getenv( 'CI' ),
            'The release archive check needs a git checkout with a commit, but this CI run has none.'
        );
        echo "Skipping the release archive check: this is not a git checkout with a commit.\n";
        return;
    }

    $entries = [];
    exec( 'git -C ' . $root . ' archive --format=tar HEAD | tar -t 2>&1', $entries, $status );

    test_assert( 0 === $status && [] !== $entries, 'git archive HEAD should list the release archive contents.' );
    test_assert(
        in_array( 'woocommerce-oen-payment.php', $entries, true ),
        'The release archive should contain the main plugin file, otherwise this check proves nothing.'
    );

    // Merchants install GitHub's "Source code (zip)". Anything else belongs in .gitattributes
    // with export-ignore. A file the plugin really needs at runtime goes on this list.
    $runtime_entries = [
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'assets',
        'includes',
        'languages',
        'readme.txt',
        'woocommerce-oen-payment.php',
    ];

    $unexpected = [];

    foreach ( $entries as $entry ) {
        $top_level = explode( '/', $entry )[0];

        if ( ! in_array( $top_level, $runtime_entries, true ) ) {
            $unexpected[ $top_level ] = true;
        }
    }

    test_assert(
        [] === $unexpected,
        'The release archive would contain ' . implode( ', ', array_keys( $unexpected ) )
            . '. Add them to .gitattributes with export-ignore or, if the plugin needs them at runtime, '
            . 'to the list in tests/RepositoryChecksTest.php.'
    );
}

test_every_source_file_declares_strict_types();
test_plugin_version_is_the_same_everywhere();
test_compatibility_fields_agree_between_plugin_header_and_readme_txt();
test_both_changelogs_have_an_entry_for_the_current_version();
test_release_archive_contains_only_what_the_plugin_needs();

echo "Repository checks harness passed.\n";
