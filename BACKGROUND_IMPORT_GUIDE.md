# Background Import System - Enhanced Guide

## 🚀 **Major Improvements Implemented**

### **1. Enhanced /background-import Endpoint**

#### **New Parameters:**
- ✅ `products_per_batch` (default: 50): How many products per batch
- ✅ `total_batches` (default: 1): Number of batches to process  
- ✅ `process_images` (default: true): Auto-process images after import
- ✅ `update_existing` (default: true): Update existing products or skip them

#### **Usage Examples:**

**Basic Background Import:**
```bash
curl -X POST "https://yoursite.com/wp-json/wholesaler/v1/background-import" \
  -H "Content-Type: application/json" \
  -d '{
    "total_batches": 5,
    "products_per_batch": 100,
    "process_images": true,
    "update_existing": true
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Background import scheduled for 5 batches with 100 products each",
  "job_id": 123,
  "total_products_estimated": 500,
  "process_images": true,
  "update_existing": true
}
```

### **2. Fixed Variations Update Issue**

#### **Problem Solved:**
- ✅ **Enhanced variation handling** - properly updates existing variations
- ✅ **Improved SKU matching** - better variation identification  
- ✅ **Batch variation operations** - faster processing
- ✅ **Fallback mechanisms** - individual creation if batch fails

#### **Technical Improvements:**
- **Enhanced caching** for existing variations lookup
- **Proper update/create separation** for variations
- **Better error handling** with fallback to individual operations
- **Chunk processing** for large variation sets (25 variations per chunk)

### **3. Comprehensive Background Job Tracking**

#### **New Database Table:** `wp_wholesaler_background_jobs`
```sql
CREATE TABLE wp_wholesaler_background_jobs (
    id bigint(20) AUTO_INCREMENT PRIMARY KEY,
    job_data longtext NOT NULL,
    status varchar(20) DEFAULT 'scheduled',
    progress int(11) DEFAULT 0,
    total_processed int(11) DEFAULT 0,
    total_created int(11) DEFAULT 0,
    total_updated int(11) DEFAULT 0,
    total_errors int(11) DEFAULT 0,
    error_messages longtext NULL,
    started_at datetime NULL,
    completed_at datetime NULL,
    created_at datetime DEFAULT CURRENT_TIMESTAMP
);
```

#### **Job Status Monitoring:**
```bash
# Check specific job status
curl "https://yoursite.com/wp-json/wholesaler/v1/background-status/123"
```

**Response:**
```json
{
  "success": true,
  "job": {
    "id": 123,
    "status": "running",
    "progress": 60,
    "total_processed": 300,
    "total_created": 180,
    "total_updated": 120,
    "total_errors": 5,
    "error_messages": ["Product SKU123: API timeout", "..."],
    "started_at": "2024-01-15 10:30:00",
    "completed_at": null,
    "estimated_total": 500,
    "configuration": {
      "total_batches": 5,
      "products_per_batch": 100,
      "process_images": true,
      "update_existing": true
    }
  }
}
```

#### **All Jobs Overview:**
```bash
# Get all background jobs
curl "https://yoursite.com/wp-json/wholesaler/v1/background-jobs"

# Filter by status
curl "https://yoursite.com/wp-json/wholesaler/v1/background-jobs?status=running&limit=10"
```

**Response:**
```json
{
  "success": true,
  "jobs": [
    {
      "id": 123,
      "status": "completed",
      "progress": 100,
      "total_processed": 500,
      "total_created": 300,
      "total_updated": 200,
      "total_errors": 0,
      "duration": 1800,
      "started_at": "2024-01-15 10:30:00",
      "completed_at": "2024-01-15 11:00:00"
    }
  ],
  "stats": {
    "total_jobs": 25,
    "scheduled": 2,
    "running": 1,
    "completed": 20,
    "failed": 2,
    "total_products_processed": 12500,
    "total_products_created": 8000,
    "total_products_updated": 4500
  }
}
```

### **4. Automatic Image Processing Integration**

#### **How It Works:**
1. **Product Import** - Products created/updated without images (fast)
2. **Image Collection** - System collects all products with images
3. **Auto-Scheduling** - Images automatically scheduled for background processing
4. **Background Processing** - Images processed without blocking main import

#### **Both Endpoints Enhanced:**

**`/batch-import` - Now Auto-Processes Images:**
```bash
curl -X POST "/wp-json/wholesaler/v1/batch-import" \
  -d '{"batch_size": 50, "performance_mode": true}'
```

**Response:**
```json
{
  "success": true,
  "message": "Batch import completed. Created: 30, Updated: 20, Images scheduled: 45",
  "created": 30,
  "updated": 20,
  "images_scheduled": 45,
  "total_processed": 50
}
```

**`/background-import` - Integrated Image Processing:**
```bash
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 3,
    "products_per_batch": 50,
    "process_images": true
  }'
```

## 📊 **Performance Improvements**

### **Variation Updates Fixed:**
- **Before**: Variations not updating on second import
- **After**: ✅ Proper variation updates with enhanced SKU matching

### **Background Processing:**
- **Before**: Fixed batch size, no tracking
- **After**: ✅ Configurable batches, full tracking, progress monitoring

### **Image Processing:**
- **Before**: Manual `/process-images` calls required
- **After**: ✅ Automatic scheduling, integrated workflow

### **Error Handling:**
- **Before**: Limited error information
- **After**: ✅ Detailed error tracking, fallback mechanisms

## 🛠️ **Usage Scenarios**

### **Small Import (< 500 products):**
```bash
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 1,
    "products_per_batch": 50,
    "process_images": true
  }'
```

### **Medium Import (500-2000 products):**
```bash
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 4,
    "products_per_batch": 100,
    "process_images": true,
    "update_existing": true
  }'
```

### **Large Import (2000+ products):**
```bash
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 10,
    "products_per_batch": 100,
    "process_images": true,
    "update_existing": true
  }'
```

### **Update Only (Skip New Products):**
```bash
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 5,
    "products_per_batch": 75,
    "process_images": false,
    "update_existing": true
  }'
```

## 🔍 **Monitoring & Debugging**

### **Real-time Progress Monitoring:**
```bash
# Start background job
RESPONSE=$(curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{"total_batches": 5, "products_per_batch": 100}')

JOB_ID=$(echo $RESPONSE | jq -r '.job_id')

# Monitor progress
while true; do
  STATUS=$(curl -s "/wp-json/wholesaler/v1/background-status/$JOB_ID" | jq -r '.job.status')
  PROGRESS=$(curl -s "/wp-json/wholesaler/v1/background-status/$JOB_ID" | jq -r '.job.progress')
  
  echo "Job $JOB_ID: $STATUS ($PROGRESS%)"
  
  if [ "$STATUS" = "completed" ] || [ "$STATUS" = "failed" ]; then
    break
  fi
  
  sleep 10
done
```

### **Error Analysis:**
```bash
# Get job with errors
curl "/wp-json/wholesaler/v1/background-status/123" | jq '.job.error_messages'

# Get failed jobs
curl "/wp-json/wholesaler/v1/background-jobs?status=failed" | jq '.jobs[].error_messages'
```

### **Performance Analysis:**
```bash
# Get completed jobs with timing
curl "/wp-json/wholesaler/v1/background-jobs?status=completed" | \
  jq '.jobs[] | {id, duration, total_processed, rate: (.total_processed / (.duration / 60))}'
```

## 🎯 **Key Benefits**

### **1. Proper Variation Handling**
- ✅ **Fixed update issues** - variations now update correctly on subsequent imports
- ✅ **Enhanced SKU matching** - better identification of existing variations
- ✅ **Batch operations** - faster variation processing

### **2. Comprehensive Tracking**
- ✅ **Real-time progress** - see exactly what's happening
- ✅ **Detailed statistics** - created, updated, errors with counts
- ✅ **Error logging** - specific error messages for debugging
- ✅ **Performance metrics** - duration, processing rates

### **3. Automatic Image Processing**
- ✅ **No manual calls** - images processed automatically
- ✅ **Non-blocking** - doesn't slow down product import
- ✅ **Intelligent scheduling** - spreads image processing over time

### **4. Flexible Configuration**
- ✅ **Configurable batch sizes** - optimize for your server
- ✅ **Selective processing** - choose what to update
- ✅ **Image control** - enable/disable image processing

## 🚨 **Migration Notes**

### **Old Background Import:**
```bash
# Old way (limited functionality)
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{"total_batches": 10}'
```

### **New Enhanced Background Import:**
```bash
# New way (full control)
curl -X POST "/wp-json/wholesaler/v1/background-import" \
  -d '{
    "total_batches": 10,
    "products_per_batch": 50,
    "process_images": true,
    "update_existing": true
  }'
```

### **Monitoring Migration:**
```bash
# Old way (no monitoring)
# Had to guess when job was done

# New way (full monitoring)
curl "/wp-json/wholesaler/v1/background-status/123"
curl "/wp-json/wholesaler/v1/background-jobs"
```

## 🔧 **Troubleshooting**

### **Variations Not Updating:**
- ✅ **Fixed** - Enhanced variation matching and update logic
- ✅ **Caching** - Better performance with variation lookups
- ✅ **Fallbacks** - Individual creation if batch fails

### **No Progress Visibility:**
- ✅ **Fixed** - Real-time progress tracking
- ✅ **Detailed stats** - See exactly what's processed
- ✅ **Error tracking** - Know what failed and why

### **Manual Image Processing:**
- ✅ **Fixed** - Automatic image scheduling
- ✅ **Integrated** - Works with both batch-import and background-import
- ✅ **Intelligent** - Only processes products with images

The enhanced system now provides **complete control**, **full visibility**, and **automatic optimization** for your product import workflow!
