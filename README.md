# FLS Checkout Flow

Custom checkout flow foundation for Floorista.

## Current scope

- Custom plugin-driven templates for:
  - Checkout
  - Thank you page
  - Order pay page
- jQuery-based step navigation for the checkout prototype
- Tailwind build scaffold with `fls-` prefix
- Preline-ready script registration if `vendor/preline/preline.js` exists

## NPM setup

```bash
npm install
```

## Tailwind watch

```bash
npm run dev
```

## Production build

```bash
npm run build
```

## Preline JS

Copy the built Preline browser file into:

```text
vendor/preline/preline.js
```

Then the plugin will enqueue it automatically on checkout-related pages.
