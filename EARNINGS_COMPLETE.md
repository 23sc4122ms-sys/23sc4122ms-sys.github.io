# ✅ Earnings Metrics - Complete Implementation

## Summary: What Each Metric Represents

### 1. **This Week** 💰
- **Definition**: Total earnings for the current week (7 days)
- **Formula**: `SUM(amount OR base_pay+bonus+tip+fee)`
- **Example**: $900 earned in the week
- **Database**: `deliveries.amount` or component sum

### 2. **Daily Average** 📊
- **Definition**: Average earnings per day
- **Formula**: `This Week ÷ 7 days`
- **Example**: $900 ÷ 7 = $128.57 per day
- **Use**: Shows work consistency

### 3. **Per Order** 🎯 ← MAIN METRIC FOR YOU
- **Definition**: Average earnings per delivery completed
- **Formula**: `Total Weekly Earnings ÷ Total Number of Orders`
- **Example**: $900 ÷ 30 orders = $30 per order
- **Use**: Shows earning efficiency per delivery
- **Calculation Code** (get_earnings.php line 74):
  ```php
  'per_order' => $total_orders ? round($week_total/$total_orders, 2) : 0
  ```

### 4. **Total Orders** 📦
- **Definition**: Number of deliveries completed
- **Formula**: `COUNT(*) from deliveries`
- **Example**: 30 orders delivered this week
- **Database**: `deliveries` row count

---

## Database Table Structure

### `deliveries` (Real-time tracking)
```sql
CREATE TABLE deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT,
  order_id INT,
  status VARCHAR(32),
  delivered_at DATETIME,         -- When delivery completed
  completed_at DATETIME,         -- When marked complete
  created_at TIMESTAMP,          -- When record created
  
  amount DECIMAL(10,2),          -- Total earnings for this delivery
  base_pay DECIMAL(10,2),        -- Base compensation
  bonus DECIMAL(10,2),           -- Performance bonus
  tip DECIMAL(10,2),             -- Customer tip
  fee DECIMAL(10,2),             -- Platform fee
  
  INDEX(rider_id),
  INDEX(status)
);
```

**Example Record**:
```
| id | rider_id | amount | base_pay | bonus | tip | delivered_at |
|----|----------|--------|----------|-------|-----|--------------|
| 1  | 5        | 35.00  | 20.00    | 5.00  | 10  | 2025-11-20   |
| 2  | 5        | 30.00  | 20.00    | 0.00  | 10  | 2025-11-20   |
| 3  | 5        | 28.00  | 20.00    | 8.00  | 0   | 2025-11-21   |
```

### `rider_weekly_earnings` (Weekly cache)
```sql
CREATE TABLE rider_weekly_earnings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT,
  week_start DATE,               -- Monday of week
  week_end DATE,                 -- Sunday of week
  total_amount DECIMAL(12,2),    -- This Week total
  total_orders INT,              -- Total Orders count
  daily_avg DECIMAL(10,2),       -- Daily Average
  per_order_avg DECIMAL(10,2),   -- Per Order average ← STORED HERE
  created_at TIMESTAMP,
  
  UNIQUE(rider_id, week_start)
);
```

### `riders_account` (Quick ledger)
```sql
CREATE TABLE riders_account (
  rider_id INT PRIMARY KEY,
  total_earned DECIMAL(12,2),    -- From rider_earnings table
  total_earnings DECIMAL(12,2),  -- Sum from deliveries ← NEW
  pending_amount DECIMAL(12,2),  -- Pending payouts
  available_amount DECIMAL(12,2),-- Available to cashout
  base_pay DECIMAL(10,2),        -- Weekly base (hourly calc)
  last_updated DATETIME
);
```

---

## Data Flow & Calculation

### Step 1: Rider Completes Deliveries
```
Delivery 1: $35 (base $20 + bonus $5 + tip $10)
Delivery 2: $30 (base $20 + tip $10)
Delivery 3: $28 (base $20 + bonus $8)
...
```

Records inserted into `deliveries` table with `delivered_at` timestamp.

### Step 2: Real-time Query (rider_earnings.php page load)
```php
// Query last 7 days of deliveries
$sql = "SELECT 
  DATE(COALESCE(delivered_at, completed_at, created_at)) as d,
  SUM(IFNULL(NULLIF(amount,0),(base_pay+bonus+tip+fee))) as total_amt,
  COUNT(*) as cnt
FROM deliveries
WHERE rider_id = ? AND DATE(...) IN (?, ?, ?, ?, ?, ?, ?)
GROUP BY DATE(...)";

// Calculate:
$this_week = SUM(total_amt)        // = $900
$total_orders = SUM(cnt)           // = 30
$per_order = $this_week / $total_orders  // = $30
$daily_avg = $this_week / 7        // = $128.57
```

### Step 3: JSON Response (get_earnings.php)
```json
{
  "labels": ["Mon", "Tue", ..., "Sun"],
  "total": [150, 120, 130, 110, 140, 160, 90],
  "summary": {
    "week_total": 900,
    "daily_avg": 128.57,
    "per_order": 30.00,        ← Calculated here
    "total_orders": 30
  }
}
```

### Step 4: UI Display (rider_earnings.php)
```html
<div class="summary-card">
  <small>Per Order</small>
  <div class="summary-amount" id="perOrder">$30.00</div>
</div>
```

### Step 5: Weekly Cache Update (backfill_rider_accounts.php)
```php
// Stores snapshot for next week
INSERT INTO rider_weekly_earnings 
  (rider_id, week_start, week_end, total_amount, daily_avg, per_order_avg, total_orders)
VALUES 
  (5, '2025-11-17', '2025-11-23', 900.00, 128.57, 30.00, 30);
```

---

## Query: Calculate All Metrics

**Get all 4 metrics for a rider for the past 7 days:**

```sql
SELECT 
  -- This Week
  IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) 
    as this_week,
  
  -- Total Orders  
  COUNT(d.id) as total_orders,
  
  -- Per Order (avg earnings per delivery)
  IFNULL(
    IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) 
    / NULLIF(COUNT(d.id), 0),
    0
  ) as per_order,
  
  -- Daily Average
  IFNULL(SUM(IFNULL(NULLIF(d.amount,0),(d.base_pay+d.bonus+d.tip+d.fee))), 0) / 7 
    as daily_average

FROM deliveries d
WHERE d.rider_id = 5
  AND DATE(COALESCE(d.delivered_at, d.completed_at, d.created_at))
    BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE();
```

**Result Example:**
```
| this_week | total_orders | per_order | daily_average |
|-----------|--------------|-----------|---------------|
| 900.00    | 30           | 30.00     | 128.57        |
```

---

## Implementation Verification ✅

| Component | File | Status | Details |
|-----------|------|--------|---------|
| **Per Order Formula** | `get_earnings.php:74` | ✅ | `$week_total / $total_orders` |
| **Daily Average Formula** | `get_earnings.php:65` | ✅ | `$week_total / 7` |
| **This Week Formula** | `get_earnings.php:43-57` | ✅ | SUM from deliveries |
| **Total Orders Formula** | `get_earnings.php:43-57` | ✅ | COUNT from deliveries |
| **deliveries Table** | `db.php:74-90` | ✅ | Has amount + components |
| **rider_weekly_earnings Table** | `db.php:146-157` | ✅ | Has per_order_avg |
| **riders_account Table** | `db.php:109-116` | ✅ | Has total_earnings |
| **Real-time UI** | `rider_earnings.php:135` | ✅ | Calculates all metrics |
| **JSON API** | `get_earnings.php:74` | ✅ | Returns per_order |
| **Weekly Cache** | `backfill_rider_accounts.php` | ✅ | Updates per_order_avg |

---

## Interpretation for Riders

### What Does $30/order Mean?

**If "Per Order" = $30:**
- On average, each delivery earns $30
- 10 deliveries = $300 expected
- 20 deliveries = $600 expected
- 25 deliveries = $750 expected

### Tracking Improvement

**Week 1**: Per Order = $25/order → 30 orders = $750
**Week 2**: Per Order = $28/order → 30 orders = $840 (+$90, +12% improvement)
**Week 3**: Per Order = $30/order → 30 orders = $900 (+$60, +7% improvement)

### Strategic Insights

1. **Higher Per Order = Better Earnings**
   - Pick better-paying routes
   - Reduce time per delivery (efficiency bonus)
   - Accept tips-friendly orders

2. **Consistency = Success**
   - Maintain $25-30/order range
   - Beat personal best each week
   - Target higher-value zones

3. **Volume vs. Value Trade-off**
   - High per-order: Fewer but better-paid deliveries
   - High orders: Many lower-value deliveries
   - Find balance: ~25 orders × $30-40/order = $750-1000/week

---

## Testing

To verify calculations are working:

1. **Login as a rider**
2. **Go to Earnings dashboard**
3. **Check summary cards**:
   - This Week: Should match SUM of amounts
   - Daily Average: Should be This Week ÷ 7
   - Per Order: Should be This Week ÷ Total Orders
   - Total Orders: Should match delivery count

4. **Example verification**:
   ```
   If This Week = $900
   If Total Orders = 30
   Then Per Order should = $30
   ```

---

## Files Summary

```
📁 Documentation
  📄 EARNINGS_METRICS_GUIDE.md          ← Detailed explanation
  📄 EARNINGS_IMPLEMENTATION.md         ← This file
  
📁 Code Files
  📄 get_earnings.php                   ← Returns per_order in JSON
  📄 get_weekly_earnings.php            ← Returns cached per_order
  📄 rider_earnings.php                 ← Displays per_order in UI
  📄 backfill_rider_accounts.php        ← Updates weekly cache
  
📁 Database
  📄 db.php                             ← Table schemas
```

---

## Status: ✅ COMPLETE

- ✅ Per Order metric properly defined
- ✅ Database tables set up and tracking data
- ✅ Calculation formulas correct
- ✅ UI displays accurate values
- ✅ Real-time and cached queries working
- ✅ Documentation complete
