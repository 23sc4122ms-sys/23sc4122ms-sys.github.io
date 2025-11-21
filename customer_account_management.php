<?php
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Detect AJAX requests (fragment load / AJAX form submit)
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Only customers may access this page (redirect to login otherwise)
if (empty($_SESSION['user_id'])) {
    header('Location: main/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Ensure extra columns on users table exist (image, address)
try{
    $cols = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('image','address')");
    $cols->execute([':db'=>DB_NAME]);
    $existing = $cols->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('image',$existing)){
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `image` VARCHAR(255) DEFAULT NULL AFTER `name`");
    }
    if(!in_array('address',$existing)){
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `address` TEXT DEFAULT NULL AFTER `image`");
    }
}catch(Exception $e){ /* non-fatal */ }

// Ensure bank_accounts table exists
try{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_accounts` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT UNSIGNED NOT NULL,
      `bank_name` VARCHAR(255) DEFAULT NULL,
      `account_number` VARCHAR(255) DEFAULT NULL,
      `account_holder` VARCHAR(255) DEFAULT NULL,
      `status` ENUM('pending','accepted','rejected') DEFAULT 'pending',
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      INDEX (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}catch(Exception $e){ /* ignore */ }

// Ensure expected columns exist in bank_accounts (repair older schemas)
try{
  $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'bank_accounts'");
  $colStmt->execute([':db' => DB_NAME]);
  $existingCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
  $needed = [
    'user_id' => "INT UNSIGNED NOT NULL",
    'bank_name' => "VARCHAR(255) DEFAULT NULL",
    'account_number' => "VARCHAR(255) DEFAULT NULL",
    'account_holder' => "VARCHAR(255) DEFAULT NULL",
    'status' => "ENUM('pending','accepted','rejected') DEFAULT 'pending'",
    'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
  ];
  foreach($needed as $col => $spec){
    if(!in_array($col, $existingCols)){
      // add missing column
      $sql = "ALTER TABLE `bank_accounts` ADD COLUMN `$col` $spec";
      try{ $pdo->exec($sql); }catch(Exception $ee){ /* ignore individual column add errors */ }
    }
  }
}catch(Exception $e){ /* ignore */ }

$msg = '';
$errors = [];

// Handle form submission
if($_SERVER['REQUEST_METHOD'] === 'POST'){
  // Basic CSRF / simple protection could be added; omitted for brevity
  $name = trim((string)($_POST['name'] ?? ''));

  // address (free-text)
  $address = trim((string)($_POST['address'] ?? ''));

  // bank selection (must choose an enabled bank)
  $bank_name = '';
  if(!empty($_POST['bank_id'])){
    $bid = (int)$_POST['bank_id'];
    try{
      $bn = $pdo->prepare('SELECT name FROM banks WHERE id = :id AND enabled = 1 LIMIT 1');
      $bn->execute([':id'=>$bid]);
      $bnName = $bn->fetchColumn();
      if($bnName) $bank_name = $bnName;
    }catch(Exception $e){ /* ignore */ }
  }
  $account_number = trim((string)($_POST['account_number'] ?? ''));
  $account_holder = trim((string)($_POST['account_holder'] ?? ''));

  // Server-side validation: require name, address free-text, bank selection, account number, account holder
  if($name === '') $errors[] = 'Full name is required.';
  if($address === '') $errors[] = 'Address is required.';
  if($bank_name === '') $errors[] = 'Please choose a bank from the list.';
  if($account_number === '') $errors[] = 'Account number is required.';
  if($account_holder === '') $errors[] = 'Account holder name is required.';

  // If there are no validation errors, proceed to save
  if(empty($errors)){
    // update name/address (free-text)
    try{
      $sth = $pdo->prepare('UPDATE users SET name = :name, address = :address WHERE id = :id');
      $sth->execute([':name'=>$name, ':address'=>$address, ':id'=>$userId]);
      $_SESSION['user_name'] = $name;
      $msg = 'Profile updated.';
    }catch(Exception $e){ $errors[] = 'Failed to update profile'; }

    // handle profile image upload (optional)
    if(!empty($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE){
      $f = $_FILES['profile_image'];
      if($f['error'] === UPLOAD_ERR_OK){
        $allowed = ['image/jpeg','image/png','image/gif'];
        if(!in_array($f['type'],$allowed)){
          $errors[] = 'Profile image must be JPG/PNG/GIF';
        }elseif($f['size'] > 2 * 1024 * 1024){
          $errors[] = 'Image too large (max 2MB)';
        }else{
          $uploads = __DIR__ . '/uploads/profile';
          if(!is_dir($uploads)) @mkdir($uploads, 0755, true);
          $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
          $nameOnDisk = 'u' . $userId . '_' . time() . '.' . $ext;
          $dest = $uploads . '/' . $nameOnDisk;
          if(move_uploaded_file($f['tmp_name'], $dest)){
            // Resize to 1024x682 and save
            $targetW = 1024; $targetH = 682;
            $resizeImage = function($srcFile, $dstFile, $tw, $th){
              $info = @getimagesize($srcFile);
              if(!$info) return false;
              $mime = $info['mime'];
              switch($mime){
                case 'image/jpeg': $srcImg = @imagecreatefromjpeg($srcFile); break;
                case 'image/png': $srcImg = @imagecreatefrompng($srcFile); break;
                case 'image/gif': $srcImg = @imagecreatefromgif($srcFile); break;
                case 'image/webp': if(function_exists('imagecreatefromwebp')) $srcImg = @imagecreatefromwebp($srcFile); else $srcImg = null; break;
                default: $srcImg = null; break;
              }
              if(!$srcImg) return false;
              $sw = imagesx($srcImg); $sh = imagesy($srcImg);
              $scale = max($tw / $sw, $th / $sh);
              $nw = (int)ceil($sw * $scale); $nh = (int)ceil($sh * $scale);
              $tmp = imagecreatetruecolor($nw, $nh);
              if(in_array($mime, ['image/png','image/webp'])){ imagealphablending($tmp, false); imagesavealpha($tmp, true); $transparent = imagecolorallocatealpha($tmp, 0,0,0,127); imagefilledrectangle($tmp,0,0,$nw,$nh,$transparent); }
              imagecopyresampled($tmp, $srcImg, 0,0,0,0, $nw, $nh, $sw, $sh);
              $dx = (int)floor(($nw - $tw)/2);
              $dy = (int)floor(($nh - $th)/2);
              $dst = imagecreatetruecolor($tw, $th);
              if(in_array($mime, ['image/png','image/webp'])){ imagealphablending($dst, false); imagesavealpha($dst, true); $transparent = imagecolorallocatealpha($dst, 0,0,0,127); imagefilledrectangle($dst,0,0,$tw,$th,$transparent); }
              imagecopy($dst, $tmp, 0,0, $dx, $dy, $tw, $th);
              $saved = false;
              if($mime === 'image/png' && function_exists('imagepng')){ $saved = imagepng($dst, $dstFile); }
              elseif($mime === 'image/gif' && function_exists('imagegif')){ $saved = imagegif($dst, $dstFile); }
              elseif($mime === 'image/webp' && function_exists('imagewebp')){ $saved = imagewebp($dst, $dstFile, 85); }
              else { $saved = imagejpeg($dst, $dstFile, 85); }
              imagedestroy($srcImg); imagedestroy($tmp); imagedestroy($dst);
              return $saved;
            };
            $resizeImage($dest, $dest, $targetW, $targetH);
            // save relative path
            $rel = 'uploads/profile/' . $nameOnDisk;
            try{
              $sth = $pdo->prepare('UPDATE users SET image = :img WHERE id = :id');
              $sth->execute([':img'=>$rel, ':id'=>$userId]);
              $msg = 'Profile updated.';
            }catch(Exception $e){ $errors[] = 'Failed to save image path'; }
          }else{ $errors[] = 'Failed to move uploaded file'; }
        }
      }else{ $errors[] = 'Upload error'; }
    }

    // handle bank account upsert
    if($bank_name || $account_number || $account_holder){
      try{
        // check existing
        $sth = $pdo->prepare('SELECT id FROM bank_accounts WHERE user_id = :uid LIMIT 1');
        $sth->execute([':uid'=>$userId]);
        $exists = $sth->fetchColumn();
        if($exists){
          $upd = $pdo->prepare('UPDATE bank_accounts SET bank_name = :bn, account_number = :an, account_holder = :ah, status = :st WHERE id = :id');
          $upd->execute([':bn'=>$bank_name, ':an'=>$account_number, ':ah'=>$account_holder, ':st'=>'pending', ':id'=>$exists]);
        }else{
          $ins = $pdo->prepare('INSERT INTO bank_accounts (user_id, bank_name, account_number, account_holder, status) VALUES (:uid,:bn,:an,:ah,:st)');
          $ins->execute([':uid'=>$userId, ':bn'=>$bank_name, ':an'=>$account_number, ':ah'=>$account_holder, ':st'=>'pending']);
        }
        $msg = 'Profile and bank info saved.';
      }catch(Exception $e){ $errors[] = 'Failed to save bank account'; }
    }
  }
}

// Load existing user data
$user = $pdo->prepare('SELECT id,name,email,image,address FROM users WHERE id = :id LIMIT 1');
$user->execute([':id'=>$userId]);
$u = $user->fetch();

// Load bank info if any
$bank = $pdo->prepare('SELECT * FROM bank_accounts WHERE user_id = :uid ORDER BY id DESC LIMIT 1');
$bank->execute([':uid'=>$userId]);
$b = $bank->fetch();

// Load available (enabled) banks for selection
try{
  $availableBanks = $pdo->prepare('SELECT id,name FROM banks WHERE enabled = 1 ORDER BY name ASC');
  $availableBanks->execute();
  $availableBanks = $availableBanks->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $availableBanks = []; }

// Render fragment HTML into buffer so we can return only the fragment for AJAX
ob_start();
?>
  <style>
    .account-wrap{max-width:840px;margin:18px auto;padding:18px;background:#fff;border:1px solid #eee;border-radius:10px;font-family:inherit}
    .profile-preview{width:110px;height:110px;border-radius:12px;object-fit:cover;border:1px solid #e6e6e6;display:block}
    .profile-card{display:flex;gap:16px;align-items:center}
    .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}
    .form-control{width:100%;padding:8px;border:1px solid #ddd;border-radius:6px}
    .small{font-size:0.85rem;color:#666}
    .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
    .btn-primary{background:#2b7cff;color:#fff}
    .btn-outline-secondary{background:transparent;border:1px solid #ccc}
    .bank-card{background:linear-gradient(90deg,#fff,#fbfbff);border:1px solid #eef;margin-top:14px;padding:12px;border-radius:8px}
    .muted{color:#666;font-size:0.9rem}
    /* custom file picker */
    .file-picker{display:flex;align-items:center;gap:8px;margin-top:8px}
    .file-picker .btn-chooser{background:#f4f4f4;border:1px solid #ddd;padding:6px 10px;border-radius:6px;color:#333;cursor:pointer}
    .file-picker .file-name{font-size:0.9rem;color:#666}
    .btn-close-orange{background:#ff8c00;color:#fff;border:0;padding:8px 12px;border-radius:6px;text-decoration:none;display:inline-block}
  </style>

  <div class="account-wrap">
    <h2 style="margin-top:0;margin-bottom:6px">Account management</h2>
    <?php if($msg): ?><div style="padding:10px;background:#e9f8e9;border:1px solid #cfe9cf;margin-bottom:12px;border-radius:6px"><?php echo htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($errors): ?><div style="padding:10px;background:#fff6f6;border:1px solid #f3caca;margin-bottom:12px;border-radius:6px"><ul><?php foreach($errors as $err){ echo '<li>' . htmlspecialchars($err) . '</li>'; } ?></ul></div><?php endif; ?>

    <form id="accountForm" method="post" enctype="multipart/form-data">
      <div class="profile-card">
          <div style="width:130px;text-align:center">
          <img id="profilePreview" src="<?php echo htmlspecialchars($u['image'] ?? '') ?: 'uploads/profile/default.png' ?>" alt="Profile" class="profile-preview">
          <div style="margin-top:8px;text-align:left">
            <label class="small muted">Change photo</label>
            <div class="file-picker">
              <input id="profileImageInput" type="file" name="profile_image" accept="image/*" style="display:none">
              <button type="button" id="chooseFileBtn" class="btn-chooser">Choose file</button>
              <span id="chosenFileName" class="file-name">No file chosen</span>
            </div>
          </div>
        </div>
        <div style="flex:1">
          <label class="small">Full name</label>
          <input name="name" class="form-control" value="<?php echo htmlspecialchars($u['name'] ?? '') ?>" required />
          <div style="margin-top:8px">
            <label class="small">Email</label>
            <div class="muted" style="padding:8px 0"><?php echo htmlspecialchars($u['email'] ?? '') ?></div>
          </div>
        </div>
      </div>

      <div style="margin-top:12px">
        <label class="small">Address</label>
        <textarea name="address" class="form-control" rows="4" required><?php echo htmlspecialchars($u['address'] ?? '') ?></textarea>
      </div>

      <h4 style="margin-top:18px;margin-bottom:6px">Bank account (for payouts)</h4>
      <div class="bank-card">
        <div class="field-grid">
          <div>
            <label class="small">Choose bank</label>
            <select name="bank_id" class="form-control" required>
              <option value="">-- choose bank --</option>
              <?php foreach($availableBanks as $bn):
                $selected = (($b['bank_name'] ?? '') === $bn['name']) ? 'selected' : '';?>
                <option value="<?= (int)$bn['id'] ?>" <?= $selected ?>><?= htmlspecialchars($bn['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="small">Account number</label>
            <input name="account_number" class="form-control" value="<?php echo htmlspecialchars($b['account_number'] ?? '') ?>" required />
          </div>
        </div>
        <div style="margin-top:8px">
          <label class="small">Account holder name</label>
          <input name="account_holder" class="form-control" value="<?php echo htmlspecialchars($b['account_holder'] ?? $_SESSION['user_name'] ?? '') ?>" required />
        </div>
        <!-- manual bank name removed: user must choose an available bank -->
        <?php if($b): ?>
          <div style="margin-top:10px;font-size:0.95rem;color:#444">Status: <strong><?php echo htmlspecialchars($b['status']) ?></strong></div>
        <?php else: ?>
          <div style="margin-top:10px;font-size:0.9rem;color:#666">No bank account on file. Save to submit for approval.</div>
        <?php endif; ?>
      </div>

      <div style="margin-top:18px;display:flex;gap:8px">
        <button class="btn btn-primary" type="submit">Save changes</button>
        <a href="index.php" class="btn-close-orange">Close</a>
      </div>
    </form>

  </div>

<?php
$fragment = ob_get_clean();

// Include a small script inside the fragment so AJAX-injected content initializes
$fragment .= <<<'JS'
<script>
// initialize file chooser and preview when fragment is injected
(function(){
  try{
    var input = document.getElementById('profileImageInput');
    var btn = document.getElementById('chooseFileBtn');
    var nameSpan = document.getElementById('chosenFileName');
    var preview = document.getElementById('profilePreview');
    if(input && btn){
      btn.addEventListener('click', function(e){ e.preventDefault(); input.click(); });
      input.addEventListener('change', function(){
        if(this.files && this.files[0]){
          var f = this.files[0];
          if(nameSpan) nameSpan.textContent = f.name;
          if(preview) preview.src = URL.createObjectURL(f);
        } else { if(nameSpan) nameSpan.textContent = 'No file chosen'; }
      });
    }
  }catch(e){}
})();

// Address selects data (demo) and wiring
// (Address selects removed — using free-text address textarea)
// attach client-side validation for AJAX-injected fragment
(function(){
  try{
    var form = document.getElementById('accountForm');
    if(!form) return;
    form.addEventListener('submit', function(e){
      var name = (form.querySelector('[name="name"]')||{}).value || '';
      var address = (form.querySelector('[name="address"]')||{}).value || '';
      var bankId = (form.querySelector('[name="bank_id"]')||{}).value || '';
      var acct = (form.querySelector('[name="account_number"]')||{}).value || '';
      var holder = (form.querySelector('[name="account_holder"]')||{}).value || '';
      var clientErrors = [];
      if(name.trim() === '') clientErrors.push('Full name is required.');
      if(address.trim() === '') clientErrors.push('Address is required.');
      if(bankId.trim() === '') clientErrors.push('Please choose a bank from the list.');
      if(acct.trim() === '') clientErrors.push('Account number is required.');
      if(holder.trim() === '') clientErrors.push('Account holder name is required.');
      if(clientErrors.length){ e.preventDefault(); alert(clientErrors.join('\n')); }
    }, {capture:false});
  }catch(e){}
})();
</script>
JS;

if($isAjax){
    // Return only the fragment HTML for AJAX loads
    header('Content-Type: text/html; charset=utf-8');
    echo $fragment;
    exit;
}

// Non-AJAX full page render
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Account Management</title>
  <link rel="stylesheet" href="css/design.css">
  <style>
    .account-wrap{max-width:840px;margin:28px auto;padding:18px;background:#fff;border:1px solid #eee;border-radius:8px}
    .profile-preview{width:96px;height:96px;border-radius:8px;object-fit:cover;border:1px solid #ddd}
  </style>
</head>
<body>
  <?php echo $fragment; ?>

  <script>
    // Attach AJAX submit when loaded as full page (non-AJAX fallback)
    (function(){
      const form = document.getElementById('accountForm');
      if(!form) return;
      form.addEventListener('submit', async function(e){
        e.preventDefault();

        // client-side validation: name, structured address (country/province/city/barangay), bank selection, account fields
        const name = (form.querySelector('[name="name"]')||{}).value || '';
        const country = (form.querySelector('[name="country"]')||{}).value || '';
        const province = (form.querySelector('[name="province"]')||{}).value || '';
        const city = (form.querySelector('[name="city"]')||{}).value || '';
        const barangay = (form.querySelector('[name="barangay"]')||{}).value || '';
        const bankId = (form.querySelector('[name="bank_id"]')||{}).value || '';
        const acct = (form.querySelector('[name="account_number"]')||{}).value || '';
        const holder = (form.querySelector('[name="account_holder"]')||{}).value || '';
        const clientErrors = [];
        if(name.trim() === '') clientErrors.push('Full name is required.');
        if(country.trim() === '') clientErrors.push('Country is required.');
        if(province.trim() === '') clientErrors.push('Province is required.');
        if(city.trim() === '') clientErrors.push('City / Municipality is required.');
        if(barangay.trim() === '') clientErrors.push('Barangay is required.');
        if(bankId.trim() === '') clientErrors.push('Please choose a bank from the list.');
        if(acct.trim() === '') clientErrors.push('Account number is required.');
        if(holder.trim() === '') clientErrors.push('Account holder name is required.');
        if(clientErrors.length){ alert(clientErrors.join('\n')); return; }

        const fd = new FormData(form);
        try{
          const res = await fetch(window.location.pathname, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          const text = await res.text();
          // replace fragment
          const container = document.querySelector('.account-wrap'); if(container) container.outerHTML = text;
        }catch(err){ alert('Network error'); }
      });
    })();
    // File chooser wiring (works for fragment and full-page)
    (function(){
      const input = document.getElementById('profileImageInput');
      const btn = document.getElementById('chooseFileBtn');
      const nameSpan = document.getElementById('chosenFileName');
      const preview = document.getElementById('profilePreview');
      if(!input || !btn) return;
      btn.addEventListener('click', function(){ input.click(); });
      input.addEventListener('change', function(){
        if(this.files && this.files[0]){
          const f = this.files[0];
          nameSpan.textContent = f.name;
          if(preview) preview.src = URL.createObjectURL(f);
        }else{
          nameSpan.textContent = 'No file chosen';
        }
      });
    })();
    // Address selects wiring for full-page view
    (function(){
      try{
        var data = {
          "Metro Manila": {
            "Manila": ["Barangay 1","Barangay 2","Barangay 3"],
            "Quezon City": ["Batasan","Project 4","Novaliches"]
          },
          "Cebu": {
            "Cebu City": ["Lahug","Basak","Mabolo"],
            "Mandaue": ["Centro","Looc"]
          },
          "Davao del Sur": {
            "Davao City": ["Agdao","Buhangin","Toril"]
          }
        };
        var countrySel = document.getElementById('addr_country');
        var provSel = document.getElementById('addr_province');
        var citySel = document.getElementById('addr_city');
        var barangaySel = document.getElementById('addr_barangay');
        if(!countrySel || !provSel || !citySel || !barangaySel) return;
        function populateProvinces(){
          provSel.innerHTML = '<option value="">-- choose province --</option>' + Object.keys(data).map(function(p){ return '<option value="'+p+'">'+p+'</option>'; }).join('');
          citySel.innerHTML = '<option value="">-- choose city --</option>';
          barangaySel.innerHTML = '<option value="">-- choose barangay --</option>';
        }
        countrySel.addEventListener('change', function(){ if(this.value === 'Philippines') populateProvinces(); else { provSel.innerHTML='<option value="">-- choose province --</option>'; citySel.innerHTML='<option value="">-- choose city --</option>'; barangaySel.innerHTML='<option value="">-- choose barangay --</option>'; } });
        provSel.addEventListener('change', function(){ var p = this.value; if(!p){ citySel.innerHTML = '<option value="">-- choose city --</option>'; barangaySel.innerHTML = '<option value="">-- choose barangay --</option>'; return; } var cities = Object.keys(data[p]||{}); citySel.innerHTML = '<option value="">-- choose city --</option>' + cities.map(function(c){ return '<option value="'+c+'">'+c+'</option>'; }).join(''); barangaySel.innerHTML = '<option value="">-- choose barangay --</option>'; });
        citySel.addEventListener('change', function(){ var p = provSel.value; var c = this.value; if(!p || !c){ barangaySel.innerHTML = '<option value="">-- choose barangay --</option>'; return; } var br = data[p] && data[p][c] ? data[p][c] : []; barangaySel.innerHTML = '<option value="">-- choose barangay --</option>' + br.map(function(b){ return '<option value="'+b+'">'+b+'</option>'; }).join(''); });
        if(countrySel.value === 'Philippines') populateProvinces();
      }catch(e){}
    })();
  </script>
</body>
</html>
