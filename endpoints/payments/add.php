<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';

if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

function sanitizeFilename($filename)
{
    $filename = preg_replace("/[^a-zA-Z0-9\s]/", "", $filename);
    $filename = str_replace(" ", "-", $filename);
    $filename = str_replace(".", "", $filename);
    return $filename;
}

function validateFileExtension($fileExtension)
{
    $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'jtif', 'webp'];
    return in_array($fileExtension, $allowedExtensions);
}

function getLogoFromUrl($url, $uploadDir, $name, $i18n, $settings)
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
        $response = [
            "success" => false,
            "errorMessage" => "Invalid URL format."
        ];
        echo json_encode($response);
        exit();
    }

    $host = parse_url($url, PHP_URL_HOST);
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        $response = [
            "success" => false,
            "errorMessage" => "Invalid IP Address."
        ];
        echo json_encode($response);
        exit();
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

    $imageData = curl_exec($ch);

    if ($imageData !== false) {
        $timestamp = time();
        $fileName = $timestamp . '-payments-' . sanitizeFilename($name) . '.png';
        $uploadDir = '../../images/uploads/logos/';
        $uploadFile = $uploadDir . $fileName;

        if (saveLogo($imageData, $uploadFile, $name, $settings)) {
            curl_close($ch);
            return $fileName;
        } else {
            curl_close($ch);
            echo translate('error_fetching_image', $i18n) . ": " . curl_error($ch);
            return "";
        }
    } else {
        echo translate('error_fetching_image', $i18n) . ": " . curl_error($ch);
        return "";
    }
}


function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';
    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($image, $tempFile);
        imagedestroy($image);

        if (extension_loaded('imagick')) {
            $imagick = new Imagick($tempFile);
            if ($removeBackground) {
                $fuzz = Imagick::getQuantum() * 0.1; // 10%
                $imagick->transparentPaintImage("rgb(247, 247, 247)", 0, $fuzz, false);
            }
            $imagick->setImageFormat('png');
            $imagick->writeImage($uploadFile);

            $imagick->clear();
            $imagick->destroy();
        } else {
            // Alternative method if Imagick is not available
            $newImage = imagecreatefrompng($tempFile);
            if ($removeBackground) {
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
                imagefill($newImage, 0, 0, $transparent);  // Fill the entire image with transparency
                imagepng($newImage, $uploadFile);
                imagedestroy($newImage);
            }
            imagepng($newImage, $uploadFile);
            imagedestroy($newImage);
        }
        unlink($tempFile);

        return true;
    } else {
        return false;
    }
}

function resizeAndUploadLogo($uploadedFile, $uploadDir, $name)
{
    $targetWidth = 70;
    $targetHeight = 48;

    $timestamp = time();
    $originalFileName = $uploadedFile['name'];
    $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION);
    $fileExtension = validateFileExtension($fileExtension) ? $fileExtension : 'png';
    $fileName = $timestamp . '-payments-' . sanitizeFilename($name) . '.' . $fileExtension;
    $uploadFile = $uploadDir . $fileName;

    if (move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        $fileInfo = getimagesize($uploadFile);

        if ($fileInfo !== false) {
            $width = $fileInfo[0];
            $height = $fileInfo[1];

            // Load the image based on its format
            if ($fileExtension === 'png') {
                $image = imagecreatefrompng($uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = imagecreatefromjpeg($uploadFile);
            } elseif ($fileExtension === 'gif') {
                $image = imagecreatefromgif($uploadFile);
            } elseif ($fileExtension === 'webp') {
                $image = imagecreatefromwebp($uploadFile);
            } else {
                // Handle other image formats as needed
                return "";
            }

            // Enable alpha channel (transparency) for PNG images
            if ($fileExtension === 'png') {
                imagesavealpha($image, true);
            }

            $newWidth = $width;
            $newHeight = $height;

            if ($width > $targetWidth) {
                $newWidth = (int) $targetWidth;
                $newHeight = (int) (($targetWidth / $width) * $height);
            }

            if ($newHeight > $targetHeight) {
                $newWidth = (int) (($targetHeight / $newHeight) * $newWidth);
                $newHeight = (int) $targetHeight;
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
            imagesavealpha($resizedImage, true);
            $transparency = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefill($resizedImage, 0, 0, $transparency);
            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            if ($fileExtension === 'png') {
                imagepng($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                imagejpeg($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'gif') {
                imagegif($resizedImage, $uploadFile);
            } elseif ($fileExtension === 'webp') {
                imagewebp($resizedImage, $uploadFile);
            } else {
                return "";
            }

            imagedestroy($image);
            imagedestroy($resizedImage);
            return $fileName;
        }
    }

    return "";
}

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $enabled = 1;
        $name = validate($_POST["paymentname"]);
        $iconUrl = validate($_POST['icon-url']);

        if ($name === "" || ($iconUrl === "" && empty($_FILES['paymenticon']['name']))) {
            $response = [
                "success" => false,
                "errorMessage" => translate('fill_all_fields', $i18n)
            ];
            echo json_encode($response);
            exit();
        }


        $icon = "";

        if ($iconUrl !== "") {
            $icon = getLogoFromUrl($iconUrl, '../../images/uploads/logos/', $name, $i18n, $settings);
        } else {
            if (!empty($_FILES['paymenticon']['name'])) {
                $fileType = mime_content_type($_FILES['paymenticon']['tmp_name']);
                if (strpos($fileType, 'image') === false) {
                    $response = [
                        "success" => false,
                        "errorMessage" => translate('fill_all_fields', $i18n)
                    ];
                    echo json_encode($response);
                    exit();
                }
                $icon = resizeAndUploadLogo($_FILES['paymenticon'], '../../images/uploads/logos/', $name);
            }
        }

        // Get the maximum existing ID
        $stmt = $db->prepare("SELECT MAX(id) as maxID FROM payment_methods");
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $maxID = $row['maxID'];

        // Ensure the new ID is greater than 31
        $newID = max($maxID + 1, 32);

        // Insert the new record with the new ID
        $sql = "INSERT INTO payment_methods (id, name, icon, enabled, user_id) VALUES (:id, :name, :icon, :enabled, :userId)";
        $stmt = $db->prepare($sql);

        $stmt->bindParam(':id', $newID, SQLITE3_INTEGER);
        $stmt->bindParam(':name', $name, SQLITE3_TEXT);
        $stmt->bindParam(':icon', $icon, SQLITE3_TEXT);
        $stmt->bindParam(':enabled', $enabled, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $success['success'] = true;
            $success['message'] = translate('payment_method_added_successfuly', $i18n);
            $json = json_encode($success);
            header('Content-Type: application/json');
            echo $json;
            exit();
        } else {
            echo translate('error', $i18n) . ": " . $db->lastErrorMsg();
        }
    }
}
$db->close();

?>