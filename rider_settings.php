<?php
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// ensure rider is logged in
if (empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider') {
    echo '<div class="container py-3"><div class="alert alert-warning">Please sign in as a rider to manage your account.</div></div>';
    return;
}
$uid = (int)$_SESSION['user_id'];

// Ensure optional columns exist (phone, vehicle_info, profile_photo)
try{
    $cols = ['phone','vehicle_info','profile_photo'];
    foreach($cols as $c){
        $sth = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = :col");
        $sth->execute([':col'=>$c]);
        if((int)$sth->fetchColumn() === 0){
            if($c === 'phone'){
                $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL AFTER email");
            }elseif($c === 'vehicle_info'){
                $pdo->exec("ALTER TABLE users ADD COLUMN vehicle_info TEXT NULL AFTER phone");
            }elseif($c === 'profile_photo'){
                $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER vehicle_info");
            }
        }
    }
}catch(Exception $e){
    // non-fatal; continue
}

$errors = [];
$success = '';

// Fetch current user
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$uid]);
$user = $stmt->fetch();
if(!$user){
    echo '<div class="container py-3"><div class="alert alert-danger">User not found.</div></div>';
    return;
}

// Handle profile update
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile'){
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $name = trim($first . ($last ? ' ' . $last : '')) ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;
    $vehicle = trim($_POST['vehicle_info'] ?? '') ?: null;

    // handle email change? keep as readonly by default; but accept if posted
    $email = strtolower(trim($_POST['email'] ?? $user['email']));

    if($email && !filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = 'Please enter a valid email address.';
    }

    // check email uniqueness if changed
    if($email && $email !== $user['email']){
        $sth = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $sth->execute([$email, $uid]);
        if($sth->fetch()){
            $errors[] = 'That email is already in use.';
        }
    }

    // handle photo upload
    $photoPath = $user['profile_photo'] ?? null;
    if(!empty($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE){
        $f = $_FILES['profile_photo'];
        if($f['error'] !== UPLOAD_ERR_OK){
            $errors[] = 'Failed to upload photo.';
        }else{
            $allowed = ['image/jpeg','image/png','image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            if(!in_array($mime, $allowed, true)){
                $errors[] = 'Only JPG, PNG or WEBP images are allowed for profile photo.';
            }elseif($f['size'] > 2 * 1024 * 1024){
                $errors[] = 'Profile photo must be under 2MB.';
            }else{
                $ext = '';
                if($mime === 'image/png') $ext = '.png';
                elseif($mime === 'image/webp') $ext = '.webp';
                else $ext = '.jpg';

                $uploadsDir = __DIR__ . '/uploads/profile';
                if(!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
                $newName = 'r' . $uid . '_' . time() . $ext;
                $dest = $uploadsDir . '/' . $newName;
                if(!move_uploaded_file($f['tmp_name'], $dest)){
                  $errors[] = 'Failed to move uploaded file.';
                }else{
                  // Resize to 1024x682 to keep consistent dimensions
                  $targetW = 1024; $targetH = 682;
                  $resizeImage = function($srcFile, $dstFile, $tw, $th){
                    $info = @getimagesize($srcFile);
                    if(!$info) return false;
                    $mime = $info['mime'];
                    switch($mime){
                      case 'image/jpeg':
                        $srcImg = function_exists('imagecreatefromjpeg') ? imagecreatefromjpeg($srcFile) : null;
                        break;
                      case 'image/png':
                        $srcImg = function_exists('imagecreatefrompng') ? imagecreatefrompng($srcFile) : null;
                        break;
                      case 'image/gif':
                        $srcImg = function_exists('imagecreatefromgif') ? imagecreatefromgif($srcFile) : null;
                        break;
                      case 'image/webp':
                        $srcImg = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($srcFile) : null;
                        break;
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
                  // store relative path
                  $photoPath = 'uploads/profile/' . $newName;
                }
            }
        }
    }

    if(empty($errors)){
        try{
            $upd = $pdo->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, vehicle_info = :vehicle, profile_photo = :photo WHERE id = :id');
            $upd->execute([
                ':name' => $name,
                ':email' => $email,
                ':phone' => $phone,
                ':vehicle' => $vehicle,
                ':photo' => $photoPath,
                ':id' => $uid
            ]);
            $success = 'Profile updated successfully.';
            // reload user
            $stmt->execute([$uid]);
            $user = $stmt->fetch();
            $_SESSION['user_name'] = $user['name'] ?: ($user['email'] ?? '');
        }catch(Exception $e){
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle password change
if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password'){
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if($new !== $confirm) $errors[] = 'New password and confirmation do not match.';
    if(strlen($new) < 6) $errors[] = 'New password must be at least 6 characters.';

    // verify current password
    if(empty($errors)){
        if(!password_verify($current, $user['password'])){
            $errors[] = 'Current password is incorrect.';
        }else{
            $hash = password_hash($new, PASSWORD_DEFAULT);
            try{
                $upd = $pdo->prepare('UPDATE users SET password = :pw WHERE id = :id');
                $upd->execute([':pw'=>$hash, ':id'=>$uid]);
                $success = 'Password updated successfully.';
                // refresh user
                $stmt->execute([$uid]);
                $user = $stmt->fetch();
            }catch(Exception $e){
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Prepare name parts
$fullName = $user['name'] ?? '';
$first = '';
$last = '';
if($fullName){
    $parts = preg_split('/\\s+/', trim($fullName), 2);
    $first = $parts[0] ?? '';
    $last = $parts[1] ?? '';
}

// If the request was made via AJAX, return JSON instead of rendering HTML fragment
$isAjax = (
  !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
)
|| (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
|| (isset($_POST['ajax']) && $_POST['ajax'] === '1');

if($isAjax){
  header('Content-Type: application/json');
  $payload = [
    'success' => $success ?: null,
    'errors' => $errors ?: [],
    'user' => [
      'id' => $user['id'] ?? null,
      'name' => $user['name'] ?? null,
      'email' => $user['email'] ?? null,
      'phone' => $user['phone'] ?? null,
      'vehicle_info' => $user['vehicle_info'] ?? null,
      'profile_photo' => $user['profile_photo'] ?? null,
    ]
  ];
  echo json_encode($payload);
  exit;
}

?>
<div class="container py-3">
  <h3 class="mb-0">Settings</h3>
  <p class="text-muted">Manage your account preferences and security</p>

  <?php if($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach($errors as $e): ?>
          <li><?=htmlspecialchars($e)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if($success): ?>
    <div class="alert alert-success"><?=htmlspecialchars($success)?></div>
  <?php endif; ?>

  <div class="card p-3">
    <ul class="nav nav-tabs mb-3" id="settingsTabs" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab">Profile</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">Security</button></li>
    </ul>

    <div class="tab-content">
      <div class="tab-pane fade show active" id="profile" role="tabpanel">
        <form method="post" action="rider_settings.php" enctype="multipart/form-data" class="row g-3" data-ajax="true">
          <input type="hidden" name="action" value="update_profile">
          <div class="col-md-6">
            <label class="form-label">First Name</label>
            <input class="form-control" name="first_name" value="<?=htmlspecialchars($first)?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Last Name</label>
            <input class="form-control" name="last_name" value="<?=htmlspecialchars($last)?>">
          </div>
          <div class="col-12">
            <label class="form-label">Email Address</label>
            <input class="form-control" name="email" type="email" value="<?=htmlspecialchars($user['email'] ?? '')?>" readonly>
          </div>
          <div class="col-12">
            <label class="form-label">Phone Number</label>
            <input class="form-control" name="phone" value="<?=htmlspecialchars($user['phone'] ?? '')?>" placeholder="e.g. +1 (555) 123-4567">
          </div>
          <div class="col-12">
            <label class="form-label">Vehicle Information</label>
            <input class="form-control" name="vehicle_info" value="<?=htmlspecialchars($user['vehicle_info'] ?? '')?>" placeholder="e.g. 2022 Honda Civic - License Plate: ABC123">
          </div>

          <div class="col-12 d-flex gap-3 align-items-center">
            <div>
              <?php if(!empty($user['profile_photo'])): ?>
                <img src="<?=htmlspecialchars($user['profile_photo'])?>" alt="photo" style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
              <?php else: ?>
                <div style="width:80px;height:80px;border-radius:8px;background:#f1f1f1;display:flex;align-items:center;justify-content:center;color:#777;">No photo</div>
              <?php endif; ?>
            </div>
            <div class="flex-fill">
              <label class="form-label">Change Photo</label>
              <input class="form-control" type="file" name="profile_photo" accept="image/*">
              <div class="small text-muted">JPG/PNG/WEBP, max 2MB.</div>
            </div>
          </div>

          <div class="col-12">
            <button class="btn btn-dark w-100" type="submit">Save Changes</button>
          </div>
        </form>
      </div>

      <div class="tab-pane fade" id="security" role="tabpanel">
        <form method="post" action="rider_settings.php" class="row g-3" data-ajax="true">
          <input type="hidden" name="action" value="change_password">
          <div class="col-12">
            <label class="form-label">Current Password</label>
            <input class="form-control" type="password" name="current_password" required>
          </div>
          <div class="col-12">
            <label class="form-label">New Password</label>
            <input class="form-control" type="password" name="new_password" required>
          </div>
          <div class="col-12">
            <label class="form-label">Confirm New Password</label>
            <input class="form-control" type="password" name="confirm_password" required>
          </div>
          <div class="col-12">
            <button class="btn btn-dark w-100" type="submit">Update Password</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- small script to activate bootstrap tabs inside fragment (when loaded via fetch) -->
<script>
  (function(){
    try{ var tabEl = document.querySelectorAll('#settingsTabs button'); tabEl.forEach(function(b){ b.addEventListener('shown.bs.tab', function(e){}); }); }catch(e){}
  })();
</script>

<?php
// end file
?>
