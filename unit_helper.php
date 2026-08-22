<?php
// Canonical measurement units, grouped by family. Recipe quantities and ingredient stock
// can be expressed in different units of the same family (e.g. a recipe needs 100 Milliliters
// of Milk while the stock is tracked in Liters) — convert_quantity() converts between them so
// stock deductions at checkout are always correct regardless of which unit was chosen where.
// 'factor' is relative to that family's base unit (grams, milliliters, or pieces).
const UNIT_DEFINITIONS = [
    'g'     => ['family' => 'mass',   'factor' => 1,    'label' => 'Grams (g)'],
    'kg'    => ['family' => 'mass',   'factor' => 1000, 'label' => 'Kilograms (kg)'],
    'ml'    => ['family' => 'volume', 'factor' => 1,    'label' => 'Milliliters (ml)'],
    'l'     => ['family' => 'volume', 'factor' => 1000, 'label' => 'Liters (L)'],
    'cup'   => ['family' => 'volume', 'factor' => 240,  'label' => 'Cups'],
    'tbsp'  => ['family' => 'volume', 'factor' => 15,   'label' => 'Tablespoons'],
    'tsp'   => ['family' => 'volume', 'factor' => 5,    'label' => 'Teaspoons'],
    'piece' => ['family' => 'count',  'factor' => 1,    'label' => 'Pieces'],
    'pair'  => ['family' => 'count',  'factor' => 2,    'label' => 'Pairs'],
    'dozen' => ['family' => 'count',  'factor' => 12,   'label' => 'Dozens'],
    'slice' => ['family' => 'count',  'factor' => 1,    'label' => 'Slices'],
    'strip' => ['family' => 'count',  'factor' => 1,    'label' => 'Strips'],
];

const UNIT_FAMILY_LABELS = [
    'mass' => 'Weight',
    'volume' => 'Volume',
    'count' => 'Count',
];

// Maps free-typed or legacy unit text (e.g. "Liters", "PCS", "Gram") to its canonical key.
// Returns null if the text isn't a recognized unit.
function normalize_unit_key(string $raw): ?string
{
    $key = strtolower(trim($raw));
    $key = rtrim($key, '.');

    if (isset(UNIT_DEFINITIONS[$key])) {
        return $key;
    }

    $aliases = [
        'gram' => 'g', 'grams' => 'g', 'gm' => 'g', 'gms' => 'g',
        'kilogram' => 'kg', 'kilograms' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg',
        'milliliter' => 'ml', 'milliliters' => 'ml', 'millilitre' => 'ml', 'millilitres' => 'ml',
        'liter' => 'l', 'liters' => 'l', 'litre' => 'l', 'litres' => 'l',
        'cups' => 'cup',
        'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp',
        'teaspoon' => 'tsp', 'teaspoons' => 'tsp',
        'pc' => 'piece', 'pcs' => 'piece', 'piece' => 'piece', 'pieces' => 'piece',
        'pairs' => 'pair',
        'dozens' => 'dozen', 'dz' => 'dozen',
        'slices' => 'slice',
        'strips' => 'strip',
    ];

    return $aliases[$key] ?? null;
}

function unit_family(string $unitKey): ?string
{
    return UNIT_DEFINITIONS[$unitKey]['family'] ?? null;
}

function unit_label(string $unitKey): string
{
    return UNIT_DEFINITIONS[$unitKey]['label'] ?? $unitKey;
}

// Returns [family => [['key' => ..., 'label' => ...], ...]] for building grouped unit dropdowns.
function unit_options_grouped(): array
{
    $out = [];
    foreach (UNIT_DEFINITIONS as $key => $def) {
        $out[$def['family']][] = ['key' => $key, 'label' => $def['label']];
    }
    return $out;
}

// Converts $qty from $fromUnit to $toUnit. Both must be recognized units of the same family
// (e.g. can't convert Grams to Milliliters — there's no fixed ratio between mass and volume).
// Throws RuntimeException on unrecognized units or a family mismatch, since either means the
// ingredient's stock can't be reliably deducted.
function convert_quantity(float $qty, string $fromUnit, string $toUnit): float
{
    $fromKey = normalize_unit_key($fromUnit);
    $toKey = normalize_unit_key($toUnit);

    if ($fromKey === null) {
        throw new RuntimeException('Unrecognized unit "' . $fromUnit . '".');
    }
    if ($toKey === null) {
        throw new RuntimeException('Unrecognized unit "' . $toUnit . '".');
    }
    if ($fromKey === $toKey) {
        return $qty;
    }

    $fromDef = UNIT_DEFINITIONS[$fromKey];
    $toDef = UNIT_DEFINITIONS[$toKey];
    if ($fromDef['family'] !== $toDef['family']) {
        throw new RuntimeException('Cannot convert "' . $fromUnit . '" to "' . $toUnit . '" — incompatible unit types.');
    }

    return $qty * $fromDef['factor'] / $toDef['factor'];
}
