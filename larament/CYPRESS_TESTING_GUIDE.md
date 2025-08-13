# Cypress E2E Testing for Orders Management

This document describes the simplified end-to-end testing setup for the Orders Management system using Cypress.

## Overview

The testing suite covers three main components:
1. **Orders Index Page** (`/orders`) - Main orders dashboard with tabs for different order types
2. **Manage Order Page** (`/orders/manage/{id}`) - Individual order management for regular orders
3. **Manage Web Order Page** (`/web-orders/manage/{id}`) - Individual order management for web orders

## Philosophy

These tests are designed to work with **existing data** in your application, not create mock data. The tests will:
- Look for existing orders in the system
- Test functionality only when orders are available
- Gracefully handle empty states
- Focus on UI interactions and user workflows

## Running Tests

### Prerequisites
1. Ensure Laravel application is running on `http://localhost:8000`
2. Have some test data in your database (orders, products, categories, users)
3. Authentication system should be functional

### Commands

```bash
# Open Cypress Test Runner (interactive mode)
npm run cypress:open
npm run test:e2e:open

# Run tests headlessly (CI mode)
npm run cypress:run
npm run test:e2e

# Run specific test file
npx cypress run --spec "cypress/e2e/orders-index.cy.ts"
```

## Test Structure

```
cypress/
├── e2e/                          # End-to-end tests
│   ├── setup-verification.cy.ts  # Basic setup verification
│   ├── orders-index.cy.ts        # Tests for Orders Index page
│   ├── manage-order.cy.ts        # Tests for Manage Order page
│   ├── manage-web-order.cy.ts    # Tests for Manage Web Order page
│   └── orders-integration.cy.ts  # Integration tests across all pages
├── fixtures/                     # Test data (not used for order creation)
│   └── orders.json              # Sample order data for reference
├── support/                      # Support files and utilities
│   ├── commands.ts              # Custom Cypress commands
│   ├── e2e.ts                   # E2E support configuration
│   └── order-utils.ts           # Simple order testing helpers
```

## Test Categories

### 1. Setup Verification (`setup-verification.cy.ts`)
Basic tests to ensure Cypress is working:
- Application loads correctly
- Arabic text renders properly
- Configuration is correct

### 2. Orders Index Tests (`orders-index.cy.ts`)
Tests the main orders dashboard:
- Tab navigation between order types
- User interface elements
- End shift functionality
- Responsive design

### 3. Manage Order Tests (`manage-order.cy.ts`)
Tests order management when orders exist:
- Navigating to order management from index
- Testing action buttons and modals
- Save functionality
- Back navigation

### 4. Manage Web Order Tests (`manage-web-order.cy.ts`)
Tests web order management:
- Web delivery and takeaway order handling
- Status-specific actions (accept, reject, out for delivery)
- Web order specific fields and calculations

### 5. Integration Tests (`orders-integration.cy.ts`)
Complete workflow testing:
- Navigation between pages
- Responsive behavior
- Error handling
- Print functionality
- Modal interactions

## Custom Commands

Simple commands for common operations:

```typescript
// Authentication
cy.login(email, password)

// Navigation
cy.navigateToOrders()

// Utility
cy.waitForPageLoad()
```

## Helper Functions

The `OrderHelpers` utility provides simple functions:

```typescript
import { OrderHelpers } from '../support/order-utils'

// Check if orders exist in a tab
OrderHelpers.findOrderInTab('الصالة')

// Click on first available order
OrderHelpers.clickFirstOrder()

// Test modal functionality
OrderHelpers.testModal('ملاحظات الطلب')
```

## How Tests Handle No Data

Tests are designed to work gracefully when no orders exist:

```typescript
cy.get('body').then(($body) => {
  if ($body.find('.ant-card').length > 0) {
    // Test with existing orders
    cy.get('.ant-card').first().click()
    // ... test functionality
  } else {
    cy.log('No orders found - testing interface only')
    // ... test basic interface elements
  }
})
```

## Arabic UI Testing

The tests handle Arabic interface correctly:
- Use exact Arabic text for element identification
- Test RTL layout behavior
- Verify Unicode character rendering
- Account for right-to-left navigation

## Setting Up Test Data

To get meaningful test results, your database should have:

### Required Data
- At least one user account for login
- Some product categories with products
- Optionally: some existing orders in different states

### Creating Test Data
You can create test data through:
1. **Laravel Seeders**: `php artisan db:seed`
2. **Manual Creation**: Use your application's UI to create orders
3. **Factory/Faker**: If you have model factories set up

## Configuration

Update `cypress.config.ts` for your environment:

```typescript
export default defineConfig({
  e2e: {
    baseUrl: 'http://localhost:8000', // Your Laravel app URL
    viewportWidth: 1280,
    viewportHeight: 720,
    // ... other config
  }
})
```

## Best Practices

1. **Graceful Degradation**: Tests should work with or without existing data
2. **No Side Effects**: Tests should not modify critical data
3. **Arabic Text**: Use exact Arabic text matches for reliable assertions
4. **Conditional Testing**: Use conditional logic for optional features
5. **User Perspective**: Test from the user's point of view, not internal implementation

## Troubleshooting

### Common Issues

1. **No Orders Found**
   - Create some test orders through the UI
   - Check database has required data
   - Verify user permissions

2. **Authentication Issues**
   - Update login credentials in commands.ts
   - Check if test user exists and has proper permissions

3. **Arabic Text Issues**
   - Ensure browser supports Arabic fonts
   - Check database charset is UTF-8
   - Verify text encoding in test files

4. **Element Not Found**
   - Elements might be loading asynchronously
   - Use proper wait strategies
   - Check for dynamic content

### Debug Tips

```typescript
// Log current state for debugging
cy.get('body').then(($body) => {
  cy.log('Orders found:', $body.find('.ant-card').length)
})

// Take screenshot for visual debugging
cy.screenshot('current-state')

// Check URL and page state
cy.url().then(url => cy.log('Current URL:', url))
```

## Example Test Run

A typical test run will:
1. Log into the application
2. Navigate to orders page
3. Check all tabs are accessible
4. If orders exist, test management functionality
5. If no orders exist, verify empty states work correctly
6. Test responsive behavior across devices

This approach ensures tests are practical and useful for real-world scenarios.
