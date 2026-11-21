    </div> <!-- End Main Content -->

    <?php require __DIR__ . '/footer-bar.php'; ?>

    <!-- Footer Scripts -->
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

        console.log('Portal Admin Loaded');
    </script>
</body>
</html>
