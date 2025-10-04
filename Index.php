<?php
$botToken = "7279966287:AAEDB33i76gtXJ0kM2n7jp5JwgW9hDmbpL8";
$apiUrl = "https://api.telegram.org/bot" . $botToken;
$apis = "https://viscodev.x10.mx/gen/api.php";
$activeRequestsFile = 'active_requests.json';

function loadActiveRequests() {
    global $activeRequestsFile;
    if (file_exists($activeRequestsFile)) {
        $data = file_get_contents($activeRequestsFile);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveActiveRequests($requests) {
    global $activeRequestsFile;
    file_put_contents($activeRequestsFile, json_encode($requests));
}

function isActiveRequest($chatId) {
    $requests = loadActiveRequests();
    return isset($requests[$chatId]) && (time() - $requests[$chatId]) < 300;
}

function addActiveRequest($chatId) {
    $requests = loadActiveRequests();
    $requests[$chatId] = time();
    saveActiveRequests($requests);
}

function removeActiveRequest($chatId) {
    $requests = loadActiveRequests();
    if (isset($requests[$chatId])) {
        unset($requests[$chatId]);
        saveActiveRequests($requests);
    }
}

function callExternalAPIParallel($text, $count = 5) {
    global $apis;
    
    $mh = curl_multi_init();
    $channels = [];
    $results = [];
    for ($i = 0; $i < $count; $i++) {
        $postData = json_encode(['text' => $text]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apis);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($postData)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_multi_add_handle($mh, $ch);
        $channels[$i] = $ch;
    }
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }
    foreach ($channels as $i => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode == 200) {
            $results[] = json_decode($response, true);
        } else {
            $results[] = false;
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    return $results;
}

function sendMessage($chatId, $text, $replyMarkup = null, $messageId = null) {
    global $apiUrl;
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    
    if ($messageId) {
        $data['message_id'] = $messageId;
        $url = $apiUrl . "/editMessageText";
    } else {
        $url = $apiUrl . "/sendMessage";
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function showProgress($chatId, $text) {
    $message = "🔄 <b>Generating your images</b>\n\n";
    $message .= "📝 <b>Description:</b> " . $text . "\n\n";
    $message .= "⏳ <i>Generating 5 different images...</i>";
    
    $result = sendMessage($chatId, $message);
    $resultData = json_decode($result, true);
    
    if (isset($resultData['result']['message_id'])) {
        return $resultData['result']['message_id'];
    }
    
    return null;
}

function updateProgress($chatId, $messageId, $current, $total, $text) {
    $percentage = round(($current / $total) * 100);
    $bars = round(($percentage / 100) * 10);
    $progressBar = str_repeat("🟩", $bars) . str_repeat("⬜", 10 - $bars);
    
    $message = "🔄 <b>Generating your images</b>\n\n";
    $message .= "📝 <b>Description:</b> " . $text . "\n\n";
    $message .= $progressBar . " " . $percentage . "%\n";
    $message .= "⚡ <b>Processing...</b>";
    
    sendMessage($chatId, $message, null, $messageId);
}

function sendImageAlbum($chatId, $images, $text) {
    global $apiUrl;
    
    if (empty($images)) {
        return false;
    }
    
    $media = [];
    $tempFiles = [];
    
    foreach ($images as $index => $imageData) {
        if ($imageData) {
            $tempFile = tempnam(sys_get_temp_dir(), 'image') . '.png';
            file_put_contents($tempFile, $imageData);
            $tempFiles[] = $tempFile;
            
            $media[] = [
                'type' => 'photo',
                'media' => 'attach://image_' . $index
            ];
        }
    }
    
    if (empty($media)) {
        return false;
    }
    $media[0]['caption'] = "✅ <b>Images generated successfully!</b>\n\n";
    $media[0]['caption'] .= "📝 <b>Used description:</b>\n<code>" . htmlspecialchars($text) . "</code>\n\n";
    $media[0]['caption'] .= "✨ <i>You can request again now...</i>";
    $media[0]['parse_mode'] = 'HTML';
    
    $postFields = [
        'chat_id' => $chatId,
        'media' => json_encode($media)
    ];
    foreach ($images as $index => $imageData) {
        if ($imageData) {
            $postFields['image_' . $index] = new CURLFile($tempFiles[$index]);
        }
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . "/sendMediaGroup");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $result = curl_exec($ch);
    curl_close($ch);
    foreach ($tempFiles as $tempFile) {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
    
    return $result;
}

function generateImages($chatId, $text) {
    $progressMessageId = showProgress($chatId, $text);
    if ($progressMessageId) {
        updateProgress($chatId, $progressMessageId, 1, 5, $text);
    }
    $apiResults = callExternalAPIParallel($text, 5);
    if ($progressMessageId) {
        updateProgress($chatId, $progressMessageId, 3, 5, $text);
    }
    
    $generatedImages = [];
    $successCount = 0;
    foreach ($apiResults as $result) {
        if ($result && isset($result['success']) && $result['success'] && isset($result['image_data'])) {
            $imageData = base64_decode($result['image_data']);
            if ($imageData) {
                $successCount++;
                $generatedImages[] = $imageData;
            }
        }
    }
    if ($progressMessageId) {
        updateProgress($chatId, $progressMessageId, 5, 5, $text);
        sleep(1);
        $deleteUrl = $GLOBALS['apiUrl'] . "/deleteMessage?chat_id=" . $chatId . "&message_id=" . $progressMessageId;
        @file_get_contents($deleteUrl);
    }
    
    if ($successCount > 0) {
        sendImageAlbum($chatId, $generatedImages, $text);
        $successMessage = "✅ Images have been generated and sent.";
        sendMessage($chatId, $successMessage);
    } else {
        $errorMessage = "❌ <b>Sorry, I couldn't generate the images</b>\n\n";
        $errorMessage .= "🔄 <b>Please try again...</b>";
        
        sendMessage($chatId, $errorMessage);
    }
    
    removeActiveRequest($chatId);
    return $successCount;
}

function sendWelcome($chatId) {
    $welcomeText = "🤖 <b>Welcome to the 3D_CARTOON image generation bot!</b>\n\n";
    $welcomeText .= "✨ <b>What can I do for you?</b>\n";
    $welcomeText .= "• Generate images in 3D_CARTOON style\n";
    $welcomeText .= "• High quality and clear 4K resolution\n\n";
    $welcomeText .= "⚡ <b>How to use?</b>\n";
    $welcomeText .= "Just send a description of the image you want\n\n";
    $welcomeText .= "💡 <b>Great examples:</b>\n";
    $welcomeText .= "• <code>anime man with cat</code>\n";
    $welcomeText .= "• <code>cute cat sitting in the garden</code>\n";
    $welcomeText .= "• <code>High-tech futuristic city</code>\n\n";
    $welcomeText .= "🚀 <b>Let's get creative...</b>";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🎨 Start 3D_CARTOON generation', 'callback_data' => 'start_design']
            ]
        ]
    ];
    
    sendMessage($chatId, $welcomeText, $keyboard);
}

$update = json_decode(file_get_contents("php://input"), true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';
    
    if (isActiveRequest($chatId)) {
        $waitMessage = "⏳ <b>Please wait</b>\n\nYour previous request is still being processed. Please wait a moment.";
        sendMessage($chatId, $waitMessage);
        echo "OK";
        exit;
    }
    
    if ($text == '/start' || $text == '/start@your_bot_username') {
        sendWelcome($chatId);
    } elseif (!empty($text) && $text != '/start') {
        addActiveRequest($chatId);
        generateImages($chatId, $text);
    }
    
} elseif (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chatId = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data = $callback['data'];
    
    if ($data == 'start_design') {
        $responseText = "🎯 <b>Great! Let's start creating...</b>\n\n";
        $responseText .= "Send me a description of the image you want and I will generate a 3D_CARTOON for you.\n\n";
        $responseText .= "💡 <b>Example:</b> <code>3D anime man with cat</code>\n";
        $responseText .= "🎨 <b>The more detailed the description, the better the result. English descriptions usually work best.</b>";
        
        sendMessage($chatId, $responseText, null, $messageId);
    }
    $answerUrl = $GLOBALS['apiUrl'] . "/answerCallbackQuery?callback_query_id=" . $callback['id'];
    file_get_contents($answerUrl);
}

echo "Bot is running";
?>
