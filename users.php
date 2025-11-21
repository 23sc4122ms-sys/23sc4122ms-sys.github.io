<?php
// users.php - registration form and users list (fragment for admin panel)
require_once __DIR__ . '/db.php';
session_start();

$errors = [];
$success = '';
$roles = ['owner','admin','rider','customer'];

// Handle AJAX requests for user actions
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    $action = $_POST['action'];
    
    // Only handle AJAX actions if user_id is present (skip create_user)
    if(in_array($action, ['get_user', 'update_user', 'deactivate', 'activate'])){
        header('Content-Type: application/json');
        $userId = (int)($_POST['user_id'] ?? 0);
        
        if($userId <= 0){
            echo json_encode(['ok' => false, 'error' => 'Invalid user ID']);
            exit;
        }
        
        try {
            $pdo = getPDO();
            
            if($action === 'get_user'){
                $stmt = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if($user){
                    echo json_encode(['ok' => true, 'user' => $user]);
                } else {
                    echo json_encode(['ok' => false, 'error' => 'User not found']);
                }
                exit;
            }
            
            if($action === 'update_user'){
                $name = trim($_POST['name'] ?? '');
                $email = strtolower(trim($_POST['email'] ?? ''));
                $role = $_POST['role'] ?? '';
                
                if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
                    echo json_encode(['ok' => false, 'error' => 'Invalid email address']);
                    exit;
                }
                
                // Check if email is already used by another user
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $stmt->execute([$email, $userId]);
                if($stmt->fetch()){
                    echo json_encode(['ok' => false, 'error' => 'Email already in use']);
                    exit;
                }
                
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?');
                $stmt->execute([$name ?: null, $email, $role, $userId]);
                echo json_encode(['ok' => true, 'message' => 'User updated successfully']);
                exit;
            }
            
            if($action === 'deactivate'){
                $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
                $stmt->execute(['inactive', $userId]);
                echo json_encode(['ok' => true, 'message' => 'User deactivated']);
                exit;
            }
            
            if($action === 'activate'){
                $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
                $stmt->execute(['active', $userId]);
                echo json_encode(['ok' => true, 'message' => 'User activated']);
                exit;
            }
        } catch(Exception $e){
            echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Handle create account POST (regular form submission)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user'){
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'customer';

    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = 'Please enter a valid email address.';
    }
    if(strlen($password) < 6){
        $errors[] = 'Password must be at least 6 characters.';
    }
    if(!in_array($role, $roles, true)){
        $errors[] = 'Invalid role selected.';
    }

    if(empty($errors)){
        try{
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if($stmt->fetch()){
                $errors[] = 'An account with that email already exists.';
            }else{
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,status) VALUES (?,?,?,?,?)');
                $stmt->execute([$name ?: null, $email, $hash, $role, 'active']);
                $success = 'Account created successfully.';
                // clear POST values for form
                $_POST = [];
            }
        }catch(Exception $e){
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch users for listing
try{
    $pdo = getPDO();
    $stmt = $pdo->query('SELECT id,name,email,role,status,created_at FROM users ORDER BY id DESC');
    $users = $stmt->fetchAll();
}catch(Exception $e){
    $users = [];
    $errors[] = 'Could not fetch users: ' . $e->getMessage();
}
?>

<div class="row g-3">
  <div class="col-12">
    <div class="card p-3">
      <h4 class="mb-2">Create account</h4>
      <div class="small mb-3">Create an account for Rider, Admin or Owner.</div>

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

  <form method="post" action="users.php" class="row g-2">
        <input type="hidden" name="action" value="create_user">
        <div class="col-md-3">
          <label class="form-label">Full name (optional)</label>
          <input class="form-control" name="name" value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Email</label>
          <input class="form-control" name="email" type="email" required value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Password</label>
          <input class="form-control" name="password" type="password" required>
          <div class="small text-muted">Use at least 6 characters.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Role</label>
          <select class="form-select" name="role">
            <?php foreach($roles as $r): ?>
              <option value="<?=htmlspecialchars($r)?>" <?=((($_POST['role'] ?? '') === $r)? 'selected':'')?>><?=htmlspecialchars(ucfirst($r))?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit">Create account</button>
            <button class="btn btn-outline-secondary" type="reset">Reset</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <h4 class="mb-2">Users</h4>
      <div class="small mb-3">Manage user accounts. View details, deactivate, or reset passwords.</div>

      <div class="table-responsive">
        <table class="table table-striped table-bordered align-middle">
          <thead class="table-light">
            <tr>
              <th>User ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Status</th>
              <th style="text-align:right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($users)): ?>
              <tr><td colspan="6" class="text-center">No users found.</td></tr>
            <?php else: ?>
              <?php foreach($users as $u): ?>
                <tr>
                  <td>#U<?=str_pad($u['id'],3,'0',STR_PAD_LEFT)?></td>
                  <td><?=htmlspecialchars($u['name'] ?? '')?></td>
                  <td><?=htmlspecialchars($u['email'])?></td>
                  <td><?=htmlspecialchars(ucfirst($u['role']))?></td>
                  <td>
                    <?php if($u['status'] === 'active'): ?>
                      <span class="badge bg-success">Active</span>
                    <?php elseif($u['status'] === 'inactive'): ?>
                      <span class="badge bg-danger">Inactive</span>
                    <?php else: ?>
                      <span class="badge bg-secondary"><?=htmlspecialchars($u['status'])?></span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right">
                    <button class="btn btn-sm btn-primary btn-edit" data-id="<?= (int)$u['id'] ?>">Edit</button>
                    <?php if($u['status'] === 'active'): ?>
                      <button class="btn btn-sm btn-danger btn-deactivate" data-id="<?= (int)$u['id'] ?>">Deactivate</button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-success btn-activate" data-id="<?= (int)$u['id'] ?>">Activate</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; max-width:500px; width:92%; padding:24px; border-radius:8px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
      <h4 id="editTitle">Edit User</h4>
      <button id="editModalClose" class="btn btn-sm btn-outline-secondary">Close</button>
    </div>
    <form id="editUserForm">
      <div class="mb-3">
        <label class="form-label">Full Name</label>
        <input type="text" id="editName" class="form-control" placeholder="Name (optional)">
      </div>
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" id="editEmail" class="form-control" placeholder="Email" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Role</label>
        <select id="editRole" class="form-select">
          <option value="customer">Customer</option>
          <option value="rider">Rider</option>
          <option value="admin">Admin</option>
          <option value="owner">Owner</option>
        </select>
      </div>
      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:20px;">
        <button type="button" id="editModalCancelBtn" class="btn btn-outline-secondary">Cancel</button>
        <button type="submit" id="editSaveBtn" class="btn btn-primary">Save Changes</button>
      </div>
      <div id="editMessage" style="margin-top:12px;"></div>
    </form>
  </div>
</div>

<script>
// Confirmation dialog
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

function showToast(message, type = 'success'){
  const toast = document.createElement('div');
  const bgColor = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
  toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10001;padding:12px 16px;border-radius:6px;background:#28a745;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,0.2);min-width:250px;';
  if(type === 'error') toast.style.background = '#dc3545';
  if(type === 'info') toast.style.background = '#17a2b8';
  
  toast.innerHTML = escapeHtml(message);
  document.body.appendChild(toast);
  
  setTimeout(() => {
    toast.style.transition = 'opacity 0.3s';
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]; }); }

function closeEditModal(){
  document.getElementById('editUserModal').style.display = 'none';
  document.getElementById('editUserForm').reset();
  document.getElementById('editMessage').innerHTML = '';
}

function initializeUserActions(){
  // Deactivate buttons
  document.querySelectorAll('.btn-deactivate').forEach(btn => {
    btn.addEventListener('click', function(){
      const userId = this.dataset.id;
      const row = this.closest('tr');
      showConfirmDialog(
        'Deactivate User',
        'Are you sure you want to deactivate this user account?',
        () => {
          btn.disabled = true;
          fetch('users.php', {
            method: 'POST',
            body: new URLSearchParams({ action: 'deactivate', user_id: userId })
          })
          .then(r => r.json())
          .then(json => {
            if(json.ok){
              showToast('User deactivated successfully', 'success');
              // Update status badge
              const statusCell = row.querySelector('td:nth-child(5)');
              if(statusCell){
                statusCell.innerHTML = '<span class="badge bg-danger">Inactive</span>';
              }
              // Replace button
              btn.className = 'btn btn-sm btn-success btn-activate';
              btn.textContent = 'Activate';
              btn.disabled = false;
              // Rebind event
              btn.onclick = null;
              btn.removeEventListener('click', arguments.callee);
              btn.addEventListener('click', activateHandler);
            } else {
              showToast(json.error || 'Failed to deactivate', 'error');
              btn.disabled = false;
            }
          })
          .catch(e => {
            showToast('Error: ' + e.message, 'error');
            btn.disabled = false;
          });
        }
      );
    });
  });

  // Activate buttons
  const activateHandler = function(){
    const userId = this.dataset.id;
    const row = this.closest('tr');
    showConfirmDialog(
      'Activate User',
      'Are you sure you want to activate this user account?',
      () => {
        this.disabled = true;
        fetch('users.php', {
          method: 'POST',
          body: new URLSearchParams({ action: 'activate', user_id: userId })
        })
        .then(r => r.json())
        .then(json => {
          if(json.ok){
            showToast('User activated successfully', 'success');
            // Update status badge
            const statusCell = row.querySelector('td:nth-child(5)');
            if(statusCell){
              statusCell.innerHTML = '<span class="badge bg-success">Active</span>';
            }
            // Replace button
            this.className = 'btn btn-sm btn-danger btn-deactivate';
            this.textContent = 'Deactivate';
            this.disabled = false;
            // Rebind event
            this.removeEventListener('click', activateHandler);
            this.addEventListener('click', activateHandler);
          } else {
            showToast(json.error || 'Failed to activate', 'error');
            this.disabled = false;
          }
        })
        .catch(e => {
          showToast('Error: ' + e.message, 'error');
          this.disabled = false;
        });
      }
    );
  };

  document.querySelectorAll('.btn-activate').forEach(btn => {
    btn.addEventListener('click', activateHandler);
  });

  // Edit buttons
  document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', function(){
      const userId = this.dataset.id;
      const row = this.closest('tr');
      
      // Fetch user data
      fetch('users.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'get_user', user_id: userId })
      })
      .then(r => r.json())
      .then(json => {
        if(json.ok){
          const user = json.user;
          document.getElementById('editName').value = user.name || '';
          document.getElementById('editEmail').value = user.email || '';
          document.getElementById('editRole').value = user.role || 'customer';
          document.getElementById('editUserModal').style.display = 'flex';
          document.getElementById('editMessage').innerHTML = '';
          
          // Save handler
          document.getElementById('editUserForm').onsubmit = function(e){
            e.preventDefault();
            const name = document.getElementById('editName').value.trim();
            const email = document.getElementById('editEmail').value.trim();
            const role = document.getElementById('editRole').value;
            
            document.getElementById('editSaveBtn').disabled = true;
            
            fetch('users.php', {
              method: 'POST',
              body: new URLSearchParams({ 
                action: 'update_user', 
                user_id: userId,
                name: name,
                email: email,
                role: role
              })
            })
            .then(r => r.json())
            .then(json => {
              if(json.ok){
                showToast('User updated successfully', 'success');
                // Update table row
                row.querySelector('td:nth-child(2)').textContent = name || '';
                row.querySelector('td:nth-child(3)').textContent = email || '';
                row.querySelector('td:nth-child(4)').textContent = role.charAt(0).toUpperCase() + role.slice(1);
                setTimeout(() => {
                  closeEditModal();
                }, 800);
              } else {
                document.getElementById('editMessage').innerHTML = '<div class="alert alert-danger">' + (json.error || 'Failed to update') + '</div>';
                document.getElementById('editSaveBtn').disabled = false;
              }
            })
            .catch(e => {
              showToast('Error: ' + e.message, 'error');
              document.getElementById('editSaveBtn').disabled = false;
            });
          };
        } else {
          showToast(json.error || 'Failed to load user', 'error');
        }
      })
      .catch(e => {
        showToast('Error: ' + e.message, 'error');
      });
    });
  });

  // Modal close handlers
  document.getElementById('editModalClose').addEventListener('click', closeEditModal);
  document.getElementById('editModalCancelBtn').addEventListener('click', closeEditModal);
  
  // Close modal when clicking outside
  document.getElementById('editUserModal').addEventListener('click', function(e){
    if(e.target === this) closeEditModal();
  });
}

// Initialize when DOM is ready
if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initializeUserActions);
} else {
  initializeUserActions();
}
</script>

