<?php
declare(strict_types=1);

// Format numbers in the Indian numbering system.
function format_inr(float|int $num): string {
    $numStr = (string) round($num);
    if (strlen($numStr) > 3) {
        $last3 = substr($numStr, -3);
        $rest = substr($numStr, 0, -3);
        $rest = preg_replace("/\\B(?=(\\d{2})+(?!\\d))/", ",", $rest);
        return '₹' . $rest . ',' . $last3;
    }
    return '₹' . $numStr;
}

// Default values.
$default_sip             = 1000;
$default_years           = 10;
$default_rate            = 12;
$default_stepup          = 10;
$default_swp_start       = 10;
$default_swp_withdrawal  = 10000;
$default_swp_stepup      = 10;
$default_tax             = 12.5;
$default_inflation       = 6.0;

// Retrieve POST values or use defaults.
$sip             = isset($_POST['sip']) ? (float)$_POST['sip'] : $default_sip;
$years           = isset($_POST['years']) ? (int)$_POST['years'] : $default_years;
$rate            = isset($_POST['rate']) ? (float)$_POST['rate'] : $default_rate;
$stepup          = isset($_POST['stepup']) ? (float)$_POST['stepup'] : $default_stepup;
$tax             = isset($_POST['tax']) ? (float)$_POST['tax'] : $default_tax;
$inflation       = isset($_POST['inflation']) ? (float)$_POST['inflation'] : $default_inflation;
$swp_start       = isset($_POST['swp_start']) ? (int)$_POST['swp_start'] : $default_swp_start;
$swp_withdrawal  = isset($_POST['swp_withdrawal']) ? (float)$_POST['swp_withdrawal'] : $default_swp_withdrawal;
$swp_stepup      = isset($_POST['swp_stepup']) ? (float)$_POST['swp_stepup'] : $default_swp_stepup;
$action          = $_POST['action'] ?? '';

$monthly_rate = $rate / 100 / 12;

// SIP Calculation with annual step-up.
$sip_table = [];
$total = 0.0;
$current_sip = $sip;
for ($y = 1; $y <= $years; $y++) {
    $begin = $total;
    for ($m = 1; $m <= 12; $m++) {
        $total = ($total + $current_sip) * (1 + $monthly_rate);
    }
    $invested = $current_sip * 12;
    $interest = $total - $begin - $invested;
    $sip_table[$y] = [
        'monthly_invested' => round($current_sip),
        'invested'         => round($invested),
        'interest'         => round($interest),
        'total'            => round($total)
    ];
    $current_sip *= (1 + $stepup / 100);
}

// Calculate cumulative SIP invested for each year.
$cumulative_invested = [];
for ($i = 1; $i <= $years; $i++) {
    $cumulative_invested[$i] = ($i === 1) ? $sip_table[1]['invested'] : $cumulative_invested[$i - 1] + $sip_table[$i]['invested'];
}

// Calculate SWP period: Extend only if (SIP years - SWP start + 1) is less than 10.
$swp_years = max($years, $swp_start + 10 - 1);
$swp_table = [];
if ($swp_start >= 1 && $swp_start <= $swp_years && $swp_withdrawal > 0) {
    // Use inflation-adjusted after tax SIP total from the year before SWP start if available.
    if ($swp_start === 1) {
        $initial_balance = 0.0;
    } elseif ($swp_start <= $years) {
        $sip_total_prev = $sip_table[$swp_start - 1]['total'];
        $initial_balance = round($sip_total_prev * (1 - $tax / 100) / pow(1 + $inflation / 100, $swp_start - 1));
    } else {
        $sip_total_prev = $sip_table[$years]['total'];
        $initial_balance = round($sip_total_prev * (1 - $tax / 100) / pow(1 + $inflation / 100, $years));
    }
    $swp_balance = $initial_balance;
    $current_withdrawal = $swp_withdrawal;
    for ($y = $swp_start; $y <= $swp_years; $y++) {
        $begin_balance = $swp_balance;
        $year_interest = 0.0;
        $year_withdrawn = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $monthly_interest = $swp_balance * $monthly_rate;
            $swp_balance += $monthly_interest;
            $year_interest += $monthly_interest;
            if ($swp_balance < $current_withdrawal) {
                $withdraw = $swp_balance;
                $swp_balance = 0;
            } else {
                $withdraw = $current_withdrawal;
                $swp_balance -= $current_withdrawal;
            }
            $year_withdrawn += $withdraw;
        }
        $swp_table[$y] = [
            'begin'              => round($begin_balance),
            'interest'           => round($year_interest),
            'monthly_withdrawal' => round($current_withdrawal),
            'annual_withdrawal'  => round($year_withdrawn),
            'end'                => round($swp_balance)
        ];
        $current_withdrawal *= (1 + $swp_stepup / 100);
    }
}

// Combine SIP and SWP results from year 1 to $swp_years, including cumulative SIP invested and inflation-adjusted after tax SIP total.
$combined = [];
for ($y = 1; $y <= $swp_years; $y++) {
    // For SIP values, use current year if available; otherwise, use final year's data.
    $sip_invested = ($y <= $years) ? $sip_table[$y]['invested'] : null;
    $sip_total = ($y <= $years) ? $sip_table[$y]['total'] : $sip_table[$years]['total'];
    // Calculate inflation-adjusted after tax SIP total.
    // For years beyond the SIP period, discount the final SIP total.
    $sip_adjusted_total = round($sip_total * (1 - $tax / 100) / pow(1 + $inflation / 100, $y));
    
    $combined[$y] = [
        'year'                   => $y,
        'sip_monthly'            => ($y <= $years) ? $sip_table[$y]['monthly_invested'] : null,
        'sip_invested'           => $sip_invested,
        'cumulative_invested'    => ($y <= $years) ? $cumulative_invested[$y] : $cumulative_invested[$years],
        'sip_interest'           => ($y <= $years) ? $sip_table[$y]['interest'] : null,
        'sip_total'              => ($y <= $years) ? $sip_table[$y]['total'] : $sip_table[$years]['total'],
        'sip_adjusted_total'     => $sip_adjusted_total,
        'swp_begin'              => ($y >= $swp_start && isset($swp_table[$y])) ? $swp_table[$y]['begin'] : null,
        'swp_interest'           => ($y >= $swp_start && isset($swp_table[$y])) ? $swp_table[$y]['interest'] : null,
        'swp_monthly_withdrawal' => ($y >= $swp_start && isset($swp_table[$y])) ? $swp_table[$y]['monthly_withdrawal'] : null,
        'swp_annual_withdrawal'  => ($y >= $swp_start && isset($swp_table[$y])) ? $swp_table[$y]['annual_withdrawal'] : null,
        'swp_end'                => ($y >= $swp_start && isset($swp_table[$y])) ? $swp_table[$y]['end'] : null,
    ];
}

// CSV Download using SplTempFileObject to avoid deprecated fputcsv() issues.
if ($action === 'download') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="SIP_SWP_Report.csv"');
    // Output BOM for UTF-8 Excel compatibility.
    echo "\xEF\xBB\xBF";
    $csv = new SplTempFileObject();
    $csv->setCsvControl(',', '"', "\\");
    $csv->fputcsv([
        'Year', 'Monthly SIP Investment (₹)', 'SIP Invested (Annual ₹)', 'Cumulative SIP Invested (₹)', 'SIP Interest (₹)', 'SIP Total (₹)',
        'Inflation Adjusted After Tax SIP Total (₹)', 'SWP Begin (₹)', 'SWP Interest (₹)', 'SWP Monthly Withdrawal (₹)', 'SWP Annual Withdrawal (₹)', 'SWP End (₹)'
    ]);
    for ($y = 1; $y <= $swp_years; $y++) {
        $row = $combined[$y];
        $csv->fputcsv([
            $row['year'],
            $row['sip_monthly'] ?? '',
            $row['sip_invested'] ?? '',
            $row['cumulative_invested'] ?? '',
            $row['sip_interest'] ?? '',
            $row['sip_total'] ?? '',
            $row['sip_adjusted_total'] ?? '',
            $row['swp_begin'] ?? '',
            $row['swp_interest'] ?? '',
            $row['swp_monthly_withdrawal'] ?? '',
            $row['swp_annual_withdrawal'] ?? '',
            $row['swp_end'] ?? ''
        ]);
    }
    $csv->rewind();
    while (!$csv->eof()) {
        echo $csv->fgets();
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- SEO & Structured Data -->
  <title>Free SIP & SWP Calculator – Investment Planning Tool for Indians</title>
  <meta name="description" content="Use our free SIP & SWP calculator to plan your investments. A simple, accurate tool designed for Indian investors.">
  <link rel="canonical" href="http://sip-calculator.sumeet-boga.lovestoblog.com/?i=1">
  <meta name="robots" content="index,follow">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebApplication",
    "name": "Free SIP & SWP Calculator",
    "url": "http://sip-calculator.sumeet-boga.lovestoblog.com/?i=1",
    "applicationCategory": "Finance",
    "description": "A free, easy-to-use SIP & SWP calculator for Indian investors."
  }
  </script>
  <!-- Using Bootswatch Cyborg dark theme for a futuristic dark UI (no custom CSS) -->
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container my-5">
  <!-- Main Header -->
  <header class="mb-4 text-center">
    <h1 class="mb-3">Free SIP & SWP Calculator</h1>
    <p class="lead">Plan your investments effectively with our free SIP & SWP calculator designed for Indian investors.</p>
  </header>
  <div class="card mb-4 shadow">
    <div class="card-body">
      <form method="post" novalidate>
        <!-- SIP Details -->
        <fieldset class="mb-4">
          <legend class="mb-3">SIP Details</legend>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Monthly SIP Investment (₹)</label>
              <input type="number" step="0.01" name="sip" class="form-control" required min="1" value="<?= htmlspecialchars((string)$sip) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Years of Investment</label>
              <input type="number" name="years" class="form-control" required min="1" value="<?= htmlspecialchars((string)$years) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Annual Interest Rate (% p.a.)</label>
              <input type="number" step="0.01" name="rate" class="form-control" required min="0" value="<?= htmlspecialchars((string)$rate) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Annual SIP Increase (%)</label>
              <input type="number" step="0.01" name="stepup" class="form-control" required min="0" value="<?= htmlspecialchars((string)$stepup) ?>">
            </div>
          </div>
          <div class="row g-3 mt-3">
            <div class="col-md-3">
              <label class="form-label">Tax Percentage (%)</label>
              <input type="number" step="0.01" name="tax" class="form-control" required min="0" value="<?= htmlspecialchars((string)$tax) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Inflation Percentage (%)</label>
              <input type="number" step="0.01" name="inflation" class="form-control" required min="0" value="<?= htmlspecialchars((string)$inflation) ?>">
            </div>
          </div>
        </fieldset>
        <!-- SWP Details -->
        <fieldset class="mb-4">
          <legend class="mb-3">SWP Details</legend>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">SWP Start Year</label>
              <input type="number" name="swp_start" class="form-control" required min="1" value="<?= htmlspecialchars((string)$swp_start) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Monthly SWP Withdrawal (₹)</label>
              <input type="number" step="0.01" name="swp_withdrawal" class="form-control" required min="0" value="<?= htmlspecialchars((string)$swp_withdrawal) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Annual SWP Increase (%)</label>
              <input type="number" step="0.01" name="swp_stepup" class="form-control" required min="0" value="<?= htmlspecialchars((string)$swp_stepup) ?>">
            </div>
          </div>
        </fieldset>
        <div class="mb-3">
          <button type="submit" name="action" value="calculate" class="btn btn-primary me-2">Calculate</button>
          <button type="submit" name="action" value="download" class="btn btn-secondary me-2">Download CSV Report</button>
          <button type="reset" class="btn btn-outline-danger">Reset</button>
        </div>
      </form>
    </div>
  </div>
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'download'): ?>
  <div class="card shadow">
    <div class="card-body">
      <h2 class="card-title mb-4">Combined SIP & SWP Report</h2>
      <div class="table-responsive">
        <table class="table table-bordered table-striped">
          <thead class="table-dark">
            <tr>
              <th style="width:5%;">Year</th>
              <th style="width:10%;">Monthly SIP Investment</th>
              <th style="width:10%;">SIP Invested (Annual)</th>
              <th style="width:10%;">Cumulative SIP Invested</th>
              <th style="width:10%;">SIP Interest</th>
              <th style="width:10%;">SIP Total</th>
              <th style="width:10%;">Inflation Adjusted After Tax SIP Total</th>
              <th style="width:8%;">SWP Begin</th>
              <th style="width:8%;">SWP Interest</th>
              <th style="width:8%;">SWP Monthly Withdrawal</th>
              <th style="width:8%;">SWP Annual Withdrawal</th>
              <th style="width:8%;">SWP End</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($combined as $row): ?>
            <tr>
              <td><?= $row['year'] ?></td>
              <td><?= $row['sip_monthly'] !== null ? format_inr($row['sip_monthly']) : '-' ?></td>
              <td><?= $row['sip_invested'] !== null ? format_inr($row['sip_invested']) : '-' ?></td>
              <td><?= $row['cumulative_invested'] !== null ? format_inr($row['cumulative_invested']) : '-' ?></td>
              <td><?= $row['sip_interest'] !== null ? format_inr($row['sip_interest']) : '-' ?></td>
              <td><?= $row['sip_total'] !== null ? format_inr($row['sip_total']) : '-' ?></td>
              <td><?= $row['sip_adjusted_total'] !== null ? format_inr($row['sip_adjusted_total']) : '-' ?></td>
              <td><?= $row['swp_begin'] !== null ? format_inr($row['swp_begin']) : '-' ?></td>
              <td><?= $row['swp_interest'] !== null ? format_inr($row['swp_interest']) : '-' ?></td>
              <td><?= $row['swp_monthly_withdrawal'] !== null ? format_inr($row['swp_monthly_withdrawal']) : '-' ?></td>
              <td><?= $row['swp_annual_withdrawal'] !== null ? format_inr($row['swp_annual_withdrawal']) : '-' ?></td>
              <td><?= $row['swp_end'] !== null ? format_inr($row['swp_end']) : '-' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="mt-3 text-muted fst-italic">Disclaimer: This tool is for illustrative purposes only and does not constitute financial advice.</p>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
