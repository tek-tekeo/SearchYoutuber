<?php
/*
it accumulate youtubers who you want to get.
*/

//GoogleAPIライブラリを読み込む
require_once(dirname(__FILE__) . '/vendor/autoload.php');

//Youtube DATA API v3　からAPIキーを取得して以下に記入
const API_KEY = "YOUR_API_KEY";

//検索ワードをカンマ区切りで入力。
$queries = '

';

//使用済みキーワード
$used_queries = '

';

$queries = multiTextToArray($queries);
$used_queries = multiTextToArray($used_queries);

foreach ($queries as $key => $query) {
  if (in_array($query, $used_queries, false)) {
    unset($queries[$key]);
  }
}
//Indexを詰める
array_values($queries);

$sy = new SearchYoutuber();
foreach ($queries as $query) {
  $sy->getYoutuberList($query);
}

function multiTextToArray($multiText): array
{
  $ar = explode("\n", $multiText); // とりあえず行に分割
  $ar = array_map('trim', $ar); // 各行にtrim()をかける
  $ar = array_filter($ar, 'strlen'); // 文字数が0の行を取り除く
  $ar = array_values($ar); // これはキーを連番に振りなおしてるだけ
  return $ar;
}
class SearchYoutuber
{
  private $youtube;

  function __construct()
  {
    $client = new Google_Client();
    $client->setApplicationName("search-youtuber");
    $client->setDeveloperKey(API_KEY);

    $youtube = new Google_Service_YouTube($client);
    $this->youtube = $youtube;
  }

  //リストを作成する
  public function getYoutuberList($query): void
  {
    $channels = $this->queryHitChannels($query);

    //登録済みのチャンネルを入れる
    $f = @fopen("youtubeList.csv", "r");
    while ($line = @fgetcsv($f)) {
      $registeredId[] = $line[1];
    }
    @fclose($f);

    foreach ($channels as $ch) {

      //登録済みであればスキップ
      if (is_array($registeredId)) {
        if (in_array($ch['channelId'], $registeredId, false)) {
          continue;
        } else {
          //今回の試行で重複済みを排除する
          $registeredId[] = $ch['channelId'];
        }
      }
      $follower = $this->followerCount($ch['channelId']);
      if ($follower > 1000000) {
        continue;
      }
      $info[] = array(
        'add' => date("Y-m-d"),
        'channelId' => $ch['channelId'],
        'channelTitle' => $ch['channelTitle'],
        'about' => "https://www.youtube.com/channel/" . $ch['channelId'] . "/about",
        'contact' => '',
        'follower' => $follower,
        'averageViews' => $this->getVideosOneMonth($ch['channelId']),
        'video' => "https://www.youtube.com/watch?v=" . $ch['videoId'],
        'query' => $query
      );
    }
    //追記する
    $f = fopen("youtubeList.csv", "a");
    if ($f) {
      if (is_array($info)) {
        foreach ($info as $line) {
          fputcsv($f, $line);
        }
      }
    }
    fclose($f);

    //検索ずみキーワードを入れる
    $f = @fopen("searchWords.csv", "a");
    fputcsv($f, array($query));
    @fclose($f);
  }

  //クエリにヒットするチャンネルIDを返す
  public function queryHitChannels($query): array
  {
    //nextPageTokenを使って、次々と獲得していく予定

    $params['q'] = $query;
    $params['maxResults'] = 50;
    $params['order'] = 'relevance'; //おすすめ順
    // $params['order'] = 'viewCount'; //再生回数順
    // $params['order'] = 'rating';  //評価の高い順
    $params['safeSearch'] = 'none';
    // $params['pageToken'] = 'CAIQAA';
    try {
      $searchResponse = $this->youtube->search->listSearch('snippet', $params);
    } catch (Google_Service_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    } catch (Google_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    }
    // print_r($searchResponse['nextPageToken']);die;
    foreach ($searchResponse['items'] as $search_result) {
      $channels[] = array(
        'channelId' => $search_result['snippet']['channelId'],
        'channelTitle' => $search_result['snippet']['channelTitle'],
        'videoId' => $search_result['id']['videoId']
      );
    }
    return $channels;
  }

  //チャンネルIDから登録者数を獲得する
  public function followerCount($channel_id): int
  {
    $params['id'] = $channel_id;
    try {
      $searchResponse = $this->youtube->channels->listChannels('statistics', $params);
    } catch (Google_Service_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    } catch (Google_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    }
    foreach ($searchResponse['items'] as $search_result) {
      $follower = $search_result['statistics']['subscriberCount'];
    }
    return (int)$follower;
  }

  //特定チャンネルで、直近一ヶ月間のビデオIDを取得し、チャンネルの平均再生回数を取得
  public function getVideosOneMonth($channel_id): int
  {
    $params['channelId'] = $channel_id;
    $params['maxResults'] = 30;

    $dateMinus1Month = strtotime('-2 month');
    $params['publishedAfter'] = date(DATE_RFC3339, $dateMinus1Month);

    try {
      $searchResponse = $this->youtube->search->listSearch('snippet', $params);
    } catch (Google_Service_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    } catch (Google_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    }

    $videoIds = array();
    foreach ($searchResponse['items'] as $search_result) {
      $videoIds[] = $search_result['id']['videoId'];
    }
    $averageViewCount = $this->averageViewsCount($videoIds);
    return (int)$averageViewCount;
  }

  //ビデオの平均再生回数を取得する
  private function averageViewsCount($videoIds): int
  {
    if (count($videoIds) == 0) {
      return 0;
    }

    $params['id'] = implode(',', $videoIds); //$videoIds; //'9z3ntoa1J14,9z3ntoa1J14';//ビデオのID v?= ... 、カンマで複数取得可能
    try {
      $searchResponse = $this->youtube->videos->listVideos('statistics', $params);
    } catch (Google_Service_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    } catch (Google_Exception $e) {
      echo htmlspecialchars($e->getMessage());
      exit;
    }

    foreach ($searchResponse['items'] as $search_result) {
      $viewCounts[] = $search_result['statistics']['viewCount'];
    }
    if (!is_array($viewCounts)) {
      $averageViewCount = 1;
    } else {
      $averageViewCount = array_sum($viewCounts) / count($viewCounts);
    }

    return (int)$averageViewCount;
  }
}
