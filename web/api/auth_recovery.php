<?php

header('Content-Type: application/json');
require_once 'auth_support.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

auth_support_ensure_user_auth_columns($conn);

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'verify_registration') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));

    if ($username === '' || $email === '' || $code === '') {
        echo json_encode(['status' => 'error', 'message' => 'Username, email and code are required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, email_verified, verification_code_hash, verification_code_expires_at FROM users WHERE username = ? AND email = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'No matching account found.']);
        exit;
    }

    if ((int)$user['email_verified'] === 1) {
        echo json_encode(['status' => 'success', 'message' => 'Account already verified. You can sign in now.']);
        exit;
    }

    $expiresAt = strtotime((string)($user['verification_code_expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt < time()) {
        echo json_encode(['status' => 'error', 'message' => 'Code has expired. Please request a new code.']);
        exit;
    }

    $incomingHash = auth_support_hash_code($code);
    if (!hash_equals((string)$user['verification_code_hash'], $incomingHash)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid confirmation code.']);
        exit;
    }

    $update = $conn->prepare("UPDATE users SET email_verified = 1, verification_code_hash = NULL, verification_code_expires_at = NULL WHERE id = ?");
    $update->bind_param('i', $user['id']);
    $update->execute();

    echo json_encode(['status' => 'success', 'message' => 'Email verified successfully. You can now sign in.']);
    exit;
}

if ($action === 'resend_registration_code') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    if ($username === '' || $email === '') {
        echo json_encode(['status' => 'error', 'message' => 'Username and email are required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, full_name, email_verified FROM users WHERE username = ? AND email = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'No matching account found.']);
        exit;
    }

    if ((int)$user['email_verified'] === 1) {
        echo json_encode(['status' => 'success', 'message' => 'Account already verified. Please sign in.']);
        exit;
    }

    $code = auth_support_generate_code(6);
    $hash = auth_support_hash_code($code);
    $minutes = 15;
    $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

    $update = $conn->prepare("UPDATE users SET verification_code_hash = ?, verification_code_expires_at = ? WHERE id = ?");
    $update->bind_param('ssi', $hash, $expiresAt, $user['id']);
    $update->execute();

    $mailResult = auth_support_send_confirmation_code($email, (string)($user['full_name'] ?? ''), $code, $minutes);
    if (!$mailResult['success']) {
        echo json_encode(['status' => 'error', 'message' => 'Unable to send code right now. Please try again later.']);
        exit;
    }

    echo json_encode(['status' => 'success', 'message' => 'A new confirmation code has been sent.']);
    exit;
}

if ($action === 'forgot_password_request') {
    $email = trim((string)($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Please provide a valid email address.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, full_name, email_verified FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    // Always return a generic message to avoid account enumeration.
    $generic = ['status' => 'success', 'message' => 'If the account exists, a reset code has been sent to the email address.'];

    if (!$user || (int)($user['email_verified'] ?? 0) !== 1) {
        echo json_encode($generic);
        exit;
    }

    $code = auth_support_generate_code(6);
    $hash = auth_support_hash_code($code);
    $minutes = 15;
    $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

    $update = $conn->prepare("UPDATE users SET password_reset_code_hash = ?, password_reset_expires_at = ? WHERE id = ?");
    $update->bind_param('ssi', $hash, $expiresAt, $user['id']);
    $update->execute();

    $mailResult = auth_support_send_password_reset_code($email, (string)($user['full_name'] ?? ''), $code, $minutes);
    if (!$mailResult['success']) {
        echo json_encode(['status' => 'error', 'message' => 'Unable to send reset code right now. Please try again later.']);
        exit;
    }

    echo json_encode($generic);
    exit;
}

if ($action === 'forgot_password_reset') {
    $email = trim((string)($_POST['email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $code === '' || $newPassword === '') {
        echo json_encode(['status' => 'error', 'message' => 'Email, code and new password are required.']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, password_reset_code_hash, password_reset_expires_at FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid reset request.']);
        exit;
    }

    $expiresAt = strtotime((string)($user['password_reset_expires_at'] ?? ''));
    if (!$expiresAt || $expiresAt < time()) {
        echo json_encode(['status' => 'error', 'message' => 'Reset code has expired. Request a new one.']);
        exit;
    }

    $incomingHash = auth_support_hash_code($code);
    if (!hash_equals((string)$user['password_reset_code_hash'], $incomingHash)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid reset code.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE users SET password_hash = ?, password_reset_code_hash = NULL, password_reset_expires_at = NULL WHERE id = ?");
    $update->bind_param('si', $newHash, $user['id']);
    $update->execute();

    echo json_encode(['status' => 'success', 'message' => 'Password updated successfully. You can now sign in.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unsupported action']);
