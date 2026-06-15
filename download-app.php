<?php
require_once 'includes/security.php';
secureSessionStart();

$apkRelativePath = 'assets/downloads/reclaim-user-app-v1.1.1.apk';
$apkAbsolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $apkRelativePath);
$apkUrl = app_url_path($apkRelativePath);
$apkVersion = '1.1.1';
$apkUpdatedAt = file_exists($apkAbsolutePath) ? date('F j, Y g:i A', filemtime($apkAbsolutePath)) : null;
$apkSizeMb = file_exists($apkAbsolutePath) ? round(filesize($apkAbsolutePath) / 1048576, 2) : null;

require_once 'includes/header.php';
?>

<style>
    .download-shell {
        padding: clamp(24px, 4vw, 48px) 0 48px;
    }

    .download-card {
        background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), rgba(255, 248, 238, 0.98));
        border: 1px solid rgba(255, 140, 0, 0.18);
        border-radius: 24px;
        box-shadow: 0 20px 50px rgba(17, 24, 39, 0.08);
        overflow: hidden;
    }

    .download-hero {
        background: linear-gradient(135deg, #ffb000 0%, #ff7b00 100%);
        color: #fff;
        padding: clamp(24px, 5vw, 42px);
        position: relative;
    }

    .download-hero::after {
        content: '';
        position: absolute;
        inset: auto -80px -120px auto;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.28) 0%, rgba(255, 255, 255, 0) 72%);
    }

    .download-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        color: #fff;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        margin-bottom: 16px;
    }

    .download-hero h1 {
        color: #fff;
        font-size: clamp(2rem, 5vw, 3rem);
        font-weight: 800;
        margin-bottom: 12px;
    }

    .download-hero p {
        color: rgba(255, 255, 255, 0.92);
        font-size: 0.98rem;
        margin: 0;
        max-width: 640px;
    }

    .download-body {
        padding: clamp(24px, 4vw, 40px);
    }

    .download-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .download-meta-card {
        background: rgba(255, 255, 255, 0.8);
        border: 1px solid rgba(255, 140, 0, 0.14);
        border-radius: 18px;
        padding: 16px 18px;
    }

    .download-meta-label {
        display: block;
        color: #7b8794;
        font-size: 0.74rem;
        font-weight: 700;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .download-meta-value {
        color: #1f2933;
        font-size: 1rem;
        font-weight: 700;
    }

    .download-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin: 24px 0 18px;
    }

    .download-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        min-height: 52px;
        padding: 0 22px;
        border-radius: 999px;
        font-weight: 700;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .download-btn:hover {
        transform: translateY(-1px);
    }

    .download-btn-primary {
        background: linear-gradient(135deg, #ff9a1f 0%, #ff6b00 100%);
        color: #fff;
        box-shadow: 0 12px 24px rgba(255, 107, 0, 0.22);
    }

    .download-btn-primary:hover,
    .download-btn-primary:focus {
        color: #fff;
    }

    .download-btn-secondary {
        background: #fff;
        color: #ff6b00;
        border: 1px solid rgba(255, 140, 0, 0.2);
    }

    .download-steps {
        margin: 0;
        padding-left: 18px;
        color: #52606d;
    }

    .download-steps li + li {
        margin-top: 10px;
    }

    .download-note {
        margin-top: 20px;
        padding: 14px 16px;
        border-radius: 16px;
        background: rgba(255, 140, 0, 0.08);
        color: #52606d;
        font-size: 0.84rem;
    }

    .download-unavailable {
        border: 1px solid rgba(231, 76, 60, 0.18);
        background: rgba(231, 76, 60, 0.06);
        border-radius: 16px;
        color: #8a2d22;
        padding: 16px 18px;
        font-weight: 600;
    }
</style>

<main class="page-shell">
    <div class="container download-shell">
        <div class="download-card">
            <section class="download-hero">
                <div class="download-badge">
                    <i class="fas fa-mobile-alt"></i>
                    Android APK Download
                </div>
                <h1>Install the latest Reclaim mobile app</h1>
                <p>Download the newest Android build directly to your phone and install it from this local Reclaim server.</p>
            </section>

            <section class="download-body">
                <?php if (file_exists($apkAbsolutePath)): ?>
                    <div class="download-meta">
                        <div class="download-meta-card">
                            <span class="download-meta-label">Version</span>
                            <span class="download-meta-value"><?= htmlspecialchars($apkVersion, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="download-meta-card">
                            <span class="download-meta-label">File Size</span>
                            <span class="download-meta-value"><?= htmlspecialchars(number_format((float) $apkSizeMb, 2), ENT_QUOTES, 'UTF-8') ?> MB</span>
                        </div>
                        <div class="download-meta-card">
                            <span class="download-meta-label">Updated</span>
                            <span class="download-meta-value"><?= htmlspecialchars($apkUpdatedAt, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <div class="download-actions">
                        <a class="download-btn download-btn-primary" href="<?= htmlspecialchars($apkUrl, ENT_QUOTES, 'UTF-8') ?>">
                            <i class="fas fa-download"></i>
                            Download APK
                        </a>
                        <a class="download-btn download-btn-secondary" href="<?= htmlspecialchars($apkUrl, ENT_QUOTES, 'UTF-8') ?>" download>
                            <i class="fas fa-file-arrow-down"></i>
                            Direct File Link
                        </a>
                    </div>

                    <ol class="download-steps">
                        <li>Open this page on the Android phone connected to the same Wi-Fi or local network as this server.</li>
                        <li>Tap <strong>Download APK</strong> and allow the browser to keep the file if Android warns about unknown apps.</li>
                        <li>Open the downloaded APK and allow installs from that browser if Android asks for permission.</li>
                    </ol>

                    <div class="download-note">
                        This build connects to the local API server on <strong>10.141.31.244</strong>, so the phone needs network access to this machine while using the app.
                    </div>
                <?php else: ?>
                    <div class="download-unavailable">
                        The latest APK is not available in the public downloads folder yet. Rebuild or republish the Android release and refresh this page.
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<?php require_once 'includes/footer.php'; ?>
