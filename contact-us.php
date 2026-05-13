<?php
require_once __DIR__ . '/includes/header.php';

$supportEmail = 'reclaim.rsystem@gmail.com';
$supportPhone = '+6011 3659 1855';
$dashboardLink = isset($_SESSION['userID']) ? $dashboard_page : $base_url . 'login.php';
$dashboardLabel = isset($_SESSION['userID']) ? 'Open Dashboard' : 'Login to Your Account';
?>

<style>
    .contact-shell {
        margin-top: 20px;
    }

    .contact-hero {
        position: relative;
        overflow: hidden;
        border-radius: 30px;
        padding: clamp(28px, 4vw, 42px);
        background:
            radial-gradient(circle at top right, rgba(255,255,255,0.22), transparent 28%),
            linear-gradient(135deg, #ff8c00 0%, #ff6b35 52%, #f4511e 100%);
        box-shadow: 0 24px 48px rgba(255, 107, 53, 0.18);
        color: #fff;
    }

    .contact-hero::after {
        content: '';
        position: absolute;
        inset: auto -8% -35% auto;
        width: 240px;
        height: 240px;
        background: rgba(255, 255, 255, 0.09);
        border-radius: 50%;
        filter: blur(2px);
    }

    .contact-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 14px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        color: #fff;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        margin-bottom: 16px;
    }

    .contact-hero-title {
        font-size: clamp(2rem, 3vw, 3.1rem);
        font-weight: 800;
        line-height: 1.08;
        margin-bottom: 14px;
        color: #fff;
        position: relative;
        z-index: 1;
    }

    .contact-hero-subtitle {
        max-width: 640px;
        font-size: 1rem;
        color: rgba(255, 255, 255, 0.92);
        margin-bottom: 22px;
        position: relative;
        z-index: 1;
    }

    .contact-hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        position: relative;
        z-index: 1;
    }

    .contact-hero-actions .btn {
        border-radius: 999px;
        padding: 11px 18px;
        font-weight: 700;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
    }

    .contact-hero-actions .btn-light {
        color: #f15b22;
    }

    .contact-summary-card {
        position: relative;
        z-index: 1;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 24px;
        padding: 22px;
        backdrop-filter: blur(6px);
    }

    .contact-summary-card h3 {
        color: #fff;
        font-size: 1.05rem;
        margin-bottom: 16px;
    }

    .contact-summary-item + .contact-summary-item {
        margin-top: 14px;
    }

    .contact-summary-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        color: rgba(255, 255, 255, 0.88);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
    }

    .contact-summary-value,
    .contact-summary-value a {
        color: #fff;
        font-size: 1rem;
        font-weight: 700;
        word-break: break-word;
    }

    .contact-grid {
        margin-top: 28px;
    }

    .contact-card {
        height: 100%;
        border: 1px solid rgba(255, 140, 0, 0.12);
        border-radius: 24px;
        background: rgba(255, 255, 255, 0.95);
        box-shadow: 0 14px 32px rgba(17, 24, 39, 0.08);
        padding: 24px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .contact-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 18px 36px rgba(17, 24, 39, 0.12);
    }

    .contact-card-icon {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 16px;
        font-size: 1.35rem;
        color: #fff;
        background: linear-gradient(135deg, #ff8c00 0%, #ff6b35 100%);
    }

    .contact-card h2 {
        font-size: 1.15rem;
        margin-bottom: 10px;
    }

    .contact-card p {
        font-size: 0.88rem;
        margin-bottom: 12px;
    }

    .contact-card a {
        font-weight: 700;
    }

    .contact-panel {
        margin-top: 28px;
        border-radius: 28px;
        padding: clamp(24px, 4vw, 34px);
        background: linear-gradient(180deg, rgba(255,255,255,0.96) 0%, rgba(255,248,238,0.98) 100%);
        border: 1px solid rgba(255, 140, 0, 0.12);
        box-shadow: 0 18px 40px rgba(17, 24, 39, 0.08);
    }

    .contact-panel h2 {
        font-size: 1.3rem;
        margin-bottom: 12px;
    }

    .contact-checklist {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        gap: 12px;
    }

    .contact-checklist li {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 14px 16px;
        border-radius: 18px;
        background: #fff;
        border: 1px solid rgba(255, 140, 0, 0.10);
    }

    .contact-checklist i {
        margin-top: 2px;
        color: #ff6b35;
    }

    .contact-side-card {
        height: 100%;
        border-radius: 22px;
        padding: 22px;
        background: #fff;
        border: 1px solid rgba(255, 140, 0, 0.12);
    }

    .contact-side-card h3 {
        font-size: 1rem;
        margin-bottom: 12px;
    }

    .contact-side-card .btn {
        width: 100%;
        margin-top: 10px;
        border-radius: 14px;
        font-weight: 700;
    }

    .contact-note {
        margin-top: 24px;
        padding: 18px 20px;
        border-radius: 20px;
        background: rgba(255, 140, 0, 0.08);
        border: 1px solid rgba(255, 140, 0, 0.14);
    }

    .contact-note p {
        margin: 0;
        font-size: 0.88rem;
    }

    @media (max-width: 767.98px) {
        .contact-hero {
            border-radius: 24px;
        }

        .contact-summary-card,
        .contact-card,
        .contact-panel,
        .contact-side-card {
            border-radius: 20px;
        }
    }
</style>

<main class="page-shell">
    <div class="container content-wrapper contact-shell">
        <section class="contact-hero">
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <span class="contact-badge"><i class="fas fa-life-ring"></i> Support Center</span>
                    <h1 class="contact-hero-title">Contact Us</h1>
                    <p class="contact-hero-subtitle">
                        Need help with a lost item report, a found item update, or a claim in progress? Reach the Reclaim team here and we’ll point you in the right direction.
                    </p>
                    <div class="contact-hero-actions">
                        <a href="mailto:<?= htmlspecialchars($supportEmail) ?>" class="btn btn-light">
                            <i class="fas fa-envelope me-2"></i>Email Support
                        </a>
                        <a href="<?= $base_url ?>search.php" class="btn btn-outline-light">
                            <i class="fas fa-search me-2"></i>Search Items
                        </a>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="contact-summary-card">
                        <h3><i class="fas fa-address-card me-2"></i>Reach Us Directly</h3>
                        <div class="contact-summary-item">
                            <div class="contact-summary-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="contact-summary-value">
                                <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a>
                            </div>
                        </div>
                        <div class="contact-summary-item">
                            <div class="contact-summary-label"><i class="fas fa-phone"></i> Phone</div>
                            <div class="contact-summary-value">
                                <a href="tel:+601136591855"><?= htmlspecialchars($supportPhone) ?></a>
                            </div>
                        </div>
                        <div class="contact-summary-item">
                            <div class="contact-summary-label"><i class="fas fa-compass"></i> Best For</div>
                            <div class="contact-summary-value">Report questions, claim follow-ups, and account assistance</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="contact-grid">
            <div class="row g-4">
                <div class="col-md-4">
                    <article class="contact-card">
                        <div class="contact-card-icon">
                            <i class="fas fa-envelope-open-text"></i>
                        </div>
                        <h2>Email the Team</h2>
                        <p>Use email when you need to share report details, claim information, or anything that needs a written follow-up.</p>
                        <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="contact-card">
                        <div class="contact-card-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <h2>Call Support</h2>
                        <p>Use the support line if you need a quicker response about an item handoff, verification step, or urgent update.</p>
                        <a href="tel:+601136591855"><?= htmlspecialchars($supportPhone) ?></a>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="contact-card">
                        <div class="contact-card-icon">
                            <i class="fas fa-route"></i>
                        </div>
                        <h2>Need a Faster Start?</h2>
                        <p>If you’re still looking for an item, the search page and your dashboard usually give the quickest next step.</p>
                        <a href="<?= $dashboardLink ?>"><?= htmlspecialchars($dashboardLabel) ?></a>
                    </article>
                </div>
            </div>
        </section>

        <section class="contact-panel">
            <div class="row g-4">
                <div class="col-lg-7">
                    <h2>Helpful Things to Include</h2>
                    <p>The more specific your message is, the easier it is to help you quickly.</p>
                    <ul class="contact-checklist">
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Item title or report details</strong><br>
                                Mention the item name, category, and whether it was reported as lost or found.
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Important dates and locations</strong><br>
                                Include where the item was last seen or found, plus the relevant date and approximate time.
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <strong>Your question or requested action</strong><br>
                                Let us know whether you need help with a claim, a report edit, or general account support.
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="col-lg-5">
                    <div class="contact-side-card">
                        <h3><i class="fas fa-bolt me-2" style="color:#ff6b35;"></i>Quick Actions</h3>
                        <p>Jump straight to the part of the system you need most.</p>
                        <a href="<?= $base_url ?>search.php" class="btn btn-outline-primary">
                            <i class="fas fa-search me-2"></i>Browse Search Page
                        </a>
                        <a href="<?= $base_url ?>user/report-item.php?type=lost" class="btn btn-outline-primary">
                            <i class="fas fa-frown me-2"></i>Report Lost Item
                        </a>
                        <a href="<?= $base_url ?>user/report-item.php?type=found" class="btn btn-outline-primary">
                            <i class="fas fa-smile me-2"></i>Report Found Item
                        </a>
                        <a href="<?= $dashboardLink ?>" class="btn btn-primary">
                            <i class="fas fa-arrow-right me-2"></i><?= htmlspecialchars($dashboardLabel) ?>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <div class="contact-note">
            <p><i class="fas fa-info-circle me-2" style="color:#ff6b35;"></i>If your message is about a specific item, include the item title or report details so the team can help more accurately.</p>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
