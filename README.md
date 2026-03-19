# FLS Checkout Flow

Custom checkout flow foundation for Floorista.

## Project structure

```text
fls-checkout-flow/
├─ fls-checkout-flow.php            # Plugin bootstrap and autoloader
├─ app/
│  ├─ Core/
│  │  └─ Plugin.php                 # Boots the plugin after WooCommerce check
│  ├─ Front/
│  │  ├─ Assets.php                 # Registers and enqueues plugin CSS and JS
│  │  └─ TemplateLoader.php         # Replaces WooCommerce checkout-related templates
│  └─ Support/
│     ├─ Template.php               # Loads template files from the plugin
│     └─ ThemeBridge.php            # Optional bridge for reusable theme partials
├─ templates/
│  ├─ checkout-page.php             # Main checkout layout
│  ├─ thankyou-page.php             # Thank you page template
│  ├─ order-pay-page.php            # Order pay page template
│  ├─ parts/
│  │  └─ stepper.php                # Step navigation UI
│  ├─ steps/
│  │  ├─ details.php                # Step 1 content
│  │  ├─ shipping.php               # Step 2 content
│  │  └─ payment.php                # Step 3 content
│  └─ sidebar/
│     └─ order-details.php          # Right-side order summary card
├─ resources/
│  ├─ css/frontend.css              # Tailwind source file
│  └─ js/frontend.js                # Source jQuery logic for steps
├─ dist/
│  ├─ css/frontend.css              # Runtime CSS loaded by WordPress
│  └─ js/frontend.js                # Runtime JS loaded by WordPress
├─ tailwind.config.js               # Tailwind config with fls- prefix
├─ postcss.config.js                # PostCSS config
└─ package.json                     # NPM dependencies and build scripts
```

## Current scope

- Custom plugin-driven templates for:
  - Checkout
  - Thank you page
  - Order pay page
- jQuery-based step navigation for the checkout prototype
- Tailwind scaffold with `fls-` prefix
- Stepper, panels, and order summary separated into their own template files

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
