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
$default_swp_withdrawal  = 10000;
$default_swp_stepup      = 10;
$default_swp_years       = 20;  // SWP years after SIP

// Retrieve POST values or use defaults.
$sip             = isset($_POST['sip']) ? (float)$_POST['sip'] : $default_sip;
$years           = isset($_POST['years']) ? (int)$_POST['years'] : $default_years;
$rate            = isset($_POST['rate']) ? (float)$_POST['rate'] : $default_rate;
$stepup          = isset($_POST['stepup']) ? (float)$_POST['stepup'] : $default_stepup;
$swp_withdrawal  = isset($_POST['swp_withdrawal']) ? (float)$_POST['swp_withdrawal'] : $default_swp_withdrawal;
$swp_stepup      = isset($_POST['swp_stepup']) ? (float)$_POST['swp_stepup'] : $default_swp_stepup;
$swp_years_input = isset($_POST['swp_years']) ? (int)$_POST['swp_years'] : $default_swp_years;
$action          = $_POST['action'] ?? '';

// SWP automatically starts in the year immediately following the SIP period.
$swp_start = $years + 1;

$monthly_rate = $rate / 100 / 12;

// Simulation period: SIP years + user-defined SWP years.
$simulation_years = $years + $swp_years_input;

$net_balance = 0.0;
$cumulative_invested = 0.0;
$cumulative_withdrawals = 0.0;
$combined = [];

for ($y = 1; $y <= $simulation_years; $y++) {
    // Determine monthly SIP (if within SIP period).
    $monthly_sip = ($y <= $years) ? round($sip * pow(1 + $stepup/100, $y - 1), 2) : 0;
    $annual_contribution = $monthly_sip * 12;
    
    // Determine monthly SWP (if SWP has started).
    $monthly_swp = ($y >= $swp_start) ? round($swp_withdrawal * pow(1 + $swp_stepup/100, $y - $swp_start), 2) : 0;
    // Instead of precomputing annual SWP, we'll sum actual withdrawals.
    $actual_year_withdrawn = 0;
    
    $year_begin = $net_balance;
    
    // Simulate month-by-month for the year.
    for ($m = 1; $m <= 12; $m++) {
         $contrib = ($y <= $years) ? $monthly_sip : 0;
         // Calculate available funds before withdrawal.
         $potential_balance = $net_balance + $contrib;
         if ($y >= $swp_start) {
             // Cap the withdrawal to available funds.
             $desired_withdraw = $monthly_swp;
             $withdraw = ($desired_withdraw > $potential_balance) ? $potential_balance : $desired_withdraw;
         } else {
             $withdraw = 0;
         }
         $actual_year_withdrawn += $withdraw;
         $net_balance = ($net_balance + $contrib - $withdraw) * (1 + $monthly_rate);
    }
    
    $annual_withdrawal = $actual_year_withdrawn;
    $interest_earned = $net_balance - ($year_begin + $annual_contribution - $annual_withdrawal);
    $cumulative_invested += $annual_contribution;
    if ($y >= $swp_start) {
         $cumulative_withdrawals += $annual_withdrawal;
    }
    
    $combined[$y] = [
         'year'                   => $y,
         'begin_balance'          => round($year_begin),
         'sip_monthly'            => ($y <= $years) ? $monthly_sip : null,
         'annual_contribution'    => $annual_contribution,
         'cumulative_invested'    => $cumulative_invested,
         'swp_monthly'            => ($y >= $swp_start) ? $monthly_swp : null,
         'annual_withdrawal'      => ($y >= $swp_start) ? $annual_withdrawal : null,
         'cumulative_withdrawals' => ($y >= $swp_start) ? $cumulative_withdrawals : 0,
         'interest'               => round($interest_earned),
         'combined_total'         => round($net_balance)
    ];
}

if ($action === 'download_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="SIP_SWP_Report.csv"');
    echo "\xEF\xBB\xBF"; // BOM for UTF-8 Excel
    $csv = new SplTempFileObject();
    $csv->setCsvControl(',', '"', "\\");
    $csv->fputcsv([
        'Year', 'Beginning Balance (₹)', 'Monthly SIP Investment (₹)', 'SIP Invested (Annual ₹)', 'Cumulative SIP Invested (₹)',
        'Monthly SWP Withdrawal (₹)', 'Annual SWP Withdrawal (₹)', 'Cumulative SWP Withdrawals (₹)', 'Interest Earned (Annual ₹)', 'Combined Total (₹)'
    ]);
    for ($y = 1; $y <= $simulation_years; $y++) {
        $row = $combined[$y];
        $csv->fputcsv([
            $row['year'],
            format_inr($row['begin_balance']),
            $row['sip_monthly'] !== null ? format_inr($row['sip_monthly']) : '-',
            format_inr($row['annual_contribution']),
            format_inr($row['cumulative_invested']),
            $row['swp_monthly'] !== null ? format_inr($row['swp_monthly']) : '-',
            $row['annual_withdrawal'] !== null ? format_inr($row['annual_withdrawal']) : '-',
            $row['cumulative_withdrawals'] ? format_inr($row['cumulative_withdrawals']) : '-',
            format_inr($row['interest']),
            format_inr($row['combined_total'])
        ]);
    }
    $csv->rewind();
    while (!$csv->eof()) {
        echo $csv->fgets();
    }
    exit;
}

// Prepare chart data for the line graph.
$years_data = array();
$cumulative_data = array();
$combined_data = array();
foreach($combined as $row) {
    $years_data[] = $row['year'];
    $cumulative_data[] = $row['cumulative_invested'];
    $combined_data[] = $row['combined_total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
  <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.0/dist/cyborg/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="container my-5">
  <header class="mb-4 text-center">
    <h1 class="mb-3">Free SIP & SWP Calculator</h1>
    <p class="lead">Visualize your investment growth with our integrated tool.</p>
  </header>
  <div class="card mb-4 shadow">
    <div class="card-body">
      <form method="post" novalidate>
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
        </fieldset>
        <fieldset class="mb-4">
          <legend class="mb-3">SWP Details</legend>
          <!-- SWP automatically starts in the year after SIP ends -->
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Monthly SWP Withdrawal (₹)</label>
              <input type="number" step="0.01" name="swp_withdrawal" class="form-control" required min="0" value="<?= htmlspecialchars((string)$swp_withdrawal) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Annual SWP Increase (%)</label>
              <input type="number" step="0.01" name="swp_stepup" class="form-control" required min="0" value="<?= htmlspecialchars((string)$swp_stepup) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Number of SWP Years</label>
              <input type="number" name="swp_years" class="form-control" required min="1" value="<?= htmlspecialchars((string)$swp_years_input) ?>">
            </div>
          </div>
          <p class="mt-2 small text-muted">Note: SWP automatically starts in the year immediately following your SIP period.</p>
        </fieldset>
        <div class="mb-3">
          <button type="submit" name="action" value="calculate" class="btn btn-primary me-2">Calculate</button>
          <button type="submit" name="action" value="download_csv" class="btn btn-secondary me-2">Download CSV Report</button>
          <button type="reset" class="btn btn-outline-danger">Reset</button>
        </div>
      </form>
    </div>
  </div>
  <!-- Chart Section -->
  <div class="card mb-4 shadow">
    <div class="card-body">
      <h2 class="card-title mb-4 text-center">Corpus vs. Cumulative Investment</h2>
      <canvas id="corpusChart"></canvas>
    </div>
  </div>
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'download_csv'): ?>
  <div class="card shadow">
    <div class="card-body">
      <h2 class="card-title mb-4">Combined SIP & SWP Report</h2>
      <div class="table-responsive">
        <table class="table table-bordered table-striped">
          <thead class="table-dark">
            <tr>
              <th style="width:5%;">Year</th>
              <th style="width:10%;">Beginning Balance (₹)</th>
              <th style="width:10%;">Monthly SIP Investment (₹)</th>
              <th style="width:10%;">SIP Invested (Annual ₹)</th>
              <th style="width:10%;">Cumulative SIP Invested (₹)</th>
              <th style="width:10%;">Monthly SWP Withdrawal (₹)</th>
              <th style="width:10%;">Annual SWP Withdrawal (₹)</th>
              <th style="width:10%;">Cumulative SWP Withdrawals (₹)</th>
              <th style="width:10%;">Interest Earned (Annual ₹)</th>
              <th style="width:10%;">Combined Total (₹)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($combined as $row): ?>
            <tr>
              <td><?= $row['year'] ?></td>
              <td><?= format_inr($row['begin_balance']) ?></td>
              <td><?= $row['sip_monthly'] !== null ? format_inr($row['sip_monthly']) : '-' ?></td>
              <td><?= format_inr($row['annual_contribution']) ?></td>
              <td><?= format_inr($row['cumulative_invested']) ?></td>
              <td><?= $row['swp_monthly'] !== null ? format_inr($row['swp_monthly']) : '-' ?></td>
              <td><?= $row['annual_withdrawal'] !== null ? format_inr($row['annual_withdrawal']) : '-' ?></td>
              <td><?= $row['cumulative_withdrawals'] ? format_inr($row['cumulative_withdrawals']) : '-' ?></td>
              <td><?= format_inr($row['interest']) ?></td>
              <td><?= format_inr($row['combined_total']) ?></td>
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
<script>
  // Prepare chart data.
  const yearsData = <?= json_encode(array_column($combined, 'year')) ?>;
  const cumulativeNumbers = <?= json_encode(array_column($combined, 'cumulative_invested')) ?>;
  const combinedNumbers = <?= json_encode(array_column($combined, 'combined_total')) ?>;
  
  const ctx = document.getElementById('corpusChart').getContext('2d');
  const corpusChart = new Chart(ctx, {
      type: 'line',
      data: {
          labels: yearsData,
          datasets: [
            {
              label: 'Cumulative SIP Invested (₹)',
              data: cumulativeNumbers,
              borderColor: 'rgba(75, 192, 192, 1)',
              backgroundColor: 'rgba(75, 192, 192, 0.2)',
              fill: false,
              tension: 0.1
            },
            {
              label: 'Combined Total (₹)',
              data: combinedNumbers,
              borderColor: 'rgba(153, 102, 255, 1)',
              backgroundColor: 'rgba(153, 102, 255, 0.2)',
              fill: false,
              tension: 0.1
            }
          ]
      },
      options: {
          responsive: true,
          plugins: {
              legend: {
                  position: 'top',
              },
              title: {
                  display: true,
                  text: 'Final Corpus vs. Cumulative Investment'
              }
          },
          scales: {
              x: {
                  title: {
                      display: true,
                      text: 'Year'
                  }
              },
              y: {
                  title: {
                      display: true,
                      text: 'Amount (₹)'
                  }
              }
          }
      }
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
