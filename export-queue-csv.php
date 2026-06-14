<?php
// export-queue-csv.php — même logique de génération que auto-daily-csv.php
header('Content-Type: application/json');
include_once 'config.php';

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if (!isset($data['queue']) || !is_array($data['queue'])) {
        throw new Exception('Invalid queue data');
    }
    $queue = $data['queue'];
    if (count($queue) === 0) {
        throw new Exception('Queue is empty');
    }

    // ── Même helpers que auto-daily-csv ──────────────────────────────────────────
    function csvField($value) {
        $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
        $value = str_replace('"', '""', $value);
        return '"' . trim($value) . '"';
    }
    function csvText($value) {
        $value = preg_replace('/[\r\n\v\f\x{0085}\x{2028}\x{2029}]+/u', ' ', (string)$value);
        $value = str_replace(['"', ','], '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return '"' . trim($value) . '"';
    }

    // ── Date de départ : aujourd'hui (évite les dates passées) ──────────────────
    $groupDate     = new DateTime(date('Y-m-d'));

    // ── Même scheduling que auto-daily-csv : 16h00 + ~1h par pin ─────────────────
    $currentMinute = 16 * 60; // 16:00
    $seenTitles    = [];

    $lines   = ['Title,Media URL,Pinterest board,Thumbnail,Description,Link,Publish date,Keywords'];

    foreach ($queue as $post) {
        $title       = $post['title']       ?? '';
        $description = $post['description'] ?? '';

        // Strip CTAs avant hashtag extraction
        $description = trim(preg_replace('/\b(SAVE|FOR LATER|PIN IT|PIN THIS|CLICK|BOOKMARK|TRY IT|MAKE IT|GRAB IT)\b\.?/i', '', $description));
        $description = trim(preg_replace('/\s{2,}/', ' ', $description));

        // Keywords depuis hashtags — même logique auto
        $keywords = '';
        if (preg_match_all('/#(\w+)/', $description, $matches)) {
            $rawTags     = $matches[1];
            $description = preg_replace('/#\w+/', '', $description);
            $description = trim(preg_replace('/\s+/', ' ', $description));
            $cleanTags   = array_filter(array_map(fn($t) => preg_replace('/(SAVE|LATER|PINIT|CLICK|BOOKMARK|TRYIT|MAKEIT|GRABIT)$/i', '', $t), $rawTags), fn($t) => strlen($t) >= 3);
            $keywords    = implode(', ', $cleanTags);
        }

        // Limites + déduplication titre — même logique auto
        if (mb_strlen($title) > 100) $title = mb_substr($title, 0, 97) . '...';
        $titleKey = mb_strtolower(trim($title));
        if (isset($seenTitles[$titleKey])) {
            $seenTitles[$titleKey]++;
            $suffix = ' ' . $seenTitles[$titleKey];
            $title  = mb_substr($title, 0, 100 - mb_strlen($suffix)) . $suffix;
        } else {
            $seenTitles[$titleKey] = 1;
        }
        if (mb_strlen($description) > 500) $description = mb_substr($description, 0, 497) . '...';

        // Media URL + Thumbnail — différent pour video pins
        $isVideo   = !empty($post['isVideo']);
        $mediaUrl  = $isVideo ? ($post['videoUrl'] ?? '') : ($post['image'] ?? '');
        $thumbnail = $isVideo ? ($post['image']    ?? '') : '';

        // Board — nom exact Pinterest (avec espaces et majuscules)
        $boardName = trim($post['category'] ?? '');
        if (empty($boardName)) $boardName = 'posts';

        // Link — depuis la queue (URL avec ?src= déjà formattée)
        $link = $post['slug'] ?? '';

        // Publish date — même calcul que auto : ~1h entre chaque pin depuis 16:00
        $minutesFromMidnight = $currentMinute + rand(0, 15);
        $currentMinute      += 60 + rand(-10, 10);
        $isNextDay           = $minutesFromMidnight >= 1440;
        $actualDate          = clone $groupDate;
        if ($isNextDay) $actualDate->modify('+1 day');
        $h           = (int)(($minutesFromMidnight % 1440) / 60);
        $m           = $minutesFromMidnight % 60;
        $publishDate = $actualDate->format('Y-m-d') . 'T' . sprintf('%02d:%02d:00', $h, $m);

        $lines[] = implode(',', [
            csvText($title),
            csvField($mediaUrl),             // image URL ou MP4 URL
            csvField($boardName),
            csvField($thumbnail),            // vide pour image pin, cover image pour video pin
            csvText($description),
            csvField($link),
            csvField($publishDate),
            csvField($keywords),
        ]);
    }

    $csvContent = implode("\r\n", $lines);
    $filename   = 'pinterest_bulk_upload_' . date('Y-m-d-H-i-s') . '.csv';

    echo json_encode([
        'success'  => true,
        'message'  => count($queue) . ' posts exportés en CSV avec succès.',
        'filename' => $filename,
        'csvData'  => base64_encode($csvContent),
        'count'    => count($queue),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
