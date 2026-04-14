<footer class="footer mt-5">
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5><i class="fas fa-recycle"></i> Reclaim System</h5>
                <p>Helping you reconnect with your lost belongings since 2024.</p>
            </div>
            <div class="col-md-4">
                <h5>Quick Links</h5>
                <ul class="list-unstyled">
                    <li><a href="/reclaim-system/" class="text-white">Home</a></li>
                    <li><a href="/reclaim-system/search.php" class="text-white">Search Items</a></li>
                    <?php if(!isset($_SESSION['userID'])): ?>
                        <li><a href="/reclaim-system/register.php" class="text-white">Register</a></li>
                        <li><a href="/reclaim-system/login.php" class="text-white">Login</a></li>
                    <?php else: ?>
                        <li><a href="/reclaim-system/logout.php" class="text-white">Logout</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="col-md-4">
                <h5>Contact</h5>
                <ul class="list-unstyled">
                    <li><i class="fas fa-envelope"></i> support@reclaim.com</li>
                    <li><i class="fas fa-phone"></i> +1 234 567 8900</li>
                </ul>
            </div>
        </div>
        <hr class="bg-light">
        <div class="text-center">
            <p>&copy; 2025 Reclaim System. All rights reserved.</p>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/reclaim-system/assets/js/main.js"></script>
</body>
</html>