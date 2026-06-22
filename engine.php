<?php
/**
 * engine.php — token-cost audit engine (clean rebuild).
 * Reads the nested pricing.json: { meta, tiers, models{id:{provider,input,output,cached_input,tier}}, downgrade_map{id:id} }.
 * Pure: CSV in -> per-model aggregation -> waste rules -> flat structured report. No sessions, no storage, no network.
 */

class TokenAudit
{
    private array $models;     // id => {provider,input,output,cached_input,tier}
    private array $downgrade;  // id => cheaper-id

    private array $aliases = [
        'model'         => ['model', 'model_id', 'model_name'],
        'input_tokens'  => ['input_tokens', 'prompt_tokens', 'tokens_prompt', 'n_context_tokens_total', 'context_tokens', 'input'],
        'output_tokens' => ['output_tokens', 'completion_tokens', 'tokens_completion', 'n_generated_tokens_total', 'generated_tokens', 'output'],
        'cached_tokens' => ['cached_tokens', 'cache_read_tokens', 'cache_read_input_tokens', 'cached', 'tokens_cached'],
        'cost'          => ['cost', 'amount', 'spend', 'total_cost', 'usd', 'cost_usd', 'cost_total'],
        'count'         => ['count', 'n_requests', 'requests', 'num_requests'],
    ];

    public function __construct(string $pricingPath)
    {
        $raw = @file_get_contents($pricingPath);
        $j = $raw ? json_decode($raw, true) : [];
        $this->models    = (is_array($j) && !empty($j['models']) && is_array($j['models'])) ? $j['models'] : [];
        $this->downgrade = (is_array($j) && !empty($j['downgrade_map']) && is_array($j['downgrade_map'])) ? $j['downgrade_map'] : [];
    }

    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/[\s\-]+/', '_', $s)));
    }

    public function detectColumns(array $header): array
    {
        $map = [];
        $normHeader = array_map(fn($h) => $this->norm((string)$h), $header);
        foreach ($this->aliases as $canon => $opts) {
            foreach ($opts as $opt) {
                $i = array_search($this->norm($opt), $normHeader, true);
                if ($i !== false) { $map[$canon] = $i; break; }
            }
        }
        return $map;
    }

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

    /** Resolve an export model string to a priced model. Returns ['key'=>id,'r'=>rates] or null. */
    private function resolve(string $model): ?array
    {
        if (isset($this->models[$model])) return ['key' => $model, 'r' => $this->models[$model]];
        foreach ($this->models as $id => $r) {
            if (stripos($model, $id) !== false || stripos($id, $model) !== false) return ['key' => $id, 'r' => $r];
        }
        return null;
    }

    private function costOf(array $r, float $in, float $out, float $cached = 0.0): float
    {
        $c = ($in / 1e6) * ($r['input'] ?? 0) + ($out / 1e6) * ($r['output'] ?? 0);
        if ($cached > 0) $c += ($cached / 1e6) * ($r['cached_input'] ?? ($r['input'] ?? 0));
        return $c;
    }

    public function aggregate(array $rows, array $map): array
    {
        $hasCacheCol = isset($map['cached_tokens']);
        $hasCostCol  = isset($map['cost']);
        $agg = [];

        foreach ($rows as $r) {
            $model = trim((string)($r[$map['model']] ?? ''));
            if ($model === '') continue;

            $in   = isset($map['input_tokens'])  ? $this->num($r[$map['input_tokens']]  ?? 0) : 0.0;
            $out  = isset($map['output_tokens']) ? $this->num($r[$map['output_tokens']] ?? 0) : 0.0;
            $cad  = $hasCacheCol ? $this->num($r[$map['cached_tokens']] ?? 0) : 0.0;
            $cost = $hasCostCol  ? $this->num($r[$map['cost']] ?? 0) : null;
            $cnt  = isset($map['count']) ? $this->num($r[$map['count']] ?? 1) : 1.0;

            if (!isset($agg[$model])) {
                $res = $this->resolve($model);
                $agg[$model] = [
                    'input' => 0, 'output' => 0, 'cached' => 0, 'cost' => 0.0, 'count' => 0,
                    'cost_from_data' => $hasCostCol,
                    'key' => $res['key'] ?? null,
                    'rates' => $res['r'] ?? null,
                ];
            }
            $agg[$model]['input']  += $in;
            $agg[$model]['output'] += $out;
            $agg[$model]['cached'] += $cad;
            $agg[$model]['count']  += $cnt;
            if ($cost !== null) $agg[$model]['cost'] += $cost;
        }

        foreach ($agg as $model => &$a) {
            if (!$a['cost_from_data'] && $a['rates']) {
                $a['cost'] = $this->costOf($a['rates'], $a['input'], $a['output'], $a['cached']);
            }
        }
        unset($a);

        return ['models' => $agg, 'hasCacheCol' => $hasCacheCol, 'hasCostCol' => $hasCostCol];
    }

    public function analyze(array $agg): array
    {
        $models = $agg['models'];
        $total  = array_sum(array_column($models, 'cost'));

        // Rule A — downshift ceiling, driven by downgrade_map
        $aTotal = 0.0; $downRows = [];
        foreach ($models as $model => $m) {
            if (!$m['key'] || !isset($this->downgrade[$m['key']])) continue;
            $targetId = $this->downgrade[$m['key']];
            if (!isset($this->models[$targetId])) continue;
            $downCost = $this->costOf($this->models[$targetId], $m['input'], $m['output'], $m['cached']);
            $save = max(0.0, $m['cost'] - $downCost);
            if ($save > 0) {
                $aTotal += $save;
                $downRows[] = ['model' => $model, 'to' => $targetId, 'cost' => $m['cost'], 'save' => $save];
            }
        }
        usort($downRows, fn($x, $y) => $y['save'] <=> $x['save']);

        // Rule B — output cost share (≤100%) and a separate, clearly-named ratio
        $outCost = 0.0; $totIn = 0.0; $totOut = 0.0;
        foreach ($models as $m) {
            if ($m['rates']) $outCost += ($m['output'] / 1e6) * ($m['rates']['output'] ?? 0);
            $totIn  += $m['input'];
            $totOut += $m['output'];
        }
        $outShare = $total > 0 ? $outCost / $total : 0.0;   // 0..1, cannot exceed 1
        $ioRatio  = $totIn > 0 ? $totOut / $totIn : 0.0;     // may exceed 1 — a ratio, never a "share"

        // Rule C — caching
        $totCached = array_sum(array_column($models, 'cached'));
        if ($agg['hasCacheCol']) {
            $cacheFlag = ($totIn > 0 && $totCached < 0.02 * $totIn);
            $cacheNote = $cacheFlag
                ? 'Almost no cached tokens against high input volume — re-sent system prompts are likely paid in full on every call.'
                : 'Caching is already in use on some traffic.';
        } else {
            $cacheFlag = true;
            $cacheNote = 'No cache data in this export. If you re-send large system prompts, caching is likely an easy win worth checking.';
        }

        // Rule D — spend concentration
        $spendRows = [];
        foreach ($models as $model => $m) {
            $spendRows[] = ['model' => $model, 'cost' => $m['cost'], 'share' => $total > 0 ? $m['cost'] / $total : 0,
                            'input' => $m['input'], 'output' => $m['output'], 'priced' => $m['rates'] !== null];
        }
        usort($spendRows, fn($x, $y) => $y['cost'] <=> $x['cost']);

        $unpriced = [];
        foreach ($models as $model => $m) { if ($m['rates'] === null) $unpriced[] = $model; }

        return [
            'total'              => $total,
            'headline_save'      => $aTotal,
            'headline_pct'       => $total > 0 ? $aTotal / $total : 0.0,
            'output_cost'        => $outCost,
            'output_cost_share'  => $outShare,
            'output_input_ratio' => $ioRatio,
            'output_save_per_20' => 0.2 * $outCost,
            'cache_flag'         => $cacheFlag,
            'cache_note'         => $cacheNote,
            'downshift_rows'     => $downRows,
            'spend_rows'         => $spendRows,
            'unpriced'           => $unpriced,
            'batch_note'         => 'Is any of this non-realtime — evals, backfills, bulk jobs? Batch endpoints run roughly half price. Worth confirming.',
        ];
    }
}
