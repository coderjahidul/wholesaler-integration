# Category Exclusion Feature

## Overview
This feature allows the import system to skip products that belong to specific excluded categories. These products will not be imported to WooCommerce but will be marked as complete in the database.

## Implementation Details

### Excluded Categories
A total of 186 category slugs are configured to be excluded from import. These include categories such as:
- Podwiązki
- Skarpety / Stopki
- Bielizna erotyczna / Perfumy
- Pończosznictwo / Leginsy
- And many more...

### How It Works

1. **Category Check**: During the import process, each product's categories are checked against the excluded categories list.

2. **Skip Logic**: If a product has ANY category that matches an excluded category, the product is:
   - Skipped from WooCommerce import (not added to create/update batches)
   - Marked as complete in the database
   - Logged with details about which category caused the exclusion

3. **Logging**: Each skipped product is logged with:
   - Product name
   - Product SKU
   - Category names that caused the exclusion

### Affected Methods

Both import methods have been updated with this feature:

1. **`batch_import_products()`** - Regular batch import
2. **`batch_import_products_enhanced()`** - Enhanced batch import with variation handling

### Response Data

The import response now includes a `skipped` count:

```php
[
    'success' => true,
    'message' => 'Batch import completed. Created: X, Updated: Y, Skipped: Z',
    'created' => X,
    'updated' => Y,
    'skipped' => Z,
    'errors' => [],
    'total_processed' => N
]
```

## Modifying Excluded Categories

To add or remove categories from the exclusion list:

1. Open `/includes/class-wholesaler-batch-import.php`
2. Locate the `$excluded_categories` property (around line 39)
3. Add or remove category slugs from the array
4. Save the file

**Important**: Category names must match exactly as they appear in the `category_terms` data from the wholesaler services.

## Example Log Output

When a product is skipped, you'll see log entries like:

```
Skipping product Example Product Name (SKU: ABC123) - excluded category: Skarpety, Stopki
```

## Database Impact

Skipped products are marked with status `COMPLETED` in the `wp_sync_wholesaler_products_data` table, ensuring they won't be reprocessed in future import batches.

## Performance

The exclusion check is performed before any WooCommerce API calls, making it very efficient:
- No unnecessary API calls for excluded products
- Minimal database overhead
- Fast array comparison using PHP's native `in_array()` function

## Benefits

1. **Reduced Import Time**: Skip unwanted products early in the process
2. **Cleaner Product Catalog**: Only import products that are relevant to your store
3. **Better Resource Usage**: No unnecessary API calls or database operations
4. **Full Audit Trail**: All skipped products are logged for review

## Testing

To verify the feature is working:

1. Check the import logs in `program_logs/import_products.log`
2. Look for "Skipping product" messages
3. Verify the import response includes the `skipped` count
4. Confirm excluded products are marked as `COMPLETED` in the database

## Troubleshooting

**Products not being skipped?**
- Verify the category name matches exactly (case-sensitive)
- Check the log to see what categories the product has
- Ensure the category exists in the `$excluded_categories` array

**Too many products being skipped?**
- Review the excluded categories list
- Remove any categories that should be imported
- Check for duplicate or similar category names

