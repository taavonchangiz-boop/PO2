# Postyar UI/UX Pro Max — Master Design System

## Direction
- Product: Persian RTL SaaS for multi-channel publishing and channel automation.
- Style: Dark Aurora Bento SaaS — structured dashboard density with cinematic depth, restrained glass surfaces, and indigo/cyan emphasis.
- Implementation: server-rendered PHP + HTML/CSS/JS; preserve existing backend contracts and JavaScript behavior unless a UI bug requires a change.
- Source: UI/UX Pro Max Skill principles and repository skill workflow.

## Non-negotiable UX rules
1. RTL-first layout using logical spacing and predictable navigation.
2. Minimum 44px touch targets for interactive controls.
3. Visible keyboard focus rings and semantic interactive elements.
4. Text contrast target >= 4.5:1 for normal text.
5. No hover-only functionality; every interactive state works with keyboard/touch.
6. Respect `prefers-reduced-motion`.
7. Use SVG/iconography for interface controls; emoji are not the primary control icon.
8. Mobile-first responsive behavior with no horizontal page overflow.
9. Forms use explicit labels, clear focus states, inline validation/feedback, and loading states where asynchronous.
10. Tables remain usable on small screens through controlled horizontal overflow inside the table container, never the whole page.

## Visual tokens
- Background: `#070B16` / `#0B1120`
- Surface: `#101827` / `#141F33` / `#192640`
- Primary: `#6366F1` / `#818CF8`
- Secondary accent: `#22D3EE`
- Success: `#10B981`
- Warning: `#F59E0B`
- Danger: `#F43F5E`
- Text: `#F8FAFC`
- Secondary text: `#CBD5E1`
- Muted: `#94A3B8`
- Border: white/slate alpha, never warm brown borders.
- Radius: 10 / 14 / 20 / 26px.
- Motion: 160–240ms standard; reduced-motion fallback required.

## Component language
- Navigation: glass/blur shell, active item uses indigo/cyan semantic state rather than a solid gold fill.
- Cards: elevated dark surfaces with subtle border and controlled hover lift.
- CTAs: indigo gradient with restrained glow; no excessive gradients elsewhere.
- Tables: dense but readable headers, semantic badges, row hover, contained overflow.
- Forms: dark input surfaces, explicit labels, 44px minimum controls, visible focus ring.
- Landing: conversion-first hero, bento feature rhythm, product visualization, pricing clarity, proof/FAQ sections, responsive CTA.
- Dashboard/Admin: information hierarchy first; stats → operational content → tables/forms; preserve functional routes and AJAX contracts.

## Canonical implementation
`public_html/assets/css/unified-product.css` is the single shared visual override layer. The mirrored asset path under `postyar-private/public/assets/css/` is kept synchronized for the private/public application copy.

Page-specific stylesheets import the unified layer as their final visual layer so legacy layout dependencies remain available while their old visual treatment is neutralized.
