# Postyar Unified Redesign Audit

## Scope audited
- Public/entry experience: home, login, registration, phone login, SMS verification, password reset, privacy, help, error states.
- User workspace: dashboard, posts, scheduling, channels, payments/subscriptions, profile/security, notifications, gold ticker, advanced settings, auto responder, WooCommerce-related flows, tickets, wallet, referral, link analytics, advertising.
- Administration/support: admin dashboard, users, payments, plans, discounts, announcements, tickets, wallet/referral settings, provider/payment settings, SMS, email, gold, AI, auto-responder, WooCommerce and advertising management.
- API controllers confirm dedicated modules for analytics, billing, channels, dashboard, notifications, posts, settings, support, wallet/referral and advertising.

## Existing design problems found
1. Separate visual systems: dashboard is indigo/navy, admin is amber/navy, home is a separate black/glass language.
2. Responsive rules are page-local and not governed by shared tokens.
3. RTL behavior exists at document level but component alignment and directional controls are not normalized globally.
4. Repeated card, button, form, modal and table styles reduce consistency.
5. Analytics needs a shared KPI/grid/chart system rather than isolated card treatments.
6. Help, privacy and errors use isolated inline style systems.

## Locked redesign system
- Persian-first, native RTL.
- Vazirmatn throughout.
- Single dark premium SaaS palette: #070b16 base, #0b1120 surfaces, #818cf8 primary, #6366f1 strong primary.
- Shared 12-column desktop grid, 6-column tablet grid and 2/1-column mobile behavior.
- 18px standard surface radius, 26px large dialog radius, 12px control radius.
- 12–24px responsive gap scale.
- Shared focus states, touch targets, hover/press feedback and reduced-motion fallback.
- Right-side desktop navigation and RTL drawer behavior.
- RTL tables, forms, breadcrumbs, dropdowns and nested navigation.
- KPI cards and analytics charts standardized around a reusable grid.

## Responsive matrix
Desktop and large desktop: 12-column layout, persistent app shell.
Tablet portrait/landscape: 6-column adaptive grid, controlled navigation collapse.
Mobile portrait: one/two-column content depending on density, 46px minimum primary controls.
Mobile landscape: compact header, scrollable drawer and reduced hero height.
All modes preserve Persian copy, RTL direction and component identity.

## Implementation artifact
`public/assets/css/unified-product.css` is the shared override layer for the unified design system. It is intended to be loaded after page-specific CSS in all primary views so legacy page styles are normalized without rewriting application behavior.
