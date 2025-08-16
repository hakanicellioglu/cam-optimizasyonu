<?php

declare(strict_types=1);

class Rect
{
  public int $x;
  public int $y;
  public int $w;
  public int $h;
  public function __construct(int $x, int $y, int $w, int $h)
  {
    $this->x = $x;
    $this->y = $y;
    $this->w = $w;
    $this->h = $h;
  }
}

class Placement extends Rect
{
  public string $code;
  public int $rawW;
  public int $rawH;
  public bool $rotated;

  public function __construct(int $x, int $y, int $w, int $h, string $code, int $rawW, int $rawH, bool $rotated)
  {
    parent::__construct($x, $y, $w, $h);
    $this->code = $code;
    $this->rawW = $rawW;
    $this->rawH = $rawH;
    $this->rotated = $rotated;
  }
}


final class Sheet
{
  public int $W;
  public int $H;
  public string $label;
  public int $kerf;
  public int $margin;
  /** @var Rect[] */  public array $free = [];
  /** @var Placement[] */ public array $used = [];

  public function __construct(int $W, int $H, string $label, int $kerf = 0, int $margin = 0)
  {
    if ($W <= 0 || $H <= 0) throw new RuntimeException("Levha boyutları geçersiz.");
    $this->W = $W;
    $this->H = $H;
    $this->label = $label;
    $this->kerf = $kerf;
    $this->margin = $margin;
    $innerW = $W - 2 * $margin;
    $innerH = $H - 2 * $margin;
    if ($innerW <= 0 || $innerH <= 0) throw new RuntimeException("Margin nedeniyle iç alan kalmadı: $label");
    $this->free[] = new Rect($margin, $margin, $innerW, $innerH);
  }

  public function place(int $partW, int $partH, string $code, bool $allowRotate = true): ?Placement
  {
    $tw = $partW + $this->kerf;
    $th = $partH + $this->kerf;
    $cands = [['w' => $tw, 'h' => $th, 'rot' => false]];
    if ($allowRotate && $partW !== $partH) $cands[] = ['w' => $th, 'h' => $tw, 'rot' => true];

    $best = null;
    foreach ($cands as $c) {
      foreach ($this->free as $fi => $fr) {
        if ($c['w'] <= $fr->w && $c['h'] <= $fr->h) {
          $leftS = min($fr->w - $c['w'], $fr->h - $c['h']);
          $leftA = ($fr->w * $fr->h) - ($c['w'] * $c['h']);
          if ($best === null || $leftS < $best['s'] || ($leftS === $best['s'] && $leftA < $best['a'])) {
            $best = ['fi' => $fi, 'x' => $fr->x, 'y' => $fr->y, 'w' => $c['w'], 'h' => $c['h'], 'rot' => $c['rot'], 's' => $leftS, 'a' => $leftA];
          }
        }
      }
    }
    if ($best === null) return null;

    $use = new Rect($best['x'], $best['y'], $best['w'], $best['h']);
    $this->splitAndPrune($best['fi'], $use);

    $pl = new Placement(
      $use->x,
      $use->y,
      $use->w,
      $use->h,
      $code,
      $best['rot'] ? $partH : $partW,
      $best['rot'] ? $partW : $partH,
      (bool)$best['rot']
    );
    $this->used[] = $pl;
    return $pl;
  }

  private function splitAndPrune(int $freeIndex, Rect $used): void
  {
    $fr = $this->free[$freeIndex];
    $new = [];
    // Sol
    if ($used->x > $fr->x) $new[] = new Rect($fr->x, $fr->y, $used->x - $fr->x, $fr->h);
    // Sağ
    if ($used->x + $used->w < $fr->x + $fr->w) $new[] = new Rect($used->x + $used->w, $fr->y, ($fr->x + $fr->w) - ($used->x + $used->w), $fr->h);
    // Üst
    if ($used->y > $fr->y) {
      $nx = max($fr->x, $used->x);
      $nw = min($fr->x + $fr->w, $used->x + $used->w) - $nx;
      if ($nw > 0) $new[] = new Rect($nx, $fr->y, $nw, $used->y - $fr->y);
    }
    // Alt
    if ($used->y + $used->h < $fr->y + $fr->h) {
      $nx = max($fr->x, $used->x);
      $nw = min($fr->x + $fr->w, $used->x + $used->w) - $nx;
      if ($nw > 0) $new[] = new Rect($nx, $used->y + $used->h, $nw, ($fr->y + $fr->h) - ($used->y + $used->h));
    }
    array_splice($this->free, $freeIndex, 1);
    foreach ($new as $nf) if ($nf->w > 0 && $nf->h > 0) $this->free[] = $nf;
    $this->prune();
  }

  private function prune(): void
  {
    for ($i = 0; $i < count($this->free); $i++) {
      for ($j = $i + 1; $j < count($this->free); $j++) {
        $a = $this->free[$i];
        $b = $this->free[$j];
        if ($this->contains($a, $b)) {
          array_splice($this->free, $i, 1);
          $i--;
          break;
        }
        if ($this->contains($b, $a)) {
          array_splice($this->free, $j, 1);
          $j--;
        }
      }
    }
  }
  private function contains(Rect $a, Rect $b): bool
  {
    return $b->x >= $a->x && $b->y >= $a->y && $b->x + $b->w <= $a->x + $a->w && $b->y + $b->h <= $a->y + $a->h;
  }

  public function usedAreaReal(): int
  {
    $sum = 0;
    foreach ($this->used as $u) {
      $sum += $u->rawW * $u->rawH;
    }
    return $sum;
  }
  public function totalArea(): int
  {
    return $this->W * $this->H;
  }

  public function toSVG(): string
  {
    $b = 2;
    $svg = [];
    $svg[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">', $this->W + 2 * $b, $this->H + 2 * $b);
    $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="black" stroke-width="1"/>', $b, $b, $this->W, $this->H);
    $svg[] = sprintf('<text x="%d" y="%d" font-size="14">%s (%dx%d)</text>', $b + 6, $b + 18, htmlspecialchars($this->label), $this->W, $this->H);
    foreach ($this->used as $u) {
      $x = $b + $u->x;
      $y = $b + $u->y;
      $svg[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="blue" stroke-width="1"/>', $x, $y, $u->w, $u->h);
      $cap = $u->code . ' ' . $u->rawW . 'x' . $u->rawH . ($u->rotated ? ' (R)' : '');
      $svg[] = sprintf('<text x="%d" y="%d" font-size="12">%s</text>', $x + 4, $y + 16, htmlspecialchars($cap));
    }
    $svg[] = '</svg>';
    return implode("\n", $svg);
  }
}

final class Optimizer
{
  /** @var array<int,array{w:int,h:int,label:string}> */
  private array $stockSizes;
  private int $kerf;
  private int $margin;
  private bool $allowRotate;
  /** @var Sheet[] */ private array $sheets = [];

  public function __construct(array $stockSizes, int $kerf = 3, int $margin = 10, bool $allowRotate = true)
  {
    $this->stockSizes = $stockSizes;
    $this->kerf = $kerf;
    $this->margin = $margin;
    $this->allowRotate = $allowRotate;
  }

  /**
   * @param array<int,array{code:string,w:int,h:int,qty:int}> $parts
   * @return array{ sheets: Sheet[], summary: array<string,mixed> }
   */
  public function run(array $parts): array
  {
    $expanded = [];
    foreach ($parts as $p) {
      $qty = max(1, (int)$p['qty']);
      for ($i = 0; $i < $qty; $i++) $expanded[] = $p;
    }
    usort($expanded, fn($a, $b) => ($b['w'] * $b['h']) <=> ($a['w'] * $a['h']));

    foreach ($expanded as $p) {
      if (!$this->placeOnExisting($p)) {
        $sheet = $this->openBestSheet($p);
        if (!$sheet) throw new RuntimeException("Parça sığabileceği levha yok: {$p['code']} {$p['w']}x{$p['h']}");
        if (!$sheet->place((int)$p['w'], (int)$p['h'], (string)$p['code'], $this->allowRotate)) {
          throw new RuntimeException("Yeni levhaya yerleştirme başarısız: {$p['code']}.");
        }
      }
    }

    $totSheet = 0;
    $totUsed = 0;
    foreach ($this->sheets as $s) {
      $totSheet += $s->totalArea();
      $totUsed += $s->usedAreaReal();
    }
    $waste = $totSheet - $totUsed;
    $wastePct = $totSheet > 0 ? round(100 * $waste / $totSheet, 2) : 0.0;

    return [
      'sheets' => $this->sheets,
      'summary' => [
        'sheet_count' => count($this->sheets),
        'total_sheet_m2' => round($totSheet / 1_000_000, 3),
        'total_used_m2' => round($totUsed / 1_000_000, 3),
        'waste_m2' => round($waste / 1_000_000, 3),
        'waste_pct' => $wastePct,
        'kerf_mm' => $this->kerf,
        'margin_mm' => $this->margin,
        'rotate' => $this->allowRotate ? 'Evet' : 'Hayır',
      ],
    ];
  }

  private function placeOnExisting(array $p): bool
  {
    foreach ($this->sheets as $s) {
      if ($s->place((int)$p['w'], (int)$p['h'], (string)$p['code'], $this->allowRotate)) return true;
    }
    return false;
  }

  private function openBestSheet(array $p): ?Sheet
  {
    $needW = (int)$p['w'] + $this->kerf + 2 * $this->margin;
    $needH = (int)$p['h'] + $this->kerf + 2 * $this->margin;
    $cand = [];
    foreach ($this->stockSizes as $ss) {
      $W = (int)$ss['w'];
      $H = (int)$ss['h'];
      if (($needW <= $W && $needH <= $H) || ($this->allowRotate && $needW <= $H && $needH <= $W)) {
        $cand[] = ['W' => $W, 'H' => $H, 'label' => $ss['label'], 'area' => $W * $H];
      }
    }
    if (!$cand) return null;
    usort($cand, fn($a, $b) => $a['area'] <=> $b['area']);
    $pick = $cand[0];
    $sheet = new Sheet($pick['W'], $pick['H'], $pick['label'], $this->kerf, $this->margin);
    $this->sheets[] = $sheet;
    return $sheet;
  }
}

/* =======================
   2) Form İşleme
   ======================= */

function postArray(string $key): array
{
  return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
}

$errors = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Kerf / margin / rotate
    $kerf   = max(0, (int)($_POST['kerf']   ?? 3));
    $margin = max(0, (int)($_POST['margin'] ?? 10));
    $rotate = isset($_POST['allow_rotate']) && $_POST['allow_rotate'] === '1';

    // Levhalar
    $sw = postArray('sheet_w');
    $sh = postArray('sheet_h');
    $sl = postArray('sheet_label');
    $stocks = [];
    for ($i = 0; $i < count($sw); $i++) {
      $w = (int)$sw[$i];
      $h = (int)$sh[$i];
      $label = trim($sl[$i] ?? '');
      if ($w > 0 && $h > 0) {
        if ($label === '') $label = "Levha " . ($i + 1);
        $stocks[] = ['w' => $w, 'h' => $h, 'label' => $label];
      }
    }
    if (!$stocks) throw new RuntimeException("En az bir levha giriniz.");

    // Parçalar
    $pc = postArray('part_code');
    $pw = postArray('part_w');
    $ph = postArray('part_h');
    $pq = postArray('part_qty');
    $parts = [];
    for ($i = 0; $i < count($pw); $i++) {
      $code = trim($pc[$i] ?? '');
      $w = (int)$pw[$i];
      $h = (int)$ph[$i];
      $q = max(0, (int)$pq[$i]);
      if ($w > 0 && $h > 0 && $q > 0) {
        if ($code === '') $code = 'P' . ($i + 1);
        $parts[] = ['code' => $code, 'w' => $w, 'h' => $h, 'qty' => $q];
      }
    }
    if (!$parts) throw new RuntimeException("En az bir parça giriniz.");

    $optimizer = new Optimizer($stocks, $kerf, $margin, $rotate);
    $result = $optimizer->run($parts);
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

/* =======================
   3) Arayüz (Bootstrap 5 CDN)
   ======================= */
?>
<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cam Optimizasyon (MVP)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .small-input {
      max-width: 120px;
    }

    .svg-box {
      overflow: auto;
      border: 1px solid #dee2e6;
      padding: 8px;
    }

    .sticky-actions {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 5;
      padding: .5rem 0;
    }

    .code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    }
  </style>
</head>

<body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h3 mb-0">Cam Optimizasyon</h1>
      <div class="text-muted">MVP • PHP</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Hata:</strong>
        <ul class="mb-0"><?php foreach ($errors as $er) echo "<li>" . htmlspecialchars($er) . "</li>"; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" class="card mb-4">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6 col-md-3">
            <label class="form-label">Kerf (mm)</label>
            <input type="number" name="kerf" class="form-control" value="<?= htmlspecialchars((string)($_POST['kerf'] ?? 3)) ?>" min="0">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label">Kenar Payı (Margin, mm)</label>
            <input type="number" name="margin" class="form-control" value="<?= htmlspecialchars((string)($_POST['margin'] ?? 10)) ?>" min="0">
          </div>
          <div class="col-12 col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="allow_rotate" value="1" id="rot" <?= isset($_POST['allow_rotate']) && $_POST['allow_rotate'] === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="rot">Rotasyona izin ver (90°)</label>
            </div>
          </div>
        </div>

        <hr class="my-4">

        <h6 class="mb-2">Levhalar</h6>
        <div id="sheets" class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light sticky-top">
              <tr>
                <th>Etiket</th>
                <th>Genişlik (mm)</th>
                <th>Yükseklik (mm)</th>
                <th style="width:48px;"></th>
              </tr>
            </thead>
            <tbody id="sheet-tbody">
              <?php
              $sheetRows = [];
              if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $sheetLabels = postArray('sheet_label');
                $sheetW = postArray('sheet_w');
                $sheetH = postArray('sheet_h');
                for ($i = 0; $i < count($sheetW); $i++) {
                  $sheetRows[] = [
                    'label' => htmlspecialchars((string)($sheetLabels[$i] ?? ('Levha ' . ($i + 1)))),
                    'w' => htmlspecialchars((string)$sheetW[$i] ?? ''),
                    'h' => htmlspecialchars((string)$sheetH[$i] ?? ''),
                  ];
                }
              }
              if (!$sheetRows) {
                $sheetRows = [
                  ['label' => 'Float 3210x2250', 'w' => '3210', 'h' => '2250'],
                  ['label' => 'Float 2550x1605', 'w' => '2550', 'h' => '1605'],
                ];
              }
              foreach ($sheetRows as $r): ?>
                <tr>
                  <td><input name="sheet_label[]" class="form-control" value="<?= $r['label'] ?>"></td>
                  <td><input name="sheet_w[]" type="number" class="form-control small-input" value="<?= $r['w'] ?>" min="1"></td>
                  <td><input name="sheet_h[]" type="number" class="form-control small-input" value="<?= $r['h'] ?>" min="1"></td>
                  <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)">Sil</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="sticky-actions">
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addSheet()">+ Levha Ekle</button>
        </div>

        <hr class="my-4">

        <h6 class="mb-2">Parçalar</h6>
        <div id="parts" class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light sticky-top">
              <tr>
                <th>Kod</th>
                <th>Genişlik (mm)</th>
                <th>Yükseklik (mm)</th>
                <th>Adet</th>
                <th style="width:48px;"></th>
              </tr>
            </thead>
            <tbody id="part-tbody">
              <?php
              $partRows = [];
              if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $pc = postArray('part_code');
                $pw = postArray('part_w');
                $ph = postArray('part_h');
                $pq = postArray('part_qty');
                for ($i = 0; $i < count($pw); $i++) {
                  $partRows[] = [
                    'code' => htmlspecialchars((string)($pc[$i] ?? ('P' . ($i + 1)))),
                    'w' => htmlspecialchars((string)$pw[$i] ?? ''),
                    'h' => htmlspecialchars((string)$ph[$i] ?? ''),
                    'q' => htmlspecialchars((string)$pq[$i] ?? '1'),
                  ];
                }
              }
              if (!$partRows) {
                $partRows = [
                  ['code' => 'P1', 'w' => '1200', 'h' => '800', 'q' => '6'],
                  ['code' => 'P2', 'w' => '900', 'h' => '600', 'q' => '8'],
                  ['code' => 'P3', 'w' => '450', 'h' => '450', 'q' => '10'],
                ];
              }
              foreach ($partRows as $r): ?>
                <tr>
                  <td><input name="part_code[]" class="form-control" value="<?= $r['code'] ?>"></td>
                  <td><input name="part_w[]" type="number" class="form-control small-input" value="<?= $r['w'] ?>" min="1"></td>
                  <td><input name="part_h[]" type="number" class="form-control small-input" value="<?= $r['h'] ?>" min="1"></td>
                  <td><input name="part_qty[]" type="number" class="form-control small-input" value="<?= $r['q'] ?>" min="1"></td>
                  <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)">Sil</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="sticky-actions">
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addPart()">+ Parça Ekle</button>
        </div>
      </div>

      <div class="card-footer d-flex gap-2">
        <button type="submit" class="btn btn-success">Optimize Et</button>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Yazdır</button>
      </div>
    </form>

    <?php if ($result): ?>
      <div class="card mb-4">
        <div class="card-header"><strong>Özet</strong></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-2">
              <div class="fw-semibold">Levha Sayısı</div><?= (int)$result['summary']['sheet_count'] ?>
            </div>
            <div class="col-md-2">
              <div class="fw-semibold">Toplam Levha (m²)</div><?= $result['summary']['total_sheet_m2'] ?>
            </div>
            <div class="col-md-2">
              <div class="fw-semibold">Kullanılan (m²)</div><?= $result['summary']['total_used_m2'] ?>
            </div>
            <div class="col-md-2">
              <div class="fw-semibold">Fire (m²)</div><?= $result['summary']['waste_m2'] ?>
            </div>
            <div class="col-md-2">
              <div class="fw-semibold">Fire (%)</div><?= $result['summary']['waste_pct'] ?>%
            </div>
            <div class="col-md-2">
              <div class="fw-semibold">Parametreler</div>
              <div class="text-muted small">Kerf: <?= $result['summary']['kerf_mm'] ?> mm</div>
              <div class="text-muted small">Margin: <?= $result['summary']['margin_mm'] ?> mm</div>
              <div class="text-muted small">Rotasyon: <?= htmlspecialchars((string)$result['summary']['rotate']) ?></div>
            </div>
          </div>
        </div>
      </div>

      <?php foreach ($result['sheets'] as $i => $s): ?>
        <?php
        /** @var Sheet $s */
        $svg = $s->toSVG();
        $svgData = 'data:image/svg+xml;base64,' . base64_encode($svg);
        $fname = "cutplan_sheet_" . ($i + 1) . ".svg";
        ?>
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Kesim Planı #<?= $i + 1 ?> — <?= htmlspecialchars($s->label) ?> (<?= $s->W ?>×<?= $s->H ?>)</strong>
            <a class="btn btn-outline-primary btn-sm" href="data:image/svg+xml,<?= rawurlencode($svg) ?>" download="<?= $fname ?>">SVG indir</a>
          </div>
          <div class="card-body">
            <div class="svg-box">
              <img alt="Kesim Planı SVG" src="<?= $svgData ?>">
            </div>
            <?php if ($s->used): ?>
              <div class="table-responsive mt-3">
                <table class="table table-sm table-striped">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>Kod</th>
                      <th>Parça (W×H)</th>
                      <th>Rotasyon</th>
                      <th>Yer (X,Y)</th>
                      <th>Yerleşim (W×H)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($s->used as $k => $u): ?>
                      <tr>
                        <td><?= $k + 1 ?></td>
                        <td class="code"><?= htmlspecialchars($u->code) ?></td>
                        <td><?= $u->rawW ?> × <?= $u->rawH ?> mm</td>
                        <td><?= $u->rotated ? 'Evet' : 'Hayır' ?></td>
                        <td><?= $u->x ?> , <?= $u->y ?></td>
                        <td><?= $u->w ?> × <?= $u->h ?> mm</td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="text-muted small mt-4">
      <strong>Not:</strong> Bu MVP dikdörtgen parçalar içindir. Büyük veri setlerinde performans için serbest alan birleştirme (merge) ve farklı yerleştirme sezgileri eklenebilir.
    </div>
  </div>

  <script>
    function removeRow(btn) {
      const tr = btn.closest('tr');
      if (tr) tr.remove();
    }

    function addSheet() {
      const tb = document.getElementById('sheet-tbody');
      const tr = document.createElement('tr');
      tr.innerHTML = `
    <td><input name="sheet_label[]" class="form-control" value=""></td>
    <td><input name="sheet_w[]" type="number" class="form-control small-input" min="1" placeholder="Genişlik"></td>
    <td><input name="sheet_h[]" type="number" class="form-control small-input" min="1" placeholder="Yükseklik"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)">Sil</button></td>
  `;
      tb.appendChild(tr);
    }

    function addPart() {
      const tb = document.getElementById('part-tbody');
      const tr = document.createElement('tr');
      tr.innerHTML = `
    <td><input name="part_code[]" class="form-control" placeholder="Kod"></td>
    <td><input name="part_w[]" type="number" class="form-control small-input" min="1" placeholder="Genişlik"></td>
    <td><input name="part_h[]" type="number" class="form-control small-input" min="1" placeholder="Yükseklik"></td>
    <td><input name="part_qty[]" type="number" class="form-control small-input" min="1" value="1"></td>
    <td><button type="button" class="btn btn-outline-danger btn-sm" onclick="removeRow(this)">Sil</button></td>
  `;
      tb.appendChild(tr);
    }
  </script>
</body>

</html>