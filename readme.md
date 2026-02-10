# Bizzio to WooCommerce Translation Importer

Scripts for automated synchronization and import of translations (Products and Categories) from Bizzio ERP to WooCommerce using **Polylang**.

## Overview

This project provides WP-CLI scripts to bridge the gap between Bizzio ERP data exports and a multilingual WooCommerce setup. It ensures that translations are not only imported but also correctly linked and structured (maintaining hierarchy, meta-fields, and images).

## Prerequisites

- **WP-CLI** installed and functional.
- **WordPress** with **WooCommerce** active.
- **Polylang** (or Polylang Pro) active.
- Source products/categories must already exist in the base language (default: Bulgarian `bg`).

## Installation

1. Clone or download these scripts into a directory on your server (e.g., `root dir`).
2. Ensure you have the CSV exports from Bizzio ready.

---

## 1. Product Categories Translation
**Script:** `bizzio_import_categories_polylang.php`

This script handles the translation of product categories.

### Features:
- **ID Matching:** Searches for categories by `bizzio_group_id` meta field.
- **Prefix Handling:** Automatically strips `id.` prefixes from CSV IDs (e.g., `id.123` -> `123`).
- **Meta Sync:** Copies all meta data (including thumbnails/images) from the original category.
- **Hierarchy Sync:** Rebuilds the category tree for the translated language after the import.
- **SEO Support:** Maps CSV columns to Yoast SEO or RankMath meta fields.

### Usage:
```bash
wp eval-file bizzio_import_categories_polylang.php -- path/to/keter_sitegroup.csv
```

### Expected CSV Columns:
- `(id)`: The Bizzio ID (matches `bizzio_group_id`).
- `Артикулна група (EN)`: The English name.
- `Бележка (EN)`: The English description.
- `Meta име (EN)` / `Meta описание (EN)`: Optional SEO fields.

---

## 2. Product Translation
**Script:** `bizzio_import_translation_polylang.php`

This script handles the translation of individual products.

### Features:
- **SKU Matching:** Matches products by SKU or a fallback barcode meta field.
- **Full Sync:** Copies all product data, attributes, and settings from the source product.
- **Term Sync:** Automatically links translated products to their corresponding translated categories and tags.
- **Polylang Integration:** Uses `pll_save_post_translations` to ensure the English version is correctly linked to the Bulgarian one.

### Usage:
```bash
wp eval-file bizzio_import_translation_polylang.php -- path/to/keter_articles.csv
```

---

## How it Works (Internal Logic)

1. **Mapping:** The scripts parse the CSV headers to dynamically find language columns (e.g., `Web име (EN)`).
2. **Identification:**
   - **Categories:** Uses `bizzio_group_id`.
   - **Products:** Uses SKU or `_barcode`.
3. **Translation Creation:**
   - Checks if a translation already exists via Polylang.
   - If not, it creates a new post/term and links it to the original.
4. **Data Synchronization:**
   - **Metadata:** All custom fields are copied.
   - **Images:** Featured images and gallery IDs are preserved.
   - **Terms:** The script looks for the translated version of each category assigned to the original product and assigns it to the translation.
5. **Finalization:** In the case of categories, a second pass is made to ensure the `parent` ID of the translated category matches the translation of the original parent.

## Important Notes
- **Dry Run:** You can set `$DRY_RUN = true;` inside the scripts to test the logic without making changes to the database.
- **Encoding:** The scripts include a helper to handle UTF-8 conversion for Bulgarian Cyrillic characters.
- **Backups:** Always perform a database backup before running bulk import scripts.
