<?php
/**
 * Run: wp eval-file bizzio_import_categories_polylang.php keter_sitegroup.csv
 * 
 * Logic:
 * 1. Reads CSV with category data.
 * 2. Finds the original (BG) term using 'bizzio_group_id' meta which matches the CSV '(id)' column.
 * 3. Checks/Creates the English translation using Polylang.
 * 4. Copies all meta (including images/thumbnails) from the BG term to the EN term.
 * 5. Updates the EN term name and description from the CSV.
 * 6. (Optional) Updates SEO meta if columns exist and are mapped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once __DIR__ . '/wp-load.php';
}
if ( ! class_exists( 'WP_CLI' ) ) {
	fwrite( STDERR, "Run via WP-CLI.
" );
	exit( 1 );
}

$CSV_PATH  = $args[0] ?? 'keter_sitegroup.csv';
$CSV_DELIM = ';';
$TAXONOMY  = 'product_cat';
$LANG_SRC  = 'bg'; // Assuming source is BG
$LANG_DEST = 'en'; // Target language

if ( ! file_exists( $CSV_PATH ) ) {
	WP_CLI::error( "File not found: $CSV_PATH" );
}

// Helpers
function _bizzio_to_utf8( $s ) {
	if ( $s === null ) {
		return null;
	}
	$enc = mb_detect_encoding( $s, [ 'UTF-8', 'Windows-1251', 'Windows-1252', 'ISO-8859-1' ], true );
	return ( $enc && $enc !== 'UTF-8' ) ? mb_convert_encoding( $s, 'UTF-8', $enc ) : $s;
}

function _bizzio_get_col_index( $header, $names ) {
	foreach ( $names as $name ) {
		foreach ( $header as $idx => $h ) {
			// Case-insensitive exact match or match within parentheses or partial match
			if ( strcasecmp( $h, $name ) === 0 || strcasecmp( $h, "($name)" ) === 0 || stripos( $h, $name ) !== false ) {
				return $idx;
			}
		}
	}
	return false;
}

function _bizzio_copy_term_meta( $source_term_id, $target_term_id ) {
	$meta = get_term_meta( $source_term_id );
	// Keys to ignore
	$ignore_keys = [
		'_pll_string_translations',
		// Add other Polylang specific keys if needed
	];

	foreach ( $meta as $key => $values ) {
		if ( in_array( $key, $ignore_keys, true ) ) {
			continue;
		}
		
		// Clear existing meta on target to avoid duplication if running multiple times?
		// Or just update/add. delete_term_meta is safer for full sync.
		delete_term_meta( $target_term_id, $key );

		foreach ( $values as $value ) {
			add_term_meta( $target_term_id, $key, maybe_unserialize( $value ) );
		}
	}
}

// Main execution
WP_CLI::log( "Reading CSV: $CSV_PATH" );
$fh = fopen( $CSV_PATH, 'r' );
if ( ! $fh ) {
	WP_CLI::error( "Cannot open CSV." );
}

$header = fgetcsv( $fh, 0, $CSV_DELIM, '"', '\\' );
if ( ! $header ) {
	WP_CLI::error( "CSV header is empty or file not readable." );
}
$header = array_map( function ( $h ) {
	return trim( _bizzio_to_utf8( $h ) );
}, $header );

// Identify columns
$col_id        = _bizzio_get_col_index( $header, [ 'id', '(id)' ] );
$col_name_en   = _bizzio_get_col_index( $header, [ 'Артикулна група (EN)', 'Name (EN)' ] );
$col_desc_en   = _bizzio_get_col_index( $header, [ 'Бележка (EN)', 'Description (EN)' ] );
// SEO Columns (optional mappings)
$col_meta_title_en = _bizzio_get_col_index( $header, [ 'Meta име (EN)', 'Meta Title (EN)' ] );
$col_meta_desc_en  = _bizzio_get_col_index( $header, [ 'Meta описание (EN)', 'Meta Description (EN)' ] );

if ( $col_id === false ) {
	WP_CLI::log( "Detected Header Columns: " . implode( ' | ', $header ) );
	WP_CLI::error( "Required ID column not found in CSV." );
}

$updated = 0;
$skipped = 0;
$created = 0;
$hierarchy_queue = [];

wp_suspend_cache_invalidation( true );

while ( ( $row = fgetcsv( $fh, 0, $CSV_DELIM, '"', '\\' ) ) !== false ) {
	$row = array_map( '_bizzio_to_utf8', $row );

	$bizzio_id = trim( $row[ $col_id ] );
	if ( empty( $bizzio_id ) ) {
		continue;
	}

	// Strip 'id.' prefix if it exists (e.g., id.291608440944460608 -> 291608440944460608)
	$bizzio_id_clean = ltrim( str_replace( 'id.', '', $bizzio_id ), '!' );

	// 1. Find Original Term by Meta
	$terms = get_terms( [
		'taxonomy'   => $TAXONOMY,
		'hide_empty' => false,
		'meta_query' => [
			[
				'key'     => 'bizzio_group_id',
				'value'   => $bizzio_id_clean,
				'compare' => '='
			]
		]
	] );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		WP_CLI::warning( "Original term not found for bizzio_id: $bizzio_id_clean (original: $bizzio_id)" );
		$skipped ++;
		continue;
	}

	$source_term = $terms[0]; // Assuming the first one is the correct one (usually BG)
	$source_term_id = $source_term->term_id;

	// Verify source language if needed, but for now assuming the found term is the source.

	// 2. Get/Create Translation
	$translations = [];
	if ( function_exists( 'pll_get_term_translations' ) ) {
		$translations = pll_get_term_translations( $source_term_id );
	}

	$target_term_id = $translations[ $LANG_DEST ] ?? null;
	$is_new         = false;

	$name_en = $row[ $col_name_en ] ?? '';
	$desc_en = $row[ $col_desc_en ] ?? '';

	if ( empty( $name_en ) ) {
		WP_CLI::log( "Skipping ID $bizzio_id: Empty EN name." );
		$skipped ++;
		continue;
	}

	if ( ! $target_term_id ) {
		// Create new term
		$args = [
			'description' => $desc_en,
			'slug'        => sanitize_title( $name_en ), // Let WP handle uniqueness
		];

		// Check if term with this name already exists in EN to avoid duplicates not linked
		// (Optional check, but WP insert_term handles duplicates by returning error)
		
		$new_term = wp_insert_term( $name_en, $TAXONOMY, $args );

		if ( is_wp_error( $new_term ) ) {
			if ( isset( $new_term->error_data['term_exists'] ) ) {
				$target_term_id = $new_term->error_data['term_exists'];
				WP_CLI::log( "Term already exists (unlinked): $name_en (ID: $target_term_id)" );
			} else {
				WP_CLI::warning( "Failed to create term '$name_en': " . $new_term->get_error_message() );
				$skipped++;
				continue;
			}
		} else {
			$target_term_id = $new_term['term_id'];
			$is_new = true;
			$created++;
		}

		// Set Language
		if ( function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( $target_term_id, $LANG_DEST );
		}

		// Link Translations
		if ( function_exists( 'pll_save_term_translations' ) ) {
			// Refresh translations in case source has changed
			$translations = pll_get_term_translations( $source_term_id );
			$translations[ $LANG_DEST ] = $target_term_id;
			// Ensure source is also in the array
			$src_lang = pll_get_term_language( $source_term_id );
			if ( $src_lang ) {
				$translations[ $src_lang ] = $source_term_id;
			}
			pll_save_term_translations( $translations );
		}

	} else {
		// Update existing term
		wp_update_term( $target_term_id, $TAXONOMY, [
			'name'        => $name_en,
			'description' => $desc_en,
		] );
		$updated++;
	}

	// 3. Copy Meta from Source
	_bizzio_copy_term_meta( $source_term_id, $target_term_id );

	// 4. Update SEO Meta (Yoast Example) if present
	if ( $col_meta_title_en !== false && ! empty( $row[ $col_meta_title_en ] ) ) {
		update_term_meta( $target_term_id, '_yoast_wpseo_title', $row[ $col_meta_title_en ] );
		// RankMath fallback
		update_term_meta( $target_term_id, 'rank_math_title', $row[ $col_meta_title_en ] );
	}
	if ( $col_meta_desc_en !== false && ! empty( $row[ $col_meta_desc_en ] ) ) {
		update_term_meta( $target_term_id, '_yoast_wpseo_metadesc', $row[ $col_meta_desc_en ] );
		// RankMath fallback
		update_term_meta( $target_term_id, 'rank_math_description', $row[ $col_meta_desc_en ] );
	}

	WP_CLI::log( "Processed: $bizzio_id -> EN Term ID: $target_term_id" . ($is_new ? " [NEW]" : "") );
	
	// Queue for hierarchy sync
	$hierarchy_queue[] = [ 'src' => $source_term_id, 'dest' => $target_term_id ];
}

wp_suspend_cache_invalidation( false );
fclose( $fh );

// --- Sync Hierarchy ---
WP_CLI::log( "Syncing category hierarchy..." );
foreach ( $hierarchy_queue as $item ) {
	$src_id  = $item['src'];
	$dest_id = $item['dest'];

	$src_term = get_term( $src_id, $TAXONOMY );
	if ( ! $src_term || is_wp_error( $src_term ) ) {
		continue;
	}

	$dest_parent_id = 0;
	if ( $src_term->parent > 0 ) {
		// Find parent's translation
		if ( function_exists( 'pll_get_term_translations' ) ) {
			$parent_trans = pll_get_term_translations( $src_term->parent );
			$dest_parent_id = $parent_trans[ $LANG_DEST ] ?? 0;
		}
	}

	// Update parent if it differs
	$dest_term = get_term( $dest_id, $TAXONOMY );
	if ( $dest_term && $dest_term->parent != $dest_parent_id ) {
		wp_update_term( $dest_id, $TAXONOMY, [ 'parent' => $dest_parent_id ] );
		WP_CLI::log( "Updated parent for Term ID $dest_id -> Parent ID $dest_parent_id" );
	}
}

WP_CLI::success( "Import complete. Created: $created, Updated: $updated, Skipped: $skipped" );
