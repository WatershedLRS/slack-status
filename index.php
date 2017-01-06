<?php
require_once('config.php');
require_once("TinCanPHP/autoload.php");

date_default_timezone_set('UTC');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die();
}

if (!($_POST["token"] === $CFG->slack->token)) {
    http_response_code(401);
    die();
}

if (
    !isset($_POST["user_name"]) 
    || !isset($_POST["user_id"]) 
    || !isset($_POST["team_domain"]) 
    || !isset($_POST["channel_name"]) 
    || !isset($_POST["timestamp"])
    || !isset($_POST["text"])
) {
    http_response_code(400);
    die();
}

$lrs = new TinCan\RemoteLRS(
    $CFG->lrs->endpoint,
    '1.0.1',
    $CFG->lrs->key,
    $CFG->lrs->secret
);

$text;
$text = substr($_POST["text"], strlen($CFG->slack->prefix) + 1);


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
        'id' => 'https://'.$_POST["team_domain"].'.slack.com/archives/'.$_POST["channel_name"].'/p'.$_POST["timestamp"],
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
$timestamp = date('c', $_POST["timestamp"]); 

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
if ($response->success) {
    $message = str_replace('@name', '@'.$_POST["user_name"], $CFG->slack->responses[array_rand($CFG->slack->responses)]);
    header('Content-Type: application/json');
    echo('{"text":"'.$message.'"}');
    http_response_code(200);
    die();
}
else {
    header('Content-Type: application/json');
    echo('{text: "'."Error statement not sent: " . $response->content .'"}');
    http_response_code(500);
    die();
}


?>