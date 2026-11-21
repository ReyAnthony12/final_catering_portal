    <!-- Fixed Footer - FIXED VERSION -->
    <footer class="main-footer">
        <div class="container">
            <div class="footer-content">
                <?php
                // FIXED: Use a new variable name for footer settings query
                $footer_settings_query = "SELECT * FROM system_settings";
                $footer_settings_result = mysqli_query($conn, $footer_settings_query);
                $footer_settings = [];
                if ($footer_settings_result) {
                    while ($row = mysqli_fetch_assoc($footer_settings_result)) {
                        $footer_settings[$row['setting_key']] = $row['setting_value'];
                    }
                }
                ?>
                <div class="footer-section">
                    <?php
                    $footer_email = $footer_settings['contact_email'] ?? 'info@cateringportal.com';
                    $footer_phone = $footer_settings['contact_phone'] ?? '+63 912 345 6789';
                    ?>
                    <p><a href="mailto:<?php echo htmlspecialchars($footer_email, ENT_QUOTES, 'UTF-8'); ?>"><span><?php echo htmlspecialchars($footer_email, ENT_QUOTES, 'UTF-8'); ?></span></a></p>
                </div>
                <div class="footer-section">
                    <p><span><?php echo htmlspecialchars($footer_settings['location'] ?? 'Ipil, Zamboanga Sibugay', ENT_QUOTES, 'UTF-8'); ?></span></p>
                </div>
                <div class="footer-section">
                    <p><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^+0-9]/', '', $footer_phone), ENT_QUOTES, 'UTF-8'); ?>"><span><?php echo htmlspecialchars($footer_phone, ENT_QUOTES, 'UTF-8'); ?></span></a></p>
                </div>
                <div class="footer-section">
                    <p><a href="terms.php"><span>Terms & Conditions</span></a></p>
                </div>
                <p class="copyright"><?php echo $footer_settings['footer_text'] ?? '© 2024 Catering Services Portal System. All rights reserved.'; ?></p>
            </div>
        </div>
    </footer>

    <script>
        (() => {
            const footerLines = document.querySelectorAll('.main-footer .footer-section:nth-child(1) p, .main-footer .footer-section:nth-child(2) p');
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            if (!context) return;

            const fitFooterLines = () => {
                footerLines.forEach(line => {
                    const style = getComputedStyle(line);
                    const maxFontSize = line.parentElement.matches('.footer-section:nth-child(2)') ? 10 : 13;
                    context.font = `${style.fontWeight} ${maxFontSize}px ${style.fontFamily}`;
                    const textWidth = context.measureText(line.textContent.trim()).width;
                    const availableWidth = line.clientWidth;
                    if (!textWidth || !availableWidth) return;
                    line.style.fontSize = `${Math.min(maxFontSize, maxFontSize * (availableWidth - 2) / textWidth)}px`;
                });
            };

            const observer = new ResizeObserver(fitFooterLines);
            footerLines.forEach(line => observer.observe(line));
            fitFooterLines();
            document.fonts.ready.then(fitFooterLines);
        })();
    </script>

