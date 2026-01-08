<?php
// index.php

// Jika diakses via CLI, tampilkan pesan dan exit
if (php_sapi_name() === 'cli') {
    die("Program ini harus dijalankan melalui web server. Silakan akses via browser.\n");
}

session_start();

// Fungsi untuk koneksi database SQLite
function getDB() {
    static $db = null;
    if ($db === null) {
        $databaseFile = __DIR__ . '/premier_league_analysis.mysql';
        try {
            $db = new PDO("mysql:" . $databaseFile);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Buat tabel jika belum ada
            $sql = "CREATE TABLE IF NOT EXISTS clubs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                matches_played INTEGER DEFAULT 0,
                wins INTEGER DEFAULT 0,
                losses INTEGER DEFAULT 0,
                draws INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            $db->exec($sql);
        } catch (PDOException $e) {
            // Jika tidak bisa menggunakan SQLite, kita akan menggunakan session
            $db = false;
        }
    }
    return $db;
}

// Inisialisasi data di session jika tidak menggunakan database
$db = getDB();
if ($db === false) {
    // Mode fallback: menggunakan session
    if (!isset($_SESSION['clubs'])) {
        $_SESSION['clubs'] = [];
    }
    $useDB = false;
} else {
    $useDB = true;
}

// Fungsi untuk menambah klub (dengan database atau session)
function addClub($name, $matches_played, $wins, $losses, $draws, $points) {
    global $db, $useDB;
    if ($useDB) {
        $stmt = $db->prepare("INSERT INTO clubs (name, matches_played, wins, losses, draws, points) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $matches_played, $wins, $losses, $draws, $points]);
        return $db->lastInsertId();
    } else {
        $id = count($_SESSION['clubs']) + 1;
        $_SESSION['clubs'][] = [
            'id' => $id,
            'name' => $name,
            'matches_played' => $matches_played,
            'wins' => $wins,
            'losses' => $losses,
            'draws' => $draws,
            'points' => $points,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $id;
    }
}

// Fungsi untuk menghapus klub
function deleteClub($id) {
    global $db, $useDB;
    if ($useDB) {
        $stmt = $db->prepare("DELETE FROM clubs WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        foreach ($_SESSION['clubs'] as $key => $club) {
            if ($club['id'] == $id) {
                unset($_SESSION['clubs'][$key]);
                break;
            }
        }
        // Re-index array
        $_SESSION['clubs'] = array_values($_SESSION['clubs']);
    }
}

// Fungsi untuk mendapatkan semua klub
function getClubs() {
    global $db, $useDB;
    if ($useDB) {
        $stmt = $db->query("SELECT * FROM clubs ORDER BY points DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Urutkan berdasarkan poin (desc)
        $clubs = $_SESSION['clubs'];
        usort($clubs, function($a, $b) {
            return $b['points'] - $a['points'];
        });
        return $clubs;
    }
}

// Fungsi untuk menghitung probabilitas
function calculateProbability($clubs) {
    $total_matches = 38;
    foreach ($clubs as &$club) {
        $matches_played = $club['matches_played'];
        $wins = $club['wins'];
        $remaining_matches = $total_matches - $matches_played;
        
        if ($matches_played > 0 && $remaining_matches > 0) {
            $p = $wins / $matches_played;
            $expected_wins = $remaining_matches * $p;
            $expected_additional_points = $expected_wins * 3;
            $expected_total_points = $club['points'] + $expected_additional_points;
            
            $club['win_probability'] = $p;
            $club['remaining_matches'] = $remaining_matches;
            $club['expected_wins'] = round($expected_wins, 2);
            $club['expected_total_points'] = round($expected_total_points, 2);
        } else {
            $club['win_probability'] = 0;
            $club['remaining_matches'] = $remaining_matches;
            $club['expected_wins'] = 0;
            $club['expected_total_points'] = $club['points'];
        }
    }
    
    // Normalisasi probabilitas
    $total_expected_points = array_sum(array_column($clubs, 'expected_total_points'));
    foreach ($clubs as &$club) {
        if ($total_expected_points > 0) {
            $club['champion_probability'] = round(($club['expected_total_points'] / $total_expected_points) * 100, 2);
        } else {
            $club['champion_probability'] = 0;
        }
    }
    
    // Urutkan berdasarkan probabilitas juara
    usort($clubs, function($a, $b) {
        return $b['champion_probability'] <=> $a['champion_probability'];
    });
    
    return $clubs;
}

// Proses form
$message = '';
$message_type = '';
$calculated_clubs = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_club':
                $name = trim($_POST['name'] ?? '');
                $matches_played = intval($_POST['matches_played'] ?? 0);
                $wins = intval($_POST['wins'] ?? 0);
                $losses = intval($_POST['losses'] ?? 0);
                $draws = intval($_POST['draws'] ?? 0);
                
                if (empty($name)) {
                    $message = "Nama klub tidak boleh kosong";
                    $message_type = "error";
                } elseif ($wins + $losses + $draws > $matches_played) {
                    $message = "Jumlah menang + kalah + seri tidak boleh lebih dari total pertandingan";
                    $message_type = "error";
                } else {
                    $points = ($wins * 3) + ($draws * 1);
                    
                    // Cek duplikasi nama
                    $clubs = getClubs();
                    $exists = false;
                    foreach ($clubs as $club) {
                        if (strcasecmp($club['name'], $name) === 0) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if ($exists) {
                        $message = "Nama klub '$name' sudah ada";
                        $message_type = "error";
                    } else {
                        addClub($name, $matches_played, $wins, $losses, $draws, $points);
                        $message = "Klub berhasil ditambahkan";
                        $message_type = "success";
                    }
                }
                break;
                
            case 'delete_club':
                $id = intval($_POST['id'] ?? 0);
                if ($id > 0) {
                    deleteClub($id);
                    $message = "Klub berhasil dihapus";
                    $message_type = "success";
                }
                break;
                
            case 'calculate_probability':
                $clubs = getClubs();
                if (count($clubs) < 2) {
                    $message = "Minimal 2 klub diperlukan untuk perhitungan probabilitas";
                    $message_type = "error";
                } else {
                    $calculated_clubs = calculateProbability($clubs);
                    $message = "Probabilitas berhasil dihitung";
                    $message_type = "success";
                }
                break;
        }
    }
}

// Ambil data klub untuk ditampilkan
$clubs = getClubs();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Analisis Probabilitas Juara Liga Inggris</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS dari sebelumnya */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(to right, #1e3c72, #2a5298);
            color: white;
            padding: 30px 40px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .message {
            padding: 15px 20px;
            margin: 20px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
            transition: opacity 0.3s;
        }

        .close-btn:hover {
            opacity: 1;
        }

        .dashboard {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            padding: 30px;
        }

        @media (max-width: 1024px) {
            .dashboard {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            padding: 30px;
            border: 1px solid #eaeaea;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card h2 {
            color: #2a5298;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-group label {
            font-weight: 600;
            color: #444;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group input {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .points-display {
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
            border: 2px dashed #dee2e6;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-success {
            background: linear-gradient(to right, #28a745, #20c997);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 0.9rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .inline-form {
            display: inline;
        }

        .delete-form {
            display: inline;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 25px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .data-table thead {
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
        }

        .data-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #eaeaea;
            transition: background 0.3s;
        }

        .data-table tbody tr:hover {
            background: #f8f9fa;
        }

        .data-table td {
            padding: 15px;
        }

        .club-name {
            font-weight: 600;
            color: #2a5298;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            background: #e9ecef;
            border-radius: 50px;
            font-weight: 600;
            color: #495057;
            min-width: 40px;
            text-align: center;
        }

        .probability-bar {
            width: 100%;
            height: 30px;
            background: #e9ecef;
            border-radius: 15px;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
        }

        .probability-fill {
            height: 100%;
            background: linear-gradient(to right, #28a745, #20c997);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 60px; /* Lebar minimum lebih besar */
            position: relative;
            transition: width 0.8s ease;
        }

        .probability-fill span {
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            padding: 0 10px;
            box-sizing: border-box;
        }

        /* Untuk persentase tinggi (>80%), teks ditampilkan di dalam bar */
        .probability-fill.high-percentage {
            justify-content: flex-end;
            padding-right: 10px;
        }

        .probability-fill.high-percentage span {
            position: static;
            transform: none;
        }

        /* Untuk persentase rendah (<20%), teks ditampilkan di luar bar */
        .probability-fill.low-percentage {
            background: #f8f9fa;
            border: 2px solid #28a745;
        }

        .probability-fill.low-percentage span {
            color: #28a745;
            font-weight: bold;
            left: auto;
            right: -5px;
            transform: none;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }

        .empty-state i {
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 1.1rem;
        }

        .summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 25px;
        }

        .summary h3 {
            color: #2a5298;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary ul {
            margin-left: 20px;
            margin-top: 10px;
        }

        .summary li {
            margin-bottom: 8px;
        }

        .footer {
            background: #f8f9fa;
            padding: 25px;
            text-align: center;
            border-top: 1px solid #eaeaea;
            color: #6c757d;
        }

        .footer p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1><i class="fas fa-futbol"></i> Analisis Probabilitas Juara Liga Inggris</h1>
            <p class="subtitle">Menggunakan Distribusi Binomial untuk Prediksi Juara</p>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
                <button class="close-btn" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <div class="dashboard">
            <!-- Form Input Klub -->
            <div class="card">
                <h2><i class="fas fa-plus-circle"></i> Tambah Klub Baru</h2>
                <form method="POST" class="form" id="clubForm">
                    <input type="hidden" name="action" value="add_club">
                    
                    <div class="form-group">
                        <label for="name"><i class="fas fa-users"></i> Nama Klub</label>
                        <input type="text" id="name" name="name" required 
                               placeholder="Contoh: Manchester United" maxlength="100">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="matches_played"><i class="fas fa-calendar-alt"></i> Main</label>
                            <input type="number" id="matches_played" name="matches_played" 
                                   min="0" max="38" value="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="wins"><i class="fas fa-trophy"></i> Menang</label>
                            <input type="number" id="wins" name="wins" 
                                   min="0" max="38" value="0" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="losses"><i class="fas fa-times-circle"></i> Kalah</label>
                            <input type="number" id="losses" name="losses" 
                                   min="0" max="38" value="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="draws"><i class="fas fa-equals"></i> Seri</label>
                            <input type="number" id="draws" name="draws" 
                                   min="0" max="38" value="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="points-display">
                            <span>Poin Otomatis: <strong id="pointsDisplay">0</strong></span>
                            <small>(3 poin untuk menang, 1 poin untuk seri)</small>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Klub
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearForm()">
                        <i class="fas fa-eraser"></i> Bersihkan Form
                    </button>
                </form>
            </div>

            <!-- Tabel Data Klub -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fas fa-table"></i> Data Klub Liga Inggris</h2>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="action" value="calculate_probability">
                        <button type="submit" class="btn btn-success" 
                                <?php echo count($clubs) < 2 ? 'disabled' : ''; ?>>
                            <i class="fas fa-calculator"></i> Hitung Probabilitas
                        </button>
                    </form>
                </div>
                
                <?php if (empty($clubs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list fa-3x"></i>
                        <p>Belum ada data klub. Silakan tambahkan klub terlebih dahulu.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Klub</th>
                                    <th>Main</th>
                                    <th>Menang</th>
                                    <th>Kalah</th>
                                    <th>Seri</th>
                                    <th>Poin</th>
                                    <?php if ($calculated_clubs): ?>
                                        <th>Prob. Menang</th>
                                        <th>Sisa Pertandingan</th>
                                        <th>Perkiraan Poin Akhir</th>
                                        <th>Probabilitas Juara</th>
                                    <?php endif; ?>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $display_clubs = $calculated_clubs ?: $clubs;
                                foreach ($display_clubs as $index => $club): 
                                ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="club-name"><?php echo htmlspecialchars($club['name']); ?></td>
                                        <td><?php echo $club['matches_played']; ?></td>
                                        <td><?php echo $club['wins']; ?></td>
                                        <td><?php echo $club['losses']; ?></td>
                                        <td><?php echo $club['draws']; ?></td>
                                        <td><span class="badge"><?php echo $club['points']; ?></span></td>
                                        
                                        <?php if ($calculated_clubs): ?>
                                            <td><?php echo isset($club['win_probability']) ? round($club['win_probability'] * 100, 1) . '%' : '0%'; ?></td>
                                            <td><?php echo $club['remaining_matches']; ?></td>
                                            <td><?php echo $club['expected_total_points']; ?></td>
                                            <td>
                                                <div class="probability-bar">
                                                    <div class="probability-fill" 
                                                         style="width: <?php echo $club['champion_probability']; ?>%">
                                                        <span><?php echo $club['champion_probability']; ?>%</span>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <td>
                                            <form method="POST" class="delete-form">
                                                <input type="hidden" name="action" value="delete_club">
                                                <input type="hidden" name="id" value="<?php echo $club['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" 
                                                        onclick="return confirm('Hapus klub ini?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if ($calculated_clubs): ?>
                        <div class="summary">
                            <h3><i class="fas fa-chart-pie"></i> Ringkasan Analisis</h3>
                            <p>Berdasarkan distribusi binomial dengan <?php echo count($clubs); ?> klub:</p>
                            <ul>
                                <li>Klub dengan probabilitas tertinggi: <strong><?php echo htmlspecialchars($calculated_clubs[0]['name']); ?></strong> (<?php echo $calculated_clubs[0]['champion_probability']; ?>%)</li>
                                <li>Total pertandingan dalam satu musim: 38 pertandingan</li>
                                <li>Probabilitas menang dihitung berdasarkan rasio kemenangan terhadap total pertandingan yang sudah dimainkan</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <footer class="footer">
            <p>Sistem Analisis Probabilitas Juara Liga Inggris By Dimas Alfi &copy; 2025</p>
            <p>Menggunakan metode Distribusi Binomial untuk prediksi yang akurat</p>
        </footer>
    </div>

    <script>
        // Validasi form dan perhitungan poin otomatis
        document.addEventListener('DOMContentLoaded', function() {
            const clubForm = document.getElementById('clubForm');
            const matchesInput = document.getElementById('matches_played');
            const winsInput = document.getElementById('wins');
            const lossesInput = document.getElementById('losses');
            const drawsInput = document.getElementById('draws');
            const pointsDisplay = document.getElementById('pointsDisplay');
            
            // Hitung poin otomatis
            function calculatePoints() {
                const wins = parseInt(winsInput.value) || 0;
                const draws = parseInt(drawsInput.value) || 0;
                const points = (wins * 3) + (draws * 1);
                pointsDisplay.textContent = points;
            }
            
            // Validasi input
            function validateInputs() {
                const matches = parseInt(matchesInput.value) || 0;
                const wins = parseInt(winsInput.value) || 0;
                const losses = parseInt(lossesInput.value) || 0;
                const draws = parseInt(drawsInput.value) || 0;
                
                // Cek apakah total pertandingan valid
                if (wins + losses + draws > matches) {
                    alert('Total menang + kalah + seri tidak boleh lebih dari total pertandingan!');
                    return false;
                }
                
                // Cek apakah tidak melebihi 38 pertandingan
                if (matches > 38) {
                    alert('Total pertandingan dalam Liga Inggris maksimal 38!');
                    return false;
                }
                
                return true;
            }
            
            // Event listeners
            [winsInput, lossesInput, drawsInput].forEach(input => {
                input.addEventListener('input', calculatePoints);
                input.addEventListener('change', validateInputs);
            });
            
            matchesInput.addEventListener('change', validateInputs);
            
            // Validasi form sebelum submit
            if (clubForm) {
                clubForm.addEventListener('submit', function(e) {
                    if (!validateInputs()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
            
            // Hitung poin awal
            calculatePoints();
        });
        
        // Fungsi untuk membersihkan form
        function clearForm() {
            const form = document.getElementById('clubForm');
            if (form) {
                form.reset();
                document.getElementById('pointsDisplay').textContent = '0';
                document.getElementById('name').focus();
            }
        }
    </script>
</body>
</html>