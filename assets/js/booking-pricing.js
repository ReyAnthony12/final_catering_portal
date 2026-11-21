window.BookingPricing = (function () {
    function toNumber(value) {
        if (value === null || value === undefined) {
            return 0;
        }
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : 0;
        }
        if (typeof value === 'string') {
            var cleaned = value.replace(/[^\d.-]/g, '').trim();
            var parsed = parseFloat(cleaned);
            return Number.isFinite(parsed) ? parsed : 0;
        }
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function roundMoney(value) {
        return Math.round((toNumber(value) + Number.EPSILON) * 100) / 100;
    }

    function normalizeAddonItems(addons) {
        if (!Array.isArray(addons)) {
            return [];
        }

        return addons
            .filter(function (addon) {
                return addon && typeof addon === 'object';
            })
            .map(function (addon) {
                var qty = parseInt(addon.qty, 10);
                if (!Number.isFinite(qty) || qty < 1) {
                    qty = 1;
                }

                var price = roundMoney(addon.price);
                return {
                    name: String(addon.name || '').trim(),
                    qty: qty,
                    price: price,
                    total_price: roundMoney(price * qty)
                };
            })
            .filter(function (addon) {
                return addon.name !== '';
            });
    }

    function normalizeSwapItems(swaps) {
        if (!Array.isArray(swaps)) {
            return [];
        }

        return swaps
            .filter(function (swap) {
                return swap && typeof swap === 'object';
            })
            .map(function (swap) {
                return {
                    original: String(swap.original || '').trim(),
                    replacement: String(swap.replacement || swap.new || '').trim(),
                    price: roundMoney(swap.price)
                };
            });
    }

    function calculate(options) {
        var quantity = Math.max(1, parseInt(options.quantity, 10) || 1);
        var selectedSetPrice = roundMoney(options.selectedSetPrice);
        var selectedSetPax = Math.max(0, parseInt(options.selectedSetPax, 10) || 0);
        var selectedSetPerHeadPrice = roundMoney(options.selectedSetPerHeadPrice);
        var fallbackUnitPrice = roundMoney(options.productPrice);
        var addons = normalizeAddonItems(options.addons || []);
        var swaps = normalizeSwapItems(options.swaps || []);
        var extraFee = roundMoney(options.extraFee || 0);

        var extraGuestRate = roundMoney(options.extraGuestRate);
        var pricingMode = selectedSetPerHeadPrice > 0 ? 'by_pax' : (selectedSetPrice > 0 ? 'package' : 'unit');
        var unitPrice = pricingMode === 'by_pax' ? selectedSetPerHeadPrice : fallbackUnitPrice;
        var extraGuestCount = 0;
        var extraGuestTotal = 0;

        var swapsPerUnit = swaps.reduce(function (sum, swap) {
            return roundMoney(sum + roundMoney(swap.price));
        }, 0);

        var addonsTotal = addons.reduce(function (sum, addon) {
            return roundMoney(sum + roundMoney(addon.total_price));
        }, 0);

        var basePrice;
        if (pricingMode === 'package') {
            if (selectedSetPax > 0 && quantity > selectedSetPax) {
                extraGuestCount = quantity - selectedSetPax;
                extraGuestTotal = roundMoney(extraGuestCount * extraGuestRate);
            }
            basePrice = roundMoney(selectedSetPrice + extraGuestTotal + swapsPerUnit);
        } else {
            // Swaps are a flat fee — only the per-head rate is multiplied by pax
            basePrice = roundMoney(unitPrice * quantity + swapsPerUnit);
        }
        var totalAmount = roundMoney(basePrice + addonsTotal + extraFee);

        return {
            quantity: quantity,
            pricingMode: pricingMode,
            pricePerUnit: unitPrice,
            swapsPerUnit: swapsPerUnit,
            basePrice: basePrice,
            addonsTotal: addonsTotal,
            extraFee: extraFee,
            extraGuestCount: extraGuestCount,
            extraGuestRate: extraGuestRate,
            extraGuestTotal: extraGuestTotal,
            packagePrice: selectedSetPrice,
            totalAmount: totalAmount,
            downPaymentRequired: roundMoney(totalAmount * 0.5),
            normalizedAddons: addons,
            normalizedSwaps: swaps
        };
    }

    return {
        calculate: calculate,
        roundMoney: roundMoney
    };
})();
