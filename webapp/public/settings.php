<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_defaults') {
        AppSettings::setMany([
            'default_diff_method' => $_POST['default_diff_method'] ?? 'ssim',
            'default_threshold' => (int) ($_POST['default_threshold'] ?? 30),
            'default_min_blob_area' => (int) ($_POST['default_min_blob_area'] ?? 40),
            'default_morph_kernel' => (int) ($_POST['default_morph_kernel'] ?? 3),
            'default_overlay_alpha' => (float) ($_POST['default_overlay_alpha'] ?? 0.35),
        ]);
        $message = 'Parametri predefiniti aggiornati.';
    }

    if ($action === 'save_sentinelhub') {
        AppSettings::setMany([
            'sentinelhub_client_id' => trim($_POST['sentinelhub_client_id'] ?? ''),
            'sentinelhub_client_secret' => trim($_POST['sentinelhub_client_secret'] ?? ''),
        ]);
        AppSettings::syncSentinelHubCredentialsFile();
        $message = 'Credenziali Sentinel Hub salvate e sincronizzate con il servizio di analisi.';
    }

    if ($action === 'save_esri') {
        AppSettings::setMany([
            'esri_api_key' => trim($_POST['esri_api_key'] ?? ''),
        ]);
        AppSettings::syncEsriCredentialsFile();
        $message = 'Impostazioni Esri World Imagery salvate e sincronizzate con il servizio di analisi.';
    }

    if ($action === 'save_telegram') {
        AppSettings::setMany([
            'telegram_bot_token' => trim($_POST['telegram_bot_token'] ?? ''),
            'telegram_chat_id' => trim($_POST['telegram_chat_id'] ?? ''),
        ]);
        $message = 'Impostazioni Telegram salvate.';
    }

    if ($action === 'test_telegram') {
        try {
            $client = new TelegramClient();
            $client->sendMessage('OrbitalEye: test di connessione riuscito. Il canale è configurato correttamente.');
            $message = 'Messaggio di test inviato: controlla il canale/chat configurato.';
        } catch (Throwable $e) {
            $error = 'Test fallito: ' . $e->getMessage();
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';

        if (!Auth::attempt($_SESSION['username'], $current)) {
            $error = 'Password attuale errata.';
        } elseif (strlen($new) < 8) {
            $error = 'La nuova password deve avere almeno 8 caratteri.';
        } elseif ($new !== $new2) {
            $error = 'Le due password non coincidono.';
        } else {
            Auth::changePassword((int) $_SESSION['user_id'], $new);
            $message = 'Password aggiornata.';
        }
    }
}

$settings = AppSettings::all();
$pageTitle = 'Impostazioni';
$activeNav = 'settings';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="grid grid-3">
  <div class="panel">
    <h2>Credenziali Copernicus / Sentinel Hub</h2>
    <p class="hint">Necessarie per il fetch automatico delle riprese Sentinel-2 (~10m/pixel). Crea un client OAuth gratuito su
      <a href="https://dataspace.copernicus.eu" target="_blank" rel="noopener">dataspace.copernicus.eu</a>.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_sentinelhub">
      <div class="field">
        <label>Client ID</label>
        <input type="text" name="sentinelhub_client_id" value="<?= e($settings['sentinelhub_client_id']) ?>">
      </div>
      <div class="field">
        <label>Client Secret</label>
        <input type="password" name="sentinelhub_client_secret" value="<?= e($settings['sentinelhub_client_secret']) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Salva credenziali</button>
    </form>
  </div>

  <div class="panel">
    <h2>Esri World Imagery</h2>
    <p class="hint">Risoluzione più alta (varia per area, spesso sub-metrica) tramite l'operazione REST ufficiale <code class="k">/export</code> del servizio pubblico Esri. Funziona anche senza API key per uso leggero/occasionale; per un uso sostenuto crea un account gratuito su
      <a href="https://developers.arcgis.com" target="_blank" rel="noopener">developers.arcgis.com</a> e incolla qui il token.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_esri">
      <div class="field">
        <label>API key / token (opzionale)</label>
        <input type="password" name="esri_api_key" value="<?= e($settings['esri_api_key']) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Salva</button>
    </form>
  </div>

  <div class="panel">
    <h2>Condivisione — Telegram</h2>
    <p class="hint">Per la funzione "Condividi" su riprese, confronti e riepiloghi di studio. Crea un bot gratuito parlando con
      <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> su Telegram, poi aggiungilo come
      <strong>amministratore</strong> (permesso "Pubblica messaggi") del canale/gruppo di destinazione — senza
      questo passaggio l'invio fallisce con "chat not found" anche con token e ID corretti.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_telegram">
      <div class="field">
        <label>Token del bot</label>
        <input type="password" name="telegram_bot_token" value="<?= e($settings['telegram_bot_token']) ?>">
      </div>
      <div class="field">
        <label>ID canale/chat <span class="info-tip" tabindex="0" data-tip="Per un canale è un numero negativo che inizia con -100 (es. -1001234567890). Si trova inoltrando un messaggio del canale a @userinfobot, o dai log della prima chiamata API se il bot è già stato aggiunto.">?</span></label>
        <input type="text" name="telegram_chat_id" value="<?= e($settings['telegram_chat_id']) ?>" placeholder="-1001234567890">
      </div>
      <button class="btn btn-primary" type="submit">Salva</button>
    </form>
    <form method="post" style="margin-top:10px;">
      <input type="hidden" name="action" value="test_telegram">
      <button class="btn btn-sm" type="submit">✉ Invia messaggio di test</button>
    </form>
  </div>

  <div class="panel">
    <h2>Parametri di analisi predefiniti</h2>
    <form method="post">
      <input type="hidden" name="action" value="save_defaults">
      <div class="field">
        <label>Metodo diff</label>
        <select name="default_diff_method">
          <option value="ssim" <?= $settings['default_diff_method']==='ssim'?'selected':'' ?>>SSIM</option>
          <option value="absdiff" <?= $settings['default_diff_method']==='absdiff'?'selected':'' ?>>Differenza assoluta</option>
        </select>
      </div>
      <div class="field"><label>Soglia threshold predefinita</label><input type="number" name="default_threshold" value="<?= e($settings['default_threshold']) ?>" min="1" max="255"></div>
      <div class="field"><label>Area minima blob predefinita</label><input type="number" name="default_min_blob_area" value="<?= e($settings['default_min_blob_area']) ?>" min="0"></div>
      <div class="field"><label>Kernel morfologico predefinito</label><input type="number" name="default_morph_kernel" value="<?= e($settings['default_morph_kernel']) ?>" min="1" max="15"></div>
      <div class="field"><label>Opacità overlay predefinita</label><input type="number" step="0.05" name="default_overlay_alpha" value="<?= e($settings['default_overlay_alpha']) ?>" min="0.05" max="1"></div>
      <button class="btn btn-primary" type="submit">Salva parametri</button>
    </form>
  </div>
</div>

<div class="panel" style="max-width:480px;">
  <h2>Cambia password</h2>
  <form method="post">
    <input type="hidden" name="action" value="change_password">
    <div class="field"><label>Password attuale</label><input type="password" name="current_password" required></div>
    <div class="field"><label>Nuova password</label><input type="password" name="new_password" required minlength="8"></div>
    <div class="field"><label>Conferma nuova password</label><input type="password" name="new_password2" required minlength="8"></div>
    <button class="btn btn-primary" type="submit">Aggiorna password</button>
  </form>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
