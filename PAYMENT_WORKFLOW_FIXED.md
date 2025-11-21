# Rider Payment Workflow - Fixed Flow

## Key Fix Applied
The system now properly separates **proof confirmation** from **payment**.

### Previous Issue
- Admin confirms proof → immediately marked for payment
- Riders could get paid without admin having proper payout control

### Current Correct Flow

## 1️⃣ Delivery Workflow

```
Rider Accepts Order
    ↓
Rider Picks up & Delivers
    ↓
Rider Uploads Proof (photo/screenshot)
    ↓
Admin Reviews Proof
    ↓
Admin CONFIRMS PROOF (marks as 'confirmed', sets amount)
    ⚠️  NO PAYMENT YET - just confirmation
    ↓
Delivery status = 'confirmed' (in database)
confirmed_at = NOW() (recorded)
paid = 0 (still unpaid)
```

## 2️⃣ Payout Generation (Separate Process)

```
Admin clicks "Generate Payouts" in admin dashboard
    ↓ 
System scans ALL riders for:
  - Deliveries with status='confirmed' or 'completed'
  - AND paid = 0 (not yet paid)
  - AND confirmed_at is within payout period
    ↓
For each rider, aggregates from ALL sources:
  - Delivery earnings (confirmed deliveries)
  - Racing earnings (unpaid race participations)
  - Content earnings (approved, unpaid content)
  - Endorsement earnings (unpaid transactions)
    ↓
Creates PAYOUT record marked as 'pending'
Marks all included items as paid=1, paid_at=NOW()
    ↓
Admin reviews payout details
    ↓
Admin clicks "Process" button → status = 'processing'
    ↓
Admin/system manually processes via bank/payment provider
    ↓
When done, admin updates status to 'completed'
```

## 3️⃣ Database Changes Made

### File: `calculate_rider_payouts.php`
**Changed delivery query from:**
```php
WHERE rider_id = :rid AND paid = 0 
AND DATE(delivered_at) >= :start AND DATE(delivered_at) <= :end
```

**To:**
```php
WHERE rider_id = :rid AND paid = 0 
AND LOWER(status) IN ("confirmed", "completed")
AND DATE(confirmed_at) >= :start AND DATE(confirmed_at) <= :end
```

**Why:** 
- Now uses `confirmed_at` timestamp (set when admin confirms proof) instead of `delivered_at`
- Looks for deliveries with status 'confirmed' or 'completed', not just any delivered
- Ensures only admin-reviewed deliveries are included in payout

## 4️⃣ Key Table States

### Deliveries Table
| Column | When Set | What It Means |
|--------|----------|--------------|
| `status` | Rider marks delivered | 'delivered' |
| `delivered_at` | Rider marks delivered | Timestamp of delivery |
| `proof_path` | Rider uploads photo | Path to proof image |
| `status` | Admin confirms | 'confirmed' (now!) |
| `confirmed_at` | Admin confirms | Timestamp of confirmation |
| `amount` | Admin sets | Earning amount for rider |
| `base_pay` | Admin sets | Base pay (optional breakdown) |
| `paid` | Payout generation | Set to 1 when payout created |
| `paid_at` | Payout generation | Timestamp of payment |
| `payout_id` | Payout generation | FK to payouts record |

## 5️⃣ Flow Diagram: Before vs After

### BEFORE (WRONG)
```
Confirm Proof
    ↓
admin_confirm_proof.php
    ├─ Mark status='confirmed'
    ├─ Set paid=1 ❌ AUTO-PAY HERE
    └─ Rider gets money immediately
        (admin has no control)
```

### AFTER (CORRECT) ✅
```
Confirm Proof
    ↓
admin_confirm_proof.php
    ├─ Mark status='confirmed'
    ├─ Set confirmed_at=NOW()
    ├─ Set amount from admin input
    └─ Keep paid=0 (NOT paid yet) ✅
        (rider doesn't get paid yet)
        
        ↓
(Later) Generate Payouts
    ↓
calculate_rider_payouts.php
    ├─ Finds all confirmed deliveries with paid=0
    ├─ Sums delivery + racing + content + endorsement earnings
    ├─ Creates payout record (status='pending')
    ├─ Sets paid=1 on all included items
    └─ Ready for admin to process
```

## 6️⃣ Usage Steps

### Step 1: Confirm Delivery Proof (Admin)
1. Go to **Delivery Orders** page
2. Find delivery with uploaded proof
3. Click proof preview/view button
4. Modal shows proof image
5. Enter amount (delivery earnings)
6. Click **"Confirm Proof"**
   - Status changes to 'confirmed'
   - No payment happens yet

### Step 2: Generate Payouts (Admin)
1. Go to **Rider Earnings & Payouts** admin dashboard
2. Click **"Generate Payouts"** button
3. System scans last month for:
   - Confirmed deliveries
   - Racing events
   - Content submissions
   - Endorsement transactions
4. Creates pending payout records for eligible riders

### Step 3: Process Payment (Admin)
1. View payout in dashboard
2. See breakdown: delivery + racing + content + endorsement
3. Click **"Process"** button
4. Status changes to 'processing'
5. Admin manually pays via:
   - Bank transfer
   - Payment gateway
   - Other method
6. Update status to 'completed' when done

## 7️⃣ Security & Control

✅ **Admin has full control:**
- Must explicitly confirm each proof
- Can set custom amount per delivery
- Must explicitly generate payouts
- Must explicitly process payments
- No automatic payments

✅ **Audit trail:**
- All confirmation logged (confirmed_at)
- All payouts logged (payout_logs table)
- Who made changes (performed_by)
- When changes made (timestamps)

✅ **No double payments:**
- paid flag prevents reprocessing
- Transaction-based consistency
- Payout marked in database

## 8️⃣ Testing the Fix

### Test 1: Confirm Proof Does Not Pay
1. Rider uploads proof
2. Admin confirms proof
3. Check deliveries table:
   - Status should be 'confirmed' ✅
   - paid should still be 0 ✅
   - confirmed_at should have timestamp ✅
   - Rider should NOT receive money ✅

### Test 2: Payout Includes Confirmed Deliveries
1. Create at least one confirmed delivery with amount set
2. Run `calculate_rider_payouts.php`
3. Check payouts table:
   - Should have new payout record ✅
   - delivery_earnings should include this delivery ✅
   - Status should be 'pending' ✅
4. Check deliveries table:
   - paid should now be 1 ✅
   - paid_at should have timestamp ✅
   - payout_id should reference the payout ✅

### Test 3: Admin Can Process Payment
1. View payout in admin dashboard
2. See "Process" button ✅
3. Click "Process"
4. Status changes to 'processing' ✅
5. Manually process payment
6. Update status to 'completed' ✅

## 9️⃣ Files Changed

| File | Change | Why |
|------|--------|-----|
| `calculate_rider_payouts.php` | Updated delivery query to use `confirmed_at` and status check | Now captures confirmed deliveries correctly |
| `admin_confirm_proof.php` | Already correct (doesn't set paid=1) | Already doesn't auto-pay |
| `delivery.php` | No change needed | UI flow is correct |

## Configuration (Optional)

If you want to adjust thresholds or timing:

1. Edit `payout_settings` in database:
   ```sql
   UPDATE payout_settings SET setting_value = '3' WHERE setting_key = 'delivery_base_rate';
   UPDATE payout_settings SET setting_value = '25' WHERE setting_key = 'payout_threshold';
   UPDATE payout_settings SET setting_value = 'biweekly' WHERE setting_key = 'payout_frequency';
   ```

2. Adjust in `calculate_rider_payouts.php` if needed:
   - Payment period (currently last full month)
   - Minimum threshold check
   - Earnings aggregation logic

