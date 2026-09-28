<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e(($title ?? '') ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
<meta name="description" content="Plan meetings with a clear agenda, capture minutes and track every action item to done.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body>
<?= $content ?>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
