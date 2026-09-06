<?php
// footer.php - Common footer for all pages
?>
    <footer>
        <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> <?php echo trans('footer', ['year' => date('Y')]); ?></p>
        </div>
    </footer>

    <script src="assets/js/menu.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <script>
    function changeLanguage(lang) {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('lang', lang);
        window.location.href = currentUrl.toString();
    }

    // Mobile menu functionality
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.getElementById('menuToggle');
        const mainNav = document.getElementById('mainNav');
        const navOverlay = document.getElementById('navOverlay');

        if (menuToggle && mainNav) {
            menuToggle.addEventListener('click', function() {
                mainNav.classList.toggle('active');
                navOverlay.classList.toggle('active');
                document.body.classList.toggle('menu-open');
            });

            navOverlay.addEventListener('click', function() {
                mainNav.classList.remove('active');
                navOverlay.classList.remove('active');
                document.body.classList.remove('menu-open');
            });

            // Close menu when clicking on nav links
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function() {
                    mainNav.classList.remove('active');
                    navOverlay.classList.remove('active');
                    document.body.classList.remove('menu-open');
                });
            });
        }
    });
    </script>
</body>
</html>