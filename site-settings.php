<?php
// site-settings.php (admin fragment)
// Provides: General settings + Bank Management
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Create simple settings table if missing
try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
      `k` VARCHAR(100) NOT NULL PRIMARY KEY,
      `v` TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}catch(Exception $e){ /* ignore */ }

// Create banks table if missing
try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `banks` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(255) NOT NULL,
      `enabled` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}catch(Exception $e){ /* ignore */ }

// helper to get/set settings
function get_setting($pdo, $k, $default = null){
    $sth = $pdo->prepare('SELECT v FROM settings WHERE k = :k LIMIT 1');
    $sth->execute([':k'=>$k]);
    $v = $sth->fetchColumn();
    return $v === false ? $default : $v;
}
function set_setting($pdo, $k, $v){
    $ins = $pdo->prepare('INSERT INTO settings (k,v) VALUES (:k,:v) ON DUPLICATE KEY UPDATE v = :v2');
    $ins->execute([':k'=>$k, ':v'=>$v, ':v2'=>$v]);
}

$msg = '';
$errors = [];

// Handle POST actions
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    // Save general settings
    if(!empty($_POST['action']) && $_POST['action'] === 'save_general'){
        $site_name = trim((string)($_POST['site_name'] ?? ''));
        $contact_email = trim((string)($_POST['contact_email'] ?? ''));
        $color_primary = trim((string)($_POST['color_primary'] ?? '#EE6F57'));
        $color_secondary = trim((string)($_POST['color_secondary'] ?? '#CB3737'));
        $default_bank = trim((string)($_POST['default_bank'] ?? ''));
        try{
            set_setting($pdo, 'site_name', $site_name);
            set_setting($pdo, 'contact_email', $contact_email);
            set_setting($pdo, 'color_primary', $color_primary);
            set_setting($pdo, 'color_secondary', $color_secondary);
            set_setting($pdo, 'default_bank', $default_bank);
            $msg = 'General settings saved.';
        }catch(Exception $e){ $errors[] = 'Failed to save settings'; }
    }

    // Add a new bank
    if(!empty($_POST['action']) && $_POST['action'] === 'add_bank'){
        $bank_name = trim((string)($_POST['bank_name'] ?? ''));
        if($bank_name === ''){ $errors[] = 'Bank name is required.'; }
        else{
            try{
                $ins = $pdo->prepare('INSERT INTO banks (name, enabled) VALUES (:name, 1)');
                $ins->execute([':name'=>$bank_name]);
                $msg = 'Bank added.';
            }catch(Exception $e){ $errors[] = 'Failed to add bank.'; }
        }
    }

    // Toggle bank enable/disable OR delete
    if(!empty($_POST['action']) && $_POST['action'] === 'update_banks'){
        // expected: banks[] => id => enabled
        $posted = $_POST['banks'] ?? [];
        try{
            // disable all first
            $pdo->exec('UPDATE banks SET enabled = 0');
            foreach($posted as $bid => $val){
                $bid = (int)$bid;
                $enabled = ($val == '1' ? 1 : 0);
                $upd = $pdo->prepare('UPDATE banks SET enabled = :e WHERE id = :id');
                $upd->execute([':e'=>$enabled, ':id'=>$bid]);
            }
            $msg = 'Bank availability updated.';
        }catch(Exception $e){ $errors[] = 'Failed to update banks.'; }
    }

    // Delete bank
    if(!empty($_POST['action']) && $_POST['action'] === 'delete_bank'){
        $bid = (int)($_POST['bank_id'] ?? 0);
        if($bid){
            try{ $del = $pdo->prepare('DELETE FROM banks WHERE id = :id'); $del->execute([':id'=>$bid]); $msg = 'Bank removed.'; }catch(Exception $e){ $errors[] = 'Failed to remove bank.'; }
        }
    }
}

// Load current values
$site_name = get_setting($pdo, 'site_name', 'Japan Food');
$contact_email = get_setting($pdo, 'contact_email', 'contact@japanfood.com');
$color_primary = get_setting($pdo, 'color_primary', '#EE6F57');
$color_secondary = get_setting($pdo, 'color_secondary', '#CB3737');
$default_bank = get_setting($pdo, 'default_bank', '');

$banks = [];
try{ $banks = $pdo->query('SELECT * FROM banks ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC); }catch(Exception $e){ }

// Render UI
?>
<div class="card p-3">
  <h3>Account Management</h3>
  <div class="small mb-2">Manage site preferences and the list of available payout banks.</div>

  <?php if($msg): ?><div class="alert alert-success" id="settingsFlash"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if(!empty($errors)): ?><div class="alert alert-danger"><ul><?php foreach($errors as $er) echo '<li>'.htmlspecialchars($er).'</li>'; ?></ul></div><?php endif; ?>

  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#general-tab">General</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bank-tab">Bank Management</a></li>
  </ul>

  <div class="tab-content" style="margin-top:12px">
    <div class="tab-pane fade show active" id="general-tab">
      <form method="post" action="site-settings.php">
        <input type="hidden" name="action" value="save_general">
        <div class="mb-3">
          <label class="form-label">Site Name</label>
          <input name="site_name" type="text" class="form-control" value="<?= htmlspecialchars($site_name) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Contact Email</label>
          <input name="contact_email" type="email" class="form-control" value="<?= htmlspecialchars($contact_email) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Primary Color</label>
          <input name="color_primary" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($color_primary) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Secondary Color</label>
          <input name="color_secondary" type="color" class="form-control form-control-color" value="<?= htmlspecialchars($color_secondary) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Default bank for payouts</label>
          <select name="default_bank" class="form-control">
            <option value="">-- none --</option>
            <?php foreach($banks as $bn): if(!$bn['enabled']) continue; ?>
              <option value="<?= (int)$bn['id'] ?>" <?= ((string)$default_bank === (string)$bn['id']) ? 'selected' : '' ?>><?= htmlspecialchars($bn['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-success">Save General Settings</button>
      </form>
    </div>

    <div class="tab-pane fade" id="bank-tab">
      <div class="mb-3">
        <form id="addBankForm" method="post" action="site-settings.php" class="d-flex gap-2">
          <input type="hidden" name="action" value="add_bank">
          <input name="bank_name" class="form-control" placeholder="New bank name">
          <button class="btn btn-primary">Add Bank</button>
        </form>
      </div>

      <form id="banksForm" method="post" action="site-settings.php">
        <input type="hidden" name="action" value="update_banks">
        <table class="table table-sm" id="banksTable">
          <thead><tr><th style="width:80px">Enabled</th><th>Bank Name</th><th style="width:120px">Actions</th></tr></thead>
          <tbody>
            <?php foreach($banks as $b): ?>
              <tr data-bank-id="<?= (int)$b['id'] ?>">
                <td><input type="checkbox" name="banks[<?= (int)$b['id'] ?>]" value="1" <?= $b['enabled'] ? 'checked' : '' ?>></td>
                <td><?= htmlspecialchars($b['name']) ?></td>
                <td>
                  <button type="button" class="btn btn-sm btn-danger delete-bank-btn">Delete</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <button class="btn btn-success">Save Bank Availability</button>
      </form>
    </div>
  </div>
</div>

<script>
  (function(){
    var flash = <?= json_encode($msg ?: '') ?>;
    if(flash){
      // open bank tab when an add/update/delete happened
      var bankLink = document.querySelector('a[href="#bank-tab"]');
      if(bankLink){ bankLink.click(); }
      // show inline alert near add form
      var addForm = document.getElementById('addBankForm');
      if(addForm){
        var d = document.createElement('div'); d.className = 'alert alert-success'; d.textContent = flash; addForm.parentNode.insertBefore(d, addForm.nextSibling);
      }
    }

    // Attach delete handlers (AJAX) so admin panel does not navigate away
    document.querySelectorAll('.delete-bank-btn').forEach(btn=>{
      if(btn.dataset.bound) return; btn.dataset.bound = '1';
      btn.addEventListener('click', async function(){
        if(!confirm('Delete bank?')) return;
        var tr = this.closest('tr'); if(!tr) return; var bid = tr.dataset.bankId;
        try{
          var fd = new FormData(); fd.append('action','delete_bank'); fd.append('bank_id', bid);
          var res = await fetch('site-settings.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          var html = await res.text();
          var main = document.getElementById('mainContent'); if(main){ main.innerHTML = html; if(window.runFragmentScripts) runFragmentScripts(main); if(window.attachFormSubmitHandler) attachFormSubmitHandler(); }
        }catch(e){ alert('Failed to delete'); }
      });
    });
  })();
</script>


