<?php if (basename($_SERVER['PHP_SELF']) != 'login.php'): ?>
    </div> <!-- End Main Content -->
</div> <!-- End App Container -->
<?php endif; ?>

<!-- Core Scripts -->
<script src="assets/script.js"></script>

<!-- Chart.js for Dashboard -->
<?php if (basename($_SERVER['PHP_SELF']) == 'dashboard.php'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>

<!-- Global Custom Modal - Handled by custom-modals.js -->
<script>
    // Custom modal initialization if needed, 
    // otherwise custom-modals.js handles everything.
</script>
</body>
</html>
