<?php
/** @var string $activeNav */
/** @var string $pageTitle */
$appName = Config::get()['app_name'] ?? 'ORBITALEYE';
$serviceOk = (new PythonServiceClient())->health();
?>
<div class="mobile-topbar">
  <button type="button" class="hamburger-btn" id="hamburger-btn" aria-label="Apri il menu" aria-expanded="false">☰</button>
  <div class="logo">◈ <?= e($appName) ?></div>
</div>
<div class="sidebar-backdrop" id="sidebar-backdrop"></div>
<div class="app-shell">
  <aside class="sidebar" id="app-sidebar">
    <div class="brand">
      <div class="logo">◈ <?= e($appName) ?></div>
      <div class="tagline">Satellite Change Intelligence</div>
    </div>
    <nav class="nav">
      <a href="index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><span class="icon">▣</span> Dashboard</a>
      <a href="new_study.php" class="<?= $activeNav === 'new_study' ? 'active' : '' ?>"><span class="icon">✚</span> Nuovo Studio</a>
      <a href="library.php" class="<?= $activeNav === 'library' ? 'active' : '' ?>"><span class="icon">▤</span> Libreria</a>
      <a href="settings.php" class="<?= $activeNav === 'settings' ? 'active' : '' ?>"><span class="icon">⚙</span> Impostazioni</a>
    </nav>
    <div class="sidebar-footer">
      <div><span class="status-dot <?= $serviceOk ? 'ok' : 'bad' ?>"></span>Analysis Engine <?= $serviceOk ? 'ONLINE' : 'OFFLINE' ?></div>
      <div style="margin-top:8px;"><?= e($_SESSION['username'] ?? '') ?> · <a href="logout.php">esci</a></div>
    </div>
  </aside>
  <main class="main">
    <div class="topbar">
      <div>
        <h1><?= e($pageTitle ?? '') ?></h1>
      </div>
    </div>
