<?php

/*
 * Static check for the POS (React) sources: restaurant words may only appear
 * in files that render solely for the Restaurant module. Generic components
 * read their wording from resources/js/lib/wording.ts, whose restaurant words
 * sit between the RESTAURANT-WORDS markers.
 *
 * Adding a file to the allowlist needs a real reason: it must be reachable
 * only through a restaurant route or only rendered under
 * <IfModule name="restaurant">. A generic component must use useTerms().
 */

/** @return array<string, string> relative path => reason */
function posRestaurantOnlyFiles(): array
{
    return [
        // Restaurant routes (/kitchen/*, /floor/designer/*) only.
        'Pages/Kitchen/Index.tsx' => 'KDS page, served only by the restaurant /kitchen route',
        'Pages/Floor/Designer.tsx' => 'floor designer page, served only by /floor/designer',
        'Components/Kitchen/KdsTicketBlocks.tsx' => 'KDS ticket UI, used only by Pages/Kitchen',
        'Components/Kitchen/ticketModel.ts' => 'KDS ticket model, used only by Pages/Kitchen',
        'Components/Floor/DesignerToolbar.tsx' => 'floor designer UI, used only by Pages/Floor/Designer',
        'Components/Floor/EmptySelectionPanel.tsx' => 'floor designer UI, used only by Pages/Floor/Designer',
        'Components/Floor/FloorCanvas.tsx' => 'floor designer and dine-in floor body (restaurant behaviours only)',
        'Components/Floor/PropertiesPanel.tsx' => 'floor designer UI, used only by Pages/Floor/Designer',
        'Components/Floor/floorLayout.ts' => 'floor layout helpers for the designer and the dine-in floor body (restaurant behaviours only)',
        'Components/Floor/hooks/useDesignerShortcuts.ts' => 'floor designer hook',
        'Components/Floor/hooks/useFloorDrag.ts' => 'floor designer hook',
        // Rendered only under <IfModule name="restaurant">.
        'Components/Orders/ChooseTableForm.tsx' => 'table picker, wrapped in <IfModule name="restaurant"> in OrderTypeTab',
        'Components/Orders/PrintChangesModal.tsx' => 'kitchen changes print, wrapped in <IfModule name="restaurant"> in ManageOrder',
        'Components/Orders/PrintInKitchenModal.tsx' => 'kitchen print, wrapped in <IfModule name="restaurant"> in ManageOrder/ManageWebOrder',
        'Components/Print/KitchenTemplate.tsx' => 'kitchen ticket template for the kitchen print flow',
    ];
}

/**
 * @param  list<string>  $words
 * @param  array<string, string>  $allowlist
 * @return list<string> "path:line: text" hits outside the allowlist
 */
function posWordHits(array $words, array $allowlist): array
{
    $root = resource_path('js');
    $pattern = '/'.implode('|', array_map(fn (string $w): string => preg_quote($w, '/'), $words)).'/u';
    $hits = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! preg_match('/\.(ts|tsx)$/', $file->getFilename())) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (isset($allowlist[$relative])) {
            continue;
        }

        $inRestaurantBlock = false;
        foreach (file($file->getPathname()) as $index => $line) {
            if (str_contains($line, 'RESTAURANT-WORDS:BEGIN')) {
                $inRestaurantBlock = $relative === 'lib/wording.ts';

                continue;
            }
            if (str_contains($line, 'RESTAURANT-WORDS:END')) {
                $inRestaurantBlock = false;

                continue;
            }
            if ($inRestaurantBlock) {
                continue;
            }
            if (preg_match($pattern, $line)) {
                $hits[] = $relative.':'.($index + 1).': '.trim($line);
            }
        }
    }

    return $hits;
}

it('keeps restaurant words out of generic POS components', function () {
    $hits = posWordHits(['مطعم', 'مطبخ', 'طاولة', 'طاولات', 'صالة', 'ترابيزة'], posRestaurantOnlyFiles());

    expect($hits)->toBe([], "Restaurant words in generic POS files (use useTerms() from @/lib/wording or gate the file):\n".implode("\n", $hits));
});

it('keeps terminal words out of the POS while multi-terminal does not exist', function () {
    $hits = posWordHits(['ترمينال', 'طرفية'], []);

    expect($hits)->toBe([], "Terminal words in POS files:\n".implode("\n", $hits));
});

it('lists only allowlisted files that exist', function () {
    foreach (array_keys(posRestaurantOnlyFiles()) as $relative) {
        expect(file_exists(resource_path('js/'.$relative)))->toBeTrue("Stale allowlist entry: {$relative}");
    }
});
