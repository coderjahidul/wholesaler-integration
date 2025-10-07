#!/bin/bash

###############################################################################
# Stock Cleanup Testing Script
# 
# This script helps you test the stock cleanup functionality
###############################################################################

# Configuration
SITE_URL="http://localhost/wholesaler/wp-json/wholesaler/v1"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

###############################################################################
# Functions
###############################################################################

print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

###############################################################################
# Test 1: Start Stock Cleanup Job
###############################################################################
test_start_cleanup() {
    print_header "Test 1: Starting Stock Cleanup Job"
    
    print_info "Sending POST request to start cleanup..."
    
    RESPONSE=$(curl -s -X POST "${SITE_URL}/remove-out-of-stock-products" \
        -H "Content-Type: application/json" \
        -d '{"batch_size": 50}')
    
    echo "Response: $RESPONSE"
    
    # Extract job_id using grep and sed (works without jq)
    JOB_ID=$(echo "$RESPONSE" | grep -o '"job_id":[0-9]*' | sed 's/"job_id"://')
    
    if [ -n "$JOB_ID" ]; then
        print_success "Job started successfully! Job ID: $JOB_ID"
        echo "$JOB_ID" > /tmp/last_cleanup_job_id.txt
        return 0
    else
        print_error "Failed to start job"
        return 1
    fi
}

###############################################################################
# Test 2: Check Job Status
###############################################################################
test_check_status() {
    print_header "Test 2: Checking Job Status"
    
    if [ -f /tmp/last_cleanup_job_id.txt ]; then
        JOB_ID=$(cat /tmp/last_cleanup_job_id.txt)
    else
        JOB_ID=${1:-1}
    fi
    
    print_info "Checking status of job #$JOB_ID..."
    
    RESPONSE=$(curl -s "${SITE_URL}/stock-cleanup-status/${JOB_ID}")
    
    echo "Response:"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    
    STATUS=$(echo "$RESPONSE" | grep -o '"status":"[^"]*"' | head -1 | sed 's/"status":"\([^"]*\)"/\1/')
    
    if [ -n "$STATUS" ]; then
        print_success "Current status: $STATUS"
        return 0
    else
        print_error "Failed to get status"
        return 1
    fi
}

###############################################################################
# Test 3: List All Jobs
###############################################################################
test_list_jobs() {
    print_header "Test 3: Listing All Jobs"
    
    print_info "Getting list of all cleanup jobs..."
    
    RESPONSE=$(curl -s "${SITE_URL}/stock-cleanup-jobs?limit=10")
    
    echo "Response:"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    
    TOTAL=$(echo "$RESPONSE" | grep -o '"total_jobs":[0-9]*' | sed 's/"total_jobs"://')
    
    if [ -n "$TOTAL" ]; then
        print_success "Found $TOTAL total jobs"
        return 0
    else
        print_error "Failed to get jobs list"
        return 1
    fi
}

###############################################################################
# Test 4: Monitor Job Until Completion
###############################################################################
test_monitor_job() {
    print_header "Test 4: Monitoring Job Until Completion"
    
    if [ -f /tmp/last_cleanup_job_id.txt ]; then
        JOB_ID=$(cat /tmp/last_cleanup_job_id.txt)
    else
        JOB_ID=${1:-1}
    fi
    
    print_info "Monitoring job #$JOB_ID..."
    
    MAX_ATTEMPTS=60
    ATTEMPT=0
    
    while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
        RESPONSE=$(curl -s "${SITE_URL}/stock-cleanup-status/${JOB_ID}")
        STATUS=$(echo "$RESPONSE" | grep -o '"status":"[^"]*"' | head -1 | sed 's/"status":"\([^"]*\)"/\1/')
        PROCESSED=$(echo "$RESPONSE" | grep -o '"total_processed":[0-9]*' | sed 's/"total_processed"://')
        REMOVED=$(echo "$RESPONSE" | grep -o '"total_removed":[0-9]*' | sed 's/"total_removed"://')
        
        echo -ne "\r[$STATUS] Processed: $PROCESSED | Removed: $REMOVED"
        
        if [ "$STATUS" = "completed" ]; then
            echo ""
            print_success "Job completed successfully!"
            print_info "Total removed: $REMOVED products"
            return 0
        fi
        
        if [ "$STATUS" = "failed" ]; then
            echo ""
            print_error "Job failed!"
            return 1
        fi
        
        ATTEMPT=$((ATTEMPT + 1))
        sleep 5
    done
    
    echo ""
    print_error "Monitoring timeout reached"
    return 1
}

###############################################################################
# Test 5: Complete Workflow
###############################################################################
test_complete_workflow() {
    print_header "Test 5: Complete Workflow"
    
    # Step 1: Start cleanup
    print_info "Step 1: Starting cleanup job..."
    test_start_cleanup
    
    if [ $? -ne 0 ]; then
        print_error "Workflow failed at step 1"
        return 1
    fi
    
    sleep 2
    
    # Step 2: Monitor progress
    print_info "Step 2: Monitoring job progress..."
    test_monitor_job
    
    if [ $? -ne 0 ]; then
        print_error "Workflow failed at step 2"
        return 1
    fi
    
    # Step 3: Get final results
    print_info "Step 3: Getting final results..."
    test_check_status
    
    print_success "Workflow completed successfully!"
    return 0
}

###############################################################################
# Main Menu
###############################################################################
show_menu() {
    echo -e "\n${BLUE}╔════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║   Stock Cleanup Testing Menu          ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════╝${NC}\n"
    echo "1) Start Cleanup Job"
    echo "2) Check Job Status"
    echo "3) List All Jobs"
    echo "4) Monitor Job Until Completion"
    echo "5) Run Complete Workflow"
    echo "6) Configure Site URL"
    echo "0) Exit"
    echo ""
    read -p "Select option: " choice
    
    case $choice in
        1) test_start_cleanup ;;
        2) test_check_status ;;
        3) test_list_jobs ;;
        4) test_monitor_job ;;
        5) test_complete_workflow ;;
        6) 
            read -p "Enter site URL (e.g., https://yoursite.com/wp-json/wholesaler/v1): " SITE_URL
            print_success "Site URL updated to: $SITE_URL"
            ;;
        0) 
            print_info "Goodbye!"
            exit 0
            ;;
        *) 
            print_error "Invalid option"
            ;;
    esac
    
    read -p "Press Enter to continue..."
}

###############################################################################
# Entry Point
###############################################################################

# Check if running with arguments
if [ $# -gt 0 ]; then
    case "$1" in
        start)
            test_start_cleanup
            ;;
        status)
            test_check_status "$2"
            ;;
        list)
            test_list_jobs
            ;;
        monitor)
            test_monitor_job "$2"
            ;;
        workflow)
            test_complete_workflow
            ;;
        *)
            echo "Usage: $0 {start|status [job_id]|list|monitor [job_id]|workflow}"
            exit 1
            ;;
    esac
else
    # Interactive menu
    while true; do
        show_menu
    done
fi

