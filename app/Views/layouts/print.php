<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? config('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>
  :root{color-scheme:light}
  body{background:#EBEEF2}
  .print-bar{max-width:720px;margin:16px auto 0;display:flex;justify-content:space-between;align-items:center;gap:10px;padding:0 16px}
  .paper-page{padding:16px}
  .paper dl{display:grid;grid-template-columns:120px 1fr;gap:6px 14px;margin:0;font-size:.9rem}
  .paper dt{color:#6b7280}.paper dd{margin:0}
  .paper section{display:flex;flex-direction:column;gap:8px}
  .paper h3{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;font-family:var(--sans)}
  .paper ol.disc{padding-left:22px;margin:0;display:flex;flex-direction:column;gap:10px}
  .paper ol.disc p{margin:3px 0 0;color:#4b5563;white-space:pre-wrap}
  .paper .pill{border:1px solid #d1d5db}
  .paper table.register,.paper table.att{width:100%;border-collapse:collapse}
  .paper table.register th,.paper table.att th{width:auto;text-transform:uppercase;letter-spacing:.06em;font-size:.68rem;padding:6px 8px;border-bottom:1.5px solid #111;text-align:left}
  .paper table.register td,.paper table.att td{padding:8px;border-bottom:1px solid #d1d5db;vertical-align:top}
  .paper table.register td.sig{width:28%;height:34px}
  .paper .letter-note{border-left:3px solid #1F6F78;padding:4px 0 4px 12px;white-space:pre-wrap;color:#1f2937}
  .paper .signs{display:flex;justify-content:space-between;gap:40px;margin-top:28px}
  .paper .signs.one{justify-content:flex-end}
  .paper .signs>div{flex:0 1 240px;font-size:.85rem;color:#374151}
  .paper .signs span{display:block;border-bottom:1px solid #111;height:36px;margin-bottom:6px}
  .paper p{margin:0}
  .paper.letter{gap:16px}
  .paper + .paper{margin-top:16px}
  @media print{
    body{background:#fff}
    .print-bar{display:none}
    .paper-page{padding:0}
    .paper{box-shadow:none;padding:0;max-width:none}
    .paper + .paper{margin-top:0}
    .page-break{break-before:page}
    @page{size:A4;margin:16mm}
  }
</style>
</head>
<body>
<div class="print-bar no-print">
  <a class="btn ghost sm" href="javascript:history.length>1?history.back():window.close()"><?= icon('back', 14) ?>Back</a>
  <button class="btn primary" type="button" onclick="window.print()"><?= icon('print', 15) ?>Print or save as PDF</button>
</div>
<div class="paper-page"><?= $content ?></div>
</body>
</html>
