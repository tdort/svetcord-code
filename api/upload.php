<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/models/UserModel.php';
require_once __DIR__ . '/../includes/models/ServerModel.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();
$user = Auth::requireLogin();
Auth::requireNotBanned($user); // every action in this file is a write (uploads)
$pdo = Database::get();
$action = $_GET['action'] ?? '';

/** Validate + move an uploaded image, returning its public URL. */
function handle_image_upload(string $fieldName, string $subDir): string
{
    global $user;
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        json_error('No valid file uploaded.');
    }
    $file = $_FILES[$fieldName];

    $isNitro = !empty($user['nitro_until']) && strtotime($user['nitro_until'] . ' UTC') > time();
    $maxBytes = $isNitro ? MAX_UPLOAD_BYTES * 2 : MAX_UPLOAD_BYTES;
    if ($file['size'] > $maxBytes) {
        $maxMb = (int) ($maxBytes / (1024 * 1024));
        json_error("File is too large (max {$maxMb}MB).");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true)) {
        json_error('Only PNG, JPEG, GIF, and WEBP images are allowed.');
    }

    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'bin',
    };

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = UPLOAD_DIR . '/' . $subDir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $destPath = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        json_error('Failed to save uploaded file.', 500);
    }

    return UPLOAD_URL . '/' . $subDir . '/' . $filename;
}

/** Like handle_image_upload but accepts the broader ALLOWED_ATTACHMENT_TYPES set (for message attachments). */
function handle_file_upload(string $fieldName, string $subDir): array
{
    global $user;
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        json_error('No valid file uploaded.');
    }
    $file = $_FILES[$fieldName];

    $isNitro = !empty($user['nitro_until']) && strtotime($user['nitro_until'] . ' UTC') > time();
    $maxBytes = $isNitro ? MAX_ATTACHMENT_BYTES * 2 : MAX_ATTACHMENT_BYTES;
    if ($file['size'] > $maxBytes) {
        $maxMb = (int) ($maxBytes / (1024 * 1024));
        json_error("File is too large (max {$maxMb}MB).");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ALLOWED_ATTACHMENT_TYPES, true)) {
        json_error('That file type is not allowed.');
    }

    $isImage = in_array($mime, ALLOWED_IMAGE_TYPES, true);
    $ext = match ($mime) {
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain'       => 'txt',
        'application/zip'  => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        default => 'bin',
    };

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = UPLOAD_DIR . '/' . $subDir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $destPath = $destDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        json_error('Failed to save uploaded file.', 500);
    }

    return [
        'url'  => UPLOAD_URL . '/' . $subDir . '/' . $filename,
        'name' => $file['name'],
        'type' => $isImage ? 'image' : 'file',
    ];
}

switch ($action) {
    case 'avatar': {
        require_method('POST');
        $url = handle_image_upload('avatar', 'avatars');
        UserModel::updateAvatar((int) $user['id'], $url);
        json_ok(['avatar_url' => $url]);
        break;
    }

    case 'banner': {
        require_method('POST');
        $isNitro = !empty($user['nitro_until']) && strtotime($user['nitro_until'] . ' UTC') > time();
        if (!$isNitro) {
            json_error('Custom profile banners are a Nitro perk.', 403);
        }
        $url = handle_image_upload('banner', 'banners');
        UserModel::updateBanner((int) $user['id'], $url);
        json_ok(['banner_url' => $url]);
        break;
    }

    case 'banner_remove': {
        require_method('POST');
        UserModel::updateBanner((int) $user['id'], null);
        json_ok(['banner_url' => null]);
        break;
    }

    case 'attachment': {
        require_method('POST');
        $file = handle_file_upload('file', 'attachments');
        json_ok($file); // { url, name, type }
        break;
    }

    case 'custom_emoji': {
        require_method('POST');
        $serverId = (int) ($_POST['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to manage this server\'s emojis.', 403);
        }
        $name = strtolower(trim((string) ($_POST['name'] ?? '')));
        if (!preg_match('/^[a-z0-9_]{2,32}$/', $name)) {
            json_error('Emoji name must be 2-32 characters: letters, numbers, underscore.');
        }
        require_once __DIR__ . '/../includes/models/CustomEmojiModel.php';
        if (CustomEmojiModel::findByServerAndName($serverId, $name)) {
            json_error('An emoji with that name already exists on this server.');
        }
        $url = handle_image_upload('icon', 'emojis');
        $emoji = CustomEmojiModel::create($serverId, $name, $url, (int) $user['id']);
        json_ok(['emoji' => $emoji]);
        break;
    }

    case 'server_icon': {
        require_method('POST');
        $serverId = (int) ($_POST['server_id'] ?? 0);
        $perms = Permissions::effectiveFor($pdo, $serverId, (int) $user['id']);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error('You do not have permission to change this server\'s icon.', 403);
        }
        $url = handle_image_upload('icon', 'icons');
        ServerModel::updateIcon($serverId, $url);
        json_ok(['icon_url' => $url]);
        break;
    }

    case 'custom_badge_icon': {
        require_method('POST');
        Auth::requireAdmin();
        $name = sanitize_text($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 50) {
            json_error('Badge name must be 1-50 characters.');
        }
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $url = handle_image_upload('icon', 'badges');
        $badge = CustomBadgeModel::create($name, $url);
        json_ok(['badge' => $badge]);
        break;
    }

    default:
        json_error('Unknown action', 404);
}
