<?php
/**
 * Run: wp eval-file bizzio_import_translation_polylang.php /path/to/EEG.csv
 * Импортира EN преводи към BG продукти по "Код" (SKU или fallback към _barcode).
 * Polylang: създава/връзва превод, записва Title/Content/Excerpt и примерни мета.
 */

if (!defined('ABSPATH')) require_once __DIR__ . '/wp-load.php';
if (!class_exists('WP_CLI')) { fwrite(STDERR, "Run via WP-CLI.\\n"); exit(1); }

/** ---- Настройки ---- */
$DRY_RUN     = false;             // true => тест без запис
$CSV_PATH    = $args[0] ?? null;
$CSV_DELIM   = ';';               // ← твоят файл е със ';'
$CSV_ENCLOS  = '"';
$FALLBACK_META_FOR_CODE = '_barcode'; // ако "Код" ≠ SKU

if (!$CSV_PATH || !file_exists($CSV_PATH)) {
    WP_CLI::error("Usage: wp eval-file bizzio_import_translation_polylang.php /absolute/path/to/your_data.csv");
}

/** ---- Помощни ---- */
function _to_utf8($s) {
    if ($s === null) return null;
    $enc = mb_detect_encoding($s, ['UTF-8','Windows-1251','Windows-1252','ISO-8859-1'], true);
    return ($enc && $enc !== 'UTF-8') ? mb_convert_encoding($s, 'UTF-8', $enc) : $s;
}
function _pick_col(array $header, array $candidates) {
    foreach ($candidates as $c) { $i = array_search($c, $header, true); if ($i !== false) return $i; }
    return null;
}
function _csv_val($row, $idx) { return $idx===null ? null : _to_utf8(trim((string)($row[$idx] ?? ''))); }

/** ---- Четене на CSV ---- */
$fh = fopen($CSV_PATH, 'r');
if (!$fh) WP_CLI::error("Cannot open: $CSV_PATH");

$header = fgetcsv($fh, 0, $CSV_DELIM, $CSV_ENCLOS, '\\');
if (!$header) WP_CLI::error("CSV seems empty.");
$header = array_map(fn($h) => trim(_to_utf8($h)), $header);

/** ---- Динамично намиране на езици от хедъра ---- */
$TARGET_LANGS = [];
foreach ($header as $col_name) {
    if (preg_match('/Web име \((.+)\)/', $col_name, $matches)) {
        $lang_code = strtolower($matches[1]);
        if (!in_array($lang_code, $TARGET_LANGS)) {
            $TARGET_LANGS[] = $lang_code;
        }
    }
}

if (empty($TARGET_LANGS)) {
    WP_CLI::warning("No language columns found in CSV header (e.g. 'Web име (en)').");
} else {
    WP_CLI::log("Detected languages: " . implode(', ', $TARGET_LANGS));
}

$COL_CODE = _pick_col($header, ['Код','Code','SKU']);
if ($COL_CODE === null) WP_CLI::error("Missing required column: 'Код' / 'Code' / 'SKU'");

WP_CLI::log("Reading CSV: $CSV_PATH");
$line=1; $updated=0; $skipped=0;

wp_suspend_cache_invalidation(true);

while (($row = fgetcsv($fh, 0, $CSV_DELIM, $CSV_ENCLOS, '\\')) !== false) {
    $line++;
    $row = array_map('_to_utf8', $row);
    $code = _csv_val($row, $COL_CODE);

    if (empty($code)) { $skipped++; continue; }

    // Намери BG продукт по SKU → иначе по fallback meta
    $product_id_bg = wc_get_product_id_by_sku($code);
    if (!$product_id_bg && $FALLBACK_META_FOR_CODE) {
        global $wpdb;
        $product_id_bg = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID=pm.post_id
             WHERE pm.meta_key=%s AND pm.meta_value=%s
               AND p.post_type IN ('product','product_variation')
             LIMIT 1",
            $FALLBACK_META_FOR_CODE, $code
        ));
    }
    if (!$product_id_bg) { WP_CLI::warning("Line $line: BG product not found for code=$code"); $skipped++; continue; }

    foreach ($TARGET_LANGS as $lang) {
        $lang_upper = strtoupper($lang);
        $col_title   = _pick_col($header, ["Web име ($lang_upper)", "Артикул ($lang_upper)", "Title ($lang_upper)"]);
        $col_content = _pick_col($header, ["Web описание ($lang_upper)", "Описание ($lang_upper)", "Description ($lang_upper)"]);
        $col_excerpt = _pick_col($header, ["Кратко описание ($lang_upper)", "Excerpt ($lang_upper)"]);

        $title   = _csv_val($row, $col_title);
        $content = _csv_val($row, $col_content);
        $excerpt = _csv_val($row, $col_excerpt);

        if (empty($title)) {
            WP_CLI::log("Skipping lang '$lang' for code=$code: empty title.");
            continue;
        }

        // Осигури превод (Polylang)
        $product_id_tr = _ensure_post_translation($product_id_bg, $lang, $DRY_RUN);

        if (!$DRY_RUN) {
            // 1. Копирай всички данни от BG продукта
            _sync_all_product_data($product_id_bg, $product_id_tr);

            // 2. Обнови с преведените заглавие, описание и откъс
            $update_data = ['ID' => $product_id_tr];
            if (!empty($title))   $update_data['post_title']   = $title;
            if (!empty($content)) $update_data['post_content'] = $content;
            if (!empty($excerpt)) $update_data['post_excerpt'] = $excerpt;
            wp_update_post($update_data);

            // 3. Синхронизирай категории, тагове и атрибути
            _sync_terms_to_lang($product_id_bg, $product_id_tr, $lang);
        }

        WP_CLI::log("✔ code=$code → $lang_upper ID $product_id_tr");
        $updated++;
    }
}

wp_suspend_cache_invalidation(false);
fclose($fh);
WP_CLI::success("Done. updated=$updated, skipped=$skipped. Dry-run=".($DRY_RUN?'yes':'no'));

/** ---- Helpers ---- */

function _sync_all_product_data(int $source_id, int $target_id): void {
    $source_meta = get_post_meta($source_id);
    if (empty($source_meta)) return;

    // Мета полета, които не трябва да се копират директно
    $meta_blacklist = [
        '_edit_lock', '_edit_last', 'post_title', 'post_content', 'post_excerpt',
        'pll_sync_post', '_dp_original', '_icl_lang_duplicate_of',
        // Всички полета, управлявани от Polylang за връзките
    ];

    foreach ($source_meta as $meta_key => $meta_values) {
        // Игнорирай, ако е в черния списък или е специфично за Polylang
        if (in_array($meta_key, $meta_blacklist) || strpos($meta_key, '_pll_') === 0) {
            continue;
        }

        // Копирай всяка стойност (обикновено е само една)
        foreach ($meta_values as $meta_value) {
            // update_post_meta ще се погрижи за сериализацията, ако е нужна
            update_post_meta($target_id, $meta_key, maybe_unserialize($meta_value));
        }
    }
    // Увери се, че типа на продукта е същия (пр. simple, variable)
    $source_product_type = wp_get_object_terms($source_id, 'product_type', ['fields' => 'slugs']);
    if(!is_wp_error($source_product_type) && !empty($source_product_type)) {
        wp_set_object_terms($target_id, $source_product_type[0], 'product_type');
    }
}

function _ensure_post_translation(int $source_id, string $lang, bool $dry): int {
    if (function_exists('pll_get_post_translations')) {
        $tr = pll_get_post_translations($source_id) ?: [];
        if (!empty($tr[$lang])) return (int)$tr[$lang];
    } else return $source_id;

    if ($dry) return $source_id;

    $src = get_post($source_id);
    $new_id = wp_insert_post([
        'post_type'   => $src->post_type,
        'post_status' => 'publish',
        'post_author' => $src->post_author,
        'post_parent' => $src->post_parent ?: 0,
        'menu_order'  => $src->menu_order ?: 0,
        'post_title'  => $src->post_title . " ($lang)", // Временно заглавие
        'post_name'   => wp_unique_post_slug($src->post_name.'-'.sanitize_title($lang), 0, 'publish', $src->post_type, 0),
    ]);

    pll_set_post_language($new_id, $lang);
    $src_lang = pll_get_post_language($source_id);
    $translations = pll_get_post_translations($source_id) ?: [];
    $translations[$lang] = $new_id;
    if ($src_lang && !isset($translations[$src_lang])) {
        $translations[$src_lang] = $source_id;
    }
    pll_save_post_translations($translations);

    return (int)$new_id;
}

function _sync_terms_to_lang(int $bg_id, int $en_id, string $lang): void {
    if (!function_exists('wc_get_attribute_taxonomy_names')) return;
    $taxes = array_unique(array_merge(['product_cat','product_tag'], wc_get_attribute_taxonomy_names()));
    foreach ($taxes as $tax) {
        $terms_bg = wp_get_object_terms($bg_id, $tax, ['fields'=>'ids']);
        if (is_wp_error($terms_bg) || empty($terms_bg)) continue;
        $target_term_ids = [];
        foreach ($terms_bg as $term_id_bg) {
            $target_id = _ensure_term_translation($tax, $term_id_bg, $lang);
            if ($target_id) $target_term_ids[] = $target_id;
        }
        if ($target_term_ids) wp_set_object_terms($en_id, $target_term_ids, $tax, false);
    }
}
function _ensure_term_translation(string $taxonomy, int $base_term_id, string $lang): ?int {
    if (!function_exists('pll_get_term_translations')) return $base_term_id;

    // 1. Check for existing Polylang translation
    $trans = pll_get_term_translations($base_term_id) ?: [];
    if (!empty($trans[$lang])) {
        return (int)$trans[$lang];
    }

    $base_term = get_term($base_term_id, $taxonomy);
    if (!$base_term || is_wp_error($base_term)) return null;

    // 2. Check for a term with the same name in the target language
    $existing_terms = get_terms([
        'taxonomy'   => $taxonomy,
        'name'       => $base_term->name,
        'lang'       => $lang,
        'fields'     => 'ids',
        'hide_empty' => false,
        'number'     => 1,
    ]);

    if (!is_wp_error($existing_terms) && !empty($existing_terms)) {
        $existing_term_id = (int)$existing_terms[0];
        $src_lang = pll_get_term_language($base_term_id);
        if ($src_lang) {
            $trans[$src_lang] = $base_term_id;
        }
        $trans[$lang] = $existing_term_id;
        pll_save_term_translations($trans);
        return $existing_term_id;
    }

    // 3. If no term was found, create a new one
    $res = wp_insert_term($base_term->name, $taxonomy, [
        'slug' => wp_unique_term_slug($base_term->slug . '-' . $lang, (object)['taxonomy' => $taxonomy]),
    ]);

    if (is_wp_error($res)) {
        if (isset($res->error_data['term_exists'])) {
            $existing_id = (int)$res->error_data['term_exists'];
            pll_set_term_language($existing_id, $lang);
            $src_lang = pll_get_term_language($base_term_id);
            if ($src_lang) {
                $trans[$src_lang] = $base_term_id;
            }
            $trans[$lang] = $existing_id;
            pll_save_term_translations($trans);
            return $existing_id;
        }
        return null;
    }

    // 4. If creation was successful, set language and save translation
    $new_id = (int)$res['term_id'];
    pll_set_term_language($new_id, $lang);

    $src_lang = pll_get_term_language($base_term_id);
    if ($src_lang) {
        $trans[$src_lang] = $base_term_id;
    }
    $trans[$lang] = $new_id;
    pll_save_term_translations($trans);

    $gid = get_term_meta($base_term_id, 'bizzio_group_id', true);
    if ($gid !== '') {
        update_term_meta($new_id, 'bizzio_group_id', $gid);
    }

    return $new_id;
}
