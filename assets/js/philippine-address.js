(() => {
    'use strict';
    const picker = document.getElementById('ph-address-picker');
    if (!picker) return;
    const island = document.getElementById('address-island');
    const region = document.getElementById('address-region');
    const province = document.getElementById('address-province');
    const city = document.getElementById('address-city');
    const barangay = document.getElementById('address-barangay');
    const address = document.getElementById('address');
    const status = document.getElementById('address-status') || document.createElement('small');
    const retry = document.getElementById('address-retry');
    const url = new URL('../data/ph-addresses.json', document.currentScript.src);
    let data, loading = false;
    const islandRegions = {
        mindanao: ['090000000', '100000000', '110000000', '120000000', '150000000', '160000000'],
        visayas: ['060000000', '070000000', '080000000', '180000000'],
        luzon: ['010000000', '020000000', '030000000', '040000000', '050000000', '130000000', '140000000', '170000000']
    };
    function fill(select, items, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));
        items.slice().sort((a, b) => a.name.localeCompare(b.name)).forEach(item => select.add(new Option(item.name, item.code)));
        select.disabled = !items.length;
    }
    function label(select) { return select.value ? select.options[select.selectedIndex].text : ''; }
    function islandChanged() {
        if (!data) return;
        const codes = islandRegions[island.value];
        fill(region, codes ? data.regions.filter(item => codes.includes(item.code)) : data.regions, 'Select region');
        regionChanged();
    }
    function compose() {
        address.value = '';
        status.textContent = 'Choose your region, province, city / municipality, and barangay.';
        if (!region.value || !province.value || !city.value || !barangay.value) return;
        address.value = [label(barangay), label(city),
            province.value === 'none' ? '' : label(province), label(region), 'Philippines'].filter(Boolean).join(', ');
        status.textContent = 'Complete address: ' + address.value;
    }
    function regionChanged() {
        fill(province, [], 'Select a region first');
        fill(city, [], 'Select a province first');
        fill(barangay, [], 'Select a city first');
        if (region.value) {
            const items = data.provinces.filter(item => item.parent === region.value);
            if (data.cities.some(item => item.parent === region.value)) {
                items.push({ code: 'none', name: region.value === '130000000' ? 'Metro Manila (no province)' : 'No province / Independent city' });
            }
            fill(province, items, 'Select province');
            if (items.length === 1 && items[0].code === 'none') { province.value = 'none'; provinceChanged(); }
        }
        status.textContent = 'Choose your province, city / municipality, and barangay.';
        compose();
    }
    function provinceChanged() {
        fill(city, [], 'Select a province first');
        fill(barangay, [], 'Select a city first');
        if (province.value) fill(city, data.cities.filter(item => item.region === region.value && item.parent === (province.value === 'none' ? region.value : province.value)), 'Select city / municipality');
        compose();
    }
    function cityChanged() {
        fill(barangay, city.value ? data.barangays.filter(item => item.parent === city.value) : [], city.value ? 'Select barangay' : 'Select a city first');
        compose();
    }
    async function initialize() {
        if (loading) return;
        loading = true;
        picker.dataset.ready = 'false';
        retry.hidden = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(url, { signal: controller.signal });
            if (!response.ok) throw new Error('Address choices unavailable');
            data = await response.json();
            if (!['regions', 'provinces', 'cities', 'barangays'].every(key => Array.isArray(data[key]))) throw new Error('Invalid address data');
            const savedRegion = picker.dataset.region || '';
            island.value = picker.dataset.island !== undefined ? picker.dataset.island : (Object.keys(islandRegions).find(group => islandRegions[group].includes(savedRegion)) || '');
            islandChanged();
            region.value = picker.dataset.region || '';
            regionChanged();
            if (picker.dataset.province) province.value = picker.dataset.province;
            provinceChanged();
            city.value = picker.dataset.city || '';
            cityChanged();
            barangay.value = picker.dataset.barangay || '';
            compose();
            picker.dataset.ready = 'true';
            picker.dispatchEvent(new Event('address-ready', { bubbles: true }));
        } catch (error) {
            fill(region, [], 'Address choices unavailable');
            // Keep required validation active so an incomplete address cannot submit.
            region.disabled = false;
            status.textContent = 'Could not load address choices. Click Retry to load them again.';
            retry.hidden = false;
        } finally { clearTimeout(timeout); loading = false; }
    }
    island.addEventListener('change', islandChanged);
    region.addEventListener('change', regionChanged);
    province.addEventListener('change', provinceChanged);
    city.addEventListener('change', cityChanged);
    barangay.addEventListener('change', compose);
    retry.addEventListener('click', initialize);
    initialize();
})();
