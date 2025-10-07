# Stock Cleanup System - Complete Guide

## 🎯 **Overview**

The Stock Cleanup System automatically removes products based on stock quantity rules using background processing. This ensures your store only displays products with adequate inventory.

---

## 📋 **Removal Rules**

### **Rule 1 & 2: Single Piece Products**
Products with **1 or fewer pieces** across ALL variations are removed.
- Applies to all categories
- Checks total stock across all variations
- Example: Product with 3 variations (0, 0, 1 pieces) = Total 1 piece → **REMOVED**

### **Rule 3: BRAS Category Special Rule**
Products in the **BRAS category** with **fewer than 5 pieces** total are removed.
- Only applies to BRAS category products
- Checks total stock across all variations
- Example: BRAS product with 4 pieces → **REMOVED**
- Example: BRAS product with 5+ pieces → **KEPT**

---

## 🚀 **Usage**

### **Start Stock Cleanup Job**

**Endpoint:**
```
POST /wp-json/wholesaler/v1/remove-out-of-stock-products
```

**Parameters:**
- `batch_size` (optional, default: 50): Number of products to check per batch (1-200)

**Example Request:**
```bash
curl -X POST "https://yoursite.com/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -H "Content-Type: application/json" \
  -d '{
    "batch_size": 100
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Stock cleanup job scheduled successfully",
  "job_id": 42,
  "scheduled": true
}
```

---

## 📊 **Monitoring Jobs**

### **Check Specific Job Status**

**Endpoint:**
```
GET /wp-json/wholesaler/v1/stock-cleanup-status/{job_id}
```

**Example:**
```bash
curl "https://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-status/42"
```

**Response:**
```json
{
  "success": true,
  "job": {
    "id": 42,
    "status": "completed",
    "total_processed": 150,
    "total_removed": 23,
    "total_errors": 0,
    "error_messages": [],
    "removed_products": [
      {
        "id": 1234,
        "name": "Product Name",
        "reason": "Total stock (1) across all variations is 1 or less"
      },
      {
        "id": 1235,
        "name": "Bra Product",
        "reason": "BRAS category product with stock (3) less than 5 pieces"
      }
    ],
    "started_at": "2025-10-07 10:30:00",
    "completed_at": "2025-10-07 10:32:15",
    "created_at": "2025-10-07 10:29:55",
    "configuration": {
      "batch_size": 100
    }
  }
}
```

### **Get All Cleanup Jobs**

**Endpoint:**
```
GET /wp-json/wholesaler/v1/stock-cleanup-jobs
```

**Parameters:**
- `status` (optional, default: 'all'): Filter by status (all, scheduled, running, completed, failed)
- `limit` (optional, default: 20): Number of jobs to return

**Example:**
```bash
# Get all jobs
curl "https://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-jobs"

# Get only completed jobs
curl "https://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-jobs?status=completed&limit=10"
```

**Response:**
```json
{
  "success": true,
  "jobs": [
    {
      "id": 42,
      "status": "completed",
      "total_processed": 150,
      "total_removed": 23,
      "total_errors": 0,
      "error_count": 0,
      "started_at": "2025-10-07 10:30:00",
      "completed_at": "2025-10-07 10:32:15",
      "created_at": "2025-10-07 10:29:55",
      "duration": 135
    }
  ],
  "stats": {
    "total_jobs": 5,
    "scheduled": 0,
    "running": 1,
    "completed": 4,
    "failed": 0,
    "total_products_processed": 750,
    "total_products_removed": 112
  },
  "filter": {
    "status": "all",
    "limit": 20
  }
}
```

---

## 🔄 **Job Lifecycle**

1. **Scheduled** → Job created and queued for processing
2. **Running** → Job actively processing products
3. **Completed** → Job finished successfully
4. **Failed** → Job encountered critical error

---

## 💡 **Usage Examples**

### **Example 1: Quick Cleanup (Small Store)**
```bash
# Process 50 products at a time
curl -X POST "https://yoursite.com/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -d '{"batch_size": 50}'
```

### **Example 2: Large Cleanup (Big Store)**
```bash
# Process 200 products at a time for faster cleanup
curl -X POST "https://yoursite.com/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -d '{"batch_size": 200}'
```

### **Backup Recommendation**
Always backup your database before running cleanup operations:
```bash
# Example database backup
wp db export backup-$(date +%Y%m%d-%H%M%S).sql
```

## 🛠️ **Troubleshooting**

### **Job Stuck in "Running" Status**
Check the job details for errors:
```bash
curl "https://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-status/{job_id}"
```

### **No Products Removed**
Possible reasons:
1. All products have adequate stock (good!)
2. BRAS category not detected (check category name/slug)
3. All variations have stock > 1

Check the job details to see which products were evaluated.

### **BRAS Category Not Detected**
Ensure your BRAS category:
- Uses one of the supported names/slugs
- Exists in the `product_cat` taxonomy
- Is properly assigned to products

You can manually check:
```bash
# List all product categories
curl "https://yoursite.com/wp-json/wc/v3/products/categories"
```

---

## 📊 **Database Tables**

### **Stock Cleanup Jobs Table**
```sql
wp_wholesaler_stock_cleanup_jobs
- id: Job identifier
- job_data: Configuration data
- status: Current job status
- total_processed: Products checked
- total_removed: Products deleted
- total_errors: Error count
- error_messages: JSON array of errors
- removed_products: JSON array of removed products
- started_at: Job start timestamp
- completed_at: Job completion timestamp
- created_at: Job creation timestamp
```

---

## 🔗 **Integration with Existing Systems**

This cleanup system works alongside:
- Background import jobs
- Image processing jobs
- Existing cron-based cleanup (in `wholesaler-integration.php`)

All jobs use the same background processing infrastructure for consistency.

---

## 📝 **Example Workflow**

1. **Import Products**: Use batch import to add/update products
2. **Wait for Completion**: Monitor import job status
3. **Run Cleanup**: Remove low-stock products
4. **Monitor Progress**: Check cleanup job status
5. **Review Results**: Check removed products list

```bash
# Step 1: Import products
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{"total_batches": 5, "products_per_batch": 100}'

# Step 2: Wait for import to complete...

# Step 3: Run cleanup
curl -X POST "/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -d '{"batch_size": 100}'

# Step 4: Monitor
curl "/wp-json/wholesaler/v1/stock-cleanup-jobs?status=all&limit=5"
```

---

## ✅ **Best Practices**

1. **Regular Schedule**: Run cleanup weekly or after major imports
2. **Appropriate Batch Size**: 
   - Small stores: 50
   - Medium stores: 100
   - Large stores: 200
3. **Monitor Results**: Always check removed products list
4. **Backup First**: Create database backup before cleanup
5. **Test Rules**: Verify on staging before production

---

## 🆘 **Support**

If you encounter issues:
1. Check job error messages
2. Review WordPress debug logs
3. Verify BRAS category exists
4. Check database connectivity
5. Ensure background jobs are enabled

---

## 📅 **Changelog**

### Version 1.0.0 (2025-10-07)
- Initial release
- Three-tier stock rules
- Background processing
- Job monitoring
- BRAS category detection
- Comprehensive API endpoints

