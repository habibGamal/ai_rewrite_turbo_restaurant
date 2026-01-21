---
name: testFilamentResource
description: Generate comprehensive tests for a FilamentPHP resource following best practices
argument-hint: The FilamentPHP resource name (e.g., CategoryResource, ProductResource)
---

Using the filamentphp-testing skill, implement comprehensive tests for the specified FilamentPHP resource.

The test file should include:

1. **Page Rendering Tests**: Test all resource pages (List, Create, Edit, View)
2. **Table Tests**: 
   - Column existence and rendering
   - Sorting functionality
   - Search functionality
   - Record visibility
3. **CRUD Operations**:
   - Create records
   - Update records
   - View records
   - Delete single records
   - Bulk delete records
4. **Form Validation**: Test all validation rules (required fields, max length, unique, etc.)
5. **Actions**: Test all page actions, table actions, and bulk actions
6. **Relationships**: Test relationship counts and related data if applicable

Follow these conventions:
- Use Pest testing framework
- Handle authentication properly (check for AdminAccess trait or policies)
- Use factories for test data
- Follow existing project test patterns
- Use proper assertions for FilamentPHP components
- Fix any authorization issues (403 errors)
- Avoid using non-existent assertion methods
- Remove invalid action assertions (create/save are not actions on resource pages)

After creating the tests, run them and iteratively fix any errors until all tests pass.
