<?php
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $asset_id = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 1;
    $interval = isset($_GET['interval']) ? $_GET['interval'] : '1m';
    $allowed_intervals = ['1m', '3m', '5m', '15m', '1h', '4h', '1d'];
    
    if (!in_array($interval, $allowed_intervals)) { $interval = '1m'; }

    // 🚀 核心防爆機制 1：先查出它是加密貨幣還是傳統股票
    $stmt = $pdo->prepare("SELECT symbol FROM Assets WHERE asset_id = ?");
    $stmt->execute([$asset_id]);
    $asset_info = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_crypto = ($asset_info && strpos($asset_info['symbol'], 'USDT') !== false);

    $interval_seconds = ['1m'=>60, '3m'=>180, '5m'=>300, '15m'=>900, '1h'=>3600, '4h'=>14400, '1d'=>86400];
    $step = $interval_seconds[$interval];
    $table_name = "Klines_" . $interval;

    $stmt = $pdo->prepare("
        SELECT 
            UNIX_TIMESTAMP(open_time) as time, 
            open_price as open, 
            high_price as high, 
            low_price as low, 
            close_price as close 
        FROM {$table_name} 
        WHERE asset_id = ? 
        ORDER BY open_time DESC 
        LIMIT 1000
    ");
    $stmt->execute([$asset_id]);
    $raw_klines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($raw_klines)) {
        echo json_encode([]);
        exit;
    }

    $raw_klines = array_reverse($raw_klines);
    
    // 🚀 核心防爆機制 2：如果是股票，絕對不執行補間，直接快速輸出原始資料
    if (!$is_crypto) {
        $formatted_klines = [];
        foreach ($raw_klines as $k) {
            $formatted_klines[] = [
                'time'  => (int)$k['time'],
                'open'  => (float)$k['open'],
                'high'  => (float)$k['high'],
                'low'   => (float)$k['low'],
                'close' => (float)$k['close']
            ];
        }
        echo json_encode($formatted_klines);
        exit;
    }

    // ==========================================
    // 以下只有「加密貨幣」會執行補間演算法
    // ==========================================
    $filled_klines = [];
    $prev_kline = null;

    foreach ($raw_klines as $current_kline) {
        if ($prev_kline !== null) {
            $expected_time = $prev_kline['time'] + $step;
            
            // 🚀 核心防爆機制 3：如果斷線超過 100 根 (例如休眠太久)，就放棄補齊，避免產生過大 JSON
            $gap_count = ($current_kline['time'] - $expected_time) / $step;
            
            if ($current_kline['time'] > $expected_time && $gap_count < 100) {
                while ($current_kline['time'] > $expected_time) {
                    $filled_klines[] = [
                        'time'  => (int)$expected_time,
                        'open'  => (float)$prev_kline['close'],
                        'high'  => (float)$prev_kline['close'],
                        'low'   => (float)$prev_kline['close'],
                        'close' => (float)$prev_kline['close']
                    ];
                    $expected_time += $step;
                }
            }
        }
        
        $filled_klines[] = [
            'time'  => (int)$current_kline['time'],
            'open'  => (float)$current_kline['open'],
            'high'  => (float)$current_kline['high'],
            'low'   => (float)$current_kline['low'],
            'close' => (float)$current_kline['close']
        ];
        $prev_kline = $current_kline;
    }

    echo json_encode($filled_klines);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
