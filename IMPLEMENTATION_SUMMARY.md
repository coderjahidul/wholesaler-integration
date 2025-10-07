# Stock Cleanup Implementation Summary

## ✅ **What Was Implemented**

### **1. New Endpoint**
**POST** `/wp-json/wholesaler/v1/remove-out-of-stock-products`

Removes products based on stock rules using background processing (job queue).

### **2. Stock Removal Rules**

#### **Rule 1 & 2: Single Piece Products**
- Products with **1 or fewer pieces** across ALL variations are removed
- Applies to every category
- Example: Product with variations having (0, 1, 0) pieces = Total 1 → **REMOVED**

#### **Rule 3: BRAS Category**
- Products in the BRAS category with **fewer than 5 pieces** are removed
- Only applies to products categorized under BRAS
- Example: BRAS product with 4 pieces → **REMOVED**

### **3. Background Processing**
- Jobs run asynchronously (non-blocking)
- Configurable batch sizes (1-200 products per batch)
- Full job tracking and monitoring
- Progress updates in real-time

---

## 📁 **Files Created/Modified**

### **New Files:**
1. **`includes/class-wholesaler-stock-cleanup.php`** - Main cleanup processor class
2. **`STOCK_CLEANUP_GUIDE.md`** - Complete usage documentation
3. **`examples/stock-cleanup-example.php`** - PHP usage examples
4. **`examples/test-stock-cleanup.sh`** - Bash testing script
5. **`IMPLEMENTATION_SUMMARY.md`** - This file

### **Modified Files:**
1. **`includes/class-wholesaler-brands-api.php`** - Updated endpoint implementation
2. **`wholesaler-integration.php`** - Registered new class

---

## 🚀 **Quick Start**

### **Method 1: Using cURL**
```bash
# Start cleanup job
curl -X POST "http://yoursite.com/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -H "Content-Type: application/json" \
  -d '{"batch_size": 50}'

# Returns:
# {
#   "success": true,
#   "message": "Stock cleanup job scheduled successfully",
#   "job_id": 1,
#   "scheduled": true
# }
```

### **Method 2: Using Test Script**
```bash
cd /srv/http/wholesaler/wp-content/plugins/wholesaler-integration/examples

# Interactive mode
./test-stock-cleanup.sh

# Or direct command
./test-stock-cleanup.sh start
./test-stock-cleanup.sh status 1
./test-stock-cleanup.sh monitor 1
./test-stock-cleanup.sh workflow
```

### **Method 3: Using WP-CLI**
```bash
wp eval-file examples/stock-cleanup-example.php
wp eval 'example_start_cleanup();'
wp eval 'example_complete_workflow();'
```

---

## 📊 **Monitoring**

### **Check Job Status**
```bash
curl "http://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-status/1"
```

**Response:**
```json
{
  "success": true,
  "job": {
    "id": 1,
    "status": "completed",
    "total_processed": 150,
    "total_removed": 23,
    "total_errors": 0,
    "removed_products": [
      {
        "id": 1234,
        "name": "Product Name",
        "reason": "Total stock (1) across all variations is 1 or less"
      }
    ],
    "started_at": "2025-10-07 10:30:00",
    "completed_at": "2025-10-07 10:32:15"
  }
}
```

### **List All Jobs**
```bash
curl "http://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-jobs?status=all&limit=10"
```

---

## 🔧 **Technical Details**

### **Database Table**
- **Table:** `wp_wholesaler_stock_cleanup_jobs`
- **Purpose:** Track job status, progress, and results
- **Auto-created:** Yes (on first use)

### **Job Statuses**
- `scheduled` - Job queued for processing
- `running` - Currently processing
- `completed` - Finished successfully
- `failed` - Error occurred

### **WordPress Hooks**
- **Action:** `wholesaler_stock_cleanup_process`
- **Schedule:** Single event (runs once when triggered)
- **Priority:** 10

---

## 📝 **API Endpoints**

### **1. Start Cleanup Job**
```
POST /wp-json/wholesaler/v1/remove-out-of-stock-products
```
**Parameters:**
- `batch_size` (optional, default: 50): Products per batch (1-200)

### **2. Get Job Status**
```
GET /wp-json/wholesaler/v1/stock-cleanup-status/{job_id}
```

### **3. List All Jobs**
```
GET /wp-json/wholesaler/v1/stock-cleanup-jobs
```
**Parameters:**
- `status` (optional, default: 'all'): Filter by status
- `limit` (optional, default: 20): Number of jobs to return

---

## 🎓 **Example Workflow**

```bash
# 1. Start the cleanup
curl -X POST "http://yoursite.com/wp-json/wholesaler/v1/remove-out-of-stock-products" \
  -d '{"batch_size": 100}'

# Response: {"success": true, "job_id": 1, ...}

# 2. Monitor progress (repeat every 5-10 seconds)
curl "http://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-status/1"

# 3. Once status is "completed", review results
# The response includes list of removed products with reasons

# 4. Check statistics
curl "http://yoursite.com/wp-json/wholesaler/v1/stock-cleanup-jobs"
```
