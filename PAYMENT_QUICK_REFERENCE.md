# ⚡ Quick Reference: Fixed Rider Payment System

## What Changed?
✅ **Proof confirmation is now separate from payment**
- Admin confirms proof → Delivery marked 'confirmed', NOT paid
- Payout generation happens separately
- Admin has explicit control over all payments

## The 3-Step Process

### 1. CONFIRM PROOF (Delivery Page)
```
Delivery Orders → View Proof → Confirm Proof
├─ Status: pending → confirmed
├─ Amount: set by admin
└─ Payment: NOT triggered
```

### 2. GENERATE PAYOUTS (Earnings Dashboard)
```
Rider Earnings & Payouts → Generate Payouts button
├─ Scans all confirmed deliveries (paid=0)
├─ Scans racing/content/endorsements
├─ Creates payout record (status=pending)
└─ Marks items as paid
```

### 3. PROCESS PAYMENT (Earnings Dashboard)
```
Payout → Process button
├─ Status: pending → processing
├─ Do manual payment (bank/gateway)
└─ Mark complete (status=completed)
```

## Key Points

| Aspect | Before (WRONG) | After (FIXED) ✅ |
|--------|----------------|------------------|
| Confirm Proof | Paid rider | No payment |
| Admin Control | None | Full control |
| Payment Trigger | Auto | Manual (explicit) |
| When Rider Paid | Right away | At end of payout period |
| Audit Trail | Missing | Complete |

## Database State After Each Step

### After Confirming Proof
```
deliveries.status = 'confirmed'
deliveries.confirmed_at = <timestamp>
deliveries.amount = <admin input>
deliveries.paid = 0 ← Still unpaid!
```

### After Generating Payout
```
deliveries.paid = 1
deliveries.paid_at = <timestamp>
deliveries.payout_id = <payout_record_id>

payouts.status = 'pending'
payouts.delivery_earnings = <sum>
payouts.total_earnings = <all sources>
payouts.net_payout = <final>
```

### After Processing Payment
```
payouts.status = 'processing' (or 'completed')
payouts.processed_at = <timestamp>
payouts.payment_reference = <txn_id> (if filled)
```

## Admin Checklist

- [ ] Rider delivers order
- [ ] Rider uploads proof photo
- [ ] Admin reviews proof
- [ ] Admin confirms proof (sets amount)
- [ ] End of payout period (e.g., end of month)
- [ ] Admin generates payouts
- [ ] Admin reviews payout details
- [ ] Admin clicks "Process"
- [ ] Admin pays via bank/gateway
- [ ] Admin marks "Completed"
- [ ] Rider receives payment

## Common Issues & Fixes

**Q: Rider doesn't show in payout after confirming proof**
- ✅ Check deliveries table: status should be 'confirmed' (not 'delivered')
- ✅ Check confirmed_at is set
- ✅ Check paid=0 (not paid yet)
- ✅ Run `calculate_rider_payouts.php` to generate

**Q: Payout amount is wrong**
- ✅ Check delivery.amount was set correctly when confirming
- ✅ Check no other earnings (racing/content/endorsement) should be included
- ✅ View payout details to see breakdown

**Q: Payment already processed but still shows pending**
- ✅ Admin must click "Process" button to change status
- ✅ Status won't change automatically

**Q: Can't confirm proof**
- ✅ Make sure order is marked as PAID (orders.paid=1)
- ✅ Only confirmed deliveries can be paid

## Files Involved

### Admin Workflow
- `delivery.php` - Confirm proof button & modal
- `admin_confirm_proof.php` - Marks status='confirmed'
- `rider_earnings_admin.php` - Payout dashboard

### Calculation
- `calculate_rider_payouts.php` - Generates payouts (FIXED!)

### Payment Update
- `update_payout_status.php` - Update payout status

### Details
- `get_payout_details.php` - View breakdown

## Quick Commands

### Generate Payouts via CLI
```bash
cd /path/to/JapanFoodOrder
php calculate_rider_payouts.php
```

### Check Deliveries Status
```sql
SELECT id, status, confirmed_at, amount, paid FROM deliveries ORDER BY confirmed_at DESC LIMIT 10;
```

### Check Pending Payouts
```sql
SELECT id, rider_id, total_earnings, net_payout, payment_status FROM payouts WHERE payment_status = 'pending';
```

## Testing Steps

1. **Confirm Proof**
   - Delivery status → 'confirmed'
   - Amount → set
   - paid → still 0

2. **Generate Payouts**
   - Run `calculate_rider_payouts.php`
   - Check new payout created
   - Check deliveries.paid → 1

3. **Process Payment**
   - View payout in dashboard
   - Click "Process"
   - Status → 'processing'
   - Update to 'completed' when done

## Support

Check these if something's wrong:
1. `payout_logs` table - audit trail of all changes
2. `payouts` table - all payout records
3. `deliveries` table - status and paid flag
4. Browser console (F12) - JS errors
5. `php_errors.log` - PHP errors

