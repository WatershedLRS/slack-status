<?php
require_once('config.php');
require_once("TinCanPHP/autoload.php");

date_default_timezone_set('UTC');

# Initial request checks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die();
}

if (!isset($_POST["token"]) || !($_POST["token"] === $CFG->slack->token)) {
    http_response_code(401);
    die();
}

if (
    !isset($_POST["user_name"]) 
    || !isset($_POST["user_id"]) 
    || !isset($_POST["team_domain"]) 
    || !isset($_POST["channel_name"]) 
    || !isset($_POST["text"])
    || !isset($_POST["response_url"])
    || !isset($_POST["trigger_id"])
) {
    http_response_code(400);
    die();
}

# Send immediate OK response, and then process the rest async
ignore_user_abort(true);
set_time_limit(0);
ob_start();
header('Content-Type: application/json');
echo('{"response_type":"in_channel", "text":""}');
header('Connection: close');
header('Content-Length: '.ob_get_length());
ob_end_flush();
ob_flush();
flush();

# Post a statement of the status update
$lrs = new TinCan\RemoteLRS(
    $CFG->lrs->endpoint,
    '1.0.1',
    $CFG->lrs->key,
    $CFG->lrs->secret
);

$text = $_POST["text"];

$actor = new TinCan\Agent(
    [ 
        'name' => $_POST["user_name"],
        'account' => [
            'name' => $_POST["user_id"],
            'homePage' => 'https://'.$_POST["team_domain"].'.slack.com'
        ]
    ]
);
$verb = new TinCan\Verb(
    [ 
        'id' => 'http://activitystrea.ms/schema/1.0/send',
        'display' => [
            'en' => 'sent'
        ]
    ]
);
$activity = new TinCan\Activity(
    [
        'id' => 'https://'.$_POST["team_domain"].'.slack.com/archives/'.$_POST["channel_name"].'/p'.$_POST["trigger_id"],
        'definition' => [
            'name' => [
                'en' => $text
            ],
            'type' => 'http://id.tincanapi.com/activitytype/chat-message'
        ]
    ]
);
$result  = new TinCan\Result(
    [
        "response" => $text
    ]
);
$context  = new TinCan\Context(
    [
        "contextActivities" => [
            "parent" => [
                [
                    "id" => "https://".$_POST["team_domain"].".slack.com/messages/".$_POST["channel_name"],
                    "definition" => [
                        "name" => [
                            "en" => $_POST["channel_name"]
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/chat-channel"
                    ],
                    "objectType" => "Activity"
                ]
            ],
            "grouping" => [
                [
                    "id" => "https://".$_POST["team_domain"].".slack.com",
                    "definition" => [
                        "name" => [
                            "en" => $_POST["team_domain"]." Slack"
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/site"
                    ],
                    "objectType" => "Activity"
                ]
            ],
           "category" => [
                [
                    "id" => "https://.slack.com/",
                    "definition" => [
                        "name" => [
                            "en" => "Slack"
                        ],
                        "type" => "http://id.tincanapi.com/activitytype/source"
                    ]
                ]
            ]
    ]
    ]
);

$timestamp = date('c'); 

$statement = new TinCan\Statement(
    [
        'actor' => $actor,
        'verb'  => $verb,
        'object' => $activity,
        'result' => $result,
        'context' => $context,
        'timestamp' => $timestamp
    ]
);

$response = $lrs->saveStatement($statement);

# If statement saved, send a random response
if ($response->success) {
    $url = 'https://docs.google.com/spreadsheets/u/0/d/' . $CFG->responsesSheetId . '/export';
    $http = array(
        'max_redirects' => 0,
        'request_fulluri' => 1,
        'ignore_errors' => true,
        'method' => 'GET',
        'header' => []
    );

    $params = [
        "format" => "csv",
        "id" => $CFG->responsesSheetId,
        "gid" => "0",
    ];

    $url .= '?'.http_build_query($params);

    $context = stream_context_create(array( 'http' => $http ));
    $fp = fopen($url, 'rb', false, $context);
    if (! $fp) {
        throw new \Exception("Request failed: $php_errormsg");
    }
    $metadata = stream_get_meta_data($fp);
    $content  = stream_get_contents($fp);
    $responseCode = (int)explode(' ', $metadata["wrapper_data"][0])[1];

    fclose($fp);

    if ($responseCode === 200) {
        $rowsAsStr = str_getcsv($content, "\n");
        $messages =[];
        foreach ($rowsAsStr as $row) {
            $rowArr = str_getcsv($row, '","', '"');
            array_push($messages, $rowArr[0]);
        }
        $message = $messages[array_rand($messages)];
     }
     else {
        $message = "@name you're awesome, but the Google Sheet integration is broken.";
     }
	$message = str_replace('@name', '@'.$_POST["user_name"], $message);
} else {
    $message = "Error statement not sent: " . $response->content;
}

# Finally, post result to response_url
$url = $_POST["response_url"];
$options = array(
        'http' => array(
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => '{"response_type": "'.$CFG->slack->responseType.'", "text":"'.$message.'"}'
    )
);
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

?>
