<?php

namespace App\Modules\Restaurant\Services;

use App\Enums\OrderBehavior;
use App\Models\OrderTypeRow;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FloorLayoutService
{
    public function isDineInType(string $code): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $code)
            && OrderTypeRow::query()
                ->where('code', $code)
                ->where('behavior', OrderBehavior::DINE_IN->value)
                ->exists();
    }

    private function path(string $code): string
    {
        return "floor-layouts/{$code}.json";
    }

    public function read(string $code): ?array
    {
        $this->ensureDineInType($code);
        $path = $this->path($code);
        $disk = Storage::disk('local');

        return $disk->exists($path)
            ? json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR)
            : null;
    }

    public function write(string $code, array $document): void
    {
        $this->ensureDineInType($code);
        $path = $this->path($code);
        Validator::make($document, $this->rules())->validate();
        if (
            isset($document['tableLabel'])
            && is_array($document['tableLabel'])
            && array_key_exists('prefix', $document['tableLabel'])
            && $document['tableLabel']['prefix'] === null
        ) {
            $document['tableLabel']['prefix'] = '';
        }
        foreach (['tables', 'sections'] as $kind) {
            foreach ($document[$kind] as $item) {
                $outOfColumns = $document['columns'] < $item['col'] + $item['colSpan'];
                $outOfRows = $document['rows'] < $item['row'] + $item['rowSpan'];
                if ($outOfColumns || $outOfRows) {
                    throw ValidationException::withMessages([$kind => 'العنصر خارج حدود الشبكة']);
                }
            }
        }
        $disk = Storage::disk('local');
        $disk->makeDirectory('floor-layouts');
        $temp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $encoded = json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
        if (! $disk->put($temp, $encoded) || ! $disk->move($temp, $path)) {
            $disk->delete($temp);
            throw new \RuntimeException('تعذر حفظ المخطط');
        }
    }

    public function isEnabledWithTables(string $code): bool
    {
        $layout = $this->read($code);

        return ($layout['enabled'] ?? false) === true && ! empty($layout['tables']);
    }

    private function ensureDineInType(string $code): void
    {
        if (! $this->isDineInType($code)) {
            throw new \InvalidArgumentException('The order type must use dine-in behavior.');
        }
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'enabled' => 'required|boolean',
            'columns' => 'required|integer|min:1|max:100',
            'rows' => 'required|integer|min:1|max:100',
            'tables' => 'present|array',
            'sections' => 'present|array',
            'tables.*.number' => 'required|string|max:50|distinct',
            'tables.*.capacity' => 'required|integer|min:1',
            'tables.*.shape' => 'nullable|in:square,round',
            'tables.*.zone' => 'present|nullable|string',
            'tables.*.active' => 'required|boolean',
            'sections.*.name' => 'required|string',
            'sections.*.zone' => 'present|nullable|string',
            'sections.*.color' => 'required|string',
            'tables.*.col' => 'required|integer|min:0',
            'tables.*.row' => 'required|integer|min:0',
            'tables.*.colSpan' => 'required|integer|min:1',
            'tables.*.rowSpan' => 'required|integer|min:1',
            'sections.*.col' => 'required|integer|min:0',
            'sections.*.row' => 'required|integer|min:0',
            'sections.*.colSpan' => 'required|integer|min:1',
            'sections.*.rowSpan' => 'required|integer|min:1',
            'tableLabel' => 'nullable|array',
            'tableLabel.prefix' => 'nullable|string|max:20',
            'tableLabel.showCapacity' => 'boolean',
            'tableLabel.showOrderNumber' => 'boolean',
            'tableLabel.showTimer' => 'boolean',
        ];
    }
}
