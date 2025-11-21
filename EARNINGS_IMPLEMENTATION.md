# Earnings Metrics Implementation Summary

## What "Per Order" Means

**Per Order = Average Earnings per Delivery Completed**

```
Per Order = Total Weekly Earnings / Total Number of Deliveries
         = $900 / 30 deliveries
         = $30 per delivery
```

It shows how much a rider earns on average for each order they complete.

---

## Quick Reference

| Field | Formula | Data Source |
|-------|---------|------------|
| **This Week** | SUM(earnings) for last 7 days | `deliveries` table |
| **Daily Average** | This Week ÷ 7 | Calculated from `deliveries` |
| **Per Order** | This Week ÷ Total Orders | `deliveries` COUNT / SUM |
| **Total Orders** | COUNT(*) for last 7 days | `deliveries` table |

---

## Database Tracking

### Primary Table: `deliveries`
Stores every delivery with earnings breakdown:
```
rider_id, amount, base_pay, bonus, tip, fee, delivered_at
```

Real-time queries use this table to calculate metrics.

### Cache Table: `rider_weekly_earnings`
Weekly snapshots for faster access:
```
rider_id, week_start, week_end, total_amount, daily_avg, per_order_avg, total_orders
```

Updated weekly via `/backfill_rider_accounts.php`

### Quick Access Table: `riders_account`
Fast ledger for balance/cashout:
```
rider_id, total_earned, total_earnings, base_pay, pending_amount, available_amount
```

Updated during payout requests and backfill.

---

## Current Implementation Status ✅

| Component | Status | File |
|-----------|--------|------|
| **Calculation** | ✅ Correct | `get_earnings.php` |
| **Per Order Formula** | ✅ Total ÷ Count | `rider_earnings.php` line 135 |
| **Database Schema** | ✅ Complete | `db.php` |
| **Weekly Cache** | ✅ Implemented | `rider_weekly_earnings` table |
| **Quick Ledger** | ✅ Implemented | `riders_account` table |
| **Real-time Query** | ✅ Optimized | COALESCE dates for all deliveries |

---

## Example Data Flow

### Step 1: Rider Completes Deliveries
```
Day 1: 3 orders @ $25, $30, $35 = $90
Day 2: 4 orders @ $28, $32, $24, $26 = $110
...
Week Total: 30 orders, $900 earned
```

### Step 2: Real-time Summary (rider_earnings.php page load)
```php
This Week: $900         (sum of amount column)
Daily Average: $128.57  ($900 ÷ 7 days)
Per Order: $30.00       ($900 ÷ 30 orders)
Total Orders: 30
```

### Step 3: Weekly Cache (backfill_rider_accounts.php)
Stores snapshot in `rider_weekly_earnings`:
```sql
- total_amount: 900.00
- daily_avg: 128.57
- per_order_avg: 30.00
- total_orders: 30
```

### Step 4: Quick Access (for cashout)
Updates `riders_account.total_earnings = 900.00`

---

## Rider Impact

### How "Per Order" Helps Riders

1. **Performance Tracking**
   - See if they're picking high-value vs low-value orders
   - Last week: $25/order → This week: $32/order = improvement ✓

2. **Goal Setting**
   - "I want to earn $40/order" 
   - Motivates them to be selective or improve efficiency

3. **Income Prediction**
   - If per order is $30 and they deliver 25/week
   - Expected income: $750/week

4. **Comparison**
   - Compare their per order rate week-to-week
   - Identify trends (improving or declining?)

---

## Technical Details

### Calculation Priority (from deliveries)
```
1. If amount > 0:        use amount
2. Else:                 sum (base_pay + bonus + tip + fee)
3. Else:                 0
```

This ensures we capture earnings even if `amount` column is empty.

### Date Handling
Uses COALESCE to get date from:
1. `delivered_at` (preferred - when actually delivered)
2. `completed_at` (fallback - when marked complete)
3. `created_at` (fallback - when record created)

This ensures all deliveries are counted even if timestamps are incomplete.

### Aggregation
```sql
GROUP BY DATE(COALESCE(delivered_at, completed_at, created_at))
```

Groups by day so chart shows daily trends.

---

## Testing

### Quick Test Query
```sql
-- Get this week's metrics for rider_id 5
SELECT 
  SUM(IFNULL(NULLIF(amount,0),(base_pay+bonus+tip+fee))) as this_week,
  COUNT(*) as total_orders,
  SUM(IFNULL(NULLIF(amount,0),(base_pay+bonus+tip+fee))) / COUNT(*) as per_order,
  SUM(IFNULL(NULLIF(amount,0),(base_pay+bonus+tip+fee))) / 7 as daily_avg
FROM deliveries
WHERE rider_id = 5 
  AND DATE(COALESCE(delivered_at, completed_at, created_at)) 
    BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE();
```

---

## Files Reference

| File | Purpose |
|------|---------|
| `EARNINGS_METRICS_GUIDE.md` | Detailed explanation of each metric |
| `get_earnings.php` | Returns JSON with all metrics |
| `get_weekly_earnings.php` | Returns cached weekly summary |
| `backfill_rider_accounts.php` | Updates weekly cache |
| `rider_earnings.php` | Displays metrics in UI |
| `db.php` | Database schema definitions |

---

## Summary

- ✅ **Per Order** = Total Earnings ÷ Number of Deliveries
- ✅ Tracked in `deliveries` table (real-time)
- ✅ Cached in `rider_weekly_earnings` table (weekly)
- ✅ Quick access via `riders_account` table
- ✅ Calculated correctly in all endpoints
- ✅ Used for rider performance and income prediction
