# Rider Earnings System Updates

## Summary of Changes

### 1. Removed Duplicate AJAX Calls
- **File**: `rider_earnings.php`
- **Change**: Consolidated `fetchWeeklyStats()` and `fetchPayoutSummary()` into a single flow to eliminate redundant API calls
- **Benefit**: Reduces server load and improves page responsiveness

### 2. Added Database Columns to `riders_account` Table
- **File**: `db.php`
- **Columns Added**:
  - `base_pay DECIMAL(10,2)` - Hourly wage × hours_per_week × weeks_per_year
  - `total_earnings DECIMAL(12,2)` - Sum of all earnings from deliveries table
- **Benefit**: Centralized storage for rider financial metrics

### 3. Updated Summary Card Calculation
- **Files Modified**:
  - `get_weekly_earnings.php` - Now returns `base_pay` and `total_earnings` alongside weekly stats
  - `backfill_rider_accounts.php` - Now populates both new columns during backfill
- **Result**: This Week, Daily Average, Per Order cards now pull from database instead of just display values

## How to Use

### Step 1: Create/Update Database Columns
Run the migration script via phpMyAdmin or MySQL CLI:
```sql
-- Use update_rider_earnings_columns.sql file
```

Or simply visit your application - the columns will be created automatically on next `getPDO()` call due to `ALTER TABLE IF NOT EXISTS` statements in `db.php`.

### Step 2: Populate `base_pay` Values
Visit: `/update_rider_base_pay.php` (Admin only)

This admin panel allows you to:
- Set hourly wage (default: $20/hr)
- Set hours per week (default: 40)
- Set weeks per year (default: 52)
- Calculate formula: (hourly_wage × hours_per_week × weeks_per_year) ÷ 52 = weekly base pay
- Apply to all riders at once

### Step 3: Populate `total_earnings` Values
Visit: `/backfill_rider_accounts.php` (Admin only)

This runs the full backfill and:
- Calculates total earnings from deliveries table (using SUM of amount or component totals)
- Updates `total_earnings` column for all riders
- Also updates pending/available balances

## Database Schema

```sql
CREATE TABLE rider_accounts (
    rider_id INT PRIMARY KEY,
    total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,           -- From rider_earnings table
    pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,         -- Pending payouts
    available_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,       -- Available to cashout
    base_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00,                -- NEW: Hourly wage calculation
    total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00,         -- NEW: Sum from deliveries
    last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## API Endpoints

### `/get_weekly_earnings.php` (Rider)
**Response now includes**:
```json
{
  "ok": true,
  "total": "500.00",
  "daily_avg": "71.43",
  "per_order": "12.50",
  "total_orders": 40,
  "base_pay": "800.00",           // NEW
  "total_earnings": "5200.00"     // NEW
}
```

### `/backfill_rider_accounts.php` (Admin)
Automatically populates:
- `base_pay`: 0.00 (requires manual setup via `/update_rider_base_pay.php`)
- `total_earnings`: Calculated from deliveries table SUM
- `available_amount`: total_earned - pending_amount

## Example Usage

### For a Rider ($20/hr, 40 hrs/week, 52 weeks/year):
```
base_pay = ($20 × 40 × 52) ÷ 52 = $800/week
```

### For Total Earnings:
```
total_earnings = SUM(
  CASE WHEN amount > 0 THEN amount 
       ELSE (base_pay + bonus + tip + fee) 
  END
) FROM deliveries WHERE rider_id = :id
```

## Files Modified
1. ✅ `db.php` - Added column definitions and migrations
2. ✅ `rider_earnings.php` - Removed duplicate AJAX calls
3. ✅ `get_weekly_earnings.php` - Returns new columns
4. ✅ `backfill_rider_accounts.php` - Populates new columns
5. ✨ `update_rider_base_pay.php` - NEW admin tool for setting base_pay
6. ✨ `update_rider_earnings_columns.sql` - NEW SQL migration file

## Next Steps (Optional)

If you want more advanced hourly rate management, create a `rider_profiles` table:

```sql
CREATE TABLE rider_profiles (
    rider_id INT PRIMARY KEY,
    hourly_rate DECIMAL(10,2) DEFAULT 20.00,
    hours_per_week INT DEFAULT 40,
    weeks_per_year INT DEFAULT 52,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
);
```

Then update base_pay dynamically:
```sql
UPDATE rider_accounts ra
SET base_pay = (
    SELECT (rp.hourly_rate * rp.hours_per_week * rp.weeks_per_year) / 52
    FROM rider_profiles rp
    WHERE rp.rider_id = ra.rider_id
)
WHERE EXISTS (
    SELECT 1 FROM rider_profiles WHERE rider_id = ra.rider_id
);
```
