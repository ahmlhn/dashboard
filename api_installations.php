<?php
// FILE: dashboard/api_installations.php
// VERSI: SIMPLIFY CLAIM FLOW
// - CLAIM sekarang set status langsung 'Proses' (bukan Survey)
// - Sisanya tetap (save/get_list/dll)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', 0);

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatal, true)) return;
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'msg' => 'Server error',
            'error' => $err['message']
        ]);
    }
});

session_start();
require 'config.php';
require_once __DIR__ . '/lib/wa_gateway.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($conn) || !$conn) {
    echo json_encode(['status' => 'error', 'msg' => 'DB connection failed']);
    exit;
}

$TENANT_ID = (int)($_SESSION['tenant_id'] ?? 0);
if ($TENANT_ID <= 0 && (isset($_SESSION['is_logged_in']) || isset($_SESSION['teknisi_logged_in']))) {
    echo json_encode(['status' => 'error', 'msg' => 'Tenant tidak valid']);
    exit;
}
$API_SECRET = "RAHASIA_BALESOTOMATIS_2025";

function get_val($key, $json) {
    if (isset($json[$key])) return $json[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    return '';
}

function parse_money($val) {
    if (is_numeric($val)) return (float)$val;
    $val = str_replace('.', '', $val);
    $val = str_replace(',', '.', $val);
    return (float)$val;
}

function get_coa_id_by_code($conn, $tenant_id, $code) {
    if (!$code) return 0;
    $code = mysqli_real_escape_string($conn, $code);
    $q = mysqli_query($conn, "SELECT id FROM noci_fin_coa WHERE tenant_id = $tenant_id AND code = '$code' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) return (int)$r['id'];
    return 0;
}

function table_exists($conn, $table_name) {
    $safe = mysqli_real_escape_string($conn, $table_name);
    $q = mysqli_query($conn, "SHOW TABLES LIKE '$safe'");
    return $q && mysqli_num_rows($q) > 0;
}

function get_coa_id_by_prefix($conn, $tenant_id, $prefix) {
    if (!$prefix) return 0;
    $prefix_safe = mysqli_real_escape_string($conn, $prefix . '%');
    $q = mysqli_query($conn, "SELECT id FROM noci_fin_coa WHERE tenant_id = $tenant_id AND code LIKE '$prefix_safe' ORDER BY code ASC LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) return (int)$r['id'];
    return 0;
}

function get_coa_id_by_id($conn, $tenant_id, $coa_id) {
    $coa_id = (int)$coa_id;
    if ($coa_id <= 0) return 0;
    $q = mysqli_query($conn, "SELECT id FROM noci_fin_coa WHERE tenant_id = $tenant_id AND id = $coa_id LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) return (int)$r['id'];
    return 0;
}

function get_coa_method_hint($conn, $tenant_id, $coa_id) {
    $coa_id = (int)$coa_id;
    if ($coa_id <= 0) return 'bank';
    $q = mysqli_query($conn, "SELECT code, name FROM noci_fin_coa WHERE tenant_id = $tenant_id AND id = $coa_id LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $code = strtoupper((string)($row['code'] ?? ''));
        $name = strtoupper((string)($row['name'] ?? ''));
        if (strpos($name, 'KAS') !== false || preg_match('/(^|\D)1100(\D|$)/', $code)) return 'kas';
        if (strpos($name, 'BANK') !== false || preg_match('/(^|\D)1110(\D|$)/', $code)) return 'bank';
    }
    return 'bank';
}

function get_install_rev_settings($conn, $tenant_id) {
    if (!table_exists($conn, 'noci_fin_settings')) {
        return ['install_rev_debit_coa' => 0, 'install_rev_credit_coa' => 0];
    }
    $has_debit = mysqli_query($conn, "SHOW COLUMNS FROM noci_fin_settings LIKE 'install_rev_debit_coa'");
    $has_credit = mysqli_query($conn, "SHOW COLUMNS FROM noci_fin_settings LIKE 'install_rev_credit_coa'");
    if (!$has_debit || !$has_credit || mysqli_num_rows($has_debit) === 0 || mysqli_num_rows($has_credit) === 0) {
        return ['install_rev_debit_coa' => 0, 'install_rev_credit_coa' => 0];
    }
    $q = mysqli_query($conn, "SELECT install_rev_debit_coa, install_rev_credit_coa FROM noci_fin_settings WHERE tenant_id = $tenant_id AND id = 1 LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) return $row;
    return ['install_rev_debit_coa' => 0, 'install_rev_credit_coa' => 0];
}

function get_teknisi_expense_settings($conn, $tenant_id) {
    if (!table_exists($conn, 'noci_fin_settings')) {
        return ['teknisi_expense_debit_coa' => 0, 'teknisi_expense_credit_coa' => 0];
    }
    $has_debit = mysqli_query($conn, "SHOW COLUMNS FROM noci_fin_settings LIKE 'teknisi_expense_debit_coa'");
    $has_credit = mysqli_query($conn, "SHOW COLUMNS FROM noci_fin_settings LIKE 'teknisi_expense_credit_coa'");
    if (!$has_debit || !$has_credit || mysqli_num_rows($has_debit) === 0 || mysqli_num_rows($has_credit) === 0) {
        return ['teknisi_expense_debit_coa' => 0, 'teknisi_expense_credit_coa' => 0];
    }
    $q = mysqli_query($conn, "SELECT teknisi_expense_debit_coa, teknisi_expense_credit_coa FROM noci_fin_settings WHERE tenant_id = $tenant_id AND id = 1 LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) return $row;
    return ['teknisi_expense_debit_coa' => 0, 'teknisi_expense_credit_coa' => 0];
}

function get_coa_id_fallback($conn, $tenant_id, $name_keyword, $category_keyword) {
    $name_safe = mysqli_real_escape_string($conn, $name_keyword);
    $cat_safe = mysqli_real_escape_string($conn, $category_keyword);
    
    // Try exact name match first
    $q = mysqli_query($conn, "SELECT id FROM noci_fin_coa WHERE tenant_id = $tenant_id AND (name LIKE '%$name_safe%' OR category LIKE '%$cat_safe%') ORDER BY id ASC LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) return (int)$r['id'];
    return 0;
}

function generate_tx_no_local($conn, $tenant_id, $date_str) {
    $prefix = 'TX-' . date('Ym', strtotime($date_str)) . '-';
    $prefix_safe = mysqli_real_escape_string($conn, $prefix);
    $q = mysqli_query($conn, "SELECT tx_no FROM noci_fin_tx WHERE tenant_id = $tenant_id AND tx_no LIKE '{$prefix_safe}%' ORDER BY LENGTH(tx_no) DESC, tx_no DESC LIMIT 1");
    $next = 1;
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $last = $row['tx_no'];
        $num_part = substr($last, strlen($prefix));
        if (is_numeric($num_part)) $next = (int)$num_part + 1;
    }
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}

function create_install_revenue_tx($conn, $tenant_id, $install_id, $amount, $finished_at, $customer_name, $teknisi_name) {
    $amount_val = parse_money($amount);
    if ($amount_val <= 0) return;

    if (!table_exists($conn, 'noci_fin_tx') || !table_exists($conn, 'noci_fin_tx_lines') || !table_exists($conn, 'noci_fin_coa')) {
        return;
    }
    
    $ref_no = "INST-" . $install_id;
    $q = mysqli_query($conn, "SELECT id FROM noci_fin_tx WHERE tenant_id = $tenant_id AND ref_no = '$ref_no' LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) return;
    
    $settings = get_install_rev_settings($conn, $tenant_id);

    // DEBIT: Settings -> Piutang (primary) -> Kas (secondary) -> Any Asset (tertiary)
    $debit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings['install_rev_debit_coa'] ?? 0);
    if (!$debit_coa_id) $debit_coa_id = get_coa_id_by_code($conn, $tenant_id, '1.1.002'); // Piutang Usaha
    if (!$debit_coa_id) $debit_coa_id = get_coa_id_by_code($conn, $tenant_id, '1.1.1'); // Kas Kecil/Besar
    if (!$debit_coa_id) $debit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Piutang', 'Asset');
    if (!$debit_coa_id) $debit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Kas', 'Asset');
    
    // CREDIT: Settings -> Pendapatan Jasa -> Pendapatan Lain -> Any Revenue
    $credit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings['install_rev_credit_coa'] ?? 0);
    if (!$credit_coa_id) $credit_coa_id = get_coa_id_by_code($conn, $tenant_id, '4.1.001'); // Pendapatan Jasa
    if (!$credit_coa_id) $credit_coa_id = get_coa_id_by_code($conn, $tenant_id, '4.1.1');
    if (!$credit_coa_id) $credit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Pendapatan', 'Revenue');
    if (!$credit_coa_id) $credit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Jasa', 'Income');
    
    if (!$debit_coa_id || !$credit_coa_id) {
        // Log failure to find COA ?
        return; 
    }
    
    $tx_no = generate_tx_no_local($conn, $tenant_id, $finished_at ?: date('Y-m-d'));
    $tx_date = $finished_at ? date('Y-m-d', strtotime($finished_at)) : date('Y-m-d');
    $desc = "Pendapatan Instalasi #$install_id - $customer_name";
    
    $user_id = 0; $user_name = "System Auto";
    if (isset($_SESSION['teknisi_logged_in'])) {
        $user_id = $_SESSION['teknisi_id'] ?? 0;
        $user_name = $_SESSION['teknisi_name'] ?? $teknisi_name;
    } elseif (isset($_SESSION['is_logged_in'])) {
        $user_name = $_SESSION['admin_name'] ?? 'Admin';
        $user_id = $_SESSION['admin_id'] ?? 0;
    }
    
    $method = get_coa_method_hint($conn, $tenant_id, $debit_coa_id);
    $stmt = $conn->prepare("INSERT INTO noci_fin_tx (tenant_id, tx_no, tx_date, description, ref_no, method, status, total_debit, total_credit, created_by, created_by_name, source) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'auto-install')");
    if (!$stmt) return;
    $stmt->bind_param("isssssddis", $tenant_id, $tx_no, $tx_date, $desc, $ref_no, $method, $amount_val, $amount_val, $user_id, $user_name);
    
    if ($stmt->execute()) {
        $tx_id = $stmt->insert_id;
        $stmt->close();
        
        $stmt_l = $conn->prepare("INSERT INTO noci_fin_tx_lines (tenant_id, tx_id, coa_id, line_desc, debit, credit, party_type, party_name, party_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt_l) return;
        $zero = 0;
        $party_type = 'customer';
        $party_name = trim((string)$customer_name);
        $party_ref = '';
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $debit_coa_id, $desc, $amount_val, $zero, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $credit_coa_id, $desc, $zero, $amount_val, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
        $stmt_l->close();
        
        $stmt_a = $conn->prepare("INSERT INTO noci_fin_approvals (tenant_id, tx_id, status, requested_by, note) VALUES (?, ?, 'pending', ?, 'Auto generated from Installation')");
        $stmt_a->bind_param("iii", $tenant_id, $tx_id, $user_id);
        $stmt_a->execute();
        $stmt_a->close();
    }
}

function create_rekap_expense_tx($conn, $tenant_id, $rekap_date, $tech_name, $expenses, $user_id, $user_name) {
    if (!table_exists($conn, 'noci_fin_tx') || !table_exists($conn, 'noci_fin_tx_lines') || !table_exists($conn, 'noci_fin_coa')) {
        return;
    }
    if (!is_array($expenses) || count($expenses) === 0) return;

    $settings = get_teknisi_expense_settings($conn, $tenant_id);
    $debit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings['teknisi_expense_debit_coa'] ?? 0);
    $credit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings['teknisi_expense_credit_coa'] ?? 0);
    if (!$debit_coa_id) $debit_coa_id = get_coa_id_by_prefix($conn, $tenant_id, '5.');
    if (!$credit_coa_id) $credit_coa_id = get_coa_id_by_prefix($conn, $tenant_id, '1.');
    if (!$debit_coa_id || !$credit_coa_id) return;

    $tx_date = $rekap_date ?: date('Y-m-d');
    $tech_key = preg_replace('/[^A-Za-z0-9]+/', '', strtolower((string)$tech_name));
    $method = get_coa_method_hint($conn, $tenant_id, $credit_coa_id);
    $ref_no = 'REKAP-EXP-' . $tx_date . '-' . $tech_key;

    $total = 0;
    foreach ($expenses as $item) {
        $amount = (int)($item['amount'] ?? 0);
        if ($amount > 0) $total += $amount;
    }
    if ($total <= 0) return;

    $existing = null;
    $stmt_chk = $conn->prepare("SELECT id, status FROM noci_fin_tx WHERE tenant_id = ? AND ref_no = ? LIMIT 1");
    if ($stmt_chk) {
        $stmt_chk->bind_param('is', $tenant_id, $ref_no);
        $stmt_chk->execute();
        $res = $stmt_chk->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt_chk->close();
    }

    if ($existing) {
        $status = strtolower((string)($existing['status'] ?? ''));
        if (!in_array($status, ['pending', 'draft'], true)) return;
        $tx_id = (int)$existing['id'];
        $desc = "Pengeluaran teknisi {$tech_name} ({$tx_date})";
        $stmt_up = $conn->prepare("UPDATE noci_fin_tx SET description=?, tx_date=?, method=?, total_debit=?, total_credit=? WHERE tenant_id = ? AND id = ?");
        if ($stmt_up) {
            $stmt_up->bind_param('sssddii', $desc, $tx_date, $method, $total, $total, $tenant_id, $tx_id);
            $stmt_up->execute();
            $stmt_up->close();
        }
        $conn->query("DELETE FROM noci_fin_tx_lines WHERE tenant_id = {$tenant_id} AND tx_id = {$tx_id}");
    } else {
        $tx_no = generate_tx_no_local($conn, $tenant_id, $tx_date);
        $desc = "Pengeluaran teknisi {$tech_name} ({$tx_date})";
        $stmt = $conn->prepare("INSERT INTO noci_fin_tx (tenant_id, tx_no, tx_date, description, ref_no, method, status, total_debit, total_credit, created_by, created_by_name, source) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'rekap-expense')");
        if (!$stmt) return;
        $stmt->bind_param('isssssddis', $tenant_id, $tx_no, $tx_date, $desc, $ref_no, $method, $total, $total, $user_id, $user_name);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }
        $tx_id = (int)$stmt->insert_id;
        $stmt->close();

        if (table_exists($conn, 'noci_fin_approvals')) {
            $stmt_a = $conn->prepare("INSERT INTO noci_fin_approvals (tenant_id, tx_id, status, requested_by, note) VALUES (?, ?, 'pending', ?, 'Auto generated from Rekap Teknisi')");
            if ($stmt_a) {
                $stmt_a->bind_param('iii', $tenant_id, $tx_id, $user_id);
                $stmt_a->execute();
                $stmt_a->close();
            }
        }
    }

    $stmt_l = $conn->prepare("INSERT INTO noci_fin_tx_lines (tenant_id, tx_id, coa_id, line_desc, debit, credit, party_type, party_name, party_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt_l) return;
    $zero = 0;
    $party_type = 'teknisi';
    $party_name = trim((string)$tech_name);
    $party_ref = '';

    foreach ($expenses as $item) {
        $amount = (int)($item['amount'] ?? 0);
        if ($amount <= 0) continue;
        $item_name = trim((string)($item['name'] ?? 'Pengeluaran'));
        $line_desc = "Pengeluaran teknisi {$tech_name}: {$item_name} ({$tx_date})";
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $debit_coa_id, $line_desc, $amount, $zero, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
    }

    $line_desc = "Pengeluaran teknisi {$tech_name} ({$tx_date})";
    $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $credit_coa_id, $line_desc, $zero, $total, $party_type, $party_name, $party_ref);
    $stmt_l->execute();
    $stmt_l->close();
}

function create_rekap_daily_finance_tx($conn, $tenant_id, $rekap_date, $tech_name, $user_id, $user_name) {
    require_once __DIR__ . '/lib/rekap_finance_helper.php';
    if (!table_exists($conn, 'noci_fin_tx') || !table_exists($conn, 'noci_fin_tx_lines') || !table_exists($conn, 'noci_fin_coa')) {
        return ['ok' => false, 'error' => 'Keuangan belum siap'];
    }
    if ($rekap_date === '') $rekap_date = date('Y-m-d');
    $rekap_date = mysqli_real_escape_string($conn, $rekap_date);
    $tech_name = trim((string)$tech_name);
    if ($tech_name === '') return ['ok' => false, 'error' => 'Teknisi tidak valid'];

    // Load expenses
    ensure_rekap_expenses_table($conn);
    $safe_tech = mysqli_real_escape_string($conn, $tech_name);
    $expenses = [];
    $q_exp = mysqli_query($conn, "SELECT expenses_json FROM noci_rekap_expenses WHERE tenant_id = {$tenant_id} AND rekap_date = '{$rekap_date}' AND technician_name = '{$safe_tech}' LIMIT 1");
    if ($q_exp && ($row = mysqli_fetch_assoc($q_exp))) {
        $expenses = json_decode($row['expenses_json'] ?? '[]', true);
        if (!is_array($expenses)) $expenses = [];
    }

    // Load revenue (PSB installs)
    $revenue_total = 0;
    $stmt_inst = $conn->prepare("SELECT id, price, customer_name FROM noci_installations WHERE tenant_id = ? AND status='Selesai' AND finished_at IS NOT NULL AND DATE(finished_at)=? AND (technician = ? OR technician_2 = ? OR technician_3 = ? OR technician_4 = ?)");
    if ($stmt_inst) {
        $stmt_inst->bind_param('isssss', $tenant_id, $rekap_date, $tech_name, $tech_name, $tech_name, $tech_name);
        $stmt_inst->execute();
        $res = $stmt_inst->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $revenue_total += (int)parse_money($row['price'] ?? 0);
        }
        $stmt_inst->close();
    }

    $expense_total = 0;
    foreach ((array)$expenses as $item) {
        $amount = (int)($item['amount'] ?? 0);
        if ($amount > 0) $expense_total += $amount;
    }

    // Save installation fees (split by tech/sales count)
    create_rekap_installation_fees_by_name($conn, $tenant_id, $rekap_date, $tech_name);

    if ($revenue_total <= 0 && $expense_total <= 0) {
        return ['ok' => false, 'error' => 'Tidak ada data pendapatan/pengeluaran'];
    }

    $settings_rev = get_install_rev_settings($conn, $tenant_id);
    $rev_debit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings_rev['install_rev_debit_coa'] ?? 0);
    if (!$rev_debit_coa_id) $rev_debit_coa_id = get_coa_id_by_code($conn, $tenant_id, '1.1.002');
    if (!$rev_debit_coa_id) $rev_debit_coa_id = get_coa_id_by_code($conn, $tenant_id, '1.1.1');
    if (!$rev_debit_coa_id) $rev_debit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Piutang', 'Asset');
    if (!$rev_debit_coa_id) $rev_debit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Kas', 'Asset');

    $rev_credit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings_rev['install_rev_credit_coa'] ?? 0);
    if (!$rev_credit_coa_id) $rev_credit_coa_id = get_coa_id_by_code($conn, $tenant_id, '4.1.001');
    if (!$rev_credit_coa_id) $rev_credit_coa_id = get_coa_id_by_code($conn, $tenant_id, '4.1.1');
    if (!$rev_credit_coa_id) $rev_credit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Pendapatan', 'Revenue');
    if (!$rev_credit_coa_id) $rev_credit_coa_id = get_coa_id_fallback($conn, $tenant_id, 'Jasa', 'Income');

    $settings_exp = get_teknisi_expense_settings($conn, $tenant_id);
    $exp_debit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings_exp['teknisi_expense_debit_coa'] ?? 0);
    $exp_credit_coa_id = get_coa_id_by_id($conn, $tenant_id, $settings_exp['teknisi_expense_credit_coa'] ?? 0);
    if (!$exp_debit_coa_id) $exp_debit_coa_id = get_coa_id_by_prefix($conn, $tenant_id, '5.');
    if (!$exp_credit_coa_id) $exp_credit_coa_id = get_coa_id_by_prefix($conn, $tenant_id, '1.');

    if (($revenue_total > 0 && (!$rev_debit_coa_id || !$rev_credit_coa_id)) || ($expense_total > 0 && (!$exp_debit_coa_id || !$exp_credit_coa_id))) {
        return ['ok' => false, 'error' => 'COA belum lengkap'];
    }

    $tech_key = preg_replace('/[^A-Za-z0-9]+/', '', strtolower((string)$tech_name));
    $ref_no = 'REKAP-DAILY-' . $rekap_date . '-' . $tech_key;
    $method = $expense_total > 0 ? get_coa_method_hint($conn, $tenant_id, $exp_credit_coa_id) : get_coa_method_hint($conn, $tenant_id, $rev_debit_coa_id);
    $total = $revenue_total + $expense_total;
    $desc = "Rekap teknisi {$tech_name} ({$rekap_date})";

    $existing = null;
    $stmt_chk = $conn->prepare("SELECT id, status FROM noci_fin_tx WHERE tenant_id = ? AND ref_no = ? LIMIT 1");
    if ($stmt_chk) {
        $stmt_chk->bind_param('is', $tenant_id, $ref_no);
        $stmt_chk->execute();
        $res = $stmt_chk->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt_chk->close();
    }

    if ($existing) {
        $status = strtolower((string)($existing['status'] ?? ''));
        if (!in_array($status, ['pending', 'draft'], true)) {
            return ['ok' => false, 'error' => 'Transaksi sudah diposting'];
        }
        $tx_id = (int)$existing['id'];
        $stmt_up = $conn->prepare("UPDATE noci_fin_tx SET description=?, tx_date=?, method=?, total_debit=?, total_credit=? WHERE tenant_id = ? AND id = ?");
        if ($stmt_up) {
            $stmt_up->bind_param('sssddii', $desc, $rekap_date, $method, $total, $total, $tenant_id, $tx_id);
            $stmt_up->execute();
            $stmt_up->close();
        }
        $conn->query("DELETE FROM noci_fin_tx_lines WHERE tenant_id = {$tenant_id} AND tx_id = {$tx_id}");
    } else {
        $tx_no = generate_tx_no_local($conn, $tenant_id, $rekap_date);
        $stmt = $conn->prepare("INSERT INTO noci_fin_tx (tenant_id, tx_no, tx_date, description, ref_no, method, status, total_debit, total_credit, created_by, created_by_name, source) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, 'rekap-daily')");
        if (!$stmt) return ['ok' => false, 'error' => 'DB error'];
        $stmt->bind_param('isssssddis', $tenant_id, $tx_no, $rekap_date, $desc, $ref_no, $method, $total, $total, $user_id, $user_name);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'error' => 'Gagal membuat transaksi'];
        }
        $tx_id = (int)$stmt->insert_id;
        $stmt->close();

        if (table_exists($conn, 'noci_fin_approvals')) {
            $stmt_a = $conn->prepare("INSERT INTO noci_fin_approvals (tenant_id, tx_id, status, requested_by, note) VALUES (?, ?, 'pending', ?, 'Auto generated from Rekap Teknisi')");
            if ($stmt_a) {
                $stmt_a->bind_param('iii', $tenant_id, $tx_id, $user_id);
                $stmt_a->execute();
                $stmt_a->close();
            }
        }
    }

    $stmt_l = $conn->prepare("INSERT INTO noci_fin_tx_lines (tenant_id, tx_id, coa_id, line_desc, debit, credit, party_type, party_name, party_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt_l) return ['ok' => false, 'error' => 'DB error'];
    $zero = 0;
    $party_type = 'teknisi';
    $party_name = trim((string)$tech_name);
    $party_ref = '';

    if ($revenue_total > 0) {
        $rev_desc = "Pendapatan PSB teknisi {$tech_name} ({$rekap_date})";
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $rev_debit_coa_id, $rev_desc, $revenue_total, $zero, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $rev_credit_coa_id, $rev_desc, $zero, $revenue_total, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
    }

    if ($expense_total > 0) {
        foreach ((array)$expenses as $item) {
            $amount = (int)($item['amount'] ?? 0);
            if ($amount <= 0) continue;
            $item_name = trim((string)($item['name'] ?? 'Pengeluaran'));
            $line_desc = "Pengeluaran teknisi {$tech_name}: {$item_name} ({$rekap_date})";
            $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $exp_debit_coa_id, $line_desc, $amount, $zero, $party_type, $party_name, $party_ref);
            $stmt_l->execute();
        }
        $exp_desc = "Pengeluaran teknisi {$tech_name} ({$rekap_date})";
        $stmt_l->bind_param("iiisddsss", $tenant_id, $tx_id, $exp_credit_coa_id, $exp_desc, $zero, $expense_total, $party_type, $party_name, $party_ref);
        $stmt_l->execute();
    }

    $stmt_l->close();

    // Attach latest transfer proof to finance tx (if any)
    if (table_exists($conn, 'noci_fin_attachments') && table_exists($conn, 'noci_rekap_attachments')) {
        $safe_tech = mysqli_real_escape_string($conn, $tech_name);
        $q_att = mysqli_query($conn, "SELECT file_name, file_path, file_ext, mime_type, file_size FROM noci_rekap_attachments WHERE tenant_id = {$tenant_id} AND rekap_date = '{$rekap_date}' AND technician_name = '{$safe_tech}' ORDER BY id DESC LIMIT 1");
        if ($q_att && ($att = mysqli_fetch_assoc($q_att))) {
            $file_path = $att['file_path'] ?? '';
            $file_name = $att['file_name'] ?? '';
            $file_ext = $att['file_ext'] ?? '';
            $mime = $att['mime_type'] ?? '';
            $size = (int)($att['file_size'] ?? 0);
            if ($file_path !== '') {
                $safe_path = mysqli_real_escape_string($conn, $file_path);
                $q_exist = mysqli_query($conn, "SELECT id FROM noci_fin_attachments WHERE tenant_id = {$tenant_id} AND tx_id = {$tx_id} AND file_path = '{$safe_path}' LIMIT 1");
                if (!$q_exist || mysqli_num_rows($q_exist) === 0) {
                    $stmt_att = $conn->prepare("INSERT INTO noci_fin_attachments (tenant_id, tx_id, file_name, file_path, file_ext, mime_type, file_size, created_by) VALUES (?,?,?,?,?,?,?,?)");
                    if ($stmt_att) {
                        $stmt_att->bind_param('iissssii', $tenant_id, $tx_id, $file_name, $file_path, $file_ext, $mime, $size, $user_id);
                        $stmt_att->execute();
                        $stmt_att->close();
                    }
                }
            }
        }
    }

    return ['ok' => true, 'tx_id' => $tx_id];
}

function create_rekap_installation_fees_by_name($conn, $tenant_id, $rekap_date, $tech_name) {
    require_once __DIR__ . '/lib/rekap_finance_helper.php';
    $rekap_date = $rekap_date ?: date('Y-m-d');
    $date_str = mysqli_real_escape_string($conn, $rekap_date);
    $safe_name = mysqli_real_escape_string($conn, $tech_name);

    $fee_per_tech = 0;
    $fee_per_sales = 0;
    $q = mysqli_query($conn, "SELECT teknisi_fee_install, sales_fee_install FROM noci_fin_settings WHERE tenant_id = {$tenant_id} LIMIT 1");
    if ($q && ($row = mysqli_fetch_assoc($q))) {
        $fee_per_tech = (float)($row['teknisi_fee_install'] ?? 0);
        $fee_per_sales = (float)($row['sales_fee_install'] ?? 0);
    }

    if ($fee_per_tech <= 0 && $fee_per_sales <= 0) return;

    $stmt = $conn->prepare("SELECT id, technician, technician_2, technician_3, technician_4, sales_name, sales_name_2, sales_name_3 FROM noci_installations WHERE tenant_id = ? AND status='Selesai' AND finished_at IS NOT NULL AND DATE(finished_at)=? AND (technician = ? OR technician_2 = ? OR technician_3 = ? OR technician_4 = ?)");
    if (!$stmt) return;
    $stmt->bind_param('isssss', $tenant_id, $date_str, $tech_name, $tech_name, $tech_name, $tech_name);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $install_id = (int)($row['id'] ?? 0);
        if ($install_id <= 0) continue;

        $tech_names = [
            trim((string)($row['technician'] ?? '')),
            trim((string)($row['technician_2'] ?? '')),
            trim((string)($row['technician_3'] ?? '')),
            trim((string)($row['technician_4'] ?? ''))
        ];
        $tech_names = array_values(array_filter($tech_names, function ($n) { return $n !== '' && $n !== '-' && $n !== 'null'; }));
        $tech_map = [];
        foreach ($tech_names as $n) {
            $key = strtolower($n);
            if (!isset($tech_map[$key])) $tech_map[$key] = $n;
        }
        $tech_names = array_values($tech_map);
        $tech_count = count($tech_names);

        if ($fee_per_tech > 0 && $tech_count > 0) {
            $fee_each = $fee_per_tech / $tech_count;
            foreach ($tech_names as $name) {
                $user_id = 0;
                $safe = mysqli_real_escape_string($conn, $name);
                $q_user = mysqli_query($conn, "SELECT id FROM noci_users WHERE tenant_id = {$tenant_id} AND name = '{$safe}' LIMIT 1");
                if ($q_user && ($u = mysqli_fetch_assoc($q_user))) $user_id = (int)($u['id'] ?? 0);
                if ($user_id > 0) {
                    saveTechniziInstallationFee($conn, $tenant_id, $install_id, $user_id, 'teknisi', $fee_each, $fee_per_tech, $tech_count);
                }
            }
        }

        $sales_names = [
            trim((string)($row['sales_name'] ?? '')),
            trim((string)($row['sales_name_2'] ?? '')),
            trim((string)($row['sales_name_3'] ?? ''))
        ];
        $sales_names = array_values(array_filter($sales_names, function ($n) { return $n !== '' && $n !== '-' && $n !== 'null'; }));
        $sales_map = [];
        foreach ($sales_names as $n) {
            $key = strtolower($n);
            if (!isset($sales_map[$key])) $sales_map[$key] = $n;
        }
        $sales_names = array_values($sales_map);
        $sales_count = count($sales_names);

        if ($fee_per_sales > 0 && $sales_count > 0) {
            $fee_each = $fee_per_sales / $sales_count;
            foreach ($sales_names as $name) {
                $user_id = 0;
                $safe = mysqli_real_escape_string($conn, $name);
                $q_user = mysqli_query($conn, "SELECT id FROM noci_users WHERE tenant_id = {$tenant_id} AND name = '{$safe}' LIMIT 1");
                if ($q_user && ($u = mysqli_fetch_assoc($q_user))) $user_id = (int)($u['id'] ?? 0);
                if ($user_id > 0) {
                    saveTechniziInstallationFee($conn, $tenant_id, $install_id, $user_id, 'sales', $fee_each, $fee_per_sales, $sales_count);
                }
            }
        }
    }
    $stmt->close();
}

function delete_rekap_expense_tx($conn, $tenant_id, $rekap_date, $tech_name) {
    if (!table_exists($conn, 'noci_fin_tx')) return;
    $ref_no = 'REKAP-EXP-' . $rekap_date . '-' . preg_replace('/[^A-Za-z0-9]+/', '', strtolower((string)$tech_name));
    $ref_safe = mysqli_real_escape_string($conn, $ref_no);
    $q = mysqli_query($conn, "SELECT id, status FROM noci_fin_tx WHERE tenant_id = {$tenant_id} AND ref_no = '{$ref_safe}'");
    if (!$q) return;
    while ($row = mysqli_fetch_assoc($q)) {
        $status = strtolower((string)($row['status'] ?? ''));
        if (!in_array($status, ['pending', 'draft'], true)) continue;
        $tx_id = (int)$row['id'];
        if (table_exists($conn, 'noci_fin_tx_lines')) {
            $conn->query("DELETE FROM noci_fin_tx_lines WHERE tenant_id = {$tenant_id} AND tx_id = {$tx_id}");
        }
        if (table_exists($conn, 'noci_fin_approvals')) {
            $conn->query("DELETE FROM noci_fin_approvals WHERE tenant_id = {$tenant_id} AND tx_id = {$tx_id}");
        }
        $stmt_del = $conn->prepare("DELETE FROM noci_fin_tx WHERE tenant_id = ? AND id = ?");
        if ($stmt_del) {
            $stmt_del->bind_param('ii', $tenant_id, $tx_id);
            $stmt_del->execute();
            $stmt_del->close();
        }
    }
}

function normalize_datetime_local($s) {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/', $s, $m)) return $m[1] . ' ' . $m[2] . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;
    return '';
}

function is_blank_sales($val) {
    $v = strtolower(trim((string)$val));
    return ($v === '' || $v === '-' || $v === 'null');
}

function get_current_actor_role() {
    if (isset($_SESSION['teknisi_role'])) return strtolower($_SESSION['teknisi_role']);
    if (isset($_SESSION['level'])) return strtolower($_SESSION['level']);
    return 'system';
}

function is_teknisi_session() {
    return isset($_SESSION['teknisi_logged_in']) && $_SESSION['teknisi_logged_in'] === true;
}

function tanggal_indo($timestamp = null) {
    $hari_array = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    $bulan_array = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $timestamp = $timestamp ? $timestamp : time();
    return $hari_array[date('l', $timestamp)] . ", " . date('j', $timestamp) . " " . $bulan_array[(int)date('n', $timestamp)] . " " . date('Y', $timestamp);
}

function get_base_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    return "$protocol://$host$dir";
}

function log_activity($conn, $actor, $action_type, $target_id, $details) {
    global $TENANT_ID;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $platform = 'Dashboard Teknisi';
    $full_action = "[$action_type] ID:$target_id - $details";
    $actor_safe = substr($actor, 0, 50);
    $action_safe = substr($full_action, 0, 255);

    $stmt = $conn->prepare("INSERT INTO noci_logs (tenant_id, ip_address, platform, visit_id, event_action) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issss", $TENANT_ID, $ip, $platform, $actor_safe, $action_safe);
        $stmt->execute();
        $stmt->close();
    }
}

function get_current_actor() {
    if (isset($_SESSION['teknisi_name'])) return $_SESSION['teknisi_name'];
    if (isset($_SESSION['admin_name'])) return $_SESSION['admin_name'];
    if (isset($_SESSION['user_name'])) return $_SESSION['user_name'];
    return 'System/API';
}

function normalize_name($name) {
    return strtolower(trim((string)$name));
}

function is_assigned_task($row, $tech_name) {
    $current = normalize_name($tech_name);
    if ($current === '') return false;
    $fields = ['technician', 'technician_2', 'technician_3', 'technician_4'];
    foreach ($fields as $field) {
        if (normalize_name($row[$field] ?? '') === $current) return true;
    }
    return false;
}

function bind_dynamic($stmt, string $types, array $params) {
    if ($types === '') return;
    $refs = [];
    $refs[] = & $types;
    for ($i = 0; $i < count($params); $i++) $refs[] = & $params[$i];
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function ensure_rekap_expenses_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS noci_rekap_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        rekap_date DATE NOT NULL,
        technician_name VARCHAR(100) NOT NULL,
        expenses_json MEDIUMTEXT NOT NULL,
        team_json MEDIUMTEXT DEFAULT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        updated_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rekap (tenant_id, rekap_date, technician_name),
        KEY idx_tenant (tenant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    mysqli_query($conn, $sql);
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM noci_rekap_expenses LIKE 'tenant_id'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE noci_rekap_expenses ADD COLUMN tenant_id INT NOT NULL DEFAULT 0 AFTER id");
    }
}

function ensure_rekap_attachments_table($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS noci_rekap_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        rekap_date DATE DEFAULT NULL,
        technician_name VARCHAR(100) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        file_ext VARCHAR(10) DEFAULT NULL,
        mime_type VARCHAR(100) DEFAULT NULL,
        file_size INT DEFAULT NULL,
        created_by VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tenant (tenant_id),
        KEY idx_rekap (tenant_id, rekap_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @mysqli_query($conn, $sql);
    $res_tenant = mysqli_query($conn, "SHOW COLUMNS FROM noci_rekap_attachments LIKE 'tenant_id'");
    if ($res_tenant && mysqli_num_rows($res_tenant) === 0) {
        @mysqli_query($conn, "ALTER TABLE noci_rekap_attachments ADD COLUMN tenant_id INT NOT NULL DEFAULT 0 AFTER id");
    }
}

function ensure_user_location_tables($conn) {
    $sql_latest = "CREATE TABLE IF NOT EXISTS noci_user_location_latest (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        user_role VARCHAR(30) DEFAULT NULL,
        latitude DECIMAL(10,6) NOT NULL,
        longitude DECIMAL(10,6) NOT NULL,
        accuracy INT DEFAULT NULL,
        speed FLOAT DEFAULT NULL,
        heading FLOAT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user (tenant_id, user_id),
        KEY idx_tenant (tenant_id),
        KEY idx_recorded_at (recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql_logs = "CREATE TABLE IF NOT EXISTS noci_user_location_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(100) NOT NULL,
        user_role VARCHAR(30) DEFAULT NULL,
        event_name VARCHAR(80) DEFAULT NULL,
        latitude DECIMAL(10,6) DEFAULT NULL,
        longitude DECIMAL(10,6) DEFAULT NULL,
        accuracy INT DEFAULT NULL,
        speed FLOAT DEFAULT NULL,
        heading FLOAT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_time (tenant_id, user_id, recorded_at),
        KEY idx_tenant (tenant_id),
        KEY idx_recorded_at (recorded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    mysqli_query($conn, $sql_latest);
    mysqli_query($conn, $sql_logs);

    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM noci_user_location_logs LIKE 'event_name'");
    if ($col_check && mysqli_num_rows($col_check) === 0) {
        mysqli_query($conn, "ALTER TABLE noci_user_location_logs ADD COLUMN event_name VARCHAR(80) DEFAULT NULL AFTER user_role");
    }
    $col_check_tenant = mysqli_query($conn, "SHOW COLUMNS FROM noci_user_location_logs LIKE 'tenant_id'");
    if ($col_check_tenant && mysqli_num_rows($col_check_tenant) === 0) {
        mysqli_query($conn, "ALTER TABLE noci_user_location_logs ADD COLUMN tenant_id INT NOT NULL DEFAULT 0 AFTER id");
    }
    $col_check_latest = mysqli_query($conn, "SHOW COLUMNS FROM noci_user_location_latest LIKE 'tenant_id'");
    if ($col_check_latest && mysqli_num_rows($col_check_latest) === 0) {
        mysqli_query($conn, "ALTER TABLE noci_user_location_latest ADD COLUMN tenant_id INT NOT NULL DEFAULT 0 AFTER id");
    }
}

function normalize_event_name($name) {
    $text = trim((string)$name);
    if ($text === '') return '';
    $text = preg_replace('/\s+/', ' ', $text);
    if (strlen($text) > 80) $text = substr($text, 0, 80);
    return $text;
}

function log_user_event($conn, $event, $target_id = null) {
    global $TENANT_ID;
    $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['teknisi_id'] ?? 0);
    $user_name = get_current_actor();
    $user_role = get_current_actor_role();
    if ($user_id <= 0) return;

    $event = normalize_event_name($event);
    if ($event === '') return;

    $prefix = $user_role !== '' ? $user_role . ':' : '';
    $full_event = $prefix . $event;
    $target_int = (int)$target_id;
    if ($target_int > 0) $full_event .= '#' . $target_int;
    $full_event = normalize_event_name($full_event);
    if ($full_event === '') return;

    $recorded_at = date('Y-m-d H:i:s');
    ensure_user_location_tables($conn);

    $stmt = $conn->prepare("INSERT INTO noci_user_location_logs (tenant_id, user_id, user_name, user_role, event_name, latitude, longitude, accuracy, speed, heading, recorded_at)
        VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?)");
    if ($stmt) {
        $stmt->bind_param("iissss", $TENANT_ID, $user_id, $user_name, $user_role, $full_event, $recorded_at);
        $stmt->execute();
        $stmt->close();
    }

    $stmt_clean = $conn->prepare("DELETE FROM noci_user_location_logs WHERE tenant_id = ? AND user_id = ? AND recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($stmt_clean) {
        $stmt_clean->bind_param("ii", $TENANT_ID, $user_id);
        $stmt_clean->execute();
        $stmt_clean->close();
    }
}

function get_tech_name_map($conn) {
    global $TENANT_ID;
    $map = [];
    $q = mysqli_query($conn, "SELECT name FROM noci_users WHERE tenant_id = $TENANT_ID AND role IN ('teknisi', 'svp lapangan')");
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $name = $row['name'] ?? '';
        $norm = normalize_name($name);
        if ($norm !== '') $map[$norm] = $name;
    }
    return $map;
}

function collect_rekap_team_names($conn, $date, $tech) {
    global $TENANT_ID;
    $team = [];
    $seen = [];
    $tech_map = get_tech_name_map($conn);

    $stmt = $conn->prepare("SELECT technician, technician_2, technician_3, technician_4, sales_name, sales_name_2, sales_name_3
        FROM noci_installations
        WHERE tenant_id = $TENANT_ID AND status='Selesai' AND finished_at IS NOT NULL AND DATE(finished_at)=?
          AND (technician = ? OR technician_2 = ? OR technician_3 = ? OR technician_4 = ?)");
    if ($stmt) {
        $stmt->bind_param('sssss', $date, $tech, $tech, $tech, $tech);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $tech_fields = ['technician', 'technician_2', 'technician_3', 'technician_4'];
            foreach ($tech_fields as $field) {
                $name = $row[$field] ?? '';
                $norm = normalize_name($name);
                if ($norm === '' || isset($seen[$norm])) continue;
                $seen[$norm] = true;
                $team[] = $name;
            }
            $sales_fields = ['sales_name', 'sales_name_2', 'sales_name_3'];
            foreach ($sales_fields as $field) {
                $sales = $row[$field] ?? '';
                $norm_sales = normalize_name($sales);
                if ($norm_sales === '' || !isset($tech_map[$norm_sales])) continue;
                $canonical = $tech_map[$norm_sales];
                $norm = normalize_name($canonical);
                if ($norm === '' || isset($seen[$norm])) continue;
                $seen[$norm] = true;
                $team[] = $canonical;
            }
        }
        $stmt->close();
    }

    $norm_current = normalize_name($tech);
    if ($norm_current !== '' && !isset($seen[$norm_current])) {
        $team[] = $tech;
    }

    return $team;
}

function log_wa_result($conn, $platform, $target, $message, $status, $response) {
    global $TENANT_ID;
    $stmt_log = $conn->prepare("INSERT INTO noci_notif_logs (tenant_id, platform, target, message, status, response_log) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt_log) {
        $stmt_log->bind_param("isssss", $TENANT_ID, $platform, $target, $message, $status, $response);
        $stmt_log->execute();
        $stmt_log->close();
    }
}

function send_wa_notification($conn, $target_name_or_phone, $message, $log_platform="WhatsApp") {
    global $TENANT_ID;
    $target_name_or_phone = trim((string)$target_name_or_phone);
    $raw = $target_name_or_phone;
    if ($raw === '') {
        return wa_gateway_send_personal($conn, $TENANT_ID, $raw, $message, ['log_platform' => $log_platform]);
    }

    $safe_target = mysqli_real_escape_string($conn, $target_name_or_phone);
    $q_user = mysqli_query($conn, "SELECT phone FROM noci_users WHERE tenant_id = $TENANT_ID AND name = '$safe_target' LIMIT 1");
    if ($q_user && mysqli_num_rows($q_user) > 0) {
        $r_user = mysqli_fetch_assoc($q_user);
        $raw = $r_user['phone'];
    }

    return wa_gateway_send_personal($conn, $TENANT_ID, $raw, $message, ['log_platform' => $log_platform]);
}

function send_wa_group_notification($conn, $group_id, $message, $log_info="WA Group") {
    global $TENANT_ID;
    $resp = wa_gateway_send_group($conn, $TENANT_ID, $group_id, $message, ['log_platform' => $log_info]);
    return ($resp['status'] ?? '') === 'sent';
}

$raw_input_debug = file_get_contents('php://input');
$json_input      = json_decode($raw_input_debug, true) ?? [];
$action = get_val('action', $json_input);

// AUTH CHECK
$incoming_key = trim((string)get_val('api_key', $json_input));
if ($incoming_key === '') $incoming_key = trim((string)get_val('apiKey', $json_input));
if ($incoming_key === '') $incoming_key = trim((string)get_val('apikey', $json_input));
if ($incoming_key === '' && isset($_SERVER['HTTP_X_API_KEY'])) $incoming_key = trim($_SERVER['HTTP_X_API_KEY']);
if ($incoming_key === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
    if (stripos($auth, 'bearer ') === 0) $incoming_key = trim(substr($auth, 7));
    elseif (stripos($auth, 'token ') === 0) $incoming_key = trim(substr($auth, 6));
    else $incoming_key = $auth;
}
if ($incoming_key === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (stripos($auth, 'bearer ') === 0) $incoming_key = trim(substr($auth, 7));
    elseif (stripos($auth, 'token ') === 0) $incoming_key = trim(substr($auth, 6));
    else $incoming_key = $auth;
}
$is_browser_login = isset($_SESSION['is_logged_in']) || isset($_SESSION['teknisi_logged_in']);
$is_api_valid     = ($incoming_key === $API_SECRET);

if (!$is_browser_login && !$is_api_valid) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'msg' => 'Akses Ditolak.']);
    exit;
}

// Allow API to set tenant_id explicitly (required when no session)
if ($is_api_valid) {
    $tenant_req = get_val('tenant_id', $json_input);
    if ($tenant_req === '') $tenant_req = get_val('tenantId', $json_input);
    $tenant_id_req = (int)$tenant_req;
    if ($tenant_id_req > 0) {
        $stmt_tenant = $conn->prepare("SELECT id FROM tenants WHERE id = ? LIMIT 1");
        if ($stmt_tenant) {
            $stmt_tenant->bind_param("i", $tenant_id_req);
            $stmt_tenant->execute();
            $stmt_tenant->store_result();
            if ($stmt_tenant->num_rows > 0) {
                $TENANT_ID = $tenant_id_req;
            } else {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'msg' => 'Tenant tidak ditemukan']);
                $stmt_tenant->close();
                exit;
            }
            $stmt_tenant->close();
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'msg' => 'DB Error']);
            exit;
        }
    } elseif (!$is_browser_login) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'tenant_id wajib untuk API']);
        exit;
    }
}

// 1. CLAIM (UPDATED => status langsung Proses)
if ($action == 'claim') {
    $id = (int)get_val('id', $json_input);
    $tech_name = get_val('technician', $json_input);
    $plan_date = get_val('installation_date', $json_input);

    if (empty($plan_date)) $plan_date = date('Y-m-d');

    if(empty($id) || empty($tech_name)) { echo json_encode(['status'=>'error', 'msg'=>'ID/Teknisi kosong']); exit; }

    $stmt_cek = $conn->prepare("SELECT technician, technician_2, technician_3, technician_4 FROM noci_installations WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt_cek) {
        $stmt_cek->bind_param("i", $id);
        $stmt_cek->execute();
        $row = $stmt_cek->get_result()->fetch_assoc();
        $stmt_cek->close();
        if(!empty($row['technician']) && $row['technician'] !== $tech_name) {
            echo json_encode(['status'=>'error', 'msg'=>'Sudah diambil oleh ' . $row['technician']]); exit;
        }
    }

    $tech2_in = trim((string)get_val('technician_2', $json_input));
    $tech3_in = trim((string)get_val('technician_3', $json_input));
    $tech4_in = trim((string)get_val('technician_4', $json_input));
    $tech2 = $tech2_in !== '' ? $tech2_in : ($row['technician_2'] ?? '');
    $tech3 = $tech3_in !== '' ? $tech3_in : ($row['technician_3'] ?? '');
    $tech4 = $tech4_in !== '' ? $tech4_in : ($row['technician_4'] ?? '');

    // ✅ status langsung PROSES
    $stmt = $conn->prepare("UPDATE noci_installations SET technician = ?, technician_2 = ?, technician_3 = ?, technician_4 = ?, status = 'Proses', installation_date = ? WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt) {
        $stmt->bind_param("sssssi", $tech_name, $tech2, $tech3, $tech4, $plan_date, $id);
        if($stmt->execute()) {
            if (!is_teknisi_session()) {
                log_user_event($conn, 'claim', $id);
            }
            echo json_encode(['status'=>'success']);
        } else echo json_encode(['status'=>'error', 'msg'=>$stmt->error]);
        $stmt->close();
    } else echo json_encode(['status'=>'error', 'msg'=>'DB Error']);

// 2. TRANSFER
} elseif ($action == 'transfer') {
    $id = (int)get_val('id', $json_input);
    $to_tech = get_val('to_tech', $json_input);
    $reason = get_val('reason', $json_input);

    if (empty($id) || empty($to_tech)) { echo json_encode(['status' => 'error', 'msg' => 'Target wajib dipilih']); exit; }

    $q_old = mysqli_query($conn, "SELECT * FROM noci_installations WHERE id = $id AND tenant_id = $TENANT_ID");
    $d_task = mysqli_fetch_assoc($q_old);
    if (!$d_task) { echo json_encode(['status' => 'error', 'msg' => 'Data tidak ditemukan']); exit; }

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $isTeknisiSession = isset($_SESSION['teknisi_logged_in']);
    $isApiRequest = $is_api_valid;
    $currentTech = $_SESSION['teknisi_name'] ?? '';

    if (!$isPrivileged && !is_assigned_task($d_task, $currentTech)) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']);
        exit;
    }

    $sender_name = get_current_actor();

    $log_note = "\n\n[TRANSFER] Dari: " . $sender_name . " Ke: $to_tech\nAlasan: " . ($reason ?: '-') . "\nWaktu: " . date('d/m H:i');
    $stmt = $conn->prepare("UPDATE noci_installations SET technician = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt) {
        $stmt->bind_param("ssi", $to_tech, $log_note, $id);
        if ($stmt->execute()) {
            if (!is_teknisi_session()) {
                log_user_event($conn, 'transfer', $id);
            }

            $link = get_base_url() . "/dashboard.php?page=teknisi&id=$id";

            $msg = "*INFO TRANSFER TUGAS*\n\n";
            $msg .= "Halo *$to_tech*, Anda menerima limpahan tugas baru.\n\n";
            $msg .= "Dari: *$sender_name*\n";
            $msg .= "Alasan: " . ($reason ?: '-') . "\n\n";
            $msg .= "Nama: " . ($d_task['customer_name'] ?? '-') . "\n";
            $msg .= "Alamat: " . ($d_task['address'] ?? '-') . "\n";
            $msg .= "Nomor Whatsapp : " . ($d_task['customer_phone'] ?? '-') . "\n\n";
            $msg .= "KLIK UNTUK PROSES:\n$link";

            send_wa_notification($conn, $to_tech, $msg, "WA (Transfer)");
            echo json_encode(['status' => 'success']);
        } else { echo json_encode(['status' => 'error', 'msg' => $stmt->error]); }
        $stmt->close();
    } else echo json_encode(['status'=>'error', 'msg'=>'DB Error']);

// 3. REQ CANCEL
} elseif ($action == 'request_cancel') {
    $id = (int)get_val('id', $json_input);
    $reason = get_val('reason', $json_input);
    $tech_name = get_current_actor();

    $q_task = mysqli_query($conn, "SELECT * FROM noci_installations WHERE id = $id AND tenant_id = $TENANT_ID LIMIT 1");
    $d_task = mysqli_fetch_assoc($q_task);
    if (!$d_task) { echo json_encode(['status' => 'error', 'msg' => 'Data tidak ditemukan']); exit; }

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $currentTech = $_SESSION['teknisi_name'] ?? '';

    if (!$isPrivileged && !is_assigned_task($d_task, $currentTech)) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']);
        exit;
    }

    $log = "\n\n[REQ BATAL] Oleh: $tech_name Alasan: $reason ".date('d/m H:i');
    $stmt = $conn->prepare("UPDATE noci_installations SET status = 'Req_Batal', notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt) {
        $stmt->bind_param("si", $log, $id);
        if ($stmt->execute()) {
        if (!is_teknisi_session()) {
            log_user_event($conn, 'cancel_req', $id);
        }

            if ($d_task) {
                $q_conf = mysqli_query($conn, "SELECT * FROM noci_conf_wa WHERE tenant_id = $TENANT_ID AND is_active=1 LIMIT 1");
                $conf = mysqli_fetch_assoc($q_conf);
                if ($conf) {
                    $target_group = $conf['group_id'];
                    if(!empty($d_task['pop'])) {
                        $safe_pop = mysqli_real_escape_string($conn, $d_task['pop']);
                        $qp = mysqli_query($conn, "SELECT group_id FROM noci_pops WHERE tenant_id = $TENANT_ID AND pop_name = '$safe_pop' LIMIT 1");
                        if($qp && $rp = mysqli_fetch_assoc($qp)) if(!empty($rp['group_id'])) $target_group = $rp['group_id'];
                    }

                    $msg_batal = "*⚠️ PENGAJUAN BATAL ⚠️*\n\n";
                    $msg_batal .= "Teknisi: *$tech_name*\n";
                    $msg_batal .= "Pelanggan: " . ($d_task['customer_name'] ?? '-') . "\n";
                    $msg_batal .= "Alamat: " . ($d_task['address'] ?? '-') . "\n";
                    $msg_batal .= "Alasan: _" . $reason . "_\n\n";
                    $msg_batal .= "Mohon Admin/SVP segera tinjau via Dashboard.";

                    send_wa_group_notification($conn, $target_group, $msg_batal, "WA Group (Req Batal)");
                }
            }
            echo json_encode(['status'=>'success']);
        } else echo json_encode(['status'=>'error', 'msg'=>$stmt->error]);
        $stmt->close();
    } else echo json_encode(['status'=>'error', 'msg'=>'DB Error']);

// 4. DECIDE CANCEL
} elseif ($action == 'decide_cancel') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!$isAdminPanel && !in_array($userRole, ['admin', 'cs', 'svp lapangan']) && !$is_api_valid) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }
    $id = (int)get_val('id', $json_input);
    $decision = get_val('decision', $json_input);
    $spv_note = get_val('reason', $json_input);
    $actor = get_current_actor();

    if ($decision == 'approve') {
        $final_status = 'Batal';
        $log = "\n\n[SYSTEM] Pembatalan DISETUJUI oleh $actor.\nCatatan: $spv_note";
        $act_type = 'ACC_BATAL';
    } else {
        $final_status = 'Pending';
        $log = "\n\n[SYSTEM] Pembatalan DITOLAK oleh $actor. Kembali Pending.\nAlasan: $spv_note";
        $act_type = 'TOLAK_BATAL';
    }

    $finished_at = ($decision == 'approve') ? date('Y-m-d H:i:s') : NULL;
    $stmt = $conn->prepare("UPDATE noci_installations SET status = ?, notes = CONCAT(IFNULL(notes,''), ?), finished_at = ? WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt) {
        $stmt->bind_param("sssi", $final_status, $log, $finished_at, $id);
        if ($stmt->execute()) {
        if (!is_teknisi_session()) {
            $event_name = ($decision === 'approve') ? 'cancel_approve' : 'cancel_reject';
            log_user_event($conn, $event_name, $id);
        }

            if ($decision == 'approve') {
                $q_task = mysqli_query($conn, "SELECT * FROM noci_installations WHERE id = $id AND tenant_id = $TENANT_ID LIMIT 1");
                $d_task = mysqli_fetch_assoc($q_task);
                if ($d_task) {
                    $q_conf = mysqli_query($conn, "SELECT * FROM noci_conf_wa WHERE tenant_id = $TENANT_ID AND is_active=1 LIMIT 1");
                    $conf = mysqli_fetch_assoc($q_conf);
                    if ($conf) {
                        $target_group = $conf['group_id'];
                        if(!empty($d_task['pop'])) {
                            $safe_pop = mysqli_real_escape_string($conn, $d_task['pop']);
                            $qp = mysqli_query($conn, "SELECT group_id FROM noci_pops WHERE tenant_id = $TENANT_ID AND pop_name = '$safe_pop' LIMIT 1");
                            if($qp && $rp = mysqli_fetch_assoc($qp)) if(!empty($rp['group_id'])) $target_group = $rp['group_id'];
                        }
                        $msg_acc = "*INFO PEMBATALAN DISETUJUI*\n\nStatus: *BATAL (Closed)*\nOleh: *$actor*\nAlasan ACC: _" . ($spv_note ?: '-') . "_\n\nPelanggan: " . ($d_task['customer_name'] ?? '-') . "\nAlamat: " . ($d_task['address'] ?? '-') . "\nTeknisi: " . ($d_task['technician'] ?? '-') . "\n";
                        send_wa_group_notification($conn, $target_group, $msg_acc, "WA Group (ACC Batal)");
                    }
                }
            }
            echo json_encode(['status' => 'success']);
        } else echo json_encode(['status' => 'error', 'msg' => $stmt->error]);
        $stmt->close();
    } else echo json_encode(['status'=>'error', 'msg'=>'DB Error']);

// 5. PRIORITY
} elseif ($action == 'toggle_priority') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!$isAdminPanel && !in_array($userRole, ['admin', 'cs', 'svp lapangan']) && !$is_api_valid) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }
    $id = (int)get_val('id', $json_input);
    $val = (int)get_val('val', $json_input);
    $stmt = $conn->prepare("UPDATE noci_installations SET is_priority = ? WHERE id = ? AND tenant_id = $TENANT_ID");
    if ($stmt) {
        $stmt->bind_param("ii", $val, $id);
        if ($stmt->execute()) {
        if (!is_teknisi_session()) {
            $event_name = ((int)$val === 1) ? 'priority_on' : 'priority_off';
            log_user_event($conn, $event_name, $id);
        }
            echo json_encode(['status' => 'success']);
        } else echo json_encode(['status' => 'error', 'msg' => $stmt->error]);
        $stmt->close();
    } else echo json_encode(['status'=>'error', 'msg'=>'DB Error']);

// 6. SAVE
} elseif ($action == 'save') {
    $id = get_val('id', $json_input);
    $id_int = (int)$id;

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $actor = get_current_actor();
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $isTeknisiSession = isset($_SESSION['teknisi_logged_in']);
    $isApiRequest = $is_api_valid;
    $currentTech = $_SESSION['teknisi_name'] ?? '';

    $old = null;
    if ($id_int > 0) {
        $stmt_old = $conn->prepare("SELECT * FROM noci_installations WHERE id=? AND tenant_id = $TENANT_ID LIMIT 1");
        if ($stmt_old) {
            $stmt_old->bind_param("i", $id_int);
            $stmt_old->execute();
            $res_old = $stmt_old->get_result();
            $old = $res_old ? $res_old->fetch_assoc() : null;
            $stmt_old->close();
        }
    }
    if (!$isPrivileged && !$isApiRequest) {
        if ($id_int > 0) {
            if (!$old) { echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit; }
            $assigned = in_array($currentTech, [$old['technician'], $old['technician_2'], $old['technician_3'], $old['technician_4']], true);
            $allow_baru_unassigned = (strtolower($old['status'] ?? '') === 'baru');
            if (!$assigned && !$allow_baru_unassigned) { echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit; }
        } else {
            if (!$isTeknisiSession || $currentTech === '') { echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit; }
        }
    }

    $is_priority = (int)get_val('is_priority', $json_input);

    $raw_name = get_val('customer_name', $json_input);
    $raw_phone = preg_replace('/\D/', '', get_val('customer_phone', $json_input));
    if (substr($raw_phone, 0, 1) === '0') $raw_phone = '62' . substr($raw_phone, 1);
    elseif (substr($raw_phone, 0, 1) === '8') $raw_phone = '62' . $raw_phone;

    $final_name = $raw_name ?: "Pelanggan ".$raw_phone;
    $address = get_val('address', $json_input);

    $raw_pop = get_val('pop', $json_input);
    $pop = $raw_pop;

    if(!empty($raw_pop)) {
        $q_all = mysqli_query($conn, "SELECT pop_name FROM noci_pops WHERE tenant_id = $TENANT_ID");
        if($q_all) {
            $best = ""; $perc = 0;
            while($r = mysqli_fetch_assoc($q_all)) {
                similar_text(strtoupper($raw_pop), strtoupper($r['pop_name']), $p);
                if($p > $perc) { $perc = $p; $best = $r['pop_name']; }
            }
            if($perc >= 75) $pop = $best;
        }
    }

    $plan = get_val('plan_name', $json_input);
    $price = (int)preg_replace('/[^0-9]/', '', get_val('price', $json_input));
    $status = get_val('status', $json_input) ?: 'Baru';

    $tech  = get_val('technician', $json_input);
    $tech2 = get_val('technician_2', $json_input);
    $tech3 = get_val('technician_3', $json_input);
    $tech4 = get_val('technician_4', $json_input);
    if (!$isPrivileged && !$isApiRequest && $currentTech !== '') {
        $keep_self = in_array($currentTech, [$tech, $tech2, $tech3, $tech4], true);
        $allow_baru_unassigned = ($id_int <= 0) || (strtolower($old['status'] ?? '') === 'baru');
        if (!$keep_self && !$allow_baru_unassigned) { echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit; }
    }

    $coords = get_val('coordinates', $json_input);
    $notes_present = array_key_exists('notes', $json_input) || array_key_exists('notes', $_POST) || array_key_exists('notes', $_GET);
    $notes_input = $notes_present ? get_val('notes', $json_input) : '';
    $notes_old_input = get_val('notes_old', $json_input);
    $notes_append = get_val('notes_append', $json_input);
    if ($notes_append === '') $notes_append = get_val('note_append', $json_input);

    $base_notes = '';
    if ($old && isset($old['notes'])) $base_notes = (string)$old['notes'];
    if ($base_notes === '' && $notes_old_input !== '') $base_notes = (string)$notes_old_input;

    if ($notes_present) {
        $notes = (string)$notes_input;
    } else if (trim((string)$notes_append) !== '') {
        $append = trim((string)$notes_append);
        $base_trim = trim($base_notes);
        $notes = $base_trim === '' ? $append : rtrim($base_notes) . "\n\n" . $append;
    } else {
        $notes = $base_notes;
    }
    $install_date = get_val('installation_date', $json_input) ?: date('Y-m-d', strtotime('+1 day'));

    $sales  = get_val('sales_name', $json_input);
    $sales2 = get_val('sales_name_2', $json_input);
    $sales3 = get_val('sales_name_3', $json_input);

    if (!$isPrivileged && !$isApiRequest && $old) {
        if (!is_blank_sales($old['sales_name'] ?? '')) {
            $sales = $old['sales_name'];
        } else if (is_blank_sales($sales)) {
            $sales = '';
        }
    } else {
        if (is_blank_sales($sales)) $sales = '';
    }

    $finished_in = normalize_datetime_local(get_val('finished_at', $json_input));
    $finished_at = NULL;
    if (in_array($status, ['Selesai', 'Batal'], true)) {
        if ($old && !empty($old['finished_at']) && $finished_in === '') $finished_at = $old['finished_at'];
        else $finished_at = $finished_in ?: date('Y-m-d H:i:s');
    }

    $change_entries = [];
    if ($status === 'Selesai' && $old) {
        $old_name = trim((string)($old['customer_name'] ?? ''));
        $new_name = trim((string)$final_name);
        if ($old_name !== $new_name) {
            $change_entries[] = ['field' => 'customer_name', 'old' => $old_name, 'new' => $new_name];
        }

        $old_phone = trim((string)($old['customer_phone'] ?? ''));
        $new_phone = trim((string)$raw_phone);
        if ($old_phone !== $new_phone) {
            $change_entries[] = ['field' => 'customer_phone', 'old' => $old_phone, 'new' => $new_phone];
        }

        $old_addr = trim((string)($old['address'] ?? ''));
        $new_addr = trim((string)$address);
        if ($old_addr !== $new_addr) {
            $change_entries[] = ['field' => 'address', 'old' => $old_addr, 'new' => $new_addr];
        }
    }

    $is_new = ($id_int <= 0);
    $old_status = $old['status'] ?? '';
    $old_tech   = $old['technician'] ?? '';

    $status_changed = (!$is_new && $old_status !== $status);
    $tech_changed   = (!$is_new && (string)$old_tech !== (string)$tech);

    $send_group_new = $is_new;
    $send_group_status_baru_survey = ($status_changed && in_array($status, ['Baru','Survey']));
    $send_group_status_selesai     = ($status_changed && $status === 'Selesai');

    $send_personal_assign = (($isPrivileged || $isApiRequest) && $tech !== '' && ($is_new || $tech_changed));

    if (!$is_new) {
        $stmt = $conn->prepare("UPDATE noci_installations SET customer_name=?, customer_phone=?, address=?, pop=?, plan_name=?, price=?, status=?, notes=?, coordinates=?, technician=?, technician_2=?, technician_3=?, technician_4=?, sales_name=?, sales_name_2=?, sales_name_3=?, installation_date=?, finished_at=?, is_priority=? WHERE id=? AND tenant_id = $TENANT_ID");
        if (!$stmt) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }

        $stmt->bind_param(
            "sssssissssssssssssii",
            $final_name, $raw_phone, $address, $pop, $plan, $price, $status, $notes, $coords,
            $tech, $tech2, $tech3, $tech4,
            $sales, $sales2, $sales3,
            $install_date, $finished_at, $is_priority, $id_int
        );
    } else {
        $ticket = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $stmt = $conn->prepare("INSERT INTO noci_installations (tenant_id, ticket_id, customer_name, customer_phone, address, pop, plan_name, price, status, notes, coordinates, technician, technician_2, technician_3, technician_4, sales_name, sales_name_2, sales_name_3, installation_date, finished_at, is_priority) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }

        $stmt->bind_param(
            "issssssissssssssssssi",
            $TENANT_ID, $ticket, $final_name, $raw_phone, $address, $pop, $plan, $price, $status, $notes, $coords,
            $tech, $tech2, $tech3, $tech4,
            $sales, $sales2, $sales3,
            $install_date, $finished_at, $is_priority
        );
    }

    if (!$stmt->execute()) {
        echo json_encode(['status' => 'error', 'msg' => $stmt->error]);
        $stmt->close();
        exit;
    }

    $target_id = $is_new ? (int)$stmt->insert_id : $id_int;
    $stmt->close();

    if ($status_changed && !is_teknisi_session()) {
        if ($status === 'Pending') {
            log_user_event($conn, 'pending', $target_id);
        } elseif ($status === 'Selesai') {
            log_user_event($conn, 'finish', $target_id);
        } elseif ($status === 'Proses') {
            log_user_event($conn, 'resume', $target_id);
        }
    }

    $skip_update_log = $status_changed && in_array($status, ['Pending', 'Selesai', 'Proses'], true);
    if ($is_new) {
        log_activity($conn, $actor, 'NEW_TASK', $target_id, "Membuat tugas baru: $final_name");
    } elseif (!$skip_update_log) {
        log_activity($conn, $actor, 'UPDATE', $target_id, "Status: $old_status -> $status. Tech: $old_tech -> $tech. Prio: $is_priority");
    }

    if ($status === 'Selesai' && ($status_changed || $is_new)) {
        // Revenue will be generated on rekap send (combined with expenses)
    }

    if (!empty($change_entries)) {
        $actor_role = get_current_actor_role();
        $source = $isApiRequest ? 'api' : ($isAdminPanel ? 'admin' : 'teknisi');
        $changed_at = date('Y-m-d H:i:s');
        $stmt_c = $conn->prepare("INSERT INTO noci_installation_changes (tenant_id, installation_id, field_name, old_value, new_value, changed_at, changed_by, changed_by_role, source) VALUES (?,?,?,?,?,?,?,?,?)");
        if ($stmt_c) {
            foreach ($change_entries as $ch) {
                $field = $ch['field'];
                $old_val = $ch['old'];
                $new_val = $ch['new'];
                $stmt_c->bind_param("iisssssss", $TENANT_ID, $target_id, $field, $old_val, $new_val, $changed_at, $actor, $actor_role, $source);
                $stmt_c->execute();
            }
            $stmt_c->close();
        }
    }

    $q_conf = mysqli_query($conn, "SELECT * FROM noci_conf_wa WHERE tenant_id = $TENANT_ID AND is_active=1 LIMIT 1");
    $conf = mysqli_fetch_assoc($q_conf);
    $target_group = null;
    if ($conf) {
        $target_group = $conf['group_id'];
        if (!empty($pop)) {
            $safe_pop_notif = mysqli_real_escape_string($conn, $pop);
            $qp = mysqli_query($conn, "SELECT group_id FROM noci_pops WHERE tenant_id = $TENANT_ID AND pop_name = '$safe_pop_notif' LIMIT 1");
            if ($qp && $rp = mysqli_fetch_assoc($qp)) {
                if (!empty($rp['group_id'])) $target_group = $rp['group_id'];
            }
        }
    }

    if ($send_personal_assign) {
        $link_detail = get_base_url() . "/dashboard.php?page=teknisi&id=$target_id";
        $msgTech = "*INFO TUGAS / ASSIGN*\n\n";
        $msgTech .= "Halo *$tech*, Anda mendapatkan tugas.\n\n";
        $msgTech .= "Pelanggan: *$final_name*\n";
        $msgTech .= "WA: " . ($raw_phone ?: '-') . "\n";
        $msgTech .= "Alamat: " . ($address ?: '-') . "\n";
        $msgTech .= "POP: " . ($pop ?: '-') . "\n";
        $msgTech .= "Paket: " . ($plan ?: '-') . "\n";
        $msgTech .= "Jadwal: " . ($install_date ?: '-') . "\n\n";
        $msgTech .= "DETAIL: $link_detail";
        send_wa_notification($conn, $tech, $msgTech, "WA (Assign via Save)");
    }

    if ($conf && !empty($target_group)) {
        $should_send_group = ($send_group_new || $send_group_status_baru_survey || $send_group_status_selesai);
        if ($should_send_group) {
            $link_page = ($status === 'Selesai') ? 'riwayat' : 'teknisi';
            $link_detail = get_base_url() . "/dashboard.php?page=$link_page&id=$target_id";

            if ($send_group_status_selesai) {
                $wa_msg = "*✅ INSTALASI SELESAI*\n" . date('d M Y H:i') . "\n\n";
                $wa_msg .= "Nama: $final_name\n";
                $wa_msg .= "POP: " . ($pop ?: '-') . "\n";
                $wa_msg .= "Teknisi: " . ($tech ?: '-') . "\n";
                $wa_msg .= "Jadwal: " . ($install_date ?: '-') . "\n";
                $sales_parts = [];
                foreach ([$sales, $sales2, $sales3] as $s) {
                    if (!is_blank_sales($s)) $sales_parts[] = trim((string)$s);
                }
                $sales_label = !empty($sales_parts) ? implode(', ', $sales_parts) : '-';
                $wa_msg .= "Sales: " . $sales_label . "\n";
                $wa_msg .= "Biaya: Rp. " . number_format((int)$price, 0, ',', '.') . "\n";
                $wa_msg .= "Selesai: " . ($finished_at ?: date('Y-m-d H:i:s')) . "\n\n";
                if (!empty($change_entries)) {
                    $wa_msg .= "Perubahan Data:\n";
                    foreach ($change_entries as $ch) {
                        $label = $ch['field'] === 'customer_name' ? 'Nama' : ($ch['field'] === 'customer_phone' ? 'WA' : ($ch['field'] === 'address' ? 'Alamat' : $ch['field']));
                        $old_val = preg_replace('/\s+/', ' ', trim((string)($ch['old'] ?? '')));
                        $new_val = preg_replace('/\s+/', ' ', trim((string)($ch['new'] ?? '')));
                        if ($old_val === '') $old_val = '-';
                        if ($new_val === '') $new_val = '-';
                        $wa_msg .= "$label: $old_val -> $new_val\n";
                    }
                    $wa_msg .= "\n";
                }
                $wa_msg .= "DETAIL: $link_detail";
                send_wa_group_notification($conn, $target_group, $wa_msg, "WA Group (Selesai)");
            } else {
                $judul_msg = $send_group_new ? "INFO PASANG BARU" : "UPDATE DATA";
                $tgl_msg = date('d F Y H:i');

                $wa_msg = "$judul_msg\n$tgl_msg\n\n";
                $wa_msg .= "Nama: $final_name\n";
                $wa_msg .= "Wa: $raw_phone\n";
                $wa_msg .= "Alamat: $address\n";
                $wa_msg .= "Maps: " . ($coords ?: '-') . "\n";
                $wa_msg .= "POP: " . ($pop ?: '-') . "\n";
                $wa_msg .= "Paket: " . ($plan ?: '-') . "\n";
                $wa_msg .= "Sales: " . ($sales ?: '-') . "\n";
                $wa_msg .= "Teknisi: " . ($tech ?: '-') . "\n";
                $wa_msg .= "Status: " . ($status ?: '-') . "\n\n";
                $wa_msg .= "DETAIL: $link_detail";

                send_wa_group_notification($conn, $target_group, $wa_msg, "WA Group (Save Important)");
            }
        }
    }

    echo json_encode(['status' => 'success', 'id' => $target_id]);

// 6B. TRACK LOCATION (TEKNISI)
} elseif ($action == 'track_location') {
    if (!isset($_SESSION['teknisi_logged_in']) || $_SESSION['teknisi_logged_in'] !== true) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }

    $tech_role = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!in_array($tech_role, ['teknisi', 'svp lapangan'], true)) {
        echo json_encode(['status' => 'error', 'msg' => 'Role tidak didukung.']); exit;
    }

    $user_id = (int)($_SESSION['user_id'] ?? $_SESSION['teknisi_id'] ?? 0);
    $user_name = trim((string)($_SESSION['teknisi_name'] ?? ''));
    if ($user_id <= 0 || $user_name === '') {
        echo json_encode(['status' => 'error', 'msg' => 'Teknisi tidak valid.']); exit;
    }

    $lat_raw = get_val('lat', $json_input);
    $lng_raw = get_val('lng', $json_input);
    if (!is_numeric($lat_raw) || !is_numeric($lng_raw)) {
        echo json_encode(['status' => 'error', 'msg' => 'Koordinat tidak valid.']); exit;
    }
    $lat = (float)$lat_raw;
    $lng = (float)$lng_raw;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['status' => 'error', 'msg' => 'Koordinat di luar batas.']); exit;
    }

    $accuracy_raw = get_val('accuracy', $json_input);
    $speed_raw = get_val('speed', $json_input);
    $heading_raw = get_val('heading', $json_input);
    $accuracy = is_numeric($accuracy_raw) ? (int)$accuracy_raw : null;
    $speed = is_numeric($speed_raw) ? (float)$speed_raw : null;
    $heading = is_numeric($heading_raw) ? (float)$heading_raw : null;
    $event_name = trim((string)get_val('event_name', $json_input));
    if ($event_name === '') $event_name = trim((string)get_val('event', $json_input));
    if ($event_name !== '') {
        $event_name = preg_replace('/\s+/', ' ', $event_name);
        $event_name = substr($event_name, 0, 80);
    } else {
        $event_name = null;
    }

    $recorded_at = date('Y-m-d H:i:s');
    ensure_user_location_tables($conn);

    $stmt_log = $conn->prepare("INSERT INTO noci_user_location_logs (tenant_id, user_id, user_name, user_role, event_name, latitude, longitude, accuracy, speed, heading, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt_log) {
        $stmt_log->bind_param("iisssddidds", $TENANT_ID, $user_id, $user_name, $tech_role, $event_name, $lat, $lng, $accuracy, $speed, $heading, $recorded_at);
        $stmt_log->execute();
        $stmt_log->close();
    }

    $stmt_latest = $conn->prepare("INSERT INTO noci_user_location_latest (tenant_id, user_id, user_name, user_role, latitude, longitude, accuracy, speed, heading, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE user_name=VALUES(user_name), user_role=VALUES(user_role),
            latitude=VALUES(latitude), longitude=VALUES(longitude), accuracy=VALUES(accuracy),
            speed=VALUES(speed), heading=VALUES(heading), recorded_at=VALUES(recorded_at)");
    if ($stmt_latest) {
        $stmt_latest->bind_param("iissddidds", $TENANT_ID, $user_id, $user_name, $tech_role, $lat, $lng, $accuracy, $speed, $heading, $recorded_at);
        $stmt_latest->execute();
        $stmt_latest->close();
    }

    $stmt_clean = $conn->prepare("DELETE FROM noci_user_location_logs WHERE tenant_id = ? AND user_id = ? AND recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    if ($stmt_clean) {
        $stmt_clean->bind_param("ii", $TENANT_ID, $user_id);
        $stmt_clean->execute();
        $stmt_clean->close();
    }

    echo json_encode(['status' => 'success']);

// 6C. GET LIVE LOCATIONS (ADMIN/CS/SVP)
} elseif ($action == 'get_live_locations') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $adminRole = strtolower($_SESSION['level'] ?? '');
    $isPrivileged = $isAdminPanel && in_array($adminRole, ['admin', 'cs'], true);
    $svpRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isSvp = (isset($_SESSION['teknisi_logged_in']) && $_SESSION['teknisi_logged_in'] === true && $svpRole === 'svp lapangan');

    if (!$isPrivileged && !$isSvp) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }

    ensure_user_location_tables($conn);
    $rows = [];
    $q = mysqli_query($conn, "SELECT l.user_id AS technician_id, l.user_name AS technician_name, l.user_role AS technician_role,
            l.latitude, l.longitude, l.accuracy, l.speed, l.heading, l.recorded_at,
            e.event_name AS last_event, e.recorded_at AS last_event_at
        FROM noci_user_location_latest l
        LEFT JOIN (
            SELECT t.user_id, t.event_name, t.recorded_at
            FROM noci_user_location_logs t
            INNER JOIN (
                SELECT user_id, MAX(id) AS max_id
                FROM noci_user_location_logs
                WHERE tenant_id = $TENANT_ID AND user_role IN ('teknisi', 'svp lapangan')
                GROUP BY user_id
            ) m ON t.user_id = m.user_id AND t.id = m.max_id
            WHERE t.tenant_id = $TENANT_ID
        ) e ON e.user_id = l.user_id
        WHERE l.tenant_id = $TENANT_ID AND l.user_role IN ('teknisi', 'svp lapangan')
        ORDER BY l.recorded_at DESC");
    while ($q && ($r = mysqli_fetch_assoc($q))) $rows[] = $r;
    echo json_encode(['status' => 'success', 'data' => $rows]);

// 6D. GET LOCATION HISTORY (ADMIN/CS/SVP)
} elseif ($action == 'get_track_history') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $adminRole = strtolower($_SESSION['level'] ?? '');
    $isPrivileged = $isAdminPanel && in_array($adminRole, ['admin', 'cs'], true);
    $svpRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isSvp = (isset($_SESSION['teknisi_logged_in']) && $_SESSION['teknisi_logged_in'] === true && $svpRole === 'svp lapangan');

    if (!$isPrivileged && !$isSvp) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }

    $tech_id = (int)get_val('technician_id', $json_input);
    if ($tech_id <= 0) { echo json_encode(['status' => 'error', 'msg' => 'Teknisi tidak valid.']); exit; }

    $date_from = trim((string)get_val('date_from', $json_input));
    $date_to = trim((string)get_val('date_to', $json_input));
    $range_clause = "recorded_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
    $params = [];
    $types = '';

    if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $start = $date_from . " 00:00:00";
        if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $end = $date_to . " 23:59:59";
            $range_clause = "recorded_at BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$start, $end];
        } else {
            $range_clause = "recorded_at >= ?";
            $types = 's';
            $params = [$start];
        }
    }

    ensure_user_location_tables($conn);
    $sql = "SELECT event_name, latitude, longitude, accuracy, speed, heading, recorded_at
        FROM noci_user_location_logs
        WHERE tenant_id = $TENANT_ID AND user_id = ? AND user_role IN ('teknisi', 'svp lapangan') AND $range_clause
        ORDER BY recorded_at ASC
        LIMIT 2000";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { echo json_encode(['status' => 'error', 'msg' => 'DB Error']); exit; }
    if ($types !== '') {
        $types2 = 'i' . $types;
        $params2 = array_merge([$tech_id], $params);
        bind_dynamic($stmt, $types2, $params2);
    } else {
        $stmt->bind_param('i', $tech_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($res && ($row = $res->fetch_assoc())) $data[] = $row;
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => $data]);

// 7. LOG CUSTOM
} elseif ($action == 'log_custom') {
    $id = (int)get_val('id', $json_input);
    $type = get_val('type', $json_input);
    $msg = get_val('message', $json_input);
    $actor = get_current_actor();

    log_activity($conn, $actor, $type, $id, $msg);
    echo json_encode(['status' => 'success']);

// 8. GET LIST
} elseif ($action == 'get_list') {
    $page = max(1, (int)get_val('page', $json_input));
    $per_page = (int)get_val('per_page', $json_input);
    if ($per_page < 5) $per_page = 5;
    if ($per_page > 100) $per_page = 100;
    $offset = ($page - 1) * $per_page;

    $search = trim((string)get_val('search', $json_input));
    $date = trim((string)get_val('date', $json_input));
    $date_preset = trim((string)get_val('date_preset', $json_input));
    $date_from = trim((string)get_val('date_from', $json_input));
    $date_to = trim((string)get_val('date_to', $json_input));
    $status = trim((string)get_val('status', $json_input));
    $pop = trim((string)get_val('pop', $json_input));
    $tech = trim((string)get_val('tech', $json_input));
    $priority_only = ((int)get_val('priority_only', $json_input) === 1);
    $overdue_only  = ((int)get_val('overdue_only', $json_input) === 1);

    $where = [];
    $types = '';
    $params = [];
    $where[] = "tenant_id = $TENANT_ID";

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(ticket_id LIKE ? OR customer_name LIKE ? OR address LIKE ? OR technician LIKE ? OR technician_2 LIKE ? OR technician_3 LIKE ? OR technician_4 LIKE ?)";
        $types .= 'sssssss';
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($date_from !== '' && $date_to !== '') { $where[] = "installation_date BETWEEN ? AND ?"; $types .= 'ss'; $params[] = $date_from; $params[] = $date_to; }
    else if ($date_from !== '') { $where[] = "installation_date >= ?"; $types .= 's'; $params[] = $date_from; }
    else if ($date_to !== '') { $where[] = "installation_date <= ?"; $types .= 's'; $params[] = $date_to; }
    else if ($date !== '') { $where[] = "installation_date = ?"; $types .= 's'; $params[] = $date; }
    if ($status !== '') { $where[] = "status = ?"; $types .= 's'; $params[] = $status; }
    if ($pop !== '') { $where[] = "pop = ?"; $types .= 's'; $params[] = $pop; }
    if ($tech !== '') {
        $where[] = "(technician = ? OR technician_2 = ? OR technician_3 = ? OR technician_4 = ?)";
        $types .= 'ssss';
        $params[] = $tech; $params[] = $tech; $params[] = $tech; $params[] = $tech;
    }
    if ($priority_only) $where[] = "is_priority = 1";

    if ($overdue_only) {
        $today = date('Y-m-d');
        $where[] = "(installation_date IS NOT NULL AND installation_date <> '' AND installation_date < ? AND status NOT IN ('Selesai','Batal'))";
        $types .= 's';
        $params[] = $today;
    }

    $where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $total = 0;
    $stmt_c = $conn->prepare("SELECT COUNT(*) AS c FROM noci_installations $where_sql");
    if (!$stmt_c) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }
    bind_dynamic($stmt_c, $types, $params);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    if ($res_c && ($r = $res_c->fetch_assoc())) $total = (int)$r['c'];
    $stmt_c->close();

    $total_pages = (int)ceil($total / $per_page);
    if ($total_pages < 1) $total_pages = 1;
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $data = [];
    $sql_data = "SELECT * FROM noci_installations $where_sql ORDER BY is_priority DESC, id DESC LIMIT ? OFFSET ?";
    $stmt_d = $conn->prepare($sql_data);
    if (!$stmt_d) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $per_page;
    $params2[] = $offset;
    bind_dynamic($stmt_d, $types2, $params2);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();
    while ($res_d && ($row = $res_d->fetch_assoc())) $data[] = $row;
    $stmt_d->close();

    $summary = [
        'priority' => 0,
        'overdue' => 0,
        'Baru' => 0,
        'Survey' => 0,
        'Proses' => 0,
        'Pending' => 0,
        'Req_Batal' => 0,
        'Batal' => 0,
        'today_done' => 0,
    ];

    $sum_where = [];
    $sum_types = '';
    $sum_params = [];
    $sum_where[] = "tenant_id = $TENANT_ID";
    if (!empty($pop)) { $sum_where[] = "pop = ?"; $sum_types .= 's'; $sum_params[] = $pop; }
    if (!empty($date_from) && !empty($date_to)) { $sum_where[] = "installation_date BETWEEN ? AND ?"; $sum_types .= 'ss'; $sum_params[] = $date_from; $sum_params[] = $date_to; }
    else if (!empty($date_from)) { $sum_where[] = "installation_date >= ?"; $sum_types .= 's'; $sum_params[] = $date_from; }
    else if (!empty($date_to)) { $sum_where[] = "installation_date <= ?"; $sum_types .= 's'; $sum_params[] = $date_to; }
    else if (!empty($date)) { $sum_where[] = "installation_date = ?"; $sum_types .= 's'; $sum_params[] = $date; }
    $sum_sql = count($sum_where) ? ('WHERE ' . implode(' AND ', $sum_where)) : '';

    $stmt_s = $conn->prepare("SELECT status, COUNT(*) AS c FROM noci_installations $sum_sql GROUP BY status");
    if ($stmt_s) {
        bind_dynamic($stmt_s, $sum_types, $sum_params);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();
        while ($res_s && ($r = $res_s->fetch_assoc())) {
            $st = $r['status'];
            if (isset($summary[$st])) $summary[$st] = (int)$r['c'];
        }
        $stmt_s->close();
    }

    $sql_p = "SELECT COUNT(*) AS c FROM noci_installations $sum_sql" . (count($sum_where) ? " AND is_priority=1" : "WHERE is_priority=1");
    $stmt_p = $conn->prepare($sql_p);
    if ($stmt_p) {
        bind_dynamic($stmt_p, $sum_types, $sum_params);
        $stmt_p->execute();
        $res_p = $stmt_p->get_result();
        if ($res_p && ($r = $res_p->fetch_assoc())) $summary['priority'] = (int)$r['c'];
        $stmt_p->close();
    }

    $today = date('Y-m-d');
    $sql_o = "SELECT COUNT(*) AS c FROM noci_installations $sum_sql" .
             (count($sum_where) ? " AND installation_date < ? AND status NOT IN ('Selesai','Batal')" :
                                 "WHERE installation_date < ? AND status NOT IN ('Selesai','Batal')");
    $stmt_o = $conn->prepare($sql_o);
    if ($stmt_o) {
        $types_o = $sum_types . 's';
        $params_o = $sum_params;
        $params_o[] = $today;
        bind_dynamic($stmt_o, $types_o, $params_o);
        $stmt_o->execute();
        $res_o = $stmt_o->get_result();
        if ($res_o && ($r = $res_o->fetch_assoc())) $summary['overdue'] = (int)$r['c'];
        $stmt_o->close();
    }

    $dt_where = [];
    $dt_types = '';
    $dt_params = [];
    $dt_where[] = "tenant_id = $TENANT_ID";
    if (!empty($pop)) { $dt_where[] = "pop = ?"; $dt_types .= 's'; $dt_params[] = $pop; }
    $dt_where[] = "status='Selesai' AND finished_at IS NOT NULL AND DATE(finished_at)=?";
    $dt_types .= 's';
    $dt_params[] = $today;
    $dt_sql = 'WHERE ' . implode(' AND ', $dt_where);

    $stmt_t = $conn->prepare("SELECT COUNT(*) AS c FROM noci_installations $dt_sql");
    if ($stmt_t) {
        bind_dynamic($stmt_t, $dt_types, $dt_params);
        $stmt_t->execute();
        $res_t = $stmt_t->get_result();
        if ($res_t && ($r = $res_t->fetch_assoc())) $summary['today_done'] = (int)$r['c'];
        $stmt_t->close();
    }

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'summary' => $summary
    ]);

// 9. GET HISTORY (TEKNISI)
} elseif ($action == 'get_history') {
    $page = max(1, (int)get_val('page', $json_input));
    $per_page = (int)get_val('per_page', $json_input);
    if ($per_page < 5) $per_page = 5;
    if ($per_page > 100) $per_page = 100;
    $offset = ($page - 1) * $per_page;

    $search = trim((string)get_val('search', $json_input));
    $status = trim((string)get_val('status', $json_input));
    $date_from = trim((string)get_val('date_from', $json_input));
    $date_to = trim((string)get_val('date_to', $json_input));
    $tech = trim((string)get_val('tech', $json_input));

    if ($status !== '' && !in_array($status, ['Selesai', 'Batal'], true)) $status = '';

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $currentTech = $_SESSION['teknisi_name'] ?? '';

    if (!$isPrivileged) {
        if ($currentTech === '') { echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit; }
        $tech = $currentTech;
    }

    $where = [];
    $types = '';
    $params = [];
    $where[] = "tenant_id = $TENANT_ID";

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = "(ticket_id LIKE ? OR customer_name LIKE ? OR address LIKE ?)";
        $types .= 'sss';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    if ($status !== '') {
        $where[] = "status = ?";
        $types .= 's';
        $params[] = $status;
    } else {
        $where[] = "status IN ('Selesai','Batal')";
    }

    if ($date_from !== '' && $date_to !== '') {
        $where[] = "(finished_at IS NOT NULL AND DATE(finished_at) BETWEEN ? AND ?)";
        $types .= 'ss';
        $params[] = $date_from; $params[] = $date_to;
    } else if ($date_from !== '') {
        $where[] = "(finished_at IS NOT NULL AND DATE(finished_at) >= ?)";
        $types .= 's';
        $params[] = $date_from;
    } else if ($date_to !== '') {
        $where[] = "(finished_at IS NOT NULL AND DATE(finished_at) <= ?)";
        $types .= 's';
        $params[] = $date_to;
    }

    if ($tech !== '') {
        $where[] = "(technician = ? OR technician_2 = ? OR technician_3 = ? OR technician_4 = ?)";
        $types .= 'ssss';
        $params[] = $tech; $params[] = $tech; $params[] = $tech; $params[] = $tech;
    }

    $where_sql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

    $total = 0;
    $stmt_c = $conn->prepare("SELECT COUNT(*) AS c FROM noci_installations $where_sql");
    if (!$stmt_c) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }
    bind_dynamic($stmt_c, $types, $params);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    if ($res_c && ($r = $res_c->fetch_assoc())) $total = (int)$r['c'];
    $stmt_c->close();

    $total_pages = (int)ceil($total / $per_page);
    if ($total_pages < 1) $total_pages = 1;
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $data = [];
    $sql_data = "SELECT * FROM noci_installations $where_sql ORDER BY finished_at DESC, id DESC LIMIT ? OFFSET ?";
    $stmt_d = $conn->prepare($sql_data);
    if (!$stmt_d) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }
    $types2 = $types . 'ii';
    $params2 = $params;
    $params2[] = $per_page;
    $params2[] = $offset;
    bind_dynamic($stmt_d, $types2, $params2);
    $stmt_d->execute();
    $res_d = $stmt_d->get_result();
    while ($res_d && ($row = $res_d->fetch_assoc())) $data[] = $row;
    $stmt_d->close();

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages
    ]);

// 10. GET REKAP EXPENSES
} elseif ($action == 'get_rekap_expenses') {
    ensure_rekap_expenses_table($conn);
    $date = trim((string)get_val('date', $json_input));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['status' => 'error', 'msg' => 'Tanggal tidak valid.']);
        exit;
    }

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $currentTech = $_SESSION['teknisi_name'] ?? '';
    $tech = trim((string)get_val('tech', $json_input));
    if (!$isPrivileged || $tech === '') $tech = $currentTech;
    if ($tech === '') { echo json_encode(['status' => 'error', 'msg' => 'Teknisi tidak ditemukan.']); exit; }

    $stmt = $conn->prepare("SELECT expenses_json, team_json FROM noci_rekap_expenses WHERE tenant_id = $TENANT_ID AND rekap_date = ? AND technician_name = ? LIMIT 1");
    if (!$stmt) { echo json_encode(['status' => 'error', 'msg' => 'DB Error']); exit; }
    $stmt->bind_param('ss', $date, $tech);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $expenses = [];
    $team = [];
    if ($row) {
        $expenses = json_decode($row['expenses_json'] ?? '[]', true);
        if (!is_array($expenses)) $expenses = [];
        $team = json_decode($row['team_json'] ?? '[]', true);
        if (!is_array($team)) $team = [];
    }

    echo json_encode(['status' => 'success', 'data' => ['expenses' => $expenses, 'team' => $team]]);

// 11. SAVE REKAP EXPENSES
} elseif ($action == 'save_rekap_expenses') {
    ensure_rekap_expenses_table($conn);
    $date = trim((string)get_val('date', $json_input));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['status' => 'error', 'msg' => 'Tanggal tidak valid.']);
        exit;
    }

    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    $isPrivileged = $isAdminPanel || in_array($userRole, ['admin', 'cs', 'svp lapangan']);
    $currentTech = $_SESSION['teknisi_name'] ?? '';
    $tech = trim((string)get_val('tech', $json_input));
    if (!$isPrivileged || $tech === '') $tech = $currentTech;
    if ($tech === '') { echo json_encode(['status' => 'error', 'msg' => 'Teknisi tidak ditemukan.']); exit; }

    $raw_expenses = get_val('expenses', $json_input);
    if (is_string($raw_expenses)) {
        $raw_expenses = json_decode($raw_expenses, true);
    }
    if (!is_array($raw_expenses)) $raw_expenses = [];

    $expenses = [];
    foreach ($raw_expenses as $item) {
        if (!is_array($item)) continue;
        $name = trim((string)($item['name'] ?? ''));
        $amount_raw = $item['amount'] ?? 0;
        $amount = (int)preg_replace('/\D/', '', (string)$amount_raw);
        if ($name === '' && $amount <= 0) continue;
        $expenses[] = ['name' => $name, 'amount' => $amount];
    }

    $team = collect_rekap_team_names($conn, $date, $tech);
    $targets = count($team) ? $team : [$tech];

    if (count($expenses) === 0) {
        $stmt = $conn->prepare("DELETE FROM noci_rekap_expenses WHERE tenant_id = $TENANT_ID AND rekap_date = ? AND technician_name = ?");
        if ($stmt) {
            foreach ($targets as $target) {
                $stmt->bind_param('ss', $date, $target);
                $stmt->execute();
            }
            $stmt->close();
        }
        foreach ($targets as $target) {
            delete_rekap_expense_tx($conn, $TENANT_ID, $date, $target);
        }
        echo json_encode(['status' => 'success', 'deleted' => true, 'team' => $targets]);
        exit;
    }

    $expenses_json = json_encode($expenses, JSON_UNESCAPED_UNICODE);
    $team_json = json_encode($team, JSON_UNESCAPED_UNICODE);
    $actor = get_current_actor();

    $stmt = $conn->prepare("INSERT INTO noci_rekap_expenses (tenant_id, rekap_date, technician_name, expenses_json, team_json, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE expenses_json = VALUES(expenses_json), team_json = VALUES(team_json), updated_by = VALUES(updated_by)");
    if (!$stmt) { echo json_encode(['status' => 'error', 'msg' => 'DB Error']); exit; }
    foreach ($targets as $target) {
        $stmt->bind_param('issssss', $TENANT_ID, $date, $target, $expenses_json, $team_json, $actor, $actor);
        $stmt->execute();
    }
    $stmt->close();

    $user_id = 0;
    $user_name = $actor;
    if (isset($_SESSION['teknisi_logged_in'])) {
        $user_id = $_SESSION['teknisi_id'] ?? 0;
        $user_name = $_SESSION['teknisi_name'] ?? $actor;
    } elseif (isset($_SESSION['is_logged_in'])) {
        $user_id = $_SESSION['admin_id'] ?? 0;
        $user_name = $_SESSION['admin_name'] ?? $actor;
    }
    // Finance transaction will be generated on rekap send (combined with revenue)

    echo json_encode(['status' => 'success', 'data' => ['expenses' => $expenses, 'team' => $team, 'targets' => $targets]]);

// 12. GET ONE
} elseif ($action == 'get_one') {
    $id = (int)get_val('id', $json_input);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'msg' => 'ID tidak valid']); exit; }
    $stmt = $conn->prepare("SELECT * FROM noci_installations WHERE id = ? AND tenant_id = $TENANT_ID LIMIT 1");
    if (!$stmt) { echo json_encode(['status' => 'error', 'msg' => 'DB Error']); exit; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) { echo json_encode(['status' => 'error', 'msg' => 'Data tidak ditemukan']); exit; }
    echo json_encode(['status' => 'success', 'data' => $row]);

// 11. GET CHANGES
} elseif ($action == 'get_changes') {
    $id = (int)get_val('id', $json_input);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'msg' => 'ID tidak valid']); exit; }
    $stmt = $conn->prepare("SELECT field_name, old_value, new_value, changed_at, changed_by, changed_by_role, source FROM noci_installation_changes WHERE tenant_id = $TENANT_ID AND installation_id = ? ORDER BY changed_at DESC, id DESC");
    if (!$stmt) { echo json_encode(['status' => 'error', 'msg' => 'DB Error']); exit; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = [];
    while ($res && ($row = $res->fetch_assoc())) $data[] = $row;
    $stmt->close();
    echo json_encode(['status' => 'success', 'data' => $data]);

// 12. GET ALL
} elseif ($action == 'get_all') {
    $q = mysqli_query($conn, "SELECT * FROM noci_installations WHERE tenant_id = $TENANT_ID ORDER BY is_priority DESC, id DESC LIMIT 200");
    $data = [];
    while($r = mysqli_fetch_assoc($q)) $data[] = $r;
    echo json_encode($data);

// 13. GET TECHS
} elseif ($action == 'get_technicians') {
    $q = mysqli_query($conn, "SELECT name FROM noci_users WHERE tenant_id = $TENANT_ID AND role IN ('teknisi', 'svp lapangan') ORDER BY name ASC");
    $data = [];
    while($r = mysqli_fetch_assoc($q)) $data[] = $r['name'];
    echo json_encode($data);

// 14. GET POPS
} elseif ($action == 'get_pops') {
    $q = mysqli_query($conn, "SELECT pop_name FROM noci_pops WHERE tenant_id = $TENANT_ID ORDER BY pop_name ASC");
    $data = [];
    while($r = mysqli_fetch_assoc($q)) $data[] = $r['pop_name'];
    echo json_encode($data);

// 15. GET STATUSES
} elseif ($action == 'get_statuses') {
    $q = mysqli_query($conn, "SHOW COLUMNS FROM noci_installations LIKE 'status'");
    if ($row = mysqli_fetch_assoc($q)) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) echo json_encode(explode("','", $matches[1]));
        else echo json_encode([]);
    } else echo json_encode([]);

// 16. DELETE
} elseif ($action == 'delete') {
    $id = (int)get_val('id', $json_input);
    $actor = get_current_actor();
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!$isAdminPanel && !in_array($userRole, ['admin', 'cs', 'svp lapangan'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM noci_installations WHERE id=? AND tenant_id = $TENANT_ID");
    if (!$stmt) { echo json_encode(['status'=>'error','msg'=>'DB Error']); exit; }
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        log_activity($conn, $actor, 'DELETE', $id, "Menghapus data instalasi");
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error','msg'=>'Gagal hapus']);
    }

// 15. SEND RECAP MANUAL
} elseif ($action == 'send_pop_recap') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!$isAdminPanel && !in_array($userRole, ['admin', 'cs', 'svp lapangan'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']); exit;
    }

    $target_pop = get_val('pop_name', $json_input);
    if (empty($target_pop)) { echo json_encode(['status'=>'error', 'msg'=>'Nama POP wajib dipilih']); exit; }

    set_time_limit(0);
    ignore_user_abort(true);

    $q_conf = mysqli_query($conn, "SELECT * FROM noci_conf_wa WHERE tenant_id = $TENANT_ID AND is_active=1 LIMIT 1");
    $conf = mysqli_fetch_assoc($q_conf);
    if (!$conf) { echo json_encode(['status'=>'error', 'msg'=>'Config WA belum disetting']); exit; }

    $target_group = $conf['group_id'];

    $safe_pop = mysqli_real_escape_string($conn, $target_pop);
    $qp = mysqli_query($conn, "SELECT group_id FROM noci_pops WHERE tenant_id = $TENANT_ID AND pop_name = '$safe_pop' LIMIT 1");
    if($qp && $rp = mysqli_fetch_assoc($qp)) {
        if(!empty($rp['group_id'])) $target_group = $rp['group_id'];
    }

    $sql_data = "SELECT * FROM noci_installations
                 WHERE pop = '$safe_pop'
                 AND status IN ('Baru', 'Survey', 'Proses')
                 ORDER BY FIELD(status, 'Baru', 'Survey', 'Proses'), id ASC";
    $q_data = mysqli_query($conn, $sql_data);

    $items = [];
    while($r = mysqli_fetch_assoc($q_data)) $items[] = $r;

    if (count($items) == 0) {
        echo json_encode(['status'=>'error', 'msg'=>"Tidak ada data antrian (Baru/Survey/Proses) di POP $target_pop."]); exit;
    }

    $actor = get_current_actor();

    $waktu_update = date('d M Y H:i');
    $header_msg = "==================\n";
    $header_msg .= "UPDATE MANUAL (Oleh: $actor)\n";
    $header_msg .= "POP: " . strtoupper($target_pop) . "\n";
    $header_msg .= "Waktu: $waktu_update\n";
    $header_msg .= "==================";

    $header_sent = send_wa_group_notification($conn, $target_group, $header_msg, "WA Recap (Header)");
    if (!$header_sent) {
        sleep(2);
        $header_sent = send_wa_group_notification($conn, $target_group, $header_msg, "WA Recap (Header Retry 1)");
    }
    if (!$header_sent) {
        sleep(2);
        $header_sent = send_wa_group_notification($conn, $target_group, $header_msg, "WA Recap (Header Retry 2)");
    }
    if (!$header_sent) {
        echo json_encode(['status' => 'error', 'msg' => 'Header rekap gagal dikirim. Cek konfigurasi gateway/backup.']);
        exit;
    }
    sleep(2);

    $BASE_URL = get_base_url();

    $sent_count = 0;
    $failed_count = 0;
    foreach ($items as $item) {
        $statusU = strtoupper($item['status']);
        $tgl_str = date('d F Y');

        if ($statusU == 'BARU') { $judul = "INFO PASANG BARU"; $btn = "AMBIL"; }
        else { $judul = "STATUS: $statusU"; $btn = "DETAIL"; }

            $link = $BASE_URL . "/dashboard.php?page=teknisi&id=" . $item['id'];

        $msg_item = "$judul\n$tgl_str\n\n";
        $msg_item .= "Nama: " . $item['customer_name'] . "\n";
        $msg_item .= "Wa: " . ($item['customer_phone'] ?: '-') . "\n";
        $msg_item .= "Alamat: " . ($item['address'] ?: '-') . "\n";
        $msg_item .= "Maps: " . ($item['coordinates'] ?: '-') . "\n";
        $msg_item .= "Paket: " . ($item['plan_name'] ?: '-') . "\n";
        $msg_item .= "Sales: " . ($item['sales_name'] ?: '-') . "\n";
        $msg_item .= "Teknisi: " . ($item['technician'] ?: '-') . "\n\n";
        $msg_item .= "$btn: $link";

        $item_sent = send_wa_group_notification($conn, $target_group, $msg_item, "WA Recap (Item)");
        if ($item_sent) $sent_count++; else $failed_count++;
        sleep(2);
    }

    log_activity($conn, $actor, 'MANUAL_RECAP', 0, "Mengirim rekap manual ke POP $target_pop (" . count($items) . " data)");
    if ($sent_count === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'Semua pesan rekap gagal dikirim. Cek Log/Outbox.']);
        exit;
    }
    $resp = ['status' => 'success', 'count' => count($items), 'sent' => $sent_count, 'failed' => $failed_count];
    if ($failed_count > 0) {
        $resp['msg'] = 'Sebagian pesan gagal. Cek Log/Outbox.';
    }
    echo json_encode($resp);

// 17. SEND TECHNICIAN RECAP TO GROUP (NEW)
} elseif ($action == 'send_rekap_to_group') {
    $isAdminPanel = isset($_SESSION['is_logged_in']);
    $userRole = strtolower($_SESSION['teknisi_role'] ?? '');
    if (!$isAdminPanel && !in_array($userRole, ['admin', 'cs', 'svp lapangan', 'teknisi'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Akses ditolak.']);
        exit;
    }

    $group_id = get_val('group_id', $json_input);
    $message = get_val('message', $json_input);
    $media_url = get_val('media_url', $json_input);
    $rekap_date = get_val('recap_date', $json_input);
    $tech_name = get_val('tech_name', $json_input);
    
    if (empty($group_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'ID grup wajib']);
        exit;
    }
    if (empty($message)) {
        echo json_encode(['status' => 'error', 'msg' => 'Pesan wajib']);
        exit;
    }

    $actor = get_current_actor();
    
    // Validate group ID format
    if (!preg_match('/^\d+@g\.us$/', $group_id) && !preg_match('/^[0-9]{10,}@g\.us$/', $group_id)) {
        echo json_encode(['status' => 'error', 'msg' => 'Format ID grup tidak valid']);
        exit;
    }

    set_time_limit(0);
    ignore_user_abort(true);

    $user_id = $_SESSION['teknisi_id'] ?? ($_SESSION['admin_id'] ?? 0);
    $user_name = $_SESSION['teknisi_name'] ?? ($_SESSION['admin_name'] ?? 'Admin');
    $tech_name = $tech_name ?: ($_SESSION['teknisi_name'] ?? $_SESSION['admin_name'] ?? '');
    $rekap_date = $rekap_date ?: date('Y-m-d');

    $fin = create_rekap_daily_finance_tx($conn, $TENANT_ID, $rekap_date, $tech_name, $user_id, $user_name);
    if (empty($fin['ok'])) {
        echo json_encode(['status' => 'error', 'msg' => $fin['error'] ?? 'Gagal membuat transaksi rekap']);
        exit;
    }

    // Get WA config
    $q_conf = mysqli_query($conn, "SELECT * FROM noci_conf_wa WHERE tenant_id = $TENANT_ID AND is_active=1 LIMIT 1");
    $conf = mysqli_fetch_assoc($q_conf);
    
    if (!$conf) {
        echo json_encode(['status' => 'error', 'msg' => 'Config WA belum disetting. Setup di Settings terlebih dahulu.']);
        exit;
    }

    // Send via wa_gateway_send (supports both balesotomatis and mpwa)
    $sent = false;
    if (!empty($media_url)) {
        $resp_media = wa_gateway_send_group_media($conn, $TENANT_ID, $group_id, $media_url, $message, ['log_platform' => 'Rekap Teknisi Media', 'force_failover' => true]);
        $sent = ($resp_media['status'] ?? '') === 'sent';
        if (!$sent) {
            $message = $message . "\n\nBukti Transfer: " . $media_url;
        }
    }
    if (!$sent) {
        $sent = send_wa_group_notification($conn, $group_id, $message, "Rekap Teknisi");
    }
    
    if ($sent) {
        log_activity($conn, $actor, 'SEND_RECAP_GROUP', 0, "Mengirim rekap ke grup WhatsApp $group_id");
        echo json_encode(['status' => 'success', 'msg' => 'Laporan terkirim ke grup WhatsApp']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Gagal mengirim ke grup. Cek konfigurasi gateway atau Log WA untuk detail error.']);
    }

// 18. UPLOAD REKAP TRANSFER PROOF
} elseif ($action == 'upload_rekap_bukti') {
    ensure_rekap_attachments_table($conn);
    if (!isset($_FILES['file'])) {
        echo json_encode(['status' => 'error', 'msg' => 'File tidak ditemukan']);
        exit;
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'msg' => 'Gagal upload']);
        exit;
    }
    $orig_name = $file['name'] ?? '';
    $tmp = $file['tmp_name'];
    $size = (int)$file['size'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($ext, $allowed, true)) {
        echo json_encode(['status' => 'error', 'msg' => 'Format file tidak didukung']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    $targetDir = __DIR__ . '/uploads/rekap/';
    if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);

    $rekap_date = trim((string)get_val('date', $json_input));
    $tech_name = $_SESSION['teknisi_name'] ?? ($_SESSION['admin_name'] ?? '');
    $tech_key = preg_replace('/[^A-Za-z0-9]+/', '', strtolower((string)$tech_name));
    $date_key = preg_replace('/[^0-9]/', '', $rekap_date ?: date('Y-m-d'));
    $base = 'rekap_bukti_' . ($tech_key ?: 'teknisi') . '_' . $date_key . '_' . date('His') . '_' . mt_rand(1000, 9999);
    $targetPath = $targetDir . $base . '.' . $ext;

    if (!move_uploaded_file($tmp, $targetPath)) {
        echo json_encode(['status' => 'error', 'msg' => 'Gagal simpan file']);
        exit;
    }

    $file_rel = 'uploads/rekap/' . basename($targetPath);
    $actor = get_current_actor();
    $created_by = $actor ?: $tech_name;
    if ($rekap_date === '') $rekap_date = date('Y-m-d');

    $stmt = $conn->prepare("INSERT INTO noci_rekap_attachments (tenant_id, rekap_date, technician_name, file_name, file_path, file_ext, mime_type, file_size, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stored_name = basename($targetPath);
        $stmt->bind_param('issssssis', $TENANT_ID, $rekap_date, $tech_name, $stored_name, $file_rel, $ext, $mime, $size, $created_by);
        $stmt->execute();
        $stmt->close();
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $file_url = $host ? ($scheme . '://' . $host . ($basePath ? $basePath : '') . '/' . $file_rel) : $file_rel;

    echo json_encode([
        'status' => 'success',
        'data' => [
            'file_name' => basename($targetPath),
            'file_path' => $file_rel,
            'file_url' => $file_url,
            'file_ext' => $ext,
            'mime_type' => $mime,
            'file_size' => $size
        ]
    ]);

// =============================================
//  GENERATE FINANCE ENTRIES FROM REKAP
// =============================================
} elseif ($action == 'get_laporan_teknisi') {
    // Get teknisi report (expenses, fees, approvals)
    require_once __DIR__ . '/lib/rekap_finance_helper.php';
    
    $date_from = get_val('date_from', $json_input);
    $date_to = get_val('date_to', $json_input);
    $teknisi_id = (int)get_val('teknisi_id', $json_input);
    $status = get_val('status', $json_input);
    
    if (!$date_from || !$date_to) {
        echo json_encode(['status' => 'error', 'msg' => 'Date range required']);
        exit;
    }
    
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to = mysqli_real_escape_string($conn, $date_to);
    $where_teknisi = $teknisi_id > 0 ? "AND teknisi_id = $teknisi_id" : '';
    $where_status = $status ? "AND fin_tx_status = '" . mysqli_real_escape_string($conn, $status) . "'" : '';
    
    // Get expenses
    $expenses = [];
    $q1 = mysqli_query($conn, "SELECT e.*, u.name as user_name 
        FROM noci_teknisi_expenses e
        LEFT JOIN noci_users u ON u.id = e.teknisi_id AND u.tenant_id = e.tenant_id
        WHERE e.tenant_id = $TENANT_ID AND e.expense_date BETWEEN '$date_from' AND '$date_to' $where_teknisi $where_status
        ORDER BY e.expense_date DESC");
    while ($q1 && $row = mysqli_fetch_assoc($q1)) {
        $expenses[] = $row;
    }
    
    // Get fees
    $fees = [];
    $q2 = mysqli_query($conn, "SELECT f.*, u.name as user_name 
        FROM noci_teknisi_installation_fees f
        LEFT JOIN noci_users u ON u.id = f.user_id AND u.tenant_id = f.tenant_id
        WHERE f.tenant_id = $TENANT_ID AND f.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59' $where_teknisi $where_status
        ORDER BY f.created_at DESC");
    while ($q2 && $row = mysqli_fetch_assoc($q2)) {
        $fees[] = $row;
    }
    
    // Get approvals (from approvals linked to expenses/fees)
    $approvals = [];
    $q3 = mysqli_query($conn, "
        SELECT a.*, t.tx_no, e.teknisi_id, u.name as teknisi_name, u2.name as approved_by_name,
            CASE WHEN e.id IS NOT NULL THEN 'expense' ELSE 'fee' END as entry_type,
            COALESCE(e.amount, f.fee_amount) as entry_amount
        FROM noci_fin_approvals a
        LEFT JOIN noci_fin_tx t ON t.id = a.tx_id AND t.tenant_id = a.tenant_id
        LEFT JOIN noci_teknisi_expenses e ON e.fin_tx_id = a.tx_id AND e.tenant_id = a.tenant_id
        LEFT JOIN noci_teknisi_installation_fees f ON f.fin_tx_id = a.tx_id AND f.tenant_id = a.tenant_id
        LEFT JOIN noci_users u ON u.id = (e.teknisi_id OR f.user_id) AND u.tenant_id = a.tenant_id
        LEFT JOIN noci_users u2 ON u2.id = a.approved_by AND u2.tenant_id = a.tenant_id
        WHERE a.tenant_id = $TENANT_ID AND a.approved_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59'
        ORDER BY a.approved_at DESC");
    while ($q3 && $row = mysqli_fetch_assoc($q3)) {
        $row['entry_id'] = $row['tx_id'];
        $approvals[] = $row;
    }
    
    // Calculate summary
    $summary = [
        'total_expense' => array_sum(array_map(fn($e) => (float)($e['amount'] ?? 0), $expenses)),
        'total_fee' => array_sum(array_map(fn($f) => (float)($f['fee_amount'] ?? 0), $fees)),
        'posted_count' => count(array_filter($expenses, fn($e) => $e['fin_tx_status'] === 'POSTED')) + 
                         count(array_filter($fees, fn($f) => $f['fin_tx_status'] === 'POSTED')),
        'pending_count' => count(array_filter($expenses, fn($e) => $e['fin_tx_status'] === 'DRAFT')) + 
                          count(array_filter($fees, fn($f) => $f['fin_tx_status'] === 'DRAFT'))
    ];
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'summary' => $summary,
            'expenses' => $expenses,
            'fees' => $fees,
            'approvals' => $approvals
        ]
    ]);

} elseif ($action == 'export_laporan_teknisi') {
    // Export laporan as CSV/Excel
    require_once __DIR__ . '/lib/rekap_finance_helper.php';
    
    $date_from = get_val('date_from', $_GET);
    $date_to = get_val('date_to', $_GET);
    $teknisi_id = (int)get_val('teknisi_id', $_GET);
    $status = get_val('status', $_GET);
    
    if (!$date_from || !$date_to) {
        echo 'Error: Date range required';
        exit;
    }
    
    $date_from = mysqli_real_escape_string($conn, $date_from);
    $date_to = mysqli_real_escape_string($conn, $date_to);
    $where_teknisi = $teknisi_id > 0 ? "AND teknisi_id = $teknisi_id" : '';
    $where_status = $status ? "AND fin_tx_status = '" . mysqli_real_escape_string($conn, $status) . "'" : '';
    
    // Collect data
    $expenses = [];
    $q1 = mysqli_query($conn, "SELECT e.*, u.name as user_name 
        FROM noci_teknisi_expenses e
        LEFT JOIN noci_users u ON u.id = e.teknisi_id AND u.tenant_id = e.tenant_id
        WHERE e.tenant_id = $TENANT_ID AND e.expense_date BETWEEN '$date_from' AND '$date_to' $where_teknisi $where_status
        ORDER BY e.expense_date DESC");
    while ($q1 && $row = mysqli_fetch_assoc($q1)) $expenses[] = $row;
    
    $fees = [];
    $q2 = mysqli_query($conn, "SELECT f.*, u.name as user_name 
        FROM noci_teknisi_installation_fees f
        LEFT JOIN noci_users u ON u.id = f.user_id AND u.tenant_id = f.tenant_id
        WHERE f.tenant_id = $TENANT_ID AND f.created_at BETWEEN '$date_from 00:00:00' AND '$date_to 23:59:59' $where_teknisi $where_status
        ORDER BY f.created_at DESC");
    while ($q2 && $row = mysqli_fetch_assoc($q2)) $fees[] = $row;
    
    // Generate CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_teknisi_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Expenses section
    fputcsv($output, ['PENGELUARAN HARIAN', 'Dari ' . $date_from . ' sampai ' . $date_to]);
    fputcsv($output, ['Tanggal', 'Teknisi', 'Kategori', 'Deskripsi', 'Jumlah', 'Status', 'TX ID']);
    foreach ($expenses as $exp) {
        fputcsv($output, [
            $exp['expense_date'],
            $exp['user_name'] ?? '',
            $exp['category'],
            $exp['description'] ?? '',
            $exp['amount'],
            $exp['fin_tx_status'],
            $exp['fin_tx_id'] ?? ''
        ]);
    }
    
    fputcsv($output, ['']);
    
    // Fees section
    fputcsv($output, ['FEE INSTALASI']);
    fputcsv($output, ['Tanggal', 'Instalasi ID', 'User', 'Tipe', 'Fee (User)', 'Total Fee', 'Status', 'TX ID']);
    foreach ($fees as $fee) {
        fputcsv($output, [
            substr($fee['created_at'], 0, 10),
            $fee['installation_id'],
            $fee['user_name'] ?? '',
            $fee['user_type'],
            $fee['fee_amount'],
            $fee['total_fee'],
            $fee['fin_tx_status'],
            $fee['fin_tx_id'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;

} elseif ($action == 'generate_rekap_finance') {
    // Called when teknisi saves rekap
    // Auto-generate finance entries untuk expenses + installation fees
    
    require_once __DIR__ . '/lib/rekap_finance_helper.php';
    
    $teknisi_id = (int)($_SESSION['teknisi_id'] ?? 0);
    $teknisi_name = $_SESSION['teknisi_name'] ?? '';
    $recap_date = get_val('recap_date', $json_input);
    $expense_text = get_val('expense_text', $json_input);
    
    if (!$teknisi_id || empty($recap_date)) {
        echo json_encode(['status' => 'error', 'msg' => 'Invalid teknisi atau tanggal']);
        exit;
    }
    
    $recap_date = mysqli_real_escape_string($conn, $recap_date);
    
    // Generate preview
    $preview = generateRekapFinancePreview($conn, $TENANT_ID, $teknisi_id, $recap_date, $expense_text);
    
    if (empty($preview)) {
        echo json_encode(['status' => 'success', 'msg' => 'No entries to generate', 'entries' => []]);
        exit;
    }
    
    // Save expenses ke noci_teknisi_expenses
    $expenses = parseRekapExpenses($expense_text);
    $categories = getExpenseCategories($conn, $TENANT_ID);
    
    foreach ($expenses as $exp) {
        saveTechniziExpense($conn, $TENANT_ID, $teknisi_id, $recap_date, $exp['category'], $exp['amount'], $exp['description']);
    }

    // Save teknisi fees ke noci_teknisi_installation_fees (pemasukan rekap)
    $techFees = calculateTechnicianFees($conn, $TENANT_ID, $teknisi_id, $recap_date);
    foreach ($techFees as $fee) {
        saveTechniziInstallationFee(
            $conn,
            $TENANT_ID,
            $fee['installation_id'] ?? 0,
            $teknisi_id,
            'teknisi',
            $fee['fee_amount'] ?? 0,
            $fee['total_fee'] ?? 0,
            $fee['tech_count'] ?? 1
        );
    }
    
    // Log activity
    log_activity($conn, "teknisi:$teknisi_name", 'RECAP_FINANCE_GENERATE', 0, "Generate finance entries dari rekap $recap_date");
    
    echo json_encode([
        'status' => 'success',
        'msg' => 'Finance entries generated, waiting for approval',
        'entries_count' => count($preview),
        'entries' => $preview
    ]);

} else {
    echo json_encode(['status'=>'error', 'msg'=>'Invalid action']);
}
