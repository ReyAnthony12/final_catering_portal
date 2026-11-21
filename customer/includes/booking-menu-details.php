<?php
// Shared initial and live menu presentation.
$menuChangeDaysLeft = (int) ceil((strtotime($booking['order_date']) - time()) / 86400);
$menuChangeBookingId = (int) $booking_id;
$menuChangeUsedResult = mysqli_query($conn, "SELECT id FROM messages WHERE order_id = $menuChangeBookingId AND sender_id = " . (int) $user_id . " AND (subject LIKE '%Menu Change Request%' OR message LIKE '%Requested Set:%') LIMIT 1");
$menuChangeAlreadyUsed = $menuChangeUsedResult && mysqli_num_rows($menuChangeUsedResult) > 0;
if (!isset($available_sets)) {
    $available_sets = [];
    $menuProductId = (int) $booking['product_id'];
    $menuSetsResult = mysqli_query($conn, "SELECT * FROM product_sets WHERE product_id = '$menuProductId' ORDER BY price ASC");
    if ($menuSetsResult) while ($menuSet = mysqli_fetch_assoc($menuSetsResult)) $available_sets[] = $menuSet;
}
?>
                <?php 
                // The current approved set takes priority over the original booking snapshot.
                $displayPackageName = !empty($setName) ? $setName : ($booking['package_name'] ?? ($booking['product_name'] ?? ''));
                $displayPackagePrice = $booking['package_price'] ?? null;
                foreach ($available_sets as $displaySet) {
                    if (strcasecmp(trim((string) $displaySet['set_name']), trim((string) $displayPackageName)) === 0) {
                        $displayPackagePrice = $displaySet['price'];
                        break;
                    }
                }
                if (!empty($displayPackageName)): 
                ?>
                <div class="package-summary-compact" style="background: rgba(255,255,255,0.03); padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 3px solid var(--accent-gold);">
                    <div style="font-size: 18px; font-weight: bold; color: #fff;"><?php echo htmlspecialchars($displayPackageName); ?></div>
                    <?php if ($displayPackagePrice !== null): ?>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Package Price: &#8369;<?php echo number_format((float) $displayPackagePrice, 2); ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($originalMenuGroups) || !empty($originalItems)): ?>
                <div style="margin-bottom: 25px;">
                    <div class="detail-label" style="margin-bottom: 10px; font-weight: bold; color: var(--text-main);">Package Inclusions</div>
                    <div class="package-inclusions-list">
                        <?php
                        $menuGroupsToShow = !empty($originalMenuGroups) ? $originalMenuGroups : [['label' => '', 'items' => $originalItems]];
                        foreach ($menuGroupsToShow as $menuGroup):
                        ?>
                        <div class="package-inclusion-row">
                        <?php
                            if (!empty($menuGroup['label'])):
                        ?>
                            <div class="package-category-label" style="padding: 10px 14px 4px; color: var(--accent-gold); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">
                                <?php echo htmlspecialchars($menuGroup['label']); ?>
                            </div>
                        <?php endif; ?>
                        <div class="package-inclusion-items">
                        <?php foreach (($menuGroup['items'] ?? []) as $item): ?>
                            <?php 
                                $isSwapped = false;
                                $replacedBy = '';
                                foreach ($swaps as $swapStr) {
                                    if (preg_match('/Swap\s+(.*?)\s+for\s+(.*?)\s+\(\+/i', $swapStr, $matches)) {
                                        $swapOrig = trim($matches[1]);
                                        $cleanItem = trim($item);
                                        $swapOrigBase = trim(preg_replace('/\s*\(.*?\)/', '', $swapOrig));
                                        $cleanItemBase = trim(preg_replace('/\s*\(.*?\)/', '', $cleanItem));
                                        if (strcasecmp($swapOrig, $cleanItem) === 0 || 
                                            ($swapOrigBase !== '' && $cleanItemBase !== '' && strcasecmp($swapOrigBase, $cleanItemBase) === 0) ||
                                            ($swapOrigBase !== '' && stripos($cleanItemBase, $swapOrigBase) !== false) ||
                                            ($cleanItemBase !== '' && stripos($swapOrigBase, $cleanItemBase) !== false)) {
                                            $isSwapped = true;
                                            $replacedBy = trim($matches[2]);
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <div class="menu-list-item<?php echo $isSwapped ? ' is-swapped' : ''; ?>">
                                <?php if ($isSwapped): ?>
                                    <div style="flex: 1;">
                                        <div style="text-decoration: line-through; color: var(--text-muted); font-size: 13px;">
                                            <span class="menu-icon"><i class="fas fa-times" style="color: var(--danger-color);"></i></span>
                                            <?php echo htmlspecialchars($item); ?>
                                        </div>
                                        <div class="package-dish-replacement">
                                            <i class="fas fa-exchange-alt"></i> Replaced by <strong><?php echo htmlspecialchars($replacedBy); ?></strong>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <span class="menu-icon"><i class="fas fa-check" style="color: var(--success-color);"></i></span>
                                        <span style="color: var(--text-main); font-weight: 500;"><?php echo htmlspecialchars($item); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add-ons Section -->
                <?php if (!empty($addons)): ?>
                <div style="margin-bottom: 25px;">
                    <div class="detail-label" style="margin-bottom: 10px; font-weight: bold; color: var(--text-main);">Extra Add-ons</div>
                    <div style="border: 1px solid rgba(33, 150, 243, 0.3); border-radius: 8px; overflow: hidden; background: rgba(33, 150, 243, 0.05);">
                        <?php foreach ($addons as $addon): ?>
                            <?php 
                                $price = 0;
                                if (preg_match('/\(\+?([\d,.]+)\)/', $addon, $matches)) {
                                    $price = floatval(str_replace(',', '', $matches[1]));
                                }
                                $display = preg_replace('/\(\+?.*?\)/', '', $addon);
                            ?>
                            <div class="menu-list-item" style="border-bottom-color: rgba(33, 150, 243, 0.1);">
                                <div>
                                    <span class="menu-icon"><i class="fas fa-plus-circle" style="color: #2196f3;"></i></span>
                                    <span style="color: var(--text-main);"><?php echo htmlspecialchars($display); ?></span>
                                </div>
                                <span style="color: var(--accent-gold); font-family: monospace;">+&#8369;<?php echo number_format($price, 2); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$menuChangeAlreadyUsed && !empty($available_sets) && $menuChangeDaysLeft > 5 && !in_array($current_status, ['completed', 'cancelled'], true)): ?>
                <div style="margin-bottom: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="toggleSetMenus()" style="border-color: var(--accent-gold); color: var(--accent-gold);">
                        <i class="fas fa-random"></i> Change Menu
                    </button>
                    <div id="available-set-menus" style="display: none; margin-top: 15px; border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden;">
                        <?php if (($menu_change_status ?? '') !== 'pending'): ?>
                        <form id="detailsMenuChangeForm" style="padding:14px;display:grid;gap:12px;" onsubmit="submitDetailsMenuChange(event)">
                            <input type="hidden" name="request_menu_change" value="1">
                            <input type="hidden" name="order_id" value="<?php echo (int) $booking_id; ?>">
                            <input type="hidden" name="store_id" value="<?php echo (int) $booking['store_id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <label for="detailsRequestedSet">Replace with</label>
                            <select id="detailsRequestedSet" name="requested_set" class="form-control" style="width:100%;min-width:0;min-height:44px;" required>
                                <option value="" disabled hidden selected>Select a new package</option>
                                <?php foreach ($available_sets as $choice): if (trim($choice['set_name']) === trim($setName)) continue; ?>
                                <option value="<?php echo htmlspecialchars($choice['set_name']); ?>"><?php echo htmlspecialchars($choice['set_name']); ?> — ₱<?php echo number_format((float) $choice['price'], 2); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit" style="min-height:44px;justify-content:center;">Send Change Menu Request</button>
                            <p id="detailsMenuChangeResult" role="status" style="margin:0;line-height:1.5;"></p>
                        </form>
                        <?php else: ?><p style="padding:14px;">Your menu change request is awaiting caterer review.</p><?php endif; ?>
                        <?php foreach ($available_sets as $available_set): ?>
                            <?php
                                $available_set_name = trim((string) ($available_set['set_name'] ?? ''));
                                if ($available_set_name === '') {
                                    continue;
                                }
                                $is_current_set = trim($available_set_name) === trim($setName);
                            ?>
                            <div style="padding: 15px; border-bottom: 1px solid #a9b6a1; background: <?php echo $is_current_set ? '#c0ceb7' : '#d4dbcd'; ?>;">
                                <div style="display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 8px;">
                                    <div>
                                        <div style="font-size: 16px; font-weight: 700; color: #173d32;overflow-wrap:anywhere;">
                                            <?php echo htmlspecialchars($available_set_name); ?>
                                            <?php if ($is_current_set): ?>
                                                <span style="display:inline-block;font-size:11px;margin-left:8px;padding:3px 8px;border-radius:999px;background:#173d32;color:#f0f1e6;">Current Set</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($available_set['pax'])): ?>
                                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">Good for <?php echo htmlspecialchars($available_set['pax']); ?> person</div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="color:#173d32;font-weight:700;white-space:nowrap;">
                                        &#8369;<?php echo number_format((float) ($available_set['price'] ?? 0), 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($customRequest)): ?>
                <div style="margin-top: 20px;">
                    <div class="detail-label" style="margin-bottom: 8px;">Special Requests / Notes</div>
                    <div style="padding: 15px; background: rgba(212, 175, 55, 0.05); border: 1px dashed var(--accent-gold); border-radius: 8px; color: var(--text-main); font-style: italic;">
                        "<?php echo htmlspecialchars($customRequest); ?>"
                    </div>
                </div>
                <?php endif; ?>
