<?php
/** etokens.com - Token-Cost Audit MVP */
session_start();

if (isset($_GET['reset'])) {
    session_unset();
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

require_once __DIR__ . '/engine.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES|ENT_HTML5, "UTF-8");
}

function fmt($n): string {
    if ($n >= 1000000) return round($n/1000000, 2)."M";
    if ($n >= 1000) return round($n/1000, 1)."K";
    return (string)round($n, 0);
}

function dollar(float $v): string {
    return $v >= 1 ? "$".number_format(round($v,2),2) : "$".number_format(round($v,4),4);
}

$PRICING_FILE = __DIR__ . "/pricing.json";
$auditEngine = new TokenAudit($PRICING_FILE);

$step   = $_SESSION["step"]    ?? "upload";
$err    = $_SESSION["error"]   ?? "";
$rows   = $_SESSION["rows"]    ?? [];
$hdrs   = $_SESSION["headers"] ?? [];
$map    = $_SESSION["map"]     ?? [];
$schema = $_SESSION["schema"]  ?? null;
$report = null;
$_SESSION["error"] = "";

function parse_csv(string $content): array {
    $lines = preg_split("/\r\n|\n|\r/", trim($content));
    if (count($lines) < 2) return ["headers"=>[], "rows"=>[]];
    $headers = array_map("trim", str_getcsv(array_shift($lines)));
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "") continue;
        $row = str_getcsv($line);
        if (count($row) >= count($headers)) {
            $rows[] = array_map("trim", array_slice($row, 0, count($headers)));
        }
    }
    return ["headers"=>$headers, "rows"=>$rows];
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];

    if ($action === "upload") {
        unset($_SESSION["rows"], $_SESSION["headers"], $_SESSION["map"], $_SESSION["schema"], $_SESSION["report"]);
        if (empty($_FILES["csv"]) || $_FILES["csv"]["error"] !== UPLOAD_ERR_OK) {
            $_SESSION["error"] = "Upload failed. Please try again.";
        } elseif ($_FILES["csv"]["size"] > 10 * 1024 * 1024) {
            $_SESSION["error"] = "File too large. Maximum size is 10 MB.";
        } else {
            $ext = strtolower(pathinfo($_FILES["csv"]["name"], PATHINFO_EXTENSION));
            if ($ext !== "csv") {
                $_SESSION["error"] = "Only .csv files are accepted.";
            } else {
                $content = file_get_contents($_FILES["csv"]["tmp_name"]);
                $parsed  = parse_csv($content);
                @unlink($_FILES["csv"]["tmp_name"]);
                if (empty($parsed["rows"])) {
                    $_SESSION["error"] = "CSV appears empty or could not be parsed.";
                } else {
                    $_SESSION["headers"] = $parsed["headers"];
                    $_SESSION["rows"]    = $parsed["rows"];
                    
                    $detectedMap = $auditEngine->detectColumns($parsed["headers"]);
                    $_SESSION["map"] = $detectedMap;
                    
                    if ($auditEngine->mappable($detectedMap)) {
                        $_SESSION["step"] = "audit";
                    } else {
                        $_SESSION["step"] = "remap";
                    }
                }
            }
        }
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit;

    } elseif ($action === "remap") {
        $userMap = [];
        foreach (['model', 'input_tokens', 'output_tokens', 'cached_tokens', 'cost', 'count', 'date', 'label'] as $canon) {
            $val = trim($_POST["col_" . $canon] ?? "");
            if ($val !== "") {
                // Find index of this header
                $idx = array_search($val, $_SESSION["headers"], true);
                if ($idx !== false) {
                    $userMap[$canon] = $idx;
                }
            }
        }
        $_SESSION["map"] = $userMap;
        if (!isset($userMap['model']) || (!isset($userMap['input_tokens']) && !isset($userMap['output_tokens']))) {
            $_SESSION["error"] = "Model and either Input or Output token columns are required.";
            $_SESSION["step"] = "remap";
        } else {
            $_SESSION["step"] = "audit";
        }
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit;

    } elseif ($action === "reset") {
        session_destroy();
        header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
        exit;
    }
}

if ($step === "audit" && !empty($rows) && !empty($map)) {
    $agg = $auditEngine->aggregate($rows, $map);
    $report = $auditEngine->analyze($agg);
    $_SESSION["report"] = $report;
    $_SESSION["step"] = "report";
    $step = "report";
}
if ($step === "report" && !$report) {
    $report = $_SESSION["report"] ?? null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>etokens — LLM Token-Cost Audit</title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--panel2:#21262d;--border:#30363d;--accent:#f0b429;--accent2:#e0549d;--teal:#39d0a3;--red:#f85149;--text:#e6edf3;--muted:#8b949e;--font:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;--mono:'SF Mono','Fira Code',monospace;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font:15px/1.6 var(--font);min-height:100vh}
a{color:var(--teal);text-decoration:none}a:hover{text-decoration:underline}
header{border-bottom:1px solid var(--border);padding:16px 24px;display:flex;align-items:center;gap:12px}
.logo{font-size:22px;font-weight:700;letter-spacing:-0.5px}.logo span{color:var(--accent)}
.tagline{color:var(--muted);font-size:13px;border-left:1px solid var(--border);padding-left:12px}
header a{color:var(--text)}header a:hover{text-decoration:none}
main{max-width:860px;margin:0 auto;padding:40px 24px 80px}
.upload-zone{display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;border:2px dashed var(--border);border-radius:12px;padding:64px 32px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--panel)}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--accent);background:#1c2128}
.upload-zone input{display:none}
.upload-icon{font-size:48px;margin-bottom:16px}
.upload-zone h2{font-size:20px;margin-bottom:8px}
.upload-zone p{color:var(--muted);font-size:13px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:opacity .2s}
.btn:hover{opacity:0.85}
.btn-primary{background:var(--accent);color:#000}
.btn-ghost{background:var(--panel2);color:var(--text);border:1px solid var(--border)}
.error-msg{background:#3d1a1a;border:1px solid var(--red);border-radius:8px;padding:12px 16px;color:var(--red);margin-bottom:16px;font-size:14px}
.supported{margin-top:24px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap}
.supported span{background:var(--panel2);border:1px solid var(--border);border-radius:6px;padding:4px 12px;font-size:12px;color:var(--muted);font-family:var(--mono)}
.remap-box{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:32px}
.remap-box h2{font-size:18px;margin-bottom:4px}
.remap-box p{color:var(--muted);font-size:13px;margin-bottom:24px}
.col-map{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.field label .req{color:var(--red)}
.field select,.field input{background:var(--panel2);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:8px 12px;font-size:14px}
.field select{cursor:pointer}
.field small{font-size:11px;color:var(--muted)}
.remap-actions{display:flex;gap:12px}
.report-header{margin-bottom:32px}
.report-header h1{font-size:26px;margin-bottom:4px}
.report-header p{color:var(--muted);font-size:13px}
.report-meta{display:flex;gap:24px;margin-top:12px;flex-wrap:wrap}
.meta-pill{background:var(--panel);border:1px solid var(--border);border-radius:100px;padding:4px 12px;font-size:12px;color:var(--muted)}
.meta-pill strong{color:var(--text)}
.summary-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:32px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px}
.card-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:8px}
.card-value{font-size:28px;font-weight:700;font-family:var(--mono)}
.card-sub{font-size:12px;color:var(--muted);margin-top:4px}
.card.accent{border-color:var(--accent)}.card.accent .card-value{color:var(--accent)}
.card.pink{border-color:var(--accent2)}.card.pink .card-value{color:var(--accent2)}
.card.teal{border-color:var(--teal)}.card.teal .card-value{color:var(--teal)}
.tier-bar-wrap{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:32px}
.tier-bar-title{font-size:13px;font-weight:600;margin-bottom:12px}
.tier-bar{height:12px;border-radius:6px;display:flex;overflow:hidden;background:var(--panel2)}
.tier-seg{transition:width .4s}
.tier-legend{display:flex;gap:16px;margin-top:10px;flex-wrap:wrap}
.tier-legend span{font-size:11px;color:var(--muted)}.tier-legend strong{color:var(--text)}
.findings{display:flex;flex-direction:column;gap:16px;margin-bottom:32px}
.finding{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px}
.finding.rule-a{border-left:3px solid var(--accent)}
.finding.rule-b{border-left:3px solid var(--accent2)}
.finding.rule-c{border-left:3px solid var(--teal)}
.finding.rule-d{border-left:3px solid #9d7af5}
.finding.rule-e{border-left:3px solid var(--muted)}
.finding-head{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.finding-badge{font-size:10px;font-weight:700;letter-spacing:.5px;padding:3px 8px;border-radius:4px;text-transform:uppercase}
.badge-a{background:rgba(240,180,41,.15);color:var(--accent)}
.badge-b{background:rgba(224,84,157,.15);color:var(--accent2)}
.badge-c{background:rgba(57,208,163,.15);color:var(--teal)}
.badge-d{background:rgba(157,122,245,.15);color:#9d7af5}
.badge-e{background:rgba(139,148,158,.15);color:var(--muted)}
.finding h3{font-size:15px;font-weight:600}
.finding p{color:var(--muted);font-size:13px;margin-bottom:12px;line-height:1.5}
.finding table{width:100%;border-collapse:collapse;font-size:13px}
.finding th{text-align:left;padding:6px 10px;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
.finding td{padding:8px 10px;border-bottom:1px solid #21262d;font-family:var(--mono);font-size:12px}
.finding tr:last-child td{border-bottom:none}
.finding .saving{color:var(--teal);font-weight:600}
.effort{display:inline-block;font-size:10px;padding:2px 7px;border-radius:4px;text-transform:uppercase;font-weight:700;letter-spacing:.5px}
.eff-l{background:rgba(57,208,163,.15);color:var(--teal)}
.eff-m{background:rgba(240,180,41,.15);color:var(--accent)}
.eff-h{background:rgba(248,81,73,.15);color:var(--red)}
.finding-footer{display:flex;gap:16px;align-items:center;margin-top:12px;flex-wrap:wrap}
.sensitivity{background:var(--panel2);border-radius:6px;padding:8px 12px;font-size:12px;color:var(--muted)}
.sensitivity strong{color:var(--teal)}
.models-table{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:32px}
.models-table h2{font-size:15px;padding:16px 20px;border-bottom:1px solid var(--border)}
.models-table table{width:100%;border-collapse:collapse}
.models-table th{text-align:left;padding:10px 16px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:var(--panel2);border-bottom:1px solid var(--border)}
.models-table td{padding:10px 16px;font-size:13px;border-bottom:1px solid var(--border);font-family:var(--mono)}
.models-table tr:last-child td{border-bottom:none}
.models-table tr:hover td{background:var(--panel2)}
.tier-badge{font-size:10px;padding:2px 7px;border-radius:4px;text-transform:uppercase;font-weight:700;letter-spacing:.5px}
.tier-f{background:rgba(240,180,41,.15);color:var(--accent)}
.tier-m{background:rgba(57,208,163,.15);color:var(--teal)}
.tier-e{background:rgba(139,148,158,.15);color:var(--muted)}
.disclaimer{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:20px;font-size:12px;color:var(--muted);line-height:1.6;margin-bottom:32px}
.disclaimer strong{color:var(--text)}
.reset-link{display:inline-block;margin-top:24px;font-size:13px;color:var(--muted)}
.reset-link:hover{color:var(--red)}
@media(max-width:600px){.col-map{grid-template-columns:1fr}.summary-cards{grid-template-columns:1fr 1fr}}
@media print{body{background:#fff;color:#000}.finding,.card,.remap-box,.upload-zone,.models-table,.tier-bar-wrap,.disclaimer{border-color:#ccc;background:#fff}header{border-color:#ccc}.btn{display:none}}
</style>
</head>
<body>
<header>
  <a href="?" class="logo">etokens<span>.com</span></a>
  <span class="tagline">LLM token-cost audit — no login, no account, no API keys</span>
</header>
<main>
<?php if ($step === "upload"): ?>
<?php if ($err): ?>
  <div class="error-msg"><?= e($err) ?></div>
<?php endif; ?>
<form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
  <input type="hidden" name="action" value="upload">
  <div class="upload-zone" id="dropZone">
    <input type="file" name="csv" id="csvInput" accept=".csv">
    <div class="upload-icon">&#128202;</div>
    <h2>Drop your CSV usage export here</h2>
    <p>or click to browse &mdash; max 10 MB, .csv only</p>
  </div>
  <div class="supported" style="margin-top:12px;margin-bottom:12px;justify-content:center;display:flex;">
    <a href="/sample.csv" style="color:var(--accent);font-weight:bold;text-decoration:underline;">Download Sample CSV File</a>
  </div>
  <div class="supported">
    <span>gpt-4o</span><span>claude-opus-4</span><span>gemini-2.5-pro</span>
    <span>deepseek-chat</span><span>mistral-large</span><span>llama-3.1-405b</span>
  </div>
  <div style="text-align:center;margin-top:24px">
    <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>Upload &amp; Analyze</button>
  </div>
</form>
<script>
const zi = document.getElementById("dropZone"), zi2 = document.getElementById("csvInput"), zi3 = document.getElementById("uploadBtn");
zi.addEventListener("click", () => zi2.click());
zi2.addEventListener("change", () => { if(zi2.files[0]) { zi3.disabled = false; document.querySelector(".upload-zone p").textContent = "Selected: " + zi2.files[0].name; } });
zi.addEventListener("dragover", e => { e.preventDefault(); zi.classList.add("dragover"); });
zi.addEventListener("dragleave", () => zi.classList.remove("dragover"));
zi.addEventListener("drop", e => { e.preventDefault(); zi.classList.remove("dragover"); if(e.dataTransfer.files[0]) { zi2.files = e.dataTransfer.files; zi3.disabled = false; document.querySelector(".upload-zone p").textContent = "Selected: " + e.dataTransfer.files[0].name; } });
</script>
<?php elseif ($step === "remap"): ?>
<?php
  // Pre-compute dropdown options
  $opt_model  = ""; foreach($hdrs as $h) $opt_model  .= "<option value=\"".e($h)."\"" . (isset($map["model"]) && $map["model"]===$h?" selected":"").">".e($h)."</option>";
  $opt_input  = ""; foreach($hdrs as $h) $opt_input  .= "<option value=\"".e($h)."\"" . (isset($map["input_tokens"]) && $map["input_tokens"]===$h?" selected":"").">".e($h)."</option>";
  $opt_output = ""; foreach($hdrs as $h) $opt_output .= "<option value=\"".e($h)."\"" . (isset($map["output_tokens"]) && $map["output_tokens"]===$h?" selected":"").">".e($h)."</option>";
  $opt_cost   = ""; foreach($hdrs as $h) $opt_cost   .= "<option value=\"".e($h)."\"" . (isset($map["cost"]) && $map["cost"]===$h?" selected":"").">".e($h)."</option>";
  $opt_cached = ""; foreach($hdrs as $h) $opt_cached .= "<option value=\"".e($h)."\"" . (isset($map["cached_tokens"]) && $map["cached_tokens"]===$h?" selected":"").">".e($h)."</option>";
?>
<div class="remap-box">
  <h2>Map your columns</h2>
  <p>We couldn't auto-detect your export format. Please match each required field to the right column.</p>
  <form action="" method="POST">
    <input type="hidden" name="action" value="remap">
    <div class="col-map">
      <div class="field">
        <label>Model <span class="req">*</span></label>
        <select name="col_model" required><option value="">— select —</option><?= $opt_model ?></select>
        <small>e.g. model, model_name</small>
      </div>
      <div class="field">
        <label>Input Tokens <span class="req">*</span></label>
        <select name="col_input_tokens" required><option value="">— select —</option><?= $opt_input ?></select>
        <small>prompt_tokens, input_tokens, etc.</small>
      </div>
      <div class="field">
        <label>Output Tokens <span class="req">*</span></label>
        <select name="col_output_tokens" required><option value="">— select —</option><?= $opt_output ?></select>
        <small>completion_tokens, output_tokens, etc.</small>
      </div>
      <div class="field">
        <label>Cost <small>(optional)</small></label>
        <select name="col_cost"><option value="">— none —</option><?= $opt_cost ?></select>
        <small>Total cost column (if pre-computed)</small>
      </div>
      <div class="field">
        <label>Cached Tokens <small>(optional)</small></label>
        <select name="col_cached_tokens"><option value="">— none —</option><?= $opt_cached ?></select>
        <small>cache_creation_input_tokens, cached_prompt_tokens</small>
      </div>
    </div>
    <div class="remap-actions">
      <button type="submit" class="btn btn-primary">Run Audit</button>
      <a href="?reset=1" class="btn btn-ghost">Start Over</a>
    </div>
  </form>
</div>
<?php elseif ($step === "report" && $report): ?>
<?php
  $ts  = $report["total_spend"];
  $tin = $report["total_in"];
  $tout= $report["total_out"];
  $tr  = $report["total_rows"];
  $st  = $report["spend_by_tier"];
  $md  = $report["model_data"];
  $findings = $report["findings"];
  
  $total_t = max(0.001, $st["frontier"] + $st["mid"] + $st["economy"]);
  $wf = round($st["frontier"] / $total_t * 100, 1);
  $wm = round($st["mid"]      / $total_t * 100, 1);
  $we = round($st["economy"]  / $total_t * 100, 1);
?>
<div class="report-header">
  <h1>Token-Cost Audit Report</h1>
  <p>Parsed <?= number_format($tr) ?> rows</p>
  <div class="report-meta">
    <span class="meta-pill"><strong><?= dollar($ts) ?></strong> total spend</span>
    <span class="meta-pill"><strong><?= fmt($tin) ?></strong> input tokens</span>
    <span class="meta-pill"><strong><?= fmt($tout) ?></strong> output tokens</span>
  </div>
</div>

<div class="summary-cards">
  <div class="card accent">
    <div class="card-label">Total Spend</div>
    <div class="card-value"><?= dollar($ts) ?></div>
    <div class="card-sub"><?= number_format($tr) ?> API calls</div>
  </div>
  <div class="card pink">
    <div class="card-label">Output Cost Share</div>
    <div class="card-value"><?= round(($ts > 0 && isset($findings['B']['out_cost_total'])) ? ($findings['B']['out_cost_total'] / $ts * 100) : 0, 1) ?>%</div>
    <div class="card-sub">of total spend on output</div>
  </div>
  <div class="card teal">
    <div class="card-label">Savings Ceiling (A)</div>
    <div class="card-value"><?= dollar($findings['A']['save'] ?? 0) ?></div>
    <div class="card-sub">frontier downshift potential</div>
  </div>
</div>

<div class="tier-bar-wrap">
  <div class="tier-bar-title">Spend by Tier</div>
  <div class="tier-bar">
    <div class="tier-seg" style="width:<?= $wf ?>%;background:#f0b429" title="Frontier: <?= $wf ?>%"></div>
    <div class="tier-seg" style="width:<?= $wm ?>%;background:#39d0a3" title="Mid: <?= $wm ?>%"></div>
    <div class="tier-seg" style="width:<?= $we ?>%;background:#8b949e" title="Economy: <?= $we ?>%"></div>
  </div>
  <div class="tier-legend">
    <span><strong style="color:#f0b429">&#9632;</strong> Frontier: <?= dollar($st["frontier"]) ?></span>
    <span><strong style="color:#39d0a3">&#9632;</strong> Mid: <?= dollar($st["mid"]) ?></span>
    <span><strong style="color:#8b949e">&#9632;</strong> Economy: <?= dollar($st["economy"]) ?></span>
  </div>
</div>

<div class="models-table">
  <h2>Models</h2>
  <table>
    <thead>
      <tr><th>Model</th><th>Provider</th><th>Tier</th><th>Input</th><th>Output</th><th>Est. Cost</th></tr>
    </thead>
    <tbody>
<?php
$sorted = $md;
uasort($sorted, fn($a,$b) => $b["cost"] <=> $a["cost"]);
foreach ($sorted as $m => $d):
?>
      <tr>
        <td><?= e($m) ?></td>
        <td><?= e($d["provider"]) ?></td>
        <td><span class="tier-badge tier-<?= substr($d["tier"],0,1) ?>"><?= e($d["tier"]) ?></span></td>
        <td><?= fmt($d["input"]) ?></td>
        <td><?= fmt($d["output"]) ?></td>
        <td><?= dollar($d["cost"]) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="findings">
<?php if (isset($findings['A']) && !empty($findings['A']['rows'])): ?>
  <div class="finding rule-a">
    <div class="finding-head">
      <span class="finding-badge badge-a">Rule A</span>
      <h3><?= e($findings['A']['title']) ?></h3>
      <span class="effort eff-h">High Effort</span>
      <span class="effort eff-m">Quality Risk</span>
    </div>
    <p>You spent money on frontier-tier models. Switching to the next tier down could reduce that by up to <strong class="saving"><?= dollar($findings['A']['save']) ?></strong>.</p>
    <table>
      <tr><th>From Model</th><th>To Model</th><th>Est. Saving</th><th>Original Cost</th></tr>
<?php foreach ($findings['A']['rows'] as $row): ?>
      <tr>
        <td><?= e($row['model']) ?></td>
        <td><?= e($row['to']) ?></td>
        <td><span class="saving"><?= dollar($row['save']) ?></span></td>
        <td><?= dollar($row['cost']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if (isset($findings['B']) && !empty($findings['B']['findings'])): ?>
  <div class="finding rule-b">
    <div class="finding-head">
      <span class="finding-badge badge-b">Rule B</span>
      <h3><?= e($findings['B']['title']) ?></h3>
      <span class="effort eff-l">Low Effort</span>
      <span class="effort eff-m">Low Risk</span>
    </div>
    <p>Models below have output costs driving &gt;55% of their line-item cost, a sign of verbose responses or missing <code>max_tokens</code> discipline.</p>
    <table>
      <tr><th>Model</th><th>Output Cost Share</th><th>Total Cost</th></tr>
<?php foreach ($findings['B']['findings'] as $f): ?>
      <tr>
        <td><?= e($f['model']) ?></td>
        <td><?= $f['out_frac'] ?>%</td>
        <td><?= dollar($f['cost']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if (isset($findings['C'])): ?>
  <div class="finding rule-c">
    <div class="finding-head">
      <span class="finding-badge badge-c">Rule C</span>
      <h3><?= e($findings['C']['title']) ?></h3>
      <span class="effort eff-l">Low Effort</span>
      <span class="effort eff-l">Low Risk</span>
    </div>
    <p>Your export shows <?= $findings['C']['has_cached'] ? 'some' : 'no' ?> cached tokens out of <strong><?= fmt($findings['C']['total_in']) ?></strong> input tokens. Enable prompt caching on compatible platforms to reduce input costs by up to 50-90% on repeat patterns.</p>
  </div>
<?php endif; ?>
</div>

<div class="disclaimer">
  <strong>Methodology note:</strong> All findings are computed from your uploaded CSV using static pricing from <code>pricing.json</code>. Savings figures are <em>opportunity ceilings</em> that require validation before any change is made to production systems. This tool does not call any LLM API.
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap">
  <button class="btn btn-primary" onclick="window.print()">Download Report (Print/PDF)</button>
  <a href="?reset=1" class="btn btn-ghost">Analyze Another File</a>
</div>
<?php endif; ?>
</main>
</body>
</html>