# Daily Earnings Trend - Field Definitions

## Overview
The Daily Earnings Trend section in the rider earnings dashboard displays four key metrics:

---

## 1. **This Week** 📊
### Definition
Total earnings accumulated during the current week (Monday through Sunday or last 7 days).

### Formula
```
This Week = SUM(earnings from all deliveries in the week)
         = SUM(CASE WHEN amount > 0 THEN amount ELSE base_pay+bonus+tip+fee END)
         FROM deliveries WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Database Source
- **Primary**: `deliveries` table → `amount` column (or sum of components)
- **Fallback**: `rider_weekly_earnings` table → `total_amount` column
- **Cached in**: `riders_account` table → `total_earned` column

### Example
```
Mon: $150  (5 orders)
Tue: $120  (4 orders)
Wed: $130  (5 orders)
Thu: $110  (3 orders)
Fri: $140  (5 orders)
Sat: $160  (6 orders)
Sun: $90   (2 orders)
─────────────────
Total: $900
```

---

## 2. **Daily Average** 📈
### Definition
Average earnings per day over the selected period (typically 7 days).

### Formula
```
Daily Average = This Week Total / Number of Days
             = $900 / 7 days
             = $128.57/day
```

### Database Query
```sql
SELECT 
  IFNULL(SUM(...), 0) / 7 as daily_avg
FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Use Case
Shows consistency of earnings. A rider earning $900 in a week but only working 2 days would show high daily average ($450/day), while spread across 7 days shows ($128.57/day).

### Example
```
If earnings are $900 over 7 days:
  Daily Average = $900 ÷ 7 = $128.57

If earnings are $900 over 5 days (2 days off):
  Daily Average = $900 ÷ 5 = $180.00 (higher, shows concentration)
```

---

## 3. **Per Order** 🎯
### Definition
**Average earnings per delivery/order completed.**

This is the key metric showing how much a rider earns on average for each delivery they complete.

### Formula
```
Per Order = Total Weekly Earnings / Total Number of Orders
         = $900 / 30 orders
         = $30/order
```

### Database Query
```sql
SELECT 
  IFNULL(SUM(IFNULL(NULLIF(amount,0),(base_pay+bonus+tip+fee))), 0) as total_earnings,
  COUNT(*) as total_orders,
  (total_earnings / NULLIF(total_orders, 0)) as per_order_avg
FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
GROUP BY rider_id
```

### Stored In
- **Real-time**: Calculated from `deliveries` table
- **Weekly snapshot**: `rider_weekly_earnings.per_order_avg` column
- **Quick access**: `riders_account.total_earnings` ÷ order count

### Use Case
This helps riders understand:
1. **Earning efficiency**: Are they completing high-value orders?
2. **Performance**: Is their per-order earning increasing over time?
3. **Optimization**: Should they focus on higher-paying deliveries?
4. **Comparison**: How does it compare to previous weeks?

### Example Scenarios

**Scenario A: High-value orders**
```
Orders: 20 deliveries
Total: $800
Per Order: $800 ÷ 20 = $40/order ✓ Good
```

**Scenario B: Low-value orders**
```
Orders: 50 deliveries
Total: $600
Per Order: $600 ÷ 50 = $12/order ✗ Poor
```

**Scenario C: Best case**
```
Orders: 15 deliveries
Total: $900
Per Order: $900 ÷ 15 = $60/order ✓✓ Excellent
```

---

## 4. **Total Orders** 📦
### Definition
Total number of deliveries/orders completed in the period.

### Formula
```
Total Orders = COUNT(distinct delivery records)
            = COUNT(*) FROM deliveries WHERE date IN (week_start TO week_end)
```

### Database Source
```sql
SELECT COUNT(*) as total_orders
FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

---

## Database Tables Used

### 1. `deliveries` Table (Real-time)
```sql
- id: INT
- rider_id: INT (FK to users)
- order_id: INT
- amount: DECIMAL(10,2)      -- Total earnings for this delivery
- base_pay: DECIMAL(10,2)    -- Base compensation
- bonus: DECIMAL(10,2)       -- Performance bonus
- tip: DECIMAL(10,2)         -- Customer tip
- fee: DECIMAL(10,2)         -- Platform fee
- delivered_at: DATETIME     -- When delivery completed
- created_at: TIMESTAMP
```

**Calculation Priority**:
```
use_amount = CASE 
  WHEN amount > 0 THEN amount
  ELSE base_pay + bonus + tip + fee
END
```

---

### 2. `rider_weekly_earnings` Table (Weekly Cache)
```sql
- id: INT
- rider_id: INT
- week_start: DATE           -- Monday of week
- week_end: DATE             -- Sunday of week
- total_amount: DECIMAL      -- This Week total
- total_orders: INT          -- Total Orders count
- daily_avg: DECIMAL         -- Daily Average
- per_order_avg: DECIMAL     -- Per Order average
- created_at: TIMESTAMP
```

**Purpose**: Cache weekly statistics for faster queries

---

### 3. `riders_account` Table (Quick Ledger)
```sql
- rider_id: INT (PK)
- total_earned: DECIMAL      -- Cumulative earnings
- pending_amount: DECIMAL    -- Pending payouts
- available_amount: DECIMAL  -- Ready to cashout
- base_pay: DECIMAL          -- Weekly base pay (formula: hourly × hours × weeks ÷ 52)
- total_earnings: DECIMAL    -- NEW: Sum of all earnings
- last_updated: DATETIME
```

**Purpose**: Quick access for balance/cashout calculations

---

## Calculation Flow

### On Initial Page Load (rider_earnings.php)
1. Query last 7 days from `deliveries`
2. Calculate totals, average, per_order
3. Display immediately in summary cards

### On Chart Refresh (get_earnings.php via AJAX)
1. Fetch `range` parameter (7, 14, or 30 days)
2. Query `deliveries` table for date range
3. Group by day and compute totals
4. Return JSON with labels and datasets

### Weekly Backfill (backfill_rider_accounts.php)
1. Calculate totals from `deliveries`
2. Insert/update `rider_weekly_earnings` row
3. Update `riders_account` total_earnings
4. Create audit log

---

## Example Query: Get All Four Metrics

```sql
SELECT 
  -- This Week
  IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) as this_week,
  
  -- Daily Average (assume 7 days)
  (IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) / 7) as daily_average,
  
  -- Per Order
  (IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) / NULLIF(COUNT(d.id), 0)) as per_order,
  
  -- Total Orders
  COUNT(d.id) as total_orders
  
FROM deliveries d
WHERE d.rider_id = ? 
  AND DATE(COALESCE(d.delivered_at, d.completed_at, d.created_at)) 
    BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
```

---

## Performance Notes

- **Real-time**: `get_earnings.php` queries `deliveries` every time
- **Cached**: `rider_weekly_earnings` updated weekly via admin backfill
- **Quick access**: `riders_account` updated during payout requests
- **Fallback**: If no `delivered_at`, uses `completed_at` or `created_at`

---

## Rider Interpretation Guide

| Metric | What It Shows | Why It Matters |
|--------|-------------|----------------|
| **This Week** | Total money earned | Overall weekly income |
| **Daily Average** | Consistent earnings per day | Work pattern & availability |
| **Per Order** | Income per delivery | Order quality & efficiency |
| **Total Orders** | Work volume | Effort level & capacity |

**Ideal Scenario**:
- This Week: $900 (good weekly income)
- Daily Average: $180 (working 5 days/week)
- Per Order: $40 (good compensation per delivery)
- Total Orders: 22-25 (reasonable workload)
