# Filament v4 Upgrade Plan for Larament Project

## Executive Summary

This document outlines the comprehensive upgrade plan for migrating the Larament project from Filament v3 to v4. The upgrade involves multiple phases including automated scripts, manual configuration changes, and testing.

**🎉 Great News**: Filament v4 includes native table repeater functionality, so you can **remove the `awcodes/filament-table-repeater` plugin** entirely!

---

## Pre-Upgrade Requirements Check

### ✅ Current Status
- **PHP Version**: 8.2+ (Required ✓)
- **Laravel Version**: 11.9+ (Required: 11.28+) - ⚠️ **NEEDS UPDATE**
- **Current Filament**: v3.3
- **Tailwind CSS**: v3.4.17 (Needs upgrade to v4.1+)

### ⚠️ Required Updates Before Upgrade
1. **Update Laravel** from 11.9 to 11.28+
   ```bash
   cd e:\AI_rewirte\larament; composer require laravel/framework:"^11.28" -W
   ```

2. **Check Plugin Compatibility**
   - `awcodes/filament-table-repeater`: ^3.1 → ✅ **CAN BE REMOVED** - Filament v4 has native support!
   - `bezhansalleh/filament-language-switch`: ^3.1 → Check for v4 compatibility

---

## Phase 1: Preparation & Backup

### 1.1 Backup Strategy
- [X] Create a git branch: `git checkout -b upgrade-filament-v4`
- [X] Backup database
- [X] Document current working state
- [X] Commit all current changes

### 1.2 Environment Check
- [ ] Review all `.env` variables related to Filament
- [ ] Check for `FILAMENT_FILESYSTEM_DISK` usage (currently none found)
- [ ] Document custom Filament configurations

### 1.3 Code Audit
- [ ] List all Filament Resources in `app/Filament/Resources/`
- [ ] Identify custom Filament Actions in `app/Filament/Actions/`
- [ ] Document custom Filament Components in `app/Filament/Components/`
- [ ] Review custom Pages in `app/Filament/Pages/`
- [ ] Check for custom Widgets in `app/Filament/Widgets/`
- [ ] Search for usage of deprecated methods

---

## Phase 2: Automated Upgrade Script

### 2.1 Install Upgrade Tool
```bash
cd e:\AI_rewirte\larament; composer require filament/upgrade:"~4.0" -W --dev
```

**Note**: Using `~4.0` instead of `^4.0` for Windows PowerShell compatibility.

### 2.2 Run Automated Upgrade
```bash
cd e:\AI_rewirte\larament; vendor/bin/filament-v4
```

**Expected Actions**:
- Automatically updates code references
- Suggests composer commands for v4 packages
- May suggest plugin replacements

### 2.3 Execute Suggested Commands
The script will output commands like:
```bash
cd e:\AI_rewirte\larament; composer require filament/filament:"~4.0" -W --no-update
cd e:\AI_rewirte\larament; composer update
```

### 2.4 Directory Structure Migration (Optional)
```bash
# Dry run first to preview changes
cd e:\AI_rewirte\larament; php artisan filament:upgrade-directory-structure-to-v4 --dry-run

# If satisfied, apply changes
cd e:\AI_rewirte\larament; php artisan filament:upgrade-directory-structure-to-v4
```

**Decision Point**: Recommend using new directory structure for better organization.

### 2.5 Remove Upgrade Tool
```bash
cd e:\AI_rewirte\larament; composer remove filament/upgrade --dev
```

---

## Phase 3: Tailwind CSS v4 Upgrade

### 3.1 Update Theme CSS File
**File**: `resources/css/filament/admin/theme.css`

**Current Content**:
```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';
@import '../../../../vendor/awcodes/filament-table-repeater/resources/css/plugin.css';

@config 'tailwind.config.js';
```

**New Content** (Remove plugin CSS import - no longer needed in v4):
```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
@source '../../../../app/Livewire/**/*';
```

**Note**: The `awcodes/filament-table-repeater` CSS import is removed because Filament v4 has native table repeater support.

### 3.2 Run Tailwind Upgrade Tool
```bash
cd e:\AI_rewirte\larament; npx @tailwindcss/upgrade
```

This will:
- Update `tailwind.config.js` to v4 format
- Install Tailwind v4 packages
- Update npm dependencies

### 3.3 Update Package.json
Update Tailwind CSS from v3.4.17 to v4.1+:
```bash
cd e:\AI_rewirte\larament; npm install tailwindcss@^4.1 -D
```

### 3.4 Convert Tailwind Config to CSS
Move any custom Tailwind configurations from `tailwind.config.js` to `theme.css` using Tailwind v4's CSS-based configuration.

---

## Phase 4: Configuration File Updates

### 4.1 Publish Filament Configuration
```bash
cd e:\AI_rewirte\larament; php artisan vendor:publish --tag=filament-config
```

### 4.2 Update Configuration File
**File**: `config/filament.php` (or wherever it's published)

Add the following configurations to preserve v3 behavior where needed:

```php
<?php

use Filament\Support\Commands\FileGenerators\FileGenerationFlag;

return [

    // Preserve v3 filesystem disk behavior
    'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),

    // File generation configuration
    'file_generation' => [
        'flags' => [
            FileGenerationFlag::EMBEDDED_PANEL_RESOURCE_SCHEMAS,
            FileGenerationFlag::EMBEDDED_PANEL_RESOURCE_TABLES,
            // Remove the following if using new directory structure:
            // FileGenerationFlag::PANEL_CLUSTER_CLASSES_OUTSIDE_DIRECTORIES,
            // FileGenerationFlag::PANEL_RESOURCE_CLASSES_OUTSIDE_DIRECTORIES,
            FileGenerationFlag::PARTIAL_IMPORTS,
        ],
    ],

];
```

### 4.3 Add Backwards Compatibility Defaults (AppServiceProvider)
**File**: `app/Providers/AppServiceProvider.php`

Add to the `boot()` method to preserve v3 behaviors:

```php
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;

public function boot(): void
{
    // Preserve v3 file visibility for non-local disks
    FileUpload::configureUsing(fn (FileUpload $fileUpload) => $fileUpload
        ->visibility('public'));

    // Preserve v3 table filter behavior (no defer)
    Table::configureUsing(fn (Table $table) => $table
        ->deferFilters(false));

    // Preserve v3 layout component column span behavior
    Fieldset::configureUsing(fn (Fieldset $fieldset) => $fieldset
        ->columnSpanFull());
    Grid::configureUsing(fn (Grid $grid) => $grid
        ->columnSpanFull());
    Section::configureUsing(fn (Section $section) => $section
        ->columnSpanFull());

    // Preserve v3 unique validation behavior
    Field::configureUsing(fn (Field $field) => $field
        ->uniqueValidationIgnoresRecordByDefault(false));

    // Preserve v3 pagination options
    Table::configureUsing(fn (Table $table) => $table
        ->paginationPageOptions([5, 10, 25, 50, 'all']));

    // Preserve v3 table sorting behavior (no default key sort)
    Table::configureUsing(fn (Table $table) => $table
        ->defaultKeySort(false));
}
```

**Note**: These are optional and should be reviewed individually. Only add what you actually need to preserve v3 behavior.

---

## Phase 5: High-Impact Breaking Changes

### 5.1 Custom Theme CSS Sources
- [x] Update `resources/css/filament/admin/theme.css` (covered in Phase 3)
- [ ] Ensure all custom Blade views using Tailwind classes are included in `@source` directives

### 5.2 Table Filter Behavior
**Impact**: Filters are now deferred by default (users must click apply button)

**Options**:
1. Accept new behavior (recommended for better UX)
2. Disable per table: `->deferFilters(false)`
3. Disable globally (see Phase 4.3)

**Action Items**:
- [ ] Test all tables with filters
- [ ] Update user documentation if keeping new behavior

### 5.3 File Upload Visibility
**Impact**: Files on non-local disks are now private by default

**Action Items**:
- [ ] Review all `FileUpload` fields
- [ ] Check image columns: `ImageColumn`, `SpatieMediaLibraryImageColumn`
- [ ] Verify if temporary signed URLs work correctly
- [ ] Update to `->visibility('public')` if needed or add global config (Phase 4.3)

### 5.4 Layout Components Column Span
**Impact**: `Grid`, `Section`, and `Fieldset` no longer span full width by default

**Action Items**:
- [ ] Search for all `Grid::make()`, `Section::make()`, `Fieldset::make()` usage
- [ ] Add `->columnSpanFull()` where full width is needed
- [ ] Or apply global config (Phase 4.3)

### 5.5 Unique Validation
**Impact**: `unique()` now ignores current record by default

**Action Items**:
- [ ] Search for all `->unique()` validation rules
- [ ] Add `ignoreRecord: false` where the old behavior is needed
- [ ] Or apply global config (Phase 4.3)

### 5.6 Pagination Options
**Impact**: `all` option is no longer available by default

**Action Items**:
- [ ] Review if `all` pagination is used
- [ ] Add `->paginationPageOptions([5, 10, 25, 50, 'all'])` per table if needed
- [ ] Or apply global config (Phase 4.3) - **⚠️ Be cautious with large datasets**

---

## Phase 6: Medium-Impact Breaking Changes

### 6.1 Column Span Responsive Behavior
**Impact**: `columnSpan()` now targets `>= lg` devices by default

**Action Items**:
- [ ] Search for all `->columnSpan()` usage
- [ ] Review responsive layouts on forms and infolists
- [ ] Update to explicit breakpoint arrays if needed: `->columnSpan(['lg' => 2])`

### 6.2 Enum Field State
**Impact**: Enum fields now always return enum instances, not values

**Action Items**:
- [ ] Search for Select/Radio/CheckboxList using `->options(Enum::class)`
- [ ] Update code that expects enum values to handle enum instances
- [ ] Example: `$state->value` instead of just `$state`

### 6.3 URL Parameter Names
**Impact**: Several URL parameters have been renamed

**Changes**:
- `activeRelationManager` → `relation`
- `activeTab` → `tab`
- `isTableReordering` → `reordering`
- `tableFilters` → `filters`
- `tableGrouping` → `grouping`
- `tableGroupingDirection` → `groupingDirection`
- `tableSearch` → `search`
- `tableSort` → `sort`

**Action Items**:
- [ ] Search for `::getUrl()` with old parameter names
- [ ] Update to new parameter names

### 6.4 Radio Inline Behavior
**Impact**: `inline()` no longer makes label inline

**Action Items**:
- [ ] Search for all `Radio::make()->inline()` usage
- [ ] Add `->inlineLabel()` if label should also be inline
- [ ] Or apply global config if needed

### 6.5 Import/Export Job Retries
**Impact**: Jobs now retry 3 times with 60s backoff instead of continuous retries

**Action Items**:
- [ ] Review if you have custom import/export classes
- [ ] Customize retry behavior if needed in importer/exporter classes

---

## Phase 7: Low-Impact Breaking Changes

### 7.1 Image Remaining Text
- [ ] Search for `limitedRemainingText()` with `isSeparate` parameter
- [ ] Remove the parameter (behavior is now automatic)

### 7.2 RichEditor Grammarly
- [ ] Search for `disableGrammarly()` method
- [ ] Remove it (no longer supported)

### 7.3 Custom Make Methods
If you have custom field/column classes:
- [ ] Update `make()` method signatures to accept nullable `?string $name = null`
- [ ] Or better: use `getDefaultName()` and `setUp()` methods instead

### 7.4 Default Primary Key Sorting
**Impact**: Tables now have default primary key sorting

**Action Items**:
- [ ] Test table sorting behavior
- [ ] Add `->defaultKeySort(false)` to tables without primary keys
- [ ] Or apply global config (Phase 4.3)

### 7.5 Authorization Methods
If overriding `can*()` methods in Resources:
- [ ] Replace with `get*AuthorizationResponse()` methods
- [ ] Use policy response objects instead of booleans

---

## Phase 8: Plugin-Specific Changes

### 8.1 Spatie Translatable Plugin
**Status**: Not currently used (not in composer.json)
- [ ] No action required

### 8.2 Third-Party Plugins

#### 8.2.1 Table Repeater Plugin - ✅ CAN BE REMOVED!
**Status**: `awcodes/filament-table-repeater` can be completely replaced with native Filament v4 functionality!

**Good News**: Filament v4 now includes native table repeater support in the core `Repeater` component. You no longer need the third-party plugin.

**Migration Steps**:

1. **Identify Current Usage**:
   ```bash
   # Search for table repeater usage
   cd e:\AI_rewirte\larament; grep -r "TableRepeater" app/
   cd e:\AI_rewirte\larament; grep -r "awcodes" app/
   ```

2. **Update Code** - Replace plugin usage with native Filament v4 repeater:

   **OLD (v3 with plugin)**:
   ```php
   use Awcodes\FilamentTableRepeater\Components\TableRepeater;
   
   TableRepeater::make('members')
       ->schema([
           TextInput::make('name')->required(),
           Select::make('role')->options([...])->required(),
       ])
   ```

   **NEW (v4 native)**:
   ```php
   use Filament\Forms\Components\Repeater;
   use Filament\Forms\Components\Repeater\TableColumn;
   use Filament\Forms\Components\TextInput;
   use Filament\Forms\Components\Select;
   
   Repeater::make('members')
       ->table([
           TableColumn::make('Name'),
           TableColumn::make('Role'),
       ])
       ->schema([
           TextInput::make('name')->required(),
           Select::make('role')->options([...])->required(),
       ])
   ```

3. **Additional Features Available**:
   ```php
   // Compact mode
   Repeater::make('members')
       ->table([...])
       ->compact()
       ->schema([...])
   
   // Required column indicators
   TableColumn::make('Name')->markAsRequired()
   
   // Column width
   TableColumn::make('Name')->width('200px')
   
   // Hide header label
   TableColumn::make('Name')->hiddenHeaderLabel()
   
   // Header wrapping
   TableColumn::make('Name')->wrapHeader()
   
   // Column alignment
   TableColumn::make('Name')->alignment(Alignment::Center)
   ```

4. **Remove Plugin CSS Import**:
   Update `resources/css/filament/admin/theme.css`:
   
   **Remove this line**:
   ```css
   @import '../../../../vendor/awcodes/filament-table-repeater/resources/css/plugin.css';
   ```

5. **Remove Plugin from Composer**:
   ```bash
   cd e:\AI_rewirte\larament; composer remove awcodes/filament-table-repeater
   ```

6. **Update Imports Across Project**:
   ```bash
   # Find all files using the old plugin
   cd e:\AI_rewirte\larament; grep -r "use Awcodes\\FilamentTableRepeater" app/ resources/
   
   # Replace imports manually or use find/replace:
   # Old: use Awcodes\FilamentTableRepeater\Components\TableRepeater;
   # New: use Filament\Forms\Components\Repeater;
   #      use Filament\Forms\Components\Repeater\TableColumn;
   ```

**Action Items**:
- [ ] Search for all `TableRepeater` usage in the project
- [ ] Convert to native Filament v4 `Repeater::make()->table()` syntax
- [ ] Remove plugin CSS import from theme file
- [ ] Remove plugin from composer.json
- [ ] Test all forms using table repeaters
- [ ] Update any documentation or comments

#### 8.2.2 Language Switch Plugin
**Status**: `bezhansalleh/filament-language-switch`: ^3.1

**Action Plan**:
```bash
# Check for v4 version
cd e:\AI_rewirte\larament; composer show bezhansalleh/filament-language-switch
```

**Action Items**:
- [ ] Check if v4 version is available
- [ ] Update to v4 compatible version or find alternative
- [ ] Test language switching functionality

---

## Phase 9: Testing & Validation

### 9.1 Automated Testing
```bash
cd e:\AI_rewirte\larament; ./vendor/bin/pest
```

**Test Priorities**:
- [ ] All Resource CRUD operations
- [ ] Form submissions with validation
- [ ] Table filtering and sorting
- [ ] File uploads and image handling
- [ ] Relation managers
- [ ] Custom actions
- [ ] Widget functionality

### 9.2 Manual Testing Checklist
- [ ] Admin panel login
- [ ] All resource list pages
- [ ] Create new records
- [ ] Edit existing records
- [ ] Delete records
- [ ] Bulk actions
- [ ] Search functionality
- [ ] Export/Import (if used)
- [ ] File uploads
- [ ] Image displays
- [ ] Relation managers
- [ ] Custom pages
- [ ] Widgets display correctly
- [ ] Language switching
- [ ] Responsive design (mobile/tablet)

### 9.3 PHPStan Analysis
```bash
cd e:\AI_rewirte\larament; ./vendor/bin/phpstan analyse
```

Fix any errors related to:
- [ ] Namespace changes from directory restructuring
- [ ] Method signature changes
- [ ] Type mismatches with enum instances

### 9.4 Code Style Check
```bash
cd e:\AI_rewirte\larament; ./vendor/bin/pint
```

### 9.5 Performance Testing
- [ ] Page load times
- [ ] Table query performance
- [ ] Asset compilation time
- [ ] Memory usage

---

## Phase 10: Production Preparation

### 10.1 Asset Compilation
```bash
cd e:\AI_rewirte\larament; npm run build
```

### 10.2 Clear Caches
```bash
cd e:\AI_rewirte\larament; php artisan optimize:clear
cd e:\AI_rewirte\larament; php artisan filament:cache-components
cd e:\AI_rewirte\larament; php artisan icons:cache
```

### 10.3 Documentation Updates
- [ ] Update README.md with new requirements
- [ ] Update deployment documentation
- [ ] Document any breaking changes for team
- [ ] Update user guides if UI behavior changed

### 10.4 Deployment Checklist
- [ ] Test on staging environment first
- [ ] Database migrations (if any)
- [ ] Run `composer install --optimize-autoloader --no-dev`
- [ ] Run `npm run build`
- [ ] Clear production caches
- [ ] Monitor error logs after deployment

---

## Phase 11: Post-Upgrade Optimization

### 11.1 Remove Backwards Compatibility (Optional)
After confirming everything works:
- [ ] Review AppServiceProvider configurations
- [ ] Remove unnecessary `configureUsing()` calls
- [ ] Adopt v4 defaults where appropriate

### 11.2 Adopt New Features
Consider using new v4 features:
- [ ] New directory structure (if not already done)
- [ ] Deferred table filters for better UX
- [ ] Private file uploads for security
- [ ] Improved tenancy features (if using tenancy)

### 11.3 Code Cleanup
- [ ] Remove deprecated method calls
- [ ] Update to use new best practices
- [ ] Simplify code using new features

---

## Risk Assessment & Mitigation

### High Risk Areas
1. **Custom Theme CSS** (High Impact)
   - **Risk**: Tailwind v4 migration may break custom styles
   - **Mitigation**: Test thoroughly, keep backup of v3 CSS

2. **File Uploads** (High Impact)
   - **Risk**: File visibility changes may break image displays
   - **Mitigation**: Test all file upload/display areas, use global config initially

3. **Third-Party Plugins** (High Impact)
   - **Risk**: Plugins may not be v4 compatible
   - **Mitigation**: Check compatibility before upgrade, have alternatives ready

### Medium Risk Areas
1. **Layout Components** (Medium Impact)
   - **Risk**: Forms may look broken without `columnSpanFull()`
   - **Mitigation**: Use global config initially, update incrementally

2. **URL Parameters** (Medium Impact)
   - **Risk**: Deep links may break
   - **Mitigation**: Update all URL generation code

3. **Enum Handling** (Medium Impact)
   - **Risk**: Code expecting enum values will fail
   - **Mitigation**: Search and update all enum field handling

### Low Risk Areas
1. **Table Sorting** (Low Impact)
   - **Risk**: Different default sorting
   - **Mitigation**: Test and adjust as needed

2. **Minor API Changes** (Low Impact)
   - **Risk**: Minor bugs from method signature changes
   - **Mitigation**: PHPStan will catch most issues

---

## Rollback Plan

### If Major Issues Occur:
1. **Immediate Rollback**:
   ```bash
   git checkout main
   git branch -D upgrade-filament-v4
   composer install
   npm install
   ```

2. **Restore Database** (if migrations were run)

3. **Clear All Caches**:
   ```bash
   cd e:\AI_rewirte\larament; php artisan optimize:clear
   ```

### Partial Rollback:
- Keep git commits small and focused
- Can revert specific changes while keeping others

---

## Timeline Estimate

### Optimistic (Everything Goes Smooth):
- **Phase 1-2**: 2-3 hours
- **Phase 3**: 1-2 hours
- **Phase 4-7**: 3-4 hours
- **Phase 8**: 1-2 hours (depends on plugins)
- **Phase 9**: 4-6 hours
- **Phase 10-11**: 2-3 hours
- **Total**: ~2-3 days

### Realistic (With Issues):
- **Phase 1-2**: 3-4 hours
- **Phase 3**: 2-3 hours
- **Phase 4-7**: 6-8 hours
- **Phase 8**: 3-4 hours
- **Phase 9**: 8-10 hours
- **Phase 10-11**: 3-4 hours
- **Total**: ~4-5 days

### Pessimistic (Major Compatibility Issues):
- Add 3-5 additional days for plugin rewrites or major refactoring

---

## Success Criteria

### Must Have:
- [ ] All existing functionality works as before
- [ ] All tests pass
- [ ] No console errors in browser
- [ ] No PHP errors in logs
- [ ] Assets compile successfully
- [ ] Admin panel loads and functions

### Should Have:
- [ ] Performance same or better than v3
- [ ] Code quality maintained or improved
- [ ] PHPStan analysis passes
- [ ] New v4 features adopted where beneficial

### Nice to Have:
- [ ] New directory structure implemented
- [ ] Code modernized to use v4 best practices
- [ ] Backwards compatibility configs removed after stabilization

---

## Notes & Considerations

### Arabic UI Consideration
- Test all Arabic text rendering after Tailwind v4 upgrade
- Verify RTL support is maintained
- Check custom Arabic fonts still load correctly

### Currency (EGP) Handling
- Ensure number formatting still works correctly
- Test currency displays in tables and forms

### SSH Management App
- The `manage_operations` app is separate
- It has its own dependencies and doesn't need Filament upgrade
- But test any API integrations between the two apps

### Performance Monitoring
- Use Laravel Pulse (already installed) to monitor performance
- Compare before/after metrics
- Watch for any regressions

---

## Command Reference

### Quick Commands
```bash
# Full upgrade sequence
cd e:\AI_rewirte\larament; composer require laravel/framework:"^11.28" -W
cd e:\AI_rewirte\larament; composer require filament/upgrade:"~4.0" -W --dev
cd e:\AI_rewirte\larament; vendor/bin/filament-v4
cd e:\AI_rewirte\larament; composer require filament/filament:"~4.0" -W --no-update
cd e:\AI_rewirte\larament; composer update
cd e:\AI_rewirte\larament; npx @tailwindcss/upgrade
cd e:\AI_rewirte\larament; npm install
cd e:\AI_rewirte\larament; php artisan vendor:publish --tag=filament-config
cd e:\AI_rewirte\larament; php artisan filament:upgrade-directory-structure-to-v4 --dry-run
cd e:\AI_rewirte\larament; composer remove filament/upgrade --dev

# Testing
cd e:\AI_rewirte\larament; ./vendor/bin/pint
cd e:\AI_rewirte\larament; ./vendor/bin/pest
cd e:\AI_rewirte\larament; ./vendor/bin/phpstan analyse

# Production build
cd e:\AI_rewirte\larament; npm run build
cd e:\AI_rewirte\larament; php artisan optimize:clear
cd e:\AI_rewirte\larament; php artisan filament:cache-components
cd e:\AI_rewirte\larament; php artisan icons:cache
```

---

## Conclusion

This upgrade plan provides a comprehensive roadmap for migrating the Larament project from Filament v3 to v4. The key to success is:

1. **Thorough preparation** - backup and document everything
2. **Incremental approach** - test after each phase
3. **Comprehensive testing** - don't skip manual testing
4. **Backwards compatibility** - use config options to preserve v3 behavior initially
5. **Gradual adoption** - move to v4 defaults after stabilization

**Recommended Approach**: Start with backwards compatibility enabled (Phase 4.3), verify everything works, then gradually remove compatibility configs and adopt v4 defaults over subsequent iterations.

---

**Document Version**: 1.0  
**Last Updated**: January 18, 2026  
**Project**: Larament (AI_rewirte)  
**Prepared For**: Filament v3 → v4 Migration
