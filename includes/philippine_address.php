<?php
// Validate the selected hierarchy and build the address stored by registration.
function buildRegistrationAddress(array $input): array {
    $data = json_decode(file_get_contents(__DIR__ . '/../assets/data/ph-addresses.json'), true);
    if (!is_array($data)) return ['error' => 'Address choices are unavailable. Please try again.'];
    $selected = [];
    foreach (['regions' => 'address_region', 'cities' => 'address_city', 'barangays' => 'address_barangay'] as $group => $field) {
        $code = $input[$field] ?? '';
        if (!is_string($code)) return ['error' => 'Please select a valid address.'];
        foreach ($data[$group] as $item) {
            if ($item['code'] === $code) { $selected[$group] = $item; break; }
        }
    }
    if (count($selected) !== 3) return ['error' => 'Please select your region, province, city / municipality, and barangay.'];
    $provinceCode = $input['address_province'] ?? '';
    $province = null;
    if (is_string($provinceCode)) {
        foreach ($data['provinces'] as $item) {
            if ($item['code'] === $provinceCode) { $province = $item; break; }
        }
    }
    $region = $selected['regions'];
    $city = $selected['cities'];
    $barangay = $selected['barangays'];
    $directCity = $city['parent'] === $region['code'];
    if ($city['region'] !== $region['code'] || $barangay['parent'] !== $city['code'] ||
        ($directCity ? $provinceCode !== 'none' : (!$province || $province['parent'] !== $region['code'] || $city['parent'] !== $province['code']))) {
        return ['error' => 'Please select a city and barangay belonging to your chosen region and province.'];
    }
    $parts = [$barangay['name'], $city['name']];
    if (!$directCity) $parts[] = $province['name'];
    $parts[] = $region['name'];
    $parts[] = 'Philippines';
    return ['address' => implode(', ', array_filter($parts, function ($part) { return $part !== ''; }))];
}
