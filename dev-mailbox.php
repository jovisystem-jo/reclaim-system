<?php
require_once __DIR__ . '/includes/security.php';

if (function_exists('app_is_production') && app_is_production()) {
    http_response_code(404);
    exit('Not found');
}

$previewDir = __DIR__ . '/storage/mail-previews';
$selectedId = trim((string) ($_GET['id'] ?? ''));
$messages = [];
$selectedMessage = null;

if ($selectedId !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $selectedId)) {
    $selectedId = '';
}

$previewFiles = glob($previewDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
usort($previewFiles, static function ($left, $right) {
    return filemtime($right) <=> filemtime($left);
});

foreach ($previewFiles as $previewFile) {
    $payload = json_decode((string) file_get_contents($previewFile), true);
    if (!is_array($payload)) {
        continue;
    }

    $messageId = basename($previewFile, '.json');
    $message = [
        'id' => $messageId,
        'to' => (string) ($payload['to'] ?? ''),
        'subject' => (string) ($payload['subject'] ?? '(No subject)'),
        'created_at' => (string) ($payload['created_at'] ?? ''),
        'html_body' => (string) ($payload['html_body'] ?? ''),
        'text_body' => (string) ($payload['text_body'] ?? ''),
    ];

    $messages[] = $message;

    if ($selectedId !== '' && $messageId === $selectedId) {
        $selectedMessage = $message;
    }
}

if ($selectedMessage === null && $messages) {
    $selectedMessage = $messages[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Development Mailbox - Reclaim System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #fff7ed 0%, #eff6ff 100%);
            color: #0f172a;
        }

        .mailbox-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 16px 48px;
        }

        .mailbox-panel {
            background: rgba(255, 255, 255, 0.92);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        .mailbox-header {
            padding: 24px 28px;
            background: linear-gradient(135deg, #facc15 0%, #f97316 100%);
            color: #172554;
        }

        .mailbox-list {
            max-height: 70vh;
            overflow-y: auto;
            border-right: 1px solid #e5e7eb;
        }

        .mailbox-link {
            display: block;
            padding: 16px 20px;
            color: inherit;
            text-decoration: none;
            border-bottom: 1px solid #eef2f7;
        }

        .mailbox-link:hover,
        .mailbox-link.active {
            background: #eff6ff;
        }

        .mailbox-body {
            padding: 24px 28px 32px;
        }

        .mailbox-preview {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #ffffff;
            padding: 20px;
        }

        .mailbox-preview-source {
            white-space: pre-wrap;
            word-break: break-word;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            font-size: 0.875rem;
        }

        @media (max-width: 991px) {
            .mailbox-list {
                max-height: none;
                border-right: 0;
                border-bottom: 1px solid #e5e7eb;
            }
        }
    </style>
</head>
<body>
    <main class="mailbox-shell">
        <div class="mailbox-panel">
            <div class="mailbox-header">
                <h1 class="h2 mb-2">Development Mailbox</h1>
                <p class="mb-0">Local preview emails captured when SMTP is unavailable on this machine.</p>
            </div>

            <div class="row g-0">
                <div class="col-lg-4">
                    <div class="mailbox-list">
                        <?php if (!$messages): ?>
                            <div class="p-4 text-muted">No preview emails yet.</div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <?php $isActive = $selectedMessage && $selectedMessage['id'] === $message['id']; ?>
                                <a
                                    class="mailbox-link<?= $isActive ? ' active' : '' ?>"
                                    href="dev-mailbox.php?id=<?= urlencode($message['id']) ?>"
                                >
                                    <div class="fw-bold mb-1"><?= htmlspecialchars($message['subject']) ?></div>
                                    <div class="small text-muted mb-1"><?= htmlspecialchars($message['to']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($message['created_at']) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="mailbox-body">
                        <?php if (!$selectedMessage): ?>
                            <div class="alert alert-light border">Select a preview email to open it.</div>
                        <?php else: ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h2 class="h4 mb-1"><?= htmlspecialchars($selectedMessage['subject']) ?></h2>
                                    <div class="text-muted">To: <?= htmlspecialchars($selectedMessage['to']) ?></div>
                                </div>
                                <div class="text-muted small"><?= htmlspecialchars($selectedMessage['created_at']) ?></div>
                            </div>

                            <div class="mailbox-preview mb-4">
                                <?= $selectedMessage['html_body'] ?>
                            </div>

                            <details>
                                <summary class="fw-semibold">Show plain-text preview</summary>
                                <div class="mailbox-preview-source mt-3"><?= htmlspecialchars($selectedMessage['text_body']) ?></div>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
