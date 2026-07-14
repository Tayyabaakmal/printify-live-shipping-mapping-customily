# Printify Live Shipping & Mapping for Customily

A production-ready WooCommerce plugin that maps Printify product metadata to products created via Customily, and calculates **live shipping rates** directly from the Printify Catalog API at checkout. Built to solve a real-world integration gap where the official Printify plugin cannot recognise Customily-created products.

## Background

When products are created through Customily and connected to Printify, the official Printify for WooCommerce plugin cannot recognise them for shipping calculation. This plugin solves that problem by:

1. Importing a CSV export of Printify product data
2. Fuzzy-matching each CSV row to WooCommerce products/variations
3. Writing the correct Printify metadata (`blueprint_id`, `provider_id`, `variant_id`) to WooCommerce
4. Calculating live shipping rates directly from the Printify Catalog API at checkout

## Features

- **CSV Import & Matching** — Upload a Printify CSV export; the plugin fuzzy-matches each row to WooCommerce products using title similarity, SKU, and variant attributes
- **Confidence scoring** — Every match gets a confidence score (0–100%); only high-confidence matches are auto-approved
- **Bulk review UI** — Paginated AJAX table with filtering, search, bulk approve/reject, and page-jump navigation
- **Live shipping rates** — Queries the Printify Catalog Shipping API at checkout; correctly handles multi-product carts, country detection, and REST_OF_THE_WORLD fallback
- **Meta management** — Apply approved mappings in bulk; undo/clear meta from individual or all products
- **Error log** — Failed apply operations are logged and viewable in the admin
- **CSV export** — Export applied/approved mappings for record-keeping

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- A Printify API key and Shop ID

## Installation

1. Download or clone this repository
2. Upload the `printify-mapping-restore` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress Admin → Plugins
4. Go to **Printify Restore → Settings** and enter your Printify API Key and Shop ID

## Usage

### 1. Import & Match
- Go to **Printify Restore → Import & Match**
- Upload your Printify product CSV export
- Run the matching process
- Review results in **Review & Apply**

### 2. Review & Apply
- Filter by status: All / Pending / Approved / Rejected / Unmatched / Applied
- Approve matches individually or in bulk
- For unmatched products, click **Fill Unmatched IDs** to enter Printify IDs manually
- Click **Apply Approved to WooCommerce** to write metadata to products

### 3. Shipping
- Add the **Printify Shipping (Mapping Restore)** method to your WooCommerce shipping zone
- The plugin fetches live rates from Printify at checkout based on each product's blueprint and provider
- Only rate types supported by **all** products in the cart are shown

### 4. Clear Meta
- Go to **Printify Restore → Clear Meta** to remove all Printify metadata from products
- Supports clearing all at once or by individual session

## Plugin Structure

```
printify-mapping-restore/
├── printify-mapping-restore.php     # Main plugin file, hooks, shipping sync
├── admin/
│   ├── class-pmr-admin.php          # Admin menu, AJAX handlers
│   └── views/
│       ├── dashboard.php
│       ├── import.php
│       ├── review.php
│       ├── settings.php
│       └── clear-meta.php
├── includes/
│   ├── class-pmr-csv-parser.php     # CSV parsing and column detection
│   ├── class-pmr-matcher.php        # Fuzzy matching engine
│   ├── class-pmr-db.php             # Custom DB table (proposals)
│   ├── class-pmr-meta-writer.php    # Reads/writes WooCommerce product meta
│   ├── class-pmr-printify-api.php   # Printify Catalog & Orders API client
│   └── class-pmr-shipping-method.php # WC_Shipping_Method implementation
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## Author

**Tayyaba Akmal**

## License

GPL-2.0-or-later
