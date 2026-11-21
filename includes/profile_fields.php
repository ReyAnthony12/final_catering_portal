<?php
require_once __DIR__ . '/philippine_address.php';

function profileAddressSelection(string $address): array {
    static $data;
    $data = $data ?? json_decode(file_get_contents(__DIR__ . '/../assets/data/ph-addresses.json'), true);
    $contains = static function ($name) use ($address) {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $address) === 1;
    };
    $provinces = array_column($data['provinces'], null, 'code');
    $matches = [];
    foreach ($data['cities'] as $city) {
        $province = $provinces[$city['parent']] ?? null;
        if (!$contains($city['name']) || ($province && !$contains($province['name']))) continue;
        foreach ($data['barangays'] as $barangay) {
            if ($barangay['parent'] === $city['code'] && $contains($barangay['name'])) {
                $matches[] = ['address_region' => $city['region'], 'address_province' => $province['code'] ?? 'none',
                    'address_city' => $city['code'], 'address_barangay' => $barangay['code']];
            }
        }
    }
    return count($matches) === 1 ? $matches[0] : [];
}

function validateProfileFields(array $input, string $existingAddress): array {
    foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name'] as $field => $label) {
        $value = $input[$field] ?? '';
        if (!is_string($value) || ($field !== 'middle_name' && trim($value) === '') ||
            ($value !== '' && !preg_match('/^[\p{L}\p{M} .\x{2019}\x{0027}-]{1,250}$/u', $value))) {
            return ['error' => $label . ' must contain letters only, with spaces or name punctuation. Numbers are not allowed.'];
        }
    }
    if (!is_string($input['phone_number'] ?? null) || !preg_match('/^[0-9]{11}$/D', $input['phone_number'])) {
        return ['error' => 'Phone number must contain exactly 11 digits, with no letters or symbols.'];
    }
    $fields = ['address_region','address_province','address_city','address_barangay'];
    $hasSelection = false;
    foreach ($fields as $field) if (!empty($input[$field])) $hasSelection = true;
    if (!$hasSelection && $existingAddress !== '') return ['address' => $existingAddress];
    $result = buildRegistrationAddress($input);
    if (isset($result['error'])) return $result;
    // Preserve street/purok details when the existing hierarchy was not changed.
    $saved = profileAddressSelection($existingAddress);
    if ($saved && array_reduce($fields, static function ($same, $field) use ($saved, $input) {
        return $same && ($saved[$field] === ($input[$field] ?? null));
    }, true)) return ['address' => $existingAddress];
    return $result;
}

function renderProfileAddressPicker(string $address): void {
    $selected = profileAddressSelection($address);
    $escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
    ?>
    <p class="profile-current-address">Current address: <?= $escape($address) ?></p>
    <div id="ph-address-picker" class="ph-address-picker" data-profile-address="<?= $escape($address) ?>"
         data-region="<?= $escape($selected['address_region'] ?? '') ?>" data-province="<?= $escape($selected['address_province'] ?? '') ?>"
         data-city="<?= $escape($selected['address_city'] ?? '') ?>" data-barangay="<?= $escape($selected['address_barangay'] ?? '') ?>">
        <div class="ph-address-islands"><label for="address-island">Island Group</label><select id="address-island" name="address_island"><option value="">All Philippines</option><option value="luzon">Luzon</option><option value="visayas">Visayas</option><option value="mindanao">Mindanao</option></select></div>
        <div><label for="address-region">Region</label><select id="address-region" name="address_region"><option value="">Loading regions…</option></select></div>
        <div><label for="address-province">Province</label><select id="address-province" name="address_province" disabled><option value="">Select a region first</option></select></div>
        <div><label for="address-city">City / Municipality</label><select id="address-city" name="address_city" disabled><option value="">Select a province first</option></select></div>
        <div><label for="address-barangay">Barangay</label><select id="address-barangay" name="address_barangay" disabled><option value="">Select a city first</option></select></div>
    </div>
    <input id="address" type="hidden" name="address" value="<?= $escape($address) ?>">
    <small id="address-status" class="ph-address-help">Select an address to replace your current address.</small>
    <button id="address-retry" type="button" hidden>Retry loading address choices</button>
    <?php
}
