<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$handle   = 'ComunidadeBatistaShalom';
$fallback = 'https://www.youtube.com/@' . $handle . '/videos';

/* 1. busca a página do canal para extrair o channelId */
$ctx = stream_context_create(['http' => [
  'timeout' => 8,
  'user_agent' => 'Mozilla/5.0 (compatible; ShalomBot/1.0)',
]]);

$page = @file_get_contents('https://www.youtube.com/@' . $handle, false, $ctx);

if (!$page) {
  echo json_encode(['error' => 'canal não encontrado', 'fallback' => $fallback]);
  exit;
}

preg_match('/"channelId":"(UC[a-zA-Z0-9_\-]{22})"/', $page, $m);

if (empty($m[1])) {
  echo json_encode(['error' => 'channelId não encontrado', 'fallback' => $fallback]);
  exit;
}

$channelId = $m[1];

/* 2. busca o feed RSS do canal */
$rss = @file_get_contents(
  'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId,
  false, $ctx
);

if (!$rss) {
  echo json_encode(['error' => 'RSS indisponível', 'fallback' => $fallback]);
  exit;
}

/* 3. extrai o primeiro videoId (= último vídeo postado) */
preg_match('/<yt:videoId>([a-zA-Z0-9_\-]{11})<\/yt:videoId>/', $rss, $v);

if (empty($v[1])) {
  echo json_encode(['error' => 'nenhum vídeo encontrado', 'fallback' => $fallback]);
  exit;
}

echo json_encode([
  'videoId'  => $v[1],
  'url'      => 'https://www.youtube.com/watch?v=' . $v[1] . '&autoplay=1',
  'fallback' => $fallback,
]);
