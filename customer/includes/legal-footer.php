<style>
    .customer-page-shell > .main-footer.customer-footer,
    .customer-footer {
        flex: 0 0 auto;
        padding: 6px 12px max(6px, env(safe-area-inset-bottom));
        background: #173d32;
        color: #d3ddce;
        text-align: center;
        font-size: 12px;
        line-height: 1.4;
    }
    .customer-footer .footer-content {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 4px 20px;
    }
    .customer-footer .footer-content p { margin: 0; }
    .customer-footer a { color: inherit; text-decoration: none; }
    .customer-footer a:is(:hover, :focus-visible) { text-decoration: underline; }
    @media (max-width: 768px) {
        .customer-footer .footer-content { flex-direction: column; gap: 2px; }
        .customer-footer { font-size: 11px; }
    }
    @media print {
        .customer-footer { display: none; }
    }
</style>
<footer class="main-footer customer-footer">
    <div class="footer-content">
        <p>&copy; <?php echo date('Y'); ?> Catering Services Portal System. All rights reserved.</p>
        <a href="../terms.php">Terms &amp; Conditions</a>
    </div>
</footer>
