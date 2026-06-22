<?php
/**
 * index.php — etokens token-cost audit (clean, stateless).
 * No sessions. No stored files. Each request is self-contained: parse -> (map?) -> analyze -> render.
 * The CSV rides through the column-mapping step in a hidden field (in memory), never touching disk.
 */
require __DIR__ . '/engine.php';

const MAX_BYTES = 10 * 1024 * 1024; // 10 MB
$audit = new TokenAudit(__DIR__ . '/pricing.json');

function parse_csv(string $csv): array
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $header = fgetcsv($fh) ?: [];
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) === 1 && trim((string)$r[0]) === '') continue;
        $rows[] = $r;
    }
    fclose($fh);
    return [$header, $rows];
}

$state = 'upload';      // upload | map | report | error
$error = '';
$report = null;
$header = [];
$csvRaw = '';
$detected = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        if (($_FILES['csv']['size'] ?? 0) > MAX_BYTES) {
            $state = 'error'; $error = 'That file is over 10 MB. Trim it to a single month and try again.';
        } elseif (strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $state = 'error'; $error = 'Upload a .csv usage export.';
        } else {
            $csvRaw = file_get_contents($_FILES['csv']['tmp_name']);
        }
    } elseif (!empty($_POST['carry'])) {
        $csvRaw = base64_decode($_POST['carry'], true) ?: '';
        if (strlen($csvRaw) > MAX_BYTES) { $state = 'error'; $error = 'That file is too large to process.'; $csvRaw = ''; }
    }

    if ($csvRaw !== '' && $state !== 'error') {
        [$header, $rows] = parse_csv($csvRaw);

        if (!empty($_POST['map']) && is_array($_POST['map'])) {
            $map = [];
            foreach ($_POST['map'] as $canon => $idx) {
                if ($idx !== '' && is_numeric($idx)) $map[$canon] = (int)$idx;
            }
        } else {
            $map = $audit->detectColumns($header);
        }

        if (!$audit->mappable($map)) {
            $state = 'map';
            $detected = $map;
        } else {
            $agg = $audit->aggregate($rows, $map);
            if (empty($agg['models'])) {
                $state = 'error'; $error = 'No usable rows found. Check that a model column and token columns are present.';
            } else {
                $report = $audit->analyze($agg);
                $state = 'report';
            }
        }
    } elseif ($state !== 'error') {
        $state = 'error'; $error = 'No file received. Choose a .csv export and run the audit.';
    }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function usd($n): string { return '$' . number_format((float)$n, 2); }
function ntok($n): string { return number_format((float)$n); }
$CANON_LABELS = [
    'model' => 'Model', 'input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens',
    'cached_tokens' => 'Cached tokens', 'cost' => 'Cost', 'count' => 'Requests',
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>etokens — token-cost audit</title>
<style>
@font-face{font-family:'Space Grotesk';src:url('/fonts/SpaceGrotesk.woff2') format('woff2');font-weight:400 700;font-display:swap}
@font-face{font-family:'Hanken Grotesk';src:url('/fonts/HankenGrotesk.woff2') format('woff2');font-weight:400 600;font-display:swap}
@font-face{font-family:'IBM Plex Mono';src:url('/fonts/IBMPlexMono.woff2') format('woff2');font-weight:400 600;font-display:swap}
:root{
  --ink:#0F1218; --panel:#181D26; --line:#262D39;
  --text:#E8EBF0; --muted:#8B95A6; --signal:#F5A524;
  --risk:#D9544B; --safe:#57B86B;
  --display:'Space Grotesk',ui-sans-serif,system-ui,sans-serif;
  --body:'Hanken Grotesk',ui-sans-serif,system-ui,sans-serif;
  --mono:'IBM Plex Mono',ui-monospace,'SFMono-Regular',Menlo,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--ink);color:var(--text);font-family:var(--body);font-size:16px;line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:880px;margin:0 auto;padding:48px 20px 96px}
.mono{font-family:var(--mono);font-variant-numeric:tabular-nums}
h1{font-family:var(--display);font-weight:700;font-size:30px;letter-spacing:-.01em;margin:0 0 4px}
.eyebrow{font-family:var(--mono);font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 24px}
.sub{color:var(--muted);margin:0 0 32px;max-width:60ch}
a{color:var(--signal)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:24px;margin:18px 0}
label.file{display:block;border:1px dashed var(--line);border-radius:12px;padding:40px 24px;text-align:center;background:var(--panel);cursor:pointer;transition:border-color .15s}
label.file:hover{border-color:var(--signal)}
input[type=file]{display:block;margin:14px auto 0;color:var(--muted)}
.btn{font-family:var(--display);font-weight:600;font-size:15px;background:var(--signal);color:#1a1206;border:0;border-radius:10px;padding:12px 22px;cursor:pointer;margin-top:18px}
.btn:focus-visible,label.file:focus-within,select:focus-visible{outline:2px solid var(--signal);outline-offset:2px}
.note{color:var(--muted);font-size:13px;margin-top:14px}
.meter{margin:6px 0 2px}
.meter .big{font-family:var(--mono);font-variant-numeric:tabular-nums;font-weight:600;font-size:64px;line-height:1;color:var(--signal);letter-spacing:-.02em}
.meter .unit{font-family:var(--mono);color:var(--muted);font-size:18px;margin-left:6px}
.meter .pct{font-family:var(--mono);color:var(--muted);font-size:14px;margin-top:8px}
.caveat{color:var(--muted);font-size:13px;margin-top:10px;border-left:2px solid var(--line);padding-left:12px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line)}
th{font-family:var(--mono);font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:400}
td.num,th.num{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums}
.finding{margin:14px 0;padding:18px;border:1px solid var(--line);border-radius:10px}
.finding h3{font-family:var(--display);font-size:17px;margin:0 0 6px;display:flex;justify-content:space-between;gap:12px;align-items:baseline}
.drain{font-family:var(--mono);color:var(--signal);font-variant-numeric:tabular-nums}
.tags{display:flex;gap:8px;margin:8px 0 0;flex-wrap:wrap}
.tag{font-family:var(--mono);font-size:11px;letter-spacing:.06em;padding:2px 8px;border-radius:5px;border:1px solid var(--line);color:var(--muted)}
.tag.risk{color:var(--risk);border-color:var(--risk)}
.tag.safe{color:var(--safe);border-color:var(--safe)}
.tag.eval{color:var(--signal);border-color:var(--signal)}
.bar{height:6px;background:var(--line);border-radius:3px;overflow:hidden;margin-top:8px}
.bar>span{display:block;height:100%;background:var(--signal)}
.foot{color:var(--muted);font-size:13px;margin-top:36px;font-family:var(--mono)}
.map-row{display:flex;gap:12px;align-items:center;margin:10px 0}
.map-row .lbl{width:140px;color:var(--muted);font-size:14px}
select{background:var(--ink);color:var(--text);border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-family:var(--mono);font-size:13px;flex:1}
.errbox{border-left:2px solid var(--risk);padding-left:14px}
@media (prefers-reduced-motion:no-preference){.meter .big{animation:rise .5s ease-out both}}
@keyframes rise{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<div class="wrap">
  <p class="eyebrow">etokens · token-cost audit</p>

<?php if ($state === 'upload' || $state === 'error'): ?>
  <h1>Find the money in your token bill.</h1>
  <p class="sub">Drop in a usage export from OpenAI, Anthropic, or OpenRouter. You get a spend map, a ranked list of where it leaks, and a recoverable-spend estimate. Processed in memory — nothing stored.</p>
  <?php if ($state === 'error'): ?>
    <div class="card"><div class="errbox"><strong>Couldn't run that.</strong><br><?= h($error) ?></div></div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <label class="file">
      <strong>Choose a .csv usage export</strong>
      <input type="file" name="csv" accept=".csv" required>
      <div class="note">Aggregated or per-request. We auto-detect the format; if it's unfamiliar, you'll map the columns yourself.</div>
    </label>
    <button class="btn" type="submit">Run the audit</button>
  </form>

<?php elseif ($state === 'map'): ?>
  <h1>Map your columns.</h1>
  <p class="sub">We couldn't recognize this export's headers. Point each field at the right column and run it.</p>
  <form method="post">
    <input type="hidden" name="carry" value="<?= h(base64_encode($csvRaw)) ?>">
    <div class="card">
      <?php foreach (['model','input_tokens','output_tokens','cached_tokens','cost','count'] as $canon): ?>
        <div class="map-row">
          <span class="lbl"><?= h($CANON_LABELS[$canon]) ?><?= $canon==='model' ? ' *' : '' ?></span>
          <select name="map[<?= $canon ?>]">
            <option value="">— none —</option>
            <?php foreach ($header as $i => $col): ?>
              <option value="<?= $i ?>" <?= (isset($detected[$canon]) && $detected[$canon]===$i) ? 'selected':'' ?>><?= h($col) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
      <div class="note">Map <em>model</em> and at least one of input / output tokens. Cost is optional — we compute it from the rate card if it's missing.</div>
    </div>
    <button class="btn" type="submit">Run the audit</button>
  </form>

<?php elseif ($state === 'report' && $report): ?>
  <?php
    $ocs   = max(0.0, min(1.0, (float)$report['output_cost_share'])) * 100; // true share, clamped 0–100
    $ratio = (float)$report['output_input_ratio'];
  ?>
  <h1>Audit report.</h1>
  <div class="card">
    <div class="meter">
      <span class="big mono"><?= usd($report['headline_save']) ?></span><span class="unit">/period recoverable</span>
      <div class="pct"><?= number_format($report['headline_pct']*100,1) ?>% of <?= usd($report['total']) ?> analyzed spend · model-downshift ceiling</div>
    </div>
    <div class="caveat">This is the ceiling, not a guarantee. Realizing it means moving tolerant traffic to a cheaper model — gate every downgrade behind a 50–500 case eval so quality doesn't silently regress.</div>
  </div>

  <div class="card">
    <h3 style="font-family:var(--display);margin:0 0 12px">Where the bill concentrates</h3>
    <table>
      <thead><tr><th>Model</th><th class="num">Input</th><th class="num">Output</th><th class="num">Cost</th><th class="num">Share</th></tr></thead>
      <tbody>
      <?php foreach ($report['spend_rows'] as $r): ?>
        <tr>
          <td class="mono"><?= h($r['model']) ?><?= $r['priced'] ? '' : ' <span class="tag">unpriced</span>' ?></td>
          <td class="num"><?= ntok($r['input']) ?></td>
          <td class="num"><?= ntok($r['output']) ?></td>
          <td class="num"><?= usd($r['cost']) ?></td>
          <td class="num"><?= number_format($r['share']*100,1) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="finding">
    <h3><span>Frontier model on tolerant work</span><span class="drain"><?= usd($report['headline_save']) ?></span></h3>
    <?php if ($report['downshift_rows']): ?>
      <table>
        <thead><tr><th>Model</th><th>Downshift to</th><th class="num">Current</th><th class="num">Recoverable</th></tr></thead>
        <tbody>
        <?php foreach ($report['downshift_rows'] as $r): ?>
          <tr><td class="mono"><?= h($r['model']) ?></td><td class="mono"><?= h($r['to']) ?></td><td class="num"><?= usd($r['cost']) ?></td><td class="num"><?= usd($r['save']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="note">No model with a cheaper downshift target detected in this data.</p>
    <?php endif; ?>
    <div class="tags"><span class="tag eval">needs eval</span><span class="tag">effort: med</span></div>
    <p class="note">This is the ceiling — confirm with a 50–500 case eval before downgrading; some traffic genuinely needs the bigger model.</p>
  </div>

  <div class="finding">
    <h3><span>Output cost share</span><span class="drain"><?= number_format($ocs,0) ?>%</span></h3>
    <div class="bar"><span style="width:<?= $ocs ?>%"></span></div>
    <div class="tags">
      <span class="tag <?= $ocs > 50 ? 'risk':'safe' ?>"><?= $ocs > 50 ? 'output-heavy':'output in range' ?></span>
      <span class="tag">output:input ratio <?= number_format($ratio,2) ?>×</span>
      <span class="tag">effort: low</span>
    </div>
    <p class="note">Output tokens cost several times input. Every 20% trimmed off output ≈ <?= usd($report['output_save_per_20']) ?> over this period. (Share is output cost ÷ total spend; the <?= number_format($ratio,2) ?>× figure is output tokens per input token — a ratio, which is why it can exceed 100%.)</p>
  </div>

  <div class="finding">
    <h3><span>Prompt caching</span><span class="drain"><?= $report['cache_flag'] ? 'opportunity':'—' ?></span></h3>
    <div class="tags"><span class="tag <?= $report['cache_flag']?'risk':'safe' ?>"><?= $report['cache_flag']?'likely savings':'in use' ?></span><span class="tag">effort: low</span></div>
    <p class="note"><?= h($report['cache_note']) ?></p>
  </div>

  <div class="finding">
    <h3><span>Batch-eligible workload?</span><span class="drain">investigate</span></h3>
    <div class="tags"><span class="tag">~50% off batch</span><span class="tag">effort: low</span></div>
    <p class="note"><?= h($report['batch_note']) ?></p>
  </div>

  <?php if (!empty($report['unpriced'])): ?>
    <p class="note">No rate-card match for <?= h(implode(', ', $report['unpriced'])) ?>. Add them to <span class="mono">pricing.json</span> for full coverage.</p>
  <?php endif; ?>

  <p style="margin-top:24px"><a href="/">Run another export</a></p>
<?php endif; ?>

  <p class="foot">Processed in memory. Nothing stored.</p>
</div>
</body>
</html>
