# 📊 Daily Earnings Trend - Quick Reference

## The 4 Metrics Explained

```
┌─────────────────────────────────────────────────────────────────┐
│                   EARNINGS DASHBOARD                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  This Week: $900           Daily Average: $128.57                │
│  💰 Total earnings         📈 Per day average                    │
│                                                                  │
│  Per Order: $30            Total Orders: 30                      │
│  🎯 Per delivery average   📦 Deliveries completed              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 1️⃣ **This Week** - Total Earnings
### What It Is
Total money earned in the current 7-day period

### How It's Calculated
```
Week Mon: $150
Week Tue: $120
Week Wed: $130
Week Thu: $110
Week Fri: $140
Week Sat: $160
Week Sun: $90
──────────────
TOTAL:   $900
```

### Database Source
```sql
SELECT SUM(amount) FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Why It Matters
Shows your total weekly income for planning and budgeting.

---

## 2️⃣ **Daily Average** - Per Day Average
### What It Is
Average earnings per day over the 7-day week

### How It's Calculated
```
Total Weekly Earnings ÷ 7 days = Daily Average
$900 ÷ 7 = $128.57 per day
```

### Database Source
```sql
SELECT SUM(amount) / 7 FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Why It Matters
- If working 5 days: $900 ÷ 5 = $180/day (high!)
- If working 7 days: $900 ÷ 7 = $128.57/day (lower)
- Shows your work consistency and pattern

---

## 3️⃣ **Per Order** - Average Per Delivery ⭐ KEY METRIC
### What It Is
**Average earnings per delivery you complete**

### How It's Calculated
```
Total Weekly Earnings ÷ Total Deliveries = Per Order
$900 ÷ 30 deliveries = $30 per order
```

### Database Source
```sql
SELECT 
  SUM(amount) as total,
  COUNT(*) as orders,
  SUM(amount) / COUNT(*) as per_order
FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Real Example

**Scenario 1: Lower per-order earnings**
```
30 deliveries × $15/order = $450/week ❌ Not great
```

**Scenario 2: Moderate per-order earnings**
```
30 deliveries × $30/order = $900/week ✓ Good
```

**Scenario 3: High per-order earnings**
```
20 deliveries × $45/order = $900/week ✓✓ Better
(Earn same with less work!)
```

### Why It Matters
1. **Quality Indicator**: Are you picking good-paying orders?
2. **Efficiency**: How much per delivery?
3. **Goal Setting**: "I want $40/order"
4. **Comparison**: Am I improving? ($25 last week → $30 this week)
5. **Income Prediction**: 25 orders × $35/order = $875 expected

---

## 4️⃣ **Total Orders** - Delivery Count
### What It Is
Number of deliveries completed in the week

### How It's Calculated
```
COUNT of all deliveries = Total Orders
30 deliveries this week
```

### Database Source
```sql
SELECT COUNT(*) FROM deliveries 
WHERE rider_id = ? AND date BETWEEN week_start AND week_end
```

### Why It Matters
- Shows your work volume
- Higher count = more active week
- Combined with per-order shows efficiency

---

## Database Tracking

### Real-Time: `deliveries` table
```sql
╔════╦═══════════╦════════╦══════════╦═══════════╦════════╗
║ id ║ rider_id  ║ amount ║ base_pay ║ bonus     ║ tip    ║
╠════╬═══════════╬════════╬══════════╬═══════════╬════════╣
║ 1  ║ 5         ║ 35.00  ║ 20.00    ║ 5.00      ║ 10.00  ║ ← Delivery 1 = $35
║ 2  ║ 5         ║ 30.00  ║ 20.00    ║ 0.00      ║ 10.00  ║ ← Delivery 2 = $30
║ 3  ║ 5         ║ 28.00  ║ 20.00    ║ 8.00      ║ 0.00   ║ ← Delivery 3 = $28
║... ║ ...       ║ ...    ║ ...      ║ ...       ║ ...    ║
║ 30 ║ 5         ║ 32.00  ║ 20.00    ║ 12.00     ║ 0.00   ║ ← Delivery 30 = $32
╚════╩═══════════╩════════╩══════════╩═══════════╩════════╝

TOTAL = 30 deliveries
SUM = $900
AVG = $30/order
```

### Weekly Cache: `rider_weekly_earnings` table
```sql
┌─────────┬──────────┬──────────────────┬──────────────┐
│ week_start  │ week_end   │ total_amount │ per_order_avg │
├─────────────┼────────────┼──────────────┼───────────────┤
│ 2025-11-17  │ 2025-11-23 │ 900.00       │ 30.00         │ ← Cached weekly
└─────────────┴────────────┴──────────────┴───────────────┘
```

### Quick Access: `riders_account` table
```sql
┌──────────┬────────────┬──────────────┐
│ rider_id │ total_earned │ total_earnings │
├──────────┼──────────────┼─────────────────┤
│ 5        │ 900.00       │ 5200.00         │ ← Cumulative
└──────────┴──────────────┴─────────────────┘
```

---

## Formulas at a Glance

| Metric | Formula | Example |
|--------|---------|---------|
| **This Week** | `SUM(amount)` | `$900` |
| **Daily Average** | `SUM / 7` | `$900 ÷ 7 = $128.57` |
| **Per Order** | `SUM / COUNT(*)` | `$900 ÷ 30 = $30` |
| **Total Orders** | `COUNT(*)` | `30` |

---

## Code Locations

| What | File | Line |
|------|------|------|
| Calculate per order | `get_earnings.php` | 74 |
| Display on page | `rider_earnings.php` | 135, 176, 313 |
| Cache weekly | `backfill_rider_accounts.php` | 25 |
| Table schema | `db.php` | 75-90 (deliveries) |

---

## How Riders Use This

### Day-to-Day
✓ "My per-order is $30 today" (good)
✓ "Need 25 orders to hit $750 this week"
✓ "Last week was $28, improving!"

### Planning
✓ "To earn $1000/week, I need 30-35 orders"
✓ "Should aim for orders paying $40+"
✓ "If I deliver 5 days instead of 7, daily rate increases"

### Analysis
✓ Compare week-to-week per-order average
✓ Track if improving or declining
✓ Identify which zones have better per-order rates

---

## Status: ✅ Complete & Working

- ✅ Per Order = Total Earnings ÷ Total Deliveries
- ✅ Calculated real-time from `deliveries` table
- ✅ Cached weekly in `rider_weekly_earnings`
- ✅ Displayed in rider earnings dashboard
- ✅ Used for performance tracking

**Ready to use!** 🚀
