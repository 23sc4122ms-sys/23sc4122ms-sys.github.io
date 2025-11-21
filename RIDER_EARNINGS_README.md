# Automatic Rider Payment System

A comprehensive multi-source earnings and automatic payout system for riders based on delivery services, professional racing, content creation, and brand endorsements.

## Overview

This system automatically aggregates earnings from four distinct revenue streams and generates periodic payouts for riders. Admins can track, manage, and process payments through a centralized dashboard.

## Revenue Streams

### 1. **Delivery Services**
- Base delivery fee per order
- Distance-based rates
- Performance bonuses
- Customer tips
- Tracks: `deliveries.base_pay`, `deliveries.bonus`, `deliveries.tip`, `deliveries.amount`

### 2. **Professional Racing**
- Event participation and prizes
- Position-based earnings (1st/2nd/3rd place)
- Time-trial and track racing support
- Tracks: `racing_events`, `racing_participations`

### 3. **Content Creation**
- Video content (YouTube, TikTok, etc.)
- Blog posts and articles
- Photo content
- Product/service reviews
- Livestreams and podcasts
- Base payment + engagement bonuses (views, likes, shares)
- Tracks: `rider_content`, `content_types`

### 4. **Brand Endorsements**
- Fixed fee partnerships
- Commission-based sales
- Hybrid models (fixed + commission)
- Monthly guarantees
- Tracks: `endorsement_deals`, `endorsement_transactions`

## Database Schema

### Core Tables

**`payouts`** - Aggregated payout records
- `id` - Payout ID
- `rider_id` - FK to riders
- `payout_period_start` / `payout_period_end` - Period covered
- `delivery_earnings`, `racing_earnings`, `content_earnings`, `endorsement_earnings` - Breakdown by source
- `total_earnings` - Sum of all sources
- `deductions` - Penalties, fees
- `net_payout` - Final amount
- `payment_status` - pending | processing | completed | failed | cancelled
- `payment_method` - bank_transfer | card | check | cryptocurrency
- `payment_reference` - Transaction ID from payment provider

**`payout_logs`** - Audit trail
- Tracks all status changes and modifications

**`payout_settings`** - Configuration
- Auto-payout enabled/disabled
- Payout frequency (weekly, biweekly, monthly)
- Minimum threshold
- Base rates for each service

### Revenue Stream Tables

**Delivery Services** (extends `deliveries` table)
- `base_pay`, `bonus`, `tip`, `amount`, `paid`, `paid_at`, `payout_id`

**Racing**
- `racing_events` - Event records
- `racing_participations` - Rider participation with earnings

**Content Creation**
- `rider_content` - Content submissions with engagement metrics
- `content_types` - Types (video, blog, photo, review, etc.) with base rates

**Brand Endorsements**
- `brands` - Brand information and commission rates
- `endorsement_deals` - Rider-brand partnerships
- `endorsement_transactions` - Individual sales/commissions

## Key Files

### Admin Interface
- **`rider_earnings_admin.php`** - Main dashboard
  - View all payouts with filtering
  - See breakdown by rider and status
  - Generate automatic payouts
  - Process pending payouts

### Calculation Engine
- **`calculate_rider_payouts.php`** - Automated payout creation
  - Scans all revenue sources
  - Aggregates by rider for payment period
  - Creates payout records marked as "pending"
  - Marks individual items as "paid"
  - Logs all changes for audit trail

### Support APIs
- **`update_payout_status.php`** - Update payout status (admin only)
- **`get_payout_details.php`** - View detailed breakdown of a payout

### Database Setup
- **`schema_rider_earnings.sql`** - Complete schema with all tables and defaults

## Setup Instructions

### 1. Create Database Tables
Run the SQL schema in phpMyAdmin or MySQL:
```bash
mysql -u root -p japan_food < schema_rider_earnings.sql
```

Or paste the entire contents of `schema_rider_earnings.sql` in phpMyAdmin Query tab.

### 2. Configure Payout Settings
Edit `payout_settings` table in phpMyAdmin:
- `auto_payout_enabled` - Set to 1 to enable
- `payout_frequency` - Set to 'weekly', 'biweekly', or 'monthly'
- `payout_threshold` - Minimum earnings before creating payout (default: $50)
- `payout_day_of_week` - Day for weekly payouts (0=Sunday, 5=Friday)
- `delivery_base_rate` - Base amount per delivery
- `racing_1st_place`, `racing_2nd_place`, `racing_3rd_place` - Prize amounts

### 3. Access Admin Dashboard
Navigate to: `http://localhost/JapanFoodOrder/rider_earnings_admin.php`

Must be logged in as admin or owner.

## Usage

### Generate Payouts (Automatic)
1. Click **"Generate Payouts"** button on admin dashboard
2. System will:
   - Scan all riders for unpaid earnings from last month
   - Aggregate across all 4 revenue sources
   - Create pending payout records
   - Mark individual items as included in payout

### Manual Payout Creation
Via CLI:
```bash
php calculate_rider_payouts.php
```

Returns JSON with details of created payouts.

### Process Payout
1. Find payout in admin dashboard
2. Click **"Process"** button
3. Status changes from "pending" to "processing"
4. Externally process payment via bank/payment provider
5. Manually update status to "completed" when done

### View Payout Details
Click **"Details"** button to see:
- Earnings breakdown by source
- Individual delivery/racing/content/endorsement items
- Exact amounts and dates

## Configuration Examples

### Example 1: Basic Delivery Only
```sql
UPDATE payout_settings SET setting_value = '1' WHERE setting_key = 'delivery_base_rate';
UPDATE payout_settings SET setting_value = '0.50' WHERE setting_key = 'delivery_distance_rate';
UPDATE payout_settings SET setting_value = '0' WHERE setting_key = 'racing_1st_place';
```

### Example 2: High Volume with All Services
```sql
UPDATE payout_settings SET setting_value = '3' WHERE setting_key = 'delivery_base_rate';
UPDATE payout_settings SET setting_value = '0.75' WHERE setting_key = 'delivery_distance_rate';
UPDATE payout_settings SET setting_value = '150' WHERE setting_key = 'racing_1st_place';
UPDATE payout_settings SET setting_value = '25' WHERE setting_key = 'content_video_base';
UPDATE payout_settings SET setting_value = '100' WHERE setting_key = 'endorsement_default_commission';
```

## Payment Methods

Currently supported payment methods in `payouts.payment_method`:
- `bank_transfer` - Direct bank deposit (default)
- `card` - Credit/debit card
- `check` - Check payment
- `cryptocurrency` - Crypto payout

## Audit & Compliance

All changes logged in `payout_logs`:
- Who made the change (performed_by)
- When (created_at)
- What changed (old_value / new_value)
- Why (notes)

Facilitates compliance audits and dispute resolution.

## Security Features

- Admin/owner role required for all payout operations
- Session-based authentication
- Logged audit trail of all modifications
- Transaction-based consistency (all-or-nothing)
- Prevents double payments (paid flag)

## Future Enhancements

1. **Scheduled Payouts** - Cron job to auto-generate on schedule
2. **Tax Integration** - Automatic 1099/W2 calculation
3. **Payment Gateway Integration** - Automatic processing via Stripe, PayPal, etc.
4. **Dispute System** - Riders can challenge payout amounts
5. **Incentive Tiers** - Performance bonuses at milestones
6. **Multi-Currency** - Support for international riders
7. **Invoice Generation** - Automatic receipt PDFs

## Troubleshooting

**Q: No payouts being generated**
- Check `payout_settings` table for `auto_payout_enabled = 1`
- Verify riders have earnings in the period
- Ensure earnings exceed `payout_threshold`

**Q: Missing delivery earnings**
- Verify `deliveries` table has `amount` and `paid` columns
- Check delivery `delivered_at` date is within period
- Ensure `paid = 0` before payout generation

**Q: Payout status not updating**
- Verify logged-in user is admin/owner
- Check browser console for errors
- Review `update_payout_status.php` permissions

## Support

For issues or questions, check:
1. `payout_logs` table for error details
2. Browser developer console (F12) for JS errors
3. PHP error logs in `php_errors.log`
