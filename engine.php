<?php
/**
 * engine.php — token-cost audit engine.
 * No external dependencies. No network calls. No API keys.
 * Pure: CSV in -> per-model aggregation -> waste-detection rules -> structured findings.
 */

class TokenAudit
{
    private array $pricing;

    /** canonical field => known header aliases (normalized, case-insensitive) */
    private array $aliases = [
        'model'         => ['model', 'model_id', 'model_name'],
        'input_tokens'  => ['input_tokens', 'prompt_tokens', 'tokens_prompt', 'n_context_tokens_total', 'context_tokens', 'input'],
        'output_tokens' => ['output_tokens', 'completion_tokens', 'tokens_completion', 'n_generated_tokens_total', 'generated_tokens', 'output'],
        'cached_tokens' => ['cached_tokens', 'cache_read_tokens', 'cache_read_input_tokens', 'cached', 'tokens_cached'],
        'cost'          => ['cost', 'amount', 'spend', 'total_cost', 'usd', 'cost_usd', 'cost_total'],
        'count'         => ['count', 'n_requests', 'requests', 'num_requests'],
        'date'          => ['date', 'day', 'timestamp', 'created_at', 'usage_date'],
        'label'         => ['label', 'app', 'endpoint', 'project', 'tag'],
    ];

    public function __construct(string $pricingPath)
    {
        $raw = @file_get_contents($pricingPath);
        $data = $raw ? json_decode($raw, true) : [];
        if (isset($data['models'])) {
            $this->pricing = $data['models'];
        } else {
            unset($data['_meta']);
            $this->pricing = is_array($data) ? $data : [];
        }
    }

    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/[\s\-]+/', '_', $s)));
    }

    /** Auto-map header row to canonical fields. Returns [canonical => column_index]. */
    public function detectColumns(array $header): array
    {
        $map = [];
        $normHeader = array_map(fn($h) => $this->norm((string)$h), $header);
        foreach ($this->aliases as $canon => $opts) {
            foreach ($opts as $opt) {
                $i = array_search($this->norm($opt), $normHeader, true);
                if ($i !== false) {
                    $map[$canon] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    /** Do we have enough to run without asking the user to map columns? */
    public function mappable(array $map): bool
    {
        return isset($map['model']) && (isset($map['input_tokens']) || isset($map['output_tokens']));
    }

    private function num($v): float
    {
        if (is_numeric($v)) return (float)$v;
        $v = preg_replace('/[^0-9.\-]/', '', (string)$v);
        return $v === '' ? 0.0 : (float)$v;
    }

    private function rates(string $model): ?array
    {
        $key = strtolower(trim($model));
        if (isset($this->pricing[$model])) return $this->pricing[$model];
        foreach ($this->pricing as $id => $p) {
            if (strtolower($id) === $key) return $p;
        }
        foreach ($this->pricing as $id => $p) {
            if (stripos($key, strtolower($id)) !== false || stripos(strtolower($id), $key) !== false) return $p;
        }
        return null;
    }

    private function priceTokens(string $model, float $in, float $out, float $cached = 0.0): float
    {
        $r = $this->rates($model);
        if (!$r) return 0.0;
        
        $inRate = ($r['input'] ?? ($r['input_per_mtok'] ?? 0)) / 1000000;
        $outRate = ($r['output'] ?? ($r['output_per_mtok'] ?? 0)) / 1000000;
        $cacheRate = ($r['cached_input'] ?? ($r['cache_read_per_mtok'] ?? $inRate)) / 1000000;
        
        $nc = max(0, $in - $cached);
        return ($nc * $inRate) + ($cached * $cacheRate) + ($out * $outRate);
    }

    /** Aggregate rows by model using a column map. */
    public function aggregate(array $rows, array $map): array
    {
        $hasCacheCol = isset($map['cached_tokens']);
        $hasCostCol = isset($map['cost']);
        $agg = [];

        foreach ($rows as $r) {
            $model = trim((string)($r[$map['model']] ?? ''));
            if ($model === '') continue;

            $in   = isset($map['input_tokens']) ? $this->num($r[$map['input_tokens']] ?? 0) : 0.0;
            $out  = isset($map['output_tokens']) ? $this->num($r[$map['output_tokens']] ?? 0) : 0.0;
            $cad  = $hasCacheCol ? $this->num($r[$map['cached_tokens']] ?? 0) : 0.0;
            $cost = $hasCostCol ? $this->num($r[$map['cost']] ?? 0) : null;
            $cnt  = isset($map['count']) ? $this->num($r[$map['count']] ?? 1) : 1.0;

            if (!isset($agg[$model])) {
                $rates = $this->rates($model);
                $agg[$model] = [
                    'input'   => 0,
                    'output'  => 0,
                    'cached'  => 0,
                    'cost'    => 0.0,
                    'count'   => 0,
                    'cost_from_data' => $hasCostCol,
                    'priced'  => $rates !== null,
                    'tier'    => $rates['tier'] ?? 'mid',
                    'provider'=> $rates['provider'] ?? ''
                ];
            }
            $agg[$model]['input']  += $in;
            $agg[$model]['output'] += $out;
            $agg[$model]['cached'] += $cad;
            $agg[$model]['count']  += $cnt;
            if ($cost !== null) $agg[$model]['cost'] += $cost;
        }

        foreach ($agg as $model => &$a) {
            if (!$a['cost_from_data']) {
                $a['cost'] = $this->priceTokens($model, $a['input'], $a['output'], $a['cached']);
            }
        }
        unset($a);

        return ['models' => $agg, 'hasCacheCol' => $hasCacheCol, 'hasCostCol' => $hasCostCol];
    }

    /** Run structured analysis; return report. */
    public function analyze(array $aggData): array
    {
        $models = $aggData['models'];
        $totalSpend = array_sum(array_column($models, 'cost'));
        $totalIn = array_sum(array_column($models, 'input'));
        $totalOut = array_sum(array_column($models, 'output'));
        $totalRows = array_sum(array_column($models, 'count'));
        $hasCached = array_sum(array_column($models, 'cached')) > 0;
        
        $tierSpend = ['frontier' => 0, 'mid' => 0, 'economy' => 0];
        foreach ($models as $m) {
            $t = $m['tier'] ?? 'mid';
            if (isset($tierSpend[$t])) $tierSpend[$t] += $m['cost'];
        }

        $findings = [];

        // Rule A — frontier downshift ceiling
        $aTotal = 0.0; $aRows = [];
        foreach ($models as $name => $m) {
            if (($m['tier'] ?? '') !== 'frontier') continue;
            $r = $this->rates($name);
            $down = $r['next_tier_down'] ?? null;
            // Legacy check or explicit map
            if (!$down && strpos(strtolower($name), 'gpt-4') !== false) $down = 'gpt-4o-mini';
            
            if ($down) {
                $downCost = $this->priceTokens($down, $m['input'], $m['output'], $m['cached']);
                $save = max(0.0, $m['cost'] - $downCost);
                if ($save > 0) {
                    $aTotal += $save;
                    $aRows[] = ['model' => $name, 'to' => $down, 'save' => $save, 'cost' => $m['cost']];
                }
            }
        }
        usort($aRows, fn($x, $y) => $y['save'] <=> $x['save']);
        $findings['A'] = ['title' => 'Frontier Downshift Opportunity', 'save' => $aTotal, 'rows' => $aRows];

        // Rule B — output verbosity
        $outCostTotal = 0;
        $bFindings = [];
        foreach ($models as $name => $m) {
            $r = $this->rates($name);
            if (!$r) continue;
            $oc = ($m['output'] / 1e6) * ($r['output'] ?? ($r['output_per_mtok'] ?? 0));
            $outCostTotal += $oc;
            $frac = $m['cost'] > 0 ? $oc / $m['cost'] : 0;
            if ($frac > 0.55 && $m['cost'] > 0.01) {
                $bFindings[] = ['model' => $name, 'out_frac' => round($frac * 100, 1), 'cost' => $m['cost']];
            }
        }
        $findings['B'] = ['title' => 'Output-Token Sprawl', 'findings' => $bFindings, 'out_cost_total' => $outCostTotal];

        // Rule C — Caching
        $findings['C'] = ['title' => 'Prompt Caching Opportunity', 'has_cached' => $hasCached, 'total_in' => $totalIn];

        return [
            'total_spend'   => $totalSpend,
            'total_in'      => $totalIn,
            'total_out'     => $totalOut,
            'total_rows'    => $totalRows,
            'spend_by_tier' => $tierSpend,
            'findings'      => $findings,
            'model_data'    => $models
        ];
    }
}
