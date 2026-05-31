<?php
// admin.php
session_start();

// 1. 核心權限鎖
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index");
    exit;
}

$db_host = '127.0.0.1';
$db_name = 'crypto_trading_db';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

$admin_message = '';

// ==========================================
// CUD 操作處理區塊 (左半邊 + 右半邊)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ------------------------------------------
    // 【左半邊：使用者管理】
    // ------------------------------------------
    if ($action === 'update_user') {
        $target_id = (int)$_POST['target_user_id'];
        $new_role = $_POST['new_role'];
        $new_balance = (float)$_POST['new_balance'];

        if ($target_id === $_SESSION['user_id'] && $new_role !== 'admin') {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 操作拒絕：你不能拔除自己的管理員權限！</div>";
        } else {
            try {
                $pdo->prepare("UPDATE Users SET role = ?, balance = ? WHERE user_id = ?")->execute([$new_role, $new_balance, $target_id]);
                $admin_message = "<div class='alert alert-success border-0 bg-success text-light mb-4 shadow-sm'>✅ 會員 #{$target_id} 資料更新成功！</div>";
            } catch (Exception $e) {
                $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 更新失敗: " . $e->getMessage() . "</div>";
            }
        }
    }

    if ($action === 'delete_user') {
        $target_id = (int)$_POST['target_user_id'];

        // 🚀 新增資安防禦：去資料庫查這個人的身分，Admin 不能互砍！
        $stmt = $pdo->prepare("SELECT role FROM Users WHERE user_id = ?");
        $stmt->execute([$target_id]);
        $target_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($target_id === $_SESSION['user_id']) {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 操作拒絕：你不能刪除自己的帳號！</div>";
        } elseif ($target_user && $target_user['role'] === 'admin') {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 操作拒絕：管理員之間無法互相刪除帳號（同級保護機制）！</div>";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM Portfolios WHERE user_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM Transactions WHERE user_id = ?")->execute([$target_id]);
                $pdo->prepare("DELETE FROM Users WHERE user_id = ?")->execute([$target_id]);
                $pdo->commit();
                $admin_message = "<div class='alert alert-warning border-0 bg-warning text-dark mb-4 shadow-sm'>🗑️ 會員 #{$target_id} 及其關聯紀錄已徹底刪除。</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 刪除失敗: " . $e->getMessage() . "</div>";
            }
        }
    }

    // ------------------------------------------
    // 【右半邊：交易標的管理】
    // ------------------------------------------
    // 🚀 功能三：上架新標的 (Create)
    if ($action === 'add_asset') {
        $symbol = strtoupper(trim($_POST['symbol']));
        $name = trim($_POST['name']);
        $price = (float)$_POST['price'];

        try {
            $pdo->prepare("INSERT INTO Assets (symbol, name, current_price, status) VALUES (?, ?, ?, 'trading')")->execute([$symbol, $name, $price]);
            $admin_message = "<div class='alert alert-success border-0 bg-success text-light mb-4 shadow-sm'>✅ 成功上架新標的：{$symbol}！</div>";
        } catch (Exception $e) {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 上架失敗 (代碼可能已存在): " . $e->getMessage() . "</div>";
        }
    }

    // 🚀 功能四：更新標的資訊 (Update)
    if ($action === 'update_asset') {
        $asset_id = (int)$_POST['target_asset_id'];
        $symbol = strtoupper(trim($_POST['edit_symbol']));
        $name = trim($_POST['edit_name']);
        $status = $_POST['edit_status'];

        try {
            // 注意：通常後台不手動改價格(因為有 Python 爬蟲在抓)，所以這裡只改名稱、代號與狀態
            $pdo->prepare("UPDATE Assets SET symbol = ?, name = ?, status = ? WHERE asset_id = ?")->execute([$symbol, $name, $status, $asset_id]);
            $admin_message = "<div class='alert alert-success border-0 bg-success text-light mb-4 shadow-sm'>✅ 標的 #{$asset_id} 資訊更新成功！</div>";
        } catch (Exception $e) {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 更新失敗: " . $e->getMessage() . "</div>";
        }
    }

    // 🚀 功能五：下架標的 (Soft Delete)
    if ($action === 'delist_asset') {
        $asset_id = (int)$_POST['target_asset_id'];

        try {
            // 軟刪除：將狀態改為 'delisted'，保留資料庫關聯完整性
            $pdo->prepare("UPDATE Assets SET status = 'delisted' WHERE asset_id = ?")->execute([$asset_id]);
            $admin_message = "<div class='alert alert-warning border-0 bg-warning text-dark mb-4 shadow-sm'>🚫 標的 #{$asset_id} 已強制下架，將不再顯示於交易列表。</div>";
        } catch (Exception $e) {
            $admin_message = "<div class='alert alert-danger border-0 bg-danger text-light mb-4 shadow-sm'>❌ 下架失敗: " . $e->getMessage() . "</div>";
        }
    }
}

// ==========================================
// 讀取 (Read) - 撈取最新資料
// ==========================================
$users = $pdo->query("SELECT user_id, username, email, balance, role, created_at FROM Users ORDER BY user_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$assets = $pdo->query("SELECT asset_id, symbol, name, current_price, status FROM Assets ORDER BY asset_id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系統管理後台 - QUANT TERMINAL</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #0b0e11; color: #eaecef; font-family: sans-serif; }
        .navbar-custom { background-color: #161a1e; border-bottom: 1px solid #2f3336; }
        .card { background-color: #161a1e; border: 1px solid #2f3336; }
        .table { color: #eaecef; }
        .table-dark { --bs-table-bg: #161a1e; --bs-table-border-color: #2f3336; }
        .badge-admin { background-color: #f5b300; color: #000; }
        .badge-member { background-color: #2b3139; color: #00c087; border: 1px solid #00c087; }
        .form-control, .form-select { background-color: #2b3139 !important; border: 1px solid #474f59 !important; color: white !important; }
        .form-control:focus, .form-select:focus { border-color: #00c087 !important; box-shadow: none !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-custom py-2 shadow-sm mb-4">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-warning d-flex align-items-center" href="#">
            <i class="bi bi-shield-lock-fill me-2"></i>系統管理後台 (Admin Panel)
        </a>
        <div class="d-flex align-items-center ms-auto">
            <span class="text-secondary me-3">管理員：<strong class="text-light"><?= htmlspecialchars($_SESSION['username']) ?></strong></span>
            <a href="index" class="btn btn-outline-success btn-sm rounded-pill px-3"><i class="bi bi-display me-1"></i>返回交易終端</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <?= $admin_message ?>
    
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card p-4 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-people-fill me-2 text-info"></i>使用者帳號管理</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead class="text-secondary small">
                            <tr>
                                <th>ID / 帳號</th>
                                <th>模擬金餘額</th>
                                <th>角色</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold">#<?= $u['user_id'] ?> <?= htmlspecialchars($u['username']) ?></div>
                                    </td>
                                    <td class="text-warning fw-bold">$ <?= number_format($u['balance'], 2) ?></td>
                                    <td>
                                        <?php if ($u['role'] === 'admin'): ?>
                                            <span class="badge badge-admin px-2 py-1"><i class="bi bi-star-fill me-1"></i>管理員</span>
                                        <?php else: ?>
                                            <span class="badge badge-member px-2 py-1">會員</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info py-0 px-2 me-1" 
                                            onclick="openEditUser(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>', <?= $u['balance'] ?>, '<?= $u['role'] ?>')">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger py-0 px-2" 
                                            onclick="openDeleteUser(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card p-4 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0"><i class="bi bi-coin me-2 text-success"></i>交易標的管理</h5>
                    <button class="btn btn-sm btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#addAssetModal"><i class="bi bi-plus-lg me-1"></i>上架新標的</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead class="text-secondary small">
                            <tr>
                                <th>代碼 (Symbol)</th>
                                <th>當前報價</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $a): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($a['symbol']) ?></div>
                                        <div class="small text-secondary"><?= htmlspecialchars($a['name']) ?></div>
                                    </td>
                                    <td class="text-light">$ <?= number_format($a['current_price'], 2) ?></td>
                                    <td>
                                        <?php if ($a['status'] === 'trading'): ?>
                                            <span class="text-success small fw-bold"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>交易中</span>
                                        <?php else: ?>
                                            <span class="text-danger small fw-bold"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i>已下架</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-info py-0 px-2 me-1" 
                                            onclick="openEditAsset(<?= $a['asset_id'] ?>, '<?= htmlspecialchars($a['symbol'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['name'], ENT_QUOTES) ?>', '<?= $a['status'] ?>')">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <?php if ($a['status'] === 'trading'): ?>
                                            <button class="btn btn-sm btn-outline-warning py-0 px-2" 
                                                onclick="openDelistAsset(<?= $a['asset_id'] ?>, '<?= htmlspecialchars($a['symbol'], ENT_QUOTES) ?>')">
                                                <i class="bi bi-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once 'admin_left_modals.php'; // 為了版面簡潔，我把左側 Modal 標籤直接寫在下方 ?>

<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary shadow-lg">
            <div class="modal-header border-secondary border-opacity-50">
                <h5 class="modal-title text-info fw-bold">編輯會員資料</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="target_user_id" id="edit-user-id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary small">帳號名稱</label>
                        <input type="text" class="form-control" id="edit-username" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small">角色權限設定</label>
                        <select class="form-select fw-bold" name="new_role" id="edit-role">
                            <option value="member">一般會員</option>
                            <option value="admin">系統管理員</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-secondary small">模擬金餘額 (USDT)</label>
                        <input type="number" step="0.01" class="form-control text-warning fw-bold fs-5" name="new_balance" id="edit-balance" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-50">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-sm btn-info fw-bold text-dark px-4">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-danger border shadow-lg">
            <div class="modal-header border-danger border-opacity-50">
                <h5 class="modal-title text-danger fw-bold">警告：刪除會員</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="target_user_id" id="delete-user-id">
                <div class="modal-body py-4 text-center">
                    <h5 class="mt-3 text-light">確定要徹底刪除 <strong class="text-warning" id="delete-username"></strong> 嗎？</h5>
                </div>
                <div class="modal-footer border-danger border-opacity-50 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger fw-bold px-4">確認徹底刪除</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addAssetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-success border shadow-lg">
            <div class="modal-header border-success border-opacity-50">
                <h5 class="modal-title text-success fw-bold"><i class="bi bi-plus-circle me-2"></i>上架新交易標的</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_asset">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">標的代碼 (例如：NVDA)</label>
                        <input type="text" class="form-control text-uppercase" name="symbol" required placeholder="英文字母代碼">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">完整名稱 (例如：NVIDIA Corp)</label>
                        <input type="text" class="form-control" name="name" required placeholder="公司或幣種全名">
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-secondary small fw-bold">初始掛牌價 (USDT)</label>
                        <input type="number" step="0.0001" min="0" class="form-control text-success fw-bold fs-5" name="price" value="100.00" required>
                    </div>
                </div>
                <div class="modal-footer border-success border-opacity-50">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-sm btn-success fw-bold px-4">確認上架</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editAssetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary shadow-lg">
            <div class="modal-header border-secondary border-opacity-50">
                <h5 class="modal-title text-info fw-bold">編輯標的資訊</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_asset">
                <input type="hidden" name="target_asset_id" id="edit-asset-id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">標的代碼</label>
                        <input type="text" class="form-control text-uppercase" name="edit_symbol" id="edit-asset-symbol" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">完整名稱</label>
                        <input type="text" class="form-control" name="edit_name" id="edit-asset-name" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-secondary small fw-bold">交易狀態</label>
                        <select class="form-select fw-bold" name="edit_status" id="edit-asset-status">
                            <option value="trading">正常交易中 (Trading)</option>
                            <option value="delisted">隱藏/下架 (Delisted)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary border-opacity-50">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-sm btn-info fw-bold text-dark px-4">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="delistAssetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-warning border shadow-lg">
            <div class="modal-header border-warning border-opacity-50">
                <h5 class="modal-title text-warning fw-bold"><i class="bi bi-ban me-2"></i>確認下架標的</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delist_asset">
                <input type="hidden" name="target_asset_id" id="delist-asset-id">
                <div class="modal-body py-4 text-center">
                    <h5 class="mt-3 text-light">確定要強制下架 <strong class="text-warning" id="delist-asset-symbol"></strong> 嗎？</h5>
                    <p class="text-secondary small mt-2 mb-0">下架後該標的將從交易終端隱藏，但<strong class="text-info">保留所有使用者的歷史交易紀錄</strong>。</p>
                </div>
                <div class="modal-footer border-warning border-opacity-50 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-warning fw-bold px-4">確認下架</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 左側：使用者管理 JS
    function openEditUser(id, username, balance, role) {
        document.getElementById('edit-user-id').value = id;
        document.getElementById('edit-username').value = username;
        document.getElementById('edit-balance').value = balance;
        document.getElementById('edit-role').value = role;
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    function openDeleteUser(id, username) {
        document.getElementById('delete-user-id').value = id;
        document.getElementById('delete-username').innerText = username;
        new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
    }

    // 右側：交易標的管理 JS
    function openEditAsset(id, symbol, name, status) {
        document.getElementById('edit-asset-id').value = id;
        document.getElementById('edit-asset-symbol').value = symbol;
        document.getElementById('edit-asset-name').value = name;
        document.getElementById('edit-asset-status').value = status;
        new bootstrap.Modal(document.getElementById('editAssetModal')).show();
    }

    function openDelistAsset(id, symbol) {
        document.getElementById('delist-asset-id').value = id;
        document.getElementById('delist-asset-symbol').innerText = symbol;
        new bootstrap.Modal(document.getElementById('delistAssetModal')).show();
    }
</script>
</body>
</html>