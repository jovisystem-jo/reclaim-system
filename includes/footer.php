<?php $embedded_layout = defined('RECLAIM_EMBEDDED_LAYOUT') && RECLAIM_EMBEDDED_LAYOUT; ?>
<footer class="footer">
    <div class="container">
        <div class="footer-container">
            <div class="row align-items-start">
                <div class="col-lg-4 col-md-6">
                    <h5><i class="fas fa-recycle me-2"></i>Reclaim System</h5>
                    <p class="mb-0">Helping you reconnect with your lost belongings since 2026.</p>
                </div>
                <div class="col-lg-4 col-md-6">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="/reclaim-system/"><i class="fas fa-home me-2"></i>Home</a></li>
                        <li><a href="/reclaim-system/search.php"><i class="fas fa-search me-2"></i>Search Items</a></li>
                        <li><a href="/reclaim-system/contact-us.php"><i class="fas fa-envelope me-2"></i>Contact Us</a></li>
                        <?php if(!isset($_SESSION['userID'])): ?>
                            <li><a href="/reclaim-system/register.php">Register</a></li>
                            <li><a href="/reclaim-system/login.php">Login</a></li>
                        <?php else: ?>
                            <li><a href="/reclaim-system/logout.php">Logout</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h5>Contact</h5>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-envelope me-2"></i>reclaim.rsystem@gmail.com</li>
                        <li><i class="fas fa-phone me-2"></i>+6011 3659 1855</li>
                    </ul>
                </div>
            </div>
            <hr>
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <p class="mb-0">&copy; <?= date('Y') ?> Reclaim System. All rights reserved.</p>
                <p class="mb-0">Lost and found made easier.</p>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/reclaim-system/assets/js/main.js"></script>
<?php if (!$embedded_layout): ?>
</body>
</html>
<?php endif; ?>
