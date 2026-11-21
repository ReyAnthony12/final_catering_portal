    <?php if (!empty($compact_workspace_enabled)): ?>
        </div> <!-- End scrollable workspace -->
        <link rel="stylesheet" href="../assets/css/caterer-compact-workspace.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/caterer-compact-workspace.css'); ?>">
    <?php endif; ?>
    </div> <!-- End Main Content -->

    <footer class="main-footer">
        <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
            <div class="footer-content" style="text-align: center; color: var(--text-muted);">
                <p>&copy; <?php echo date('Y'); ?> Catering Services Portal System. All rights reserved.</p>
                <div class="footer-links">
                    <a href="../terms.php" style="color: var(--text-muted); text-decoration: none; margin: 0 10px; transition: color 0.2s;" onmouseover="this.style.color='#d4af37'" onmouseout="this.style.color='#a0a0a0'">
                        <i class="fas fa-file-contract"></i> Terms & Conditions
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <style>
        /* Keep the shared footer after the full-height workspace. */
        html:has(body.caterer-page:not(.store-sidebar-open)) {
            height: auto;
            overflow-y: auto !important;
        }
        html > body.caterer-page:not(.store-sidebar-open) {
            overflow-x: clip !important;
            overflow-y: visible !important;
        }
        body.caterer-page {
            height: auto;
            grid-template-rows: 100dvh auto;
        }
        body.caterer-page > .main-content {
            box-sizing: border-box;
            min-height: 100dvh !important;
            flex-shrink: 0;
        }
        body.caterer-page > .main-footer {
            flex: 0 0 auto;
        }
        .main-footer {
            background: #000;
            padding: 6px 0;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
            margin-left: 260px;
            transition: margin-left 0.3s ease;
        }
        .main-footer .footer-content {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 4px 20px;
            font-size: 12px;
            line-height: 1.4;
        }
        .main-footer .footer-content p { margin: 0; }
        .main-footer .footer-content a { font-size: inherit; }
        @media (max-width: 992px) {
            .main-footer { margin-left: 0; }
        }
    </style>

    <!-- Shared Scripts -->
    <script>
        (function () {
            const formatWholePesoAmounts = (root) => {
                const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
                let node;

                while ((node = walker.nextNode())) {
                    const parent = node.parentElement;
                    if (!parent || ['SCRIPT', 'STYLE', 'TEXTAREA'].includes(parent.tagName)) {
                        continue;
                    }

                    node.nodeValue = node.nodeValue.replace(/((?:₱|PHP\s*)\s*-?[\d,]+)\.00\b/g, '$1');
                }
            };

            document.addEventListener('DOMContentLoaded', function () {
                formatWholePesoAmounts(document.body);
                new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        mutation.addedNodes.forEach((node) => {
                            if (node.nodeType === Node.ELEMENT_NODE) formatWholePesoAmounts(node);
                            if (node.nodeType === Node.TEXT_NODE && node.parentElement) formatWholePesoAmounts(node.parentElement);
                        });
                    });
                }).observe(document.body, { childList: true, subtree: true });
            });
        }());


    </script>
</body>
</html>
