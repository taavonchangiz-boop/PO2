# Task 15-22: Mobile API Controllers & Migration

## Summary
Created 7 new PHP API controllers and 1 SQLite migration file for the Postyar-Z mobile API layer.

## Files Created

### 1. NotificationApiController.php
- `index()` — GET /api/v1/notifications — paginated notifications with unread count
- `markRead($id)` — POST /api/v1/notifications/{id}/read — mark single as read
- `markAllRead()` — POST /api/v1/notifications/read-all — mark all as read

### 2. SettingsApiController.php
- `getSettings()` — GET /api/v1/settings — user settings as key-value map
- `saveGoldSettings()` — POST /api/v1/settings/gold — UPSERT gold settings with image upload
- `triggerGoldPublish()` — POST /api/v1/settings/gold/trigger — manual gold price publish
- `saveAdvancedSettings()` — PUT /api/v1/settings/advanced — UPSERT 18 advanced settings
- `getAutoReplies()` — GET /api/v1/auto-responder — auto-reply list with channel info
- `addAutoReply()` — POST /api/v1/auto-responder — create auto-reply with ownership check
- `deleteAutoReply($id)` — DELETE /api/v1/auto-responder/{id} — delete with tenant check
- `toggleResponder()` — POST /api/v1/auto-responder/toggle — enable/disable per-channel

### 3. WalletReferralApiController.php
- `getWallet()` — GET /api/v1/wallet — balance + last 50 transactions
- `convertPoints()` — POST /api/v1/wallet/convert-points — points to wallet
- `getReferral()` — GET /api/v1/referral — code, link, stats, referral history

### 4. AnalyticsApiController.php
- `linkStats()` — GET /api/v1/analytics/links — all links with click/unique counts
- `linkStatsDetail($id)` — GET /api/v1/analytics/links/{id} — link detail + daily click breakdown

### 5. SupportApiController.php
- `index()` — GET /api/v1/tickets — user tickets with reply counts
- `store()` — POST /api/v1/tickets — create ticket with attachment upload
- `show($id)` — GET /api/v1/tickets/{id} — ticket detail + replies with sender names
- `reply($id)` — POST /api/v1/tickets/{id}/reply — reply with optional close

### 6. BillingApiController.php
- `getPlans()` — GET /api/v1/plans — public plan list (no auth)
- `submitPayment()` — POST /api/v1/payments — payment submission with receipt upload
- `getPayments()` — GET /api/v1/payments — user payment history
- `validateCoupon()` — POST /api/v1/coupons/validate — coupon validation

### 7. AdminApiController.php (15 methods, all superadmin)
- `dashboard()` — stats: users, payments, tickets, recent registrations
- `users()` — paginated user list with channel/post counts + active subscription
- `suspendUser($id)` / `activateUser($id)` — user status management
- `payments()` — admin payment list with filters
- `approvePayment($id)` — full payment approval logic
- `tickets()` / `replyTicket($id)` — ticket management with push notifications
- `plans()` / `createPlan()` / `updatePlan($id)` / `deletePlan($id)` — CRUD plans
- `broadcast()` — global announcement
- `addDiscount()` / `deleteDiscount($id)` — discount code management

### 8. mobile_api.sql
- SQLite migration: api_tokens table with indexes
