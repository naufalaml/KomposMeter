<?php
require_once __DIR__ . '/includes/functions.php';

// ── Ambil data dari database ─────────────────────────────────
$latest  = getLatestReading();
$recent  = getRecentRows(10);
$thr     = getThreshold();
$history = getHistory(24);

// ── Siapkan nilai untuk ditampilkan ──────────────────────────
$suhu     = isset($latest['suhu'])        ? (float)$latest['suhu']      : null;
$humid    = isset($latest['kelembapan']) ? (float)$latest['kelembapan']: null;
$amonia   = isset($latest['amonia'])     ? (float)$latest['amonia']    : null;
$status   = $latest['status_kematangan'] ?? 'Tidak Ada Data';
$fase     = $latest['fase_fermentasi']   ?? '-';
$estHari  = (int)($latest['estimasi_sisa_hari'] ?? 0);
$devIP    = $latest['ip_address'] ?? '-';
$devStat  = $latest['device_status'] ?? 'offline';
$waktu    = isset($latest['waktu_baca']) ? tglIndo($latest['waktu_baca']) : '-';

// Badge status per sensor
[$suhuBadgeType,   $suhuBadgeText]   = $suhu   !== null ? suhuBadge($suhu,   $thr) : ['warning','—'];
[$humidBadgeType,  $humidBadgeText]  = $humid  !== null ? humidBadge($humid, $thr) : ['warning','—'];
[$amoniaBadgeType, $amoniaBadgeText] = $amonia !== null ? amoniaBadge($amonia,$thr): ['warning','—'];

// Banner kelas CSS & deskripsi (Disesuaikan dengan status keluaran baru)
$bannerMap = [
    'Matang'     => ['matang', 'Pupuk kandang kohe kambing telah siap digunakan. Suhu adem, kelembapan mengering, dan amonia aman (bau hilang).'],
    'Blm Matang' => ['mentah', 'Pupuk masih dalam proses pematangan atau fermentasi aktif. Belum siap diaplikasikan ke tanaman.'],
];
[$bannerCls, $bannerDesc] = $bannerMap[$status] ?? ['proses', 'Menunggu data dari perangkat sensor.'];

// Range bar (0-100%)
$suhuBar   = $suhu   !== null ? min(100, round(($suhu/70)*100))   : 0;
$humidBar  = $humid  !== null ? min(100, round($humid))            : 0;
$amoniaBar = $amonia !== null ? min(100, round($amonia))           : 0;

// Siapkan data grafik (JSON untuk Chart.js)
$chartLabels  = [];
$chartSuhu    = [];
$chartHumid   = [];
$chartAmonia  = [];
foreach ($history as $row) {
    $chartLabels[]  = date('H:i', strtotime($row['waktu_baca']));
    $chartSuhu[]    = (float)$row['suhu'];
    $chartHumid[]   = (float)$row['kelembapan'];
    $chartAmonia[]  = (float)$row['amonia'];
}
$jsonLabels  = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
$jsonSuhu    = json_encode($chartSuhu);
$jsonHumid   = json_encode($chartHumid);
$jsonAmonia  = json_encode($chartAmonia);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>KomposMeter IoT – Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <style>
    /* ─── DESIGN TOKENS ──────────────────────────────────── */
    :root{
      --g-deep:#1E3F1A;--g-mid:#2D6A27;--g-light:#4A9E3E;--g-soft:#D6EAD3;
      --earth:#6B4423;--earth-l:#C4935A;
      --cream:#F8F4EC;--cream-d:#EDE7D8;
      --white:#FFFFFF;
      --tx:#1A1A18;--tx-mid:#4A4A44;--tx-soft:#7A7A72;
      --red:#C0392B;--red-s:#FADBD8;
      --orange:#E67E22;--orange-s:#FDEBD0;
      --r-sm:8px;--r-md:14px;--r-lg:22px;
      --sh-sm:0 2px 8px rgba(30,63,26,.08);
      --sh-md:0 6px 24px rgba(30,63,26,.12);
      --sh-lg:0 12px 40px rgba(30,63,26,.16);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--tx);min-height:100vh;font-size:15px}
    body::before{content:'';position:fixed;inset:0;
      background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
      pointer-events:none;z-index:0}

    /* ─── SIDEBAR ─────────────────────────────────────────── */
    .sidebar{position:fixed;top:0;left:0;width:240px;height:100vh;
      background:var(--g-deep);display:flex;flex-direction:column;z-index:100;overflow:hidden}
    .sidebar::after{content:'';position:absolute;bottom:-60px;right:-60px;width:200px;height:200px;
      border-radius:50%;background:rgba(74,158,62,.12)}
    .sb-brand{padding:28px 22px 20px;border-bottom:1px solid rgba(255,255,255,.08)}
    .brand-ico{width:40px;height:40px;background:var(--g-light);border-radius:10px;
      display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:10px}
    .brand-name{font-family:'DM Serif Display',serif;font-size:15px;color:#fff;line-height:1.3}
    .brand-sub{font-size:10px;color:rgba(255,255,255,.45);margin-top:3px;
      text-transform:uppercase;letter-spacing:.06em}
    .sb-nav{flex:1;padding:18px 12px;display:flex;flex-direction:column;gap:4px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:var(--r-sm);
      cursor:pointer;transition:all .2s;color:rgba(255,255,255,.55);font-size:13.5px;font-weight:500;
      text-decoration:none;border:none;background:none;width:100%;text-align:left}
    .nav-item .ico{font-size:17px}
    .nav-item:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85)}
    .nav-item.active{background:var(--g-light);color:#fff;box-shadow:0 4px 12px rgba(74,158,62,.4)}
    .sb-footer{padding:16px 22px;border-top:1px solid rgba(255,255,255,.08)}
    .dev-status{display:flex;align-items:center;gap:10px}
    .dot{width:8px;height:8px;border-radius:50%;background:var(--g-light);
      box-shadow:0 0 0 3px rgba(74,158,62,.3);animation:pulse 2s infinite}
    .dot.off{background:var(--red);box-shadow:0 0 0 3px rgba(192,57,43,.3);animation:none}
    @keyframes pulse{0%,100%{box-shadow:0 0 0 3px rgba(74,158,62,.3)}50%{box-shadow:0 0 0 6px rgba(74,158,62,.1)}}
    .dev-label{color:#fff;font-weight:600;font-size:13px}
    .dev-sub{font-size:12px;color:rgba(255,255,255,.5)}

    /* ─── MAIN ────────────────────────────────────────────── */
    .main{margin-left:240px;min-height:100vh;display:flex;flex-direction:column}

    /* ─── TOPBAR ──────────────────────────────────────────── */
    .topbar{background:rgba(248,244,236,.85);backdrop-filter:blur(12px);
      border-bottom:1px solid var(--cream-d);padding:14px 28px;
      display:flex;align-items:center;justify-content:space-between;
      position:sticky;top:0;z-index:50}
    .tb-title{font-family:'DM Serif Display',serif;font-size:20px;color:var(--g-deep)}
    .tb-right{display:flex;align-items:center;gap:16px}
    .last-upd{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--tx-soft);
      background:var(--cream-d);padding:5px 11px;border-radius:20px}
    .btn-refresh{background:var(--g-mid);color:#fff;border:none;padding:8px 18px;
      border-radius:20px;font-size:13px;font-weight:500;cursor:pointer;
      transition:all .2s;display:flex;align-items:center;gap:6px;font-family:'DM Sans',sans-serif}
    .btn-refresh:hover{background:var(--g-light);transform:translateY(-1px);box-shadow:var(--sh-sm)}
    .btn-refresh svg{transition:transform .5s}
    .spinning svg{animation:spin .7s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}

    /* ─── CONTENT ─────────────────────────────────────────── */
    .content{padding:28px;flex:1}

    /* ─── BANNER ──────────────────────────────────────────── */
    .banner{border-radius:var(--r-lg);padding:24px 30px;display:flex;
      align-items:center;justify-content:space-between;margin-bottom:28px;
      position:relative;overflow:hidden;transition:all .5s}
    .banner.matang{background:linear-gradient(135deg,var(--g-deep) 0%,var(--g-mid) 60%,var(--g-light) 100%);box-shadow:0 8px 30px rgba(30,63,26,.3)}
    .banner.mentah{background:linear-gradient(135deg,#7B3F00 0%,var(--earth) 60%,var(--earth-l) 100%);box-shadow:0 8px 30px rgba(107,68,35,.3)}
    .banner.proses{background:linear-gradient(135deg,#874b00 0%,var(--orange) 60%,#F39C12 100%);box-shadow:0 8px 30px rgba(230,126,34,.3)}
    .banner::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.07)}
    .bn-left{position:relative;z-index:1}
    .bn-label{font-size:12px;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px}
    .bn-status{font-family:'DM Serif Display',serif;font-size:36px;color:#fff;line-height:1}
    .bn-desc{font-size:13px;color:rgba(255,255,255,.70);margin-top:8px;max-width:500px}
    .bn-right{text-align:right;position:relative;z-index:1}
    .bn-days{font-family:'JetBrains Mono',monospace;font-size:52px;font-weight:600;color:#fff;line-height:1}
    .bn-days-lbl{font-size:13px;color:rgba(255,255,255,.65)}

    /* ─── SENSOR CARDS ────────────────────────────────────── */
    .sensor-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:28px}
    .sc{background:#fff;border-radius:var(--r-md);padding:22px;
      box-shadow:var(--sh-sm);border:1px solid var(--cream-d);
      position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
    .sc:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
    .sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-md) var(--r-md) 0 0}
    .sc.suhu::before{background:linear-gradient(90deg,#E74C3C,#E67E22)}
    .sc.humid::before{background:linear-gradient(90deg,#2980B9,#1ABC9C)}
    .sc.amonia::before{background:linear-gradient(90deg,#8E44AD,#3498DB)}
    .sc-hd{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px}
    .sc-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px}
    .suhu .sc-ico{background:#FDEDEC}.humid .sc-ico{background:#EBF5FB}.amonia .sc-ico{background:#F4ECF7}
    .badge{font-size:10.5px;font-weight:600;padding:4px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:.05em}
    .b-normal{background:var(--g-soft);color:var(--g-deep)}
    .b-warning{background:var(--orange-s);color:#7D4E00}
    .b-danger{background:var(--red-s);color:var(--red)}
    .sc-lbl{font-size:12px;color:var(--tx-soft);font-weight:500;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
    .sc-val{font-family:'JetBrains Mono',monospace;font-size:40px;font-weight:600;line-height:1;color:var(--tx)}
    .sc-unit{font-size:16px;color:var(--tx-soft);font-weight:400;margin-left:3px}
    .range{margin-top:16px}
    .rbar{height:6px;background:var(--cream-d);border-radius:3px;overflow:hidden}
    .rfill{height:100%;border-radius:3px;transition:width 1s ease}
    .suhu .rfill{background:linear-gradient(90deg,#F6D365,#FDA085)}
    .humid .rfill{background:linear-gradient(90deg,#74B9FF,#00CEC9)}
    .amonia .rfill{background:linear-gradient(90deg,#A29BFE,#6C5CE7)}
    .rlbls{display:flex;justify-content:space-between;font-size:10px;color:var(--tx-soft);margin-top:5px}

    /* ─── BOTTOM GRID ─────────────────────────────────────── */
    .bot-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px}

    /* ─── PANEL ───────────────────────────────────────────── */
    .panel{background:#fff;border-radius:var(--r-md);padding:22px;
      box-shadow:var(--sh-sm);border:1px solid var(--cream-d)}
    .ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    .pt{font-family:'DM Serif Display',serif;font-size:17px;color:var(--g-deep)}
    .chart-tabs{display:flex;gap:6px}
    .tab{font-size:11.5px;font-weight:500;padding:5px 12px;border-radius:16px;
      border:1px solid var(--cream-d);background:transparent;color:var(--tx-soft);
      cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
    .tab.active,.tab:hover{background:var(--g-deep);color:#fff;border-color:var(--g-deep)}
    .chart-wrap{position:relative;height:230px}

    /* ─── TABLE ───────────────────────────────────────────── */
    .tbl-wrap{overflow-x:auto;margin-top:22px}
    table{width:100%;border-collapse:collapse;font-size:13px}
    thead th{text-align:left;padding:8px 10px;font-size:10.5px;font-weight:600;
      text-transform:uppercase;letter-spacing:.06em;color:var(--tx-soft);
      border-bottom:2px solid var(--cream-d)}
    tbody td{padding:10px;border-bottom:1px solid var(--cream-d);color:var(--tx-mid);
      font-family:'JetBrains Mono',monospace;font-size:12.5px}
    tbody tr:last-child td{border-bottom:none}
    tbody tr:hover td{background:var(--cream)}
    .pill{display:inline-block;font-size:10.5px;font-weight:600;padding:3px 10px;border-radius:20px}
    .pill-matang{background:var(--g-soft);color:var(--g-deep)}
    .pill-mentah{background:var(--red-s);color:var(--red)}
    .pill-proses{background:var(--orange-s);color:#7D4E00}

    /* ─── INFO STACK ──────────────────────────────────────── */
    .info-stack{display:flex;flex-direction:column;gap:14px}
    .ic-title{font-size:11px;font-weight:600;text-transform:uppercase;
      letter-spacing:.06em;color:var(--tx-soft);margin-bottom:10px}
    .trow{display:flex;align-items:center;justify-content:space-between;
      font-size:13px;padding:5px 0;border-bottom:1px solid var(--cream-d);color:var(--tx-mid)}
    .trow:last-child{border-bottom:none}
    .tv{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;color:var(--g-deep)}
    .tv.red{color:var(--red)}
    .fstage{display:flex;align-items:center;gap:10px;margin-bottom:8px}
    .fstage span{font-size:13px;color:var(--tx-mid)}
    .fstage.current span{font-weight:700;color:var(--tx)!important}
    .sdot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
    .ic-note{background:var(--cream);border-radius:var(--r-sm);padding:14px;
      border:1px solid var(--cream-d);margin-top:10px;font-size:13px;color:var(--tx-mid)}

    /* ─── MOBILE ──────────────────────────────────────────── */
    .mob-tog{display:none;position:fixed;top:14px;left:14px;z-index:200;
      background:var(--g-deep);color:#fff;border:none;width:38px;height:38px;
      border-radius:10px;font-size:18px;cursor:pointer;align-items:center;justify-content:center}
    .overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:90}

    @media(max-width:1024px){.sensor-grid{grid-template-columns:repeat(2,1fr)}.bot-grid{grid-template-columns:1fr}}
    @media(max-width:768px){
      .mob-tog{display:flex}.sidebar{transform:translateX(-100%);transition:transform .3s}
      .sidebar.open{transform:translateX(0)}.overlay.open{display:block}
      .main{margin-left:0}.topbar{padding:12px 16px 12px 62px}
      .content{padding:16px}.sensor-grid{grid-template-columns:1fr}
      .banner{flex-direction:column;gap:14px;text-align:center}
      .bn-right{text-align:center}.bn-status{font-size:28px}.bn-days{font-size:40px}
    }
  </style>
</head>
<body>

<button class="mob-tog" id="mobTog">☰</button>
<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="brand-ico">🌿</div>
    <div class="brand-name">KomposMeter IoT</div>
    <div class="brand-sub">Pupuk Kandang · Kohe Kambing</div>
  </div>
  <nav class="sb-nav">
    <a class="nav-item active" href="index.php"><span class="ico">📊</span> Dashboard</a>
  </nav>
  <div class="sb-footer">
    <div class="dev-status">
      <div class="dot <?= $devStat !== 'online' ? 'off' : '' ?>"></div>
      <div>
        <div class="dev-label"><?= $devStat === 'online' ? 'Online' : 'Offline' ?></div>
        <div class="dev-sub">WeMos D1 Mini</div>
      </div>
    </div>
  </div>
</aside>

<div class="main">

  <div class="topbar">
    <div class="tb-title">Monitoring Kematangan Pupuk</div>
    <div class="tb-right">
      <div class="last-upd" id="lastUpd"><?= htmlspecialchars($waktu) ?></div>
      <button class="btn-refresh" id="btnRefresh" onclick="ajaxRefresh()">
        <svg id="refIco" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
        </svg>
        Refresh
      </button>
    </div>
  </div>

  <div class="content">

    <div class="banner <?= $bannerCls ?>" id="banner">
      <div class="bn-left">
        <div class="bn-label">Status Kematangan Pupuk</div>
        <div class="bn-status" id="bnStatus"><?= strtoupper($status) ?></div>
        <div class="bn-desc" id="bnDesc"><?= htmlspecialchars($bannerDesc) ?></div>
      </div>
      <div class="bn-right">
        <div class="bn-days" id="bnDays"><?= $estHari ?></div>
        <div class="bn-days-lbl">hari tersisa</div>
      </div>
    </div>

    <div class="sensor-grid">

      <div class="sc suhu">
        <div class="sc-hd">
          <div class="sc-ico">🌡️</div>
          <span class="badge b-<?= $suhuBadgeType ?>" id="badgeSuhu"><?= $suhuBadgeText ?></span>
        </div>
        <div class="sc-lbl">Suhu</div>
        <div class="sc-val">
          <span id="valSuhu"><?= $suhu !== null ? number_format($suhu,1) : '--' ?></span>
          <span class="sc-unit">°C</span>
        </div>
        <div class="range">
          <div class="rbar"><div class="rfill" id="barSuhu" style="width:<?= $suhuBar ?>%"></div></div>
          <div class="rlbls"><span>0°C</span><span>Matang: ≤<?= $thr['suhu_matang_max'] ?>°C</span><span>70°C</span></div>
        </div>
      </div>

      <div class="sc humid">
        <div class="sc-hd">
          <div class="sc-ico">💧</div>
          <span class="badge b-<?= $humidBadgeType ?>" id="badgeHumid"><?= $humidBadgeText ?></span>
        </div>
        <div class="sc-lbl">Kelembapan</div>
        <div class="sc-val">
          <span id="valHumid"><?= $humid !== null ? number_format($humid,1) : '--' ?></span>
          <span class="sc-unit">%</span>
        </div>
        <div class="range">
          <div class="rbar"><div class="rfill" id="barHumid" style="width:<?= $humidBar ?>%"></div></div>
          <div class="rlbls"><span>0%</span><span>Matang: &lt;<?= $thr['kelembapan_ideal_min'] ?>% (Kering)</span><span>100%</span></div>
        </div>
      </div>

      <div class="sc amonia">
        <div class="sc-hd">
          <div class="sc-ico">🧪</div>
          <span class="badge b-<?= $amoniaBadgeType ?>" id="badgeAmonia"><?= $amoniaBadgeText ?></span>
        </div>
        <div class="sc-lbl">Kadar Amonia (NH₃)</div>
        <div class="sc-val">
          <span id="valAmonia"><?= $amonia !== null ? number_format($amonia,1) : '--' ?></span>
          <span class="sc-unit">ppm</span>
        </div>
        <div class="range">
          <div class="rbar"><div class="rfill" id="barAmonia" style="width:<?= $amoniaBar ?>%"></div></div>
          <div class="rlbls"><span>0</span><span>Matang: ≤<?= $thr['amonia_matang_max'] ?>ppm</span><span>100</span></div>
        </div>
      </div>

    </div><div class="bot-grid">

      <div class="panel">
        <div class="ph">
          <div class="pt">Grafik Parameter Fermentasi</div>
          <div class="chart-tabs">
            <button class="tab active" onclick="loadChart(24,this)">24 Jam</button>
            <button class="tab" onclick="loadChart(72,this)">3 Hari</button>
            <button class="tab" onclick="loadChart(168,this)">7 Hari</button>
          </div>
        </div>
        <div class="chart-wrap"><canvas id="mainChart"></canvas></div>

        <div class="tbl-wrap">
          <div class="pt" style="margin-bottom:14px">Riwayat Pembacaan Terakhir</div>
          <table>
            <thead>
              <tr>
                <th>Waktu</th><th>Suhu (°C)</th>
                <th>Kelembapan (%)</th><th>Amonia (ppm)</th><th>Status</th>
              </tr>
            </thead>
            <tbody id="histTbody">

<?php if (!empty($recent)): ?>

<?php foreach($recent as $r): ?>

<?php

if($r['status_kematangan'] == 'Matang'){
    $pillCls = 'pill-matang';
}
elseif($r['status_kematangan'] == 'Mentah'){
    $pillCls = 'pill-mentah';
}
else{
    $pillCls = 'pill-proses';
}

?>

<tr>
    <td><?= tglIndo($r['waktu_baca']) ?></td>
    <td><?= number_format((float)$r['suhu'], 2) ?></td>
    <td><?= number_format((float)$r['kelembapan'], 2) ?></td>
    <td><?= number_format((float)$r['amonia'], 3) ?></td>

    <td>
        <span class="pill <?= $pillCls ?>">
            <?= $r['status_kematangan'] ?>
        </span>
    </td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
    <td colspan="5" style="text-align:center;color:gray">
        Belum ada data sensor
    </td>
</tr>

<?php endif; ?>

</tbody>
          </table>
        </div>
      </div>

      <div class="info-stack">
        <div class="panel">
          <div class="ph"><div class="pt">Aturan Logika Kematangan</div></div>
          <div class="ic-title">Kriteria Matang (Wajib Semua) ✓</div>
          <div class="trow"><span>Suhu</span><span class="tv">≤ <?= $thr['suhu_matang_max'] ?>°C (Adem)</span></div>
          <div class="trow"><span>Kelembapan</span><span class="tv">&lt; <?= $thr['kelembapan_ideal_min'] ?>% (Kering)</span></div>
          <div class="trow"><span>Amonia</span><span class="tv">≤ <?= $thr['amonia_matang_max'] ?> ppm (Bau hilang)</span></div>
          <br/>
          <div class="ic-title" style="margin-top:4px">Kriteria Belum Matang (Salah satu terpenuhi) ✗</div>
          <div class="trow"><span>Suhu</span><span class="tv red">&gt; <?= $thr['suhu_matang_max'] ?>°C</span></div>
          <div class="trow"><span>Kelembapan</span><span class="tv red">≥ <?= $thr['kelembapan_ideal_min'] ?>% (Masih Basah)</span></div>
          <div class="trow"><span>Amonia</span><span class="tv red">&gt; <?= $thr['amonia_matang_max'] ?> ppm (Bau Menyengat)</span></div>
        </div>

        <div class="panel">
          <div class="ph"><div class="pt">Fase Fermentasi</div></div>
          <?php
            $fases = [
              'Mesofilik'   => ['#F39C12', '35–50°C'],
              'Termofilik'  => ['#E74C3C', '≥ 50°C'],
              'Pematangan'  => ['#27AE60', '< 35°C / Stabil ✓'],
            ];
            foreach ($fases as $name => [$color, $desc]):
              $isCurrent = ($fase === $name);
          ?>
          <div class="fstage <?= $isCurrent ? 'current' : '' ?>">
            <div class="sdot" style="background:<?= $color ?>"></div>
            <span><?= $name ?> <small style="color:var(--tx-soft)">(<?= $desc ?>)</small></span>
            <?= $isCurrent ? '<span style="margin-left:auto;font-size:11px;background:var(--g-soft);color:var(--g-deep);padding:2px 8px;border-radius:20px;font-weight:600">Saat ini</span>' : '' ?>
          </div>
          <?php endforeach; ?>
          <div class="ic-note">📍 Pupuk Kandang Ngalah<br>Jl. Jombatan, Jombang, Jawa Timur</div>
        </div>

        <div class="panel">
          <div class="ph"><div class="pt">Info Perangkat</div></div>
          <div class="trow"><span>Mikrokontroler</span><span class="tv">WeMos D1 Mini</span></div>
          <div class="trow"><span>Sensor Suhu</span><span class="tv">DS18B20</span></div>
          <div class="trow"><span>Sensor Kelembapan</span><span class="tv">Cap. Soil</span></div>
          <div class="trow"><span>Sensor Amonia</span><span class="tv">MQ-135</span></div>
          <div class="trow"><span>IP Address</span><span class="tv"><?= htmlspecialchars($devIP) ?></span></div>
          <div class="trow"><span>Interval Kirim</span><span class="tv">30 detik</span></div>
          <div class="trow"><span>Fase Aktif</span><span class="tv"><?= htmlspecialchars($fase) ?></span></div>
        </div>
      </div></div></div></div><script>
/* ════════════════════════════════════════════════════════════
    DATA AWAL DARI PHP (server-side)
════════════════════════════════════════════════════════════ */
let chartLabels  = <?= $jsonLabels ?>;
let chartSuhu    = <?= $jsonSuhu ?>;
let chartHumid   = <?= $jsonHumid ?>;
let chartAmonia  = <?= $jsonAmonia ?>;
let chartInst    = null;

/* ════════════════════════════════════════════════════════════
    CHART.JS — inisialisasi dengan data PHP
════════════════════════════════════════════════════════════ */
function initChart(labels, suhu, humid, amonia) {
  if (chartInst) chartInst.destroy();
  const ctx = document.getElementById('mainChart').getContext('2d');
  chartInst = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label:'Suhu (°C)',        data:suhu,   borderColor:'#E74C3C', backgroundColor:'rgba(231,76,60,.08)',  fill:true, tension:.45, pointRadius:0, borderWidth:2 },
        { label:'Kelembapan (%)',   data:humid,  borderColor:'#2980B9', backgroundColor:'rgba(41,128,185,.08)', fill:true, tension:.45, pointRadius:0, borderWidth:2 },
        { label:'Amonia (ppm)',     data:amonia, borderColor:'#8E44AD', backgroundColor:'rgba(142,68,173,.08)', fill:true, tension:.45, pointRadius:0, borderWidth:2 },
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:false,
      interaction:{ mode:'index', intersect:false },
      plugins:{
        legend:{ position:'top', labels:{ font:{family:'DM Sans',size:12}, boxWidth:12, usePointStyle:true }},
        tooltip:{ backgroundColor:'#1E3F1A', titleFont:{family:'JetBrains Mono',size:11}, bodyFont:{family:'JetBrains Mono',size:12} }
      },
      scales:{
        x:{ grid:{color:'rgba(0,0,0,.04)'}, ticks:{font:{family:'JetBrains Mono',size:10}, maxTicksLimit:8, color:'#7A7A72'} },
        y:{ grid:{color:'rgba(0,0,0,.04)'}, ticks:{font:{family:'JetBrains Mono',size:10}, color:'#7A7A72'} }
      }
    }
  });
}

initChart(chartLabels, chartSuhu, chartHumid, chartAmonia);

function loadChart(hours, btn) {
  document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  fetch(`api/history.php?hours=${hours}`)
    .then(r => r.json())
    .then(res => {
      if (res.status !== 'ok') return;
      const labels = res.data.map(d => {
        const t = new Date(d.waktu_baca);
        return t.toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
      });
      initChart(
        labels,
        res.data.map(d => d.suhu),
        res.data.map(d => d.kelembapan),
        res.data.map(d => d.amonia)
      );
    });
}

/* ════════════════════════════════════════════════════════════
    AJAX REFRESH — perbarui kartu sensor tanpa reload penuh
════════════════════════════════════════════════════════════ */
const bannerClsMap = {
  'Matang':['matang','Pupuk kandang kohe kambing telah siap digunakan. Suhu adem, kelembapan mengering, dan amonia aman (bau hilang).'],
  'Blm Matang':['mentah','Pupuk masih dalam proses pematangan atau fermentasi aktif. Belum siap diaplikasikan ke tanaman.']
};

function ajaxRefresh() {
  const btn = document.getElementById('btnRefresh');
  btn.classList.add('spinning');

  fetch('api/latest.php')
    .then(r => r.json())
    .then(d => {
      if (d.status !== 'ok') return;

      // Sensor values
      setText('valSuhu',   d.suhu.toFixed(1));
      setText('valHumid',  d.kelembapan.toFixed(1));
      setText('valAmonia', d.amonia.toFixed(1));

      // Progress bars
      setWidth('barSuhu',   Math.min(100, (d.suhu/70)*100));
      setWidth('barHumid',  Math.min(100, d.kelembapan));
      setWidth('barAmonia', Math.min(100, d.amonia));

      // Banner
      const [cls, desc] = bannerClsMap[d.status_kematangan] || bannerClsMap['Blm Matang'];
      const banner = document.getElementById('banner');
      banner.className = 'banner ' + cls;
      setText('bnStatus', d.status_kematangan.toUpperCase());
      setText('bnDesc',   desc);
      setText('bnDays',   d.estimasi_hari);

      // Last update
      setText('lastUpd', new Date(d.waktu_baca).toLocaleString('id-ID'));

      // Reload chart (24 jam)
      loadChart(24, document.querySelector('.tab.active'));
    })
    .catch(e => console.error('Refresh error:', e))
    .finally(() => btn.classList.remove('spinning'));
}

function setText(id, val) { const el=document.getElementById(id); if(el) el.textContent=val; }
function setWidth(id, pct) { const el=document.getElementById(id); if(el) el.style.width=pct+'%'; }

// Auto-refresh setiap 30 detik
setInterval(ajaxRefresh, 10000);

/* ════════════════════════════════════════════════════════════
    MOBILE SIDEBAR
════════════════════════════════════════════════════════════ */
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('overlay');
document.getElementById('mobTog').addEventListener('click', () => {
  sidebar.classList.toggle('open'); overlay.classList.toggle('open');
});
overlay.addEventListener('click', () => {
  sidebar.remove('open'); overlay.classList.remove('open');
});
</script>
</body>
</html>
