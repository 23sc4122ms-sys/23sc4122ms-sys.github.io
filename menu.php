<?php
// menu.php - dynamic menu management using DB
require_once __DIR__ . '/db.php';

$pdo = getPDO();
$errors = [];
$success = '';

// Handle add form
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_menu'){
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = trim($_POST['price'] ?? '0');
    $availability = $_POST['availability'] ?? 'Available';

  if($name === '') $errors[] = 'Dish name is required.';
  if(!is_numeric($price)) $errors[] = 'Price must be a number.';

  // handle image upload
  $imagePath = null;
  if(isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE){
    $f = $_FILES['image'];
    if($f['error'] !== UPLOAD_ERR_OK){
      $errors[] = 'Image upload error.';
    } else {
      $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime = finfo_file($finfo, $f['tmp_name']);
      finfo_close($finfo);
      if(!array_key_exists($mime, $allowed)){
        $errors[] = 'Only JPG, PNG, GIF images allowed.';
      } elseif($f['size'] > 2 * 1024 * 1024){
        $errors[] = 'Image too large (max 2MB).';
      } else {
        $uploadDir = __DIR__ . '/uploads/menu';
        if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = $allowed[$mime];
        $base = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadDir . '/' . $base;
        if(move_uploaded_file($f['tmp_name'], $dest)){
          // Resize/uploaded image to target size (1024x682) and store relative path used in <img src>
          $targetW = 1024; $targetH = 682;
          // helper: resize and center-crop
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
              default:
                $srcImg = null; break;
            }
            if(!$srcImg) return false;
            $sw = imagesx($srcImg); $sh = imagesy($srcImg);
            // scale to cover
            $scale = max($tw / $sw, $th / $sh);
            $nw = (int)ceil($sw * $scale); $nh = (int)ceil($sh * $scale);
            $tmp = imagecreatetruecolor($nw, $nh);
            // preserve PNG alpha
            if(in_array($mime, ['image/png','image/webp'])){ imagealphablending($tmp, false); imagesavealpha($tmp, true); $transparent = imagecolorallocatealpha($tmp, 0,0,0,127); imagefilledrectangle($tmp,0,0,$nw,$nh,$transparent); }
            imagecopyresampled($tmp, $srcImg, 0,0,0,0, $nw, $nh, $sw, $sh);
            // crop center
            $dx = (int)floor(($nw - $tw)/2);
            $dy = (int)floor(($nh - $th)/2);
            $dst = imagecreatetruecolor($tw, $th);
            // preserve alpha for png/webp
            if(in_array($mime, ['image/png','image/webp'])){ imagealphablending($dst, false); imagesavealpha($dst, true); $transparent = imagecolorallocatealpha($dst, 0,0,0,127); imagefilledrectangle($dst,0,0,$tw,$th,$transparent); }
            imagecopy($dst, $tmp, 0,0, $dx, $dy, $tw, $th);
            // save according to mime (prefer jpeg for others if save func missing)
            $saved = false;
            if($mime === 'image/png' && function_exists('imagepng')){ $saved = imagepng($dst, $dstFile); }
            elseif($mime === 'image/gif' && function_exists('imagegif')){ $saved = imagegif($dst, $dstFile); }
            elseif($mime === 'image/webp' && function_exists('imagewebp')){ $saved = imagewebp($dst, $dstFile, 85); }
            else { $saved = imagejpeg($dst, $dstFile, 85); }
            imagedestroy($srcImg); imagedestroy($tmp); imagedestroy($dst);
            return $saved;
          };

          // attempt resize in-place (overwrite dest)
          $resizedOk = $resizeImage($dest, $dest, $targetW, $targetH);
          if($resizedOk){
            $imagePath = 'uploads/menu/' . $base;
          } else {
            // leave original if resize failed
            $imagePath = 'uploads/menu/' . $base;
          }
        } else {
          $errors[] = 'Failed to move uploaded image.';
        }
      }
    }
  }

  if(empty($errors)){
    try{
      // include image column if provided
      if($imagePath !== null){
        $stmt = $pdo->prepare('INSERT INTO menu_items (name, category, price, availability, image, created_at) VALUES (:name,:category,:price,:availability,:image,NOW())');
        $stmt->execute([
          ':name'=>$name,
          ':category'=>$category,
          ':price'=>number_format((float)$price,2,'.',''),
          ':availability'=>$availability,
          ':image'=>$imagePath
        ]);
      } else {
        $stmt = $pdo->prepare('INSERT INTO menu_items (name, category, price, availability, created_at) VALUES (:name,:category,:price,:availability,NOW())');
        $stmt->execute([
          ':name'=>$name,
          ':category'=>$category,
          ':price'=>number_format((float)$price,2,'.',''),
          ':availability'=>$availability
        ]);
      }
      $success = 'Menu item added.';
    }catch(Exception $e){
      $errors[] = 'Insert failed: ' . $e->getMessage();
      // if image was uploaded, delete file on failure
      if($imagePath && file_exists(__DIR__ . '/' . $imagePath)) @unlink(__DIR__ . '/' . $imagePath);
    }
  }
}

// Handle delete (optional)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])){
    $id = (int)$_POST['id'];
    try{
    // fetch image path to delete file
    $s = $pdo->prepare('SELECT image FROM menu_items WHERE id = :id');
    $s->execute([':id'=>$id]);
    $r = $s->fetch();
    $stmt = $pdo->prepare('DELETE FROM menu_items WHERE id = :id');
    $stmt->execute([':id'=>$id]);
    if($r && !empty($r['image'])){
      $fpath = __DIR__ . '/' . $r['image'];
      if(file_exists($fpath)) @unlink($fpath);
    }
        $success = 'Menu item deleted.';
    }catch(Exception $e){ $errors[] = 'Delete failed: '.$e->getMessage(); }
}

// Fetch menu items
$items = [];
try{
    $stmt = $pdo->query('SELECT * FROM menu_items ORDER BY id DESC');
    $items = $stmt->fetchAll();
}catch(Exception $e){ $errors[] = 'Failed to fetch menu items: ' . $e->getMessage(); }
?>

<div class="card p-3">
  <h3>Menu Items</h3>
  <div class="small mb-2">Manage all menu items. Add, edit, or remove dishes.</div>

  <?php if($errors): ?>
    <div class="alert alert-danger">
      <?php foreach($errors as $err) echo htmlspecialchars($err) . '<br>'; ?>
    </div>
  <?php endif; ?>
  <?php if($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="post" action="menu.php" enctype="multipart/form-data" class="row g-2 mb-3">
    <input type="hidden" name="action" value="add_menu">
    <div class="col-md-4">
      <input name="name" class="form-control" placeholder="Dish name" required>
    </div>
    <div class="col-md-3">
      <input name="category" class="form-control" placeholder="Category">
    </div>
    <div class="col-md-2">
      <input name="price" class="form-control" placeholder="Price" value="0.00">
    </div>
    <div class="col-md-2">
      <select name="availability" class="form-select">
        <option value="Available">Available</option>
        <option value="Limited">Limited</option>
        <option value="Out of stock">Out of stock</option>
      </select>
    </div>
    <div class="col-md-2">
      <input type="file" name="image" accept="image/*" class="form-control form-control-sm">
    </div>
    <div class="col-md-1 text-end">
      <button class="btn btn-primary w-100">Add</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Dish Name</th>
          <th>Category</th>
          <th>Price</th>
          <th>Availability</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($items)): ?>
          <tr><td colspan="6" class="text-center">No menu items yet.</td></tr>
        <?php else: foreach($items as $row): ?>
          <tr>
            <td>#M<?= htmlspecialchars($row['id']) ?></td>
              <td>
                <?php if(!empty($row['image'])): ?>
                  <img src="<?= htmlspecialchars($row['image']) ?>" alt="" style="height:48px;width:auto;margin-right:8px;border-radius:6px;vertical-align:middle">
                <?php endif; ?>
                <?= htmlspecialchars($row['name']) ?>
              </td>
            <td><?= htmlspecialchars($row['category']) ?></td>
            <td>$<?= number_format($row['price'],2) ?></td>
            <td>
              <?php if($row['availability'] === 'Available'): ?>
                <span class="badge bg-success">Available</span>
              <?php elseif($row['availability'] === 'Limited'): ?>
                <span class="badge bg-warning text-dark">Limited</span>
              <?php else: ?>
                <span class="badge bg-secondary">Out of stock</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <button class="btn btn-sm btn-danger btn-delete-menu" data-id="<?= (int)$row['id'] ?>">Delete</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Confirmation dialog for delete
function showConfirmDialog(title, message, onConfirm, onCancel) {
  const dialog = document.createElement('div');
  dialog.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:10000;display:flex;align-items:center;justify-content:center;';
  dialog.innerHTML = `
    <div style="background:#fff;padding:20px;border-radius:8px;max-width:400px;width:90%;">
      <h5 style="margin-bottom:12px;">${escapeHtml(title)}</h5>
      <p style="margin-bottom:20px;color:#666;">${escapeHtml(message)}</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button class="btn btn-sm btn-outline-secondary" id="cancelBtn">Cancel</button>
        <button class="btn btn-sm btn-danger" id="confirmBtn">Confirm</button>
      </div>
    </div>
  `;
  document.body.appendChild(dialog);
  
  dialog.querySelector('#confirmBtn').addEventListener('click', () => {
    dialog.remove();
    onConfirm();
  });
  
  dialog.querySelector('#cancelBtn').addEventListener('click', () => {
    dialog.remove();
    if(onCancel) onCancel();
  });
  
  dialog.addEventListener('click', (e) => {
    if(e.target === dialog) {
      dialog.remove();
      if(onCancel) onCancel();
    }
  });
}

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]; }); }

// Initialize delete buttons
function initializeDeleteButtons(){
  document.querySelectorAll('.btn-delete-menu').forEach(btn => {
    btn.addEventListener('click', function(){
      const id = this.dataset.id;
      showConfirmDialog(
        'Delete Menu Item',
        'Are you sure you want to delete this menu item? This action cannot be undone.',
        () => {
          btn.disabled = true;
          fetch('menu.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'delete', id: id })
          })
          .then(r => r.text())
          .then(html => {
            // Reload the page to show updated menu
            window.location.reload();
          })
          .catch(e => {
            alert('Error: ' + e.message);
            btn.disabled = false;
          });
        }
      );
    });
  });
}

// Initialize when DOM is ready
if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initializeDeleteButtons);
} else {
  initializeDeleteButtons();
}
</script>